<?php /** @var array $site */ /** @var string $mode */ /** @var string $next */ /** @var string $err */ /** @var string $who 用户刚提交的昵称/账号（失败回填用；不叫 name，见 Login::render 注释） */
/** @var bool $captchaOn */ /** @var array|null $captcha */ /** @var bool $oauthOn 后台开了 OAuth 并填了 Client ID */
$admin = $mode === "admin";
$qs = $next !== "" ? "next=" . rawurlencode($next) : "";
$toMember = \LightGroup\Web\Url::to("/login" . ($qs !== "" ? "?" . $qs : ""));
$toAdmin  = \LightGroup\Web\Url::to("/login?m=admin" . ($qs !== "" ? "&" . $qs : ""));
// 验证码：内建滑块或第三方 widget（turnstile / hcaptcha / recaptcha）
$cap = is_array($captcha) ? $captcha : null;
$capProvider = $cap ? (string) $cap["provider"] : "builtin";
$capSiteKey  = $cap ? (string) $cap["site_key"] : "";
$capScript   = $cap ? (string) $cap["script"] : "";
$capWarn     = $cap ? (string) $cap["warning"] : "";
if ($capProvider === "geetest4") {
    $capHint = "点一下下面的按钮，按提示完成验证";
} elseif ($capProvider === "recaptcha") {
    $capHint = "点一下「我不是机器人」，过了就能进";
} elseif ($capProvider === "hcaptcha") {
    $capHint = "点一下下面的方框，过了就能进";
} else {
    $capHint = "点一下下面的方框，等它变绿";
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>登录 · <?= e($site["name"]) ?></title>
<meta name="theme-color" content="<?= e($site["accent"] ?? "#2563eb") ?>">
<meta name="robots" content="noindex">
<?= \LightGroup\Web\Brand::heads($site) ?>
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="/assets/base.css?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/base.css") ?>">
<?= \LightGroup\Web\ThemePreset::style() ?>
<link rel="stylesheet" href="/assets/login.css?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/login.css") ?>">
</head>
<body class="page-login">
<div class="bg-glow" aria-hidden="true"></div>

<main class="login-shell">
  <div class="login-stage">
    <section class="login-card" aria-labelledby="login-name">
      <div class="login-brand">
        <?= \LightGroup\Web\Brand::icon($site, "login-mark") ?>
        <div class="login-brand-t">
          <b id="login-name"><?= e($site["name"]) ?></b>
          <small><?= $admin ? "管理员登录" : "进群聊" ?></small>
        </div>
      </div>

      <p class="login-tip"><?= $admin
        ? ("用站长的账号和密码进管理后台。" . ($captchaOn ? "开过人机验证，登录时也要过一道。" : ""))
        : ($gate
            ? "本站开了 OAuth 门禁：先用第三方账号登录主账号，再挑一个业务账号进群。"
            : "第一次来？填昵称和邀请码，自己设个密码就能进。回来时填密码。") ?></p>

      <?php if ($gate): ?>
      <?php if ($oauthOn): ?>
      <a class="btn btn-primary btn-lg login-go" href="<?= e(\LightGroup\Web\Url::to("/oauth/start")) ?>">
        <i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i><span>用第三方账号登录</span>
      </a>
      <?php else: ?>
      <p class="login-tip">OAuth 还没配好，现在没人能登进来，请联系管理员。</p>
      <?php endif; ?>
      <?php if ($err !== ""): ?>
      <p class="login-err" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= e($err) ?></span></p>
      <?php endif; ?>
      <?php else: ?>
      <form class="login-form" method="post" action="<?= e(\LightGroup\Web\Url::to("/login")) ?>" autocomplete="off">
        <input type="hidden" name="mode" value="<?= $admin ? "admin" : "member" ?>">
        <?php if ($next !== ""): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>

        <label class="login-field">
          <span><?= $admin ? "账号" : "昵称" ?></span>
          <input class="field" type="text" name="name" value="<?= e($who) ?>" data-srv="<?= e($who) ?>" maxlength="24" required
                 autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                 placeholder="<?= $admin ? "管理员账号" : "群里的名字" ?>" <?= $who === "" ? "autofocus" : "" ?>>
        </label>

        <?php if ($admin): ?>
        <label class="login-field">
          <span>密码</span>
          <input class="field" type="password" name="password" autocomplete="new-password" required placeholder="密码">
        </label>
        <?php else: ?>
        <label class="login-field">
          <span>邀请码</span>
          <input class="field" type="text" name="invite" autocomplete="off" required placeholder="找群主要一个">
        </label>
        <label class="login-field">
          <span>密码</span>
          <input class="field" type="password" name="password" minlength="6" autocomplete="new-password" required placeholder="至少 6 位，第一次来就是设密码">
        </label>
        <?php endif; ?>

        <?php /* 管理员和成员共用这一道：后台是整站最值钱的入口，不能比进群聊还松 */ ?>
        <?php if ($captchaOn): ?>
          <?php if ($capProvider === "builtin"): ?>
        <div class="captcha" id="captcha" data-start="<?= e(\LightGroup\Web\Url::to("/captcha/start")) ?>">
          <div class="captcha-tip"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><span>拖动滑块，把小方块送到缺口上</span></div>
          <div class="captcha-track" id="cap-track">
            <div class="captcha-hole" id="cap-hole"></div>
            <div class="captcha-knob" id="cap-knob"><i class="fa-solid fa-angles-right" aria-hidden="true"></i></div>
          </div>
          <input type="hidden" name="cap_token" id="cap-token" value="">
          <input type="hidden" name="cap_off" id="cap-off" value="">
          <input type="hidden" name="cap_ms" id="cap-ms" value="">
          <input type="hidden" name="cap_moves" id="cap-moves" value="">
          <input type="hidden" name="cap_path" id="cap-path" value="">
        </div>
          <?php else: ?>
        <div class="captcha captcha-third" id="captcha3">
          <div class="captcha-tip"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><span><?= e($capHint) ?></span></div>
            <?php if ($capProvider === "turnstile"): ?>
          <div class="cf-turnstile" data-sitekey="<?= e($capSiteKey) ?>" data-language="zh-CN"></div>
            <?php elseif ($capProvider === "hcaptcha"): ?>
          <div class="h-captcha" data-sitekey="<?= e($capSiteKey) ?>" data-hl="zh"></div>
            <?php elseif ($capProvider === "geetest4"): ?>
          <div id="geetest4-box" class="geetest4-box"></div>
            <?php else: ?>
          <div class="g-recaptcha" data-sitekey="<?= e($capSiteKey) ?>" data-hl="zh-CN"></div>
            <?php endif; ?>
          <input type="hidden" name="cap3" id="cap3" value="">
        </div>
          <?php endif; ?>
          <?php if ($capWarn !== ""): ?>
        <p class="login-tip"><?= e($capWarn) ?></p>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($err !== ""): ?>
        <p class="login-err" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= e($err) ?></span></p>
        <?php endif; ?>

        <button class="btn btn-primary btn-lg login-go" type="submit"><?= $admin ? "进入后台" : "进入群聊" ?></button>
      </form>
      <?php endif; ?>

      <?php if ($oauthOn && !$gate): ?>
      <div class="login-or"><span>或</span></div>
      <a class="btn btn-ghost btn-lg login-go" href="<?= e(\LightGroup\Web\Url::to("/oauth/start")) ?>">
        <i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i><span>用第三方账号登录</span>
      </a>
      <?php endif; ?>

      <p class="login-foot">
        <?php if ($admin): ?>
        <a href="<?= e($toMember) ?>">用昵称进群</a>
        <?php else: ?>
        <a href="<?= e($toAdmin) ?>">管理员登录</a>
        <?php endif; ?>
        <span class="login-dot" aria-hidden="true">·</span>
        <a href="<?= e(\LightGroup\Web\Url::to("/")) ?>">回到首页</a>
      </p>
    </section>
  </div>

  <?= \LightGroup\Web\Footer::render() ?>
</main>

<?= \LightGroup\Web\Footer::aboutHtml($site) ?>
<?php if ($captchaOn && $capProvider !== "builtin" && $capScript !== ""): ?>
<script src="<?= e($capScript) ?>" async defer></script>
<?php endif; ?>
<script src="/assets/common.js?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/common.js") ?>"></script>
<?php if ($captchaOn && $capProvider === "builtin"): ?>
<script src="/assets/login.js?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/login.js") ?>"></script>
<?php endif; ?>
<script>
// 老版本把 token 存在 localStorage 里；现在登录只走 cookie session，顺手清掉
try { localStorage.removeItem("lg.token"); } catch (e) { void e; }

// 浏览器（Chrome 和 Electron 里的 Chromium 一样）会把记下来的账号自动填进登录框。
// 服务端 GET /login 传的就是空值，这里把「服务端空、输入框却被填了」的情况清干净：
// 先把输入框设成只读挡住自动填充，用户一碰就放开；再在几个时间点兜一遍，
// 覆盖 load 之后才填、以及从后退缓存（pageshow）恢复的老值。
// 失败回填的值在 data-srv 里，非空就一律不碰。
(function () {
  var form = document.querySelector(".login-form");
  if (!form) return;
  var fields = form.querySelectorAll("input[data-srv]");
  var touched = false;
  var i;
  function clean() {
    if (touched) return;
    for (var k = 0; k < fields.length; k++) {
      var el = fields[k];
      if (el.getAttribute("data-srv") === "" && el.value !== "") el.value = "";
    }
  }
  function unlock(el) {
    el.readOnly = false;
  }
  for (i = 0; i < fields.length; i++) {
    var f = fields[i];
    if (f.getAttribute("data-srv") !== "") continue;
    f.readOnly = true;
    f.addEventListener("pointerdown", function () { unlock(this); touched = true; });
    f.addEventListener("focus", function () { unlock(this); });
    f.addEventListener("keydown", function () { unlock(this); touched = true; });
  }
  window.addEventListener("load", clean);
  window.addEventListener("pageshow", clean);
  document.addEventListener("DOMContentLoaded", clean);
  var n = 0;
  var timer = setInterval(function () {
    clean();
    if (++n >= 12) clearInterval(timer);
  }, 250);
  clean();
})();

<?php if ($captchaOn && $capProvider === "geetest4"): ?>
// Geetest v4：gt4.js 是异步加载的，initGeetest4 可能还没就绪，先等它；
// product=bind 表示验证框由我们自己弹，点「开始验证」或提交时再 showCaptcha。
(function () {
  var box = document.getElementById("geetest4-box");
  var form = document.querySelector(".login-form");
  if (!box || !form) return;
  var out = document.getElementById("cap3");
  var gt = null;
  var passed = false;
  function tip(t) {
    var s = document.querySelector("#captcha3 .captcha-tip span");
    if (s) s.textContent = t;
  }
  function boot() {
    if (typeof window.initGeetest4 !== "function") { setTimeout(boot, 200); return; }
    window.initGeetest4({
      captchaId: <?= json_encode($capSiteKey) ?>,
      product: "bind",
      language: "zho"
    }, function (cap) {
      gt = cap;
      cap.onSuccess(function () {
        var v = cap.getValidate() || {};
        var payload = {
          lot_number: v.lot_number || "",
          captcha_output: v.captcha_output || "",
          pass_token: v.pass_token || "",
          gen_time: v.gen_time || ""
        };
        if (out) out.value = JSON.stringify(payload);
        passed = true;
        tip("验证通过，可以登录了");
      });
      cap.onClose(function () {
        if (!passed) tip("还没完成验证，点下面的按钮再试一次");
      });
      cap.onError(function () { tip("验证框加载失败，刷新页面再试"); });
      var b = document.createElement("button");
      b.type = "button";
      b.className = "btn btn-ghost";
      b.textContent = "开始验证";
      b.addEventListener("click", function () { cap.showCaptcha(); });
      box.appendChild(b);
    });
  }
  boot();
  form.addEventListener("submit", function (ev) {
    if (passed) return;
    ev.preventDefault();
    tip("先完成人机验证再登录");
    if (gt) gt.showCaptcha();
  });
})();
<?php else: ?>
// 第三方人机验证：widget 通过之后才把 token 塞进表单，没通过就别提交
(function () {
  var box = document.getElementById("captcha3");
  var form = document.querySelector(".login-form");
  if (!box || !form) return;
  var out = document.getElementById("cap3");
  form.addEventListener("submit", function (ev) {
    var tok = "";
    var probes = box.querySelectorAll('input[name="cf-turnstile-response"], textarea[name="h-captcha-response"], textarea[name="g-recaptcha-response"], input[name="h-captcha-response"], input[name="g-recaptcha-response"]');
    for (var i = 0; i < probes.length; i++) {
      if (probes[i].value) { tok = probes[i].value; break; }
    }
    if (!tok) {
      ev.preventDefault();
      var tip = box.querySelector(".captcha-tip span");
      if (tip) tip.textContent = "先点一下上面的验证框，等它显示通过了再登录";
      return;
    }
    if (out) out.value = tok;
  });
})();
<?php endif; ?>
</script>
</body>
</html>
