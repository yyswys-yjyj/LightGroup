<?php
declare(strict_types=1);

namespace LightGroup\Api;

use PDO;

/**
 * 成员对外的 id。
 * 库里 members.id 是自增整数，只在服务端内部用；页面、接口、二维码里一律用随机 uid，
 * 这样「按 id 找人」没有规律可循，也不用再区分 h_ / a_ 前缀（类型看 kind 字段）。
 */
final class Ids {
    private const ALPHABET = "0123456789abcdefghijklmnopqrstuvwxyz";

    /** 生成一个库里的没被占用的 uid：10 位 36 进制，约 3.6e15 种 */
    public static function newUid(PDO $pdo): string {
        $st = $pdo->prepare("SELECT 1 FROM members WHERE uid = ? LIMIT 1");
        for ($i = 0; $i < 8; $i++) {
            $uid = self::random(10);
            $st->execute([$uid]);
            if (!$st->fetchColumn()) return $uid;
        }
        return self::random(13);
    }

    /** 从查询出来的成员行取对外 id；老库还没回填时退回整数，至少还能用 */
    public static function of(array $m): string {
        $uid = (string) ($m["uid"] ?? "");
        return $uid !== "" ? $uid : (string) (int) ($m["id"] ?? 0);
    }

    /** 把外部传进来的 id（uid、纯数字，或旧的 h_/a_ 写法）解成 members.id */
    public static function resolve(PDO $pdo, string $raw, int $scopeId = 0): int {
        $raw = trim($raw);
        if ($raw === "") return 0;
        if (preg_match('/^[ha]_(\\d+)$/', $raw, $mm)) return (int) $mm[1];
        if (preg_match('/^\\d+$/', $raw)) return (int) $raw;
        $sql = "SELECT id FROM members WHERE uid = ?" . ($scopeId > 0 ? " AND scope_id = ?" : "") . " LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute($scopeId > 0 ? [$raw, $scopeId] : [$raw]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    private static function random(int $len): string {
        $out = "";
        for ($i = 0; $i < $len; $i++) {
            $out .= self::ALPHABET[random_int(0, 35)];
        }
        return $out;
    }
}
