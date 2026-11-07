// LightGroup · 好友、成员、加好友、申请处理
import { S, h, icon, btn, toast, api, pageHead, go, openSheet, closeSheet } from './core.js';
import { avatar } from './avatar.js';
import * as conv from './conv.js';
import { initProfile, openUserCard } from './profile.js';

let hostRef = null;
let kind = 'friends';
let tab = 'friends';
const FRIENDS_LOADED = { v: false };

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

export async function acceptFriend(id) {
  const j = await api('api/friends/accept', { id: id });
  if (!j.ok) { toast(j.err || '操作失败'); return; }
  toast('已加为好友');
  await loadFriends();
  repaint();
}

export async function rejectFriend(id) {
  const j = await api('api/friends/reject', { id: id });
  if (!j.ok) { toast(j.err || '操作失败'); return; }
  await loadFriends();
  repaint();
}

export async function removeFriend(id) {
  const j = await api('api/friends/remove', { id: id });
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

export function renderMembers(host) {
  hostRef = host;
  kind = 'members';
  paintMembers();
  loadMembers().then(function () { if (kind === 'members') paintMembers(); });
}

function repaint() {
  if (!hostRef) return;
  if (kind === 'friends') paintFriends();
  else paintMembers();
}

function paintFriends() {
  const host = hostRef;
  if (!host) return;
  host.textContent = '';
  const page = h('div', 'chat-page');
  const total = (S.friends.friends || []).length;
  page.appendChild(pageHead('好友', total ? '共 ' + total + ' 位好友' : '还没有好友'));

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

function friendRow(m, mode) {
  const row = h('div', 'friend-row');
  row.appendChild(avatar(m, 42));
  const info = h('div', 'm-row-i');
  const nm = h('div', 'm-row-n');
  nm.appendChild(h('b', '', m.name || '匿名'));
  if (m.kind === 'ai') nm.appendChild(h('span', 'tag-ai', 'AI'));
  info.appendChild(nm);
  info.appendChild(h('div', 'm-row-s', mode === 'incoming' ? '想加你为好友' : (mode === 'outgoing' ? '等待对方同意' : memberSub(m))));
  row.appendChild(info);

  const acts = h('div', 'row-acts');
  if (mode === 'friend') {
    const b1 = btn('发消息', 'btn btn-sm btn-primary', function () { openDm(m); });
    acts.appendChild(b1);
  } else if (mode === 'incoming') {
    acts.appendChild(btn('同意', 'btn btn-sm btn-primary', function () { acceptFriend(m.id); }));
    acts.appendChild(btn('拒绝', 'btn btn-sm btn-ghost', function () { rejectFriend(m.id); }));
  } else {
    acts.appendChild(btn('撤回', 'btn btn-sm btn-ghost', function () { rejectFriend(m.id); }));
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
      else if (st === 'incoming') acts.appendChild(btn('同意', 'btn btn-xs btn-primary', function () { acceptFriend(m.id); }));
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
