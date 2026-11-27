// LightGroup · 空间：群里的小广场（动态）
import { S, h, icon, iconBtn, btn, toast, api, go, openSheet, closeSheet, siteName, hhmm } from './core.js';
import { avatar, uidOf } from './avatar.js';

// 站点功能开关：空间总开关与发/赞/评三项，都在 S.me.features 里
function feat(group, key) {
  const box = ((S.me && S.me.features) || {})[group];
  if (box === false) return false;
  if (!box || typeof box !== 'object') return true;
  return box[key] !== false;
}
import { openImage } from './msg.js';
import { shrinkImage } from './chat.js';
import { canScan, followScan, decodeSource, decodeFile } from './qr.js';

let tab = 'feed';          // feed | mine（扫描器是独立页 #/scan）
let tag = '';              // 只看这个标签
let mine = false;          // 只看我发的
let tags = [];             // 最近出现过的标签，侧栏列出来
let draft = [];            // 发布框里已上传的图片
let feedRec = false;       // 广场是否开了推荐算法（决定翻页用 off 还是 before）
let feedOff = 0;           // 下一页的游标：推荐算法用 offset，时间序用最后一条 id
let openComment = 0;       // 当前展开评论框的动态 id

let hostEl = null;
let sideEl = null;

export function renderSpace(host) {
  hostEl = host;
  host.textContent = '';
  if (!feat('space', 'enabled')) {
    const empty = h('div', 'page-body sp-page');
    empty.appendChild(h('div', 'sp-empty', '本站没有开放空间。'));
    host.appendChild(empty);
    return;
  }
  const body = h('div', 'page-body sp-page');
  host.appendChild(body);
  const inner = h('div', 'sp-inner');
  body.appendChild(inner);

  inner.appendChild(cover());

  if (tag) {
    const bar = h('div', 'sp-filter');
    bar.appendChild(h('span', '', '只看 #' + tag));
    const clear = btn('取消', 'btn-ghost btn-xs', function () { tag = ''; repaint(); });
    bar.appendChild(clear);
    inner.appendChild(bar);
  }

  const box = h('div', 'sp-body');
  inner.appendChild(box);
  paintFeed(box);
}

/** 中间那一栏：空间自己的导航（广场 / 我的动态 / 通知 + 标签） */
export function renderSideList(host) {
  if (!host) return;
  sideEl = host;
  host.textContent = '';
  host.dataset.pane = 'space';
  const side = h('div', 'sp-side');
  const head = h('div', 'sp-side-head');
  head.appendChild(h('b', '', '空间'));
  head.appendChild(h('span', '', (siteName() || '这个群') + ' · 小广场'));
  side.appendChild(head);

  const nav = h('div', 'sp-navs');
  const items = [
    ['feed', 'fa-compass', '广场', 0],
    ['mine', 'fa-user-pen', '我的动态', 0],
  ];
  items.forEach(function (it) {
    const on = (it[0] === 'feed' && tab === 'feed' && !mine) || it[0] === tab;
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'sp-nav' + (on && (it[0] !== 'feed' || !mine) ? ' on' : '');
    b.dataset.nav = it[0];
    b.appendChild(icon(it[1]));
    b.appendChild(h('span', '', it[2]));
    if (it[3] > 0) b.appendChild(h('span', 'req-badge', it[3] > 99 ? '99+' : String(it[3])));
    b.addEventListener('click', function () {
      tab = 'feed';
      mine = it[0] === 'mine';
      tag = '';
      repaint();
    });
    nav.appendChild(b);
  });
  side.appendChild(nav);

  if (tags.length) {
    side.appendChild(h('div', 'sp-side-label', '标签'));
    const tl = h('div', 'sp-navs sp-navs-tags');
    tags.slice(0, 12).forEach(function (t) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'sp-nav sp-nav-tag' + (tag === t ? ' on' : '');
      b.appendChild(icon('fa-hashtag'));
      b.appendChild(h('span', '', t));
      b.addEventListener('click', function () {
        tab = 'feed';
        mine = false;
        tag = tag === t ? '' : t;
        repaint();
      });
      tl.appendChild(b);
    });
    side.appendChild(tl);
  }
  host.appendChild(side);
}

/** 只重画业务区，别碰别的东西 */
function repaint() {
  if (hostEl) renderSpace(hostEl);
  // 中间的导航是同一份状态，选中态和红点要跟着一起变
  if (sideEl && sideEl.isConnected) renderSideList(sideEl);
}

/** 顶上的封面 + 头像 + 发动态入口，朋友圈那一路 */
function cover() {
  const wrap = h('div', 'sp-cover-wrap');
  wrap.appendChild(h('div', 'sp-cover'));

  const row = h('div', 'sp-profile');
  row.appendChild(avatar(S.me || { name: '我' }, 64));

  const meta = h('div', 'sp-profile-meta');
  meta.appendChild(h('b', '', siteName() || '空间'));
  meta.appendChild(h('span', '', '群里的小广场，只有这个群的人看得到'));
  row.appendChild(meta);

  const acts = h('div', 'sp-profile-acts');
  if (feat('space', 'post')) {
    const write = btn('发动态', 'btn-primary btn-sm', openComposer);
    write.insertBefore(icon('fa-pen'), write.firstChild);
    acts.appendChild(write);
  }
  row.appendChild(acts);

  wrap.appendChild(row);
  wrap.appendChild(tabs());
  return wrap;
}

/** 写动态放弹层里，正文区不摆输入框 */
function openComposer() {
  const box = h('div', 'sp-sheet');
  composer(box);
  openSheet('发动态', box, { size: 'lg' });
}

function tabs() {
  const tabs = h('div', 'sp-tabs');
  // 桌面用左栏导航（扫描器也在那边），手机的页签是唯一导航，所以这里也得有一项
  const list = [['feed', '广场'], ['mine', '我的动态']];
  list.forEach(function (it) {
    const on = (it[0] === 'feed' && tab === 'feed' && !mine) || (it[0] === 'mine' && mine);
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'sp-tab' + (on ? ' on' : '');
    b.appendChild(h('span', '', it[1]));
    b.addEventListener('click', function () {
      tab = 'feed';
      mine = it[0] === 'mine';
      tag = '';
      repaint();
    });
    tabs.appendChild(b);
  });
  return tabs;
}

// ---------- 扫描器（独立页 #/scan） ----------

let scanStream = null;
let scanTimer = 0;
let scanDevice = '';

/** 关摄像头；离开扫描页、识别成功、切走页面时都要调 */
export function stopScan() {
  if (scanTimer) { clearInterval(scanTimer); scanTimer = 0; }
  if (scanStream) {
    scanStream.getTracks().forEach(function (t) {
      try { t.stop(); } catch (e) { void e; }
    });
    scanStream = null;
  }
}

function mobileUA() {
  return /Android|iPhone|iPad|iPod|Mobile|HarmonyOS|Windows Phone/i.test(navigator.userAgent || '');
}

async function listCams() {
  try {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return [];
    const all = await navigator.mediaDevices.enumerateDevices();
    return all.filter(function (d) { return d.kind === 'videoinput'; });
  } catch (e) { return []; }
}

// 手机优先后摄；桌面避开虚拟摄像头（OBS 这类），实在没有再用第一个
function pickCam(cams) {
  if (!cams.length) return '';
  const back = cams.filter(function (d) { return /back|rear|environment|后置/i.test(d.label || ''); })[0];
  if (mobileUA() && back) return back.deviceId || '';
  const real = cams.filter(function (d) { return !/virtual|obs/i.test(d.label || ''); })[0];
  if (real && real.deviceId) return real.deviceId;
  if (back && back.deviceId) return back.deviceId;
  return cams[0].deviceId || '';
}

// 内嵌浏览器（App 里的 WebView）默认拒绝网页申请摄像头：
// 安卓要在宿主里重写 WebChromeClient.onPermissionRequest 并 grant，iOS 要实现
// WKUIDelegate 的 requestMediaCapturePermissionFor，宿主没做就打不开，站点侧无解
function inAppWebview() {
  const ua = navigator.userAgent || '';
  return /;\s*wv\)/i.test(ua) || /\bWebView\b/i.test(ua) ||
    /MicroMessenger|QQ\/|Weibo|AlipayClient|DingTalk/i.test(ua);
}

function isIOS() {
  return /iPad|iPhone|iPod/.test(navigator.userAgent || '') ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

// 浏览器只在安全上下文开摄像头：https 或 localhost/127.0.0.1
function camSecure() {
  if (window.isSecureContext) return true;
  const hn = location.hostname;
  return hn === 'localhost' || hn === '127.0.0.1' || hn === '[::1]' || hn === '::1';
}

// 站点响应头里的 Permissions-Policy 可以把摄像头整个禁掉，
// 这时浏览器连权限弹窗都不给，直接在 getUserMedia 里抛 NotAllowedError
function policyBlocksCam() {
  try {
    const fp = document.featurePolicy || document.permissionsPolicy;
    if (fp && typeof fp.allowsFeature === 'function') return fp.allowsFeature('camera') === false;
  } catch (e) { void e; }
  return false;
}

function camErrText(e) {
  const n = (e && e.name) || '';
  if (policyBlocksCam()) return '这个站点没有开放摄像头，找站长处理。';
  if (!camSecure()) return '浏览器只在 https 或本机地址下允许用摄像头。';
  if (n === 'NotAllowedError' || n === 'SecurityError') {
    if (inAppWebview()) return '这个内置浏览器不放行摄像头，用系统浏览器打开这个页面再试。';
    if (isIOS()) return '摄像头没被允许：到 设置 → Safari 浏览器 → 相机 里改成「允许」，或直接在下面的按钮上再点一次。';
    return '摄像头被拦了：点地址栏左边的锁图标，把「摄像头」改成允许，刷新后再试。';
  }
  if (n === 'NotFoundError' || n === 'OverconstrainedError') return '这台设备上没有可用的摄像头。';
  if (n === 'NotReadableError' || n === 'AbortError') return '摄像头被别的程序占着（会议、录屏软件），关掉再试。';
  if (n === 'NotSupportedError') return '这个浏览器打不开摄像头。';
  return '打不开摄像头' + (n ? '（' + n + '）' : '') + '。';
}

// Chrome 在 Android 上报的设备名是 camera2 0 / camera2 1 这种逻辑相机编号，
// 不是焦距。这里只按前/后置分类，同一朝向有多颗时加序号。
function camFacing(dev) {
  const raw = String((dev && dev.label) || '');
  if (/back|rear|environment|后置/i.test(raw)) return 'back';
  if (/front|user|前置/i.test(raw)) return 'front';
  return '';
}

function camLabel(dev, cams) {
  const raw = String((dev && dev.label) || '').trim();
  const f = camFacing(dev);
  const pre = f === 'back' ? '后置' : f === 'front' ? '前置' : '';
  // Android 上的 label 形如 camera2 6, facing back：6 是手机内部的摄像头编号，
  // 不同厂商对应的镜头（主摄/长焦/超广角）不一样，Web 端也拿不到真实焦距，所以原样保留编号
  const m = /camera\s*2?\s+(\d+)/i.exec(raw);
  if (m) return (pre ? pre + ' · ' : '') + 'camera ' + m[1];
  if (raw) return pre ? pre + ' · ' + raw : raw;
  return '摄像头 ' + (cams.indexOf(dev) + 1);
}

function camConstraints(deviceId) {
  if (deviceId) return { video: { deviceId: { exact: deviceId } }, audio: false };
  if (mobileUA()) return { video: { facingMode: { ideal: 'environment' } }, audio: false };
  return { video: true, audio: false };
}

/** 独立页：带返回，不占空间的 tab */
export function renderScan(host) {
  stopScan();
  host.textContent = '';
  const body = h('div', 'page-body');
  host.appendChild(body);
  const inner = h('div', 'scan-page');
  body.appendChild(inner);
  const head = h('div', 'scan-page-head');
  head.appendChild(iconBtn('fa-chevron-left', '返回', function () { go('#/space'); }));
  head.appendChild(h('b', '', '扫描器'));
  inner.appendChild(head);
  paintScanner(inner);
}

function paintScanner(box) {
  stopScan();
  // 换设备只重建内容，不重复插入：以前每切一次就多一整套取景框
  const oldWrap = box.querySelector('.scan-wrap');
  if (oldWrap) oldWrap.remove();
  const wrap = h('div', 'scan-wrap');
  const stage = h('div', 'scan-stage');
  wrap.appendChild(stage);
  wrap.appendChild(h('div', 'page-tip', '对准二维码，认出后自动跳转。'));

  const fileInput = document.createElement('input');
  fileInput.type = 'file';
  fileInput.accept = 'image/*';
  fileInput.hidden = true;
  fileInput.addEventListener('change', async function () {
    const f = fileInput.files && fileInput.files[0];
    fileInput.value = '';
    if (!f) return;
    toast('正在识别…');
    const t = await decodeFile(f);
    if (!t) { toast('这张图里没找到二维码'); return; }
    stopScan();
    await followScan(t);
  });
  wrap.appendChild(fileInput);

  const acts = h('div', 'scan-acts');
  acts.appendChild(btn('从相册选图片', 'btn btn-sm btn-ghost', function () { fileInput.click(); }));
  wrap.appendChild(acts);

  const camSel = document.createElement('select');
  camSel.className = 'tb scan-cam';
  camSel.hidden = true;
  camSel.addEventListener('change', function () {
    scanDevice = camSel.value || '';
    restart();
  });
  wrap.appendChild(camSel);

  // 没摄像头或权限被拒时，手动粘内容还能用
  const manual = h('details', 'scan-manual');
  manual.appendChild(h('summary', '', '打不开摄像头？手动粘贴内容'));
  const ta = document.createElement('textarea');
  ta.className = 'tb';
  ta.rows = 2;
  ta.placeholder = 'lightgroup://user/8f3k2a9c1d 或网址';
  manual.appendChild(ta);
  manual.appendChild(btn('解析', 'btn btn-sm', async function () {
    const t = ta.value.trim();
    if (!t) { toast('先粘贴内容'); return; }
    stopScan();
    await followScan(t);
  }));
  wrap.appendChild(manual);

  box.appendChild(wrap);

  function cap(msg) {
    stage.textContent = '';
    stage.appendChild(h('div', 'scan-empty', msg));
  }

  // 权限被拒时不要只留一句话：在用户这次点击里重新申请，iOS/Safari 上只有手势里申请才会给
  function capRetry(msg) {
    stage.textContent = '';
    stage.appendChild(h('div', 'scan-empty', msg));
    stage.appendChild(btn('点一下开启摄像头', 'btn btn-sm', function () {
      stage.textContent = '';
      stage.appendChild(h('div', 'scan-empty', '正在打开摄像头…'));
      boot(scanDevice);
    }));
  }

  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    cap('这个浏览器打不开摄像头，需要在 https 下打开。');
    return;
  }

  const video = document.createElement('video');
  video.className = 'scan-video';
  video.setAttribute('playsinline', '');
  video.muted = true;
  video.autoplay = true;

  async function boot(deviceId) {
    let stream = null;
    try {
      stream = await navigator.mediaDevices.getUserMedia(camConstraints(deviceId));
    } catch (e) {
      // 记着的设备已经不在了（换了浏览器/拔了 USB 摄像头）：退回默认设备再试一次
      if (deviceId) { scanDevice = ''; await restart(); return; }
      if (e && (e.name === 'NotAllowedError' || e.name === 'SecurityError')) {
        capRetry(camErrText(e));
        return;
      }
      cap(camErrText(e));
      return;
    }
    if (!stage.isConnected) {
      stream.getTracks().forEach(function (t) { try { t.stop(); } catch (err) { void err; } });
      return;
    }
    scanStream = stream;

    const track = stream.getVideoTracks()[0];
    const cams = await listCams();
    // 默认设备可能是 OBS 这类虚拟摄像头：桌面端自动换到真实的那一个
    if (!deviceId && track) {
      const better = pickCam(cams);
      const cur = track.getSettings().deviceId || '';
      if (better && better !== cur && /virtual|obs/i.test(track.label || '')) {
        stream.getTracks().forEach(function (t) { try { t.stop(); } catch (err) { void err; } });
        return boot(better);
      }
    }
    if (cams.length) {
      camSel.textContent = '';
      cams.forEach(function (c) {
        const o = document.createElement('option');
        o.value = c.deviceId;
        o.textContent = camLabel(c, cams);
        o.title = c.label || '';
        camSel.appendChild(o);
      });
      const cur = (stream.getVideoTracks()[0] && stream.getVideoTracks()[0].getSettings().deviceId) || '';
      camSel.value = deviceId || cur || '';
      camSel.hidden = cams.length < 2;
    }

    stage.textContent = '';
    stage.appendChild(video);
    stage.appendChild(h('div', 'scan-frame'));
    stage.appendChild(h('div', 'scan-tip', '把二维码放进取景框里'));
    video.srcObject = stream;
    video.play().catch(function () {});
    let busy = false;
    scanTimer = setInterval(async function () {
      if (busy || !video.videoWidth) return;
      busy = true;
      try {
        const t = await decodeSource(video);
        if (t) {
          stopScan();
          video.remove();
          await followScan(t);
        }
      } finally {
        busy = false;
      }
    }, 450);
  }

  // 换设备：停掉旧流、清空取景框，稍等一下让硬件断开再开新的
  async function restart() {
    stopScan();
    stage.textContent = '';
    stage.appendChild(h('div', 'scan-empty', '正在打开摄像头…'));
    await new Promise(function (r) { setTimeout(r, 220); });
    await boot(scanDevice);
  }

  // 先按默认设备开一次，拿到设备名后再决定要不要切到真正的摄像头
  boot(scanDevice);
}

window.addEventListener('hashchange', function () { stopScan(); });

// 扫码后弹出的提示层关掉时（站外链接/不支持的协议/别的站点），回到取景框重新开摄像头
window.addEventListener('lg:scan-resume', function () {
  if (location.hash !== '#/scan') return;
  const box = document.querySelector('.scan-page');
  if (box && !document.querySelector('.scan-video')) paintScanner(box);
});

// ---------- 动态 ----------

function paintFeed(box) {
  if (tag) {
    box.appendChild(h('div', 'sp-filter', '正在看 #' + tag));
  }

  const list = h('div', 'sp-list');
  box.appendChild(list);
  list.appendChild(h('div', 'sp-empty', '加载中…'));

  const more = btn('再看一些', 'btn-ghost sp-more', function () {
    const last = list.querySelector('.sp-item:last-of-type');
    const cursor = feedRec ? feedOff : (last ? Number(last.dataset.id || 0) : 0);
    more.disabled = true;
    loadFeed(list, cursor, false).then(function (n) {
      more.disabled = false;
      if (n < 20) more.remove();
    });
  });
  feedOff = 0;
  loadFeed(list, 0, true).then(function (n) { if (n < 20) more.remove(); });
  box.appendChild(more);
}

async function loadFeed(list, before, replace) {
  let q = 'api/moments';
  const qs = [];
  if (tag) qs.push('tag=' + encodeURIComponent(tag));
  if (mine) qs.push('mine=1');
  if (before) qs.push((feedRec ? 'off=' : 'before=') + before);
  if (qs.length) q = q + '?' + qs.join('&');

  const j = await api(q);
  if (!j || !j.ok) {
    if (replace) { list.textContent = ''; list.appendChild(h('div', 'sp-empty', (j && j.err) || '加载失败')); }
    else toast((j && j.err) || '加载失败');
    return 0;
  }
  if (replace) list.textContent = '';
  if (!j.items.length) {
    if (replace) list.appendChild(h('div', 'sp-empty', tag ? '这个标签下还没有动态' : '还没有人发动态，第一条可以是你'));
    return 0;
  }
  feedRec = !!j.recommend && !tag && !mine;
  if (feedRec && typeof j.next_off === 'number') feedOff = j.next_off;
  j.items.forEach(function (m) {
    (m.tags || []).forEach(function (tg) { if (tags.indexOf(tg) < 0) tags.push(tg); });
    list.appendChild(itemNode(m, list));
  });
  return j.items.length;
}

function composer(box) {
  const card = h('div', 'sp-card sp-compose');
  card.classList.add('sp-compose-sheet');
  const row = h('div', 'sp-c-row');
  row.appendChild(avatar(S.me || { name: '我' }, 40));
  const main = h('div', 'sp-c-main');
  row.appendChild(main);
  card.appendChild(row);
  const ta = document.createElement('textarea');
  ta.className = 'tb sp-ta';
  ta.id = 'sp-text';
  ta.rows = 3;
  ta.maxLength = 300;
  ta.placeholder = '这一刻的想法…（可以用 #话题 打标签）';
  main.appendChild(ta);

  const pics = h('div', 'sp-draft');
  main.appendChild(pics);

  const bar = h('div', 'sp-bar');
  const pick = btn('图片', 'btn-ghost btn-sm', function () { pickImages(paintDraft); });
  pick.insertBefore(icon('fa-image'), pick.firstChild);
  bar.appendChild(pick);

  const cnt = h('span', 'sp-count', '0/300');
  bar.appendChild(cnt);
  bar.appendChild(h('div', 'sp-gap'));

  const sendBtn = btn('发布', 'btn-primary btn-sm', async function () {
    const text = String(ta.value || '').trim();
    if (!text && !draft.length) { toast('写点什么或者选一张图'); return; }
    sendBtn.disabled = true;
    const j = await api('api/moments', { text: text, images: draft.slice() });
    sendBtn.disabled = false;
    if (!j || !j.ok) { toast((j && j.err) || '没发出去'); return; }
    ta.value = '';
    draft = [];
    paintDraft();
    cnt.textContent = '0/300';
    toast('发出去了');
    closeSheet();
    repaint();
  });
  bar.appendChild(sendBtn);
  main.appendChild(bar);

  ta.addEventListener('input', function () {
    cnt.textContent = String(ta.value || '').length + '/300';
  });

  function paintDraft() {
    pics.textContent = '';
    draft.forEach(function (url, i) {
      const wrap = h('div', 'sp-draft-item');
      const im = document.createElement('img');
      im.src = url;
      im.alt = '';
      wrap.appendChild(im);
      const x = document.createElement('button');
      x.type = 'button';
      x.className = 'sp-draft-x';
      x.appendChild(icon('fa-xmark'));
      x.addEventListener('click', function () { draft.splice(i, 1); paintDraft(); });
      wrap.appendChild(x);
      pics.appendChild(wrap);
    });
  }
  paintDraft();

  box.appendChild(card);
  return card;
}

function pickImages(repaint) {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/*';
  input.multiple = true;
  input.addEventListener('change', async function () {
    const files = Array.prototype.slice.call(input.files || []);
    for (const file of files) {
      if (draft.length >= 9) { toast('最多 9 张图'); break; }
      if (file.type && !/^image\//.test(file.type)) { toast('选图片文件'); continue; }
      toast('处理图片…');
      let dataUrl;
      try { dataUrl = await shrinkImage(file); }
      catch (e) { toast((e && e.message) || '这张图片读不出来'); continue; }
      const up = await api('api/upload', { data: dataUrl });
      if (!up || !up.ok) { toast((up && up.err) || '上传失败'); continue; }
      draft.push(up.url);
      repaint();
    }
  });
  input.click();
}

function itemNode(m, list) {
  const el = h('div', 'sp-item');
  el.dataset.id = String(m.id);

  const head = h('div', 'sp-head');
  const a = avatar(m.member || { name: '?' }, 40);
  a.addEventListener('click', function (ev) { ev.stopPropagation(); openProfile(m.member); });
  head.appendChild(a);

  const who = h('div', 'sp-who');
  const line = h('div', 'sp-name');
  line.appendChild(h('b', '', (m.member && m.member.name) || '已注销'));
  if (m.member && m.member.badge === 'super') line.appendChild(h('span', 'sp-badge super', '超级管理员'));
  else if (m.member && m.member.badge === 'admin') line.appendChild(h('span', 'sp-badge', '管理员'));
  if (m.member && m.member.kind === 'ai') line.appendChild(h('span', 'sp-badge ai', 'AI'));
  who.appendChild(line);
  who.appendChild(h('div', 'sp-time', when(m.ts)));
  head.appendChild(who);
  head.appendChild(h('div', 'sp-gap'));

  const more = document.createElement('button');
  more.type = 'button';
  more.className = 'sp-more-btn';
  more.appendChild(icon('fa-ellipsis'));
  more.addEventListener('click', function () { openItemMenu(more, m, el, list); });
  head.appendChild(more);
  el.appendChild(head);

  if (m.text) {
    const p = h('div', 'sp-text');
    paintRich(p, m.text);
    el.appendChild(p);
  }

  if (m.images && m.images.length) {
    const grid = h('div', 'sp-imgs n' + Math.min(m.images.length, 9));
    m.images.forEach(function (src) {
      const im = document.createElement('img');
      im.src = src;
      im.loading = 'lazy';
      im.alt = '';
      im.addEventListener('click', function () { openImage(src); });
      grid.appendChild(im);
    });
    el.appendChild(grid);
  }

  const acts = h('div', 'sp-acts');
  const like = document.createElement('button');
  like.type = 'button';
  like.className = 'sp-act' + (m.liked ? ' on' : '');
  like.appendChild(icon(m.liked ? 'fa-solid fa-heart' : 'fa-regular fa-heart'));
  like.appendChild(h('span', '', m.likes ? String(m.likes) : '赞'));
  like.addEventListener('click', async function () {
    const j = await api('api/moment/like', { id: m.id });
    if (!j || !j.ok) { toast((j && j.err) || '点不了'); return; }
    m.liked = j.liked;
    m.likes = j.likes;
    like.className = 'sp-act' + (m.liked ? ' on' : '');
    like.textContent = '';
    like.appendChild(icon(m.liked ? 'fa-solid fa-heart' : 'fa-regular fa-heart'));
    like.appendChild(h('span', '', m.likes ? String(m.likes) : '赞'));
  });
  if (feat('space', 'like')) acts.appendChild(like);

  const cm = document.createElement('button');
  cm.type = 'button';
  cm.className = 'sp-act';
  cm.appendChild(icon('fa-regular fa-comment'));
  cm.appendChild(h('span', '', m.comments && m.comments.length ? String(m.comments.length) : '评论'));
  cm.addEventListener('click', function () {
    openComment = openComment === m.id ? 0 : m.id;
    const old = el.querySelector('.sp-cbox');
    if (old) old.remove();
    if (openComment === m.id) el.appendChild(commentBox(m, el));
  });
  if (feat('space', 'comment')) acts.appendChild(cm);
  el.appendChild(acts);

  if (m.comments && m.comments.length) {
    const box = h('div', 'sp-comments');
    m.comments.forEach(function (c) { box.appendChild(commentNode(c, m, el, box)); });
    el.appendChild(box);
  }

  if (openComment === m.id) el.appendChild(commentBox(m, el));
  return el;
}

function commentNode(c, m, el, box) {
  const row = h('div', 'sp-comment');
  row.appendChild(h('b', '', ((c.member && c.member.name) || '已注销') + '：'));
  row.appendChild(h('span', '', c.text));
  if (S.me && (c.member && c.member.id === S.me.id || isAdmin())) {
    const del = iconBtn('fa-xmark', '删掉这条评论', async function () {
      const j = await api('api/moment/comment', { op: 'del', id: m.id, comment_id: c.id });
      if (!j || !j.ok) { toast((j && j.err) || '删不了'); return; }
      row.remove();
      m.comments = (m.comments || []).filter(function (x) { return x.id !== c.id; });
      if (!box.children.length) box.remove();
    });
    row.appendChild(del);
  }
  return row;
}

function commentBox(m, el) {
  const box = h('div', 'sp-cbox');
  const input = document.createElement('input');
  input.type = 'text';
  input.className = 'tb';
  input.placeholder = '说点什么…';
  input.maxLength = 300;
  box.appendChild(input);
  const send = btn('发送', 'btn-primary btn-sm', async function () {
    const text = String(input.value || '').trim();
    if (!text) return;
    const j = await api('api/moment/comment', { id: m.id, text: text });
    if (!j || !j.ok) { toast((j && j.err) || '没发出去'); return; }
    m.comments = (m.comments || []).concat([j.comment]);
    input.value = '';
    openComment = 0;
    repaint();
  });
  box.appendChild(send);
  input.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); send.click(); } });
  setTimeout(function () { input.focus(); }, 30);
  return box;
}

function openItemMenu(anchor, m, el, list) {
  const old = document.querySelector('.sp-menu');
  if (old) old.remove();
  const menu = h('div', 'sp-menu');
  const items = [];
  if (m.can_delete) {
    items.push(['删除', 'fa-trash-can', async function () {
      const j = await api('api/moments', { op: 'delete', id: m.id });
      if (!j || !j.ok) { toast((j && j.err) || '删不了'); return; }
      el.remove();
      toast('已删除');
    }]);
  }
  items.push(['举报', 'fa-flag', function () { reportDialog(m); }]);

  items.forEach(function (it) {
    const b = document.createElement('button');
    b.type = 'button';
    b.appendChild(icon(it[1]));
    b.appendChild(h('span', '', it[0]));
    b.addEventListener('click', function () { menu.remove(); it[2](); });
    menu.appendChild(b);
  });
  document.body.appendChild(menu);
  const r = anchor.getBoundingClientRect();
  menu.style.left = Math.max(8, Math.min(r.left - 120, window.innerWidth - 160)) + 'px';
  menu.style.top = (r.bottom + 6) + 'px';
  setTimeout(function () {
    document.addEventListener('click', function onDoc(ev) {
      if (!document.querySelector('.sp-menu')) { document.removeEventListener('click', onDoc); return; }
      if (!menu.contains(ev.target)) { menu.remove(); document.removeEventListener('click', onDoc); }
    });
  }, 0);
}

function reportDialog(m) {
  const body = h('div', 'sp-form');
  const ta = document.createElement('textarea');
  ta.className = 'tb';
  ta.rows = 4;
  ta.maxLength = 300;
  ta.placeholder = '这条动态哪里不合适？管理员会收到通知。';
  body.appendChild(ta);
  const send = btn('提交举报', 'btn-primary', async function () {
    const reason = String(ta.value || '').trim();
    if (!reason) { toast('写一句原因'); return; }
    const j = await api('api/moment/report', { id: m.id, reason: reason });
    if (!j || !j.ok) { toast((j && j.err) || '提交失败'); return; }
    closeSheet();
    toast('已提交，管理员会看到');
  });
  body.appendChild(send);
  openSheet('举报动态', body, { size: 'md' });
}

function openProfile(m) {
  const uid = uidOf(m);
  if (!uid) return;
  go('#/u/' + uid);
}

// #标签 变成可点的 span
function paintRich(el, text) {
  const parts = String(text).split(/(#[^\s#]+)/g);
  parts.forEach(function (p) {
    if (!p) return;
    if (p.charAt(0) === '#' && p.length > 1) {
      const s = h('span', 'sp-tag', p);
      s.addEventListener('click', function () {
        tag = p.slice(1);
        repaint();
      });
      el.appendChild(s);
    } else {
      el.appendChild(document.createTextNode(p));
    }
  });
}

function isAdmin() {
  return !!(S.me && (S.me.role === 'admin' || S.me.role === 'super'));
}

function when(ts) {
  const d = new Date(Number(ts) || 0);
  const now = new Date();
  if (d.toDateString() === now.toDateString()) return hhmm(ts);
  return (d.getMonth() + 1) + '月' + d.getDate() + '日 ' + hhmm(ts);
}