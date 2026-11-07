// LightGroup · 左侧会话列表：搜索、未读、最后一条摘要
import { S, h, icon, api, convTime, go, emit, siteName, siteMark, applyLayout } from './core.js';
import { avatar } from './avatar.js';

let listHost = null;

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

// 老接口兜底：conversations 还没上线时，至少有一个公开大群可进
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

// ---------- 视图 ----------
export function mount(host) {
  listHost = host;
  host.textContent = '';
  const search = h('div', 'conv-search');
  search.appendChild(icon('fa-magnifying-glass'));
  const inp = document.createElement('input');
  inp.className = 'tb conv-find';
  inp.type = 'search';
  inp.placeholder = '搜索会话';
  inp.value = S.search || '';
  inp.addEventListener('input', function () {
    S.search = inp.value;
    paint();
  });
  search.appendChild(inp);
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
  else av.appendChild(siteMark('conv-mark'));
  b.appendChild(av);

  const meta = h('span', 'conv-meta');
  const top = h('span', 'conv-top');
  top.appendChild(h('span', 'conv-name', convName(c)));
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
