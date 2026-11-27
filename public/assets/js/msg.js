// LightGroup · 消息渲染：气泡/图片/语音/引用/撤回/卡片 + 右键菜单 + 顶部三条横幅
import { S, h, icon, toast, api, apiMissing, hhmm, dayLabel, copyText, openSheet, closeSheet, emit, go } from './core.js';
import { avatar } from './avatar.js';

// 超过这个字数就把消息折成「消息盒子」，默认只露前面一段
const LONG_TEXT = 200;

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
  // 私群只看会话里的身份：站长在私群里也是普通成员，管理动作要留痕，不能走客户端特权
  if (S.cur && S.cur.kind === 'pg') {
    return S.cur.role === 'owner' || S.cur.role === 'admin';
  }
  const r = (S.me && S.me.role) || '';
  return r === 'admin' || r === 'super' || (S.me && S.me.badge === 'admin');
}

// 群功能开关（权限组）；后端也会拦，这里只是别让菜单里出现点不动的项
export function permOn(key) {
  const p = (S.me && S.me.perms) || {};
  return p[key] !== false;
}

export function isDm() { return !!(S.cur && S.cur.kind === 'dm'); }
// 私群的公告/成员放在群资料页，聊天页顶部不挂大群那几条横幅
export function isPg() { return !!(S.cur && S.cur.kind === 'pg'); }

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
    // 图文混排：一条消息里文字和图片按发送时的顺序排在同一个气泡里
    const segs = Array.isArray(m.segs) && m.segs.length ? m.segs : null;
    if (segs) {
      const box = h('div', 'txt rich');
      segs.forEach(function (sg) {
        if (!sg) return;
        if (sg.t === 'file') {
          box.appendChild(fileCard(sg));
        } else if (sg.t === 'img') {
          const src = imgSrc(sg.v);
          const im = document.createElement('img');
          im.className = 'pic inline';
          im.src = src;
          im.alt = '图片';
          im.loading = 'lazy';
          im.addEventListener('click', function () { openImage(src); });
          box.appendChild(im);
        } else if (sg.v) {
          paintText(box, String(sg.v));
        }
      });
      bub.appendChild(box);
      if (m.voice) bub.appendChild(voiceNode(m));
      if (m.card) bub.appendChild(cardNode(m.card));
    } else {
      // text_auto：服务端给老客户端补的媒体行，网页已经有图/语音气泡了，剔掉再渲染
      const shown = displayText(m);
      if (shown) {
        // 长文渲染成消息卡片：气泡里只露个摘要，点开在弹层里读全文
        if (shown.length > LONG_TEXT) {
          bub.classList.add('bub-long');
          bub.appendChild(longCard(shown));
        } else {
          const p = h('div', 'txt');
          paintText(p, shown);
          bub.appendChild(p);
        }
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
  }
  body.appendChild(bub);
  row.appendChild(body);
  row.addEventListener('contextmenu', function (e) { e.preventDefault(); msgMenu(m, e.clientX, e.clientY); });
  return row;
}

function fmtBytes(n) {
  n = Number(n) || 0;
  if (n < 1024) return n + ' B';
  if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
  return (n / 1048576).toFixed(1) + ' MB';
}

function fileCard(f) {
  const a = document.createElement('a');
  a.className = 'file-card';
  a.href = relUrl(f.url || ('/g/api/file/' + f.id));
  a.target = '_blank';
  a.rel = 'noopener noreferrer';
  a.download = f.n || '';
  a.appendChild(icon('fa-file-arrow-down'));
  const t = h('span', 'file-card-t');
  t.appendChild(h('b', '', f.n || '文件'));
  t.appendChild(h('span', 'file-card-s', fmtBytes(f.s)));
  a.appendChild(t);
  return a;
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

  // 语音转文字：按钮一直放着，站点没配识别服务时后端会明确回「这个站还没开语音转文字」
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

  // 转写交给后台任务：请求线程只提交，识别再慢也不会把整站拖着转圈
  async function transcribeVoice() {
    const job = await submitJob({ kind: 'asr', conv: S.curId, msg: m.id });
    if (job) {
      const r = await waitJob(job, 90000);
      if (!r) throw new Error('这段语音没转成功，稍后再试');
      return r.text ? String(r.text) : '';
    }
    // 服务端还是老版本、没有任务接口时退回同步转写
    const r = await api('api/voice/text', { conv: S.curId, msg: m.id }, { timeout: 55000 });
    if (!r || !r.ok) throw new Error((r && r.err) || '这段语音没转成功，稍后再试');
    return r.text ? String(r.text) : '';
  }

  tx.addEventListener('click', function () {
    if (tx.disabled) return;
    tx.disabled = true;
    txLabel.textContent = '转写中';
    const spin = icon('fa-spinner');
    spin.classList.add('fa-spin');
    tx.insertBefore(spin, tx.firstChild);
    transcribeVoice().then(function (text) {
      if (!text) throw new Error('这段语音里没听出内容，换个位置再录一次');
      showText(text);
    }).catch(function (e) {
      toast((e && e.message) || '这段语音没转成功，稍后再试');
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

/**
 * 长消息卡片：几百字的消息会把会话顶掉半屏，卡片里只放几行摘要，
 * 想看整段点「查看全文」，在弹层里读完还能一键复制。
 */
function longCard(text) {
  const card = h('div', 'msg-card');
  const head = h('div', 'msg-card-head');
  head.appendChild(icon('fa-file-lines'));
  head.appendChild(h('span', 'msg-card-title', '长消息'));
  head.appendChild(h('span', 'msg-card-count', '共 ' + text.length + ' 字'));
  card.appendChild(head);

  const body = h('div', 'msg-card-body');
  body.textContent = text.slice(0, 160);
  card.appendChild(body);

  const foot = h('div', 'msg-card-foot');
  const open = document.createElement('button');
  open.type = 'button';
  open.className = 'msg-card-open';
  open.textContent = '查看全文';
  open.addEventListener('click', function (ev) {
    ev.stopPropagation();
    openReader(text);
  });
  foot.appendChild(open);
  card.appendChild(foot);

  card.addEventListener('click', function () { openReader(text); });
  return card;
}

/** 全文阅读弹层：内容可以很长，弹层里滚动，底部给一个复制 */
function openReader(text) {
  const wrap = h('div', 'msg-reader-wrap');
  const box = h('div', 'msg-reader');
  paintText(box, text);
  wrap.appendChild(box);
  const bar = h('div', 'msg-reader-bar');
  const cp = document.createElement('button');
  cp.type = 'button';
  cp.className = 'btn';
  cp.textContent = '复制全文';
  cp.addEventListener('click', function () { copyText(text); toast('已复制'); });
  bar.appendChild(cp);
  wrap.appendChild(bar);
  openSheet('长消息', wrap, { size: 'reader' });
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
      if (permOn('pin')) {
        const pinned = (S.pins || []).some(function (p) { return p.id === m.id; });
        items.push([pinned ? '取消置顶' : '置顶这条', 'fa-thumbtack', () => togglePin(m, !pinned)]);
      }
      if (permOn('todo')) items.push(['设为群待办', 'fa-list-check', () => makeTodo(m)]);
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
  if (Array.isArray(m.segs) && m.segs.some(function (s) { return s && s.t === 'file'; })) return '[文件]';
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
  // 私聊没有群设施；大群和私群各自显示自己的公告、置顶和待办
  if (isDm()) return;
  const n = S.notice;
  if (n && n.text) {
    const bar = h('div', 'bar notice');
    bar.appendChild(icon('fa-bullhorn'));
    bar.appendChild(h('b', '', '公告'));
    bar.appendChild(h('span', 'bar-t', n.text));
    bar.addEventListener('click', function () {
      // 私群的公告在群管理页编辑，大群的在「群资料」里
      if (isPg()) { go('#/pg/' + S.curId); return; }
      openGroupInfo();
    });
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

/**
 * 大群的「群资料」：公告 + 成员入口。公告从顶部横幅挪到这里，
 * 更像 QQ 的群资料页；只有管理员/超级管理员能编辑。
 */
export function openGroupInfo() {
  const body = h('div', 'sheet-body');
  const card = h('div', 'pg-card');
  const head = h('div', 'pg-card-h');
  head.appendChild(h('div', 'pg-card-t', '群公告'));
  if (canModerate()) {
    const ed = document.createElement('button');
    ed.type = 'button';
    ed.className = 'btn btn-xs btn-ghost';
    ed.textContent = '编辑';
    ed.addEventListener('click', function () { noticeEditor(card); });
    head.appendChild(ed);
  }
  card.appendChild(head);
  const n = S.notice;
  card.appendChild(h('div', 'pg-notice' + (n && n.text ? '' : ' empty'), (n && n.text) || '还没有群公告'));
  if (n && n.text && (n.by || n.at)) {
    const meta = (n.by || '') + (n.by && n.at ? ' · ' : '') + (n.at ? hhmm(n.at) : '');
    card.appendChild(h('div', 'pg-meta', meta));
  }
  body.appendChild(card);

  const mc = h('div', 'pg-card');
  const mh = h('div', 'pg-card-h');
  mh.appendChild(h('div', 'pg-card-t', '群成员'));
  const more = document.createElement('button');
  more.type = 'button';
  more.className = 'btn btn-xs btn-ghost';
  more.textContent = '查看成员';
  more.addEventListener('click', function () { closeSheet(); go('#/members'); });
  mh.appendChild(more);
  mc.appendChild(mh);
  const cnt = (S.members && S.members.length) || (S.cur && S.cur.members) || 0;
  mc.appendChild(h('div', 'pg-notice empty', cnt + ' 位成员'));
  body.appendChild(mc);

  openSheet('群资料', body, { size: 'md' });
}

function noticeEditor(card) {
  card.textContent = '';
  const head = h('div', 'pg-card-h');
  head.appendChild(h('div', 'pg-card-t', '编辑群公告'));
  card.appendChild(head);
  const ta = document.createElement('textarea');
  ta.className = 'tb';
  ta.rows = 6;
  ta.maxLength = 500;
  ta.placeholder = '写点什么，最多 500 字；清空后保存就是取消公告';
  ta.value = (S.notice && S.notice.text) || '';
  card.appendChild(ta);
  const acts = h('div', 'pg-ops');
  const save = document.createElement('button');
  save.type = 'button';
  save.className = 'btn btn-primary btn-sm';
  save.textContent = '保存';
  save.addEventListener('click', function () { postNotice(ta.value.trim()); });
  const cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.className = 'btn btn-ghost btn-sm';
  cancel.textContent = '取消';
  cancel.addEventListener('click', function () { openGroupInfo(); });
  acts.appendChild(save);
  acts.appendChild(cancel);
  card.appendChild(acts);
  ta.focus();
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

async function togglePin(m, on) {
  const j = await api('api/pin', { id: m.id, on: on ? 1 : 0, conv: S.curId });
  if (!j.ok) { toast(j.err || '置顶失败'); return; }
  S.pins = j.pins || [];
  refreshBars();
  toast(on ? '已置顶' : '已取消置顶');
}

async function makeTodo(m) {
  const j = await api('api/todo', { op: 'create', msgId: m.id, conv: S.curId });
  if (!j.ok) { toast(j.err || '设待办失败'); return; }
  S.todos = j.todos || [];
  if (j.notice) S.notice = j.notice;
  refreshBars();
  toast('已设为群待办');
}

async function todoDone(t) {
  const j = await api('api/todo', { op: 'done', id: t.id, conv: S.curId });
  if (!j.ok) { toast(j.err || '操作失败'); return; }
  S.todos = j.todos || [];
  if (j.notice) S.notice = j.notice;
  refreshBars();
  if (document.getElementById('sheet')) openTodoPanel();
}

async function todoClose(t) {
  const j = await api('api/todo', { op: 'close', id: t.id, conv: S.curId });
  if (!j.ok) { toast(j.err || '撤不掉'); return; }
  S.todos = j.todos || [];
  if (j.notice) S.notice = j.notice;
  refreshBars();
  toast('待办已撤掉');
  if (document.getElementById('sheet')) openTodoPanel();
}

// 后台任务：翻译、语音转文字这类要等外部接口的活，提交后拿任务号轮询
async function submitJob(payload) {
  const r = await api('api/jobs', payload, { timeout: 15000 });
  if (!r || !r.ok) {
    if (apiMissing(r)) return null;
    throw new Error((r && r.err) || '任务提交失败');
  }
  return r.job || null;
}

async function waitJob(job, timeoutMs) {
  if (!job) return null;
  if (job.status === 'done') return job.result || {};
  if (job.status === 'failed') throw new Error(job.error || '任务失败了');
  const deadline = Date.now() + timeoutMs;
  let delay = 400;
  while (Date.now() < deadline) {
    await new Promise(function (r) { setTimeout(r, delay); });
    delay = Math.min(delay + 200, 1500);
    const r = await api('api/jobs/' + job.id, undefined, { timeout: 10000 });
    if (!r || !r.ok) {
      if (apiMissing(r)) return null;
      throw new Error((r && r.err) || '任务状态读取失败');
    }
    const j = r.job || {};
    if (j.status === 'done') return j.result || {};
    if (j.status === 'failed') throw new Error(j.error || '任务失败了');
  }
  throw new Error('等太久了，稍后再试');
}

async function doTranslate(m) {
  toast('翻译中…');
  const row = document.querySelector('.row[data-id="' + m.id + '"]');
  try {
    let r = null;
    const job = await submitJob({ kind: 'translate', text: m.text, to: 'zh-Hans' });
    if (job) {
      r = await waitJob(job, 25000);
    } else {
      // 老服务端还没有任务接口，退回同步翻译
      r = await api('api/translate', { text: m.text, to: 'zh-Hans' }, { timeout: 15000 });
      if (!r || !r.ok) throw new Error((r && r.err) || '翻译失败');
    }
    if (!r || !r.text) throw new Error('翻译失败');
    if (!row) return;
    let tr = row.querySelector('.trans');
    if (!tr) { tr = h('div', 'trans'); row.querySelector('.bub').appendChild(tr); }
    tr.textContent = r.text;
    // 出处要写在译文下面，微信也是这么标的；后端没给名字就不写，别自己猜是哪个服务
    if (r.provider_name) tr.appendChild(h('span', 'trans-by', '翻译服务由 ' + r.provider_name + ' 提供'));
  } catch (e) {
    toast((e && e.message) || '翻译失败');
  }
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
