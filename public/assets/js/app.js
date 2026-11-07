// LightGroup · 前端入口：登录检查、三栏布局、hash 路由分发
import {
  S, BOOT, LSK, URLS, h, icon, iconBtn, btn, toast, api, toLogin,
  siteName, applyThemeMode, applyScopeCss, applyWall, refreshThemes,
  startRouter, parseRoute, go, on, onLayout, applyLayout, setListOpen, closeSheet
} from './core.js';
import { avatar } from './avatar.js';
import * as conv from './conv.js';
import * as chat from './chat.js';
import * as people from './people.js';
import * as me from './me.js';

const APP = document.getElementById('app');
let convTimer = 0;


// ---------- 三栏骨架 ----------
function buildShell() {
  APP.textContent = '';
  const im = h('div', 'im');
  im.id = 'im';

  const rail = h('aside', 'im-rail');
  rail.id = 'im-rail';
  const list = h('section', 'im-list');
  list.id = 'im-list';
  const main = h('section', 'im-main');
  main.id = 'im-main';
  const host = h('div', 'page-host');
  host.id = 'page-host';
  const foot = h('footer', 'site-foot');
  foot.innerHTML = BOOT.foot || '';
  main.appendChild(host);
  main.appendChild(foot);

  im.appendChild(rail);
  im.appendChild(list);
  im.appendChild(main);
  APP.appendChild(im);

  fillRail();
  conv.mount(list);
  layout();
}

function layout() {
  const im = document.getElementById('im');
  const list = document.getElementById('im-list');
  if (!im) return;
  im.classList.toggle('list-open', !!S.listOpen);
  if (list) list.classList.toggle('is-open', !!S.listOpen);
  refreshRailState();
}

function railTab(key, label, ic, on) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'rail-tab' + (on ? ' on' : '');
  b.title = label;
  b.appendChild(icon(ic));
  b.appendChild(h('span', '', label));
  b.addEventListener('click', function () {
    if (key === 'chat') {
      setListOpen(true);
      go('#/c/' + (S.curId || 0));
    } else {
      setListOpen(false);
      go('#/friends');
    }
  });
  return b;
}

function fillRail() {
  const rail = document.getElementById('im-rail');
  if (!rail) return;
  rail.textContent = '';
  const meBtn = document.createElement('button');
  meBtn.type = 'button';
  meBtn.className = 'rail-me';
  meBtn.title = '我的资料';
  meBtn.appendChild(avatar(S.me || { name: '我' }, 40));
  meBtn.addEventListener('click', function () { openRailMenu(meBtn); });
  rail.appendChild(meBtn);
  rail.appendChild(railTab('chat', '聊天', 'fa-comments', S.page === 'chat'));
  const ct = railTab('contacts', '联系人', 'fa-user-group', S.page === 'friends' || S.page === 'members');
  if (people.pendingCount() > 0) ct.appendChild(h('span', 'req-badge', String(people.pendingCount())));
  rail.appendChild(ct);
  rail.appendChild(h('div', 'rail-spacer'));
}

// 只改状态不重建，避免菜单被点掉
function refreshRailState() {
  const rail = document.getElementById('im-rail');
  if (!rail) return;
  const tabs = rail.querySelectorAll('.rail-tab');
  if (tabs.length < 2) return;
  tabs[0].classList.toggle('on', S.page === 'chat');
  tabs[1].classList.toggle('on', S.page === 'friends' || S.page === 'members');
  const dot = tabs[1].querySelector('.req-badge');
  if (people.pendingCount() > 0) {
    if (!dot) tabs[1].appendChild(h('span', 'req-badge', String(people.pendingCount())));
    else dot.textContent = String(people.pendingCount());
  } else if (dot) {
    dot.remove();
  }
}

function openRailMenu(anchor) {
  const old = document.querySelector('.rail-menu');
  if (old) old.remove();
  const menu = h('div', 'rail-menu');
  const items = [
    ['我的资料', 'fa-user', function () { go('#/me'); }],
    ['气泡与外观', 'fa-paintbrush', function () { go('#/appearance'); }]
  ];
  // 管理后台只对管理员开：普通成员和 AI 不该看到这个入口
  const myRole = (S.me && S.me.role) || 'member';
  if (myRole === 'admin' || myRole === 'super') {
    items.push(['管理后台', 'fa-gear', function () { me.openAdmin(null); }]);
  }
  items.push(['退出登录', 'fa-right-from-bracket', function () { location.href = URLS.logout; }]);
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
  if (window.innerWidth <= 860) {
    menu.style.left = '12px';
    menu.style.bottom = Math.max(12, window.innerHeight - r.top + 8) + 'px';
  } else {
    menu.style.left = Math.max(8, r.right + 8) + 'px';
    menu.style.top = Math.max(12, Math.min(r.top, window.innerHeight - 230)) + 'px';
  }
  setTimeout(function () {
    document.addEventListener('click', function onDoc(ev) {
      if (!document.querySelector('.rail-menu')) { document.removeEventListener('click', onDoc); return; }
      if (!menu.contains(ev.target)) { menu.remove(); document.removeEventListener('click', onDoc); }
    });
  }, 0);
}

// ---------- 路由 ----------
function renderRoute() {
  const r = parseRoute();
  const prev = S.page;
  // 整页重渲染会把滚动位置清零：点头像框样式、换主题这类按钮不该把人弹回顶部
  const keepY = window.scrollY || 0;
  const hostEl = document.getElementById('page-host');
  const keepHostY = hostEl ? hostEl.scrollTop : 0;
  // 真正的滚动条多半在内层 .page-body 上，window 本身不滚
  const innerEl = hostEl ? hostEl.querySelector('.page-body') : null;
  const keepInnerY = innerEl ? innerEl.scrollTop : 0;
  S.page = r.page;
  closeSheet();

  if (r.page === 'chat') {
    if (prev !== 'chat') { /* 从别的页面回来，聊天页重新挂 */ }
    chat.mount(r.id);
  } else {
    chat.stop();
    const host = document.getElementById('page-host');
    if (!host) return;
    if (r.page === 'friends') people.renderFriends(host);
    else if (r.page === 'members') people.renderMembers(host);
    else if (r.page === 'me') me.renderProfile(host);
    else me.renderAppearance(host);
  }
  layout();
  if (keepY || keepHostY || keepInnerY) {
    const put = function () {
      if (hostEl) hostEl.scrollTop = keepHostY;
      if (keepInnerY && hostEl) {
        const n = hostEl.querySelector('.page-body');
        if (n) n.scrollTop = keepInnerY;
      }
      if (keepY) window.scrollTo(0, keepY);
    };
    put();
    // 内容高度还没稳定时浏览器会夹住滚动，下一帧再补一次
    requestAnimationFrame(put);
  }
}

// ---------- 好友变更轮询 ----------
// 好友申请 / 同意不产生消息，原来只在进页面时拉一次 api/friends，红点和列表都很滞后。
// 这里每 2.5s 打一个只回 {sig, friends, pending} 的小接口，指纹变了才去拉完整列表。
let friendTimer = 0;
let friendSig = '';

async function friendTick() {
  if (document.hidden) return;
  let j;
  try { j = await api('api/friends/poll'); } catch (e) { return; }
  if (!j || !j.ok || !j.sig) return;
  if (!friendSig) { friendSig = j.sig; return; }
  if (j.sig === friendSig) return;
  friendSig = j.sig;
  // loadFriends 里会 emit('lg:friends')，红点由那边刷新
  await people.loadFriends();
  if (S.page === 'friends') {
    const act = document.activeElement;
    const typing = act && (act.tagName === 'INPUT' || act.tagName === 'TEXTAREA');
    const host = document.getElementById('page-host');
    if (host && !typing) people.renderFriends(host);
  }
}

function startFriendPoll() {
  if (friendTimer) clearInterval(friendTimer);
  friendTimer = setInterval(friendTick, 2500);
  friendTick();
}

// ---------- 启动 ----------
async function bootChat() {
  const j = await api('api/me');
  if (!j.ok) {
    // 会话没了（api() 里已经把 302/登录失效统一导到 /login），这里只留个兜底
    toast(j.err || '登录失效');
    toLogin();
    return;
  }
  if (j.me) S.me = j.me;
  if (j.members && j.members.length) {
    S.members = j.members;
    S.memberSig = j.members.map(function (m) { return m.id + ':' + m.name + ':' + (m.avatar ? 1 : 0); }).join('|');
  }
  if (j.title) BOOT.site = Object.assign({}, BOOT.site, { name: j.title });
  S.notice = j.notice || null;
  S.pins = j.pins || [];
  S.todos = j.todos || [];
  S.gcss = typeof j.gcss === 'string' ? j.gcss : '';
  if (typeof j.style === 'string') S.css = j.style;
  if (typeof j.wall === 'string' && j.wall) {
    S.wall = j.wall;
    try { localStorage.setItem(LSK('wall'), j.wall); } catch (e) { void e; }
  }
  const st = await api('api/styles');
  if (st && st.ok && typeof st.css === 'string') S.css = st.css;
  applyScopeCss((S.gcss || '') + '\n' + (S.css || ''));
  applyWall();
  await refreshThemes();

  buildShell();
  await conv.load();
  await people.loadFriends();
  startFriendPoll();

  // 默认落到公开大群；显式带了会话 id 就听 url 的
  // 但 #/u/<uid> 是用户资料页，profile.js 自己接管，这里不能把 hash 改回群聊，
  // 否则刷新一下资料页就掉回聊天了
  const r = parseRoute();
  const userDeepLink = /^#\/u\//.test(String(location.hash || ""));
  if (!userDeepLink && r.page === 'chat' && !r.id) {
    const pub = conv.publicConv();
    const id = pub ? pub.id : 0;
    history.replaceState(null, '', '#/c/' + id);
  }
  S.listOpen = window.innerWidth > 860 ? true : (parseRoute().page === 'chat' ? true : false);

  startRouter(renderRoute);

  if (convTimer) clearInterval(convTimer);
  convTimer = setInterval(function () {
    if (document.hidden || !S.convsReady) return;
    conv.load();
  }, 7000);
}

function boot() {
  S.css = localStorage.getItem(LSK('css')) || '';
  applyThemeMode(S.theme || (BOOT.site && BOOT.site.theme) || 'auto');
  applyScopeCss(S.css);
  applyWall();
  onLayout(layout);
  on('refresh', function () { if (S.convsReady) renderRoute(); });
  window.addEventListener('lg:friends', function () { refreshRailState(); });
  window.addEventListener('hashchange', function () { /* startRouter 已接管 */ });

  // 登录态由服务端会话决定（未登录服务端已 302 到 /login），这里直接按 api/me 探活
  bootChat();
}

boot();
