<?php
declare(strict_types=1);

namespace LightGroup\Web;

/**
 * 站点自更新：从 GitHub 最新 release 拉源码包，覆盖到站点根。
 *
 * 只换代码，不动站点自己的东西：config.php、.lock、data/、public/uploads/、
 * favicon、site.conf 等一律跳过。覆盖前把会被替换的旧文件备份到 data/backup/<时间>/，
 * 出问题能照着路径一个个恢复。
 */
final class Updater
{
    public const REPO = 'yyswys-yjyj/LightGroup';

    /** 覆盖时绝不碰的路径（相对站点根；目录按前缀匹配） */
    private const KEEP = [
        'config.php', '.lock', '.git', '.gitignore', '.dsh',
        'data', 'public/uploads', 'site.conf', 'php-cli.ini',
        'favicon.ico', 'favicon.svg', 'vendor', 'publish', 'opmbuild',
    ];

    /** GitHub 最新 release：没有 release 就退化成 main 分支的源码包 */
    public static function latest(): array
    {
        $release = self::releaseInfo();
        // 版本号以仓库清单为准：release 的 tag 可能是 release011 这种非语义版本，
        // 直接拿去和 0.1.1 比大小会误判成「有新版本」。
        $ver = self::manifestVersion();
        if ($ver === '' && $release !== null) {
            $tag = (string) ($release['tag_name'] ?? '');
            if (preg_match('/(\d+(?:\.\d+){1,3})/', $tag, $m)) $ver = $m[1];
        }
        if ($ver === '') {
            return ['ok' => false, 'err' => '拉不到 GitHub 上的版本号（仓库清单和 release 都没读到）'];
        }

        // 只有 release 的 tag 版本和清单版本一致时才用 release 的包：
        // 仓库里可能挂着旧 tag（比如 release011），拿它覆盖会把代码退回旧版。
        $relVer = '';
        if ($release !== null && preg_match('/(\d+(?:\.\d+){1,3})/', (string) ($release['tag_name'] ?? ''), $tm)) {
            $relVer = $tm[1];
        }
        $useRelease = $relVer !== '' && version_compare($relVer, $ver, '==');

        $tarball = $useRelease ? (string) ($release['tarball_url'] ?? '') : '';
        $zipball = $useRelease ? (string) ($release['zipball_url'] ?? '') : '';
        if ($tarball === '' && $zipball === '') {
            $tarball = 'https://codeload.github.com/' . self::REPO . '/tar.gz/refs/heads/main';
        }

        return [
            'ok'           => true,
            'version'      => $ver,
            'name'         => (string) ($release['name'] ?? ''),
            'notes'        => mb_substr((string) ($release['body'] ?? ''), 0, 4000),
            'published_at' => (string) ($release['published_at'] ?? ''),
            'tarball'      => $tarball,
            'zipball'      => $zipball,
            'source'       => $tarball !== '' && $zipball !== '' ? 'release' : 'main',
        ];
    }

    /** GitHub 的 latest release；没有 release 或被限流返回 null */
    private static function releaseInfo(): ?array
    {
        $raw = self::get('https://api.github.com/repos/' . self::REPO . '/releases/latest', 10);
        if ($raw === null) return null;
        $j = json_decode($raw, true);
        return is_array($j) && isset($j['tag_name']) ? $j : null;
    }

    /**
     * 仓库 main 上 api/manifest.json 的 version。
     * 先走 contents API（不经过 CDN，刚推上去的提交立刻可见），
     * 再退 raw.githubusercontent，最后 jsDelivr。
     */
    private static function manifestVersion(): string
    {
        $api = self::get('https://api.github.com/repos/' . self::REPO . '/contents/api/manifest.json?ref=main', 8);
        if ($api !== null) {
            $j = json_decode($api, true);
            if (is_array($j) && !empty($j['content'])) {
                $txt = base64_decode((string) $j['content'], true);
                if (is_string($txt)) {
                    $m = json_decode($txt, true);
                    $v = is_array($m) ? trim((string) ($m['version'] ?? '')) : '';
                    if ($v !== '') return $v;
                }
            }
        }

        $sources = [
            'https://raw.githubusercontent.com/' . self::REPO . '/main/api/manifest.json',
            'https://cdn.jsdelivr.net/gh/' . self::REPO . '@main/api/manifest.json',
        ];
        foreach ($sources as $url) {
            $raw = self::get($url, 8);
            if ($raw === null) continue;
            $j = json_decode($raw, true);
            $v = is_array($j) ? trim((string) ($j['version'] ?? '')) : '';
            if ($v !== '') return $v;
        }
        return '';
    }

    /**
     * 下载并覆盖。$root 是站点根目录。
     * 返回 ['ok'=>true,'files'=>n,'backup'=>'data/backup/…'] 或 ['ok'=>false,'err'=>...]
     */
    public static function apply(string $root, array $rel): array
    {
        $stamp = date('Ymd-His');
        $tmp   = $root . '/data/tmp';
        if (!is_dir($tmp) && !@mkdir($tmp, 0755, true)) {
            return ['ok' => false, 'err' => '建不了临时目录 data/tmp'];
        }

        $src = '';
        $tgz = $tmp . '/update-' . $stamp . '.tar.gz';
        if (($rel['tarball'] ?? '') !== '' && self::download((string) $rel['tarball'], $tgz)) {
            $src = self::untar($tgz, $tmp . '/x-' . $stamp);
        }

        if ($src === '' && ($rel['zipball'] ?? '') !== '') {
            $zip = $tmp . '/update-' . $stamp . '.zip';
            if (self::download((string) $rel['zipball'], $zip)) {
                $src = self::unzip($zip, $tmp . '/x-' . $stamp);
            }
            @unlink($zip);
        }

        if ($src === '') {
            @unlink($tgz);
            return ['ok' => false, 'err' => '下载或解压源码包失败（检查服务器的出网与 PHP 的 Phar/Zip 扩展）'];
        }

        $files   = 0;
        $failed  = [];
        $backup  = 'data/backup/' . $stamp;
        $backDir = $root . '/' . $backup;
        $owner   = function_exists('fileowner') ? @fileowner($root) : false;
        $group   = function_exists('filegroup') ? @filegroup($root) : false;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if (!$f->isFile()) continue;
            $relPath = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($src))), '/');
            if ($relPath === '' || self::keep($relPath)) continue;

            $dst = $root . '/' . $relPath;
            if (is_file($dst)) {
                $bk = $backDir . '/' . $relPath;
                @mkdir(dirname($bk), 0755, true);
                @copy($dst, $bk);
            }
            $dir = dirname($dst);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            if (!@copy($f->getPathname(), $dst)) {
                $failed[] = $relPath;
                continue;
            }
            @chmod($dst, 0644);
            if ($owner !== false && function_exists('chown')) @\chown($dst, $owner);
            if ($group !== false && function_exists('chgrp')) @\chgrp($dst, $group);
            $files++;
        }

        self::rmrf($src);
        @unlink($tgz);

        if ($files === 0) {
            return ['ok' => false, 'err' => '源码包里没有可覆盖的文件'];
        }
        if (function_exists('opcache_reset')) @opcache_reset();

        return [
            'ok'     => true,
            'files'  => $files,
            'failed' => $failed,
            'backup' => $backup,
        ];
    }

    private static function keep(string $rel): bool
    {
        foreach (self::KEEP as $k) {
            if ($rel === $k) return true;
            if (strncmp($rel, $k . '/', strlen($k) + 1) === 0) return true;
        }
        return false;
    }

    private static function get(string $url, int $timeout): ?string
    {
        $body = self::request($url, $timeout);
        return $body === null ? null : $body;
    }

    private static function download(string $url, string $to): bool
    {
        $fp = @fopen($to, 'wb');
        if ($fp === false) return false;
        $ok = self::request($url, 120, $fp);
        fclose($fp);
        if ($ok === null || !is_file($to) || filesize($to) < 1024) {
            @unlink($to);
            return false;
        }
        return true;
    }

    /** curl 优先；没 curl 用流。$sink 传文件句柄时直接落地，不占内存 */
    private static function request(string $url, int $timeout, $sink = null): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opt = [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_USERAGENT      => 'LightGroup-Updater/1.0',
                CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
            ];
            if ($sink !== null) {
                $opt[CURLOPT_FILE] = $sink;
            } else {
                $opt[CURLOPT_RETURNTRANSFER] = true;
            }
            curl_setopt_array($ch, $opt);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code < 200 || $code >= 300) return null;
            if ($sink !== null) return '';
            return is_string($body) ? $body : null;
        }

        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => 'LightGroup-Updater/1.0']]);
        if ($sink !== null) {
            $in = @fopen($url, 'rb', false, $ctx);
            if ($in === false) return null;
            stream_copy_to_stream($in, $sink);
            fclose($in);
            return '';
        }
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : null;
    }

    private static function untar(string $tgz, string $dir): string
    {
        if (!class_exists('PharData')) return '';
        @mkdir($dir, 0755, true);
        try {
            $phar = new \PharData($tgz);
            $phar->extractTo($dir, null, true);
        } catch (\Throwable $e) {
            return '';
        }
        return self::topDir($dir);
    }

    private static function unzip(string $zip, string $dir): string
    {
        if (!class_exists('ZipArchive')) return '';
        @mkdir($dir, 0755, true);
        $z = new \ZipArchive();
        if ($z->open($zip) !== true) return '';
        $z->extractTo($dir);
        $z->close();
        return self::topDir($dir);
    }

    /** 源码包解出来是一层 LightGroup-main/ 之类的目录，取它 */
    private static function topDir(string $dir): string
    {
        $items = @scandir($dir);
        if (!is_array($items)) return '';
        $dirs = [];
        foreach ($items as $n) {
            if ($n === '.' || $n === '..') continue;
            if (is_dir($dir . '/' . $n)) $dirs[] = $dir . '/' . $n;
        }
        return count($dirs) === 1 ? $dirs[0] : '';
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
