// LightGroup · 左侧会话列表：搜索、未读、最后一条摘要
import { S, h, icon, api, btn, convTime, go, emit, siteName, siteMark, applyLayout, openSheet, closeSheet, toast } from './core.js';
import { avatar } from './avatar.js';
import { unreadCount } from './notices.js';
import { canScan } from './qr.js';

let listHost = null;

// 当前页面属于哪个群：/g/<slug>/app 是子群，/g/app 是主群（slug 为空）
function scopeSlug() {
  const m = String(location.pathname || '').match(/^\/g\/([^/]+)\//);
  return m ? decodeURIComponent(m[1]) : '';
}

export function publicConv() {
  return (S.convs || []).find(function (c) { return c && (c.isPublic || c.kind === 'group'); }) || null;
}
export function convById(id) {
  return (S.convs || []).find(function (c) { return c && String(c.id) === String(id); }) || null;
}
export function convName(c) {
  if (!c) return siteName();
  if (c.kind === 'dm') return (c.member && c.member.name) || '私聊';
  return c.title || siteName();
}
export function convPeer(c) { return (c && c.kind === 'dm' && c.member) || null; }
export function lastLine(c) {
  const last = c && c.last;
  if (!last) return '还没有消息';
  // text 里可能带着服务端补的「[图片](地址)」媒体行（见 Compat::fetchSince 的 text_auto），预览里剔掉
  const clean = String(last.text || '').split('\n')
    .filter(function (line) { return !/^\[(图片|语音|卡片)\]\(/.test(line.trim()); }).join(' ').trim();
  const body = last.recalled ? '消息已撤回'
    : (clean || (last.img ? '[图片]' : (last.voice ? '[语音]' : (last.card ? '[卡片]' : '新消息'))));
  if (c.kind !== 'dm' && last.name) return last.name + '：' + body;
  return body;
}
function lastTs(c) { return (c && c.last && c.last.ts) || 0; }

// 兜底：conversations 还没上线时，至少有一个公开大群可进
function legacyList() {
  const id = (S.me && S.me.publicConv) ? S.me.publicConv : 0;
  return [{ id: id, kind: 'group', title: siteName(), isPublic: 1, last: null, unread: 0 }];
}

export async function load() {
  const j = await api('api/conversations');
  if (j && j.ok && Array.isArray(j.convs) && j.convs.length) {
    S.convApi = true;
    S.convs = j.convs;
  } else if (j && j.ok && Array.isArray(j.convs)) {
    S.convApi = true;
    S.convs = j.convs;
  } else {
    S.convApi = false;
    S.convs = legacyList();
  }
  S.convsReady = true;
  paint();
  emit('convs');
  return S.convs;
}

// ---------- 搜索框右侧：站内信铃铛 + 加号 ----------

let bellEl = null;

function paintBell() {
  if (!bellEl) return;
  const n = unreadCount();
  const old = bellEl.querySelector('.req-badge');
  if (old) old.remove();
  if (n > 0) bellEl.appendChild(h('span', 'req-badge', n > 99 ? '99+' : String(n)));
}

// 站内信数字变了就改铃铛上的角标，不重画列表
window.addEventListener('lg:notices', function () { paintBell(); });

function searchActs() {
  const box = h('div', 'conv-search-acts');
  const bell = document.createElement('button');
  bell.type = 'button';
  bell.className = 'conv-ico conv-bell';
  bell.title = '站内信';
  bell.setAttribute('aria-label', '站内信');
  bell.appendChild(icon('fa-bell'));
  bell.addEventListener('click', function () { go('#/notices'); });
  bellEl = bell;
  paintBell();
  box.appendChild(bell);

  const add = document.createElement('button');
  add.type = 'button';
  add.className = 'conv-ico conv-add';
  add.title = '新建';
  add.setAttribute('aria-label', '新建');
  add.appendChild(icon('fa-circle-plus'));
  add.addEventListener('click', function (ev) {
    ev.stopPropagation();
    openNewMenu(add);
  });
  box.appendChild(add);
  return box;
}

function openNewMenu(anchor) {
  const old = document.querySelector('.conv-menu');
  if (old) old.remove();
  const menu = h('div', 'rail-menu conv-menu');
  // 扫一扫只在能扫码的设备上出现：桌面浏览器没有摄像头入口，菜单里就别摆一项点不动的
  const items = [];
  if (canScan()) items.push(['扫一扫', 'fa-qrcode', function () { go('#/scan'); }]);
  items.push(['添加好友', 'fa-user-plus', openFindFriend]);
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
  menu.style.top = Math.round(r.bottom + 6) + 'px';
  menu.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
  setTimeout(function () {
    document.addEventListener('click', function onDoc(ev) {
      if (!document.querySelector('.conv-menu')) { document.removeEventListener('click', onDoc); return; }
      if (!menu.contains(ev.target)) { menu.remove(); document.removeEventListener('click', onDoc); }
    });
  }, 0);
}

function openFindFriend() {
  const wrap = h('div', 'conv-findbox');
  const row = h('div', 'dc-search');
  row.appendChild(icon('fa-magnifying-glass'));
  const inp = document.createElement('input');
  inp.className = 'tb';
  inp.type = 'search';
  inp.placeholder = '输入对方的 uid';
  inp.autocomplete = 'off';
  row.appendChild(inp);
  const goBtn = btn('搜索', 'btn-primary btn-sm', run);
  row.appendChild(goBtn);
  wrap.appendChild(row);
  const box = h('div', 'conv-findlist');
  wrap.appendChild(box);
  let reqId = 0;

  async function run() {
    const q = String(inp.value || '').trim();
    if (!q) { toast('填对方的 uid'); return; }
    const my = ++reqId;
    box.textContent = '';
    box.appendChild(h('div', 'sp-empty', '搜索中…'));
    const j = await api('api/discover?q=' + encodeURIComponent(q));
    if (my !== reqId) return;
    box.textContent = '';
    if (!j || !j.ok) { box.appendChild(h('div', 'sp-empty', (j && j.err) || '搜索失败')); return; }
    const items = j.items || [];
    if (!items.length) { box.appendChild(h('div', 'sp-empty', j.hint || '没有找到这个 uid 的人')); return; }
    items.forEach(function (it) {
      const u = document.createElement('button');
      u.type = 'button';
      u.className = 'm-row conv-find-row';
      u.appendChild(avatar(it, 38));
      const info = h('div', 'm-row-i');
      const nm = h('div', 'm-row-n');
      nm.appendChild(h('b', '', it.name || '匿名'));
      nm.appendChild(h('span', 'conv-find-at', '@' + it.uid));
      info.appendChild(nm);
      info.appendChild(h('div', 'm-row-s', it.signature || ''));
      u.appendChild(info);
      u.appendChild(h('span', 'conv-find-go', '看看'));
      u.addEventListener('click', function () {
        closeSheet();
        go('#/u/' + it.uid);
      });
      box.appendChild(u);
    });
  }

  inp.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') run(); });
  openSheet('添加好友', wrap, { size: 'sm' });
  setTimeout(function () { inp.focus(); }, 60);
}

// ---------- 视图 ----------
export function mount(host) {
  listHost = host;
  host.textContent = '';
  const search = h('div', 'conv-search');
  const sbox = h('div', 'conv-search-box');
  sbox.appendChild(icon('fa-magnifying-glass'));
  const inp = document.createElement('input');
  inp.className = 'tb conv-find';
  inp.type = 'search';
  inp.placeholder = '搜索会话';
  inp.value = S.search || '';
  inp.addEventListener('input', function () {
    S.search = inp.value;
    paint();
  });
  sbox.appendChild(inp);
  search.appendChild(sbox);
  search.appendChild(searchActs());
  host.appendChild(search);
  const list = h('div', 'conv-list');
  list.id = 'conv-list';
  host.appendChild(list);
  paint();
}

export function repaint() { paint(); }

/** 收到撤回通知时，本地把会话列表里的那条摘要立即改掉，不用等下一次 /api/conversations */
export function markRecalled(id) {
  const mid = Number(id) || 0;
  if (!mid) return false;
  let hit = false;
  (S.convs || []).forEach(function (c) {
    const last = c && c.last;
    if (!last || Number(last.id) !== mid || last.recalled) return;
    last.recalled = true;
    last.text = '';
    last.img = '';
    last.voice = '';
    hit = true;
  });
  if (hit) paint();
  return hit;
}

export function paint() {
  const list = document.getElementById('conv-list');
  if (!list) return;
  list.textContent = '';
  const q = String(S.search || '').trim().toLowerCase();
  const items = (S.convs || []).filter(function (c) {
    if (!q) return true;
    return convName(c).toLowerCase().indexOf(q) >= 0 || lastLine(c).toLowerCase().indexOf(q) >= 0;
  });
  if (!items.length) {
    list.appendChild(h('div', 'empty-hint', q ? '没有匹配的会话' : '还没有会话'));
    return;
  }
  items.forEach(function (c) { list.appendChild(item(c)); });
}

function item(c) {
  const active = S.page === 'chat' && String(S.curId) === String(c.id);
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'conv-item' + (active ? ' is-active' : '');
  b.dataset.id = String(c.id);

  const av = h('span', 'conv-avatar');
  if (c.kind === 'dm' && c.member) av.appendChild(avatar(c.member, 44));
  else if (c.kind === 'pg') av.appendChild(avatar({ id: 'pg' + c.id, name: convName(c) }, 44));
  else if (c.scope) av.appendChild(avatar({ id: 'scope' + c.scope, name: convName(c) }, 44));
  else av.appendChild(siteMark('conv-mark'));
  b.appendChild(av);

  const meta = h('span', 'conv-meta');
  const top = h('span', 'conv-top');
  top.appendChild(h('span', 'conv-name', convName(c)));
  if (c.pinned) top.appendChild(h('span', 'conv-cross', '置顶'));
  if (c.scope && String(c.slug || '') !== scopeSlug()) top.appendChild(h('span', 'conv-cross', '其它群'));
  top.appendChild(h('span', 'conv-time', convTime(lastTs(c))));
  meta.appendChild(top);
  const sub = h('span', 'conv-sub');
  sub.appendChild(h('span', 'conv-last', lastLine(c)));
  if (c.unread > 0) sub.appendChild(h('span', 'unread-dot', c.unread > 99 ? '99+' : String(c.unread)));
  meta.appendChild(sub);
  b.appendChild(meta);

  b.addEventListener('click', function () { openConv(c); });
  return b;
}

export function openConv(c) {
  if (!c) return;
  // 这个会话在别的群：换成那个群的地址再打开，本页继续切会话只会 404
  const slug = String(c.slug || '');
  if (c.scope && slug !== scopeSlug()) {
    // 主群（slug 为空）挂在 /app 上，子群挂在 /g/<slug>/app 上
    const base = slug ? '/g/' + encodeURIComponent(slug) + '/app' : '/app';
    location.href = base + '#/c/' + c.id;
    return;
  }
  if (c.unread) { c.unread = 0; }
  S.listOpen = false;
  applyLayout();
  go('#/c/' + c.id);
  if (String(S.curId) === String(c.id)) paint();
}

// 有新消息/新会话时，把列表里那条的摘要和未读同步一下
export function noteLast(convId, m) {
  const c = convById(convId);
  if (!c) return;
  c.last = { id: m.id, name: m.name, text: m.text || '', img: m.img || '', voice: m.voice || '', recalled: m.recalled ? 1 : 0, ts: m.ts };
  paint();
}
