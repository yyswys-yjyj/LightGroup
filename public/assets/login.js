// 登录页的人机验证：一维滑块
// 服务端发一条自签名的挑战（滑块该停的位置 + 过期时间，HMAC 签过，不存服务端状态），
// 把方块拖到位再提交。拖错自动换一条，旧的两分钟自然过期。
(function () {
  var box = document.getElementById('captcha');
  if (!box) return;
  var startUrl = box.dataset.start || '/captcha/start';
  var track = document.getElementById('cap-track');
  var hole = document.getElementById('cap-hole');
  var knob = document.getElementById('cap-knob');
  var fToken = document.getElementById('cap-token');
  var fOff = document.getElementById('cap-off');
  var fMs = document.getElementById('cap-ms');
  var fMoves = document.getElementById('cap-moves');
  var fPath = document.getElementById('cap-path');
  var form = box.closest('form');
  var target = 0;
  var ready = false;
  var done = false;
  var dragging = false;
  var startX = 0;
  var startAt = 0;
  var moves = 0;
  var left = 0;
  var path = [];

  function knobW() { return knob.offsetWidth || 44; }
  function maxX() { return Math.max(0, (track.clientWidth || 260) - knobW()); }

  function reset() {
    done = false;
    ready = false;
    left = 0;
    moves = 0;
    path = [];
    fToken.value = '';
    fOff.value = '';
    fMs.value = '';
    fMoves.value = '';
    if (fPath) fPath.value = '';
    box.classList.remove('done', 'fail');
    knob.style.transform = 'translateX(0)';
  }

  function load() {
    reset();
    fetch(startUrl, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) return;
        target = Number(j.target) || 0;
        fToken.value = j.token || '';
        ready = true;
        hole.style.left = target + '%';
      })
      .catch(function () { /* 拿不到就交给服务端报错 */ });
  }

  function begin(e) {
    if (!ready || done) return;
    dragging = true;
    startX = e.clientX != null ? e.clientX : (e.touches ? e.touches[0].clientX : 0);
    startAt = Date.now();
    moves = 0;
    // 起点就记 0：服务端会看轨迹是不是从左往右走过来的
    path = [0];
    box.classList.add('drag');
    knob.setPointerCapture && e.pointerId != null && knob.setPointerCapture(e.pointerId);
    e.preventDefault();
  }

  function move(e) {
    if (!dragging) return;
    var x = e.clientX != null ? e.clientX : (e.touches ? e.touches[0].clientX : 0);
    left = Math.min(maxX(), Math.max(0, x - startX));
    knob.style.transform = 'translateX(' + left + 'px)';
    moves++;
    // 采样点给服务端核轨迹用（只看终点和次数的话，脚本填个 5 就过了）
    if (path.length < 64) path.push(Math.round(((left + knobW() / 2) / (track.clientWidth || 260)) * 100));
    e.preventDefault();
  }

  function end() {
    if (!dragging) return;
    dragging = false;
    box.classList.remove('drag');
    var center = ((left + knobW() / 2) / (track.clientWidth || 260)) * 100;
    var ms = Date.now() - startAt;
    fOff.value = String(Math.round(center));
    fMs.value = String(ms);
    fMoves.value = String(moves);
    if (fPath) {
      var pts = path.slice();
      if (!pts.length || pts[pts.length - 1] !== Math.round(center)) pts.push(Math.round(center));
      fPath.value = pts.join(',');
    }
    if (Math.abs(center - target) <= 4 && ms >= 300 && moves >= 5) {
      done = true;
      box.classList.add('done');
    } else {
      box.classList.add('fail');
      setTimeout(load, 420);
    }
  }

  knob.addEventListener('pointerdown', begin);
  window.addEventListener('pointermove', move, { passive: false });
  window.addEventListener('pointerup', end);
  window.addEventListener('pointercancel', end);

  if (form) {
    form.addEventListener('submit', function (e) {
      // 只拦「该验但还没过」的情况：服务端才是最终裁判
      if (form.querySelector('#captcha') && !done) {
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
        err.textContent = '先把滑块拖到缺口上';
      }
    });
  }

  load();
})();
