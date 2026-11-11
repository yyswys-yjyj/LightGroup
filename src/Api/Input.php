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

    /**
     * 统一入口清洗：去掉 NUL 与不可见控制字符（保留换行、回车、制表）。
     * 换行要留：消息和 CSS 都是多行的。只清不可见的那些，
     * 免得它们混进 HTML、日志和数据库里。
     */
    private static function clean(string $s): string {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', "", $s) ?? $s;
    }

    public static function get(string $key, string $default = ""): string {
        $b = self::body();
        if (isset($b[$key]) && is_scalar($b[$key])) return trim(self::clean((string) $b[$key]));
        if (isset($_GET[$key]) && is_scalar($_GET[$key])) return trim(self::clean((string) $_GET[$key]));
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