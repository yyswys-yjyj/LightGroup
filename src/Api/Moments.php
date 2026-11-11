<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Http\Json;
use PDO;

/**
 * 空间（说说）：纯文本 300 字以内 + 最多 9 张图 + #标签。
 * 点赞、评论、举报都做在动态上；举报会变成管理员的站内信。
 * 圈子互通开着时，同一个圈子的群共看一条时间线。
 */
final class Moments {
    private const MAX_TEXT = 300;
    private const MAX_IMAGES = 9;
    private const MAX_TAGS = 5;
    private const PAGE = 20;

    public static function handle(PDO $pdo, array $scope, string $path): void {
        $me = Compat::requireMemberPublic($pdo);
        $method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));

        if ($path === "moments") {
            $op = (string) (Input::body()["op"] ?? "");
            if ($method === "DELETE" || $op === "delete") self::remove($pdo, $scope, $me);
            elseif ($method === "POST") self::create($pdo, $scope, $me);
            else self::timeline($pdo, $scope, $me);
            return;
        }
        if ($path === "like") {
            self::like($pdo, $scope, $me);
            return;
        }
        if ($path === "comment") {
            $op = (string) (Input::body()["op"] ?? "");
            if ($method === "DELETE" || $op === "del") self::removeComment($pdo, $scope, $me);
            else self::comment($pdo, $scope, $me);
            return;
        }
        if ($path === "report") {
            self::report($pdo, $scope, $me);
            return;
        }
        if ($path === "settings") {
            self::settings($pdo, $scope, $me);
            return;
        }
        Json::fail("未知接口");
    }

    // ---------- 读 ----------

    /** h_12 / a_3 / 12 都认，返回内部成员 id；不认识的返回 0 */
    private static function midOf(string $raw): int {
        $raw = trim($raw);
        if ($raw === "") return 0;
        if (preg_match('/^[ha]_(\d+)$/i', $raw, $m)) return (int) $m[1];
        return ctype_digit($raw) ? (int) $raw : 0;
    }

    private static function timeline(PDO $pdo, array $scope, array $me): void {
        // u / cu 前端传的是 uid（h_12 / a_3），不能按整数读，否则全被当成 0、过滤失效
        $cu = self::midOf(Input::get("cu"));
        if ($cu > 0) {
            self::commentsBy($pdo, $scope, $me, $cu);
            return;
        }
        $ids = Compat::scopeIdsPublic($pdo, $scope);
        $in = implode(",", array_fill(0, count($ids), "?"));
        $params = $ids;

        $where = ["m.deleted_at IS NULL", "m.scope_id IN ($in)"];
        $before = Input::int("before", 0);
        if ($before > 0) {
            $where[] = "m.id < ?";
            $params[] = $before;
        }
        $tag = trim(Input::get("tag"), "# ");
        if ($tag !== "") {
            $where[] = "m.tags LIKE ?";
            $params[] = "%#" . $tag . "#%";
        }
        if (Input::int("mine", 0) === 1) {
            $where[] = "m.member_id = ?";
            $params[] = (int) $me["id"];
        }
        // 看别人的资料页：按成员过滤（仍受本作用域可见范围限制）
        $u = self::midOf(Input::get("u"));
        if ($u > 0) {
            $where[] = "m.member_id = ?";
            $params[] = $u;
        }

        // 推荐算法只用在广场主列表：看标签/看某人/我的动态都按时间走，别乱序
        $recommend = self::pushMode($pdo, (int) $scope["id"]) === 1
            && $tag === "" && $u <= 0 && Input::int("mine", 0) !== 1;
        $off = max(0, Input::int("off", 0));
        $nextOff = null;
        if ($recommend) {
            // 候选取最近 300 条再重排，越翻越久的东西不该把新的挤下去
            $sql = "SELECT m.* FROM moments m WHERE " . implode(" AND ", $where) . " ORDER BY m.id DESC LIMIT 300";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $cand = self::rank($pdo, $st->fetchAll(), $me);
            $rows = array_slice($cand, $off, self::PAGE);
            $nextOff = ($off + count($rows)) < count($cand) ? $off + count($rows) : null;
        } else {
            $sql = "SELECT m.* FROM moments m WHERE " . implode(" AND ", $where) . " ORDER BY m.id DESC LIMIT " . self::PAGE;
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll();
        }

        $mids = array_map(fn($r) => (int) $r["id"], $rows);
        $memberIds = [];
        foreach ($rows as $r) $memberIds[(int) $r["member_id"]] = true;
        $members = self::members($pdo, array_keys($memberIds));

        $likes = [];
        $mine = [];
        $comments = [];
        if ($mids) {
            $inM = implode(",", array_fill(0, count($mids), "?"));
            $q = $pdo->prepare("SELECT moment_id, COUNT(*) AS n FROM moment_likes WHERE moment_id IN ($inM) GROUP BY moment_id");
            $q->execute($mids);
            foreach ($q->fetchAll() as $r) $likes[(int) $r["moment_id"]] = (int) $r["n"];

            $q = $pdo->prepare("SELECT moment_id FROM moment_likes WHERE moment_id IN ($inM) AND member_id = ?");
            $q->execute(array_merge($mids, [(int) $me["id"]]));
            foreach ($q->fetchAll() as $r) $mine[(int) $r["moment_id"]] = true;

            $q = $pdo->prepare("SELECT c.* FROM moment_comments c WHERE c.moment_id IN ($inM) AND c.deleted_at IS NULL ORDER BY c.id ASC");
            $q->execute($mids);
            $crows = $q->fetchAll();
            $cMemberIds = [];
            foreach ($crows as $r) $cMemberIds[(int) $r["member_id"]] = true;
            $members += self::members($pdo, array_keys($cMemberIds));
            foreach ($crows as $r) {
                $comments[(int) $r["moment_id"]][] = [
                    "id"   => (int) $r["id"],
                    "member" => $members[(int) $r["member_id"]] ?? null,
                    "text" => (string) $r["text"],
                    "reply_to" => (int) ($r["reply_to"] ?? 0),
                    "ts"   => (int) $r["created_at"],
                ];
            }
        }

        $role = Compat::roleOfPublic($pdo, $me);
        $isAdmin = $role === "admin" || $role === "super";

        $items = [];
        foreach ($rows as $r) {
            $mid = (int) $r["id"];
            $author = (int) $r["member_id"];
            $items[] = [
                "id"      => $mid,
                "member"  => $members[$author] ?? null,
                "text"    => (string) $r["text"],
                "images"  => self::decodeImages((string) ($r["images"] ?? "")),
                "tags"    => self::decodeTags((string) ($r["tags"] ?? "")),
                "ts"      => (int) $r["created_at"],
                "likes"   => $likes[$mid] ?? 0,
                "liked"   => isset($mine[$mid]),
                "comments" => $comments[$mid] ?? [],
                "can_delete" => $isAdmin || $author === (int) $me["id"],
            ];
        }

        $pmode = self::pushMode($pdo, (int) $scope["id"]);
        Json::ok([
            "items" => $items,
            "push"  => $pmode > 0,
            "push_mode" => $pmode,
            "recommend" => $recommend,
            "next_off"  => $nextOff,
            "me"    => Compat::memberBriefPublic($pdo, $me),
            "admin" => $isAdmin,
        ]);
    }

    /** 某个人发过的评论（资料页「评论」页签），带上是哪条动态下面的 */
    private static function commentsBy(PDO $pdo, array $scope, array $me, int $memberId): void {
        $ids = Compat::scopeIdsPublic($pdo, $scope);
        $in = implode(",", array_fill(0, count($ids), "?"));
        $sql = "SELECT c.*, m.text AS m_text, m.images AS m_images, m.member_id AS m_member_id, m.created_at AS m_created_at
                FROM moment_comments c JOIN moments m ON m.id = c.moment_id
                WHERE c.member_id = ? AND c.deleted_at IS NULL AND m.deleted_at IS NULL AND m.scope_id IN ($in)
                ORDER BY c.id DESC LIMIT " . self::PAGE;
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$memberId], $ids));
        $rows = $st->fetchAll();

        $authorIds = [];
        foreach ($rows as $r) $authorIds[(int) $r["m_member_id"]] = true;
        $members = self::members($pdo, array_keys($authorIds));

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                "id"   => (int) $r["id"],
                "text" => (string) $r["text"],
                "ts"   => (int) $r["created_at"],
                "moment" => [
                    "id"     => (int) $r["moment_id"],
                    "text"   => mb_substr((string) $r["m_text"], 0, 120),
                    "images" => self::decodeImages((string) ($r["m_images"] ?? "")),
                    "ts"     => (int) $r["m_created_at"],
                    "member" => $members[(int) $r["m_member_id"]] ?? null,
                ],
            ];
        }
        Json::ok(["items" => $items, "me" => Compat::memberBriefPublic($pdo, $me)]);
    }

    private static function members(PDO $pdo, array $ids): array {
        $ids = array_values(array_filter(array_map("intval", $ids)));
        if (!$ids) return [];
        $in = implode(",", array_fill(0, count($ids), "?"));
        $st = $pdo->prepare("SELECT * FROM members WHERE id IN ($in) AND deleted_at IS NULL");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[(int) $r["id"]] = Compat::memberBriefPublic($pdo, $r);
        return $out;
    }

    /**
     * 推荐排序：在候选里重排。
     * 打底是时间（越新越高，但衰减很慢，昨天的好帖不会被早上的流水账顶掉），
     * 然后加互动热度，再加「我赞过/评论过的作者」和「我互动过的标签」加权。
     */
    private static function rank(PDO $pdo, array $rows, array $me): array {
        if (!$rows) return [];
        $mid = (int) $me["id"];
        $seenAuthors = [];
        $seenTags = [];
        $st = $pdo->prepare(
            "SELECT m.member_id, m.tags FROM moment_likes l JOIN moments m ON m.id = l.moment_id WHERE l.member_id = ?"
            . " UNION "
            . "SELECT m.member_id, m.tags FROM moment_comments c JOIN moments m ON m.id = c.moment_id WHERE c.member_id = ?"
        );
        $st->execute([$mid, $mid]);
        foreach ($st->fetchAll() as $r) {
            $seenAuthors[(int) $r["member_id"]] = true;
            foreach (self::decodeTags((string) ($r["tags"] ?? "")) as $t) $seenTags[$t] = true;
        }

        $ids = array_map(fn($r) => (int) $r["id"], $rows);
        $in = implode(",", array_fill(0, count($ids), "?"));
        $likes = [];
        $cmts = [];
        $q = $pdo->prepare("SELECT moment_id, COUNT(*) AS n FROM moment_likes WHERE moment_id IN ($in) GROUP BY moment_id");
        $q->execute($ids);
        foreach ($q->fetchAll() as $r) $likes[(int) $r["moment_id"]] = (int) $r["n"];
        $q = $pdo->prepare("SELECT moment_id, COUNT(*) AS n FROM moment_comments WHERE moment_id IN ($in) AND deleted_at IS NULL GROUP BY moment_id");
        $q->execute($ids);
        foreach ($q->fetchAll() as $r) $cmts[(int) $r["moment_id"]] = (int) $r["n"];

        $now = lg_now();
        foreach ($rows as $i => $r) {
            $id = (int) $r["id"];
            $ageH = max(0.2, ($now - (int) $r["created_at"]) / 3600);
            $score = 80 / pow(1 + $ageH / 18, 0.75);
            $score += ($likes[$id] ?? 0) * 5 + ($cmts[$id] ?? 0) * 7;
            if (isset($seenAuthors[(int) $r["member_id"]])) $score += 14;
            foreach (self::decodeTags((string) ($r["tags"] ?? "")) as $t) {
                if (isset($seenTags[$t])) $score += 8;
            }
            $rows[$i]["_score"] = $score;
        }
        usort($rows, fn($a, $b) => $b["_score"] <=> $a["_score"]);
        return $rows;
    }

    // ---------- 写 ----------

    private static function create(PDO $pdo, array $scope, array $me): void {
        $text = trim((string) (Input::body()["text"] ?? ""));
        if (mb_strlen($text) > self::MAX_TEXT) Json::fail("最多 " . self::MAX_TEXT . " 个字，删一点再发");
        $images = Input::raw("images");
        $images = array_values(array_filter(array_map("strval", $images), fn($u) => self::okImage($u)));
        if (count($images) > self::MAX_IMAGES) Json::fail("最多 " . self::MAX_IMAGES . " 张图");
        if ($text === "" && !$images) Json::fail("写点什么或者选一张图");

        $tags = self::tagsOf($text, Input::raw("tags"));
        if (count($tags) > self::MAX_TAGS) $tags = array_slice($tags, 0, self::MAX_TAGS);

        $now = lg_now();
        $st = $pdo->prepare("INSERT INTO moments (scope_id, member_id, text, images, tags, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $st->execute([
            (int) $scope["id"],
            (int) $me["id"],
            $text,
            $images ? implode("\n", $images) : null,
            $tags ? "#" . implode("#", $tags) . "#" : null,
            $now,
        ]);
        $id = (int) $pdo->lastInsertId();

        Json::ok(["id" => $id, "ts" => $now]);
    }

    private static function remove(PDO $pdo, array $scope, array $me): void {
        $id = Input::int("id", 0);
        if ($id <= 0) Json::fail("要指定哪一条");
        $ids = Compat::scopeIdsPublic($pdo, $scope);
        $in = implode(",", array_fill(0, count($ids), "?"));
        $st = $pdo->prepare("SELECT * FROM moments WHERE id = ? AND scope_id IN ($in) AND deleted_at IS NULL");
        $st->execute(array_merge([$id], $ids));
        $row = $st->fetch();
        if (!$row) Json::fail("这条动态不在了");

        $role = Compat::roleOfPublic($pdo, $me);
        $isAdmin = $role === "admin" || $role === "super";
        if (!$isAdmin && (int) $row["member_id"] !== (int) $me["id"]) Json::fail("只能删自己的动态");

        $st = $pdo->prepare("UPDATE moments SET deleted_at = ?, deleted_by = ? WHERE id = ?");
        $st->execute([lg_now(), (int) $me["id"], $id]);
        Json::ok(["deleted" => true]);
    }

    private static function like(PDO $pdo, array $scope, array $me): void {
        $id = Input::int("id", 0);
        $row = self::find($pdo, $scope, $id);
        if (!$row) Json::fail("这条动态不在了");
        $mid = (int) $me["id"];

        $st = $pdo->prepare("SELECT 1 FROM moment_likes WHERE moment_id = ? AND member_id = ?");
        $st->execute([$id, $mid]);
        $exists = (bool) $st->fetchColumn();
        if ($exists) {
            $st = $pdo->prepare("DELETE FROM moment_likes WHERE moment_id = ? AND member_id = ?");
            $st->execute([$id, $mid]);
        } else {
            $st = $pdo->prepare("INSERT INTO moment_likes (moment_id, member_id, created_at) VALUES (?, ?, ?)");
            $st->execute([$id, $mid, lg_now()]);
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM moment_likes WHERE moment_id = ?");
        $st->execute([$id]);
        Json::ok(["liked" => !$exists, "likes" => (int) $st->fetchColumn()]);
    }

    private static function comment(PDO $pdo, array $scope, array $me): void {
        $id = Input::int("id", 0);
        if (!self::find($pdo, $scope, $id)) Json::fail("这条动态不在了");
        $text = trim((string) (Input::body()["text"] ?? ""));
        if ($text === "") Json::fail("评论不能是空的");
        if (mb_strlen($text) > self::MAX_TEXT) Json::fail("评论最多 " . self::MAX_TEXT . " 个字");

        $now = lg_now();
        $st = $pdo->prepare("INSERT INTO moment_comments (moment_id, member_id, text, reply_to, created_at) VALUES (?, ?, ?, ?, ?)");
        $st->execute([$id, (int) $me["id"], $text, Input::int("reply_to", 0), $now]);
        $cid = (int) $pdo->lastInsertId();

        // 有人评论，通知作者（自己评自己的不通知）
        $st = $pdo->prepare("SELECT member_id FROM moments WHERE id = ?");
        $st->execute([$id]);
        $author = (int) $st->fetchColumn();
        if ($author > 0 && $author !== (int) $me["id"]) {
            Notices::push($pdo, $scope, $author, "comment", (string) $me["name"] . " 评论了你的动态", mb_substr($text, 0, 80), "#/space", (int) $me["id"]);
        }

        Json::ok([
            "comment" => [
                "id" => $cid,
                "member" => Compat::memberBriefPublic($pdo, $me),
                "text" => $text,
                "reply_to" => Input::int("reply_to", 0),
                "ts" => $now,
            ],
        ]);
    }

    private static function removeComment(PDO $pdo, array $scope, array $me): void {
        $cid = Input::int("comment_id", 0);
        $id = Input::int("id", 0);
        if ($cid <= 0) Json::fail("要指定哪条评论");
        $st = $pdo->prepare("SELECT * FROM moment_comments WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$cid]);
        $row = $st->fetch();
        if (!$row) Json::fail("评论不在了");
        if ($id > 0 && (int) $row["moment_id"] !== $id) Json::fail("评论和动态对不上");

        $role = Compat::roleOfPublic($pdo, $me);
        $isAdmin = $role === "admin" || $role === "super";
        $isAuthor = (int) $row["member_id"] === (int) $me["id"];
        if (!$isAdmin && !$isAuthor) Json::fail("只能删自己的评论");

        $st = $pdo->prepare("UPDATE moment_comments SET deleted_at = ?, deleted_by = ? WHERE id = ?");
        $st->execute([lg_now(), (int) $me["id"], $cid]);
        Json::ok(["deleted" => true]);
    }

    private static function report(PDO $pdo, array $scope, array $me): void {
        $id = Input::int("id", 0);
        $row = self::find($pdo, $scope, $id);
        if (!$row) Json::fail("这条动态不在了");
        $reason = trim((string) (Input::body()["reason"] ?? ""));
        if ($reason === "") Json::fail("写一句举报原因");
        if (mb_strlen($reason) > 300) $reason = mb_substr($reason, 0, 300);
        $commentId = Input::int("comment_id", 0);

        $st = $pdo->prepare("INSERT INTO moment_reports (scope_id, moment_id, comment_id, reporter_id, reason, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $st->execute([(int) $scope["id"], $id, $commentId, (int) $me["id"], $reason, lg_now()]);

        Notices::pushAdmins(
            $pdo,
            $scope,
            "report",
            "收到一条动态举报",
            (string) $me["name"] . " 举报了 " . (string) ($row["member_name"] ?? "一条动态") . "：\n" . $reason,
            "#/space",
            (int) $me["id"]
        );
        Json::ok(["reported" => true]);
    }

    // ---------- 推流开关（本群管理员可关） ----------

    private static function settings(PDO $pdo, array $scope, array $me): void {
        $role = Compat::roleOfPublic($pdo, $me);
        $isAdmin = $role === "admin" || $role === "super";
        $method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
        if ($method === "POST") {
            if (!$isAdmin) Json::fail("只有这个群的管理员能改");
            $on = Input::int("on", 1) === 1;
            self::setPush($pdo, (int) $scope["id"], $on);
        }
        Json::ok(["on" => self::pushMode($pdo, (int) $scope["id"]) > 0, "mode" => self::pushMode($pdo, (int) $scope["id"]), "admin" => $isAdmin]);
    }

    /**
     * 推荐算法开关（默认关闭）：0 按时间倒序 / 1 按兴趣推荐排序。
     * 打开后广场列表不再单纯按新到旧，而是「你赞过、评论过的人与标签」优先，
     * 再叠加热度和时间衰减——就是把人往自己的信息茧房里推。
     */
    private static function pushMode(PDO $pdo, int $scopeId): int {
        try {
            $st = $pdo->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
            $st->execute(["moment.push." . $scopeId]);
            $v = $st->fetchColumn();
            if ($v === false) return 0;
            return (int) $v === 1 ? 1 : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function setPush(PDO $pdo, int $scopeId, bool $on): void {
        $key = "moment.push." . $scopeId;
        $val = $on ? "1" : "0";
        $st = $pdo->prepare("SELECT 1 FROM settings WHERE k = ?");
        $st->execute([$key]);
        if ($st->fetchColumn()) {
            $st = $pdo->prepare("UPDATE settings SET v = ? WHERE k = ?");
            $st->execute([$val, $key]);
        } else {
            $st = $pdo->prepare("INSERT INTO settings (k, v) VALUES (?, ?)");
            $st->execute([$key, $val]);
        }
    }

    // ---------- 工具 ----------

    private static function find(PDO $pdo, array $scope, int $id): ?array {
        if ($id <= 0) return null;
        $ids = Compat::scopeIdsPublic($pdo, $scope);
        $in = implode(",", array_fill(0, count($ids), "?"));
        $st = $pdo->prepare("SELECT m.*, mb.name AS member_name FROM moments m LEFT JOIN members mb ON mb.id = m.member_id
            WHERE m.id = ? AND m.scope_id IN ($in) AND m.deleted_at IS NULL");
        $st->execute(array_merge([$id], $ids));
        $row = $st->fetch();
        return $row ?: null;
    }

    private static function okImage(string $url): bool {
        $url = trim($url);
        if ($url === "" || mb_strlen($url) > 500) return false;
        if (preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $url)) return true;
        return (bool) preg_match('#^https?://[^\s"\'<>]+$#', $url);
    }

    private static function decodeImages(string $raw): array {
        if ($raw === "") return [];
        return array_values(array_filter(array_map("trim", explode("\n", $raw))));
    }

    private static function decodeTags(string $raw): array {
        if ($raw === "") return [];
        return array_values(array_filter(array_map("trim", explode("#", $raw))));
    }

    /** 标签从正文里的 #xxx 提取，另外也接受显式传的 tags 数组（去重、限长） */
    private static function tagsOf(string $text, array $extra): array {
        $tags = [];
        if (preg_match_all('/#([^\s#]{1,16})/u', $text, $m)) {
            foreach ($m[1] as $t) $tags[] = $t;
        }
        foreach ($extra as $t) {
            $t = trim((string) $t, "# ");
            if ($t !== "") $tags[] = mb_substr($t, 0, 16);
        }
        $tags = array_values(array_unique($tags));
        return $tags;
    }
}
