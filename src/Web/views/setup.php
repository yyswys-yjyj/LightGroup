<?php /** @var array $site */ /** @var array $checks */ /** @var bool $ready */
/** 安装阶段还没有「站点地址」，只能按当前访问的域名推断；装好后后台「认证 → OAuth 登录」会按站点地址给出正式值 */
$lgCallback = rtrim(\LightGroup\Api\OAuth::origin(), "/") . \LightGroup\Api\OAuth::CALLBACK_PATH;
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装 · <?= e($site["name"]) ?></title>
<?= \LightGroup\Web\Brand::heads($site ?? []) ?>
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="/assets/base.css?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/base.css") ?>">
<link rel="stylesheet" href="/assets/setup.css?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/setup.css") ?>">
<?= \LightGroup\Web\ThemePreset::style() ?>
</head>
<body class="page-setup">
<div class="setup-wrap">
  <header class="setup-head">
    <?= \LightGroup\Web\Brand::icon($site ?? []) ?>
    <div class="setup-title">
      <h1>安装 <?= e($site["name"]) ?></h1>
      <p>七步装好一个群，人和 AI 都能进来。</p>
    </div>
  </header>

  <ol class="steps" id="steps">
    <li data-dot="1"><span>1</span><b>环境</b></li>
    <li data-dot="2"><span>2</span><b>站点</b></li>
    <li data-dot="3"><span>3</span><b>数据库</b></li>
    <li data-dot="4"><span>4</span><b>图标</b></li>
    <li data-dot="5"><span>5</span><b>群组</b></li>
    <li data-dot="6"><span>6</span><b>配额</b></li>
    <li data-dot="7"><span>7</span><b>管理员</b></li>
  </ol>

  <section class="panel step" data-step="1"<?= $ready ? ' hidden' : '' ?>>
    <div class="panel-head">
      <h2>环境自检</h2>
      <p class="lead">跑这一遍看看这台机器能不能装。有红叉就先处理，装到一半失败更麻烦。</p>
    </div>
    <ul class="check-list">
      <?php foreach ($checks as $c): ?>
      <li class="<?= $c["ok"] ? "ok" : "bad" ?>">
        <i class="fa-solid <?= $c["ok"] ? "fa-circle-check" : "fa-circle-exclamation" ?>" aria-hidden="true"></i>
        <span class="ck-label"><?= e($c["label"]) ?></span>
        <span class="ck-detail"><?= e($c["detail"]) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$ready): ?>
    <p class="tip bad"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> 有检查项没通过，先处理再继续。需要 PHP 8.1 以上、PDO SQLite、GD，并且 data/、public/ 和根目录可写。</p>
    <?php else: ?>
    <p class="tip ok"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> 都通过了，可以往下走。</p>
    <?php endif; ?>
  </section>

  <section class="panel step" data-step="2"<?= $ready ? '' : ' hidden' ?>>
    <div class="panel-head">
      <h2>站点信息</h2>
      <p class="lead">名字会出现在门面页、浏览器标题和页脚。介绍写一两句就够了。</p>
    </div>
    <label class="fld"><span>站点名称</span><input id="f-site" maxlength="40" placeholder="LightGroup" autocomplete="off"><em>1-40 个字</em></label>
    <label class="fld"><span>站点介绍</span><textarea id="f-intro" rows="3" placeholder="一个小而安静的群，人和 AI 都在这里。"></textarea><em>可以留空，会显示默认的一句话</em></label>
  </section>

  <section class="panel step" data-step="3" hidden>
    <div class="panel-head">
      <h2>数据库</h2>
      <p class="lead">先选一种，再点一下「测试连接」。测通了才允许下一步，免得装到一半连不上。</p>
    </div>
    <div class="radio-grid db-pick">
      <label class="radio-card on">
        <input type="radio" name="db_driver" value="sqlite" checked>
        <strong><i class="fa-solid fa-database" aria-hidden="true"></i> SQLite</strong>
        <span>单文件，不用装服务。数据放在 data/ 下，一台小机器就能跑。</span>
      </label>
      <label class="radio-card">
        <input type="radio" name="db_driver" value="mysql">
        <strong><i class="fa-solid fa-server" aria-hidden="true"></i> MySQL / MariaDB</strong>
        <span>已经有数据库服务时用这个，账号要有建表权限。</span>
      </label>
    </div>

    <div id="db-sqlite">
      <label class="fld"><span>数据文件路径</span><input id="f-db-path" placeholder="data/lightgroup.sqlite" autocomplete="off"><em>留空用默认位置，相对路径以站点根目录为准</em></label>
    </div>
    <div id="db-mysql" hidden>
      <div class="grid2">
        <label class="fld"><span>主机</span><input id="f-db-host" placeholder="127.0.0.1" autocomplete="off"></label>
        <label class="fld"><span>端口</span><input id="f-db-port" type="number" placeholder="3306" autocomplete="off"></label>
      </div>
      <div class="grid2">
        <label class="fld"><span>库名</span><input id="f-db-name" placeholder="lightgroup" autocomplete="off"></label>
        <label class="fld"><span>用户名</span><input id="f-db-user" placeholder="root" autocomplete="off"></label>
      </div>
      <label class="fld"><span>密码</span><input id="f-db-pass" type="password" autocomplete="new-password"><em>需要 PHP 已启用 pdo_mysql</em></label>
    </div>

    <div class="db-check">
      <button type="button" class="btn btn-ghost" id="db-test-btn"><i class="fa-solid fa-plug-circle-check" aria-hidden="true"></i> 测试连接</button>
      <span class="db-state" id="db-state" data-state="idle"><i class="fa-solid fa-circle-dot" aria-hidden="true"></i> 还没测</span>
    </div>
    <div class="db-result" id="db-result" hidden></div>
  </section>

  <section class="panel step" data-step="4" hidden>
    <div class="panel-head">
      <h2>站点图标</h2>
      <p class="lead">上传后会以圆角方框为蒙版展示在站点的每个品牌位上，并用浅色那张生成 favicon.ico。图片超出方框的部分请在这里拖一拖、缩放一下裁掉。</p>
    </div>
    <ul class="icon-note">
      <li><i class="fa-solid fa-circle-info" aria-hidden="true"></i> 透明底的图标，在深色和浅色模式下会分别垫一层不同的底色。</li>
      <li><i class="fa-solid fa-circle-info" aria-hidden="true"></i> 深色图标是可选项，不传就跟浅色用同一张。</li>
      <li><i class="fa-solid fa-circle-info" aria-hidden="true"></i> 都不传就用程序自带的蓝色图标。</li>
    </ul>
    <div class="icon-grid">
      <div class="icon-card" data-icon="light">
        <div class="icon-prev" data-prev><span class="icon-mask empty"><i class="fa-regular fa-image" aria-hidden="true"></i></span></div>
        <div class="icon-info"><strong>浅色模式</strong><span>亮色主题下显示，并生成 favicon.ico</span></div>
        <div class="icon-acts">
          <button type="button" class="btn btn-ghost btn-sm" data-pick>选择图片</button>
          <button type="button" class="btn btn-ghost btn-sm" data-clear hidden>清除</button>
        </div>
      </div>
      <div class="icon-card" data-icon="dark">
        <div class="icon-prev" data-prev><span class="icon-mask empty"><i class="fa-regular fa-image" aria-hidden="true"></i></span></div>
        <div class="icon-info"><strong>深色模式</strong><span>可选，暗色主题下显示</span></div>
        <div class="icon-acts">
          <button type="button" class="btn btn-ghost btn-sm" data-pick>选择图片</button>
          <button type="button" class="btn btn-ghost btn-sm" data-clear hidden>清除</button>
        </div>
      </div>
    </div>
  </section>

  <section class="panel step" data-step="5" hidden>
    <div class="panel-head">
      <h2>第一个群</h2>
      <p class="lead">群就是一个独立的作用域，有自己的邀请码和成员。装好以后可以在这里继续加群。</p>
    </div>
    <label class="fld"><span>群名</span><input id="f-group" maxlength="40" placeholder="留空用站点名称" autocomplete="off"></label>
    <label class="fld"><span>邀请码</span><input id="f-invite" maxlength="32" placeholder="留空自动生成" autocomplete="off"><em>4-32 位字母、数字、下划线或短横线</em></label>
    <div class="grid2">
      <label class="fld"><span>人数上限</span><input id="f-human-cap" type="number" min="0" placeholder="0"><em>0 = 跟随站点配额</em></label>
      <label class="fld"><span>AI 数量上限</span><input id="f-ai-cap" type="number" min="0" placeholder="0"><em>0 = 跟随站点配额</em></label>
    </div>

    <fieldset class="grp">
      <legend>群之间的关系</legend>
      <div class="radio-grid">
        <label class="radio-card on">
          <input type="radio" name="cluster_mode" value="single" checked>
          <strong><i class="fa-solid fa-circle-dot" aria-hidden="true"></i> 相互独立</strong>
          <span>消息、成员都只在群里，互不可见。</span>
        </label>
        <label class="radio-card" data-disabled>
          <input type="radio" name="cluster_mode" value="cluster" disabled>
          <strong><i class="fa-solid fa-circle-nodes" aria-hidden="true"></i> 互通</strong>
          <span>至少要有两个群，装好后在后台的「圈子」里建圈子再并进去。</span>
        </label>
      </div>
    </fieldset>
  </section>

  <section class="panel step" data-step="6" hidden>
    <div class="panel-head">
      <h2>配额与安全</h2>
      <p class="lead">限一下人数和 AI 数量，再选一种登录验证方式。填 0 表示这一项不限。</p>
    </div>
    <fieldset class="grp">
      <legend><i class="fa-solid fa-users" aria-hidden="true"></i> 成员配额</legend>
      <div class="grid2">
        <label class="fld"><span>站点人数总上限</span><input id="f-q-human" type="number" min="0" placeholder="0"></label>
        <label class="fld"><span>站点 AI 总数上限</span><input id="f-q-ai" type="number" min="0" placeholder="0"></label>
      </div>
      <div class="grid2">
        <label class="fld"><span>每个人类账号可建几个 AI</span><input id="f-q-per-human" type="number" min="0" placeholder="0"></label>
        <label class="fld"><span>每个 OAuth 账号可建几个群账号</span><input id="f-q-per-oauth" type="number" min="0" placeholder="0"><em>门禁关闭时不生效</em></label>
      </div>
      <label class="fld"><span>成员默认头像</span><input id="f-avatar" placeholder="留空用昵称首字" autocomplete="off"><em>填一个图片网址</em></label>
    </fieldset>

    <fieldset class="grp">
      <legend><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> 数据安全</legend>
      <div class="fld auth-mode">
        <span>登录验证方式</span>
        <div class="opt-row">
          <label class="opt">
            <input type="radio" name="f-auth-mode" id="f-auth-captcha" value="captcha" checked>
            <span><strong>内建滑块验证码</strong><em>账密之外再拖一次滑块，不用对接外部服务。</em></span>
          </label>
          <label class="opt">
            <input type="radio" name="f-auth-mode" id="f-auth-oauth" value="oauth">
            <span><strong>第三方 OAuth2 门禁</strong><em>要有一个 OAuth2 服务，下面填它的参数。</em></span>
          </label>
          <label class="opt">
            <input type="radio" name="f-auth-mode" id="f-auth-none" value="none">
            <span><strong>都不要</strong><em>只验账号密码，方便但容易被撞库。</em></span>
          </label>
        </div>
      </div>
      <div class="captcha-box" id="captcha-box">
        <p class="oauth-head"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> 人机验证方式</p>
        <p class="lead">默认用内建滑块，不依赖外部服务。接第三方要先去它们那边申请 Site Key 与 Secret Key；没填 Secret Key 开不起来，登录页会退回内建滑块并提示。</p>
        <label class="fld"><span>验证服务</span>
          <select id="f-cap-provider">
            <option value="builtin">内建滑块（默认）</option>
            <option value="turnstile">Cloudflare Turnstile</option>
            <option value="hcaptcha">hCaptcha</option>
            <option value="recaptcha">Google reCAPTCHA v2</option>
            <option value="geetest4">Geetest 行为验证 v4</option>
          </select>
        </label>
        <div id="cap3-box" hidden>
          <label class="fld"><span id="f-cap-sitekey-label">Site Key</span><input id="f-cap-sitekey" placeholder="站点公钥，会出现在登录页" autocomplete="off"></label>
          <label class="fld"><span id="f-cap-secret-label">Secret Key</span><input id="f-cap-secret" type="password" autocomplete="new-password"><em id="f-cap-secret-tip">只留在服务端；留空不算配好，会退回内建滑块</em></label>
          <label class="fld"><span>校验地址（可选）</span><input id="f-cap-verify" placeholder="https://…" autocomplete="off"><em>自建代理或私有化部署才填，留空用官方地址</em></label>
        </div>
      </div>
      <div class="oauth-box" id="oauth-box" hidden>
        <p class="oauth-head"><i class="fa-solid fa-key" aria-hidden="true"></i> OAuth2 提供方</p>
        <p class="lead">门禁开着的时候这些是必填项。Client Secret 只留在这台服务器上，别的地方不要再填同一份。</p>
        <div class="cb-box">
          <span class="cb-label">回调地址（Redirect URI）—— 要填到提供方后台</span>
          <div class="cb-row">
            <code id="f-oauth-callback"><?= e($lgCallback) ?></code>
            <button type="button" class="btn btn-ghost btn-sm" data-copy-btn="f-oauth-callback"><i class="fa-regular fa-copy" aria-hidden="true"></i> 复制</button>
          </div>
          <em class="cb-tip">去提供方后台新建一个 Web / 服务端应用，把上面这个地址原样填进「回调地址 / Redirect URI / 授权回调 URL」。一个字符都不能差，否则登录时会报 redirect_uri_mismatch。地址是按你现在访问的域名生成的，装好后在后台「认证 → OAuth 登录」里还能再复制一次（那里会优先用站点地址）。</em>
        </div>
        <div class="grid2">
          <label class="fld"><span>提供方标识</span><input id="f-oauth-provider" placeholder="generic" autocomplete="off"><em>只做标记，写进 oauth_identities.provider</em></label>
          <label class="fld"><span>Scope</span><input id="f-oauth-scope" placeholder="openid profile email" autocomplete="off"></label>
        </div>
        <label class="fld"><span>Client ID</span><input id="f-oauth-client" autocomplete="off"></label>
        <label class="fld"><span>Client Secret</span><input id="f-oauth-secret" type="password" autocomplete="new-password"></label>
        <div class="fld endpoints-mode">
          <span>三个端点怎么来</span>
          <div class="opt-row">
            <label class="opt">
              <input type="radio" name="f-oauth-mode" id="f-oauth-mode-manual" value="manual" checked>
              <span><strong>手动填写端点</strong><em>自己从提供方文档里把三个地址抄过来，最直接。</em></span>
            </label>
            <label class="opt">
              <input type="radio" name="f-oauth-mode" id="f-oauth-mode-auto" value="discover">
              <span><strong>从 Basic URL 自动发现</strong><em>只填提供方站点根地址，去 /.well-known/openid-configuration 把三个端点找出来。</em></span>
            </label>
          </div>
        </div>
        <div id="oauth-manual">
          <label class="fld"><span>授权地址</span><input id="f-oauth-authorize" placeholder="https://example.com/oauth/authorize" autocomplete="off"><em>浏览器跳过去登录的地址，提供方文档里叫 authorization_endpoint</em></label>
          <label class="fld"><span>换取 token 地址</span><input id="f-oauth-token" placeholder="https://example.com/oauth/token" autocomplete="off"><em>服务器拿授权码换 token 的地址，文档里叫 token_endpoint</em></label>
          <label class="fld"><span>用户信息地址</span><input id="f-oauth-userinfo" placeholder="https://example.com/oauth/userinfo" autocomplete="off"><em>拿用户唯一标识（sub）和昵称的地址，文档里叫 userinfo_endpoint；留空就从 id_token 里取</em></label>
        </div>
        <div id="oauth-discover" hidden>
          <label class="fld"><span>Basic URL（提供方站点根地址）</span><input id="f-oauth-issuer" placeholder="https://accounts.google.com" autocomplete="off"><em>只填根地址，不要带 /.well-known/…，也不要带 ? 查询参数</em></label>
          <div class="discover-row">
            <button type="button" class="btn btn-ghost" id="oauth-discover-btn"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> 拉取端点</button>
            <span class="hint">拉取成功后三个端点会自动填好，想改就切回「手动填写端点」。</span>
          </div>
          <div class="discover-state" id="oauth-discover-state" hidden></div>
        </div>
      </div>
      <label class="switch-row">
        <input type="checkbox" id="f-bansync">
        <span><strong>封禁同步</strong><em>同一个 OAuth 账号下的所有小群账号一起封。门禁关闭时不生效。</em></span>
      </label>
    </fieldset>
  </section>

  <section class="panel step" data-step="7" hidden>
    <div class="panel-head">
      <h2>管理员账号</h2>
      <p class="lead">超级管理员在站点里显示带标识牌，并且是所有群的成员。昵称会保存下来，别人注册同名会被拒绝。</p>
    </div>
    <label class="fld"><span>管理员昵称</span><input id="f-admin" maxlength="24" placeholder="管理员" autocomplete="off"></label>
    <div class="grid2">
      <label class="fld"><span>密码</span><input id="f-pass" type="password" placeholder="至少 6 位" autocomplete="new-password"></label>
      <label class="fld"><span>确认密码</span><input id="f-pass2" type="password" placeholder="再输一次" autocomplete="new-password"></label>
    </div>
    <div class="oauth-box" id="admin-oauth" hidden>
      <p class="oauth-head"><i class="fa-solid fa-id-badge" aria-hidden="true"></i> 管理员的 OAuth 身份</p>
      <p class="lead">选了第三方门禁的话，管理员的身份要绑在那个 OAuth 账号上。这里填提供方那边唯一标识这个人的 subject，装完就绑在一起。空着不会写 .lock。</p>
      <label class="fld"><span>OAuth subject</span><input id="f-admin-sub" placeholder="例如 1024 或 user@example.com" autocomplete="off"><em>必填，绑不上就不让装完</em></label>
      <label class="fld"><span>邮箱（可选）</span><input id="f-admin-email" placeholder="只用来对账，不参与登录" autocomplete="off"></label>
    </div>
  </section>

  <section class="panel step" data-step="done" hidden>
    <div class="panel-head">
      <h2>装好了</h2>
      <p class="lead">群已经建好。下面三样只显示这一次，点一下就能复制，请自己存好。</p>
    </div>
    <div class="done-cards">
      <div class="done-card">
        <span>群地址</span>
        <code id="done-base" data-copy></code>
        <button type="button" class="btn btn-ghost btn-sm" data-copy-btn="done-base"><i class="fa-regular fa-copy" aria-hidden="true"></i> 复制</button>
      </div>
      <div class="done-card">
        <span>邀请码</span>
        <code id="done-invite" data-copy></code>
        <button type="button" class="btn btn-ghost btn-sm" data-copy-btn="done-invite"><i class="fa-regular fa-copy" aria-hidden="true"></i> 复制</button>
      </div>
      <div class="done-card">
        <span>管理员 token</span>
        <code id="done-token" data-copy></code>
        <button type="button" class="btn btn-ghost btn-sm" data-copy-btn="done-token"><i class="fa-regular fa-copy" aria-hidden="true"></i> 复制</button>
      </div>
    </div>
    <p class="lead">把群地址填进 Operit 插件的 base 参数，再填邀请码就能进群；下面这串是管理员 token，给对外的工具用。</p>
    <div class="panel-acts"><a id="done-app" class="btn btn-primary btn-lg" href="<?= e(\LightGroup\Web\Url::to("/app")) ?>"><i class="fa-solid fa-comments" aria-hidden="true"></i> 进入群聊</a><a id="done-admin" class="btn btn-ghost btn-lg" href="<?= e(\LightGroup\Web\Url::to("/admin")) ?>">管理后台</a></div>
  </section>

  <p class="tip bad setup-err" id="err" hidden></p>

  <div class="setup-acts" id="acts">
    <button type="button" class="btn btn-ghost" id="btn-prev" hidden><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> 上一步</button>
    <button type="button" class="btn btn-primary" id="btn-next"<?= $ready ? '' : ' disabled' ?>>下一步 <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
  </div>

  <?= \LightGroup\Web\Footer::render() ?>
  <?= \LightGroup\Web\Footer::aboutHtml(\LightGroup\Web\Site::info()) ?>
</div>

<div class="crop-modal" id="crop-modal" hidden>
  <div class="crop-box">
    <h3>裁切图标</h3>
    <p class="lead">拖动图片挪位置，用滑块缩放。虚线框里的部分就是最终图标。</p>
    <div class="crop-stage"><canvas id="crop-canvas" width="288" height="288"></canvas></div>
    <label class="crop-zoom"><i class="fa-solid fa-magnifying-glass-minus" aria-hidden="true"></i><input type="range" id="crop-zoom" min="1" max="4" step="0.01" value="1"><i class="fa-solid fa-magnifying-glass-plus" aria-hidden="true"></i></label>
    <div class="crop-acts"><button type="button" class="btn btn-ghost" id="crop-cancel">取消</button><button type="button" class="btn btn-primary" id="crop-ok">使用这张</button></div>
  </div>
</div>
<input type="file" id="file-input" accept="image/png,image/jpeg,image/webp" hidden>
<script src="/assets/common.js?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/common.js") ?>"></script>
<script src="/assets/setup.js?v=<?= (int) @filemtime(PUBLIC_ROOT . "/assets/setup.js") ?>"></script>
</body>
</html>
