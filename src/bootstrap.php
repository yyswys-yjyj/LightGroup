<?php
declare(strict_types=1);

define("APP_ROOT", dirname(__DIR__));
define("PUBLIC_ROOT", APP_ROOT . "/public");

spl_autoload_register(function (string $class): void {
    $prefix = "LightGroup\\";
    if (!str_starts_with($class, $prefix)) return;
    $rel = str_replace("\\", "/", substr($class, strlen($prefix)));
    $file = APP_ROOT . "/src/" . $rel . ".php";
    if (is_file($file)) require $file;
});

function lg_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $file = APP_ROOT . "/config.php";
        $cfg = is_file($file) ? (require $file) : [];
        if (!is_array($cfg)) $cfg = [];
        $cfg += [
            "driver" => "sqlite",
            "path"   => APP_ROOT . "/data/lightgroup.sqlite",
            "secret" => "",
        ];
    }
    return $cfg;
}

function lg_installed(): bool {
    return is_file(APP_ROOT . "/.lock");
}

function lg_lock(): array {
    static $lock = null;
    if ($lock === null) {
        $raw = @file_get_contents(APP_ROOT . "/.lock");
        $lock = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    return $lock;
}

/**
 * Web 会话：浏览器登录态。插件 / AI / 第三方客户端仍然走 token，
 * 这里只服务网页端。
 */
function lg_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (headers_sent()) return;
    session_name("lgb");
    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/",
        "httponly" => true,
        "samesite" => "Lax",
        "secure"   => (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off"),
    ]);
    @session_start();
}

/** 释放会话锁：长轮询 / 慢请求之前必须调用，否则同一浏览器的请求会被串行化 */
function lg_session_release(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function e(?string $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function lg_now(): int {
    return (int) floor(microtime(true) * 1000);
}

/** 给自助进群的人发一个网页登录密码；去掉容易看错的 0/o/1/l */
function lg_pass(int $len = 10): string {
    $abc = "abcdefghijkmnpqrstuvwxyz23456789";
    $out = "";
    $max = strlen($abc) - 1;
    for ($i = 0; $i < $len; $i++) {
        $out .= $abc[random_int(0, $max)];
    }
    return $out;
}