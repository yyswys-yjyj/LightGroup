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

    /**
     * 内建主题的【浅底文字色, 页面底色】，与 core.js 的 THEME_EXTRA 一一对应。
     * 门面页只能靠这里把首屏配色定成和进站后一致，少一帧跳色。
     * @var array<string,array{0:string,1:string}>
     */
    public const EXTRA = [
        'dadi' => ['#7f1d1d', '#fbeceb'],
        'zhuanhong' => ['#7c2d12', '#fdeee6'],
        'shuguang' => ['#7c2d22', '#fcf2f0'],
        'daoxiang' => ['#9a3412', '#fdefe2'],
        'nuanyangcheng' => ['#7d4a06', '#fdf5e6'],
        'kafei' => ['#422006', '#f6efe3'],
        'naicha' => ['#6b4a2a', '#f8f3ec'],
        'xingse' => ['#7a4a1f', '#fbf4ec'],
        'shechijin' => ['#6b4f0a', '#fbf5e4'],
        'luori' => ['#713f12', '#fdf6da'],
        'dianjihuang' => ['#5b4306', '#faf7ec'],
        'mocha' => ['#3f6212', '#f3f9e0'],
        'xiaocao' => ['#14532d', '#e9f8ed'],
        'songlv' => ['#14532d', '#edf8e4'],
        'danbohe' => ['#0f5c4a', '#ecfbf5'],
        'qingbi' => ['#134e4a', '#e5f7f4'],
        'yunqing' => ['#155e75', '#e5f6fa'],
        'minghuanqing' => ['#0e5c6b', '#f1fbfd'],
        'dailan' => ['#334867', '#f7faff'],
        'tianqing' => ['#0c4a6e', '#e6f1fb'],
        'wumailan' => ['#3f4f5e', '#f3f7f9'],
        'hulan' => ['#1e3a8a', '#e8f0fd'],
        'xuanfenglan' => ['#1e3a8a', '#eff4ff'],
        'menghuanlan' => ['#33407a', '#f5f8ff'],
        'beijixing' => ['#22307a', '#f1f4fb'],
        'shenkongming' => ['#28305a', '#f4f6fc'],
        'ziluolan' => ['#4c1d95', '#f1ebfd'],
        'dingxiangzi' => ['#55409b', '#f7f4ff'],
        'huopo' => ['#701a75', '#fcf0fd'],
        'mengdong' => ['#831843', '#fdebf5'],
        'daifen' => ['#6b3b52', '#fff7fb'],
        'caixiafen' => ['#9d174d', '#fff5fa'],
        'yinghuafen' => ['#7a3352', '#fffafd'],
        'mitao' => ['#7c3b2e', '#fffaf8'],
        'naihuang' => ['#78530a', '#fffefa'],
        'bohenai' => ['#14543f', '#f9fffc'],
        'yunduolan' => ['#1d4f66', '#fafdff'],
        'yuni' => ['#4c3a86', '#fcfbff'],
        'oufen' => ['#6b4451', '#fffbfc'],
        'tongtou' => ['#334155', '#f6f7f9'],
        'mibai' => ['#4a453c', '#f9f6ef'],
        'yuebai' => ['#455468', '#fcfdff'],
        'shiban' => ['#1e293b', '#edf1f6'],
        'yanhui' => ['#334155', '#eff1f4'],
        'menglonghui' => ['#3d4451', '#f5f7f9'],
        'didiao' => ['#18181b', '#f2f3f4'],
        'gaoyahei' => ['#3a3a42', '#f6f6f8'],
        'wuye' => ['#39445e', '#f4f7fb'],
    ];

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
     * 把 members.theme 里那串「模式-主题」解成主题 id；解不出就用站点默认。
     * 老数据只存了主题 id，也认。
     */
    public static function pick(string $choice): string
    {
        $s = trim($choice);
        if ($s === '') return self::presetId();
        $modes = ['auto', 'light', 'dark'];
        if (in_array($s, $modes, true)) return self::presetId();
        $i = strpos($s, '-');
        if ($i !== false && in_array(substr($s, 0, $i), $modes, true)) $s = substr($s, $i + 1);
        if ($s === '') return self::presetId();
        if (isset(self::ACCENT[$s]) || str_starts_with($s, 'p')) return $s;
        return self::presetId();
    }

    /**
     * 直接可输出的 <style>：门面页面在 base.css 之后插入即可。
     * 门面页不加载前端模块，所以浅色 / 深色两套都在这儿算好，
     * 变量口径与 public/assets/js/core.js 的 paintAccent 保持一致。
     */
    public static function style(?string $choice = null): string
    {
        // 有个人主题就按个人的算：首帧就是用户选的那套，不用等接口回来再换
        $id = $choice === null ? self::presetId() : self::pick($choice);
        $accent = self::accentOf($id);
        $text = self::textOn($accent);
        $soft = self::mix($accent, '#ffffff', 0.13);
        $ex = self::EXTRA[$id] ?? [];
        // 内建主题的浅底文字色 / 页面底色跟着前端表走，别再各算一套
        $ink = (string) ($ex[0] ?? '');
        if ($ink === '') $ink = self::lumTo($accent, min(self::lum($accent) * 0.55, 0.20));
        $line = self::mix($accent, $soft, 0.35);
        $page = (string) ($ex[1] ?? '');
        if ($page === '') $page = self::mix($accent, '#ffffff', 0.07);

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
