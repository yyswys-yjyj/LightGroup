<?php
declare(strict_types=1);

namespace LightGroup\Api;

use PDO;
use RuntimeException;

/**
 * 人机验证。
 *
 * 两种路子：
 *  - 内建滑块（默认）：挑战本身是自签名的，target 与过期时间用 .lock 里的 secret
 *    做 HMAC 签名，服务端不存任何状态。校验看签名、位置、耗时与轨迹点数。
 *  - 第三方（Turnstile / hCaptcha / reCAPTCHA）：登录页加载各家 widget，
 *    拿到的 token 随表单一起提交，服务端带 secret 去它们的 siteverify 换结果。
 *  - Geetest v4（行为验证）：客户端用 gt4.js 拿到 lot_number / captcha_output /
 *    pass_token / gen_time 四个值，前端拼成 JSON 塞进 cap3；服务端用 captcha_key
 *    对 lot_number 做 HMAC-SHA256 得到 sign_token，再去 gcaptcha4 换 result。
 *
 * auth.mode 仍然是 captcha，第三方只是 captcha 底下的 provider，
 * 这样 Compat / Admin 里判 enabled() 的地方一行都不用改。
 * 没填 Secret Key 的时候 provider 配了也退回内建滑块，登录页会说明原因。
 */
final class Captcha {
    /** 挑战有效期（毫秒） */
    public const TTL = 120000;
    /** 允许的横向误差（百分比） */
    public const TOL = 4;
    /** 人拖不可能比这更快（毫秒） */
    public const MIN_MS = 300;
    /** 轨迹至少要这么多个采样点 */
    public const MIN_MOVES = 5;

    /** 第三方人机验证：键 => [显示名, 官方校验地址, 前端脚本, 前端字段名] */
    public const THIRD = [
        "turnstile" => ["Cloudflare Turnstile", "https://challenges.cloudflare.com/turnstile/v0/siteverify", "https://challenges.cloudflare.com/turnstile/v0/api.js", "cf-turnstile-response"],
        "hcaptcha"  => ["hCaptcha", "https://api.hcaptcha.com/siteverify", "https://js.hcaptcha.com/1/api.js", "h-captcha-response"],
        "recaptcha" => ["Google reCAPTCHA", "https://www.google.com/recaptcha/api/siteverify", "https://www.google.com/recaptcha/api.js", "g-recaptcha-response"],
        // Geetest v4 一次返回四个值，前端拼成 JSON 塞进 cap3，字段名就统一填 cap3
        "geetest4"  => ["Geetest 行为验证 v4", "https://gcaptcha4.geetest.com/validate", "https://static.geetest.com/v4/gt4.js", "cap3"],
    ];

    /** 站点登录方式：oauth（第三方门禁）/ captcha（人机验证）/ none */
    public static function mode(PDO $pdo): string {
        $st = $pdo->prepare("SELECT v FROM settings WHERE k = 'auth.mode' LIMIT 1");
        $st->execute();
        $v = (string) ($st->fetchColumn() ?: "");
        if ($v === "oauth" || $v === "none" || $v === "captcha") return $v;
        // 没配过的老站点：按原来的 OAuth 开关推断
        $st = $pdo->prepare("SELECT v FROM settings WHERE k = 'oauth.enabled' LIMIT 1");
        $st->execute();
        return ((string) $st->fetchColumn() === "1") ? "oauth" : "captcha";
    }

    public static function enabled(PDO $pdo): bool {
        return self::mode($pdo) === "captcha";
    }

    /** 后台配置的验证方式（builtin / turnstile / hcaptcha / recaptcha），没配过就是 builtin */
    public static function provider(PDO $pdo): string {
        $v = self::setting($pdo, "auth.captcha.provider");
        if ($v === "builtin") return "builtin";
        return isset(self::THIRD[$v]) ? $v : "builtin";
    }

    /**
     * 实际生效的验证方式。配了第三方但没填 Secret Key 的时候退回 builtin：
     * 不这么退的话，登录页会挂一个永远过不去的 widget，谁也进不来。
     */
    public static function active(PDO $pdo): string {
        $p = self::provider($pdo);
        if ($p !== "builtin" && self::secret($pdo) === "") return "builtin";
        return $p;
    }

    public static function thirdParty(PDO $pdo): bool {
        return self::active($pdo) !== "builtin";
    }

    /** 配置不完整时的中文说明，登录页会显示；没问题时返回空串 */
    public static function warning(PDO $pdo): string {
        $p = self::provider($pdo);
        if ($p === "builtin" || self::secret($pdo) !== "") return "";
        return "站点选了 " . self::THIRD[$p][0] . "，但没填 " . self::keyLabel($p) . "，现在先按内建滑块验证。";
    }

    /** 两家对密钥的叫法不一样，配置界面的提示跟着换 */
    private static function keyLabel(string $provider): string {
        return $provider === "geetest4" ? "Captcha Key" : "Secret Key";
    }

    public static function siteKey(PDO $pdo): string {
        return self::setting($pdo, "auth.captcha.site_key");
    }

    public static function secret(PDO $pdo): string {
        return self::setting($pdo, "auth.captcha.secret");
    }

    /** 自定义校验地址（自建代理或私有化部署用），空 = 用官方地址 */
    public static function verifyUrl(PDO $pdo): string {
        return self::setting($pdo, "auth.captcha.verify_url");
    }

    /** 登录页要用的配置：provider / site key / 前端脚本地址 / 不完整时的提示 */
    public static function clientConfig(PDO $pdo): array {
        $p    = self::active($pdo);
        $script = $p === "builtin" ? "" : self::THIRD[$p][2];
        if ($p === "recaptcha") $script .= "?hl=zh-CN";
        return [
            "provider" => $p,
            "site_key" => self::siteKey($pdo),
            "script"   => $script,
            "warning"  => self::warning($pdo),
        ];
    }

    /** 发一条挑战：target 是滑块该停的百分比位置 */
    public static function start(): array {
        $now    = self::now();
        $target = random_int(58, 88);
        $exp    = $now + self::TTL;
        $nonce  = bin2hex(random_bytes(6));
        $token  = $target . "." . $exp . "." . $nonce . "." . self::sign($target . "|" . $exp . "|" . $nonce);
        return ["ok" => true, "token" => $token, "target" => $target, "ttl" => self::TTL];
    }

    /**
     * 校验一次提交，返回空串表示通过，否则是给用户看的中文原因。
     * 内建滑块看签名/位置/耗时/轨迹；第三方看站点的 siteverify 结果。
     */
    public static function check(PDO $pdo, array $fields): string {
        if (!self::thirdParty($pdo)) {
            $token = self::scalar($fields, "cap_token");
            $ok = self::verify(
                $token,
                (int) self::scalar($fields, "cap_off"),
                (int) self::scalar($fields, "cap_ms"),
                (int) self::scalar($fields, "cap_moves"),
                self::scalar($fields, "cap_path")
            );
            if (!$ok) return "人机验证没过，重新拖一下再试";
            // 一条挑战只能换一次登录：不消费 nonce 的话，抓到一次合法请求
            // 就能把同一份 cap_* 无限重放（攻击者不用真的拖滑块）。
            if (!self::consume($pdo, $token)) return "这条验证已经用过了，刷新页面再拖一次";
            return "";
        }
        $p     = self::active($pdo);
        $token = self::scalar($fields, "cap3");
        if ($token === "") {
            // 各家 widget 用自己的字段名，前端没能塞进 cap3 的时候再兜一遍
            foreach (self::THIRD as $one) {
                if ($token === "") $token = self::scalar($fields, $one[3]);
            }
        }
        if ($token === "") return "请先完成人机验证";
        if (strlen($token) > 6000) return "人机验证的凭据不对，刷新页面再试";
        if ($p === "geetest4") {
            return self::verifyGeetest4($pdo, $token) ? "" : "人机验证没过，重试一下";
        }
        if (!self::verifyThirdParty($pdo, $p, $token)) return "人机验证没过，重试一下";
        return "";
    }

    /** 校验一条内建挑战：签名、有效期、位置、耗时、轨迹 */
    public static function verify(string $token, int $offset, int $ms, int $moves, string $path = ""): bool {
        $parts = explode(".", $token);
        if (count($parts) !== 4) return false;
        [$t, $exp, $nonce, $sig] = $parts;
        if (!ctype_digit($t) || !ctype_digit($exp) || !preg_match('/^[0-9a-f]{12}$/', $nonce)) return false;
        if (!hash_equals(self::sign($t . "|" . $exp . "|" . $nonce), $sig)) return false;
        if (self::now() > (int) $exp) return false;
        if (abs($offset - (int) $t) > self::TOL) return false;
        if ($ms < self::MIN_MS || $ms > self::TTL) return false;
        if ($moves < self::MIN_MOVES) return false;
        if (!self::verifyPath($path, $offset)) return false;
        return true;
    }

    /**
     * 轨迹校验：只报「拖了几下」的话，脚本随手填个 5 就能过。
     * 这里要求前端真的上报采样点，轨迹大体单调、起点靠左、终点落在缺口附近。
     * 挡不住蓄意伪造（一维滑块本质就是这个水平），但能把「抓到一次答案无限重放」
     * 变成「每次都得重新开一条挑战」。
     */
    private static function verifyPath(string $path, int $offset): bool {
        if ($path === "" || strlen($path) > 512) return false;
        $pts = [];
        foreach (explode(",", $path) as $one) {
            $one = trim($one);
            if (!is_numeric($one)) return false;
            $pts[] = (float) $one;
            if (count($pts) > 64) return false;
        }
        if (count($pts) < self::MIN_MOVES) return false;
        if ($pts[0] > 5) return false;
        if (abs($pts[count($pts) - 1] - $offset) > 6) return false;
        // 大体单调：允许手抖回退 1 个百分点
        for ($i = 1; $i < count($pts); $i++) {
            if ($pts[$i] < $pts[$i - 1] - 1) return false;
        }
        return true;
    }

    /** 记下这条挑战已经用过；重复使用（主键冲突）返回 false */
    private static function consume(PDO $pdo, string $token): bool {
        $parts = explode(".", $token);
        $nonce = $parts[2] ?? "";
        if (!preg_match('/^[0-9a-f]{12}$/', $nonce)) return false;
        try {
            $pdo->prepare("INSERT INTO captcha_used (nonce, used_at) VALUES (?, ?)")->execute([$nonce, self::now()]);
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            // 主键冲突 = 这条挑战已经用过了；其它异常（老库还没建表之类）放行，别把人挡在门外
            $dup = strpos($msg, "duplicate") !== false || strpos($msg, "unique") !== false || strpos($msg, "constraint") !== false;
            return !$dup;
        }
        // 顺手清掉两倍有效期以前的记录，表别无限长
        try {
            $pdo->prepare("DELETE FROM captcha_used WHERE used_at < ?")->execute([self::now() - self::TTL * 2]);
        } catch (\Throwable $e) {
        }
        return true;
    }

    /**
     * 后台与安装向导保存验证码配置。返回规整后的配置，出错抛 RuntimeException（中文）。
     */
    public static function save(PDO $pdo, array $in): array {
        $p      = trim((string) ($in["provider"] ?? "builtin"));
        if ($p === "") $p = "builtin";
        if ($p !== "builtin" && !isset(self::THIRD[$p])) throw new RuntimeException("不认识这种人机验证方式");
        $site   = trim((string) ($in["site_key"] ?? ""));
        $secret = trim((string) ($in["secret"] ?? ""));
        $url    = trim((string) ($in["verify_url"] ?? ""));
        if ($p !== "builtin") {
            $idLabel = $p === "geetest4" ? "Captcha ID" : "Site Key";
            if ($site === "") throw new RuntimeException("用第三方验证要填 " . $idLabel . "，登录页才知道加载哪个验证框");
            if ($secret === "") throw new RuntimeException("用第三方验证要填 " . self::keyLabel($p) . "，不然服务端没法确认验证结果");
        }
        if ($url !== "" && !preg_match('#^https?://#i', $url)) {
            throw new RuntimeException("校验地址要以 http:// 或 https:// 开头");
        }
        if (mb_strlen($site) > 191 || mb_strlen($url) > 300) throw new RuntimeException("Site Key 或校验地址太长");
        $st = $pdo->prepare("REPLACE INTO settings (k, v) VALUES (?, ?)");
        foreach ([
            "auth.captcha.provider"   => $p,
            "auth.captcha.site_key"   => $site,
            "auth.captcha.secret"     => $secret,
            "auth.captcha.verify_url" => $url,
        ] as $k => $v) {
            $st->execute([$k, $v]);
        }
        return self::read($pdo);
    }

    /** 现有配置（后台表单回显用） */
    public static function read(PDO $pdo): array {
        return [
            "provider"   => self::provider($pdo),
            "site_key"   => self::siteKey($pdo),
            "secret"     => self::secret($pdo),
            "verify_url" => self::verifyUrl($pdo),
            "active"     => self::active($pdo),
            "warning"    => self::warning($pdo),
        ];
    }

    // ---------- 内部 ----------

    /** 向第三方 siteverify 换结果：success 严格等于 true 才算过 */
    private static function verifyThirdParty(PDO $pdo, string $provider, string $token): bool {
        $secret = self::secret($pdo);
        if ($secret === "") return false;
        $url = self::verifyUrl($pdo);
        if ($url === "") $url = self::THIRD[$provider][1];
        $post = ["secret" => $secret, "response" => $token];
        $ip = (string) ($_SERVER["REMOTE_ADDR"] ?? "");
        if ($ip !== "") $post["remoteip"] = $ip;
        // hCaptcha 认 sitekey，别的服务多传也不会报错，就只给它
        if ($provider === "hcaptcha") $post["sitekey"] = self::siteKey($pdo);
        $body = self::post($url, $post);
        if ($body === "") return false;
        $j = json_decode($body, true);
        if (!is_array($j)) return false;
        return ($j["success"] ?? false) === true;
    }

    /**
     * Geetest v4 二次校验。
     * 客户端交上来的是 gt4.js 的 getValidate()：lot_number / captcha_output / pass_token / gen_time。
     * 官方要求再带一个 sign_token = HMAC-SHA256(lot_number, captcha_key)，
     * 校验地址是 /validate?captcha_id=<captcha_id>，返回 {status:"success", result:"success"} 才算过。
     */
    private static function verifyGeetest4(PDO $pdo, string $raw): bool {
        $j = json_decode($raw, true);
        if (!is_array($j)) return false;
        $lot  = trim((string) ($j["lot_number"] ?? ""));
        $out  = trim((string) ($j["captcha_output"] ?? ""));
        $pass = trim((string) ($j["pass_token"] ?? ""));
        $gen  = trim((string) ($j["gen_time"] ?? ""));
        if ($lot === "" || $out === "" || $pass === "" || $gen === "") return false;
        $key = self::secret($pdo);
        if ($key === "") return false;
        $site = self::siteKey($pdo);
        $url  = self::verifyUrl($pdo);
        if ($url === "") {
            $url = self::THIRD["geetest4"][1];
        }
        // captcha_id 走 query，sign_token 走表单，两边都不能少
        $sep = strpos($url, "?") === false ? "?" : "&";
        $url .= $sep . "captcha_id=" . urlencode($site);
        $post = [
            "lot_number"     => $lot,
            "captcha_output" => $out,
            "pass_token"     => $pass,
            "gen_time"       => $gen,
            "sign_token"     => hash_hmac("sha256", $lot, $key),
        ];
        $body = self::post($url, $post);
        if ($body === "") return false;
        $r = json_decode($body, true);
        if (!is_array($r)) return false;
        return ($r["status"] ?? "") === "success" && ($r["result"] ?? "") === "success";
    }

    /** POST 表单：有 curl 用 curl，没有就退回 stream，超时都短，别把登录页挂住 */
    private static function post(string $url, array $post): string {
        $data = http_build_query($post);
        if (function_exists("curl_init")) {
            $ch = @curl_init($url);
            if ($ch === false) return "";
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER     => ["Content-Type: application/x-www-form-urlencoded"],
            ]);
            $out = curl_exec($ch);
            // curl_close() 在 PHP 8.0+ 已无效果，PHP 8.5 起还会报 deprecated，删掉免刷日志
            return is_string($out) ? $out : "";
        }
        $ctx = stream_context_create(["http" => [
            "method"        => "POST",
            "header"        => "Content-Type: application/x-www-form-urlencoded\r\n",
            "content"       => $data,
            "timeout"       => 8,
            "ignore_errors" => false,
        ]]);
        $out = @file_get_contents($url, false, $ctx);
        return is_string($out) ? $out : "";
    }

    private static function setting(PDO $pdo, string $key): string {
        $st = $pdo->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
        $st->execute([$key]);
        return (string) ($st->fetchColumn() ?: "");
    }

    private static function scalar(array $fields, string $key): string {
        if (!isset($fields[$key]) || !is_scalar($fields[$key])) return "";
        return trim((string) $fields[$key]);
    }

    private static function now(): int {
        return (int) floor(microtime(true) * 1000);
    }

    /** 站点密钥：安装时写进 .lock，没装的时候退回 config 里的 database 路径，保证同机稳定 */
    private static function key(): string {
        $lock = lg_lock();
        $secret = (string) ($lock["secret"] ?? "");
        if ($secret !== "") return $secret;
        $cfg = lg_config();
        return "lg:" . (string) ($cfg["db_path"] ?? ($cfg["db"]["path"] ?? "default"));
    }

    private static function sign(string $body): string {
        return substr(hash_hmac("sha256", "cap|" . $body, self::key()), 0, 24);
    }
}
