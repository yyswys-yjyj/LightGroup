<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Http\Json;
use PDO;

/**
 * 管理接口：全部走网页会话，不接受 API key。
 * 普通管理员只能动自己作用域；超管不受限（跨域/邀请码/站点设置/互通）。
 */
final class Admin {
    public static function handle(PDO $pdo, array $scope, string $path): void {
        lg_session();
        // 同一主账号在多个群都有成员时（超管），按这次请求的群换身份，
        // 否则在子群后台里动的还是主群那个身份。
        $me = Compat::adoptScope($pdo, $scope);
        if ($me === null) Json::fail("请先登录");
        $role = self::role($pdo, $me);
        if ($role === "member") Json::fail("没有管理权限");
        $myScope = (int) $me["scope_id"];

        $parts  = array_values(array_filter(explode("/", trim($path, "/")), fn($x) => $x !== ""));
        $res    = $parts[0] ?? "";
        $id     = isset($parts[1]) ? (int) $parts[1] : 0;
        $sub    = $parts[2] ?? "";
        $method = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");

        // 管理接口只有网页会话这一条路，写操作一律验 CSRF（token 由 admin.php 注入页面）
        self::guardCsrf($method);
        self::ensureTables($pdo);

        switch ($res) {
            case "dashboard": self::dashboard($pdo, $me, $role); break;
            case "accounts":  self::accounts($pdo, $me, $role, $method, $id, $sub); break;
            case "oauth":     self::oauthApi($pdo, $role, $method); break;
            case "overview":  self::overview($pdo, $me, $role); break;
            case "scopes":    self::scopes($pdo, $role, $myScope, $method, $id, $sub); break;
            case "members":   self::members($pdo, $me, $role, $myScope, $method, $id, $sub); break;
            case "settings":  self::settings($pdo, $role, $method); break;
            case "clusters":  self::clusters($pdo, $role, $method, $id, $sub); break;
            case "themes":    self::themes($pdo, $role, $myScope, $method, $id, $sub); break;
            case "convs":     self::convs($pdo, $role, $myScope, $method, $id, $sub); break;
            case "messages":  self::messagesApi($pdo, $role, $myScope, $method, $id); break;
            case "update":    $method === "POST" ? self::updateApply($pdo, $role) : self::updateApi($pdo); break;
            case "mail":      self::mailTest($pdo, $role, $method); break;
            case "moments":   self::momentsSetting($pdo, $me, $method); break;
            default:          Json::fail("未知管理接口");
        }
    }

    private static function role(PDO $pdo, array $m): string {
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

    /** 超管能看所有域，普通管理员只看自己那个 */
    private static function scopeRow(PDO $pdo, string $role, int $myScope, int $id): ?array {
        $st = $pdo->prepare("SELECT * FROM scopes WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) return null;
        if ($role !== "super" && (int) $row["id"] !== $myScope) return null;
        return $row;
    }

    private static function scopeOut(PDO $pdo, array $s, string $role): array {
        $id = (int) $s["id"];
        $st = $pdo->prepare("SELECT kind, COUNT(*) c FROM members WHERE scope_id = ? AND deleted_at IS NULL GROUP BY kind");
        $st->execute([$id]);
        $humans = 0; $ais = 0;
        foreach ($st->fetchAll() as $r) {
            if ($r["kind"] === "ai") $ais = (int) $r["c"]; else $humans = (int) $r["c"];
        }
        $st = $pdo->prepare("SELECT COUNT(*) c FROM messages WHERE scope_id = ? AND deleted_at IS NULL AND sys = 0");
        $st->execute([$id]);
        $msgs = (int) $st->fetchColumn();
        $out = [
            "id"            => $id,
            "slug"          => (string) $s["slug"],
            "title"         => (string) $s["title"],
            "intro"         => (string) ($s["intro"] ?? ""),
            "cluster_id"    => $s["cluster_id"] === null ? null : (int) $s["cluster_id"],
            "human_cap"     => (int) $s["human_cap"],
            "ai_cap"        => (int) $s["ai_cap"],
            "archived"      => (int) $s["archived"],
            "recall_policy" => (string) $s["recall_policy"],
            "theme"         => (string) ($s["theme"] ?? ""),
            "humans"        => $humans,
            "ais"           => $ais,
            "messages"      => $msgs,
        ];
        // 邀请码只有超管能拿到
        if ($role === "super") $out["invite"] = (string) $s["invite"];
        return $out;
    }

    private static function overview(PDO $pdo, array $me, string $role): void {
        $site = ["name" => self::setting($pdo, "site.name", "LightGroup"), "intro" => self::setting($pdo, "site.intro", "")];
        $scopes = [];
        if ($role === "super") {
            $rows = $pdo->query("SELECT * FROM scopes ORDER BY id ASC")->fetchAll();
        } else {
            $st = $pdo->prepare("SELECT * FROM scopes WHERE id = ? LIMIT 1");
            $st->execute([(int) $me["scope_id"]]);
            $rows = $st->fetchAll();
        }
        foreach ($rows as $r) $scopes[] = self::scopeOut($pdo, $r, $role);
        $tot = ["scopes" => count($scopes), "humans" => 0, "ais" => 0, "messages" => 0];
        foreach ($scopes as $s) { $tot["humans"] += $s["humans"]; $tot["ais"] += $s["ais"]; $tot["messages"] += $s["messages"]; }
        Json::ok([
            "site"   => $site,
            "role"   => $role,
            "me"     => ["id" => (int) $me["id"], "name" => (string) $me["name"], "kind" => (string) $me["kind"]],
            "scope"  => (int) $me["scope_id"],
            "totals" => $tot,
            "scopes" => $scopes,
        ]);
    }

    private static function scopes(PDO $pdo, string $role, int $myScope, string $method, int $id, string $sub): void {
        if ($method === "GET" && $id === 0) {
            if ($role === "super") {
                $rows = $pdo->query("SELECT * FROM scopes ORDER BY id ASC")->fetchAll();
            } else {
                $st = $pdo->prepare("SELECT * FROM scopes WHERE id = ?");
                $st->execute([$myScope]);
                $rows = $st->fetchAll();
            }
            $out = [];
            foreach ($rows as $r) $out[] = self::scopeOut($pdo, $r, $role);
            Json::ok(["scopes" => $out]);
            return;
        }

        if ($method === "POST" && $id === 0) {
            if ($role !== "super") Json::fail("只有超级管理员能新建群");
            $title  = trim((string) self::in("title", ""));
            $slug   = trim((string) self::in("slug", ""));
            $invite = trim((string) self::in("invite", ""));
            if ($title === "") Json::fail("群名不能为空");
            if ($slug !== "" && !preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $slug)) Json::fail("群路径只能用字母数字和 - _");
            if ($slug === "") {
                // 主群地址是 /g/，其它群都得有自己的一段路径；空着就自动生成，
                // 不能空着插入 —— 那会撞上「slug 必须唯一」的索引，报成「已被占用」。
                $slug = self::autoSlug($pdo);
            }
            if ($invite !== "" && !preg_match('/^[A-Za-z0-9_-]{4,32}$/', $invite)) {
                Json::fail("邀请码只能用字母数字和 - _（4-32 位）");
            }
            if ($invite === "") $invite = self::randCode();
            try {
                $st = $pdo->prepare("INSERT INTO scopes (slug, title, intro, invite, cluster_id, human_cap, ai_cap, archived, recall_policy, created_at) VALUES (?, ?, '', ?, NULL, 0, 0, 0, '5m', ?)");
                $st->execute([$slug, $title, $invite, lg_now()]);
            } catch (\Throwable $e) {
                Json::fail("群路径或邀请码已被占用，换一个试试");
            }
            $newId = (int) $pdo->lastInsertId();
            self::ensureSuperMember($pdo, $newId);
            Json::ok(["id" => $newId, "slug" => $slug, "invite" => $invite]);
            return;
        }

        $row = self::scopeRow($pdo, $role, $myScope, $id);
        if ($row === null) Json::fail("群不存在或无权管理");

        if ($method === "POST" && $sub === "invite") {
            if ($role !== "super") Json::fail("只有超级管理员能改邀请码");
            $invite = trim((string) self::in("invite", ""));
            if ($invite === "") $invite = self::randCode();
            if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $invite)) Json::fail("邀请码只能用字母数字和 - _（4-32 位）");
            try {
                $st = $pdo->prepare("UPDATE scopes SET invite = ? WHERE id = ?");
                $st->execute([$invite, $id]);
            } catch (\Throwable $e) {
                Json::fail("这个邀请码已被别的群占用");
            }
            Json::ok(["invite" => $invite]);
            return;
        }

        if ($method === "POST" && $sub === "archive") {
            if ($role !== "super") Json::fail("只有超级管理员能归档群");
            $on = (int) self::in("on", 1) === 1 ? 1 : 0;
            $st = $pdo->prepare("UPDATE scopes SET archived = ? WHERE id = ?");
            $st->execute([$on, $id]);
            Json::ok(["archived" => $on]);
            return;
        }

        if ($method === "POST") {
            $sets = [];
            $args = [];
            $title = self::in("title", null);
            if ($title !== null) {
                $title = trim((string) $title);
                if ($title === "") Json::fail("群名不能为空");
                $sets[] = "title = ?"; $args[] = $title;
            }
            $intro = self::in("intro", null);
            if ($intro !== null) { $sets[] = "intro = ?"; $args[] = (string) $intro; }
            foreach (["human_cap", "ai_cap"] as $k) {
                $v = self::in($k, null);
                if ($v === null) continue;
                $n = (int) $v;
                if ($n < 0) Json::fail("配额不能是负数");
                $sets[] = $k . " = ?"; $args[] = $n;
            }
            $theme = self::in("theme", null);
            if ($theme !== null) {
                $t = trim((string) $theme);
                if ($t !== "") {
                    if (strpos($t, "p_") === 0) {
                        $st = $pdo->prepare("SELECT id FROM theme_packs WHERE id = ? AND scope_id = ? LIMIT 1");
                        $st->execute([(int) substr($t, 2), $id]);
                        if (!$st->fetch()) Json::fail("这个主题包不属于本群");
                    } elseif (!preg_match('/^[a-z0-9_-]{1,40}$/', $t)) {
                        Json::fail("主题 id 不对");
                    }
                }
                $sets[] = "theme = ?"; $args[] = $t === "" ? null : $t;
            }
            $policy = self::in("recall_policy", null);
            if ($policy !== null) {
                if (!in_array($policy, ["5m", "until_other", "forever"], true)) Json::fail("撤回策略不对");
                $sets[] = "recall_policy = ?"; $args[] = $policy;
            }
            if (!$sets) Json::fail("没有要改的内容");
            $args[] = $id;
            $st = $pdo->prepare("UPDATE scopes SET " . implode(", ", $sets) . " WHERE id = ?");
            $st->execute($args);
            Json::ok(["id" => $id]);
            return;
        }

        Json::fail("不支持的操作");
    }

    private static function ensureSuperMember(PDO $pdo, int $scopeId): void {
        $st = $pdo->query("SELECT id, name FROM accounts WHERE role = 'super' ORDER BY id ASC LIMIT 1");
        $a = $st->fetch();
        if (!$a) return;
        $st = $pdo->prepare("SELECT id FROM members WHERE scope_id = ? AND account_id = ? LIMIT 1");
        $st->execute([$scopeId, (int) $a["id"]]);
        if ($st->fetch()) return;
        try {
            $st = $pdo->prepare("INSERT INTO members (scope_id, name, kind, account_id, created_at) VALUES (?, ?, 'human', ?, ?)");
            $st->execute([$scopeId, self::superName($pdo), (int) $a["id"], lg_now()]);
        } catch (\Throwable $e) {
        }
    }

    private static function superName(PDO $pdo): string {
        $st = $pdo->query("SELECT name FROM accounts WHERE role = 'super' ORDER BY id ASC LIMIT 1");
        $n = $st->fetchColumn();
        return $n === false || $n === null ? "管理员" : (string) $n;
    }

    private static function randCode(): string {
        return "iv" . bin2hex(random_bytes(4));
    }

    /** 群路径留空时自动生成：短、全小写、避开容易看错的字符，撞了就再抽一次 */
    private static function autoSlug(PDO $pdo): string {
        $abc = "abcdefghjkmnpqrstuvwxyz23456789";
        $max = strlen($abc) - 1;
        for ($try = 0; $try < 20; $try++) {
            $s = "";
            for ($i = 0; $i < 6; $i++) $s .= $abc[random_int(0, $max)];
            $st = $pdo->prepare("SELECT 1 FROM scopes WHERE slug = ? LIMIT 1");
            $st->execute([$s]);
            if (!$st->fetchColumn()) return $s;
        }
        // 连着撞 20 次基本不可能，真撞上了也别让用户看到「已被占用」
        return "g" . bin2hex(random_bytes(5));
    }

    /**
     * 读一个管理参数。默认值可以是 null，用来区分「没传」和「传了空/0」。
     * 不走 Input::get()：它带 strict_types 且签名是 string，传 null / int 会直接 TypeError。
     */
    private static function in(string $key, $default = null) {
        $b = Input::body();
        if (array_key_exists($key, $b)) return $b[$key];
        if (isset($_GET[$key]) && is_scalar($_GET[$key])) return trim((string) $_GET[$key]);
        return $default;
    }

    private static function setting(PDO $pdo, string $k, string $d = ""): string {
        $st = $pdo->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $d : (string) $v;
    }

    private static function putSetting(PDO $pdo, string $k, string $v): void {
        $st = $pdo->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v");
        try { $st->execute([$k, $v]); }
        catch (\Throwable $e) {
            $st = $pdo->prepare("REPLACE INTO settings (k, v) VALUES (?, ?)");
            $st->execute([$k, $v]);
        }
    }

    private static function members(PDO $pdo, array $me, string $role, int $myScope, string $method, int $id, string $sub): void {
        if ($method === "GET") {
            $target = $role === "super" ? (int) self::in("scope", 0) : $myScope;
            if ($target <= 0) $target = $myScope;
            if ($role !== "super" && $target !== $myScope) Json::fail("无权查看");
            $st = $pdo->prepare("SELECT m.*, a.role AS acc_role FROM members m LEFT JOIN accounts a ON a.id = m.account_id WHERE m.scope_id = ? ORDER BY m.id ASC");
            $st->execute([$target]);
            $rows = $st->fetchAll();
            $byId = [];
            foreach ($rows as $r) $byId[(int) $r["id"]] = $r;
            $out = [];
            foreach ($rows as $r) {
                $badge = "";
                if (($r["acc_role"] ?? "") === "super") $badge = "super";
                elseif (($r["acc_role"] ?? "") === "admin" && (int) $r["account_id"] > 0) $badge = "admin";
                $owner = null;
                if ($r["owner_id"] !== null && isset($byId[(int) $r["owner_id"]])) $owner = (string) $byId[(int) $r["owner_id"]]["name"];
                $out[] = [
                    "id"         => (int) $r["id"],
                    "name"       => (string) $r["name"],
                    "kind"       => (string) $r["kind"],
                    "owner"      => $owner,
                    "badge"      => $badge,
                    "banned"     => (int) $r["banned"] === 1,
                    "deleted"    => $r["deleted_at"] !== null,
                    "created_at" => (int) $r["created_at"],
                    "last_seen"  => $r["last_seen_at"] === null ? 0 : (int) $r["last_seen_at"],
                ];
            }
            Json::ok(["scope" => $target, "members" => $out]);
            return;
        }

        if ($id <= 0) Json::fail("缺少成员");
        $st = $pdo->prepare("SELECT m.*, a.role AS acc_role FROM members m LEFT JOIN accounts a ON a.id = m.account_id WHERE m.id = ? LIMIT 1");
        $st->execute([$id]);
        $target = $st->fetch();
        if (!$target) Json::fail("成员不存在");
        if ($role !== "super" && (int) $target["scope_id"] !== $myScope) Json::fail("无权管理这个成员");
        if (($target["acc_role"] ?? "") === "super") Json::fail("不能动超级管理员");

        if ($sub === "ban") {
            $on = (int) self::in("on", 1) === 1 ? 1 : 0;
            $sync = (int) self::in("sync", 0) === 1 && self::setting($pdo, "oauth.ban_sync", "0") === "1";
            $ids = [ (int) $target["id"] ];
            if ($sync && $target["oauth_id"] !== null) {
                $st = $pdo->prepare("SELECT id, owner_id FROM members WHERE oauth_id = ?");
                $st->execute([(int) $target["oauth_id"]]);
                $rows = $st->fetchAll();
                foreach ($rows as $r) {
                    $ids[] = (int) $r["id"];
                }
                // 同 OAuth 账号名下的 AI 一起封
                foreach ($rows as $r) {
                    $st = $pdo->prepare("SELECT id FROM members WHERE owner_id = ? AND kind = 'ai'");
                    $st->execute([(int) $r["id"]]);
                    foreach ($st->fetchAll() as $x) $ids[] = (int) $x["id"];
                }
            }
            $ids = array_values(array_unique($ids));
            $in  = implode(",", array_fill(0, count($ids), "?"));
            $args = array_merge([$on], $ids);
            $st = $pdo->prepare("UPDATE members SET banned = ? WHERE id IN (" . $in . ")");
            $st->execute($args);
            Json::ok(["banned" => $on, "count" => count($ids)]);
            return;
        }

        if ($sub === "delete") {
            $st = $pdo->prepare("UPDATE members SET deleted_at = ?, token_hash = NULL WHERE id = ?");
            $st->execute([lg_now(), $id]);
            $st = $pdo->prepare("UPDATE members SET banned = 1 WHERE owner_id = ? AND kind = 'ai'");
            $st->execute([$id]);
            Json::ok(["deleted" => true]);
            return;
        }

        // 恢复与彻底删除都交给 Account：它会还原原名、检查唯一索引冲突、连带处理名下的 AI
        if ($sub === "restore") {
            try {
                Account::restore($pdo, $me, $id);
            } catch (\RuntimeException $ex) {
                Json::fail($ex->getMessage());
            }
            self::log($pdo, "member.restore", "恢复成员 #" . $id);
            Json::ok(["restored" => true]);
            return;
        }

        if ($sub === "purge") {
            try {
                Account::purge($pdo, $me, $id);
            } catch (\RuntimeException $ex) {
                Json::fail($ex->getMessage());
            }
            self::log($pdo, "member.purge", "彻底删除成员 #" . $id);
            Json::ok(["purged" => true]);
            return;
        }

        Json::fail("不支持的操作");
    }

    /** 站点设置：超管专用。键 => 类型，写库前逐个校验，别再把 URL 存成 "0" */
    private static function settings(PDO $pdo, string $role, string $method): void {
        if ($role !== "super") Json::fail("只有超级管理员能改站点设置");
        $types = [
            "site.name"            => "text",
            "site.intro"           => "text",
            "site.url"             => "url",
            "theme"                => "theme",
            "theme.preset"         => "text",
            "avatar.default"       => "url",
            "quota.human_total"    => "int",
            "quota.ai_total"       => "int",
            "quota.ai_per_human"   => "int",
            "quota.account_per_oauth" => "int",
            "oauth.enabled"        => "int",
            "oauth.ban_sync"       => "int",
            "oauth.provider"       => "provider",
            "oauth.client_id"      => "text",
            "oauth.client_secret"  => "secret",
            "oauth.authorize_url"  => "url",
            "oauth.token_url"      => "url",
            "oauth.userinfo_url"   => "url",
            "oauth.scope"          => "text",
            "oauth.issuer"         => "url",
            // 登录门禁与人机验证：安装向导能配，后台也得能改，不然装完就锁死了
            "auth.mode"               => "authmode",
            "auth.captcha.provider"   => "captchaprovider",
            "auth.captcha.site_key"   => "text",
            "auth.captcha.secret"     => "secret",
            "auth.captcha.verify_url" => "url",
            // 邮件订阅：只有超管能配，站内信通知用户时用它发信
            "mail.enabled"            => "int",
            "mail.notify"             => "int",
            "mail.host"               => "text",
            "mail.port"               => "mport",
            "mail.secure"             => "mailsecure",
            "mail.user"               => "text",
            "mail.pass"               => "secret",
            "mail.from"               => "email",
            "mail.from_name"          => "text",
            // MCP：给 AI 用的 JSON-RPC 端点，默认开着；关掉后建 AI 不再下发地址
            "mcp.enabled"             => "int",
            // 语音转文字：站内没有识别引擎，走外部 OpenAI 兼容接口，默认关闭
            "stt.enabled"             => "int",
            "stt.url"                 => "url",
            "stt.key"                 => "secret",
            "stt.model"               => "text",
        ];
        if ($method === "GET") {
            $out = [];
            foreach ($types as $k => $_) $out[$k] = self::setting($pdo, $k, "");
            // 默认值不是空串，得单独取，否则后台开关会显示成「关」
            $out["mcp.enabled"] = self::setting($pdo, "mcp.enabled", "1");
            // 密钥不回传明文：前端只显示「已配置」，留空保存表示不改
            $hasSecret = trim($out["oauth.client_secret"]) !== "";
            $out["oauth.client_secret"] = "";
            // 人机验证的 Secret 同理：只回「是否配过」，不回明文
            $hasCaptchaSecret = trim($out["auth.captcha.secret"]) !== "";
            $out["auth.captcha.secret"] = "";
            // 邮箱密码同理
            $hasMailPass = trim($out["mail.pass"]) !== "";
            $out["mail.pass"] = "";
            // 语音转写的 Key 同理
            $hasSttKey = trim($out["stt.key"]) !== "";
            $out["stt.key"] = "";
            $callback = OAuth::callbackUrl($pdo);
            Json::ok([
                "settings"      => $out,
                "has_captcha_secret" => $hasCaptchaSecret,
                "captcha_active"     => Captcha::active($pdo),
                "captcha_warning"    => Captcha::warning($pdo),
                "has_mail_pass"      => $hasMailPass,
                "mail_configured"    => Mailer::configured($pdo),
                "mail_ready"         => Mailer::notifyOn($pdo),
                "has_stt_key"        => $hasSttKey,
                "stt_ready"          => Stt::configured($pdo),
                "callback_url"  => $callback,
                "callback_path" => OAuth::CALLBACK_PATH,
                "start_url"     => OAuth::origin() . OAuth::START_PATH,
                "well_known"    => trim($out["oauth.issuer"], "/") !== "" ? rtrim($out["oauth.issuer"], "/") . "/.well-known/openid-configuration" : "",
                "has_secret"    => $hasSecret,
            ]);
            return;
        }
        $body = Input::body();
        $changed = [];
        // 人机验证的几个键要合在一起校验：逐键过一遍会放行「provider=geetest4 但没填 Key」，
        // 落库后登录页会渲染一个跑不起来的验证框。这里用「提交值优先、留空回落到库里现值」
        // 的合成值先判断，出错时一个键都还没写，不会留下半套配置。
        if (array_key_exists("auth.captcha.provider", $body) || array_key_exists("auth.captcha.site_key", $body) || array_key_exists("auth.captcha.secret", $body)) {
            $eff = static function (string $k) use ($body, $pdo): string {
                if (array_key_exists($k, $body) && is_scalar($body[$k])) {
                    $v = trim((string) $body[$k]);
                    if ($v !== "") return $v;
                }
                return (string) self::setting($pdo, $k, "");
            };
            $cap = $eff("auth.captcha.provider");
            if ($cap === "") $cap = "builtin";
            if (!in_array($cap, ["builtin", "turnstile", "hcaptcha", "recaptcha", "geetest4"], true)) {
                Json::fail("不认识这种人机验证方式");
            }
            if ($cap !== "builtin") {
                $idLabel  = $cap === "geetest4" ? "Captcha ID" : "Site Key";
                $keyLabel = $cap === "geetest4" ? "Captcha Key" : "Secret Key";
                if ($eff("auth.captcha.site_key") === "") Json::fail("用第三方验证要填 " . $idLabel . "，登录页才知道加载哪个验证框");
                if ($eff("auth.captcha.secret") === "") Json::fail("用第三方验证要填 " . $keyLabel . "，不然服务端没法确认验证结果");
            }
        }
        foreach ($types as $k => $kind) {
            if (!array_key_exists($k, $body)) continue;
            $raw = $body[$k];
            if (!is_scalar($raw)) Json::fail("参数 " . $k . " 格式不对");
            $v = trim((string) $raw);
            if ($kind === "int") {
                if ($v === "") $v = "0";
                if (!preg_match('/^\d+$/', $v)) Json::fail("「" . $k . "」要填非负整数");
                if ((int) $v < 0) Json::fail("数值不能是负数");
            } elseif ($kind === "url") {
                if ($v !== "" && !self::isHttpUrl($v)) Json::fail("「" . $k . "」必须是以 http:// 或 https:// 开头的地址");
            } elseif ($kind === "provider") {
                if ($v !== "" && !preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $v)) Json::fail("提供方标识只能用 1-32 位小写字母、数字、- 和 _（例如 google）");
            } elseif ($kind === "theme") {
                if (!in_array($v, ["auto", "light", "dark"], true)) Json::fail("外观模式只能是 auto / light / dark");
            } elseif ($kind === "authmode") {
                if (!in_array($v, ["oauth", "captcha", "none"], true)) Json::fail("登录方式只能是 oauth / captcha / none");
            } elseif ($kind === "captchaprovider") {
                if (!in_array($v, ["builtin", "turnstile", "hcaptcha", "recaptcha", "geetest4"], true)) Json::fail("人机验证只能是 builtin / turnstile / hcaptcha / recaptcha / geetest4");
            } elseif ($kind === "mport") {
                if ($v === "") $v = "465";
                if (!preg_match('/^\d{1,5}$/', $v) || (int) $v < 1 || (int) $v > 65535) Json::fail("邮箱端口要填 1-65535 之间的数字");
            } elseif ($kind === "mailsecure") {
                if (!in_array($v, ["", "ssl", "tls"], true)) Json::fail("加密方式只能是 ssl / tls / 不加密");
            } elseif ($kind === "email") {
                if ($v !== "" && !filter_var($v, FILTER_VALIDATE_EMAIL)) Json::fail("「" . $k . "」不是有效的邮箱地址");
            }
            if ($k === "site.name" && $v === "") Json::fail("站点名不能为空");
            if ($k === "oauth.scope" && $v === "") $v = "openid profile email";
            // 密钥留空 = 保持原值，避免前端拿不到明文时把已配置的密钥清掉
            if ($kind === "secret" && $v === "") continue;
            self::putSetting($pdo, $k, $v);
            $changed[] = $k;
        }
        // 门禁开关和 auth.mode 说的是同一件事：任一处改，另一处跟着走，
        // 免得出现「OAuth 开着但登录页还是账密」或者反过来的半吊子状态
        if (in_array("oauth.enabled", $changed, true)) {
            $on = self::setting($pdo, "oauth.enabled", "0") === "1";
            $now = self::setting($pdo, "auth.mode", "captcha");
            self::putSetting($pdo, "auth.mode", $on ? "oauth" : ($now === "oauth" ? "captcha" : $now));
            if (!in_array("auth.mode", $changed, true)) $changed[] = "auth.mode";
        } elseif (in_array("auth.mode", $changed, true)) {
            $gate = self::setting($pdo, "auth.mode", "captcha") === "oauth";
            self::putSetting($pdo, "oauth.enabled", $gate ? "1" : "0");
            $changed[] = "oauth.enabled";
        }
        if (!$changed) Json::fail("没有要改的内容");
        self::log($pdo, "settings.save", "更新设置 " . count($changed) . " 项：" . implode(", ", $changed));
        Json::ok(["changed" => $changed]);
    }

    /** 空间推流开关：按作用域存，本群管理员和超管都能改 */
    private static function momentsSetting(PDO $pdo, array $me, string $method): void {
        $sid = (int) $me["scope_id"];
        $key = "moment.push." . $sid;
        if ($method === "GET") {
            $mode = (int) self::setting($pdo, $key, "0");
            if ($mode !== 1 && $mode !== 2) $mode = 0;
            Json::ok(["on" => $mode > 0, "mode" => $mode, "scope_id" => $sid]);
            return;
        }
        $raw = Input::body()["mode"] ?? null;
        if ($raw === null) {
            // 兼容旧前端：on=true 当作「全群」
            $on = Input::body()["on"] ?? null;
            if ($on === null) Json::fail("要告诉我是哪一种推流");
            $raw = in_array((string) $on, ["1", "true", "on"], true) ? 1 : 0;
        }
        $mode = (int) $raw;
        if ($mode !== 1 && $mode !== 2) $mode = 0;
        self::putSetting($pdo, $key, (string) $mode);
        $label = [0 => "关闭", 1 => "推给全群", 2 => "只推给最近活跃的人"][$mode];
        self::log($pdo, "moment.push", "本群空间推流改为「" . $label . "」");
        Json::ok(["on" => $mode > 0, "mode" => $mode]);
    }

    /** 发一封测试邮件：只用来看 SMTP 配好没有，不给成员用 */
    private static function mailTest(PDO $pdo, string $role, string $method): void {
        if ($role !== "super") Json::fail("只有超级管理员能发测试邮件");
        if ($method !== "POST") Json::fail("用 POST 发测试邮件");
        if (!Mailer::configured($pdo)) Json::fail("先把 SMTP 服务器、发件地址和端口填好");
        $to = trim((string) (Input::body()["to"] ?? ""));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) Json::fail("收件邮箱不对");
        try {
            Mailer::sendNow($pdo, $to, "测试邮件", "<p>如果你看到这封邮件，说明站点的邮件通知配好了。</p>");
        } catch (\Throwable $e) {
            Json::fail("发不出去：" . $e->getMessage());
        }
        self::log($pdo, "mail.test", "发送测试邮件到 " . $to);
        Json::ok(["sent" => true, "to" => $to]);
    }

    /** 只允许 http(s) 的绝对地址，公网/内网交给发现接口自己判断 */
    private static function isHttpUrl(string $v): bool {
        $p = parse_url($v);
        if ($p === false || empty($p["scheme"]) || empty($p["host"])) return false;
        $s = strtolower((string) $p["scheme"]);
        return ($s === "http" || $s === "https") && !str_contains($v, " ") && !str_contains($v, "\n");
    }

    private static function clusters(PDO $pdo, string $role, string $method, int $id, string $sub): void {
        if ($role !== "super") Json::fail("只有超级管理员能管互通");
        if ($method === "GET") {
            $rows = $pdo->query("SELECT * FROM clusters ORDER BY id ASC")->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $st = $pdo->prepare("SELECT id, slug, title FROM scopes WHERE cluster_id = ? ORDER BY id ASC");
                $st->execute([(int) $r["id"]]);
                $members = [];
                foreach ($st->fetchAll() as $s) {
                    $members[] = ["id" => (int) $s["id"], "slug" => (string) $s["slug"], "title" => (string) $s["title"]];
                }
                $out[] = ["id" => (int) $r["id"], "name" => (string) $r["name"], "scopes" => $members];
            }
            Json::ok(["clusters" => $out]);
            return;
        }

        if ($id === 0) {
            $name = trim((string) self::in("name", ""));
            if ($name === "") Json::fail("圈子要有个名字");
            $st = $pdo->prepare("INSERT INTO clusters (name, created_at) VALUES (?, ?)");
            $st->execute([$name, lg_now()]);
            Json::ok(["id" => (int) $pdo->lastInsertId()]);
            return;
        }

        $st = $pdo->prepare("SELECT id FROM clusters WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        if (!$st->fetch()) Json::fail("圈子不存在");

        if ($sub === "scopes") {
            $sid  = (int) self::in("scope_id", 0);
            $join = (int) self::in("join", 1) === 1;
            if ($sid <= 0) Json::fail("缺少群");
            $st = $pdo->prepare("UPDATE scopes SET cluster_id = ? WHERE id = ?");
            $st->execute([$join ? $id : null, $sid]);
            Json::ok(["scope" => $sid, "cluster" => $join ? $id : null]);
            return;
        }

        if ($sub === "delete") {
            $st = $pdo->prepare("UPDATE scopes SET cluster_id = NULL WHERE cluster_id = ?");
            $st->execute([$id]);
            $st = $pdo->prepare("DELETE FROM clusters WHERE id = ?");
            $st->execute([$id]);
            Json::ok(["deleted" => true]);
            return;
        }

        if ($sub === "name") {
            $name = trim((string) self::in("name", ""));
            if ($name === "") Json::fail("圈子要有个名字");
            $st = $pdo->prepare("UPDATE clusters SET name = ? WHERE id = ?");
            $st->execute([$name, $id]);
            Json::ok(["id" => $id]);
            return;
        }

        Json::fail("不支持的操作");
    }

    /** 本群主题包：管理员能看全部（含成员私有），也在这里建共享包 */
    private static function themes(PDO $pdo, string $role, int $myScope, string $method, int $id, string $sub): void {
        if ($role === "super" && isset($_GET["scope"]) && (int) $_GET["scope"] > 0) {
            $myScope = (int) $_GET["scope"];
        }
        if ($method === "GET" && $id === 0) {
            $st = $pdo->prepare("SELECT p.*, m.name owner_name FROM theme_packs p"
                . " LEFT JOIN members m ON m.id = p.owner_id"
                . " WHERE p.scope_id = ? ORDER BY (p.owner_id IS NULL) DESC, p.id ASC");
            $st->execute([$myScope]);
            $packs = [];
            foreach ($st->fetchAll() as $r) {
                $p = Compat::packOut($r);
                $p["owner"] = (string) ($r["owner_name"] ?? "");
                $packs[] = $p;
            }
            $st = $pdo->prepare("SELECT id, title, theme FROM scopes WHERE id = ? LIMIT 1");
            $st->execute([$myScope]);
            $sc = $st->fetch() ?: [];
            Json::ok([
                "packs" => $packs,
                "builtin" => Themes::builtin(),
                "scope" => ["id" => $myScope, "title" => (string) ($sc["title"] ?? ""), "theme" => (string) ($sc["theme"] ?? "")],
            ]);
            return;
        }

        // 改本群默认主题
        if ($method === "POST" && $sub === "scope") {
            $t = trim((string) self::in("theme", ""));
            if ($t !== "") {
                if (strpos($t, "p_") === 0) {
                    $st = $pdo->prepare("SELECT id FROM theme_packs WHERE id = ? AND scope_id = ? LIMIT 1");
                    $st->execute([(int) substr($t, 2), $myScope]);
                    if (!$st->fetch()) Json::fail("这个主题包不属于本群");
                } elseif (!preg_match('/^[a-z0-9_-]{1,40}$/', $t)) {
                    Json::fail("主题 id 不对");
                }
            }
            $pdo->prepare("UPDATE scopes SET theme = ? WHERE id = ?")->execute([$t === "" ? null : $t, $myScope]);
            Json::ok(["theme" => $t]);
            return;
        }

        // 建共享主题包
        if ($method === "POST" && $id === 0) {
            $name = mb_substr(trim((string) self::in("name", "")), 0, 20);
            if ($name === "") Json::fail("主题名不能为空");
            $vars = Compat::cleanVars(self::in("vars", []));
            if (!$vars) Json::fail("主题至少要有一个颜色");
            $dark = (int) self::in("dark", 0) === 1 ? 1 : 0;
            $pdo->prepare("INSERT INTO theme_packs (scope_id, owner_id, name, dark, vars, created_at) VALUES (?, NULL, ?, ?, ?, ?)")
                ->execute([$myScope, $name, $dark, (string) json_encode($vars, JSON_UNESCAPED_UNICODE), lg_now()]);
            Json::ok(Compat::packOut([
                "id" => (int) $pdo->lastInsertId(), "name" => $name, "dark" => $dark,
                "vars" => (string) json_encode($vars, JSON_UNESCAPED_UNICODE), "owner_id" => null,
            ]));
            return;
        }

        // 删本群任意主题包（成员私有的也归管理员处置）
        if ($method === "POST" && $id > 0) {
            $st = $pdo->prepare("SELECT id FROM theme_packs WHERE id = ? AND scope_id = ? LIMIT 1");
            $st->execute([$id, $myScope]);
            if (!$st->fetch()) Json::fail("主题包不存在");
            $pdo->prepare("DELETE FROM theme_packs WHERE id = ?")->execute([$id]);
            // 成员身上存的是「模式-主题包」编码（如 dark-p_3），裸 id 和编码两种形态都要清，
            // 否则删掉主题包之后，选过深色的成员会挂在一个不存在的主题上
            $tag  = "p_" . $id;
            $like = "%-" . str_replace("_", "\\_", $tag);
            $pdo->prepare("UPDATE members SET theme = NULL WHERE theme = ? OR theme LIKE ? ESCAPE '\\'")
                ->execute([$tag, $like]);
            $pdo->prepare("UPDATE scopes SET theme = NULL WHERE theme = ? AND id = ?")->execute([$tag, $myScope]);
            Json::ok(["deleted" => "p_" . $id]);
            return;
        }

        Json::fail("不支持的操作");
    }


    /**
     * 会话审计：管理员能回看本域的会话（含成员之间的私聊），超管能看任意域。
     * 每进一次会话，就在会话里留一条系统提示。
     */
    private static function convs(PDO $pdo, string $role, int $myScope, string $method, int $id, string $sub): void {
        if ($method === "POST") {
            self::convAction($pdo, $role, $myScope, $id, $sub);
            return;
        }
        if ($id > 0) {
            self::convDetail($pdo, $role, $myScope, $id);
            return;
        }
        self::convList($pdo, $role, $myScope);
    }

    /** 会话级操作：目前只有清空历史 */
    private static function convAction(PDO $pdo, string $role, int $myScope, int $convId, string $sub): void {
        $conv = Conv::convById($pdo, $convId);
        if ($conv === null) Json::fail("会话不存在");
        if ($role !== "super" && (int) $conv["scope_id"] !== $myScope) Json::fail("没有权限操作这个会话");

        if ($sub === "clear") {
            $now = lg_now();
            $st = $pdo->prepare("UPDATE messages SET deleted_at = ?, deleted_by = 0 WHERE conv_id = ? AND deleted_at IS NULL");
            $st->execute([$now, $convId]);
            $n = $st->rowCount();
            $pdo->prepare("UPDATE conversations SET last_msg_id = 0, last_msg_at = 0 WHERE id = ?")->execute([$convId]);
            self::noteAdmin($pdo, (int) $conv["scope_id"], $convId, "清空了本会话的 " . $n . " 条历史消息");
            self::log($pdo, "conv.clear", "清空会话 #" . $convId . "（" . $n . " 条）");
            Json::ok(["cleared" => $n]);
            return;
        }
        Json::fail("未知操作");
    }

    /** 单条消息操作：删除（软删，留痕） */
    private static function messagesApi(PDO $pdo, string $role, int $myScope, string $method, int $id): void {
        if ($method !== "POST") Json::fail("只支持 POST");
        if ($id <= 0) Json::fail("缺少消息 id");

        $st = $pdo->prepare("SELECT id, scope_id, conv_id, member_id, sys, deleted_at FROM messages WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $msg = $st->fetch();
        if (!$msg) Json::fail("消息不存在");
        if ($role !== "super" && (int) $msg["scope_id"] !== $myScope) Json::fail("没有权限操作这条消息");
        if ((int) $msg["sys"] === 1) Json::fail("系统提示不能删");
        if ($msg["deleted_at"] !== null) Json::fail("这条消息已经删过了");

        $body = Input::body();
        $op = (string) ($body["op"] ?? "");
        if ($op !== "delete") Json::fail("未知操作");

        $now = lg_now();
        $pdo->prepare("UPDATE messages SET deleted_at = ?, deleted_by = 0 WHERE id = ?")->execute([$now, $id]);
        self::noteAdmin($pdo, (int) $msg["scope_id"], (int) $msg["conv_id"], "删除了一条消息");
        self::log($pdo, "message.delete", "删除消息 #" . $id);
        Json::ok(["deleted" => $id]);
    }

    /** 管理员动作写进会话里的系统提示（和回看留痕同一个写法） */
    private static function noteAdmin(PDO $pdo, int $scopeId, int $convId, string $what): int {
        $me = Session::member($pdo);
        if ($me === null) return 0;
        $who = "管理员「" . (string) $me["name"] . "」";
        return Compat::systemMessage($pdo, $scopeId, $convId, (int) $me["id"], $who . $what);
    }

    private static function convList(PDO $pdo, string $role, int $myScope): void {
        $scopeId = $myScope;
        if ($role === "super") {
            $raw = (string) self::in("scope", "");
            if ($raw !== "") $scopeId = (int) $raw;
        }
        $where = "c.scope_id = ?";
        $args  = [$scopeId];
        if ($role === "super" && $scopeId === 0) {
            $where = "1 = 1";
            $args  = [];
        }

        $sql = "SELECT c.id, c.kind, c.is_public, c.scope_id, c.last_msg_id, c.last_msg_at, s.title AS scope_title,"
             . " (SELECT COUNT(*) FROM messages m WHERE m.conv_id = c.id AND m.deleted_at IS NULL AND m.sys = 0) AS msg_count"
             . " FROM conversations c JOIN scopes s ON s.id = c.scope_id"
             . " WHERE $where ORDER BY c.last_msg_at DESC, c.id DESC LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $conv  = ["id" => (int) $row["id"], "scope_id" => (int) $row["scope_id"], "is_public" => (int) $row["is_public"]];
            $isPub = (int) $row["is_public"] === 1;
            $people = self::convPeople($pdo, $conv);
            $out[] = [
                "id"         => (int) $row["id"],
                "kind"       => (string) $row["kind"],
                "isPublic"   => $isPub,
                "scopeId"    => (int) $row["scope_id"],
                "scopeTitle" => (string) $row["scope_title"],
                "title"      => $isPub ? (string) $row["scope_title"] : implode(" · ", array_map(fn($p) => $p["name"], $people)),
                "members"    => $people,
                "msgCount"   => (int) $row["msg_count"],
                "lastAt"     => (int) $row["last_msg_at"],
            ];
        }
        Json::ok(["convs" => $out, "scope" => $scopeId, "role" => $role]);
    }

    private static function convDetail(PDO $pdo, string $role, int $myScope, int $convId): void {
        $conv = Conv::convById($pdo, $convId);
        if ($conv === null) Json::fail("会话不存在");
        if ($role !== "super" && (int) $conv["scope_id"] !== $myScope) Json::fail("没有权限查看这个会话");

        $st = $pdo->prepare("SELECT * FROM scopes WHERE id = ? LIMIT 1");
        $st->execute([(int) $conv["scope_id"]]);
        $scope = $st->fetch();
        if (!$scope) Json::fail("会话所属的群不存在");

        $since = (int) (self::in("since", "0") ?: 0);
        $limit = (int) (self::in("limit", "80") ?: 80);
        if ($limit <= 0) $limit = 80;
        if ($limit > 200) $limit = 200;

        $msgs = Compat::fetchSince($pdo, $scope, $since, $limit, $convId);
        $isPub = (int) $conv["is_public"] === 1;
        $people = self::convPeople($pdo, $conv);

        // 回看一次就留一次痕（同一人同一会话一分钟内只写一条，免得刷新刷屏）
        self::auditMark($pdo, $conv, (int) $scope["id"], $role);

        Json::ok([
            "conv" => [
                "id"         => (int) $conv["id"],
                "kind"       => (string) $conv["kind"],
                "isPublic"   => $isPub,
                "title"      => $isPub ? (string) $scope["title"] : implode(" · ", array_map(fn($p) => $p["name"], $people)),
                "scopeId"    => (int) $scope["id"],
                "scopeTitle" => (string) $scope["title"],
                "members"    => $people,
            ],
            "msgs" => $msgs,
            "now"  => lg_now(),
        ]);
    }

    private static function convPeople(PDO $pdo, array $conv): array {
        $out = [];
        foreach (Conv::memberIds($pdo, $conv) as $mid) {
            $st = $pdo->prepare("SELECT id, name, kind FROM members WHERE id = ? LIMIT 1");
            $st->execute([$mid]);
            $m = $st->fetch();
            if ($m) $out[] = ["id" => (int) $m["id"], "name" => (string) $m["name"], "kind" => (string) $m["kind"]];
        }
        return $out;
    }

    private static function auditMark(PDO $pdo, array $conv, int $scopeId, string $role): int {
        $me = Session::member($pdo);
        if ($me === null) return 0;
        if ((string) $me["kind"] === "ai") return 0;

        $st = $pdo->prepare("SELECT ts_ms FROM messages WHERE conv_id = ? AND member_id = ? AND sys = 1 ORDER BY id DESC LIMIT 1");
        $st->execute([(int) $conv["id"], (int) $me["id"]]);
        $prev = $st->fetchColumn();
        if ($prev !== false && lg_now() - (int) $prev < 60000) return 0;

        $tz = new \DateTimeZone("Asia/Shanghai");
        $dt = new \DateTime("now", $tz);
        $dt->setTimestamp((int) floor(lg_now() / 1000));
        $who  = $role === "super" ? "超级管理员" : "管理员";
        $text = $who . "「" . (string) $me["name"] . "」于 " . $dt->format("H:i:s") . " 时访问了该会话";
        return Compat::systemMessage($pdo, $scopeId, (int) $conv["id"], (int) $me["id"], $text);
    }

    // ================================================================ 仪表盘

    /**
     * 仪表盘真数据：成员/消息/会话/AI 四张卡 + 近 7 天消息趋势 + 最近注册 + 最近审计。
     * 普通管理员只看自己那个群，超管看全站。
     */
    private static function dashboard(PDO $pdo, array $me, string $role): void {
        $myScope = (int) $me["scope_id"];
        $super   = $role === "super";
        $tz      = new \DateTimeZone("Asia/Shanghai");
        $now     = new \DateTime("now", $tz);
        $today   = (clone $now)->setTime(0, 0, 0)->getTimestamp() * 1000;

        $humans = self::countOne($pdo, "SELECT COUNT(*) FROM members WHERE kind <> 'ai' AND deleted_at IS NULL", $super, $myScope);
        $newHumans = self::countOne($pdo, "SELECT COUNT(*) FROM members WHERE kind <> 'ai' AND deleted_at IS NULL AND created_at >= ?", $super, $myScope, [$today]);
        $ais    = self::countOne($pdo, "SELECT COUNT(*) FROM members WHERE kind = 'ai' AND deleted_at IS NULL", $super, $myScope);
        $msgs   = self::countOne($pdo, "SELECT COUNT(*) FROM messages WHERE sys = 0 AND deleted_at IS NULL", $super, $myScope);
        $msgToday = self::countOne($pdo, "SELECT COUNT(*) FROM messages WHERE sys = 0 AND deleted_at IS NULL AND ts_ms >= ?", $super, $myScope, [$today]);
        $activeConvs = self::countOne($pdo, "SELECT COUNT(*) FROM conversations WHERE last_msg_at >= ?", $super, $myScope, [lg_now() - 7 * 86400000]);
        $scopeCount = 0;
        if ($super) {
            $scopeCount = (int) $pdo->query("SELECT COUNT(*) FROM scopes WHERE archived = 0")->fetchColumn();
        } else {
            $scopeCount = 1;
        }

        // 近 7 天（含今天）：按本地 0 点切天，逐天统计，不做时区魔法
        $trend = [];
        $weekday = ["1" => "周一", "2" => "周二", "3" => "周三", "4" => "周四", "5" => "周五", "6" => "周六", "7" => "周日"];
        for ($i = 6; $i >= 0; $i--) {
            $d = (clone $now)->modify("-" . $i . " days")->setTime(0, 0, 0);
            $from = $d->getTimestamp() * 1000;
            $to   = (clone $d)->modify("+1 day")->getTimestamp() * 1000;
            $n = self::countOne($pdo, "SELECT COUNT(*) FROM messages WHERE sys = 0 AND deleted_at IS NULL AND ts_ms >= ? AND ts_ms < ?", $super, $myScope, [$from, $to]);
            $trend[] = [
                "date"    => $d->format("Y-m-d"),
                "label"   => $d->format("m-d"),
                "weekday" => $weekday[$d->format("N")] ?? "",
                "count"   => $n,
            ];
        }

        $st = $pdo->prepare("SELECT m.id, m.name, m.kind, m.created_at, s.title AS scope_title, a.role AS acc_role"
            . " FROM members m LEFT JOIN scopes s ON s.id = m.scope_id LEFT JOIN accounts a ON a.id = m.account_id"
            . " WHERE m.deleted_at IS NULL" . ($super ? "" : " AND m.scope_id = ?")
            . " ORDER BY m.id DESC LIMIT 5");
        $st->execute($super ? [] : [$myScope]);
        $recent = [];
        foreach ($st->fetchAll() as $r) {
            $recent[] = [
                "id"      => (int) $r["id"],
                "name"    => (string) $r["name"],
                "kind"    => (string) $r["kind"],
                "badge"   => self::badgeOf((string) ($r["acc_role"] ?? "")),
                "scope"   => (string) ($r["scope_title"] ?? ""),
                "created_at" => (int) $r["created_at"],
            ];
        }

        $events = [];
        $st = $pdo->prepare("SELECT at, name, action, detail FROM admin_log WHERE 1 = 1"
            . ($super ? "" : " AND (scope_id = ? OR scope_id IS NULL)")
            . " ORDER BY at DESC LIMIT 20");
        $st->execute($super ? [] : [$myScope]);
        foreach ($st->fetchAll() as $r) {
            $events[] = [
                "at"     => (int) $r["at"],
                "who"    => (string) $r["name"],
                "action" => self::actionLabel((string) $r["action"]),
                "detail" => (string) $r["detail"],
                "kind"   => "admin",
            ];
        }
        $st = $pdo->prepare("SELECT x.ts_ms AS at, m.name AS name, x.text AS detail FROM messages x"
            . " LEFT JOIN members m ON m.id = x.member_id"
            . " WHERE x.sys = 1" . ($super ? "" : " AND x.scope_id = ?")
            . " ORDER BY x.id DESC LIMIT 20");
        $st->execute($super ? [] : [$myScope]);
        foreach ($st->fetchAll() as $r) {
            $events[] = [
                "at"     => (int) $r["at"],
                "who"    => (string) ($r["name"] ?? ""),
                "action" => "会话审计",
                "detail" => (string) $r["detail"],
                "kind"   => "audit",
            ];
        }
        usort($events, function ($a, $b) { return $b["at"] <=> $a["at"]; });
        $events = array_slice($events, 0, 20);

        Json::ok([
            "role"         => $role,
            "me"           => ["id" => (int) $me["id"], "name" => (string) $me["name"]],
            "generated_at" => lg_now(),
            "cards"        => [
                ["key" => "members",  "label" => "人类成员", "value" => $humans,      "sub" => "今日新增 " . $newHumans, "icon" => "fa-users",         "tone" => "blue"],
                ["key" => "messages", "label" => "消息总数", "value" => $msgs,        "sub" => "今日 " . $msgToday,      "icon" => "fa-comments",      "tone" => "green"],
                ["key" => "convs",    "label" => "活跃会话", "value" => $activeConvs, "sub" => "近 7 天有消息",           "icon" => "fa-comment-dots",  "tone" => "violet"],
                ["key" => "ais",      "label" => "AI 成员",  "value" => $ais,         "sub" => "和人类同群",             "icon" => "fa-robot",         "tone" => "amber"],
                ["key" => "scopes",   "label" => "群组",     "value" => $scopeCount,  "sub" => "未归档",                 "icon" => "fa-layer-group",   "tone" => "slate"],
            ],
            "trend"        => $trend,
            "recent_members" => $recent,
            "events"       => $events,
        ]);
    }

    /** 带可选 scope 过滤的 count；参数顺序固定为「先 ? 参数，再 scope」 */
    private static function countOne(PDO $pdo, string $sql, bool $super, int $scopeId, array $args = []): int {
        if (!$super) {
            // scope 条件插在 WHERE 最前面，所以参数也要前插，顺序才对得上
            $sql = str_replace(" WHERE ", " WHERE scope_id = ? AND ", $sql);
            array_unshift($args, $scopeId);
        }
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return (int) $st->fetchColumn();
    }

    private static function badgeOf(string $accRole): string {
        if ($accRole === "super") return "super";
        if ($accRole === "admin") return "admin";
        return "";
    }

    // ================================================================ 管理员账号

    /** accounts 表增删改查：超管专用；建号时顺手补一条群身份，否则登录页会说「还没有群身份」 */
    private static function accounts(PDO $pdo, array $me, string $role, string $method, int $id, string $sub): void {
        if ($role !== "super") Json::fail("只有超级管理员能管理管理员账号");

        if ($method === "GET") {
            $rows = $pdo->query("SELECT a.*, s.title AS scope_title, oi.provider AS oauth_provider, oi.sub AS oauth_sub FROM accounts a"
                . " LEFT JOIN scopes s ON s.id = a.scope_id"
                . " LEFT JOIN oauth_identities oi ON oi.id = a.oauth_id"
                . " ORDER BY CASE a.role WHEN 'super' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END, a.id ASC")->fetchAll();
            $myAccount = (int) ($me["account_id"] ?? 0);
            $out = [];
            foreach ($rows as $r) {
                $aid = (int) $r["id"];
                $st = $pdo->prepare("SELECT COUNT(*) FROM members WHERE account_id = ? AND deleted_at IS NULL");
                $st->execute([$aid]);
                $members = (int) $st->fetchColumn();
                $st = $pdo->prepare("SELECT COUNT(*) FROM members WHERE account_id = ? AND oauth_id IS NOT NULL AND deleted_at IS NULL");
                $st->execute([$aid]);
                $bound = (int) $st->fetchColumn();
                $out[] = [
                    "id"         => $aid,
                    "name"       => (string) $r["name"],
                    "role"       => (string) $r["role"],
                    "scope_id"   => $r["scope_id"] === null ? 0 : (int) $r["scope_id"],
                    "scope"      => (string) ($r["scope_title"] ?? ""),
                    "members"    => $members,
                    "oauth"      => $bound,
                    "oauth_provider" => (string) ($r["oauth_provider"] ?? ""),
                    "oauth_sub"      => (string) ($r["oauth_sub"] ?? ""),
                    "banned"     => (int) $r["banned"] === 1,
                    "deleted"    => $r["deleted_at"] !== null,
                    "created_at" => (int) $r["created_at"],
                    "self"       => $aid === $myAccount,
                ];
            }
            $scopes = [];
            foreach ($pdo->query("SELECT id, title, slug FROM scopes WHERE archived = 0 ORDER BY id ASC")->fetchAll() as $s) {
                $scopes[] = ["id" => (int) $s["id"], "title" => (string) $s["title"], "slug" => (string) $s["slug"]];
            }
            // 门禁开着时，新增管理员的表单要额外收 OAuth 归属，这里告诉前端要不要显示那块
            $providers = [];
            foreach ($pdo->query("SELECT DISTINCT provider FROM oauth_identities WHERE provider <> ''")->fetchAll() as $p) {
                $providers[] = (string) $p["provider"];
            }
            $defProvider = self::setting($pdo, "oauth.provider", "generic");
            if ($defProvider === "") $defProvider = "generic";
            if (!in_array($defProvider, $providers, true)) array_unshift($providers, $defProvider);
            Json::ok([
                "accounts"      => $out,
                "scopes"        => $scopes,
                "me"            => $myAccount,
                "gate"          => self::gateOn($pdo),
                "providers"     => $providers,
                "default_provider" => $defProvider,
            ]);
            return;
        }

        if ($id === 0) {
            self::accountCreate($pdo, $method);
            return;
        }

        $st = $pdo->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $target = $st->fetch();
        if (!$target) Json::fail("账号不存在");

        if ($sub === "password") {
            self::accountPassword($pdo, $target);
            return;
        }
        if ($sub === "delete") {
            self::accountDelete($pdo, $me, $target);
            return;
        }
        if ($sub !== "") Json::fail("不支持的操作");
        self::accountUpdate($pdo, $me, $target);
    }

    private static function accountCreate(PDO $pdo, string $method): void {
        if ($method !== "POST") Json::fail("用 POST 提交");
        $name    = trim((string) self::in("name", ""));
        $pass    = (string) self::in("password", "");
        $roleIn  = (string) self::in("role", "admin");
        $scopeId = (int) self::in("scope_id", 0);
        if (!preg_match('/^[\p{L}\p{N}_.\-]{2,32}$/u', $name)) Json::fail("账号名 2-32 位，只能用文字、数字和 . _ -");
        if (mb_strlen($pass) < 8) Json::fail("密码至少 8 位");
        if (!in_array($roleIn, ["admin", "super"], true)) Json::fail("角色只能是管理员或超级管理员");
        $scopeId = self::pickScope($pdo, $scopeId);
        // 先查重名：万一重名，下面已经登记好的 oauth_identities 就白建一条
        $st = $pdo->prepare("SELECT 1 FROM accounts WHERE name = ? LIMIT 1");
        $st->execute([$name]);
        if ($st->fetchColumn()) Json::fail("这个账号名已被占用（含已停用的账号）");

        // 门禁（auth.mode=oauth）开着的时候，管理员也走 OAuth 登录：
        // 不把账号和提供方那边的唯一标识（sub）绑上的话，他自己 OAuth 登录会被当成新成员另建一个号。
        $oauthId = null;
        if (self::gateOn($pdo)) {
            $provider = trim((string) self::in("oauth_provider", ""));
            if ($provider === "") $provider = self::setting($pdo, "oauth.provider", "generic");
            if ($provider === "") $provider = "generic";
            if (!preg_match('/^[A-Za-z0-9_.\-]{1,32}$/', $provider)) Json::fail("OAuth 提供方标识只能用字母、数字和 . _ -");
            $sub = trim((string) self::in("oauth_sub", ""));
            if ($sub === "") Json::fail("门禁开着，要填这个管理员的 OAuth 主体（sub）");
            if (mb_strlen($sub) > 190) Json::fail("OAuth 主体太长了（最多 190 个字）");
            $email = trim((string) self::in("oauth_email", ""));
            $oauthId = self::bindIdentity($pdo, $provider, $sub, $email, $name);
        }

        try {
            $st = $pdo->prepare("INSERT INTO accounts (name, oauth_id, pass_hash, role, scope_id, banned, created_at) VALUES (?, ?, ?, ?, ?, 0, ?)");
            $st->execute([$name, $oauthId, password_hash($pass, PASSWORD_DEFAULT), $roleIn, $roleIn === "super" ? null : $scopeId, lg_now()]);
        } catch (\Throwable $e) {
            Json::fail("这个账号名已被占用（含已停用的账号）");
        }
        $aid = (int) $pdo->lastInsertId();
        self::ensureAccountMember($pdo, $aid, $scopeId, $name, $oauthId);
        self::log($pdo, "accounts.create", "新增" . ($roleIn === "super" ? "超级管理员" : "管理员") . "「" . $name . "」", $scopeId);
        Json::ok(["id" => $aid]);
    }

    private static function accountUpdate(PDO $pdo, array $me, array $target): void {
        $id = (int) $target["id"];
        $isSelf = $id === (int) ($me["account_id"] ?? 0);
        $sets = [];
        $args = [];

        $name = self::in("name", null);
        if ($name !== null) {
            $name = trim((string) $name);
            if (!preg_match('/^[\p{L}\p{N}_.\-]{2,32}$/u', $name)) Json::fail("账号名 2-32 位，只能用文字、数字和 . _ -");
            if ($name !== (string) $target["name"]) {
                try {
                    $pdo->prepare("UPDATE accounts SET name = ? WHERE id = ?")->execute([$name, $id]);
                } catch (\Throwable $e) {
                    Json::fail("这个账号名已被占用");
                }
                // 群里的显示名跟着改；重名冲突就保留原显示名，不阻断保存
                $st = $pdo->prepare("SELECT id FROM members WHERE account_id = ? AND deleted_at IS NULL");
                $st->execute([$id]);
                foreach ($st->fetchAll() as $r) {
                    try {
                        $pdo->prepare("UPDATE members SET name = ? WHERE id = ?")->execute([$name, (int) $r["id"]]);
                    } catch (\Throwable $e) {
                    }
                }
            }
        }

        $roleIn = self::in("role", null);
        if ($roleIn !== null) {
            $roleIn = (string) $roleIn;
            if (!in_array($roleIn, ["member", "admin", "super"], true)) Json::fail("角色不对");
            $oldRole = (string) $target["role"];
            if ($isSelf && $roleIn !== "super" && $oldRole === "super") Json::fail("不能降低自己的权限，请让另一位超级管理员操作");
            if ($oldRole === "super" && $roleIn !== "super" && self::superCount($pdo, $id) <= 0) {
                Json::fail("站点至少要留一个超级管理员");
            }
            if ($roleIn === "super") {
                $sets[] = "role = ?"; $args[] = "super";
                $sets[] = "scope_id = NULL";
            } elseif ($roleIn === "admin") {
                $scopeId = self::pickScope($pdo, (int) self::in("scope_id", (int) ($target["scope_id"] ?? 0)));
                $sets[] = "role = ?"; $args[] = "admin";
                $sets[] = "scope_id = ?"; $args[] = $scopeId;
                self::ensureAccountMember($pdo, $id, $scopeId, (string) $target["name"], (int) ($target["oauth_id"] ?? 0) ?: null);
            } else {
                $sets[] = "role = ?"; $args[] = "member";
                $sets[] = "scope_id = NULL";
            }
        } elseif ((int) self::in("scope_id", 0) > 0 && (string) $target["role"] === "admin") {
            $scopeId = self::pickScope($pdo, (int) self::in("scope_id", 0));
            $sets[] = "scope_id = ?"; $args[] = $scopeId;
            self::ensureAccountMember($pdo, $id, $scopeId, (string) $target["name"], (int) ($target["oauth_id"] ?? 0) ?: null);
        }

        if (!$sets) Json::fail("没有要改的内容");
        $args[] = $id;
        $pdo->prepare("UPDATE accounts SET " . implode(", ", $sets) . " WHERE id = ?")->execute($args);
        self::log($pdo, "accounts.update", "修改管理员「" . (string) $target["name"] . "」");
        Json::ok(["id" => $id]);
    }

    private static function accountPassword(PDO $pdo, array $target): void {
        $pass = (string) self::in("password", "");
        if (mb_strlen($pass) < 8) Json::fail("密码至少 8 位");
        $pdo->prepare("UPDATE accounts SET pass_hash = ? WHERE id = ?")
            ->execute([password_hash($pass, PASSWORD_DEFAULT), (int) $target["id"]]);
        self::log($pdo, "accounts.password", "重置管理员「" . (string) $target["name"] . "」的密码");
        Json::ok(["id" => (int) $target["id"]]);
    }

    private static function accountDelete(PDO $pdo, array $me, array $target): void {
        $id = (int) $target["id"];
        if ($id === (int) ($me["account_id"] ?? 0)) Json::fail("不能删除自己");
        if ((string) $target["role"] === "super" && self::superCount($pdo, $id) <= 0) {
            Json::fail("站点至少要留一个超级管理员");
        }
        $pdo->prepare("UPDATE accounts SET deleted_at = ?, pass_hash = NULL WHERE id = ?")
            ->execute([lg_now(), $id]);
        self::log($pdo, "accounts.delete", "停用管理员「" . (string) $target["name"] . "」");
        Json::ok(["deleted" => true]);
    }

    /** 除自己以外还有几个在用的超管 */
    private static function superCount(PDO $pdo, int $excludeId): int {
        $st = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE role = 'super' AND deleted_at IS NULL AND id <> ?");
        $st->execute([$excludeId]);
        return (int) $st->fetchColumn();
    }

    /** 给账号补群身份：没有就建，被注销过就恢复；名字冲突时自动加后缀 */
    private static function ensureAccountMember(PDO $pdo, int $accountId, int $scopeId, string $name, ?int $oauthId = null): int {
        $st = $pdo->prepare("SELECT id, deleted_at FROM members WHERE account_id = ? AND scope_id = ? LIMIT 1");
        $st->execute([$accountId, $scopeId]);
        $row = $st->fetch();
        if ($row) {
            if ($row["deleted_at"] !== null) {
                $pdo->prepare("UPDATE members SET deleted_at = NULL, banned = 0 WHERE id = ?")->execute([(int) $row["id"]]);
            }
            // 门禁下群身份也跟着主账号绑同一份 OAuth 身份，配额统计才对得上
            if ($oauthId !== null) {
                $pdo->prepare("UPDATE members SET oauth_id = ? WHERE id = ?")->execute([$oauthId, (int) $row["id"]]);
            }
            return (int) $row["id"];
        }
        $memberName = self::uniqueMemberName($pdo, $name, $scopeId);
        $pdo->prepare("INSERT INTO members (scope_id, name, kind, account_id, oauth_id, created_at) VALUES (?, ?, 'human', ?, ?, ?)")
            ->execute([$scopeId, $memberName, $accountId, $oauthId, lg_now()]);
        return (int) $pdo->lastInsertId();
    }

    /** 门禁是不是开着（auth.mode = oauth） */
    private static function gateOn(PDO $pdo): bool {
        return self::setting($pdo, "auth.mode", "none") === "oauth";
    }

    /**
     * 把 OAuth 身份（provider + sub）登记到 oauth_identities 并返回它的 id。
     * 同一个 sub 只能绑一个账号：否则两个账号 OAuth 登录会抢同一条 identity。
     */
    private static function bindIdentity(PDO $pdo, string $provider, string $sub, string $email, string $display): int {
        // 跟 OAuth 登录时一样不区分大小写，免得后台绑上的身份和登录时查到的那条对不上
        $st = $pdo->prepare("SELECT * FROM oauth_identities WHERE LOWER(provider) = LOWER(?) AND sub = ? LIMIT 1");
        $st->execute([$provider, $sub]);
        $row = $st->fetch();
        if ($row) {
            $id = (int) $row["id"];
            $st = $pdo->prepare("SELECT name FROM accounts WHERE oauth_id = ? AND deleted_at IS NULL LIMIT 1");
            $st->execute([$id]);
            $owner = $st->fetchColumn();
            if ($owner !== false && $owner !== null && (string) $owner !== "") {
                Json::fail("这个 OAuth 身份已经绑在「" . (string) $owner . "」上了");
            }
            if ($email !== "" || $display !== "") {
                $pdo->prepare("UPDATE oauth_identities SET email = COALESCE(NULLIF(email, ''), ?), display = COALESCE(NULLIF(display, ''), ?) WHERE id = ?")
                    ->execute([$email, $display, $id]);
            }
            return $id;
        }
        $pdo->prepare("INSERT INTO oauth_identities (provider, sub, email, display, created_at) VALUES (?, ?, ?, ?, ?)")
            ->execute([$provider, $sub, $email, $display, lg_now()]);
        return (int) $pdo->lastInsertId();
    }

    private static function uniqueMemberName(PDO $pdo, string $base, int $scopeId): string {
        if ($base === "") $base = "管理员";
        for ($i = 1; $i <= 50; $i++) {
            $cand = $i === 1 ? $base : $base . $i;
            $st = $pdo->prepare("SELECT 1 FROM members WHERE scope_id = ? AND name = ? LIMIT 1");
            $st->execute([$scopeId, $cand]);
            if (!$st->fetchColumn()) return $cand;
        }
        return $base . "-" . bin2hex(random_bytes(3));
    }

    /** 没指定群就用主群（slug 为空），再没有就最早建的群 */
    private static function pickScope(PDO $pdo, int $prefer): int {
        if ($prefer > 0) {
            $st = $pdo->prepare("SELECT id FROM scopes WHERE id = ? LIMIT 1");
            $st->execute([$prefer]);
            if ($st->fetchColumn()) return $prefer;
        }
        $id = (int) $pdo->query("SELECT id FROM scopes WHERE archived = 0 ORDER BY CASE WHEN slug = '' THEN 0 ELSE 1 END, id ASC LIMIT 1")->fetchColumn();
        if ($id <= 0) Json::fail("站点还没有群，先建一个群再设管理员");
        return $id;
    }

    // ================================================================ OAuth 发现

    /** well-known 自动发现：只把结果返回给前端填表，落库仍然走 settings 保存 */
    private static function oauthApi(PDO $pdo, string $role, string $method): void {
        if ($role !== "super") Json::fail("只有超级管理员能配置 OAuth");
        if ($method !== "POST") Json::fail("自动发现要用 POST 提交");
        $issuer = trim((string) self::in("issuer", ""));
        $r = OAuth::discover($issuer);
        if (!$r["ok"]) Json::fail((string) $r["err"]);
        self::log($pdo, "oauth.discover", "从 well-known 发现：" . $r["issuer"]);
        Json::ok([
            "issuer"     => $r["issuer"],
            "well_known" => $r["well_known"],
            "discovered" => $r["data"],
            "warnings"   => $r["warnings"],
        ]);
    }

    // ---------------------------------------------------------------- 更新检查

    /**
     * 更新服务器就是 GitHub：版本和说明读仓库里的 api/manifest.json，
     * 源码包下载时才去取 latest release。站点自己不再托管清单，
     * 否则「本站副本总是和代码同版本」，永远查不出真正的更新。
     */
    private static function updateApi(PDO $pdo): void {
        $current = self::currentVersion();
        $rel = \LightGroup\Web\Updater::latest();

        if (!$rel["ok"]) {
            Json::ok([
                "current"    => $current,
                "latest"     => null,
                "has_update" => false,
                "error"      => (string) $rel["err"],
            ]);
        }

        $latest = [
            "version"     => (string) $rel["version"],
            "released_at" => (string) $rel["released_at"],
            "notes"       => (string) $rel["notes"],
            "url"         => (string) $rel["html_url"],
        ];
        Json::ok([
            "current"    => $current,
            "latest"     => $latest,
            "has_update" => version_compare($latest["version"], $current, ">"),
            "error"      => "",
        ]);
    }

    /**
     * POST update：拉 GitHub 最新 release 的源码包覆盖安装。
     * 只超管能点；config.php / data / uploads / favicon 这些由 Updater 自己跳过。
     */
    private static function updateApply(PDO $pdo, string $role): void {
        if ($role !== "super") Json::fail("只有超级管理员能更新站点");
        $cur = self::currentVersion();
        $rel = \LightGroup\Web\Updater::latest();
        if (!$rel["ok"]) Json::fail((string) $rel["err"]);
        $to = (string) $rel["version"];
        if (!version_compare($to, $cur, ">")) {
            Json::ok(["updated" => false, "current" => $cur, "version" => $to]);
        }
        $root = defined("APP_ROOT") ? (string) APP_ROOT : dirname(__DIR__, 2);
        $r = \LightGroup\Web\Updater::apply($root, $rel);
        if (!$r["ok"]) Json::fail((string) $r["err"]);
        self::log($pdo, "site.update", "站点更新 " . $cur . " -> " . $to . "（" . $r["files"] . " 个文件）");
        Json::ok([
            "updated" => true,
            "from"    => $cur,
            "version" => $to,
            "files"   => $r["files"],
            "failed"  => $r["failed"],
            "backup"  => $r["backup"],
        ]);
    }

    /** 当前站点版本：读 api/manifest.json（与底栏同一来源），.lock 只作回退 */
    private static function currentVersion(): string {
        return \LightGroup\Web\Version::current();
    }

    /** 拉一小段文本（更新清单），超时/失败都返回 null，不抛 */
    private static function httpText(string $url): ?string {
        if (function_exists("curl_init")) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_USERAGENT      => "LightGroup-Update/1.0",
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (!is_string($body) || $code < 200 || $code >= 300) return null;
            return $body;
        }
        $ctx = stream_context_create(["http" => ["timeout" => 6, "user_agent" => "LightGroup-Update/1.0"]]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : null;
    }

    // ================================================================ 公共小件

    /** 写操作必须带 X-LG-CSRF，值是 admin.php 渲染时写进会话的 token */
    private static function guardCsrf(string $method): void {
        if ($method !== "POST") return;
        $want = (string) ($_SESSION["lg_csrf"] ?? "");
        $got  = (string) ($_SERVER["HTTP_X_LG_CSRF"] ?? "");
        if ($want === "" || $got === "" || !hash_equals($want, $got)) {
            Json::fail("页面已过期，请刷新后台后重试");
        }
    }

    /** 管理操作日志：仪表盘的「最近审计」用；老库也能幂等补表 */
    private static function ensureTables(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        $done = true;
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "mysql";
        $pk    = $mysql ? "BIGINT PRIMARY KEY AUTO_INCREMENT" : "INTEGER PRIMARY KEY AUTOINCREMENT";
        $text  = $mysql ? "LONGTEXT" : "TEXT";
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_log ("
                . "id " . $pk . ", at BIGINT NOT NULL DEFAULT 0, account_id BIGINT NULL,"
                . " scope_id BIGINT NULL, name VARCHAR(191) NOT NULL DEFAULT '',"
                . " action VARCHAR(64) NOT NULL DEFAULT '', detail " . $text . " NULL"
                . ")" . ($mysql ? " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" : ""));
            $pdo->exec("CREATE INDEX " . ($mysql ? "" : "IF NOT EXISTS ") . "idx_admin_log_at ON admin_log(at)");
        } catch (\Throwable $e) {
            // 建不出来也不影响主流程，仪表盘会少一块审计数据
        }
    }

    private static function log(PDO $pdo, string $action, string $detail, int $scopeId = 0): void {
        $me = Session::member($pdo);
        if ($me === null) return;
        try {
            $st = $pdo->prepare("INSERT INTO admin_log (at, account_id, scope_id, name, action, detail) VALUES (?, ?, ?, ?, ?, ?)");
            $st->execute([
                lg_now(),
                (int) ($me["account_id"] ?? 0) ?: null,
                $scopeId > 0 ? $scopeId : (int) $me["scope_id"],
                (string) $me["name"],
                $action,
                $detail,
            ]);
        } catch (\Throwable $e) {
        }
    }

    private static function actionLabel(string $action): string {
        $map = [
            "settings.save"   => "修改站点设置",
            "accounts.create" => "新增管理员",
            "accounts.update" => "修改管理员",
            "accounts.password" => "重置管理员密码",
            "accounts.delete" => "停用管理员",
            "oauth.discover"  => "OAuth 自动发现",
            "scope.create"    => "新建群",
            "scope.update"    => "修改群",
            "scope.invite"    => "修改邀请码",
            "scope.archive"   => "归档群",
            "member.ban"      => "封禁成员",
            "member.delete"   => "注销成员",
            "member.restore"  => "恢复成员",
            "themes.create"   => "新建主题包",
            "themes.delete"   => "删除主题包",
        ];
        return $map[$action] ?? $action;
    }

}