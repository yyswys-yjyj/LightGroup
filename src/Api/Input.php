<?php
declare(strict_types=1);

namespace LightGroup\Api;

final class Input {
    private static ?array $body = null;

    public static function body(): array {
        if (self::$body !== null) return self::$body;
        $raw = file_get_contents("php://input");
        $data = [];
        if (is_string($raw) && $raw !== "") {
            $json = json_decode($raw, true);
            if (is_array($json)) $data = $json;
            else {
                parse_str($raw, $form);
                if (is_array($form)) $data = $form;
            }
        }
        self::$body = $data;
        return $data;
    }

    public static function get(string $key, string $default = ""): string {
        $b = self::body();
        if (isset($b[$key]) && is_scalar($b[$key])) return trim((string) $b[$key]);
        if (isset($_GET[$key]) && is_scalar($_GET[$key])) return trim((string) $_GET[$key]);
        return $default;
    }

    public static function int(string $key, int $default = 0): int {
        $v = self::get($key);
        return $v === "" ? $default : (int) $v;
    }

    public static function raw(string $key): array {
        $b = self::body();
        return (isset($b[$key]) && is_array($b[$key])) ? $b[$key] : [];
    }
}