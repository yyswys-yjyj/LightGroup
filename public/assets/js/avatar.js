// LightGroup · 头像、颜色、首字、头像框
import { S, h } from './core.js';

// 和 src/Api/Compat.php、src/Api/Profile.php 的 COLORS 必须是同一套、同一顺序。
const HUES = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#ca8a04', '#16a34a', '#0891b2', '#475569', '#9f1239', '#4d7c0f'];

export function colorOf(name) {
  const s0 = String(name || '?');
  let s = 0;
  for (let i = 0; i < s0.length; i++) s = (s * 31 + s0.charCodeAt(i)) % 997;
  return HUES[s % HUES.length];
}

// 取色优先级：服务端给的 color > 按 uid 哈希 > 按名字哈希。
// 用 uid 才算得准：资料卡先用列表里那份（没有 color）画、再换成接口返回的，
// 两次都按同一个 uid 哈希就不会「闪一下变个色」。
function colorFor(m) {
  if (m && m.color) return m.color;
  const u = uidOf(m);
  if (u) return colorOf(u);
  return colorOf((m && m.name) || '?');
}

export function initial(name) { return String(name || '?').trim().slice(0, 1).toUpperCase(); }

// 取成员的对外 uid。成员对象的 id 就是 uid；消息对象的 id 是消息 id，所以 from 优先。
export function uidOf(m) {
  if (!m) return '';
  const raw = m.uid || m.from || m.memberId;
  if (raw !== undefined && raw !== null && String(raw) !== '') return String(raw);
  const id = m.id;
  if (id === undefined || id === null || String(id) === '') return '';
  return String(id);
}

// m 可以是 member（id/name/avatar/kind）或消息（name/ava/kind）
export function avatar(m, size, extra) {
  const box = h('div', 'ava' + (S.frame ? ' frame-' + S.frame : '') + (extra ? ' ' + extra : ''));
  box.style.background = colorFor(m);
  if (size) { box.style.width = size + 'px'; box.style.height = size + 'px'; }
  const av = m ? (m.avatar || m.ava) : '';
  if (av) {
    const img = document.createElement('img');
    img.src = av;
    img.alt = '';
    box.textContent = '';
    box.appendChild(img);
  } else {
    box.textContent = initial(m && m.name);
  }
  if (m && m.kind === 'ai') {
    const tag = h('span', 'ai-dot');
    tag.textContent = 'AI';
    box.appendChild(tag);
  }
  // 带上对外 id，全局的头像点击委托（people.js）靠它开资料卡
  const uid = uidOf(m);
  if (uid) {
    box.classList.add('ava-click');
    box.setAttribute('data-uid', uid);
    if (m && m.name) box.setAttribute('data-name', m.name);
    if (m && m.kind) box.setAttribute('data-kind', m.kind);
    box.setAttribute('title', '查看资料');
  }
  return box;
}
