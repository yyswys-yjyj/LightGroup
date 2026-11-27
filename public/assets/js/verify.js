// LightGroup · 高危操作的二次验证
// 转让群主、解散群、注销账号、后台清空历史这类动作点错就回不去，
// 提交前先在这里过一道人机验证（站点用什么验证方式由后台决定）。
import { BASE, S, h, btn, openSheet, closeSheet, toast } from './core.js';

// 后端 Guard::ACTIONS 的键；这里只判断要不要弹，真正拦人的在服务端
export function riskNeed(action) {
  const r = (S.me && S.me.risk) || {};
  if (!r.enabled) return false;
  const list = r.actions || [];
  return list.indexOf(action) >= 0;
}

/**
 * 需要验证时弹一个 dialog，通过后返回要随请求提交的字段；不需要验证返回 {};
 * 用户取消返回 null（调用方直接放弃这次操作）。
 */
export async function requireRisk(action, title, hint) {
  if (!riskNeed(action)) return {};
  let cfg;
  try {
    cfg = await startChallenge();
  } catch (e) {
    toast('验证服务暂时连不上，稍后再试');
    return null;
  }
  if (!cfg) { toast('验证服务暂时连不上，稍后再试'); return null; }
  return new Promise(function (resolve) {
    if (cfg.provider && cfg.provider !== 'builtin') openThird(cfg, title, hint, resolve);
    else openSlider(cfg, title, hint, resolve);
  });
}

async function startChallenge() {
  const r = await fetch(BASE + 'captcha/start', { credentials: 'same-origin' });
  const j = await r.json();
  if (!j || !j.token) return null;
  return j;
}

// ---------- 内建二维滑块 ----------

function openSlider(cfg, title, hint, resolve) {
  const wrap = h('div', 'vrf');
  wrap.appendChild(h('div', 'vrf-lead', title || '这个操作要先确认是你本人'));
  if (hint) wrap.appendChild(h('div', 'vrf-hint', hint));

  const stage = h('div', 'vrf-stage');
  const target = h('div', 'vrf-target');
  const knob = h('div', 'vrf-knob');
  stage.appendChild(target);
  stage.appendChild(knob);
  wrap.appendChild(stage);

  const tip = h('div', 'vrf-tip', '按住左上角的圆点，拖到圆圈里再松手');
  wrap.appendChild(tip);
  const state = h('div', 'vrf-state', '');
  wrap.appendChild(state);

  const acts = h('div', 'vrf-acts');
  const again = btn('重新拖', 'btn btn-ghost btn-sm', function () { reset(); });
  const cancel = btn('取消', 'btn btn-ghost btn-sm', function () { closeSheet(); resolve(null); });
  acts.appendChild(again);
  acts.appendChild(cancel);
  wrap.appendChild(acts);

  // 目标点：百分比坐标，交给 CSS 定位
  const tx = Number(cfg.target && cfg.target.x) || 50;
  const ty = Number(cfg.target && cfg.target.y) || 50;
  target.style.left = tx + '%';
  target.style.top = ty + '%';

  let dragging = false;
  let t0 = 0;
  let path = [];
  let busy = false;

  function place(x, y) {
    knob.style.left = x + '%';
    knob.style.top = y + '%';
  }
  function reset() {
    path = [];
    place(2, 2);
    tip.textContent = '按住左上角的圆点，拖到圆圈里再松手';
    state.textContent = '';
    knob.classList.remove('on');
  }
  reset();

  function at(ev) {
    const r = stage.getBoundingClientRect();
    let x = ((ev.clientX - r.left) / r.width) * 100;
    let y = ((ev.clientY - r.top) / r.height) * 100;
    x = Math.max(0, Math.min(100, x));
    y = Math.max(0, Math.min(100, y));
    return { x: Math.round(x), y: Math.round(y) };
  }

  function down(ev) {
    if (busy) return;
    ev.preventDefault();
    dragging = true;
    t0 = Date.now();
    path = [{ x: 2, y: 2 }];
    knob.classList.add('on');
    tip.textContent = '拖到圆圈里再松手';
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
    window.addEventListener('pointercancel', up);
  }
  function move(ev) {
    if (!dragging) return;
    const p = at(ev);
    place(p.x, p.y);
    const last = path[path.length - 1];
    // 采样不用太密：既能看出轨迹，也不至于一次拖动几千个点
    if (!last || Math.abs(last.x - p.x) + Math.abs(last.y - p.y) >= 2) path.push(p);
  }
  function up() {
    if (!dragging) return;
    dragging = false;
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', up);
    window.removeEventListener('pointercancel', up);
    const end = path[path.length - 1] || { x: 2, y: 2 };
    const dist = Math.hypot(end.x - tx, end.y - ty);
    if (dist > 14) {
      tip.textContent = '没对上圆圈，再拖一次';
      state.textContent = '';
      return;
    }
    if (path.length < 4) path.push({ x: Math.round((end.x + tx) / 2), y: Math.round((end.y + ty) / 2) }, { x: tx, y: ty });
    finish(path, Date.now() - t0);
  }

  function finish(points, ms) {
    busy = true;
    knob.classList.remove('on');
    const fields = {
      cap_token: cfg.token,
      cap_x: String(points[points.length - 1].x),
      cap_y: String(points[points.length - 1].y),
      cap_ms: String(ms),
      cap_moves: String(points.length),
      cap_path: points.map(function (p) { return p.x + ':' + p.y; }).join(';')
    };
    if (!cfg.pow) {
      state.textContent = '通过';
      closeSheet();
      resolve(fields);
      return;
    }
    state.textContent = '正在计算工作量证明…';
    powNonce(cfg.pow.salt, Number(cfg.pow.bits) || 18, function (nonce) {
      fields.cap_pow = nonce;
      state.textContent = '通过';
      closeSheet();
      resolve(fields);
    }, function () {
      state.textContent = '计算失败，重新拖一次';
      busy = false;
    });
  }

  knob.addEventListener('pointerdown', down);
  stage.addEventListener('pointerdown', function (ev) { if (ev.target === knob) return; });
  openSheet('安全验证', wrap, { size: 'sm' });
}

// ---------- 第三方 widget ----------

const THIRD_LABEL = { turnstile: 'Cloudflare Turnstile', hcaptcha: 'hCaptcha', recaptcha: 'reCAPTCHA', geetest4: 'Geetest v4' };

function openThird(cfg, title, hint, resolve) {
  const wrap = h('div', 'vrf');
  wrap.appendChild(h('div', 'vrf-lead', title || '这个操作要先确认是你本人'));
  if (hint) wrap.appendChild(h('div', 'vrf-hint', hint));
  const box = h('div', 'vrf-third');
  wrap.appendChild(box);
  const tip = h('div', 'vrf-tip', '在下面的验证框里完成验证');
  wrap.appendChild(tip);
  const acts = h('div', 'vrf-acts');
  acts.appendChild(btn('取消', 'btn btn-ghost btn-sm', function () { closeSheet(); resolve(null); }));
  wrap.appendChild(acts);
  openSheet('安全验证', wrap, { size: 'sm' });

  let left = false;
  function done(token) {
    if (left) return;
    left = true;
    tip.textContent = '通过';
    closeSheet();
    resolve({ cap3: token });
  }
  function fail() { tip.textContent = '验证框加载失败，刷新页面再试'; }

  const p = cfg.provider;
  loadScript(cfg.script).then(function () {
    if (p === 'turnstile') {
      window.turnstile.render(box, { sitekey: cfg.site_key, language: 'zh-CN', callback: done });
    } else if (p === 'hcaptcha') {
      window.hcaptcha.render(box, { sitekey: cfg.site_key, callback: done });
    } else if (p === 'recaptcha') {
      window.grecaptcha.render(box, { sitekey: cfg.site_key, callback: done });
    } else if (p === 'tencent') {
      // 腾讯防水墙：TCaptcha.js 挂全局 TencentCaptcha，回调给 ticket + randstr
      const tc = new window.TencentCaptcha(cfg.site_key, function (res) {
        if (res && res.ret === 0 && res.ticket) {
          done(JSON.stringify({ ticket: res.ticket, randstr: res.randstr || '' }));
        } else {
          tip.textContent = '验证没过，再试一次';
        }
      }, {});
      tc.show();
    } else if (p === 'geetest4') {
      initGeetest4({
        captchaId: cfg.site_key,
        product: 'bind',
        language: 'zho'
      }, function (cap) {
        cap.onSuccess(function () {
          const v = cap.getValidate() || {};
          done(JSON.stringify({
            lot_number: v.lot_number || '',
            captcha_output: v.captcha_output || '',
            pass_token: v.pass_token || '',
            gen_time: v.gen_time || ''
          }));
        });
        const b = btn('开始验证', 'btn btn-ghost', function () { cap.showCaptcha(); });
        box.appendChild(b);
      });
    } else {
      fail();
    }
  }).catch(fail);
}

function loadScript(src) {
  if (!src) return Promise.reject(new Error('no script'));
  const hit = document.querySelector('script[data-vrf="' + src + '"]');
  if (hit) return Promise.resolve();
  return new Promise(function (ok, no) {
    const s = document.createElement('script');
    s.src = src;
    s.async = true;
    s.defer = true;
    s.setAttribute('data-vrf', src);
    s.onload = function () { ok(); };
    s.onerror = function () { no(new Error('load failed')); };
    document.head.appendChild(s);
  });
}

// ---------- 工作量证明 ----------

function powNonce(salt, bits, ok, no) {
  const full = bits >> 2;
  const rem = bits & 3;
  const limit = 4000000000;
  let i = 0;
  function step() {
    // 每批让出一次主线程，26 万次哈希也不会把界面冻住
    const end = Math.min(i + 20000, limit);
    for (; i < end; i++) {
      const hex = sha256Hex(salt + ':' + i);
      if (zeroBits(hex, full, rem)) {
        ok(String(i));
        return;
      }
    }
    if (i >= limit) { no(); return; }
    setTimeout(step, 0);
  }
  step();
}

function zeroBits(hex, full, rem) {
  for (let k = 0; k < full; k++) if (hex[k] !== '0') return false;
  if (rem === 0) return true;
  return parseInt(hex[full], 16) < (1 << (4 - rem));
}

const SHA_K = [
  0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
  0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
  0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
  0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
  0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
  0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
  0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
  0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
];

function sha256Hex(str) {
  const bytes = new TextEncoder().encode(str);
  const l = bytes.length;
  const total = (((l + 9) >> 6) + 1) << 6;
  const buf = new Uint8Array(total);
  buf.set(bytes);
  buf[l] = 0x80;
  const dv = new DataView(buf.buffer);
  dv.setUint32(total - 4, (l * 8) >>> 0, false);

  let h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a;
  let h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;
  const w = new Int32Array(64);

  for (let off = 0; off < total; off += 64) {
    for (let i = 0; i < 16; i++) w[i] = dv.getInt32(off + i * 4, false);
    for (let i = 16; i < 64; i++) {
      const a = w[i - 15], b = w[i - 2];
      const s0 = ((a >>> 7) | (a << 25)) ^ ((a >>> 18) | (a << 14)) ^ (a >>> 3);
      const s1 = ((b >>> 17) | (b << 15)) ^ ((b >>> 19) | (b << 13)) ^ (b >>> 10);
      w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
    }
    let a = h0, b = h1, c = h2, d = h3, e = h4, f = h5, g = h6, hh = h7;
    for (let i = 0; i < 64; i++) {
      const S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
      const ch = (e & f) ^ (~e & g);
      const t1 = (hh + S1 + ch + SHA_K[i] + w[i]) | 0;
      const S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
      const maj = (a & b) ^ (a & c) ^ (b & c);
      const t2 = (S0 + maj) | 0;
      hh = g; g = f; f = e; e = (d + t1) | 0;
      d = c; c = b; b = a; a = (t1 + t2) | 0;
    }
    h0 = (h0 + a) | 0; h1 = (h1 + b) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0;
    h4 = (h4 + e) | 0; h5 = (h5 + f) | 0; h6 = (h6 + g) | 0; h7 = (h7 + hh) | 0;
  }
  function hex(n) { return ('00000000' + (n >>> 0).toString(16)).slice(-8); }
  return hex(h0) + hex(h1) + hex(h2) + hex(h3) + hex(h4) + hex(h5) + hex(h6) + hex(h7);
}
