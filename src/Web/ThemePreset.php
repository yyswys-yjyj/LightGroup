<?php
declare(strict_types=1);

namespace LightGroup\Web;

use LightGroup\Store\Db;
use Throwable;

/**
 * 站点默认主题（settings.theme.preset）到整套配色变量的推导。
 *
 * 色值必须和 public/assets/js/core.js 里的 THEMES 保持一致（改一边记得同步另一边），
 * 主题包（p_N）的色值从 themes 表读，所以后台新建的共享主题包也能拿来当站点默认主题。
 * 门面页面（首页 / 登录页 / 404）不加载前端模块，只能靠这里把第一眼配色定下来。
 *
 * 【调色引擎】整站颜色一律由主色推导，公式与 core.js 的 themePalette() 逐行同款：
 *   1) 主色转 HSL；饱和度 < 0.05 的近白 / 近灰主色按中性色处理（色相取 214），
 *      否则通透白、米白、月白会因为色相是 0 把整站染成红色系。
 *   2) 染色强度 st = clamp(s, 0.32, 0.88)，近灰主色也取 0.32 的底强度，不回到一片白。
 *   3) 从页面底到描边铺一条同一个饱和度的色阶：明度决定层级，色相 / 饱和度跟着主色走。
 *   4) 强调文字 --accent-ink 同色相压深，直到在面板底色上对比 >= 4.5；
 *      主色本身极浅时只压 ink，--accent 保留原值供填充用。
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

    /** 近白 / 近灰主色的色相没有意义，统一按中性色算 */
    private const NEUTRAL_HUE = 214.0;

    /** @var list<string> 浅色模式要输出的变量（clearAccent 也要按这份清单清） */
    public const LIGHT_VARS = [
        '--page-bg', '--bg', '--surface', '--surface-2', '--surface-3',
        '--border', '--border-strong', '--accent', '--accent-text', '--accent-ink',
        '--accent-soft', '--accent-line', '--text', '--text-dim', '--text-faint',
        '--bubble', '--bubble-text', '--self-bubble', '--self-bubble-text',
    ];

    /** @var list<string> 深色模式专属变量 */
    public const DARK_VARS = [
        '--dk-bg', '--dk-surface', '--dk-surface-2', '--dk-surface-3',
        '--dk-border', '--dk-border-strong', '--dk-text', '--dk-text-dim',
        '--dk-text-faint', '--dk-accent', '--dk-accent-text',
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

    /** 主题 id => 主色；主题包读 theme_packs.vars 里的 accent，读不到就回退湖蓝 */
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

    /** 亮底配深字、暗底配浅字：在两个候选里挑对比更高的那个（与 core.js 的 contrastText 同规则） */
    public static function textOn(string $hex): string
    {
        if (!preg_match('/^#?([0-9a-fA-F]{6})$/', $hex)) return "#ffffff";
        return self::contrast($hex, '#111827') >= self::contrast($hex, '#ffffff') ? '#111827' : '#ffffff';
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

    /** 对比度（WCAG） */
    private static function contrast(string $a, string $b): float
    {
        $x = self::lum($a) + 0.05;
        $y = self::lum($b) + 0.05;
        return $x > $y ? $x / $y : $y / $x;
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

    /** @return array{float,float,float} [h(0-360), s(0-1), l(0-1)]，近灰主色 h 取 NEUTRAL_HUE */
    private static function rgbHsl(string $hex): array
    {
        [$r, $g, $b] = self::rgb($hex);
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $mx = max($r, $g, $b);
        $mn = min($r, $g, $b);
        $l = ($mx + $mn) / 2;
        $d = $mx - $mn;
        $h = 0.0;
        $s = 0.0;
        if ($d > 0.0001) {
            $s = $l > 0.5 ? $d / (2 - $mx - $mn) : $d / ($mx + $mn);
            if ($mx === $r) $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
            elseif ($mx === $g) $h = ($b - $r) / $d + 2;
            else $h = ($r - $g) / $d + 4;
            $h *= 60;
        }
        if ($s < 0.05) {
            $h = self::NEUTRAL_HUE;
            $s = 0.0;
        }
        return [$h, $s, $l];
    }

    /** HSL -> #rrggbb（输出只留小写 hex，老 WebView 也认） */
    private static function hslHex(float $h, float $s, float $l): string
    {
        $hh = fmod(fmod($h, 360.0) + 360.0, 360.0) / 360.0;
        $ss = max(0.0, min(1.0, $s));
        $ll = max(0.0, min(1.0, $l));
        if ($ss === 0.0) {
            $v = round($ll * 255);
            return self::hex([$v, $v, $v]);
        }
        $q = $ll < 0.5 ? $ll * (1 + $ss) : $ll + $ss - $ll * $ss;
        $p = 2 * $ll - $q;
        $ch = static function (float $t) use ($p, $q): float {
            $x = $t;
            if ($x < 0) $x += 1;
            if ($x > 1) $x -= 1;
            if ($x < 1 / 6) return $p + ($q - $p) * 6 * $x;
            if ($x < 1 / 2) return $q;
            if ($x < 2 / 3) return $p + ($q - $p) * (2 / 3 - $x) * 6;
            return $p;
        };
        return self::hex([$ch($hh + 1 / 3) * 255, $ch($hh) * 255, $ch($hh - 1 / 3) * 255]);
    }

    /** 染色强度：主色越艳整站越带色；近灰主色也留一档底强度 */
    public static function tintStrength(float $s): float
    {
        if ($s < 0.05) return 0.32;
        return max(0.32, min(0.88, $s));
    }

    /** 强调文字：同色相压深到面板底色上对比 >= 4.5，浅主色只压 ink 不动 --accent */
    private static function accentInk(float $h, float $st, string $surface, float $srcL): string
    {
        $sat = max(0.12, min(0.72, $st * 0.80));
        $top = max(0.04, min($srcL, 0.45));
        $first = self::hslHex($h, $sat, $top);
        if (self::contrast($first, $surface) >= 4.5) return $first;
        $lo = 0.04;
        $hi = $top;
        for ($i = 0; $i < 20; $i++) {
            $mid = ($lo + $hi) / 2;
            if (self::contrast(self::hslHex($h, $sat, $mid), $surface) >= 4.5) $lo = $mid;
            else $hi = $mid;
        }
        return self::hslHex($h, $sat, $lo);
    }

    /**
     * 主色 -> 全套变量。返回 ['light' => 19 个浅色变量, 'dark' => 19 个同名深色取值 + 11 个 --dk-*]。
     * 与 core.js 的 themePalette() 必须逐位一致。
     *
     * @return array{light: array<string,string>, dark: array<string,string>}
     */
    public static function palette(string $accent, string $accentText = ''): array
    {
        $src = self::hex(self::rgb($accent));
        [$h, $s, $l] = self::rgbHsl($src);
        $st = self::tintStrength($s);
        $surface = self::hslHex($h, $st, 0.982);
        $pageBg = self::hslHex($h, $st, 0.951);
        $surface2 = self::hslHex($h, $st, 0.963);
        $text = self::hslHex($h, $st * 0.30, 0.16);
        if (self::contrast($text, $surface) < 4.5) $text = '#14171c';
        $given = '';
        if ($accentText !== '' && preg_match('/^#?[0-9a-fA-F]{6}$/', $accentText)) {
            $given = self::hex(self::rgb($accentText));
        }
        $tc = ($given !== '' && self::contrast($given, $src) >= 3.0) ? $given : self::textOn($src);

        $dkAccent = self::hslHex($h, max(0.30, min(0.72, $st * 0.75)), 0.60);
        $dkAccentText = self::textOn($dkAccent);
        $dkBg = self::hslHex($h, $st * 0.27, 0.070);
        $dkSurface = self::hslHex($h, $st * 0.34, 0.110);
        $dkSurface2 = self::hslHex($h, $st * 0.36, 0.145);
        $dkSurface3 = self::hslHex($h, $st * 0.34, 0.180);
        $dkBorder = self::hslHex($h, $st * 0.30, 0.245);
        $dkBorderStrong = self::hslHex($h, $st * 0.32, 0.345);
        $dkText = self::hslHex($h, $st * 0.12, 0.93);
        $dkTextDim = self::hslHex($h, $st * 0.12, 0.72);
        $dkTextFaint = self::hslHex($h, $st * 0.12, 0.58);

        $light = [
            '--page-bg' => $pageBg,
            '--bg' => $pageBg,
            '--surface' => $surface,
            '--surface-2' => $surface2,
            '--surface-3' => self::hslHex($h, $st, 0.940),
            '--border' => self::hslHex($h, $st * 0.86, 0.885),
            '--border-strong' => self::hslHex($h, $st * 0.88, 0.80),
            '--accent' => $src,
            '--accent-text' => $tc,
            '--accent-ink' => self::accentInk($h, $st, $surface, $l),
            '--accent-soft' => self::hslHex($h, $st * 0.55, 0.94),
            '--accent-line' => self::hslHex($h, $st * 0.60, 0.845),
            '--text' => $text,
            '--text-dim' => self::hslHex($h, $st * 0.30, 0.42),
            '--text-faint' => self::hslHex($h, $st * 0.30, 0.58),
            '--bubble' => $surface2,
            '--bubble-text' => $text,
            '--self-bubble' => $src,
            '--self-bubble-text' => $tc,
        ];
        $dark = [
            '--page-bg' => $dkBg,
            '--bg' => $dkBg,
            '--surface' => $dkSurface,
            '--surface-2' => $dkSurface2,
            '--surface-3' => $dkSurface3,
            '--border' => $dkBorder,
            '--border-strong' => $dkBorderStrong,
            '--accent' => $dkAccent,
            '--accent-text' => $dkAccentText,
            '--accent-ink' => self::hslHex($h, $st * 0.55, 0.78),
            '--accent-soft' => self::hslHex($h, $st * 0.40, 0.20),
            '--accent-line' => self::hslHex($h, $st * 0.40, 0.30),
            '--text' => $dkText,
            '--text-dim' => $dkTextDim,
            '--text-faint' => $dkTextFaint,
            '--bubble' => $dkSurface2,
            '--bubble-text' => $dkText,
            '--self-bubble' => $dkAccent,
            '--self-bubble-text' => $dkAccentText,
            '--dk-bg' => $dkBg,
            '--dk-surface' => $dkSurface,
            '--dk-surface-2' => $dkSurface2,
            '--dk-surface-3' => $dkSurface3,
            '--dk-border' => $dkBorder,
            '--dk-border-strong' => $dkBorderStrong,
            '--dk-text' => $dkText,
            '--dk-text-dim' => $dkTextDim,
            '--dk-text-faint' => $dkTextFaint,
            '--dk-accent' => $dkAccent,
            '--dk-accent-text' => $dkAccentText,
        ];
        return ['light' => $light, 'dark' => $dark];
    }

    /** @param array<string,string> $vars */
    private static function css(array $vars): string
    {
        $out = [];
        foreach ($vars as $k => $v) $out[] = $k . ':' . $v;
        return implode(';', $out);
    }

    /** 浅色模式下的页面底色（与 style() 同口径） */
    public static function pageBg(?string $choice = null): string
    {
        $id = $choice === null ? self::presetId() : self::pick($choice);
        return self::palette(self::accentOf($id))['light']['--page-bg'];
    }

    /**
     * 只画 html 的底色：插在 base.css 之前，样式表还没到的这几十毫秒
     * 也不会先露一块浏览器白底，再被主题色盖回来。
     */
    public static function firstPaint(?string $choice = null): string
    {
        $id = $choice === null ? self::presetId() : self::pick($choice);
        $pal = self::palette(self::accentOf($id));
        // 显式选了深色就别等 base.css：先把深底铺上，否则深色用户会先看到一帧亮底
        if (strtok((string) $choice, '-') === 'dark') {
            return '<style>html{background:' . $pal['dark']['--dk-bg'] . '}</style>';
        }
        return '<style>html{background:' . $pal['light']['--page-bg'] . '}'
            . '@media (prefers-color-scheme:dark){html:not([data-theme="light"]){background:' . $pal['dark']['--dk-bg'] . '}}'
            . '</style>';
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
        $pal = self::palette(self::accentOf($id));
        $light = self::css($pal['light']);
        $dark = self::css($pal['dark']);
        // 本人明确选了深色：直接把深色算成默认，别等前端加 data-theme 才变
        if (strtok((string) $choice, '-') === 'dark') {
            return '<style>:root{' . $dark . '}:root[data-theme="light"]{' . $light . '}</style>';
        }
        return '<style>:root{' . $light . '}'
            . '@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){' . $dark . '}}'
            . ':root[data-theme="dark"]{' . $dark . '}</style>';
    }
}
