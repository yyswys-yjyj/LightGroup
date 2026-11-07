<?php
/** @var array $site */
// 管理页面的 CSRF token：每个会话一个，写操作由 admin.js 放进 X-LG-CSRF 头
lg_session();
if (empty($_SESSION["lg_csrf"])) {
    $_SESSION["lg_csrf"] = bin2hex(random_bytes(16));
}
$csrf = (string) $_SESSION["lg_csrf"];

// 回调地址优先用后台里配的 site.url，没有就用当前访问域名，所以必须渲染进页面给人抄
$callbackUrl = "";
try {
    $callbackUrl = \LightGroup\Api\OAuth::callbackUrl(\LightGroup\Store\Db::connect());
} catch (\Throwable $e) {
    $callbackUrl = "";
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>管理 · <?= e($site["name"]) ?></title>
<?= \LightGroup\Web\Brand::heads($site) ?>
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="/assets/base.css?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/base.css") ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/admin.css") ?>">
</head>
<body class="page-admin">
<div id="adm" class="adm">
  <div class="boot"><?= \LightGroup\Web\Brand::icon($site, "boot-mark") ?><p>正在加载…</p></div>
</div>
<script>window.__LG_BOOT__ = <?= json_encode([
    "site"     => $site,
    "base"     => "/g/",
    "foot"     => \LightGroup\Web\Footer::render(),
    "about"    => \LightGroup\Web\Footer::aboutHtml($site),
    "csrf"     => $csrf,
    "callback" => $callbackUrl,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php $adminVer = (int) max(
    @filemtime(__DIR__ . "/../../../public/assets/admin.js") ?: 0,
    @filemtime(__DIR__ . "/../../../public/assets/admin.css") ?: 0,
    @filemtime(__DIR__ . "/../../../public/assets/base.css") ?: 0
); ?>
<?= \LightGroup\Web\Footer::aboutHtml($site) ?>
<script src="/assets/common.js?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/common.js") ?>"></script>
<script type="module" src="/assets/admin.js?v=<?= (int) $adminVer ?>"></script>
</body>
</html>
