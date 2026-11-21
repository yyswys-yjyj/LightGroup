<?php
declare(strict_types=1);

namespace LightGroup\Store;

use PDO;

/**
 * 登录名（username）的生成规则。
 *
 * - 人类：默认等于昵称；同域里撞了加序号
 * - AI：主人登录名 + "." + AI 名字；撞了加序号
 *
 * 昵称（name）之后可以随便改，登录名建好就不再变，所以这里只在建号时用一次。
 */
final class MemberNames {
    /** 人类成员的登录名 */
    public static function plain(PDO $pdo, int $scopeId, string $base): string {
        return self::unique($pdo, $scopeId, $base, "user");
    }

    /** AI 成员的登录名：主人登录名.名字 */
    public static function forAi(PDO $pdo, int $scopeId, int $ownerId, string $aiName): string {
        $owner = "";
        try {
            $st = $pdo->prepare("SELECT username, name FROM members WHERE id = ? LIMIT 1");
            $st->execute([$ownerId]);
            $row = $st->fetch();
            if ($row) {
                $owner = trim((string) ($row["username"] ?? ""));
                if ($owner === "") $owner = (string) $row["name"];
            }
        } catch (\Throwable $e) {
        }
        if ($owner === "") $owner = "user";
        $name = trim((string) preg_replace('/\s+/u', " ", $aiName));
        if ($name === "") $name = "ai";
        return self::unique($pdo, $scopeId, $owner . "." . $name, "ai");
    }

    /** 在作用域内找一个没被占用的登录名 */
    private static function unique(PDO $pdo, int $scopeId, string $base, string $fallback): string {
        $base = trim($base);
        if ($base === "") $base = $fallback;
        $base = mb_substr($base, 0, 40);
        for ($i = 1; $i <= 50; $i++) {
            $cand = $i === 1 ? $base : $base . "#" . $i;
            $st = $pdo->prepare("SELECT 1 FROM members WHERE scope_id = ? AND username = ? LIMIT 1");
            $st->execute([$scopeId, $cand]);
            if (!$st->fetchColumn()) return $cand;
        }
        return mb_substr($base, 0, 32) . "#" . bin2hex(random_bytes(3));
    }
}
