<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Http\Json;
use PDO;

/**
 * 好友关系（friends 表）。
 *
 * 表里是「双向一行」：a_id < b_id 恒成立，status = pending / accepted（也允许 blocked）。
 * requested_by 记录是谁先发的申请：accept 只有被申请的一方可以点，reject 同理。
 * 好友只存在于同一个作用域内，跨 scope 一律看不到对方。
 */
final class Social {
    /** 任意群里的有效成员（超管跨群加好友时用） */
    private static function anyLive(PDO $pdo, int $id): ?array {
        if ($id <= 0) return null;
        $st = $pdo->prepare("SELECT * FROM members WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** 当前身份的主账号是不是超级管理员 */
    private static function isSuper(PDO $pdo, array $me): bool {
        return self::isSuperAccount($pdo, (int) ($me["account_id"] ?? 0));
    }

    /** 某个主账号是不是超级管理员 */
    private static function isSuperAccount(PDO $pdo, int $accountId): bool {
        if ($accountId <= 0) return false;
        $st = $pdo->prepare("SELECT role FROM accounts WHERE id = ? LIMIT 1");
        $st->execute([$accountId]);
        return (string) ($st->fetchColumn() ?: "") === "super";
    }

    /** 归一化成 (小 id, 大 id)，写库时 a_id < b_id 是硬约束 */
    private static function pair(int $x, int $y): array {
        return $x < $y ? [$x, $y] : [$y, $x];
    }

    /** 两个人之间的那一行，没有就返回 null */
    public static function row(PDO $pdo, int $x, int $y): ?array {
        if ($x <= 0 || $y <= 0) return null;
        [$a, $b] = self::pair($x, $y);
        $st = $pdo->prepare("SELECT * FROM friends WHERE a_id = ? AND b_id = ? LIMIT 1");
        $st->execute([$a, $b]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** 是不是好友：只有 accepted 才算 */
    public static function areFriends(PDO $pdo, int $x, int $y): bool {
        if ($x <= 0 || $y <= 0 || $x === $y) return false;
        $row = self::row($pdo, $x, $y);
        return $row !== null && (string) $row["status"] === "accepted";
    }

    /**
     * 同作用域内的有效成员，不在这个 scope / 已注销都返回 null。
     * 超级管理员是所有作用域的共同成员：当前身份虽然只挂在某一个群上，
     * 但在别的群里点到超管时不该当成「外群的人」。
     */
    public static function scopeMember(PDO $pdo, array $scope, int $id): ?array {
        if ($id <= 0) return null;
        $st = $pdo->prepare("SELECT * FROM members WHERE id = ? AND scope_id = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$id, (int) $scope["id"]]);
        $row = $st->fetch();
        if ($row) return $row;
        $any = self::anyLive($pdo, $id);
        if ($any === null) return null;
        return self::isSuperAccount($pdo, (int) ($any["account_id"] ?? 0)) ? $any : null;
    }

    /**
     * 发好友申请：建一条 pending 行，requested_by = 我。
     * 校验顺序按契约 §4.4：不能加自己 → 必须同 scope → 已是好友 → 已有 pending。
     */
    public static function request(PDO $pdo, array $scope, array $me, int $targetId): array {
        $mine = (int) $me["id"];
        if ($targetId <= 0) Json::fail("要加谁？");
        if ($targetId === $mine) Json::fail("不能加自己");
        // 同一个群里的人照常加；只有超级管理员能跨群加，因为超管在每个群都有成员身份，
        // 但网页上的当前身份只属于一个群，不加这条就永远加不到别的群里的自己人。
        if (self::scopeMember($pdo, $scope, $targetId) === null) {
            if (!self::isSuper($pdo, $me)) Json::fail("找不到这个成员");
            if (self::anyLive($pdo, $targetId) === null) Json::fail("找不到这个成员");
        }

        $row = self::row($pdo, $mine, $targetId);
        if ($row !== null) {
            $status = (string) $row["status"];
            if ($status === "accepted") Json::fail("已经是好友");
            if ($status === "blocked") Json::fail("这个人现在不能加");
            Json::fail("已经申请过了");
        }

        [$a, $b] = self::pair($mine, $targetId);
        try {
            $ins = $pdo->prepare(
                "INSERT INTO friends (a_id, b_id, status, requested_by, created_at) VALUES (?,?,?,?,?)"
            );
            $ins->execute([$a, $b, "pending", $mine, lg_now()]);
        } catch (\Throwable $e) {
            // 并发下两个人同时申请：主键冲突就沿用已存在的那条
            if (self::row($pdo, $mine, $targetId) === null) Json::fail("申请失败，稍后再试");
            Json::fail("已经申请过了");
        }

        Notices::pushToMember($pdo, $targetId, "friend", $me["name"] . " 想加你为好友", "同意之后就能单独聊天", "#/friends", $mine);
        return self::row($pdo, $mine, $targetId) ?? [];
    }

    /** 同意我收到的申请 */
    public static function accept(PDO $pdo, array $scope, array $me, int $targetId): array {
        $mine = (int) $me["id"];
        $row = $targetId > 0 ? self::row($pdo, $mine, $targetId) : null;
        if ($row === null) Json::fail("没有待处理的申请");
        $status = (string) $row["status"];
        if ($status === "accepted") Json::fail("已经是好友");
        if ($status === "blocked") Json::fail("这个人现在不能加");
        if ((int) $row["requested_by"] === $mine) Json::fail("你已经申请过了，等对方同意");

        $up = $pdo->prepare("UPDATE friends SET status = 'accepted', accepted_at = ? WHERE a_id = ? AND b_id = ?");
        $up->execute([lg_now(), (int) $row["a_id"], (int) $row["b_id"]]);

        Notices::pushToMember($pdo, (int) $row["requested_by"], "friend", $me["name"] . " 通过了你的好友申请", "现在可以单独聊天了", "#/friends", $mine);
        return self::row($pdo, $mine, $targetId) ?? [];
    }

    /** 拒绝我收到的申请：直接删行，对方还能再申请 */
    public static function reject(PDO $pdo, array $scope, array $me, int $targetId): void {
        $mine = (int) $me["id"];
        $row = $targetId > 0 ? self::row($pdo, $mine, $targetId) : null;
        if ($row === null || (string) $row["status"] !== "pending" || (int) $row["requested_by"] === $mine) {
            Json::fail("没有待处理的申请");
        }
        $requester = (int) $row["requested_by"];
        $del = $pdo->prepare("DELETE FROM friends WHERE a_id = ? AND b_id = ?");
        $del->execute([(int) $row["a_id"], (int) $row["b_id"]]);

        Notices::pushToMember($pdo, $requester, "friend", $me["name"] . " 拒绝了你的好友申请", "", "#/friends", $mine);
    }

    /** 删好友：只删 accepted 行，不动私聊会话，历史消息留着 */
    public static function remove(PDO $pdo, array $scope, array $me, int $targetId): void {
        $mine = (int) $me["id"];
        $row = $targetId > 0 ? self::row($pdo, $mine, $targetId) : null;
        if ($row === null || (string) $row["status"] !== "accepted") Json::fail("你们还不是好友");
        $del = $pdo->prepare("DELETE FROM friends WHERE a_id = ? AND b_id = ?");
        $del->execute([(int) $row["a_id"], (int) $row["b_id"]]);
    }

    /**
     * 我的好友关系，三段：friends / incoming（别人申请我）/ outgoing（我申请的）。
     * 每项只带对方 id 与时间戳，成员对象由 Compat 统一序列化。
     *
     * @return array{friends: array<int, array{id:int, at:int}>, incoming: array<int, array{id:int, at:int}>, outgoing: array<int, array{id:int, at:int}>}
     */
    public static function listFor(PDO $pdo, array $scope, array $me): array {
        $mine = (int) $me["id"];
        $out = ["friends" => [], "incoming" => [], "outgoing" => []];
        if ($mine <= 0) return $out;
        $st = $pdo->prepare(
            "SELECT * FROM friends WHERE ((a_id = ? OR b_id = ?) AND status IN ('pending','accepted'))"
            . " ORDER BY created_at DESC, a_id DESC"
        );
        $st->execute([$mine, $mine]);
        foreach ($st->fetchAll() as $r) {
            $other = (int) $r["a_id"] === $mine ? (int) $r["b_id"] : (int) $r["a_id"];
            if ($other <= 0 || $other === $mine) continue;
            if ((string) $r["status"] === "accepted") {
                $out["friends"][] = ["id" => $other, "at" => (int) ($r["accepted_at"] ?? 0) ?: (int) $r["created_at"]];
                continue;
            }
            $item = ["id" => $other, "at" => (int) $r["created_at"]];
            if ((int) $r["requested_by"] === $mine) $out["outgoing"][] = $item;
            else $out["incoming"][] = $item;
        }
        return $out;
    }

    /**
     * 好友关系的指纹：给前端轮询用。
     *
     * 申请 / 同意 / 拒绝 / 删好友都是「关系行」的变化，不产生新消息，
     * 前端原来的做法是进页面才拉一次 /api/friends，所以红点和列表一直不更新。
     * 这里把「我参与的、pending 或 accepted 的关系」拼成指纹，变了才让前端重拉。
     * accepted 用 accepted_at 参与指纹，所以对方同意的那一刻指纹一定会变。
     */
    public static function sig(PDO $pdo, array $scope, array $me): string {
        $rel = self::listFor($pdo, $scope, $me);
        $parts = [];
        foreach (["friends", "incoming", "outgoing"] as $k) {
            $one = [];
            foreach ($rel[$k] as $it) $one[] = (int) $it["id"] . ":" . (int) $it["at"];
            sort($one);
            $parts[] = $k . "=" . implode(",", $one);
        }
        return md5(implode("|", $parts));
    }

    /** 我这边已接受的好友数（api/me 的 friends 字段） */
    public static function countFriends(PDO $pdo, int $memberId): int {
        if ($memberId <= 0) return 0;
        $st = $pdo->prepare("SELECT COUNT(*) FROM friends WHERE (a_id = ? OR b_id = ?) AND status = 'accepted'");
        $st->execute([$memberId, $memberId]);
        return (int) $st->fetchColumn();
    }

    /** 待我处理的好友申请数（api/me 的 pending 字段） */
    public static function countPending(PDO $pdo, int $memberId): int {
        if ($memberId <= 0) return 0;
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM friends WHERE (a_id = ? OR b_id = ?) AND status = 'pending' AND requested_by <> ?"
        );
        $st->execute([$memberId, $memberId, $memberId]);
        return (int) $st->fetchColumn();
    }
}
