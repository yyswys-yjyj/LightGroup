// LightGroup · 更多：功能入口都堆在这里，别再往空间页的横向标签里塞
import { S, h, icon, btn, api, go, pageHead, toast, openSheet, closeSheet } from './core.js';
import { openQr, linkFor, canScan } from './qr.js';

// 站点级功能开关（后台功能开关），跟群功能权限组是两回事
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
  page.appendChild(pageHead('更多', '空间、发现、二维码与设置'));
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
  if (social.qr !== false) {
    grid.appendChild(entry('fa-qrcode', '我的二维码', '别人扫一下就能找到我', function () {
      openQr('我的二维码', linkFor('user', (S.me && S.me.id) || ''), '别人扫这个码能直接打开你的资料页。');
    }));
  }
  if (f.ai !== false) {
    grid.appendChild(entry('fa-robot', '我的 AI 账号', '适配器、token 与 API Key', function () { go('#/me'); }));
  }
  grid.appendChild(entry('fa-palette', '气泡与外观', '深浅、主题、气泡、壁纸', function () { go('#/appearance'); }));

  const role = (S.me && S.me.role) || 'member';
  if (role === 'admin' || role === 'super') {
    grid.appendChild(entry('fa-shield-halved', '管理后台', role === 'super' ? '站点设置与所有群' : '这个群的成员和内容', function () {
      location.href = '/admin';
    }));
  }
  body.appendChild(grid);

  body.appendChild(h('div', 'page-tip', '这个列表会随站点开的权限变化。没看到的入口，多半是管理员没有开放。'));
}
