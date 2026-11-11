// LightGroup · 空间：群里的小广场（动态 + 站内信）
import { S, h, icon, iconBtn, btn, toast, api, go, openSheet, closeSheet, siteName, hhmm } from './core.js';
import { avatar } from './avatar.js';
import { openImage } from './msg.js';
import { shrinkImage } from './chat.js';

let tab = 'feed';          // feed | mine | notice
let tag = '';              // 只看这个标签
let mine = false;          // 只看我发的
let unread = 0;
let tags = [];             // 最近出现过的标签，侧栏列出来
let draft = [];            // 发布框里已上传的图片
let feedRec = false;       // 广场是否开了推荐算法（决定翻页用 off 还是 before）
let feedOff = 0;           // 下一页的游标：推荐算法用 offset，时间序用最后一条 id
let openComment = 0;       // 当前展开评论框的动态 id

export function unreadCount() { return unread; }

export async function refreshUnread() {
  const j = await api('api/notices?count=1');
  if (j && j.ok) { unread = j.unread || 0; emitUnread(); }
  return unread;
}

function emitUnread() {
  window.dispatchEvent(new CustomEvent('lg:notices', { detail: { unread: unread } }));
}

// 通知页就地读掉一条时，左侧「通知」上的数字红点跟着改，别去重拉整页
function syncNoticeBadge() {
  if (!sideEl || !sideEl.isConnected) return;
  const b = sideEl.querySelector('.sp-nav[data-nav="notice"]');
  if (!b) return;
  const old = b.querySelector('.req-badge');
  if (old) old.remove();
  if (unread > 0) b.appendChild(h('span', 'req-badge', unread > 99 ? '99+' : String(unread)));
}

let hostEl = null;
let sideEl = null;

export function renderSpace(host) {
  hostEl = host;
  host.textContent = '';
  const body = h('div', 'page-body sp-page');
  host.appendChild(body);
  const inner = h('div', 'sp-inner');
  body.appendChild(inner);

  inner.appendChild(cover());

  if (tag) {
    const bar = h('div', 'sp-filter');
    bar.appendChild(h('span', '', '只看 #' + tag));
    const clear = btn('取消', 'btn-ghost btn-xs', function () { tag = ''; repaint(); });
    bar.appendChild(clear);
    inner.appendChild(bar);
  }

  const box = h('div', 'sp-body');
  inner.appendChild(box);
  if (tab === 'notice') paintNotices(box);
  else paintFeed(box);
}

/** 中间那一栏：空间自己的导航（广场 / 我的动态 / 通知 + 标签） */
export function renderSideList(host) {
  if (!host) return;
  sideEl = host;
  host.textContent = '';
  host.dataset.pane = 'space';
  const side = h('div', 'sp-side');
  const head = h('div', 'sp-side-head');
  head.appendChild(h('b', '', '空间'));
  head.appendChild(h('span', '', (siteName() || '这个群') + ' · 小广场'));
  side.appendChild(head);

  const nav = h('div', 'sp-navs');
  const items = [
    ['feed', 'fa-compass', '广场', 0],
    ['mine', 'fa-user-pen', '我的动态', 0],
    ['notice', 'fa-bell', '通知', unread],
  ];
  items.forEach(function (it) {
    const on = (it[0] === 'feed' && tab === 'feed' && !mine) || it[0] === tab;
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'sp-nav' + (on && (it[0] !== 'feed' || !mine) ? ' on' : '');
    b.dataset.nav = it[0];
    b.appendChild(icon(it[1]));
    b.appendChild(h('span', '', it[2]));
    if (it[3] > 0) b.appendChild(h('span', 'req-badge', it[3] > 99 ? '99+' : String(it[3])));
    b.addEventListener('click', function () {
      if (it[0] === 'notice') { tab = 'notice'; }
      else { tab = 'feed'; mine = it[0] === 'mine'; }
      tag = '';
      repaint();
    });
    nav.appendChild(b);
  });
  side.appendChild(nav);

  if (tags.length) {
    side.appendChild(h('div', 'sp-side-label', '标签'));
    const tl = h('div', 'sp-navs sp-navs-tags');
    tags.slice(0, 12).forEach(function (t) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'sp-nav sp-nav-tag' + (tag === t ? ' on' : '');
      b.appendChild(icon('fa-hashtag'));
      b.appendChild(h('span', '', t));
      b.addEventListener('click', function () {
        tab = 'feed';
        mine = false;
        tag = tag === t ? '' : t;
        repaint();
      });
      tl.appendChild(b);
    });
    side.appendChild(tl);
  }
  host.appendChild(side);
}

/** 只重画业务区，别碰别的东西 */
function repaint() {
  if (hostEl) renderSpace(hostEl);
  // 中间的导航是同一份状态，选中态和红点要跟着一起变
  if (sideEl && sideEl.isConnected) renderSideList(sideEl);
}

/** 顶上的封面 + 头像 + 发动态入口，朋友圈那一路 */
function cover() {
  const wrap = h('div', 'sp-cover-wrap');
  wrap.appendChild(h('div', 'sp-cover'));

  const row = h('div', 'sp-profile');
  row.appendChild(avatar(S.me || { name: '我' }, 64));

  const meta = h('div', 'sp-profile-meta');
  meta.appendChild(h('b', '', siteName() || '空间'));
  meta.appendChild(h('span', '', '群里的小广场，只有这个群的人看得到'));
  row.appendChild(meta);

  const acts = h('div', 'sp-profile-acts');
  const write = btn('发动态', 'btn-primary btn-sm', openComposer);
  write.insertBefore(icon('fa-pen'), write.firstChild);
  acts.appendChild(write);
  row.appendChild(acts);

  wrap.appendChild(row);
  wrap.appendChild(tabs());
  return wrap;
}

/** 写动态放弹层里，正文区不摆输入框 */
function openComposer() {
  const box = h('div', 'sp-sheet');
  composer(box);
  openSheet('发动态', box, { size: 'lg' });
}

function tabs() {
  const tabs = h('div', 'sp-tabs');
  [['feed', '广场'], ['mine', '我的动态'], ['notice', '通知']].forEach(function (it) {
    const on = (it[0] === 'feed' && tab === 'feed' && !mine) || (it[0] === 'mine' && mine) || (it[0] === 'notice' && tab === 'notice');
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'sp-tab' + (on ? ' on' : '');
    b.appendChild(h('span', '', it[1]));
    if (it[0] === 'notice' && unread > 0) b.appendChild(h('span', 'req-badge', unread > 99 ? '99+' : String(unread)));
    b.addEventListener('click', function () {
      if (it[0] === 'notice') tab = 'notice';
      else { tab = 'feed'; mine = it[0] === 'mine'; }
      tag = '';
      repaint();
    });
    tabs.appendChild(b);
  });
  return tabs;
}

// ---------- 动态 ----------

function paintFeed(box) {
  if (tag) {
    box.appendChild(h('div', 'sp-filter', '正在看 #' + tag));
  }

  const list = h('div', 'sp-list');
  box.appendChild(list);
  list.appendChild(h('div', 'sp-empty', '加载中…'));

  const more = btn('再看一些', 'btn-ghost sp-more', function () {
    const last = list.querySelector('.sp-item:last-of-type');
    const cursor = feedRec ? feedOff : (last ? Number(last.dataset.id || 0) : 0);
    more.disabled = true;
    loadFeed(list, cursor, false).then(function (n) {
      more.disabled = false;
      if (n < 20) more.remove();
    });
  });
  feedOff = 0;
  loadFeed(list, 0, true).then(function (n) { if (n < 20) more.remove(); });
  box.appendChild(more);
}

async function loadFeed(list, before, replace) {
  let q = 'api/moments';
  const qs = [];
  if (tag) qs.push('tag=' + encodeURIComponent(tag));
  if (mine) qs.push('mine=1');
  if (before) qs.push((feedRec ? 'off=' : 'before=') + before);
  if (qs.length) q = q + '?' + qs.join('&');

  const j = await api(q);
  if (!j || !j.ok) {
    if (replace) { list.textContent = ''; list.appendChild(h('div', 'sp-empty', (j && j.err) || '加载失败')); }
    else toast((j && j.err) || '加载失败');
    return 0;
  }
  if (replace) list.textContent = '';
  if (!j.items.length) {
    if (replace) list.appendChild(h('div', 'sp-empty', tag ? '这个标签下还没有动态' : '还没有人发动态，第一条可以是你'));
    return 0;
  }
  feedRec = !!j.recommend && !tag && !mine;
  if (feedRec && typeof j.next_off === 'number') feedOff = j.next_off;
  j.items.forEach(function (m) {
    (m.tags || []).forEach(function (tg) { if (tags.indexOf(tg) < 0) tags.push(tg); });
    list.appendChild(itemNode(m, list));
  });
  return j.items.length;
}

function composer(box) {
  const card = h('div', 'sp-card sp-compose');
  card.classList.add('sp-compose-sheet');
  const row = h('div', 'sp-c-row');
  row.appendChild(avatar(S.me || { name: '我' }, 40));
  const main = h('div', 'sp-c-main');
  row.appendChild(main);
  card.appendChild(row);
  const ta = document.createElement('textarea');
  ta.className = 'tb sp-ta';
  ta.id = 'sp-text';
  ta.rows = 3;
  ta.maxLength = 300;
  ta.placeholder = '这一刻的想法…（可以用 #话题 打标签）';
  main.appendChild(ta);

  const pics = h('div', 'sp-draft');
  main.appendChild(pics);

  const bar = h('div', 'sp-bar');
  const pick = btn('图片', 'btn-ghost btn-sm', function () { pickImages(paintDraft); });
  pick.insertBefore(icon('fa-image'), pick.firstChild);
  bar.appendChild(pick);

  const cnt = h('span', 'sp-count', '0/300');
  bar.appendChild(cnt);
  bar.appendChild(h('div', 'sp-gap'));

  const sendBtn = btn('发布', 'btn-primary btn-sm', async function () {
    const text = String(ta.value || '').trim();
    if (!text && !draft.length) { toast('写点什么或者选一张图'); return; }
    sendBtn.disabled = true;
    const j = await api('api/moments', { text: text, images: draft.slice() });
    sendBtn.disabled = false;
    if (!j || !j.ok) { toast((j && j.err) || '没发出去'); return; }
    ta.value = '';
    draft = [];
    paintDraft();
    cnt.textContent = '0/300';
    toast('发出去了');
    closeSheet();
    repaint();
  });
  bar.appendChild(sendBtn);
  main.appendChild(bar);

  ta.addEventListener('input', function () {
    cnt.textContent = String(ta.value || '').length + '/300';
  });

  function paintDraft() {
    pics.textContent = '';
    draft.forEach(function (url, i) {
      const wrap = h('div', 'sp-draft-item');
      const im = document.createElement('img');
      im.src = url;
      im.alt = '';
      wrap.appendChild(im);
      const x = document.createElement('button');
      x.type = 'button';
      x.className = 'sp-draft-x';
      x.appendChild(icon('fa-xmark'));
      x.addEventListener('click', function () { draft.splice(i, 1); paintDraft(); });
      wrap.appendChild(x);
      pics.appendChild(wrap);
    });
  }
  paintDraft();

  box.appendChild(card);
  return card;
}

function pickImages(repaint) {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/*';
  input.multiple = true;
  input.addEventListener('change', async function () {
    const files = Array.prototype.slice.call(input.files || []);
    for (const file of files) {
      if (draft.length >= 9) { toast('最多 9 张图'); break; }
      if (file.type && !/^image\//.test(file.type)) { toast('选图片文件'); continue; }
      toast('处理图片…');
      let dataUrl;
      try { dataUrl = await shrinkImage(file); }
      catch (e) { toast((e && e.message) || '这张图片读不出来'); continue; }
      const up = await api('api/upload', { data: dataUrl });
      if (!up || !up.ok) { toast((up && up.err) || '上传失败'); continue; }
      draft.push(up.url);
      repaint();
    }
  });
  input.click();
}

function itemNode(m, list) {
  const el = h('div', 'sp-item');
  el.dataset.id = String(m.id);

  const head = h('div', 'sp-head');
  const a = avatar(m.member || { name: '?' }, 40);
  a.addEventListener('click', function (ev) { ev.stopPropagation(); openProfile(m.member); });
  head.appendChild(a);

  const who = h('div', 'sp-who');
  const line = h('div', 'sp-name');
  line.appendChild(h('b', '', (m.member && m.member.name) || '已注销'));
  if (m.member && m.member.badge === 'super') line.appendChild(h('span', 'sp-badge super', '超级管理员'));
  else if (m.member && m.member.badge === 'admin') line.appendChild(h('span', 'sp-badge', '管理员'));
  if (m.member && m.member.kind === 'ai') line.appendChild(h('span', 'sp-badge ai', 'AI'));
  who.appendChild(line);
  who.appendChild(h('div', 'sp-time', when(m.ts)));
  head.appendChild(who);
  head.appendChild(h('div', 'sp-gap'));

  const more = document.createElement('button');
  more.type = 'button';
  more.className = 'sp-more-btn';
  more.appendChild(icon('fa-ellipsis'));
  more.addEventListener('click', function () { openItemMenu(more, m, el, list); });
  head.appendChild(more);
  el.appendChild(head);

  if (m.text) {
    const p = h('div', 'sp-text');
    paintRich(p, m.text);
    el.appendChild(p);
  }

  if (m.images && m.images.length) {
    const grid = h('div', 'sp-imgs n' + Math.min(m.images.length, 9));
    m.images.forEach(function (src) {
      const im = document.createElement('img');
      im.src = src;
      im.loading = 'lazy';
      im.alt = '';
      im.addEventListener('click', function () { openImage(src); });
      grid.appendChild(im);
    });
    el.appendChild(grid);
  }

  const acts = h('div', 'sp-acts');
  const like = document.createElement('button');
  like.type = 'button';
  like.className = 'sp-act' + (m.liked ? ' on' : '');
  like.appendChild(icon(m.liked ? 'fa-solid fa-heart' : 'fa-regular fa-heart'));
  like.appendChild(h('span', '', m.likes ? String(m.likes) : '赞'));
  like.addEventListener('click', async function () {
    const j = await api('api/moment/like', { id: m.id });
    if (!j || !j.ok) { toast((j && j.err) || '点不了'); return; }
    m.liked = j.liked;
    m.likes = j.likes;
    like.className = 'sp-act' + (m.liked ? ' on' : '');
    like.textContent = '';
    like.appendChild(icon(m.liked ? 'fa-solid fa-heart' : 'fa-regular fa-heart'));
    like.appendChild(h('span', '', m.likes ? String(m.likes) : '赞'));
  });
  acts.appendChild(like);

  const cm = document.createElement('button');
  cm.type = 'button';
  cm.className = 'sp-act';
  cm.appendChild(icon('fa-regular fa-comment'));
  cm.appendChild(h('span', '', m.comments && m.comments.length ? String(m.comments.length) : '评论'));
  cm.addEventListener('click', function () {
    openComment = openComment === m.id ? 0 : m.id;
    const old = el.querySelector('.sp-cbox');
    if (old) old.remove();
    if (openComment === m.id) el.appendChild(commentBox(m, el));
  });
  acts.appendChild(cm);
  el.appendChild(acts);

  if (m.comments && m.comments.length) {
    const box = h('div', 'sp-comments');
    m.comments.forEach(function (c) { box.appendChild(commentNode(c, m, el, box)); });
    el.appendChild(box);
  }

  if (openComment === m.id) el.appendChild(commentBox(m, el));
  return el;
}

function commentNode(c, m, el, box) {
  const row = h('div', 'sp-comment');
  row.appendChild(h('b', '', ((c.member && c.member.name) || '已注销') + '：'));
  row.appendChild(h('span', '', c.text));
  if (S.me && (c.member && c.member.id === S.me.id || isAdmin())) {
    const del = iconBtn('fa-xmark', '删掉这条评论', async function () {
      const j = await api('api/moment/comment', { op: 'del', id: m.id, comment_id: c.id });
      if (!j || !j.ok) { toast((j && j.err) || '删不了'); return; }
      row.remove();
      m.comments = (m.comments || []).filter(function (x) { return x.id !== c.id; });
      if (!box.children.length) box.remove();
    });
    row.appendChild(del);
  }
  return row;
}

function commentBox(m, el) {
  const box = h('div', 'sp-cbox');
  const input = document.createElement('input');
  input.type = 'text';
  input.className = 'tb';
  input.placeholder = '说点什么…';
  input.maxLength = 300;
  box.appendChild(input);
  const send = btn('发送', 'btn-primary btn-sm', async function () {
    const text = String(input.value || '').trim();
    if (!text) return;
    const j = await api('api/moment/comment', { id: m.id, text: text });
    if (!j || !j.ok) { toast((j && j.err) || '没发出去'); return; }
    m.comments = (m.comments || []).concat([j.comment]);
    input.value = '';
    openComment = 0;
    repaint();
  });
  box.appendChild(send);
  input.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); send.click(); } });
  setTimeout(function () { input.focus(); }, 30);
  return box;
}

function openItemMenu(anchor, m, el, list) {
  const old = document.querySelector('.sp-menu');
  if (old) old.remove();
  const menu = h('div', 'sp-menu');
  const items = [];
  if (m.can_delete) {
    items.push(['删除', 'fa-trash-can', async function () {
      const j = await api('api/moments', { op: 'delete', id: m.id });
      if (!j || !j.ok) { toast((j && j.err) || '删不了'); return; }
      el.remove();
      toast('已删除');
    }]);
  }
  items.push(['举报', 'fa-flag', function () { reportDialog(m); }]);

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
  menu.style.left = Math.max(8, Math.min(r.left - 120, window.innerWidth - 160)) + 'px';
  menu.style.top = (r.bottom + 6) + 'px';
  setTimeout(function () {
    document.addEventListener('click', function onDoc(ev) {
      if (!document.querySelector('.sp-menu')) { document.removeEventListener('click', onDoc); return; }
      if (!menu.contains(ev.target)) { menu.remove(); document.removeEventListener('click', onDoc); }
    });
  }, 0);
}

function reportDialog(m) {
  const body = h('div', 'sp-form');
  const ta = document.createElement('textarea');
  ta.className = 'tb';
  ta.rows = 4;
  ta.maxLength = 300;
  ta.placeholder = '这条动态哪里不合适？管理员会收到通知。';
  body.appendChild(ta);
  const send = btn('提交举报', 'btn-primary', async function () {
    const reason = String(ta.value || '').trim();
    if (!reason) { toast('写一句原因'); return; }
    const j = await api('api/moment/report', { id: m.id, reason: reason });
    if (!j || !j.ok) { toast((j && j.err) || '提交失败'); return; }
    closeSheet();
    toast('已提交，管理员会看到');
  });
  body.appendChild(send);
  openSheet('举报动态', body, { size: 'md' });
}

function openProfile(m) {
  if (!m || !m.id) return;
  go('#/u/' + (m.kind === 'ai' ? 'a_' : 'h_') + m.id);
}

// #标签 变成可点的 span
function paintRich(el, text) {
  const parts = String(text).split(/(#[^\s#]+)/g);
  parts.forEach(function (p) {
    if (!p) return;
    if (p.charAt(0) === '#' && p.length > 1) {
      const s = h('span', 'sp-tag', p);
      s.addEventListener('click', function () {
        tag = p.slice(1);
        repaint();
      });
      el.appendChild(s);
    } else {
      el.appendChild(document.createTextNode(p));
    }
  });
}

// ---------- 通知 ----------

async function paintNotices(box) {
  const sub = h('div', 'sp-card');
  box.appendChild(sub);
  sub.appendChild(h('div', 'sp-empty', '加载中…'));

  const list = h('div', 'sp-list');
  box.appendChild(list);

  const [j, s] = await Promise.all([api('api/notices'), api('api/subscribe')]);
  sub.textContent = '';
  sub.appendChild(subscribeCard(s && s.ok ? s : null, sub));

  if (!j || !j.ok) { list.appendChild(h('div', 'sp-empty', (j && j.err) || '加载失败')); return; }
  unread = j.unread || 0;
  emitUnread();
  syncNoticeBadge();
  // 服务端 unread 可能是 0，但某几行还带着未读样式；这种时候也得能一键清掉
  const anyUnread = j.items.some(function (n) { return !n.read; });
  let bar = null;
  if (j.items.length && (unread > 0 || anyUnread)) {
    bar = h('div', 'sp-notice-bar');
    const read = btn('全部已读', 'btn-ghost btn-sm', async function () {
      const r = await api('api/notices', { op: 'readAll' });
      if (!r || !r.ok) { toast('失败了'); return; }
      unread = 0;
      emitUnread();
      syncNoticeBadge();
      list.querySelectorAll('.sp-note.unread').forEach(function (el) { el.classList.remove('unread'); });
      list.querySelectorAll('[data-mark]').forEach(function (el) { el.remove(); });
      if (bar && bar.parentNode) bar.remove();
    });
    bar.appendChild(read);
    list.appendChild(bar);
  }
  if (!j.items.length) { list.appendChild(h('div', 'sp-empty', '还没有通知')); return; }

  // 单条已读：只动本地状态和这一行，别重拉整页
  function applyRead(n, row, mark) {
    if (n.read) return;
    n.read = true;
    if (unread > 0) unread--;
    emitUnread();
    syncNoticeBadge();
    row.classList.remove('unread');
    if (mark) { mark.disabled = true; mark.textContent = '已读'; }
    // 都读完了就把「全部已读」收掉
    if (!unread && bar && bar.parentNode) bar.remove();
  }

  // 请求失败时把刚才那一下倒回去
  function undoRead(n, row, mark) {
    if (!n.read) return;
    n.read = false;
    unread++;
    emitUnread();
    syncNoticeBadge();
    row.classList.add('unread');
    if (mark) { mark.disabled = false; mark.textContent = '标为已读'; }
    if (bar && !bar.parentNode) list.insertBefore(bar, list.firstChild);
  }

  j.items.forEach(function (n) {
    const row = h('div', 'sp-note' + (n.read ? '' : ' unread'));
    const head = h('div', 'sp-note-head');
    head.appendChild(h('b', '', n.title));
    head.appendChild(h('span', 'sp-time', when(n.ts)));
    row.appendChild(head);
    if (n.body) row.appendChild(h('div', 'sp-note-body', n.body));
    const foot = h('div', 'sp-note-foot');
    if (n.from) foot.appendChild(h('span', 'sp-note-from', '来自 ' + n.from));
    let mark = null;
    if (n.link) {
      const goBtn = btn('去看看', 'btn-ghost btn-sm', async function () {
        // 先本地生效，界面立刻有反应；再发请求，失败回滚
        const wasUnread = !n.read;
        goBtn.disabled = true;
        if (wasUnread) applyRead(n, row, mark);
        const r = await api('api/notices', { op: 'read', id: n.id });
        goBtn.disabled = false;
        if (wasUnread && (!r || !r.ok)) undoRead(n, row, mark);
        if (n.link.charAt(0) !== '#') { location.href = n.link; return; }
        // go() 遇到同一个 hash 只会发事件、不重绘，这里自己补一次
        const same = location.hash === n.link;
        go(n.link);
        if (same) { repaint(); toast('已标为已读'); }
      });
      foot.appendChild(goBtn);
    }
    if (!n.read) {
      mark = btn('标为已读', 'btn-ghost btn-sm', async function () {
        const r = await api('api/notices', { op: 'read', id: n.id });
        if (!r || !r.ok) { toast('失败了'); return; }
        applyRead(n, row, mark);
      });
      mark.dataset.mark = '1';
      foot.appendChild(mark);
    }
    if (foot.children.length) row.appendChild(foot);
    list.appendChild(row);
  });
}

function subscribeCard(s, box) {
  const card = h('div', 'sp-sub');
  if (!s) { card.appendChild(h('div', 'sp-empty', '邮件提醒暂时用不了')); return card; }
  if (!s.available) {
    card.appendChild(h('b', '', '邮件提醒'));
    card.appendChild(h('div', 'sp-empty', '站点还没开邮件提醒，需要超管在后台配好邮箱'));
    return card;
  }
  card.appendChild(h('b', '', '邮件提醒'));
  card.appendChild(h('div', 'sp-sub-tip', '收到站内信时会发一封邮件到你填的邮箱。聊天消息不会发邮件。'));

  if (s.subscribed) {
    card.appendChild(h('div', 'sp-sub-line', '已订阅：' + s.email));
    const off = btn('取消订阅', 'btn-ghost btn-sm', async function () {
      const r = await api('api/subscribe', { op: 'off' });
      if (!r || !r.ok) { toast('取消失败'); return; }
      toast('已取消');
      repaint();
    });
    card.appendChild(off);
    return card;
  }

  if (s.pending) {
    card.appendChild(h('div', 'sp-sub-line', '激活邮件已发给 ' + s.email));
    const row = h('div', 'sp-cbox');
    const code = document.createElement('input');
    code.type = 'text';
    code.className = 'tb';
    code.placeholder = '邮件里的 6 位验证码';
    code.maxLength = 6;
    row.appendChild(code);
    const ok = btn('确认', 'btn-primary btn-sm', async function () {
      const v = String(code.value || '').trim();
      if (!v) { toast('填验证码'); return; }
      const r = await api('api/subscribe/verify', { code: v });
      if (!r || !r.ok) { toast((r && r.err) || '验证失败'); return; }
      toast('订阅成功');
      repaint();
    });
    row.appendChild(ok);
    card.appendChild(row);
    return card;
  }

  const row = h('div', 'sp-cbox');
  const email = document.createElement('input');
  email.type = 'email';
  email.className = 'tb';
  email.placeholder = '你的邮箱';
  row.appendChild(email);
  const send = btn('发激活邮件', 'btn-primary btn-sm', async function () {
    const v = String(email.value || '').trim();
    if (!v) { toast('填邮箱'); return; }
    send.disabled = true;
    const r = await api('api/subscribe', { email: v });
    send.disabled = false;
    if (!r || !r.ok) { toast((r && r.err) || '发不出去'); return; }
    toast('激活邮件发出去了，去收一下');
    repaint();
  });
  row.appendChild(send);
  card.appendChild(row);
  return card;
}

function isAdmin() {
  return !!(S.me && (S.me.role === 'admin' || S.me.role === 'super'));
}

function when(ts) {
  const d = new Date(Number(ts) || 0);
  const now = new Date();
  if (d.toDateString() === now.toDateString()) return hhmm(ts);
  return (d.getMonth() + 1) + '月' + d.getDate() + '日 ' + hhmm(ts);
}
