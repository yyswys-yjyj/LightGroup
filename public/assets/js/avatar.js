// LightGroup · 头像、颜色、首字、头像框
import { S, h } from './core.js';

const HUES = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#ca8a04', '#16a34a', '#0891b2', '#475569', '#9f1239', '#4d7c0f'];

export function colorOf(name) {
  const s0 = String(name || '?');
  let s = 0;
  for (let i = 0; i < s0.length; i++) s = (s * 31 + s0.charCodeAt(i)) % 997;
  return HUES[s % HUES.length];
}

export function initial(name) { return String(name || '?').trim().slice(0, 1).toUpperCase(); }

// 从成员对象（id/kind）或消息对象（from）里取对外 id："h_5" / "a_6"。
// 消息的 m.id 是消息 id，所以 from 优先；成员的数字 id 按 kind 补前缀。
export function uidOf(m) {
  if (!m) return '';
  const raw = m.from || m.memberId || m.uid;
  const ok = function (v) { return /^[ha]_\d+$/.test(String(v)); };
  if (raw && ok(raw)) return String(raw);
  const id = (m.id === null || m.id === undefined) ? '' : String(m.id);
  if (ok(id)) return id;
  if (/^\d+$/.test(id)) return (m.kind === 'ai' ? 'a_' : 'h_') + id;
  return raw ? String(raw) : '';
}

// m 可以是 member（id/name/avatar/kind）或消息（name/ava/kind）
export function avatar(m, size, extra) {
  const box = h('div', 'ava' + (S.frame ? ' frame-' + S.frame : '') + (extra ? ' ' + extra : ''));
  box.style.background = (m && m.color) ? m.color : colorOf((m && m.name) || '?');
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
