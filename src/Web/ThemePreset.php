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

    /** @return array{int,int,int} */
    private static function rgb(string $hex): array
    {
        if (!preg_match('/^#?([0-9a-fA-F]{6})$/', $hex, $m)) return [37, 99, 235];
        $v = (int) hexdec($m[1]);
        return [($v >> 16) & 255, ($v >> 8) & 255, $v & 255];
    }

    /** @param array{float,float,float} $c */
    private static function hex(array $c): string
    {
        return sprintf(
            '#%02x%02x%02x',
            (int) max(0, min(255, round($c[0]))),
            (int) max(0, min(255, round($c[1]))),
            (int) max(0, min(255, round($c[2])))
        );
    }

    /** 相对亮度（与 core.js 的 lumOf 同口径） */
    public static function lum(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);
        $lin = static function (float $c): float {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $lin((float) $r) + 0.7152 * $lin((float) $g) + 0.0722 * $lin((float) $b);
    }

    /** a 占 $t、b 占 1-$t */
    public static function mix(string $a, string $b, float $t): string
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);
        return self::hex([
            $r1 * $t + $r2 * (1 - $t),
            $g1 * $t + $g2 * (1 - $t),
            $b1 * $t + $b2 * (1 - $t),
        ]);
    }

    /** 往黑或白推，直到亮度贴近目标 */
    public static function lumTo(string $hex, float $target): string
    {
        $l = self::lum($hex);
        if (abs($l - $target) < 0.015) return $hex;
        $other = $target > $l ? '#ffffff' : '#000000';
        $lo = 1.0;
        $hi = 0.0;
        for ($i = 0; $i < 18; $i++) {
            $t = ($lo + $hi) / 2;
            $v = self::lum(self::mix($hex, $other, $t));
            if (($target > $l && $v < $target) || ($target < $l && $v > $target)) $lo = $t;
            else $hi = $t;
        }
        return self::mix($hex, $other, ($lo + $hi) / 2);
    }

    /**
     * 直接可输出的 <style>：门面页面在 base.css 之后插入即可。
     * 门面页不加载前端模块，所以浅色 / 深色两套都在这儿算好，
     * 变量口径与 public/assets/js/core.js 的 paintAccent 保持一致。
     */
    public static function style(): string
    {
        $accent = self::accentOf(self::presetId());
        $text = self::textOn($accent);
        $soft = self::mix($accent, '#ffffff', 0.13);
        // ink 只会往深里走：主色本身够暗就别提亮，浅色主色按亮度压下去
        $ink = self::lumTo($accent, min(self::lum($accent) * 0.55, 0.20));
        $line = self::mix($accent, $soft, 0.35);
        $page = self::mix($accent, '#ffffff', 0.07);

        $dAccent = self::lumTo($accent, 0.30);
        $dText = self::textOn($dAccent);
        $dSoft = self::mix($dAccent, '#1b222b', 0.18);
        $dInk = self::lumTo($accent, 0.72);
        $dLine = self::mix($dAccent, '#1b222b', 0.5);

        $vars = static function (string $a, string $t, string $i, string $s, string $l) : string {
            return '--accent:' . $a . ';--accent-text:' . $t . ';--accent-ink:' . $i
                . ';--accent-soft:' . $s . ';--accent-line:' . $l
                . ';--self-bubble:' . $a . ';--self-bubble-text:' . $t;
        };
        $light = $vars($accent, $text, $ink, $soft, $line) . ';--page-bg:' . $page;
        $dark = $vars($dAccent, $dText, $dInk, $dSoft, $dLine);
        return '<style>:root{' . $light . '}'
            . '@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){' . $dark . '}}'
            . ':root[data-theme="dark"]{' . $dark . '}</style>';
    }
}
