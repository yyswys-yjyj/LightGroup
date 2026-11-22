// LightGroup · 会话页：头部 + 三条横幅 + 消息流 + 输入区 + @ + 轮询
import {
  S, BOOT, h, icon, iconBtn, toast, api, apiMissing, hhmm, dayLabel,
  emit, go, readDataUrl, siteName, siteMark, setListOpen, openSheet, copyText, pageStop
} from './core.js';
import { avatar } from './avatar.js';
import { msgNode, refreshBars, clearReply, isDm, setReply, applyRecall } from './msg.js';
import * as side from './side.js';
import * as conv from './conv.js';
import { openProfileCard } from './people.js';

let feedEl = null;
let mountedId = null;
// 会话代数：每换一次会话 +1。异步请求回来先对一下代数，对不上就丢掉，
// 否则慢请求会把上一个会话的消息画到当前会话里（「串会话」）。
let gen = 0;
let timer = 0;
// 往上翻历史：首屏只拿最新一页，滚到顶再要更早的
let olderLoading = false;
let hasOlder = true;

export function isPublicConv() {
  return !S.cur || S.curId === 0 || S.cur.isPublic || S.cur.kind === 'group';
}
// 私群：只有群里的人看得到；头部换群名，点一下进群资料
export function isPg() { return !!(S.cur && S.cur.kind === 'pg'); }
export function subText() {
  if (isDm()) return '好友 · 私聊';
  if (isPg()) {
    const n = convMembersOf().length || (S.cur && S.cur.members) || 0;
    return n ? '群聊 · ' + n + ' 人' : '群聊';
  }
  const n = (S.members || []).length;
  return n ? n + ' 人' : '在线';
}

// 私群成员单独放，别覆盖大群的 S.members（成员页、建群邀请都读它）
function convMembersOf() {
  if (isPg()) return S.pgMembers || [];
  return S.members || [];
}
export function refreshSub() {
  const sub = document.querySelector('.chat-sub');
  if (sub) sub.textContent = subText();
}
function isNearBottom() {
  if (!feedEl) return true;
  return feedEl.scrollHeight - feedEl.scrollTop - feedEl.clientHeight < 180;
}

function onFeedScroll() {
  if (feedEl && feedEl.scrollTop < 120) loadOlder();
}

// 滚到顶：拿更早的一页塞到前面，并把视口按原来的相对位置挪回去
async function loadOlder() {
  if (olderLoading || !hasOlder || !feedEl) return;
  if (!S.msgs.length) return;
  const oldest = S.msgs[0].id;
  if (!oldest) return;
  olderLoading = true;
  const my = gen;
  const id = S.curId;
  const beforeHeight = feedEl.scrollHeight;
  const beforeTop = feedEl.scrollTop;
  let j = null;
  try {
    j = S.mode === 'legacy'
      ? await api('fetch?before=' + oldest + '&limit=50')
      : await api('api/conv?id=' + id + '&before=' + oldest + '&limit=50');
  } catch (e) {
    j = null;
  }
  olderLoading = false;
  if (my !== gen || S.curId !== id) return;
  if (!j || !j.ok) return;
  const older = (j.msgs || []).filter(function (m) { return !S.msgs.some(function (x) { return x.id === m.id; }); });
  if (!older.length) { hasOlder = false; return; }
  S.msgs = older.concat(S.msgs);
  hasOlder = !!j.more;
  renderFeed();
  if (feedEl) feedEl.scrollTop = feedEl.scrollHeight - beforeHeight + beforeTop;
}
function scrollBottom(smooth) {
  if (!feedEl) return;
  const f = feedEl;
  requestAnimationFrame(function () {
    f.scrollTo({ top: f.scrollHeight, behavior: smooth === false ? 'auto' : 'smooth' });
  });
}

// ---------- 挂载 / 卸载 ----------
export function mount(id) {
  const nid = Number(id) || 0;
  const c = conv.convById(nid) ||
    (nid === 0 ? (conv.publicConv() || { id: 0, kind: 'group', title: siteName(), isPublic: 1 }) : null);
  // URL 里没带 id 时用兜底会话的真实 id，别让 S.curId 与 S.cur 打架
  const cid = c ? Number(c.id) || 0 : nid;
  S.curId = cid;
  S.cur = c;
  if (mountedId !== cid) {
    gen++;
    // 旧会话的轮询先停掉，等新会话的首页消息回来再重启（startLoop）
    S.polling = false;
    if (timer) { clearTimeout(timer); timer = 0; }
    mountedId = cid;
    S.msgs = [];
    S.lastId = 0;
    S.reply = null;
    S.pgMembers = [];
    S.pgSig = '';
    S.mode = (S.convApi || cid !== 0) ? 'conv' : 'legacy';
    hasOlder = true;
    olderLoading = false;
  }
  S.page = 'chat';
  build();
  if (!c) {
    renderError('找不到这个会话');
    return;
  }
  if (!S.msgs.length && !S.lastId) {
    showLoading();
    initLoad();
  } else {
    renderFeed();
    scrollBottom(false);
    refreshBars();
    startLoop();
  }
}

// 切会话先出一张「加载中」的中间页：不要留着上一屏的空壳，也避免旧消息先闪一下
function showLoading() {
  if (!feedEl) return;
  feedEl.textContent = '';
  const box = h('div', 'feed-loading');
  const sp = icon('fa-spinner');
  sp.classList.add('fa-spin');
  box.appendChild(sp);
  box.appendChild(h('span', '', '正在加载消息…'));
  feedEl.appendChild(box);
}

export function stop() {
  S.polling = false;
  // 离开聊天页：还在路上的请求回来后不能往别的页面里画
  gen++;
  if (timer) { clearTimeout(timer); timer = 0; }
  mountedId = null;
  feedEl = null;
  S.pgMembers = [];
  S.pgSig = '';
  // 输入框是当前会话的草稿，换会话时整页重建，草稿不会带到别的会话
  side.reset();
}

function build() {
  const host = document.getElementById('page-host');
  if (!host) return;
  host.textContent = '';
  S.chatEl = host;
  const page = h('div', 'chat-page');
  page.appendChild(buildHead());
  const bars = h('div', 'top-bars');
  bars.id = 'top-bars';
  page.appendChild(bars);
  feedEl = h('div', 'feed');
  feedEl.id = 'feed';
  feedEl.addEventListener('scroll', onFeedScroll, { passive: true });
  page.appendChild(feedEl);
  page.appendChild(buildComposer());

  // 右侧栏是布局的一部分：桌面贴右侧，窄屏盖住聊天区
  const wrap = h('div', 'chat-wrap');
  wrap.appendChild(page);
  const aside = h('aside', 'chat-side');
  aside.id = 'chat-side';
  aside.hidden = true;
  side.init(aside);
  wrap.appendChild(aside);
  host.appendChild(wrap);
}

function buildHead() {
  const head = h('header', 'chat-head');
  const back = iconBtn('fa-chevron-left', '返回会话列表', function () { setListOpen(true); });
  back.classList.add('only-narrow');
  head.appendChild(back);

  const info = h('div', 'chat-head-info');
  const dm = isDm();
  const pg = isPg();
  if (dm && S.cur.member) info.appendChild(avatar(S.cur.member, 34));
  else if (pg) info.appendChild(avatar({ id: 'pg' + S.curId, name: conv.convName(S.cur) }, 34));
  else info.appendChild(siteMark('head-mark'));
  const t = h('div', 'chat-title');
  t.appendChild(h('b', '', dm || pg ? conv.convName(S.cur) : (siteName())));
  t.appendChild(h('span', 'chat-sub', subText()));
  info.appendChild(t);
  if (dm && S.cur.member) {
    info.style.cursor = 'pointer';
    info.title = '点开资料';
    info.addEventListener('click', function () { openProfileCard(S.cur.member); });
  }
  head.appendChild(info);

  const acts = h('div', 'chat-acts');
  if (dm) {
    acts.appendChild(iconBtn('fa-address-card', '聊天资料', function () { side.toggle(); }));
  } else {
    acts.appendChild(iconBtn('fa-circle-info', '群资料', function () { side.toggle(); }));
  }
  head.appendChild(acts);
  return head;
}

/**
 * 私聊里点「邀请 AI」：服务端生成一段 lg1-<群id>-<会话id>-<hex> 的邀请码，
 * 全 ASCII，粘到哪都不会被改坏。AI 那边调 api/dm/join 带这段码就进得来。
 */
function inviteAi() {
  api('api/dm/invite', { conv: S.curId }).then(function (j) {
    if (!j.ok) { toast(j.err || '生成失败了'); return; }
    const body = h('div', 'dm-invite');
    body.appendChild(h('p', 'dm-invite-tip', '把下面这段发给要拉进来的 AI。24 小时内有效，用一次就作废。'));
    body.appendChild(h('code', 'dm-invite-code', j.code));
    const cp = document.createElement('button');
    cp.type = 'button';
    cp.className = 'btn dm-invite-copy';
    cp.textContent = '复制邀请码';
    cp.addEventListener('click', function () { copyText(j.code); });
    body.appendChild(cp);
    body.appendChild(h('p', 'dm-invite-tip', 'AI 用它接入这个私聊。'));
    openSheet('邀请 AI 进私聊', body, { size: 'md' });
  });
}

function buildComposer() {
  const bar = h('footer', 'composer');
  const replyBar = h('div', 'reply-bar');
  replyBar.id = 'reply-bar';
  replyBar.hidden = true;
  bar.appendChild(replyBar);

  const row = h('div', 'composer-row');
  const plus = document.createElement('button');
  plus.type = 'button';
  plus.className = 'round-btn';
  plus.title = '添加图片';
  plus.appendChild(icon('fa-plus'));
  plus.addEventListener('mousedown', saveCaret);
  plus.addEventListener('click', pickImage);
  row.appendChild(plus);

  // 输入框是 contenteditable：图片插在文字中间，和字一样能删、能跟着一起选中
  const box = document.createElement('div');
  box.className = 'compose';
  box.id = 'compose';
  box.setAttribute('contenteditable', 'true');
  box.setAttribute('role', 'textbox');
  box.setAttribute('aria-multiline', 'true');
  box.setAttribute('data-ph', '说点什么…');
  box.addEventListener('input', function () {
    composeSize();
    saveCaret();
    if (!isDm() && caretBefore() === '@') openAtPicker();
  });
  box.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.isComposing) return;
    if (e.key === 'Enter' && e.shiftKey) { e.preventDefault(); insertTextAtCaret('\n'); return; }
    if (e.key === 'Enter') { e.preventDefault(); sendText(); }
  });
  box.addEventListener('paste', onComposePaste);
  box.addEventListener('keyup', saveCaret);
  box.addEventListener('mouseup', saveCaret);
  box.addEventListener('focus', saveCaret);
  box.addEventListener('blur', saveCaret);
  row.appendChild(box);

  if (!isDm()) {
    const at = document.createElement('button');
    at.type = 'button';
    at.className = 'round-btn';
    at.title = '提及成员';
    at.appendChild(icon('fa-at'));
    at.addEventListener('mousedown', saveCaret);
    at.addEventListener('click', function () { openAtPicker(); });
    row.appendChild(at);
  }

  const mic = document.createElement('button');
  mic.type = 'button';
  mic.className = 'round-btn mic-btn';
  mic.title = '语音：点一下开始录制，再点一下发送';
  mic.appendChild(icon('fa-microphone'));
  mic.addEventListener('click', function () { toggleVoice(); });
  row.appendChild(mic);

  const send = h('button', 'send-btn', '发送');
  send.type = 'button';
  send.addEventListener('click', sendText);
  row.appendChild(send);
  bar.appendChild(row);
  return bar;
}

// ---------- 输入框（图文混排） ----------
// 图片是插在文字中间的行内块：能被 del 掉，也能跟着文字一起选中，复制出来就是文件名
let composeCaret = null;

function composeEl() { return document.getElementById('compose'); }

function composeSize() {
  const el = composeEl();
  if (!el) return;
  el.style.height = 'auto';
  el.style.height = Math.min(132, el.scrollHeight) + 'px';
}

function saveCaret() {
  const el = composeEl();
  const sel = window.getSelection();
  if (!el || !sel || !sel.rangeCount) return;
  const r = sel.getRangeAt(0);
  if (!el.contains(r.startContainer)) return;
  composeCaret = r.cloneRange();
}

function ensureCaret() {
  const el = composeEl();
  if (!el) return null;
  const sel = window.getSelection();
  if (sel && sel.rangeCount) {
    const cur = sel.getRangeAt(0);
    if (el.contains(cur.startContainer)) return cur;
  }
  const r = document.createRange();
  if (composeCaret && el.contains(composeCaret.startContainer)) {
    r.setStart(composeCaret.startContainer, composeCaret.startOffset);
    r.setEnd(composeCaret.endContainer, composeCaret.endOffset);
  } else {
    r.selectNodeContents(el);
    r.collapse(false);
  }
  if (sel) { sel.removeAllRanges(); sel.addRange(r); }
  return r;
}

// 光标前一个字符，用来判断是不是刚打了「@」
function caretBefore() {
  const el = composeEl();
  const sel = window.getSelection();
  if (!el || !sel || !sel.rangeCount) return '';
  const r = sel.getRangeAt(0);
  if (!r.collapsed || !el.contains(r.startContainer)) return '';
  const n = r.startContainer;
  if (n.nodeType !== 3) return '';
  return String(n.nodeValue || '').slice(Math.max(0, r.startOffset - 1), r.startOffset);
}

function insertNodeAt(node, spot) {
  const el = composeEl();
  if (!el) return;
  el.focus();
  let r = spot;
  if (!r || !el.contains(r.startContainer)) r = ensureCaret();
  if (!r || !el.contains(r.startContainer)) {
    r = document.createRange();
    r.selectNodeContents(el);
    r.collapse(false);
  }
  r.deleteContents();
  r.insertNode(node);
  const after = document.createRange();
  after.setStartAfter(node);
  after.collapse(true);
  const sel = window.getSelection();
  if (sel) { sel.removeAllRanges(); sel.addRange(after); }
  composeCaret = after.cloneRange();
  composeSize();
}

function insertTextAtCaret(str) {
  const el = composeEl();
  if (!el) return;
  el.focus();
  ensureCaret();
  let ok = false;
  try { ok = document.execCommand('insertText', false, str); } catch (e) { ok = false; }
  if (!ok) {
    const r = ensureCaret();
    if (!r) return;
    const tn = document.createTextNode(str);
    r.deleteContents();
    r.insertNode(tn);
    const after = document.createRange();
    after.setStartAfter(tn);
    after.collapse(true);
    const sel = window.getSelection();
    if (sel) { sel.removeAllRanges(); sel.addRange(after); }
  }
  saveCaret();
  composeSize();
}

// 按 DOM 里的真实先后顺序收：文字段、图片段交替
function composeSegs() {
  const el = composeEl();
  const segs = [];
  let buf = '';
  let pics = 0;
  const flush = function () {
    const t = buf.replace(/\u00a0/g, ' ').replace(/\n{3,}/g, '\n\n').replace(/^\n+|\n+$/g, '');
    if (t.trim()) segs.push({ t: 'text', v: t });
    buf = '';
  };
  const walk = function (node) {
    const kids = node.childNodes;
    for (let i = 0; i < kids.length; i++) {
      const n = kids[i];
      if (n.nodeType === 3) { buf += n.nodeValue; continue; }
      if (n.nodeType !== 1) continue;
      const tag = n.tagName;
      if (tag === 'BR') { buf += '\n'; continue; }
      if (n.classList && n.classList.contains('ph-img')) {
        flush();
        const url = n.getAttribute('data-url') || '';
        if (url) {
          segs.push({ t: 'img', v: url, n: n.getAttribute('data-name') || '图片' });
          pics++;
        }
        continue;
      }
      const block = tag === 'DIV' || tag === 'P' || tag === 'LI';
      if (block && buf && !/\n$/.test(buf)) buf += '\n';
      walk(n);
      if (block && !/\n$/.test(buf)) buf += '\n';
    }
  };
  if (el) walk(el);
  flush();
  const text = segs.filter(function (x) { return x.t === 'text'; })
    .map(function (x) { return x.v; }).join('\n');
  return { text: text, segs: segs, pics: pics };
}

// 发送失败时把内容放回输入框
function restoreCompose(segs) {
  const el = composeEl();
  if (!el) return;
  el.textContent = '';
  (segs || []).forEach(function (x) {
    if (x.t === 'img') el.appendChild(picNode(x.v, x.n || '图片'));
    else if (x.t === 'text' && x.v) el.appendChild(document.createTextNode(x.v));
  });
  composeSize();
  el.focus();
  saveCaret();
}

function onComposePaste(e) {
  const cd = e.clipboardData;
  if (!cd) return;
  const files = [];
  if (cd.items) {
    for (let i = 0; i < cd.items.length; i++) {
      const it = cd.items[i];
      if (it.kind === 'file' && /^image\//i.test(it.type || '')) {
        const f = it.getAsFile();
        if (f) files.push(f);
      }
    }
  }
  if (files.length) {
    e.preventDefault();
    (async function () {
      for (let i = 0; i < files.length; i++) await addPic(files[i]);
    })();
    return;
  }
  const plain = cd.getData('text/plain');
  if (plain) {
    e.preventDefault();
    insertTextAtCaret(plain);
  }
}

function renderError(msg) {
  if (!feedEl) return;
  feedEl.textContent = '';
  feedEl.appendChild(h('div', 'empty-hint', msg));
}

// ---------- 拉数据 ----------
async function initLoad() {
  const id = S.curId;
  const my = gen;
  const j = await api('api/conv?id=' + id + '&since=0&limit=50');
  // 等接口的这段时间可能已经点到别的会话了：这次结果直接作废
  if (my !== gen || S.curId !== id) return;
  if (j && j.ok) {
    S.convApi = true;
    S.mode = 'conv';
    apply(j);
    startLoop();
    return;
  }
  if (isPublicConv() && (apiMissing(j) || S.mode === 'legacy')) {
    const k = await api('fetch?since=0&limit=50');
    if (my !== gen || S.curId !== id) return;
    if (k && k.ok) {
      S.convApi = false;
      S.mode = 'legacy';
      apply(k);
      startLoop();
      return;
    }
    renderError((k && k.err) || '读不到消息');
    return;
  }
  renderError((j && j.err) || '读不到消息');
}

function apply(j) {
  if (j.conv && j.conv.id) S.cur = Object.assign({}, S.cur || {}, j.conv);
  if (typeof j.title === 'string' && j.title) S.cur.title = j.title;
  if (!isDm()) syncMembers(j.members);
  S.notice = j.notice || S.notice;
  if (j.pins) S.pins = j.pins;
  if (j.todos) S.todos = j.todos;
  S.msgs = [];
  S.lastId = 0;
  hasOlder = !!j.more;
  if (j.msgs && j.msgs.length) {
    j.msgs.forEach(function (m) { if (!S.msgs.some(function (x) { return x.id === m.id; })) S.msgs.push(m); });
    S.lastId = j.lastId || j.msgs[j.msgs.length - 1].id;
  } else if (j.lastId) {
    S.lastId = j.lastId;
  }
  const c = conv.convById(S.curId);
  if (c && c.unread) { c.unread = 0; conv.repaint(); }
  renderFeed();
  scrollBottom(false);
  refreshBars();
  // 消息真正画出来才算这次切页走完，底栏的「页面: Xms」用这个数
  pageStop();
}

async function pull(since, id) {
  const cid = typeof id === 'number' ? id : S.curId;
  if (S.mode === 'legacy') return api('fetch?since=' + since);
  const j = await api('api/conv?id=' + cid + '&since=' + since);
  if (j && j.ok) return j;
  if (isPublicConv() && apiMissing(j)) {
    S.mode = 'legacy';
    const k = await api('fetch?since=' + since);
    if (k && k.ok) return k;
  }
  return j;
}

// ---------- 消息渲染 ----------
// 分隔条只在「换天」或「超过 30 分钟没说话」时出现，避免每条消息前都顶一条
function needSep(m, prev) {
  if (!prev) return true;
  if (dayLabel(prev.ts) !== dayLabel(m.ts)) return true;
  return m.ts - prev.ts > 30 * 60 * 1000;
}

function sepNode(ts) {
  const sep = h('div', 'day-sep');
  sep.appendChild(h('span', '', dayLabel(ts) + ' ' + hhmm(ts)));
  return sep;
}

function renderFeed() {
  if (!feedEl) return;
  feedEl.textContent = '';
  S.msgs.forEach(function (m, i) {
    const prev = i > 0 ? S.msgs[i - 1] : null;
    if (needSep(m, prev)) feedEl.appendChild(sepNode(m.ts));
    feedEl.appendChild(msgNode(m, needSep(m, prev) ? null : prev));
  });
  refreshSub();
}

function appendMsgs(list) {
  if (!list || !list.length) return;
  const nearBottom = isNearBottom();
  list.forEach(function (m) {
    if (S.msgs.some(function (x) { return x.id === m.id; })) return;
    S.msgs.push(m);
    if (!feedEl) return;
    const prev = S.msgs[S.msgs.length - 2];
    const sep = needSep(m, prev);
    if (sep) feedEl.appendChild(sepNode(m.ts));
    feedEl.appendChild(msgNode(m, sep ? null : prev));
    if (!isDm()) conv.noteLast(S.curId, m);
  });
  if (feedEl && nearBottom && S.msgs.length) scrollBottom(false);
  const c = conv.convById(S.curId);
  if (c && c.unread) { c.unread = 0; conv.repaint(); }
}

function syncMembers(list) {
  if (!list || !list.length) return;
  const sig = list.map(function (m) { return m.id + ':' + m.name + ':' + (m.avatar ? 1 : 0); }).join('|');
  if (isPg()) {
    if (S.pgSig === sig) return;
    S.pgSig = sig;
    S.pgMembers = list;
  } else {
    if (S.memberSig === sig) return;
    S.memberSig = sig;
    S.members = list;
  }
  refreshSub();
  emit('members', list);
}

// ---------- 轮询 ----------
export function startLoop() {
  S.polling = true;
  if (timer) clearTimeout(timer);
  tick();
}

async function tick() {
  if (!S.polling) return;
  if (document.hidden) { timer = setTimeout(tick, 4000); return; }
  const my = gen;
  const id = S.curId;
  let j;
  try { j = await pull(S.lastId, id); }
  catch (e) { timer = setTimeout(tick, 3000); return; }
  if (!S.polling) return;
  if (my !== gen || S.curId !== id) {
    // 这一轮问的是上一个会话，结果不能往新会话里塞；让新会话的轮询接着跑
    timer = setTimeout(tick, 200);
    return;
  }
  if (j && j.ok) {
    if (j.conv && j.conv.id) S.cur = Object.assign({}, S.cur || {}, j.conv);
    if (!isDm()) syncMembers(j.members);
    if (j.msgs && j.msgs.length) {
      appendMsgs(j.msgs);
      S.lastId = j.lastId || j.msgs[j.msgs.length - 1].id;
    } else if (j.lastId && j.lastId > S.lastId) {
      S.lastId = j.lastId;
    }
    // 撤回是「旧消息的状态变更」，id 游标拉不回来，只能靠服务端每轮带回的 recalled 列表
    if (j.recalled && j.recalled.length) {
      for (let i = 0; i < j.recalled.length; i++) {
        applyRecall(j.recalled[i]);
        conv.markRecalled(j.recalled[i]);
      }
    }
    timer = setTimeout(tick, 1200);
  } else {
    if (j && j.err && (j.err.indexOf('登录') >= 0 || j.err.indexOf('注销') >= 0 || j.err.indexOf('封禁') >= 0)) {
      toast(j.err);
      return;
    }
    timer = setTimeout(tick, 3000);
  }
}

// ---------- @ 提及 ----------
let atPop = null;
export function closeAtPicker() {
  if (atPop && atPop.parentNode) atPop.parentNode.removeChild(atPop);
  atPop = null;
}
function insertAtCompose(text) {
  const el = composeEl();
  if (!el) return;
  el.focus();
  const sel = window.getSelection();
  const r = sel && sel.rangeCount ? sel.getRangeAt(0) : null;
  if (r && r.collapsed && el.contains(r.startContainer)) {
    const n = r.startContainer;
    if (n.nodeType === 3 && String(n.nodeValue || '').slice(0, r.startOffset).endsWith('@')) {
      const cut = document.createRange();
      cut.setStart(n, r.startOffset - 1);
      cut.setEnd(n, r.startOffset);
      cut.deleteContents();
      const put = document.createRange();
      put.setStart(cut.startContainer, cut.startOffset);
      put.collapse(true);
      sel.removeAllRanges();
      sel.addRange(put);
    }
  }
  insertTextAtCaret(text);
}
function openAtPicker() {
  closeAtPicker();
  const list = convMembersOf().filter(function (m) { return m && m.name && (!S.me || String(m.id) !== String(S.me.id)); });
  if (!list.length) { toast('还没有其他成员'); return; }
  const pop = h('div', 'at-pop');
  pop.appendChild(h('div', 'at-pop-h', '提及'));
  list.forEach(function (m) {
    const it = document.createElement('button');
    it.type = 'button';
    it.className = 'at-item';
    it.appendChild(avatar(m));
    it.appendChild(h('span', 'at-name', m.name));
    if (m.kind === 'ai') it.appendChild(h('span', 'tag-ai', 'AI'));
    it.addEventListener('click', function () {
      closeAtPicker();
      insertAtCompose('@' + m.name + ' ');
    });
    pop.appendChild(it);
  });
  document.body.appendChild(pop);
  const row = document.querySelector('.composer-row');
  const rb = row ? row.getBoundingClientRect() : { top: innerHeight - 60, left: 20 };
  pop.style.left = Math.max(12, Math.min(rb.left, innerWidth - 268)) + 'px';
  pop.style.bottom = Math.max(12, innerHeight - rb.top + 8) + 'px';
  atPop = pop;
  setTimeout(function () {
    document.addEventListener('pointerdown', function onDoc(ev) {
      if (!atPop) { document.removeEventListener('pointerdown', onDoc); return; }
      if (!atPop.contains(ev.target)) { closeAtPicker(); document.removeEventListener('pointerdown', onDoc); }
    });
  }, 0);
}

// ---------- 图片块：插在文字中间 ----------
const PIC_MAX = 9;

function picNode(url, name) {
  const n = document.createElement('span');
  n.className = 'ph-img';
  n.setAttribute('contenteditable', 'false');
  n.setAttribute('data-url', url);
  n.setAttribute('data-name', name);
  n.title = name;
  n.appendChild(icon('fa-image'));
  n.appendChild(h('span', 'ph-name', name));
  return n;
}

function picCount() {
  const el = composeEl();
  return el ? el.querySelectorAll('.ph-img').length : 0;
}

async function addPic(file) {
  if (file.type && !/^image\//.test(file.type)) { toast('选一个图片文件'); return; }
  if (picCount() >= PIC_MAX) { toast('一条消息最多 9 张图片'); return; }
  const spot = ensureCaret();
  const saved = spot ? spot.cloneRange() : null;
  toast('处理图片…');
  let dataUrl;
  try { dataUrl = await shrinkImage(file); }
  catch (e) { toast((e && e.message) || '这张图片读不出来'); return; }
  toast('上传中…');
  const up = await api('api/upload', { data: dataUrl });
  if (!up || !up.ok) { toast((up && up.err) || '上传失败'); return; }
  if (picCount() >= PIC_MAX) { toast('一条消息最多 9 张图片'); return; }
  const name = String(file.name || '图片').slice(0, 40);
  insertNodeAt(picNode(up.url, name), saved);
}

// ---------- 发送 ----------
async function send(payload) {
  if (S.mode === 'legacy') {
    if (!isPublicConv()) return { ok: false, err: '私聊暂时打不开，稍后再试' };
    return api('api/send', payload);
  }
  const body = Object.assign({ conv: S.curId }, payload);
  const j = await api('api/conv/send', body);
  if (j && j.ok) return j;
  if (isPublicConv() && apiMissing(j)) {
    S.mode = 'legacy';
    const k = await api('api/send', payload);
    if (k && k.ok) return k;
  }
  return j;
}

async function sendText() {
  const el = composeEl();
  if (!el) return;
  const got = composeSegs();
  const text = got.text.trim();
  if (!text && !got.pics) return;
  if (text.length > 10000) {
    toast('单条消息最多 10000 字，再长请分开几条发');
    return;
  }
  if (got.pics > PIC_MAX) { toast('一条消息最多 9 张图片'); return; }
  const segs = got.segs.map(function (x) {
    return x.t === 'img' ? { t: 'img', v: x.v } : { t: 'text', v: x.v };
  });

  el.textContent = '';
  composeSize();

  const payload = { text: text };
  if (got.pics) payload.segs = segs;
  const replySnap = S.reply ? { id: S.reply.id, name: S.reply.name, text: S.reply.text } : null;
  if (S.reply) payload.reply = S.reply.id;
  const j = await send(payload);
  if (!j || !j.ok) {
    toast((j && j.err) || '没发出去');
    restoreCompose(got.segs);
    return;
  }
  clearReply();
  if (j.msg) echoMsg(j.msg.id, j.msg.ts, text, '', replySnap, '', 0, got.pics ? segs : null);
}

// 发送后先把这条画上去，不等下一轮轮询；轮询拉回同一条会按 id 去重跳过
function echoMsg(id, ts, text, img, replySnap, voice, dur, segs) {
  const me = S.me || {};
  const m = {
    id: id, ts: ts, from: me.id || '', name: me.name || '',
    kind: me.kind || 'human', color: me.color || '', ava: me.avatar || '',
    text: text || '', mentions: [], rev: 0, voice: voice || '', dur: dur || 0,
  };
  if (img) m.img = img;
  if (Array.isArray(segs) && segs.length) m.segs = segs;
  if (replySnap) { m.reply = replySnap; m.q = JSON.stringify(replySnap); }
  appendMsgs([m]);
  scrollBottom(true);
}

function loadImg(src) {
  return new Promise(function (res, rej) {
    const im = new Image();
    im.onload = function () { res(im); };
    im.onerror = function () { rej(new Error('decode')); };
    im.src = src;
  });
}

/**
 * 把用户选的图片压成能上传的 dataURL。
 *
 * 以前这里是「超过 3.2MB 直接劝退」，但手机原图普遍 4–8MB，等于大部分图都发不出去；
 * 服务端又只收 5,200,000 字符以内的 dataURL（≈3.9MB 原图），
 * 所以正确做法是本地先按长边 1600 缩一遍、体积还大就降质量，实在压不下来才提示。
 */
export async function shrinkImage(file) {
  let raw;
  try { raw = await readDataUrl(file); }
  catch (e) { throw new Error('这个文件读不出来，换一张图片试试'); }
  let im;
  try { im = await loadImg(raw); }
  catch (e) {
    const m = new Error('这个格式浏览器解不开（iPhone 的 HEIC 常见），改发截图或者 JPG/PNG 吧');
    m.friendly = true;
    throw m;
  }
  const w0 = im.naturalWidth || im.width;
  const h0 = im.naturalHeight || im.height;
  if (!w0 || !h0) throw new Error('这张图片是空的');
  let side = 1600;
  let q = 0.86;
  let out = '';
  for (let i = 0; i < 6; i++) {
    const scale = Math.min(1, side / Math.max(w0, h0));
    const w = Math.max(1, Math.round(w0 * scale));
    const ht = Math.max(1, Math.round(h0 * scale));
    const cv = document.createElement('canvas');
    cv.width = w;
    cv.height = ht;
    const cx = cv.getContext('2d');
    // 透明 PNG 转 JPEG 会变黑底，先铺白
    cx.fillStyle = '#fff';
    cx.fillRect(0, 0, w, ht);
    cx.drawImage(im, 0, 0, w, ht);
    out = cv.toDataURL('image/jpeg', q);
    if (out.length <= 4600000) break;
    if (q > 0.62) q -= 0.08; else side = Math.round(side * 0.8);
  }
  if (out.length > 5000000) throw new Error('图片压完还是太大，换一张小一点的试试');
  return out;
}

function pickImage() {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/*';
  input.multiple = true;
  input.addEventListener('change', function () {
    const files = Array.prototype.slice.call(input.files || []);
    // 串行上传，插图顺序才和选图顺序一致
    (async function () {
      for (let i = 0; i < files.length; i++) await addPic(files[i]);
    })();
  });
  input.click();
}

// 供外部（比如引用）在会话页里定位
export function focusCompose() {
  const ta = document.getElementById('compose');
  if (ta) ta.focus();
}

// ---------- 语音录制 ----------
let rec = null;
let recStream = null;
let recChunks = [];
let recStart = 0;
let recTimer = 0;
let recSending = false;

function micSupported() {
  return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
}

function audioMime() {
  const list = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
  for (let i = 0; i < list.length; i++) {
    try { if (MediaRecorder.isTypeSupported(list[i])) return list[i]; } catch (e) { void e; }
  }
  return '';
}

function micButton() { return document.querySelector('.mic-btn'); }

function setRecUI(on) {
  const b = micButton();
  if (!b) return;
  b.classList.toggle('rec', !!on);
  b.title = on ? '正在录音，点一下发送' : '语音：点一下开始录制，再点一下发送';
}

function closeStream() {
  if (recTimer) { clearInterval(recTimer); recTimer = 0; }
  if (recStream) {
    try { recStream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) { void e; }
  }
  recStream = null;
}

// 浏览器只在安全上下文开麦克风：https 或 localhost/127.0.0.1。
// 局域网 IP 走 http 时 getUserMedia 根本不会弹权限框，直接抛错，很容易被误报成「权限不足」。
function micSecure() {
  if (window.isSecureContext) return true;
  const h = location.hostname;
  return h === 'localhost' || h === '127.0.0.1' || h === '[::1]' || h === '::1';
}

/**
 * 把 getUserMedia 的失败原因翻成能做点什么的话。
 *
 * 以前只分两种情况，遇到站点的 Permissions-Policy 把麦克风整个禁掉（Chrome 抛 NotAllowedError）
 * 时也只说「拿不到麦克风权限」，用户完全无从下手。
 */
function micErrText(e) {
  const n = (e && e.name) || '';
  if (!micSecure()) {
    return '浏览器只在 https 或 localhost 下允许用麦克风，当前页面是 ' + location.protocol + '//' + location.host;
  }
  if (n === 'NotAllowedError' || n === 'SecurityError') {
    if (navigator.permissions) {
      return '麦克风被拦了：点地址栏左边的锁/麦克风图标选「允许」，或检查这个站点的权限策略是否禁了 microphone';
    }
    return '麦克风被拦了：点地址栏左边的锁图标允许麦克风，再点一次';
  }
  if (n === 'NotFoundError' || n === 'OverconstrainedError') return '这台设备上没有可用的麦克风';
  if (n === 'NotReadableError' || n === 'AbortError') return '麦克风被别的程序占用了（会议、录屏软件），关掉再试';
  if (n === 'NotSupportedError') return '这个浏览器不支持录音';
  return '拿不到麦克风权限' + (n ? '（' + n + '）' : '');
}

async function toggleVoice() {
  if (rec) { stopVoice(); return; }
  if (recSending) return;
  if (!micSupported()) { toast('这个浏览器不支持录音'); return; }
  if (!micSecure()) { toast(micErrText(null)); return; }
  try {
    recStream = await navigator.mediaDevices.getUserMedia({ audio: true });
  } catch (e) {
    toast(micErrText(e));
    return;
  }
  recChunks = [];
  recStart = Date.now();
  const mime = audioMime();
  try {
    rec = mime ? new MediaRecorder(recStream, { mimeType: mime }) : new MediaRecorder(recStream);
  } catch (e) {
    closeStream();
    toast('这台设备录不了音');
    return;
  }
  rec.addEventListener('dataavailable', function (e) { if (e.data && e.data.size) recChunks.push(e.data); });
  rec.addEventListener('stop', finishVoice);
  rec.start();
  setRecUI(true);
  recTimer = window.setInterval(function () {
    const b = micButton();
    if (b) b.title = '录音中 ' + Math.round((Date.now() - recStart) / 1000) + 's，点一下发送';
  }, 500);
  toast('开始录音，说完点一下麦克风');
}

function stopVoice() {
  if (!rec) return;
  try { rec.stop(); } catch (e) { void e; }
}

async function finishVoice() {
  const dur = Math.max(1, Math.round((Date.now() - recStart) / 1000));
  const type = (rec && rec.mimeType) || 'audio/webm';
  rec = null;
  closeStream();
  setRecUI(false);
  if (!recChunks.length) { toast('没录到声音'); return; }
  const blob = new Blob(recChunks, { type: type });
  recChunks = [];
  if (blob.size > 5.5 * 1024 * 1024) { toast('这条语音太长了'); return; }
  recSending = true;
  try {
    toast('发送语音…');
    const dataUrl = await new Promise(function (res, rej) {
      const fr = new FileReader();
      fr.onload = function () { res(String(fr.result || '')); };
      fr.onerror = rej;
      fr.readAsDataURL(blob);
    });
    const up = await api('api/upload', { data: dataUrl });
    if (!up || !up.ok) { toast((up && up.err) || '上传失败'); return; }
    const j = await send({ text: '', voice: up.url, dur: dur });
    if (!j || !j.ok) { toast((j && j.err) || '没发出去'); return; }
    if (j.msg) echoMsg(j.msg.id, j.msg.ts, '', '', null, up.url, dur);
  } catch (e) {
    toast('语音发送失败');
  } finally {
    recSending = false;
  }
}
