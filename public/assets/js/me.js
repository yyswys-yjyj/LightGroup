// LightGroup · 我的资料（昵称/头像/AI/API Key）+ 气泡与外观
import {
  S, BOOT, LSK, URLS, h, icon, iconBtn, btn, field, copyNote, toast, api, pageHead,
  applyScopeCss, applyWall, applyThemeMode, readDataUrl, emit, setListOpen, go,
  openSheet, closeSheet, THEMES, THEME_GROUPS, THEME_INDEX, applyThemeChoice,
  resolveThemeId, refreshThemes, themeName, contrastText,
  THEME_MODES, setThemeMode, effectiveMode, themeMode, encodeThemeChoice
} from './core.js';
import { avatar } from './avatar.js';
import { msgNode, isMine } from './msg.js';
import { openQr } from './qr.js';
import { loadMembers } from './people.js';

// ---------- 个人首页 ----------
// 功能平铺在首页，表单收进子页；移动端底栏「个人」就落在这里。
let meHost = null;
let meView = 'home';

export function renderProfile(host) {
  meHost = host || meHost;
  meView = 'home';
  paintMe();
}

function paintMe() {
  if (!meHost) return;
  // 三个视图共用一个 host，先清空再画；不清就会把首页叠在子页上面
  meHost.textContent = '';
  if (meView === 'edit') {
    renderProfileEdit(meHost);
    withBack(meHost);
    return;
  }
  if (meView === 'ai') {
    paintMeAi(meHost);
    withBack(meHost);
    return;
  }
  paintMeHome(meHost);
}

/** 二级页左上角补一个「返回」，不重写各页自己的头部 */
function withBack(host) {
  const head = host.querySelector('.chat-head');
  if (!head) return;
  const b = btn('返回', 'btn btn-ghost btn-sm', function () { meView = 'home'; paintMe(); });
  b.classList.add('me-back');
  head.insertBefore(b, head.firstChild);
}

/** markdown → 一行纯文本：给身份卡那种只放得下一行的地方用 */
function plainLine(md, max) {
  let s = String(md || '');
  s = s.replace(/```[a-zA-Z0-9+#-]*\s*/g, ' ');
  s = s.replace(/`([^`]*)`/g, '$1');
  s = s.replace(/!\[[^\]]*\]\([^)]*\)/g, ' ');
  s = s.replace(/\[([^\]]*)\]\([^)]*\)/g, '$1');
  s = s.replace(/^\s{0,3}#{1,6}\s+/gm, '');
  s = s.replace(/^\s{0,3}>\s?/gm, '');
  s = s.replace(/^\s{0,3}([-*+]|\d+\.)\s+/gm, '');
  s = s.replace(/^\s{0,3}([-*_]\s*){3,}$/gm, ' ');
  s = s.replace(/(\*\*|__)(.*?)\1/g, '$2');
  s = s.replace(/~~(.*?)~~/g, '$1');
  s = s.replace(/\*(.+?)\*/g, '$1');
  s = s.replace(/[ \t]*\n[ \t]*/g, ' ').replace(/\s+/g, ' ').trim();
  if (!s) return '';
  const lim = max || 90;
  return s.length > lim ? s.slice(0, lim) + '…' : s;
}

function paintMeHome(host) {
  const me = S.me || {};
  const role = me.role || 'member';
  const page = h('div', 'chat-page');
  page.appendChild(pageHead('个人', '你的资料、AI 账号与外观'));
  const body = h('div', 'page-body');
  page.appendChild(body);
  host.appendChild(page);

  const card = h('div', 'me-card');
  card.appendChild(avatar(me, 64));
  const info = h('div', 'me-card-info');
  info.appendChild(h('b', '', me.name || ''));
  const bits = [me.kind === 'ai' ? 'AI 成员' : '人类成员'];
  if (role === 'super') bits.push('超级管理员');
  else if (role === 'admin') bits.push('管理员');
  if (me.name) bits.push('@' + me.name);
  info.appendChild(h('span', 'me-card-sub', bits.join(' · ')));
  // 简介是 markdown，身份卡里只当一行纯文本：去掉语法、换行也压平，长了截断
  const bioOne = plainLine(me.intro || me.bio, 90);
  if (bioOne) info.appendChild(h('div', 'me-card-bio', bioOne));
  card.appendChild(info);
  body.appendChild(card);

  const aiN = (S.members || []).filter(function (m) {
    return m.kind === 'ai' && m.owner && me.name && m.owner === me.name;
  }).length;

  const grid = h('div', 'me-grid');
  grid.appendChild(meEntry('fa-pen', '我的资料', '昵称、头像、介绍', function () { meView = 'edit'; paintMe(); }));
  grid.appendChild(meEntry('fa-qrcode', '我的二维码', '别人扫一下就能找到我', function () {
    openQr('我的二维码', 'lightgroup://user/' + String(me.id || ''), '别人扫这个码能直接打开你的资料页。');
  }));
  grid.appendChild(meEntry('fa-robot', '我的 AI 账号', aiN ? aiN + ' 个，适配器与 token' : '还没有，去建一个', function () { meView = 'ai'; paintMe(); }));
  grid.appendChild(meEntry('fa-palette', '气泡与外观', '深浅、主题、气泡、壁纸', function () { go('#/appearance'); }));
  if (me.oauth) {
    grid.appendChild(meEntry('fa-users-gear', '业务账号', '进群前挑一个身份', function () {
      location.href = '/oauth/accounts?choose=1';
    }));
  }
  if (role === 'admin' || role === 'super') {
    grid.appendChild(meEntry('fa-shield-halved', '管理后台', role === 'super' ? '站点设置与所有群' : '这个群的成员和内容', function (ev) {
      openAdmin(ev.currentTarget);
    }));
  }
  grid.appendChild(meEntry('fa-right-from-bracket', '退出登录', '这台设备不再保持登录', function () { location.href = URLS.logout; }));
  body.appendChild(grid);

  if (role === 'member' && me.kind !== 'ai') {
    body.appendChild(btn('注销账号', 'btn btn-ghost btn-wide me-danger', function () { openDeleteAccount(); }));
  }
}

function meEntry(ic, title, sub, fn) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'me-entry';
  b.appendChild(icon(ic));
  const txt = h('span', 'me-entry-txt');
  txt.appendChild(h('b', '', title));
  txt.appendChild(h('span', '', sub || ''));
  b.appendChild(txt);
  b.appendChild(icon('fa-chevron-right'));
  b.addEventListener('click', fn);
  return b;
}

/** AI 账号管理：适配器决定这个号能走哪套接口 */
function paintMeAi(host) {
  const page = h('div', 'chat-page');
  page.appendChild(pageHead('我的 AI 账号', '每个 AI 有自己的适配器与 token'));
  const body = h('div', 'page-body');
  page.appendChild(body);
  host.appendChild(page);

  body.appendChild(copyNote('适配器决定这个 AI 走哪套接口：小群（xsj）只能用老接口收发消息；MCP 走 /g/mcp；API 走 /g/api/v1 的 REST。后两种是完整账号。token 只在创建时显示一次，丢了就重签。'));
  body.appendChild(myAiBlock());
  body.appendChild(h('div', 'page-sec', 'API Key'));
  body.appendChild(copyNote('给第三方客户端用，和 AI 的 token 不是一回事。'));
  body.appendChild(btn('创建一把 API Key', 'btn btn-line btn-wide', createApiKey));
}

// ---------- 资料编辑 ----------
function renderProfileEdit(host) {
  host.textContent = '';
  const page = h('div', 'chat-page');
  page.appendChild(pageHead('我的资料', '用户名、昵称、头像、介绍'));
  const body = h('div', 'page-body');
  page.appendChild(body);
  host.appendChild(page);

  const av = h('div', 'av-row');
  if (S.me) av.appendChild(avatar(S.me, 56));
  const avInfo = h('div', 'av-info');
  avInfo.appendChild(h('b', '', (S.me && S.me.name) || ''));
  avInfo.appendChild(h('small', '', (S.me && S.me.kind === 'ai') ? 'AI 成员' : '人类成员'));
  av.appendChild(avInfo);
  av.appendChild(btn('换头像', 'btn btn-ghost btn-sm', pickAvatar));
  body.appendChild(av);

  field(body, '用户名', function (row) {
    const v = document.createElement('input');
    v.className = 'tb';
    v.type = 'text';
    v.readOnly = true;
    v.value = (S.me && S.me.username) || '';
    row.appendChild(v);
  });
  body.appendChild(copyNote('用户名是登录用的，建好后不能改；下面的昵称可以随时改。'));
  field(body, '昵称', function (row) {
    const v = document.createElement('input');
    v.id = 'f-name';
    v.className = 'tb';
    v.type = 'text';
    v.value = (S.me && S.me.name) || '';
    row.appendChild(v);
  });
  field(body, '介绍', function (row) {
    const v = document.createElement('textarea');
    v.id = 'f-intro';
    v.className = 'tb';
    v.rows = 3;
    v.value = (S.me && (S.me.intro || S.me.bio)) || '';
    row.appendChild(v);
  });
  const sw = h('label', 'sw-row');
  const inv = document.createElement('input');
  inv.type = 'checkbox';
  inv.id = 'f-allow-invite';
  inv.checked = !(S.me && S.me.allowInvite === false);
  sw.appendChild(inv);
  sw.appendChild(h('span', '', '允许他人把我拉进群聊'));
  body.appendChild(sw);
  body.appendChild(copyNote('关掉之后，别人建群时选不到你；已经在的群不受影响。'));

  body.appendChild(btn('保存', 'btn btn-primary btn-wide', saveProfile));

}

/**
 * 二次确认：输入昵称才让注销。注销接口挂在 /login 上（JSON），
 * 不走 api()：api() 前缀是 /g/，而且它见到「注销」类的错误会直接跳登录页。
 */
function openDeleteAccount() {
  const body = h('div', 'sheet-body');
  body.appendChild(h('div', 'page-tip', '注销后这个昵称会被释放，别人可以重新注册；你的消息显示「账号已注销」。'));
  body.appendChild(h('div', 'f-label', '输入昵称「' + ((S.me && S.me.name) || '') + '」确认'));
  const inp = document.createElement('input');
  inp.className = 'tb';
  inp.type = 'text';
  inp.autocomplete = 'off';
  inp.placeholder = '一字不差地打一遍';
  body.appendChild(inp);
  const goBtn = btn('确认注销', 'btn btn-primary btn-wide', function () {
    const name = (inp.value || '').trim();
    if (!name) { toast('先把昵称打一遍'); inp.focus(); return; }
    goBtn.disabled = true;
    fetch(URLS.login, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'account.delete', name: name })
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.ok) {
        goBtn.disabled = false;
        toast((j && j.err) || '注销失败');
        return;
      }
      closeSheet();
      location.replace(URLS.login);
    }).catch(function () {
      goBtn.disabled = false;
      toast('注销失败，稍后再试');
    });
  });
  body.appendChild(goBtn);
  openSheet('注销账号', body);
}

/** 后台是服务端会话鉴权的独立页面，直接跳过去 */
export function openAdmin(button) {
  if (button) {
    if (button.disabled) return;
    button.disabled = true;
  }
  location.href = URLS.admin;
}

// 创建 AI 时选适配器：xsj 给小群插件，mcp 给 MCP 客户端，api 给 REST 客户端
let aiAdapter = 'xsj';

function adapterHint(a) {
  if (a === 'xsj') return '小群（xsj）：老的小群接口，只能收发消息、看成员、等新消息。';
  if (a === 'api') return 'API（REST）：走 /g/api/v1，会话、好友、空间、通知都能用，适合自己写脚本或客户端。';
  return 'MCP：完整的 AI 账号，会话、好友、空间、通知都能用。';
}

function adapterLabel(a) {
  return a === 'xsj' ? '小群适配器' : (a === 'api' ? 'API 适配器' : 'MCP 适配器');
}

function adapterShort(a) {
  return a === 'xsj' ? '小群' : (a === 'api' ? 'API' : 'MCP');
}

function myAiBlock() {
  const box = h('div', 'ai-box');
  const list = h('div', 'ai-list');
  paintMyAi(list);
  box.appendChild(list);

  const hint = h('div', 'page-tip', adapterHint(aiAdapter));
  const pick = h('div', 'chip-row');
  const chips = {};
  [['xsj', '小群（xsj）'], ['mcp', 'MCP'], ['api', 'API']].forEach(function (o) {
    const b = btn(o[1], 'chip' + (aiAdapter === o[0] ? ' on' : ''), function () {
      aiAdapter = o[0];
      Object.keys(chips).forEach(function (k) { chips[k].classList.toggle('on', k === aiAdapter); });
      hint.textContent = adapterHint(aiAdapter);
    });
    chips[o[0]] = b;
    pick.appendChild(b);
  });
  box.appendChild(h('div', 'page-sec', '新建 AI'));
  box.appendChild(pick);
  box.appendChild(hint);

  const row = h('div', 'add-row');
  const input = document.createElement('input');
  input.id = 'ai-name';
  input.className = 'tb';
  input.type = 'text';
  input.placeholder = 'AI 的名字';
  row.appendChild(input);
  row.appendChild(btn('添加', 'btn btn-primary', function () { addAi(input); }));
  box.appendChild(row);
  return box;
}

function paintMyAi(list) {
  list.textContent = '';
  const mine = (S.members || []).filter(function (m) {
    return m.kind === 'ai' && m.owner && S.me && m.owner === S.me.name;
  });
  if (!mine.length) { list.appendChild(h('div', 'page-tip', '还没有 AI。')); return; }
  mine.forEach(function (a) {
    const row = h('div', 'ai-row');
    row.appendChild(avatar(a, 30));
    row.appendChild(h('span', 'ai-name', a.name));
    const ad = a.adapter === 'xsj' ? 'xsj' : (a.adapter === 'api' ? 'api' : 'mcp');
    row.appendChild(h('span', 'ai-adapter on', adapterShort(ad)));
    row.appendChild(btn('重签 token', 'btn btn-ghost btn-sm', function () { rekeyAi(a, row); }));
    row.appendChild(btn('销毁', 'btn btn-ghost btn-sm', function () { deleteAi(a); }));
    list.appendChild(row);
  });
}

function addAi(input) {
  const name = (input.value || '').trim();
  if (!name) { input.focus(); return; }
  const adapter = aiAdapter;
  api('api/ai/create', { name: name, adapter: adapter }).then(function (j) {
    if (!j.ok) { toast(j.err || '创建失败'); return; }
    input.value = '';
    keyBox('AI「' + ((j.bot && j.bot.name) || name) + '」已创建（' + adapterLabel(adapter) + '），token 只显示这一次：', j.token || '', j.mcp || '', j.default || null, j.api || '');
    loadMembers().then(function () {
      const l = document.querySelector('.ai-list');
      if (l) paintMyAi(l);
    });
  }).catch(function (e) { toast(String((e && e.message) || e)); });
}

function rekeyAi(a, row) {
  const adapter = a.adapter === 'xsj' ? 'xsj' : (a.adapter === 'api' ? 'api' : 'mcp');
  api('api/ai/create', { name: a.name, adapter: adapter }).then(function (j) {
    if (!j.ok) { toast(j.err || '重签失败'); return; }
    keyBox('AI「' + a.name + '」的新 token（' + adapterLabel(adapter) + '）：', j.token || '', j.mcp || '', null, j.api || '');
    void row;
  }).catch(function (e) { toast(String((e && e.message) || e)); });
}

/** 销毁自己名下的 AI：软删，消息留着、token 立刻失效、名字不释放 */
function deleteAi(a) {
  if (!confirm('销毁 AI「' + a.name + '」？它发过的消息还留着，但 token 立刻失效；想要回同名账号，再建一个同名的就行。')) return;
  api('api/ai/delete', { name: a.name }).then(function (j) {
    if (!j.ok) { toast(j.err || '销毁失败'); return; }
    toast('已销毁「' + a.name + '」');
    loadMembers().then(function () {
      const l = document.querySelector('.ai-list');
      if (l) paintMyAi(l);
    });
  }).catch(function (e) { toast(String((e && e.message) || e)); });
}

function keyBox(label, tok, mcp, def, apiUrl) {
  const old = document.querySelector('.key-wrap');
  if (old) old.remove();
  const wrap = h('div', 'key-wrap');
  wrap.appendChild(h('div', 'page-tip', label));
  const box = h('div', 'key-box');
  box.appendChild(h('code', '', tok));
  wrap.appendChild(box);
  wrap.appendChild(btn('复制', 'btn btn-ghost btn-sm', function () { copy(tok); }));
  // 新建的 AI 默认挂到公开大群：不指定会话时读写都落在这里
  if (def && def.title) {
    wrap.appendChild(h('div', 'page-tip', '默认落在「' + def.title + '」：read 读群消息，send 不带会话就直接在这发言。'));
  }
  // 站点开着 MCP 时，服务端会连同 token 一起给地址，直接贴进 Operit 就能连
  if (mcp) {
    wrap.appendChild(h('div', 'page-tip', '同一个身份还有一条 MCP 地址，填进 Operit 的 MCP 配置就能连，不用另建账号：'));
    const mbox = h('div', 'key-box');
    mbox.appendChild(h('code', '', mcp));
    wrap.appendChild(mbox);
    wrap.appendChild(btn('复制 MCP 地址', 'btn btn-ghost btn-sm', function () { copy(mcp); }));
  }
  // api 适配器：REST 地址（/g/api/v1），token 走 Authorization: Bearer 或 ?k=
  if (apiUrl) {
    wrap.appendChild(h('div', 'page-tip', '这个 AI 走 REST：下面这个地址 + token 就能读写，适合自己写脚本或 App：'));
    const abox = h('div', 'key-box');
    abox.appendChild(h('code', '', apiUrl));
    wrap.appendChild(abox);
    wrap.appendChild(h('div', 'page-tip', '用法：GET ' + apiUrl + '/me，请求头 Authorization: Bearer <token>；接口清单直接 GET ' + apiUrl + '。'));
    wrap.appendChild(btn('复制 REST 地址', 'btn btn-ghost btn-sm', function () { copy(apiUrl); }));
  }
  const ai = document.querySelector('.ai-box');
  const parent = ai ? ai.parentNode : document.querySelector('.page-body');
  if (parent) parent.insertBefore(wrap, ai || null);
}

async function copy(t) {
  try { await navigator.clipboard.writeText(t); toast('已复制'); }
  catch (e) { toast('复制不了，手动选一下吧'); }
}

function saveProfile() {
  const nameEl = document.getElementById('f-name');
  const introEl = document.getElementById('f-intro');
  const name = nameEl ? nameEl.value.trim() : '';
  const intro = introEl ? introEl.value : '';
  const invEl = document.getElementById('f-allow-invite');
  if (!name) { toast('昵称不能空着'); return; }
  api('api/profile', {
    name: name,
    bio: intro,
    allowInvite: invEl && invEl.checked ? '1' : '0'
  }).then(function (j) {
    if (!j.ok) { toast(j.err || '保存失败'); return; }
    if (j.me) S.me = j.me;
    toast('已保存');
    loadMembers().then(function () { emit('refresh'); });
    // 保存完回个人首页，别停在表单上
    meView = 'home';
    paintMe();
  }).catch(function (e) { toast(String((e && e.message) || e)); });
}

function createApiKey() {
  api('/u/api/v1/keys', { name: '网页客户端' }).then(function (j) {
    if (!j.ok) { toast(j.err || '创建失败'); return; }
    keyBox('API Key 只显示这一次：', j.key || '');
  }).catch(function (e) { toast(String((e && e.message) || e)); });
}

function pickAvatar() {
  const inp = document.createElement('input');
  inp.type = 'file';
  inp.accept = 'image/*';
  inp.addEventListener('change', function () {
    const f = inp.files && inp.files[0];
    if (!f) return;
    readDataUrl(f).then(function (url) {
      return api('api/upload', { data: url, kind: 'avatar' }).then(function (j) {
        if (!j.ok) { toast(j.err || '上传失败'); return null; }
        return api('api/profile', { avatar: j.url });
      });
    }).then(function (k) {
      if (!k) return;
      if (!k.ok) { toast(k.err || '保存失败'); return; }
      if (k.me) S.me = k.me;
      toast('头像已更新');
      loadMembers().then(function () { emit('refresh'); });
    }).catch(function (e) { toast(String((e && e.message) || e)); });
  });
  inp.click();
}

// ---------- 气泡与外观 ----------
const PRESETS = [
  ['默认', ''],
  ['轻角', '.bub{border-radius:10px}'],
  ['方圆', '.bub{border-radius:4px}'],
  ['描线', '.bub{border-radius:14px;box-shadow:none;border:1px solid var(--border)}'],
  ['渐变', '.row.me .bub{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:var(--accent-text)}']
];
const FRAMES = [['', '原样'], ['circle', '圆形'], ['square', '方角'], ['dash', '虚线框']];

// ---------- 主题 ----------
let themeDim = 'temp';

function themeCard(t) {
  const on = (S.themeChoice || '') === t.id;
  const c = document.createElement('button');
  c.type = 'button';
  c.className = 'theme-card' + (on ? ' on' : '');
  const sw = h('span', 'theme-swatch');
  sw.style.background = t.accent;
  sw.style.color = contrastText(t.accent);
  const body = h('span', 'theme-body');
  // 浅底由主色现混，深色模式下自动变暗，不会预览出一块刺眼的白
  body.style.background = 'color-mix(in srgb, ' + t.accent + ' 18%, var(--surface))';
  c.appendChild(sw);
  c.appendChild(body);
  c.appendChild(h('span', 'theme-name', t.name));
  c.addEventListener('click', function () { pickTheme(t.id); });
  return c;
}

function pickTheme(id) {
  // 强调色与模式拼成一个串存进 members.theme：mode-accent
  const val = encodeThemeChoice(themeMode(), id);
  api('api/theme/set', { theme: val }).then(function (j) {
    if (!j.ok) { toast(j.err || '换主题失败'); return; }
    S.themeChoice = id;
    S.themeSaved = val;
    applyThemeChoice(resolveThemeId());
    emit('refresh');
  }).catch(function (e) { toast(String((e && e.message) || e)); });
}

function renderThemeBlock(box) {
  box.textContent = '';
  const cur = S.themeChoice || '';
  const line = h('div', 'theme-cur');
  line.appendChild(h('span', '', '当前：' + (cur ? themeName(cur) : '跟随本群默认（' + themeName(resolveThemeId()) + '）')));
  if (cur) line.appendChild(btn('跟随本群默认', 'btn btn-ghost', function () { pickTheme(''); }));
  box.appendChild(line);

  const tabs = h('div', 'theme-tabs');
  THEME_GROUPS.forEach(function (g) {
    tabs.appendChild(btn(g[1], 'chip' + (themeDim === g[0] ? ' on' : ''), function () {
      themeDim = g[0];
      renderThemeBlock(box);
    }));
  });
  box.appendChild(tabs);

  const group = THEME_GROUPS.find(function (g) { return g[0] === themeDim; });
  if (group) {
    group[2].forEach(function (pair) {
      const key = pair[0];
      const list = THEMES.filter(function (t) { return (TAG_INDEX(t.id) || {})[group[0]] === key; });
      if (!list.length) return;
      box.appendChild(h('div', 'theme-group', pair[1] + ' · ' + list.length));
      const grid = h('div', 'theme-grid');
      list.forEach(function (t) { grid.appendChild(themeCard(t)); });
      box.appendChild(grid);
    });
  }

  const packs = (S.themePacks || []);
  if (packs.length) {
    box.appendChild(h('div', 'theme-group', '我的主题包 · ' + packs.length));
    packs.forEach(function (pk) {
      const row = h('div', 'theme-pack' + (cur === pk.id ? ' on' : ''));
      const dot = h('span', 'theme-pack-dot');
      const v = pk.vars || {};
      dot.style.background = v.accent || v.bubble || '#999';
      row.appendChild(dot);
      const nm = h('span', 'theme-pack-name', pk.name);
      row.appendChild(nm);
      if (pk.shared) row.appendChild(h('span', 'theme-pack-tag', '本群共享'));
      row.addEventListener('click', function () { pickTheme(pk.id); });
      const del = iconBtn('fa-trash-can', '删除');
      del.addEventListener('click', function (e) {
        e.stopPropagation();
        if (!confirm('删除主题包「' + pk.name + '」？')) return;
        api('api/theme/pack/del', { id: pk.id }).then(function (j) {
          if (!j.ok) { toast(j.err || '删除失败'); return; }
          toast('已删除');
          refreshThemes().then(function () { emit('refresh'); });
        });
      });
      row.appendChild(del);
      box.appendChild(row);
    });
  }

  box.appendChild(btn('新建主题包', 'btn btn-line btn-wide', openThemeEditor));
}

function TAG_INDEX(id) { return (window.__lgTagIndex || {})[id] || {}; }

function openThemeEditor() {
  const body = h('div', 'sheet-body');
  const name = document.createElement('input');
  name.className = 'tb';
  name.placeholder = '主题名，例如「我的黄昏」';
  name.maxLength = 20;
  body.appendChild(h('div', 'f-label', '主题名'));
  body.appendChild(name);

  const accent = document.createElement('input');
  accent.type = 'color';
  accent.value = '#2563eb';
  accent.className = 'theme-color';
  const row = h('div', 'theme-color-row');
  row.appendChild(h('span', '', '主色'));
  row.appendChild(accent);
  body.appendChild(row);
  body.appendChild(copyNote('浅底与深色底都由主色和当前深浅模式自动混出来，不用单独配。'));

  // 预览
  const pv = h('div', 'theme-preview');
  const pvSw = h('span', 'theme-preview-sw');
  const pvBody = h('span', 'theme-preview-body');
  pv.appendChild(pvSw); pv.appendChild(pvBody);
  body.appendChild(pv);
  function sync() {
    pvSw.style.background = accent.value;
    pvSw.style.color = contrastText(accent.value);
    pvBody.style.background = 'color-mix(in srgb, ' + accent.value + ' 18%, var(--surface))';
  }
  accent.addEventListener('input', sync);
  sync();

  const role = (S.me && S.me.role) || 'member';
  let sharedBox = null;
  if (role === 'admin' || role === 'super') {
    sharedBox = document.createElement('input');
    sharedBox.type = 'checkbox';
    const lab = document.createElement('label');
    lab.className = 'f-check';
    lab.appendChild(sharedBox);
    lab.appendChild(h('span', '', '全群成员都能用（管理员）'));
    body.appendChild(lab);
  }

  body.appendChild(btn('保存', 'btn btn-primary btn-wide', function () {
    const nm = (name.value || '').trim();
    if (!nm) { toast('先给主题起个名'); return; }
    api('api/theme/pack/save', {
      name: nm,
      shared: sharedBox && sharedBox.checked ? 1 : 0,
      vars: { accent: accent.value }
    }).then(function (j) {
      if (!j.ok) { toast(j.err || '保存失败'); return; }
      closeSheet();
      toast('主题包已保存');
      refreshThemes().then(function () { emit('refresh'); });
    }).catch(function (e) { toast(String((e && e.message) || e)); });
  }));
  openSheet('新建主题包', body);
}

export function renderAppearance(host) {
  host.textContent = '';
  const page = h('div', 'chat-page');
  page.appendChild(pageHead('气泡与外观', '跟随账号同步；深浅跟随系统或你手选的模式'));
  const body = h('div', 'page-body');
  page.appendChild(body);
  host.appendChild(page);

  // 深浅模式：立刻生效 + 存本地 + 同步服务端（换设备登录还在）
  body.appendChild(h('div', 'page-sec', '深浅'));
  const modeRow = h('div', 'chip-row');
  THEME_MODES.forEach(function (m) {
    const on = S.themeMode === m[0];
    const label = m[0] === 'auto' ? m[1] + ' · 当前' + (effectiveMode() === 'dark' ? '深色' : '浅色') : m[1];
    modeRow.appendChild(btn(label, 'chip' + (on ? ' on' : ''), function () {
      setThemeMode(m[0]);
      emit('refresh');
    }));
  });
  body.appendChild(modeRow);

  body.appendChild(h('div', 'page-sec', '主题'));
  const themeBox = h('div', 'theme-box');
  body.appendChild(themeBox);
  renderThemeBlock(themeBox);

  body.appendChild(h('div', 'page-sec', '气泡样式'));
  const grid = h('div', 'preset-grid');
  PRESETS.forEach(function (p) {
    const c = document.createElement('button');
    c.type = 'button';
    c.className = 'preset';
    c.appendChild(h('b', '', p[0]));
    c.addEventListener('click', function () {
      const ta = document.getElementById('f-css');
      if (ta) ta.value = p[1];
      applyScopeCss((S.gcss || '') + '\n' + p[1]);
      S.css = p[1];
    });
    grid.appendChild(c);
  });
  body.appendChild(grid);

  const ta = document.createElement('textarea');
  ta.id = 'f-css';
  ta.className = 'tb mono';
  ta.rows = 6;
  ta.placeholder = '例如 .bub{border-radius:18px;background:var(--bubble)}';
  ta.value = S.css || '';
  ta.addEventListener('input', function () { applyScopeCss((S.gcss || '') + '\n' + ta.value); S.css = ta.value; });
  body.appendChild(ta);
  body.appendChild(copyNote('可用选择器：.bub 气泡本体，.row.me .bub 自己的，.nm 名字，.ava 头像，.row 整行，body 背景。'));

  const pv = h('div', 'preview');
  const pvFeed = h('div', 'feed mini');
  pvFeed.appendChild(msgNode({ id: 9001, ts: Date.now(), name: '他', kind: 'human', text: '这是对面的气泡，改 .bub 就变。' }, null));
  pvFeed.appendChild(msgNode({ id: 9002, ts: Date.now(), name: (S.me && S.me.name) || '我', kind: 'human', from: (S.me && S.me.id) || '', text: '这是自己的气泡，改 .row.me .bub 就变。' }, null));
  pv.appendChild(pvFeed);
  body.appendChild(pv);

  body.appendChild(btn('应用并保存', 'btn btn-primary btn-wide', function () {
    const cssEl = document.getElementById('f-css');
    const css = cssEl ? cssEl.value : '';
    applyScopeCss((S.gcss || '') + '\n' + css);
    S.css = css;
    api('api/styles', { css: css }).then(function (j) {
      toast(j.ok ? '已保存' : (j.err || '保存失败'));
    }).catch(function (e) { toast(String((e && e.message) || e)); });
  }));
  body.appendChild(btn('恢复默认', 'btn btn-ghost btn-wide', function () {
    const cssEl = document.getElementById('f-css');
    if (cssEl) cssEl.value = '';
    applyScopeCss(S.gcss || '');
    S.css = '';
    api('api/styles', { css: '' }).then(function (j) {
      toast(j.ok ? '已恢复' : (j.err || '恢复失败'));
    }).catch(function () {});
  }));

  body.appendChild(h('div', 'page-sec', '头像框'));
  const chips = h('div', 'chip-row');
  FRAMES.forEach(function (f) {
    const on = (S.frame || '') === f[0];
    chips.appendChild(btn(f[1], 'chip' + (on ? ' on' : ''), function () {
      S.frame = f[0];
      localStorage.setItem(LSK('frame'), f[0]);
      emit('refresh');
    }));
  });
  body.appendChild(chips);

  body.appendChild(h('div', 'page-sec', '聊天壁纸'));
  body.appendChild(copyNote('只影响你自己这台设备上的画面，别人看不到。'));
  body.appendChild(btn('选择图片当壁纸', 'btn btn-ghost btn-wide', function () {
    const inp = document.createElement('input');
    inp.type = 'file';
    inp.accept = 'image/*';
    inp.addEventListener('change', function () {
      const f = inp.files && inp.files[0];
      if (!f) return;
      readDataUrl(f).then(function (url) {
        try { localStorage.setItem(LSK('wall'), url); } catch (err) { toast('图片太大，存不下'); return; }
        S.wall = url;
        applyWall();
        toast('壁纸已换');
      }).catch(function () { toast('读取失败'); });
    });
    inp.click();
  }));
  body.appendChild(btn('清除壁纸', 'btn btn-ghost btn-wide', function () {
    try { localStorage.removeItem(LSK('wall')); } catch (err) { void err; }
    S.wall = '';
    applyWall();
    toast('壁纸已清除');
  }));
}
