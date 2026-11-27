<?php
declare(strict_types=1);

/**
 * 消息剪枝（命令行版）。
 *
 * 站点平时会自己在请求里按小时触发一次，这个脚本只是给「想立刻清一次」或者
 * 确实有 crontab / 计划任务的地方用，两条路走的是同一套逻辑，结果是幂等的。
 *
 * 用法：
 *   php bin/prune.php            按站点设置跑一次
 *   php bin/prune.php --days=30  临时只留 30 天
 *   php bin/prune.php --per=500  临时每个会话只留 500 条
 *   php bin/prune.php --dry-run  只看会删多少，不落库
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "只能在命令行跑。\n"); exit(1); }

require dirname(__DIR__) . '/src/bootstrap.php';

use LightGroup\Store\Db;
use LightGroup\Store\Pruner;

$opt = [];
$dry = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run' || $arg === '-n') { $dry = true; continue; }
    if (str_starts_with($arg, '--days=')) { $opt['days'] = max(0, (int) substr($arg, 7)); continue; }
    if (str_starts_with($arg, '--per=')) { $opt['per_conv'] = max(0, (int) substr($arg, 6)); continue; }
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "用法: php bin/prune.php [--days=N] [--per=N] [--dry-run]\n");
        exit(0);
    }
    fwrite(STDERR, "不认识的参数：$arg\n");
    exit(1);
}

$pdo = Db::connect();
$before = Pruner::info($pdo);

if ($dry) {
    // 只看规模：不动数据
    fwrite(STDOUT, "当前消息 " . $before['messages'] . " 条\n");
    fwrite(STDOUT, "策略：保留 " . ($opt['days'] ?? $before['keep_days']) . " 天，每个会话最多 "
        . ($opt['per_conv'] ?? $before['keep_per_conv']) . " 条\n");
    fwrite(STDOUT, "dry-run：没有删任何数据\n");
    exit(0);
}

$r = Pruner::run($pdo, $opt);
$after = Pruner::info($pdo);

fwrite(STDOUT, "剪枝完成：" . $r['deleted'] . " 条被删，消息从 " . $before['messages'] . " 降到 " . $after['messages'] . " 条\n");
if ($r['deleted'] === 0 && $before['keep_days'] <= 0 && $before['keep_per_conv'] <= 0) {
    fwrite(STDOUT, "提示：站点的保留策略是「不限」，什么都没删。到后台站点设置里配「消息保留」再跑。\n");
}
exit(0);
