<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Http\Json;
use LightGroup\Store\Db;
use PDO;

final class Compat {
    public static function handle(string $slug, string $path): void {
        $pdo = Db::connect();
        $scope = self::scopeBySlug($pdo, $slug);
        if ($scope === null) Json::fail("群不存在");

        $path = trim($path, "/");

        if (str_starts_with($path, "api/v1/")) {
            self::v1($pdo, $scope, substr($path, 7));
            return;
        }

        // 管理接口只认网页会话
        if (str_starts_with($path, "api/admin/")) {
            Admin::handle($pdo, $scope, substr($path, 10));
            return;
        }

        switch ($path) {
            case "join":      self::join($pdo, $scope); break;
            case "api/link":  self::link($pdo, $scope); break;
            case "api/me":    self::me($pdo, $scope); break;
            case "api/read":  self::read($pdo, $scope); break;
            case "api/fetch": self::fetch($pdo, $scope); break;
            case "api/send":  self::send($pdo, $scope); break;
            case "api/wait":  self::wait($pdo, $scope); break;
            case "api/upload": self::upload($pdo, $scope); break;
            case "api/doc":   self::doc(); break;
            case "api/recall":  self::recall($pdo, $scope); break;
            case "api/profile": self::profile($pdo, $scope); break;
            case "api/styles":  self::styles($pdo, $scope); break;
            case "api/cap":     self::cap($pdo, $scope); break;
            case "api/session": self::sessionLogin($pdo, $scope); break;
            case "api/login":   self::passwordLogin($pdo, $scope); break;
            case "api/logout":  Session::logout(); Json::ok(); break;
            case "api/translate": self::translate($pdo, $scope); break;
            case "api/notice":  self::noticeSet($pdo, $scope); break;
            case "api/pin":     self::pinSet($pdo, $scope); break;
            case "api/todo":    self::todoOp($pdo, $scope); break;
            case "api/conversations": self::conversations($pdo, $scope); break;
            case "api/conv":          self::convGet($pdo, $scope); break;
            case "api/conv/send":     self::convSend($pdo, $scope); break;
            case "api/friends":       self::friendsList($pdo, $scope); break;
            case "api/friends/poll":  self::friendsPoll($pdo, $scope); break;
            case "api/friends/request": self::friendRequest($pdo, $scope); break;
            case "api/friends/accept":  self::friendAccept($pdo, $scope); break;
            case "api/friends/reject":  self::friendReject($pdo, $scope); break;
            case "api/friends/remove":  self::friendRemove($pdo, $scope); break;
            case "api/dm/open":       self::dmOpen($pdo, $scope); break;
            case "api/themes":          self::themes($pdo, $scope); break;
            case "api/theme/set":       self::themeSet($pdo, $scope); break;
            case "api/theme/pack/save": self::themePackSave($pdo, $scope); break;
            case "api/theme/pack/del":  self::themePackDel($pdo, $scope); break;
            default:          Json::fail("未知接口"); break;
        }
    }

    private static function scopeBySlug(PDO $pdo, string $slug): ?array {
        $st = $pdo->prepare("SELECT * FROM scopes WHERE slug = ? AND archived = 0 LIMIT 1");
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function scopeBySlugPublic(PDO $pdo, string $slug): ?array {
        return self::scopeBySlug($pdo, $slug);
    }

    public static function memberByTokenPublic(PDO $pdo, string $token): ?array {
        return self::memberByToken($pdo, $token);
    }

    private static function memberByToken(PDO $pdo, string $token): ?array {
        if ($token === "") return null;
        $st = $pdo->prepare("SELECT * FROM members WHERE token_hash = ? LIMIT 1");
        $st->execute([Token::hash($token)]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** 当前请求的“代为操作”成员：API key 认证通过后挂在这里 */
    private static ?array $acting = null;

    public static function actAs(?array $member): void {
        self::$acting = $member;
    }

    private static function method(): string {
        return strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
    }

    private static function requireMember(PDO $pdo): array {
        if (self::$acting !== null) {
            $m = self::$acting;
            if (!empty($m["deleted_at"])) Json::fail("账号已注销");
            if ((int) $m["banned"] === 1) Json::fail("该账号已被封禁");
            return $m;
        }
        $token = (string) Input::get("token");
        // token 优先（插件 / AI / 第三方客户端），没有 token 才看网页会话
        $m = $token !== "" ? self::memberByToken($pdo, $token) : Session::member($pdo);
        if ($m === null) Json::fail("登录已失效，请重新进群");
        if (!empty($m["deleted_at"])) Json::fail("账号已注销");
        if ((int) $m["banned"] === 1) Json::fail("该账号已被封禁");
        return $m;
    }

    private static function setting(PDO $pdo, string $key, string $default = "0"): string {
        $st = $pdo->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
        $st->execute([$key]);
        $row = $st->fetch();
        return $row ? (string) $row["v"] : $default;
    }

    private static function superName(PDO $pdo): string {
        $st = $pdo->query("SELECT name FROM accounts WHERE role = 'super' LIMIT 1");
        $row = $st->fetch();
        return $row ? (string) $row["name"] : "";
    }

    /**
     * 每人一个稳定的头像底色，按主键取模。
     * 必须和 Profile::COLORS、前端 avatar.js 的 HUES 完全一致：三处各写一套的话，
     * 聊天里的头像和点开资料页的头像会是两种颜色。
     */
    private const COLORS = [
        "#2563eb", "#7c3aed", "#db2777", "#ea580c", "#ca8a04",
        "#16a34a", "#0891b2", "#475569", "#9f1239", "#4d7c0f",
    ];

    private static function colorOf(int $id): string {
        $n = count(self::COLORS);
        return self::COLORS[(($id % $n) + $n) % $n];
    }

    /** 对外成员 id：h_13 / a_14（内部仍是自增整数，对外统一带前缀） */
    private static function mid(array $m): string {
        return ((string) $m["kind"] === "ai" ? "a_" : "h_") . (int) $m["id"];
    }

    /** 解析外部成员 id：h_13 / a_14 / 13，认不出来回 0 */
    private static function parseMid(string $raw): int {
        if (preg_match('/^[ha]_(\d+)$/', $raw, $g)) return (int) $g[1];
        if ($raw !== "" && ctype_digit($raw)) return (int) $raw;
        return 0;
    }

    private static function memberOut(array $m, ?string $ownerName, string $role = ""): array {
        $isAi = (string) $m["kind"] === "ai";
        $out = [
            "id"       => self::mid($m),
            "name"     => (string) $m["name"],
            "kind"     => (string) $m["kind"],
            "color"    => self::colorOf((int) $m["id"]),
            "emoji"    => "",
            "owner"    => "",
            "ownerId"  => "",
            "bot"      => $isAi,
            "avatar"   => (string) ($m["avatar"] ?? ""),
            "hasWake"  => false,
            "wakeOnly" => "all",
            "role"     => $role,
            "badge"    => $role,
            "model"    => "",
        ];
        if ($isAi && $ownerName !== null) {
            $out["owner"]   = $ownerName;
            $out["ownerId"] = "h_" . (int) $m["owner_id"];
        }
        return $out;
    }

    private static function membersOf(PDO $pdo, int $scopeId): array {
        $st = $pdo->prepare("SELECT * FROM members WHERE scope_id = ? AND deleted_at IS NULL ORDER BY id ASC");
        $st->execute([$scopeId]);
        $rows = $st->fetchAll();
        $names = [];
        foreach ($rows as $r) $names[(int) $r["id"]] = $r["name"];
        $roles = self::badgeMap($pdo);
        $out = [];
        foreach ($rows as $r) {
            $owner = isset($r["owner_id"]) && $r["owner_id"] !== null ? ($names[(int) $r["owner_id"]] ?? null) : null;
            $out[] = self::memberOut($r, $owner, $roles[(int) ($r["account_id"] ?? 0)] ?? "");
        }
        return $out;
    }

    /** 成员的角标身份：管理员账号在群里要显示「管理员」「超级管理员」 */
    private static function badgeMap(PDO $pdo): array {
        $out = [];
        foreach ($pdo->query("SELECT id, role FROM accounts")->fetchAll() as $a) {
            $out[(int) $a["id"]] = (string) $a["role"];
        }
        return $out;
    }

    private static function join(PDO $pdo, array $scope): void {
        $name   = Input::get("name");
        $invite = Input::get("invite");
        if ($name === "" || mb_strlen($name) > 24) Json::fail("昵称不能为空，且不超过 24 个字");
        // 邀请码只有这一个秘密，试错次数必须限；不然「邀请码不正确」就是一个可枚举的判据
        self::rateLimit($pdo, "join:" . self::clientIp(), 20, "试得太频繁了，过几分钟再来");
        if ($invite !== (string) $scope["invite"]) Json::fail("邀请码不正确");

        // 门禁开着的时候账号是两层的：OAuth 主账号 → 群里的业务账号。
        // 这里再放行「昵称 + 邀请码」就会造出没有主账号的幽灵成员，
        // 既进不了网页，也管不了，所以直接从脚本入口挡掉。
        if (self::setting($pdo, "auth.mode", "none") === "oauth") {
            Json::fail("本站开启了 OAuth 门禁，请先用第三方账号登录，再在网页里创建群成员");
        }

        if ($name === self::superName($pdo)) Json::fail("该昵称已保留");

        $st = $pdo->prepare("SELECT * FROM members WHERE scope_id = ? AND name = ? LIMIT 1");
        $st->execute([(int) $scope["id"], $name]);
        $exist = $st->fetch();

        if ($exist) {
            if (!empty($exist["deleted_at"])) Json::fail("该昵称已注销");
            if ((int) $exist["banned"] === 1) Json::fail("该昵称已被封禁");
            $plain = Token::issue();
            $up = $pdo->prepare("UPDATE members SET token_hash = ?, last_seen_at = ? WHERE id = ?");
            $up->execute([Token::hash($plain), lg_now(), (int) $exist["id"]]);
            Json::ok([
                "token" => $plain,
                "title" => $scope["title"],
                "members" => self::membersOf($pdo, (int) $scope["id"]),
            ]);
        }

        self::checkHumanQuota($pdo, $scope);

        // 脚本/AI 批量建号会把群刷爆：同一个 IP 10 分钟内只放行一个新账号
        $ip = (string) ($_SERVER["REMOTE_ADDR"] ?? "");
        if ($ip !== "" && !self::ipMayJoin($pdo, $ip)) {
            Json::fail("同一个网络 10 分钟内只能注册一个新账号，请稍后再试");
        }

        $plain = Token::issue();
        // 顺便发一个网页登录用的密码：进群的人不用再去注册一遍
        $pass = lg_pass();
        $ins = $pdo->prepare("INSERT INTO members (scope_id, name, kind, token_hash, pass_hash, created_at, last_seen_at) VALUES (?,?,?,?,?,?,?)");
        $ins->execute([(int) $scope["id"], $name, "human", Token::hash($plain), password_hash($pass, PASSWORD_DEFAULT), lg_now(), lg_now()]);
        $newId = (int) $pdo->lastInsertId();
        self::noteJoinIp($pdo, $ip, $newId);

        Json::ok([
            "token" => $plain,
            "password" => $pass,
            "title" => $scope["title"],
            "members" => self::membersOf($pdo, (int) $scope["id"]),
        ]);
    }

    /** 同一个 IP 10 分钟窗口内是否还能新建账号 */
    private static function ipMayJoin(PDO $pdo, string $ip): bool {
        try {
            $st = $pdo->prepare("SELECT created_at FROM join_guard WHERE ip = ? ORDER BY created_at DESC LIMIT 1");
            $st->execute([$ip]);
            $row = $st->fetch();
            if (!$row) return true;
            return lg_now() - (int) $row["created_at"] >= 600 * 1000;
        } catch (\Throwable $e) {
            // 表还没建好就放行，别把正常进群卡死
            return true;
        }
    }

    /** 记一笔进群 IP，供上面的限流用；只留最近 7 天，别无限长 */
    private static function noteJoinIp(PDO $pdo, string $ip, int $memberId): void {
        if ($ip === "") return;
        try {
            $ins = $pdo->prepare("INSERT INTO join_guard (ip, member_id, created_at) VALUES (?,?,?)");
            $ins->execute([$ip, $memberId, lg_now()]);
            $del = $pdo->prepare("DELETE FROM join_guard WHERE created_at < ?");
            $del->execute([lg_now() - 7 * 86400 * 1000]);
        } catch (\Throwable $e) {
        }
    }

    private static function checkHumanQuota(PDO $pdo, array $scope): void {
        $total = (int) self::setting($pdo, "quota.human_total", "0");
        $cap   = (int) $scope["human_cap"];
        if ($total === 0 && $cap === 0) return;
        $st = $pdo->query("SELECT COUNT(*) c FROM members WHERE kind = 'human' AND deleted_at IS NULL");
        $all = (int) $st->fetch()["c"];
        if ($total > 0 && $all >= $total) Json::fail("人数已满");
        if ($cap > 0) {
            $st2 = $pdo->prepare("SELECT COUNT(*) c FROM members WHERE scope_id = ? AND kind = 'human' AND deleted_at IS NULL");
            $st2->execute([(int) $scope["id"]]);
            if ((int) $st2->fetch()["c"] >= $cap) Json::fail("人数已满");
        }
    }

    private static function link(PDO $pdo, array $scope): void {
        $invite = Input::get("invite");
        $owner  = Input::get("owner");
        $name   = Input::get("name");

        // 门禁下账号是「OAuth 主账号 → 业务账号」两层，要挡住的是「只拿一个邀请码
        // 从脚本直连、背后没有主账号」的幽灵 AI；网页里登录过的本人、以及主人已经
        // 绑了主账号的情况都算正常操作，不该拦。
        if (self::setting($pdo, "auth.mode", "none") === "oauth" && !self::gateCallerOk($pdo, $scope, $owner)) {
            Json::fail("本站开启了 OAuth 门禁，请先用第三方账号登录，再在网页里给 AI 建身份");
        }
        // 有邀请码就能无限建 AI 的话，名额再多也顶不住脚本刷
        self::rateLimit($pdo, "link:" . self::clientIp(), 20, "建 AI 太频繁了，过几分钟再试");

        // 邀请码错和主人不存在合并成一句话：分开报就是一个邀请码枚举器
        if ($invite !== (string) $scope["invite"]) Json::fail("邀请码或主人不对");
        if ($name === "" || mb_strlen($name) > 24) Json::fail("AI 名字不能为空，且不超过 24 个字");

        $st = $pdo->prepare("SELECT * FROM members WHERE scope_id = ? AND name = ? AND kind = 'human' LIMIT 1");
        $st->execute([(int) $scope["id"], $owner]);
        $human = $st->fetch();
        if (!$human) Json::fail("邀请码或主人不对");
        if ((int) $human["banned"] === 1) Json::fail("主人的账号已被封禁");

        $st2 = $pdo->prepare("SELECT * FROM members WHERE scope_id = ? AND name = ? LIMIT 1");
        $st2->execute([(int) $scope["id"], $name]);
        $exist = $st2->fetch();
        if ($exist) {
            if ($exist["kind"] !== "ai") Json::fail("该名字已被人类成员占用");
            $plain = Token::issue();
            $up = $pdo->prepare("UPDATE members SET token_hash = ?, last_seen_at = ? WHERE id = ?");
            $up->execute([Token::hash($plain), lg_now(), (int) $exist["id"]]);
            Json::ok(["token" => $plain, "bot" => ["name" => $exist["name"]], "again" => true]);
        }

        self::checkAiQuota($pdo, $scope, (int) $human["id"]);

        $plain = Token::issue();
        $ins = $pdo->prepare("INSERT INTO members (scope_id, name, kind, owner_id, oauth_id, token_hash, created_at, last_seen_at) VALUES (?,?,?,?,?,?,?,?)");
        $ins->execute([
            (int) $scope["id"], $name, "ai", (int) $human["id"],
            $human["oauth_id"], Token::hash($plain), lg_now(), lg_now(),
        ]);

        Json::ok(["token" => $plain, "bot" => ["name" => $name]]);
    }

    /**
     * 门禁下建 AI 的调用方站不站得住脚：
     *   1) 网页里登录着，而且登录的就是本群成员 —— 已经过了 OAuth 那一层；
     *   2) 或者主人这个成员本身已经绑了主账号（account_id / oauth_id）。
     * 两条都不满足，就是脚本拿着邀请码直连、背后没有主账号，挡掉。
     */
    private static function gateCallerOk(PDO $pdo, array $scope, string $owner): bool {
        $me = Session::member($pdo);
        if ($me !== null && (int) $me["scope_id"] === (int) $scope["id"]) return true;

        $st = $pdo->prepare("SELECT account_id, oauth_id FROM members WHERE scope_id = ? AND name = ? AND kind = 'human' LIMIT 1");
        $st->execute([(int) $scope["id"], $owner]);
        $row = $st->fetch();
        if (!$row) return false;
        return (int) ($row["account_id"] ?? 0) > 0 || (int) ($row["oauth_id"] ?? 0) > 0;
    }

    private static function checkAiQuota(PDO $pdo, array $scope, int $ownerId): void {
        $perHuman = (int) self::setting($pdo, "quota.ai_per_human", "0");
        if ($perHuman > 0) {
            $st = $pdo->prepare("SELECT COUNT(*) c FROM members WHERE owner_id = ? AND kind = 'ai' AND deleted_at IS NULL");
            $st->execute([$ownerId]);
            if ((int) $st->fetch()["c"] >= $perHuman) Json::fail("你名下的 AI 已达上限");
        }
        $total = (int) self::setting($pdo, "quota.ai_total", "0");
        $cap   = (int) $scope["ai_cap"];
        if ($total === 0 && $cap === 0) return;
        $st2 = $pdo->query("SELECT COUNT(*) c FROM members WHERE kind = 'ai' AND deleted_at IS NULL");
        $all = (int) $st2->fetch()["c"];
        if ($total > 0 && $all >= $total) Json::fail("AI 名额已满");
        if ($cap > 0) {
            $st3 = $pdo->prepare("SELECT COUNT(*) c FROM members WHERE scope_id = ? AND kind = 'ai' AND deleted_at IS NULL");
            $st3->execute([(int) $scope["id"]]);
            if ((int) $st3->fetch()["c"] >= $cap) Json::fail("AI 名额已满");
        }
    }

    private static function me(PDO $pdo, array $scope): void {
        $m = self::requireMember($pdo);
        $up = $pdo->prepare("UPDATE members SET last_seen_at = ? WHERE id = ?");
        $up->execute([lg_now(), (int) $m["id"]]);

        $isAi  = (string) $m["kind"] === "ai";
        $owner = "";
        if ($isAi && $m["owner_id"] !== null) {
            $st = $pdo->prepare("SELECT name FROM members WHERE id = ? LIMIT 1");
            $st->execute([(int) $m["owner_id"]]);
            $row = $st->fetch();
            $owner = $row ? (string) $row["name"] : "";
        }

        Json::ok([
            "me" => [
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
                // me.invite 是网页端「我的资料 → 添加 AI」调 api/link 时要带的
                // 邀请码；原先只在响应顶层给，前端从 me.invite 取到空串，
                // 建 AI 必然报「邀请码不正确」。
                "invite"   => (string) $scope["invite"],
                // 这个群身份是不是走 OAuth 门禁建的：是的话「我的资料」里要能去
                // /oauth/accounts 挑或新建业务账号
                "oauth"    => (int) ($m["oauth_id"] ?? 0) > 0,
            ],
            "members" => self::membersOf($pdo, (int) $scope["id"]),
            "title" => $scope["title"],
            "invite" => (string) $scope["invite"],
            "recall_policy" => (string) ($scope["recall_policy"] ?? "5m"),
            "notice" => self::noticeOf($pdo, $scope),
            "pins"   => self::pinsOf($pdo, $scope),
            "todos"  => self::todosOf($pdo, $scope, (int) $m["id"]),
            "gcss"   => "",
            "style"  => (string) ($m["styles_css"] ?? ""),
            "wall"   => (string) ($m["wall"] ?? ""),
            "theme"      => (string) ($m["theme"] ?? ""),
            "scopeTheme" => (string) ($scope["theme"] ?? ""),
            // 社交层附加：公开大群 id、好友数、待我处理的好友申请数
            "publicConv" => self::publicConvId($pdo, $scope),
            "friends"    => Social::countFriends($pdo, (int) $m["id"]),
            "pending"    => Social::countPending($pdo, (int) $m["id"]),
        ]);
    }

    // ---------- 群公告 / 置顶 / 群待办 ----------

    private static function noticeOf(PDO $pdo, array $scope): array {
        $text = (string) ($scope["notice"] ?? "");
        if ($text === "") return ["text" => "", "at" => 0, "by" => "", "byId" => ""];
        $by = "";
        $byId = "";
        if ($scope["notice_by"] !== null) {
            $st = $pdo->prepare("SELECT id, name, kind FROM members WHERE id = ? LIMIT 1");
            $st->execute([(int) $scope["notice_by"]]);
            $row = $st->fetch();
            if ($row) {
                $by = (string) $row["name"];
                $byId = ((string) $row["kind"] === "ai" ? "a_" : "h_") . (int) $row["id"];
            }
        }
        return ["text" => $text, "at" => (int) ($scope["notice_at"] ?? 0), "by" => $by, "byId" => $byId];
    }

    /** 本群所有置顶消息，按置顶时间倒序 */
    private static function pinsOf(PDO $pdo, array $scope): array {
        $ids = self::scopeIds($pdo, $scope);
        $in = implode(",", array_fill(0, count($ids), "?"));
        $st = $pdo->prepare(
            "SELECT id, ts_ms, text, img, voice, dur, card_json, member_id, pinned_at FROM messages"
            . " WHERE scope_id IN ($in) AND pinned_at IS NOT NULL AND deleted_at IS NULL"
            . " ORDER BY pinned_at DESC LIMIT 20"
        );
        $st->execute($ids);
        $rows = $st->fetchAll();
        if (!$rows) return [];
        $names = [];
        $st2 = $pdo->prepare("SELECT id, name FROM members WHERE scope_id = ?");
        $st2->execute([(int) $scope["id"]]);
        foreach ($st2->fetchAll() as $r) $names[(int) $r["id"]] = (string) $r["name"];
        $out = [];
        foreach ($rows as $r) {
            $imgUrl = self::absUrl(self::stripImg((string) ($r["img"] ?? "")));
            $voiceUrl = (string) ($r["voice"] ?? "") !== "" ? self::absUrl((string) $r["voice"]) : "";
            $hasCard = ($r["card_json"] !== null && $r["card_json"] !== "");
            $out[] = [
                "id"   => (int) $r["id"],
                "name" => $names[(int) $r["member_id"]] ?? "",
                "text" => (string) $r["text"],
                "img"  => (string) ($r["img"] ?? ""),
                "voice" => (string) ($r["voice"] ?? ""),
                "dur"  => (int) ($r["dur"] ?? 0),
                "card" => $hasCard,
                "type" => $imgUrl !== "" ? "image" : ($voiceUrl !== "" ? "voice" : ($hasCard ? "card" : "text")),
                "md"   => self::renderMd((string) $r["text"], $imgUrl, $voiceUrl, (int) ($r["dur"] ?? 0), $hasCard),
                "img_url" => $imgUrl,
                "voice_url" => $voiceUrl,
                "at"   => (int) $r["pinned_at"],
                "ts"   => (int) $r["ts_ms"],
            ];
            $last = $out[count($out) - 1];
            if ((string) $last["text"] === "" && (string) $last["md"] !== "") {
                $out[count($out) - 1]["text"] = (string) $last["md"];
                $out[count($out) - 1]["text_auto"] = true;
            }
        }
        return $out;
    }

    /** 未关闭的群待办，带上我自己完成没完成 */
    private static function todosOf(PDO $pdo, array $scope, int $meId): array {
        $ids = self::scopeIds($pdo, $scope);
        $in = implode(",", array_fill(0, count($ids), "?"));
        $st = $pdo->prepare(
            "SELECT t.id, t.msg_id, t.created_at, t.created_by, m.text, m.img, m.voice, m.dur, m.card_json,"
            . " m.ts_ms, u.name AS author"
            . " FROM todos t"
            . " JOIN messages m ON m.id = t.msg_id AND m.deleted_at IS NULL"
            . " JOIN members u ON u.id = m.member_id"
            . " WHERE t.scope_id IN ($in) AND t.closed_at IS NULL"
            . " ORDER BY t.id DESC LIMIT 50"
        );
        $st->execute($ids);
        $rows = $st->fetchAll();
        if (!$rows) return [];
        $out = [];
        foreach ($rows as $r) {
            $todoId = (int) $r["id"];
            $cnt = $pdo->prepare("SELECT COUNT(*) c FROM todo_done WHERE todo_id = ?");
            $cnt->execute([$todoId]);
            $done = (int) $cnt->fetch()["c"];
            $mine = $pdo->prepare("SELECT 1 FROM todo_done WHERE todo_id = ? AND member_id = ? LIMIT 1");
            $mine->execute([$todoId, $meId]);
            $tImgUrl = self::absUrl(self::stripImg((string) ($r["img"] ?? "")));
            $tVoiceUrl = (string) ($r["voice"] ?? "") !== "" ? self::absUrl((string) $r["voice"]) : "";
            $tCard = ($r["card_json"] !== null && $r["card_json"] !== "");
            $out[] = [
                "id"     => $todoId,
                "msgId"  => (int) $r["msg_id"],
                "name"   => (string) $r["author"],
                "text"   => (string) $r["text"],
                "img"    => (string) ($r["img"] ?? ""),
                "voice"  => (string) ($r["voice"] ?? ""),
                "dur"    => (int) ($r["dur"] ?? 0),
                "card"   => $tCard,
                "type"   => $tImgUrl !== "" ? "image" : ($tVoiceUrl !== "" ? "voice" : ($tCard ? "card" : "text")),
                "md"     => self::renderMd((string) $r["text"], $tImgUrl, $tVoiceUrl, (int) ($r["dur"] ?? 0), $tCard),
                "img_url" => $tImgUrl,
                "voice_url" => $tVoiceUrl,
                "mine"   => (int) $r["created_by"] === $meId,
                "at"     => (int) $r["created_at"],
                "done"   => $done,
                "total"  => self::memberCount($pdo, $scope),
                "meDone" => (bool) $mine->fetch(),
            ];
            $last = $out[count($out) - 1];
            if ((string) $last["text"] === "" && (string) $last["md"] !== "") {
                $out[count($out) - 1]["text"] = (string) $last["md"];
                $out[count($out) - 1]["text_auto"] = true;
            }
        }
        return $out;
    }

    private static function memberCount(PDO $pdo, array $scope): int {
        $st = $pdo->prepare("SELECT COUNT(*) c FROM members WHERE scope_id = ? AND deleted_at IS NULL");
        $st->execute([(int) $scope["id"]]);
        return (int) $st->fetch()["c"];
    }

    /** 只有本群管理员与超管能改公告、置顶、待办 */
    private static function requireModerator(PDO $pdo): array {
        $m = self::requireMember($pdo);
        $role = self::roleOf($pdo, $m);
        if ($role !== "admin" && $role !== "super") Json::fail("只有管理员能这么做");
        return $m;
    }

    private static function noticeSet(PDO $pdo, array $scope): void {
        $m = self::requireModerator($pdo);
        $text = trim(Input::get("text"));
        if (mb_strlen($text) > 2000) Json::fail("公告太长，2000 字以内");
        if ($text === "") {
            $st = $pdo->prepare("UPDATE scopes SET notice = NULL, notice_at = 0, notice_by = NULL WHERE id = ?");
            $st->execute([(int) $scope["id"]]);
            Json::ok(["notice" => ["text" => "", "at" => 0, "by" => "", "byId" => ""]]);
        }
        $st = $pdo->prepare("UPDATE scopes SET notice = ?, notice_at = ?, notice_by = ? WHERE id = ?");
        $st->execute([$text, lg_now(), (int) $m["id"], (int) $scope["id"]]);
        Json::ok(["notice" => ["text" => $text, "at" => lg_now(), "by" => (string) $m["name"], "byId" => self::mid($m)]]);
    }

    private static function pinSet(PDO $pdo, array $scope): void {
        $m = self::requireModerator($pdo);
        $id = self::parseMid(Input::get("id"));
        if ($id <= 0) $id = Input::int("id", 0);
        if ($id <= 0) Json::fail("要置顶哪一条");
        $ids = self::scopeIds($pdo, $scope);
        $in = implode(",", array_fill(0, count($ids), "?"));
        $st = $pdo->prepare("SELECT id, pinned_at FROM messages WHERE id = ? AND scope_id IN ($in) AND deleted_at IS NULL LIMIT 1");
        $st->execute([$id, ...$ids]);
        $row = $st->fetch();
        if (!$row) Json::fail("消息不在了");
        $on = Input::get("on") !== "0";
        if ($on) {
            $st2 = $pdo->prepare("UPDATE messages SET pinned_at = ?, pinned_by = ? WHERE id = ?");
            $st2->execute([lg_now(), (int) $m["id"], $id]);
        } else {
            $st2 = $pdo->prepare("UPDATE messages SET pinned_at = NULL, pinned_by = NULL WHERE id = ?");
            $st2->execute([$id]);
        }
        Json::ok(["pinned" => $on, "pins" => self::pinsOf($pdo, $scope)]);
    }

    private static function todoOp(PDO $pdo, array $scope): void {
        $m = self::requireMember($pdo);
        $op = Input::get("op");
        $id = Input::int("id", 0);
        if ($op === "create") {
            $role = self::roleOf($pdo, $m);
            if ($role !== "admin" && $role !== "super") Json::fail("只有管理员能设群待办");
            $msgId = Input::int("msgId", 0);
            if ($msgId <= 0) Json::fail("要拿哪条消息做待办");
            $ids = self::scopeIds($pdo, $scope);
            $in = implode(",", array_fill(0, count($ids), "?"));
            $st = $pdo->prepare("SELECT id FROM messages WHERE id = ? AND scope_id IN ($in) AND deleted_at IS NULL LIMIT 1");
            $st->execute([$msgId, ...$ids]);
            if (!$st->fetch()) Json::fail("消息不在了");
            $dup = $pdo->prepare("SELECT id FROM todos WHERE msg_id = ? AND closed_at IS NULL LIMIT 1");
            $dup->execute([$msgId]);
            if ($dup->fetch()) Json::fail("这条已经是待办了");
            $ins = $pdo->prepare("INSERT INTO todos (scope_id, msg_id, created_by, created_at) VALUES (?,?,?,?)");
            $ins->execute([(int) $scope["id"], $msgId, (int) $m["id"], lg_now()]);
        } elseif ($op === "done") {
            $st = $pdo->prepare("SELECT id, scope_id FROM todos WHERE id = ? AND closed_at IS NULL LIMIT 1");
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) Json::fail("待办不在了");
            $in = implode(",", array_fill(0, count(self::scopeIds($pdo, $scope)), "?"));
            $chk = $pdo->prepare("SELECT id FROM todos WHERE id = ? AND scope_id IN ($in) LIMIT 1");
            $chk->execute([$id, ...self::scopeIds($pdo, $scope)]);
            if (!$chk->fetch()) Json::fail("待办不在了");
            try {
                // SQLite 用 INSERT OR REPLACE，MySQL 只有 REPLACE INTO；写死一种会在另一种库上直接语法错
                $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "mysql"
                    ? "REPLACE INTO todo_done (todo_id, member_id, done_at) VALUES (?,?,?)"
                    : "INSERT OR REPLACE INTO todo_done (todo_id, member_id, done_at) VALUES (?,?,?)";
                $ins = $pdo->prepare($sql);
                $ins->execute([$id, (int) $m["id"], lg_now()]);
            } catch (\Throwable $e) {
                $pdo->prepare("DELETE FROM todo_done WHERE todo_id = ? AND member_id = ?")->execute([$id, (int) $m["id"]]);
            }
        } elseif ($op === "close") {
            // 管理员能撤任意待办，发起人能撤自己发起的
            $ids = self::scopeIds($pdo, $scope);
            $in = implode(",", array_fill(0, count($ids), "?"));
            $st = $pdo->prepare("SELECT created_by FROM todos WHERE id = ? AND scope_id IN ($in) AND closed_at IS NULL LIMIT 1");
            $st->execute([$id, ...$ids]);
            $row = $st->fetch();
            if (!$row) Json::fail("待办不在了");
            $role = self::roleOf($pdo, $m);
            $isAdmin = $role === "admin" || $role === "super";
            if (!$isAdmin && (int) $row["created_by"] !== (int) $m["id"]) {
                Json::fail("只有管理员或发起人能撤掉这条待办");
            }
            $st = $pdo->prepare("UPDATE todos SET closed_at = ? WHERE id = ? AND scope_id = ?");
            $st->execute([lg_now(), $id, (int) $scope["id"]]);
        } else {
            Json::fail("未知操作");
        }
        Json::ok([
            "todos" => self::todosOf($pdo, $scope, (int) $m["id"]),
            "notice" => self::noticeOf($pdo, $scope),
        ]);
    }

    private static function scopeIds(PDO $pdo, array $scope): array {
        $cid = $scope["cluster_id"];
        if ($cid === null) return [(int) $scope["id"]];
        $st = $pdo->prepare("SELECT id FROM scopes WHERE cluster_id = ? AND archived = 0");
        $st->execute([(int) $cid]);
        $ids = array_map(fn($r) => (int) $r["id"], $st->fetchAll());
        return $ids ?: [(int) $scope["id"]];
    }

    private static function read(PDO $pdo, array $scope): void {
        $me = self::requireMember($pdo);
        $since = Input::int("since", 0);
        $limit = Input::int("limit", 50);
        if ($limit <= 0) $limit = 50;
        if ($limit > 200) $limit = 200;
        // 可选 conv：不传就是老的 scope 语义（含集群互通）
        $conv = self::resolveConv($pdo, $scope, $me, self::optionalConvId());
        $convId = $conv === null ? null : (int) $conv["id"];
        $msgs = self::fetchSince($pdo, $scope, $since, $limit, $convId);
        Json::ok([
            "msgs"   => $msgs,
            "recalled" => Conv::recalledIds($pdo, $convId, $convId === null ? self::scopeIds($pdo, $scope) : null),
            "lastId" => $msgs ? (int) $msgs[count($msgs) - 1]["id"] : $since,
            "now"    => lg_now(),
            "tick"   => self::tick(),
        ]);
    }

    /**
     * 增量拉取（网页端每 tick 调一次）。
     * 与 read 的区别：不带 me/title 等首屏信息，但每次都回最新成员表，
     * 上层用 lastId 推进游标、用 now/tick 判断服务端是否还活着。
     */
    private static function fetch(PDO $pdo, array $scope): void {
        $me = self::requireMember($pdo);
        $since = Input::int("since", 0);
        $limit = Input::int("limit", 50);
        if ($limit <= 0) $limit = 50;
        if ($limit > 200) $limit = 200;
        $conv = self::resolveConv($pdo, $scope, $me, self::optionalConvId());
        $convId = $conv === null ? null : (int) $conv["id"];
        $msgs = self::fetchSince($pdo, $scope, $since, $limit, $convId);
        Json::ok([
            "msgs"    => $msgs,
            "recalled" => Conv::recalledIds($pdo, $convId, $convId === null ? self::scopeIds($pdo, $scope) : null),
            "members" => self::convMembers($pdo, $scope, $conv),
            "lastId"  => $msgs ? (int) $msgs[count($msgs) - 1]["id"] : $since,
            "now"     => lg_now(),
            "tick"    => self::tick(),
        ]);
    }

    private static function tick(): int {
        return (int) (lg_now() / 1000) % 100000;
    }

    /**
     * 增量拉消息。
     * $convId = null：按 scope（集群内合并）过滤，与改造前完全一致；
     * 给了会话：只认这个会话，私聊消息不会从群接口漏出去。
     */
    /** 系统提示：审计留痕等，渲染成居中灰字，不算任何人说的话 */
    public static function systemMessage(PDO $pdo, int $scopeId, int $convId, int $memberId, string $text): int {
        $ts = lg_now();
        $ins = $pdo->prepare("INSERT INTO messages (scope_id, conv_id, member_id, ts_ms, text, sys) VALUES (?,?,?,?,?,1)");
        $ins->execute([$scopeId, $convId, $memberId, $ts, $text]);
        $id = (int) $pdo->lastInsertId();
        Conv::touch($pdo, $convId, $id, $ts);
        return $id;
    }

    public static function fetchSince(PDO $pdo, array $scope, int $since, int $limit, ?int $convId = null): array {
        $ids = [];
        $in  = "";
        if ($convId === null) {
            $ids = self::scopeIds($pdo, $scope);
            $in  = implode(",", array_fill(0, count($ids), "?"));
        }
        $sql = "SELECT m.id, m.ts_ms, m.text, m.img, m.card_json, m.member_id, m.reply_to, m.deleted_at,"
             . " m.voice, m.dur, m.pinned_at, m.sys,"
             . " u.id AS author_id, u.name AS author, u.kind AS author_kind, u.avatar AS author_avatar,"
             . " u.deleted_at AS author_deleted, o.name AS owner_name,"
             . " r.text AS r_text, r.img AS r_img, r.voice AS r_voice, r.dur AS r_dur, r.card_json AS r_card,"
             . " r.deleted_at AS r_deleted, ru.name AS r_name, ru.deleted_at AS ru_deleted"
             . " FROM messages m"
             . " JOIN members u ON u.id = m.member_id"
             . " LEFT JOIN members o ON o.id = u.owner_id"
             . " LEFT JOIN messages r ON r.id = m.reply_to"
             . " LEFT JOIN members ru ON ru.id = r.member_id"
             . ($convId === null
                    // 缺省 = 这些 scope 的公开大群（集群内合并）；私聊消息绝不能从群接口漏出去
                    ? " WHERE m.scope_id IN ($in)"
                        . " AND (m.conv_id IN (SELECT c.id FROM conversations c WHERE c.is_public = 1 AND c.scope_id IN ($in))"
                        . " OR m.conv_id IS NULL)"
                        . " AND m.id > ?"
                    : " WHERE m.conv_id = ? AND m.id > ?")
             . " ORDER BY m.id ASC LIMIT $limit";
        $st = $pdo->prepare($sql);
        $st->execute($convId === null ? [...$ids, ...$ids, $since] : [$convId, $since]);
        $rows = $st->fetchAll();

        $names = self::memberIdMap($pdo, $scope);

        $msgs = [];
        foreach ($rows as $r) {
            $author   = !empty($r["author_deleted"]) ? "账号已注销" : $r["author"];
            $recalled = !empty($r["deleted_at"]);
            $authorId = (int) $r["author_id"];
            $msg = [
                "id"       => (int) $r["id"],
                "from"     => ((string) $r["author_kind"] === "ai" ? "a_" : "h_") . $authorId,
                "name"     => $author,
                "kind"     => $r["author_kind"],
                "color"    => self::colorOf($authorId),
                "ava"      => (string) ($r["author_avatar"] ?? ""),
                "text"     => $recalled ? "" : (string) $r["text"],
                "ts"       => (int) $r["ts_ms"],
                "to"       => "",
                "mentions" => $recalled ? [] : self::mentionsIn((string) $r["text"], $names),
                "rev"      => $recalled ? 1 : 0,
                "q"        => null,
                "voice"    => "",
                "dur"      => 0,
                "model"    => "",
            ];
            if (!empty($r["sys"])) { $msg["sys"] = 1; }
            if ((string) $r["author_kind"] === "ai" && $r["owner_name"] !== null) $msg["owner"] = $r["owner_name"];
            if ($recalled) {
                $msg["recalled"] = true;
            } else {
                if ($r["img"] !== null && $r["img"] !== "") $msg["img"] = self::imgOut((string) $r["img"]);
                if ($r["card_json"] !== null && $r["card_json"] !== "") $msg["card"] = json_decode($r["card_json"], true);
                if ($r["voice"] !== null && $r["voice"] !== "") $msg["voice"] = (string) $r["voice"];
                $msg["dur"] = (int) $r["dur"];
            }
            if ($r["reply_to"] !== null && $r["reply_to"] !== "") {
                $rName = !empty($r["ru_deleted"]) ? "账号已注销" : (string) $r["r_name"];
                $rText = !empty($r["r_deleted"]) ? "" : (string) $r["r_text"];
                $rImg  = !empty($r["r_deleted"]) || $r["r_img"] === null ? "" : (string) $r["r_img"];
                // 引用目标也可能是图片/语音/卡片：把类型带上，前端才不会把「没文字」当成已撤回
                $rVoice = (!empty($r["r_deleted"]) || $r["r_voice"] === null) ? "" : (string) $r["r_voice"];
                $rDur   = !empty($r["r_deleted"]) ? 0 : (int) ($r["r_dur"] ?? 0);
                $rCard  = !empty($r["r_deleted"]) ? false : ($r["r_card"] !== null && $r["r_card"] !== "");
                $rImgUrl = $rImg !== "" ? self::absUrl(self::stripImg($rImg)) : "";
                $rVoiceUrl = $rVoice !== "" ? self::absUrl($rVoice) : "";
                // 引用目标对第三方客户端也要能显示：type + md + 可直接访问的地址
                $rMd = self::renderMd($rText, $rImgUrl, $rVoiceUrl, $rDur, $rCard);
                $msg["reply"] = [
                    "id" => (int) $r["reply_to"], "name" => $rName, "text" => $rText, "img" => $rImg,
                    "voice" => $rVoice, "dur" => $rDur, "card" => $rCard,
                    "type" => $rImgUrl !== "" ? "image" : ($rVoiceUrl !== "" ? "voice" : ($rCard ? "card" : "text")),
                    "md" => $rMd,
                    "img_url" => $rImgUrl,
                    "voice_url" => $rVoiceUrl,
                ];
                $msg["q"] = json_encode($msg["reply"], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $msg["reply_md"] = "↳ 引用 " . $rName . "：" . $rMd;
            }

            // ---- 给第三方客户端（iOS / Android / CLI / 别家插件）用的统一字段 ----
            // type：text / image / voice / card / recalled / system
            // md：已经拼好的显示文本，客户端直接输出这一行就行（图片、语音、撤回、引用都在里面）
            // img_url / voice_url：绝对地址，不在浏览器里也能直接加载
            $msg["type"] = !empty($r["sys"]) ? "system"
                : ($recalled ? "recalled"
                : (!empty($msg["img"]) ? "image"
                : (!empty($msg["voice"]) ? "voice"
                : (!empty($msg["card"]) ? "card" : "text"))));
            if (!empty($msg["img"])) $msg["img_url"] = self::absUrl(self::stripImg((string) $msg["img"]));
            if (!empty($msg["voice"])) $msg["voice_url"] = self::absUrl((string) $msg["voice"]);
            $msg["md"] = $recalled
                ? "（该消息已被撤回）"
                : self::renderMd((string) ($msg["text"] ?? ""), (string) ($msg["img_url"] ?? ""), (string) ($msg["voice_url"] ?? ""), (int) $msg["dur"], !empty($msg["card"]));
            if (!empty($msg["reply_md"])) {
                $msg["md"] = $msg["reply_md"] . (($msg["md"] !== "" && $msg["md"] !== "（空消息）") ? "\n" . $msg["md"] : "");
            }
            if ($r["pinned_at"] !== null) $msg["pin"] = true;
            // 老客户端（包括第三方 CLI/插件）只认 text，不会看 img/voice 字段：
            // 出口把媒体和撤回文案拼进 text，并在 text_auto 上标记，网页端渲染时剔掉这一行。
            $media = "";
            if (!empty($msg["img"])) $media = "[图片](" . (string) ($msg["img_url"] ?? "") . ")";
            elseif (!empty($msg["voice"])) $media = "[语音](" . (string) ($msg["voice_url"] ?? "") . ") " . (int) $msg["dur"] . '"';
            elseif (!empty($msg["card"])) $media = "[卡片]";
            if ($media !== "" && strpos((string) $msg["text"], $media) === false) {
                $msg["text"] = (string) $msg["text"] === "" ? $media : (string) $msg["text"] . "\n" . $media;
                $msg["text_auto"] = true;
            } elseif ((string) $msg["text"] === "" && $recalled) {
                $msg["text"] = "（该消息已被撤回）";
                $msg["text_auto"] = true;
            }
            $msgs[] = $msg;
        }
        return $msgs;
    }

    // ---------- 会话 / 私聊 / 好友 ----------

    /** 公开大群 id：Schema 保证每个 scope 都有 */
    private static function publicConvId(PDO $pdo, array $scope): int {
        return (int) Conv::publicConv($pdo, $scope)["id"];
    }

    /** 前端可能给 7、"7"、"c_7"；认不出来按 0（= 没传） */
    private static function parseConvId(string $raw): int {
        $raw = trim($raw);
        if ($raw === "") return 0;
        if (preg_match('/^c_(\d+)$/i', $raw, $mm)) return (int) $mm[1];
        if (ctype_digit($raw)) return (int) $raw;
        return 0;
    }

    /** POST body 里的会话：conv 优先，其次 id */
    private static function convIdOfRequest(): int {
        $id = self::parseConvId(Input::get("conv"));
        if ($id <= 0) $id = self::parseConvId(Input::get("id"));
        return $id;
    }

    /** 可选参数 conv：没传返回 0，调用方按「公开大群」走 */
    private static function optionalConvId(): int {
        return self::parseConvId(Input::get("conv"));
    }

    /**
     * 解析并鉴权一个会话：
     *   0 且非必须 → null（老的 scope 语义，含集群互通）
     *   不存在 / 跨 scope → "会话不存在"
     *   AI 进私聊 → "AI 账号只能看公开大群"
     *   非成员 → "不在这个会话里"
     */
    private static function resolveConv(PDO $pdo, array $scope, array $me, int $convId, bool $required = false): ?array {
        if ($convId <= 0) {
            if ($required) Json::fail("缺少会话 id");
            return null;
        }
        $conv = Conv::convById($pdo, $convId);
        if ($conv === null || (int) $conv["scope_id"] !== (int) $scope["id"]) Json::fail("会话不存在");
        if ((string) $me["kind"] === "ai" && (int) $conv["is_public"] !== 1) Json::fail("AI 账号只能看公开大群");
        if (!Conv::isMember($pdo, $conv, (int) $me["id"])) Json::fail("不在这个会话里");
        return $conv;
    }

    /** 对外成员表：内部 id => memberOut 结构 */
    private static function memberMap(PDO $pdo, array $scope): array {
        $map = [];
        foreach (self::membersOf($pdo, (int) $scope["id"]) as $mem) {
            $map[self::parseMid((string) $mem["id"])] = $mem;
        }
        return $map;
    }

    /** 会话成员：公开大群回整个 scope 的成员表，私聊只回两个人 */
    private static function convMembers(PDO $pdo, array $scope, ?array $conv): array {
        if ($conv === null || (int) $conv["is_public"] === 1) return self::membersOf($pdo, (int) $scope["id"]);
        $ids = Conv::memberIds($pdo, $conv);
        if (!$ids) return [];
        $in = implode(",", array_fill(0, count($ids), "?"));
        $st = $pdo->prepare("SELECT * FROM members WHERE id IN ($in) ORDER BY id ASC");
        $st->execute($ids);
        $rows = $st->fetchAll();
        $names = [];
        foreach ($rows as $r) $names[(int) $r["id"]] = $r["name"];
        $roles = self::badgeMap($pdo);
        $out = [];
        foreach ($rows as $r) {
            $owner = $r["owner_id"] !== null ? ($names[(int) $r["owner_id"]] ?? null) : null;
            $out[] = self::memberOut($r, $owner, $roles[(int) ($r["account_id"] ?? 0)] ?? "");
        }
        return $out;
    }

    /** 会话的对外结构（api/conv 的 conv 字段） */
    private static function convOut(PDO $pdo, array $scope, array $conv, array $me, array $map): array {
        $isPublic = (int) $conv["is_public"] === 1;
        $out = [
            "id"       => (int) $conv["id"],
            "kind"     => (string) $conv["kind"],
            "title"    => $isPublic ? (string) $scope["title"] : "",
            "isPublic" => $isPublic,
        ];
        if (!$isPublic) {
            $other = 0;
            foreach (Conv::memberIds($pdo, $conv) as $x) {
                if ($x !== (int) $me["id"]) { $other = $x; break; }
            }
            $peer = $other > 0 ? ($map[$other] ?? null) : null;
            $out["title"]     = $peer !== null ? (string) $peer["name"] : Conv::nameOf($pdo, $other);
            $out["member"]    = $peer;
            $out["memberId"]  = $peer !== null ? (string) $peer["id"] : "";
        }
        return $out;
    }

    /** 会话摘要行：Conv::lastLine 不返回 img，这里补空串保证字段稳定 */
    private static function lastOut(?array $last): ?array {
        if ($last === null) return null;
        return [
            "id"   => (int) $last["id"],
            "name" => (string) $last["name"],
            "text" => (string) $last["text"],
            "img"  => (string) ($last["img"] ?? ""),
            "ts"   => (int) $last["ts"],
            // 前端会话列表靠这个直接显示「消息已撤回」（lastLine 里已经判过 deleted_at）
            "recalled" => !empty($last["recalled"]),
        ];
    }

    /** GET api/conversations：公开大群置顶，其后私聊按最近活跃倒序 */
    private static function conversations(PDO $pdo, array $scope): void {
        $me    = self::requireMember($pdo);
        $pubId = self::publicConvId($pdo, $scope);
        $isAi  = (string) $me["kind"] === "ai";

        $pubItem = null;
        $dmItems = [];
        foreach (Conv::mine($pdo, $scope, $me) as $c) {
            $isPublic = (int) $c["id"] === $pubId;
            if ($isAi && !$isPublic) continue;
            if ($isPublic) {
                $pubItem = [
                    "id"       => (int) $c["id"],
                    "kind"     => "group",
                    "title"    => (string) $c["title"],
                    "isPublic" => true,
                    "last"     => self::lastOut($c["last"] ?? null),
                    "unread"   => (int) $c["unread"],
                    "members"  => (int) ($c["members"] ?? 0),
                    // 公开大群永远钉在会话列表顶部
                    "pin"      => true,
                ];
                continue;
            }
            $item = [
                "id"       => (int) $c["id"],
                "kind"     => "dm",
                "title"    => (string) $c["title"],
                "isPublic" => false,
                "member"   => $c["member"] ?? null,
                "last"     => self::lastOut($c["last"] ?? null),
                "unread"   => (int) $c["unread"],
                "pin"      => false,
            ];
            $dmItems[] = $item;
        }
        if ($pubItem === null) $pubItem = self::publicConvItem($pdo, $scope);
        Json::ok(["convs" => [$pubItem, ...$dmItems]]);
    }

    /** 兜底：mine() 没给出公开大群时自己拼一条（正常不会走到） */
    private static function publicConvItem(PDO $pdo, array $scope): array {
        $pub = Conv::publicConv($pdo, $scope);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM members WHERE scope_id = ? AND deleted_at IS NULL");
        $stc->execute([(int) $scope["id"]]);
        return [
            "id"       => (int) $pub["id"],
            "kind"     => "group",
            "title"    => (string) $scope["title"],
            "isPublic" => true,
            "last"     => self::lastOut(Conv::lastLine($pdo, $pub)),
            "unread"   => 0,
            "members"  => (int) $stc->fetchColumn(),
            "pin"      => true,
        ];
    }

    /** GET api/conv：会话内增量拉取，顺手更新已读 */
    private static function convGet(PDO $pdo, array $scope): void {
        $me = self::requireMember($pdo);
        $conv = self::resolveConv($pdo, $scope, $me, self::convIdOfRequest(), true);

        $since = Input::int("since", 0);
        $limit = Input::int("limit", 50);
        if ($limit <= 0) $limit = 50;
        if ($limit > 200) $limit = 200;

        $msgs   = self::fetchSince($pdo, $scope, $since, $limit, (int) $conv["id"]);
        $lastId = $msgs ? (int) $msgs[count($msgs) - 1]["id"] : $since;
        if ($msgs) Conv::markRead($pdo, $conv, $me, $lastId);

        Json::ok([
            "msgs"    => $msgs,
            "recalled" => Conv::recalledIds($pdo, (int) $conv["id"]),
            "members" => self::convMembers($pdo, $scope, $conv),
            "lastId"  => $lastId,
            "now"     => lg_now(),
            "tick"    => self::tick(),
            "conv"    => self::convOut($pdo, $scope, $conv, $me, self::memberMap($pdo, $scope)),
        ]);
    }

    /** GET api/friends：好友 + 收到的申请 + 我发出的申请 */
    private static function friendsList(PDO $pdo, array $scope): void {
        $me  = self::requireMember($pdo);
        $map = self::memberMap($pdo, $scope);
        $rel = Social::listFor($pdo, $scope, $me);

        $friends = [];
        foreach ($rel["friends"] as $it) {
            $mem = $map[$it["id"]] ?? null;
            if ($mem !== null) $friends[] = $mem;
        }
        Json::ok([
            "friends"  => $friends,
            "incoming" => self::friendReqOut($rel["incoming"], $map),
            "outgoing" => self::friendReqOut($rel["outgoing"], $map),
        ]);
    }

    /**
     * GET api/friends/poll：好友变更的轻量轮询。
     *
     * 只回指纹和两个计数，前端拿去跟上次的指纹比，变了才去拉完整的 api/friends。
     * 不直接轮询 api/friends 是因为成员对象序列化比较重，而好友关系变化其实很少。
     */
    private static function friendsPoll(PDO $pdo, array $scope): void {
        $me = self::requireMember($pdo);
        $mid = (int) $me["id"];
        Json::ok([
            "sig"     => Social::sig($pdo, $scope, $me),
            "friends" => Social::countFriends($pdo, $mid),
            "pending" => Social::countPending($pdo, $mid),
        ]);
    }

    private static function friendReqOut(array $rows, array $map): array {
        $out = [];
        foreach ($rows as $it) {
            $mem = $map[$it["id"]] ?? null;
            if ($mem === null) continue;
            $out[] = [
                "id"     => (string) $mem["id"],
                "name"   => (string) $mem["name"],
                "color"  => self::colorOf((int) $mem["id"]),
                "avatar" => (string) $mem["avatar"],
                "at"     => (int) $it["at"],
            ];
        }
        return $out;
    }

    /** 申请对象：body 给 id 或 name，只认同 scope 的活人 */
    private static function friendTarget(PDO $pdo, array $scope): int {
        $raw = Input::get("id");
        $id  = $raw !== "" ? self::parseMid($raw) : 0;
        if ($id > 0) return $id;
        $name = Input::get("name");
        if ($name !== "") {
            $st = $pdo->prepare("SELECT id FROM members WHERE scope_id = ? AND deleted_at IS NULL AND name = ? LIMIT 1");
            $st->execute([(int) $scope["id"], $name]);
            $row = $st->fetch();
            if ($row) return (int) $row["id"];
        }
        Json::fail("找不到这个成员");
    }

    /** 好友动作后回一份计数，前端能直接刷新红点 */
    private static function friendCounts(PDO $pdo, array $me): array {
        return [
            "friends" => Social::countFriends($pdo, (int) $me["id"]),
            "pending" => Social::countPending($pdo, (int) $me["id"]),
        ];
    }

    private static function friendRequest(PDO $pdo, array $scope): void {
        $me = self::requireMember($pdo);
        Social::request($pdo, $scope, $me, self::friendTarget($pdo, $scope));
        Json::ok(self::friendCounts($pdo, $me));
    }

    private static function friendAccept(PDO $pdo, array $scope): void {
        $me = self::requireMember($pdo);
        Social::accept($pdo, $scope, $me, self::friendTarget($pdo, $scope));
        Json::ok(self::friendCounts($pdo, $me));
    }

    private static function friendReject(PDO $pdo, array $scope): void {
        $me = self::requireMember($pdo);
        Social::reject($pdo, $scope, $me, self::friendTarget($pdo, $scope));
        Json::ok(self::friendCounts($pdo, $me));
    }

    private static function friendRemove(PDO $pdo, array $scope): void {
        $me = self::requireMember($pdo);
        Social::remove($pdo, $scope, $me, self::friendTarget($pdo, $scope));
        Json::ok(self::friendCounts($pdo, $me));
    }

    /** POST api/dm/open：先必须是好友，再复用/新建私聊 */
    private static function dmOpen(PDO $pdo, array $scope): void {
        $me = self::requireMember($pdo);
        if ((string) $me["kind"] === "ai") Json::fail("AI 账号只能看公开大群");
        $targetId = self::friendTarget($pdo, $scope);
        if ($targetId === (int) $me["id"]) Json::fail("不能和自己私聊");
        $target = Social::scopeMember($pdo, $scope, $targetId);
        if ($target === null) Json::fail("找不到这个成员");
        if ((string) $target["kind"] === "ai") Json::fail("AI 账号只能看公开大群");
        if (!Social::areFriends($pdo, (int) $me["id"], $targetId)) Json::fail("先加好友");

        $conv = Conv::ensureDm($pdo, $scope, (int) $me["id"], $targetId, (int) $me["id"]);
        Json::ok(["conv" => self::convOut($pdo, $scope, $conv, $me, self::memberMap($pdo, $scope))]);
    }

    /** 群内成员名 => 对外 id，用于把 @名字 认成 mentions */
    private static function memberIdMap(PDO $pdo, array $scope): array {
        $st = $pdo->prepare("SELECT id, name, kind FROM members WHERE scope_id = ? AND deleted_at IS NULL");
        $st->execute([(int) $scope["id"]]);
        $map = [];
        foreach ($st->fetchAll() as $row) {
            $map[(string) $row["name"]] = ((string) $row["kind"] === "ai" ? "a_" : "h_") . (int) $row["id"];
        }
        return $map;
    }

    /** 从正文里认出 @成员，最多 50 个，@所有人 由前端各自判断 */
    private static function mentionsIn(string $text, array $names): array {
        if ($text === "" || strpos($text, "@") === false) return [];
        $out = [];
        foreach ($names as $name => $mid) {
            if ($name === "") continue;
            if (strpos($text, "@" . $name) !== false) {
                $out[] = $mid;
                if (count($out) >= 50) break;
            }
        }
        return $out;
    }

    /** 图片字段：站长的格式是 [img]uploads/x.jpg，我们存纯路径，出口统一补前缀 */
    private static function imgOut(string $img): string {
        if ($img === "" || str_starts_with($img, "[img]")) return $img;
        if (str_starts_with($img, "http://") || str_starts_with($img, "https://") || str_starts_with($img, "data:")) return $img;
        return "[img]" . $img;
    }

    /** 出口图片字段可能带 [img] 前缀（老前端约定），拼地址前先剥掉 */
    private static function stripImg(string $img): string {
        return (string) preg_replace('#^\[img\]#', "", $img);
    }

    /** 拼一条消息的显示文本：图片/语音/卡片都转成 markdown，纯文本原样 */
    private static function renderMd(string $text, string $imgUrl, string $voiceUrl, int $dur, bool $card): string {
        $bits = [];
        if ($text !== "") $bits[] = $text;
        if ($imgUrl !== "") $bits[] = "[图片](" . $imgUrl . ")";
        if ($voiceUrl !== "") $bits[] = "[语音](" . $voiceUrl . ") " . $dur . '"';
        if ($card) $bits[] = "[卡片]";
        return $bits === [] ? "（空消息）" : implode("\n", $bits);
    }

    private static function send(PDO $pdo, array $scope): void {
        $m = self::requireMember($pdo);
        // 不传 conv 时行为与改造前一致：落在 scope 的公开大群
        $conv = self::resolveConv($pdo, $scope, $m, self::optionalConvId());
        self::insertMessage($pdo, $scope, $m, $conv);
    }

    /** send 与 api/conv/send 共用：校验 → 落库（带 conv_id）→ 刷新会话摘要 */
    private static function insertMessage(PDO $pdo, array $scope, array $m, ?array $conv): void {
        $text = Input::get("text");
        $img  = Input::get("img");
        $card = Input::raw("card");
        $voice = Input::get("voice");
        $dur   = Input::int("dur", 0);

        // 站长的图片带 [img] 前缀，我们库里只存路径
        if (str_starts_with($img, "[img]")) $img = substr($img, 5);

        if ($text === "" && $img === "" && $voice === "" && !$card) Json::fail("不能发空消息");
        if (mb_strlen($text) > 2000) Json::fail("单条消息不要超过 2000 字");
        if ($dur < 0) $dur = 0;
        if ($dur > 600) $dur = 600;

        // 公开大群与私聊都要写 conv_id，历史行为（NULL）由 Schema 迁移回填，这里不再产生 NULL
        $convId  = $conv === null ? self::publicConvId($pdo, $scope) : (int) $conv["id"];
        $replyTo = self::replyId($pdo, $scope, $convId);

        $ts = lg_now();
        $ins = $pdo->prepare(
            "INSERT INTO messages (scope_id, conv_id, member_id, ts_ms, text, img, card_json, reply_to, voice, dur)"
            . " VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $ins->execute([
            (int) $scope["id"], $convId, (int) $m["id"], $ts, $text,
            $img !== "" ? $img : null,
            $card ? json_encode($card, JSON_UNESCAPED_UNICODE) : null,
            $replyTo,
            $voice !== "" ? $voice : null,
            $dur,
        ]);
        $id = (int) $pdo->lastInsertId();

        $up = $pdo->prepare("UPDATE members SET last_seen_at = ? WHERE id = ?");
        $up->execute([$ts, (int) $m["id"]]);

        Conv::touch($pdo, $convId, $id, $ts);

        Json::ok(["msg" => ["id" => $id, "ts" => $ts, "from" => self::mid($m)]]);
    }

    /** POST api/conv/send：只多发一个 conv，其余校验与 send 完全一致 */
    private static function convSend(PDO $pdo, array $scope): void {
        $m = self::requireMember($pdo);
        $conv = self::resolveConv($pdo, $scope, $m, self::convIdOfRequest(), true);
        self::insertMessage($pdo, $scope, $m, $conv);
    }

    /** 引用目标：兼容 reply=23、reply="h_23"、q={"id":23}（senders 可能只回传 q 对象） */
    private static function replyId(PDO $pdo, array $scope, int $convId = 0): ?int {
        $id = 0;
        $replyRaw = Input::get("reply");
        if ($replyRaw !== "") {
            $id = preg_match('/^[ha]_(\d+)$/', $replyRaw, $mm) ? (int) $mm[1] : (int) $replyRaw;
        }
        if ($id <= 0) {
            $q = Input::raw("q");
            if (is_array($q) && isset($q["id"])) $id = (int) $q["id"];
            if (is_string($q) && $q !== "") {
                $dec = json_decode($q, true);
                if (is_array($dec) && isset($dec["id"])) $id = (int) $dec["id"];
            }
        }
        if ($id <= 0) return null;

        if ($convId > 0) {
            // 按会话校验：群里的消息引不到私聊里的内容
            $st = $pdo->prepare("SELECT id FROM messages WHERE id = ? AND conv_id = ? AND deleted_at IS NULL LIMIT 1");
            $st->execute([$id, $convId]);
        } else {
            $ids = self::scopeIds($pdo, $scope);
            $in = implode(",", array_fill(0, count($ids), "?"));
            $st = $pdo->prepare("SELECT id FROM messages WHERE id = ? AND scope_id IN ($in) AND deleted_at IS NULL LIMIT 1");
            $st->execute([$id, ...$ids]);
        }
        if (!$st->fetch()) Json::fail("被引用的消息不在了");
        return $id;
    }

    /**
     * 长轮询：最多 hold $seconds 秒，期间有新消息立刻返回。
     * 原客户端 group_wait 默认 20 秒、上限 25 秒。
     */
    private static function wait(PDO $pdo, array $scope): void {
        $m = self::requireMember($pdo);
        $since = Input::int("since", 0);
        $secs  = Input::int("seconds", 20);
        if ($secs <= 0) $secs = 20;
        if ($secs > 25) $secs = 25;

        @set_time_limit($secs + 15);
        ignore_user_abort(false);
        // 长轮询期间必须放掉会话锁，否则同一浏览器的其它请求全被堵住
        lg_session_release();

        $deadline = microtime(true) + $secs;
        // php -S 是单进程，长轮询会把同一浏览器的其它请求全堵住。
        // 本地手动验收时用 LG_DEV_HOLD=1 启动，退化成短轮询；线上与自动化测试保持真实长轮询。
        if (getenv("LG_DEV_HOLD") !== false) {
            $deadline = microtime(true);
        }
        $token = Input::get("token");
        $key = $token !== "" ? Token::hash($token) : "api:" . (int) $m["id"];
        try {
            $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "mysql"
                ? "REPLACE INTO waiters (token_hash, expires_at) VALUES (?, ?)"
                : "INSERT OR REPLACE INTO waiters (token_hash, expires_at) VALUES (?, ?)";
            $st = $pdo->prepare($sql);
            $st->execute([$key, (int) (microtime(true) * 1000) + $secs * 1000 + 5000]);
        } catch (\Throwable $e) {
            // 记录失败不影响等待本身
        }

        $lastId = $since;
        try {
            while (true) {
                $msgs = self::fetchSince($pdo, $scope, $lastId, 100);
                if ($msgs) {
                    Json::ok(["msgs" => $msgs, "lastId" => (int) $msgs[count($msgs) - 1]["id"]]);
                    return;
                }
                if (microtime(true) >= $deadline) break;
                usleep(180000);
                if (connection_aborted()) break;
            }
            Json::ok(["msgs" => [], "lastId" => $lastId]);
        } finally {
            try {
                $del = $pdo->prepare("DELETE FROM waiters WHERE token_hash = ?");
                $del->execute([$key]);
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * 图片上传：客户端把本地图片转成 dataURL 发上来，落盘后返回可访问 URL。
     * 原客户端限制 base64 长度 5,200,000 字符（约 3.5MB 原图）。
     */
    private static function upload(PDO $pdo, array $scope): void {
        $m = self::requireMember($pdo);
        $data = (string) Input::get("data");
        if ($data === "") {
            // 请求体超过 post_max_size 时 PHP 会整个丢掉 body，$data 自然是空的；
            // 这里把真实原因说出来，否则前端只能看到「没有收到文件」这种误导信息
            $len = (int) ($_SERVER["CONTENT_LENGTH"] ?? 0);
            $cap = self::iniBytes((string) ini_get("post_max_size"));
            if ($len > 0 && $cap > 0 && $len > $cap) {
                Json::fail("上传内容 " . self::mbSize($len) . "，超过了服务器上限 " . self::mbSize($cap) . "，换一张小一点的图");
            }
            Json::fail("没有收到文件");
        }

        // 语音：webm / ogg / mp4 / mp3 / wav，先存再验，只给真音频
        if (preg_match('#^data:audio/(webm|ogg|mp4|mpeg|wav|m4a)(;[a-z0-9=.,\-]+)?;base64,#i', $data, $av)) {
            if (strlen($data) > 8000000) Json::fail("语音太长了，说短一点");
            $raw = base64_decode(substr($data, strlen($av[0])), true);
            if ($raw === false || $raw === "") Json::fail("语音数据损坏");
            if (strlen($raw) > 6 * 1024 * 1024) Json::fail("语音太长了，说短一点");
            if (class_exists("finfo")) {
                $fi = new \finfo(FILEINFO_MIME_TYPE);
                $mime = (string) @$fi->buffer($raw);
                // WebM / Ogg 容器在 finfo 里可能报 video/*，同样放行
                if (!str_starts_with($mime, "audio/") && $mime !== "video/webm" && $mime !== "video/ogg" && $mime !== "application/ogg") {
                    Json::fail("这不像是音频");
                }
            }
            $extMap = ["webm" => "webm", "ogg" => "ogg", "mp4" => "m4a", "m4a" => "m4a", "mpeg" => "mp3", "wav" => "wav"];
            $ext = $extMap[strtolower($av[1])] ?? "webm";
            $dir = PUBLIC_ROOT . "/uploads/" . date("Y") . "/" . date("m");
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) Json::fail("服务端存储目录不可写");
            $name = "v_" . bin2hex(random_bytes(10)) . "." . $ext;
            if (file_put_contents($dir . "/" . $name, $raw) === false) Json::fail("保存失败");
            Json::ok(["url" => self::absUrl("/uploads/" . date("Y") . "/" . date("m") . "/" . $name)]);
            return;
        }

        // 5,200,000 字符 ≈ 3.9MB 原图；手机原图普遍 4–8MB，前端会先按长边 1600 压一遍再传
        if (strlen($data) > 5200000) {
            Json::fail("图片太大了（压完约 " . self::mbSize((int) (strlen($data) * 0.75)) . "），换一张小一点的");
        }

        if (!preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,#i', $data, $mm)) {
            Json::fail("只支持 png / jpeg / gif / webp（手机原图如果是 HEIC，先在系统里改成兼容格式或截个图再发）");
        }
        $ext = strtolower($mm[1] === "jpg" ? "jpeg" : $mm[1]);
        $raw = base64_decode(substr($data, strlen($mm[0])), true);
        if ($raw === false || $raw === "") Json::fail("图片数据损坏");

        $info = @getimagesize("data://application/octet-stream;base64," . base64_encode($raw));
        $allowed = [IMAGETYPE_PNG => "png", IMAGETYPE_JPEG => "jpeg", IMAGETYPE_GIF => "gif", IMAGETYPE_WEBP => "webp"];
        if (!$info || !isset($allowed[$info[2]])) Json::fail("这不像是图片");
        $ext = $allowed[$info[2]];
        if ($info[0] > 6000 || $info[1] > 6000) Json::fail("图片尺寸太大了");

        $dir = PUBLIC_ROOT . "/uploads/" . date("Y") . "/" . date("m");
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) Json::fail("服务端存储目录不可写");

        $kind = Input::get("kind") === "avatar" ? "avatar" : "img";
        $name = ($kind === "avatar" ? "a_" : "u_") . bin2hex(random_bytes(10)) . "." . $ext;
        $target = $dir . "/" . $name;
        if (file_put_contents($target, $raw) === false) Json::fail("保存失败");

        $url = self::absUrl("/uploads/" . date("Y") . "/" . date("m") . "/" . $name);
        if ($kind === "avatar") {
            $pdo->prepare("UPDATE members SET avatar = ? WHERE id = ?")->execute([$url, (int) $m["id"]]);
        }

        Json::ok(["url" => $url]);
    }

    /** ini 里的 8M / 512K 这种写法转成字节数 */
    private static function iniBytes(string $v): int {
        $v = trim($v);
        if ($v === "") return 0;
        $unit = strtolower(substr($v, -1));
        $num = (float) $v;
        if ($unit === "g") return (int) ($num * 1024 * 1024 * 1024);
        if ($unit === "m") return (int) ($num * 1024 * 1024);
        if ($unit === "k") return (int) ($num * 1024);
        return (int) $num;
    }

    /** 给用户看的体积，1.4MB / 320KB */
    private static function mbSize(int $bytes): string {
        if ($bytes >= 1024 * 1024) return round($bytes / 1024 / 1024, 1) . "MB";
        return max(1, (int) round($bytes / 1024)) . "KB";
    }

    /** 补成绝对地址：第三方客户端不在浏览器里，相对路径没法直接加载；已是绝对地址的原样返回 */
    private static function absUrl(string $path): string {
        $path = trim($path);
        if ($path === "") return "";
        $https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
            || (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https");
        $scheme = $https ? "https" : "http";
        if (preg_match('#^https?://#i', $path) || str_starts_with($path, "data:")) return $path;
        if (str_starts_with($path, "//")) return $scheme . ":" . $path;
        $host = (string) ($_SERVER["HTTP_HOST"] ?? "localhost");
        return $scheme . "://" . $host . ($path[0] === "/" ? "" : "/") . $path;
    }

    /**
     * 撤回。策略由群管理员配置：forever 永久 / until_other 直到有他人发言 / 5m 五分钟。
     * 本人可撤回自己的，管理员可撤回本群的任意消息，都受策略约束。
     */
    private static function recall(PDO $pdo, array $scope): void {
        $m = self::requireMember($pdo);
        $id = Input::int("id", 0);
        if ($id <= 0) Json::fail("参数不对");

        $st = $pdo->prepare("SELECT * FROM messages WHERE id = ? AND scope_id = ? LIMIT 1");
        $st->execute([$id, (int) $scope["id"]]);
        $msg = $st->fetch();
        if (!$msg) Json::fail("消息不存在");
        if (!empty($msg["deleted_at"])) Json::fail("已经撤回过了");

        $role = self::roleOf($pdo, $m);
        $isAuthor = (int) $msg["member_id"] === (int) $m["id"];
        if (!$isAuthor && $role === "member") Json::fail("只能撤回自己的消息");

        $policy = (string) ($scope["recall_policy"] ?? "5m");
        if ($policy === "5m") {
            if (lg_now() - (int) $msg["ts_ms"] > 5 * 60 * 1000) Json::fail("超过 5 分钟，撤回不了了");
        } elseif ($policy === "until_other") {
            $st2 = $pdo->prepare("SELECT COUNT(*) c FROM messages WHERE scope_id = ? AND id > ? AND member_id <> ? AND sys = 0");
            $st2->execute([(int) $scope["id"], $id, (int) $m["id"]]);
            if ((int) $st2->fetch()["c"] > 0) Json::fail("已经有人接过话了，撤回不了了");
        }

        // voice 也要清掉：撤回后还能点开听、会话摘要还显示 [语音] 都很怪
        $pdo->prepare("UPDATE messages SET deleted_at = ?, deleted_by = ?, text = '', img = NULL, voice = NULL, card_json = NULL WHERE id = ?")
            ->execute([lg_now(), (int) $m["id"], $id]);
        Json::ok(["id" => $id, "recalled" => true]);
    }

    /** 成员在群里的身份：super / admin / member */
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

    private static function profileOut(PDO $pdo, array $m): array {
        $out = [
            "id"     => (int) $m["id"],
            "name"   => $m["name"],
            "kind"   => $m["kind"],
            "role"   => self::roleOf($pdo, $m),
            "bio"    => (string) ($m["bio"] ?? ""),
            "avatar" => (string) ($m["avatar"] ?? ""),
            "joined" => (int) ($m["created_at"] ?? 0),
        ];
        if ($m["kind"] === "ai" && !empty($m["owner_id"])) {
            $st = $pdo->prepare("SELECT name FROM members WHERE id = ? LIMIT 1");
            $st->execute([(int) $m["owner_id"]]);
            $o = $st->fetch();
            if ($o) $out["owner"] = $o["name"];
        }
        return $out;
    }

    /** 个人资料：GET 读，POST 改（昵称 / 简介 / 头像） */
    private static function profile(PDO $pdo, array $scope): void {
        $m = self::requireMember($pdo);
        $isPost = ($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST";
        if ($isPost) {
            $name = trim((string) Input::get("name"));
            $bio = (string) Input::get("bio");
            $avatar = (string) Input::get("avatar");
            if ($name !== "" && $name !== $m["name"]) {
                if (mb_strlen($name) > 24) Json::fail("昵称最多 24 个字");
                $st = $pdo->prepare("SELECT id FROM members WHERE scope_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1");
                $st->execute([(int) $m["scope_id"], $name]);
                if ($st->fetch()) Json::fail("这个昵称有人在用了");
                $pdo->prepare("UPDATE members SET name = ? WHERE id = ?")->execute([$name, (int) $m["id"]]);
                $m["name"] = $name;
            }
            if (mb_strlen($bio) > 500) Json::fail("简介最多 500 字");
            $pdo->prepare("UPDATE members SET bio = ? WHERE id = ?")->execute([$bio, (int) $m["id"]]);
            $m["bio"] = $bio;
            if ($avatar !== "") {
                $pdo->prepare("UPDATE members SET avatar = ? WHERE id = ?")->execute([$avatar, (int) $m["id"]]);
                $m["avatar"] = $avatar;
            }
        }
        Json::ok(["profile" => self::profileOut($pdo, $m)]);
    }

    /** 个性化气泡：CSS 存服务端，换设备也在；壁纸等纯图片走客户端 localStorage */
    private static function styles(PDO $pdo, array $scope): void {
        $m = self::requireMember($pdo);
        if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
            $css = (string) Input::get("css");
            if (mb_strlen($css) > 20000) Json::fail("样式最多 2 万字符");
            // 只放行内联 data:image，其余 url() 一律拒：协议相对地址（url(//x)）
            // 和相对路径都能绕开「只拦 http(s)」的旧规则，把访客的 IP 送给第三方。
            if (preg_match("#</style|@import|expression\(|javascript:|behavior\s*:|url\(\s*['\"]?(?!data:image/)#i", $css)) {
                Json::fail("样式里不能有外部资源或标签");
            }
            $pdo->prepare("UPDATE members SET styles_css = ? WHERE id = ?")->execute([$css, (int) $m["id"]]);
            $m["styles_css"] = $css;
        }
        Json::ok(["css" => (string) ($m["styles_css"] ?? ""), "name" => $m["name"]]);
    }

    /** 主题包允许自定义的变量：键 => CSS 变量名 */
    private const THEME_VARS = [
        "bg" => "--bg", "surface" => "--surface", "surface2" => "--surface-2",
        "text" => "--text", "textDim" => "--text-dim", "border" => "--border",
        "accent" => "--accent", "accentText" => "--accent-text", "accentSoft" => "--accent-soft",
        "bubble" => "--bubble", "bubbleText" => "--bubble-text",
    ];

    /** 只收行内颜色值，别的一律丢掉 */
    public static function cleanVars($raw): array {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) return [];
        $out = [];
        foreach (self::THEME_VARS as $k => $_) {
            if (!isset($raw[$k]) || !is_string($raw[$k])) continue;
            $v = trim($raw[$k]);
            if ($v === "") continue;
            if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $v)
                && !preg_match('/^rgba?\([0-9,.\s%]+\)$/', $v)) {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    public static function packOut(array $r): array {
        $vars = json_decode((string) ($r["vars"] ?? "{}"), true);
        return [
            "id"     => "p_" . (int) $r["id"],
            "name"   => (string) $r["name"],
            "dark"   => (int) ($r["dark"] ?? 0),
            "shared" => ($r["owner_id"] ?? null) === null,
            "vars"   => (object) (is_array($vars) ? $vars : []),
        ];
    }

    /** 本域可见的主题包：管理员共享的 + 自己建的 */
    private static function themes(PDO $pdo, array $scope): void {
        $m = self::requireMember($pdo);
        $st = $pdo->prepare("SELECT * FROM theme_packs WHERE scope_id = ? AND (owner_id IS NULL OR owner_id = ?) ORDER BY (owner_id IS NULL) DESC, id ASC");
        $st->execute([(int) $scope["id"], (int) $m["id"]]);
        $packs = [];
        foreach ($st->fetchAll() as $r) $packs[] = self::packOut($r);
        Json::ok([
            "scope" => (string) ($scope["theme"] ?? ""),
            "mine"  => (string) ($m["theme"] ?? ""),
            "site"  => self::setting($pdo, "theme.preset", "hulan"),
            "role"  => self::roleOf($pdo, $m),
            "packs" => $packs,
        ]);
    }

    /** 校验主题 id：内建主题只收合法 slug，主题包要校验归属 */
    private static function assertThemeId(PDO $pdo, array $scope, array $m, string $id): string {
        if (strpos($id, "p_") === 0) {
            $pid = (int) substr($id, 2);
            $st = $pdo->prepare("SELECT owner_id FROM theme_packs WHERE id = ? AND scope_id = ? LIMIT 1");
            $st->execute([$pid, (int) $scope["id"]]);
            $row = $st->fetch();
            if (!$row) Json::fail("主题包不存在");
            if ($row["owner_id"] !== null && (int) $row["owner_id"] !== (int) $m["id"]) Json::fail("主题包不存在");
            return "p_" . $pid;
        }
        if (!preg_match('/^[a-z0-9_-]{1,40}$/', $id)) Json::fail("主题 id 不对");
        return $id;
    }

    /** 我选的主题：空 = 跟随本群默认 */
    private static function themeSet(PDO $pdo, array $scope): void {
        $m = self::requireMember($pdo);
        $id = trim((string) Input::get("theme"));
        if ($id !== "") $id = self::assertThemeId($pdo, $scope, $m, $id);
        $pdo->prepare("UPDATE members SET theme = ? WHERE id = ?")->execute([$id === "" ? null : $id, (int) $m["id"]]);
        Json::ok(["theme" => $id]);
    }

    /** 存一个主题包：自己用，或管理员建成本群共享 */
    private static function themePackSave(PDO $pdo, array $scope): void {
        $m = self::requireMember($pdo);
        $role = self::roleOf($pdo, $m);
        $shared = (int) Input::get("shared", "0") === 1;
        if ($shared && !in_array($role, ["super", "admin"], true)) Json::fail("只有管理员能建全群主题");
        $name = trim((string) Input::get("name"));
        $name = mb_substr($name === "" ? "我的主题" : $name, 0, 20);
        $vars = self::cleanVars(Input::raw("vars") ?: Input::get("vars"));
        if (!$vars) Json::fail("主题至少要有一个颜色");
        $dark = (int) Input::get("dark", "0") === 1 ? 1 : 0;
        $json = (string) json_encode($vars, JSON_UNESCAPED_UNICODE);
        $owner = $shared ? null : (int) $m["id"];
        $rawId = trim((string) Input::get("id"));

        if ($rawId !== "" && strpos($rawId, "p_") === 0) {
            $pid = (int) substr($rawId, 2);
            $st = $pdo->prepare("SELECT * FROM theme_packs WHERE id = ? AND scope_id = ? LIMIT 1");
            $st->execute([$pid, (int) $scope["id"]]);
            $p = $st->fetch();
            if (!$p) Json::fail("主题包不存在");
            $isMine = $p["owner_id"] !== null && (int) $p["owner_id"] === (int) $m["id"];
            $canShared = $p["owner_id"] === null && in_array($role, ["super", "admin"], true);
            if (!$isMine && !$canShared) Json::fail("这个主题包不是你建的");
            $pdo->prepare("UPDATE theme_packs SET name = ?, dark = ?, vars = ?, owner_id = ? WHERE id = ?")
                ->execute([$name, $dark, $json, $owner, $pid]);
            Json::ok(self::packOut(["id" => $pid, "name" => $name, "dark" => $dark, "vars" => $json, "owner_id" => $owner]));
            return;
        }

        $pdo->prepare("INSERT INTO theme_packs (scope_id, owner_id, name, dark, vars, created_at) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([(int) $scope["id"], $owner, $name, $dark, $json, lg_now()]);
        $nid = (int) $pdo->lastInsertId();
        Json::ok(self::packOut(["id" => $nid, "name" => $name, "dark" => $dark, "vars" => $json, "owner_id" => $owner]));
    }

    private static function themePackDel(PDO $pdo, array $scope): void {
        $m = self::requireMember($pdo);
        $role = self::roleOf($pdo, $m);
        $raw = trim((string) Input::get("id"));
        if (strpos($raw, "p_") !== 0) Json::fail("主题包不存在");
        $pid = (int) substr($raw, 2);
        $st = $pdo->prepare("SELECT * FROM theme_packs WHERE id = ? AND scope_id = ? LIMIT 1");
        $st->execute([$pid, (int) $scope["id"]]);
        $p = $st->fetch();
        if (!$p) Json::fail("主题包不存在");
        $isMine = $p["owner_id"] !== null && (int) $p["owner_id"] === (int) $m["id"];
        $canShared = $p["owner_id"] === null && in_array($role, ["super", "admin"], true);
        if (!$isMine && !$canShared) Json::fail("不能删别人的主题包");
        $pdo->prepare("DELETE FROM theme_packs WHERE id = ?")->execute([$pid]);
        $pdo->prepare("UPDATE members SET theme = NULL WHERE theme = ?")->execute([$raw]);
        $pdo->prepare("UPDATE scopes SET theme = NULL WHERE theme = ? AND id = ?")->execute([$raw, (int) $scope["id"]]);
        Json::ok(["deleted" => $raw]);
    }

    /** 用群 token 换网页会话：在插件里点一下就能带着身份进网页与后台 */
    private static function sessionLogin(PDO $pdo, array $scope): void {
        $m = self::memberByToken($pdo, (string) Input::get("token"));
        if ($m === null) Json::fail("token 不对，重新进群一次");
        if (!empty($m["deleted_at"])) Json::fail("账号已注销");
        if ((int) $m["banned"] === 1) Json::fail("该账号已被封禁");
        if ((int) $m["scope_id"] !== (int) $scope["id"]) Json::fail("这个身份不属于本群");
        Session::login($pdo, $m);
        Json::ok(["role" => self::roleOf($pdo, $m), "name" => (string) $m["name"]]);
    }

    /**
     * 账号密码登录：管理员账号存在 accounts 表，登录后用同一个身份进网页。
     * 后台的登录弹窗（admin.js renderLogin）也走这条接口，所以节流口径
     * 必须和网页登录（Web\Login）对齐：IP 松一点、账号名紧一点。
     */
    private static function passwordLogin(PDO $pdo, array $scope): void {
        $name = trim((string) Input::get("name"));
        $pass = (string) Input::get("password");
        if ($name === "" || $pass === "") Json::fail("账号和密码都要填");

        // 先看是不是被节流了，省得再去验密码（爆破面小不等于可以不限速）
        $keys = ["ip:" . self::clientIp() => 10];
        $keys["admin:" . $name] = 5;
        foreach ($keys as $k => $limit) {
            $wait = Throttle::wait($pdo, $k, $limit);
            if ($wait !== null) Json::fail("试了太多次，请 " . $wait . " 秒后再来");
        }

        $t0 = microtime(true);
        $st = $pdo->prepare("SELECT * FROM accounts WHERE name = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$name]);
        $a = $st->fetch();
        if (!$a || empty($a["pass_hash"])) {
            // 账号不存在时也跑一次 bcrypt：不然「175ms 没这个号 / 450ms 有」
            // 本身就是一个账号枚举器，慢的那一边等于告诉对面「这个名字存在」。
            password_verify($pass, self::DUMMY_HASH);
            self::authDelay($t0);
            foreach (array_keys($keys) as $k) Throttle::fail($pdo, $k);
            Json::fail("账号或密码不对");
        }
        if (!password_verify($pass, (string) $a["pass_hash"])) {
            self::authDelay($t0);
            foreach (array_keys($keys) as $k) Throttle::fail($pdo, $k);
            Json::fail("账号或密码不对");
        }

        // 门禁开着的时候业务账号只能走 OAuth，这条接口服务的是管理员后台，
        // 所以只放行 admin / super；别的角色在这里拿到会话就等于绕过门禁。
        if (self::setting($pdo, "auth.mode", "none") === "oauth"
            && !in_array((string) ($a["role"] ?? ""), ["admin", "super"], true)) {
            self::authDelay($t0);
            foreach (array_keys($keys) as $k) Throttle::fail($pdo, $k);
            Json::fail("本站开启了 OAuth 门禁，请用第三方账号登录");
        }

        // 开了人机验证的站点，登录也要过这一道；前端没带 cap_* 时按节流兜底，
        // 免得后台弹窗（没有滑块控件）被这条规则锁死。
        if (Captcha::enabled($pdo) && self::hasCaptcha()) {
            $why = Captcha::check($pdo, self::captchaFields());
            if ($why !== "") {
                self::authDelay($t0);
                foreach (array_keys($keys) as $k) Throttle::fail($pdo, $k);
                Json::fail($why);
            }
        }

        if ((int) $a["banned"] === 1) Json::fail("这个账号被停用了");
        $st = $pdo->prepare("SELECT * FROM members WHERE account_id = ? AND scope_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1");
        $st->execute([(int) $a["id"], (int) $scope["id"]]);
        $m = $st->fetch();
        if (!$m) {
            $st = $pdo->prepare("SELECT * FROM members WHERE account_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1");
            $st->execute([(int) $a["id"]]);
            $m = $st->fetch();
        }
        if (!$m) Json::fail("这个账号在本群没有身份");
        if ((int) $m["banned"] === 1) Json::fail("该账号已被封禁");
        foreach (array_keys($keys) as $k) Throttle::clear($pdo, $k);
        Session::login($pdo, $m);
        Json::ok(["role" => self::roleOf($pdo, $m), "name" => (string) $m["name"]]);
    }

    /**
     * 账号不存在时拿来陪跑的 bcrypt hash（密码是随机的，谁也撞不上）。
     * 目的只有一个：让「这个账号不存在」和「密码错了」花一样的时间。
     */
    private const DUMMY_HASH = '$2y$10$bccLVa3qCXU58empO0tpleg4Hpb1hSmY69c48TjbfPoTrZ/6uqCUm';

    /** 请求方 IP：只在服务端取值，别信 X-Forwarded-For（那串是客户端能改的） */
    private static function clientIp(): string {
        return (string) ($_SERVER["REMOTE_ADDR"] ?? "");
    }

    /** 把一次认证请求至少拖到 300ms，抹掉「查得快 = 没这个号」的时间差 */
    private static function authDelay(float $t0): void {
        $spent = microtime(true) - $t0;
        if ($spent < 0.30) usleep((int) ((0.30 - $spent) * 1000000));
    }

    /** 请求里带没带人机验证字段（内建 slider 与第三方 widget 都算） */
    private static function hasCaptcha(): bool {
        foreach (["cap_token", "cap3", "cf-turnstile-response", "h-captcha-response", "g-recaptcha-response"] as $k) {
            if ((string) Input::get($k) !== "") return true;
        }
        return false;
    }

    /** 把请求体转成 Captcha::check() 认识的一维数组 */
    private static function captchaFields(): array {
        $out = [];
        foreach (["cap_token", "cap_off", "cap_ms", "cap_moves", "cap3", "cf-turnstile-response", "h-captcha-response", "g-recaptcha-response"] as $k) {
            $out[$k] = (string) Input::get($k);
        }
        return $out;
    }

    /**
     * 计数型限流（Throttle 的窗口是 5 分钟）：link 这类「有邀请码就能无限刷」
     * 的接口，每次调用都记一笔，超过上限就拒。
     */
    private static function rateLimit(PDO $pdo, string $key, int $limit, string $msg): void {
        if (Throttle::wait($pdo, $key, $limit) !== null) Json::fail($msg);
        Throttle::fail($pdo, $key);
    }


    /** 能力探测：第三方客户端先问一下服务端会什么 */
    private static function cap(PDO $pdo, array $scope): void {
        Json::ok([
            "name"     => (string) self::setting($pdo, "site.name", "LightGroup"),
            "version"  => \LightGroup\Web\Version::current(),
            "scope"    => $scope["title"],
            "features" => ["reply", "recall", "styles", "profile", "upload", "wait", "doc", "translate", "apikey"],
            "limits"   => [
                "text"      => 2000,
                "upload"    => 5200000,
                "wait_max"  => 25,
                "read_max"  => 200,
            ],
        ]);
    }

    /**
     * api/v1：给第三方客户端用的、带鉴权的接口。
     * 鉴权用 API key（Authorization: Bearer lgk_xxx 或 ?key=），不用群 token、也不吃网页会话。
     */
    private static function v1(PDO $pdo, array $scope, string $sub): void {
        $sub = trim($sub, "/");
        if ($sub === "keys" || str_starts_with($sub, "keys/")) {
            self::keysApi($pdo, $scope, $sub);
            return;
        }
        $m = self::requireApiKey($pdo, $scope);
        self::actAs($m);
        switch ($sub) {
            case "me":
            case "whoami":      self::me($pdo, $scope); break;
            case "messages":
                if (self::method() === "POST") self::send($pdo, $scope);
                else self::read($pdo, $scope);
                break;
            case "messages/recall":
            case "recall":      self::recall($pdo, $scope); break;
            case "members":     Json::ok(["members" => self::membersOf($pdo, (int) $scope["id"])]); break;
            case "wait":        self::wait($pdo, $scope); break;
            case "cap":         self::cap($pdo, $scope); break;
            default:            Json::fail("未知接口"); break;
        }
    }

    /** key 的增删查走网页会话：key 只能给“我自己”建，不能替别人建 */
    private static function keysApi(PDO $pdo, array $scope, string $sub): void {
        $me = Session::member($pdo);
        if ($me === null) Json::fail("请先登录网页");
        if ((int) $me["scope_id"] !== (int) $scope["id"]) Json::fail("这个身份不属于本群");

        if ($sub === "keys" && self::method() === "GET") {
            $st = $pdo->prepare("SELECT id, name, created_at, last_used_at, revoked_at FROM api_keys WHERE member_id = ? ORDER BY id DESC");
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
            Json::ok(["keys" => $rows]);
        }

        if ($sub === "keys" && self::method() === "POST") {
            $name = trim((string) Input::get("name"));
            if ($name === "") $name = "API Key";
            if (mb_strlen($name) > 40) Json::fail("名字太长");
            $raw = "lgk_" . bin2hex(random_bytes(20));
            $pdo->prepare("INSERT INTO api_keys (member_id, name, key_hash, created_at) VALUES (?,?,?,?)")
                ->execute([(int) $me["id"], $name, Token::hash($raw), lg_now()]);
            Json::ok(["id" => (int) $pdo->lastInsertId(), "name" => $name, "key" => $raw]);
        }

        if (preg_match("#^keys/(\d+)$#", $sub, $mm)) {
            $id = (int) $mm[1];
            $pdo->prepare("UPDATE api_keys SET revoked_at = ? WHERE id = ? AND member_id = ? AND revoked_at IS NULL")
                ->execute([lg_now(), $id, (int) $me["id"]]);
            Json::ok(["revoked" => $id]);
        }

        Json::fail("未知接口");
    }

    private static function requireApiKey(PDO $pdo, array $scope): array {
        $key = "";
        $hdr = (string) ($_SERVER["HTTP_AUTHORIZATION"] ?? "");
        if ($hdr !== "" && preg_match('/^Bearer\s+(\S+)$/i', $hdr, $bm)) $key = $bm[1];
        if ($key === "") $key = (string) Input::get("key");
        if ($key === "") Json::fail("缺少 API key");

        $st = $pdo->prepare("SELECT k.id AS key_id, k.revoked_at AS key_revoked, m.* FROM api_keys k JOIN members m ON m.id = k.member_id WHERE k.key_hash = ? LIMIT 1");
        $st->execute([Token::hash($key)]);
        $row = $st->fetch();
        if (!$row) Json::fail("API key 不对");
        if (!empty($row["key_revoked"])) Json::fail("这个 key 已经作废了");
        if ((int) $row["scope_id"] !== (int) $scope["id"]) Json::fail("这个 key 不属于本群");
        if (!empty($row["deleted_at"])) Json::fail("账号已注销");
        if ((int) $row["banned"] === 1) Json::fail("该账号已被封禁");

        $pdo->prepare("UPDATE api_keys SET last_used_at = ? WHERE id = ?")->execute([lg_now(), (int) $row["key_id"]]);
        return $row;
    }

    /** 翻译一条消息（右键菜单里的「翻译」） */
    private static function translate(PDO $pdo, array $scope): void {
        self::requireMember($pdo);
        $text = (string) Input::get("text");
        $to = (string) Input::get("to");
        if ($to === "") $to = "zh-Hans";
        if (!preg_match('/^[A-Za-z]{1,3}(-[A-Za-z]{2,12})?$/', $to)) Json::fail("目标语言不对");
        try {
            $r = Translator::translate($text, $to);
        } catch (\Throwable $e) {
            Json::fail($e->getMessage());
        }
        Json::ok($r);
    }

    /** 三份手册：给用户看 HTML、给 AI 看纯文本、给客户端开发者看 API 参考 */
    private static function doc(): void {
        $t = (string) Input::get("t");
        if (!in_array($t, ["user", "ai", "api"], true)) $t = "user";
        $docBase = self::absUrl("/g/");
        if ($t === "ai") {
            // 给 AI 的是 plaintext：模型读 HTML 会白吃一堆标签
            header("Content-Type: text/plain; charset=utf-8");
            $file = APP_ROOT . "/src/Web/views/doc-ai.txt.php";
        } else {
            header("Content-Type: text/html; charset=utf-8");
            $file = APP_ROOT . "/src/Web/views/doc-" . $t . ".php";
        }
        if (!is_file($file)) { echo "手册不存在"; return; }
        require $file;
    }
}