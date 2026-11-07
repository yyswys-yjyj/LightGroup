<?php
declare(strict_types=1);

namespace LightGroup\Http;

final class Json {
    public static function out(array $data, int $code = 200): void {
        if (!headers_sent()) {
            http_response_code($code);
            header("Content-Type: application/json; charset=utf-8");
            header("Cache-Control: no-store");
            header("X-Content-Type-Options: nosniff");
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(array $data = []): void {
        self::out(["ok" => true] + $data);
    }

    public static function fail(string $err, int $code = 200): void {
        self::out(["ok" => false, "err" => $err], $code);
    }
}