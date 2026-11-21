// LightGroup · 会话右侧栏：群资料 / 好友资料（和 QQ 一样是右侧常驻面板，不是弹窗）
import { S, h, iconBtn, toast, api, go, openSheet, closeSheet, emit } from './core.js';
import { avatar } from './avatar.js';
import { canModerate, refreshBars } from './msg.js';
import { openProfileCard } from './people.js';
import { openQr } from './qr.js';
import { convById } from './conv.js';

let el = null;
let open = false;

export function init(aside) {
  el = aside;
}

export function isOpen() {
  return open;
}

export function close() {
  open = false;
  if (el) {
    el.hidden = true;
    el.classList.remove('open');
  }
}

export function reset() {
  open = false;
  el = null;
}

export function toggle() {
  if (!el) return;
  if (open) { close(); return; }
  paint();
  open = true;
  el.hidden = false;
  requestAnimationFrame(function () { if (el) el.classList.add('open'); });
}

export function refresh() {
  if (open) paint();
}

function isDmChat() {
  return !!(S.cur && S.cur.kind === 'dm');
}

function paint() {
  if (!el) return;
  el.textContent = '';
  const head = h('div', 'cs-head');
  head.appendChild(h('div', 'cs-title', isDmChat() ? '聊天资料' : '群资料'));
  head.appendChild(iconBtn('fa-xmark', '关闭', close));
  el.appendChild(head);
  const body = h('div', 'cs-body');
  el.appendChild(body);
  if (isDmChat()) paintDm(body); else paintGroup(body);
}

function card(title) {
  const c = h('div', 'cs-card');
  if (!title) return c;
  const hd = h('div', 'cs-card-h');
  hd.appendChild(h('div', 'cs-card-t', title));
  c.appendChild(hd);
  return c;
}


function paintGroup(body) {
  const pg = !!(S.cur && S.cur.kind === 'pg');
  const members = pg ? (S.pgMembers || []) : (S.members || []);
  const cnt = members.length || (S.cur && S.cur.members) || 0;

  const mc = card('群成员');
  const more = document.createElement('button');
  more.type = 'button';
  more.className = 'btn btn-xs btn-ghost';
  more.textContent = pg ? '群管理' : '全部成员';
  more.addEventListener('click', function () {
    close();
    go(pg ? '#/pg/' + S.curId : '#/members');
  });
  mc.querySelector('.cs-card-h').appendChild(more);
  const grid = h('div', 'cs-members');
  members.slice(0, 12).forEach(function (m) {
    const it = h('div', 'cs-member');
    it.appendChild(avatar(m, 38));
    it.appendChild(h('div', 'cs-member-n', m.name || ''));
    it.title = m.name || '';
    it.addEventListener('click', function () { openProfileCard(m); });
    grid.appendChild(it);
  });
  if (!members.length) grid.appendChild(h('div', 'cs-empty', '还没有成员'));
  mc.appendChild(grid);
  if (cnt > 12) mc.appendChild(h('div', 'cs-empty', '共 ' + cnt + ' 人'));
  body.appendChild(mc);

  const nc = card('群公告');
  if (canModerate()) {
    const ed = document.createElement('button');
    ed.type = 'button';
    ed.className = 'btn btn-xs btn-ghost';
    ed.textContent = '编辑';
    ed.addEventListener('click', function () { noticeSheet(); });
    nc.querySelector('.cs-card-h').appendChild(ed);
  }
  const n = S.notice;
  nc.appendChild(h('div', 'cs-notice' + (n && n.text ? '' : ' empty'), (n && n.text) || '还没有群公告'));
  if (n && n.text && (n.by || n.at)) {
    nc.appendChild(h('div', 'cs-meta', (n.by || '') + (n.by && n.at ? ' · ' : '')));
  }
  body.appendChild(nc);

  const qc = card('群二维码');
  const qb = document.createElement('button');
  qb.type = 'button';
  qb.className = 'btn btn-block btn-ghost';
  qb.textContent = '显示二维码';
  qb.addEventListener('click', function () { qr(); });
  qc.appendChild(qb);
  body.appendChild(qc);
}

function qr() {
  const name = (S.cur && (S.cur.title || S.cur.name)) || '群';
  openQr(name + ' 的二维码', 'lightgroup://group/' + S.curId);
}

function noticeSheet() {
  openSheet('编辑群公告', (function () {
    const box = h('div', 'sheet-body');
    const ta = document.createElement('textarea');
    ta.className = 'tb';
    ta.rows = 6;
    ta.maxLength = 500;
    ta.value = (S.notice && S.notice.text) || '';
    box.appendChild(ta);
    const row = h('div', 'cs-foot');
    const save = document.createElement('button');
    save.type = 'button';
    save.className = 'btn btn-primary';
    save.textContent = '保存';
    save.addEventListener('click', function () { saveNotice(ta.value.trim()); });
    row.appendChild(save);
    box.appendChild(row);
    ta.focus();
    return box;
  })(), { size: 'sm' });
}

async function saveNotice(text) {
  const j = await api('api/notice', { text: text });
  if (!j.ok) { toast(j.err || '公告没设上'); return; }
  S.notice = j.notice || null;
  refreshBars();
  closeSheet();
  refresh();
  emit('conv:touch');
}

// ---------- 私聊 ----------
function paintDm(body) {
  const m = (S.cur && S.cur.member) || {};
  const who = card('');
  const top = h('div', 'cs-who');
  top.appendChild(avatar(m, 54));
  const tx = h('div', 'cs-who-t');
  tx.appendChild(h('b', '', m.name || ''));
  if (m.username) tx.appendChild(h('div', 'cs-meta', '@' + m.username));
  top.appendChild(tx);
  who.appendChild(top);
  body.appendChild(who);

  const c = convById(S.curId);
  const pinned = !!(c && c.pinned);
  body.appendChild(row(pinned ? '取消置顶会话' : '置顶会话', null, function () { pinConv(!pinned); }));

  body.appendChild(row('备注', null, function () { remarkSheet(m); }));
  body.appendChild(row('二维码', null, function () {
    openQr((m.name || '') + ' 的二维码', 'lightgroup://user/' + m.id);
  }));
  body.appendChild(row('拉入黑名单', 'danger', function () { block(m); }));
  body.appendChild(row('删除好友', 'danger', function () { unfriend(m); }));
}

function row(text, cls, fn) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'cs-row' + (cls ? ' ' + cls : '');
  b.appendChild(h('span', '', text));
  if (fn) {
    const arrow = document.createElement('i');
    arrow.className = 'fa-solid fa-chevron-right cs-arrow';
    b.appendChild(arrow);
    b.addEventListener('click', fn);
  }
  return b;
}

function pinConv(on) {
  api('api/conv/pin', { id: S.curId, on: on ? 1 : 0 }).then(function (j) {
    if (!j.ok) { toast(j.err || '置顶失败'); return; }
    const c = convById(S.curId);
    if (c) c.pinned = on;
    toast(on ? '已置顶' : '已取消置顶');
    paint();
  });
}

function remarkSheet(m) {
  openSheet('备注', (function () {
    const box = h('div', 'sheet-body');
    const inp = document.createElement('input');
    inp.className = 'tb';
    inp.maxLength = 32;
    inp.placeholder = '给 TA 起个备注';
    box.appendChild(inp);
    const row2 = h('div', 'cs-foot');
    const save = document.createElement('button');
    save.type = 'button';
    save.className = 'btn btn-primary';
    save.textContent = '保存备注';
    save.addEventListener('click', function () { saveRemark(m, inp.value.trim()); });
    row2.appendChild(save);
    box.appendChild(row2);
    inp.focus();
    return box;
  })(), { size: 'sm' });
}

async function saveRemark(m, remark) {
  const j = await api('api/friends/remark', { id: m.id, remark: remark });
  if (!j.ok) { toast(j.err || '备注没保存'); return; }
  closeSheet();
  toast('备注已保存');
  refresh();
}

function block(m) {
  if (!confirm('把「' + (m.name || '') + '」拉进黑名单？拉黑后对方不能给你发消息，也不能再加你。')) return;
  api('api/friends/block', { id: m.id }).then(function (j) {
    if (!j.ok) { toast(j.err || '拉黑失败'); return; }
    toast('已拉黑');
    refresh();
  });
}

function unfriend(m) {
  if (!confirm('删除好友「' + (m.name || '') + '」？聊天记录会保留。')) return;
  api('api/friends/remove', { id: m.id }).then(function (j) {
    if (!j.ok) { toast(j.err || '删除失败'); return; }
    toast('已删除好友');
    refresh();
  });
}
