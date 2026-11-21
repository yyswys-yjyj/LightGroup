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

    /** 我名下的 AI（没注销的） */
    public static function ownedAiIds(PDO $pdo, int $ownerId): array {
        if ($ownerId <= 0) return [];
        $st = $pdo->prepare("SELECT id FROM members WHERE owner_id = ? AND kind = 'ai' AND deleted_at IS NULL ORDER BY id ASC");
        $st->execute([$ownerId]);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[] = (int) $r["id"];
        return $out;
    }

    /**
     * 动作落在谁头上：默认是我自己；asId 只能是我名下的 AI（主人代 AI 处理好友）。
     */
    public static function actorId(PDO $pdo, array $me, ?int $asId): int {
        $mine = (int) $me["id"];
        if ($asId === null || $asId <= 0 || $asId === $mine) return $mine;
        $st = $pdo->prepare("SELECT COUNT(*) FROM members WHERE id = ? AND owner_id = ? AND kind = 'ai' AND deleted_at IS NULL");
        $st->execute([$asId, $mine]);
        if ((int) $st->fetchColumn() === 0) Json::fail("这不是你的 AI");
        return $asId;
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
        $target = self::scopeMember($pdo, $scope, $targetId);
        if ($target === null) {
            if (!self::isSuper($pdo, $me)) Json::fail("找不到这个成员");
            $target = self::anyLive($pdo, $targetId);
            if ($target === null) Json::fail("找不到这个成员");
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
        // AI 的好友由主人管：申请同时送到主人，主人不登 AI 也知道
        $ownerId = (int) ($target["owner_id"] ?? 0);
        if ((string) $target["kind"] === "ai" && $ownerId > 0 && $ownerId !== $mine) {
            Notices::pushToMember(
                $pdo,
                $ownerId,
                "friend",
                "你的 AI「" . $target["name"] . "」收到好友申请",
                $me["name"] . " 想加它。到好友页替它同意，或者让 AI 发 /friend ok 名字",
                "#/friends",
                $mine
            );
        }
        return self::row($pdo, $mine, $targetId) ?? [];
    }

    /** 同意我收到的申请 */
    public static function accept(PDO $pdo, array $scope, array $me, int $targetId, ?int $asId = null): array {
        $mine = self::actorId($pdo, $me, $asId);
        $actor = $mine === (int) $me["id"] ? $me : (self::anyLive($pdo, $mine) ?? $me);
        $row = $targetId > 0 ? self::row($pdo, $mine, $targetId) : null;
        if ($row === null) Json::fail("没有待处理的申请");
        $status = (string) $row["status"];
        if ($status === "accepted") Json::fail("已经是好友");
        if ($status === "blocked") Json::fail("这个人现在不能加");
        if ((int) $row["requested_by"] === $mine) Json::fail("你已经申请过了，等对方同意");

        $up = $pdo->prepare("UPDATE friends SET status = 'accepted', accepted_at = ? WHERE a_id = ? AND b_id = ?");
        $up->execute([lg_now(), (int) $row["a_id"], (int) $row["b_id"]]);

        Notices::pushToMember($pdo, (int) $row["requested_by"], "friend", $actor["name"] . " 通过了你的好友申请", "现在可以单独聊天了", "#/friends", $mine);
        return self::row($pdo, $mine, $targetId) ?? [];
    }

    /** 拒绝我收到的申请：直接删行，对方还能再申请 */
    public static function reject(PDO $pdo, array $scope, array $me, int $targetId, ?int $asId = null): void {
        $mine = self::actorId($pdo, $me, $asId);
        $actor = $mine === (int) $me["id"] ? $me : (self::anyLive($pdo, $mine) ?? $me);
        $row = $targetId > 0 ? self::row($pdo, $mine, $targetId) : null;
        if ($row === null || (string) $row["status"] !== "pending" || (int) $row["requested_by"] === $mine) {
            Json::fail("没有待处理的申请");
        }
        $requester = (int) $row["requested_by"];
        $del = $pdo->prepare("DELETE FROM friends WHERE a_id = ? AND b_id = ?");
        $del->execute([(int) $row["a_id"], (int) $row["b_id"]]);

        Notices::pushToMember($pdo, $requester, "friend", $actor["name"] . " 拒绝了你的好友申请", "", "#/friends", $mine);
    }

    /** 删好友：只删 accepted 行，不动私聊会话，历史消息留着 */
    public static function remove(PDO $pdo, array $scope, array $me, int $targetId, ?int $asId = null): void {
        $mine = self::actorId($pdo, $me, $asId);
        $row = $targetId > 0 ? self::row($pdo, $mine, $targetId) : null;
        if ($row === null || (string) $row["status"] !== "accepted") Json::fail("你们还不是好友");
        $del = $pdo->prepare("DELETE FROM friends WHERE a_id = ? AND b_id = ?");
        $del->execute([(int) $row["a_id"], (int) $row["b_id"]]);
    }

    /**
     * 给这段关系设备注：remark 是我看到对方的名字。
     * 关系挂在名下 AI 上时，备注属于 AI 视角（for=a_<id>）。
     */
    public static function remark(PDO $pdo, array $scope, array $me, int $targetId, string $remark, ?int $asId = null): void {
        $mine = self::actorId($pdo, $me, $asId);
        if ($targetId <= 0 || $targetId === $mine) Json::fail("不能给自己设备注");
        $row = self::row($pdo, $mine, $targetId);
        if ($row === null) Json::fail("你们还不是好友");
        $remark = trim($remark);
        if (mb_strlen($remark) > 32) Json::fail("备注最多 32 个字");
        $st = $pdo->prepare("UPDATE friends SET remark_a = ?, remark_b = ? WHERE a_id = ? AND b_id = ?");
        $st->execute([
            $mine === (int) $row["a_id"] ? $remark : (string) ($row["remark_a"] ?? ""),
            $mine === (int) $row["b_id"] ? $remark : (string) ($row["remark_b"] ?? ""),
            (int) $row["a_id"],
            (int) $row["b_id"],
        ]);
    }

    /**
     * 拉黑：关系行改成 blocked（好友关系同时解除），但这一行会一直留着。
     * 这就是「删了好友也加不回来」——request 见到 blocked 直接拒。
     */
    public static function block(PDO $pdo, array $scope, array $me, int $targetId, ?int $asId = null): void {
        $mine = self::actorId($pdo, $me, $asId);
        if ($targetId <= 0 || $targetId === $mine) Json::fail("不能拉黑自己");
        if (self::scopeMember($pdo, $scope, $targetId) === null && !self::isSuper($pdo, $me)) Json::fail("找不到这个成员");
        $row = self::row($pdo, $mine, $targetId);
        if ($row !== null) {
            $up = $pdo->prepare("UPDATE friends SET status = 'blocked', blocked_by = ?, accepted_at = NULL WHERE a_id = ? AND b_id = ?");
            $up->execute([$mine, (int) $row["a_id"], (int) $row["b_id"]]);
        } else {
            [$a, $b] = self::pair($mine, $targetId);
            $ins = $pdo->prepare("INSERT INTO friends (a_id, b_id, status, requested_by, created_at, blocked_by) VALUES (?,?,?,?,?,?)");
            $ins->execute([$a, $b, "blocked", $mine, lg_now(), $mine]);
        }
    }

    /** 解除拉黑：只删「我拉黑对方」的那一行 */
    public static function unblock(PDO $pdo, array $scope, array $me, int $targetId, ?int $asId = null): void {
        $mine = self::actorId($pdo, $me, $asId);
        $row = $targetId > 0 ? self::row($pdo, $mine, $targetId) : null;
        if ($row === null || (string) $row["status"] !== "blocked") Json::fail("这个人不在你的黑名单里");
        if ((int) ($row["blocked_by"] ?? 0) !== $mine) Json::fail("是对方拉黑了你");
        $pdo->prepare("DELETE FROM friends WHERE a_id = ? AND b_id = ?")->execute([(int) $row["a_id"], (int) $row["b_id"]]);
    }

    /** 我和某人的关系，给资料页决定按钮用 */
    public static function relation(PDO $pdo, int $viewer, int $other): array {
        $row = self::row($pdo, $viewer, $other);
        if ($row === null) return ["state" => "none", "remark" => ""];
        $remark = self::remarkOf($row, $viewer);
        $status = (string) $row["status"];
        if ($status === "blocked") {
            $by = (int) ($row["blocked_by"] ?? 0);
            return ["state" => $by === $viewer ? "blockedByMe" : "blockedMe", "remark" => $remark];
        }
        if ($status === "accepted") return ["state" => "friend", "remark" => $remark];
        if ((int) $row["requested_by"] === $viewer) return ["state" => "outgoing", "remark" => $remark];
        return ["state" => "incoming", "remark" => $remark];
    }

    /** 取这行关系里、viewer 视角的备注 */
    public static function remarkOf(array $row, int $viewer): string {
        $v = (int) $row["a_id"] === $viewer ? (string) ($row["remark_a"] ?? "") : (string) ($row["remark_b"] ?? "");
        return trim($v);
    }

    /**
     * 我的好友关系，三段：friends / incoming（别人申请我）/ outgoing（我申请的）。
     * 每项只带对方 id 与时间戳，成员对象由 Compat 统一序列化。
     *
     * @return array{friends: array<int, array{id:int, at:int}>, incoming: array<int, array{id:int, at:int}>, outgoing: array<int, array{id:int, at:int}>}
     */
    public static function listFor(PDO $pdo, array $scope, array $me, bool $withOwned = false): array {
        $mine = (int) $me["id"];
        $out = ["friends" => [], "incoming" => [], "outgoing" => []];
        if ($mine <= 0) return $out;
        // 主人视角：名下的 AI 收到的申请也算「我的」，条目上用 for 标出是哪个 AI
        $ids = [$mine];
        if ($withOwned) {
            foreach (self::ownedAiIds($pdo, $mine) as $ai) $ids[] = $ai;
        }
        $seen = ["friends" => [], "incoming" => [], "outgoing" => []];
        foreach ($ids as $id) {
            $st = $pdo->prepare(
                "SELECT * FROM friends WHERE ((a_id = ? OR b_id = ?) AND status IN ('pending','accepted'))"
                . " ORDER BY created_at DESC, a_id DESC"
            );
            $st->execute([$id, $id]);
            foreach ($st->fetchAll() as $r) {
                $other = (int) $r["a_id"] === $id ? (int) $r["b_id"] : (int) $r["a_id"];
                if ($other <= 0 || $other === $id) continue;
                // 我名下两个 AI 互加：主人不用管
                if ($withOwned && $other !== $mine && in_array($other, $ids, true)) continue;
                $bucket = "friends";
                if ((string) $r["status"] !== "accepted") {
                    $bucket = (int) $r["requested_by"] === $id ? "outgoing" : "incoming";
                }
                $key = $other . ":" . $id;
                if (isset($seen[$bucket][$key])) continue;
                $seen[$bucket][$key] = true;
                $item = [
                    "id" => $other,
                    "at" => $bucket === "friends"
                        ? ((int) ($r["accepted_at"] ?? 0) ?: (int) $r["created_at"])
                        : (int) $r["created_at"],
                    // 备注是「我看对方」的视角：关系挂在 AI 上时用 AI 的视角
                    "remark" => self::remarkOf($r, $id),
                ];
                if ($id !== $mine) $item["for"] = $id;
                $out[$bucket][] = $item;
            }
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
    public static function sig(PDO $pdo, array $scope, array $me, bool $withOwned = false): string {
        $rel = self::listFor($pdo, $scope, $me, $withOwned);
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
    public static function countPending(PDO $pdo, int $memberId, bool $withOwned = false): int {
        if ($memberId <= 0) return 0;
        if ($withOwned) {
            return count(self::listFor($pdo, [], ["id" => $memberId], true)["incoming"]);
        }
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM friends WHERE (a_id = ? OR b_id = ?) AND status = 'pending' AND requested_by <> ?"
        );
        $st->execute([$memberId, $memberId, $memberId]);
        return (int) $st->fetchColumn();
    }
}
