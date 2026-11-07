<?php /** @var array $site */ /** @var string $base */ /** @var array|null $bootMe */
$appVer = (int) max(
    @filemtime(__DIR__ . "/../../../public/assets/app.css") ?: 0,
    @filemtime(__DIR__ . "/../../../public/assets/base.css") ?: 0
);
// 前端拆成 /assets/js/*.js 之后，改模块也要让 ?v= 跟着变
$lgJsImports = [];
foreach (@glob(PUBLIC_ROOT . "/assets/js/*.js") ?: [] as $jsFile) {
    $appVer = (int) max($appVer, (int) (@filemtime($jsFile) ?: 0));
    $lgName = basename($jsFile);
    $lgJsImports["/assets/js/" . $lgName] = "/assets/js/" . $lgName . "?v=" . (int) (@filemtime($jsFile) ?: 0);
} ?>
<!doctype html>
<html lang="zh-CN">
<head>
<script>
// 首屏就定深浅，避免深色用户先看到一帧白底；键与 public/assets/js/core.js 的 LSK('theme') 一致
(function () {
  try {
    var m = localStorage.getItem("lg.theme") || "auto";
    if (m !== "light" && m !== "dark") m = matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
    document.documentElement.setAttribute("data-theme", m);
    document.documentElement.setAttribute("data-theme-mode", m);
  } catch (e) { void e; }
})();
</script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($site["name"]) ?></title>
<meta name="theme-color" content="#2563eb">
<?= \LightGroup\Web\Brand::heads($site) ?>
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="/assets/base.css?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/base.css") ?>">
<link rel="stylesheet" href="/assets/app.css?v=<?= $appVer ?>">
<!-- 资料卡 / 资料页 / markdown 编辑器的样式；带 filemtime，改完不用手动改版本号 -->
<link rel="stylesheet" data-lg-profile="1" href="/assets/profile.css?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/profile.css") ?>">
</head>
<body class="page-app">
<div id="app">
  <div class="boot">
    <?= \LightGroup\Web\Brand::icon($site, "boot-mark") ?>
    <p>正在加载 <?= e($site["name"]) ?>…</p>
  </div>
</div>
<script>
window.__LG_BOOT__ = <?= json_encode([
    "site"    => $site,
    "base"    => $base,
    "me"      => $bootMe,
    // 站内路径（全站单一入口，路由里没有 .php）
    "urls"    => [
        "app"    => \LightGroup\Web\Url::to("/app"),
        "login"  => \LightGroup\Web\Url::to("/login"),
        "logout" => \LightGroup\Web\Url::to("/logout"),
        "admin"  => \LightGroup\Web\Url::to("/admin"),
    ],
    "foot"    => \LightGroup\Web\Footer::render(),
    "about"   => \LightGroup\Web\Footer::aboutHtml($site),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?= \LightGroup\Web\Footer::aboutHtml($site) ?>
<script src="/assets/common.js?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/common.js") ?>"></script>
<?php // app.js 里的相对 import 解析到 /assets/js/*.js，用 importmap 给每个模块带上 mtime 版本号，改了哪个模块就只失效哪个 ?>
<script type="importmap"><?= json_encode(["imports" => $lgJsImports], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script type="module" src="/assets/js/app.js?v=<?= (int) $appVer ?>"></script>
</body>
</html>