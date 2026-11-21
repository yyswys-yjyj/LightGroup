// LightGroup · 二维码：生成弹层、解码（BarcodeDetector / jsQR）、扫码跳转
import { BASE, BOOT, S, h, btn, toast, api, go, openSheet, closeSheet } from './core.js';

/** 本群的作用域 id：二维码带它，扫到别的群时能分辨出来 */
function myScopeId() {
  return (BOOT.scope && BOOT.scope.id) ? String(BOOT.scope.id) : '';
}

/** 生成一张本站的二维码内容：带站点与作用域，避免不同站点/不同群串在一起 */
export function linkFor(kind, id) {
  const q = ['site=' + encodeURIComponent(location.origin)];
  const sid = myScopeId();
  if (sid) q.push('scope=' + encodeURIComponent(sid));
  return 'lightgroup://' + kind + '/' + encodeURIComponent(String(id)) + '?' + q.join('&');
}

/** 二维码图片地址（服务端生成 SVG） */
export function qrSrc(data, px) {
  return BASE + 'api/qr?d=' + encodeURIComponent(String(data)) + '&s=' + (px || 560);
}

/** 弹层里放一张二维码，附复制内容与下载；只给复制按钮，不把原文铺在界面上 */
export function openQr(title, data, hint) {
  const wrap = h('div', 'qr-wrap');
  const img = document.createElement('img');
  img.className = 'qr-img';
  img.alt = '二维码';
  img.width = 220;
  img.height = 220;
  img.src = qrSrc(data, 560);
  wrap.appendChild(img);
  if (hint) wrap.appendChild(h('div', 'qr-hint', hint));

  const row = h('div', 'qr-acts');
  row.appendChild(btn('复制内容', 'btn btn-sm', function () {
    const t = String(data);
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(t).then(function () { toast('已复制'); }, function () { toast('复制失败'); });
    } else {
      toast('这个浏览器不支持复制');
    }
  }));
  const dl = document.createElement('a');
  dl.className = 'btn btn-sm btn-ghost';
  dl.textContent = '下载图片';
  dl.href = qrSrc(data, 960);
  dl.download = 'lightgroup-qr.svg';
  row.appendChild(dl);
  wrap.appendChild(row);

  openSheet(title || '二维码', wrap, { size: 'sm' });
  return wrap;
}

/** lightgroup://user/h_1?site=...&scope=1（site/scope 可缺省，老码照样能用） */
function parseLg(raw) {
  const t = String(raw || '').trim();
  if (t.slice(0, 11).toLowerCase() !== 'lightgroup:') return null;
  let rest = t.slice(11);
  if (rest.slice(0, 2) !== '//') return null;
  rest = rest.slice(2);
  let qs = '';
  const qi = rest.indexOf('?');
  if (qi >= 0) { qs = rest.slice(qi + 1); rest = rest.slice(0, qi); }
  const si = rest.indexOf('/');
  if (si <= 0) return null;
  const kind = rest.slice(0, si).toLowerCase();
  let id = rest.slice(si + 1);
  try { id = decodeURIComponent(id); } catch (e) { void e; }
  const q = new URLSearchParams(qs);
  return { kind: kind, id: id, site: q.get('site') || '', scope: q.get('scope') || '', raw: t };
}

function siteOrigin(site) {
  try { return new URL(String(site)).origin; } catch (e) { return ''; }
}

function isSameSite(site) {
  const o = siteOrigin(site);
  return o !== '' && o === location.origin;
}

/** 扫码结果统一入口：认得出就跳，认不出就解释 */
export async function followScan(text) {
  const t = String(text || '').trim();
  if (!t) return false;
  const p = parseLg(t);
  if (p) { await routeLg(p); return true; }
  const low = t.toLowerCase();
  if (low.startsWith('http://') || low.startsWith('https://')) { warnExternal(t); return true; }
  unsupported(t);
  return true;
}

async function routeLg(p) {
  // 老码没有 site：按本站处理
  if (p.site && !isSameSite(p.site)) { await otherSite(p); return; }
  const mine = myScopeId();
  if (p.scope && mine && String(p.scope) !== mine) { otherScope(p); return; }
  if (p.kind === 'user') { await openUserScan(p.id); return; }
  if (p.kind === 'group') { await openGroupScan(parseInt(p.id, 10) || 0); return; }
  unsupported(p.raw);
}

async function openUserScan(uid) {
  if (!uid) { toast('二维码不对'); return; }
  const j = await api('api/u/' + encodeURIComponent(uid));
  if (!j || !j.ok) { toast((j && j.err) || '这个二维码找不到人'); return; }
  const me = !!(j.profile && j.profile.isMe) || !!(S.me && String(S.me.id) === uid);
  const st = (j.relation && j.relation.state) || 'none';
  if (!me && st === 'friend') {
    const d = await api('api/dm/open', { id: uid });
    if (d && d.ok && d.conv && d.conv.id) { go('#/c/' + d.conv.id); return; }
  }
  go('#/u/' + encodeURIComponent(uid));
}

async function openGroupScan(id) {
  if (!id) { toast('群二维码不对'); return; }
  const j = await api('api/groups/' + id);
  if (!j || !j.ok) { toast((j && j.err) || '这个群找不到了'); return; }
  if (j.group && j.group.isMember) go('#/c/' + id);
  else go('#/pg/' + id);
}

/** 拉对方站点的公开信息；拿不到就返回 null（对方可能还没升级） */
async function fetchAbout(origin) {
  const ctl = ('AbortController' in window) ? new AbortController() : null;
  const timer = ctl ? setTimeout(function () { ctl.abort(); }, 5000) : 0;
  try {
    const r = await fetch(origin + '/g/api/about', {
      method: 'GET',
      mode: 'cors',
      credentials: 'omit',
      headers: { Accept: 'application/json' },
      signal: ctl ? ctl.signal : undefined,
    });
    if (!r.ok) return null;
    const j = await r.json();
    return (j && j.ok && j.site) ? j.site : null;
  } catch (e) {
    void e;
    return null;
  } finally {
    if (timer) clearTimeout(timer);
  }
}

/** 别的站点：取它的公开信息并展示，不自动跳过去 */
async function otherSite(p) {
  const origin = siteOrigin(p.site) || String(p.site);
  const about = await fetchAbout(origin);
  const wrap = h('div', 'qr-other');
  wrap.appendChild(h('div', 'qr-other-t', '这是另一个站点的二维码'));

  if (about) {
    const card = h('div', 'qr-site');
    const im = document.createElement('img');
    im.className = 'qr-site-i';
    im.alt = '';
    im.src = String(about.icon || (origin + '/assets/favicon.svg'));
    card.appendChild(im);
    const main = h('div', 'qr-site-m');
    main.appendChild(h('b', 'qr-site-n', String(about.name || origin)));
    if (about.intro) main.appendChild(h('div', 'qr-site-d', String(about.intro)));
    const bits = [];
    if (about.stats) {
      if (about.stats.members) bits.push(about.stats.members + ' 位成员');
      if (about.stats.scopes) bits.push(about.stats.scopes + ' 个群');
    }
    if (about.version) bits.push('v' + about.version);
    if (bits.length) main.appendChild(h('div', 'qr-site-b', bits.join(' · ')));
    card.appendChild(main);
    wrap.appendChild(card);
    wrap.appendChild(h('div', 'qr-hint', '这个码属于上面这个站点。扫错了站点的话，加不了对方的群或人。'));
  } else {
    wrap.appendChild(h('div', 'qr-hint', '这个码属于站点 ' + origin + '。对方站点没有提供公开信息，可能还没升级。'));
  }

  const row = h('div', 'qr-acts');
  row.appendChild(btn('关闭', 'btn btn-sm btn-ghost', closeScanSheet));
  row.appendChild(btn('打开这个站点', 'btn btn-sm btn-primary', function () {
    closeScanSheet();
    window.open(origin + '/', '_blank', 'noopener,noreferrer');
  }));
  wrap.appendChild(row);
  openSheet('扫到别的站点', wrap, { size: 'sm', onClosed: resumeScan });
}

/** 同一个站点、不同的群 */
function otherScope(p) {
  const wrap = h('div', 'qr-warn');
  wrap.appendChild(h('div', 'qr-warn-t', '这是别的群的二维码'));
  wrap.appendChild(h('div', 'qr-hint', '这张码属于本站的另一个群，群和群之间不互通。到那个群里再扫一次就行。'));
  const row = h('div', 'qr-acts');
  row.appendChild(btn('知道了', 'btn btn-sm btn-primary', closeScanSheet));
  wrap.appendChild(row);
  openSheet('扫到别的群', wrap, { size: 'sm', onClosed: resumeScan });
}

// 扫到码之后可能弹提示层（站外链接、不支持的协议、别的站点）：
// 关掉它得回到取景框，所以通知扫描页重新开摄像头
function resumeScan() {
  try { window.dispatchEvent(new Event('lg:scan-resume')); } catch (e) { void e; }
}

function closeScanSheet() {
  closeSheet();
  resumeScan();
}

function warnExternal(url) {
  const wrap = h('div', 'qr-warn');
  wrap.appendChild(h('div', 'qr-warn-t', '要打开站外网页'));
  const box = h('div', 'qr-url');
  box.textContent = url; // 纯文本，不拼 HTML
  wrap.appendChild(box);
  wrap.appendChild(h('div', 'qr-hint', '这个地址不是本站的，确认来源可信再继续。'));
  const row = h('div', 'qr-acts');
  row.appendChild(btn('取消', 'btn btn-sm btn-ghost', closeScanSheet));
  row.appendChild(btn('继续访问', 'btn btn-sm btn-primary', function () {
    closeScanSheet();
    window.open(url, '_blank', 'noopener,noreferrer');
  }));
  wrap.appendChild(row);
  openSheet('站外链接', wrap, { size: 'sm', onClosed: resumeScan });
}

function unsupported(raw) {
  const wrap = h('div', 'qr-warn');
  wrap.appendChild(h('div', 'qr-warn-t', '不支持的协议'));
  wrap.appendChild(h('div', 'qr-hint', '这个二维码既不是 LightGroup 的码，也不是网页地址。'));
  const pre = document.createElement('pre');
  pre.className = 'qr-raw';
  pre.hidden = true;
  pre.textContent = raw;
  wrap.appendChild(pre);
  const row = h('div', 'qr-acts');
  row.appendChild(btn('关闭', 'btn btn-sm btn-ghost', function () { closeSheet(); }));
  const toggle = btn('查看信息原文', 'btn btn-sm', function () {
    pre.hidden = !pre.hidden;
    toggle.textContent = pre.hidden ? '查看信息原文' : '收起信息原文';
  });
  row.appendChild(toggle);
  wrap.appendChild(row);
  openSheet('二维码无法识别', wrap, { size: 'sm', onClosed: resumeScan });
}

/** 要不要在「空间」里显示扫描器：移动端显示；UA 说不清时只看有没有摄像头 */
export function canScan() {
  const ua = navigator.userAgent || '';
  const mobile = /Android|iPhone|iPad|iPod|Windows Phone|HarmonyOS|Mobile/i.test(ua)
    || (/Macintosh/i.test(ua) && (navigator.maxTouchPoints || 0) > 1);
  if (mobile) return true;
  if (/Windows NT|Macintosh|X11|Linux x86_64|CrOS/i.test(ua)) return false;
  return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
}

// ---------------------------------------------------------------- 解码

let jsqrLoading = null;

function loadJsqr() {
  if (window.jsQR) return Promise.resolve(true);
  if (!jsqrLoading) {
    jsqrLoading = new Promise(function (resolve) {
      const s = document.createElement('script');
      // 静态资源在站点根 /assets 下，不能拼 API 的 BASE（那会变成 /g/assets/… 404）
      s.src = '/assets/vendor/jsqr.js';
      s.async = true;
      s.onload = function () { resolve(!!window.jsQR); };
      s.onerror = function () { resolve(false); };
      document.head.appendChild(s);
    });
  }
  return jsqrLoading;
}

let detector = null;
// 有些 Android 设备报了 BarcodeDetector 却调不通（缺 Play Services 的 ML Kit），
// 抛过一次错之后就别再试了，直接走自带解码
let detectorBroken = false;
function barcodeDetector() {
  if (detectorBroken) return false;
  if (detector !== null) return detector;
  if (!('BarcodeDetector' in window)) { detector = false; return detector; }
  try {
    detector = new window.BarcodeDetector({ formats: ['qr_code'] });
  } catch (e) {
    void e;
    detector = false;
  }
  return detector;
}

// 截一帧到复用的 canvas：每 450ms 都 new 一个 canvas 会把内存和 GC 拖慢
let frameCanvas = null;

function frameData(src, sx, sy, sw, sh, maxSide) {
  const scale = Math.min(1, maxSide / Math.max(sw, sh));
  const cw = Math.max(1, Math.round(sw * scale));
  const ch = Math.max(1, Math.round(sh * scale));
  if (!frameCanvas) frameCanvas = document.createElement('canvas');
  if (frameCanvas.width !== cw) frameCanvas.width = cw;
  if (frameCanvas.height !== ch) frameCanvas.height = ch;
  const ctx = frameCanvas.getContext('2d', { willReadFrequently: true });
  if (!ctx) return null;
  ctx.drawImage(src, sx, sy, sw, sh, 0, 0, cw, ch);
  return { data: ctx.getImageData(0, 0, cw, ch).data, w: cw, h: ch };
}

/** 从 video 元素或图片元素里读二维码；读不到返回空串 */
export async function decodeSource(src) {
  const det = barcodeDetector();
  if (det) {
    try {
      const codes = await det.detect(src);
      if (codes && codes.length) return String(codes[0].rawValue || '');
    } catch (e) {
      void e;
      detectorBroken = true;
    }
  }
  const ok = await loadJsqr();
  if (!ok) return '';
  const w = src.videoWidth || src.naturalWidth || src.width || 0;
  const h0 = src.videoHeight || src.naturalHeight || src.height || 0;
  if (!w || !h0) return '';

  // 先整帧（上限 1280，别像以前那样缩到 720——远距离的小码会直接糊掉），
  // 整帧读不出来再把取景框那一块（中间 70%）单独放大读一遍。
  const tries = [
    [0, 0, w, h0, 1280],
    [Math.round(w * 0.15), Math.round(h0 * 0.15), Math.round(w * 0.7), Math.round(h0 * 0.7), 960]
  ];
  for (let i = 0; i < tries.length; i++) {
    const t = tries[i];
    if (t[2] < 24 || t[3] < 24) continue;
    const fr = frameData(src, t[0], t[1], t[2], t[3], t[4]);
    if (!fr) continue;
    try {
      const res = window.jsQR(fr.data, fr.w, fr.h, { inversionAttempts: 'attemptBoth' });
      if (res && res.data) return String(res.data);
    } catch (e) {
      void e;
    }
  }
  return '';
}

/** 相册图片解码 */
export async function decodeFile(file) {
  if (!file) return '';
  const url = URL.createObjectURL(file);
  try {
    const im = new Image();
    im.src = url;
    await im.decode();
    return await decodeSource(im);
  } catch (e) {
    void e;
    return '';
  } finally {
    setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
  }
}
