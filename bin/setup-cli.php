<?php
declare(strict_types=1);

require dirname(__DIR__) . "/src/bootstrap.php";

use LightGroup\Api\Token;
use LightGroup\Store\Db;
use LightGroup\Store\Schema;

$opts = getopt("", ["site:", "title:", "invite:", "admin:", "password:", "db:", "auth-mode:", "force"]);
$site     = $opts["site"] ?? "LightGroup";
$title    = $opts["title"] ?? "LightGroup";
$invite   = $opts["invite"] ?? "" ;
$admin    = $opts["admin"] ?? "管理员";
$password = $opts["password"] ?? "";
$force    = isset($opts["force"]);
$authMode = (string) ($opts["auth-mode"] ?? "none");
if (!in_array($authMode, ["oauth", "captcha", "none"], true)) {
    fwrite(STDERR, "--auth-mode 只能是 oauth、captcha 或 none\n");
    exit(1);
}

if (lg_installed() && !$force) {
    fwrite(STDERR, "已经安装过了（存在 .lock）。加 --force 会覆盖数据库。\n");
    exit(1);
}
if ($invite === "") $invite = substr(bin2hex(random_bytes(3)), 0, 6);
if ($password === "") {
    fwrite(STDERR, "缺少 --password\n");
    exit(1);
}

$dbRel = (string) ($opts["db"] ?? "data/lightgroup.sqlite");
$dbPath = preg_match('#^([A-Za-z]:|/)#', $dbRel) ? $dbRel : APP_ROOT . "/" . $dbRel;
$dbDir = dirname($dbPath);
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0775, true);
}
if ($force) {
    foreach (["", "-wal", "-shm"] as $suffix) {
        $f = $dbPath . $suffix;
        if (is_file($f)) unlink($f);
    }
}

$cfg = [
    "driver" => "sqlite",
    "path"   => $dbPath,
    "secret" => bin2hex(random_bytes(16)),
];

$pdo = Db::connect($cfg);
Schema::install($pdo, "sqlite");

$now = lg_now();

$settings = [
    "site.name" => $site,
    "site.intro" => "",
    "theme" => "light",
    "avatar.default" => "",
    "quota.human_total" => "0",
    "quota.ai_total" => "0",
    "quota.ai_per_human" => "0",
    "quota.account_per_oauth" => "0",
    "auth.mode" => $authMode,
    "oauth.enabled" => $authMode === "oauth" ? "1" : "0",
    "oauth.ban_sync" => "0",
];
$ins = $pdo->prepare("INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)");
foreach ($settings as $k => $v) $ins->execute([$k, $v]);

$pdo->prepare("INSERT INTO scopes (slug, title, intro, invite, cluster_id, human_cap, ai_cap, archived, created_at) VALUES ('', ?, '', ?, NULL, 0, 0, 0, ?)")
    ->execute([$title, $invite, $now]);
$scopeId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO accounts (name, oauth_id, pass_hash, role, scope_id, banned, deleted_at, created_at) VALUES (?, NULL, ?, 'super', NULL, 0, NULL, ?)")
    ->execute([$admin, password_hash($password, PASSWORD_DEFAULT), $now]);
$adminId = (int) $pdo->lastInsertId();

$adminToken = Token::issue();
$pdo->prepare("INSERT INTO members (scope_id, name, kind, account_id, owner_id, oauth_id, token_hash, avatar, banned, deleted_at, created_at, last_seen_at) VALUES (?, ?, 'human', ?, NULL, NULL, ?, NULL, 0, NULL, ?, ?)")
    ->execute([$scopeId, $admin, $adminId, Token::hash($adminToken), $now, $now]);

file_put_contents(APP_ROOT . "/config.php", "<?php\nreturn " . var_export($cfg, true) . ";\n");

$lock = [
    "version" => "0.1.0",
    "installed_at" => $now,
    "site" => $site,
    "admin_id" => $adminId,
    "secret" => $cfg["secret"],
    "db_driver" => "sqlite",
];
file_put_contents(APP_ROOT . "/.lock.tmp", json_encode($lock, JSON_UNESCAPED_UNICODE));
rename(APP_ROOT . "/.lock.tmp", APP_ROOT . "/.lock");

echo "安装完成\n";
echo "  站点: $site\n";
echo "  邀请码: $invite\n";
echo "  超管: $admin\n";
echo "  超管 token（Operit 用这个进群）: $adminToken\n";
echo "  默认群地址: http://127.0.0.1:8081/g/\n";