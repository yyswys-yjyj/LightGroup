<?php
declare(strict_types=1);

/**
 * 站点定时任务（命令行版）：消息剪枝 + 附件到期清理。
 *
 * 部署时不需要配系统计划任务：站点平时会在请求里按小时触发一次同样的逻辑。
 * 这个脚本给「想立刻清一次」或者确实有 crontab 的地方用，两条路是同一套代码。
 *
 * 用法：
 *   php bin/task.php            按站点设置跑一次
 *   php bin/task.php --dry-run  只报告会清多少，不动数据
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "只能在命令行跑。\n"); exit(1); }

require dirname(__DIR__) . '/src/bootstrap.php';

use LightGroup\Store\Db;
use LightGroup\Store\Pruner;
use LightGroup\Store\Files;

$dry = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run' || $arg === '-n') { $dry = true; continue; }
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "用法: php bin/task.php [--dry-run]\n");
        exit(0);
    }
    fwrite(STDERR, "不认识的参数：$arg\n");
    exit(1);
}

$pdo = Db::connect();
$pol = Pruner::info($pdo);
$fcfg = Files::config($pdo);

if ($dry) {
    fwrite(STDOUT, "消息保留策略：" . ($pol["mode"] === "keep" ? "消息保留" : "由站点下发")
        . "，保留 " . (int) $pol["keep_days"] . " 天 / 每会话 " . (int) $pol["keep_per_conv"] . " 条\n");
    fwrite(STDOUT, "当前消息：" . (int) $pol["messages"] . " 条\n");
    fwrite(STDOUT, "附件保留：" . ($fcfg["keepDays"] > 0 ? $fcfg["keepDays"] . " 天" : "永久") . "\n");
    fwrite(STDOUT, "dry-run：没有删任何数据\n");
    exit(0);
}

$r = Pruner::run($pdo);
fwrite(STDOUT, "消息剪枝：" . (int) $r["deleted"] . " 条被删\n");

$f = ["deleted" => 0];
try {
    $f = Files::cleanup($pdo);
} catch (\Throwable $e) {
    fwrite(STDERR, "附件清理失败：" . $e->getMessage() . "\n");
}
fwrite(STDOUT, "附件清理：" . (int) ($f["deleted"] ?? 0) . " 个到期文件被删\n");
fwrite(STDOUT, "完成\n");
