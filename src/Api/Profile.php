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
        $m = self::requireMember($pdo);
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
        }
        Json::ok(["profile" => self::out($pdo, $m), "me" => self::meOut($pdo, $scope, $m)]);
    }

    /** 单个成员资料：必须同 scope，注销的不给看 */
    private static function show(PDO $pdo, array $scope, string $raw): void {
        $me = self::requireMember($pdo);
        $id = self::parseMid($raw);
        $target = $id > 0 ? Social::scopeMember($pdo, $scope, $id) : null;
        if ($target === null) Json::fail("成员不存在");

        $out = self::out($pdo, $target);
        $out["isMe"] = (int) $target["id"] === (int) $me["id"];
        $out["friends"] = self::friendState($pdo, (int) $me["id"], (int) $target["id"]);
        Json::ok(["profile" => $out]);
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
            "kind"   => (string) $m["kind"],
            "role"   => self::roleOf($pdo, $m),
            "color"  => self::colorOf((int) $m["id"]),
            "bio"    => (string) ($m["bio"] ?? ""),
            "avatar" => (string) ($m["avatar"] ?? ""),
            "joined" => (int) ($m["created_at"] ?? 0),
        ];
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
            "invite"   => (string) $scope["invite"],
        ];
    }

    /** 简介清洗：保留换行与空格，只把 CRLF 归一、去掉 \0 */
    private static function cleanBio(string $raw): string {
        if (strlen($raw) > 200000) return str_repeat("a", self::BIO_MAX + 1);
        $bio = str_replace(["\r\n", "\r"], "\n", $raw);
        return str_replace("\0", "", $bio);
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
    private static function requireMember(PDO $pdo): array {
        $token = trim((string) Input::get("token"));
        $m = $token !== "" ? Compat::memberByTokenPublic($pdo, $token) : Session::member($pdo);
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
