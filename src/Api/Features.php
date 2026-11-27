<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Store\Pruner;
use PDO;

/**
 * 站点 / 作用域级的功能开关（不是权限组）。
 *
 * 权限组管「一个群能用哪些群功能」；这里管全站能力：注册、AI 账号、
 * 适配器、MCP 连接方式、空间、扫一扫与基础社交。
 * 存在 settings 表，键统一带 feature. 前缀，默认全开。
 */
final class Features {
    /** key => [类型, 默认值, 中文名, 枚举值] */
    public const KEYS = [
        "register"       => ["bool", "1", "允许新用户自助注册", []],
        "ai"             => ["bool", "1", "启用 AI 账号", []],
        "adapter.xsj"    => ["bool", "1", "小群（xsj）适配器", []],
        "adapter.mcp"    => ["bool", "1", "MCP 适配器", []],
        "adapter.api"    => ["bool", "1", "REST（api）适配器", []],
        "mcp.transport"  => ["enum", "both", "MCP 连接方式", ["both", "stream", "sse"]],
        "space"          => ["bool", "1", "启用空间", []],
        "space.post"     => ["bool", "1", "允许发空间动态", []],
        "space.like"     => ["bool", "1", "允许点赞动态", []],
        "space.comment"  => ["bool", "1", "允许评论动态", []],
        "social.friend"  => ["bool", "1", "允许加好友", []],
        "social.dm"      => ["bool", "1", "允许私聊", []],
        "social.scan"    => ["bool", "1", "扫一扫", []],
        "social.qr"      => ["bool", "1", "个人/群二维码", []],
        "social.discover"=> ["bool", "1", "「发现」", []],
    ];

    private static function raw(PDO $pdo, string $key, string $d): string {
        $st = $pdo->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
        $st->execute(["feature." . $key]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $d : (string) $v;
    }

    /** 某个开关开没开；未知键按开处理，避免新代码被旧配置挡住 */
    public static function on(PDO $pdo, string $key): bool {
        $meta = self::KEYS[$key] ?? null;
        $def = $meta ? (string) $meta[1] : "1";
        if ($meta && $meta[0] === "enum") return self::raw($pdo, $key, $def) !== "off";
        return self::raw($pdo, $key, $def) !== "0";
    }

    public static function adapter(PDO $pdo, string $name): bool {
        $key = "adapter." . ($name === "xsj" ? "xsj" : ($name === "api" ? "api" : "mcp"));
        return self::on($pdo, "ai") && self::on($pdo, $key);
    }

    /** MCP 连接方式：both / stream / sse */
    public static function transport(PDO $pdo): string {
        $v = self::raw($pdo, "mcp.transport", "both");
        return in_array($v, ["both", "stream", "sse"], true) ? $v : "both";
    }

    public static function social(PDO $pdo, string $name): bool {
        return self::on($pdo, "social." . $name);
    }

    /** 私群创建：按作用域配，默认开 */
    public static function pgCreate(PDO $pdo, int $scopeId): bool {
        return self::raw($pdo, "pg.create." . $scopeId, "1") !== "0";
    }

    /** 消息存储策略（群覆盖 → 站点总配置），前端用来决定要不要在本机留一份 */
    public static function msg(PDO $pdo, int $scopeId): array {
        $pol = Pruner::policy($pdo, $scopeId);
        return [
            "mode"      => (string) $pol["mode"],
            "local"     => (bool) $pol["local"],
            "keepDays"  => (int) $pol["days"],
            "keepPerConv" => (int) $pol["per_conv"],
            "pageSize"  => (int) $pol["page_size"],
            "inherited" => (bool) $pol["inherited"],
        ];
    }

    /** 后台设置页用的元信息 */
    public static function meta(): array {
        $out = [];
        foreach (self::KEYS as $k => $m) {
            $out[] = ["key" => "feature." . $k, "type" => $m[0], "default" => $m[1], "label" => $m[2], "values" => $m[3]];
        }
        return $out;
    }

    /** 给前端的站点能力表 */
    public static function site(PDO $pdo, int $scopeId = 0): array {
        return [
            "register"  => self::on($pdo, "register"),
            "ai"        => self::on($pdo, "ai"),
            "adapters"  => [
                "xsj" => self::on($pdo, "adapter.xsj"),
                "mcp" => self::on($pdo, "adapter.mcp"),
                "api" => self::on($pdo, "adapter.api"),
            ],
            "mcpTransport" => self::transport($pdo),
            "space"     => [
                "enabled" => self::on($pdo, "space"),
                "post"    => self::on($pdo, "space.post"),
                "like"    => self::on($pdo, "space.like"),
                "comment" => self::on($pdo, "space.comment"),
            ],
            "social"    => [
                "friend"   => self::social($pdo, "friend"),
                "dm"       => self::social($pdo, "dm"),
                "scan"     => self::social($pdo, "scan"),
                "qr"       => self::social($pdo, "qr"),
                "discover" => self::social($pdo, "discover"),
            ],
            "msg"       => self::msg($pdo, $scopeId),
        ];
    }
}
