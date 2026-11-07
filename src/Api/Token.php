<?php
declare(strict_types=1);

namespace LightGroup\Api;

final class Token {
    public static function issue(): string {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $plain): string {
        return hash("sha256", $plain);
    }
}