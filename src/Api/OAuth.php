<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Store\Db;
use PDO;
use RuntimeException;

/**
 * OAuth2 / OpenID Connect 登录。
 *
 * 两件事：
 * 1. 发现：按 issuer 拉 /.well-known/openid-configuration，把授权/token/userinfo
 *    端点和 scope 填回后台表单（手动填写和自动发现并存，自动只负责填，保存还是人来点）；
 * 2. 回调：/oauth/start 发起授权，/oauth/callback 用 code 换 token、读 userinfo、
 *    找到或建立站点账号，然后写网页会话。
 *
 * 后台「认证 → OAuth 登录」里展示的回调地址就是 callbackUrl()，
 * 必须原样填到第三方平台的重定向白名单（少一个字符都会 redirect_uri_mismatch）。
 */
final class OAuth {
    /** 回调路径：和 public/index.php 里的路由、后台展示的地址必须是同一个常量 */
    public const CALLBACK_PATH = "/oauth/callback";
    public const START_PATH    = "/oauth/start";
    /** 门禁登录之后挑业务账号的地方（一个主账号可以有好几个群身份） */
    public const ACCOUNTS_PATH = "/oauth/accounts";

    /** OpenID 标准的发现文档位置 */
    private const WELL_KNOWN = "/.well-known/openid-configuration";

    /** state 有效期（秒）：授权页停留太久就重来一遍 */
    private const STATE_TTL = 600;

    // ---------------------------------------------------------------- 地址

    /**
     * 回调地址：优先用后台填的「站点对外地址」，没填就按当前请求的域名推断。
     * 反向代理后面走 X-Forwarded-Proto，免得 https 站点返回 http 回调。
     */
    public static function callbackUrl(PDO $pdo): string {
        $base = trim(self::setting($pdo, "site.url", ""));
        if ($base === "") $base = self::origin();
        return rtrim($base, "/") . self::CALLBACK_PATH;
    }

    /** 当前请求的站点根地址（不带结尾斜杠） */
    public static function origin(): string {
        $https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
            || (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https");
        $host = (string) ($_SERVER["HTTP_HOST"] ?? "localhost");
        return ($https ? "https" : "http") . "://" . $host;
    }

    // ---------------------------------------------------------------- 发现

    /**
     * 拉 well-known 文档。返回结构化结果，不抛异常，让后台能原样展示错误。
     *
     * @return array{ok:bool,err:string,issuer:string,well_known:string,data:array,warnings:array}
     */
    public static function discover(string $issuer): array {
        $issuer = trim($issuer);
        $bad = function (string $why, string $wk = "") use ($issuer): array {
            return ["ok" => false, "err" => $why, "issuer" => $issuer, "well_known" => $wk, "data" => [], "warnings" => []];
        };
        if ($issuer === "") {
            return $bad("请先填写 Issuer 地址，例如 https://accounts.google.com");
        }
        $p = parse_url($issuer);
        if ($p === false || empty($p["scheme"]) || empty($p["host"])) {
            return $bad("Issuer 不是有效的网址，要带 http:// 或 https://");
        }
        $scheme = strtolower((string) $p["scheme"]);
        if ($scheme !== "http" && $scheme !== "https") {
            return $bad("Issuer 只支持 http / https 地址");
        }
        if (isset($p["query"]) || isset($p["fragment"])) {
            return $bad("Issuer 不能带 ? 查询参数或 # 片段，填提供方站点根地址即可");
        }
        if (self::blockedHost((string) $p["host"])) {
            return $bad("不允许请求本机或内网地址（防止被拿来探测内网）");
        }

        $issuer = rtrim($issuer, "/");
        $wk = $issuer . self::WELL_KNOWN;
        [$code, $body, $err] = self::http($wk);
        if ($err !== "") {
            return $bad("拉取 " . $wk . " 失败：" . $err . "（确认服务器能访问外网）", $wk);
        }
        if ($code !== 200) {
            return $bad("拉取 " . $wk . " 返回 HTTP " . ($code > 0 ? (string) $code : "?") . "，请确认 Issuer 填的是提供方站点根地址", $wk);
        }
        $j = json_decode($body, true);
        if (!is_array($j)) {
            return $bad($wk . " 返回的不是 JSON，这个地址可能不是 OpenID 提供方", $wk);
        }
        $auth  = trim((string) ($j["authorization_endpoint"] ?? ""));
        $token = trim((string) ($j["token_endpoint"] ?? ""));
        if ($auth === "" || $token === "") {
            return $bad("well-known 文档里缺少 authorization_endpoint 或 token_endpoint，这个地址可能不是 OIDC 提供方", $wk);
        }
        $userinfo = trim((string) ($j["userinfo_endpoint"] ?? ""));
        $warnings = [];
        if ($userinfo === "") {
            $warnings[] = "文档里没有 userinfo_endpoint：登录时只能从 id_token 里取身份";
        }
        $docIssuer = trim((string) ($j["issuer"] ?? ""));
        if ($docIssuer !== "" && rtrim($docIssuer, "/") !== $issuer) {
            $warnings[] = "文档里的 issuer 是 " . $docIssuer . "，和你填的不一致，回调时可能校验不过";
        }
        if (empty($j["scopes_supported"])) {
            $warnings[] = "文档里没有 scopes_supported，已默认填 openid profile email";
        }

        return [
            "ok"         => true,
            "err"        => "",
            "issuer"     => $issuer,
            "well_known" => $wk,
            "warnings"   => $warnings,
            "data"       => [
                "issuer"                 => $issuer,
                "provider"               => self::suggestProvider((string) $p["host"]),
                "authorization_endpoint" => $auth,
                "token_endpoint"         => $token,
                "userinfo_endpoint"      => $userinfo,
                "scope"                  => self::pickScopes($j["scopes_supported"] ?? []),
            ],
        ];
    }

    /** 从域名猜一个提供方标识：accounts.google.com → google */
    private static function suggestProvider(string $host): string {
        $host = strtolower($host);
        $parts = array_values(array_filter(explode(".", $host), fn($x) => $x !== ""));
        while (count($parts) > 1 && in_array($parts[0], ["accounts", "login", "auth", "id", "sso", "www", "openid"], true)) {
            array_shift($parts);
        }
        $name = $parts[0] ?? "oidc";
        $name = preg_replace("/[^a-z0-9_-]/", "", $name);
        return $name === "" ? "oidc" : substr($name, 0, 32);
    }

    /** 从 scopes_supported 里挑常用的一小把，别把整份清单塞进输入框 */
    private static function pickScopes($scopes): string {
        $list = [];
        if (is_array($scopes)) {
            foreach ($scopes as $s) {
                if (is_string($s) && trim($s) !== "" && !in_array(trim($s), $list, true)) $list[] = trim($s);
            }
        }
        if (!$list) return "openid profile email";
        $prefer = ["openid", "profile", "email"];
        $picked = [];
        foreach ($prefer as $s) {
            if (in_array($s, $list, true)) $picked[] = $s;
        }
        foreach ($list as $s) {
            if (count($picked) >= 6) break;
            if (!in_array($s, $picked, true)) $picked[] = $s;
        }
        return implode(" ", $picked);
    }

    // ---------------------------------------------------------------- 授权 / 回调

    public static function handle(PDO $pdo, string $path): void {
        lg_session();
        if ($path === self::START_PATH) {
            self::start($pdo);
            return;
        }
        if ($path === self::CALLBACK_PATH) {
            self::callback($pdo);
            return;
        }
        if ($path === self::ACCOUNTS_PATH) {
            self::accounts($pdo);
            return;
        }
        self::page(404, "找不到这个地址", "OAuth 只提供 " . self::START_PATH . "、" . self::CALLBACK_PATH . " 与 " . self::ACCOUNTS_PATH . " 三个入口。");
    }

    /** 发起授权：拼参数 + state + PKCE，然后 302 到提供方 */
    private static function start(PDO $pdo): void {
        if (self::setting($pdo, "oauth.enabled", "0") !== "1") {
            self::page(403, "OAuth 登录没有开启", "请管理员在后台「认证 → OAuth 登录」里打开开关并保存配置。");
            return;
        }
        $authUrl  = trim(self::setting($pdo, "oauth.authorize_url", ""));
        $clientId = trim(self::setting($pdo, "oauth.client_id", ""));
        $scope    = trim(self::setting($pdo, "oauth.scope", "openid profile email"));
        if ($clientId === "") {
            self::page(400, "OAuth 还没有配好", "缺少 Client ID，去后台「认证 → OAuth 登录」填写后再试。");
            return;
        }
        if ($authUrl === "") {
            // 只填了 issuer 也行：现场拉一次 well-known 找授权地址
            $issuer = trim(self::setting($pdo, "oauth.issuer", ""));
            if ($issuer === "") {
                self::page(400, "OAuth 还没有配好", "授权地址和 Issuer 都是空的，去后台填写或用 well-known 自动发现。");
                return;
            }
            $d = self::discover($issuer);
            if (!$d["ok"]) {
                self::page(502, "OAuth 发现失败", (string) $d["err"]);
                return;
            }
            $authUrl = (string) $d["data"]["authorization_endpoint"];
        }

        $verifier  = self::randToken(48);
        $challenge = rtrim(strtr(base64_encode(hash("sha256", $verifier, true)), "+/", "-_"), "=");
        $state     = self::randToken(24);
        $_SESSION["lg_oauth"] = [
            "state"     => $state,
            "verifier"  => $verifier,
            "at"        => time(),
            "provider"  => trim(self::setting($pdo, "oauth.provider", "oidc")),
        ];

        $q = http_build_query([
            "response_type"         => "code",
            "client_id"             => $clientId,
            "redirect_uri"          => self::callbackUrl($pdo),
            "scope"                 => $scope === "" ? "openid profile email" : $scope,
            "state"                 => $state,
            "code_challenge"        => $challenge,
            "code_challenge_method" => "S256",
        ], "", "&", PHP_QUERY_RFC3986);

        if (!headers_sent()) header("Location: " . $authUrl . (str_contains($authUrl, "?") ? "&" : "?") . $q, true, 302);
        exit;
    }

    /** 第三方带着 code 回来：换 token → 查身份 → 写会话 */
    private static function callback(PDO $pdo): void {
        if (!empty($_GET["error"])) {
            $why = trim((string) ($_GET["error_description"] ?? $_GET["error"]));
            self::page(400, "授权被拒绝或失败", $why !== "" ? $why : "第三方没有返回 code，请重新登录。");
            return;
        }
        $code  = trim((string) ($_GET["code"] ?? ""));
        $state = trim((string) ($_GET["state"] ?? ""));
        $sess  = $_SESSION["lg_oauth"] ?? null;
        unset($_SESSION["lg_oauth"]);
        if (!is_array($sess)) {
            self::page(400, "登录会话已失效", "请重新从登录入口发起 OAuth（可能是换了浏览器或清了 cookie）。");
            return;
        }
        if ($state === "" || !hash_equals((string) $sess["state"], $state)) {
            self::page(400, "state 校验失败", "授权回调的 state 和发起时对不上，为了安全已拒绝这次登录，请重试。");
            return;
        }
        if (time() - (int) $sess["at"] > self::STATE_TTL) {
            self::page(400, "授权超时", "在授权页停留超过 " . (int) (self::STATE_TTL / 60) . " 分钟，请重新发起登录。");
            return;
        }
        if ($code === "") {
            self::page(400, "没有拿到授权码", "第三方回调里没有 code 参数。");
            return;
        }

        $provider  = (string) ($sess["provider"] ?? "oidc");
        $tokenUrl  = trim(self::setting($pdo, "oauth.token_url", ""));
        $clientId  = trim(self::setting($pdo, "oauth.client_id", ""));
        $secret    = (string) self::setting($pdo, "oauth.client_secret", "");
        if ($tokenUrl === "" || $clientId === "") {
            self::page(500, "OAuth 配置不完整", "缺少 token 地址或 Client ID，请管理员到后台补全。");
            return;
        }

        [$code0, $body, $err] = self::http($tokenUrl, [
            "grant_type"    => "authorization_code",
            "code"          => $code,
            "redirect_uri"  => self::callbackUrl($pdo),
            "client_id"     => $clientId,
            "client_secret" => $secret,
            "code_verifier" => (string) $sess["verifier"],
        ]);
        if ($err !== "") {
            self::page(502, "换取 token 失败", $err);
            return;
        }
        $tok = json_decode($body, true);
        if ($code0 < 200 || $code0 >= 300 || !is_array($tok)) {
            $why = is_array($tok) ? (string) ($tok["error_description"] ?? $tok["error"] ?? "") : substr(trim($body), 0, 300);
            self::page(502, "换取 token 失败", "HTTP " . ($code0 > 0 ? (string) $code0 : "?") . ($why !== "" ? "：" . $why : ""));
            return;
        }
        $access = (string) ($tok["access_token"] ?? "");
        $idToken = (string) ($tok["id_token"] ?? "");

        $identity = null;
        $userinfoUrl = trim(self::setting($pdo, "oauth.userinfo_url", ""));
        if ($access !== "" && $userinfoUrl !== "") {
            [$c1, $b1, $e1] = self::http($userinfoUrl, null, ["Authorization: Bearer " . $access]);
            if ($e1 === "" && $c1 >= 200 && $c1 < 300) {
                $u = json_decode($b1, true);
                if (is_array($u)) $identity = $u;
            }
        }
        if ($identity === null && $idToken !== "") {
            $claims = self::jwtPayload($idToken);
            if ($claims !== null) $identity = $claims;
        }
        if ($identity === null) {
            self::page(502, "拿不到用户身份", "userinfo 接口没有返回可用的 JSON，且 id_token 也解不出来。检查 userinfo 地址或 scope。");
            return;
        }

        $sub = trim((string) ($identity["sub"] ?? $identity["id"] ?? ""));
        if ($sub === "") {
            self::page(502, "用户身份里没有 sub", "第三方返回的身份缺少 sub（唯一标识），无法建立账号绑定。");
            return;
        }
        $email   = trim((string) ($identity["email"] ?? ""));
        $display = trim((string) ($identity["preferred_username"] ?? $identity["name"] ?? $identity["display"] ?? ""));
        if ($display === "" && $email !== "") $display = (string) strstr($email, "@", true);

        try {
            $account = self::resolveAccount($pdo, $provider, $sub, $email, $display);
        } catch (RuntimeException $ex) {
            self::page(403, "登录失败", $ex->getMessage());
            return;
        }
        if ((int) $account["banned"] === 1) {
            self::page(403, "这个主账号已被封禁", "请联系站点管理员。");
            return;
        }

        // 门禁是两层的：这一步只认主账号，业务账号到 /oauth/accounts 挑或建
        Session::loginAccount($pdo, $account);
        if (!headers_sent()) header("Location: " . self::ACCOUNTS_PATH, true, 302);
        exit;
    }

    /**
     * 找到（或第一次建）本次 OAuth 身份对应的主账号 accounts。
     * 这一层只是「登录身份」，群里说话的业务账号由用户自己挑或新建，见 accounts()。
     */
    private static function resolveAccount(PDO $pdo, string $provider, string $sub, string $email, string $display): array {
        // provider 有历史大小写不一致的数据（安装时填 CCHSSO、后来改成 cchsso），
        // 按不区分大小写查，否则同一个人再登录会被当成新身份另建一个主账号。
        $st = $pdo->prepare("SELECT * FROM oauth_identities WHERE LOWER(provider) = LOWER(?) AND sub = ? LIMIT 1");
        $st->execute([$provider, $sub]);
        $ident = $st->fetch();
        if ($ident) {
            // 顺手把邮箱/昵称更新成最新值
            if ($email !== "" || $display !== "") {
                $pdo->prepare("UPDATE oauth_identities SET email = ?, display = ? WHERE id = ?")
                    ->execute([$email !== "" ? $email : (string) $ident["email"], $display !== "" ? $display : (string) $ident["display"], (int) $ident["id"]]);
            }
            $st = $pdo->prepare("SELECT * FROM accounts WHERE oauth_id = ? AND deleted_at IS NULL ORDER BY id ASC LIMIT 1");
            $st->execute([(int) $ident["id"]]);
            $acct = $st->fetch();
            if ($acct) return $acct;
            throw new RuntimeException("这个第三方身份绑定的站点账号已被注销，请联系管理员恢复。");
        }

        // 新的 OAuth 身份：只开主账号，业务账号让用户到 /oauth/accounts 建
        $ts = lg_now();
        $pdo->prepare("INSERT INTO oauth_identities (provider, sub, email, display, created_at) VALUES (?, ?, ?, ?, ?)")
            ->execute([$provider, $sub, $email, $display, $ts]);
        $identId = (int) $pdo->lastInsertId();

        $accountName = self::uniqueName($pdo, "accounts", "name", $display !== "" ? $display : "user" . $identId);
        $pdo->prepare("INSERT INTO accounts (name, oauth_id, pass_hash, role, scope_id, banned, created_at) VALUES (?, ?, NULL, 'member', NULL, 0, ?)")
            ->execute([$accountName, $identId, $ts]);

        $st = $pdo->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
        $st->execute([(int) $pdo->lastInsertId()]);
        $acct = $st->fetch();
        if (!$acct) throw new RuntimeException("建主账号失败，请再试一次。");
        return $acct;
    }

    // ---------------------------------------------------------------- 业务账号

    /**
     * 门禁的落点：主账号进来之后，在它名下的业务账号（群身份）里挑一个，
     * 没有就建一个。配额 quota.account_per_oauth 就是这一步的上限。
     */
    private static function accounts(PDO $pdo): void {
        $acct = Session::account($pdo);
        if ($acct === null) {
            if (!headers_sent()) header("Location: /login", true, 302);
            exit;
        }
        $isPost = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET")) === "POST";
        $err    = "";
        $choose = isset($_GET["choose"]);

        if ($isPost) {
            if (!self::csrfOk()) {
                $err = "页面过期了，请刷新重试。";
            } else {
                $act = (string) ($_POST["act"] ?? "");
                try {
                    if ($act === "pick") {
                        $m = self::ownedMember($pdo, (int) $acct["id"], (int) ($_POST["mid"] ?? 0));
                        if ((int) $m["banned"] === 1) throw new RuntimeException("这个业务账号已被封禁，换一个或联系管理员。");
                        $pdo->prepare("UPDATE members SET last_seen_at = ? WHERE id = ?")->execute([lg_now(), (int) $m["id"]]);
                        Session::login($pdo, $m);
                        self::land($pdo, $acct, $m);
                        return;
                    }
                    if ($act === "create") {
                        $m = self::createMember($pdo, $acct, (string) ($_POST["name"] ?? ""), (string) ($_POST["invite"] ?? ""));
                        Session::login($pdo, $m);
                        self::land($pdo, $acct, $m);
                        return;
                    }
                    $err = "不认识的操作。";
                } catch (RuntimeException $ex) {
                    $err = $ex->getMessage();
                }
            }
        }

        $list  = self::accountMembers($pdo, (int) $acct["id"]);
        $limit = (int) self::setting($pdo, "quota.account_per_oauth", "0");
        $used  = count(array_filter($list, static fn(array $m): bool => (int) $m["deleted_at"] === 0 && (int) $m["banned"] === 0));

        // 只有一个能用、也没要求换，就直接进，别多一步
        if (!$isPost && !$choose && $err === "") {
            $alive = array_values(array_filter($list, static fn(array $m): bool => (int) $m["deleted_at"] === 0));
            if (count($alive) === 1 && (int) $alive[0]["banned"] === 0) {
                $pdo->prepare("UPDATE members SET last_seen_at = ? WHERE id = ?")->execute([lg_now(), (int) $alive[0]["id"]]);
                Session::login($pdo, $alive[0]);
                self::land($pdo, $acct, $alive[0]);
                return;
            }
        }

        self::renderAccounts($acct, $list, $used, $limit, $err, $choose);
    }

    /** 进站落地：管理员去后台，其他人进群聊 */
    private static function land(PDO $pdo, array $acct, array $member): void {
        $role = (string) ($acct["role"] ?? "member");
        $to   = ($role === "super" || $role === "admin") ? "/admin" : "/app";
        if (!headers_sent()) header("Location: " . $to, true, 302);
        exit;
    }

    /** 主账号名下的业务账号（含已注销/已封禁，页面上要标出来） */
    private static function accountMembers(PDO $pdo, int $accountId): array {
        $st = $pdo->prepare(
            "SELECT m.*, s.title AS scope_title, s.slug AS scope_slug FROM members m"
            . " LEFT JOIN scopes s ON s.id = m.scope_id"
            . " WHERE m.account_id = ? ORDER BY m.deleted_at IS NOT NULL, m.id ASC"
        );
        $st->execute([$accountId]);
        return $st->fetchAll();
    }

    private static function ownedMember(PDO $pdo, int $accountId, int $mid): array {
        $st = $pdo->prepare("SELECT * FROM members WHERE id = ? AND account_id = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$mid, $accountId]);
        $m = $st->fetch();
        if (!$m) throw new RuntimeException("这个业务账号不属于当前主账号。");
        return $m;
    }

    /**
     * 新建业务账号：先过群邀请码，再过站点/群人数配额，最后是「每个主账号能建几个」。
     * 邀请码决定进哪个群 —— 门禁下这是唯一的分群入口，不校验就等于所有主账号都能
     * 自己造号进主群，几个群完全隔不开。
     */
    private static function createMember(PDO $pdo, array $acct, string $name, string $invite): array {
        $name = trim(preg_replace('/\s+/u', " ", $name) ?? "");
        $invite = trim($invite);
        if ($name === "") throw new RuntimeException("先给这个业务账号起个名字。");
        if (mb_strlen($name) > 24) throw new RuntimeException("名字最多 24 个字。");
        if ($invite === "") throw new RuntimeException("要填群邀请码才能加入，邀请码由那个群的管理员给你。");

        $limit = (int) self::setting($pdo, "quota.account_per_oauth", "0");
        if ($limit > 0) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM members WHERE account_id = ? AND deleted_at IS NULL");
            $st->execute([(int) $acct["id"]]);
            if ((int) $st->fetchColumn() >= $limit) {
                throw new RuntimeException("一个主账号最多建 " . $limit . " 个业务账号，先把不用的注销掉。");
            }
        }

        $st = $pdo->prepare("SELECT * FROM scopes WHERE invite = ? AND archived = 0 LIMIT 1");
        $st->execute([$invite]);
        $scope = $st->fetch();
        if (!$scope) throw new RuntimeException("邀请码不对，或者那个群已经关了。");
        self::assertQuota($pdo, $scope);

        $ts = lg_now();
        try {
            $pdo->prepare("INSERT INTO members (scope_id, name, kind, account_id, oauth_id, created_at, last_seen_at) VALUES (?, ?, 'human', ?, ?, ?, ?)")
                ->execute([(int) $scope["id"], $name, (int) $acct["id"], (int) ($acct["oauth_id"] ?? 0) ?: null, $ts, $ts]);
        } catch (\PDOException $ex) {
            throw new RuntimeException("「" . $name . "」这个名字在这个群里已经有了，换一个。");
        }
        $st = $pdo->prepare("SELECT * FROM members WHERE id = ? LIMIT 1");
        $st->execute([(int) $pdo->lastInsertId()]);
        $m = $st->fetch();
        if (!$m) throw new RuntimeException("建群身份失败，请再试一次。");
        return $m;
    }

    private static function csrf(): string {
        if (empty($_SESSION["lg_csrf"])) $_SESSION["lg_csrf"] = self::randToken(24);
        return (string) $_SESSION["lg_csrf"];
    }

    private static function csrfOk(): bool {
        $got = (string) ($_POST["csrf"] ?? "");
        $want = (string) ($_SESSION["lg_csrf"] ?? "");
        return $want !== "" && $got !== "" && hash_equals($want, $got);
    }

    /** 挑/建业务账号的页面：不依赖静态资源，样式和 page() 一致 */
    private static function renderAccounts(array $acct, array $list, int $used, int $limit, string $err, bool $choose): void {
        if (!headers_sent()) header("Content-Type: text/html; charset=utf-8");
        $h = static fn(string $s): string => e($s);
        $alive = array_values(array_filter($list, static fn(array $m): bool => (int) $m["deleted_at"] === 0));
        $full  = $limit > 0 && $used >= $limit;

        $rows = "";
        foreach ($alive as $m) {
            $banned = (int) $m["banned"] === 1;
            $group  = (string) ($m["scope_title"] ?? "");
            $rows  .= '<form class="row" method="post" action="' . $h(self::ACCOUNTS_PATH) . '">'
                . '<input type="hidden" name="csrf" value="' . $h(self::csrf()) . '">'
                . '<input type="hidden" name="act" value="pick">'
                . '<input type="hidden" name="mid" value="' . (int) $m["id"] . '">'
                . '<div class="who"><b>' . $h((string) $m["name"]) . '</b>'
                . '<span>' . $h($group !== "" ? "群：" . $group : "群身份") . ($banned ? ' · 已封禁' : '') . '</span></div>'
                . '<button type="submit"' . ($banned ? ' disabled' : '') . '>' . ($banned ? '不能用' : '用这个身份进群') . '</button>'
                . '</form>';
        }
        if ($rows === "") $rows = '<p class="empty">这个主账号名下还没有业务账号，先建一个。</p>';

        $create = $full
            ? '<p class="empty">一个主账号最多建 ' . (int) $limit . ' 个业务账号，先注销不用的再来。</p>'
            : '<form class="row" method="post" action="' . $h(self::ACCOUNTS_PATH) . '">'
                . '<input type="hidden" name="csrf" value="' . $h(self::csrf()) . '">'
                . '<input type="hidden" name="act" value="create">'
                . '<div class="who"><input name="name" maxlength="24" placeholder="新业务账号的名字（群里显示这个）" autocomplete="off">'
                . '<input name="invite" maxlength="32" placeholder="群邀请码" autocomplete="off"></div>'
                . '<button type="submit">新建并进群</button></form>';

        $errHtml = $err !== "" ? '<p class="err">' . $h($err) . '</p>' : "";
        $quota   = $limit > 0
            ? '已用 ' . $used . ' / ' . $limit . ' 个业务账号'
            : '业务账号数量不限';

        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>选择业务账号</title>'
            . '<link rel="icon" href="/favicon.ico" sizes="any"><style>'
            . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f7fa;color:#1b2330;'
            . 'font:15px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;padding:24px}'
            . '.box{width:100%;max-width:560px;background:#fff;border:1px solid #e5e9f0;border-radius:16px;padding:26px 24px;'
            . 'box-shadow:0 18px 50px -24px rgba(16,24,40,.25)}'
            . 'h1{margin:0 0 4px;font-size:19px}.sub{margin:0 0 16px;color:#5b6472;font-size:13px}'
            . '.row{display:flex;align-items:center;gap:10px;margin:0 0 10px;padding:10px;border:1px solid #e5e9f0;border-radius:12px}'
            . '.who{flex:1;min-width:0}.who b{display:block;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            . '.who span{display:block;color:#8a93a3;font-size:12px}'
            . '.who input{width:100%;box-sizing:border-box;border:1px solid #dfe4ec;border-radius:9px;padding:8px 10px;font:inherit;font-size:14px}'
            . 'button{border:0;background:#2563eb;color:#fff;padding:8px 14px;border-radius:9px;font:inherit;font-size:13px;cursor:pointer}'
            . 'button[disabled]{background:#c3cad6;cursor:not-allowed}'
            . '.err{margin:0 0 14px;padding:9px 12px;border-radius:9px;background:#fdecec;color:#b42318;font-size:13px}'
            . '.empty{margin:0 0 10px;color:#8a93a3;font-size:13px}'
            . '.foot{display:flex;justify-content:space-between;align-items:center;margin-top:16px;font-size:12px;color:#8a93a3}'
            . '.foot a{color:#2563eb;text-decoration:none}'
            . '</style></head><body><div class="box">'
            . '<h1>' . ($choose ? '换个业务账号' : '选择一个业务账号') . '</h1>'
            . '<p class="sub">主账号 ' . $h((string) $acct["name"]) . ' · ' . $h($quota) . '</p>'
            . $errHtml . $rows . '<p class="sub" style="margin:14px 0 6px">新建一个</p>' . $create
            . '<div class="foot"><span>登录用的是第三方身份，群里说话的是业务账号</span><a href="/logout">退出</a></div>'
            . '</div></body></html>';
    }

    /** 默认群：优先 slug 为空的主群，其次最早建的未归档群 */
    private static function defaultScope(PDO $pdo): ?array {
        $row = $pdo->query("SELECT * FROM scopes WHERE archived = 0 ORDER BY CASE WHEN slug = '' THEN 0 ELSE 1 END, id ASC LIMIT 1")->fetch();
        return $row ?: null;
    }

    /** 昵称/账号名去重：name、name2、name3… */
    private static function uniqueName(PDO $pdo, string $table, string $col, string $base, int $scopeId = 0): string {
        $base = trim(preg_replace('/\s+/u', " ", $base) ?? "");
        if ($base === "") $base = "user";
        if (mb_strlen($base) > 20) $base = mb_substr($base, 0, 20);
        $sql = "SELECT 1 FROM " . $table . " WHERE " . $col . " = ?";
        if ($scopeId > 0) $sql .= " AND scope_id = ?";
        $sql .= " LIMIT 1";
        for ($i = 1; $i <= 50; $i++) {
            $cand = $i === 1 ? $base : $base . $i;
            $st = $pdo->prepare($sql);
            $st->execute($scopeId > 0 ? [$cand, $scopeId] : [$cand]);
            if (!$st->fetchColumn()) return $cand;
        }
        return $base . "-" . bin2hex(random_bytes(3));
    }

    /** 和 Login::assertQuota 同一套规则：两个 0 都不限，只看 human 且未注销 */
    private static function assertQuota(PDO $pdo, array $scope): void {
        $total = (int) self::setting($pdo, "quota.human_total", "0");
        $cap   = (int) $scope["human_cap"];
        if ($total === 0 && $cap === 0) return;
        $all = (int) $pdo->query("SELECT COUNT(*) FROM members WHERE kind = 'human' AND deleted_at IS NULL")->fetchColumn();
        if ($total > 0 && $all >= $total) throw new RuntimeException("站点人数已满，OAuth 暂时不能自动建号。");
        if ($cap > 0) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM members WHERE scope_id = ? AND kind = 'human' AND deleted_at IS NULL");
            $st->execute([(int) $scope["id"]]);
            if ((int) $st->fetchColumn() >= $cap) throw new RuntimeException("这个群的人已满，OAuth 暂时不能自动进群。");
        }
    }

    // ---------------------------------------------------------------- HTTP

    /**
     * 极简 HTTP 客户端：优先 curl，没有就退回 stream。
     * @return array{0:int,1:string,2:string} [HTTP 码, 响应体, 错误]
     */
    private static function http(string $url, ?array $form = null, array $headers = []): array {
        $head = array_merge(["Accept: application/json", "User-Agent: LightGroup OAuth"], $headers);
        if ($form !== null) $head[] = "Content-Type: application/x-www-form-urlencoded";

        if (function_exists("curl_init")) {
            $ch = curl_init($url);
            $opt = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_HTTPHEADER     => $head,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            if ($form !== null) {
                $opt[CURLOPT_POST] = true;
                $opt[CURLOPT_POSTFIELDS] = http_build_query($form, "", "&");
            }
            @curl_setopt_array($ch, $opt);
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($body === false) return [0, "", $err !== "" ? $err : "网络请求失败"];
            return [$code, (string) $body, ""];
        }

        $ctx = stream_context_create(["http" => [
            "method"          => $form === null ? "GET" : "POST",
            "header"          => implode("\r\n", $head),
            "content"         => $form === null ? "" : http_build_query($form, "", "&"),
            "timeout"         => 12,
            "ignore_errors"   => true,
            "follow_location" => 1,
            "max_redirects"   => 3,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $code = (int) $m[1];
        }
        if ($body === false) return [0, "", "网络请求失败（没装 curl 扩展，或者目标不可达）"];
        return [$code, (string) $body, ""];
    }

    /** id_token 是 JWT：这里只取 payload，不验签（token 是刚从 token 端点拿到的） */
    private static function jwtPayload(string $jwt): ?array {
        $parts = explode(".", $jwt);
        if (count($parts) < 2) return null;
        $p = strtr($parts[1], "-_", "+/");
        $p .= str_repeat("=", (4 - strlen($p) % 4) % 4);
        $raw = base64_decode($p, true);
        if ($raw === false) return null;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    // ---------------------------------------------------------------- 小工具

    private static function randToken(int $bytes): string {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), "+/", "-_"), "=");
    }

    private static function setting(PDO $pdo, string $k, string $d = ""): string {
        $st = $pdo->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
        $st->execute([$k]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) return (string) $v;
        // 老版本安装器把 OAuth 参数写成 oauth.provider.client_id 这种前缀，
        // 这里回退读一次，免得升级上来之后门禁配置看着是空的
        if (str_starts_with($k, "oauth.") && !in_array($k, ["oauth.enabled", "oauth.ban_sync"], true)) {
            $st->execute(["oauth.provider." . substr($k, 6)]);
            $old = $st->fetchColumn();
            if ($old !== false && $old !== null) return (string) $old;
        }
        return $d;
    }

    /** 本机 / 内网地址不允许请求，免得 well-known 发现被拿来扫内网 */
    private static function blockedHost(string $host): bool {
        $host = strtolower(trim($host, "[]"));
        if ($host === "localhost" || str_ends_with($host, ".localhost")) return true;
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** 失败/提示页：不依赖任何静态资源，后台挂了也能看清原因 */
    private static function page(int $status, string $title, string $detail): void {
        http_response_code($status);
        header("Content-Type: text/html; charset=utf-8");
        $t = e($title);
        $d = e($detail);
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $t . '</title>'
            . '<link rel="icon" href="/favicon.ico" sizes="any"><style>'
            . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f7fa;color:#1b2330;'
            . 'font:15px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;padding:24px}'
            . '.box{max-width:520px;background:#fff;border:1px solid #e5e9f0;border-radius:16px;padding:28px 26px;'
            . 'box-shadow:0 18px 50px -24px rgba(16,24,40,.25)}'
            . 'h1{margin:0 0 10px;font-size:19px}p{margin:0 0 18px;color:#5b6472;white-space:pre-wrap;word-break:break-word}'
            . 'a{display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:9px 16px;border-radius:9px;font-size:14px}'
            . '</style></head><body><div class="box"><h1>' . $t . '</h1><p>' . $d . '</p>'
            . '<a href="/login">返回登录</a></div></body></html>';
    }
}
