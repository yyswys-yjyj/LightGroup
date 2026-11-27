<?php
declare(strict_types=1);

namespace LightGroup\Store;

use PDO;

/**
 * 消息存储策略：站点给总配置，每个群（作用域）可以覆盖；群没覆盖时跟着总配置走。
 *
 * 三种策略：
 *   msg.keep_days      只留最近 N 天（0 = 不限）
 *   msg.keep_per_conv  每个会话只留最近 N 条（0 = 不限）
 *   msg.local          是否允许成员把消息留一份在自己设备上
 *
 * 作用域覆盖写在 settings 里：msg.<scopeId>.keep_days / .keep_per_conv / .local，
 * 空值表示「跟随总配置」。剪枝不依赖系统计划任务：请求时按小时惰性触发 + 文件锁。
 *
 * 永远不剪：置顶消息、还有未完成群待办挂着的消息。
 */
final class Pruner
{
    public const KEEP_DAYS = "msg.keep_days";
    public const KEEP_PER_CONV = "msg.keep_per_conv";
    public const LOCAL = "msg.local";
    public const PAGE_SIZE = "msg.page_size";
    public const PAGE_DEFAULT = 20;
    public const LAST_RUN = "msg.prune.at";
    /** 两次自动剪枝之间至少隔这么久（秒） */
    public const INTERVAL = 3600;
    /** 单次最多删这么多条 */
    public const BATCH = 5000;

    private static $checked = false;

    /** 请求里的顺手检查：到点才真剪，平时只多一条 settings 查询 */
    public static function maybeRun(PDO $pdo): void
    {
        if (self::$checked) return;
        self::$checked = true;
        try {
            $last = (int) self::setting($pdo, self::LAST_RUN, "0");
            if ($last > 0 && time() - $last < self::INTERVAL) return;
            if (!self::hasPolicy($pdo)) {
                self::touch($pdo);
                return;
            }
            self::run($pdo);
        } catch (\Throwable $e) {
            // 剪枝失败不能影响正常请求
        }
    }

    /** 有效策略：群有覆盖就用自己的，没覆盖就跟总配置 */
    public static function policy(PDO $pdo, int $scopeId = 0): array
    {
        $gDays = (int) self::setting($pdo, self::KEEP_DAYS, "0");
        $gPer = (int) self::setting($pdo, self::KEEP_PER_CONV, "0");
        $gLocal = self::setting($pdo, self::LOCAL, "1") !== "0";
        // 一页多少条：翻历史靠用户点「加载更多」，不点就不多给
        $gPage = (int) self::setting($pdo, self::PAGE_SIZE, (string) self::PAGE_DEFAULT);
        if ($gPage <= 0) $gPage = self::PAGE_DEFAULT;
        $gPage = max(1, min(100, $gPage));
        if ($scopeId <= 0) {
            return ["days" => $gDays, "per_conv" => $gPer, "local" => $gLocal, "page_size" => $gPage, "inherited" => true];
        }
        $sd = self::setting($pdo, "msg." . $scopeId . ".keep_days", "");
        $sp = self::setting($pdo, "msg." . $scopeId . ".keep_per_conv", "");
        $sl = self::setting($pdo, "msg." . $scopeId . ".local", "");
        $sq = self::setting($pdo, "msg." . $scopeId . ".page_size", "");
        return [
            "days" => $sd === "" ? $gDays : max(0, (int) $sd),
            "per_conv" => $sp === "" ? $gPer : max(0, (int) $sp),
            "local" => $sl === "" ? $gLocal : ($sl !== "0"),
            "page_size" => $sq === "" ? $gPage : max(1, min(100, (int) $sq)),
            "inherited" => ($sd === "" && $sp === "" && $sl === "" && $sq === ""),
        ];
    }

    /**
     * 真跑一次；$opt 里带 days / per_conv 时按它临时覆盖全部群（命令行与「立即剪枝」用）。
     * @return array{deleted:int,scopes:int,live:bool,days:int,per_conv:int}
     */
    public static function run(PDO $pdo, array $opt = []): array
    {
        $override = [];
        if (array_key_exists("days", $opt)) $override["days"] = max(0, (int) $opt["days"]);
        if (array_key_exists("per_conv", $opt)) $override["per_conv"] = max(0, (int) $opt["per_conv"]);

        $out = ["deleted" => 0, "scopes" => 0, "live" => false, "days" => 0, "per_conv" => 0];
        $lock = self::lock();
        if ($lock === null) return $out; // 另一个请求正在剪
        $out["live"] = true;
        try {
            $st = $pdo->query("SELECT id FROM scopes");
            $ids = [];
            foreach ($st->fetchAll() as $row) $ids[] = (int) $row["id"];
            foreach ($ids as $sid) {
                $pol = $override + self::policy($pdo, $sid);
                $days = (int) $pol["days"];
                $per = (int) $pol["per_conv"];
                if ($days <= 0 && $per <= 0) continue;
                $out["scopes"]++;
                $out["days"] = $days;
                $out["per_conv"] = $per;
                if ($days > 0) {
                    $cut = lg_now() - $days * 86400000;
                    $rows = self::pick($pdo,
                        "SELECT id FROM messages WHERE scope_id = ? AND ts_ms < ? AND " . self::safe(),
                        [$sid, $cut], self::BATCH);
                    $out["deleted"] += self::remove($pdo, $rows);
                }
                if ($per > 0) {
                    foreach (self::fatConvs($pdo, $sid, $per) as $convId) {
                        $rows = self::pick($pdo,
                            "SELECT id FROM messages WHERE scope_id = ? AND conv_id = ? AND " . self::safe()
                                . " ORDER BY id DESC LIMIT 100000 OFFSET " . $per,
                            [$sid, $convId], self::BATCH);
                        $out["deleted"] += self::remove($pdo, $rows);
                    }
                }
            }
            self::touch($pdo);
        } finally {
            self::unlock($lock);
        }
        return $out;
    }

    /** 当前策略与统计，后台页面用；给 scopeId 时返回这个群的实际生效值 */
    public static function info(PDO $pdo, int $scopeId = 0): array
    {
        $pol = self::policy($pdo, $scopeId);
        if ($scopeId > 0) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE scope_id = ?");
            $st->execute([$scopeId]);
        } else {
            $st = $pdo->query("SELECT COUNT(*) FROM messages");
        }
        return [
            "keep_days" => (int) $pol["days"],
            "keep_per_conv" => (int) $pol["per_conv"],
            "local" => (bool) $pol["local"],
            "page_size" => (int) $pol["page_size"],
            "inherited" => (bool) $pol["inherited"],
            "messages" => (int) $st->fetchColumn(),
            "last_run" => (int) self::setting($pdo, self::LAST_RUN, "0"),
            "scoped" => [
                "keep_days" => self::setting($pdo, "msg." . $scopeId . ".keep_days", ""),
                "keep_per_conv" => self::setting($pdo, "msg." . $scopeId . ".keep_per_conv", ""),
                "local" => self::setting($pdo, "msg." . $scopeId . ".local", ""),
                "page_size" => self::setting($pdo, "msg." . $scopeId . ".page_size", ""),
            ],
        ];
    }

    /** 有没有任何生效策略（总配置或某个群覆盖） */
    private static function hasPolicy(PDO $pdo): bool
    {
        if (self::setting($pdo, self::KEEP_DAYS, "0") !== "0") return true;
        if (self::setting($pdo, self::KEEP_PER_CONV, "0") !== "0") return true;
        $st = $pdo->query("SELECT COUNT(*) FROM settings WHERE k LIKE 'msg.%keep%' AND v <> '' AND v <> '0'");
        return (int) $st->fetchColumn() > 0;
    }

    /** 可删条件：没置顶、没挂没关闭的群待办 */
    private static function safe(): string
    {
        return "pinned_at IS NULL AND id NOT IN (SELECT msg_id FROM todos WHERE closed_at IS NULL AND msg_id IS NOT NULL)";
    }

    private static function fatConvs(PDO $pdo, int $scopeId, int $per): array
    {
        // 数字直接拼：SQLite 里整数和文本比较按类型排序，绑成字符串会永远比不过
        $st = $pdo->prepare(
            "SELECT conv_id FROM messages WHERE scope_id = " . $scopeId
            . " GROUP BY conv_id HAVING COUNT(*) > " . max(1, $per)
        );
        $st->execute([]);
        $out = [];
        foreach ($st->fetchAll() as $row) $out[] = (int) $row["conv_id"];
        return $out;
    }

    private static function pick(PDO $pdo, string $sql, array $args, int $limit): array
    {
        $st = $pdo->prepare($sql);
        // 时间戳必须按 INT 绑，否则 SQLite 拿文本比数字，条件会恒真
        foreach (array_values($args) as $i => $v) {
            $st->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->execute();
        $ids = [];
        foreach ($st->fetchAll() as $row) {
            $ids[] = (int) $row["id"];
            if (count($ids) >= $limit) break;
        }
        return $ids;
    }

    private static function remove(PDO $pdo, array $ids): int
    {
        if (!$ids) return 0;
        $in = implode(",", array_fill(0, count($ids), "?"));
        // 先撤掉指向这些消息的引用，别留下点不开的待办
        $pdo->prepare("DELETE FROM todos WHERE msg_id IN ($in)")->execute($ids);
        $pdo->prepare("DELETE FROM todo_done WHERE todo_id NOT IN (SELECT id FROM todos)")->execute();
        $st = $pdo->prepare("DELETE FROM messages WHERE id IN ($in)");
        $st->execute($ids);
        return $st->rowCount();
    }

    private static function touch(PDO $pdo): void
    {
        $st = $pdo->prepare("UPDATE settings SET v = ? WHERE k = ?");
        $st->execute([(string) time(), self::LAST_RUN]);
        if ($st->rowCount() === 0) {
            try {
                $pdo->prepare("INSERT INTO settings (k, v) VALUES (?, ?)")->execute([self::LAST_RUN, (string) time()]);
            } catch (\Throwable $e) {
                // 并发插入撞了主键就算了
            }
        }
    }

    private static function setting(PDO $pdo, string $key, string $default = ""): string
    {
        $st = $pdo->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $default : (string) $v;
    }

    /** @return resource|null */
    private static function lock()
    {
        $dir = defined("APP_ROOT") ? APP_ROOT . "/data" : sys_get_temp_dir();
        if (!is_dir($dir)) $dir = sys_get_temp_dir();
        $fh = @fopen($dir . "/prune.lock", "c");
        if ($fh === false) return null;
        if (!@flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return null;
        }
        return $fh;
    }

    /** @param resource $fh */
    private static function unlock($fh): void
    {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
}
