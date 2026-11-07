<?php
declare(strict_types=1);

// 开发服务器路由：php -S 127.0.0.1:8099 bin/router.php
$path = (string) parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
$public = dirname(__DIR__) . "/public";

// 静态文件自己发，避免 php -S 的 docroot 与项目根不一致
if ($path !== "/" && !str_ends_with($path, ".php")) {
    $file = $public . $path;
    if (is_file($file)) {
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $types = [
            "css"   => "text/css; charset=utf-8",
            "js"    => "text/javascript; charset=utf-8",
            "mjs"   => "text/javascript; charset=utf-8",
            "json"  => "application/json; charset=utf-8",
            "svg"   => "image/svg+xml",
            "png"   => "image/png",
            "jpg"   => "image/jpeg",
            "jpeg"  => "image/jpeg",
            "gif"   => "image/gif",
            "webp"  => "image/webp",
            "ico"   => "image/x-icon",
            "woff2" => "font/woff2",
            "woff"  => "font/woff",
            "ttf"   => "font/ttf",
            "map"   => "application/json; charset=utf-8",
            "txt"   => "text/plain; charset=utf-8",
        ];
        header("Content-Type: " . ($types[$ext] ?? "application/octet-stream"));
        header("Content-Length: " . filesize($file));
        header("Cache-Control: max-age=3600");
        readfile($file);
        return true;
    }
}

// 其它一律交给唯一入口，跟生产环境行为一致
require $public . "/index.php";
return true;
