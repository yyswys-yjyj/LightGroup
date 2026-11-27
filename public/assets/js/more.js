// LightGroup · 更多：站点级入口（空间、发现、扫一扫）。个人相关的都在「我的」。
import { S, h, icon, go, pageHead } from './core.js';
import { canScan } from './qr.js';

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
  b.appendChild(icon('fa-chevron-right'));
  b.addEventListener('click', fn);
  return b;
}

export function renderMore(host) {
  host.textContent = '';
  const page = h('div', 'chat-page');
  page.appendChild(pageHead('更多', '空间、发现与扫一扫'));
  const body = h('div', 'page-body');
  page.appendChild(body);
  host.appendChild(page);

  const f = feats();
  const space = f.space || {};
  const social = f.social || {};
  const grid = h('div', 'me-grid');

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
