<?php
declare(strict_types=1);

/**
 * 后台任务执行器：由 Jobs::dispatch() 起一个 CLI 进程调用。
 * 用法：php bin/job.php <任务id>
 *
 * 一定要用 CLI 跑（php-fpm 下执行会占住请求线程，等于没解决问题）。
 */
if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("forbidden");
}
$id = (int) ($argv[1] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "usage: php bin/job.php <id>\n");
    exit(2);
}
require dirname(__DIR__) . "/src/bootstrap.php";
\LightGroup\Store\Jobs::run($id);
