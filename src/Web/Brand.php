<?php
declare(strict_types=1);

namespace LightGroup\Web;

/**
 * 站点图标输出。页面上所有展示站点图标的地方都走这里：
 * 统一套一层圆角方框蒙版，深色模式换用深色稿。
 */
final class Brand
{
    /** 圆角蒙版里的站点图标；没设置图标时回退到内置的靶心图标 */
    public static function icon(array $site, string $cls = 'brand-mark'): string
    {
        $light = (string) ($site['icon'] ?? '');
        $dark = (string) ($site['icon_dark'] ?? '');
        if ($light === '' && $dark === '') {
            // 没设置图标时的默认稿：和 assets/favicon.svg 同一套双圆，避免品牌位和浏览器标签页长得不一样
            return '<span class="' . e($cls) . '">'
                . '<svg viewBox="0 0 64 64" width="62%" height="62%" fill="none" stroke="currentColor"'
                . ' stroke-width="4.6" stroke-linecap="round" aria-hidden="true">'
                . '<circle cx="32" cy="32" r="15"/><circle cx="32" cy="32" r="6"/></svg></span>';
        }
        if ($light === '') $light = $dark;
        if ($dark === '') $dark = $light;
        $out = '<span class="icon-mask ' . e($cls) . '">';
        $out .= '<img class="i-light" src="' . e($light) . '" alt="">';
        if ($dark !== $light) {
            $out .= '<img class="i-dark" src="' . e($dark) . '" alt="">';
        }
        $out .= '</span>';
        return $out;
    }

    /** <head> 里的图标声明：有站点图标就用图标本身，没设置时才回退到内置稿 */
    public static function heads(array $site): string
    {
        $light = (string) ($site['icon'] ?? '');
        $dark = (string) ($site['icon_dark'] ?? '');
        if ($light === '' && $dark === '') {
            return '<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">';
        }
        // 不再列 favicon.ico：浏览器看到 sizes="any" 的 ico 会优先选它，
        // 而那份 ico 可能是上一次装站时的稿子，看起来就像图标没换。
        $out = '';
        if ($light !== '') {
            $out .= '<link rel="icon" type="image/png" href="' . e($light) . '">';
        }
        if ($dark !== '') {
            $out .= '<link rel="icon" type="image/png" media="(prefers-color-scheme: dark)" href="' . e($dark) . '">';
        }
        return $out;
    }
}
