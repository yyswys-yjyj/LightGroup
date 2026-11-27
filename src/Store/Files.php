<?php
declare(strict_types=1);

namespace LightGroup\Store;

use PDO;
use LightGroup\Http\Json;

/**
 * 聊天附件。
 *
 * 上传走分块：客户端先 init 拿 uid，再按 1MB 一块往上传，最后 finish 合并。
 * 这样不用改 nginx / php.ini 的 body 上限。
 *
 * 三种存储由管理员在「文件传输设置」里选：
 *   local 落盘（默认，文件放在数据目录的 files/ 下）
 *   db    二进制入库（有体积上限）
 *   s3    对象存储（下载 302 到签名地址，不在 PHP 里转发大文件）
 */
final class Files {
    public const CHUNK = 1048576;

    // ---------- 配置 ----------

    public static function config(PDO $pdo): array {
        $storage = self::get($pdo, "file.storage", "local");
        if (!in_array($storage, ["local", "db", "s3"], true)) $storage = "local";
        return [
            "enabled"     => self::get($pdo, "file.enabled", "1") !== "0",
            "maxMB"       => max(1, min(2048, (int) self::get($pdo, "file.max_mb", "50"))),
            "attachments" => max(1, min(9, (int) self::get($pdo, "file.attachments", "3"))),
            "keepDays"    => max(0, min(3650, (int) self::get($pdo, "file.keep_days", "0"))),
            "storage"     => $storage,
            "dbLimitMB"   => 8,
            "s3"          => [
                "endpoint" => rtrim(self::get($pdo, "file.s3.endpoint", ""), "/"),
                "bucket"   => self::get($pdo, "file.s3.bucket", ""),
                "region"   => self::get($pdo, "file.s3.region", "auto") ?: "auto",
                "key"      => self::get($pdo, "file.s3.key", ""),
                "secret"   => self::get($pdo, "file.s3.secret", ""),
                "prefix"   => trim(self::get($pdo, "file.s3.prefix", ""), "/"),
            ],
        ];
    }

    /** 下发给客户端的精简配置 */
    public static function site(PDO $pdo): array {
        $c = self::config($pdo);
        return [
            "enabled"     => $c["enabled"],
            "maxMB"       => $c["maxMB"],
            "attachments" => $c["attachments"],
            "storage"     => $c["storage"],
        ];
    }

    private static function get(PDO $pdo, string $k, string $d = ""): string {
        try {
            $st = $pdo->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
            $st->execute([$k]);
            $v = $st->fetchColumn();
            return $v === false || $v === null ? $d : (string) $v;
        } catch (\Throwable $e) {
            return $d;
        }
    }

    // ---------- 目录 ----------

    private static function dataDir(): string {
        $cfg = lg_config();
        $dir = !empty($cfg["path"]) ? dirname((string) $cfg["path"]) : (APP_ROOT . "/data");
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) Json::fail("数据目录不可写");
        return $dir;
    }

    private static function tmpDir(): string {
        $dir = self::dataDir() . "/tmp/files";
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) Json::fail("临时目录不可写");
        return $dir;
    }

    private static function safeUid(string $uid): string {
        if (!preg_match('/^[a-f0-9]{24}$/', $uid)) Json::fail("上传编号不对");
        return $uid;
    }

    private static function meta(string $uid): ?array {
        $f = self::tmpDir() . "/" . self::safeUid($uid) . ".json";
        if (!is_file($f)) return null;
        $j = json_decode((string) @file_get_contents($f), true);
        return is_array($j) ? $j : null;
    }

    private static function saveMeta(string $uid, array $meta): void {
        file_put_contents(self::tmpDir() . "/" . $uid . ".json", json_encode($meta));
    }

    // ---------- 上传 ----------

    public static function init(PDO $pdo, array $scope, array $me, string $name, int $size, string $mime, int $chunks): array {
        $c = self::config($pdo);
        if (!$c["enabled"]) Json::fail("本站没有开放文件传输", 403);
        if ($size <= 0) Json::fail("空文件不用发");
        if ($size > $c["maxMB"] * 1024 * 1024) Json::fail("文件最多 " . $c["maxMB"] . "MB");
        if ($chunks <= 0) $chunks = (int) ceil($size / self::CHUNK);
        if ($chunks > 4096) Json::fail("文件太大，分块太多");
        $uid = bin2hex(random_bytes(12));
        self::saveMeta($uid, [
            "uid"      => $uid,
            "name"     => self::safeName($name),
            "size"     => $size,
            "mime"     => self::safeMime($mime),
            "chunks"   => $chunks,
            "received" => 0,
            "scope"    => (int) $scope["id"],
            "member"   => (int) $me["id"],
            "at"       => lg_now(),
        ]);
        return ["uid" => $uid, "chunk" => self::CHUNK, "chunks" => $chunks];
    }

    public static function chunk(PDO $pdo, array $scope, array $me, string $uid, int $idx, string $raw): array {
        $c = self::config($pdo);
        if (!$c["enabled"]) Json::fail("本站没有开放文件传输", 403);
        $meta = self::meta($uid);
        if ($meta === null) Json::fail("上传会话不存在，重传一次");
        if ((int) $meta["scope"] !== (int) $scope["id"] || (int) $meta["member"] !== (int) $me["id"]) {
            Json::fail("上传会话不属于你", 403);
        }
        if ($idx !== (int) $meta["received"]) Json::fail("分块顺序不对，重传一次");
        if ($raw === "") Json::fail("这一块是空的");
        if (strlen($raw) > self::CHUNK * 2) Json::fail("单块太大");
        $part = self::tmpDir() . "/" . $uid . ".part";
        $fp = @fopen($part, "ab");
        if (!$fp) Json::fail("临时文件写不进去");
        fwrite($fp, $raw);
        fclose($fp);
        $meta["received"] = $idx + 1;
        self::saveMeta($uid, $meta);
        return ["received" => $meta["received"], "chunks" => (int) $meta["chunks"]];
    }

    public static function finish(PDO $pdo, array $scope, array $me, string $uid): array {
        $c = self::config($pdo);
        if (!$c["enabled"]) Json::fail("本站没有开放文件传输", 403);
        $meta = self::meta($uid);
        if ($meta === null) Json::fail("上传会话不存在，重传一次");
        if ((int) $meta["scope"] !== (int) $scope["id"] || (int) $meta["member"] !== (int) $me["id"]) {
            Json::fail("上传会话不属于你", 403);
        }
        if ((int) $meta["received"] !== (int) $meta["chunks"]) Json::fail("还有分块没传完");
        $part = self::tmpDir() . "/" . $uid . ".part";
        if (!is_file($part)) Json::fail("上传的文件丢了，重传一次");
        clearstatcache(true, $part);
        $size = (int) filesize($part);
        if ($size !== (int) $meta["size"]) Json::fail("文件大小对不上，重传一次");

        $name = (string) $meta["name"];
        $mime = (string) $meta["mime"];
        $sha = (string) hash_file("sha256", $part);
        $ext = self::extOf($name);
        $path = null;
        $blob = null;

        if ($c["storage"] === "db") {
            if ($size > $c["dbLimitMB"] * 1024 * 1024) {
                Json::fail("二进制入库最多 " . $c["dbLimitMB"] . "MB，这个文件太大，换个存储方式");
            }
            $blob = (string) file_get_contents($part);
        } elseif ($c["storage"] === "s3") {
            $key = trim($c["s3"]["prefix"] . "/" . date("Y/m") . "/" . $uid . ($ext !== "" ? "." . $ext : ""), "/");
            self::s3Put($c["s3"], $key, $part, $mime);
            $path = $key;
        } else {
            $rel = "files/" . date("Y/m");
            $dir = self::dataDir() . "/" . $rel;
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) Json::fail("存储目录不可写");
            $target = $dir . "/" . $uid . ($ext !== "" ? "." . $ext : "");
            if (!@rename($part, $target)) {
                if (!@copy($part, $target)) Json::fail("保存失败");
                @unlink($part);
            }
            $path = $rel . "/" . basename($target);
        }
        @unlink($part);
        @unlink(self::tmpDir() . "/" . $uid . ".json");

        $keep = (int) $c["keepDays"];
        $expires = $keep > 0 ? lg_now() + $keep * 86400000 : 0;
        $st = $pdo->prepare(
            "INSERT INTO files (scope_id, member_id, name, mime, size, sha256, storage, path, data, created_at, expires_at)"
            . " VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $st->bindValue(1, (int) $scope["id"], PDO::PARAM_INT);
        $st->bindValue(2, (int) $me["id"], PDO::PARAM_INT);
        $st->bindValue(3, $name, PDO::PARAM_STR);
        $st->bindValue(4, $mime, PDO::PARAM_STR);
        $st->bindValue(5, $size, PDO::PARAM_INT);
        $st->bindValue(6, $sha, PDO::PARAM_STR);
        $st->bindValue(7, $c["storage"], PDO::PARAM_STR);
        $st->bindValue(8, $path, $path === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(9, $blob, $blob === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $st->bindValue(10, lg_now(), PDO::PARAM_INT);
        $st->bindValue(11, $expires, PDO::PARAM_INT);
        $st->execute();
        $id = (int) $pdo->lastInsertId();
        return ["id" => $id, "name" => $name, "size" => $size, "mime" => $mime, "url" => self::url($id)];
    }

    // ---------- 下载 ----------

    public static function url(int $id): string {
        return "/g/api/file/" . $id;
    }

    public static function find(PDO $pdo, int $id): ?array {
        $st = $pdo->prepare("SELECT * FROM files WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** 直接输出文件（local / db）；s3 由调用方先取 s3Redirect */
    public static function send(PDO $pdo, array $row): void {
        $name = (string) ($row["name"] ?? "file");
        $mime = (string) ($row["mime"] ?? "");
        if ($mime === "" || !preg_match('#^[\w.+-]+/[\w.+-]+$#', $mime)) $mime = "application/octet-stream";
        header("Content-Type: " . $mime);
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
        header("X-Content-Type-Options: nosniff");
        header("Cache-Control: private, max-age=0, no-store");
        $storage = (string) ($row["storage"] ?? "local");
        if ($storage === "db") {
            $blob = $row["data"] ?? null;
            if ($blob === null) { http_response_code(410); return; }
            header("Content-Length: " . strlen((string) $blob));
            echo $blob;
            return;
        }
        $path = self::dataDir() . "/" . (string) ($row["path"] ?? "");
        if (!is_file($path)) { http_response_code(410); echo "文件已经没了"; return; }
        header("Content-Length: " . (string) filesize($path));
        readfile($path);
    }

    public static function s3Redirect(PDO $pdo, array $row): ?string {
        $c = self::config($pdo);
        $key = (string) ($row["path"] ?? "");
        if ($c["storage"] !== "s3" || $key === "" || $c["s3"]["endpoint"] === "" || $c["s3"]["bucket"] === "") return null;
        return self::s3Presign($c["s3"], $key, 600);
    }

    // ---------- 清理 ----------

    public static function cleanup(PDO $pdo, int $limit = 300): array {
        $now = lg_now();
        $deleted = 0;
        $st = $pdo->prepare("SELECT id, storage, path FROM files WHERE expires_at > 0 AND expires_at <= ? AND deleted_at IS NULL LIMIT " . (int) $limit);
        $st->execute([$now]);
        $rows = $st->fetchAll();
        $c = self::config($pdo);
        foreach ($rows as $r) {
            if ((string) $r["storage"] === "local") {
                @unlink(self::dataDir() . "/" . (string) $r["path"]);
            } elseif ((string) $r["storage"] === "s3") {
                self::s3Delete($c["s3"], (string) $r["path"]);
            }
            $pdo->prepare("DELETE FROM files WHERE id = ?")->execute([(int) $r["id"]]);
            $deleted++;
        }
        // 一天前还没传完的分块直接扔掉
        $cut = time() - 86400;
        foreach ((array) @glob(self::tmpDir() . "/*") as $f) {
            if (is_file($f) && @filemtime($f) < $cut) @unlink($f);
        }
        return ["deleted" => $deleted];
    }

    // ---------- 工具 ----------

    private static function safeName(string $name): string {
        $name = trim($name);
        $name = str_replace(["\\", "/", "\0"], "_", $name);
        $name = preg_replace('/[\x00-\x1f\x7f]/', "", $name) ?? "file";
        if ($name === "") $name = "file";
        if (mb_strlen($name) > 120) $name = mb_substr($name, -120);
        return $name;
    }

    private static function safeMime(string $mime): string {
        $mime = strtolower(trim($mime));
        if ($mime === "" || !preg_match('#^[\w.+-]+/[\w.+-]+$#', $mime)) return "application/octet-stream";
        return $mime;
    }

    private static function extOf(string $name): string {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === "" || !preg_match('/^[a-z0-9]{1,8}$/', $ext)) return "";
        return $ext;
    }

    // ---------- S3（SigV4） ----------

    private static function s3Endpoint(array $s3, string $key = ""): array {
        $u = parse_url($s3["endpoint"]);
        $scheme = ($u["scheme"] ?? "https") . "://";
        $host = (string) ($u["host"] ?? "");
        $port = isset($u["port"]) ? ":" . $u["port"] : "";
        $base = (string) ($u["path"] ?? "");
        $path = "/" . rawurlencode($s3["bucket"]) . "/" . str_replace("%2F", "/", rawurlencode($key));
        return ["scheme" => $scheme, "host" => $host . $port, "path" => $base . $path];
    }

    private static function s3Sign(array $s3, string $method, string $path, string $payloadHash, array $headers, string $query = ""): string {
        $region = $s3["region"] !== "" ? $s3["region"] : "us-east-1";
        $t = new \DateTimeImmutable("now", new \DateTimeZone("UTC"));
        $amz = $t->format("Ymd\THis\Z");
        $day = $t->format("Ymd");
        $p = parse_url($s3["endpoint"]);
        $host = (string) ($p["host"] ?? "");
        if (!empty($p["port"])) $host .= ":" . $p["port"];
        $hdrs = $headers;
        $hdrs["host"] = $host;
        $hdrs["x-amz-content-sha256"] = $payloadHash;
        $hdrs["x-amz-date"] = $amz;
        ksort($hdrs);
        $canonHeaders = "";
        $signed = [];
        foreach ($hdrs as $k => $v) {
            $canonHeaders .= strtolower($k) . ":" . trim((string) $v) . "\n";
            $signed[] = strtolower($k);
        }
        $canonical = $method . "\n" . $path . "\n" . $query . "\n" . $canonHeaders . "\n" . implode(";", $signed) . "\n" . $payloadHash;
        $scope = $day . "/" . $region . "/s3/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n" . $amz . "\n" . $scope . "\n" . hash("sha256", $canonical);
        $k = hash_hmac("sha256", $day, "AWS4" . $s3["secret"], true);
        $k = hash_hmac("sha256", $region, $k, true);
        $k = hash_hmac("sha256", "s3", $k, true);
        $k = hash_hmac("sha256", "aws4_request", $k, true);
        $sig = hash_hmac("sha256", $toSign, $k);
        return "AWS4-HMAC-SHA256 Credential=" . $s3["key"] . "/" . $scope
            . ", SignedHeaders=" . implode(";", $signed) . ", Signature=" . $sig;
    }

    private static function s3Put(array $s3, string $key, string $file, string $mime): void {
        self::assertS3($s3);
        $ep = self::s3Endpoint($s3, $key);
        $url = $ep["scheme"] . $ep["host"] . $ep["path"];
        $hash = (string) hash_file("sha256", $file);
        $ct = $mime !== "" ? $mime : "application/octet-stream";
        $auth = self::s3Sign($s3, "PUT", $ep["path"], $hash, ["content-type" => $ct]);
        $fp = fopen($file, "rb");
        if (!$fp) Json::fail("读取上传文件失败");
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $fp,
            CURLOPT_INFILESIZE     => (int) filesize($file),
            CURLOPT_CUSTOMREQUEST  => "PUT",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_HTTPHEADER     => [
                "Authorization: " . $auth,
                "Content-Type: " . $ct,
                "x-amz-content-sha256: " . $hash,
                "Expect:",
            ],
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($code < 200 || $code >= 300) {
            Json::fail("传到对象存储失败（HTTP " . $code . "）" . ($err !== "" ? "：" . $err : ""));
        }
    }

    private static function s3Delete(array $s3, string $key): void {
        if ($s3["endpoint"] === "" || $s3["bucket"] === "" || $s3["key"] === "" || $s3["secret"] === "") return;
        $ep = self::s3Endpoint($s3, $key);
        $url = $ep["scheme"] . $ep["host"] . $ep["path"];
        $hash = hash("sha256", "");
        $auth = self::s3Sign($s3, "DELETE", $ep["path"], $hash, []);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => "DELETE",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ["Authorization: " . $auth, "x-amz-content-sha256: " . $hash],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private static function s3Presign(array $s3, string $key, int $ttl = 600): ?string {
        if ($s3["endpoint"] === "" || $s3["bucket"] === "" || $s3["key"] === "" || $s3["secret"] === "") return null;
        $ep = self::s3Endpoint($s3, $key);
        $region = $s3["region"] !== "" ? $s3["region"] : "us-east-1";
        $t = new \DateTimeImmutable("now", new \DateTimeZone("UTC"));
        $amz = $t->format("Ymd\THis\Z");
        $day = $t->format("Ymd");
        $scope = $day . "/" . $region . "/s3/aws4_request";
        $q = [
            "X-Amz-Algorithm"     => "AWS4-HMAC-SHA256",
            "X-Amz-Credential"    => $s3["key"] . "/" . $scope,
            "X-Amz-Date"          => $amz,
            "X-Amz-Expires"       => (string) $ttl,
            "X-Amz-SignedHeaders" => "host",
        ];
        ksort($q);
        $parts = [];
        foreach ($q as $k => $v) $parts[] = rawurlencode($k) . "=" . rawurlencode($v);
        $query = implode("&", $parts);
        $canonical = "GET\n" . $ep["path"] . "\n" . $query . "\nhost:" . $ep["host"] . "\n\nhost\nUNSIGNED-PAYLOAD";
        $toSign = "AWS4-HMAC-SHA256\n" . $amz . "\n" . $scope . "\n" . hash("sha256", $canonical);
        $k = hash_hmac("sha256", $day, "AWS4" . $s3["secret"], true);
        $k = hash_hmac("sha256", $region, $k, true);
        $k = hash_hmac("sha256", "s3", $k, true);
        $k = hash_hmac("sha256", "aws4_request", $k, true);
        $sig = hash_hmac("sha256", $toSign, $k);
        return $ep["scheme"] . $ep["host"] . $ep["path"] . "?" . $query . "&X-Amz-Signature=" . $sig;
    }

    private static function assertS3(array $s3): void {
        if ($s3["endpoint"] === "" || $s3["bucket"] === "" || $s3["key"] === "" || $s3["secret"] === "") {
            Json::fail("对象存储还没配好，去后台把 endpoint、bucket、key、secret 填上");
        }
    }
}
