<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Web\Site;

/**
 * 公开的站点信息：别的站点的二维码扫过来时，拿它显示「这是哪个站点」。
 *
 * 不要求登录，也不带成员数据；允许任意来源读取 —— 跨站扫码必须能直接取。
 */
final class About
{
    public static function handle(): void
    {
        // 只读、公开：先把会话锁放掉，扫码端并发请求不互相排队
        lg_session_release();
        self::cors();

        $method = (string) ($_SERVER["REQUEST_METHOD"] ?? "GET");
        if ($method === "OPTIONS") {
            http_response_code(204);
            return;
        }
        if ($method !== "GET" && $method !== "HEAD") {
            http_response_code(405);
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(["ok" => false, "err" => "只支持 GET"], JSON_UNESCAPED_UNICODE);
            return;
        }

        header("Content-Type: application/json; charset=utf-8");
        header("Cache-Control: public, max-age=300");

        $info = Site::info();
        $origin = self::origin();
        $abs = static function (string $p) use ($origin): string {
            if ($p === "") return "";
            if (preg_match('#^https?://#i', $p)) return $p;
            return $origin . ($p[0] === "/" ? $p : "/" . $p);
        };
        $icon = $abs((string) ($info["icon"] ?? ""));
        $iconDark = $abs((string) ($info["icon_dark"] ?? ""));
        if ($icon === "") $icon = $origin . "/assets/favicon.svg";
        if ($iconDark === "") $iconDark = $icon;

        echo json_encode([
            "ok" => true,
            "site" => [
                "name" => (string) $info["name"],
                "intro" => (string) $info["intro"],
                "icon" => $icon,
                "icon_dark" => $iconDark,
                "url" => $origin,
                "version" => (string) $info["version"],
                "stats" => $info["stats"],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 站点根地址：带协议、不带 /g/ 那段 */
    private static function origin(): string
    {
        $https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
            || (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https");
        $host = (string) ($_SERVER["HTTP_HOST"] ?? "localhost");
        return ($https ? "https" : "http") . "://" . $host;
    }

    private static function cors(): void
    {
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, OPTIONS");
        header("Access-Control-Allow-Headers: *");
        header("X-Content-Type-Options: nosniff");
    }
}
