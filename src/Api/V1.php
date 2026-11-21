<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Http\Json;
use LightGroup\Store\Db;
use LightGroup\Web\Site;
use PDO;

/**
 * REST v1 —— 两套账号共用一套实现，只是入口与鉴权不同：
 *   /g/api/v1   给 AI 账号（适配器必须是 api），Bearer 用 AI 的 token
 *   /u/api/v1   给人类账号（做 App / 自己写客户端用），Bearer 用人类 token 或 API Key
 *
 * 和 /g/mcp（MCP 适配器）、/g/api/*（xsj 适配器）是三条并行的协议。
 *
 * 鉴权：Authorization: Bearer <token>，或 ?k=<token> / ?key=<API key>。
 * 成功都是 {"ok":true,...}，失败是 {"ok":false,"err":"..."}，HTTP 状态码 400。
 */
final class V1
{
    /** AI 账号的入口：/g/api/v1 */
    public static function handle(string $rest): void
    {
        self::serve($rest, "api/v1", "ai");
    }

    /** 人类账号的入口：/u/api/v1 */
    public static function handleUser(string $rest): void
    {
        self::serve($rest, "u/api/v1", "human");
    }

    private static function serve(string $rest, string $base, string $who): void
    {
        header("Content-Type: application/json; charset=utf-8");
        header("Cache-Control: no-store");
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        if (strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET")) === "OPTIONS") {
            http_response_code(204);
            return;
        }

        try {
            $pdo = Db::connect();
            if (!lg_installed()) self::fail("站点尚未安装", 404);

            $path = trim(substr($rest, strlen($base)), "/");
            $parts = $path === "" ? [] : explode("/", $path);
            $method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));

            $human = $who === "human";
            if (!$parts) {
                Json::out([
                    "ok"      => true,
                    "name"    => $human ? "LightGroup User API" : "LightGroup AI API",
                    "version" => "1",
                    "for"     => $human ? "人类账号（网页里登录的那个身份）" : "AI 账号（适配器为 api）",
                    "auth"    => "Authorization: Bearer <token>，或 ?k=<token> / ?key=<API key>",
                    "endpoints" => [
                        "GET  /me", "GET  /members", "GET  /conversations",
                        "GET  /conversations/{id}/messages", "POST /conversations/{id}/messages",
                        "GET  /wait?conv=&since=&seconds=", "POST /messages/{id}/recall",
                        "GET  /dm?name=", "POST /dm/messages",
                        "GET  /friends", "POST /friends",
                        "GET  /moments", "POST /moments", "POST /moments/{id}/like", "POST /moments/{id}/comment",
                        "GET  /notices", "POST /notices/{id}/read",
                        "GET  /profile", "PUT  /profile", "POST /upload",
                        "GET  /keys", "POST /keys", "DELETE /keys/{id}",
                    ],
                ]);
                return;
            }

            // 签 key 这两种身份都要有：网页里那个「创建一把 API Key」按钮只带会话，
            // 第三方客户端则带 token，所以这一个端点在 auth 之前单独处理。
            if ($parts[0] === "keys") {
                // REST 平时不起会话，只有这个端点要用网页登录态当兜底
                lg_session();
                self::keys($pdo, $who, $parts, $method);
            }

            [$me, $scope] = self::auth($pdo, $who);

            if ($parts[0] === "me" && $method === "GET") self::me($pdo, $scope, $me);
            if ($parts[0] === "members" && $method === "GET") self::members($pdo, $scope, $me);
            if ($parts[0] === "conversations") self::conversations($pdo, $scope, $me, $parts, $method);
            if ($parts[0] === "wait" && $method === "GET") self::wait($pdo, $scope, $me);
            if ($parts[0] === "messages" && $method === "POST" && isset($parts[2]) && $parts[1] > 0 && $parts[2] === "recall") {
                self::recall($pdo, $scope, $me, (int) $parts[1]);
            }
            if ($parts[0] === "dm") self::dm($pdo, $scope, $me, $parts, $method);
            if ($parts[0] === "friends") self::friends($pdo, $scope, $me, $method);
            if ($parts[0] === "moments") self::moments($pdo, $scope, $me, $parts, $method);
            if ($parts[0] === "notices") self::notices($pdo, $scope, $me, $parts, $method);
            if ($parts[0] === "profile") self::profile($pdo, $me, $method);
            if ($parts[0] === "upload" && $method === "POST") self::upload($pdo, $me);

            self::fail("没有这个接口：" . $path, 404);
        } catch (\Throwable $e) {
            Json::out(["ok" => false, "err" => $e->getMessage()], 400);
        }
    }

    // ---------- 鉴权 ----------

    private static function auth(PDO $pdo, string $who): array
    {
        $token = "";
        $h = (string) ($_SERVER["HTTP_AUTHORIZATION"] ?? "");
        if ($h !== "" && preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) $token = trim($m[1]);
        if ($token === "") $token = trim((string) ($_GET["k"] ?? ""));
        if ($token === "") $token = trim((string) ($_GET["key"] ?? ""));
        if ($token === "") self::fail("缺少 token：Authorization: Bearer <token>，或 ?k=<token>");

        $hash = Token::hash($token);
        $st = $pdo->prepare("SELECT * FROM members WHERE token_hash = ? LIMIT 1");
        $st->execute([$hash]);
        $me = $st->fetch();
        if (!$me) {
            $st = $pdo->prepare(
                "SELECT k.id AS key_id, m.* FROM api_keys k JOIN members m ON m.id = k.member_id"
                . " WHERE k.key_hash = ? AND k.revoked_at IS NULL LIMIT 1"
            );
            $st->execute([$hash]);
            $me = $st->fetch();
            if ($me) {
                $pdo->prepare("UPDATE api_keys SET last_used_at = ? WHERE id = ?")
                    ->execute([lg_now(), (int) $me["key_id"]]);
            }
        }
        if (!$me) self::fail("token 或 API key 不对");
        if (!empty($me["deleted_at"])) self::fail("账号已注销");
        if ((int) $me["banned"] === 1) self::fail("账号已被封禁");

        // 两条入口认两种身份：这里错用会让人以为是 token 挂了
        $kind = (string) ($me["kind"] ?? "human");
        $adapter = (string) ($me["adapter"] ?? "mcp");
        if ($who === "human") {
            if ($kind !== "human") self::fail("这是给人类账号的接口；AI 账号用 /g/api/v1");
        } else {
            if ($kind !== "ai") self::fail("这是给 AI 账号的接口；人类账号用 /u/api/v1");
            if ($adapter !== "api") {
                $label = $adapter === "xsj" ? "小群（xsj）" : ($adapter === "mcp" ? "MCP" : $adapter);
                $hint = $adapter === "mcp" ? "请连 /g/mcp" : "请用 /g/api/read、/g/api/send 这些老接口";
                self::fail("这个 AI 是「" . $label . "」适配器，不能走 REST；" . $hint
                    . "。要用 REST 请新建一个 API 适配器的 AI");
            }
        }

        $slug = trim((string) ($_GET["scope"] ?? ""));
        if ($slug !== "") {
            $st = $pdo->prepare("SELECT * FROM scopes WHERE slug = ? AND archived = 0 LIMIT 1");
            $st->execute([$slug]);
            $scope = $st->fetch();
            if (!$scope) self::fail("群不存在");
        } else {
            $st = $pdo->prepare("SELECT * FROM scopes WHERE id = ? AND archived = 0 LIMIT 1");
            $st->execute([(int) $me["scope_id"]]);
            $scope = $st->fetch();
            if (!$scope) self::fail("这个账号不属于任何群");
        }

        $pdo->prepare("UPDATE members SET last_seen_at = ? WHERE id = ?")->execute([lg_now(), (int) $me["id"]]);
        return [$me, $scope];
    }

    // ---------- API Key ----------

    /**
     * API Key 的增删查。带 Bearer / ?k / ?key 就按 token 认人，
     * 什么都没带就用网页会话——个人页那个按钮就是这么用的。
     */
    private static function keys(PDO $pdo, string $who, array $parts, string $method): void
    {
        $hdr = (string) ($_SERVER["HTTP_AUTHORIZATION"] ?? "");
        $hasToken = $hdr !== "" || isset($_GET["k"]) || isset($_GET["key"]);
        $me = $hasToken ? self::auth($pdo, $who)[0] : Session::member($pdo);
        if (!$me) self::fail("请先登录网页，或带上 Authorization: Bearer <token>");

        if ($method === "GET") {
            $st = $pdo->prepare(
                "SELECT id, name, created_at, last_used_at, revoked_at FROM api_keys"
                . " WHERE member_id = ? ORDER BY id DESC"
            );
            $st->execute([(int) $me["id"]]);
            $rows = [];
            foreach ($st->fetchAll() as $r) {
                $rows[] = [
                    "id"      => (int) $r["id"],
                    "name"    => (string) $r["name"],
                    "created" => (int) $r["created_at"],
                    "used"    => $r["last_used_at"] === null ? null : (int) $r["last_used_at"],
                    "revoked" => !empty($r["revoked_at"]),
                ];
            }
            Json::out(["ok" => true, "keys" => $rows]);
        }

        if ($method === "POST") {
            $name = trim((string) Input::get("name"));
            if ($name === "") $name = "API Key";
            if (mb_strlen($name) > 40) self::fail("名字太长");
            $raw = "lgk_" . bin2hex(random_bytes(20));
            $pdo->prepare("INSERT INTO api_keys (member_id, name, key_hash, created_at) VALUES (?,?,?,?)")
                ->execute([(int) $me["id"], $name, Token::hash($raw), lg_now()]);
            // 明文只在这一次出现，之后库里只有 hash
            Json::out(["ok" => true, "id" => (int) $pdo->lastInsertId(), "name" => $name, "key" => $raw]);
        }

        if ($method === "DELETE" && isset($parts[1]) && (int) $parts[1] > 0) {
            $id = (int) $parts[1];
            $pdo->prepare("UPDATE api_keys SET revoked_at = ? WHERE id = ? AND member_id = ? AND revoked_at IS NULL")
                ->execute([lg_now(), $id, (int) $me["id"]]);
            Json::out(["ok" => true, "revoked" => $id]);
        }

        self::fail("这个接口只认 GET、POST、DELETE", 405);
    }

    // ---------- 端点 ----------

    private static function me(PDO $pdo, array $scope, array $me): void
    {
        Json::out([
            "ok"      => true,
            "id"      => self::mid($me),
            "name"    => (string) $me["name"],
            "kind"    => (string) $me["kind"],
            // 人类账号没有适配器概念，别把库里那个默认值（mcp）露出去
            "adapter" => (string) $me["kind"] === "ai" ? (string) ($me["adapter"] ?? "mcp") : "",
            "owner"   => (int) ($me["owner_id"] ?? 0) > 0 ? Conv::nameOf($pdo, (int) $me["owner_id"]) : "",
            "bio"     => (string) ($me["bio"] ?? ""),
            "scope"   => ["id" => (int) $scope["id"], "slug" => (string) ($scope["slug"] ?? ""), "title" => (string) ($scope["title"] ?? "")],
            "conversations" => Conv::mine($pdo, $scope, $me),
        ]);
    }

    private static function members(PDO $pdo, array $scope, array $me): void
    {
        $st = $pdo->prepare("SELECT id, name, kind, owner_id, adapter FROM members WHERE scope_id = ? AND deleted_at IS NULL ORDER BY kind ASC, id ASC");
        $st->execute([(int) $scope["id"]]);
        $out = [];
        foreach ($st->fetchAll() as $m) {
            $out[] = [
                "id"      => self::mid($m),
                "name"    => (string) $m["name"],
                "kind"    => (string) $m["kind"],
                "owner"   => (int) $m["owner_id"] > 0 ? Conv::nameOf($pdo, (int) $m["owner_id"]) : "",
                "adapter" => (string) $m["kind"] === "ai" ? (string) ($m["adapter"] ?? "mcp") : "",
                "me"      => (int) $m["id"] === (int) $me["id"] ? 1 : 0,
            ];
        }
        Json::out(["ok" => true, "members" => $out, "total" => count($out)]);
    }

    private static function conversations(PDO $pdo, array $scope, array $me, array $parts, string $method): void
    {
        if (count($parts) === 1 && $method === "GET") {
            Json::out(["ok" => true, "items" => Conv::mine($pdo, $scope, $me)]);
        }
        if (count($parts) === 3 && $parts[2] === "messages") {
            $conv = self::convOf($pdo, $scope, $me, (int) $parts[1]);
            if ($method === "GET") {
                $limit = max(1, min(200, (int) ($_GET["limit"] ?? 30)));
                $since = (int) ($_GET["since"] ?? 0);
                $before = (int) ($_GET["before"] ?? 0);
                $ids = (int) $conv["is_public"] === 1 ? Conv::publicConvIds($pdo, $scope) : [(int) $conv["id"]];
                $rows = self::msgs($pdo, $ids ?: [(int) $conv["id"]], $limit, $since, $before);
                Json::out([
                    "ok" => true, "conv" => (int) $conv["id"], "messages" => self::light($rows),
                    "oldestId" => $rows ? (int) $rows[0]["id"] : 0,
                    "lastId" => $rows ? (int) $rows[count($rows) - 1]["id"] : $since,
                    "more" => count($rows) >= $limit,
                ]);
            }
            if ($method === "POST") {
                $b = self::body();
                $r = self::insert($pdo, $scope, $me, $conv, $b);
                Json::out(["ok" => true] + $r);
            }
        }
        self::fail("conversations 只认 GET /conversations 和 GET|POST /conversations/{id}/messages", 404);
    }

    private static function wait(PDO $pdo, array $scope, array $me): void
    {
        $convId = (int) ($_GET["conv"] ?? 0);
        $conv = $convId > 0 ? self::convOf($pdo, $scope, $me, $convId) : Conv::publicConv($pdo, $scope);
        if (!$conv) self::fail("没有这个会话");
        $ids = (int) $conv["is_public"] === 1 ? Conv::publicConvIds($pdo, $scope) : [(int) $conv["id"]];
        $ids = $ids ?: [(int) $conv["id"]];
        $since = (int) ($_GET["since"] ?? 0);
        if ($since <= 0) $since = self::maxId($pdo, $ids);
        $seconds = max(1, min(25, (int) ($_GET["seconds"] ?? 20)));
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $rows = self::msgs($pdo, $ids, 50, $since, 0);
            if ($rows) {
                Json::out(["ok" => true, "messages" => self::light($rows), "lastId" => (int) $rows[count($rows) - 1]["id"], "timeout" => false]);
            }
            usleep(250000);
        }
        Json::out(["ok" => true, "messages" => [], "lastId" => $since, "timeout" => true]);
    }

    private static function recall(PDO $pdo, array $scope, array $me, int $id): void
    {
        $st = $pdo->prepare("SELECT * FROM messages WHERE id = ? AND scope_id = ? LIMIT 1");
        $st->execute([$id, (int) $scope["id"]]);
        $msg = $st->fetch();
        if (!$msg) self::fail("消息不存在");
        if (!empty($msg["deleted_at"])) self::fail("已经撤回过了");
        if ((int) $msg["member_id"] !== (int) $me["id"]) self::fail("只能撤回自己的消息");
        $pdo->prepare("UPDATE messages SET deleted_at = ?, deleted_by = ? WHERE id = ?")
            ->execute([lg_now(), (int) $me["id"], $id]);
        Json::out(["ok" => true, "id" => $id]);
    }

    private static function dm(PDO $pdo, array $scope, array $me, array $parts, string $method): void
    {
        if (count($parts) === 1 && $method === "GET") {
            $other = self::findMember($pdo, $scope, (string) ($_GET["name"] ?? ""));
            if ($other === null) self::fail("找不到这个人");
            $conv = self::dmConv($pdo, $scope, $me, $other);
            Json::out(["ok" => true, "conv" => (int) $conv["id"], "with" => self::mid($other), "name" => (string) $other["name"]]);
        }
        if (count($parts) === 2 && $parts[1] === "messages" && $method === "POST") {
            $b = self::body();
            $other = self::findMember($pdo, $scope, (string) ($b["name"] ?? ""));
            if ($other === null) self::fail("找不到这个人");
            $conv = self::dmConv($pdo, $scope, $me, $other);
            $r = self::insert($pdo, $scope, $me, $conv, $b);
            Json::out(["ok" => true] + $r);
        }
        self::fail("dm 只认 GET /dm?name= 和 POST /dm/messages", 404);
    }

    private static function friends(PDO $pdo, array $scope, array $me, string $method): void
    {
        if ($method === "GET") {
            $raw = Social::listFor($pdo, $scope, $me, false);
            $out = ["friends" => [], "incoming" => [], "outgoing" => []];
            foreach ($raw as $k => $list) {
                foreach ($list as $row) {
                    $out[$k][] = ["id" => "h_" . (int) $row["id"], "name" => Conv::nameOf($pdo, (int) $row["id"]), "at" => (int) ($row["at"] ?? 0)];
                }
            }
            Json::out(["ok" => true] + $out);
        }
        if ($method === "POST") {
            $b = self::body();
            $action = strtolower(trim((string) ($b["action"] ?? "")));
            $other = self::findMember($pdo, $scope, (string) ($b["name"] ?? ""));
            if ($other === null) self::fail("找不到这个人");
            $id = (int) $other["id"];
            $res = Json::capture(function () use ($pdo, $scope, $me, $action, $id): void {
                match ($action) {
                    "request" => Social::request($pdo, $scope, $me, $id),
                    "accept"  => Social::accept($pdo, $scope, $me, $id),
                    "reject"  => Social::reject($pdo, $scope, $me, $id),
                    "remove"  => Social::remove($pdo, $scope, $me, $id),
                    default   => Json::fail("action 只认 request / accept / reject / remove"),
                };
            });
            if (!$res["ok"]) self::fail((string) $res["err"]);
            Json::out(["ok" => true, "action" => $action, "with" => (string) $other["name"]]);
        }
        self::fail("friends 只认 GET / POST", 404);
    }

    private static function moments(PDO $pdo, array $scope, array $me, array $parts, string $method): void
    {
        $path = "moments";
        $m = $method;
        $in = [];
        if (count($parts) === 1 && $method === "GET") {
            // timeline
        } elseif (count($parts) === 1 && $method === "POST") {
            $b = self::body();
            $in = ["text" => (string) ($b["text"] ?? ""), "images" => $b["images"] ?? [], "tags" => $b["tags"] ?? []];
        } elseif (count($parts) === 3 && $parts[0] === "moments" && $method === "POST") {
            $path = $parts[2] === "like" ? "like" : ($parts[2] === "comment" ? "comment" : "");
            if ($path === "") self::fail("没有这个接口", 404);
            $b = self::body();
            $in = ["id" => (int) $parts[1], "text" => (string) ($b["text"] ?? "")];
        } else {
            self::fail("moments 只认 GET|POST /moments 和 POST /moments/{id}/like|comment", 404);
        }
        $data = self::viaCapture($m, $in, function () use ($pdo, $scope, $path): void {
            Moments::handle($pdo, $scope, $path);
        });
        Json::out(["ok" => true] + $data);
    }

    private static function notices(PDO $pdo, array $scope, array $me, array $parts, string $method): void
    {
        if (count($parts) === 1 && $method === "GET") {
            $data = self::viaCapture("GET", [], function () use ($pdo, $scope): void {
                Notices::handle($pdo, $scope, "notices");
            });
            Json::out(["ok" => true] + $data);
        }
        if (count($parts) === 3 && $parts[2] === "read" && $method === "POST") {
            $data = self::viaCapture("POST", ["op" => "read", "id" => (int) $parts[1]], function () use ($pdo, $scope): void {
                Notices::handle($pdo, $scope, "notices");
            });
            Json::out(["ok" => true] + $data);
        }
        self::fail("notices 只认 GET /notices 和 POST /notices/{id}/read", 404);
    }

    private static function profile(PDO $pdo, array $me, string $method): void
    {
        if ($method === "GET") {
            Json::out([
                "ok" => true, "id" => self::mid($me), "name" => (string) $me["name"],
                "adapter" => (string) ($me["adapter"] ?? ""), "bio" => (string) ($me["bio"] ?? ""),
                "avatar" => self::abs((string) ($me["avatar"] ?? "")),
            ]);
        }
        if ($method === "PUT" || $method === "POST") {
            $b = self::body();
            $bio = trim((string) ($b["bio"] ?? ""));
            if (mb_strlen($bio) > 500) self::fail("简介最多 500 字");
            $pdo->prepare("UPDATE members SET bio = ? WHERE id = ?")->execute([$bio, (int) $me["id"]]);
            Json::out(["ok" => true, "bio" => $bio]);
        }
        self::fail("profile 只认 GET / PUT", 404);
    }

    private static function upload(PDO $pdo, array $me): void
    {
        $b = self::body();
        $data = (string) ($b["data"] ?? "");
        if (!preg_match('#^data:(image/(png|jpe?g|gif|webp)|audio/[a-z0-9.+-]+|video/[a-z0-9.+-]+);base64,(.+)$#i', $data, $mm)) {
            self::fail("data 必须是 data:<类型>;base64,<内容>");
        }
        $raw = base64_decode($mm[3], true);
        if ($raw === false || $raw === "") self::fail("内容解不出来");
        if (strlen($raw) > 8 * 1024 * 1024) self::fail("文件最大 8MB");
        $mime = strtolower($mm[1]);
        $ext = match (true) {
            $mime === "image/png" => "png",
            $mime === "image/jpeg" => "jpg",
            $mime === "image/gif" => "gif",
            $mime === "image/webp" => "webp",
            str_starts_with($mime, "audio/") => "m4a",
            default => "bin",
        };
        $dir = PUBLIC_ROOT . "/uploads/" . date("Y") . "/" . date("m");
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) self::fail("上传目录建不出来");
        $name = bin2hex(random_bytes(8)) . "." . $ext;
        if (file_put_contents($dir . "/" . $name, $raw) === false) self::fail("保存失败");
        $url = self::abs("/uploads/" . date("Y") . "/" . date("m") . "/" . $name);
        if ((string) ($b["kind"] ?? "") === "avatar") {
            $pdo->prepare("UPDATE members SET avatar = ? WHERE id = ?")->execute([$url, (int) $me["id"]]);
        }
        Json::out(["ok" => true, "url" => $url, "size" => strlen($raw)]);
    }

    // ---------- 内部 ----------

    private static function convOf(PDO $pdo, array $scope, array $me, int $id): array
    {
        $c = Conv::convById($pdo, $id);
        if (!$c) self::fail("会话不存在");
        if ((int) $c["is_public"] !== 1 && !Conv::isMember($pdo, $c, (int) $me["id"])) self::fail("你不在这个会话里");
        return $c;
    }

    private static function dmConv(PDO $pdo, array $scope, array $me, array $other): array
    {
        if ((int) $other["id"] === (int) $me["id"]) self::fail("不能和自己私聊");
        $a = (int) $me["id"];
        $b = (int) $other["id"];
        [$x, $y] = $a < $b ? [$a, $b] : [$b, $a];
        $st = $pdo->prepare("SELECT COUNT(*) FROM friends WHERE a_id = ? AND b_id = ? AND status = 'accepted'");
        $st->execute([$x, $y]);
        if ((int) $st->fetchColumn() === 0) self::fail("先加好友才能私聊");
        return Conv::ensureDm($pdo, $scope, $a, $b, $a);
    }

    private static function findMember(PDO $pdo, array $scope, string $name): ?array
    {
        $name = trim($name);
        if ($name === "") return null;
        if (preg_match('/^[ha]_(\d+)$/i', $name, $m)) {
            $st = $pdo->prepare("SELECT * FROM members WHERE id = ? AND deleted_at IS NULL LIMIT 1");
            $st->execute([(int) $m[1]]);
            $row = $st->fetch();
            return $row ?: null;
        }
        $st = $pdo->prepare("SELECT * FROM members WHERE scope_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([(int) $scope["id"], $name]);
        $row = $st->fetch();
        return $row ?: null;
    }

    private static function msgs(PDO $pdo, array $convIds, int $limit, int $since, int $before): array
    {
        $ids = array_values(array_filter(array_map("intval", $convIds), fn($x) => $x > 0));
        if (!$ids) return [];
        $in = implode(",", array_fill(0, count($ids), "?"));
        $sql = "SELECT m.*, mem.name AS mname, mem.kind AS mkind FROM messages m"
            . " LEFT JOIN members mem ON mem.id = m.member_id WHERE m.conv_id IN ($in)";
        $args = $ids;
        if ($since > 0) {
            $sql .= " AND m.id > ? ORDER BY m.id ASC LIMIT " . $limit;
            $args[] = $since;
        } elseif ($before > 0) {
            $sql .= " AND m.id < ? ORDER BY m.id DESC LIMIT " . $limit;
            $args[] = $before;
        } else {
            $sql .= " ORDER BY m.id DESC LIMIT " . $limit;
        }
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll();
        return $since <= 0 ? array_reverse($rows) : $rows;
    }

    private static function maxId(PDO $pdo, array $convIds): int
    {
        $ids = array_values(array_filter(array_map("intval", $convIds), fn($x) => $x > 0));
        if (!$ids) return 0;
        $in = implode(",", array_fill(0, count($ids), "?"));
        $st = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM messages WHERE conv_id IN ($in)");
        $st->execute($ids);
        return (int) $st->fetchColumn();
    }

    private static function insert(PDO $pdo, array $scope, array $me, array $conv, array $b): array
    {
        $text  = trim((string) ($b["text"] ?? ""));
        $img   = trim((string) ($b["img"] ?? ""));
        $voice = trim((string) ($b["voice"] ?? ""));
        $dur   = max(0, min(600, (int) ($b["dur"] ?? 0)));
        $card  = is_array($b["card"] ?? null) ? $b["card"] : null;
        $model = mb_substr(trim((string) ($b["model"] ?? "")), 0, 40);
        if ($text === "" && $img === "" && $voice === "" && !$card) self::fail("不能发空消息");
        if (mb_strlen($text) > 10000) self::fail("单条消息最多 10000 字");

        $replyTo = null;
        $reply = (int) ($b["reply"] ?? 0);
        if ($reply > 0) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE id = ? AND conv_id = ?");
            $chk->execute([$reply, (int) $conv["id"]]);
            if ((int) $chk->fetchColumn() > 0) $replyTo = $reply;
        }

        $ts = lg_now();
        $pdo->prepare(
            "INSERT INTO messages (scope_id, conv_id, member_id, ts_ms, text, img, card_json, reply_to, voice, dur, model)"
            . " VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            (int) $scope["id"], (int) $conv["id"], (int) $me["id"], $ts, $text,
            $img !== "" ? $img : null,
            $card ? json_encode($card, JSON_UNESCAPED_UNICODE) : null,
            $replyTo, $voice !== "" ? $voice : null, $dur, $model !== "" ? $model : null,
        ]);
        $id = (int) $pdo->lastInsertId();
        Conv::touch($pdo, (int) $conv["id"], $id, $ts);
        return ["id" => $id, "ts" => $ts, "conv" => (int) $conv["id"]];
    }

    private static function light(array $rows): array
    {
        $out = [];
        foreach ($rows as $m) {
            $t = (string) ($m["text"] ?? "");
            if (!empty($m["text_auto"])) {
                $t = trim((string) preg_replace('/(\s*\[(图片|语音|卡片)\]\([^)]*\)(\s*\d+")?)+$/u', "", $t));
            }
            $card = null;
            if (!empty($m["card_json"])) {
                $dec = json_decode((string) $m["card_json"], true);
                if (is_array($dec)) $card = $dec;
            }
            $out[] = [
                "id" => (int) $m["id"], "from" => (string) ($m["mname"] ?? ""),
                "kind" => (string) ($m["mkind"] ?? "human"), "text" => $t,
                "img" => self::abs((string) ($m["img"] ?? "")), "voice" => self::abs((string) ($m["voice"] ?? "")),
                "dur" => (int) ($m["dur"] ?? 0), "card" => $card,
                "reply" => (int) ($m["reply_to"] ?? 0) ?: null,
                "ts" => (int) ($m["ts_ms"] ?? 0),
                "recalled" => empty($m["deleted_at"]) ? 0 : 1,
            ];
        }
        return $out;
    }

    private static function viaCapture(string $method, array $input, callable $fn): array
    {
        $prev = $_SERVER["REQUEST_METHOD"] ?? "GET";
        $_SERVER["REQUEST_METHOD"] = $method;
        Input::inject($input);
        $res = Json::capture($fn);
        $_SERVER["REQUEST_METHOD"] = $prev;
        if (!$res["ok"]) self::fail((string) $res["err"]);
        return is_array($res["data"]) ? $res["data"] : [];
    }

    private static function body(): array
    {
        return Input::body();
    }

    private static function mid(array $m): string
    {
        return ((string) ($m["kind"] ?? "human") === "ai" ? "a_" : "h_") . (int) $m["id"];
    }

    private static function abs(string $path): string
    {
        $path = trim($path);
        if ($path === "") return "";
        if (preg_match('#^(https?://|data:)#i', $path)) return $path;
        return Site::baseUrl() . "/" . ltrim($path, "/");
    }

    private static function fail(string $msg, int $code = 400): void
    {
        Json::out(["ok" => false, "err" => $msg], $code);
    }
}
