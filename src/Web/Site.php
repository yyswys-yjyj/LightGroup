<?php
declare(strict_types=1);

namespace LightGroup\Web;

use LightGroup\Store\Db;
use Throwable;

final class Site
{
    public const DEFAULT_NAME = 'LightGroup';

    public static function baseUrl(): string
    {
        $https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
            || (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https");
        $host = (string) ($_SERVER["HTTP_HOST"] ?? "localhost");
        return ($https ? "https" : "http") . "://" . $host . "/g/";
    }

    /** @return array{name:string,intro:string,theme:string,icon:string,icon_dark:string,version:string,base:string,stats:array{scopes:int,members:int,messages:int},copyright:string,installed:bool} */
    public static function info(): array
    {
        $name = self::DEFAULT_NAME;
        $intro = "";
        $theme = "auto";
        $accent = \LightGroup\Web\ThemePreset::ACCENT[\LightGroup\Web\ThemePreset::DEFAULT_ID];
        $icon = "";
        $iconDark = "";
        $copyright = "";
        $stats = ["scopes" => 0, "members" => 0, "messages" => 0];
        $installed = function_exists("lg_installed") && lg_installed();

        if ($installed) {
            try {
                $pdo = Db::connect();
                $get = function (string $k, string $fallback) use ($pdo): string {
                    $st = $pdo->prepare("SELECT v FROM settings WHERE k = ?");
                    $st->execute([$k]);
                    $v = $st->fetchColumn();
                    return ($v === false || $v === null) ? $fallback : (string) $v;
                };
                $name = $get("site.name", self::DEFAULT_NAME);
                $intro = $get("site.intro", "");
                $theme = $get("theme", "auto");
                $preset = $get("theme.preset", \LightGroup\Web\ThemePreset::DEFAULT_ID);
                $accent = \LightGroup\Web\ThemePreset::accentOf($preset);
                $icon = $get("site.icon_light", "");
                $iconDark = $get("site.icon_dark", "");
                $copyright = $get("site.copyright", "");
                $stats["scopes"] = (int) $pdo->query("SELECT COUNT(*) FROM scopes WHERE archived = 0")->fetchColumn();
                $stats["members"] = (int) $pdo->query("SELECT COUNT(*) FROM members WHERE deleted_at IS NULL AND banned = 0")->fetchColumn();
                $stats["messages"] = (int) $pdo->query("SELECT COUNT(*) FROM messages WHERE sys = 0")->fetchColumn();
            } catch (Throwable $e) {
                // 数据还没装好时不影响门面渲染
            }
        }

        return [
            "name" => $name !== "" ? $name : self::DEFAULT_NAME,
            "intro" => $intro,
            "theme" => $theme !== "" ? $theme : "auto",
            // 站点默认主题的主色：门面页面拿它定第一眼的强调色与 theme-color
            "accent" => $accent !== "" ? $accent : \LightGroup\Web\ThemePreset::ACCENT[\LightGroup\Web\ThemePreset::DEFAULT_ID],
            "icon" => $icon,
            "icon_dark" => $iconDark,
            "version" => Version::current(),
            "base" => self::baseUrl(),
            "stats" => $stats,
            "copyright" => $copyright,
            "installed" => $installed,
        ];
    }
}
