// LightGroup · 好友、成员、加好友、申请处理
import { S, h, icon, btn, field, toast, api, pageHead, go, openSheet, closeSheet } from './core.js';
import { avatar } from './avatar.js';
import * as conv from './conv.js';
import { initProfile, openUserCard } from './profile.js';

let hostRef = null;
let kind = 'friends';
let tab = 'friends';
// 联系人页的分栏：好友 / 群聊（群聊里是私群）
function contactNav(active) {
  const nav = h('div', 'friend-tabs');
  [['friends', '好友'], ['groups', '群聊']].forEach(function (d) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'friend-tab' + (active === d[0] ? ' on' : '');
    b.textContent = d[1];
    b.addEventListener('click', function () {
      if (active !== d[0]) go(d[0] === 'groups' ? '#/groups' : '#/friends');
    });
    nav.appendChild(b);
  });
  return nav;
}
const FRIENDS_LOADED = { v: false };
const GROUPS = { v: false };

export function isMe(m) { return !!(m && S.me && String(m.id) === String(S.me.id)); }

export function isFriend(id) {
  return (S.friends.friends || []).some(function (m) { return String(m.id) === String(id); });
}
export function friendState(id) {
  if (isFriend(id)) return 'friend';
  if ((S.friends.incoming || []).some(function (m) { return String(m.id) === String(id); })) return 'incoming';
  if ((S.friends.outgoing || []).some(function (m) { return String(m.id) === String(id); })) return 'outgoing';
  return 'none';
}
export function pendingCount() { return (S.friends.incoming || []).length; }

export function memberSub(m) {
  if (m.kind === 'ai') return m.owner ? 'AI · ' + m.owner + ' 的' : 'AI';
  if (isMe(m)) return '群成员 · 你';
  if (isFriend(m.id)) return '好友 · 群成员';
  return '群成员';
}

// ---------- 数据 ----------
export async function loadFriends() {
  const j = await api('api/friends');
  if (j && j.ok) {
    S.friends = {
      friends: j.friends || [],
      incoming: j.incoming || [],
      outgoing: j.outgoing || [],
    };
    FRIENDS_LOADED.v = true;
  } else {
    S.friends = S.friends || { friends: [], incoming: [], outgoing: [] };
  }
  emitFriends();
  return j;
}
export function friendsLoaded() { return FRIENDS_LOADED.v; }

function emitFriends() { try { window.dispatchEvent(new CustomEvent('lg:friends')); } catch (e) { void e; } }

export async function loadMembers() {
  const j = await api('api/me');
  if (j && j.ok) {
    if (j.me) S.me = j.me;
    if (j.members) S.members = j.members;
    if (typeof j.pending === 'number') S.pending = j.pending;
  }
  return j;
}

// ---------- 加好友 / 申请处理 ----------
export async function addFriend(member) {
  const id = member && (member.id || member.name);
  if (!id) return;
  const j = await api('api/friends/request', member.id ? { id: member.id } : { name: member.name });
  if (!j.ok) {
    toast(j.err || '申请失败');
    if (/已经申请过|已经是好友/.test(String(j.err || ''))) loadFriends();
    return;
  }
  toast('好友申请已发出');
  await loadFriends();
  repaint();
}

// forId：这条关系挂在自己的某个 AI 上（主人代管），缺省就是自己
function friendBody(id, forId) {
  const b = { id: id };
  if (forId) b.for = forId;
  return b;
}

export async function acceptFriend(id, forId) {
  const j = await api('api/friends/accept', friendBody(id, forId));
  if (!j.ok) { toast(j.err || '操作失败'); return; }
  toast(forId ? '已替 AI 加为好友' : '已加为好友');
  await loadFriends();
  repaint();
}

export async function rejectFriend(id, forId) {
  const j = await api('api/friends/reject', friendBody(id, forId));
  if (!j.ok) { toast(j.err || '操作失败'); return; }
  await loadFriends();
  repaint();
}

export async function removeFriend(id, forId) {
  const j = await api('api/friends/remove', friendBody(id, forId));
  if (!j.ok) { toast(j.err || '操作失败'); return; }
  toast('已移除好友');
  closeSheet();
  await loadFriends();
  repaint();
}

// ---------- 开私聊 ----------
export async function openDm(member) {
  if (!member || !member.id) return;
  const j = await api('api/dm/open', { id: member.id });
  if (!j.ok) { toast(j.err || '开不了私聊'); return; }
  closeSheet();
  await conv.load();
  let c = j.conv ? conv.convById(j.conv.id) : null;
  if (!c && j.conv) {
    c = j.conv;
    S.convs.push(c);
    conv.repaint();
  }
  if (!c) return;
  conv.openConv(c);
}

// ---------- 资料卡 ----------
// 悬浮卡本体在 profile.js 里（markdown 渲染 + 资料页都在那边），这里只做转发。
export function openProfileCard(member, anchor) {
  if (!member) return;
  openUserCard(member, anchor || null);
}

// 全局头像点击：聊天消息、成员列表、好友列表、私聊标题栏都走这里。
// 用捕获阶段拦下来，列表行的 click（打开同一个卡）就不会重复触发。
function bindAvatarClick() {
  document.addEventListener('click', function (e) {
    const t = e.target;
    const av = t && t.closest ? t.closest('.ava[data-uid]') : null;
    if (!av) return;
    // 外框栏里的自己、会话列表整项、资料卡内部、sheet 里都不弹
    if (av.closest('.rail-me') || av.closest('.im-rail') || av.closest('.conv-item')) return;
    if (av.closest('.pf-pop') || av.closest('.pf-page') || av.closest('#sheet')) return;
    e.stopPropagation();
    const uid = av.getAttribute('data-uid');
    if (!uid) return;
    const seed = {
      id: uid,
      name: av.getAttribute('data-name') || '',
      kind: av.getAttribute('data-kind') || ''
    };
    openUserCard(seed, av);
  }, true);
}

// ---------- 页面 ----------
export function renderFriends(host) {
  hostRef = host;
  kind = 'friends';
  paintFriends();
  loadFriends().then(function () { if (kind === 'friends') paintFriends(); });
}

export function renderGroups(host) {
  hostRef = host;
  kind = 'groups';
  paintGroups();
  loadGroups().then(function () { if (kind === 'groups') paintGroups(); });
}

export function renderMembers(host) {
  hostRef = host;
  kind = 'members';
  paintMembers();
  loadMembers().then(function () { if (kind === 'members') paintMembers(); });
}

function repaint() {
  if (!hostRef) return;
  if (kind === 'friends') paintFriends();
  else if (kind === 'groups') paintGroups();
  else paintMembers();
}

function paintFriends() {
  const host = hostRef;
  if (!host) return;
  host.textContent = '';
  const page = h('div', 'chat-page');
  const total = (S.friends.friends || []).length;
  page.appendChild(pageHead('联系人', total ? '共 ' + total + ' 位好友' : '还没有好友'));
  page.appendChild(contactNav('friends'));

  const tabs = h('div', 'friend-tabs');
  const defs = [
    ['friends', '好友', total],
    ['incoming', '待处理', (S.friends.incoming || []).length],
    ['outgoing', '我发出的', (S.friends.outgoing || []).length]
  ];
  defs.forEach(function (d) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'friend-tab' + (tab === d[0] ? ' on' : '');
    b.appendChild(document.createTextNode(d[1]));
    // 只有「待处理 / 我发出的」是通知，才挂数字；「好友」那个数字是总数，
    // 不是待办，挂红点就会永远消不掉（页头已经写了「共 N 位好友」）
    if (d[2] && d[0] !== 'friends') {
      b.appendChild(h('span', 'req-badge' + (d[0] === 'incoming' ? '' : ' quiet'), String(d[2])));
    }
    b.addEventListener('click', function () { tab = d[0]; paintFriends(); });
    tabs.appendChild(b);
  });
  page.appendChild(tabs);

  const body = h('div', 'page-body');
  page.appendChild(body);
  host.appendChild(page);

  if (tab === 'friends') {
    const list = S.friends.friends || [];
    if (!list.length) { body.appendChild(h('div', 'empty-hint', '还没有好友。到成员页点「加好友」，对方同意就能私聊了。')); return; }
    list.forEach(function (m) { body.appendChild(friendRow(m, 'friend')); });
  } else if (tab === 'incoming') {
    const list = S.friends.incoming || [];
    if (!list.length) { body.appendChild(h('div', 'empty-hint', '没有待处理的好友申请')); return; }
    list.forEach(function (m) { body.appendChild(friendRow(m, 'incoming')); });
  } else {
    const list = S.friends.outgoing || [];
    if (!list.length) { body.appendChild(h('div', 'empty-hint', '你还没有发出好友申请')); return; }
    list.forEach(function (m) { body.appendChild(friendRow(m, 'outgoing')); });
  }
}

// ---------- 群聊（私群） ----------
// 列表来自 api/groups：{groups:[{id,title,role,members,ownerId,pinned,notice,last,unread,lastAt,createdAt}], caps}
export async function loadGroups() {
  const j = await api('api/groups');
  if (j && j.ok) {
    S.groups = { groups: j.groups || [], caps: j.caps || {}, perm: j.perm || {} };
    GROUPS.v = true;
  } else {
    S.groups = S.groups || { groups: [], caps: {} };
  }
  return j;
}
export function groupsLoaded() { return GROUPS.v; }

function paintGroups() {
  const host = hostRef;
  if (!host) return;
  host.textContent = '';
  const gs = (S.groups && S.groups.groups) || [];
  const page = h('div', 'chat-page');
  page.appendChild(pageHead('联系人', gs.length ? '群聊 · ' + gs.length + ' 个' : '群聊'));
  page.appendChild(contactNav('groups'));
  const body = h('div', 'page-body');
  page.appendChild(body);
  host.appendChild(page);

  const canCreate = !S.groups || !S.groups.perm || S.groups.perm.create !== false;
  if (canCreate) {
    body.appendChild(btn('＋ 创建群聊', 'btn btn-primary btn-block', openCreateGroup));
    body.appendChild(h('div', 'page-tip', '至少拉 3 个人或 AI 才能建群。'));
  } else {
    body.appendChild(h('div', 'page-tip', '这个群没有开放创建私群。'));
  }
  if (!gs.length) {
    body.appendChild(h('div', 'empty-hint', '还没有群聊。'));
    return;
  }
  const card = h('div', 'list-card');
  gs.forEach(function (g) { card.appendChild(groupRow(g)); });
  body.appendChild(card);
}

function groupRow(g) {
  const row = h('div', 'm-row');
  row.appendChild(avatar({ id: 'pg' + g.id, name: g.title || '群聊' }, 42));
  const info = h('div', 'm-row-i');
  const nm = h('div', 'm-row-n');
  nm.appendChild(h('b', '', g.title || '未命名群聊'));
  if (g.role === 'owner') nm.appendChild(h('span', 'badge', '群主'));
  else if (g.role === 'admin') nm.appendChild(h('span', 'badge', '管理员'));
  if (g.pinned) nm.appendChild(h('span', 'tag-ai', '置顶'));
  info.appendChild(nm);
  let sub;
  if (g.last && g.last.text) sub = (g.last.name ? g.last.name + '：' : '') + g.last.text;
  else sub = (g.members || 0) + ' 人';
  info.appendChild(h('div', 'm-row-s', sub));
  row.appendChild(info);
  if (g.unread) row.appendChild(h('span', 'unread-dot', g.unread > 99 ? '99+' : String(g.unread)));
  row.addEventListener('click', function () { go('#/pg/' + g.id); });
  return row;
}

function openCreateGroup() {
  const wrap = h('div', 'pg-create');
  const nameInp = document.createElement('input');
  nameInp.className = 'tb';
  nameInp.type = 'text';
  nameInp.maxLength = 24;
  nameInp.placeholder = '群名，比如「今晚吃啥」';
  field(wrap, '群名', function (row) { row.appendChild(nameInp); });

  const picked = {};
  const list = h('div', 'pg-pick');
  const candidates = (S.members || []).filter(function (m) { return !isMe(m); });
  field(wrap, '拉谁进群', function (row) {
    if (!candidates.length) { row.appendChild(h('div', 'page-tip', '这个群里还没有别人')); return; }
    candidates.forEach(function (m) {
      const item = h('button', 'pg-pick-row');
      item.type = 'button';
      item.appendChild(avatar(m, 30));
      const nm = h('span', 'pg-pick-n');
      nm.appendChild(document.createTextNode(m.name || '匿名'));
      if (m.kind === 'ai') nm.appendChild(h('span', 'tag-ai', 'AI'));
      item.appendChild(nm);
      item.appendChild(icon('fa-check'));
      item.addEventListener('click', function () {
        if (picked[m.id]) { delete picked[m.id]; item.classList.remove('on'); }
        else { picked[m.id] = true; item.classList.add('on'); }
        refresh();
      });
      list.appendChild(item);
    });
    row.appendChild(list);
  });

  const tip = h('div', 'page-tip', '至少选 3 位');
  wrap.appendChild(tip);
  const ok = btn('创建群聊', 'btn btn-primary btn-block', doCreate);
  wrap.appendChild(ok);
  function refresh() {
    const n = Object.keys(picked).length;
    tip.textContent = n ? '已选 ' + n + ' 位' + (n < 3 ? '，还差 ' + (3 - n) + ' 位' : '') : '至少选 3 位';
    tip.classList.toggle('warn', n > 0 && n < 3);
  }
  async function doCreate() {
    const title = nameInp.value.trim();
    const ids = Object.keys(picked);
    if (!title) { toast('给群起个名字'); return; }
    if (ids.length < 3) { toast('至少拉 3 个人或 AI 才能建群'); return; }
    ok.disabled = true;
    const j = await api('api/groups/create', { title: title, members: ids });
    ok.disabled = false;
    if (!j.ok) { toast(j.err || '建群失败'); return; }
    closeSheet();
    await loadGroups();
    if (kind === 'groups') paintGroups();
    await conv.load();
    go('#/pg/' + (j.id || 0));
  }
  openSheet('创建群聊', wrap, { size: 'md' });
}

// 这条关系挂在自己的哪个 AI 上（主人代管），回名字
function aiNameOf(forRaw) {
  if (!forRaw) return '';
  const id = String(forRaw).replace(/^a_/, '');
  const ai = (S.members || []).filter(function (x) { return String(x.id) === id; })[0];
  return ai ? (ai.name || 'AI') : 'AI';
}

function friendRow(m, mode) {
  const row = h('div', 'friend-row');
  row.appendChild(avatar(m, 42));
  const info = h('div', 'm-row-i');
  const nm = h('div', 'm-row-n');
  const rm = mode === 'friend' ? String(m.remark || '') : '';
  nm.appendChild(h('b', '', rm || m.name || '匿名'));
  if (rm) nm.appendChild(h('span', 'badge', '备注'));
  if (m.kind === 'ai') nm.appendChild(h('span', 'tag-ai', 'AI'));
  if (m.for) nm.appendChild(h('span', 'badge', '你的 AI'));
  info.appendChild(nm);
  let sub;
  if (mode === 'incoming') sub = m.for ? '想加你的 AI「' + aiNameOf(m.for) + '」为好友' : '想加你为好友';
  else if (mode === 'outgoing') sub = m.for ? '你的 AI「' + aiNameOf(m.for) + '」申请中' : '等待对方同意';
  else sub = m.for ? '你的 AI「' + aiNameOf(m.for) + '」的好友' : memberSub(m);
  info.appendChild(h('div', 'm-row-s', sub));
  row.appendChild(info);

  const acts = h('div', 'row-acts');
  if (mode === 'friend') {
    const b1 = btn('发消息', 'btn btn-sm btn-primary', function () { openDm(m); });
    acts.appendChild(b1);
  } else if (mode === 'incoming') {
    acts.appendChild(btn('同意', 'btn btn-sm btn-primary', function () { acceptFriend(m.id, m.for); }));
    acts.appendChild(btn('拒绝', 'btn btn-sm btn-ghost', function () { rejectFriend(m.id, m.for); }));
  } else {
    acts.appendChild(btn('撤回', 'btn btn-sm btn-ghost', function () { rejectFriend(m.id, m.for); }));
  }
  row.appendChild(acts);
  row.addEventListener('click', function (e) {
    if (e.target.closest('button')) return;
    if (e.target.closest('.ava')) return;
    openProfileCard(m, row.querySelector('.ava'));
  });
  return row;
}

function paintMembers() {
  const host = hostRef;
  if (!host) return;
  host.textContent = '';
  const page = h('div', 'chat-page');
  page.appendChild(pageHead('成员', '共 ' + (S.members || []).length + ' 位'));
  const body = h('div', 'page-body');
  page.appendChild(body);
  host.appendChild(page);

  const list = S.members || [];
  if (!list.length) { body.appendChild(h('div', 'empty-hint', '还没有成员')); return; }
  const humans = list.filter(function (m) { return m.kind !== 'ai'; });
  const ais = list.filter(function (m) { return m.kind === 'ai'; });
  section(body, '群成员 · ' + humans.length, humans);
  section(body, 'AI · ' + ais.length, ais);
}

function section(body, label, list) {
  if (!list.length) return;
  body.appendChild(h('div', 'page-sec', label));
  const card = h('div', 'list-card');
  list.forEach(function (m) {
    const it = h('div', 'm-row');
    it.appendChild(avatar(m, 40));
    const info = h('div', 'm-row-i');
    const nm = h('div', 'm-row-n');
    nm.appendChild(h('b', '', m.name || '匿名'));
    if (m.kind === 'ai') nm.appendChild(h('span', 'tag-ai', 'AI'));
    if (m.badge === 'super') nm.appendChild(h('span', 'badge', '超级管理员'));
    else if (m.badge === 'admin') nm.appendChild(h('span', 'badge', '管理员'));
    if (isMe(m)) nm.appendChild(h('span', 'badge', '我'));
    info.appendChild(nm);
    info.appendChild(h('div', 'm-row-s', memberSub(m)));
    it.appendChild(info);

    if (!isMe(m)) {
      const acts = h('div', 'row-acts');
      const st = friendState(m.id);
      if (st === 'friend') acts.appendChild(btn('发消息', 'btn btn-xs btn-primary', function () { openDm(m); }));
      else if (st === 'outgoing') acts.appendChild(btn('已申请', 'btn btn-xs btn-ghost', function () { toast('已经申请过了'); }));
      else if (st === 'incoming') {
        // 可能是自己的 AI 收到申请：带上 for，才替对应 AI 处理
        const inc = (S.friends.incoming || []).filter(function (x) { return String(x.id) === String(m.id); })[0];
        acts.appendChild(btn('同意', 'btn btn-xs btn-primary', function () { acceptFriend(m.id, inc && inc.for); }));
      }
      else if (m.kind !== 'ai') acts.appendChild(btn('加好友', 'btn btn-xs btn-ghost', function () { addFriend(m); }));
      if (acts.children.length) it.appendChild(acts);
    }
    it.addEventListener('click', function (e) {
      if (e.target.closest('button')) return;
      if (e.target.closest('.ava')) return;
      openProfileCard(m, it.querySelector('.ava'));
    });
    card.appendChild(it);
  });
  body.appendChild(card);
}

// 把好友/私聊那些能力注给 profile.js（它不能反过来 import 这里，不然会成环）
initProfile({
  openDm: openDm,
  addFriend: addFriend,
  acceptFriend: acceptFriend,
  rejectFriend: rejectFriend,
  removeFriend: removeFriend,
  loadMembers: loadMembers,
  friendState: friendState,
  isMe: isMe,
  isFriend: isFriend,
  memberSub: memberSub
});
bindAvatarClick();
