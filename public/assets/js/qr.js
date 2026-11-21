// LightGroup · 二维码：生成弹层、解码（BarcodeDetector / jsQR）、扫码跳转
import { BASE, S, h, btn, toast, api, go, openSheet, closeSheet } from './core.js';

const RE_USER = /^lightgroup:\/\/user\/([A-Za-z0-9_]+)$/i;
const RE_GROUP = /^lightgroup:\/\/group\/(\d+)$/i;

/** 二维码图片地址（服务端生成 SVG） */
export function qrSrc(data, px) {
  return BASE + 'api/qr?d=' + encodeURIComponent(String(data)) + '&s=' + (px || 560);
}

/** 弹层里放一张二维码，附复制内容与下载 */
export function openQr(title, data, hint) {
  const wrap = h('div', 'qr-wrap');
  const img = document.createElement('img');
  img.className = 'qr-img';
  img.alt = '二维码';
  img.width = 220;
  img.height = 220;
  img.src = qrSrc(data, 560);
  wrap.appendChild(img);
  wrap.appendChild(h('div', 'qr-text', String(data)));
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

/** 扫码结果统一入口：认得出就跳，认不出就解释 */
export async function followScan(text) {
  const t = String(text || '').trim();
  if (!t) return false;
  let m;
  if ((m = RE_USER.exec(t))) { await openUserScan(m[1]); return true; }
  if ((m = RE_GROUP.exec(t))) { await openGroupScan(parseInt(m[1], 10) || 0); return true; }
  if (/^https?:\/\//i.test(t)) { warnExternal(t); return true; }
  unsupported(t);
  return true;
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

function warnExternal(url) {
  const wrap = h('div', 'qr-warn');
  wrap.appendChild(h('div', 'qr-warn-t', '要打开站外网页'));
  const box = h('div', 'qr-url');
  box.textContent = url; // 纯文本，不拼 HTML
  wrap.appendChild(box);
  wrap.appendChild(h('div', 'qr-hint', '这个地址不是本站的，确认来源可信再继续。'));
  const row = h('div', 'qr-acts');
  row.appendChild(btn('取消', 'btn btn-sm btn-ghost', function () { closeSheet(); }));
  row.appendChild(btn('继续访问', 'btn btn-sm btn-primary', function () {
    closeSheet();
    window.open(url, '_blank', 'noopener,noreferrer');
  }));
  wrap.appendChild(row);
  openSheet('站外链接', wrap, { size: 'sm' });
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
  openSheet('二维码无法识别', wrap, { size: 'sm' });
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
      s.src = BASE + 'assets/vendor/jsqr.js';
      s.async = true;
      s.onload = function () { resolve(!!window.jsQR); };
      s.onerror = function () { resolve(false); };
      document.head.appendChild(s);
    });
  }
  return jsqrLoading;
}

let detector = null;
function barcodeDetector() {
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

/** 从 video 元素或图片元素里读二维码；读不到返回空串 */
export async function decodeSource(src) {
  const det = barcodeDetector();
  if (det) {
    try {
      const codes = await det.detect(src);
      if (codes && codes.length) return String(codes[0].rawValue || '');
    } catch (e) {
      void e;
    }
  }
  const ok = await loadJsqr();
  if (!ok) return '';
  const w = src.videoWidth || src.naturalWidth || src.width || 0;
  const h0 = src.videoHeight || src.naturalHeight || src.height || 0;
  if (!w || !h0) return '';
  const scale = Math.min(1, 720 / Math.max(w, h0));
  const cw = Math.max(1, Math.round(w * scale));
  const ch = Math.max(1, Math.round(h0 * scale));
  const cv = document.createElement('canvas');
  cv.width = cw;
  cv.height = ch;
  const ctx = cv.getContext('2d', { willReadFrequently: true });
  if (!ctx) return '';
  ctx.drawImage(src, 0, 0, cw, ch);
  const img = ctx.getImageData(0, 0, cw, ch);
  try {
    const res = window.jsQR(img.data, cw, ch, { inversionAttempts: 'attemptBoth' });
    return res && res.data ? String(res.data) : '';
  } catch (e) {
    void e;
    return '';
  }
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
