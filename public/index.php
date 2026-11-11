<?php
declare(strict_types=1);

// 生产环境不要把 PHP 错误打进响应体：接口返回的是 JSON，混进去一条 warning
// 前端 r.json() 就会整个解析失败，用户看到的是「Unexpected token '<'」。
// 需要看错误时设环境变量 LG_DEBUG=1。
if (!getenv("LG_DEBUG")) {
    @ini_set("display_errors", "0");
}

require dirname(__DIR__) . "/src/bootstrap.php";

use LightGroup\Api\Captcha;
use LightGroup\Api\Entry;
use LightGroup\Api\Session;
use LightGroup\Store\Db;
use LightGroup\Web\Login;
use LightGroup\Web\Setup;
use LightGroup\Web\Site;
use LightGroup\Web\Url;
use LightGroup\Web\View;

// 全站唯一入口。所有页面与接口都从这里分发，路由上没有 .php。
$path = (string) parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
$path = "/" . trim($path, "/");
if ($path === "/") {
    $path = "/";
}

// 静态文件兜底：web server 没命中时不要落到页面路由
if (preg_match('#^/(assets|vendor)/#', $path)) {
    if (is_file(PUBLIC_ROOT . $path)) {
        return false;
    }
    http_response_code(404);
    header("Content-Type: text/plain; charset=utf-8");
    echo "404";
    exit;
}

// Operit 兼容层：插件把 base 配成 https://host/g/，后面直接拼 api/me
if ($path === "/g" || str_starts_with($path, "/g/")) {
    Entry::handle();
    exit;
}

// 未安装：/ 与 /setup 都进安装向导，其余网页入口先送回安装向导
if (!lg_installed()) {
    if ($path === "/" || $path === "/setup") {
        Setup::handle();
        exit;
    }
    if ($path === "/login" || $path === "/logout" || $path === "/app" || $path === "/admin") {
        header("Location: " . Url::to("/setup"), true, 302);
        exit;
    }
    View::render("notfound", ["site" => Site::info(), "path" => $path], 404);
    exit;
}

if ($path === "/setup") {
    View::render("notfound", ["site" => Site::info(), "path" => $path], 404);
    exit;
}

if ($path === "/") {
    View::render("welcome", ["site" => Site::info()]);
    exit;
}

// 内建人机验证：登录页加载时来领一条挑战
if ($path === "/captcha/start") {
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    echo json_encode(Captcha::start(), JSON_UNESCAPED_UNICODE);
    exit;
}

// 登录态只认 cookie session：/login、/logout、/app 都不看查询参数里的 token
if ($path === "/login") {
    Login::handle(Site::info());
    exit;
}

if ($path === "/logout") {
    lg_session();
    Session::logout();
    header("Location: " . Url::to("/"), true, 302);
    exit;
}

if ($path === "/app") {
    lg_session();
    $pdo = Db::connect();
    $me = Session::member($pdo);
    if ($me === null || !empty($me["deleted_at"]) || (int) $me["banned"] === 1) {
        if ($me !== null) Session::logout();
        header("Location: " . Url::to("/login?next=" . rawurlencode(Url::to("/app"))), true, 302);
        exit;
    }
    // 页面里的接口地址跟着成员所在的群走（默认群的 slug 是空串）
    $base = "/g/";
    $st = $pdo->prepare("SELECT slug FROM scopes WHERE id = ? LIMIT 1");
    $st->execute([(int) $me["scope_id"]]);
    $slug = (string) ($st->fetchColumn() ?: "");
    if ($slug !== "") $base = "/g/" . $slug . "/";
    View::render("app-shell", [
        "site"   => Site::info(),
        "base"   => $base,
        // theme 要带上：首屏配色按本人主题直接输出，不然先闪一下站点默认色
        "bootMe" => [
            "id"   => (int) $me["id"],
            "name" => (string) $me["name"],
            "kind" => (string) $me["kind"],
            "theme" => (string) ($me["theme"] ?? ""),
        ],
    ]);
    exit;
}

if ($path === "/admin") {
    View::render("admin", ["site" => Site::info()]);
    exit;
}

// OAuth 登录：发起授权、第三方回调，以及门禁登录后挑/建业务账号的 /oauth/accounts
if ($path === "/oauth/start" || $path === "/oauth/callback" || $path === "/oauth/accounts") {
    \LightGroup\Api\OAuth::handle(Db::connect(), $path);
    exit;
}

View::render("notfound", ["site" => Site::info(), "path" => $path], 404);
