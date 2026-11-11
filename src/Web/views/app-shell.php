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
}
// 本人明确选过深浅（members.theme 的 "dark-xxx" / "light-xxx"）就由服务端直接定在 <html> 上，
// 别让下面那段本地脚本按「跟随系统」把它掰回浅色，再等接口回来变深色
$lgThemeChoice = (string) ($bootMe["theme"] ?? "");
$lgMode = strtok($lgThemeChoice, "-");
if ($lgMode !== "dark" && $lgMode !== "light") $lgMode = "";
?>
<!doctype html>
<html lang="zh-CN"<?= $lgMode !== "" ? ' data-theme="' . $lgMode . '"' : '' ?> data-theme-mode="<?= $lgMode !== "" ? $lgMode : "auto" ?>">
<head>
<script>
// 首屏就定深浅，避免深色用户先看到一帧白底；键与 public/assets/js/core.js 的 LSK('theme') 一致
(function () {
  try {
    // 只说得出三种：本人选了深/浅，或者跟随系统。服务端已经把结论写在 <html> 上，
    // 这里只补「跟随系统」那一种，不再看 localStorage —— 本地残留的模式会把首帧带偏。
    var el = document.documentElement;
    var srv = el.getAttribute("data-theme");
    var mode = el.getAttribute("data-theme-mode") || "auto";
    var m = srv;
    if (m !== "light" && m !== "dark") {
      m = (mode === "light" || mode === "dark") ? mode
        : (matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
    }
    el.setAttribute("data-theme", m);
    el.setAttribute("data-theme-mode", m);
  } catch (e) { void e; }
})();
</script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($site["name"]) ?></title>
<meta name="theme-color" content="#2563eb">
<?= \LightGroup\Web\Brand::heads($site) ?>
<?= \LightGroup\Web\ThemePreset::firstPaint((string) ($bootMe["theme"] ?? "")) ?>
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="/assets/base.css?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/base.css") ?>">
<link rel="stylesheet" href="/assets/app.css?v=<?= $appVer ?>">
<!-- 资料卡 / 资料页 / markdown 编辑器的样式；带 filemtime，改完不用手动改版本号 -->
<link rel="stylesheet" data-lg-profile="1" href="/assets/profile.css?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/profile.css") ?>">
<link rel="stylesheet" data-lg-space="1" href="/assets/space.css?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/space.css") ?>">
<?= \LightGroup\Web\ThemePreset::style((string) ($bootMe["theme"] ?? "")) ?>
<script>
// 上次真正生效的强调色（core.js 的 paintAccent 写进 lg.accent）：首帧直接套上，
// 不然会先看到站点默认色，等接口回来再换成本人的主题，看着像被白底盖了一下
(function () {
  try {
    var raw = localStorage.getItem("lg.accent");
    if (!raw) return;
    var c = JSON.parse(raw);
    var m = document.documentElement.getAttribute("data-theme") === "dark" ? "dark" : "light";
    if (!c || c.mode !== m || !c.vars) return;
    var el = document.documentElement;
    for (var k in c.vars) if (Object.prototype.hasOwnProperty.call(c.vars, k)) el.style.setProperty(k, c.vars[k]);
  } catch (e) { void e; }
})();
</script>
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