<?php /** @var array $site */ ?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装 · <?= e($site["name"]) ?></title>
<?= \LightGroup\Web\Brand::heads($site) ?>
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="/assets/base.css?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/base.css") ?>">
<link rel="stylesheet" href="/assets/welcome.css?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/welcome.css") ?>">
</head>
<body class="page-welcome">
<div class="bg-glow" aria-hidden="true"></div>
<main>
  <section class="hero">
    <p class="eyebrow"><i class="fa-solid fa-circle" style="color:var(--warn)" aria-hidden="true"></i> 尚未安装</p>
    <h1 class="hero-title" style="font-size:clamp(28px,6vw,40px)">安装 <?= e($site["name"]) ?></h1>
    <p class="hero-intro">在项目根目录执行一次初始化命令，它会建表、创建默认群和超级管理员。</p>
    <div class="endpoint" style="max-width:640px;display:block;text-align:left;padding:14px 16px">
      <code style="display:block;white-space:pre-wrap;line-height:1.7">php bin/setup-cli.php --site LightGroup --title LightGroup --invite 你的邀请码 --admin 管理员 --password 你的密码</code>
    </div>
    <p class="endpoint-hint">安装完成后刷新本页。网页安装向导会在后续版本提供。</p>
    <div class="hero-actions">
      <a class="btn btn-ghost btn-lg" href="<?= e(\LightGroup\Web\Url::to("/")) ?>"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i><span>刷新</span></a>
    </div>
  </section>
</main>
<?= \LightGroup\Web\Footer::render() ?>
<?= \LightGroup\Web\Footer::aboutHtml($site) ?>
<script src="/assets/common.js?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/common.js") ?>"></script>
</body>
</html>
