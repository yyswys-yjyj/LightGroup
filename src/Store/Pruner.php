<?php
declare(strict_types=1);

namespace LightGroup\Store;

use PDO;

/**
 * 消息存储策略：站点给总配置，每个群（作用域）可以覆盖；群没覆盖时跟着总配置走。
 *
 * 两套：
 *   msg.mode = server  由站点下发：客户端每次要一页，翻历史靠「加载更多」（msg.page_size）
 *   msg.mode = keep    消息保留：只在成员设备上留一份，站点只回新增
 *   msg.local          是否允许成员把消息留一份在自己设备上
 *
 * 消息只增不删：平台不做任何按时间/按条数的消息剪枝。剪枝之后如果切回「由站点下发」，
 * 被删掉的历史就永久缺了，这属于平台造成的数据丢失，任何情况下都不做。
 *
 * 附件到期清理按 file.keep_days（默认 0 = 永久）：只有管理员显式设了保留天数才删；
 * 纯 PHP 在请求里按小时惰性触发，不依赖系统计划任务。
 */
final class Pruner
{
    public const MODE = "msg.mode";
    /** 旧键：还认得出来，但不再产生任何删除 */
    public const KEEP_DAYS = "msg.keep_days";
    public const KEEP_PER_CONV = "msg.keep_per_conv";
    public const LOCAL = "msg.local";
    public const PAGE_SIZE = "msg.page_size";
    public const PAGE_DEFAULT = 20;
    public const LAST_RUN = "msg.prune.at";
    /** 两次清理之间至少隔这么久（秒） */
    public const INTERVAL = 3600;

    private static $checked = false;

    /** 请求里的顺手检查：到点才清过期附件，消息一条都不动 */
    public static function maybeRun(PDO $pdo): void
    {
        if (self::$checked) return;
        self::$checked = true;
        try {
            $last = (int) self::setting($pdo, self::LAST_RUN, "0");
            if ($last > 0 && time() - $last < self::INTERVAL) return;
            // 文件到期清理跟着这次检查一起做：纯 PHP，不依赖系统定时任务
            try {
                if (Files::config($pdo)["keepDays"] > 0) Files::cleanup($pdo);
            } catch (\Throwable $e) {
            }
            self::touch($pdo);
        } catch (\Throwable $e) {
            // 清理失败不能影响正常请求
        }
    }

    /** 有效策略：群有覆盖就用自己的，没覆盖就跟总配置 */
    public static function policy(PDO $pdo, int $scopeId = 0): array
    {
        $gMode = self::modeOf(self::setting($pdo, self::MODE, "server"));
        $gLocal = self::setting($pdo, self::LOCAL, "1") !== "0";
        // 由站点下发时一页多少条；翻历史靠用户点「加载更多」，不点就不多给
        $gPage = (int) self::setting($pdo, self::PAGE_SIZE, (string) self::PAGE_DEFAULT);
        if ($gPage <= 0) $gPage = self::PAGE_DEFAULT;
        $gPage = max(1, min(100, $gPage));
        if ($scopeId <= 0) {
            return self::pack($gMode, $gLocal, $gPage, true);
        }
        $sm = self::setting($pdo, "msg." . $scopeId . ".mode", "");
        $sl = self::setting($pdo, "msg." . $scopeId . ".local", "");
        $sq = self::setting($pdo, "msg." . $scopeId . ".page_size", "");
        return self::pack(
            $sm === "" ? $gMode : self::modeOf($sm),
            $sl === "" ? $gLocal : ($sl !== "0"),
            $sq === "" ? $gPage : max(1, min(100, (int) $sq)),
            ($sm === "" && $sl === "" && $sq === "")
        );
    }

    private static function modeOf(string $v): string
    {
        return $v === "keep" ? "keep" : "server";
    }

    /** 两套策略的差别都在这里：保留模式不做懒加载，站点只发新增 */
    private static function pack(string $mode, bool $local, int $page, bool $inherited): array
    {
        $keep = $mode === "keep";
        return [
            "mode" => $mode,
            // 字段留着给老的调用方读，平台已经不按天数/条数删消息了
            "days" => 0,
            "per_conv" => 0,
            // 由站点下发时也把拉过的消息在本机留一份，下次先画缓存、只补增量（漫游）
            "local" => $keep ? $local : true,
            "page_size" => $keep ? 0 : max(1, min(100, $page)),
            "inherited" => $inherited,
        ];
    }

    /** 当前策略与统计，后台页面用 */
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
            "mode" => (string) $pol["mode"],
            "local" => (bool) $pol["local"],
            "page_size" => (int) $pol["page_size"],
            "inherited" => (bool) $pol["inherited"],
            "messages" => (int) $st->fetchColumn(),
            "last_run" => (int) self::setting($pdo, self::LAST_RUN, "0"),
            "scoped" => [
                "mode" => self::setting($pdo, "msg." . $scopeId . ".mode", ""),
                "local" => self::setting($pdo, "msg." . $scopeId . ".local", ""),
                "page_size" => self::setting($pdo, "msg." . $scopeId . ".page_size", ""),
            ],
        ];
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
}
