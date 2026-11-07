<?php
declare(strict_types=1);

namespace LightGroup\Web;

use LightGroup\Store\Db;
use Throwable;

/**
 * 站点默认主题（settings.theme.preset）到强调色的解析。
 *
 * 色值必须和 public/assets/js/core.js 里的 THEMES 保持一致（改一边记得同步另一边），
 * 主题包（p_N）的色值从 themes 表读，所以后台新建的共享主题包也能拿来当站点默认主题。
 * 门面页面（首页 / 登录页 / 404）不加载前端模块，只能靠这里把第一眼配色定下来。
 */
final class ThemePreset
{
    /** @var array<string,string> 内建主题 id => 主色 */
    public const ACCENT = [
        'dadi' => '#b91c1c',
        'zhuanhong' => '#9a3412',
        'shuguang' => '#e05a45',
        'daoxiang' => '#ea580c',
        'nuanyangcheng' => '#f59e0b',
        'kafei' => '#78350f',
        'naicha' => '#c8a27a',
        'xingse' => '#f2b98c',
        'shechijin' => '#b8860b',
        'luori' => '#ca8a04',
        'dianjihuang' => '#a16207',
        'mocha' => '#65a30d',
        'xiaocao' => '#16a34a',
        'songlv' => '#15803d',
        'danbohe' => '#34d399',
        'qingbi' => '#0d9488',
        'yunqing' => '#0891b2',
        'minghuanqing' => '#06b6d4',
        'dailan' => '#dbeafe',
        'tianqing' => '#0284c7',
        'wumailan' => '#7d94a8',
        'hulan' => '#2563eb',
        'xuanfenglan' => '#1d4ed8',
        'menghuanlan' => '#5b7cfa',
        'beijixing' => '#172554',
        'shenkongming' => '#0b1026',
        'ziluolan' => '#7c3aed',
        'dingxiangzi' => '#c4b5fd',
        'huopo' => '#c026d3',
        'mengdong' => '#db2777',
        'daifen' => '#fce7f3',
        'caixiafen' => '#f472b6',
        'yinghuafen' => '#fbcfe8',
        'mitao' => '#ffb3a7',
        'naihuang' => '#fde68a',
        'bohenai' => '#a7f3d0',
        'yunduolan' => '#bae6fd',
        'yuni' => '#ddd6fe',
        'oufen' => '#e8c4d0',
        'tongtou' => '#ffffff',
        'mibai' => '#f7f3ea',
        'yuebai' => '#eef2f7',
        'shiban' => '#334155',
        'yanhui' => '#64748b',
        'menglonghui' => '#9ca8b8',
        'didiao' => '#18181b',
        'gaoyahei' => '#0b0b0f',
        'wuye' => '#0b1220',
    ];

    public const DEFAULT_ID = 'hulan';

    /** 站点默认主题 id（可能是内建 id，也可能是主题包 p_N） */
    public static function presetId(): string
    {
        try {
            $pdo = Db::connect();
            $st = $pdo->prepare("SELECT v FROM settings WHERE k = ?");
            $st->execute(["theme.preset"]);
            $v = $st->fetchColumn();
            return ($v === false || $v === null || $v === "") ? self::DEFAULT_ID : (string) $v;
        } catch (Throwable $e) {
            return self::DEFAULT_ID;
        }
    }

    /** 主题 id => 主色；主题包读 themes.vars 里的 accent，读不到就回退湖蓝 */
    public static function accentOf(string $preset): string
    {
        if ($preset !== "" && isset(self::ACCENT[$preset])) {
            return self::ACCENT[$preset];
        }
        if (str_starts_with($preset, "p_")) {
            try {
                $pdo = Db::connect();
                $st = $pdo->prepare("SELECT vars FROM theme_packs WHERE id = ?");
                $st->execute([(int) substr($preset, 2)]);
                $vars = $st->fetchColumn();
                if (is_string($vars) && $vars !== "") {
                    $j = json_decode($vars, true);
                    if (is_array($j)) {
                        $a = (string) ($j["accent"] ?? $j["bubble"] ?? "");
                        if (preg_match('/^#[0-9a-fA-F]{6}$/', $a)) return $a;
                    }
                }
            } catch (Throwable $e) {
                // 表还没建好之类：落到默认色
            }
        }
        return self::ACCENT[self::DEFAULT_ID];
    }

    /** 亮底配深字、暗底配浅字（与 core.js 的 contrastText 同规则） */
    public static function textOn(string $hex): string
    {
        if (!preg_match('/^#?([0-9a-fA-F]{6})$/', $hex, $m)) return "#ffffff";
        $v = (int) hexdec($m[1]);
        $lin = static function (float $c): float {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin(($v >> 16) & 255) + 0.7152 * $lin(($v >> 8) & 255) + 0.0722 * $lin($v & 255);
        return $l > 0.45 ? "#111827" : "#ffffff";
    }

    /** 直接可输出的 <style>：门面页面在 base.css 之后插入即可 */
    public static function style(): string
    {
        $id = self::presetId();
        $accent = self::accentOf($id);
        $text = self::textOn($accent);
        $a = htmlspecialchars($accent, ENT_QUOTES, "UTF-8");
        $t = htmlspecialchars($text, ENT_QUOTES, "UTF-8");
        return '<style>:root{--accent:' . $a . ';--accent-text:' . $t . ';--self-bubble:' . $a . ';--self-bubble-text:' . $t . '}</style>';
    }
}
