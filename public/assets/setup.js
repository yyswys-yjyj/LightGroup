(function () {
  'use strict';

  var state = { light: '', dark: '' };

  var cur = 1;
  var LAST = 7;

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function val(sel) { var el = $(sel); return el ? String(el.value || '') : ''; }
  function num(sel) { var n = parseInt(val(sel), 10); return isFinite(n) && n > 0 ? n : 0; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var panels = $$('.step');
  var btnPrev = $('#btn-prev');
  var btnNext = $('#btn-next');
  var acts = $('#acts');
  var errBox = $('#err');

  function go(n) {
    var key = String(n);
    cur = key === 'done' ? 'done' : Number(key);
    panels.forEach(function (p) { p.hidden = String(p.dataset.step) !== key; });
    $$('#steps li').forEach(function (li) {
      var d = Number(li.dataset.dot);
      li.classList.toggle('on', d === cur);
      li.classList.toggle('done', cur === 'done' || d < cur);
    });
    if (key === 'done') {
      acts.hidden = true;
    } else {
      acts.hidden = false;
      btnPrev.hidden = cur <= 1;
      btnNext.innerHTML = cur >= LAST ? '开始安装 <i class="fa-solid fa-check"></i>' : '下一步 <i class="fa-solid fa-arrow-right"></i>';
      btnNext.disabled = false;
    }
    if (errBox) errBox.hidden = true;
    try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e) { window.scrollTo(0, 0); }
  }

  function errText(msg) {
    if (!errBox) return;
    errBox.hidden = false;
    errBox.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><span>' + esc(msg) + '</span>';
  }
  function errClear() { if (errBox) errBox.hidden = true; }

  function flash(el) {
    var node = $(el);
    if (!node) return;
    node.focus();
    node.classList.add('bad');
    setTimeout(function () { node.classList.remove('bad'); }, 1400);
  }

  var INVITE = /^[A-Za-z0-9_-]{4,32}$/;

  // ---------- 数据库 ----------
  function driver() {
    var r = $('input[name="db_driver"]:checked');
    return r ? r.value : 'sqlite';
  }

  function dbPayload() {
    return {
      action: 'test-db',
      db_driver: driver(),
      db_path: val('#f-db-path').trim(),
      db_host: val('#f-db-host').trim(),
      db_port: num('#f-db-port') || 3306,
      db_name: val('#f-db-name').trim(),
      db_user: val('#f-db-user').trim(),
      db_password: val('#f-db-pass')
    };
  }

  var dbState = { sig: '', ok: false };
  function dbSig() { return JSON.stringify(dbPayload()); }

  function setDbIdle() {
    dbState.ok = false;
    dbState.sig = '';
    var res = $('#db-result');
    var st = $('#db-state');
    if (res) { res.hidden = true; res.className = 'db-result'; res.innerHTML = ''; }
    if (st) { st.dataset.state = 'idle'; st.innerHTML = '<i class="fa-solid fa-circle-dot"></i> 还没测'; }
    var btn = $('#db-test-btn');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-plug-circle-check"></i> 测试连接'; }
  }

  function paintDb() {
    var mysql = driver() === 'mysql';
    var a = $('#db-sqlite');
    var b = $('#db-mysql');
    if (a) a.hidden = mysql;
    if (b) b.hidden = !mysql;
    setDbIdle();
  }

  function runDbTest(done) {
    var btn = $('#db-test-btn');
    var res = $('#db-result');
    var st = $('#db-state');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 测试中…'; }
    if (st) { st.dataset.state = 'idle'; st.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> 正在连…'; }
    if (res) { res.hidden = true; }
    errClear();
    fetch('/setup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(dbPayload())
    }).then(function (r) {
      return r.json();
    }).then(function (j) {
      var ok = !!(j && j.ok);
      var msg = (j && j.msg) || (ok ? '连接成功' : '连接失败');
      dbState.ok = ok;
      dbState.sig = dbSig();
      if (res) {
        res.hidden = false;
        res.className = 'db-result ' + (ok ? 'ok' : 'bad');
        res.innerHTML = '<i class="fa-solid ' + (ok ? 'fa-circle-check' : 'fa-triangle-exclamation') + '"></i><span>' + esc(msg) + '</span>';
      }
      if (st) {
        st.dataset.state = ok ? 'ok' : 'bad';
        st.innerHTML = '<i class="fa-solid ' + (ok ? 'fa-circle-check' : 'fa-circle-xmark') + '"></i> ' + (ok ? '已连通' : '连不上');
      }
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-plug-circle-check"></i> 重新测试'; }
      if (done) done(ok);
    }).catch(function (e) {
      dbState.ok = false;
      dbState.sig = dbSig();
      if (res) {
        res.hidden = false;
        res.className = 'db-result bad';
        res.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><span>' + esc(e && e.message ? e.message : '请求失败') + '</span>';
      }
      if (st) { st.dataset.state = 'bad'; st.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> 连不上'; }
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-plug-circle-check"></i> 重新测试'; }
      if (done) done(false);
    });
  }

  var dbTestBtn = $('#db-test-btn');
  if (dbTestBtn) dbTestBtn.addEventListener('click', function () { runDbTest(null); });

  $$('input[name="db_driver"]').forEach(function (r) {
    r.addEventListener('change', function () {
      $$('input[name="db_driver"]').forEach(function (x) {
        var card = x.closest('.radio-card');
        if (card) card.classList.toggle('on', x.checked);
      });
      paintDb();
    });
  });

  ['#f-db-path', '#f-db-host', '#f-db-port', '#f-db-name', '#f-db-user', '#f-db-pass'].forEach(function (sel) {
    var el = $(sel);
    if (el) el.addEventListener('input', setDbIdle);
  });
  paintDb();

  // ---------- 登录验证方式 ----------
  function authMode() {
    var r = document.querySelector('input[name="f-auth-mode"]:checked');
    return r ? r.value : 'captcha';
  }
  function oauthOn() { return authMode() === 'oauth'; }

  // 人机验证：内建滑块或第三方（Turnstile / hCaptcha / reCAPTCHA）
  function capProvider() {
    return val('#f-cap-provider') || 'builtin';
  }
  // 端点两种填法：自己抄（manual）或从 Basic URL 拉 well-known（discover）
  function oauthMode() {
    var r = document.querySelector('input[name="f-oauth-mode"]:checked');
    return r ? r.value : 'manual';
  }

  function paintOauth() {
    var on = oauthOn();
    var box = $('#oauth-box');
    var adm = $('#admin-oauth');
    if (box) box.hidden = !on;
    if (adm) adm.hidden = !on;
    var auto = oauthMode() === 'discover';
    var manualBox = $('#oauth-manual');
    var discBox = $('#oauth-discover');
    if (manualBox) manualBox.hidden = auto;
    if (discBox) discBox.hidden = !auto;
    // 只有「人机验证」这一档才显示验证码配置；门禁或都不要时藏起来
    var capBox = $('#captcha-box');
    if (capBox) capBox.hidden = authMode() !== 'captcha';
    var third = $('#cap3-box');
    if (third) third.hidden = capProvider() === 'builtin';
    // Geetest 那边叫 Captcha ID / Captcha Key，标签跟着换
    var gt = capProvider() === 'geetest4';
    var lk = $('#f-cap-sitekey-label');
    var ls = $('#f-cap-secret-label');
    var lt = $('#f-cap-secret-tip');
    if (lk) lk.textContent = gt ? 'Captcha ID' : 'Site Key';
    if (ls) ls.textContent = gt ? 'Captcha Key' : 'Secret Key';
    if (lt) lt.textContent = gt ? 'Geetest 后台的验证密钥；只留在服务端，留空会退回内建滑块'
      : '只留在服务端；留空不算配好，会退回内建滑块';
  }
  var authRadios = $$('input[name="f-auth-mode"]');
  authRadios.forEach(function (r) { r.addEventListener('change', paintOauth); });
  var capSel = $('#f-cap-provider');
  if (capSel) capSel.addEventListener('change', paintOauth);
  $$('input[name="f-oauth-mode"]').forEach(function (r) { r.addEventListener('change', paintOauth); });

  // 装之前也能拉 well-known：只读外网文档，不碰数据库
  var discBtn = $('#oauth-discover-btn');
  function discState(kind, html) {
    var box = $('#oauth-discover-state');
    if (!box) return;
    box.hidden = false;
    box.className = 'discover-state ' + kind;
    box.innerHTML = html;
  }
  function setVal(sel, v) { var el = $(sel); if (el && v) el.value = v; }

  if (discBtn) discBtn.addEventListener('click', function () {
    var iss = val('#f-oauth-issuer').trim();
    if (iss === '') { discState('bad', '<i class="fa-solid fa-triangle-exclamation"></i> 先填提供方的 Basic URL，例如 https://accounts.google.com'); errText('填一下提供方的 Basic URL，例如 https://accounts.google.com'); flash('#f-oauth-issuer'); return; }
    if (!/^https?:\/\//i.test(iss)) { errText('Basic URL 要以 http:// 或 https:// 开头'); flash('#f-oauth-issuer'); return; }
    errClear();
    discBtn.disabled = true;
    discBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 拉取中…';
    discState('busy', '<i class="fa-solid fa-circle-notch fa-spin"></i> 正在请求 ' + esc(iss.replace(/\/+$/, '')) + '/.well-known/openid-configuration …');
    fetch('/setup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'oauth-discover', issuer: iss })
    }).then(function (r) {
      return r.json();
    }).then(function (j) {
      discBtn.disabled = false;
      discBtn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> 重新拉取';
      if (!j || !j.ok) {
        discState('bad', '<i class="fa-solid fa-triangle-exclamation"></i> ' + esc((j && j.err) || '拉取失败'));
        return;
      }
      var d = j.data || {};
      setVal('#f-oauth-authorize', d.authorization_endpoint);
      setVal('#f-oauth-token', d.token_endpoint);
      setVal('#f-oauth-userinfo', d.userinfo_endpoint);
      var pv = $('#f-oauth-provider');
      if (pv && !pv.value.trim() && d.provider) pv.value = d.provider;
      var sv = $('#f-oauth-scope');
      if (sv && !sv.value.trim() && d.scope) sv.value = d.scope;
      var out = ['<i class="fa-solid fa-circle-check"></i> 已找到端点' + (j.well_known ? '（来自 ' + esc(j.well_known) + '）' : '')];
      out.push('<div class="mono">授权地址：' + esc(d.authorization_endpoint || '') + '</div>');
      out.push('<div class="mono">换取 token：' + esc(d.token_endpoint || '') + '</div>');
      out.push('<div class="mono">用户信息：' + esc(d.userinfo_endpoint || '（文档里没有，留空就从 id_token 取）') + '</div>');
      (j.warnings || []).forEach(function (w) { out.push('<div class="warn">注意：' + esc(w) + '</div>'); });
      discState('ok', out.join(''));
    }).catch(function (e) {
      discBtn.disabled = false;
      discBtn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> 重新拉取';
      discState('bad', '<i class="fa-solid fa-triangle-exclamation"></i> ' + esc(e && e.message ? e.message : '请求失败'));
    });
  });
  paintOauth();

  // ---------- 群关系卡 ----------
  $$('.radio-card').forEach(function (card) {
    var input = card.querySelector('input');
    if (!input || input.disabled || input.name === 'db_driver') return;
    card.addEventListener('click', function () {
      $$('.radio-card').forEach(function (c) { c.classList.remove('on'); });
      card.classList.add('on');
      input.checked = true;
    });
  });

  // ---------- 校验 ----------
  function validate(step) {
    if (step === 2) {
      if (val('#f-site').trim() === '') { errText('填一个站点名称'); flash('#f-site'); return false; }
      if (val('#f-site').trim().length > 40) { errText('站点名称最多 40 个字'); flash('#f-site'); return false; }
      errClear();
      return true;
    }
    if (step === 3) {
      if (driver() === 'mysql') {
        if (val('#f-db-host').trim() === '') { errText('MySQL 要填主机'); flash('#f-db-host'); return false; }
        if (val('#f-db-name').trim() === '') { errText('MySQL 要填库名'); flash('#f-db-name'); return false; }
        if (val('#f-db-user').trim() === '') { errText('MySQL 要填用户名'); flash('#f-db-user'); return false; }
      }
      errClear();
      return true;
    }
    if (step === 5) {
      if (val('#f-group').trim().length > 40) { errText('群名最多 40 个字'); flash('#f-group'); return false; }
      var inv = val('#f-invite').trim();
      if (inv !== '' && !INVITE.test(inv)) { errText('邀请码只能使用 4-32 位字母、数字、下划线或短横线'); flash('#f-invite'); return false; }
      errClear();
      return true;
    }
    if (step === 6) {
      if (authMode() === 'captcha' && capProvider() !== 'builtin') {
        if (val('#f-cap-sitekey').trim() === '') { errText('第三方人机验证要填 Site Key'); flash('#f-cap-sitekey'); return false; }
        if (val('#f-cap-secret').trim() === '') { errText('第三方人机验证要填 Secret Key，不然开不起来'); flash('#f-cap-secret'); return false; }
        var vu = val('#f-cap-verify').trim();
        if (vu !== '' && !/^https?:\/\//i.test(vu)) { errText('校验地址要以 http:// 或 https:// 开头'); flash('#f-cap-verify'); return false; }
      }
      if (oauthOn()) {
        if (oauthMode() === 'discover') {
          var iss = val('#f-oauth-issuer').trim();
          if (iss === '') { errText('填一下提供方的 Basic URL，例如 https://accounts.google.com'); flash('#f-oauth-issuer'); return false; }
          if (!/^https?:\/\//i.test(iss)) { errText('Basic URL 要以 http:// 或 https:// 开头'); flash('#f-oauth-issuer'); return false; }
          if (val('#f-oauth-authorize').trim() === '' || val('#f-oauth-token').trim() === '') {
            errText('还没拉到端点，点一下「拉取端点」'); flash('#oauth-discover-btn'); return false;
          }
        }
        var need = [
          ['#f-oauth-client', '开启门禁要填 Client ID'],
          ['#f-oauth-secret', '开启门禁要填 Client Secret'],
          ['#f-oauth-authorize', '开启门禁要填授权地址'],
          ['#f-oauth-token', '开启门禁要填换取 token 地址']
        ];
        for (var i = 0; i < need.length; i++) {
          if (val(need[i][0]).trim() === '') { errText(need[i][1]); flash(need[i][0]); return false; }
        }
        var urls = ['#f-oauth-authorize', '#f-oauth-token'];
        for (var k = 0; k < urls.length; k++) {
          if (!/^https?:\/\//i.test(val(urls[k]).trim())) { errText('OAuth2 的地址要以 http:// 或 https:// 开头'); flash(urls[k]); return false; }
        }
        // 用户信息地址可以不填：没有 userinfo 的提供方从 id_token 取身份
        var ui = val('#f-oauth-userinfo').trim();
        if (ui !== '' && !/^https?:\/\//i.test(ui)) { errText('用户信息地址要以 http:// 或 https:// 开头'); flash('#f-oauth-userinfo'); return false; }
      }
      errClear();
      return true;
    }
    if (step === LAST) {
      if (val('#f-admin').trim() === '') { errText('填一个管理员昵称'); flash('#f-admin'); return false; }
      if (val('#f-pass').length < 6) { errText('密码至少 6 位'); flash('#f-pass'); return false; }
      if (val('#f-pass') !== val('#f-pass2')) { errText('两次输入的密码不一样'); flash('#f-pass2'); return false; }
      if (oauthOn() && val('#f-admin-sub').trim() === '') {
        errText('门禁开着，管理员得绑一个 OAuth subject'); flash('#f-admin-sub'); return false;
      }
      errClear();
      return true;
    }
    return true;
  }

  btnNext.addEventListener('click', function () {
    if (!validate(cur)) return;
    if (cur === 3 && (dbState.sig !== dbSig() || !dbState.ok)) {
      errText('先测试一下数据库连接');
      runDbTest(function (ok) { if (ok) go(4); });
      return;
    }
    if (cur === LAST) { submit(); return; }
    go(cur + 1);
  });

  btnPrev.addEventListener('click', function () {
    if (cur > 1) go(cur - 1);
  });
  // ---------- 图标 ----------
  function paintIcon(kind) {
    var card = $('[data-icon="' + kind + '"]');
    if (!card) return;
    var prev = card.querySelector('[data-prev]');
    var clearBtn = card.querySelector('[data-clear]');
    var url = state[kind];
    if (url) {
      prev.innerHTML = '<span class="icon-mask"><img src="' + url + '" alt=""></span>';
      if (clearBtn) clearBtn.hidden = false;
    } else {
      prev.innerHTML = '<span class="icon-mask empty"><i class="fa-regular fa-image" aria-hidden="true"></i></span>';
      if (clearBtn) clearBtn.hidden = true;
    }
  }

  var fileInput = $('#file-input');
  var pickKind = 'light';

  $$('[data-pick]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var card = btn.closest('[data-icon]');
      pickKind = card ? card.dataset.icon : 'light';
      fileInput.value = '';
      fileInput.click();
    });
  });

  $$('[data-clear]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var card = btn.closest('[data-icon]');
      var kind = card ? card.dataset.icon : 'light';
      state[kind] = '';
      paintIcon(kind);
    });
  });

  fileInput.addEventListener('change', function () {
    var f = fileInput.files && fileInput.files[0];
    if (!f) return;
    if (!/^image\/(png|jpeg|webp)$/.test(f.type)) { alert('请选择 PNG、JPG 或 WebP 图片'); return; }
    var reader = new FileReader();
    reader.onload = function () {
      var img = new Image();
      img.onload = function () { openCrop(img, pickKind); };
      img.onerror = function () { alert('这张图片打不开'); };
      img.src = String(reader.result);
    };
    reader.readAsDataURL(f);
  });

  // ---------- 裁切 ----------
  var cropModal = $('#crop-modal');
  var cropCanvas = $('#crop-canvas');
  var cropZoom = $('#crop-zoom');
  var crop = null;
  var drag = null;

  function clamp() {
    var S = crop.S;
    var w = crop.img.width * crop.scale;
    var h = crop.img.height * crop.scale;
    crop.x = Math.min(0, Math.max(S - w, crop.x));
    crop.y = Math.min(0, Math.max(S - h, crop.y));
  }

  function drawCrop() {
    clamp();
    var g = cropCanvas.getContext('2d');
    var S = crop.S;
    g.clearRect(0, 0, S, S);
    g.drawImage(crop.img, crop.x, crop.y, crop.img.width * crop.scale, crop.img.height * crop.scale);
  }

  function openCrop(img, kind) {
    var S = 288;
    var cover = S / Math.min(img.width, img.height);
    crop = { img: img, kind: kind, S: S, scale: cover, x: 0, y: 0 };
    crop.x = (S - img.width * cover) / 2;
    crop.y = (S - img.height * cover) / 2;
    cropZoom.min = String(cover);
    cropZoom.max = String(cover * 3);
    cropZoom.step = String(cover / 100);
    cropZoom.value = String(cover);
    cropModal.hidden = false;
    drawCrop();
  }

  function closeCrop() {
    cropModal.hidden = true;
    crop = null;
    drag = null;
  }

  function toCanvas(e) {
    var r = cropCanvas.getBoundingClientRect();
    var k = cropCanvas.width / (r.width || cropCanvas.width);
    return { x: (e.clientX - r.left) * k, y: (e.clientY - r.top) * k };
  }

  cropCanvas.addEventListener('pointerdown', function (e) {
    if (!crop) return;
    e.preventDefault();
    if (cropCanvas.setPointerCapture) cropCanvas.setPointerCapture(e.pointerId);
    var p = toCanvas(e);
    drag = { dx: p.x - crop.x, dy: p.y - crop.y };
  });

  cropCanvas.addEventListener('pointermove', function (e) {
    if (!crop || !drag) return;
    var p = toCanvas(e);
    crop.x = p.x - drag.dx;
    crop.y = p.y - drag.dy;
    drawCrop();
  });

  function endDrag() { drag = null; }
  cropCanvas.addEventListener('pointerup', endDrag);
  cropCanvas.addEventListener('pointercancel', endDrag);
  cropCanvas.addEventListener('pointerleave', function (e) { if (e.buttons === 0) endDrag(); });

  cropZoom.addEventListener('input', function () {
    if (!crop) return;
    var S = crop.S;
    var next = Number(cropZoom.value);
    if (!(next > 0)) return;
    var c = S / 2;
    var px = (c - crop.x) / crop.scale;
    var py = (c - crop.y) / crop.scale;
    crop.scale = next;
    crop.x = c - px * next;
    crop.y = c - py * next;
    drawCrop();
  });

  $('#crop-cancel').addEventListener('click', closeCrop);

  $('#crop-ok').addEventListener('click', function () {
    if (!crop) return;
    var out = document.createElement('canvas');
    out.width = 512;
    out.height = 512;
    var g = out.getContext('2d');
    var k = 512 / crop.S;
    g.imageSmoothingEnabled = true;
    g.imageSmoothingQuality = 'high';
    g.drawImage(crop.img, crop.x * k, crop.y * k, crop.img.width * crop.scale * k, crop.img.height * crop.scale * k);
    state[crop.kind] = out.toDataURL('image/png');
    paintIcon(crop.kind);
    closeCrop();
  });

  // ---------- 提交 ----------
  function payload() {
    return {
      site: val('#f-site').trim(),
      intro: val('#f-intro').trim(),
      db_driver: driver(),
      db_path: val('#f-db-path').trim(),
      db_host: val('#f-db-host').trim(),
      db_port: num('#f-db-port') || 3306,
      db_name: val('#f-db-name').trim(),
      db_user: val('#f-db-user').trim(),
      db_password: val('#f-db-pass'),
      group_title: val('#f-group').trim(),
      invite: val('#f-invite').trim(),
      human_cap: num('#f-human-cap'),
      ai_cap: num('#f-ai-cap'),
      quota_human_total: num('#f-q-human'),
      quota_ai_total: num('#f-q-ai'),
      quota_ai_per_human: num('#f-q-per-human'),
      quota_account_per_oauth: num('#f-q-per-oauth'),
      avatar_default: val('#f-avatar').trim(),
      auth_mode: authMode(),
      auth_captcha_provider: capProvider(),
      auth_captcha_site_key: val('#f-cap-sitekey').trim(),
      auth_captcha_secret: val('#f-cap-secret'),
      auth_captcha_verify_url: val('#f-cap-verify').trim(),
      oauth_enabled: oauthOn(),
      oauth_ban_sync: !!($('#f-bansync') && $('#f-bansync').checked),
      oauth_provider: val('#f-oauth-provider').trim(),
      oauth_client_id: val('#f-oauth-client').trim(),
      oauth_client_secret: val('#f-oauth-secret'),
      oauth_authorize_url: val('#f-oauth-authorize').trim(),
      oauth_token_url: val('#f-oauth-token').trim(),
      oauth_userinfo_url: val('#f-oauth-userinfo').trim(),
      oauth_scope: val('#f-oauth-scope').trim(),
      oauth_issuer: val('#f-oauth-issuer').trim(),
      admin_oauth_sub: val('#f-admin-sub').trim(),
      admin_oauth_email: val('#f-admin-email').trim(),
      admin: val('#f-admin').trim(),
      password: val('#f-pass'),
      icon_light: state.light,
      icon_dark: state.dark
    };
  }

  function submit() {
    if (!validate(LAST)) return;
    errClear();
    btnNext.disabled = true;
    btnNext.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 安装中…';
    fetch('/setup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload())
    }).then(function (r) {
      return r.json();
    }).then(function (j) {
      if (!j || !j.ok) throw new Error((j && j.err) || '安装失败');
      $('#done-base').textContent = location.origin + '/g/';
      $('#done-invite').textContent = j.invite || '';
      $('#done-token').textContent = j.token || '';
      go('done');
    }).catch(function (e) {
      errText(e && e.message ? e.message : '安装失败');
      btnNext.disabled = false;
      btnNext.innerHTML = '开始安装 <i class="fa-solid fa-check"></i>';
    });
  }

  // ---------- 复制 ----------
  $$('[data-copy-btn]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = document.getElementById(btn.dataset.copyBtn);
      if (!target) return;
      var text = String(target.textContent || '');
      var done = function () {
        var old = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> 已复制';
        setTimeout(function () { btn.innerHTML = old; }, 1400);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
      } else {
        fallbackCopy(text);
        done();
      }
    });
  });

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }

  // ---------- 启动 ----------
  var firstVisible = panels.filter(function (p) { return !p.hidden; })[0];
  go(firstVisible ? firstVisible.dataset.step : 1);
})();
