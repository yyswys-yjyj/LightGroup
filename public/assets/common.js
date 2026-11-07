// LightGroup · 站内公共交互（关于弹层、底部声明）
(function () {
  'use strict';

  function panel() { return document.querySelector('.about'); }

  function open() {
    var m = panel();
    if (!m) return;
    m.hidden = false;
    document.documentElement.classList.add('about-open');
  }

  function close() {
    var m = panel();
    if (!m) return;
    m.hidden = true;
    document.documentElement.classList.remove('about-open');
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    if (t.closest('[data-about-close]')) { close(); return; }
    var opener = t.closest('[data-about-open]');
    if (opener) { e.preventDefault(); open(); return; }
    var m = panel();
    if (m && !m.hidden && t === m) close();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') close();
  });
})();
