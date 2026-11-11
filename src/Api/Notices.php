<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Http\Json;
use PDO;

/**
 * 站内信 + 邮箱订阅。
 * 站内信不是聊天消息：空间动态推流、举报提醒这类系统通知走这里，
 * 私聊和群聊消息不发站内信（也就不发邮件）。
 */
final class Notices {
    public static function handle(PDO $pdo, array $scope, string $path): void {
        $me = Compat::requireMemberPublic($pdo);
        $scopeId = (int) $scope["id"];

        if ($path === "notices") {
            self::listOrRead($pdo, $scopeId, $me);
            return;
        }
        if ($path === "subscribe") {
            self::subscribe($pdo, $me);
            return;
        }
        if ($path === "subscribe/verify") {
            self::verify($pdo, $me);
            return;
        }
        Json::fail("未知接口");
    }

    // ---------- 站内信 ----------

    private static function listOrRead(PDO $pdo, int $scopeId, array $me): void {
        $method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
        if ($method === "POST") {
            $body = Input::body();
            $op = (string) ($body["op"] ?? "read");
            if ($op === "readAll") {
                // 个人的通知按 member_id 认，不按作用域：超管在哪个群看到的都是同一批
                $st = $pdo->prepare("UPDATE notices SET read_at = ? WHERE (member_id = ? OR (member_id = 0 AND scope_id = ?)) AND read_at IS NULL");
                $st->execute([lg_now(), (int) $me["id"], $scopeId]);
            } else {
                $id = (int) ($body["id"] ?? 0);
                if ($id <= 0) Json::fail("要指定是哪一条");
                $st = $pdo->prepare("UPDATE notices SET read_at = ? WHERE id = ? AND (member_id = ? OR (member_id = 0 AND scope_id = ?)) AND read_at IS NULL");
                $st->execute([lg_now(), $id, (int) $me["id"], $scopeId]);
            }
            self::send($pdo, $scopeId, $me);
            return;
        }
        self::send($pdo, $scopeId, $me);
    }

    private static function send(PDO $pdo, int $scopeId, array $me): void {
        if (Input::int("count", 0) === 1) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM notices WHERE (member_id = ? OR (member_id = 0 AND scope_id = ?)) AND read_at IS NULL");
            $st->execute([(int) $me["id"], $scopeId]);
            Json::ok(["unread" => (int) $st->fetchColumn()]);
            return;
        }
        $st = $pdo->prepare("SELECT * FROM notices WHERE (member_id = ? OR (member_id = 0 AND scope_id = ?)) ORDER BY id DESC LIMIT 50");
        $st->execute([(int) $me["id"], $scopeId]);
        $rows = $st->fetchAll();

        $fromIds = [];
        foreach ($rows as $r) {
            if ((int) ($r["from_id"] ?? 0) > 0) $fromIds[(int) $r["from_id"]] = true;
        }
        $names = [];
        if ($fromIds) {
            $in = implode(",", array_fill(0, count($fromIds), "?"));
            $q = $pdo->prepare("SELECT id, name FROM members WHERE id IN ($in)");
            $q->execute(array_keys($fromIds));
            foreach ($q->fetchAll() as $r) $names[(int) $r["id"]] = (string) $r["name"];
        }

        $items = [];
        $unread = 0;
        foreach ($rows as $r) {
            $read = $r["read_at"] !== null;
            if (!$read) $unread++;
            $items[] = [
                "id"    => (int) $r["id"],
                "kind"  => (string) $r["kind"],
                "title" => (string) $r["title"],
                "body"  => (string) ($r["body"] ?? ""),
                "link"  => (string) ($r["link"] ?? ""),
                "from"  => $names[(int) ($r["from_id"] ?? 0)] ?? "",
                "ts"    => (int) $r["created_at"],
                "read"  => $read,
            ];
        }
        Json::ok(["items" => $items, "unread" => $unread]);
    }

    /** 给一个成员发站内信；订阅过邮箱并且总开关开着，会同时发一封邮件 */
    public static function push(PDO $pdo, array $scope, int $memberId, string $kind, string $title, string $body, string $link = "", int $fromId = 0): void {
        if ($memberId <= 0) return;
        $st = $pdo->prepare("INSERT INTO notices (scope_id, member_id, kind, title, body, link, from_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([(int) $scope["id"], $memberId, $kind, $title, $body, $link, $fromId, lg_now()]);
        self::mailTo($pdo, $memberId, $title, $body, $link);
    }

    /**
     * 给一个成员发站内信，但不需要知道他在哪个群。
     * 好友申请、同意、拒绝这些是「人 to 人」，收件人可能不在发起人当前的作用域里。
     */
    public static function pushToMember(PDO $pdo, int $memberId, string $kind, string $title, string $body, string $link = "", int $fromId = 0): void {
        if ($memberId <= 0) return;
        $scopeId = 0;
        $st = $pdo->prepare("SELECT scope_id FROM members WHERE id = ? LIMIT 1");
        $st->execute([$memberId]);
        $row = $st->fetch();
        if ($row) $scopeId = (int) $row["scope_id"];
        $ins = $pdo->prepare("INSERT INTO notices (scope_id, member_id, kind, title, body, link, from_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$scopeId, $memberId, $kind, $title, $body, $link, $fromId, lg_now()]);
        self::mailTo($pdo, $memberId, $title, $body, $link);
    }

    /** 发给这个群里所有管理员（举报提醒用） */
    public static function pushAdmins(PDO $pdo, array $scope, string $kind, string $title, string $body, string $link = "", int $fromId = 0): void {
        $st = $pdo->prepare("SELECT m.* FROM members m JOIN accounts a ON a.id = m.account_id
            WHERE m.scope_id = ? AND m.deleted_at IS NULL AND a.role IN ('admin', 'super')");
        $st->execute([(int) $scope["id"]]);
        $ids = [];
        foreach ($st->fetchAll() as $r) $ids[(int) $r["id"]] = true;
        // 超管是所有作用域共同成员，只在主群有一行成员，要单独补上
        $sup = $pdo->query("SELECT m.id FROM members m JOIN accounts a ON a.id = m.account_id
            WHERE a.role = 'super' AND m.deleted_at IS NULL ORDER BY m.id ASC LIMIT 1")->fetchColumn();
        if ($sup) $ids[(int) $sup] = true;
        foreach (array_keys($ids) as $mid) {
            self::push($pdo, $scope, (int) $mid, $kind, $title, $body, $link, $fromId);
        }
    }

    private static function mailTo(PDO $pdo, int $memberId, string $title, string $body, string $link): void {
        if (!Mailer::notifyOn($pdo)) return;
        $st = $pdo->prepare("SELECT email FROM notice_subs WHERE member_id = ? AND verified_at IS NOT NULL LIMIT 1");
        $st->execute([$memberId]);
        $email = $st->fetchColumn();
        if (!$email) return;
        $esc = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, "UTF-8");
        $html = "<div style=\"font-family:system-ui,-apple-system,'PingFang SC',sans-serif;line-height:1.7\">"
            . "<h2 style=\"margin:0 0 10px;font-size:17px\">" . $esc($title) . "</h2>"
            . "<p style=\"margin:0;white-space:pre-wrap\">" . $esc($body) . "</p>"
            . ($link !== "" ? "<p style=\"margin:16px 0 0\"><a href=\"" . $esc($link) . "\">在站内查看</a></p>" : "")
            . "<p style=\"margin:20px 0 0;color:#8a93a0;font-size:12px\">你订阅了站内信邮件提醒，可以在「我的资料」里关掉。</p>"
            . "</div>";
        Mailer::sendLater($pdo, (string) $email, $title, $html);
    }

    // ---------- 邮箱订阅 ----------

    private static function subscribe(PDO $pdo, array $me): void {
        $mid = (int) $me["id"];
        $st = $pdo->prepare("SELECT * FROM notice_subs WHERE member_id = ? LIMIT 1");
        $st->execute([$mid]);
        $row = $st->fetch();

        $method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
        if ($method === "DELETE" || $method === "POST" && (string) (Input::body()["op"] ?? "") === "off") {
            $st = $pdo->prepare("DELETE FROM notice_subs WHERE member_id = ?");
            $st->execute([$mid]);
            Json::ok(["subscribed" => false]);
            return;
        }

        if ($method === "POST") {
            $email = (string) (Input::body()["email"] ?? "");
            if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) Json::fail("邮箱格式不对");
            if (!Mailer::notifyOn($pdo)) Json::fail("站点还没开邮件提醒，先找管理员");

            $code = (string) random_int(100000, 999999);
            $now = lg_now();
            if ($row) {
                $st = $pdo->prepare("UPDATE notice_subs SET email = ?, code = ?, verified_at = NULL, updated_at = ? WHERE member_id = ?");
                $st->execute([$email, $code, $now, $mid]);
            } else {
                $st = $pdo->prepare("INSERT INTO notice_subs (member_id, email, code, verified_at, created_at, updated_at) VALUES (?, ?, ?, NULL, ?, ?)");
                $st->execute([$mid, $email, $code, $now, $now]);
            }

            $esc = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, "UTF-8");
            $html = "<div style=\"font-family:system-ui,-apple-system,'PingFang SC',sans-serif;line-height:1.7\">"
                . "<h2 style=\"margin:0 0 10px;font-size:17px\">确认订阅站内信提醒</h2>"
                . "<p style=\"margin:0\">把下面这串验证码填回浏览器，订阅才会生效：</p>"
                . "<p style=\"margin:14px 0;font-size:26px;letter-spacing:6px;font-weight:700\">" . $esc($code) . "</p>"
                . "<p style=\"margin:0;color:#8a93a0;font-size:12px\">10 分钟内有效。不是你本人操作的，忽略这封邮件就行。</p>"
                . "</div>";
            try {
                Mailer::sendNow($pdo, $email, "确认订阅站内信提醒", $html);
            } catch (\Throwable $e) {
                Json::fail("激活邮件没发出去：" . $e->getMessage());
            }
            Json::ok(["subscribed" => false, "pending" => true, "email" => $email]);
            return;
        }

        Json::ok([
            "available"  => Mailer::notifyOn($pdo),
            "subscribed" => $row && $row["verified_at"] !== null,
            "pending"    => $row && $row["verified_at"] === null,
            "email"      => $row ? (string) $row["email"] : "",
        ]);
    }

    private static function verify(PDO $pdo, array $me): void {
        $mid = (int) $me["id"];
        $code = trim((string) (Input::body()["code"] ?? ""));
        if ($code === "") Json::fail("把邮件里的验证码填进来");

        $key = "subverify:" . $mid;
        $wait = Throttle::wait($pdo, $key, 10);
        if ($wait !== null) Json::fail("试太多次了，过 " . $wait . " 秒再来");

        $st = $pdo->prepare("SELECT * FROM notice_subs WHERE member_id = ? LIMIT 1");
        $st->execute([$mid]);
        $row = $st->fetch();
        if (!$row) Json::fail("先填邮箱发一次激活邮件");
        if ((int) $row["updated_at"] + 10 * 60 * 1000 < lg_now()) Json::fail("验证码过期了，重新发一封");

        if (!hash_equals((string) $row["code"], $code)) {
            Throttle::fail($pdo, $key);
            Json::fail("验证码不对");
        }
        $st = $pdo->prepare("UPDATE notice_subs SET verified_at = ?, code = '' WHERE member_id = ?");
        $st->execute([lg_now(), $mid]);
        Throttle::clear($pdo, $key);
        Json::ok(["subscribed" => true, "email" => (string) $row["email"]]);
    }
}
