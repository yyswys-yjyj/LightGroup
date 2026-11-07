<?php
declare(strict_types=1);

namespace LightGroup\Api;

use PDO;

/**
 * 网页登录态。session 里只放 member_id / scope_id / account_id，不放 token 明文。
 * token 仍然属于插件与 AI，两者互不干扰。
 *
 * 开了 OAuth 门禁时账号是两层的：
 *   accounts（主账号，来自第三方身份）→ members（业务账号，群里说话的那个）
 * 登录只代表主账号进来，业务账号要自己选或新建，所以 mid 允许暂时为空。
 */
final class Session {
    public static function member(PDO $pdo): ?array {
        $id = (int) ($_SESSION["mid"] ?? 0);
        if ($id <= 0) return null;
        $st = $pdo->prepare("SELECT * FROM members WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            // 业务账号没了就把 mid 清掉，主账号还在的话还能重新选一个
            unset($_SESSION["mid"], $_SESSION["sid"]);
            if ((int) ($_SESSION["aid"] ?? 0) <= 0) self::logout();
            return null;
        }
        return $row;
    }

    /** 主账号（accounts 行）；没走 OAuth 的账密成员这里也是空 */
    public static function account(PDO $pdo): ?array {
        $id = (int) ($_SESSION["aid"] ?? 0);
        if ($id <= 0) return null;
        $st = $pdo->prepare("SELECT * FROM accounts WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            unset($_SESSION["aid"]);
            return null;
        }
        return $row;
    }

    /** 第三方身份认过了，但还没挑业务账号 */
    public static function loginAccount(PDO $pdo, array $account): void {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        session_regenerate_id(true);
        $_SESSION = ["aid" => (int) $account["id"]];
    }

    public static function login(PDO $pdo, array $member): void {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        session_regenerate_id(true);
        $_SESSION["mid"] = (int) $member["id"];
        $_SESSION["sid"] = (int) $member["scope_id"];
        $aid = (int) ($member["account_id"] ?? 0);
        if ($aid > 0) $_SESSION["aid"] = $aid;
    }

    public static function logout(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $p = session_get_cookie_params();
            setcookie(session_name(), "", [
                "expires"  => time() - 42000,
                "path"     => $p["path"] ?: "/",
                "domain"   => $p["domain"] ?? "",
                "secure"   => (bool) ($p["secure"] ?? false),
                "httponly" => (bool) ($p["httponly"] ?? true),
            ]);
        }
        @session_destroy();
    }
}
