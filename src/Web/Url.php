<?php
declare(strict_types=1);

namespace LightGroup\Web;

/**
 * 站内路径。
 *
 * 全站只有一个入口 public/index.php，路由由 PHP 解析 REQUEST_URI 分发，
 * 所以路由上不会出现 .php，也不需要两套 URL 模式。
 *
 * 部署方要做的只有一件事：让 web server 把找不到的路径交给 index.php。
 *   nginx  —— location / { try_files $uri $uri/ /index.php?$query_string; }
 *   Apache —— public/.htaccess 已经写好了
 * 这是 Flarum、开放平台都在用的标准写法，不针对任何面板。
 */
final class Url
{
    /** 站内路径原样返回；集中在这里，将来要加子目录部署只改这一处 */
    public static function to(string $path): string
    {
        return $path === "" ? "/" : $path;
    }
}
