// 登录页的人机验证：二维滑块（把左上角的圆点拖到圆圈里）
// 服务端发一条自签名的挑战（目标坐标 + 过期时间 + UA 摘要，HMAC 签过，不存服务端状态），
// 拖到位再提交；站点开了工作量证明时，拖完还要算一个满足难度的 nonce 一起提交。
(function () {
  var box = document.getElementById('captcha');
  if (!box) return;
  var startUrl = box.dataset.start || '/captcha/start';
  var stage = document.getElementById('cap-stage');
  var targetEl = document.getElementById('cap-target');
  var knob = document.getElementById('cap-knob');
  var fToken = document.getElementById('cap-token');
  var fX = document.getElementById('cap-x');
  var fY = document.getElementById('cap-y');
  var fMs = document.getElementById('cap-ms');
  var fMoves = document.getElementById('cap-moves');
  var fPath = document.getElementById('cap-path');
  var fPow = document.getElementById('cap-pow');
  var form = box.closest('form');
  var cfg = null;
  var tx = 50;
  var ty = 50;
  var dragging = false;
  var t0 = 0;
  var path = [];
  var done = false;
  var busy = false;

  function place(x, y) {
    knob.style.left = x + '%';
    knob.style.top = y + '%';
  }
  function tip(t) {
    var s = box.querySelector('.captcha-tip span');
    if (s) s.textContent = t;
  }
  function reset() {
    done = false;
    busy = false;
    path = [];
    place(2, 2);
    box.classList.remove('done', 'fail');
    if (fToken) fToken.value = '';
    if (fX) fX.value = '';
    if (fY) fY.value = '';
    if (fMs) fMs.value = '';
    if (fMoves) fMoves.value = '';
    if (fPath) fPath.value = '';
    if (fPow) fPow.value = '';
  }

  function load() {
    reset();
    tip('按住左上角的圆点，拖到圆圈里再松手');
    fetch(startUrl, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.token) return;
        cfg = j;
        tx = Number(j.target && j.target.x) || 50;
        ty = Number(j.target && j.target.y) || 50;
        targetEl.style.left = tx + '%';
        targetEl.style.top = ty + '%';
        if (fToken) fToken.value = j.token;
      })
      .catch(function () { /* 拿不到就交给服务端报错 */ });
  }

  function at(ev) {
    var r = stage.getBoundingClientRect();
    var x = ((ev.clientX - r.left) / r.width) * 100;
    var y = ((ev.clientY - r.top) / r.height) * 100;
    return {
      x: Math.round(Math.max(0, Math.min(100, x))),
      y: Math.round(Math.max(0, Math.min(100, y)))
    };
  }

  function begin(ev) {
    if (busy || !cfg || done) return;
    dragging = true;
    t0 = Date.now();
    path = [{ x: 2, y: 2 }];
    tip('拖到圆圈里再松手');
    window.addEventListener('pointermove', move, { passive: false });
    window.addEventListener('pointerup', end);
    window.addEventListener('pointercancel', end);
    ev.preventDefault();
  }
  function move(ev) {
    if (!dragging) return;
    var p = at(ev);
    place(p.x, p.y);
    var last = path[path.length - 1];
    // 采样点给服务端核轨迹用：只要终点的话，脚本填个坐标就过了
    if (!last || Math.abs(last.x - p.x) + Math.abs(last.y - p.y) >= 2) {
      if (path.length < 64) path.push(p);
    }
    ev.preventDefault();
  }
  function end() {
    if (!dragging) return;
    dragging = false;
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', end);
    window.removeEventListener('pointercancel', end);
    var last = path[path.length - 1] || { x: 2, y: 2 };
    var ms = Date.now() - t0;
    if (fX) fX.value = String(last.x);
    if (fY) fY.value = String(last.y);
    if (fMs) fMs.value = String(ms);
    if (fMoves) fMoves.value = String(path.length);
    if (fPath) {
      var pts = path.slice();
      if (!pts.length || pts[pts.length - 1].x !== last.x || pts[pts.length - 1].y !== last.y) pts.push(last);
      fPath.value = pts.map(function (p) { return p.x + ':' + p.y; }).join(';');
    }
    if (Math.hypot(last.x - tx, last.y - ty) > 14 || path.length < 4) {
      fail();
      return;
    }
    if (cfg.pow && Number(cfg.pow.bits) > 0) {
      busy = true;
      tip('正在计算工作量证明…');
      powNonce(String(cfg.pow.salt || ''), Number(cfg.pow.bits) || 18, function (nonce) {
        if (fPow) fPow.value = nonce;
        pass();
      }, function () {
        busy = false;
        fail();
      });
      return;
    }
    pass();
  }
  function pass() {
    done = true;
    box.classList.add('done');
    tip('验证通过，可以登录了');
  }
  function fail() {
    box.classList.add('fail');
    tip('没对上圆圈，再拖一次');
    setTimeout(function () {
      if (box.classList.contains('fail')) load();
    }, 700);
  }

  knob.addEventListener('pointerdown', begin);

  if (form) {
    form.addEventListener('submit', function (e) {
      // 只拦「该验但还没过」的情况：服务端才是最终裁判
      if (!done) {
        e.preventDefault();
        box.classList.add('fail');
        setTimeout(function () { box.classList.remove('fail'); }, 400);
        var err = form.querySelector('.login-err');
        if (!err) {
          err = document.createElement('p');
          err.className = 'login-err';
          err.setAttribute('role', 'alert');
          form.insertBefore(err, form.querySelector('.login-go'));
        }
        err.textContent = '先把圆点拖到圆圈里';
      }
    });
  }

  // ---------- 工作量证明 ----------
  function powNonce(salt, bits, ok, no) {
    var full = bits >> 2;
    var rem = bits & 3;
    var limit = 4000000000;
    var i = 0;
    function step() {
      var end = Math.min(i + 20000, limit);
      for (; i < end; i++) {
        var hex = sha256Hex(salt + ':' + i);
        if (zeroBits(hex, full, rem)) { ok(String(i)); return; }
      }
      if (i >= limit) { no(); return; }
      setTimeout(step, 0);
    }
    step();
  }
  function zeroBits(hex, full, rem) {
    for (var k = 0; k < full; k++) if (hex.charAt(k) !== '0') return false;
    if (rem === 0) return true;
    return parseInt(hex.charAt(full), 16) < (1 << (4 - rem));
  }

  var K = [
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
    var bytes = new TextEncoder().encode(str);
    var l = bytes.length;
    var total = (((l + 9) >> 6) + 1) << 6;
    var buf = new Uint8Array(total);
    buf.set(bytes);
    buf[l] = 0x80;
    var dv = new DataView(buf.buffer);
    dv.setUint32(total - 4, (l * 8) >>> 0, false);

    var h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a;
    var h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;
    var w = new Int32Array(64);

    for (var off = 0; off < total; off += 64) {
      for (var i = 0; i < 16; i++) w[i] = dv.getInt32(off + i * 4, false);
      for (i = 16; i < 64; i++) {
        var a15 = w[i - 15], a2 = w[i - 2];
        var s0 = ((a15 >>> 7) | (a15 << 25)) ^ ((a15 >>> 18) | (a15 << 14)) ^ (a15 >>> 3);
        var s1 = ((a2 >>> 17) | (a2 << 15)) ^ ((a2 >>> 19) | (a2 << 13)) ^ (a2 >>> 10);
        w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
      }
      var a = h0, b = h1, c = h2, d = h3, e = h4, f = h5, g = h6, hh = h7;
      for (i = 0; i < 64; i++) {
        var S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
        var ch = (e & f) ^ (~e & g);
        var t1 = (hh + S1 + ch + K[i] + w[i]) | 0;
        var S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
        var maj = (a & b) ^ (a & c) ^ (b & c);
        var t2 = (S0 + maj) | 0;
        hh = g; g = f; f = e; e = (d + t1) | 0;
        d = c; c = b; b = a; a = (t1 + t2) | 0;
      }
      h0 = (h0 + a) | 0; h1 = (h1 + b) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0;
      h4 = (h4 + e) | 0; h5 = (h5 + f) | 0; h6 = (h6 + g) | 0; h7 = (h7 + hh) | 0;
    }
    function hex(n) { return ('00000000' + (n >>> 0).toString(16)).slice(-8); }
    return hex(h0) + hex(h1) + hex(h2) + hex(h3) + hex(h4) + hex(h5) + hex(h6) + hex(h7);
  }

  load();
})();
