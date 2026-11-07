<?php
declare(strict_types=1);

namespace LightGroup\Setup;

use LightGroup\Api\Token;
use LightGroup\Store\Db;
use LightGroup\Store\Schema;
use RuntimeException;
use Throwable;

/**
 * 网页安装向导的后端。命令行安装脚本 bin/setup-cli.php 仍然可用，
 * 两边写的是同一套表结构与 settings 键名。
 */
final class Installer
{
    public const ICON_DIR = '/assets/icon';

    /** @return array<int,array{label:string,ok:bool,detail:string}> */
    public static function checks(): array
    {
        $items = [];
        $items[] = ['label' => 'PHP 版本', 'ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'detail' => PHP_VERSION];
        foreach ([['pdo_sqlite', 'PDO SQLite'], ['mbstring', 'mbstring'], ['gd', 'GD 图像'], ['fileinfo', 'fileinfo']] as $ext) {
            $on = extension_loaded($ext[0]);
            $items[] = ['label' => '扩展 ' . $ext[1], 'ok' => $on, 'detail' => $on ? '已启用' : '未启用'];
        }
        $items[] = ['label' => 'data/ 可写', 'ok' => self::writable(APP_ROOT . '/data'), 'detail' => 'data/'];
        $items[] = ['label' => 'public/ 可写', 'ok' => self::writable(PUBLIC_ROOT), 'detail' => 'public/'];
        $items[] = ['label' => '根目录可写', 'ok' => self::writable(APP_ROOT), 'detail' => 'config.php 与 .lock'];
        return $items;
    }

    private static function writable(string $path): bool
    {
        if (is_dir($path) || is_file($path)) return is_writable($path);
        $parent = dirname($path);
        return is_dir($parent) && is_writable($parent);
    }

    /**
     * 执行安装。icon_light / icon_dark 是浏览器裁切后的 PNG dataURL（可空）。
     * @return array{ok:bool,err?:string,invite?:string,token?:string}
     */
    public static function install(array $in): array
    {
        if (lg_installed()) return ['ok' => false, 'err' => '已经安装过了'];

        $siteName   = trim((string) ($in['site'] ?? ''));
        $intro      = trim((string) ($in['intro'] ?? ''));
        $groupTitle = trim((string) ($in['group_title'] ?? ''));
        $invite     = trim((string) ($in['invite'] ?? ''));
        $admin      = trim((string) ($in['admin'] ?? ''));
        $password   = (string) ($in['password'] ?? '');

        if ($siteName === '' || mb_strlen($siteName) > 40) return ['ok' => false, 'err' => '站点名称需要 1-40 个字'];
        if ($admin === '' || mb_strlen($admin) > 24) return ['ok' => false, 'err' => '管理员昵称需要 1-24 个字'];
        if (strlen($password) < 6) return ['ok' => false, 'err' => '管理员密码至少 6 位'];
        if ($groupTitle === '') $groupTitle = $siteName;
        if (mb_strlen($groupTitle) > 40) return ['ok' => false, 'err' => '群名需要 1-40 个字'];
        if ($invite === '') $invite = substr(bin2hex(random_bytes(3)), 0, 6);
        if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $invite)) return ['ok' => false, 'err' => '邀请码只能使用 4-32 位字母、数字、下划线或短横线'];

        $iconLight = (string) ($in['icon_light'] ?? '');
        $iconDark  = (string) ($in['icon_dark'] ?? '');
        $cap = function ($value): int {
            $n = (int) $value;
            return $n > 0 ? $n : 0;
        };
        $switch = function ($value): string {
            return ($value === true || $value === 1 || $value === '1' || $value === 'on') ? '1' : '0';
        };

        // 登录验证方式：oauth（第三方门禁）/ captcha（人机验证）/ none（只账密）
        $authMode = (string) ($in['auth_mode'] ?? '');
        if (!in_array($authMode, ['oauth', 'captcha', 'none'], true)) {
            $authMode = $switch($in['oauth_enabled'] ?? 0) === '1' ? 'oauth' : 'captcha';
        }
        $oauthOn = $authMode === 'oauth';
        // 人机验证的具体方式：内建滑块或第三方（Turnstile / hCaptcha / reCAPTCHA）
        $capProvider = trim((string) ($in['auth_captcha_provider'] ?? 'builtin'));
        if ($capProvider === '') $capProvider = 'builtin';
        if (!in_array($capProvider, ['builtin', 'turnstile', 'hcaptcha', 'recaptcha', 'geetest4'], true)) $capProvider = 'builtin';
        $capSiteKey = trim((string) ($in['auth_captcha_site_key'] ?? ''));
        $capSecret  = trim((string) ($in['auth_captcha_secret'] ?? ''));
        $capVerify  = trim((string) ($in['auth_captcha_verify_url'] ?? ''));
        if ($authMode === 'captcha' && $capProvider !== 'builtin') {
            $capIdLabel = $capProvider === 'geetest4' ? 'Captcha ID' : 'Site Key';
            $capKeyLabel = $capProvider === 'geetest4' ? 'Captcha Key' : 'Secret Key';
            if ($capSiteKey === '') return ['ok' => false, 'err' => '人机验证选了第三方，要填 ' . $capIdLabel];
            if ($capSecret === '') return ['ok' => false, 'err' => '人机验证选了第三方，要填 ' . $capKeyLabel . '，不然验证不生效'];
            if ($capVerify !== '' && !preg_match('#^https?://#i', $capVerify)) {
                return ['ok' => false, 'err' => '人机验证的校验地址要以 http:// 或 https:// 开头'];
            }
        }
        $oauth = [
            'provider'      => trim((string) ($in['oauth_provider'] ?? '')) ?: 'generic',
            'client_id'     => trim((string) ($in['oauth_client_id'] ?? '')),
            'client_secret' => (string) ($in['oauth_client_secret'] ?? ''),
            'authorize_url' => trim((string) ($in['oauth_authorize_url'] ?? '')),
            'token_url'     => trim((string) ($in['oauth_token_url'] ?? '')),
            'userinfo_url'  => trim((string) ($in['oauth_userinfo_url'] ?? '')),
            'scope'         => trim((string) ($in['oauth_scope'] ?? '')) ?: 'openid profile email',
        ];
        $adminSub   = trim((string) ($in['admin_oauth_sub'] ?? ''));
        $adminEmail = trim((string) ($in['admin_oauth_email'] ?? ''));
        if ($oauthOn) {
            $need = [
                'client_id'     => 'Client ID',
                'client_secret' => 'Client Secret',
                'authorize_url' => '授权地址',
                'token_url'     => '换取 token 地址',
            ];
            foreach ($need as $key => $label) {
                if ($oauth[$key] === '') return ['ok' => false, 'err' => '开启 OAuth2 门禁要填 ' . $label];
            }
            foreach (['authorize_url', 'token_url'] as $key) {
                if (!preg_match('#^https?://#i', $oauth[$key])) return ['ok' => false, 'err' => 'OAuth2 的地址要以 http:// 或 https:// 开头'];
            }
            // 用户信息地址允许留空：没有 userinfo 端点的提供方，登录时从 id_token 里取 sub
            if ($oauth['userinfo_url'] !== '' && !preg_match('#^https?://#i', $oauth['userinfo_url'])) {
                return ['ok' => false, 'err' => '用户信息地址要以 http:// 或 https:// 开头'];
            }
            if ($adminSub === '') return ['ok' => false, 'err' => '开启 OAuth2 门禁时，管理员要绑定一个 OAuth 身份（subject 不能空）'];
            if (mb_strlen($adminSub) > 191) return ['ok' => false, 'err' => 'OAuth subject 最多 191 个字符'];
        }

        try {
            $cfg = self::dbConfig($in);

            $lightRel = $iconLight !== '' ? self::saveIcon($iconLight, 'light') : null;
            $darkRel  = $iconDark !== '' ? self::saveIcon($iconDark, 'dark') : null;
            self::writeFavicon($lightRel ?: $darkRel);

            $pdo = Db::connect($cfg);
            Schema::install($pdo, (string) $cfg['driver']);

            $now = lg_now();
            $settings = [
                'site.name'  => $siteName,
                'site.intro' => $intro,
                'theme'      => 'light',
                'theme.preset' => 'hulan',
                'avatar.default' => trim((string) ($in['avatar_default'] ?? '')),
                'quota.human_total' => (string) $cap($in['quota_human_total'] ?? 0),
                'quota.ai_total'    => (string) $cap($in['quota_ai_total'] ?? 0),
                'quota.ai_per_human' => (string) $cap($in['quota_ai_per_human'] ?? 0),
                'quota.account_per_oauth' => (string) $cap($in['quota_account_per_oauth'] ?? 0),
                'auth.mode'      => $authMode,
                // 第三方人机验证：secret 只留在服务端 settings，前端只拿 site key
                'auth.captcha.provider'   => $capProvider,
                'auth.captcha.site_key'   => $capSiteKey,
                'auth.captcha.secret'     => $capSecret,
                'auth.captcha.verify_url' => $capVerify,
                'oauth.enabled'  => $oauthOn ? '1' : '0',
                'oauth.ban_sync' => $oauthOn ? $switch($in['oauth_ban_sync'] ?? 0) : '0',
                // 键名必须和 OAuth::start() / 后台「OAuth 登录」页读的一致，否则装完门禁是空的
                'oauth.provider'        => $oauth['provider'],
                'oauth.client_id'       => $oauth['client_id'],
                'oauth.client_secret'   => $oauth['client_secret'],
                'oauth.authorize_url'   => $oauth['authorize_url'],
                'oauth.token_url'       => $oauth['token_url'],
                'oauth.userinfo_url'    => $oauth['userinfo_url'],
                'oauth.scope'           => $oauth['scope'],
                // 自动发现用的站点根地址，存下来方便后台再拉一次
                'oauth.issuer'          => trim((string) ($in['oauth_issuer'] ?? '')),
            ];
            if ($lightRel) $settings['site.icon_light'] = $lightRel;
            if ($darkRel)  $settings['site.icon_dark']  = $darkRel;
            $ins = $pdo->prepare('REPLACE INTO settings (k, v) VALUES (?, ?)');
            foreach ($settings as $k => $v) $ins->execute([$k, $v]);

            $pdo->prepare("INSERT INTO scopes (slug, title, intro, invite, cluster_id, human_cap, ai_cap, archived, created_at) VALUES ('', ?, '', ?, NULL, ?, ?, 0, ?)")
                ->execute([$groupTitle, $invite, $cap($in['human_cap'] ?? 0), $cap($in['ai_cap'] ?? 0), $now]);
            $scopeId = (int) $pdo->lastInsertId();

            $adminOauthId = null;
            if ($oauthOn) {
                $pdo->prepare('INSERT INTO oauth_identities (provider, sub, email, display, created_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$oauth['provider'], $adminSub, $adminEmail, $admin, $now]);
                $adminOauthId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare("INSERT INTO accounts (name, oauth_id, pass_hash, role, scope_id, banned, deleted_at, created_at) VALUES (?, ?, ?, 'super', NULL, 0, NULL, ?)")
                ->execute([$admin, $adminOauthId, password_hash($password, PASSWORD_DEFAULT), $now]);
            $adminId = (int) $pdo->lastInsertId();

            $token = Token::issue();
            $pdo->prepare("INSERT INTO members (scope_id, name, kind, account_id, owner_id, oauth_id, token_hash, avatar, banned, deleted_at, created_at, last_seen_at) VALUES (?, ?, 'human', ?, NULL, ?, ?, NULL, 0, NULL, ?, ?)")
                ->execute([$scopeId, $admin, $adminId, $adminOauthId, Token::hash($token), $now, $now]);

            if ($oauthOn) {
                $bound = $pdo->prepare('SELECT oauth_id FROM accounts WHERE id = ?');
                $bound->execute([$adminId]);
                if ((int) $bound->fetchColumn() <= 0) {
                    throw new RuntimeException('管理员没有绑定 OAuth 身份，不能写入 .lock');
                }
            }

            file_put_contents(APP_ROOT . '/config.php', "<?php\nreturn " . var_export($cfg, true) . ";\n");
            $lock = [
                // 安装时也按发布清单写，.lock 只作 api/manifest.json 丢失时的回退
                'version'       => \LightGroup\Web\Version::current(),
                'installed_at'  => $now,
                'site'          => $siteName,
                'admin_id'      => $adminId,
                'secret'        => $cfg['secret'],
                'db_driver'     => $cfg['driver'],
                'oauth_enabled' => $oauthOn ? 1 : 0,
            ];
            file_put_contents(APP_ROOT . '/.lock.tmp', json_encode($lock, JSON_UNESCAPED_UNICODE));
            rename(APP_ROOT . '/.lock.tmp', APP_ROOT . '/.lock');

            return ['ok' => true, 'invite' => $invite, 'token' => $token];

        } catch (Throwable $ex) {
            return ['ok' => false, 'err' => $ex->getMessage()];
        }
    }

    /** 组装 config.php 用的数据库配置 */
    private static function dbConfig(array $in): array
    {
        $driver = (string) ($in['db_driver'] ?? 'sqlite');
        $secret = bin2hex(random_bytes(16));
        if ($driver === 'mysql') {
            $host = trim((string) ($in['db_host'] ?? '127.0.0.1'));
            $database = trim((string) ($in['db_name'] ?? ''));
            $user = trim((string) ($in['db_user'] ?? ''));
            if ($host === '' || $database === '' || $user === '') {
                throw new RuntimeException('MySQL 需要填主机、库名和用户名');
            }
            if (!extension_loaded('pdo_mysql')) {
                throw new RuntimeException('当前 PHP 没有启用 pdo_mysql，只能选 SQLite');
            }
            return [
                'driver'   => 'mysql',
                'host'     => $host,
                'port'     => (int) ($in['db_port'] ?? 3306) ?: 3306,
                'database' => $database,
                'user'     => $user,
                'password' => (string) ($in['db_password'] ?? ''),
                'secret'   => $secret,
            ];
        }
        $path = trim((string) ($in['db_path'] ?? ''));
        if ($path === '') $path = APP_ROOT . '/data/lightgroup.sqlite';
        elseif (!preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/]|[a-z]+://)#i', $path)) $path = APP_ROOT . '/' . ltrim($path, '/\\');
        return ['driver' => 'sqlite', 'path' => $path, 'secret' => $secret];
    }

    /**
     * 测试数据库连接：不建表、不写 config.php。
     * SQLite 会真的打开一次文件（新建的就删掉），MySQL 会连上去查一次版本号。
     * @return array{ok:bool,msg:string}
     */
    public static function testDb(array $in): array
    {
        $driver = (string) ($in['db_driver'] ?? 'sqlite');
        try {
            if ($driver === 'mysql') {
                if (!extension_loaded('pdo_mysql')) {
                    return ['ok' => false, 'msg' => '当前 PHP 没有启用 pdo_mysql'];
                }
                $host     = trim((string) ($in['db_host'] ?? ''));
                $database = trim((string) ($in['db_name'] ?? ''));
                $user     = trim((string) ($in['db_user'] ?? ''));
                if ($host === '' || $database === '' || $user === '') {
                    return ['ok' => false, 'msg' => '主机、库名、用户名都要填'];
                }
                $port = (int) ($in['db_port'] ?? 3306) ?: 3306;
                $dsn  = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
                $pdo  = new \PDO($dsn, $user, (string) ($in['db_password'] ?? ''), [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 5,
                ]);
                $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
                return ['ok' => true, 'msg' => '连接成功 · MySQL ' . $version];
            }

            $path = trim((string) ($in['db_path'] ?? ''));
            if ($path === '') {
                $path = APP_ROOT . '/data/lightgroup.sqlite';
            } elseif (!preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/]|[a-z]+://)#i', $path)) {
                $path = APP_ROOT . '/' . ltrim($path, '/\\');
            }
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return ['ok' => false, 'msg' => '目录不存在也建不出来：' . $dir];
            }
            if (!is_writable($dir)) {
                return ['ok' => false, 'msg' => '目录不可写：' . $dir];
            }
            $fresh = !is_file($path);
            $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA journal_mode=WAL');
            $version = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
            $pdo = null;
            if ($fresh) {
                foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                    if (is_file($file)) @unlink($file);
                }
            }
            return ['ok' => true, 'msg' => '连接成功 · SQLite ' . $version];
        } catch (Throwable $ex) {
            return ['ok' => false, 'msg' => $ex->getMessage()];
        }
    }

    /** 保存裁切后的图标，返回 /assets/icon/xxx.png */
    private static function saveIcon(string $dataUrl, string $kind): string
    {
        if (!preg_match('#^data:image/(png|jpeg|webp);base64,#', $dataUrl, $m)) {
            throw new RuntimeException('图标格式不支持');
        }
        $bin = base64_decode(substr($dataUrl, strlen($m[0])), true);
        if ($bin === false) throw new RuntimeException('图标数据损坏');
        $img = @imagecreatefromstring($bin);
        if (!$img) throw new RuntimeException('无法读取图标图片');

        $w = imagesx($img);
        $h = imagesy($img);
        $side = min($w, $h);
        $x = intdiv($w - $side, 2);
        $y = intdiv($h - $side, 2);
        $dst = imagecreatetruecolor(512, 512);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, 511, 511, $transparent);
        imagealphablending($dst, true);
        imagecopyresampled($dst, $img, 0, 0, $x, $y, 512, 512, $side, $side);

        $dir = PUBLIC_ROOT . self::ICON_DIR;
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = $kind . '-' . gmdate('Ymd') . '-' . bin2hex(random_bytes(4)) . '.png';
        imagepng($dst, $dir . '/' . $name);
        return self::ICON_DIR . '/' . $name;
    }

    /** 生成 public/favicon.ico（用浅色模式那张，没有就用深色，再没有就画默认图标） */
    private static function writeFavicon(?string $lightRel): void
    {
        $src = null;
        if ($lightRel) {
            $file = PUBLIC_ROOT . $lightRel;
            if (is_file($file)) $src = @imagecreatefrompng($file);
        }
        if (!$src) $src = self::defaultIcon(512);

        $w = imagesx($src);
        $h = imagesy($src);
        $sizes = [16, 32, 48, 64, 128, 256];
        $pngs = [];
        foreach ($sizes as $s) {
            $d = imagecreatetruecolor($s, $s);
            imagealphablending($d, false);
            imagesavealpha($d, true);
            $t = imagecolorallocatealpha($d, 0, 0, 0, 127);
            imagefilledrectangle($d, 0, 0, $s, $s, $t);
            imagealphablending($d, true);
            imagecopyresampled($d, $src, 0, 0, 0, 0, $s, $s, $w, $h);
            ob_start();
            imagepng($d);
            $pngs[$s] = (string) ob_get_clean();
        }
        $n = count($pngs);
        $offset = 6 + 16 * $n;
        $dir = pack('vvv', 0, 1, $n);
        $entries = '';
        $blob = '';
        foreach ($pngs as $size => $png) {
            $dim = $size >= 256 ? 0 : $size;
            $entries .= pack('CCCCvvVV', $dim, $dim, 0, 0, 1, 32, strlen($png), $offset);
            $blob .= $png;
            $offset += strlen($png);
        }
        file_put_contents(PUBLIC_ROOT . '/favicon.ico', $dir . $entries . $blob);
    }

    /**
     * 默认图标：蓝色圆角方块 + 两道白色细圆环，和 assets/favicon.svg 同一套画法。
     * 环的半径/线宽按 svg 的 64 格坐标系换算（外圆 r=15、内圆 r=6、描边 4.2），
     * 用「白色实心圆叠蓝色内孔」的方式画环，比 imagesetthickness 的描边干净。
     */
    public static function defaultIcon(int $size = 512)
    {
        $img = imagecreatetruecolor($size, $size);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $transparent);
        imagealphablending($img, true);
        $blue = imagecolorallocate($img, 37, 99, 235);
        $white = imagecolorallocate($img, 255, 255, 255);
        $r = (int) round($size * 0.22);
        // 圆角方块：两条十字矩形铺满直边，四个角再各补一个半径 r 的圆
        imagefilledrectangle($img, $r, 0, $size - 1 - $r, $size - 1, $blue);
        imagefilledrectangle($img, 0, $r, $size - 1, $size - 1 - $r, $blue);
        imagefilledellipse($img, $r, $r, $r * 2, $r * 2, $blue);
        imagefilledellipse($img, $size - 1 - $r, $r, $r * 2, $r * 2, $blue);
        imagefilledellipse($img, $r, $size - 1 - $r, $r * 2, $r * 2, $blue);
        imagefilledellipse($img, $size - 1 - $r, $size - 1 - $r, $r * 2, $r * 2, $blue);
        $c = (int) round($size / 2);
        foreach ([[15.0, 4.2], [6.0, 4.2]] as $ring) {
            $rad = $ring[0] / 64 * $size;
            $half = $ring[1] / 64 * $size / 2;
            $outerD = max(2, (int) round(($rad + $half) * 2));
            $innerD = (int) round(($rad - $half) * 2);
            imagefilledellipse($img, $c, $c, $outerD, $outerD, $white);
            if ($innerD > 2) {
                imagefilledellipse($img, $c, $c, $innerD, $innerD, $blue);
            }
        }
        return $img;
    }
}
