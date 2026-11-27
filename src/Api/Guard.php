<?php
declare(strict_types=1);

namespace LightGroup\Api;

use PDO;
use LightGroup\Http\Json;

/**
 * 高危操作的二次验证。
 *
 * 这些动作一旦点错就没法回头（转让群主、解散群、注销账号、后台清空历史……），
 * 所以不管站点用哪种登录方式，都要在提交前再过一次人机验证：
 *  - auth.mode=captcha：用站点配置的验证方式（第三方 widget 或内建二维滑块，可叠加 PoW）；
 *  - auth.mode=oauth / none：没有可用的第三方 widget，就走内建二维滑块。
 * 这样「没开 OAuth 门禁」「压根没开验证」的站点也有一道门槛，
 * 而 OAuth 门禁站点只在清单里的动作上弹，不需要每次操作都验。
 *
 * 开关与清单在后台「人机验证」专项设置里：verify.risk / verify.risk.actions。
 */
final class Guard {
    /** 动作键 => 后台显示的名字。清单只在这里出现的动作才可能要求验证 */
    public const ACTIONS = [
        "group.transfer"       => "转让群主",
        "group.dissolve"       => "解散群聊",
        "account.delete"       => "注销账号",
        "ai.delete"            => "删除 AI 账号",
        "admin.conv.ban"       => "封禁群",
        "admin.conv.dissolve"  => "注销群聊",
        "admin.conv.clear"     => "清空会话历史",
        "admin.member.delete"  => "删除成员",
        "admin.member.purge"   => "彻底删除成员",
    ];

    public static function enabled(PDO $pdo): bool {
        return self::setting($pdo, "verify.risk", "1") !== "0";
    }

    /** 清单里实际开启的动作（配置了不认识的键就忽略） */
    public static function actions(PDO $pdo): array {
        $raw = trim(self::setting($pdo, "verify.risk.actions", ""));
        if ($raw === "") return array_keys(self::ACTIONS);
        // 后台按逗号串存（表单好提交）；早期版本试过 JSON，两种都认
        $list = $raw[0] === "[" ? json_decode($raw, true) : explode(",", $raw);
        if (!is_array($list)) return array_keys(self::ACTIONS);
        $out = [];
        foreach ($list as $one) {
            $k = (string) $one;
            if (isset(self::ACTIONS[$k])) $out[$k] = true;
        }
        // 一个都没勾等于全关：不允许出现「配置坏了反而全开」的意外
        return array_keys($out);
    }

    public static function need(PDO $pdo, string $action): bool {
        if (!self::enabled($pdo)) return false;
        if (!isset(self::ACTIONS[$action])) return false;
        return in_array($action, self::actions($pdo), true);
    }

    /**
     * 高危操作统一用内建二维滑块：第三方 widget 的 token 是给登录页用的，
     * 后台和高危弹窗里再嵌一套各家 SDK 既不稳也没必要；内建滑块自带 UA 摘要、
     * 轨迹与一次性 nonce，captcha 模式下还能叠加 PoW，够这一道门槛用了。
     */
    public static function mode(PDO $pdo): string {
        return "builtin";
    }

    /** 返回空串表示通过，否则是原因（ok=1 由调用方决定是否 Json::fail） */
    public static function why(PDO $pdo, string $action, array $fields): string {
        if (!self::need($pdo, $action)) return "";
        if (self::mode($pdo) === "builtin") return Captcha::checkBuiltin($pdo, $fields);
        return Captcha::check($pdo, $fields);
    }

    /** 高危接口统一入口：没过就 403，并给前端一个明确的文案 */
    public static function require(PDO $pdo, string $action, array $fields): void {
        // 滑块只服务于网页客户端：MCP / REST / 小群适配器这些带 token 的调用不走这一步。
        // 它们的身份在 token 里，加一道给人拖的验证只会把机器挡死。
        if (self::isApiCall()) return;
        $why = self::why($pdo, $action, $fields);
        if ($why !== "") Json::fail($why, 403);
    }

    /** 是不是带 token 的接口调用（不是网页会话） */
    private static function isApiCall(): bool {
        foreach (["HTTP_AUTHORIZATION", "REDIRECT_HTTP_AUTHORIZATION"] as $k) {
            $v = trim((string) ($_SERVER[$k] ?? ""));
            if ($v !== "") return true;
        }
        foreach (["k", "key", "token"] as $k) {
            if (isset($_GET[$k]) && is_scalar($_GET[$k]) && trim((string) $_GET[$k]) !== "") return true;
        }
        return false;
    }

    /** 后台设置页用：动作清单与开闭 */
    public static function client(): array {
        $out = [];
        foreach (self::ACTIONS as $k => $label) {
            $out[] = ["key" => $k, "label" => $label];
        }
        return $out;
    }

    private static function setting(PDO $pdo, string $key, string $default = ""): string {
        $st = $pdo->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $default : (string) $v;
    }
}
