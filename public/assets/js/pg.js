// LightGroup · 私群详情：公告、成员、权限操作
import { S, h, icon, iconBtn, btn, field, toast, api, go, openSheet, closeSheet } from './core.js';
import { avatar } from './avatar.js';
import { refreshBars } from './msg.js';
import { openQr, linkFor } from './qr.js';
import * as conv from './conv.js';
import { requireRisk } from './verify.js';

let hostRef = null;
let curId = 0;
let data = null; // { group, members }

const ROLE_TEXT = { owner: '群主', admin: '管理员', member: '成员' };

export function renderGroup(host, id) {
  hostRef = host;
  curId = parseInt(id, 10) || 0;
  data = null;
  host.textContent = '';
  if (!curId) { host.appendChild(h('div', 'empty-hint', '群不存在')); return; }
  host.appendChild(h('div', 'empty-hint', '正在加载群资料…'));
  reload();
}

async function reload() {
  const j = await api('api/groups/' + curId);
  if (!hostRef) return;
  if (!j.ok) {
    hostRef.textContent = '';
    const page = h('div', 'chat-page');
    page.appendChild(head('群资料', ''));
    page.appendChild(h('div', 'empty-hint', j.err || '加载失败'));
    hostRef.appendChild(page);
    return;
  }
  data = { group: j.group || {}, members: j.members || [] };
  paint();
}

// 从会话里点进来的就回会话，从联系人进来的回群聊列表
function backTo() {
  if (S.cur && S.cur.kind === 'pg' && String(S.curId) === String(curId)) return '#/c/' + curId;
  return '#/groups';
}

function head(title, sub, acts) {
  const bar = h('header', 'chat-head');
  bar.appendChild(iconBtn('fa-chevron-left', '返回', function () { go(backTo()); }));
  const t = h('div', 'chat-title');
  t.appendChild(h('b', '', title));
  if (sub) t.appendChild(h('span', 'chat-sub', sub));
  bar.appendChild(t);
  if (acts && acts.length) {
    const box = h('div', 'chat-acts');
    acts.forEach(function (a) { box.appendChild(a); });
    bar.appendChild(box);
  }
  return bar;
}

function isMe(m) { return !!(m && S.me && String(m.id) === String(S.me.id)); }

function groupQr(g) {
  openQr((g.title || '群聊') + ' 的二维码', linkFor('group', curId),
    '群里的人扫这个码直接进群聊；不在群里的人扫了只能看到群资料。');
}

// 非成员：只给群名和人数，别把公告、成员名单漏出去
function paintGuest(g) {
  hostRef.textContent = '';
  const page = h('div', 'chat-page');
  page.appendChild(head(g.title || '群聊', '群资料', []));
  const body = h('div', 'page-body');
  page.appendChild(body);
  hostRef.appendChild(page);

  const card = h('section', 'pg-card');
  const row = h('div', 'pg-guest');
  row.appendChild(avatar({ id: 'pg' + curId, name: g.title || '群聊' }, 56));
  const info = h('div', 'pg-guest-i');
  info.appendChild(h('b', '', g.title || '群聊'));
  info.appendChild(h('span', '', (g.members || 0) + ' 人'));
  row.appendChild(info);
  card.appendChild(row);
  card.appendChild(h('p', 'pg-notice empty', '你不在这个群里。群里的成员拉你进来之后，才能看消息和公告。'));
  body.appendChild(card);

  const ops = h('div', 'pg-ops');
  ops.appendChild(btn('群二维码', 'btn btn-line', function () { groupQr(g); }));
  ops.appendChild(btn('返回', 'btn btn-ghost', function () { go(backTo()); }));
  body.appendChild(ops);
}

// 权限组是群功能开关：后端返回的 perm 里 false 表示这个群关了该功能
function perm(key) {
  const p = (data && data.group && data.group.perm) || {};
  return p[key] !== false;
}

function paint() {
  if (!hostRef || !data) return;
  const g = data.group;
  if (g.isMember === false) { paintGuest(g); return; }
  const members = data.members || [];
  const total = g.members || members.length;
  const role = g.role || 'member';
  hostRef.textContent = '';
  const page = h('div', 'chat-page');
  const acts = [];
  if (g.isOwner && perm('rename')) acts.push(iconBtn('fa-pen', '改群名', renameSheet));
  if (perm('qr')) acts.push(iconBtn('fa-qrcode', '群二维码', function () { groupQr(g); }));
  page.appendChild(head(g.title || '群聊', total + ' 人 · ' + (ROLE_TEXT[role] || '成员'), acts));
  const body = h('div', 'page-body');
  page.appendChild(body);
  hostRef.appendChild(page);

  body.appendChild(noticeCard(g, role, members));
  body.appendChild(memberCard(g, members, role));

  const ops = h('div', 'pg-ops');
  if (role !== 'member' || perm('invite')) ops.appendChild(btn('＋ 邀请成员', 'btn btn-line', inviteSheet));
  if (role !== 'owner') ops.appendChild(btn('退出群聊', 'btn btn-ghost', leaveGroup));
  else ops.appendChild(btn('解散群聊', 'btn btn-danger', dissolveGroup));
  body.appendChild(ops);
}

function noticeCard(g, role, members) {
  const card = h('section', 'pg-card');
  const head2 = h('div', 'pg-card-h');
  head2.appendChild(h('b', '', '群公告'));
  if ((role === 'owner' || role === 'admin') && perm('notice')) {
    head2.appendChild(btn(g.notice ? '编辑' : '发布', 'btn btn-xs btn-ghost', noticeSheet));
  }
  card.appendChild(head2);
  if (g.notice) {
    card.appendChild(h('div', 'pg-notice', g.notice));
    if (g.noticeBy || g.noticeAt) {
      // 后端存的是毫秒；老数据可能是秒，两种都认
      const d = g.noticeAt ? new Date(g.noticeAt > 1e12 ? g.noticeAt : g.noticeAt * 1000) : null;
      const when = d ? (d.getMonth() + 1) + '-' + d.getDate() + ' ' +
        String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0') : '';
      const nid = String(g.noticeBy || '');
      const who = (members || []).filter(function (m) {
        return String(m.id) === nid;
      })[0];
      const by = who ? who.name : '';
      card.appendChild(h('div', 'pg-meta', [by, when].filter(Boolean).join(' · ')));
    }
  } else {
    card.appendChild(h('p', 'pg-notice empty', role === 'owner' || role === 'admin'
      ? '还没有群公告，点「发布」写一条。' : '还没有群公告。'));
  }
  return card;
}

function memberCard(g, members, role) {
  const card = h('section', 'pg-card pg-members');
  const head2 = h('div', 'pg-card-h');
  head2.appendChild(h('b', '', '群成员 ' + (g.members || members.length)));
  card.appendChild(head2);
  if (!members.length) {
    card.appendChild(h('p', 'pg-notice empty', '这个群没有开放成员列表。'));
    return card;
  }
  const list = h('div', 'list-card');
  members.forEach(function (m) { list.appendChild(memberRow(g, m, role)); });
  card.appendChild(list);
  return card;
}

function memberRow(g, m, myRole) {
  const row = h('div', 'm-row');
  row.appendChild(avatar(m, 40));
  const info = h('div', 'm-row-i');
  const nm = h('div', 'm-row-n');
  nm.appendChild(h('b', '', m.name || '匿名'));
  if (m.kind === 'ai') nm.appendChild(h('span', 'tag-ai', 'AI'));
  if (isMe(m)) nm.appendChild(h('span', 'badge', '我'));
  const r = m.role || 'member';
  if (r !== 'member') nm.appendChild(h('span', 'badge', ROLE_TEXT[r] || r));
  if (m.gone) nm.appendChild(h('span', 'tag-ai', '已退出'));
  info.appendChild(nm);
  row.appendChild(info);

  const acts = h('div', 'row-acts');
  const canKick = myRole === 'owner' ? r !== 'owner' : (myRole === 'admin' ? r === 'member' : false);
  if (g.isOwner && perm('admin') && r !== 'owner' && !m.gone) {
    acts.appendChild(btn(r === 'admin' ? '取消管理员' : '设管理员', 'btn btn-xs btn-ghost', function () {
      op('role', { member: m.id, role: r === 'admin' ? 'member' : 'admin' });
    }));
    acts.appendChild(btn('转让群主', 'btn btn-xs btn-ghost', function () {
      if (!confirm('把群主转给「' + (m.name || '这位') + '」？转完你就变成管理员，只有对方能解散这个群。')) return;
      op('transfer', { member: m.id });
    }));
  }
  if (canKick && perm('admin') && !m.gone) {
    acts.appendChild(btn('移出', 'btn btn-xs btn-ghost danger', function () {
      if (!confirm('把「' + (m.name || '这位') + '」移出群聊？')) return;
      op('remove', { member: m.id });
    }));
  }
  if (acts.childNodes.length) row.appendChild(acts);
  return row;
}

async function op(action, extra) {
  // 转让和解散不可逆：提交前先过人机验证（不需要验证时返回空对象）
  let cap = {};
  if (action === 'transfer') {
    cap = await requireRisk('group.transfer', '转让群主', '转完你就变成管理员，只有新群主能解散这个群。');
  } else if (action === 'dissolve') {
    cap = await requireRisk('group.dissolve', '解散群聊', '成员会被请出，聊天记录不再出现在他们的列表里。');
  }
  if (cap === null) return false;
  const body = Object.assign({ op: action }, extra || {}, cap);
  const j = await api('api/groups/' + curId, body);
  if (!j.ok) { toast(j.err || '操作失败'); return false; }
  if (action === 'notice') {
    // 私群公告属于这个会话：改完立刻同步聊天页的公告条，不用切页
    const text = (j.notice || '').trim();
    S.notice = text
      ? { text: text, at: Date.now(), by: (S.me && S.me.name) || '', byId: (S.me && S.me.id) || '' }
      : { text: '', at: 0, by: '', byId: '' };
    refreshBars();
  }
  if (conv && conv.load) conv.load();
  await reload();
  return j;
}

function renameSheet() {
  const wrap = h('div', 'pg-create');
  const inp = document.createElement('input');
  inp.className = 'tb';
  inp.type = 'text';
  inp.maxLength = 24;
  inp.value = (data && data.group && data.group.title) || '';
  field(wrap, '群名', function (row) { row.appendChild(inp); });
  wrap.appendChild(btn('保存', 'btn btn-primary btn-block', async function () {
    const title = inp.value.trim();
    if (!title) { toast('群名不能空着'); return; }
    if (await op('rename', { title: title })) { closeSheet(); toast('群名已改'); }
  }));
  openSheet('改群名', wrap, { size: 'sm' });
}

function noticeSheet() {
  const wrap = h('div', 'pg-create');
  const ta = document.createElement('textarea');
  ta.className = 'tb';
  ta.maxLength = 500;
  ta.value = (data && data.group && data.group.notice) || '';
  ta.placeholder = '写点什么，群里的人打开聊天就能看到';
  field(wrap, '群公告', function (row) { row.appendChild(ta); });
  const acts = h('div', 'pg-row2');
  acts.appendChild(btn('清空公告', 'btn btn-ghost', async function () {
    if (await op('notice', { notice: '' })) { closeSheet(); toast('公告已清空'); }
  }));
  acts.appendChild(btn('保存', 'btn btn-primary', async function () {
    if (await op('notice', { notice: ta.value.trim() })) { closeSheet(); toast('公告已发布'); }
  }));
  wrap.appendChild(acts);
  openSheet('群公告', wrap, { size: 'md' });
}

function pickSheet(title, candidates, build) {
  const wrap = h('div', 'pg-create');
  const picked = {};
  const list = h('div', 'pg-pick');
  if (!candidates.length) {
    wrap.appendChild(h('div', 'page-tip', '没有可以拉的人了'));
  }
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
    });
    list.appendChild(item);
  });
  if (candidates.length) wrap.appendChild(list);
  build(wrap, function () { return Object.keys(picked); });
  openSheet(title, wrap, { size: 'md' });
}

function inviteSheet() {
  const inGroup = {};
  (data.members || []).forEach(function (m) { inGroup[String(m.id)] = true; });
  const candidates = (S.members || []).filter(function (m) { return !isMe(m) && !inGroup[String(m.id)]; });
  pickSheet('邀请成员', candidates, function (wrap, picked) {
    wrap.appendChild(btn('邀请进群', 'btn btn-primary btn-block', async function () {
      const ids = picked();
      if (!ids.length) { toast('先选几个人'); return; }
      if (await op('add', { members: ids })) { closeSheet(); toast('已邀请 ' + ids.length + ' 位'); }
    }));
  });
}

function leaveGroup() {
  if (!confirm('退出「' + ((data.group && data.group.title) || '这个群') + '」？退出后要别人再拉你才能回来。')) return;
  api('api/groups/' + curId, { op: 'leave' }).then(function (j) {
    if (!j.ok) { toast(j.err || '退出失败'); return; }
    toast('已退出群聊');
    if (conv && conv.load) conv.load();
    go('#/groups');
  });
}

async function dissolveGroup() {
  if (!confirm('解散「' + ((data.group && data.group.title) || '这个群') + '」？成员会被请出，聊天记录不会再出现在他们的列表里。')) return;
  // 解散不可逆：和转让一样先过人机验证
  const cap = await requireRisk('group.dissolve', '解散群聊', '成员会被请出，聊天记录不再出现在他们的列表里。');
  if (cap === null) return;
  api('api/groups/' + curId, Object.assign({ op: 'dissolve' }, cap)).then(function (j) {
    if (!j.ok) { toast(j.err || '解散失败'); return; }
    toast('群聊已解散');
    if (conv && conv.load) conv.load();
    go('#/groups');
  });
}
