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
import { loadMembers } from './people.js';

// ---------- 我的资料 ----------
export function renderProfile(host) {
  host.textContent = '';
  const page = h('div', 'chat-page');
  page.appendChild(pageHead('我的资料', '昵称、头像、我的 AI 与 API Key'));
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
  body.appendChild(btn('保存', 'btn btn-primary btn-wide', saveProfile));

  body.appendChild(h('div', 'page-sec', '主题'));
  // 深浅模式：立刻生效 + 存本地 + 同步服务端（换设备登录还在）
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
  // 自定义主题包、主色都在「气泡与外观」里，这里给个入口，免得在资料页找不到
  body.appendChild(btn('主题包与主色', 'btn btn-ghost btn-wide', function () { go('#/appearance'); }));

  // OAuth 门禁建的群身份：主账号下可以有好几个业务账号，去专门那页挑或新建
  if (S.me && S.me.oauth) {
    body.appendChild(h('div', 'page-sec', '业务账号'));
    body.appendChild(copyNote('登录用的是第三方主账号，群里说话的是业务账号。一个主账号可以建好几个，进群前自己挑。'));
    body.appendChild(btn('管理业务账号', 'btn btn-ghost btn-wide', function () {
      location.href = '/oauth/accounts?choose=1';
    }));
  }

  body.appendChild(h('div', 'page-sec', '我的 AI'));
  body.appendChild(copyNote('AI 用 token 接入。token 只在创建时显示一次，丢了就重签。'));
  body.appendChild(myAiBlock());

  body.appendChild(h('div', 'page-sec', 'API Key'));
  body.appendChild(copyNote('给第三方客户端用。创建后只显示一次，记得存好。'));
  body.appendChild(btn('创建一把 API Key', 'btn btn-ghost btn-wide', createApiKey));

  const role = (S.me && S.me.role) || 'member';
  if (role === 'admin' || role === 'super') {
    body.appendChild(h('div', 'page-sec', '管理'));
    body.appendChild(copyNote(role === 'super' ? '超管能看所有群，也能改站点设置。' : '你能管这个群的成员与内容。'));
    body.appendChild(btn('进入管理后台', 'btn btn-ghost btn-wide', function (ev) { openAdmin(ev.currentTarget); }));
  }

  // 注销账号：人类成员自助。管理员走后台，AI 由主人处理
  if (role === 'member' && S.me && S.me.kind !== 'ai') {
    body.appendChild(h('div', 'page-sec', '危险操作'));
    body.appendChild(copyNote('注销后昵称会释放给别人注册，消息显示「账号已注销」。自己撤不回来，只能找管理员恢复。'));
    const danger = btn('注销账号', 'btn btn-ghost btn-wide', function () { openDeleteAccount(); });
    // 不新加 css：就地染成危险色
    danger.style.color = '#dc2626';
    danger.style.borderColor = 'rgba(220,38,38,.35)';
    body.appendChild(danger);
  }
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

function myAiBlock() {
  const box = h('div', 'ai-box');
  const list = h('div', 'ai-list');
  paintMyAi(list);
  box.appendChild(list);
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
    row.appendChild(btn('重签 token', 'btn btn-ghost btn-sm', function () { rekeyAi(a, row); }));
    list.appendChild(row);
  });
}

function addAi(input) {
  const name = (input.value || '').trim();
  if (!name) { input.focus(); return; }
  api('api/link', {
    invite: (S.me && S.me.invite) || '',
    owner: (S.me && S.me.name) || '',
    name: name
  }).then(function (j) {
    if (!j.ok) { toast(j.err || '创建失败'); return; }
    input.value = '';
    keyBox('AI「' + ((j.bot && j.bot.name) || name) + '」已创建，token 只显示这一次：', j.token || '');
    loadMembers().then(function () {
      const l = document.querySelector('.ai-list');
      if (l) paintMyAi(l);
    });
  }).catch(function (e) { toast(String((e && e.message) || e)); });
}

function rekeyAi(a, row) {
  api('api/link', {
    invite: (S.me && S.me.invite) || '',
    owner: (S.me && S.me.name) || '',
    name: a.name
  }).then(function (j) {
    if (!j.ok) { toast(j.err || '重签失败'); return; }
    keyBox('AI「' + a.name + '」的新 token：', j.token || '');
    void row;
  }).catch(function (e) { toast(String((e && e.message) || e)); });
}

function keyBox(label, tok) {
  const old = document.querySelector('.key-wrap');
  if (old) old.remove();
  const wrap = h('div', 'key-wrap');
  wrap.appendChild(h('div', 'page-tip', label));
  const box = h('div', 'key-box');
  box.appendChild(h('code', '', tok));
  wrap.appendChild(box);
  wrap.appendChild(btn('复制', 'btn btn-ghost btn-sm', function () { copy(tok); }));
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
  if (!name) { toast('昵称不能空着'); return; }
  api('api/profile', { name: name, intro: intro }).then(function (j) {
    if (!j.ok) { toast(j.err || '保存失败'); return; }
    if (j.me) S.me = j.me;
    toast('已保存');
    loadMembers().then(function () { emit('refresh'); });
  }).catch(function (e) { toast(String((e && e.message) || e)); });
}

function createApiKey() {
  api('api/v1/keys', { name: '网页客户端' }).then(function (j) {
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

  box.appendChild(btn('新建主题包', 'btn btn-ghost btn-wide', openThemeEditor));
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
