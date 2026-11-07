<?php
declare(strict_types=1);

namespace LightGroup\Web;

/**
 * 站内统一的底部声明与「关于」弹层。
 * 项目说明只出现在这两处，别的地方不再重复版本号与项目名。
 */
final class Footer
{
    public const PROJECT = 'LightGroup';
    public const LICENSE = 'Apache-2.0';
    public const REPO = 'https://github.com/yyswys-yjyj/LightGroup';

    public static function version(): string
    {
        $lock = function_exists('lg_lock') ? lg_lock() : [];
        $v = (string) ($lock['version'] ?? '');
        return $v !== '' ? $v : '0.1.0';
    }

    /** 本次请求耗时（毫秒），对齐 Gitea 底栏的「页面 Xms」 */
    public static function elapsedMs(): int
    {
        $start = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        return (int) max(0, (int) round((microtime(true) - $start) * 1000));
    }

    /** 底部常驻声明，站内所有页面共用这一条 */
    public static function render(): string
    {
        return '<footer class="site-foot">'
            . '<span class="foot-drive">由 <b>' . self::PROJECT . '</b> 强力驱动</span>'
            . '<span class="foot-dot">·</span>'
            . '<span>版本: ' . e(self::version()) . '</span>'
            . '<span class="foot-dot">·</span>'
            . '<span>页面: ' . self::elapsedMs() . 'ms</span>'
            . '<span class="foot-dot">·</span>'
            . '<button type="button" class="foot-link" data-about-open>关于</button>'
            . '</footer>';
    }

    /** 「关于」弹层：站点信息与站点声明在前，项目声明在后 */
    public static function aboutHtml(array $site): string
    {
        $name = (string) ($site['name'] ?? self::PROJECT);
        $intro = trim((string) ($site['intro'] ?? ''));
        if ($intro === '') $intro = '一个自建的群，人和 AI 都在这里。';
        $stats = $site['stats'] ?? ['scopes' => 0, 'members' => 0, 'messages' => 0];
        $copy = trim((string) ($site['copyright'] ?? ''));
        if ($copy === '') $copy = '© ' . date('Y') . ' ' . $name;

        $line = function (string $k, int $v): string {
            return '<li><b>' . $v . '</b><span>' . e($k) . '</span></li>';
        };

        return '<div class="about" data-about hidden>'
            . '<div class="about-panel" role="dialog" aria-modal="true" aria-label="关于">'
            . '<button type="button" class="about-close" data-about-close aria-label="关闭"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>'
            . '<div class="about-site">'
            . '<div class="about-brand">' . Brand::icon($site, 'about-mark') . '<b>' . e($name) . '</b></div>'
            . ($intro !== '' ? '<p class="about-intro">' . e($intro) . '</p>' : '')
            . '<ul class="about-stats">'
            . $line('群组', (int) ($stats['scopes'] ?? 0))
            . $line('成员', (int) ($stats['members'] ?? 0))
            . '</ul>'
            . '<p class="about-copy">' . e($copy) . '</p>'
            . '</div>'
            . '<div class="about-proj">'
            . '<p class="about-proj-name">' . self::PROJECT . '</p>'
            . '<p>一个开源的人和 AI 聊天群：代码公开，谁都能自己搭一个；消息只存在你自己的机器上，不会跑到别人的服务器去。</p>'
            . '<p class="about-license">以 ' . self::LICENSE . ' 许可开源 · <a href="' . self::REPO . '" target="_blank" rel="noopener">github.com/yyswys-yjyj/LightGroup</a></p>'
            . '</div>'
            . '</div>'
            . '</div>';
    }
}
