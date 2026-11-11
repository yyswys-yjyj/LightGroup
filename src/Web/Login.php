<?php
declare(strict_types=1);

namespace LightGroup\Web;

use LightGroup\Api\Account;
use LightGroup\Api\Captcha;
use LightGroup\Api\Input;
use LightGroup\Api\Session;
use LightGroup\Api\Throttle;
use LightGroup\Store\Db;
use PDO;
use PDOException;
use RuntimeException;

/**
 * 网页登录（GET /login 与 POST /login）。
 *
 * 两条路：
 *  - 昵称 + 邀请码 + 密码：邀请码认群（scopes.invite），昵称认身份，密码认人。
 *    第一次来等于注册（自己设密码，受人数配额约束）；老成员凭密码回来。
 *  - 账号 + 密码：accounts 里的管理员身份，语义和 /g/api/login 一致，登完进 /admin。
 *
 * 站内开了内建人机验证（auth.mode=captcha）时，成员账密认过之后还要过一道滑块；
 * 失败次数按 IP 与昵称各记一份，超了先锁一会儿（src/Api/Throttle.php）。
 *
 * 登录态只写 cookie session（src/Api/Session.php），token 只留给插件和 AI：
 * token 是 AI 的密码，不是会话凭据。
 */
final class Login {
    /** 没给 next 时，普通成员登录后去哪 */
    private const HOME = "/app";
    /** 没给 next 时，管理员登录后去哪 */
    private const ADMIN_HOME = "/admin";
    /** 密码最短几位 */
    private const PASS_MIN = 6;

    public static function handle(array $site): void {
        lg_session();
        $pdo    = Db::connect();
        $mode   = self::mode();
        $next   = self::safeNext(self::field("next"));
        $isPost = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET")) === "POST";

        if (!$isPost) {
            // 已经登录了就别再让填一次。switch=1 是例外：在别的群的地址上被拦下来的
            // 人得能用这个群的邀请码换个身份，不然只能先去退出。
            if (Session::member($pdo) !== null && self::field("switch") !== "1") self::redirect(self::target($next, $mode));
            // 门禁登录到一半：第三方已经认过主账号，还差挑业务账号
            if ($mode !== "admin" && Session::account($pdo) !== null) self::redirect("/oauth/accounts");
            self::render($site, $pdo, $mode, $next, "", "");
            return;
        }

        // 注销相关的请求也走 /login：public/index.php 里只有这一个入口是我们能接的，
        // 加新路由要改那里，不归我管（见报告里给 Compat 的接法）。
        // 必须排在门禁之前：注销用的是已经登录的网页会话，不是登录凭据，门禁拦它没道理。
        $action = self::field("action");
        if (str_starts_with($action, "account.")) {
            Account::handle($pdo, $action);
            return;
        }

        // 开了 OAuth 门禁，账密这条路就关掉，管理员也走同一个第三方入口。
        // 只有第三方没配好时才放行（否则 IdP 一挂连后台都进不去，配置也没法修）。
        if (self::gateOn($pdo) && self::oauthReady($pdo)) {
            self::render($site, $pdo, $mode, $next, "本站开启了 OAuth 门禁，请用第三方账号登录", "");
            return;
        }

        $name = self::field("name");
        // 键 => 允许的失败次数。IP 松一点（一个出口后面可能是很多人），昵称紧一点
        $keys = ["ip:" . self::ip() => 10];
        if ($name !== "") $keys[($mode === "admin" ? "admin:" : "member:") . $name] = 5;

        // 先看是不是被节流了，省得再去验密码
        foreach ($keys as $k => $limit) {
            $wait = Throttle::wait($pdo, $k, $limit);
            if ($wait !== null) {
                self::render($site, $pdo, $mode, $next, "试了太多次，请 " . $wait . " 秒后再来", $name);
                return;
            }
        }

        try {
            $member = $mode === "admin"
                ? self::adminLogin($pdo, $name, self::password())
                : self::memberLogin($pdo, $name, self::field("invite"), self::password());

            // 账密认过之后再要求人机验证（内建滑块或第三方 widget 都走 Captcha::check）。
            // 管理员后台是整站最值钱的入口，同样要过这一道，不因为 mode=admin 就放过。
            if (Captcha::enabled($pdo)) {
                $why = Captcha::check($pdo, self::body());
                if ($why !== "") throw new RuntimeException($why);
            }
        } catch (RuntimeException $ex) {
            // 失败留在登录页，别把人甩回空白页
            foreach (array_keys($keys) as $k) Throttle::fail($pdo, $k);
            self::render($site, $pdo, $mode, $next, $ex->getMessage(), $name);
            return;
        }
        foreach (array_keys($keys) as $k) Throttle::clear($pdo, $k);
        Session::login($pdo, $member);
        self::redirect(self::target($next, $mode));
    }

    // ---------- 昵称 + 邀请码 + 密码 ----------

    /**
     * 昵称 + 邀请码 + 密码。第一次来等于注册（自己设密码），老成员凭密码回来；
     * 老库里没有密码的成员第一次进来顺手认领一次，之后就必须凭密码。
     */
    private static function memberLogin(PDO $pdo, string $name, string $invite, string $pass): array {
        if ($name === "") throw new RuntimeException("昵称不能空着");
        if (mb_strlen($name) > 24) throw new RuntimeException("昵称最多 24 个字");
        if ($invite === "") throw new RuntimeException("邀请码不能空着");
        if ($pass === "") throw new RuntimeException("密码不能空着");
        if (mb_strlen($pass) < self::PASS_MIN) throw new RuntimeException("密码至少 " . self::PASS_MIN . " 位");

        $st = $pdo->prepare("SELECT * FROM scopes WHERE invite = ? AND archived = 0 LIMIT 1");
        $st->execute([$invite]);
        $scope = $st->fetch();
        if (!$scope) throw new RuntimeException("邀请码不对，找群主要一个");

        $st = $pdo->prepare("SELECT * FROM members WHERE scope_id = ? AND name = ? LIMIT 1");
        $st->execute([(int) $scope["id"], $name]);
        $m = $st->fetch();
        if ($m) return self::passwordBack($pdo, $m, $pass);

        if ($name === self::superName($pdo)) throw new RuntimeException("这个昵称已保留，换一个");
        self::assertQuota($pdo, $scope);

        $ts  = lg_now();
        $new = null;
        try {
            $ins = $pdo->prepare("INSERT INTO members (scope_id, name, kind, token_hash, pass_hash, created_at, last_seen_at) VALUES (?, ?, 'human', NULL, ?, ?, ?)");
            $ins->execute([(int) $scope["id"], $name, password_hash($pass, PASSWORD_DEFAULT), $ts, $ts]);
            $new = self::memberById($pdo, (int) $pdo->lastInsertId());
        } catch (PDOException $ex) {
            $new = null; // 并发下被别人抢先建了同名，下面再查一次
        }
        if ($new === null) {
            $st = $pdo->prepare("SELECT * FROM members WHERE scope_id = ? AND name = ? LIMIT 1");
            $st->execute([(int) $scope["id"], $name]);
            $m = $st->fetch();
            if ($m) return self::passwordBack($pdo, $m, $pass);
            throw new RuntimeException("进不去，请再试一次");
        }
        return $new;
    }

    /** 老成员回来：查状态、验密码（没密码就这次认领） */
    private static function passwordBack(PDO $pdo, array $m, string $pass): array {
        if (!empty($m["deleted_at"])) throw new RuntimeException("这个昵称已经注销了");
        if ((int) $m["banned"] === 1) throw new RuntimeException("这个昵称已被封禁");
        // AI 是给插件用的身份，token 就是它的密码，网页这边没有它的位置
        if ((string) $m["kind"] === "ai") throw new RuntimeException("AI 账号用 token 接插件，不能在这里登录");
        // 管理员身份只能走账号密码，别让任何人用一个昵称领走它
        if (!empty($m["account_id"])) throw new RuntimeException("这个昵称是管理员，请点下面的「管理员登录」");

        $hash = (string) ($m["pass_hash"] ?? "");
        if ($hash === "") {
            $pdo->prepare("UPDATE members SET pass_hash = ? WHERE id = ?")
                ->execute([password_hash($pass, PASSWORD_DEFAULT), (int) $m["id"]]);
        } elseif (!password_verify($pass, $hash)) {
            throw new RuntimeException("昵称或密码不对");
        }
        $now = lg_now();
        $pdo->prepare("UPDATE members SET last_seen_at = ? WHERE id = ?")->execute([$now, (int) $m["id"]]);
        $m["last_seen_at"] = $now;
        return $m;
    }

    // ---------- 账号 + 密码 ----------

    private static function adminLogin(PDO $pdo, string $name, string $pass): array {
        if ($name === "" || $pass === "") throw new RuntimeException("账号和密码都要填");
        $st = $pdo->prepare("SELECT * FROM accounts WHERE name = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$name]);
        $a = $st->fetch();
        if (!$a || empty($a["pass_hash"]) || !password_verify($pass, (string) $a["pass_hash"])) {
            throw new RuntimeException("账号或密码不对");
        }
        if ((int) $a["banned"] === 1) throw new RuntimeException("这个账号被停用了");
        $st = $pdo->prepare("SELECT * FROM members WHERE account_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1");
        $st->execute([(int) $a["id"]]);
        $m = $st->fetch();
        if (!$m) throw new RuntimeException("这个账号还没有群身份");
        if ((int) $m["banned"] === 1) throw new RuntimeException("这个账号已被封禁");
        return $m;
    }

    // ---------- 小工具 ----------

    /** 表单优先；前端 fetch 发 JSON 也能读 */
    private static function body(): array {
        if ($_POST !== []) return $_POST;
        $raw = Input::body();
        return is_array($raw) ? $raw : [];
    }

    private static function field(string $key): string {
        $b = self::body();
        if (isset($b[$key]) && is_scalar($b[$key])) return trim((string) $b[$key]);
        if (isset($_GET[$key]) && is_scalar($_GET[$key])) return trim((string) $_GET[$key]);
        return "";
    }

    /** 密码不 trim：前后空格也是密码的一部分 */
    private static function password(): string {
        $b = self::body();
        return isset($b["password"]) && is_scalar($b["password"]) ? (string) $b["password"] : "";
    }

    private static function ip(): string {
        $ip = (string) ($_SERVER["REMOTE_ADDR"] ?? "");
        return $ip !== "" ? $ip : "0.0.0.0";
    }

    private static function mode(): string {
        $m = self::field("mode");
        if ($m === "") $m = self::field("m");
        return $m === "admin" ? "admin" : "member";
    }

    private static function target(string $next, string $mode): string
    {
        // next 也跟着一起换算：没开 rewrite 的站点里 /app 是要 404 的
        if ($next !== "") return Url::to($next);
        return Url::to($mode === "admin" ? self::ADMIN_HOME : self::HOME);
    }

    /** next 只认站内相对路径：防开放重定向，也别自己绕自己 */
    private static function safeNext(string $raw): string {
        if ($raw === "" || strlen($raw) > 300) return "";
        if ($raw[0] !== "/" || str_starts_with($raw, "//")) return "";
        if (str_contains($raw, "\\") || str_contains($raw, "\r") || str_contains($raw, "\n")) return "";
        if (preg_match('#^/[a-z][a-z0-9+.-]*:#i', $raw)) return "";        // /javascript:…
        if (preg_match('~^/(?:login|logout)(?:[?#]|$)~', $raw)) return "";  // 别自己绕自己
        return $raw;
    }

    private static function memberById(PDO $pdo, int $id): ?array {
        $st = $pdo->prepare("SELECT * FROM members WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    private static function superName(PDO $pdo): string {
        $row = $pdo->query("SELECT name FROM accounts WHERE role = 'super' LIMIT 1")->fetch();
        return $row ? (string) $row["name"] : "";
    }

    /** 和 Compat::checkHumanQuota 同一套规则：两个 0 都不限，只看 human 且未注销 */
    private static function assertQuota(PDO $pdo, array $scope): void {
        $st = $pdo->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
        $st->execute(["quota.human_total"]);
        $row = $st->fetch();
        $total = (int) ($row ? $row["v"] : 0);
        $cap   = (int) $scope["human_cap"];
        if ($total === 0 && $cap === 0) return;
        $all = (int) $pdo->query("SELECT COUNT(*) FROM members WHERE kind = 'human' AND deleted_at IS NULL")->fetchColumn();
        if ($total > 0 && $all >= $total) throw new RuntimeException("人数已满");
        if ($cap > 0) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM members WHERE scope_id = ? AND kind = 'human' AND deleted_at IS NULL");
            $st->execute([(int) $scope["id"]]);
            if ((int) $st->fetchColumn() >= $cap) throw new RuntimeException("人数已满");
        }
    }

    private static function render(array $site, PDO $pdo, string $mode, string $next, string $err, string $name): void {
        View::render("login", [
            "site"      => $site,
            "mode"      => $mode,
            "next"      => $next,
            "err"       => $err,
            // 键名不能叫 name：View::render 里 extract($data, EXTR_SKIP) 会跳过
            // 与自身局部变量同名的键，而它的局部变量就是 $name（视图文件名），
            // 传 name 会被丢掉、视图里拿到的永远是 "login"。这是「登录页预填 login」的真凶。
            "who"       => $name,
            "authMode"  => Captcha::mode($pdo),
            // 管理员登录也过人工验证：后台是最值钱的入口，没理由比进群聊还松
            "captchaOn" => Captcha::enabled($pdo),
            // provider/site key/前端脚本，内建滑块时为 provider=builtin
            "captcha"   => Captcha::enabled($pdo) ? Captcha::clientConfig($pdo) : null,
            // 第三方登录入口：没开或没填 Client ID 就不显示，免得点进去是一张报错页
            "oauthOn"   => self::oauthReady($pdo),
            // 门禁开着：账密表单不渲染，只留第三方入口。
            // 管理员这里多一个前提——OAuth 没配好时不能把账密也收走，
            // 否则 IdP 一挂（或装完一直没配）连后台都进不去，配置都没法修。
            "gate"      => self::gateOn($pdo) && ($mode !== "admin" || self::oauthReady($pdo)),
        ]);
    }

    /**
     * 门禁是否开着：auth.mode=oauth 表示账号是两层（主账号 + 业务账号），
     * 登录只能走第三方，业务账号在 /oauth/accounts 里挑或建。
     */
    private static function gateOn(PDO $pdo): bool {
        return Captcha::mode($pdo) === "oauth";
    }

    /** 第三方登录能不能用：后台开了开关，并且填了 Client ID（授权地址可以靠 well-known 现场发现） */
    private static function oauthReady(PDO $pdo): bool {
        $st = $pdo->prepare("SELECT k, v FROM settings WHERE k IN ('oauth.enabled', 'oauth.client_id')");
        $st->execute();
        $v = [];
        foreach ($st->fetchAll() as $row) $v[(string) $row["k"]] = (string) $row["v"];
        return ($v["oauth.enabled"] ?? "0") === "1" && trim($v["oauth.client_id"] ?? "") !== "";
    }

    private static function redirect(string $to): void {
        if (!headers_sent()) header("Location: " . $to, true, 302);
        exit;
    }
}
