<?php
declare(strict_types=1);

namespace LightGroup\Web;

final class View
{
    public static function render(string $name, array $data = [], int $status = 200): void
    {
        $file = APP_ROOT . "/src/Web/views/" . $name . ".php";
        if (!is_file($file)) {
            http_response_code(500);
            header("Content-Type: text/plain; charset=utf-8");
            echo "view not found: " . $name;
            return;
        }
        http_response_code($status);
        header("Content-Type: text/html; charset=utf-8");
        extract($data, EXTR_SKIP);
        require $file;
    }
}
