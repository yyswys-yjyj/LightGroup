<?php
declare(strict_types=1);

namespace LightGroup\Api;

use PDO;
use LightGroup\Http\Json;

/**
 * 群治理：群封禁、全员禁言、单成员禁言。
 *
 * 群主与管理员不是同一个权限：群主能禁言管理员，管理员只能禁言普通成员；
 * 全员禁言只有群主（大群由超管代管）能开。真正拦发送在 insertMessage 之前。
 */
final class Moderation
{
    /** 大群群主：scopes.owner_id；没设时只有超管算群主 */
    public static function isScopeOwner(PDO $pdo, array $scope, array $m): bool
    {
        if (self::isSuper($pdo, $m)) return true;
        $owner = (int) ($scope["owner_id"] ?? 0);
        return $owner > 0 && $owner === (int) $m["id"];
    }

    public static function isSuper(PDO $pdo, array $m): bool
    {
        return self::accountRole($pdo, $m) === "super";
    }

    /** 大群管理员：账号 role=admin 且同域；群主也算管理员 */
    public static function isScopeAdmin(PDO $pdo, array $scope, array $m): bool
    {
        if (self::isScopeOwner($pdo, $scope, $m)) return true;
        $aid = (int) ($m["account_id"] ?? 0);
        if ($aid <= 0) return false;
        $st = $pdo->prepare("SELECT role, scope_id FROM accounts WHERE id = ? LIMIT 1");
        $st->execute([$aid]);
        $row = $st->fetch();
        if (!$row) return false;
        return (string) $row["role"] === "admin" && (int) $row["scope_id"] === (int) $scope["id"];
    }

    /** 会话里的治理身份：super / owner / admin / member */
    public static function convRole(PDO $pdo, array $scope, array $conv, array $m): string
    {
        if ((int) $conv["is_public"] === 1) {
            if (self::isSuper($pdo, $m)) return "super";
            if (self::isScopeOwner($pdo, $scope, $m)) return "owner";
            if (self::isScopeAdmin($pdo, $scope, $m)) return "admin";
            return "member";
        }
        if ((string) $conv["kind"] === "pg") {
            $r = Conv::roleIn($pdo, (int) $conv["id"], (int) $m["id"]);
            return $r === "" ? "member" : $r;
        }
        return "member";
    }

    public static function canModerate(PDO $pdo, array $scope, array $conv, array $m): bool
    {
        $r = self::convRole($pdo, $scope, $conv, $m);
        return $r === "super" || $r === "owner" || $r === "admin";
    }

    /** 群封禁 */
    public static function scopeBanned(array $scope): bool
    {
        return (int) ($scope["banned"] ?? 0) === 1;
    }

    /**
     * 发送前检查：封禁 → 全员禁言 → 单成员禁言。管理员/群主不受全员禁言限制，
     * 被单成员禁言的管理员仍然不能发（这正是群主禁言管理员的效果）。
     */
    public static function assertCanSend(PDO $pdo, array $scope, array $m, ?array $conv): void
    {
        if (self::scopeBanned($scope)) Json::fail("这个群已经被封禁，暂时不能发消息", 403);

        $now = lg_now();
        $role = $conv === null ? "member" : self::convRole($pdo, $scope, $conv, $m);
        $isMod = $role === "super" || $role === "owner" || $role === "admin";

        $until = (int) ($scope["whole_mute_until"] ?? 0);
        if ($conv !== null && (int) ($conv["whole_mute_until"] ?? 0) > $until) {
            $until = (int) $conv["whole_mute_until"];
        }
        if ($until > $now && !$isMod) Json::fail("群正在全员禁言，暂时不能发消息", 403);

        $mute = (int) ($m["mute_until"] ?? 0);
        if ($mute <= $now && $conv !== null && (string) $conv["kind"] === "pg") {
            $st = $pdo->prepare("SELECT mute_until FROM conv_members WHERE conv_id = ? AND member_id = ? LIMIT 1");
            $st->execute([(int) $conv["id"], (int) $m["id"]]);
            $mute = (int) ($st->fetchColumn() ?: 0);
        }
        if ($mute > $now) Json::fail("你被禁言了，解禁时间：" . self::fmtMs($mute), 403);
    }

    /** 单成员禁言；until <= now 表示解除 */
    public static function mute(PDO $pdo, array $scope, array $conv, int $targetId, int $until, array $by): void
    {
        $byRole = self::convRole($pdo, $scope, $conv, $by);
        if ($byRole !== "super" && $byRole !== "owner" && $byRole !== "admin") Json::fail("没有权限禁言", 403);
        if ($targetId <= 0) Json::fail("要禁言谁");
        if ($targetId === (int) $by["id"]) Json::fail("不能禁言自己");

        $st = $pdo->prepare("SELECT * FROM members WHERE id = ? AND scope_id = ? LIMIT 1");
        $st->execute([$targetId, (int) $scope["id"]]);
        $target = $st->fetch();
        if (!$target || $target["deleted_at"] !== null) Json::fail("成员不存在");

        if ((int) $conv["is_public"] === 1) {
            $tRole = self::convRole($pdo, $scope, $conv, $target);
            if ($tRole === "super" || $tRole === "owner") Json::fail("不能禁言群主", 403);
            if ($tRole === "admin" && $byRole === "admin") Json::fail("管理员不能禁言管理员", 403);
            $pdo->prepare("UPDATE members SET mute_until = ? WHERE id = ?")->execute([$until, $targetId]);
        } else {
            $tRole = Conv::roleIn($pdo, (int) $conv["id"], $targetId);
            if ($tRole === "") Json::fail("这个人不在这个群");
            if ($tRole === "owner") Json::fail("不能禁言群主", 403);
            if ($tRole === "admin" && $byRole === "admin") Json::fail("管理员不能禁言管理员", 403);
            $pdo->prepare("UPDATE conv_members SET mute_until = ? WHERE conv_id = ? AND member_id = ?")
                ->execute([$until, (int) $conv["id"], $targetId]);
        }

        $who = ($byRole === "super" ? "超级管理员" : ($byRole === "owner" ? "群主" : "管理员")) . "「" . (string) $by["name"] . "」";
        $tail = $until > lg_now()
            ? "禁言了「" . (string) $target["name"] . "」，解禁时间 " . self::fmtMs($until)
            : "解除了「" . (string) $target["name"] . "」的禁言";
        Compat::systemMessage($pdo, (int) $scope["id"], (int) $conv["id"], (int) $by["id"], $who . $tail);
    }

    /** 全员禁言；until <= now 表示解除，只有群主/超管能操作 */
    public static function muteAll(PDO $pdo, array $scope, array $conv, int $until, array $by): void
    {
        $byRole = self::convRole($pdo, $scope, $conv, $by);
        if ($byRole !== "super" && $byRole !== "owner") Json::fail("只有群主能开关全员禁言", 403);
        if ((int) $conv["is_public"] === 1) {
            $pdo->prepare("UPDATE scopes SET whole_mute_until = ? WHERE id = ?")->execute([$until, (int) $scope["id"]]);
        } else {
            $pdo->prepare("UPDATE conversations SET whole_mute_until = ? WHERE id = ?")->execute([$until, (int) $conv["id"]]);
        }
        Compat::systemMessage(
            $pdo,
            (int) $scope["id"],
            (int) $conv["id"],
            (int) $by["id"],
            "群主「" . (string) $by["name"] . "」" . ($until > lg_now() ? "开启了全员禁言" : "解除了全员禁言")
        );
    }

    /** 毫秒时间戳按站点时区显示（和审计留痕同一个时区） */
    private static function fmtMs(int $ms): string
    {
        $dt = new \DateTime("now", new \DateTimeZone("Asia/Shanghai"));
        $dt->setTimestamp((int) floor($ms / 1000));
        return $dt->format("Y-m-d H:i");
    }

    private static function accountRole(PDO $pdo, array $m): string
    {
        $aid = (int) ($m["account_id"] ?? 0);
        if ($aid <= 0) return "";
        $st = $pdo->prepare("SELECT role FROM accounts WHERE id = ? LIMIT 1");
        $st->execute([$aid]);
        return (string) ($st->fetchColumn() ?: "");
    }
}
