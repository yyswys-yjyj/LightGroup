<?php /** @var array $site */ /** @var string $path */ ?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>页面不存在 · <?= e($site["name"]) ?></title>
<?= \LightGroup\Web\Brand::heads($site ?? []) ?>
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="/assets/base.css?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/base.css") ?>">
<?= \LightGroup\Web\ThemePreset::style() ?>
<link rel="stylesheet" href="/assets/welcome.css?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/welcome.css") ?>">
</head>
<body class="page-welcome">
<div class="bg-glow" aria-hidden="true"></div>
<main>
  <section class="hero">
    <h1 class="hero-title">404</h1>
    <p class="hero-intro">没有找到 <code><?= e($path) ?></code></p>
    <div class="hero-actions">
      <a class="btn btn-primary btn-lg" href="<?= e(\LightGroup\Web\Url::to("/")) ?>"><i class="fa-solid fa-house" aria-hidden="true"></i><span>回到首页</span></a>
    </div>
  </section>
  <?= \LightGroup\Web\Footer::render() ?>
</main>
<?= \LightGroup\Web\Footer::aboutHtml($site) ?>
<script src="/assets/common.js?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/common.js") ?>"></script>
</body>
</html>
