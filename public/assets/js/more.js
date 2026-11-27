// LightGroup · 更多：站点级入口（站内信、空间、发现、扫一扫）。个人相关的都在「我的」。
import { S, h, icon, go, pageHead } from './core.js';
import { canScan } from './qr.js';
import { unreadCount } from './notices.js';

// 站点级功能开关，跟群功能权限组是两回事
function feats() {
  return (S.me && S.me.features) || {};
}

function entry(ic, title, sub, fn, danger) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'me-entry' + (danger ? ' me-entry-danger' : '');
  b.appendChild(icon(ic));

  const txt = h('span', 'me-entry-txt');
  txt.appendChild(h('b', '', title));
  txt.appendChild(h('span', '', sub || ''));
  b.appendChild(txt);
  const chev = icon('fa-chevron-right');
  chev.classList.add('me-chev');
  b.appendChild(chev);
  b.addEventListener('click', fn);
  return b;
}

// 更多页可能比 bootChat 的未读查询先画出来，角标要能在数字回来后补上
let mailRow = null;

function paintMailBadge() {
  if (!mailRow) return;
  const old = mailRow.querySelector('.req-badge');
  if (old) old.remove();
  const n = unreadCount();
  if (n > 0) mailRow.appendChild(h('span', 'req-badge', n > 99 ? '99+' : String(n)));
}

window.addEventListener('lg:notices', function () { paintMailBadge(); });

export function renderMore(host) {
  host.textContent = '';
  const page = h('div', 'chat-page');
  page.appendChild(pageHead('更多', '站内信、空间、发现与扫一扫'));
  const body = h('div', 'page-body');
  page.appendChild(body);
  host.appendChild(page);

  const f = feats();
  const space = f.space || {};
  const social = f.social || {};
  const grid = h('div', 'me-grid');

  // 站内信跟站点开关无关：好友申请、群通知都要能到
  const mail = entry('fa-envelope', '站内信', '好友申请、群通知和系统提醒', function () { go('#/notices'); });
  grid.appendChild(mail);
  mailRow = mail;
  paintMailBadge();

  if (space.enabled !== false) {
    grid.appendChild(entry('fa-compass', '空间', '群里的朋友圈', function () { go('#/space'); }));
  }
  if (social.discover !== false) {
    grid.appendChild(entry('fa-wand-magic-sparkles', '发现', '按资料找到聊得来的人', function () { go('#/discover'); }));
  }
  if (social.scan !== false && canScan()) {
    grid.appendChild(entry('fa-qrcode', '扫一扫', '扫二维码加好友、进群', function () { go('#/scan'); }));
  }
  body.appendChild(grid);
}
