// LightGroup · 用户资料：头像悬浮卡、资料页、markdown 渲染器、markdown 简介编辑器
// 依赖方向：people.js -> profile.js -> core.js / avatar.js。
// profile.js 不 import people.js（会成环），好友/私聊那些能力由 people.js 用 initProfile() 注进来。
import { S, h, icon, btn, toast, api, go, openSheet, closeSheet, pageHead, applyLayout } from './core.js';
import { avatar, uidOf } from './avatar.js';

// ---------- markdown 渲染器 ----------
// 自己写的小渲染器，不引任何外部库：
//   1. 先整体把 & < > " 和单引号转义掉，原文里的 HTML 就再也变不回标签；
//   2. 只拼自己生成的白名单标签；
//   3. 链接只放行 http(s) 和 mailto，javascript: / data: / vbscript: 之类降级成纯文本；
//   4. 图片语法只渲染成链接，不产出 img 标签（也就没有 onerror 这类属性注入的口子）。

// MD-START
function mdEsc(s) {
  return String(s === null || s === undefined ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function mdUnesc(s) {
  return String(s === null || s === undefined ? '' : s)
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&amp;/g, '&');
}

// 只认 http/https/mailto，其它协议一律返回空串（由调用方降级成纯文本）
function mdSafeUrl(raw) {
  const u = mdUnesc(String(raw === null || raw === undefined ? '' : raw)).trim();
  if (!u) return '';
  if (/^https?:\/\/[^\s]+$/i.test(u)) return u;
  if (/^mailto:[^\s]+$/i.test(u)) return u;
  return '';
}

function mdLink(url, text) {
  const u = mdSafeUrl(url);
  if (!u) return text;
  return '<a class="pf-md-link" href="' + mdEsc(u) + '" target="_blank" rel="nofollow noopener noreferrer">' + text + '</a>';
}

// 行内标记：输入必须是「已转义」的文本，输出只含我们自己拼的标签
function mdInline(esc) {
  let s = String(esc === null || esc === undefined ? '' : esc);
  const codes = [];
  // 行内代码先摘出来，免得里面的星号下划线被当成强调
  s = s.replace(/\x60([^\x60]+?)\x60/g, function (m, code) {
    void m;
    codes.push(code);
    return '\u0000C' + (codes.length - 1) + '\u0000';
  });
  // 图片语法：只给链接，不给 img
  s = s.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, function (m, alt, url) {
    void m;
    return mdLink(url, alt || '图片');
  });
  // 普通链接
  s = s.replace(/\[([^\]]*)\]\(([^)\s]+)\)/g, function (m, text, url) {
    void m;
    return mdLink(url, text || mdUnesc(url));
  });
  // 粗体 / 斜体 / 删除线
  // 强调符号两侧不能贴着空白，否则「5 * 3 * 2」这种算式会被当成斜体
  s = s.replace(/\*\*(\S(?:[^*]*\S)?)\*\*/g, '<strong>$1</strong>');
  s = s.replace(/__(\S(?:[^_]*\S)?)__/g, '<strong>$1</strong>');
  s = s.replace(/(^|[^*])\*(\S(?:[^*\n]*\S)?)\*/g, '$1<em>$2</em>');
  s = s.replace(/(^|[^_])_(\S(?:[^_\n]*\S)?)_/g, '$1<em>$2</em>');
  s = s.replace(/~~([^~]+)~~/g, '<del>$1</del>');
  // 还原行内代码
  s = s.replace(/\u0000C(\d+)\u0000/g, function (m, i) {
    void m;
    return '<code class="pf-md-code">' + (codes[Number(i)] || '') + '</code>';
  });
  return s;
}

const MD_HR = /^\s*([-*_])\1{2,}[\s\-*_]*$/;
const MD_FENCE = /^\s*(\x60{3,}|~{3,})\s*([A-Za-z0-9+#._-]*)\s*$/;

function mdBlocks(text) {
  const src = String(text === null || text === undefined ? '' : text).replace(/\r\n?/g, '\n');
  const lines = src.split('\n');
  const out = [];
  let para = [];
  let i = 0;

  function flush() {
    if (!para.length) return;
    out.push('<p>' + mdInline(mdEsc(para.join('\n'))).replace(/\n/g, '<br>') + '</p>');
    para = [];
  }

  while (i < lines.length) {
    const line = lines[i];

    // 代码块：整段只转义不解析
    const fence = MD_FENCE.exec(line);
    if (fence) {
      flush();
      const mark = mdUnesc(fence[1]).charAt(0);
      const lang = String(fence[2] || '').replace(/[^A-Za-z0-9+#._-]/g, '');
      const close = new RegExp('^\\s*' + (mark === '~' ? '~' : '\\x60') + '{3,}\\s*$');
      i++;
      const buf = [];
      while (i < lines.length && !close.test(lines[i])) {
        buf.push(lines[i]);
        i++;
      }
      i++;
      const cls = lang ? ' class="pf-md-lang pf-md-lang-' + mdEsc(lang) + '"' : '';
      out.push('<pre class="pf-md-pre"><code' + cls + '>' + mdEsc(buf.join('\n')) + '</code></pre>');
      continue;
    }

    // 空行：结束当前段落
    if (/^\s*$/.test(line)) {
      flush();
      i++;
      continue;
    }

    // 分隔线
    if (MD_HR.test(line)) {
      flush();
      out.push('<hr class="pf-md-hr">');
      i++;
      continue;
    }

    // 标题
    const hd = /^\s{0,3}(#{1,6})\s+(.*)$/.exec(line);
    if (hd) {
      flush();
      const lv = hd[1].length;
      const t = hd[2].replace(/\s+#+\s*$/, '');
      out.push('<h' + lv + ' class="pf-md-h pf-md-h' + lv + '">' + mdInline(mdEsc(t)) + '</h' + lv + '>');
      i++;
      continue;
    }

    // 引用
    if (/^\s*>\s?/.test(line)) {
      flush();
      const buf = [];
      while (i < lines.length && /^\s*>\s?/.test(lines[i])) {
        buf.push(lines[i].replace(/^\s*>\s?/, ''));
        i++;
      }
      out.push('<blockquote class="pf-md-quote">' + mdBlocks(buf.join('\n')) + '</blockquote>');
      continue;
    }

    // 无序列表
    if (/^\s*[-*+]\s+/.test(line)) {
      flush();
      const buf = [];
      while (i < lines.length && /^\s*[-*+]\s+/.test(lines[i])) {
        buf.push(lines[i].replace(/^\s*[-*+]\s+/, ''));
        i++;
      }
      out.push('<ul class="pf-md-ul">' + buf.map(function (t) {
        return '<li>' + mdInline(mdEsc(t)) + '</li>';
      }).join('') + '</ul>');
      continue;
    }

    // 有序列表
    if (/^\s*\d+[.)]\s+/.test(line)) {
      flush();
      const buf = [];
      while (i < lines.length && /^\s*\d+[.)]\s+/.test(lines[i])) {
        buf.push(lines[i].replace(/^\s*\d+[.)]\s+/, ''));
        i++;
      }
      out.push('<ol class="pf-md-ol">' + buf.map(function (t) {
        return '<li>' + mdInline(mdEsc(t)) + '</li>';
      }).join('') + '</ol>');
      continue;
    }

    para.push(line);
    i++;
  }
  flush();
  return out.join('');
}

function mdHtml(md) {
  return mdBlocks(md);
}

// 纯文本摘要：卡片上只放一小段
function mdExcerpt(md, max) {
  const lim = Math.max(1, Number(max) || 90);
  let s = String(md === null || md === undefined ? '' : md).replace(/\r\n?/g, '\n');
  s = s.replace(/\x60{3,}[A-Za-z0-9+#._-]*/g, ' ');
  s = s.replace(/!\[([^\]]*)\]\([^)]*\)/g, '$1');
  s = s.replace(/\[([^\]]*)\]\([^)]*\)/g, '$1');
  s = s.replace(/^\s{0,3}#{1,6}\s+/gm, '');
  s = s.replace(/^\s*>\s?/gm, '');
  s = s.replace(/^\s*[-*+]\s+/gm, '');
  s = s.replace(/^\s*\d+[.)]\s+/gm, '');
  s = s.replace(/^\s*([-*_])\1{2,}[\s\-*_]*$/gm, ' ');
  s = s.replace(/(\*\*|__)(.+?)\1/g, '$2');
  s = s.replace(/~~(.+?)~~/g, '$1');
  s = s.replace(/\x60+([^\x60]*)\x60+/g, '$1');
  s = s.replace(/\*([^*\n]+?)\*/g, '$1');
  s = s.replace(/(^|[\s(])_([^_\n]+?)_/g, '$1$2');
  s = s.replace(/\s+/g, ' ').trim();
  if (s.length <= lim) return s;
  return s.slice(0, lim).trim() + '…';
}
// MD-END

// ---------- 样式注入 ----------
let cssDone = false;
function injectCss() {
  if (cssDone) return;
  cssDone = true;
  if (document.querySelector('link[data-lg-profile]')) return;
  try {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.setAttribute('data-lg-profile', '1');
    // 正常情况下 app-shell.php 已经用 filemtime 注入过这条样式；这里只是兜底，
    // 所以 ?v= 写个固定值就行，不必跟着改。
    link.href = new URL('../profile.css', import.meta.url).href + '?v=3';
    document.head.appendChild(link);
  } catch (e) { void e; }
}

// ---------- 状态 ----------
// people.js 注进来的能力；没注进来就退化成「只能看」
let HOST = null;
const CACHE = new Map();
const URE = /^#\/u\/([A-Za-z0-9_-]+)$/;

export function initProfile(host) {
  if (host) HOST = host;
  injectCss();
  watchRoute();
}

function keyOf(v) { return String(v === null || v === undefined ? '' : v); }

function uidOfMember(m) {
  if (!m) return '';
  return keyOf(uidOf(m) || m.uid || m.id);
}

function roleText(p) {
  if (!p) return '';
  if (p.role === 'super') return '超级管理员';
  if (p.role === 'admin') return '管理员';
  return '群成员';
}

function fmtDay(ts) {
  const n = Number(ts) || 0;
  if (!n) return '';
  const d = new Date(n);
  if (isNaN(d.getTime())) return '';
  return d.getFullYear() + '/' + (d.getMonth() + 1) + '/' + d.getDate();
}

function subtitle(p, m) {
  const mm = p || m || {};
  if (mm.kind === 'ai') return mm.owner ? 'AI · ' + mm.owner + ' 的' : 'AI 成员';
  const bits = [roleText(mm)];
  if (mm.scope === 'cluster') bits.push('别的群');
  else if (mm.scope === 'other') bits.push('不在同一圈子');
  if (mm.joined) bits.push('加入于 ' + fmtDay(mm.joined));
  return bits.join(' · ');
}

function badges(box, p) {
  const d = p || {};
  if (d.kind === 'ai') box.appendChild(h('span', 'tag-ai', 'AI'));
  if (d.role === 'super') box.appendChild(h('span', 'badge', '超级管理员'));
  else if (d.role === 'admin') box.appendChild(h('span', 'badge', '管理员'));
  if (d.isMe) box.appendChild(h('span', 'badge', '我'));
  if (d.friends === 'friend') box.appendChild(h('span', 'badge', '好友'));
}

// ---------- 数据 ----------
export async function loadUserProfile(uid, force) {
  const key = keyOf(uid);
  if (!key) return null;
  if (!force && CACHE.has(key)) return CACHE.get(key);
  // 自己的资料先用内存里的，省一次请求（编辑保存后会把缓存清掉）
  if (!force && S.me && keyOf(S.me.id) === key) {
    const own = {
      id: S.me.id, uid: key, name: S.me.name, kind: S.me.kind, role: S.me.role || 'member',
      color: S.me.color, bio: S.me.bio || '', avatar: S.me.avatar || '', joined: S.me.joined || 0,
      owner: S.me.owner || '', isMe: true, friends: 'me'
    };
    CACHE.set(key, own);
    return own;
  }
  const j = await api('api/u/' + encodeURIComponent(key));
  if (j && j.ok && j.profile) {
    const p = j.profile;
    if (!p.uid) p.uid = uidOfMember(p);
    CACHE.set(key, p);
    return p;
  }
  return null;
}

function clearProfileCache() { CACHE.clear(); }

// ---------- 悬浮资料卡 ----------
let popEl = null;
let maskEl = null;
let popUid = '';

export function closeUserCard() {
  if (popEl && popEl.parentNode) popEl.parentNode.removeChild(popEl);
  if (maskEl && maskEl.parentNode) maskEl.parentNode.removeChild(maskEl);
  popEl = null;
  maskEl = null;
  popUid = '';
}

export function openUserCard(member, anchor) {
  const uid = uidOfMember(member);
  if (!uid) return;
  injectCss();
  closeUserCard();
  popUid = uid;

  maskEl = h('div', 'pf-mask');
  maskEl.addEventListener('click', closeUserCard);
  popEl = h('div', 'pf-pop');
  popEl.setAttribute('data-uid', uid);
  document.body.appendChild(maskEl);
  document.body.appendChild(popEl);

  paintCard(popEl, member, null);
  placeCard(popEl, anchor);

  loadUserProfile(uid).then(function (p) {
    if (!popEl || popUid !== uid) return;
    if (!p) return;
    paintCard(popEl, member, p);
    placeCard(popEl, anchor);
  }).catch(function () { /* 拉不到就先显示列表里那份 */ });
}

function paintCard(el, member, p) {
  const data = p || member || {};
  const uid = uidOfMember(data) || popUid;
  el.textContent = '';

  const head = h('div', 'pf-pop-head');
  const av = avatar(Object.assign({}, member || {}, data, { id: uid }), 48);
  av.classList.remove('ava-click');
  av.removeAttribute('data-uid');
  head.appendChild(av);

  const info = h('div', 'pf-pop-i');
  const nm = h('div', 'pf-pop-n');
  nm.appendChild(h('b', '', data.name || '匿名'));
  badges(nm, data);
  info.appendChild(nm);
  info.appendChild(h('div', 'pf-pop-s', subtitle(p, member)));
  head.appendChild(info);
  el.appendChild(head);

  const bio = h('div', 'pf-pop-bio md-body');
  if (!p) {
    bio.appendChild(h('div', 'pf-pop-empty', '简介加载中…'));
  } else if (!String(p.bio || '').trim()) {
    bio.appendChild(h('div', 'pf-pop-empty', '还没有写简介'));
  } else {
    bio.innerHTML = mdHtml(p.bio);
  }
  el.appendChild(bio);

  const acts = h('div', 'pf-pop-acts');
  acts.appendChild(btn('查看资料', 'btn btn-sm btn-primary', function () {
    closeUserCard();
    goToUser(uid);
  }));
  cardActions(acts, data, p);
  el.appendChild(acts);
}

// 卡片 / 资料页共用的一排操作按钮
function cardActions(box, data, p) {
  const d = data || {};
  const uid = uidOfMember(d);
  const isMe = !!(p && p.isMe) || !!(S.me && keyOf(S.me.id) === uid);
  const st = (p && p.friends) || (HOST && HOST.friendState ? HOST.friendState(uid) : 'none');
  // 圈子互通时能点到别的群的成员：加好友、私聊都只在本群内成立，这里只留一句说明
  const cross = p && p.scope && p.scope !== 'same';
  if (!isMe && cross) {
    box.appendChild(h('span', 'pf-cross', p.scope === 'cluster'
      ? '不在这个群，只能看看资料'
      : '不在同一个圈子，加不了好友'));
    return;
  }
  if (isMe) {
    box.appendChild(btn('编辑简介', 'btn btn-sm btn-ghost', function () {
      openBioEditor((p && p.bio) || (S.me && S.me.bio) || '', function () {
        clearProfileCache();
      });
    }));
    return;
  }
  if (st === 'friend' && d.kind !== 'ai') {
    box.appendChild(btn('发消息', 'btn btn-sm', function () {
      closeUserCard();
      if (HOST && HOST.openDm) HOST.openDm({ id: uid, name: d.name, kind: d.kind });
    }));
  }
  if (st === 'incoming') {
    box.appendChild(btn('同意好友申请', 'btn btn-sm', function () {
      closeUserCard();
      if (HOST && HOST.acceptFriend) HOST.acceptFriend(uid);
    }));
  } else if (st === 'outgoing') {
    box.appendChild(btn('已申请', 'btn btn-sm btn-ghost', function () { toast('已经申请过了'); }));
  } else if (st === 'none' && d.kind !== 'ai') {
    box.appendChild(btn('加好友', 'btn btn-sm', function () {
      closeUserCard();
      if (HOST && HOST.addFriend) HOST.addFriend({ id: uid, name: d.name, kind: d.kind });
    }));
  }
}

// 桌面端贴着头像弹，空间不够就左右翻面；窄屏贴底
function placeCard(el, anchor) {
  const pad = 10;
  const vw = window.innerWidth || 1024;
  const vh = window.innerHeight || 768;
  el.classList.remove('pf-pop-sheet');
  el.style.left = '0px';
  el.style.top = '0px';
  const box = el.getBoundingClientRect();
  const w = box.width || 300;
  const ht = box.height || 240;
  const r = anchor && anchor.getBoundingClientRect ? anchor.getBoundingClientRect() : null;
  if (vw <= 640) {
    el.classList.add('pf-pop-sheet');
    el.style.left = '';
    el.style.top = '';
    return;
  }
  let left;
  let top;
  if (r && (r.width || r.height)) {
    left = r.right + 12;
    if (left + w + pad > vw) left = r.left - w - 12;
    top = r.top + r.height / 2 - ht / 2;
  } else {
    left = (vw - w) / 2;
    top = vh / 3;
  }
  left = Math.max(pad, Math.min(vw - w - pad, left));
  top = Math.max(pad, Math.min(vh - ht - pad, top));
  el.style.left = Math.round(left) + 'px';
  el.style.top = Math.round(top) + 'px';
}

function goToUser(uid) {
  const target = '#/u/' + uid;
  if (location.hash === target) { checkRoute(); return; }
  go(target);
}

// ---------- 独立资料页 #/u/<uid> ----------
let watchers = 0;

function hostEl() { return document.getElementById('page-host'); }
function uidFromHash() {
  const m = URE.exec(String(location.hash || ''));
  return m ? m[1] : '';
}

async function stopChat() {
  try {
    const m = await import('./chat.js');
    if (m && typeof m.stop === 'function') m.stop();
  } catch (e) { void e; }
}

function backFromUser() {
  if (history.length > 1) { history.back(); return; }
  go('#/c/' + (S.curId || 0));
}

let hostWaits = 0;

function renderUserPage(uid) {
  const host = hostEl();
  if (!host) {
    // 外壳还没建好（极早期深链），最多等 ~3.6s
    hostWaits++;
    if (hostWaits < 30) setTimeout(checkRoute, 120);
    return;
  }
  hostWaits = 0;
  stopChat();
  S.page = 'u';
  try { applyLayout(); } catch (e) { void e; }
  host.textContent = '';
  const page = h('div', 'pf-page');
  page.setAttribute('data-uid', uid);
  host.appendChild(page);
  paintUserPage(uid, CACHE.get(uid) || null);
  loadUserProfile(uid).then(function (p) {
    if (uidFromHash() !== uid) return;
    if (p) paintUserPage(uid, p);
    else paintMissingUser(uid);
  }).catch(function () { /* 拉不到就停在列表里那份 */ });
  // app.js / chat.js 稍后可能又写一次 page-host，多补几次
  recheck(5);
}

function recheck(n) {
  if (n <= 0) return;
  setTimeout(function () { checkRoute(); recheck(n - 1); }, 120);
}

// 相对时间：动态列表里「3 小时前」比日期好读
function whenText(ts) {
  const n = Number(ts) || 0;
  if (!n) return '';
  const diff = Math.floor(Date.now() / 1000) - n;
  if (diff < 60) return '刚刚';
  if (diff < 3600) return Math.floor(diff / 60) + ' 分钟前';
  if (diff < 86400) return Math.floor(diff / 3600) + ' 小时前';
  if (diff < 86400 * 7) return Math.floor(diff / 86400) + ' 天前';
  return fmtDay(n);
}

function paintUserPage(uid, p) {
  const host = hostEl();
  if (!host) return;
  const page = host.querySelector('.pf-page');
  if (!page || page.getAttribute('data-uid') !== uid) return;
  const d = p || null;
  page.textContent = '';

  const head = h('div', 'pf-page-head');
  head.appendChild(btn('← 返回', 'btn btn-sm btn-ghost', backFromUser));
  head.appendChild(h('div', 'pf-page-title', '用户资料'));
  page.appendChild(head);

  // ---------- 头图 + 身份 ----------
  // 头图和空间页同一套底色（同一个 --accent 渐变），改了圈子主题这里跟着变
  const hero = h('div', 'pf-hero');
  hero.appendChild(h('div', 'pf-hero-cover'));

  const inner = h('div', 'pf-hero-inner');
  const seed = Object.assign({}, d || {}, { id: uid });
  const av = avatar(seed, 92);
  av.classList.remove('ava-click');
  av.removeAttribute('data-uid');
  av.removeAttribute('title');
  const avWrap = h('div', 'pf-hero-ava');
  avWrap.appendChild(av);
  inner.appendChild(avWrap);

  const info = h('div', 'pf-hero-info');
  const nm = h('div', 'pf-hero-name');
  nm.appendChild(h('b', '', (d && d.name) || '匿名'));
  if (d) badges(nm, d);
  info.appendChild(nm);
  info.appendChild(h('div', 'pf-hero-handle',
    d ? '@' + String(d.name || 'member').replace(/\s+/g, '') : '加载中…'));
  if (d) {
    const bits = [];
    if (d.kind === 'ai') bits.push(d.owner ? 'AI · ' + d.owner + ' 的' : 'AI 成员');
    else bits.push(roleText(d));
    if (d.joined) bits.push('自 ' + fmtDay(d.joined) + ' 成为成员');
    if (d.scope === 'cluster') bits.push('不在这个群');
    else if (d.scope === 'other') bits.push('不在同一圈子');
    info.appendChild(h('div', 'pf-hero-sub', bits.join(' · ')));

    const bio = h('div', 'pf-hero-bio md-body');
    if (!String(d.bio || '').trim()) bio.appendChild(h('div', 'pf-pop-empty', '这个人还没写介绍。'));
    else bio.innerHTML = mdHtml(d.bio);
    info.appendChild(bio);

    const acts = h('div', 'pf-hero-acts');
    cardActions(acts, d, d);
    if (acts.childNodes.length) info.appendChild(acts);
  }
  inner.appendChild(info);
  hero.appendChild(inner);

  const tabs = h('div', 'pf-tabs');
  const tabDefs = [['moments', '动态'], ['comments', '评论'], ['about', '资料']];
  const tabBtns = {};
  tabDefs.forEach(function (t) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'pf-tab' + (t[0] === 'moments' ? ' on' : '');
    b.setAttribute('data-tab', t[0]);
    b.textContent = t[1];
    b.addEventListener('click', function () { showTab(t[0]); });
    tabBtns[t[0]] = b;
    tabs.appendChild(b);
  });
  hero.appendChild(tabs);
  page.appendChild(hero);

  const body = h('div', 'pf-tab-body');
  page.appendChild(body);

  let curTab = 'moments';
  const loaded = {};
  const counts = { moments: 0, comments: 0 };

  function setTabLabel(name) {
    const base = name === 'moments' ? '动态' : '评论';
    tabBtns[name].textContent = counts[name] > 0 ? base + ' ' + counts[name] : base;
  }

  function showTab(name) {
    curTab = name;
    Object.keys(tabBtns).forEach(function (k) { tabBtns[k].classList.toggle('on', k === name); });
    body.textContent = '';
    if (name === 'about') { paintAbout(); return; }
    paintList(name);
  }

  function paintAbout() {
    const card = h('div', 'pf-card pf-card-main');
    card.appendChild(h('div', 'pf-card-title', '资料'));
    const meta = h('div', 'pf-card-meta');
    meta.appendChild(h('span', 'pf-card-tag', '成员 ID：' + uid));
    if (d && d.joined) meta.appendChild(h('span', 'pf-card-tag', '加入于 ' + fmtDay(d.joined)));
    if (d && d.kind === 'ai') meta.appendChild(h('span', 'pf-card-tag', 'AI 成员'));
    card.appendChild(meta);
    const stat = h('div', 'pf-stat-row');
    stat.appendChild(statCell('动态', counts.moments));
    stat.appendChild(statCell('评论', counts.comments));
    card.appendChild(stat);
    body.appendChild(card);
  }

  function statCell(label, n) {
    const c = h('div', 'pf-stat');
    c.appendChild(h('b', '', String(n)));
    c.appendChild(h('span', '', label));
    return c;
  }

  async function paintList(name) {
    body.textContent = '';
    body.appendChild(h('div', 'pf-empty', '正在加载…'));
    const q = name === 'moments'
      ? 'api/moments?u=' + encodeURIComponent(uid)
      : 'api/moments?cu=' + encodeURIComponent(uid);
    let j = null;
    try { j = await api(q); } catch (e) { void e; }
    if (curTab !== name) return;
    body.textContent = '';
    if (!j || !j.ok) {
      body.appendChild(h('div', 'pf-empty', '看不到他的动态：' + ((j && j.err) || '网络断了')));
      return;
    }
    const items = j.items || [];
    counts[name] = items.length;
    setTabLabel(name);
    loaded[name] = items;
    if (!items.length) {
      body.appendChild(h('div', 'pf-empty', name === 'moments' ? '还没发过动态' : '还没评论过别人'));
      return;
    }
    const list = h('div', 'pf-feed');
    items.forEach(function (it) {
      list.appendChild(name === 'moments' ? momentRow(it) : commentRow(it));
    });
    body.appendChild(list);
  }

  function momentRow(it) {
    const row = h('div', 'pf-item');
    const who = it.member || seed;
    const a = avatar(who, 40);
    a.removeAttribute('data-uid');
    a.classList.remove('ava-click');
    row.appendChild(wrapAva(a));
    const main = h('div', 'pf-item-main');
    const line = h('div', 'pf-item-head');
    line.appendChild(h('b', '', (who && who.name) || '匿名'));
    line.appendChild(h('span', 'pf-item-time', whenText(it.ts)));
    if (it.tags && it.tags.length) {
      it.tags.slice(0, 3).forEach(function (t) {
        line.appendChild(h('span', 'pf-item-tag', '#' + t));
      });
    }
    main.appendChild(line);
    const text = String(it.text || '');
    main.appendChild(h('div', 'pf-item-text', text));
    if (it.images && it.images.length) {
      const grid = h('div', 'pf-item-imgs');
      it.images.slice(0, 9).forEach(function (src) {
        const im = document.createElement('img');
        im.src = src;
        im.alt = '';
        im.loading = 'lazy';
        grid.appendChild(im);
      });
      main.appendChild(grid);
    }
    const foot = h('div', 'pf-item-foot');
    const metric = function (ic, n) {
      const span = h('span', 'pf-metric', '');
      span.appendChild(icon(ic));
      span.appendChild(document.createTextNode(' ' + n));
      return span;
    };
    foot.appendChild(metric('fa-heart', it.likes || 0));
    foot.appendChild(metric('fa-comment', (it.comments && it.comments.length) || 0));
    const go2 = document.createElement('button');
    go2.type = 'button';
    go2.className = 'btn btn-sm btn-ghost';
    go2.textContent = '去空间看';
    go2.addEventListener('click', function (ev) { ev.stopPropagation(); go('#/space'); });
    foot.appendChild(go2);
    main.appendChild(foot);
    row.appendChild(main);
    return row;
  }

  function commentRow(it) {
    const row = h('div', 'pf-item');
    row.appendChild(wrapAva(avatar({ id: uid, name: (d && d.name) || '他' }, 40)));
    const main = h('div', 'pf-item-main');
    const line = h('div', 'pf-item-head');
    line.appendChild(h('b', '', (d && d.name) || '匿名'));
    line.appendChild(h('span', 'pf-item-time', whenText(it.ts)));
    line.appendChild(h('span', 'pf-item-time', '回复了 ' + ((it.moment && it.moment.member && it.moment.member.name) || '一条动态')));
    main.appendChild(line);
    main.appendChild(h('div', 'pf-item-quote', String(it.moment && it.moment.text || '（图片）').slice(0, 80)));
    main.appendChild(h('div', 'pf-item-text', String(it.text || '')));
    row.appendChild(main);
    return row;
  }

  function wrapAva(a) {
    a.removeAttribute('data-uid');
    a.classList.remove('ava-click');
    const w = h('div', 'pf-item-ava');
    w.appendChild(a);
    return w;
  }

  showTab('moments');
}

// 成员不存在 / 接口挂了：别一直停在「加载中」
function paintMissingUser(uid) {
  const host = hostEl();
  if (!host) return;
  const page = host.querySelector('.pf-page');
  if (!page || page.getAttribute('data-uid') !== uid) return;
  page.textContent = '';
  const head = h('div', 'pf-page-head');
  head.appendChild(btn('← 返回', 'btn btn-sm btn-ghost', backFromUser));
  head.appendChild(h('div', 'pf-page-title', '用户资料'));
  page.appendChild(head);
  const card = h('div', 'pf-card');
  card.appendChild(h('div', 'pf-pop-empty', '没有找到这个成员：可能不在当前的群，也可能已经注销了'));
  page.appendChild(card);
}

// 保存简介后把资料页上的简介就地刷新
function refreshUserPageBio(bio) {
  const host = hostEl();
  if (!host) return;
  const page = host.querySelector('.pf-page');
  if (!page) return;
  const box = page.querySelector('.pf-card-bio');
  if (!box) return;
  box.textContent = '';
  if (!String(bio || '').trim()) {
    box.appendChild(h('div', 'pf-pop-empty', '还没有写简介'));
    return;
  }
  box.innerHTML = mdHtml(bio);
}

// ---------- markdown 简介编辑器 ----------
const ED_TOOLS = [
  { ic: 'fa-bold', tip: '粗体 **文字**', wrap: ['**', '**'], ph: '粗体' },
  { ic: 'fa-italic', tip: '斜体 *文字*', wrap: ['*', '*'], ph: '斜体' },
  { ic: 'fa-heading', tip: '标题 #', line: '# ', ph: '标题' },
  { ic: 'fa-list-ul', tip: '无序列表 -', line: '- ', ph: '列表项' },
  { ic: 'fa-list-ol', tip: '有序列表 1.', line: '1. ', ph: '列表项' },
  { ic: 'fa-quote-left', tip: '引用 >', line: '> ', ph: '引用' },
  { ic: 'fa-link', tip: '链接 [文字](地址)', link: true, ph: '链接文字' },
  { ic: 'fa-code', tip: '行内代码', wrap: ['\x60', '\x60'], ph: 'code' },
  { ic: 'fa-file-code', tip: '代码块', block: true }
];

export function openBioEditor(bio, onSaved, title) {
  injectCss();
  const box = h('div', 'pf-ed');
  const bar = h('div', 'pf-ed-bar');
  const ta = h('textarea', 'pf-ed-ta');
  ta.id = 'pf-ed-ta';
  ta.placeholder = '支持 markdown：**粗体**、*斜体*、# 标题、- 列表、[链接](https://…)、行内代码用反引号包起来';
  ta.value = String(bio === null || bio === undefined ? '' : bio);
  const prev = h('div', 'pf-ed-prev md-body');
  const foot = h('div', 'pf-ed-foot');
  const cnt = h('span', 'pf-ed-cnt', '0 / ' + 2000);
  const save = btn('保存', 'btn btn-primary btn-sm', doSave);
  foot.appendChild(cnt);
  foot.appendChild(save);

  function cur() { return ta.value; }

  function setVal(v) {
    ta.value = v;
    sync();
  }

  function sync() {
    const s = String(ta.value || '');
    prev.innerHTML = mdHtml(s);
    cnt.textContent = s.length + ' / ' + 2000;
    const over = s.length > 2000;
    cnt.classList.toggle('pf-ed-over', over);
    save.disabled = over;
    cnt.title = over ? '超出 2000 字，请删一点' : '';
  }

  // 在选区两侧加标记（没选就放占位词并选中它）
  function wrap(open, close, ph) {
    const st = ta.selectionStart || 0;
    const en = ta.selectionEnd || 0;
    const v = ta.value;
    const sel = v.slice(st, en) || ph || '';
    ta.value = v.slice(0, st) + open + sel + close + v.slice(en);
    const a = st + open.length;
    ta.focus();
    ta.setSelectionRange(a, a + sel.length);
    sync();
  }

  // 行首插前缀
  function lineStart(prefix, ph) {
    const st = ta.selectionStart || 0;
    const en = ta.selectionEnd || 0;
    const v = ta.value;
    const ls = v.lastIndexOf('\n', Math.max(0, st - 1)) + 1;
    const le = v.indexOf('\n', en);
    const end = le < 0 ? v.length : le;
    const block = v.slice(ls, end) || (ph || '');
    const lines = block.split('\n').map(function (ln) { return prefix + ln; });
    ta.value = v.slice(0, ls) + lines.join('\n') + v.slice(end);
    ta.focus();
    ta.setSelectionRange(ls + prefix.length, ls + lines.join('\n').length);
    sync();
  }

  function insertBlock() {
    const st = ta.selectionStart || 0;
    const en = ta.selectionEnd || 0;
    const v = ta.value;
    const sel = v.slice(st, en) || 'code';
    const fence = '\x60\x60\x60';
    const text = fence + '\n' + sel + '\n' + fence + '\n';
    ta.value = v.slice(0, st) + text + v.slice(en);
    ta.focus();
    ta.setSelectionRange(st + text.length, st + text.length);
    sync();
  }

  function insertLink() {
    const st = ta.selectionStart || 0;
    const en = ta.selectionEnd || 0;
    const v = ta.value;
    const sel = v.slice(st, en) || '链接文字';
    const text = '[' + sel + '](https://)';
    ta.value = v.slice(0, st) + text + v.slice(en);
    const a = st + text.length - 1;
    ta.focus();
    ta.setSelectionRange(a, a);
    sync();
  }

  ED_TOOLS.forEach(function (tool) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'pf-ed-tool';
    b.appendChild(icon(tool.ic));
    b.title = tool.tip;
    b.setAttribute('aria-label', tool.tip);
    b.addEventListener('click', function () {
      if (tool.wrap) wrap(tool.wrap[0], tool.wrap[1], tool.ph);
      else if (tool.line) lineStart(tool.line, tool.ph);
      else if (tool.link) insertLink();
      else if (tool.block) insertBlock();
    });
    bar.appendChild(b);
  });

  ta.addEventListener('input', sync);
  ta.addEventListener('keydown', function (ev) {
    if ((ev.ctrlKey || ev.metaKey) && (ev.key === 's' || ev.key === 'S')) {
      ev.preventDefault();
      doSave();
    }
  });

  const prevWrap = h('div', 'pf-ed-prev-wrap');
  prevWrap.appendChild(h('div', 'pf-ed-label', '预览'));
  prevWrap.appendChild(prev);

  // 宽屏左边写、右边实时预览；窄屏上下堆叠（样式在 profile.css 的 .pf-ed-main）
  const editSide = h('div', 'pf-ed-side');
  editSide.appendChild(ta);
  const main = h('div', 'pf-ed-main');
  main.appendChild(editSide);
  main.appendChild(prevWrap);

  box.appendChild(bar);
  box.appendChild(main);
  box.appendChild(foot);

  const initialText = String(bio === null || bio === undefined ? '' : bio);
  // 第一次点空白 / ESC / × 只提醒，5 秒内在同一个位置再关一次才真的丢掉；
  // 隔太久（说明是另外一次误触）就重新提醒，避免「提示早过期了还静默丢弃」。
  let armAt = 0;
  openSheet(title || '编辑简介', box, {
    size: 'editor',
    onBeforeClose: function () {
      if (String(ta.value || '') === initialText) return true;
      const now = Date.now();
      const gap = now - armAt;
      if (armAt && gap >= 400 && gap <= 5000) return true;
      armAt = now;
      toast('还有改动没保存，再关一次就丢掉');
      return false;
    }
  });
  sync();

  function doSave() {
    const s = String(ta.value || '');
    if (s.length > 2000) { toast('简介最多 2000 字'); return; }
    save.disabled = true;
    api('api/profile', { bio: s }).then(function (j) {
      save.disabled = false;
      if (!j || !j.ok) { toast((j && j.err) || '保存失败'); return; }
      if (j.me) S.me = j.me;
      clearProfileCache();
      closeSheet();
      toast('简介已保存');
      refreshUserPageBio(s);
      const f = document.getElementById('f-intro');
      if (f && f.value !== s) f.value = s;
      if (typeof onSaved === 'function') onSaved(s);
    }).catch(function () {
      save.disabled = false;
      toast('保存失败');
    });
  }
}

// ---------- 给 #/me 页加一个 markdown 编辑入口 ----------
// me.js 不能改，这里只做非破坏性增强：在简介 textarea 下面补一个按钮
function enhanceMePage() {
  const ta = document.getElementById('f-intro');
  if (!ta) return;
  const row = ta.closest ? ta.closest('.f-row') : null;
  const box = row || ta.parentNode;
  if (!box) return;
  if (box.querySelector('[data-pf-md]')) return;
  const line = h('div', 'pf-md-row');
  const b = btn('Markdown 编辑', 'btn btn-sm btn-ghost', function () {
    openBioEditor((S.me && S.me.bio) || ta.value || '', function (saved) {
      ta.value = saved;
    });
  });
  b.setAttribute('data-pf-md', '1');
  line.appendChild(b);
  line.appendChild(h('span', 'pf-md-tip', '支持粗体 / 斜体 / 标题 / 列表 / 链接 / 代码'));
  box.appendChild(line);
}

// ---------- 路由接管 ----------
// profile.js 由 people.js 在 app.js 之前加载，所以这里的 hashchange 比 core.js:startRouter 先注册；
// 命中 #/u/ 时 stopImmediatePropagation 掉，app.js 就不会再把它当成未知路由去 chat.mount(0)。
function onHash(ev) {
  if (uidFromHash()) {
    if (ev && ev.stopImmediatePropagation) ev.stopImmediatePropagation();
    if (ev && ev.preventDefault) ev.preventDefault();
    checkRoute();
    return;
  }
  setTimeout(enhanceMePage, 0);
  setTimeout(enhanceMePage, 150);
}

function checkRoute() {
  const uid = uidFromHash();
  if (!uid) {
    setTimeout(enhanceMePage, 0);
    return;
  }
  enhanceMePage();
  const host = hostEl();
  if (!host) { setTimeout(checkRoute, 120); return; }
  const cur = host.querySelector('.pf-page');
  if (cur && cur.getAttribute('data-uid') === uid) return;
  renderUserPage(uid);
}

function watchRoute() {
  if (watchers) return;
  watchers = 1;
  window.addEventListener('hashchange', onHash, true);
  window.addEventListener('resize', function () { closeUserCard(); });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') closeUserCard();
  });
  // 首屏深链（#/u/x 直接打开）不会有 hashchange，这里兜几次
  setTimeout(checkRoute, 0);
  setTimeout(checkRoute, 350);
  setTimeout(checkRoute, 1200);
  // 外壳和聊天页都会重写 #page-host，用 observer 兜住覆盖
  try {
    const obs = new MutationObserver(function () {
      if (uidFromHash()) checkRoute();
      else enhanceMePage();
    });
    obs.observe(document.body, { childList: true, subtree: true });
  } catch (e) { void e; }
  setTimeout(enhanceMePage, 200);
  setTimeout(enhanceMePage, 800);
}

export { mdHtml, mdExcerpt };
