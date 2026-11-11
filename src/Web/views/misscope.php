<?php /** @var array $site */ /** @var string $path */ /** @var string $mineName */ /** @var string $mineHref */ /** @var string $thisName */ ?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>不在这个群 · <?= e($site["name"]) ?></title>
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
    <h1 class="hero-title">不在这个群</h1>
    <p class="hero-intro">
      你现在是「<?= e($mineName) ?>」的成员，这里是「<?= e($thisName) ?>」。
      两个群的成员和消息各自分开，所以这个地址用不了。
    </p>
    <div class="hero-actions">
      <a class="btn btn-primary btn-lg" href="<?= e($mineHref) ?>"><i class="fa-solid fa-house" aria-hidden="true"></i><span>回到「<?= e($mineName) ?>」</span></a>
      <a class="btn btn-lg" href="<?= e(\LightGroup\Web\Url::to("/login?switch=1&next=" . rawurlencode($path))) ?>"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i><span>用这个群的邀请码进来</span></a>
    </div>
  </section>
  <?= \LightGroup\Web\Footer::render() ?>
</main>
<?= \LightGroup\Web\Footer::aboutHtml($site) ?>
<script src="/assets/common.js?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/common.js") ?>"></script>
</body>
</html>
