<?php
declare(strict_types=1);

/**
 * 生成发布副本：把能公开的源码复制到 publish/，可整目录推到 GitHub。
 *
 * 用法：php bin/release.php [--with-doc-images]
 *   --with-doc-images  连 doc/ 下的截图一起带走（默认只带 doc/*.md）
 *
 * 发布版不含：config.php、.lock、data/ 下的数据库与上传文件、调试残留。
 */

$root = dirname(__DIR__);
$out  = $root . DIRECTORY_SEPARATOR . 'publish';
$withDocImages = in_array('--with-doc-images', $argv, true);

/* ---------- 工具 ---------- */

function out_line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): void { fwrite(STDERR, '✗ ' . $s . "\n"); exit(1); }

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function excluded(string $rel, bool $withDocImages): bool
{
    $rel = str_replace('\\', '/', $rel);
    $base = basename($rel);

    $pats = [
        '#(^|/)\.git($|/)#',
        '#(^|/)node_modules($|/)#',
        '#(^|/)publish($|/)#',
        '#(^|/)dist($|/)#',
        '#^config\.php$#',
        '#^\.lock(\.tmp)?$#',
        '#(^|/)\.DS_Store$#',
        '#(^|/)Thumbs\.db$#',
        '#\.(swp|bak|tmp|orig|rej)$#',
        '#(^|/)~\$#',
    ];
    foreach ($pats as $p) {
        if (preg_match($p, $rel)) { return true; }
    }

    // data/ 只保留空目录占位，内容一律不发
    if (preg_match('#^data/#', $rel)) {
        return !preg_match('#^data/(uploads/|cache/)?\.gitkeep$#', $rel);
    }

    // doc/ 只带 markdown；截图按开关；协议样本这类原始数据一律不发
    if (preg_match('#^doc/#', $rel)) {
        if (!$withDocImages && preg_match('#\.(png|jpe?g|gif|webp)$#i', $rel)) { return true; }
        return !(bool) preg_match('#\.md$#i', $rel);
    }

    // 其它明显的调试残留
    if (preg_match('#\.(html|txt|log)$#', $base) && str_starts_with($rel, 'data/')) {
        return true;
    }
    return false;
}

function copyFile(string $from, string $to): void
{
    $dir = dirname($to);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail('建目录失败: ' . $dir);
    }
    if (!copy($from, $to)) { fail('复制失败: ' . $from); }
}

function copyTree(string $from, string $to, string $relBase, bool $withDocImages, array &$stat): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $rel = $relBase === '' ? $f->getFilename() : $relBase . '/' . substr($f->getPathname(), strlen($from) + 1);
        $rel = str_replace('\\', '/', $rel);
        if (excluded($rel, $withDocImages)) { continue; }
        $target = $to . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if ($f->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) { fail('建目录失败: ' . $target); }
        } else {
            copyFile($f->getPathname(), $target);
            $stat['files']++;
            $stat['bytes'] += (int) filesize($f->getPathname());
        }
    }
}

/* ---------- 开始 ---------- */

if (is_dir($out)) {
    out_line('清理旧的 publish/');
    rrmdir($out);
}
if (!mkdir($out, 0775, true) && !is_dir($out)) { fail('无法创建 publish/'); }

$stat = ['files' => 0, 'bytes' => 0];

// 源码目录整棵带走
foreach (['src', 'public', 'tests', 'deploy'] as $dir) {
    if (!is_dir($root . '/' . $dir)) { continue; }
    copyTree($root . '/' . $dir, $out, $dir, $withDocImages, $stat);
}

// doc：只带 markdown（除非开图）
if (is_dir($root . '/doc')) {
    foreach (glob($root . '/doc/*') as $f) {
        $rel = 'doc/' . basename($f);
        if (!is_file($f) || excluded($rel, $withDocImages)) { continue; }
        copyFile($f, $out . '/doc/' . basename($f));
        $stat['files']++;
        $stat['bytes'] += (int) filesize($f);
    }
}

// 命令行工具
foreach (['router.php', 'setup-cli.php'] as $f) {
    copyFile($root . '/bin/' . $f, $out . '/bin/' . $f);
    $stat['files']++;
    $stat['bytes'] += (int) filesize($root . '/bin/' . $f);
}

// 顶层文件
foreach (['README.md', 'site.conf'] as $f) {
    if (!is_file($root . '/' . $f)) { continue; }
    copyFile($root . '/' . $f, $out . '/' . $f);
    $stat['files']++;
    $stat['bytes'] += (int) filesize($root . '/' . $f);
}

// data/ 占位目录
foreach (['data', 'data/uploads', 'data/cache'] as $d) {
    if (!is_dir($out . '/' . $d)) { mkdir($out . '/' . $d, 0775, true); }
    if ($d === 'data') { continue; }
    file_put_contents($out . '/' . $d . '/.gitkeep', '');
}
file_put_contents($out . '/data/.gitkeep', '');

// LICENSE（Apache-2.0）：根目录优先，其次用 doc 里的标准全文，都没有就不瞎编
$license = $root . '/LICENSE';
$licenseTpl = $root . '/doc/LICENSE-APACHE-2.0.txt';
if (is_file($license)) {
    copyFile($license, $out . '/LICENSE');
    out_line('复制 LICENSE（Apache-2.0）');
} elseif (is_file($licenseTpl)) {
    copyFile($licenseTpl, $out . '/LICENSE');
    out_line('复制 LICENSE（Apache-2.0）');
} else {
    out_line('缺少 LICENSE 与 doc/LICENSE-APACHE-2.0.txt，已跳过许可文件');
}

// 发布版 .gitignore：只挡本地实例产物，不挡源码
$gitignore = implode("\n", [
    '# 本地实例数据',
    '/data/*.sqlite',
    '/data/*.sqlite-wal',
    '/data/*.sqlite-shm',
    '/data/uploads/*',
    '!/data/uploads/.gitkeep',
    '/data/cache/*',
    '!/data/cache/.gitkeep',
    '',
    '# 安装产物',
    '/config.php',
    '/.lock',
    '/.lock.tmp',
    '',
    '# 编辑器',
    '.idea/',
    '.vscode/',
    '.DS_Store',
    'Thumbs.db',
    '',
]) ;
file_put_contents($out . '/.gitignore', $gitignore);

/* ---------- 自检 ---------- */
$bad = [];
foreach (['config.php', '.lock', '.lock.tmp'] as $f) {
    if (file_exists($out . '/' . $f)) { $bad[] = $f; }
}
foreach (glob($out . '/data/*.sqlite*') ?: [] as $f) { $bad[] = 'data/' . basename($f); }
foreach (glob($out . '/data/uploads/*') ?: [] as $f) {
    if (basename($f) !== '.gitkeep') { $bad[] = 'data/uploads/' . basename($f); }
}
if ($bad) { fail('发布副本里混进了不该发的文件：' . implode(', ', $bad)); }

out_line('发布副本已生成：' . $out);
out_line('  文件 ' . $stat['files'] . ' 个，' . number_format($stat['bytes'] / 1024, 1) . ' KB');
out_line('  不含 config.php / .lock / 数据库 / 上传文件 / 调试残留');
out_line('  推送到 GitHub：cd publish && git init && git add -A && git commit -m "init"');
