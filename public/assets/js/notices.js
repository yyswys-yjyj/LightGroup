// LightGroup · 站内信：好友申请、群注销、系统提醒都落在这里，跟空间动态分开
import { S, h, btn, api, go, pageHead, toast, hhmm } from './core.js';

let unread = 0;
let hostEl = null;

export function unreadCount() { return unread; }

export function emitUnread() {
  window.dispatchEvent(new CustomEvent('lg:notices', { detail: { unread: unread } }));
}

export async function refreshUnread() {
  const j = await api('api/notices?count=1');
  if (j && j.ok) { unread = j.unread || 0; emitUnread(); }
  return unread;
}

export function renderNotices(host) {
  hostEl = host;
  host.textContent = '';
  const page = h('div', 'chat-page');
  page.appendChild(pageHead('站内信', '好友申请、群通知和系统提醒'));
  const body = h('div', 'page-body sp-page sn-page');
  page.appendChild(body);
  host.appendChild(page);
  paint(body);
}

function repaint() {
  if (hostEl) renderNotices(hostEl);
}

async function paint(box) {
  box.textContent = '';
  const inner = h('div', 'sp-inner');
  box.appendChild(inner);

  const sub = h('div', 'sp-sub');
  inner.appendChild(sub);
  sub.appendChild(h('div', 'sp-empty', '加载中…'));

  const list = h('div', 'sp-list');
  inner.appendChild(list);

  const res = await Promise.all([api('api/notices'), api('api/subscribe')]);
  const j = res[0];
  const s = res[1] && res[1].ok ? res[1] : null;
  // 邮件提醒卡先出来，通知列表慢一点也没关系
  if (s) sub.replaceWith(subscribeCard(s));
  else sub.remove();

  if (!j || !j.ok) { list.appendChild(h('div', 'sp-empty', (j && j.err) || '加载失败')); return; }
  unread = j.unread || 0;
  emitUnread();
  const items = j.items || [];
  const anyUnread = items.some(function (n) { return !n.read; });
  let bar = null;
  if (items.length && (unread > 0 || anyUnread)) {
    bar = h('div', 'sp-notice-bar');
    const read = btn('全部已读', 'btn-ghost btn-sm', async function () {
      const r = await api('api/notices', { op: 'readAll' });
      if (!r || !r.ok) { toast('失败了'); return; }
      unread = 0;
      emitUnread();
      list.querySelectorAll('.sp-note.unread').forEach(function (el) { el.classList.remove('unread'); });
      list.querySelectorAll('[data-mark]').forEach(function (el) { el.remove(); });
      if (bar && bar.parentNode) bar.remove();
    });
    bar.appendChild(read);
    list.appendChild(bar);
  }
  if (!items.length) { list.appendChild(h('div', 'sp-empty', '还没有站内信')); return; }

  function applyRead(n, row, mark) {
    if (n.read) return;
    n.read = true;
    if (unread > 0) unread--;
    emitUnread();
    row.classList.remove('unread');
    if (mark) { mark.disabled = true; mark.textContent = '已读'; }
    if (!unread && bar && bar.parentNode) bar.remove();
  }

  function undoRead(n, row, mark) {
    if (!n.read) return;
    n.read = false;
    unread++;
    emitUnread();
    row.classList.add('unread');
    if (mark) { mark.disabled = false; mark.textContent = '标为已读'; }
    if (bar && !bar.parentNode) list.insertBefore(bar, list.firstChild);
  }

  items.forEach(function (n) {
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
        const wasUnread = !n.read;
        goBtn.disabled = true;
        if (wasUnread) applyRead(n, row, mark);
        const r = await api('api/notices', { op: 'read', id: n.id });
        goBtn.disabled = false;
        if (wasUnread && (!r || !r.ok)) undoRead(n, row, mark);
        if (String(n.link).charAt(0) !== '#') { location.href = n.link; return; }
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

function subscribeCard(s) {
  const card = h('div', 'sp-sub');
  card.appendChild(h('b', '', '邮件提醒'));
  if (!s.available) {
    card.appendChild(h('div', 'sp-empty', '邮件提醒暂时不可用'));
    return card;
  }
  if (!s.hasEmail) {
    card.appendChild(h('div', 'sp-sub-tip', '邮件提醒发到资料卡里填的邮箱。'));
    card.appendChild(h('div', 'sp-empty', '还没填邮箱。'));
    card.appendChild(btn('去填邮箱', 'btn-ghost btn-sm', function () { go('#/me'); }));
    return card;
  }

  card.appendChild(h('div', 'sp-sub-tip', '收到站内信时会发一封邮件。聊天消息不会发邮件。'));
  card.appendChild(h('div', 'sp-sub-line', '收件邮箱：' + s.email));

  if (s.subscribed) {
    card.appendChild(btn('取消订阅', 'btn-ghost btn-sm', async function () {
      const r = await api('api/subscribe', { op: 'off' });
      if (!r || !r.ok) { toast('取消失败'); return; }
      toast('已取消');
      repaint();
    }));
    return card;
  }

  if (s.pending) {
    card.appendChild(h('div', 'sp-sub-tip', '验证码已发到上面这个邮箱。'));
    const row = h('div', 'sp-cbox');
    const code = document.createElement('input');
    code.type = 'text';
    code.className = 'tb';
    code.placeholder = '邮件里的 6 位验证码';
    code.maxLength = 6;
    row.appendChild(code);
    row.appendChild(btn('确认', 'btn-primary btn-sm', async function () {
      const v = String(code.value || '').trim();
      if (!v) { toast('填验证码'); return; }
      const r = await api('api/subscribe/verify', { code: v });
      if (!r || !r.ok) { toast((r && r.err) || '验证失败'); return; }
      toast('订阅成功');
      repaint();
    }));
    card.appendChild(row);
    const again = btn('重新发送', 'btn-ghost btn-sm', async function () {
      again.disabled = true;
      const r = await api('api/subscribe', {});
      again.disabled = false;
      if (!r || !r.ok) { toast((r && r.err) || '发不出去'); return; }
      toast('验证码重新发出去了');
      repaint();
    });
    card.appendChild(again);
    return card;
  }

  card.appendChild(btn('发送验证码', 'btn-primary btn-sm', async function () {
    const r = await api('api/subscribe', {});
    if (!r || !r.ok) { toast((r && r.err) || '发不出去'); return; }
    toast('验证码发出去了，去收一下');
    repaint();
  }));
  return card;
}

function when(ts) {
  const d = new Date(Number(ts) || 0);
  const now = new Date();
  if (d.toDateString() === now.toDateString()) return hhmm(ts);
  return (d.getMonth() + 1) + '月' + d.getDate() + '日 ' + hhmm(ts);
}
