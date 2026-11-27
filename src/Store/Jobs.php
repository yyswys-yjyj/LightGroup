<?php
declare(strict_types=1);

namespace LightGroup\Store;

use PDO;

/**
 * 后台任务：翻译、语音转文字这类要等外部接口的活，不放在 php-fpm 的请求线程里做。
 *
 * 请求只负责登记一条任务然后立刻返回，由 bin/job.php 的 CLI 进程去执行；
 * 客户端拿任务 id 轮询结果。这样翻译慢一点、识别慢一点也不会把 fpm worker 占住，
 * 别人打开页面、发消息不会被挤得排队。
 */
final class Jobs {
    public const STATUS_PENDING = "pending";
    public const STATUS_RUNNING = "running";
    public const STATUS_DONE    = "done";
    public const STATUS_FAILED  = "failed";

    private const KEEP_SECONDS = 86400;

    /** 登记一条任务，返回任务 id */
    public static function create(PDO $pdo, string $kind, array $payload, int $memberId, int $scopeId): int {
        self::cleanup($pdo);
        $st = $pdo->prepare(
            "INSERT INTO jobs (kind, payload, status, scope_id, member_id, created_at) VALUES (?,?,?,?,?,?)"
        );
        $st->execute([
            $kind,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::STATUS_PENDING,
            $scopeId,
            $memberId,
            lg_now(),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** 起一个 CLI 进程去跑（不阻塞当前请求） */
    public static function dispatch(int $id): void {
        $php = self::phpBin();
        $script = dirname(__DIR__, 2) . "/bin/job.php";
        if (!is_file($script)) return;
        $cmd = escapeshellarg($php) . " " . escapeshellarg($script) . " " . (int) $id;
        try {
            if (DIRECTORY_SEPARATOR === "\\") {
                // Windows：start /B 起一个后台进程，不弹窗
                pclose(popen("start \"\" /B " . $cmd . " > NUL 2>&1", "r"));
            } else {
                exec($cmd . " > /dev/null 2>&1 &");
            }
        } catch (\Throwable $e) {
            // 起不来就让它停在 pending，下面 get() 会提示重试
        }
    }

    /** CLI 侧：跑一条任务。可以重复调用，非 pending 的直接忽略 */
    public static function run(int $id): void {
        $pdo = Db::connect();
        $st = $pdo->prepare("SELECT * FROM jobs WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $job = $st->fetch();
        if (!$job || (string) $job["status"] !== self::STATUS_PENDING) return;

        $up = $pdo->prepare("UPDATE jobs SET status = ?, started_at = ? WHERE id = ? AND status = ?");
        $up->execute([self::STATUS_RUNNING, lg_now(), $id, self::STATUS_PENDING]);
        if ($up->rowCount() < 1) return;

        $payload = json_decode((string) ($job["payload"] ?? ""), true);
        if (!is_array($payload)) $payload = [];

        try {
            $result = self::execute($pdo, (string) $job["kind"], $payload);
            $pdo->prepare("UPDATE jobs SET status = ?, result = ?, finished_at = ? WHERE id = ?")
                ->execute([self::STATUS_DONE, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), lg_now(), $id]);
        } catch (\Throwable $e) {
            $pdo->prepare("UPDATE jobs SET status = ?, error = ?, finished_at = ? WHERE id = ?")
                ->execute([self::STATUS_FAILED, mb_substr($e->getMessage(), 0, 500), lg_now(), $id]);
        }
    }

    /** 给客户端看的状态；只给创建者本人 */
    public static function get(PDO $pdo, int $id, int $memberId): ?array {
        $st = $pdo->prepare("SELECT * FROM jobs WHERE id = ? AND member_id = ? LIMIT 1");
        $st->execute([$id, $memberId]);
        $job = $st->fetch();
        if (!$job) return null;
        $out = [
            "id"     => (int) $job["id"],
            "status" => (string) $job["status"],
            "kind"   => (string) $job["kind"],
        ];
        if ((string) $job["status"] === self::STATUS_DONE) {
            $out["result"] = json_decode((string) ($job["result"] ?? ""), true) ?: [];
        }
        if ((string) $job["status"] === self::STATUS_FAILED) {
            $out["error"] = (string) ($job["error"] ?? "任务失败了");
        }
        return $out;
    }

    /** 过期任务清掉，别把库撑大 */
    public static function cleanup(PDO $pdo): void {
        try {
            $pdo->prepare("DELETE FROM jobs WHERE created_at < ? AND status <> ? AND status <> ?")
                ->execute([lg_now() - self::KEEP_SECONDS, self::STATUS_PENDING, self::STATUS_RUNNING]);
        } catch (\Throwable $e) {
        }
    }

    /** 任务类型分发 */
    private static function execute(PDO $pdo, string $kind, array $payload): array {
        if ($kind === "translate") {
            $text = trim((string) ($payload["text"] ?? ""));
            $to   = trim((string) ($payload["to"] ?? ""));
            if ($to === "") $to = "zh-Hans";
            return \LightGroup\Api\Translator::translate($text, $to);
        }
        if ($kind === "asr") {
            $msgId  = (int) ($payload["msgId"] ?? 0);
            $convId = (int) ($payload["convId"] ?? 0);
            $st = $pdo->prepare("SELECT id, voice, voice_text FROM messages WHERE id = ? AND conv_id = ? LIMIT 1");
            $st->execute([$msgId, $convId]);
            $msg = $st->fetch();
            if (!$msg) throw new \RuntimeException("这条语音已经没了，可能要重新发一次");
            $cached = trim((string) ($msg["voice_text"] ?? ""));
            if ($cached !== "") return ["text" => $cached, "cached" => true];
            $voice = trim((string) ($msg["voice"] ?? ""));
            $voice = (string) preg_replace('#^\[voice\]#', "", $voice);
            if ($voice === "") throw new \RuntimeException("这条不是语音");
            $res = \LightGroup\Api\Stt::transcribe($pdo, $voice);
            if (!$res["ok"]) throw new \RuntimeException((string) ($res["err"] ?: "这段语音没转成功，稍后再试"));
            $pdo->prepare("UPDATE messages SET voice_text = ? WHERE id = ?")->execute([$res["text"], $msgId]);
            return ["text" => (string) $res["text"], "cached" => false];
        }
        throw new \RuntimeException("未知任务类型");
    }

    /** CLI 的 php 可执行文件：先看设置，再按 PHP_BINDIR 猜，最后退回 PATH 里的 php */
    private static function phpBin(): string {
        try {
            $pdo = Db::connect();
            $st = $pdo->prepare("SELECT v FROM settings WHERE k = 'job.php' LIMIT 1");
            $st->execute();
            $v = trim((string) $st->fetchColumn());
            if ($v !== "" && is_file($v)) return $v;
        } catch (\Throwable $e) {
        }
        $dir = defined("PHP_BINDIR") ? PHP_BINDIR : "";
        foreach ([$dir . "/php", $dir . "/php.exe"] as $c) {
            if ($dir !== "" && is_file($c)) return $c;
        }
        return "php";
    }
}
