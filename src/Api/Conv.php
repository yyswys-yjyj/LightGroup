<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Http\Json;
use PDO;

/**
 * 会话（conversations）与好友（friends）的公共逻辑。
 *
 * 公开大群：每个作用域一行，dm_key = "public"，is_public = 1，作用是容纳原来
 * 「一个 scope 就是一个群」的全部消息。作用域所有成员隐式在里面，不写 conv_members，
 * 已读游标复用 members.last_read_id。
 *
 * 私聊：dm_key = "小id_大id"，两人各一行 conv_members，已读游标在 conv_members.last_read_id。
 */
final class Conv {
    private const COLORS = [
        "#6b7280", "#f76d6d", "#6d9df7", "#f7b76d", "#b06df7",
        "#6df7d1", "#f76dc1", "#a0d468", "#e2b93b", "#7f8c8d",
    ];

    public static function colorOf(int $id): string {
        if ($id <= 0) return self::COLORS[0];
        return self::COLORS[$id % count(self::COLORS)];
    }

    public static function convById(PDO $pdo, int $id): ?array {
        $st = $pdo->prepare("SELECT * FROM conversations WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** 取作用域的公开大群，没有就建一个（幂等） */
    public static function publicConv(PDO $pdo, array $scope): array {
        $sid = (int) $scope["id"];
        $st = $pdo->prepare("SELECT * FROM conversations WHERE scope_id = ? AND dm_key = 'public' LIMIT 1");
        $st->execute([$sid]);
        $row = $st->fetch();
        if ($row) return $row;
        try {
            $ins = $pdo->prepare(
                "INSERT INTO conversations (scope_id, kind, title, is_public, dm_key, created_by, created_at, last_msg_id, last_msg_at)"
                . " VALUES (?, 'group', ?, 1, 'public', NULL, ?, 0, 0)"
            );
            $ins->execute([$sid, (string) ($scope["title"] ?? ""), lg_now()]);
        } catch (\Throwable $e) {
            // 并发创建时忽略，下面再查一次
        }
        $st->execute([$sid]);
        $row = $st->fetch();
        if ($row) return $row;
        Json::fail("公开大群不存在");
    }

    /**
     * 读消息时该合并哪些公开大群：同圈子的群互通，消息要能互相看见。
     * 没配圈子就只有自己那一个；本群的 id 永远在列表里（sent 消息落在它上面）。
     */
    public static function publicConvIds(PDO $pdo, array $scope): array {
        $mine = (int) self::publicConv($pdo, $scope)["id"];
        $cid  = $scope["cluster_id"] ?? null;
        if ($cid === null) return [$mine];
        $st = $pdo->prepare(
            "SELECT c.id FROM conversations c JOIN scopes s ON s.id = c.scope_id"
            . " WHERE c.is_public = 1 AND s.cluster_id = ? AND s.archived = 0"
        );
        $st->execute([(int) $cid]);
        $all = array_map("intval", $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$all) return [$mine];
        if (!in_array($mine, $all, true)) $all[] = $mine;
        sort($all);
        return $all;
    }

    /** 两个成员之间的私聊会话，存在就复用 */
    public static function ensureDm(PDO $pdo, array $scope, int $a, int $b, int $by = 0): array {
        $sid = (int) $scope["id"];
        $lo = min($a, $b);
        $hi = max($a, $b);
        $key = $lo . "_" . $hi;
        $st = $pdo->prepare("SELECT * FROM conversations WHERE scope_id = ? AND dm_key = ? LIMIT 1");
        $st->execute([$sid, $key]);
        $row = $st->fetch();
        if ($row) return $row;
        try {
            $now = lg_now();
            $ins = $pdo->prepare(
                "INSERT INTO conversations (scope_id, kind, title, is_public, dm_key, created_by, created_at, last_msg_id, last_msg_at)"
                . " VALUES (?, 'dm', '', 0, ?, ?, ?, 0, 0)"
            );
            $ins->execute([$sid, $key, $by > 0 ? $by : $lo, $now]);
            $cid = (int) $pdo->lastInsertId();
            $cm = $pdo->prepare("INSERT INTO conv_members (conv_id, member_id, last_read_id, joined_at) VALUES (?, ?, 0, ?)");
            $cm->execute([$cid, $lo, $now]);
            $cm->execute([$cid, $hi, $now]);
            return self::convById($pdo, $cid) ?? [];
        } catch (\Throwable $e) {
            $st->execute([$sid, $key]);
            $row = $st->fetch();
            if ($row) return $row;
            Json::fail("创建私聊失败");
        }
        return [];
    }

    /** 我是不是这个会话的成员 */
    public static function isMember(PDO $pdo, array $conv, int $memberId): bool {
        if ($memberId <= 0) return false;
        if ((int) ($conv["is_public"] ?? 0) === 1) {
            $st = $pdo->prepare("SELECT 1 FROM members WHERE id = ? AND scope_id = ? AND deleted_at IS NULL LIMIT 1");
            $st->execute([$memberId, (int) $conv["scope_id"]]);
            return (bool) $st->fetchColumn();
        }
        $st = $pdo->prepare("SELECT 1 FROM conv_members WHERE conv_id = ? AND member_id = ? LIMIT 1");
        $st->execute([(int) $conv["id"], $memberId]);
        return (bool) $st->fetchColumn();
    }

    public static function requireConv(PDO $pdo, int $id): array {
        $conv = self::convById($pdo, $id);
        if ($conv === null) Json::fail("会话不存在");
        return $conv;
    }

    /** 会话里除我以外的成员 id（公开大群返回整个作用域） */
    public static function memberIds(PDO $pdo, array $conv): array {
        if ((int) ($conv["is_public"] ?? 0) === 1) {
            $st = $pdo->prepare("SELECT id FROM members WHERE scope_id = ? AND deleted_at IS NULL");
            $st->execute([(int) $conv["scope_id"]]);
            return array_map("intval", $st->fetchAll(PDO::FETCH_COLUMN));
        }
        $st = $pdo->prepare("SELECT member_id FROM conv_members WHERE conv_id = ?");
        $st->execute([(int) $conv["id"]]);
        return array_map("intval", $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function touch(PDO $pdo, int $convId, int $msgId, int $ts): void {
        try {
            $st = $pdo->prepare("UPDATE conversations SET last_msg_id = ?, last_msg_at = ? WHERE id = ?");
            $st->execute([$msgId, $ts, $convId]);
        } catch (\Throwable $e) {
        }
    }

    public static function markRead(PDO $pdo, array $conv, array $me, int $lastId): void {
        $mid = (int) $me["id"];
        if ((int) ($conv["is_public"] ?? 0) === 1) {
            $st = $pdo->prepare("UPDATE members SET last_read_id = ? WHERE id = ? AND last_read_id < ?");
            $st->execute([$lastId, $mid, $lastId]);
            return;
        }
        $st = $pdo->prepare("UPDATE conv_members SET last_read_id = ? WHERE conv_id = ? AND member_id = ? AND last_read_id < ?");
        $st->execute([$lastId, (int) $conv["id"], $mid, $lastId]);
    }

    /** 会话的最后一条消息摘要，给会话列表用 */
    public static function lastLine(PDO $pdo, array $conv, ?array $convIds = null): ?array {
        $cid = (int) $conv["id"];
        $sql = "SELECT m.id, m.ts_ms, m.text, m.img, m.voice, m.deleted_at, m.sys, u.name, u.id AS uid"
            . " FROM messages m LEFT JOIN members u ON u.id = m.member_id";
        $args = [];
        if ($convIds !== null && count($convIds) > 1) {
            // 互通：摘要要取同圈子所有公开大群里最新的那条，不然对方群的新消息不刷新
            $in  = implode(",", array_fill(0, count($convIds), "?"));
            $sql .= " WHERE m.conv_id IN ($in)";
            $args = array_map("intval", $convIds);
        } else {
            $sql .= " WHERE m.conv_id = ?";
            $args = [$cid];
        }
        $sql .= " ORDER BY m.id DESC LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $row = $st->fetch();
        if (!$row) return null;
        $text = (string) ($row["text"] ?? "");
        if (!empty($row["deleted_at"])) $text = "[已撤回]";
        elseif ($text === "" && !empty($row["voice"])) $text = "[语音]";
        elseif ($text === "" && !empty($row["img"])) $text = "[图片]";
        // 系统提示不算谁说的话，摘要前面加个标记，别给会话列表带上人名
        $isSys = !empty($row["sys"]);
        if ($isSys) $text = "[系统] " . $text;
        return [
            "id"   => (int) $row["id"],
            "ts"   => (int) $row["ts_ms"],
            "name" => $isSys ? "" : (string) ($row["name"] ?? ""),
            "uid"  => (int) ($row["uid"] ?? 0),
            "text" => $text,
            // 前端会话列表据此直接显示「消息已撤回」，不用再猜文本是不是标记
            "recalled" => !empty($row["deleted_at"]),
        ];
    }

    public static function unread(PDO $pdo, array $conv, array $me, ?array $convIds = null): int {
        $mid = (int) $me["id"];
        if ((int) ($conv["is_public"] ?? 0) === 1) {
            $since = (int) ($me["last_read_id"] ?? 0);
        } else {
            $st = $pdo->prepare("SELECT last_read_id FROM conv_members WHERE conv_id = ? AND member_id = ?");
            $st->execute([(int) $conv["id"], $mid]);
            $since = (int) ($st->fetchColumn() ?: 0);
        }
        if ($convIds !== null && count($convIds) > 1) {
            // 互通：未读要把兄弟群的消息一起算
            $in = implode(",", array_fill(0, count($convIds), "?"));
            $st = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE conv_id IN ($in) AND id > ? AND member_id <> ? AND deleted_at IS NULL AND sys = 0");
            $st->execute([...array_map("intval", $convIds), $since, $mid]);
            return (int) $st->fetchColumn();
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE conv_id = ? AND id > ? AND member_id <> ? AND deleted_at IS NULL AND sys = 0");
        $st->execute([(int) $conv["id"], $since, $mid]);
        return (int) $st->fetchColumn();
    }

    /**
     * 左侧会话列表：公开大群永远第一，其后是我参与的私聊，按最后消息时间倒序。
     */
    public static function mine(PDO $pdo, array $scope, array $me): array {
        $sid = (int) $scope["id"];
        $mid = (int) $me["id"];
        $pub = self::publicConv($pdo, $scope);
        // 互通时公开大群的摘要/未读要跨兄弟群聚合
        $pubIds = self::publicConvIds($pdo, $scope);
        $st = $pdo->prepare(
            "SELECT c.* FROM conversations c"
            . " JOIN conv_members cm ON cm.conv_id = c.id AND cm.member_id = ?"
            . " WHERE c.scope_id = ? AND c.is_public = 0"
            . " ORDER BY c.last_msg_at DESC, c.id DESC LIMIT 100"
        );
        $st->execute([$mid, $sid]);
        $rows = array_merge([$pub], $st->fetchAll());
        $out = [];
        foreach ($rows as $c) {
            $isPub = (int) $c["is_public"] === 1;
            $item = [
                "id"       => (int) $c["id"],
                "kind"     => $isPub ? "group" : "dm",
                "title"    => $isPub ? (string) ($scope["title"] ?? "") : "",
                "isPublic" => $isPub ? 1 : 0,
                "last"     => self::lastLine($pdo, $c, $isPub ? $pubIds : null),
                "unread"   => self::unread($pdo, $c, $me, $isPub ? $pubIds : null),
            ];
            if ($isPub) {
                $stc = $pdo->prepare("SELECT COUNT(*) FROM members WHERE scope_id = ? AND deleted_at IS NULL");
                $stc->execute([$sid]);
                $item["members"] = (int) $stc->fetchColumn();
            } else {
                $other = 0;
                foreach (self::memberIds($pdo, $c) as $x) {
                    if ($x !== $mid) { $other = $x; break; }
                }
                $item["memberId"] = $other;
                $item["member"] = self::briefOf($pdo, $other);
                $item["title"] = $item["member"]["name"];
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * 最近被撤回的消息 id。
     *
     * 撤回是「旧消息的状态变更」，而前端增量拉取用的是 id > since 的游标，
     * 那条旧消息再也不会被拉回来，所以对方一直看到原消息。这里把最近的撤回 id 单独列出来，
     * 前端每轮轮询按 id 比对，把自己本地还显示着的那条改成「已撤回」。
     *
     * @param int|null   $convId   给了会话就只算这个会话；null = 按作用域（集群内公开大群）
     * @param array|null $scopeIds 作用域 id 列表（集群互通时不止一个）
     */
    public static function recalledIds(PDO $pdo, ?int $convId, ?array $scopeIds = null, int $limit = 200, ?array $convIds = null): array {
        $limit = max(1, min(500, $limit));
        if ($convIds !== null && count($convIds) > 1) {
            // 互通：撤回也要从兄弟群一起拉，否则对方撤回的消息这边不变灰
            $in = implode(",", array_fill(0, count($convIds), "?"));
            $st = $pdo->prepare(
                "SELECT id FROM messages WHERE conv_id IN ($in) AND deleted_at IS NOT NULL"
                . " ORDER BY id DESC LIMIT " . $limit
            );
            $st->execute(array_map("intval", $convIds));
            return array_map("intval", $st->fetchAll(PDO::FETCH_COLUMN));
        }
        if ($convId !== null && $convId > 0) {
            $st = $pdo->prepare(
                "SELECT id FROM messages WHERE conv_id = ? AND deleted_at IS NOT NULL"
                . " ORDER BY id DESC LIMIT " . $limit
            );
            $st->execute([$convId]);
            return array_map("intval", $st->fetchAll(PDO::FETCH_COLUMN));
        }
        $ids = [];
        foreach ($scopeIds ?: [] as $x) {
            $x = (int) $x;
            if ($x > 0) $ids[] = $x;
        }
        if (!$ids) return [];
        $in = implode(",", array_fill(0, count($ids), "?"));
        // 私聊里的撤回不从群接口漏出去，过滤条件与 fetchSince 的 scope 语义保持一致
        $st = $pdo->prepare(
            "SELECT m.id FROM messages m WHERE m.scope_id IN ($in) AND m.deleted_at IS NOT NULL"
            . " AND (m.conv_id IS NULL OR m.conv_id IN ("
            . "SELECT c.id FROM conversations c WHERE c.is_public = 1 AND c.scope_id IN ($in)))"
            . " ORDER BY m.id DESC LIMIT " . $limit
        );
        $st->execute([...$ids, ...$ids]);
        return array_map("intval", $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function nameOf(PDO $pdo, int $memberId): string {
        if ($memberId <= 0) return "";
        $st = $pdo->prepare("SELECT name FROM members WHERE id = ? LIMIT 1");
        $st->execute([$memberId]);
        return (string) ($st->fetchColumn() ?: "");
    }

    /** 会话列表里显示对方用的精简资料（id 与 Compat 的对外 id 一致：h_/a_ 前缀） */
    public static function briefOf(PDO $pdo, int $memberId): array {
        if ($memberId <= 0) return ["id" => "", "name" => "", "kind" => "", "avatar" => ""];
        $st = $pdo->prepare("SELECT id, name, kind, avatar FROM members WHERE id = ? LIMIT 1");
        $st->execute([$memberId]);
        $m = $st->fetch();
        if (!$m) return ["id" => "", "name" => "", "kind" => "", "avatar" => ""];
        return [
            "id"     => ((string) $m["kind"] === "ai" ? "a_" : "h_") . (int) $m["id"],
            "name"   => (string) $m["name"],
            "kind"   => (string) $m["kind"],
            "avatar" => (string) ($m["avatar"] ?? ""),
            "color"  => self::colorOf((int) $m["id"]),
        ];
    }
}