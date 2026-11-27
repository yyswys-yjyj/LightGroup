<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Http\Json;
use PDO;

/**
 * 私群：一群人自己拉的小群，群主 / 管理员 / 成员三种角色。
 *
 *   GET  api/groups          我加入的私群列表（联系人 → 群聊）
 *   POST api/groups/create   建群：title + members（除自己外至少 3 人）
 *   GET  api/groups/{id}     群资料 + 成员 + 我的角色
 *   POST api/groups/{id}     操作：rename / notice / add / remove / role / transfer / leave / dissolve
 *
 * 私群只落在所属作用域里，会话不进公开大群的消息流；管理员在前台看不到别人的私群，
 * 后台「会话审计」能查到（那边直接查 conversations 全表）。
 */
final class PrivateGroup {
    private const TITLE_MAX  = 24;
    private const NOTICE_MAX = 500;
    private const MIN_INVITE = 3;   // 建群时除群主外至少要拉的人

    public static function handle(PDO $pdo, array $scope, string $path): void {
        $me = Compat::requireMemberPublic($pdo);
        if ((int) ($me["scope_id"] ?? 0) !== (int) $scope["id"]) {
            Json::fail("这里是「" . (string) ($scope["title"] ?? "") . "」，你的身份不在这群里");
        }
        $method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));

        if ($path === "" || $path === "create") {
            if ($path === "create") {
                if ($method !== "POST") Json::fail("只支持 POST");
                if (!Features::pgCreate($pdo, (int) $scope["id"])) Json::fail("这个群没有开放创建私群", 403);
                self::create($pdo, $scope, $me);
                return;
            }
            if ($method !== "GET") Json::fail("只支持 GET");
            self::listMine($pdo, $scope, $me);
            return;
        }

        $id = (int) $path;
        if ($id <= 0) Json::fail("未知接口");
        if ($method === "GET") {
            self::detail($pdo, $scope, $me, $id);
            return;
        }
        if ($method !== "POST") Json::fail("只支持 GET / POST");
        self::op($pdo, $scope, $me, $id);
    }

    // ---------------------------------------------------------------- 读

    private static function listMine(PDO $pdo, array $scope, array $me): void {
        Json::ok([
            "groups" => Conv::myPrivateGroups($pdo, $scope, (int) $me["id"]),
            "caps"   => [
                "member" => self::cap($pdo, $scope, "member"),
                "size"   => self::cap($pdo, $scope, "size"),
            ],
            "perm"   => [
                "create" => Features::pgCreate($pdo, (int) $scope["id"]),
            ] + Perm::forClient($pdo, (int) $scope["id"]),
        ]);
    }

    private static function detail(PDO $pdo, array $scope, array $me, int $id): void {
        $conv = self::load($pdo, $scope, $me, $id);
        $role = Conv::roleIn($pdo, $id, (int) $me["id"]);
        $members = Conv::membersWithRole($pdo, $conv);
        if ($role === "") {
            // 扫群二维码但还没进群：只告诉他是哪个群、多少人，成员名单和公告都不出
            Json::ok([
                "group" => [
                    "id"       => (int) $conv["id"],
                    "title"    => (string) ($conv["title"] ?? ""),
                    "members"  => count($members),
                    "isMember" => false,
                ],
                "members" => [],
            ]);
            return;
        }
        $perm = Perm::forClientConv($pdo, $conv);
        // 成员列表也受权限组管；群主/管理员要管理成员，始终能看
        $showMembers = $role !== "member" || ($perm["memberList"] ?? true);
        Json::ok([
            "group" => [
                "id"        => (int) $conv["id"],
                "title"     => (string) ($conv["title"] ?? ""),
                "notice"    => (string) ($conv["notice"] ?? ""),
                "noticeAt"  => (int) ($conv["notice_at"] ?? 0),
                "noticeBy"  => (int) ($conv["notice_by"] ?? 0),
                "role"      => $role,
                "ownerId"   => (int) ($conv["created_by"] ?? 0),
                "members"   => count($members),
                "createdAt" => (int) ($conv["created_at"] ?? 0),
                "isOwner"   => $role === "owner",
                "canManage" => $role === "owner" || $role === "admin",
                "isMember"  => true,
                "perm"      => $perm,
                "permGroupId" => (int) ($conv["perm_group_id"] ?? 0),
                "caps"      => [
                    "member" => self::cap($pdo, $scope, "member"),
                    "size"   => self::cap($pdo, $scope, "size"),
                ],
            ],
            "members" => $showMembers ? $members : [],
        ]);
    }

    // ---------------------------------------------------------------- 建群

    private static function create(PDO $pdo, array $scope, array $me): void {
        $body  = Input::body();
        $title = trim((string) ($body["title"] ?? ""));
        if ($title === "") Json::fail("给群起个名字");
        if (mb_strlen($title) > self::TITLE_MAX) Json::fail("群名最多 " . self::TITLE_MAX . " 个字");

        $raw = $body["members"] ?? [];
        if (!is_array($raw)) $raw = [];
        $ids = [];
        foreach ($raw as $v) {
            $mid = self::parseId($v);
            if ($mid > 0 && $mid !== (int) $me["id"]) $ids[$mid] = true;
        }
        if (count($ids) < self::MIN_INVITE) {
            Json::fail("至少拉 " . self::MIN_INVITE . " 个人或 AI 才能建群");
        }

        $cap = self::cap($pdo, $scope, "member");
        if ($cap > 0) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM conversations WHERE scope_id = ? AND kind = 'pg' AND created_by = ? AND dissolved_at IS NULL");
            $st->execute([(int) $scope["id"], (int) $me["id"]]);
            if ((int) $st->fetchColumn() >= $cap) {
                Json::fail("你在这个群最多能建 " . $cap . " 个私群");
            }
        }
        $sizeCap = self::cap($pdo, $scope, "size");
        if ($sizeCap > 0 && count($ids) + 1 > $sizeCap) {
            Json::fail("这个群最多 " . $sizeCap . " 个人");
        }

        self::checkTargets($pdo, $scope, array_keys($ids));

        $cid = Conv::createPrivate($pdo, $scope, (int) $me["id"], $title, array_keys($ids));
        Compat::systemMessage($pdo, (int) $scope["id"], $cid, (int) $me["id"], "「" . (string) $me["name"] . "」创建了群聊");
        foreach (array_keys($ids) as $mid) {
            Notices::pushToMember(
                $pdo,
                $mid,
                "group",
                "你被拉进了群「" . $title . "」",
                "「" . (string) $me["name"] . "」把你拉进了私群「" . $title . "」，点开就能看到群里的消息。",
                "#/c/" . $cid,
                (int) $me["id"]
            );
        }
        Json::ok([
            "id"      => $cid,
            "title"   => $title,
            "members" => count($ids) + 1,
        ]);
    }

    // ---------------------------------------------------------------- 操作

    private static function op(PDO $pdo, array $scope, array $me, int $id): void {
        $conv = self::load($pdo, $scope, $me, $id);
        $myId = (int) $me["id"];
        $role = Conv::roleIn($pdo, $id, $myId);
        if ($role === "") Json::fail("你不在这个群里");
        $body = Input::body();
        $op   = (string) ($body["op"] ?? "");

        switch ($op) {
            case "rename":
                self::mustOwner($role, "只有群主能改群名");
                if (!Perm::canForConv($pdo, $conv, "group.rename")) Json::fail("这个群没有开放修改群资料", 403);
                $title = trim((string) ($body["title"] ?? ""));
                if ($title === "") Json::fail("群名不能是空的");
                if (mb_strlen($title) > self::TITLE_MAX) Json::fail("群名最多 " . self::TITLE_MAX . " 个字");
                $pdo->prepare("UPDATE conversations SET title = ? WHERE id = ?")->execute([$title, $id]);
                self::note($pdo, $scope, $id, $myId, "群名改成了「" . $title . "」");
                Json::ok(["title" => $title]);
                return;

            case "notice":
                self::mustAdmin($role, "只有群主或管理员能发群公告");
                if (!Perm::canForConv($pdo, $conv, "group.notice")) Json::fail("这个群没有开放群公告", 403);
                $notice = trim((string) ($body["notice"] ?? ""));
                if (mb_strlen($notice) > self::NOTICE_MAX) Json::fail("公告最多 " . self::NOTICE_MAX . " 个字");
                $now = lg_now();
                $pdo->prepare("UPDATE conversations SET notice = ?, notice_at = ?, notice_by = ? WHERE id = ?")
                    ->execute([$notice, $notice === "" ? 0 : $now, $notice === "" ? null : $myId, $id]);
                if ($notice !== "") {
                    self::note($pdo, $scope, $id, $myId, "发布了新公告");
                }
                Json::ok(["notice" => $notice]);
                return;

            case "add":
                // 群主/管理员不受限；普通成员邀请要看权限组
                if ($role === "member" && !Perm::canForConv($pdo, $conv, "group.invite")) {
                    Json::fail("这个群没有开放成员邀请，请让群主或管理员拉人", 403);
                }
                // 人数上限与对方意愿仍然要守
                $raw = $body["members"] ?? [];
                if (!is_array($raw)) $raw = [];
                $ids = [];
                foreach ($raw as $v) {
                    $mid = self::parseId($v);
                    if ($mid > 0) $ids[$mid] = true;
                }
                if (!$ids) Json::fail("先选要拉进来的人");
                $old = Conv::memberIds($pdo, $conv);
                $fresh = array_values(array_diff(array_keys($ids), $old));
                if (!$fresh) Json::fail("这些人已经在群里了");
                $sizeCap = self::cap($pdo, $scope, "size");
                if ($sizeCap > 0 && count($old) + count($fresh) > $sizeCap) {
                    Json::fail("这个群最多 " . $sizeCap . " 个人");
                }
                self::checkTargets($pdo, $scope, $fresh);
                foreach ($fresh as $mid) {
                    Conv::addMember($pdo, $id, $mid, $myId);
                    Notices::pushToMember(
                        $pdo,
                        $mid,
                        "group",
                        "你被拉进了群「" . (string) $conv["title"] . "」",
                        "「" . (string) $me["name"] . "」把你拉进了私群「" . (string) $conv["title"] . "」。",
                        "#/c/" . $id,
                        $myId
                    );
                }
                self::note($pdo, $scope, $id, $myId, "邀请 " . count($fresh) . " 位成员加入群聊");
                Json::ok(["added" => count($fresh)]);
                return;

            case "remove":
                self::mustAdmin($role, "只有群主或管理员能移出成员");
                if (!Perm::canForConv($pdo, $conv, "group.admin")) Json::fail("这个群没有开放成员管理", 403);
                $target = self::parseId($body["member"] ?? 0);
                if ($target <= 0) Json::fail("缺少成员");
                if ($target === (int) ($conv["created_by"] ?? 0)) Json::fail("不能移出群主");
                if ($target === $myId) Json::fail("要退出请用「退出群聊」");
                $targetRole = Conv::roleIn($pdo, $id, $target);
                if ($targetRole === "") Json::fail("这个人不在群里");
                if ($role === "admin" && $targetRole === "admin") Json::fail("管理员不能移出别的管理员");
                Conv::removeMember($pdo, $id, $target);
                self::note($pdo, $scope, $id, $myId, "把「" . Conv::nameOf($pdo, $target) . "」移出了群聊");
                Notices::pushToMember($pdo, $target, "group", "你被移出了群「" . (string) $conv["title"] . "」", "「" . (string) $me["name"] . "」把你移出了这个私群。", "", $myId);
                Json::ok(["removed" => $target]);
                return;

            case "role":
                self::mustOwner($role, "只有群主能设置管理员");
                if (!Perm::canForConv($pdo, $conv, "group.admin")) Json::fail("这个群没有开放设置管理员", 403);
                $target = self::parseId($body["member"] ?? 0);
                $want   = (string) ($body["role"] ?? "member");
                if (!in_array($want, ["admin", "member"], true)) Json::fail("不认识的成员角色");
                if ($target <= 0) Json::fail("缺少成员");
                if ($target === (int) ($conv["created_by"] ?? 0)) Json::fail("群主不能被改角色");
                if (Conv::roleIn($pdo, $id, $target) === "") Json::fail("这个人不在群里");
                Conv::setRole($pdo, $id, $target, $want);
                self::note($pdo, $scope, $id, $myId, ($want === "admin" ? "把「" : "取消「") . Conv::nameOf($pdo, $target) . ($want === "admin" ? "」设成了管理员" : "」的管理员"));
                Json::ok(["member" => $target, "role" => $want]);
                return;

            case "transfer":
                self::mustOwner($role, "只有群主能转让群主");
                $target = self::parseId($body["member"] ?? 0);
                if ($target <= 0 || $target === $myId) Json::fail("选一个别的成员");
                if (Conv::roleIn($pdo, $id, $target) === "") Json::fail("这个人不在群里");
                Conv::setRole($pdo, $id, $myId, "admin");
                Conv::setRole($pdo, $id, $target, "owner");
                $pdo->prepare("UPDATE conversations SET created_by = ? WHERE id = ?")->execute([$target, $id]);
                self::note($pdo, $scope, $id, $myId, "把群主转让给了「" . Conv::nameOf($pdo, $target) . "」");
                Json::ok(["owner" => $target]);
                return;

            case "leave":
                if ($role === "owner") Json::fail("群主不能直接退出，请先转让群主或解散群聊");
                Conv::removeMember($pdo, $id, $myId);
                self::note($pdo, $scope, $id, $myId, "「" . (string) $me["name"] . "」退出了群聊");
                Json::ok(["left" => true]);
                return;

            case "dissolve":
                self::mustOwner($role, "只有群主能解散群聊");
                $pdo->prepare("UPDATE conversations SET dissolved_at = ? WHERE id = ?")->execute([lg_now(), $id]);
                self::note($pdo, $scope, $id, $myId, "解散了群聊");
                Json::ok(["dissolved" => true]);
                return;
        }
        Json::fail("未知操作");
    }

    // ---------------------------------------------------------------- 工具

    /** 取私群（同群、未解散）；能不能看内容由调用方按 role 判断 */
    private static function load(PDO $pdo, array $scope, array $me, int $id): array {
        $conv = Conv::convById($pdo, $id);
        if ($conv === null || (string) ($conv["kind"] ?? "") !== "pg") Json::fail("私群不存在");
        if ((int) ($conv["scope_id"] ?? 0) !== (int) $scope["id"]) Json::fail("私群不存在");
        if (!empty($conv["dissolved_at"])) Json::fail("这个群已经解散了");
        return $conv;
    }

    private static function mustOwner(string $role, string $msg): void {
        if ($role !== "owner") Json::fail($msg);
    }

    private static function mustAdmin(string $role, string $msg): void {
        if ($role !== "owner" && $role !== "admin") Json::fail($msg);
    }

    /** 被拉的人必须同群、活着、允许被拉 */
    private static function checkTargets(PDO $pdo, array $scope, array $ids): void {
        $st = $pdo->prepare("SELECT id, name, allow_invite, deleted_at FROM members WHERE id = ? AND scope_id = ? LIMIT 1");
        foreach ($ids as $mid) {
            $mid = (int) $mid;
            $st->execute([$mid, (int) $scope["id"]]);
            $row = $st->fetch();
            if (!$row || !empty($row["deleted_at"])) Json::fail("有一个成员不在这群里");
            if ((int) ($row["allow_invite"] ?? 1) !== 1) {
                Json::fail("「" . (string) $row["name"] . "」不接受被拉进群");
            }
        }
    }

    /** 有效配额：作用域配了就用作用域的，没配（0）看站点，站点 0 = 不限 */
    private static function cap(PDO $pdo, array $scope, string $kind): int {
        $col = $kind === "size" ? "pg_size_cap" : "pg_member_cap";
        $v = (int) ($scope[$col] ?? 0);
        if ($v > 0) return $v;
        $key = $kind === "size" ? "pg.size_cap" : "pg.member_cap";
        try {
            $st = $pdo->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
            $st->execute([$key]);
            $raw = $st->fetchColumn();
            return $raw === false ? 0 : max(0, (int) $raw);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function note(PDO $pdo, array $scope, int $convId, int $by, string $what): void {
        Compat::systemMessage($pdo, (int) $scope["id"], $convId, $by, $what);
    }

    /** 前端可能送 h_5 / a_7，也可能送数字 */
    private static function parseId(mixed $v): int {
        if (is_int($v)) return max(0, $v);
        $s = trim((string) $v);
        if ($s !== "" && ($s[0] === "h" || $s[0] === "a") && ($s[1] ?? "") === "_") $s = substr($s, 2);
        return ctype_digit($s) ? (int) $s : 0;
    }
}
