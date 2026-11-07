<?php
declare(strict_types=1);

namespace LightGroup\Api;

use PDO;

/**
 * 登录失败节流：同一个键（IP 或 昵称）5 分钟内错 5 次，锁 5 分钟。
 * 计数落 login_fails 表，重启和并发都不会把计数丢掉。
 */
final class Throttle {
    /** 统计窗口（毫秒） */
    public const WINDOW = 300000;
    /** 窗口内允许的失败次数 */
    public const LIMIT = 5;
    /** 触发后的锁定时长（毫秒） */
    public const LOCK = 300000;

    /** 还能不能试：返回 null 表示可以，返回整数是要等的秒数 */
    public static function wait(PDO $pdo, string $key, int $limit = self::LIMIT): ?int {
        $st = $pdo->prepare("SELECT n, last_at FROM login_fails WHERE k = ? LIMIT 1");
        $st->execute([$key]);
        $row = $st->fetch();
        if (!$row) return null;
        $now = lg_now();
        if ($now - (int) $row["last_at"] > self::WINDOW) return null;
        if ((int) $row["n"] < $limit) return null;
        $left = (int) ceil(((int) $row["last_at"] + self::LOCK - $now) / 1000);
        return max(1, $left);
    }

    public static function fail(PDO $pdo, string $key): void {
        $now = lg_now();
        $st = $pdo->prepare("SELECT n, first_at FROM login_fails WHERE k = ? LIMIT 1");
        $st->execute([$key]);
        $row = $st->fetch();
        if (!$row) {
            try {
                $pdo->prepare("INSERT INTO login_fails (k, n, first_at, last_at) VALUES (?, 1, ?, ?)")->execute([$key, $now, $now]);
            } catch (\Throwable $e) {
                // 并发下被别人先插了，下一轮走自增
            }
            return;
        }
        if ($now - (int) $row["first_at"] > self::WINDOW) {
            $pdo->prepare("UPDATE login_fails SET n = 1, first_at = ?, last_at = ? WHERE k = ?")->execute([$now, $now, $key]);
            return;
        }
        $pdo->prepare("UPDATE login_fails SET n = n + 1, last_at = ? WHERE k = ?")->execute([$now, $key]);
    }

    public static function clear(PDO $pdo, string $key): void {
        $pdo->prepare("DELETE FROM login_fails WHERE k = ?")->execute([$key]);
    }
}
