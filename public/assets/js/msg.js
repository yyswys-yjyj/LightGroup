// LightGroup · 消息渲染：气泡/图片/语音/引用/撤回/卡片 + 右键菜单 + 顶部三条横幅
import { S, h, icon, toast, api, hhmm, dayLabel, copyText, openSheet, closeSheet, emit } from './core.js';
import { avatar } from './avatar.js';

// 站内上传的资源统一转成站内相对路径。
// 上传接口回的是带域名的绝对 URL；站点跑在 https 而后端因为反代没传 X-Forwarded-Proto
// 把地址算成 http 时，浏览器会把 <img>/<audio> 当混合内容直接挡掉，表现就是「图发不出去 / 语音放不了」。
export function relUrl(u) {
  const s = String(u || '');
  if (!/^https?:\/\//i.test(s)) return s;
  try {
    const x = new URL(s);
    if (x.pathname.indexOf('/uploads/') === 0) return x.pathname + x.search;
  } catch (e) { /* 不是合法 URL 就原样返回 */ }
  return s;
}

// 站长的图片字段带 [img] 前缀，我们的库里只存路径，出口统一剥掉
export function imgSrc(u) { return relUrl(String(u || '').replace(/^\[img\]/, '')); }
export function authorKey(m) { return (m && (m.from || m.memberId)) || ''; }

export function qOf(m) {
  if (m && m.reply) return m.reply;
  if (m && typeof m.q === 'string' && m.q) {
    try { return JSON.parse(m.q); } catch (e) { return null; }
  }
  if (m && m.q && typeof m.q === 'object') return m.q;
  return null;
}

export function isMine(m) {
  if (!S.me) return false;
  const k = authorKey(m);
  if (k && S.me.id) return String(k) === String(S.me.id);
  return m.name === S.me.name;
}

export function canModerate() {
  const r = (S.me && S.me.role) || '';
  return r === 'admin' || r === 'super' || (S.me && S.me.badge === 'admin');
}

export function isDm() { return !!(S.cur && S.cur.kind === 'dm'); }

export function sameAuthor(a, b) {
  if (!a || !b) return false;
  const ka = authorKey(a), kb = authorKey(b);
  if (ka && kb) return String(ka) === String(kb);
  return a.name === b.name && a.kind === b.kind;
}

// 服务端会给老客户端在 text 末尾补「[图片](地址)」这类媒体行；网页自己会渲染图片/语音，先剔掉再显示
export function displayText(m) {
  let t = String((m && m.text) || '');
  if (m && m.text_auto) {
    t = t.split('\n').filter(function (line) { return !/^\[(图片|语音|卡片)\]\(/.test(String(line).trim()); }).join('\n');
  }
  return t.trim();
}

// ---------- 渲染单条消息 ----------
export function msgNode(m, prev) {
  // 系统提示（例如管理员查看了会话）单独一行，不属于任何人的气泡
  if (m.sys) {
    const line = h('div', 'sys-line');
    line.dataset.id = String(m.id);
    line.appendChild(h('span', '', m.text || ''));
    return line;
  }
  const prevReal = prev && !prev.sys ? prev : null;
  const mine = isMine(m);
  const grouped = prevReal && sameAuthor(prevReal, m) && (m.ts - prevReal.ts) < 5 * 60 * 1000;
  const recalled = !!(m.recalled || m.rev === 1);
  const row = h('div', 'row' + (mine ? ' me' : '') + (grouped ? ' grouped' : '') + (m.pin ? ' is-pin' : ''));
  row.dataset.id = String(m.id);
  row.dataset.name = m.name || '';

  row.appendChild(grouped ? h('div', 'ava-gap') : avatar(m));

  const body = h('div', 'body');
  if (!grouped) {
    const meta = h('div', 'meta');
    if (!mine) {
      meta.appendChild(h('span', 'nm', m.name || '匿名'));
      if (m.kind === 'ai') meta.appendChild(h('span', 'tag-ai', 'AI'));
      if (m.kind === 'ai' && m.owner) meta.appendChild(h('span', 'owner', '· ' + m.owner + ' 的'));
    }
    if (m.pin) meta.appendChild(h('span', 'pin-flag', '置顶'));
    meta.appendChild(h('span', 'time', hhmm(m.ts)));
    body.appendChild(meta);
  } else if (m.pin) {
    body.appendChild(h('div', 'meta', '置顶'));
  }

  const bub = h('div', 'bub');
  if (recalled) {
    bub.classList.add('recalled');
    bub.textContent = '消息已撤回';
  } else {
    const qq = qOf(m);
    if (qq) {
      const q = h('div', 'quote');
      q.appendChild(h('b', '', (qq.name || '') + ': '));
      // 引用的是图片消息：直接把缩略图放进来，只写「[图片]」看不出是哪张
      if (qq.img) {
        const qim = document.createElement('img');
        qim.className = 'quote-pic';
        qim.src = imgSrc(qq.img);
        qim.alt = '图片';
        qim.loading = 'lazy';
        qim.addEventListener('click', function () { openImage(imgSrc(qq.img)); });
        q.appendChild(qim);
        if (qq.text) q.appendChild(h('span', '', qq.text));
      } else {
        q.appendChild(h('span', '', previewText(qq)));
      }
      bub.appendChild(q);
    }
    // text_auto：服务端给老客户端补的媒体行，网页已经有图/语音气泡了，剔掉再渲染
    const shown = displayText(m);
    if (shown) {
      const p = h('div', 'txt');
      paintText(p, shown);
      bub.appendChild(p);
    }
    if (m.img) {
      const src = imgSrc(m.img);
      const im = document.createElement('img');
      im.className = 'pic';
      im.src = src;
      im.alt = '图片';
      im.loading = 'lazy';
      im.addEventListener('click', function () { openImage(src); });
      bub.appendChild(im);
    }
    if (m.voice) bub.appendChild(voiceNode(m));
    if (m.card) bub.appendChild(cardNode(m.card));
  }
  body.appendChild(bub);
  row.appendChild(body);
  row.addEventListener('contextmenu', function (e) { e.preventDefault(); msgMenu(m, e.clientX, e.clientY); });
  return row;
}

// 语音：原生播放器在不同主题下太丑，自己画一个胶囊
// 进度条可以点、可以拖；真正开始出声之前按钮转圈，转圈期间点不动。
function voiceNode(m) {
  const box = h('div', 'voice-box');
  const au = document.createElement('audio');
  au.src = relUrl(m.voice);
  au.preload = 'none';
  const play = document.createElement('button');
  play.type = 'button';
  play.className = 'voice-play';
  play.title = '播放';
  const setIcon = function (name, spin) {
    play.textContent = '';
    const i = icon(name);
    if (spin) i.classList.add('fa-spin');
    play.appendChild(i);
  };
  setIcon('fa-play');

  const bar = h('div', 'voice-bar');
  const fill = h('span', 'voice-fill');
  bar.appendChild(fill);
  const dur = h('span', 'voice-dur', (m.dur ? m.dur + '"' : '语音'));

  let loading = false;
  let dragging = false;
  let pending = -1; // 还没拿到时长时先记住要跳的比例

  const paint = function (ratio) {
    let r = ratio;
    if (typeof r !== 'number') {
      const d = au.duration || 0;
      r = d > 0 ? Math.min(1, (au.currentTime || 0) / d) : 0;
    }
    fill.style.width = (Math.max(0, Math.min(1, r)) * 100) + '%';
  };
  const setLoading = function (on) {
    if (loading === on) return;
    loading = on;
    box.classList.toggle('loading', on);
    if (on) {
      setIcon('fa-spinner', true);
      play.disabled = true;
      play.title = '正在加载';
      loadTimer = setTimeout(function () { loadTimer = 0; setLoading(false); }, 8000);
    } else {
      if (loadTimer) { clearTimeout(loadTimer); loadTimer = 0; }
      play.disabled = false;
      play.title = '播放';
      setIcon(au.paused ? 'fa-play' : 'fa-pause');
    }
  };
  const ratioAt = function (clientX) {
    const r = bar.getBoundingClientRect();
    return Math.max(0, Math.min(1, (clientX - r.left) / (r.width || 1)));
  };
  const seekTo = function (ratio) {
    if (au.duration) {
      au.currentTime = ratio * au.duration;
      paint(ratio);
    } else {
      pending = ratio;
      paint(ratio);
    }
  };

  play.addEventListener('click', function () {
    if (loading) return;
    if (au.paused) {
      if (au.readyState < 3) setLoading(true);
      else setIcon('fa-pause');
      const p = au.play();
      if (p && p.then) {
        p.then(function () { setLoading(false); setIcon('fa-pause'); })
          .catch(function () { setLoading(false); toast('这条语音放不了'); });
      } else {
        setIcon('fa-pause');
      }
    } else {
      au.pause();
    }
  });

  // 兜底：有的浏览器 waiting 之后不再发 canplay，靠计时和 timeupdate 收尾，
  // 不然按钮会一直转圈。
  let loadTimer = 0;
  const stopSpin = function () {
    if (loadTimer) { clearTimeout(loadTimer); loadTimer = 0; }
    setLoading(false);
  };
  au.addEventListener('loadedmetadata', function () {
    if (pending >= 0) { const r = pending; pending = -1; seekTo(r); }
    paint();
  });
  au.addEventListener('canplay', function () { if (!au.paused) stopSpin(); });
  au.addEventListener('playing', function () { stopSpin(); setIcon('fa-pause'); });
  au.addEventListener('waiting', function () { if (!au.paused) setLoading(true); });
  au.addEventListener('timeupdate', function () {
    if (loading && !au.paused && au.readyState >= 2) stopSpin();
    if (!dragging) paint();
  });
  au.addEventListener('pause', function () { if (!loading) setIcon('fa-play'); });
  au.addEventListener('ended', function () { setIcon('fa-play'); paint(0); });

  bar.addEventListener('pointerdown', function (e) {
    dragging = true;
    try { bar.setPointerCapture(e.pointerId); } catch (_) {}
    const r = ratioAt(e.clientX);
    if (!au.duration) {
      setLoading(true);
      if (au.readyState === 0) au.load();
      pending = r;
      paint(r);
    } else {
      paint(r);
    }
    e.preventDefault();
  });
  bar.addEventListener('pointermove', function (e) {
    if (!dragging) return;
    paint(ratioAt(e.clientX));
  });
  const endDrag = function (e) {
    if (!dragging) return;
    dragging = false;
    seekTo(ratioAt(e.clientX));
  };
  bar.addEventListener('pointerup', endDrag);
  bar.addEventListener('pointercancel', endDrag);

  au.hidden = true;
  box.appendChild(au);
  box.appendChild(play);
  box.appendChild(bar);
  box.appendChild(dur);

  // 语音转文字：按钮一直放着，站点没配识别接口时后端会明确回「站点没开语音转文字」
  const wrap = h('div', 'voice-wrap');
  wrap.appendChild(box);
  const tx = document.createElement('button');
  tx.type = 'button';
  tx.className = 'voice-tx';
  tx.title = '把这条语音转成文字';
  tx.appendChild(icon('fa-align-left'));
  const txLabel = h('span', '', '转文字');
  tx.appendChild(txLabel);
  const txOut = h('div', 'voice-tx-out');
  txOut.hidden = true;

  const showText = function (text) {
    m.voiceText = text;
    txOut.textContent = text;
    txOut.hidden = false;
    if (tx.parentNode) tx.parentNode.removeChild(tx);
  };

  tx.addEventListener('click', function () {
    if (tx.disabled) return;
    tx.disabled = true;
    txLabel.textContent = '转写中';
    const spin = icon('fa-spinner');
    spin.classList.add('fa-spin');
    tx.insertBefore(spin, tx.firstChild);
    api('api/voice/text', { conv: S.curId, msg: m.id }).then(function (r) {
      const text = (r && r.text) ? String(r.text) : '';
      if (!text) throw new Error('没听清，再说一遍试试');
      showText(text);
    }).catch(function (e) {
      toast((e && e.message) || '转写失败了');
      tx.disabled = false;
      txLabel.textContent = '转文字';
      if (spin.parentNode) spin.parentNode.removeChild(spin);
    });
  });

  if (m.voiceText) showText(String(m.voiceText));
  else wrap.appendChild(tx);
  wrap.appendChild(txOut);
  return wrap;
}

// @名字 高亮：只有真成员才算
export function paintText(el, text) {
  const names = {};
  (S.members || []).forEach(function (m) { if (m.name) names[m.name] = true; });
  const re = /@([^\s@]{1,24})/g;
  let last = 0, mt;
  while ((mt = re.exec(text)) !== null) {
    const name = mt[1];
    if (!names[name]) continue;
    if (mt.index > last) el.appendChild(document.createTextNode(text.slice(last, mt.index)));
    el.appendChild(h('span', 'mention', '@' + name));
    last = mt.index + mt[0].length;
  }
  if (last < text.length) el.appendChild(document.createTextNode(text.slice(last)));
}

function cardNode(card) {
  const c = h('div', 'card-msg');
  const shop = { luckin: '瑞幸', mcd: '麦当劳' }[card.shop] || '请客卡';
  c.appendChild(h('b', '', card.item || shop));
  if (card.price) c.appendChild(h('span', '', '¥' + card.price));
  if (card.note) c.appendChild(h('em', '', card.note));
  return c;
}

// ---------- 右键菜单 ----------
let menuNode = null;
export function closeMenu() {
  if (menuNode && menuNode.parentNode) menuNode.parentNode.removeChild(menuNode);
  menuNode = null;
}

export function msgMenu(m, x, y) {
  closeMenu();
  const recalled = !!(m.recalled || m.rev === 1);
  const items = [];
  if (!recalled) {
    items.push(['引用', 'fa-quote-left', () => setReply(m)]);
    if (displayText(m)) items.push(['翻译', 'fa-language', () => doTranslate(m)]);
    items.push(['复制文字', 'fa-copy', () => copyText(displayText(m) || (m.img ? '[图片]' : (m.voice ? '[语音]' : '')))]);
    if (!isDm() && canModerate()) {
      const pinned = (S.pins || []).some(function (p) { return p.id === m.id; });
      items.push([pinned ? '取消置顶' : '置顶这条', 'fa-thumbtack', () => togglePin(m, !pinned)]);
      items.push(['设为群公告', 'fa-bullhorn', () => setNotice(m)]);
      items.push(['设为群待办', 'fa-list-check', () => makeTodo(m)]);
    }
  }
  items.push(['撤回', 'fa-rotate-left', () => doRecall(m)]);
  if (!items.length) return;

  const box = h('div', 'context-menu');
  box.style.left = Math.min(x, window.innerWidth - 200) + 'px';
  box.style.top = Math.min(y, Math.max(12, window.innerHeight - 60 - items.length * 44)) + 'px';
  items.forEach(([label, ic, fn]) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.appendChild(icon(ic));
    b.appendChild(h('span', '', label));
    b.addEventListener('click', () => { closeMenu(); fn(); });
    box.appendChild(b);
  });
  document.body.appendChild(box);
  menuNode = box;
}

document.addEventListener('click', (e) => {
  if (menuNode && !menuNode.contains(e.target)) closeMenu();
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closeMenu(); } });

// ---------- 引用回复 ----------
export function setReply(m) {
  S.reply = m;
  const bar = document.getElementById('reply-bar');
  if (!bar) return;
  bar.textContent = '';
  bar.hidden = false;
  const txt = h('div', 'reply-txt');
  txt.appendChild(h('b', '', m.name || ''));
  txt.appendChild(h('span', '', (displayText(m) || '[图片]').slice(0, 80)));
  const x = document.createElement('button');
  x.type = 'button';
  x.className = 'reply-x';
  x.appendChild(icon('fa-xmark'));
  x.addEventListener('click', clearReply);
  bar.appendChild(txt);
  bar.appendChild(x);
  const ta = document.getElementById('compose');
  if (ta) ta.focus();
}

// ---------- 看图弹层：图片消息点开是在站内看，不跳新窗口 ----------
let imgDlg = null;

function onImgKey(e) { if (e.key === 'Escape') closeImage(); }

export function closeImage() {
  if (!imgDlg) return;
  const el = imgDlg;
  imgDlg = null;
  window.removeEventListener('keydown', onImgKey);
  el.classList.remove('on');
  setTimeout(function () { el.remove(); }, 160);
}

export function openImage(src) {
  if (!src) return;
  closeImage();
  const mask = h('div', 'img-dialog');
  const im = document.createElement('img');
  im.src = src;
  im.alt = '图片';
  const x = document.createElement('button');
  x.type = 'button';
  x.className = 'img-dialog-x';
  x.title = '关闭';
  x.appendChild(icon('fa-xmark'));
  mask.appendChild(im);
  mask.appendChild(x);
  mask.addEventListener('click', function (e) {
    // 点图片本身不关，方便放大看
    if (e.target === im) return;
    closeImage();
  });
  document.body.appendChild(mask);
  window.addEventListener('keydown', onImgKey);
  requestAnimationFrame(function () { mask.classList.add('on'); });
  imgDlg = mask;
}

/** 一条消息的简短预览：置顶条、待办、引用都用它，别再把「没有文字」当成已撤回 */
export function previewText(m) {
  if (!m) return '';
  const t = displayText(m);
  if (t) return t;
  if (m.img) return '[图片]';
  if (m.voice) return m.dur ? '[语音 ' + m.dur + '"]' : '[语音]';
  if (m.card) return '[卡片]';
  if (m.recalled || m.rev) return '（已撤回）';
  return '[消息]';
}

export function clearReply() {
  S.reply = null;
  const bar = document.getElementById('reply-bar');
  if (bar) { bar.hidden = true; bar.textContent = ''; }
}

// ---------- 群公告 / 置顶 / 群待办 ----------
export function refreshBars() {
  const host = document.getElementById('top-bars');
  if (!host) return;
  host.textContent = '';
  if (isDm()) return;
  const notice = S.notice;
  if (notice && notice.text) {
    const bar = h('div', 'bar notice');
    bar.appendChild(icon('fa-bullhorn'));
    bar.appendChild(h('b', '', '公告'));
    const tx = h('span', 'bar-t', notice.text);
    bar.appendChild(tx);
    if (canModerate()) {
      const x = document.createElement('button');
      x.type = 'button';
      x.className = 'bar-x';
      x.title = '取消公告';
      x.appendChild(icon('fa-xmark'));
      x.addEventListener('click', function () { postNotice(''); });
      bar.appendChild(x);
    }
    bar.addEventListener('click', function (e) { if (e.target.closest('.bar-x')) return; openNotice(); });
    host.appendChild(bar);
  }
  if (S.pins && S.pins.length) {
    const bar = h('div', 'bar pin');
    bar.appendChild(icon('fa-thumbtack'));
    bar.appendChild(h('b', '', '置顶'));
    const top = S.pins[0];
    const tx = h('span', 'bar-t', (top.name ? top.name + ': ' : '') + previewText(top));
    bar.appendChild(tx);
    if (S.pins.length > 1) bar.appendChild(h('span', 'bar-n', '+' + (S.pins.length - 1)));
    bar.addEventListener('click', function () { jumpTo(top.id); });
    host.appendChild(bar);
  }
  if (S.todos && S.todos.length) {
    const mine = S.todos.filter(function (t) { return t.meDone; }).length;
    const bar = h('div', 'bar todo');
    bar.appendChild(icon('fa-list-check'));
    bar.appendChild(h('b', '', '待办'));
    bar.appendChild(h('span', 'bar-t', (S.todos.length - mine) + ' 条未完成，点开看'));
    bar.addEventListener('click', openTodoPanel);
    host.appendChild(bar);
  }
}

export function jumpTo(id) {
  const row = document.querySelector('.row[data-id="' + id + '"]');
  if (!row) { toast('这条消息不在当前视图里'); return; }
  row.scrollIntoView({ block: 'center', behavior: 'smooth' });
  row.classList.add('flash');
  setTimeout(function () { row.classList.remove('flash'); }, 1200);
}

export function openNotice() {
  const body = h('div', 'sheet-body');
  body.appendChild(h('p', 'notice-full', (S.notice && S.notice.text) || ''));
  if (S.notice && S.notice.by) body.appendChild(h('div', 'sheet-sub', S.notice.by + ' 发布'));
  openSheet('群公告', body);
}

export function openTodoPanel() {
  const body = h('div', 'sheet-body');
  if (!S.todos.length) body.appendChild(h('div', 'page-tip', '暂时没有待办'));
  S.todos.forEach(function (t) {
    const it = h('div', 'todo-item');
    const tx = h('div', 'todo-main');
    tx.appendChild(h('div', 'todo-text', previewText(t)));
    tx.appendChild(h('div', 'sheet-sub', (t.name || '') + ' 发起 · 已完成 ' + (t.done || 0) + '/' + (t.total || 0)));
    it.appendChild(tx);
    const acts = h('div', 'todo-btns');
    const ok = h('button', 'btn btn-sm ' + (t.meDone ? 'btn-ghost' : 'btn-primary'), t.meDone ? '已完成' : '完成');
    ok.type = 'button';
    ok.addEventListener('click', function () { if (!t.meDone) todoDone(t); });
    acts.appendChild(ok);
    // 管理员可以把待办撤掉，撤掉后不再挂在消息上
    if (canModerate() || t.mine) {
      const rm = h('button', 'btn btn-sm btn-ghost', '撤掉');
      rm.type = 'button';
      rm.addEventListener('click', function () { todoClose(t); });
      acts.appendChild(rm);
    }
    it.appendChild(acts);
    body.appendChild(it);
  });
  openSheet('群待办', body);
}

export async function postNotice(text) {
  const j = await api('api/notice', { text: text });
  if (!j.ok) { toast(j.err || '公告没设上'); return; }
  S.notice = j.notice || null;
  refreshBars();
  closeSheet();
  emit('conv:touch');
}

function setNotice(m) {
  const cur = (S.notice && S.notice.text) || '';
  const t = window.prompt('群公告内容（留空取消公告）', m.text || cur);
  if (t === null) return;
  postNotice(t.trim());
}

async function togglePin(m, on) {
  const j = await api('api/pin', { id: m.id, on: on ? 1 : 0 });
  if (!j.ok) { toast(j.err || '置顶失败'); return; }
  S.pins = j.pins || [];
  refreshBars();
  toast(on ? '已置顶' : '已取消置顶');
}

async function makeTodo(m) {
  const j = await api('api/todo', { op: 'create', msgId: m.id });
  if (!j.ok) { toast(j.err || '设待办失败'); return; }
  S.todos = j.todos || [];
  if (j.notice) S.notice = j.notice;
  refreshBars();
  toast('已设为群待办');
}

async function todoDone(t) {
  const j = await api('api/todo', { op: 'done', id: t.id });
  if (!j.ok) { toast(j.err || '操作失败'); return; }
  S.todos = j.todos || [];
  if (j.notice) S.notice = j.notice;
  refreshBars();
  if (document.getElementById('sheet')) openTodoPanel();
}

async function todoClose(t) {
  const j = await api('api/todo', { op: 'close', id: t.id });
  if (!j.ok) { toast(j.err || '撤不掉'); return; }
  S.todos = j.todos || [];
  if (j.notice) S.notice = j.notice;
  refreshBars();
  toast('待办已撤掉');
  if (document.getElementById('sheet')) openTodoPanel();
}

async function doTranslate(m) {
  toast('翻译中…');
  const j = await api('api/translate', { text: m.text, to: 'zh-Hans' });
  if (!j.ok) { toast(j.err || '翻译失败'); return; }
  const row = document.querySelector('.row[data-id="' + m.id + '"]');
  if (!row) return;
  let tr = row.querySelector('.trans');
  if (!tr) { tr = h('div', 'trans'); row.querySelector('.bub').appendChild(tr); }
  tr.textContent = j.text;
}

/**
 * 把本地已经渲染出来的一条消息改成「已撤回」。
 *
 * 撤回是状态变更而不是新消息，id 游标拉不回来，所以聊天页每轮轮询会带着服务端给的
 * recalled id 列表调这里；自己点撤回时也走同一条路，行为完全一致。
 * 返回 true 表示这次真的改了（调用方可以据此决定要不要滚一下）。
 */
export function applyRecall(id) {
  const mid = Number(id) || 0;
  if (!mid) return false;
  let changed = false;
  const idx = (S.msgs || []).findIndex((x) => Number(x.id) === mid);
  if (idx >= 0) {
    const m = S.msgs[idx];
    if (!(m.recalled || m.rev === 1)) {
      m.recalled = true;
      m.rev = 1;
      m.text = '';
      delete m.img;
      delete m.voice;
      delete m.card;
      m.q = null;
      m.reply = null;
      changed = true;
    }
  }
  const row = document.querySelector('.row[data-id="' + mid + '"]');
  if (row) {
    const old = row.querySelector('.bub');
    if (old && !old.classList.contains('recalled')) {
      const b = h('div', 'bub recalled', '消息已撤回');
      old.parentNode.replaceChild(b, old);
      changed = true;
    }
  }
  return changed;
}

async function doRecall(m) {
  if (!confirm('撤回这条消息？')) return;
  const j = await api('api/recall', { id: m.id });
  if (!j.ok) { toast(j.err || '撤回失败'); return; }
  applyRecall(m.id);
}
