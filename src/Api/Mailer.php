<?php
declare(strict_types=1);

namespace LightGroup\Api;

use PDO;

/**
 * 发邮件：直接用 socket 说 SMTP，不引第三方库（插件运行环境没有 composer）。
 * 配置都在 settings 表里，后台「邮箱」页写：
 *   mail.enabled   总开关（超管打开才发信）
 *   mail.notify    站内信邮件提醒（用户订阅后收到站内信才发）
 *   mail.host/port/secure/user/pass/from/from_name
 * secure: ssl（465）| tls（587，STARTTLS）| 空（明文 25）
 */
final class Mailer {
    public static function configured(PDO $pdo): bool {
        return self::get($pdo, "mail.host") !== ""
            && self::get($pdo, "mail.from") !== ""
            && self::get($pdo, "mail.port") !== "";
    }

    public static function enabled(PDO $pdo): bool {
        return self::get($pdo, "mail.enabled") === "1" && self::configured($pdo);
    }

    public static function notifyOn(PDO $pdo): bool {
        return self::enabled($pdo) && self::get($pdo, "mail.notify") === "1";
    }

    /** 异步发送：请求已经返回给用户，别让 SMTP 卡着页面 */
    public static function sendLater(PDO $pdo, string $to, string $subject, string $html): void {
        if (!self::notifyOn($pdo)) return;
        $cfg = self::config($pdo);
        $payload = [$cfg, $to, $subject, $html];
        register_shutdown_function(function () use ($payload) {
            if (function_exists("fastcgi_finish_request")) @fastcgi_finish_request();
            try {
                self::deliver($payload[0], $payload[1], $payload[2], $payload[3]);
            } catch (\Throwable $e) {
                error_log("mail failed: " . $e->getMessage());
            }
        });
    }

    public static function sendNow(PDO $pdo, string $to, string $subject, string $html): void {
        self::deliver(self::config($pdo), $to, $subject, $html);
    }

    private static function config(PDO $pdo): array {
        return [
            "host" => self::get($pdo, "mail.host"),
            "port" => (int) (self::get($pdo, "mail.port") ?: "25"),
            "secure" => self::get($pdo, "mail.secure"),
            "user" => self::get($pdo, "mail.user"),
            "pass" => self::get($pdo, "mail.pass"),
            "from" => self::get($pdo, "mail.from"),
            "name" => self::get($pdo, "mail.from_name") ?: "LightGroup",
        ];
    }

    private static function get(PDO $pdo, string $key): string {
        try {
            $st = $pdo->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v === false ? "" : (string) $v;
        } catch (\Throwable $e) {
            return "";
        }
    }

    private static function deliver(array $cfg, string $to, string $subject, string $html): void {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException("收件地址不对");
        $host = $cfg["host"];
        $port = $cfg["port"] > 0 ? $cfg["port"] : 25;
        $secure = strtolower((string) $cfg["secure"]);
        $prefix = $secure === "ssl" ? "ssl://" : "";
        $fp = @stream_socket_client($prefix . $host . ":" . $port, $errno, $errstr, 15);
        if (!$fp) throw new \RuntimeException("连不上邮件服务器：" . $errstr);
        stream_set_timeout($fp, 15);

        $read = function () use ($fp): string {
            $out = "";
            while (($line = fgets($fp, 1024)) !== false) {
                $out .= $line;
                // 多行响应以 "250-" 继续，最后一行是 "250 "
                if (strlen($line) < 4 || $line[3] !== "-") break;
            }
            return $out;
        };
        $cmd = function (string $line, string $expect) use ($fp, $read): string {
            fwrite($fp, $line . "\r\n");
            $resp = $read();
            $code = substr($resp, 0, 3);
            if ($code !== $expect) throw new \RuntimeException("邮件服务器拒绝：" . trim($resp));
            return $resp;
        };

        $read(); // 220
        $hostname = "localhost";
        $cmd("EHLO " . $hostname, "250");
        if ($secure === "tls") {
            $cmd("STARTTLS", "220");
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException("STARTTLS 握手失败");
            }
            $cmd("EHLO " . $hostname, "250");
        }
        if ($cfg["user"] !== "") {
            $cmd("AUTH LOGIN", "334");
            $cmd(base64_encode($cfg["user"]), "334");
            $cmd(base64_encode((string) $cfg["pass"]), "235");
        }
        $from = (string) $cfg["from"];
        $cmd("MAIL FROM:<" . $from . ">", "250");
        $cmd("RCPT TO:<" . $to . ">", "250");
        $cmd("DATA", "354");

        $fromName = self::mime($cfg["name"]);
        $body = "From: " . $fromName . " <" . $from . ">\r\n"
            . "To: <" . $to . ">\r\n"
            . "Subject: " . self::mime($subject) . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Date: " . date("r") . "\r\n"
            . "\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n");
        fwrite($fp, $body . ".\r\n");
        $resp = $read();
        if (substr($resp, 0, 3) !== "250") throw new \RuntimeException("邮件正文被拒：" . trim($resp));
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
    }

    private static function mime(string $text): string {
        return "=?UTF-8?B?" . base64_encode($text) . "?=";
    }
}
