<?php
declare(strict_types=1);

namespace LightGroup\Web;

/**
 * 站点版本号。
 *
 * 版本号跟着发布走：读项目根的 api/manifest.json（和后台「更新检查」看的是同一份清单），
 * 读不到才回退安装时写进 .lock 的值，最后兜底 0.1.0。
 * 只读 .lock 的话，部署新版本后底栏会一直显示安装那天的版本号。
 */
final class Version
{
    public static function current(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        $root = defined("APP_ROOT") ? (string) APP_ROOT : dirname(__DIR__, 2);

        $manifest = $root . "/api/manifest.json";
        if (is_file($manifest)) {
            $j = json_decode((string) @file_get_contents($manifest), true);
            $v = is_array($j) ? trim((string) ($j["version"] ?? "")) : "";
            if ($v !== "") return $cached = $v;
        }

        $lock = $root . "/.lock";
        if (is_file($lock)) {
            $j = json_decode((string) @file_get_contents($lock), true);
            $v = is_array($j) ? trim((string) ($j["version"] ?? "")) : "";
            if ($v !== "") return $cached = $v;
        }

        return $cached = "0.1.0";
    }
}
