<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Http\Json;
use PDO;
use RuntimeException;

/**
 * 账号注销：成员自助注销 + 管理员恢复 / 彻底删除。
 *
 * 自助注销挂在 /login 上（public/index.php 里只有这一个不用改路由就能接的入口）：
 * POST /login，Content-Type: application/json，body {"action":"account.delete","name":"<昵称>"}。
 * 只收 JSON：跨站表单没法带这个 Content-Type（要过 CORS 预检），顺手挡掉 CSRF。
 *
 * 注销是软删除：members.deleted_at 记时间、deleted_name 留原名、name 改成「已注销」。
 * 改名的原因有两个：
 *  - idx_members_name 是 (scope_id, name) 唯一索引，不改名昵称腾不出来；
 *  - 私聊标题读 members.name，改名之后对方看到的就是「已注销」。
 * 消息不动（Compat 会按 deleted_at 显示「账号已注销」），管理员还能恢复。
 *
 * 恢复 / 彻底删除只提供后端函数，后台界面由 admin 那条线接：
 *   Account::restore($pdo, $adminMember, $memberId)
 *   Account::purge($pdo, $adminMember, $memberId)
 * 两个都返回数组，出错抛 RuntimeException（中文文案）。$adminMember 传 Session::member()
 * 拿到的行（或带 role / account_id 的数组）即可。
 */
final class Account {
    /** 注销后统一显示的墓碑昵称 */
    private const TOMB = "已注销";

    /** /login 上的 account.* 请求入口。正常返回走 Json::ok，出错走 Json::fail。 */
    public static function handle(PDO $pdo, string $action): void {
        $ct = strtolower((string) ($_SERVER["CONTENT_TYPE"] ?? ""));
        if (strpos($ct, "application/json") === false) {
            Json::fail("请求格式不对，刷新页面再试");
        }
        $me = Session::member($pdo);
        if ($me === null) Json::fail("登录已失效，请重新登录");
        if ($action !== "account.delete") Json::fail("不认识的请求");

        try {
            self::selfDelete($pdo, $me, Input::get("name"));
        } catch (RuntimeException $ex) {
            Json::fail($ex->getMessage());
        }
        // 软删除成功后清掉网页登录态，前端会跳回登录页
        Session::logout();
        Json::ok(["msg" => "账号已注销"]);
    }

    /**
     * 成员自己注销。$name 必须和当前昵称一字不差，防手滑。
     */
    private static function selfDelete(PDO $pdo, array $me, string $name): void {
        $id = (int) $me["id"];
        if ((string) $me["kind"] !== "human") {
            throw new RuntimeException("AI 账号不能自己注销，让主人处理");
        }
        if (!empty($me["account_id"])) {
            throw new RuntimeException("这是管理员账号，不能在网页上注销");
        }
        if ((string) $me["name"] !== $name) {
            throw new RuntimeException("昵称对不上，没注销");
        }
        $now  = lg_now();
        $tomb = self::tombName($pdo, (int) $me["scope_id"], $id);
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE members SET name = ?, deleted_name = ?, deleted_at = ?, pass_hash = NULL, token_hash = NULL WHERE id = ?"
            )->execute([$tomb, (string) $me["name"], $now, $id]);
            // 插件与 AI 的 API Key 全部作废
            $pdo->prepare("UPDATE api_keys SET revoked_at = ? WHERE member_id = ? AND revoked_at IS NULL")->execute([$now, $id]);
            // 好友关系是双向的，两条都清
            $pdo->prepare("DELETE FROM friends WHERE a_id = ? OR b_id = ?")->execute([$id, $id]);
            // 退出所有会话：私聊会从对方的列表里消失，大群里的历史消息还在
            $pdo->prepare("DELETE FROM conv_members WHERE member_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM todo_done WHERE member_id = ?")->execute([$id]);
            // 名下的 AI 一起停掉：主人没了，token 不能再留着
            $pdo->prepare("UPDATE members SET banned = 1 WHERE owner_id = ?")->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new RuntimeException("注销没成功，稍后再试");
        }
    }

    /**
     * 管理员恢复一个注销的成员：把原名还回去，解除删除与封禁。
     * 原名被别人占了就恢复不了（唯一索引），只能彻底删除。
     */
    public static function restore(PDO $pdo, array $actor, int $memberId): array {
        self::assertAdmin($pdo, $actor, "恢复");
        $m = self::member($pdo, $memberId);
        self::assertScope($pdo, $actor, $m);
        if (empty($m["deleted_at"])) throw new RuntimeException("这个成员没注销，不用恢复");
        if (!empty($m["account_id"])) throw new RuntimeException("这是管理员账号，请用账号管理处理");
        $old = (string) ($m["deleted_name"] ?? "");
        if ($old === "") throw new RuntimeException("这个成员没留原名（后台直接删的），恢复不了，只能彻底删除");

        // 同名（含已注销的墓碑之外的）任何一行都会撞唯一索引
        $st = $pdo->prepare("SELECT id FROM members WHERE scope_id = ? AND name = ? LIMIT 1");
        $st->execute([(int) $m["scope_id"], $old]);
        if ($st->fetch()) {
            throw new RuntimeException("昵称「" . $old . "」已经被别人用了，没法恢复，只能彻底删除");
        }
        $pdo->prepare("UPDATE members SET name = ?, deleted_name = NULL, deleted_at = NULL, banned = 0 WHERE id = ?")
            ->execute([$old, $memberId]);
        // 名下的 AI 一起解封
        $pdo->prepare("UPDATE members SET banned = 0 WHERE owner_id = ?")->execute([$memberId]);
        return ["ok" => true, "member_id" => $memberId, "name" => $old];
    }

    /**
     * 彻底删除：成员行与消息一起清掉，不可恢复。
     * 只处理已经注销（deleted_at 非空）的成员，先软删再彻底删，别让一次误点把在线成员带走。
     * 名下的 AI 一并删除；引用过这些消息的 reply_to 置空，避免留下空引用。
     */
    public static function purge(PDO $pdo, array $actor, int $memberId): array {
        self::assertAdmin($pdo, $actor, "彻底删除");
        $m = self::member($pdo, $memberId);
        self::assertScope($pdo, $actor, $m);
        if (!empty($m["account_id"])) throw new RuntimeException("这是管理员账号，不能在这里彻底删除");
        if (empty($m["deleted_at"])) throw new RuntimeException("先注销这个成员，再彻底删除");

        // 成员本人 + 名下的 AI
        $ids = [$memberId];
        $st = $pdo->prepare("SELECT id FROM members WHERE owner_id = ?");
        $st->execute([$memberId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $row) $ids[] = (int) $row;
        $in = implode(",", array_fill(0, count($ids), "?"));

        $pdo->beginTransaction();
        try {
            // 先找消息与它们所属的会话（purge 之后要重算会话最后一条）
            $st = $pdo->prepare("SELECT id, conv_id FROM messages WHERE member_id IN (" . $in . ")");
            $st->execute($ids);
            $msgIds = [];
            $convs  = [];
            foreach ($st->fetchAll() as $row) {
                $msgIds[] = (int) $row["id"];
                if (!empty($row["conv_id"])) $convs[(int) $row["conv_id"]] = true;
            }
            foreach (array_chunk($msgIds, 400) as $chunk) {
                $q = implode(",", array_fill(0, count($chunk), "?"));
                $pdo->prepare("UPDATE messages SET reply_to = NULL WHERE reply_to IN (" . $q . ")")->execute($chunk);
            }
            $pdo->prepare("DELETE FROM api_keys WHERE member_id IN (" . $in . ")")->execute($ids);
            $pdo->prepare("DELETE FROM conv_members WHERE member_id IN (" . $in . ")")->execute($ids);
            $pdo->prepare("DELETE FROM friends WHERE a_id IN (" . $in . ") OR b_id IN (" . $in . ")")->execute($ids);
            $pdo->prepare("DELETE FROM todo_done WHERE member_id IN (" . $in . ")")->execute($ids);
            $pdo->prepare("DELETE FROM todos WHERE created_by IN (" . $in . ")")->execute($ids);
            $pdo->prepare("DELETE FROM theme_packs WHERE owner_id IN (" . $in . ")")->execute($ids);
            $pdo->prepare("DELETE FROM messages WHERE member_id IN (" . $in . ")")->execute($ids);
            foreach (array_chunk(array_keys($convs), 200) as $chunk) {
                $q = implode(",", array_fill(0, count($chunk), "?"));
                $pdo->prepare(
                    "UPDATE conversations SET"
                    . " last_msg_id = COALESCE((SELECT MAX(m.id) FROM messages m WHERE m.conv_id = conversations.id AND m.deleted_at IS NULL), 0),"
                    . " last_msg_at = COALESCE((SELECT MAX(m.ts_ms) FROM messages m WHERE m.conv_id = conversations.id), 0)"
                    . " WHERE id IN (" . $q . ")"
                )->execute($chunk);
            }
            $pdo->prepare("DELETE FROM members WHERE id IN (" . $in . ")")->execute($ids);
            $pdo->commit();
        } catch (\Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new RuntimeException("彻底删除没成功，稍后再试");
        }
        return ["ok" => true, "deleted" => count($ids), "name" => (string) ($m["deleted_name"] ?? $m["name"])];
    }

    // ---------- 内部 ----------

    private static function member(PDO $pdo, int $id): array {
        if ($id <= 0) throw new RuntimeException("成员不对");
        $st = $pdo->prepare("SELECT * FROM members WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException("找不到这个成员");
        return $row;
    }

    /** 管理员判断：super 管全站，admin 只管自己那个群。$actor 可以是 members 行或 accounts 行 */
    private static function assertAdmin(PDO $pdo, array $actor, string $what): void {
        $role = (string) ($actor["role"] ?? "");
        if ($role === "") {
            $aid = (int) ($actor["account_id"] ?? 0);
            if ($aid <= 0 && !empty($actor["id"]) && (string) ($actor["kind"] ?? "") !== "human") {
                // 传进来的可能就是 accounts 行
                $aid = (int) $actor["id"];
            }
            if ($aid > 0) {
                $st = $pdo->prepare("SELECT role, scope_id FROM accounts WHERE id = ? LIMIT 1");
                $st->execute([$aid]);
                $a = $st->fetch();
                if ($a) {
                    $role = (string) $a["role"];
                    if (!isset($actor["scope_id"])) $actor["scope_id"] = $a["scope_id"];
                }
            }
        }
        if ($role !== "super" && $role !== "admin") {
            throw new RuntimeException("只有管理员能" . $what . "成员");
        }
        // super 不设群限制；admin 必须是同一个群，由 assertScope 再看
        if ($role === "super") return;
        if ((int) ($actor["scope_id"] ?? 0) <= 0) {
            throw new RuntimeException("看不出你在哪个群，不能" . $what . "成员");
        }
    }

    /**
     * 群范围判断。$actor 里没有 role 的时候（传的是 members 行）先用 account_id 反查 accounts：
     * assertAdmin 里补出来的 role/scope_id 只在它自己的函数作用域里，这里必须再查一次。
     */
    private static function assertScope(PDO $pdo, array $actor, array $target): void {
        $role  = (string) ($actor["role"] ?? "");
        $where = (int) ($actor["scope_id"] ?? 0);
        if ($role === "") {
            $aid = (int) ($actor["account_id"] ?? 0);
            if ($aid <= 0 && !empty($actor["id"]) && (string) ($actor["kind"] ?? "") !== "human") {
                $aid = (int) $actor["id"];
            }
            if ($aid > 0) {
                $st = $pdo->prepare("SELECT role, scope_id FROM accounts WHERE id = ? LIMIT 1");
                $st->execute([$aid]);
                $a = $st->fetch();
                if ($a) {
                    $role  = (string) $a["role"];
                    $where = (int) $a["scope_id"];
                }
            }
        }
        if ($role === "super") return;
        if ($where !== (int) $target["scope_id"]) {
            throw new RuntimeException("这个成员不在你管的群里");
        }
    }

    /** 墓碑昵称：默认「已注销」，被占了（之前也有人注销过）就补编号 */
    private static function tombName(PDO $pdo, int $scopeId, int $memberId): string {
        $st = $pdo->prepare("SELECT COUNT(*) FROM members WHERE scope_id = ? AND name = ?");
        for ($i = 0; $i < 60; $i++) {
            $name = $i === 0 ? self::TOMB : self::TOMB . "（" . $memberId . "-" . $i . "）";
            $st->execute([$scopeId, $name]);
            if ((int) $st->fetchColumn() === 0) return $name;
        }
        // 极端情况下用 id 直接兜底，id 本身在群内唯一
        return self::TOMB . "（" . $memberId . "）";
    }
}
