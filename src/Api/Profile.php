<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Http\Json;
use LightGroup\Store\Db;
use PDO;

/**
 * 用户资料：读 / 改自己的资料（简介是 markdown），以及看单个人的资料。
 *
 *   GET  api/profile        我的资料，返回 profile + me
 *   POST api/profile        改昵称（name）/ 简介（bio，兼容老前端的 intro）/ 头像（avatar）
 *   GET  api/u/{h_1|a_2}    同一个群里的任意成员资料（供悬浮资料卡与资料页用）
 *
 * 只认这两个路径，别的返回 false 交回 Compat::handle。
 * 简介原样存 markdown（只把 CRLF 归一成 LF、去掉 \0），上限 2000 字。
 */
final class Profile {
    /** 简介上限：markdown 比纯文本占字，放宽到 2000 */
    public const BIO_MAX = 2000;

    /** 入口：命中就自己处理并返回 true */
    public static function handle(string $slug, string $path): bool {
        $path = trim($path, "/");
        $isUser = preg_match('#^api/u/([A-Za-z0-9_]+)$#', $path, $hit) === 1;
        if (!$isUser && $path !== "api/profile") return false;

        $pdo = Db::connect();
        $scope = Compat::scopeBySlugPublic($pdo, $slug);
        if ($scope === null) {
            Json::fail("群不存在");
            return true;
        }

        if ($isUser) self::show($pdo, $scope, (string) ($hit[1] ?? ""));
        else self::mine($pdo, $scope);
        return true;
    }

    /** 自己的资料：GET 读，POST 改 */
    private static function mine(PDO $pdo, array $scope): void {
        $m = self::requireMember($pdo, $scope);
        // token 只证明「你是某个群的成员」，不证明「你是这个群的」。
        // 不查 scope 的话，拿 A 群 token 请求 /g/<B 群 slug>/api/profile
        // 就能读到 B 群的邀请码（返回体里的 me.invite），报告 P1。
        if ((int) ($m["scope_id"] ?? 0) !== (int) $scope["id"]) Json::fail("这个身份不属于本群");
        if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
            $name = trim(self::field("name", ""));
            $bio = self::field("bio", null);
            // 老版「我的资料」表单发的是 intro，兼容一下，不然保存简介一直没反应
            if ($bio === null) $bio = self::field("intro", null);
            $avatar = self::field("avatar", "");
            // 「允许他人把我拉进群」：布尔开关，没传就当没改
            $allow = self::field("allowInvite", null);
            // 简介模式：默认纯文本；只有显式切到 markdown 才存 markdown
            $bioMode = self::field("bioMode", null);
            $birthday = self::field("birthday", null);
            $gender = self::field("gender", null);
            $signature = self::field("signature", null);
            $email = self::field("email", null);
            $tagsRaw = self::rawField("tags", null);
            $discover = self::field("discover", null);

            if ($name !== "" && $name !== (string) $m["name"]) {
                if (mb_strlen($name) > 24) Json::fail("昵称最多 24 个字");
                $st = $pdo->prepare("SELECT id FROM members WHERE scope_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1");
                $st->execute([(int) $m["scope_id"], $name]);
                if ($st->fetch()) Json::fail("这个昵称有人在用了");
                $pdo->prepare("UPDATE members SET name = ? WHERE id = ?")->execute([$name, (int) $m["id"]]);
                $m["name"] = $name;
            }
            if ($bio !== null) {
                $bio = self::cleanBio($bio);
                if (mb_strlen($bio) > self::BIO_MAX) Json::fail("简介最多 " . self::BIO_MAX . " 字");
                $pdo->prepare("UPDATE members SET bio = ? WHERE id = ?")->execute([$bio, (int) $m["id"]]);
                $m["bio"] = $bio;
            }
            if ($avatar !== "") {
                $pdo->prepare("UPDATE members SET avatar = ? WHERE id = ?")->execute([$avatar, (int) $m["id"]]);
                $m["avatar"] = $avatar;
            }
            if ($allow !== null) {
                $on = in_array(strtolower(trim($allow)), ["1", "true", "on", "yes"], true) ? 1 : 0;
                $pdo->prepare("UPDATE members SET allow_invite = ? WHERE id = ?")->execute([$on, (int) $m["id"]]);
                $m["allow_invite"] = $on;
            }
            if ($bioMode !== null) {
                $mode = strtolower(trim($bioMode)) === "markdown" ? "markdown" : "plain";
                $pdo->prepare("UPDATE members SET bio_mode = ? WHERE id = ?")->execute([$mode, (int) $m["id"]]);
                $m["bio_mode"] = $mode;
            }
            if ($birthday !== null) {
                $birthday = trim($birthday);
                if ($birthday !== "") self::checkBirthday($birthday);
                $pdo->prepare("UPDATE members SET birthday = ? WHERE id = ?")->execute([$birthday === "" ? null : $birthday, (int) $m["id"]]);
                $m["birthday"] = $birthday;
            }
            if ($gender !== null) {
                $gender = strtolower(trim($gender));
                if (!in_array($gender, ["", "male", "female", "other"], true)) Json::fail("性别只能是 male / female / other / 不填");
                $pdo->prepare("UPDATE members SET gender = ? WHERE id = ?")->execute([$gender === "" ? null : $gender, (int) $m["id"]]);
                $m["gender"] = $gender;
            }
            if ($signature !== null) {
                $signature = trim(str_replace(["\r", "\n"], " ", $signature));
                if (mb_strlen($signature) > 80) Json::fail("签名最多 80 个字");
                $pdo->prepare("UPDATE members SET signature = ? WHERE id = ?")->execute([$signature === "" ? null : $signature, (int) $m["id"]]);
                $m["signature"] = $signature;
            }
            if ($email !== null) {
                $email = trim($email);
                if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) Json::fail("邮箱格式不对");
                if (mb_strlen($email) > 191) Json::fail("邮箱太长了");
                $pdo->prepare("UPDATE members SET email = ? WHERE id = ?")->execute([$email === "" ? null : $email, (int) $m["id"]]);
                $m["email"] = $email;
            }
            if ($tagsRaw !== null) {
                $tags = self::cleanTags($tagsRaw);
                $pdo->prepare("UPDATE members SET tags = ? WHERE id = ?")->execute([$tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null, (int) $m["id"]]);
                $m["tags"] = $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : "";
            }
            if ($discover !== null) {
                $on = in_array(strtolower(trim($discover)), ["1", "true", "on", "yes"], true) ? 1 : 0;
                $pdo->prepare("UPDATE members SET discover = ? WHERE id = ?")->execute([$on, (int) $m["id"]]);
                $m["discover"] = $on;
            }
        }
        Json::ok(["profile" => self::out($pdo, $m), "me" => self::meOut($pdo, $scope, $m)]);
    }

    /**
     * 单个成员资料。本群的正常给；圈子互通时消息流里会出现别的群的成员，
     * 那种头像还能点开看一眼，但加好友、私聊只在本群内成立，所以带一个 scope 标记，
     * 前端据此把按钮换掉。注销的不给看。
     */
    private static function show(PDO $pdo, array $scope, string $raw): void {
        $me = self::requireMember($pdo, $scope);
        $id = self::parseMid($raw);
        $target = $id > 0 ? Social::scopeMember($pdo, $scope, $id) : null;

        if ($target === null) {
            // deleted_at 老数据是 NULL、新数据写 0，两种都要认
            $st = $pdo->prepare("SELECT * FROM members WHERE id = ? AND (deleted_at IS NULL OR deleted_at = 0) LIMIT 1");
            $st->execute([$id]);
            $other = $id > 0 ? $st->fetch() : null;
            if (!$other) Json::fail("成员不存在");
            $out = self::out($pdo, $other);
            $out["isMe"] = false;
            $out["friends"] = "none";
            $out["scope"] = self::sameCluster($pdo, $scope, (int) $other["scope_id"]) ? "cluster" : "other";
            Json::ok(["profile" => $out]);
            return;
        }

        $out = self::out($pdo, $target);
        $out["isMe"] = (int) $target["id"] === (int) $me["id"];
        $out["friends"] = self::friendState($pdo, (int) $me["id"], (int) $target["id"]);
        // relation 比 friends 细：多出 blockedByMe / blockedMe 与备注，前端据此换按钮
        $out["relation"] = Social::relation($pdo, (int) $me["id"], (int) $target["id"]);
        $out["dm"] = self::dmWith($pdo, (int) $me["id"], (int) $target["id"]);
        $out["scope"] = "same";
        Json::ok(["profile" => $out]);
    }

    /**
     * 我和这个人的私聊会话（有就给 id 与置顶状态）。
     * 没建过返回 null —— 资料页的「发消息」这时才去调 api/dm/open 现开。
     */
    private static function dmWith(PDO $pdo, int $a, int $b): ?array {
        if ($a <= 0 || $b <= 0 || $a === $b) return null;
        [$x, $y] = $a < $b ? [$a, $b] : [$b, $a];
        $st = $pdo->prepare(
            "SELECT c.id, cm.pinned_at FROM conversations c
             JOIN conv_members cm ON cm.conv_id = c.id AND cm.member_id = ?
             WHERE c.dm_key = ? AND c.dissolved_at IS NULL LIMIT 1"
        );
        $st->execute([$a, $x . "_" . $y]);
        $row = $st->fetch();
        if (!$row) return null;
        return ["convId" => (int) $row["id"], "pinned" => (int) ($row["pinned_at"] ?? 0) > 0];
    }

    /** 两个人所在的群是不是在同一个互通圈子里 */
    private static function sameCluster(PDO $pdo, array $scope, int $otherScope): bool {
        if ($otherScope <= 0) return false;
        if ($otherScope === (int) $scope["id"]) return true;
        $st = $pdo->prepare("SELECT cluster_id FROM scopes WHERE id IN (?, ?)");
        $st->execute([(int) $scope["id"], $otherScope]);
        $ids = [];
        foreach ($st->fetchAll() as $r) {
            if ($r["cluster_id"] === null) return false;
            $ids[] = (int) $r["cluster_id"];
        }
        return count($ids) === 2 && $ids[0] === $ids[1];
    }

    /** none / outgoing / incoming / friend / me */
    private static function friendState(PDO $pdo, int $mine, int $other): string {
        if ($mine <= 0 || $other <= 0) return "none";
        if ($mine === $other) return "me";
        $row = Social::row($pdo, $mine, $other);
        if ($row === null) return "none";
        if ((string) $row["status"] === "accepted") return "friend";
        if ((string) $row["status"] === "pending") {
            return (int) $row["requested_by"] === $mine ? "outgoing" : "incoming";
        }
        return "none";
    }

    /**
     * 对外资料。id 仍是内部数字（老接口一直这么给，别改坏第三方客户端），
     * 另外多给一个 uid（h_1 / a_2），前端路由用它。
     */
    private static function out(PDO $pdo, array $m): array {
        $out = [
            "id"     => (int) $m["id"],
            "uid"    => self::mid($m),
            "name"   => (string) $m["name"],
            // 对外用户 ID：资料页显示成 @username，登录名不再只留在 me 接口
            "username" => (string) ($m["username"] ?? $m["name"]),
            "kind"   => (string) $m["kind"],
            "role"   => self::roleOf($pdo, $m),
            "color"  => self::colorOf((int) $m["id"]),
            "bio"    => (string) ($m["bio"] ?? ""),
            "bioMode" => (string) ($m["bio_mode"] ?? "plain") === "markdown" ? "markdown" : "plain",
            "tags"    => self::tagsOf($m),
            "birthday" => (string) ($m["birthday"] ?? ""),
            "age"     => self::ageOf((string) ($m["birthday"] ?? "")),
            "gender"  => (string) ($m["gender"] ?? ""),
            "signature" => (string) ($m["signature"] ?? ""),
            "discover" => (int) ($m["discover"] ?? 1) === 1,
            "avatar" => (string) ($m["avatar"] ?? ""),
            "joined" => (int) ($m["created_at"] ?? 0),
        ];
        if ((string) $m["kind"] === "ai") {
            $out["adapter"] = self::adapterOf($m);
        }
        if ((string) $m["kind"] === "ai" && !empty($m["owner_id"])) {
            $st = $pdo->prepare("SELECT name FROM members WHERE id = ? LIMIT 1");
            $st->execute([(int) $m["owner_id"]]);
            $o = $st->fetch();
            if ($o) $out["owner"] = (string) $o["name"];
        }
        return $out;
    }

    /** 和 api/me 的 me 保持同一形状，前端拿到后直接替换 S.me */
    private static function meOut(PDO $pdo, array $scope, array $m): array {
        $isAi = (string) $m["kind"] === "ai";
        $owner = "";
        if ($isAi && !empty($m["owner_id"])) {
            $st = $pdo->prepare("SELECT name FROM members WHERE id = ? LIMIT 1");
            $st->execute([(int) $m["owner_id"]]);
            $row = $st->fetch();
            $owner = $row ? (string) $row["name"] : "";
        }
        return [
            "id"       => self::mid($m),
            "name"     => (string) $m["name"],
            // 登录名只有本人看得到（me 接口），不进公开资料
            "username" => (string) ($m["username"] ?? $m["name"]),
            "kind"     => (string) $m["kind"],
            "color"    => self::colorOf((int) $m["id"]),
            "emoji"    => "",
            "owner"    => $owner,
            "ownerId"  => $isAi ? "h_" . (int) $m["owner_id"] : "",
            "bot"      => $isAi,
            "avatar"   => (string) ($m["avatar"] ?? ""),
            "hasWake"  => false,
            "wakeOnly" => "all",
            "role"     => self::roleOf($pdo, $m),
            "model"    => "",
            "bio"      => (string) ($m["bio"] ?? ""),
            "bioMode"  => (string) ($m["bio_mode"] ?? "plain") === "markdown" ? "markdown" : "plain",
            "tags"     => self::tagsOf($m),
            "birthday" => (string) ($m["birthday"] ?? ""),
            "age"      => self::ageOf((string) ($m["birthday"] ?? "")),
            "gender"   => (string) ($m["gender"] ?? ""),
            "signature"=> (string) ($m["signature"] ?? ""),
            // 邮箱只给本人：公开资料（out）不带它
            "email"    => (string) ($m["email"] ?? ""),
            "discover" => (int) ($m["discover"] ?? 1) === 1,
            "invite"   => (string) $scope["invite"],
            "adapter"  => $isAi ? self::adapterOf($m) : "",
            // 「允许他人把我拉进群」：建私群选人时后端会按这个拦
            "allowInvite" => (int) ($m["allow_invite"] ?? 1) === 1,
        ];
    }

    /** AI 的适配器：xsj 只能收发消息，mcp 是完整账号，api 走 REST */
    private static function adapterOf(array $m): string {
        $a = (string) ($m["adapter"] ?? "");
        return in_array($a, ["xsj", "mcp", "api"], true) ? $a : "mcp";
    }

    /** 简介清洗：保留换行与空格，只把 CRLF 归一、去掉 \0 */
    private static function cleanBio(string $raw): string {
        if (strlen($raw) > 200000) return str_repeat("a", self::BIO_MAX + 1);
        $bio = str_replace(["\r\n", "\r"], "\n", $raw);
        return str_replace("\0", "", $bio);
    }

    /** 取值但允许数组（标签用），body 优先 */
    private static function rawField(string $key, $default) {
        $body = Input::body();
        if (array_key_exists($key, $body)) return $body[$key];
        if (array_key_exists($key, $_GET)) return $_GET[$key];
        return $default;
    }

    /** 生日必须是 1900 年至今的真实日期 */
    private static function checkBirthday(string $v): void {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) Json::fail("生日要写成 2000-01-31 这样");
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
        if ($y < 1900 || !checkdate($mo, $d, $y)) Json::fail("生日不是有效日期");
        $today = (int) date("Ymd");
        if ($y * 10000 + $mo * 100 + $d > $today) Json::fail("生日不能填未来的日期");
    }

    /** 标签：1-5 个，每个最多 16 字，去掉开头的 #，去重 */
    private static function cleanTags($raw): array {
        $list = [];
        if (is_array($raw)) {
            $list = $raw;
        } elseif (is_string($raw) && trim($raw) !== "") {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $list = $decoded;
            else $list = preg_split('/[,，\s]+/u', $raw) ?: [];
        }
        $out = [];
        foreach ($list as $t) {
            if (!is_scalar($t)) continue;
            $t = trim((string) $t);
            $t = ltrim($t, "#＃");
            $t = trim($t);
            if ($t === "") continue;
            if (mb_strlen($t) > 16) Json::fail("单个标签最多 16 个字");
            if (in_array($t, $out, true)) continue;
            $out[] = $t;
            if (count($out) > 5) Json::fail("标签最多 5 个");
        }
        return $out;
    }

    private static function tagsOf(array $m): array {
        $raw = (string) ($m["tags"] ?? "");
        if ($raw === "") return [];
        $row = json_decode($raw, true);
        if (!is_array($row)) return [];
        $out = [];
        foreach ($row as $t) {
            if (is_scalar($t) && trim((string) $t) !== "") $out[] = (string) $t;
        }
        return array_slice($out, 0, 5);
    }

    /** 按生日算年龄；没填或格式不对返回 0 */
    public static function ageOf(string $birthday): int {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthday, $m)) return 0;
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
        if (!checkdate($mo, $d, $y)) return 0;
        $ty = (int) date("Y"); $tm = (int) date("n"); $td = (int) date("j");
        $age = $ty - $y;
        if ($tm < $mo || ($tm === $mo && $td < $d)) $age--;
        return $age >= 0 && $age <= 150 ? $age : 0;
    }

    /** 和 Input::get 一样先看 JSON body 再看 query，但不 trim（markdown 的缩进要留着） */
    private static function field(string $key, ?string $default): ?string {
        $body = Input::body();
        if (array_key_exists($key, $body)) {
            $v = $body[$key];
            return is_scalar($v) ? (string) $v : $default;
        }
        if (array_key_exists($key, $_GET)) {
            $v = $_GET[$key];
            return is_scalar($v) ? (string) $v : $default;
        }
        return $default;
    }

    /** 当前请求的成员：token 优先，其次网页会话（与 Compat::requireMember 一致） */
    private static function requireMember(PDO $pdo, array $scope): array {
        $token = trim((string) Input::get("token"));
        $m = $token !== "" ? Compat::memberByTokenPublic($pdo, $token) : Compat::adoptScope($pdo, $scope);
        if ($m === null) {
            Json::fail("登录已失效，请重新进群");
            return [];
        }
        if (!empty($m["deleted_at"])) Json::fail("账号已注销");
        if ((int) $m["banned"] === 1) Json::fail("该账号已被封禁");
        return $m;
    }

    private static function roleOf(PDO $pdo, array $m): string {
        $aid = (int) ($m["account_id"] ?? 0);
        if ($aid <= 0) return "member";
        $st = $pdo->prepare("SELECT role, scope_id FROM accounts WHERE id = ? LIMIT 1");
        $st->execute([$aid]);
        $a = $st->fetch();
        if (!$a) return "member";
        if ($a["role"] === "super") return "super";
        if ($a["role"] === "admin" && (int) $a["scope_id"] === (int) $m["scope_id"]) return "admin";
        return "member";
    }

    private const COLORS = ["#2563eb", "#7c3aed", "#db2777", "#ea580c", "#ca8a04", "#16a34a", "#0891b2", "#475569", "#9f1239", "#4d7c0f"];

    private static function colorOf(int $id): string {
        $n = count(self::COLORS);
        return self::COLORS[(($id % $n) + $n) % $n];
    }

    private static function mid(array $m): string {
        return ((string) $m["kind"] === "ai" ? "a_" : "h_") . (int) $m["id"];
    }

    private static function parseMid(string $raw): int {
        if (preg_match('/^[ha]_(\d+)$/', $raw, $g)) return (int) $g[1];
        if ($raw !== "" && ctype_digit($raw)) return (int) $raw;
        return 0;
    }
}
