// LightGroup 管理后台：原生 ES module，零构建。
// 布局是「固定侧栏 + 顶部条 + 内容区」，所有页面共用一套卡片/表格/空态。
const BOOT = window.__LG_BOOT__ || {};
const BASE = BOOT.base || '/g/';
const SITE = BOOT.site || {};
const CSRF = BOOT.csrf || '';
const CALLBACK = BOOT.callback || '';

const S = {
  role: '',
  me: null,
  scopes: [],
  tab: '',
  arg: '',
  loaded: {},
};

// ---------------------------------------------------------------- 基础小工具

function el(tag, props, kids) {
  const e = document.createElement(tag);
  props = props || {};
  Object.keys(props).forEach(function (k) {
    const v = props[k];
    if (v === null || v === undefined || v === false) return;
    if (k === 'class') e.className = v;
    else if (k === 'text') e.textContent = v;
    else if (k === 'html') e.innerHTML = v;
    else if (k === 'style') e.setAttribute('style', v);
    else if (k === 'value' || k === 'checked' || k === 'selected' || k === 'disabled') e[k] = v;
    else if (k === 'on') Object.keys(v).forEach(function (ev) { e.addEventListener(ev, v[ev]); });
    else e.setAttribute(k, v);
  });
  (kids || []).forEach(function (kid) {
    if (kid === null || kid === undefined || kid === false) return;
    e.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
  });
  return e;
}

function div(cls, kids) { return el('div', { class: cls }, kids); }
function icon(name, cls) { return el('i', { class: (cls ? cls + ' ' : '') + 'fa-solid ' + name }); }
function btn(label, cls, onclick, ic) {
  const b = el('button', { class: 'btn ' + (cls || ''), type: 'button', on: { click: onclick } });
  if (ic) b.append(icon(ic));
  b.append(el('span', { text: label }));
  return b;
}
function pad(n) { return n < 10 ? '0' + n : '' + n; }
function fmtTime(ms) { const d = new Date(ms || 0); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
function fmtDate(ms) {
  const d = new Date(ms || 0);
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}
function fmtDT(ms) { return fmtDate(ms) + ' ' + fmtTime(ms); }
function ago(ms) {
  if (!ms) return '—';
  const s = Math.max(1, Math.floor((Date.now() - ms) / 1000));
  if (s < 60) return s + ' 秒前';
  if (s < 3600) return Math.floor(s / 60) + ' 分钟前';
  if (s < 86400) return Math.floor(s / 3600) + ' 小时前';
  if (s < 86400 * 30) return Math.floor(s / 86400) + ' 天前';
  return fmtDate(ms);
}
function badge(role, text) {
  if (!role) return null;
  const map = { super: '超管', admin: '管理员', ai: 'AI', member: '成员' };
  return el('span', { class: 'badge ' + role, text: text || map[role] || role });
}

async function api(path, body, method) {
  const opt = { method: method || (body === undefined ? 'GET' : 'POST'), headers: {}, credentials: 'same-origin' };
  if (body !== undefined) {
    opt.headers['Content-Type'] = 'application/json';
    opt.headers['X-LG-CSRF'] = CSRF;
    opt.body = JSON.stringify(body);
  }
  const r = await fetch(BASE + 'api/admin/' + path, opt);
  let j = null;
  try { j = await r.json(); } catch (e) {
    throw new Error('服务器返回了非 JSON（HTTP ' + r.status + '），请看 PHP 错误日志');
  }
  if (!j || !j.ok) throw new Error((j && j.err) || '请求失败');
  return j;
}

function toast(msg, kind) {
  let box = document.getElementById('adm-toasts');
  if (!box) { box = div('adm-toasts'); box.id = 'adm-toasts'; document.body.append(box); }
  const t = div('adm-toast ' + (kind === 'err' ? 'err' : 'ok'), [
    icon(kind === 'err' ? 'fa-circle-exclamation' : 'fa-circle-check'),
    el('span', { text: msg }),
  ]);
  box.append(t);
  setTimeout(function () {
    t.classList.add('out');
    setTimeout(function () { t.remove(); }, 220);
  }, 2800);
}

function loading() { return div('adm-loading', [icon('fa-spinner', 'fa-spin'), el('span', { text: '正在加载…' })]); }

function modal(title, body, foot) {
  let mask = null;
  function close() { if (mask) { mask.remove(); mask = null; } document.removeEventListener('keydown', onKey); }
  function onKey(e) { if (e.key === 'Escape') close(); }
  const box = div('adm-modal', [
    div('adm-modal-head', [
      el('div', { class: 'adm-modal-title', text: title }),
      el('button', { class: 'adm-x', type: 'button', on: { click: close } }, [icon('fa-xmark')]),
    ]),
    div('adm-modal-body', body),
    foot ? div('adm-modal-foot', foot) : null,
  ]);
  mask = div('adm-mask', [box]);
  // 点遮罩不关：表单里手一抖就丢内容，只认右上角 × 和 ESC
  document.addEventListener('keydown', onKey);
  document.body.append(mask);
  return { close: close, box: box };
}

function confirmBox(title, text, danger) {
  return new Promise(function (resolve) {
    const m = modal(title, [el('p', { class: 'adm-confirm-text', text: text })], [
      btn('取消', 'btn-ghost', function () { m.close(); resolve(false); }),
      btn('确定', danger ? 'btn-danger' : 'btn-primary', function () { m.close(); resolve(true); }),
    ]);
  });
}

function pageHead(title, desc, actions) {
  return div('page-head', [
    div('page-head-l', [
      el('h1', { class: 'page-title', text: title }),
      desc ? el('p', { class: 'page-desc', text: desc }) : null,
    ]),
    div('page-actions', actions || []),
  ]);
}

function card(title, kids, opts) {
  opts = opts || {};
  const head = div('card-head', [
    div('card-title', [opts.icon ? icon(opts.icon) : null, el('span', { text: title })]),
    opts.headRight || null,
  ]);
  const body = div('card-body' + (opts.flush ? ' flush' : ''), kids);
  return div('card', [head, body]);
}

function table(head, rows, emptyText, emptyIcon) {
  const t = el('table', { class: 'adm-table' });
  t.append(el('thead', {}, [
    el('tr', {}, head.map(function (h) { return el('th', {}, [h]); })),
  ]));
  const tb = el('tbody');
  if (!rows.length) {
    tb.append(el('tr', {}, [
      el('td', { colspan: String(head.length) }, [
        div('adm-empty', [icon(emptyIcon || 'fa-inbox'), el('div', { text: emptyText || '暂无数据' })]),
      ]),
    ]));
  } else {
    rows.forEach(function (r) { tb.append(el('tr', {}, r)); });
  }
  t.append(tb);
  return t;
}

function field(label, input, hint) {
  return el('label', { class: 'field' }, [
    el('span', { class: 'field-label', text: label }),
    input,
    hint ? el('span', { class: 'field-hint', text: hint }) : null,
  ]);
}

function textInput(name, value, ph, type) {
  return el('input', {
    class: 'adm-input',
    name: name,
    type: type || 'text',
    value: value === undefined || value === null ? '' : value,
    placeholder: ph || '',
  });
}

function selectInput(name, options, value) {
  const s = el('select', { class: 'adm-input', name: name });
  options.forEach(function (o) {
    s.append(el('option', { value: o.value, text: o.label, selected: String(o.value) === String(value) }));
  });
  return s;
}

function formValue(form, name) {
  const e = form.elements[name];
  if (!e) return '';
  return e.value.trim();
}

// ---------------------------------------------------------------- 侧栏壳

/** 侧栏左上角的品牌位：有站点图标就用站点图标，没有就用和 favicon 一致的双圆 */
function brandMark() {
  const box = div('adm-brand-mark');
  const light = String(SITE.icon || SITE.icon_dark || '');
  if (!light) {
    box.innerHTML = '<svg viewBox="0 0 64 64" width="62%" height="62%" fill="none" stroke="currentColor"'
      + ' stroke-width="4.6" stroke-linecap="round" aria-hidden="true">'
      + '<circle cx="32" cy="32" r="15"/><circle cx="32" cy="32" r="6"/></svg>';
    return box;
  }
  const img = document.createElement('img');
  img.src = light;
  img.alt = '';
  box.appendChild(img);
  return box;
}

const NAV = [
  { group: '概览', items: [ { id: 'dashboard', icon: 'fa-gauge-high', text: '仪表盘' } ] },
  { group: '群组', items: [
    { id: 'scopes', icon: 'fa-layer-group', text: '群组管理' },
    { id: 'permgroups', icon: 'fa-user-lock', text: '权限组' },
    { id: 'clusters', icon: 'fa-circle-nodes', text: '互通圈子', superOnly: true },
  ] },
  { group: '成员', items: [ { id: 'members', icon: 'fa-users', text: '群成员' } ] },
  { group: '认证', items: [
    { id: 'accounts', icon: 'fa-user-shield', text: '管理员账号', superOnly: true },
    { id: 'oauth', icon: 'fa-right-to-bracket', text: 'OAuth 登录', superOnly: true },
    { id: 'captcha', icon: 'fa-shield-halved', text: '人机验证', superOnly: true },
  ] },
  { group: '内容', items: [ { id: 'moments', icon: 'fa-compass', text: '空间' } ] },
  { group: '主题', items: [ { id: 'themes', icon: 'fa-palette', text: '主题包' } ] },
  { group: '审计', items: [ { id: 'convs', icon: 'fa-magnifying-glass-chart', text: '会话审计' } ] },
  { group: '设置', items: [
    { id: 'settings', icon: 'fa-sliders', text: '站点设置', superOnly: true },
    { id: 'mail', icon: 'fa-envelope-open-text', text: '邮件通知', superOnly: true },
    { id: 'update', icon: 'fa-cloud-arrow-down', text: '更新检查' },
  ] },
];

const TITLES = {
  dashboard: '仪表盘',
  scopes: '群组管理',
  permgroups: '权限组',
  clusters: '互通圈子',
  members: '群成员',
  accounts: '管理员账号',
  oauth: 'OAuth 登录',
  captcha: '人机验证',
  moments: '空间',
  themes: '主题包',
  convs: '会话审计',
  settings: '站点设置',
  mail: '邮件通知',
  update: '更新检查',
};

function visibleNav() {
  return NAV.map(function (g) {
    const items = g.items.filter(function (it) { return !it.superOnly || S.role === 'super'; });
    return { group: g.group, items: items };
  }).filter(function (g) { return g.items.length > 0; });
}

function buildShell() {
  const adm = document.getElementById('adm');
  adm.innerHTML = '';
  adm.classList.add('adm-ready');

  const nav = el('nav', { class: 'adm-nav' });
  visibleNav().forEach(function (g) {
    nav.append(div('adm-nav-group', [
      div('adm-nav-label', [document.createTextNode(g.group)]),
      ...g.items.map(function (it) {
        return el('a', {
          class: 'adm-nav-item',
          href: '#' + it.id,
          'data-tab': it.id,
          on: { click: function (e) { e.preventDefault(); go(it.id); } },
        }, [icon(it.icon), el('span', { class: 'adm-nav-text', text: it.text })]);
      }),
    ]));
  });

  const side = el('aside', { class: 'adm-side', id: 'adm-side' }, [
    div('adm-brand', [
      brandMark(),
      div('adm-brand-txt', [
        el('div', { class: 'adm-brand-name', text: SITE.name || 'LightGroup' }),
        el('div', { class: 'adm-brand-sub', text: '管理后台' }),
      ]),
    ]),
    nav,
    div('adm-side-foot', [
      el('div', { class: 'adm-me' }, [
        div('adm-me-ava', [icon('fa-user')]),
        div('adm-me-txt', [
          el('div', { class: 'adm-me-name', text: (S.me && S.me.name) || '管理员' }),
          el('div', { class: 'adm-me-role', text: S.role === 'super' ? '超级管理员' : '管理员' }),
        ]),
      ]),
      div('adm-side-links', [
        el('a', { class: 'adm-side-link', href: '/app' }, [icon('fa-comments'), el('span', { text: '回到群聊' })]),
        el('a', { class: 'adm-side-link', href: '/logout' }, [icon('fa-right-from-bracket'), el('span', { text: '退出登录' })]),
      ]),
    ]),
  ]);

  const burger = el('button', {
    class: 'adm-burger', type: 'button', 'aria-label': '打开菜单',
    on: { click: function () { side.classList.toggle('open'); } },
  }, [icon('fa-bars')]);

  const crumb = el('div', { class: 'adm-crumb' }, [
    el('span', { class: 'adm-crumb-root', text: '管理后台' }),
    icon('fa-chevron-right', 'adm-crumb-sep'),
    el('b', { text: TITLES[S.tab] || '仪表盘' }),
  ]);
  crumb.id = 'adm-crumb';

  const top = div('adm-top', [
    burger,
    crumb,
    div('adm-top-right', [
      el('span', { class: 'tag' }, [icon('fa-shield-halved'), document.createTextNode(S.role === 'super' ? '超级管理员' : '管理员')]),
    ]),
  ]);

  const body = div('adm-body', []);
  body.id = 'adm-body';

  adm.append(div('adm-shell', [side, div('adm-main', [top, body])]));
}

function setCrumb(tab, arg) {
  const c = document.getElementById('adm-crumb');
  if (!c) return;
  c.innerHTML = '';
  c.append(el('span', { class: 'adm-crumb-root', text: '管理后台' }));
  c.append(icon('fa-chevron-right', 'adm-crumb-sep'));
  const first = el('b', { text: TITLES[tab] || tab });
  if (arg) {
    first.style.cursor = 'pointer';
    first.addEventListener('click', function () { go(tab); });
  }
  c.append(first);
  if (arg) {
    c.append(icon('fa-chevron-right', 'adm-crumb-sep'));
    c.append(el('b', { text: '详情' }));
  }
}

function splitPath(p) {
  const i = p.indexOf('/');
  return i < 0 ? { tab: p, arg: '' } : { tab: p.slice(0, i), arg: p.slice(i + 1) };
}

const RENDERERS = {
  dashboard: renderDashboard,
  scopes: renderScopes,
  permgroups: renderPermGroups,
  clusters: renderClusters,
  members: renderMembers,
  accounts: renderAccounts,
  oauth: renderOAuth,
  captcha: renderCaptcha,
  moments: renderMoments,
  themes: renderThemes,
  convs: renderConvs,
  settings: renderSettings,
  mail: renderMail,
  update: renderUpdate,
};

async function go(tab, arg) {
  if (!TITLES[tab]) { tab = 'dashboard'; arg = ''; }
  const argStr = arg === undefined || arg === null ? '' : String(arg);
  S.tab = tab;
  S.arg = argStr;
  const path = tab + (argStr ? '/' + argStr : '');
  document.querySelectorAll('.adm-nav-item').forEach(function (a) {
    a.classList.toggle('on', a.getAttribute('data-tab') === tab);
  });
  setCrumb(tab, argStr);
  if (location.hash !== '#' + path) history.replaceState(null, '', '#' + path);
  const body = document.getElementById('adm-body');
  if (!body) return;
  body.innerHTML = '';
  body.scrollTop = 0;
  const wrap = div('adm-wrap', []);
  body.append(wrap);
  try {
    await RENDERERS[tab](wrap, argStr);
  } catch (e) {
    wrap.innerHTML = '';
    wrap.append(div('card', [div('card-body', [
      div('adm-error', [icon('fa-triangle-exclamation'), el('div', { text: e.message || '加载失败' })]),
      btn('重试', 'btn-ghost', function () { go(tab, argStr); }, 'fa-rotate'),
    ])]));
  }
}

// ---------------------------------------------------------------- 登录

function renderLogin(msg) {
  const adm = document.getElementById('adm');
  adm.innerHTML = '';
  adm.classList.add('adm-ready');
  const gateMsg = msg && msg.indexOf('门禁') >= 0;
  const err = div('login-err', msg && msg !== '请先登录' ? [
    el('span', { text: msg }),
    gateMsg ? el('a', { class: 'login-back', href: '/oauth/start' }, [icon('fa-right-to-bracket'), el('span', { text: '用第三方账号登录' })]) : null,
  ] : []);
  const name = textInput('name', '', '管理员账号');
  const pass = textInput('password', '', '密码', 'password');
  const form = el('form', { class: 'adm-login-form' }, [
    div('login-logo', [icon('fa-bolt')]),
    el('h1', { class: 'login-title', text: (SITE.name || 'LightGroup') + ' 管理后台' }),
    el('p', { class: 'login-sub', text: '用管理员账号登录，普通成员请走 /login' }),
    field('账号', name),
    field('密码', pass),
    err,
    el('button', { class: 'btn btn-primary login-btn', type: 'submit' }, [icon('fa-right-to-bracket'), el('span', { text: '登录' })]),
    el('a', { class: 'login-back', href: '/' }, [icon('fa-arrow-left'), el('span', { text: '返回站点' })]),
  ]);
  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    err.innerHTML = '';
    const b = form.querySelector('.login-btn');
    b.disabled = true;
    try {
      const r = await fetch(BASE + 'api/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ name: formValue(form, 'name'), password: formValue(form, 'password') }),
      });
      const j = await r.json();
      if (!j.ok) throw new Error(j.err || '登录失败');
      location.reload();
    } catch (ex) {
      err.append(el('span', { text: ex.message || '登录失败' }));
      b.disabled = false;
    }
  });
  adm.append(div('adm-login', [form, el('div', { class: 'adm-login-foot', text: (SITE.intro || '') })]));
}

// ---------------------------------------------------------------- 仪表盘

function metric(c) {
  return div('metric', [
    div('metric-ico ' + (c.tone || 'blue'), [icon(c.icon || 'fa-chart-simple')]),
    div('metric-main', [
      el('div', { class: 'metric-val', text: String(c.value) }),
      el('div', { class: 'metric-label', text: c.label }),
      el('div', { class: 'metric-sub', text: c.sub || '' }),
    ]),
  ]);
}

function trendChart(trend) {
  const max = Math.max.apply(null, [1].concat(trend.map(function (x) { return x.count; })));
  const chart = div('chart', []);
  trend.forEach(function (x) {
    const hpx = x.count > 0 ? Math.max(10, Math.round((x.count / max) * 130)) : 4;
    chart.append(div('chart-col', [
      el('div', { class: 'chart-val', text: x.count ? String(x.count) : '' }),
      el('div', {
        class: 'chart-bar' + (x.count ? '' : ' zero'),
        style: 'height:' + hpx + 'px',
        title: x.date + ' · ' + x.weekday + ' · ' + x.count + ' 条',
      }),
      el('div', { class: 'chart-label', text: x.label }),
      el('div', { class: 'chart-sub', text: x.weekday }),
    ]));
  });
  return chart;
}

function eventFeed(events) {
  if (!events.length) return div('adm-empty', [icon('fa-clipboard-list'), el('div', { text: '还没有审计事件' })]);
  const list = div('feed', []);
  events.forEach(function (e) {
    list.append(div('feed-item', [
      div('feed-ico ' + (e.kind === 'audit' ? 'audit' : 'admin'), [icon(e.kind === 'audit' ? 'fa-eye' : 'fa-user-shield')]),
      div('feed-main', [
        div('feed-title', [
          el('b', { text: e.who || '系统' }),
          el('span', { class: 'feed-action', text: e.action }),
        ]),
        div('feed-detail', [document.createTextNode(e.detail || '')]),
      ]),
      div('feed-time', [el('span', { text: ago(e.at) })]),
    ]));
  });
  return list;
}

async function renderDashboard(box) {
  box.append(loading());
  const d = await api('dashboard');
  box.innerHTML = '';
  box.append(pageHead('仪表盘', '站点与群组的实时数据；近 7 天按 Asia/Shanghai 的 0 点切天，不含系统消息'));
  const grid = div('metric-grid', []);
  (d.cards || []).forEach(function (c) { grid.append(metric(c)); });
  box.append(grid);

  const total = (d.trend || []).reduce(function (a, x) { return a + x.count; }, 0);
  // 一条消息都没有时别画一排空柱子，给个明确的空态
  const trendBody = total > 0 || (d.trend || []).length === 0
    ? [trendChart(d.trend || [])]
    : [div('adm-empty', [icon('fa-chart-simple'), el('div', { text: '最近 7 天还没有消息' })])];
  box.append(card('最近 7 天消息趋势', trendBody, {
    icon: 'fa-chart-column',
    headRight: el('span', { class: 'card-sub', text: '合计 ' + total + ' 条' }),
  }));

  const recentRows = (d.recent_members || []).map(function (m) {
    return [
      el('td', {}, [div('cell-name', [el('b', { text: m.name }), m.badge ? badge(m.badge) : null])]),
      el('td', {}, [m.kind === 'ai' ? badge('ai') : el('span', { class: 'muted', text: '人类' })]),
      el('td', { class: 'muted', text: m.scope || '—' }),
      el('td', { class: 'muted', text: fmtDT(m.created_at) }),
    ];
  });

  box.append(div('grid-2', [
    card('最近注册的成员', [table(['成员', '身份', '所属群', '注册时间'], recentRows, '还没有成员', 'fa-users')], {
      icon: 'fa-user-plus', flush: true,
      headRight: el('a', { class: 'card-link', href: '#members', on: { click: function (e) { e.preventDefault(); go('members'); } } }, [document.createTextNode('全部成员'), icon('fa-arrow-right')]),
    }),
    card('最近审计事件', [eventFeed(d.events || [])], { icon: 'fa-clipboard-list', flush: true }),
  ]));
}

// ---------------------------------------------------------------- 群组

async function renderScopes(box) {
  box.append(loading());
  const d = await api('scopes');
  S.scopes = d.scopes || [];
  box.innerHTML = '';
  box.append(pageHead('群组管理', '每个群是一个作用域；邀请码与配额决定谁能进来', [
    S.role === 'super' ? btn('新建群组', 'btn-primary', newScopeDialog, 'fa-plus') : null,
  ]));

  const rows = S.scopes.map(function (s) {
    const acts = [];
    acts.push(btn('编辑', 'btn-ghost btn-sm', function () { editScopeDialog(s); }, 'fa-pen'));
    if (S.role === 'super') acts.push(btn('邀请码', 'btn-ghost btn-sm', function () { inviteDialog(s); }, 'fa-ticket'));
    if (!s.archived) acts.push(btn('归档', 'btn-ghost btn-sm danger', function () { archiveScope(s); }, 'fa-box-archive'));
    return [
      el('td', {}, [div('cell-name', [
        el('b', { text: s.title || '(未命名)' }),
        s.slug ? el('span', { class: 'mono muted', text: '/g/' + s.slug }) : el('span', { class: 'badge muted', text: '主群' }),
        s.archived ? el('span', { class: 'badge muted', text: '已归档' }) : null,
      ])]),
      el('td', { class: 'muted', text: s.intro || '—' }),
      el('td', {}, [el('span', { class: 'num', text: String(s.humans) }), el('span', { class: 'muted', text: ' 人 · ' }), el('span', { class: 'num', text: String(s.ais) }), el('span', { class: 'muted', text: ' AI' })]),
      el('td', { class: 'num', text: String(s.messages) }),
      el('td', {}, [s.invite ? el('span', { class: 'mono', text: s.invite }) : el('span', { class: 'muted', text: '仅超管可见' })]),
      el('td', {}, [div('row-acts', acts)]),
    ];
  });

  box.append(card('全部群组', [table(['群组', '简介', '成员', '消息', '邀请码', '操作'], rows, '还没有群组', 'fa-layer-group')], {
    icon: 'fa-layer-group', flush: true,
  }));

  box.append(card('说明', [div('hint', [
    el('p', { text: '主群是安装时创建的默认群，地址就是 /g/。其它群都有自己的路径，地址形如 /g/群路径/，建群时留空会自动生成。' }),
    el('p', { text: '归档后群从列表里隐藏，数据保留；成员的登录与消息不会丢。' }),
  ])], { icon: 'fa-circle-info' }));
}

function newScopeDialog() {
  const f = el('form', { class: 'adm-form' }, [
    div('form-grid', [
      field('群名', textInput('title', '', '例如：夜航船')),
      field('群路径（可留空）', textInput('slug', '', '例如：night'),
        '群路径就是网址里的那一段：填 night，群地址就是 /g/night/。留空会自动生成。'),
      field('邀请码（可留空）', textInput('invite', '', '4-32 位字母数字'),
        '成员进这个群要用的码。留空会自动生成，建完能在列表里看到，也可以随时改。'),
      div('span2', [field('简介', textInput('intro', '', '一句话介绍'))]),
      field('人类上限', textInput('human_cap', '0', '0 表示不限', 'number')),
      field('AI 上限', textInput('ai_cap', '0', '0 表示不限', 'number')),
    ]),
  ]);
  const m = modal('新建群组', [f], [
    btn('取消', 'btn-ghost', function () { m.close(); }),
    btn('创建', 'btn-primary', async function () {
      try {
        await api('scopes', {
          title: formValue(f, 'title'),
          slug: formValue(f, 'slug'),
          invite: formValue(f, 'invite'),
          intro: formValue(f, 'intro'),
          human_cap: formValue(f, 'human_cap') || '0',
          ai_cap: formValue(f, 'ai_cap') || '0',
        });
        m.close(); toast('群组已创建'); go('scopes');
      } catch (e) { toast(e.message, 'err'); }
    }, 'fa-check'),
  ]);
}

function editScopeDialog(s) {
  const fPgCreate = el('input', { type: 'checkbox', checked: s.pg_create !== false });
  const fPerm = selectInput('perm_group_id', [{ value: '0', label: '跟随平台默认组' }], s.perm_group_id || 0);
  const f = el('form', { class: 'adm-form' }, [
    div('form-grid', [
      field('群名', textInput('title', s.title)),
      field('简介', textInput('intro', s.intro || '')),
      field('人类上限', textInput('human_cap', String(s.human_cap), '0 不限', 'number')),
      field('AI 上限', textInput('ai_cap', String(s.ai_cap), '0 不限', 'number')),
      field('每人私群数', textInput('pg_member_cap', String(s.pg_member_cap || 0), '0 跟站点', 'number')),
      field('单群人数上限', textInput('pg_size_cap', String(s.pg_size_cap || 0), '0 跟站点', 'number')),
      field('撤回策略', selectInput('recall_policy', [
        { value: '5m', label: '5 分钟内可撤回' },
        { value: 'until_other', label: '对方未读可撤回' },
        { value: 'forever', label: '始终可撤回' },
      ], s.recall_policy)),
      div('span2', [field('权限组', fPerm, '权限组决定这个群能开哪些群功能；留「跟随平台默认组」就用平台那套')]),
      div('span2', [el('label', { class: 'switch' }, [fPgCreate, el('span', { text: '允许成员在这个群创建私群' })])]),
    ]),
  ]);
  const fMsgMode = selectInput('msg_mode', [
    { value: '', label: '跟随站点总配置' },
    { value: 'server', label: '由站点下发' },
    { value: 'keep', label: '消息保留' },
  ], String(s.msg_mode ?? ''));
  const fMsgLocal = selectInput('msg_local', [
    { value: '', label: '跟随站点总配置' },
    { value: '1', label: '允许本机保存' },
    { value: '0', label: '禁止本机保存' },
  ], String(s.msg_local ?? ''));
  const fMsgPage = textInput('msg_page_size', String(s.msg_page_size ?? ''), '留空跟随站点总配置', 'number');
  f.append(div('form-grid', [
    field('消息存储', fMsgMode, '由站点下发=按页拉、可懒加载；消息保留=只发新增、存在成员设备上'),
    field('本机保存', fMsgLocal, '成员把消息缓存在自己设备上；留空跟随站点'),
    field('每次加载条数', fMsgPage, '1-100；成员点「加载更多」之前只加载这么多；留空跟随站点'),
  ]));
  const m = modal('编辑群组 · ' + (s.title || ''), [f], [
    btn('取消', 'btn-ghost', function () { m.close(); }),
    btn('保存', 'btn-primary', async function () {
      try {
        await api('scopes/' + s.id, {
          title: formValue(f, 'title'),
          intro: formValue(f, 'intro'),
          human_cap: formValue(f, 'human_cap') || '0',
          ai_cap: formValue(f, 'ai_cap') || '0',
          pg_member_cap: formValue(f, 'pg_member_cap') || '0',
          pg_size_cap: formValue(f, 'pg_size_cap') || '0',
          recall_policy: formValue(f, 'recall_policy'),
          pg_create: fPgCreate.checked ? 1 : 0,
          perm_group_id: formValue(f, 'perm_group_id') || '0',
          msg_mode: fMsgMode.value,
          msg_page_size: fMsgPage.value.trim(),
          msg_local: fMsgLocal.value,
        });
        m.close(); toast('群组已保存'); go('scopes');
      } catch (e) { toast(e.message, 'err'); }
    }, 'fa-check'),
  ]);
  // 本群能套的权限组：异步补进下拉；读不到就只留「跟随平台默认组」
  api('permgroups?scope=' + s.id).then(function (d) {
    ((d && d.groups) || []).forEach(function (g) {
      if (g.is_default) return;
      fPerm.append(el('option', { value: String(g.id), text: g.name, selected: String(g.id) === String(s.perm_group_id || 0) }));
    });
  }).catch(function () {});
}
function inviteDialog(s) {
  const code = el('input', { class: 'adm-input mono', value: s.invite || '', readonly: true });
  const m = modal('邀请码 · ' + (s.title || ''), [
    field('当前邀请码', code, '成员注册时要填这个；重置后旧邀请码立即失效'),
  ], [
    btn('复制', 'btn-ghost', function () { copyText(code.value); }, 'fa-copy'),
    btn('重置邀请码', 'btn-danger', async function () {
      const ok = await confirmBox('重置邀请码', '旧邀请码会立即失效，已加入的成员不受影响。继续？', true);
      if (!ok) return;
      try {
        const r = await api('scopes/' + s.id + '/invite', {});
        code.value = r.invite || '';
        toast('邀请码已重置');
      } catch (e) { toast(e.message, 'err'); }
    }, 'fa-rotate'),
    btn('关闭', 'btn-ghost', function () { m.close(); }),
  ]);
}

async function archiveScope(s) {
  const ok = await confirmBox('归档群组', '归档后「' + (s.title || '') + '」会从列表隐藏，数据保留。确定？', true);
  if (!ok) return;
  try { await api('scopes/' + s.id + '/archive', { on: 1 }); toast('已归档'); go('scopes'); }
  catch (e) { toast(e.message, 'err'); }
}

// ---------------------------------------------------------------- 权限组

/**
 * 权限组：一套群功能开关（群公告、邀请、设管理员、改群名、群待办、置顶、成员列表、群二维码）。
 * 群和私群都能单独套组；私群不套就跟主群。和站点设置里的「AI 与适配器 / 空间 / 社交」无关。
 */
async function renderPermGroups(box) {
  box.append(loading());
  if (!S.scopes.length) {
    const dd = await api('scopes').catch(function () { return null; });
    S.scopes = (dd && dd.scopes) || [];
  }
  const cur = S.permScope || (S.scopes[0] && S.scopes[0].id) || 0;
  const scopeSel = S.role === 'super' ? selectInput('scope', S.scopes.map(function (s) {
    return { value: s.id, label: s.title || ('群 #' + s.id) };
  }), cur) : null;

  box.innerHTML = '';
  box.append(pageHead('权限组', '一套群功能开关。群可以单独套组，私群也能单独套；不套就跟着平台默认组。', [
    scopeSel ? el('span', { class: 'field-inline' }, [el('span', { class: 'field-hint', text: '选择群组' }), scopeSel]) : null,
  ]));

  const holder = div('adm-perm-groups');
  box.append(holder);

  function scopeRowOf(id) {
    for (let i = 0; i < S.scopes.length; i++) {
      if (String(S.scopes[i].id) === String(id)) return S.scopes[i];
    }
    return null;
  }

  async function paint(scopeId) {
    S.permScope = scopeId;
    holder.innerHTML = '';
    holder.append(loading());
    let d;
    try { d = await api('permgroups' + (scopeId ? '?scope=' + scopeId : '')); }
    catch (e) {
      holder.innerHTML = '';
      holder.append(card('读取失败', [div('adm-error', [icon('fa-circle-exclamation'), el('div', { text: e.message })])], { icon: 'fa-user-lock' }));
      return;
    }
    holder.innerHTML = '';
    const meta = d.meta || [];
    const groups = d.groups || [];
    const eff = d.effective || {};
    const row = scopeRowOf(scopeId);
    const title = (row && row.title) || ('群 #' + scopeId);
    // 以服务端返回的 assigned 为准（本地 S.scopes 是首屏快照，套组后不会自己变）
    const myGroup = typeof d.assigned === 'number' ? d.assigned : ((row && row.perm_group_id) || 0);
    if (row) row.perm_group_id = myGroup;

    const effRows = meta.map(function (m) {
      const on = !!eff[m.key];
      return [
        el('td', {}, [el('b', { text: m.label }), el('span', { class: 'mono muted', text: '  ' + m.key })]),
        el('td', {}, [el('span', { class: 'badge ' + (on ? 'ok' : 'muted'), text: on ? '开启' : '关闭' })]),
      ];
    });
    holder.append(card('生效结果 · ' + title, [
      table(['群功能', '状态'], effRows, '没有可配置的群功能', 'fa-toggle-on'),
      div('card-foot', [div('hint', [el('p', { text: '平台默认组先铺底，这个群套的组再盖一层。组里只存「改过的键」，没列出来的跟着默认走。' })])]),
    ], { icon: 'fa-toggle-on', flush: true }));

    const groupRows = groups.map(function (g) {
      const acts = [];
      if (g.editable) {
        acts.push(btn('编辑', 'btn-ghost btn-sm', function () { permGroupDialog(g, meta, scopeId, function () { paint(scopeId); }); }, 'fa-pen'));
      }
      if (!g.is_default) {
        if (String(g.id) !== String(myGroup)) {
          acts.push(btn('套用到本群', 'btn-ghost btn-sm', function () { assignPermGroup(g.id, scopeId, function () { paint(scopeId); }); }, 'fa-link'));
        }
        if (g.editable) {
          acts.push(btn('删除', 'btn-ghost btn-sm danger', function () { removePermGroup(g, function () { paint(scopeId); }); }, 'fa-trash-can'));
        }
      }
      const n = Object.keys(g.perms || {}).length;
      return [
        el('td', {}, [div('cell-name', [
          el('b', { text: g.name }),
          g.is_default ? el('span', { class: 'badge muted', text: '平台默认' }) : el('span', { class: 'badge muted', text: '本群' }),
          String(g.id) === String(myGroup) ? el('span', { class: 'badge ok', text: '正在使用' }) : null,
        ])]),
        el('td', { class: 'muted', text: n ? '改了 ' + n + ' 项' : '全部跟随默认' }),
        el('td', {}, [div('row-acts', acts)]),
      ];
    });
    holder.append(card('权限组', [
      table(['名称', '覆盖项', '操作'], groupRows, '还没有自定义权限组', 'fa-user-lock'),
    ], { icon: 'fa-user-lock', flush: true }));

    const footActs = [];
    if (myGroup) {
      footActs.push(btn('回到平台默认组', 'btn-ghost', function () { assignPermGroup(0, scopeId, function () { paint(scopeId); }); }, 'fa-rotate-left'));
    }
    footActs.push(btn('新建权限组', 'btn-ghost', function () { permGroupDialog(null, meta, scopeId, function () { paint(scopeId); }); }, 'fa-plus'));
    holder.append(card('说明', [
      div('hint', [
        el('p', { text: '权限组只管群里的设施：群公告、邀请成员、设管理员、改群名、群待办、置顶、成员列表、群二维码。' }),
        el('p', { text: '普通管理员只能动本群的组；平台默认组只有超级管理员能改。' }),
        el('p', { text: '注册在「账号与注册」；AI 账号、适配器、MCP 连接方式在「AI 与适配器」；空间与社交各自成卡。' }),
      ]),
      div('form-foot', footActs),
    ], { icon: 'fa-circle-info' }));
  }

  if (scopeSel) {
    scopeSel.addEventListener('change', function () { paint(scopeSel.value).catch(function (e) { toast(e.message, 'err'); }); });
  }
  await paint(cur);
}

/** 新建/编辑一个权限组；meta 是 Perm::meta() 的 8 项定义 */
function permGroupDialog(g, meta, scopeId, done) {
  const fName = textInput('name', g ? g.name : '', '例如：只读群');
  const boxes = {};
  const list = div('adm-perm-keys');
  (meta || []).forEach(function (m) {
    const key = m.key;
    // 组里明确设过就用它，否则显示默认值；保存时提交全部 8 项，后端只落与默认不同的
    const has = !!(g && g.perms && Object.prototype.hasOwnProperty.call(g.perms, key));
    const on = has ? !!g.perms[key] : !!m.default;
    const cb = el('input', { type: 'checkbox', checked: on });
    boxes[key] = cb;
    list.append(el('label', { class: 'switch' }, [cb, el('span', { text: m.label })]));
  });
  const f = el('form', { class: 'adm-form' }, [
    div('form-grid', [div('span2', [field('名字', fName, '只给管理员看，成员看不到')])]),
    list,
    div('hint', [el('p', { text: '勾上表示这个群开启该功能；没勾的项不写进组，等于跟着平台默认走。' })]),
  ]);
  const m = modal(g ? '编辑权限组 · ' + g.name : '新建权限组', [f], [
    btn('取消', 'btn-ghost', function () { m.close(); }),
    btn('保存', 'btn-primary', async function () {
      const perms = {};
      Object.keys(boxes).forEach(function (k) { perms[k] = boxes[k].checked; });
      try {
        await api('permgroups' + (g ? '/' + g.id : ''), {
          name: formValue(f, 'name'),
          perms: perms,
          scope_id: scopeId,
        });
        m.close(); toast('权限组已保存'); if (done) done();
      } catch (e) { toast(e.message, 'err'); }
    }, 'fa-check'),
  ]);
}

/** 把某个组套到这个群；gid=0 表示退回平台默认组 */
async function assignPermGroup(gid, scopeId, done) {
  try {
    await api('permgroups/0/assign', { scope: scopeId, group: gid });
    toast(gid ? '已套用到这个群' : '已回到平台默认组');
    if (done) done();
  } catch (e) { toast(e.message, 'err'); }
}

async function removePermGroup(g, done) {
  const ok = await confirmBox('删除权限组', '删除「' + g.name + '」？正在用它的群会退回平台默认组。', true);
  if (!ok) return;
  try {
    await api('permgroups/' + g.id, undefined, 'DELETE');
    toast('已删除');
    if (done) done();
  } catch (e) { toast(e.message, 'err'); }
}
function copyText(text) {
  const done = function () { toast('已复制到剪贴板'); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
  } else { fallbackCopy(text); done(); }
}
function fallbackCopy(text) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.append(ta);
  ta.select();
  try { document.execCommand('copy'); } catch (e) {}
  ta.remove();
}

// ---------------------------------------------------------------- 成员

async function renderMembers(box) {
  box.append(loading());
  const d = await api('members');
  box.innerHTML = '';
  const scopeSel = S.role === 'super' ? selectInput('scope', S.scopes.map(function (s) {
    return { value: s.id, label: s.title || ('群 #' + s.id) };
  }), d.scope) : null;

  async function load(scopeId) {
    const dd = await api('members' + (scopeId ? '?scope=' + scopeId : ''));
    renderTable(dd.members || []);
  }

  const holder = div('adm-table-holder');
  box.append(pageHead('群成员', '封禁后无法登录与发言；注销保留历史消息但账号不可用', [
    scopeSel ? el('span', { class: 'field-inline' }, [el('span', { class: 'field-hint', text: '选择群组' }), scopeSel]) : null,
  ]));

  function renderTable(members) {
    holder.innerHTML = '';
    const rows = members.map(function (m) {
      const acts = [];
      if (m.badge !== 'super') {
        acts.push(btn(m.banned ? '解封' : '封禁', 'btn-ghost btn-sm', function () { memberAct(m, 'ban', !m.banned); }, m.banned ? 'fa-unlock' : 'fa-ban'));
        if (!m.deleted) acts.push(btn('注销', 'btn-ghost btn-sm danger', function () { memberAct(m, 'delete'); }, 'fa-user-slash'));
        else {
          acts.push(btn('恢复', 'btn-ghost btn-sm', function () { memberAct(m, 'restore'); }, 'fa-user-check'));
          acts.push(btn('彻底删除', 'btn-ghost btn-sm danger', function () { memberAct(m, 'purge'); }, 'fa-trash-can'));
        }
      }
      return [
        el('td', {}, [div('cell-name', [
          el('b', { text: m.name }),
          m.badge ? badge(m.badge) : null,
          m.kind === 'ai' ? badge('ai') : null,
          m.deleted ? el('span', { class: 'badge muted', text: '已注销' }) : null,
          m.banned ? el('span', { class: 'badge danger', text: '已封禁' }) : null,
        ])]),
        el('td', {}, [m.kind === 'ai' ? el('span', { class: 'muted', text: m.owner ? '归属 ' + m.owner : 'AI 成员' }) : el('span', { class: 'muted', text: '人类' })]),
        el('td', { class: 'muted', text: m.last_seen ? ago(m.last_seen) : '从未上线' }),
        el('td', { class: 'muted', text: fmtDate(m.created_at) }),
        el('td', {}, [div('row-acts', acts)]),
      ];
    });
    holder.append(card('成员列表', [table(['成员', '类型', '最近活跃', '加入时间', '操作'], rows, '这个群还没有成员', 'fa-users')], { icon: 'fa-users', flush: true }));
  }

  if (scopeSel) {
    scopeSel.addEventListener('change', function () { load(scopeSel.value).catch(function (e) { toast(e.message, 'err'); }); });
  }
  box.append(holder);
  await load(S.role === 'super' ? '' : 0);
}

async function memberAct(m, sub, on) {
  if (sub === 'purge') {
    // 不可恢复的操作：输入昵称才算确认，避免误点
    const ok = await purgeMember(m);
    if (!ok) return;
  } else if (sub !== 'restore' && sub !== 'ban') {
    const ok = await confirmBox('注销成员', '注销「' + m.name + '」后该账号无法登录，历史消息保留。继续？', true);
    if (!ok) return;
  }
  try {
    await api('members/' + m.id + '/' + sub, sub === 'ban' ? { on: on ? 1 : 0 } : {});
    toast('操作成功');
    go('members');
  } catch (e) { toast(e.message, 'err'); }
}

/** 彻底删除的二次确认：要把昵称原样敲一遍才放行 */
function purgeMember(m) {
  return new Promise(function (resolve) {
    const inp = el('input', { class: 'adm-input', placeholder: m.name });
    const m2 = modal('彻底删除成员', [
      el('p', { class: 'adm-confirm-text', text: '「' + m.name + '」的消息会一起删掉，不可恢复。' }),
      el('p', { class: 'adm-confirm-text muted', text: '只处理已注销的成员；确认请输入昵称。' }),
      inp,
    ], [
      btn('取消', 'btn-ghost', function () { m2.close(); resolve(false); }),
      btn('彻底删除', 'btn-danger', function () {
        if (inp.value.trim() !== m.name) { toast('昵称不一致，没有删除', 'err'); return; }
        m2.close();
        resolve(true);
      }),
    ]);
    inp.focus();
  });
}

// ---------------------------------------------------------------- 管理员账号

async function renderAccounts(box) {
  box.append(loading());
  const d = await api('accounts');
  S.scopes = (d.scopes && d.scopes.length ? d.scopes : S.scopes);
  // 门禁（auth.mode=oauth）开着时，新增管理员要一并填 OAuth 归属
  const gate = !!d.gate;
  const providers = (d.providers && d.providers.length ? d.providers : ['generic']);
  const defProvider = d.default_provider || providers[0];
  const opts = { gate: gate, providers: providers, defProvider: defProvider };
  box.innerHTML = '';
  box.append(pageHead('管理员账号', gate
    ? '一个主账号在群里有一个身份。门禁开着，新增管理员要一起填他的 OAuth 身份。'
    : '一个主账号在群里有一个身份；如果开了门禁，一个主账号可以有几个群身份。', [
    btn('新增管理员', 'btn-primary', function () { accountDialog(null, opts); }, 'fa-user-plus'),
  ]));

  const rows = (d.accounts || []).map(function (a) {
    const roleBadge = a.role === 'super' ? badge('super') : (a.role === 'admin' ? badge('admin') : el('span', { class: 'badge muted', text: '普通成员' }));
    const acts = [];
    acts.push(btn('编辑', 'btn-ghost btn-sm', function () { accountDialog(a, opts); }, 'fa-pen'));
    acts.push(btn('重置密码', 'btn-ghost btn-sm', function () { passwordDialog(a); }, 'fa-key'));
    if (!a.self && !a.deleted) acts.push(btn('停用', 'btn-ghost btn-sm danger', function () { deleteAccount(a); }, 'fa-user-slash'));
    return [
      el('td', {}, [div('cell-name', [
        el('b', { text: a.name }),
        a.self ? el('span', { class: 'badge muted', text: '我' }) : null,
        a.deleted ? el('span', { class: 'badge danger', text: '已停用' }) : null,
      ])]),
      el('td', {}, [roleBadge]),
      el('td', { class: 'muted', text: a.role === 'super' ? '全站' : (a.scope || '—') }),
      el('td', { class: 'num', text: String(a.members) }),
      el('td', {}, [a.oauth_sub
        ? el('span', { class: 'badge ai', text: '已绑定' + (a.oauth > 0 ? ' ' + a.oauth : ''), title: (a.oauth_provider || '') + ' / ' + a.oauth_sub })
        : (a.oauth > 0 ? el('span', { class: 'badge ai', text: '已绑定 ' + a.oauth }) : el('span', { class: 'muted', text: '未绑定' }))]),
      el('td', { class: 'muted', text: fmtDate(a.created_at) }),
      el('td', {}, [div('row-acts', acts)]),
    ];
  });

  box.append(card('账号列表', [table(['账号', '角色', '归属群', '群身份', 'OAuth', '创建时间', '操作'], rows, '还没有管理员账号', 'fa-user-shield')], {
    icon: 'fa-user-shield', flush: true,
  }));
  box.append(card('角色说明', [div('hint', [
    el('p', { html: '<b>超级管理员</b>：全站权限，能管账号、OAuth、站点设置和互通圈子。' }),
    el('p', { html: '<b>管理员</b>：只能管自己所属的群，看不到其它群和站点设置。' }),
    el('p', { html: '<b>普通成员</b>：保留群身份，但没有管理后台权限。' }),
    el('p', { text: '账号停用后无法登录，但历史消息保留。站点至少保留一个超级管理员。' }),
  ])], { icon: 'fa-circle-info' }));
}

function accountDialog(a, opts) {
  const isNew = !a;
  const gate = !!(opts && opts.gate);
  const providers = (opts && opts.providers && opts.providers.length ? opts.providers : ['generic']);
  const defProvider = (opts && opts.defProvider) || providers[0];
  const askOAuth = gate && isNew;
  const grid = div('form-grid', [
    field('账号名', textInput('name', a ? a.name : '', '2-32 位文字/数字/._-')),
    field('角色', selectInput('role', [
      { value: 'admin', label: '管理员（限一个群）' },
      { value: 'super', label: '超级管理员' },
      { value: 'member', label: '普通成员（保留群身份）' },
    ], a ? a.role : 'admin')),
    isNew ? field('初始密码', textInput('password', '', '至少 8 位', 'password')) : null,
    field('归属群（仅管理员）', selectInput('scope_id', S.scopes.map(function (s) {
      return { value: s.id, label: s.title || ('群 #' + s.id) };
    }), a ? a.scope_id : (S.me && S.me.scope_id) || 0)),
  ]);
  if (askOAuth) {
    grid.appendChild(field('OAuth 提供方', selectInput('oauth_provider', providers.map(function (p) {
      return { value: p, label: p };
    }), defProvider)));
    grid.appendChild(field('OAuth 主体（sub）', textInput('oauth_sub', '', '提供方返回的用户唯一标识')));
    grid.appendChild(field('邮箱（可选）', textInput('oauth_email', '', '只用来认人，可以留空')));
  }
  const f = el('form', { class: 'adm-form' }, [grid]);
  if (askOAuth) {
    f.append(el('p', {
      class: 'field-hint',
      text: '门禁开着：管理员也走 OAuth 登录，这里要把他和提供方返回的 sub 绑上，否则他自己用 OAuth 登录会被当成新成员另建一个号。sub 在提供方后台能看到，第一次登录后也能在用户信息里查到。',
    }));
  } else if (a && a.oauth_sub) {
    f.append(el('p', { class: 'field-hint', text: '已绑 OAuth：' + (a.oauth_provider || '') + ' / ' + a.oauth_sub }));
  }
  const m = modal(isNew ? '新增管理员' : '编辑管理员 · ' + a.name, [f], [
    btn('取消', 'btn-ghost', function () { m.close(); }),
    btn(isNew ? '创建' : '保存', 'btn-primary', async function () {
      const body = {
        name: formValue(f, 'name'),
        role: formValue(f, 'role'),
        scope_id: formValue(f, 'scope_id'),
      };
      if (isNew) body.password = formValue(f, 'password');
      if (askOAuth) {
        body.oauth_provider = formValue(f, 'oauth_provider');
        body.oauth_sub = formValue(f, 'oauth_sub');
        body.oauth_email = formValue(f, 'oauth_email');
      }
      try {
        if (isNew) await api('accounts', body);
        else await api('accounts/' + a.id, body);
        m.close(); toast(isNew ? '管理员已创建' : '已保存'); go('accounts');
      } catch (e) { toast(e.message, 'err'); }
    }, 'fa-check'),
  ]);
}

function passwordDialog(a) {
  const f = el('form', { class: 'adm-form' }, [
    field('新密码', textInput('password', '', '至少 8 位', 'password')),
    el('p', { class: 'field-hint', text: '重置后该账号需要用新密码重新登录；如果忘了自己的密码，请让另一位超级管理员操作。' }),
  ]);
  const m = modal('重置密码 · ' + a.name, [f], [
    btn('取消', 'btn-ghost', function () { m.close(); }),
    btn('重置', 'btn-danger', async function () {
      try {
        await api('accounts/' + a.id + '/password', { password: formValue(f, 'password') });
        m.close(); toast('密码已重置');
      } catch (e) { toast(e.message, 'err'); }
    }, 'fa-key'),
  ]);
}

async function deleteAccount(a) {
  const ok = await confirmBox('停用管理员', '停用「' + a.name + '」后该账号无法登录后台，群身份保留。确定？', true);
  if (!ok) return;
  try { await api('accounts/' + a.id + '/delete', {}); toast('已停用'); go('accounts'); }
  catch (e) { toast(e.message, 'err'); }
}

// ---------------------------------------------------------------- OAuth

async function renderOAuth(box) {
  box.append(loading());
  const d = await api('settings');
  const st = d.settings || {};
  box.innerHTML = '';
  box.append(pageHead('OAuth 登录', '端点可以手动填，也可以只填 Basic URL 从 /.well-known/openid-configuration 自动发现', [
    st['oauth.enabled'] === '1'
      ? el('a', { class: 'btn btn-ghost', href: '/oauth/start', target: '_blank', rel: 'noopener' }, [icon('fa-up-right-from-square'), el('span', { text: '发起登录测试' })])
      : null,
  ]));

  // 回调地址卡：这段必须能直接抄到第三方后台
  const cbUrl = d.callback_url || CALLBACK;
  const cbInput = el('input', { class: 'adm-input mono', value: cbUrl, readonly: true });
  box.append(card('回调地址（Redirect URI）', [
    div('oauth-cb', [
      el('div', { class: 'oauth-cb-row' }, [cbInput, btn('复制', 'btn-ghost', function () { copyText(cbInput.value); }, 'fa-copy')]),
      div('hint', [
        el('p', { text: '把这个地址原样填到第三方 OAuth 后台的「回调地址 / Redirect URI」里，一字不差（含 http/https 和端口）。' }),
        el('p', { text: '地址取自「站点设置 → 站点地址」；没配时按当前访问域名自动生成。若前面有反向代理，请把站点地址写成对外域名。' }),
        el('p', { class: 'mono', text: cbUrl || '' }),
      ]),
    ]),
  ], { icon: 'fa-link' }));

  // 端点：两种填法（手动抄三个地址 / 从 Basic URL 拉 well-known）
  const issuerInput = textInput('oauth.issuer', st['oauth.issuer'] || '', 'https://accounts.google.com');
  const status = div('discover-status');
  const discovered = div('discover-fields');
  const fAuth = textInput('oauth.authorize_url', st['oauth.authorize_url'] || '', 'https://.../authorize');
  const fToken = textInput('oauth.token_url', st['oauth.token_url'] || '', 'https://.../token');
  const fUser = textInput('oauth.userinfo_url', st['oauth.userinfo_url'] || '', 'https://.../userinfo');
  const modeManual = el('input', { type: 'radio', name: 'oauth.mode', value: 'manual', checked: true });
  const modeAuto = el('input', { type: 'radio', name: 'oauth.mode', value: 'discover' });
  const endpointsManual = div('oauth-endpoints', [
    div('form-grid', [
      div('span2', [field('授权地址', fAuth, '浏览器跳过去登录的地址；提供方文档里叫 authorization_endpoint')]),
      div('span2', [field('换取 Token 地址', fToken, '服务器拿授权码换 token 的地址；文档里叫 token_endpoint')]),
      div('span2', [field('用户信息地址', fUser, '拿用户唯一标识（sub）和昵称的地址；文档里叫 userinfo_endpoint；留空就从 id_token 取')]),
    ]),
  ]);
  const endpointsAuto = div('oauth-endpoints', [
    div('form-grid', [
      div('span2', [field('Basic URL（提供方站点根地址）', issuerInput, '只填根地址，不要带 /.well-known/…，也不要带 ? 查询参数')]),
    ]),
    div('discover-actions', [
      btn('拉取端点', 'btn-primary', doDiscover, 'fa-magnifying-glass'),
      el('span', { class: 'hint', text: '拉取成功后三个端点会自动填好，再点保存才生效。' }),
    ]),
    status,
    discovered,
  ]);
  endpointsAuto.hidden = true;
  const modeWrap = div('oauth-mode', [
    el('label', { class: 'oauth-mode-opt' }, [
      modeManual,
      el('span', {}, [el('strong', { text: '手动填写端点' }), el('em', { text: '自己从提供方文档里抄三个地址，最直接' })]),
    ]),
    el('label', { class: 'oauth-mode-opt' }, [
      modeAuto,
      el('span', {}, [el('strong', { text: '从 Basic URL 自动发现' }), el('em', { text: '只填站点根地址，去 /.well-known/openid-configuration 找端点' })]),
    ]),
  ]);
  function paintMode() {
    const auto = modeAuto.checked;
    endpointsAuto.hidden = !auto;
    endpointsManual.hidden = auto;
  }
  modeManual.addEventListener('change', paintMode);
  modeAuto.addEventListener('change', paintMode);

  async function doDiscover() {
    status.innerHTML = '';
    discovered.innerHTML = '';
    const iss = issuerInput.value.trim();
    if (!iss) {
      status.append(el('div', { class: 'discover-err', text: '先填上面的提供方地址，例如 https://accounts.google.com' }));
      return;
    }
    status.append(div('discover-busy', [icon('fa-spinner', 'fa-spin'), el('span', { text: '正在请求 ' + iss.replace(/\/+$/, '') + '/.well-known/openid-configuration …' })]));
    try {
      const r = await api('oauth', { issuer: iss });
      status.innerHTML = '';
      status.append(div('discover-ok', [icon('fa-circle-check'), el('span', { text: '发现成功：' + r.well_known })]));
      (r.warnings || []).forEach(function (w) {
        status.append(el('div', { class: 'discover-warn', text: '注意：' + w }));
      });
      discovered.innerHTML = '';
      discovered.append(el('div', { class: 'discover-list' }, [
        el('div', { text: 'authorization_endpoint：' + (r.discovered.authorization_endpoint || '') }),
        el('div', { text: 'token_endpoint：' + (r.discovered.token_endpoint || '') }),
        el('div', { text: 'userinfo_endpoint：' + (r.discovered.userinfo_endpoint || '（无，留空从 id_token 取）') }),
        el('div', { text: 'scopes：' + (r.discovered.scope || '') }),
      ].map(function (x) { return el('div', { class: 'mono discover-line', text: x.textContent }); })));
      fillOAuthForm(r);
      toast('已按 well-known 填充端点，记得保存');
    } catch (e) {
      status.innerHTML = '';
      status.append(div('discover-err', [icon('fa-circle-xmark'), el('span', { text: e.message || '发现失败' })]));
    }
  }

  // 客户端与开关
  const fEnabled = el('input', { type: 'checkbox', name: 'oauth.enabled', checked: st['oauth.enabled'] === '1' });
  const fBan = el('input', { type: 'checkbox', name: 'oauth.ban_sync', checked: st['oauth.ban_sync'] === '1' });
  const fProvider = textInput('oauth.provider', st['oauth.provider'] || '', 'google');
  const fClient = textInput('oauth.client_id', st['oauth.client_id'] || '', '第三方给的 Client ID');
  // 后端不回传密钥明文：只按 has_secret 提示，留空保存表示沿用已存的值
  const fSecret = textInput('oauth.client_secret', '', d.has_secret ? '已保存，留空不修改' : '第三方给的 Client Secret', 'password');
  const fScope = textInput('oauth.scope', st['oauth.scope'] || 'openid profile email', 'openid profile email');

  const form = el('form', { class: 'adm-form oauth-form' }, [
    div('oauth-switches', [
      el('label', { class: 'switch' }, [fEnabled, el('span', { text: '启用 OAuth 登录（可用 /oauth/start 发起；登录页会出现「用第三方账号登录」按钮）' })]),
      el('label', { class: 'switch' }, [fBan, el('span', { text: '封禁时同步封禁该 OAuth 身份的所有成员' })]),
    ]),
    div('form-grid', [
      field('提供方标识', fProvider, '小写字母、数字、- 和 _，例如 google'),
      field('Scope', fScope, '通常 openid profile email'),
      field('Client ID', fClient, '提供方后台登记的应用 ID'),
      field('Client Secret', fSecret, '留空就沿用已经存的那个'),
    ]),
    div('hint', [
      el('p', { text: '保存后可在登录页点「第三方登录」测试；回调地址就是上面那张卡里的地址。' }),
      el('p', { text: '如果提供方不返回 userinfo，会尝试从 id_token 里取 sub/email/name，所以用户信息地址可以留空。' }),
    ]),
  ]);

  const save = btn('保存 OAuth 设置', 'btn-primary', async function () {
    const body = {
      'oauth.enabled': fEnabled.checked ? '1' : '0',
      'oauth.ban_sync': fBan.checked ? '1' : '0',
      'oauth.provider': fProvider.value.trim(),
      'oauth.client_id': fClient.value.trim(),
      'oauth.client_secret': fSecret.value,
      'oauth.authorize_url': fAuth.value.trim(),
      'oauth.token_url': fToken.value.trim(),
      'oauth.userinfo_url': fUser.value.trim(),
      'oauth.scope': fScope.value.trim(),
      'oauth.issuer': issuerInput.value.trim(),
    };
    try {
      await api('settings', body);
      toast('OAuth 设置已保存');
      go('oauth');
    } catch (e) { toast(e.message, 'err'); }
  }, 'fa-floppy-disk');

  box.append(card('端点（授权 / token / 用户信息）', [modeWrap, endpointsManual, endpointsAuto], { icon: 'fa-route' }));
  box.append(card('客户端与开关', [form, div('form-foot', [save])], { icon: 'fa-sliders' }));

  // 自动发现结果回填
  function fillOAuthForm(r) {
    const dd = r.discovered || {};
    if (dd.provider) fProvider.value = dd.provider;
    if (dd.authorization_endpoint) fAuth.value = dd.authorization_endpoint;
    if (dd.token_endpoint) fToken.value = dd.token_endpoint;
    if (dd.userinfo_endpoint) fUser.value = dd.userinfo_endpoint;
    if (dd.scope) fScope.value = dd.scope;
    if (r.issuer) issuerInput.value = r.issuer;
    if (dd.issuer) issuerInput.value = dd.issuer;
    if (!fClient.value && dd.client_id) fClient.value = dd.client_id;
  }
}

async function renderCaptcha(box) {
  box.append(loading());
  const d = await api('settings');
  const st = d.settings || {};
  box.innerHTML = '';
  box.append(pageHead('人机验证', '登录验证方式（安装向导第 5 步同一项）；选了 OAuth2 门禁的话，参数在「OAuth 登录」页填'));

  const mode = selectInput('auth.mode', [
    { value: 'captcha', label: '内建滑块验证码（账密之外再拖一次滑块）' },
    { value: 'oauth', label: '第三方 OAuth2 门禁' },
    { value: 'none', label: '都不要（只验账号密码）' },
  ], st['auth.mode'] || 'captcha');

  const provider = selectInput('auth.captcha.provider', [
    { value: 'builtin', label: '内建滑块（不依赖外部服务）' },
    { value: 'turnstile', label: 'Cloudflare Turnstile' },
    { value: 'hcaptcha', label: 'hCaptcha' },
    { value: 'recaptcha', label: 'Google reCAPTCHA v2' },
    { value: 'geetest4', label: 'Geetest 行为验证 v4' },
  ], st['auth.captcha.provider'] || 'builtin');

  const siteKey = textInput('auth.captcha.site_key', st['auth.captcha.site_key'] || '', '第三方给的 Site Key');
  // 后端不回传密钥明文：只按 has_captcha_secret 提示，留空保存表示沿用已存的值
  const secret = textInput('auth.captcha.secret', '', d.has_captcha_secret ? '已保存，留空不修改' : '第三方给的 Secret Key', 'password');
  const verify = textInput('auth.captcha.verify_url', st['auth.captcha.verify_url'] || '', '留空用官方校验地址');

  // 内建滑块不需要钥匙，选它就收起第三方字段
  const idField = field('Site Key', siteKey, '前端渲染 widget 用，是公开的');
  const keyField = field('Secret Key', secret, '留空就沿用已经存的那个');
  const third = div('form-grid', [
    idField,
    keyField,
    div('span2', [field('校验地址（可选）', verify, '填了用它校验；留空用提供方官方地址')]),
  ]);

  const status = div('hint');
  function paint() {
    const isThird = provider.value !== 'builtin';
    third.style.display = isThird ? '' : 'none';
    // Geetest 那边把公钥/密钥叫 Captcha ID / Captcha Key
    const isGt = provider.value === 'geetest4';
    const lk = idField.querySelector('.field-label');
    const lkk = keyField.querySelector('.field-label');
    if (lk) lk.textContent = isGt ? 'Captcha ID' : 'Site Key';
    if (lkk) lkk.textContent = isGt ? 'Captcha Key' : 'Secret Key';
    siteKey.placeholder = isGt ? 'Geetest 后台的 Captcha ID' : '第三方给的 Site Key';
    if (!secret.value) {
      secret.placeholder = d.has_captcha_secret
        ? '已保存，留空不修改'
        : (isGt ? 'Geetest 后台的 Captcha Key' : '第三方给的 Secret Key');
    }
    status.innerHTML = '';
    if (mode.value === 'oauth') {
      status.append(el('p', { text: '当前登录方式：第三方 OAuth2 门禁，参数请在「OAuth 登录」页配置' }));
    } else if (mode.value === 'none') {
      status.append(el('p', { text: '当前登录方式：只验账号密码' }));
    } else {
      status.append(el('p', { text: d.captcha_active
        ? '当前登录方式：人机验证（' + (provider.value === 'builtin' ? '内建滑块' : provider.value) + '）'
        : '当前登录方式：人机验证，但还没生效' }));
      if (d.captcha_warning) status.append(el('p', { class: 'warn', text: '注意：' + d.captcha_warning }));
    }
  }
  provider.addEventListener('change', paint);
  mode.addEventListener('change', paint);
  paint();

  box.append(card('验证设置', [
    el('form', { class: 'adm-form' }, [
      div('form-grid', [
        field('登录验证方式', mode, '安装向导里选过，这里可以改'),
        field('人机验证提供方', provider),
      ]),
      third,
      status,
    ]),
    div('form-foot', [
      btn('保存验证设置', 'btn-primary', async function () {
        try {
          await api('settings', {
            'auth.mode': mode.value,
            'auth.captcha.provider': provider.value,
            'auth.captcha.site_key': siteKey.value.trim(),
            'auth.captcha.secret': secret.value,
            'auth.captcha.verify_url': verify.value.trim(),
          });
          toast('验证设置已保存');
          go('captcha');
        } catch (e) { toast(e.message, 'err'); }
      }, 'fa-floppy-disk'),
    ]),
  ], { icon: 'fa-shield-halved' }));
}

// ---------------------------------------------------------------- 邮件通知

async function renderMail(box) {
  box.append(loading());
  const d = await api('settings');
  const st = d.settings || {};
  box.innerHTML = '';
  box.append(pageHead('邮件通知', '站内信发出去的时候，顺带给订阅了邮箱的成员发一封；聊天消息永远不发邮件'));

  const fOn = el('input', { type: 'checkbox', checked: st['mail.enabled'] === '1' });
  const fNotify = el('input', { type: 'checkbox', checked: st['mail.notify'] === '1' });
  const fHost = textInput('mail.host', st['mail.host'] || '', '例如 smtp.exmail.qq.com');
  const fPort = textInput('mail.port', st['mail.port'] || '465', 'SSL 一般是 465，STARTTLS 一般是 587');
  const fSecure = selectInput('mail.secure', [
    { value: 'ssl', label: 'SSL（常用 465）' },
    { value: 'tls', label: 'STARTTLS（常用 587）' },
    { value: '', label: '不加密（内网自建才用）' },
  ], !st['mail.host'] && !st['mail.secure'] ? 'ssl' : (st['mail.secure'] || ''));
  const fUser = textInput('mail.user', st['mail.user'] || '', '登录 SMTP 的账号，通常是完整邮箱');
  const fPass = textInput('mail.pass', '', d.has_mail_pass ? '已保存，留空不修改' : '邮箱密码或授权码', 'password');
  const fFrom = textInput('mail.from', st['mail.from'] || '', '发件地址，例如 noreply@example.com');
  const fName = textInput('mail.from_name', st['mail.from_name'] || '', '默认用站点名');

  const stateLine = div('hint');
  function paintState() {
    stateLine.innerHTML = '';
    if (!d.mail_configured) {
      stateLine.append(el('p', { class: 'warn', text: '还没配好：服务器地址、发件地址、端口都填上才能发信' }));
      return;
    }
    if (d.mail_ready) stateLine.append(el('p', { text: '现在会发信：成员订阅并验证过邮箱后，收到站内信就会收到一封邮件' }));
    else if (st['mail.enabled'] === '1') stateLine.append(el('p', { text: '总开关开着，但「站内信邮件提醒」还没开，现在不会发' }));
    else stateLine.append(el('p', { text: '配置齐了，但总开关关着，现在不会发' }));
  }
  paintState();

  const testTo = textInput('', '', '发给哪个邮箱');
  const testBtn = btn('发测试邮件', 'btn-ghost btn-sm', async function () {
    const to = testTo.value.trim();
    if (!to) { toast('先填一个收件邮箱'); return; }
    testBtn.disabled = true;
    try {
      await api('mail', { to: to });
      toast('测试邮件发出去了');
    } catch (e) { toast(e.message, 'err'); }
    testBtn.disabled = false;
  }, 'fa-paper-plane');

  const save = btn('保存邮件设置', 'btn-primary', async function () {
    try {
      await api('settings', {
        'mail.enabled': fOn.checked ? '1' : '0',
        'mail.notify': fNotify.checked ? '1' : '0',
        'mail.host': fHost.value.trim(),
        'mail.port': fPort.value.trim(),
        'mail.secure': fSecure.value,
        'mail.user': fUser.value.trim(),
        'mail.pass': fPass.value,
        'mail.from': fFrom.value.trim(),
        'mail.from_name': fName.value.trim(),
      });
      toast('邮件设置已保存');
      go('mail');
    } catch (e) { toast(e.message, 'err'); }
  }, 'fa-floppy-disk');

  box.append(card('SMTP 服务器', [
    el('form', { class: 'adm-form' }, [
      div('form-grid', [
        field('服务器地址', fHost, '发信用的 SMTP 服务器'),
        field('端口', fPort),
        field('加密方式', fSecure),
        field('发件地址', fFrom, '收件人看到的发件人邮箱'),
        field('发件人名字', fName, '留空用站点名'),
      ]),
      div('form-grid', [
        field('账号', fUser, '多数邮箱要求填完整地址'),
        field('密码 / 授权码', fPass, '留空就沿用已经存的那个'),
      ]),
      stateLine,
    ]),
    div('form-foot', [save]),
  ], { icon: 'fa-server' }));

  box.append(card('开关', [
    el('form', { class: 'adm-form' }, [
      div('oauth-switches', [
        el('label', { class: 'switch' }, [fOn, el('span', { text: '总开关：允许发信' })]),
        el('label', { class: 'switch' }, [fNotify, el('span', { text: '成员收到站内信时发邮件（成员在「站内信」页验证资料卡邮箱后生效）' })]),
      ]),
      div('hint', [el('p', { text: '只对站内信发信。私聊、群消息、空间动态本身都不会发邮件。' })]),
    ]),
  ], { icon: 'fa-toggle-on' }));

  box.append(card('试一试', [
    div('field-inline', [testTo, testBtn]),
    div('hint', [el('p', { text: '测试邮件直接发，不看开关；发不出去会显示原因。' })]),
  ], { icon: 'fa-vial' }));
}

// ---------------------------------------------------------------- 空间

async function renderMoments(box) {
  box.append(loading());
  const d = await api('moments').catch(function () { return null; });
  box.innerHTML = '';
  box.append(pageHead('空间', '群成员在这里发短动态（文字 300 字、最多 9 张图）；推荐算法默认关闭，开了才有「信息茧房」'));

  const cur = d && typeof d.mode === 'number' ? d.mode : (d && d.on ? 1 : 0);
  const opts = [
    { v: 0, t: '关闭（默认）', s: '广场按时间倒序，最新的在最上面。小圈子这样最公平。' },
    { v: 1, t: '开启推荐算法', s: '按「你赞过、评论过的人和标签」优先排序，再叠加热度和时间衰减。用得越久，越只看到自己熟悉的那一类。' },
  ];
  const radios = opts.map(function (o) {
    const r = el('input', { type: 'radio', name: 'pushmode', value: String(o.v), checked: cur === o.v });
    return el('label', { class: 'push-opt' }, [
      el('div', { class: 'push-opt-top' }, [r, el('b', { text: o.t })]),
      el('p', { class: 'hint', text: o.s }),
    ]);
  });

  box.append(card('帖子推荐算法', [
    el('form', { class: 'adm-form' }, radios.concat([
      div('hint', [
        el('p', { text: '这个开关只管当前这个群；互通圈子里的其它群各自管各自的。' }),
      ]),
    ])),
    div('form-foot', [
      btn('保存', 'btn-primary', async function () {
        const picked = radios.map(function (l) { return l.querySelector('input'); }).find(function (i) { return i.checked; });
        try {
          await api('moments', { mode: picked ? Number(picked.value) : 0 });
          toast('已保存');
        } catch (e) { toast(e.message, 'err'); }
      }, 'fa-floppy-disk'),
    ]),
  ], { icon: 'fa-bullhorn' }));
}

// ---------------------------------------------------------------- 主题

async function renderThemes(box) {
  box.append(loading());
  const d = await api('themes');
  box.innerHTML = '';
  const packs = d.packs || [];
  box.append(pageHead('主题包', '共享主题包所有成员可见；站点默认主题在「站点设置」，这里设的是本群默认主题', [
    btn('新建共享主题', 'btn-primary', function () { newPackDialog(); }, 'fa-plus'),
  ]));

  const opts = [{ value: '', label: '跟随站点默认' }].concat((d.builtin || []).map(function (b) { return { value: b.id, label: b.name }; }))
    .concat(packs.map(function (p) { return { value: p.id, label: p.name + (p.dark ? '（深色）' : '') }; }));
  const scopeTheme = (d.scope && d.scope.theme) || '';
  const sel = selectInput('theme', opts, scopeTheme);
  const saveWrap = div('theme-default', [
    el('div', { class: 'field-inline' }, [el('span', { class: 'field-hint', text: '本群默认主题' }), sel]),
    btn('保存默认主题', 'btn-primary btn-sm', async function () {
      try { await api('themes/scope', { theme: sel.value }); toast('默认主题已保存'); go('themes'); }
      catch (e) { toast(e.message, 'err'); }
    }, 'fa-floppy-disk'),
  ]);

  const rows = packs.map(function (p) {
    return [
      el('td', {}, [div('cell-name', [
        el('b', { text: p.name }),
        p.dark ? el('span', { class: 'badge muted', text: '深色' }) : null,
        p.shared ? el('span', { class: 'badge admin', text: '共享' }) : el('span', { class: 'badge muted', text: '私有' }),
      ])]),
      el('td', {}, [div('color-dots', Object.keys(p.vars || {}).slice(0, 6).map(function (k) {
        return el('span', { class: 'color-dot', style: 'background:' + p.vars[k], title: k + ' ' + p.vars[k] });
      }))]),
      el('td', { class: 'mono muted', text: p.id }),
      el('td', {}, [btn('删除', 'btn-ghost btn-sm danger', async function () {
        const ok = await confirmBox('删除主题包', '删除「' + p.name + '」后，正在用它的成员会回到默认主题。', true);
        if (!ok) return;
        try { await api('themes/' + p.id.replace('p_', ''), {}); toast('已删除'); go('themes'); }
        catch (e) { toast(e.message, 'err'); }
      }, 'fa-trash')]),
    ];
  });

  box.append(card('本群主题', [saveWrap, div('theme-table', [table(['名称', '配色', 'ID', '操作'], rows, '还没有共享主题包', 'fa-palette')])], {
    icon: 'fa-palette', flush: true,
  }));
}

function newPackDialog() {
  const f = el('form', { class: 'adm-form' }, [
    div('form-grid', [
      field('主题名', textInput('name', '', '最多 20 字')),
      field('深色主题', selectInput('dark', [{ value: '0', label: '浅色' }, { value: '1', label: '深色' }], '0')),
      field('主色 accent', textInput('accent', '#3b82f6', '#rrggbb')),
      field('背景 bg', textInput('bg', '', '#rrggbb，可空')),
      field('文字 text', textInput('text', '', '#rrggbb，可空')),
    ]),
  ]);
  const m = modal('新建共享主题', [f], [
    btn('取消', 'btn-ghost', function () { m.close(); }),
    btn('创建', 'btn-primary', async function () {
      const vars = {};
      ['accent', 'bg', 'text'].forEach(function (k) {
        const v = formValue(f, k);
        if (v) vars[k] = v;
      });
      try {
        await api('themes', { name: formValue(f, 'name'), dark: formValue(f, 'dark'), vars: vars });
        m.close(); toast('主题包已创建'); go('themes');
      } catch (e) { toast(e.message, 'err'); }
    }, 'fa-check'),
  ]);
}

// ---------------------------------------------------------------- 互通圈子

async function renderClusters(box) {
  box.append(loading());
  const d = await api('clusters');
  box.innerHTML = '';
  box.append(pageHead('互通圈子', '同一个圈子里的群会合并消息流（运营维度，不影响数据隔离）', [
    btn('新建圈子', 'btn-primary', newClusterDialog, 'fa-plus'),
  ]));

  const rows = (d.clusters || []).map(function (c) {
    return [
      el('td', {}, [el('b', { text: c.name })]),
      el('td', {}, [div('cell-tags', (c.scopes || []).length ? c.scopes.map(function (s) {
        return el('span', { class: 'badge muted', text: s.title || s.slug || ('群 #' + s.id) });
      }) : [el('span', { class: 'muted', text: '还没有群加入' })])]),
      el('td', {}, [div('row-acts', [
        btn('改名', 'btn-ghost btn-sm', function () { renameCluster(c); }, 'fa-pen'),
        btn('加群/移出', 'btn-ghost btn-sm', function () { clusterScopeDialog(c); }, 'fa-plus-minus'),
        btn('删除', 'btn-ghost btn-sm danger', async function () {
          const ok = await confirmBox('删除圈子', '删除「' + c.name + '」不会删群，只解除互通。', true);
          if (!ok) return;
          try { await api('clusters/' + c.id + '/delete', {}); toast('已删除'); go('clusters'); }
          catch (e) { toast(e.message, 'err'); }
        }, 'fa-trash'),
      ])]),
    ];
  });

  box.append(card('全部圈子', [table(['名称', '包含的群', '操作'], rows, '还没有互通圈子', 'fa-circle-nodes')], { icon: 'fa-circle-nodes', flush: true }));
}

function newClusterDialog() {
  const f = el('form', { class: 'adm-form' }, [field('圈子名', textInput('name', '', '例如：夜航系列'))]);
  const m = modal('新建圈子', [f], [
    btn('取消', 'btn-ghost', function () { m.close(); }),
    btn('创建', 'btn-primary', async function () {
      try { await api('clusters', { name: formValue(f, 'name') }); m.close(); toast('已创建'); go('clusters'); }
      catch (e) { toast(e.message, 'err'); }
    }, 'fa-check'),
  ]);
}

function renameCluster(c) {
  const f = el('form', { class: 'adm-form' }, [field('圈子名', textInput('name', c.name))]);
  const m = modal('改名', [f], [
    btn('取消', 'btn-ghost', function () { m.close(); }),
    btn('保存', 'btn-primary', async function () {
      try { await api('clusters/' + c.id + '/name', { name: formValue(f, 'name') }); m.close(); toast('已保存'); go('clusters'); }
      catch (e) { toast(e.message, 'err'); }
    }, 'fa-check'),
  ]);
}

function clusterScopeDialog(c) {
  const f = el('form', { class: 'adm-form' }, [
    div('form-grid', [
      field('群组', selectInput('scope_id', S.scopes.map(function (s) { return { value: s.id, label: s.title || ('群 #' + s.id) }; }), 0)),
      field('操作', selectInput('join', [{ value: '1', label: '加入圈子' }, { value: '0', label: '移出圈子' }], '1')),
    ]),
  ]);
  const m = modal('加群/移出 · ' + c.name, [f], [
    btn('取消', 'btn-ghost', function () { m.close(); }),
    btn('执行', 'btn-primary', async function () {
      try {
        await api('clusters/' + c.id + '/scopes', { scope_id: formValue(f, 'scope_id'), join: formValue(f, 'join') });
        m.close(); toast('已更新'); go('clusters');
      } catch (e) { toast(e.message, 'err'); }
    }, 'fa-check'),
  ]);
}

// ---------------------------------------------------------------- 会话审计

async function renderConvs(box, arg) {
  if (arg) { await renderConvAudit(box, Number(arg) || 0); return; }
  const filter = { kind: '', kw: '' };

  async function paint() {
    box.innerHTML = '';
    box.append(loading());
    const d = await api('convs?kind=' + encodeURIComponent(filter.kind) + '&q=' + encodeURIComponent(filter.kw));
    box.innerHTML = '';
    box.append(pageHead('会话审计', '大群、私群、私聊都在这里；点「查看」看消息、成员和权限组。'));

    const kindSel = selectInput('kind', [
      { value: '', label: '全部会话' },
      { value: 'public', label: '大群' },
      { value: 'pg', label: '私群' },
      { value: 'dm', label: '私聊' },
    ], filter.kind);
    kindSel.addEventListener('change', function () { filter.kind = kindSel.value; paint(); });
    const kwInp = el('input', { class: 'adm-input', type: 'search', placeholder: '搜群名、成员、创建者', value: filter.kw });
    kwInp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { filter.kw = kwInp.value.trim(); paint(); }
    });
    box.append(div('conv-filter', [
      kindSel,
      kwInp,
      btn('筛选', 'btn-primary btn-sm', function () { filter.kw = kwInp.value.trim(); paint(); }, 'fa-magnifying-glass'),
    ]));

    const rows = (d.convs || []).map(function (c) {
      return [
        el('td', {}, [div('cell-name', [
          el('b', { text: c.title || ('会话 #' + c.id) }),
          c.isPublic
            ? el('span', { class: 'badge admin', text: '大群' })
            : (c.private ? el('span', { class: 'badge', text: '私群' }) : el('span', { class: 'badge muted', text: '私聊' })),
          c.dissolved ? el('span', { class: 'badge muted', text: '已解散' }) : null,
        ])]),
        el('td', { class: 'muted', text: c.scopeTitle }),
        el('td', {}, [el('span', { text: c.permGroupName || '默认权限组' }), c.permOwn ? el('span', { class: 'badge', text: '单独' }) : null]),
        el('td', { class: 'num', text: String(c.memberCount) }),
        el('td', { class: 'num', text: String(c.msgCount) }),
        el('td', { class: 'muted', text: c.lastAt ? ago(c.lastAt) : '—' }),
        el('td', {}, [btn('查看', 'btn-ghost btn-sm', function () { go('convs', String(c.id)); }, 'fa-eye')]),
      ];
    });

    box.append(card('会话', [table(['会话', '群组', '权限组', '成员', '消息数', '最后活跃', '操作'], rows, '没有符合条件的会话', 'fa-comments')], {
      icon: 'fa-magnifying-glass-chart', flush: true,
    }));
  }

  await paint();
}

/** 会话审计里的一条消息预览：没有文字不等于撤回，语音/卡片/图片各自有标签 */
function adminMsgText(msg) {
  if (!msg) return '';
  if (msg.recalled) return '（已撤回）';
  // 服务端为了老客户端会把媒体地址拼进 text，这里剥掉，看的是人话
  let t = String(msg.text || '')
    .replace(/\[(图片|语音|卡片)\]\(\s*https?:\/\/[^)\s]*\s*\)/g, '')
    .replace(/\s*\d+"\s*$/, '')
    .trim();
  if (t) return t;
  if (msg.img) return '[图片]';
  if (msg.voice) return msg.dur ? '[语音 ' + msg.dur + '"]' : '[语音]';
  if (msg.card) return '[卡片]';
  return '[消息]';
}

function convMetaItem(label, value) {
  return div('conv-meta-i', [
    el('span', { class: 'conv-meta-l', text: label }),
    el('span', { class: 'conv-meta-v', text: value || '—' }),
  ]);
}

/** 会话审计详情：独立子页 #/convs/<id>，信息面板放全，不再用弹窗 */
async function renderConvAudit(box, id) {
  box.classList.add('conv-audit');
  async function act(path, body, okMsg) {
    try {
      await api('convs/' + id + '/' + path, body || {});
      toast(okMsg || '已完成');
      await paint();
    } catch (e) { toast(e.message, 'err'); }
  }
  async function paint() {
    box.innerHTML = '';
    box.append(loading());
    let d;
    try { d = await api('convs/' + id); }
    catch (e) {
      box.innerHTML = '';
      box.append(div('conv-audit-head', [
        btn('返回会话列表', 'btn-ghost btn-sm', function () { go('convs'); }, 'fa-arrow-left'),
      ]));
      box.append(card('读取失败', [div('adm-error', [icon('fa-circle-exclamation'), el('div', { text: e.message })])], { icon: 'fa-triangle-exclamation' }));
      return;
    }
    const cv = d.conv || {};
    const rows = cv.memberRows || [];
    const msgs = d.msgs || [];
    const kindText = cv.isPublic ? '大群' : (cv.private ? '私群' : '私聊');
    const title = (cv.title || ('会话 #' + id)) + (cv.dissolved ? '（已解散）' : '');
    const memberName = function (mid) {
      for (let i = 0; i < rows.length; i++) if (Number(rows[i].id) === Number(mid)) return rows[i].name;
      return mid ? ('#' + mid) : '';
    };

    box.innerHTML = '';
    box.append(div('conv-audit-head', [
      btn('返回会话列表', 'btn-ghost btn-sm', function () { go('convs'); }, 'fa-arrow-left'),
      div('conv-audit-title', [
        el('h1', { class: 'page-title', text: title }),
        el('p', { class: 'page-desc', text: kindText + ' · ' + (cv.scopeTitle || '') + ' · 会话 #' + (cv.id || id) }),
      ]),
    ]));

    const metaPairs = [
      ['会话 ID', String(cv.id || id)],
      ['群组', cv.scopeTitle || '—'],
      ['类型', kindText + (cv.dissolved ? ' · 已解散' : '')],
      ['创建者', cv.creator || '—'],
      ['创建时间', cv.createdAt ? fmtDT(cv.createdAt) : '—'],
      ['最后活跃', cv.lastAt ? ago(cv.lastAt) : '—'],
      ['成员数', String(rows.length)],
      ['消息数', String(cv.msgCount === undefined ? msgs.length : cv.msgCount)],
    ];
    box.append(card('基本信息', [div('conv-meta', metaPairs.map(function (kv) { return convMetaItem(kv[0], kv[1]); }))], { icon: 'fa-circle-info' }));

    // 管理：广播、群封禁、全员禁言、群注销；每个动作都会在会话里留一条系统提示
    const modKids = [];
    const bcTa = el('textarea', { class: 'adm-input conv-bc', rows: '2', placeholder: '广播内容，会以系统提示发到这个会话' });
    modKids.push(div('conv-mod-row', [
      bcTa,
      btn('发送广播', 'btn-primary btn-sm', async function () {
        if (!bcTa.value.trim()) { toast('先写点什么', 'err'); return; }
        await act('broadcast', { text: bcTa.value.trim() }, '广播已发出');
      }, 'fa-bullhorn'),
    ]));
    const banned = !!cv.banned;
    const wmUntil = Number(cv.wholeMuteUntil || 0);
    const modBtns = [];
    modBtns.push(btn(banned ? '解除群封禁' : '封禁这个群', banned ? 'btn-ghost btn-sm' : 'btn-danger btn-sm', async function () {
      if (!banned && !(await confirmBox('封禁这个群', '封禁后所有人都不能发消息，群主和管理员也不行。', true))) return;
      await act('ban', { on: banned ? '0' : '1' }, banned ? '已解除封禁' : '已封禁');
    }, banned ? 'fa-lock-open' : 'fa-lock'));
    modBtns.push(btn(wmUntil ? '解除全员禁言' : '全员禁言', 'btn-ghost btn-sm', async function () {
      if (wmUntil) { await act('mute_all', { on: '0' }, '已解除全员禁言'); return; }
      const min = textInput('mute_all_minutes', '60', '分钟');
      const mm = modal('全员禁言', [field('禁言时长（分钟）', min, '到点自动解除；群主和超管不受影响')], [
        btn('取消', 'btn-ghost', function () { mm.close(); }),
        btn('开启', 'btn-danger', function () { mm.close(); act('mute_all', { on: '1', minutes: min.value }, '已开启全员禁言'); }),
      ]);
    }, 'fa-volume-xmark'));
    modBtns.push(btn('注销这个群', 'btn-danger btn-sm', async function () {
      const tip = cv.isPublic
        ? '整个群会被注销，所有成员都会收到站内信，历史消息仍保留在审计里。'
        : '这个群会解散，成员都会收到站内信，历史消息仍保留在审计里。';
      if (!(await confirmBox('注销这个群', tip, true))) return;
      await act('dissolve', {}, '已注销');
    }, 'fa-trash-can'));
    modKids.push(div('conv-mod-acts', modBtns));
    if (banned) modKids.push(el('p', { class: 'conv-mod-tip', text: '这个群已被封禁，所有人都不能发消息。' }));
    if (wmUntil) modKids.push(el('p', { class: 'conv-mod-tip', text: '全员禁言到 ' + fmtDT(wmUntil) + '。' }));
    box.append(card('管理', modKids, { icon: 'fa-gavel' }));

    const permKids = [];
    if (cv.private) {
      const opts = [{ value: '0', label: '跟随主群' }].concat((cv.permGroups || []).map(function (g) {
        return { value: String(g.id), label: g.name + (g.is_default ? '（平台默认）' : '') };
      }));
      const sel = selectInput('perm_group', opts, cv.permOwn ? String(cv.permGroupId) : '0');
      const savePerm = btn('保存权限组', 'btn-primary btn-sm', async function () {
        try {
          await api('convs/' + id + '/perm_group', { group: sel.value });
          toast('权限组已保存');
          paint();
        } catch (e) { toast(e.message, 'err'); }
      }, 'fa-floppy-disk');
      permKids.push(div('conv-perm', [
        el('span', { class: 'conv-perm-t', text: '这个私群的权限组' }),
        sel,
        savePerm,
        el('span', { class: 'conv-perm-cur', text: '当前：' + (cv.permOwn ? (cv.permGroupName || '单独') : ('跟随主群（' + (cv.permGroupName || '默认权限组') + '）')) }),
      ]));
    } else {
      permKids.push(div('conv-perm', [
        el('span', { class: 'conv-perm-t', text: '权限组' }),
        el('span', { class: 'conv-perm-cur', text: cv.permGroupName || '默认权限组' }),
      ]));
    }
    const permMeta = cv.permMeta || [];
    const chips = permMeta.map(function (m) {
      const raw = String(m.key).split('.').pop();
      const camel = raw.replace(/_([a-z])/g, function (_, c) { return c.toUpperCase(); });
      const on = !!(cv.perm || {})[camel];
      return el('span', { class: 'perm-chip ' + (on ? 'on' : 'off') }, [
        icon(on ? 'fa-circle-check' : 'fa-circle-xmark'),
        document.createTextNode(m.label || m.key),
      ]);
    });
    if (chips.length) permKids.push(div('conv-perm-grid', chips));
    box.append(card('权限组与群功能', permKids, { icon: 'fa-user-lock' }));

    if (cv.notice) {
      box.append(card('群公告', [
        div('conv-notice-t', [document.createTextNode(cv.notice)]),
        div('conv-notice-meta', [document.createTextNode((cv.noticeBy ? memberName(cv.noticeBy) : '') + (cv.noticeAt ? ' · ' + fmtDT(cv.noticeAt) : ''))]),
      ], { icon: 'fa-bullhorn' }));
    }

    const memberRows = rows.map(function (m) {
      const muted = Number(m.muteUntil || 0) > Date.now();
      const acts = [];
      acts.push(btn(muted ? '解禁' : '禁言', 'btn-ghost btn-sm', function () {
        const min = textInput('mute_minutes', '60', '分钟');
        const mm = modal((muted ? '解除禁言「' : '禁言「') + (m.name || ('#' + m.id)) + '」', [
          field('时长（分钟）', min, muted ? '填 0 解除禁言' : '0 表示解除；最长 43200 分钟'),
        ], [
          btn('取消', 'btn-ghost', function () { mm.close(); }),
          btn('确定', 'btn-primary', function () { mm.close(); act('mute', { member: m.id, minutes: min.value }, '已更新禁言'); }),
        ]);
      }));
      if (cv.private && !cv.dissolved) {
        if (m.role !== 'owner') {
          acts.push(btn('移出', 'btn-ghost btn-sm', async function () {
            if (!(await confirmBox('移出成员', '把「' + (m.name || '') + '」移出这个群？', true))) return;
            await act('member', { op: 'remove', member: m.id }, '已移出');
          }));
          if (m.role === 'admin') {
            acts.push(btn('取消管理员', 'btn-ghost btn-sm', function () { act('member', { op: 'role', member: m.id, role: 'member' }, '已取消管理员'); }));
          } else {
            acts.push(btn('设管理员', 'btn-ghost btn-sm', function () { act('member', { op: 'role', member: m.id, role: 'admin' }, '已设为管理员'); }));
          }
          acts.push(btn('转让群主', 'btn-ghost btn-sm', async function () {
            if (!(await confirmBox('转让群主', '把群主转给「' + (m.name || '') + '」？你自己会变成管理员。', true))) return;
            await act('member', { op: 'owner', member: m.id }, '已转让群主');
          }));
        }
      }
      return [
        el('td', {}, [div('cell-name', [el('b', { text: m.name || ('#' + m.id) }), m.gone ? el('span', { class: 'badge muted', text: '已退出' }) : null])]),
        el('td', { class: 'muted', text: m.username || '—' }),
        el('td', {}, [m.kind === 'ai' ? badge('ai') : el('span', { class: 'muted', text: '人' })]),
        el('td', {}, [m.role === 'owner' ? badge('admin', '群主') : (m.role === 'admin' ? badge('admin', '管理员') : el('span', { class: 'muted', text: '—' }))]),
        el('td', {}, [muted ? el('span', { class: 'badge danger', text: '禁言到 ' + fmtDT(m.muteUntil) }) : el('span', { class: 'muted', text: '—' })]),
        el('td', { class: 'muted', text: m.lastRead ? ('#' + m.lastRead) : '—' }),
        el('td', {}, [div('conv-row-acts', acts)]),
      ];
    });
    box.append(card('成员 · ' + rows.length + ' 人', [
      table(['名称', '用户名', '类型', '角色', '禁言', '最后已读', '操作'], memberRows, '没有成员', 'fa-users'),
    ], { icon: 'fa-users', flush: true }));

    const info = div('conv-info', [
      el('span', { class: 'conv-count', text: '最近 ' + msgs.length + ' 条' }),
      btn('清空历史', 'btn-danger btn-sm', async function () {
        if (!(await confirmBox('清空历史', '这个会话里的所有消息都会消失，成员和会话本身不动。', true))) return;
        try {
          const r = await api('convs/' + id + '/clear', {});
          toast('已清空 ' + r.cleared + ' 条');
          paint();
        } catch (e) { toast(e.message, 'err'); }
      }, 'fa-broom'),
    ]);
    const list = div('conv-msgs', []);
    msgs.forEach(function (msg) {
      const canDel = !msg.sys && !msg.recalled;
      list.append(div('conv-msg' + (msg.sys ? ' sys' : ''), [
        div('conv-msg-head', [
          el('b', { text: msg.name || '?' }),
          msg.kind === 'ai' ? badge('ai') : null,
          el('span', { class: 'muted', text: fmtDT(msg.ts) }),
          div('conv-msg-sp'),
          canDel ? btn('删除', 'btn-ghost btn-sm', async function () {
            if (!(await confirmBox('删除消息', '这条消息会从这个会话里消失，之后会留下一条系统提示。', true))) return;
            try {
              await api('messages/' + msg.id, { op: 'delete' });
              toast('已删除');
              paint();
            } catch (e) { toast(e.message, 'err'); }
          }, 'fa-trash-can') : null,
        ]),
        div('conv-msg-text', [document.createTextNode(adminMsgText(msg))]),
      ]));
    });
    if (!msgs.length) list.append(div('adm-empty', [icon('fa-comments'), el('div', { text: '这个会话还没有消息' })]));
    box.append(card('消息', [info, list], { icon: 'fa-comments' }));
  }
  await paint();
}

// ---------------------------------------------------------------- 站点设置

async function renderSettings(box) {
  box.append(loading());
  const d = await api('settings');
  const st = d.settings || {};
  box.innerHTML = '';
  box.append(pageHead('站点设置', '站点名、默认主题、注册配额；OAuth 参数请到「认证 → OAuth 登录」里配'));

  const fName = textInput('site.name', st['site.name'] || '');
  const fIntro = textInput('site.intro', st['site.intro'] || '');
  const fUrl = textInput('site.url', st['site.url'] || '', 'https://example.com');
  const fTheme = selectInput('theme', [
    { value: 'auto', label: '跟随系统' }, { value: 'light', label: '浅色' }, { value: 'dark', label: '深色' },
  ], st['theme'] || 'auto');
  // 站点默认主题（换色）＝ settings.theme.preset，兼容内建主题与共享主题包；
  // 生效顺序是「我选的 → 本群默认 → 站点默认 → 湖蓝」，这一项决定没登录过的访客第一眼
  const tj = await api('themes').catch(function () { return null; });
  const presetOpts = [{ value: '', label: '内置默认（湖蓝）' }]
    .concat(((tj && tj.builtin) || []).map(function (b) { return { value: b.id, label: b.name }; }))
    .concat(((tj && tj.packs) || []).map(function (p) { return { value: p.id, label: p.name + '（共享）' }; }));
  const fPreset = selectInput('theme.preset', presetOpts, st['theme.preset'] || '');
  const fAvatar = textInput('avatar.default', st['avatar.default'] || '', '默认头像图片地址，可空');
  const fHuman = textInput('quota.human_total', st['quota.human_total'] || '0', '0 不限', 'number');
  const fAi = textInput('quota.ai_total', st['quota.ai_total'] || '0', '0 不限', 'number');
  const fAiPer = textInput('quota.ai_per_human', st['quota.ai_per_human'] || '0', '0 不限', 'number');
  const fAcc = textInput('quota.account_per_oauth', st['quota.account_per_oauth'] || '0', '0 不限', 'number');
  const fPgMember = textInput('pg.member_cap', st['pg.member_cap'] || '0', '0 不限', 'number');
  const fPgSize = textInput('pg.size_cap', st['pg.size_cap'] || '0', '0 不限', 'number');
  const pruneInfo = d.prune || {};
  const fKeepPage = textInput('msg.page_size', String(pruneInfo.page_size || st['msg.page_size'] || '20'), '1-100，默认 20', 'number');
  const fMsgLocal = el('input', { type: 'checkbox', checked: String(st['msg.local'] || '1') !== '0' });
  const fMsgMode = selectInput('msg.mode', [
    { value: 'server', label: '由站点下发' },
    { value: 'keep', label: '消息保留' },
  ], String(st['msg.mode'] || 'server'));

  const form = el('form', { class: 'adm-form' }, [
    div('form-grid', [
      field('站点名', fName, '显示在浏览器标题与页脚'),
      field('外观', fTheme),
      field('站点默认主题', fPreset, '决定新访客的配色，成员自己的选择优先'),
      div('span2', [field('站点简介', fIntro)]),
      div('span2', [field('站点地址', fUrl, '填对外域名，OAuth 回调地址会优先用它；留空则用当前访问域名')]),
      div('span2', [field('默认头像', fAvatar)]),
    ]),
  ]);
  box.append(card('基本信息', [form], { icon: 'fa-globe' }));

  const quotaForm = el('form', { class: 'adm-form' }, [
    div('form-grid', [
      field('人类成员总数上限', fHuman, '0 表示不限'),
      field('AI 成员总数上限', fAi, '0 表示不限'),
      field('每人可创建的 AI 数', fAiPer, '0 表示不限'),
      field('每个 OAuth 身份可建账号数', fAcc, '0 表示不限'),
    ]),
  ]);
  box.append(card('注册配额', [quotaForm, div('hint', [el('p', { text: '配额在注册时校验，改小不会踢掉已有成员。' })])], { icon: 'fa-gauge' }));

  // 私群配额：站点的数是兜底，群级配置非 0 时以群为准
  const pgForm = el('form', { class: 'adm-form' }, [
    div('form-grid', [
      field('每人可创建的私群数', fPgMember, '0 表示不限；群级配置优先'),
      field('单个私群人数上限', fPgSize, '0 表示不限；群级配置优先'),
    ]),
  ]);
  box.append(card('私群配额', [pgForm, div('hint', [el('p', { text: '私群是成员自己拉的群，不会出现在管理员会话列表里，但后台审计能看到。' })])], { icon: 'fa-users-viewfinder' }));

  // ---- 消息存储：两套策略二选一，界面跟着模式换；消息一条都不删 ----
  const serverBox = div('form-grid', [
    field('每次加载条数', fKeepPage, '成员点「加载更多消息」才会继续往前翻'),
  ]);
  const localBox = div('oauth-switches', [
    el('label', { class: 'switch' }, [fMsgLocal, el('span', { text: '允许成员把消息留一份在自己设备上' })]),
  ]);
  function paintMode() {
    const keep = fMsgMode.value === 'keep';
    serverBox.style.display = keep ? 'none' : '';
    localBox.style.display = keep ? '' : 'none';
  }
  fMsgMode.addEventListener('change', paintMode);
  const pruneForm = el('form', { class: 'adm-form' }, [
    div('form-grid', [field('存储方式', fMsgMode, '由站点下发=按页拉取、可选懒加载；消息保留=只发新增、存在成员设备上')]),
    serverBox,
    localBox,
  ]);
  paintMode();
  box.append(card('消息存储', [
    pruneForm,
    div('hint', [
      el('p', { class: 'hint-p', text: '当前 ' + (pruneInfo.messages || 0) + ' 条消息' }),
      el('p', { text: '消息只增不删：站点不按时间或条数删消息，切回「由站点下发」也不会缺历史。' }),
      el('p', { text: '由站点下发：打开会话先拉一页，成员点「加载更多消息」才继续往前翻。' }),
      el('p', { text: '消息保留：站点只发新消息，历史留在成员设备上。' }),
    ]),
  ], { icon: 'fa-box-archive' }));

  // ---- 文件传输：群文件与私聊附件共用这套站点策略 ----
  const fFileOn = el('input', { type: 'checkbox', checked: String(st['file.enabled'] || '1') !== '0' });
  const fFileMax = textInput('file.max_mb', st['file.max_mb'] || '50', '1-2048，默认 50', 'number');
  const fFileAtt = textInput('file.attachments', st['file.attachments'] || '3', '1-9，默认 3', 'number');
  const fFileKeep = textInput('file.keep_days', st['file.keep_days'] || '180', '1-3650，默认 180', 'number');
  const fFileQuota = textInput('file.quota_mb', st['file.quota_mb'] || '4096', '0 表示不限', 'number');
  const fileUse = d.file_usage || {};
  const useText = '当前已用 ' + (fileUse.files || 0) + ' 个文件，'
    + ((fileUse.bytes || 0) / 1048576).toFixed(1) + ' MB';
  const fFileStore = selectInput('file.storage', [
    { value: 'local', label: '本地存储（数据目录 files/）' },
    { value: 'db', label: '二进制入库' },
    { value: 's3', label: 'S3 对象存储' },
  ], String(st['file.storage'] || 'local'));
  const fS3Endpoint = textInput('file.s3.endpoint', st['file.s3.endpoint'] || '', 'https://<account>.r2.cloudflarestorage.com');
  const fS3Bucket = textInput('file.s3.bucket', st['file.s3.bucket'] || '', '桶名');
  const fS3Region = textInput('file.s3.region', st['file.s3.region'] || 'auto', 'R2 填 auto，AWS 填所在区域');
  const fS3Key = textInput('file.s3.key', st['file.s3.key'] || '', 'Access Key ID');
  const fS3Secret = textInput('file.s3.secret', '', d.has_file_s3_secret ? '已经存过，留空不修改' : 'Secret Access Key', 'password');
  const fS3Prefix = textInput('file.s3.prefix', st['file.s3.prefix'] || '', '可选，例如 lightgroup');
  const fileWarn = el('p', {
    class: 'field-hint',
    style: 'color:#b91c1c;margin:0',
    text: '二进制入库会把整段文件写进数据库，消息表会明显变慢：文件上限必须压到 8MB 以内。',
  });
  const s3Box = div('form-grid', [
    div('span2', [field('Endpoint', fS3Endpoint)]),
    field('Bucket', fS3Bucket),
    field('Region', fS3Region),
    field('Access Key', fS3Key),
    field('Secret Key', fS3Secret),
    div('span2', [field('对象前缀（可选）', fS3Prefix)]),
  ]);
  function paintFileStore() {
    const v = fFileStore.value;
    fileWarn.style.display = v === 'db' ? '' : 'none';
    s3Box.style.display = v === 's3' ? '' : 'none';
  }
  fFileStore.addEventListener('change', paintFileStore);
  paintFileStore();
  const fileForm = el('form', { class: 'adm-form' }, [
    div('oauth-switches', [
      el('label', { class: 'switch' }, [fFileOn, el('span', { text: '开放文件传输' })]),
    ]),
    div('form-grid', [
      field('单个文件上限（MB）', fFileMax, '1-2048，默认 50'),
      field('一条消息最多几个附件', fFileAtt, '1-9，默认 3'),
      field('文件保留天数', fFileKeep, '到期自动清理，清理后消息里只剩占位；0 表示永久保留（不推荐，会占满磁盘）'),
      field('站点文件总容量（MB）', fFileQuota, '0 表示不限；用满后拒收新文件，不删旧文件'),
    ]),
    div('hint', [el('p', { text: useText })]),
    div('form-grid', [field('存储方式', fFileStore)]),
    fileWarn,
    s3Box,
  ]);
  box.append(card('文件传输', [
    fileForm,
    div('hint', [
      el('p', { text: '群文件与私聊附件共用这套设置；文本超过一万字时客户端会转成 txt 文件再发。' }),
      el('p', { text: '下载走 /g/api/file/<id>，只有同群、且在这个会话里的人能取。' }),
    ]),
  ], { icon: 'fa-paperclip' }));

  // ---- 语音转文字：站内没有识别引擎，得把音频交给外部 OpenAI 兼容接口 ----
  const fSttOn = el('input', { type: 'checkbox', checked: String(st['stt.enabled'] || '0') === '1' });
  const fSttUrl = textInput('stt.url', st['stt.url'] || '', 'https://api.openai.com/v1/audio/transcriptions');
  const fSttKey = textInput('stt.key', '', d.has_stt_key ? '已经存过，留空不修改' : '服务商的 Key', 'password');
  const fSttModel = textInput('stt.model', st['stt.model'] || '', '留空用 whisper-1');
  const saveStt = btn('保存语音设置', 'btn-primary', async function () {
    try {
      await api('settings', {
        'stt.enabled': fSttOn.checked ? '1' : '0',
        'stt.url': fSttUrl.value.trim(),
        'stt.key': fSttKey.value,
        'stt.model': fSttModel.value.trim(),
      });
      toast('语音设置已保存');
      go('settings');
    } catch (e) { toast(e.message, 'err'); }
  }, 'fa-floppy-disk');

  box.append(card('语音转文字', [
    el('form', { class: 'adm-form' }, [
      div('oauth-switches', [
        el('label', { class: 'switch' }, [fSttOn, el('span', { text: '允许成员把语音转成文字' })]),
      ]),
      div('form-grid', [
        div('span2', [field('识别接口地址', fSttUrl, 'OpenAI 兼容的转写接口，例如 https://api.openai.com/v1/audio/transcriptions')]),
        field('服务商 Key', fSttKey, '留空就沿用已经存的那个'),
        field('模型名', fSttModel, '留空用 whisper-1'),
      ]),
      div('hint', [el('p', {
        text: d.stt_ready
          ? '已就绪：成员点语音条下方的「转文字」，文字会存进那条消息，同一条只转一次。'
          : '还没就绪：开关打开、接口地址填上才会生效。',
      })]),
    ]),
    div('form-foot', [saveStt]),
  ], { icon: 'fa-align-left' }));

  const mcpHint = d.mcp_url
    ? 'AI 那边填「' + d.mcp_url + '?k=<它自己的 token>」。'
    : '';
  box.append(card('MCP 端点', [
    div('form-grid', [div('span2', [field('端点', el('input', { value: d.mcp_url || '', disabled: true, readOnly: true }), mcpHint)])]),
    div('hint', [el('p', { text: 'MCP 是否可用由「AI 与适配器」里的开关决定。' })]),
  ], { icon: 'fa-plug' }));

  // ---- AI 与适配器：只有这一片是 AI 工作台的开关，别的卡不再混在一起 ----
  const aiDefs = [
    ['ai', '启用 AI 账号'],
    ['adapter.xsj', '小群（xsj）适配器'],
    ['adapter.mcp', 'MCP 适配器'],
    ['adapter.api', 'REST（api）适配器'],
  ];
  const accountDefs = [
    ['register', '允许新用户自助注册'],
  ];
  const spaceDefs = [
    ['space', '启用空间'],
    ['space.post', '允许发动态'],
    ['space.like', '允许点赞'],
    ['space.comment', '允许评论'],
  ];
  const socialDefs = [
    ['social.friend', '允许加好友'],
    ['social.dm', '允许私聊'],
    ['social.scan', '扫一扫'],
    ['social.qr', '个人与群二维码'],
    ['social.discover', '发现'],
  ];
  const featCbs = {};
  function featSwitches(defs) {
    return defs.map(function (d) {
      const cb = el('input', { type: 'checkbox', checked: String(st['feature.' + d[0]] || '1') !== '0' });
      featCbs[d[0]] = cb;
      return el('label', { class: 'switch' }, [cb, el('span', { text: d[1] })]);
    });
  }
  const fTransport = selectInput('feature.mcp.transport', [
    { value: 'both', label: 'SSE 与 HTTP Stream' },
    { value: 'stream', label: '只接受 HTTP Stream' },
    { value: 'sse', label: '只接受 SSE' },
  ], st['feature.mcp.transport'] || 'both');
  function saveFeatBtn(defs, withTransport) {
    return btn('保存', 'btn-primary', async function () {
      const body = {};
      defs.forEach(function (d) { body['feature.' + d[0]] = featCbs[d[0]].checked ? '1' : '0'; });
      if (withTransport) body['feature.mcp.transport'] = fTransport.value;
      try {
        await api('settings', body);
        toast('已保存');
      } catch (e) { toast(e.message, 'err'); }
    }, 'fa-floppy-disk');
  }
  box.append(card('AI 与适配器', [
    div('adm-switch-grid', featSwitches(aiDefs)),
    div('form-grid', [div('span2', [field('MCP 连接方式', fTransport, '按客户端支持的连接方式来选')])]),
    div('hint', [el('p', { text: 'AI 总开关关掉后，已创建的 AI 账号一律不可用。' })]),
    div('form-foot', [saveFeatBtn(aiDefs, true)]),
  ], { icon: 'fa-robot' }));
  box.append(card('账号与注册', [
    div('adm-switch-grid', featSwitches(accountDefs)),
    div('form-foot', [saveFeatBtn(accountDefs, false)]),
  ], { icon: 'fa-user-plus' }));
  box.append(card('空间', [
    div('adm-switch-grid', featSwitches(spaceDefs)),
    div('form-foot', [saveFeatBtn(spaceDefs, false)]),
  ], { icon: 'fa-compass' }));
  box.append(card('社交', [
    div('adm-switch-grid', featSwitches(socialDefs)),
    div('form-foot', [saveFeatBtn(socialDefs, false)]),
  ], { icon: 'fa-user-group' }));

  box.append(card('保存', [
    btn('保存全部设置', 'btn-primary', async function () {
      try {
        await api('settings', {
          'site.name': fName.value.trim(),
          'site.intro': fIntro.value.trim(),
          'site.url': fUrl.value.trim(),
          'theme': fTheme.value,
          'theme.preset': fPreset.value,
          'avatar.default': fAvatar.value.trim(),
          'quota.human_total': fHuman.value.trim() || '0',
          'quota.ai_total': fAi.value.trim() || '0',
          'quota.ai_per_human': fAiPer.value.trim() || '0',
          'quota.account_per_oauth': fAcc.value.trim() || '0',
          'pg.member_cap': fPgMember.value.trim() || '0',
          'pg.size_cap': fPgSize.value.trim() || '0',
          'msg.mode': fMsgMode.value,
          'msg.page_size': fKeepPage.value.trim() || '20',
          'msg.local': fMsgLocal.checked ? '1' : '0',
          'file.enabled': fFileOn.checked ? '1' : '0',
          'file.max_mb': fFileMax.value.trim() || '50',
          'file.attachments': fFileAtt.value.trim() || '3',
          'file.keep_days': fFileKeep.value.trim() || '180',
    'file.quota_mb': fFileQuota.value.trim() || '4096',
          'file.storage': fFileStore.value,
          'file.s3.endpoint': fS3Endpoint.value.trim(),
          'file.s3.bucket': fS3Bucket.value.trim(),
          'file.s3.region': fS3Region.value.trim() || 'auto',
          'file.s3.key': fS3Key.value.trim(),
          'file.s3.secret': fS3Secret.value,
          'file.s3.prefix': fS3Prefix.value.trim(),
        });
        toast('设置已保存');
        go('settings');
      } catch (e) { toast(e.message, 'err'); }
    }, 'fa-floppy-disk'),
  ], { icon: 'fa-floppy-disk' }));
}


// ---------------------------------------------------------------- 更新检查

/** 拉一次更新清单；失败不抛，交给页面显示 */
async function updateInfo() {
  return api('update');
}

function updateStateBadge(d) {
  if (d.error) return el('span', { class: 'adm-update-badge err', text: '检查失败' });
  if (d.has_update) return el('span', { class: 'adm-update-badge new', text: '有新版本' });
  return el('span', { class: 'adm-update-badge ok', text: '已是最新' });
}

/** 真正执行更新：拉 GitHub 最新 release 的源码包覆盖代码（服务端只放行超管） */
async function doUpdate(ver) {
  const yes = await confirmBox(
    '立即更新到 v' + ver,
    '会从 GitHub 拉源码包覆盖本站代码。config.php、data、上传的图片、favicon 都不会被覆盖；被替换的旧文件先备份到 data/backup/。更新完成后页面自动重载。',
    false
  );
  if (!yes) return;
  toast('正在下载并覆盖，别关页面…');
  try {
    const r = await api('update', {});
    if (!r || !r.ok) { toast('更新失败：' + ((r && r.err) || '网络错误'), 'err'); return; }
    if (!r.updated) { toast('已经是最新版本'); return; }
    toast('已更新到 ' + r.version);
    setTimeout(function () { location.reload(); }, 800);
  } catch (e) {
    toast('更新失败：' + (e.message || '网络错误'), 'err');
  }
}

async function renderUpdate(box) {
  box.append(pageHead('更新检查',
    '有新版本就能一键升级，站点的配置、数据和上传的文件都会留着',
    [btn('检查更新', 'btn-primary', function () { go('update'); }, 'fa-rotate')]));

  const wrap = div('adm-update-page');
  box.append(wrap);

  let d;
  try {
    wrap.append(loading());
    d = await updateInfo();
  } catch (e) {
    wrap.innerHTML = '';
    wrap.append(card('更新检查', [div('adm-error', [
      icon('fa-triangle-exclamation'),
      el('div', { text: e.message || '检查失败' }),
    ])], { icon: 'fa-cloud-arrow-down' }));
    return;
  }
  wrap.innerHTML = '';

  const latest = d.latest || null;
  const cur = el('div', { class: 'adm-update-ver' }, [
    el('div', { class: 'adm-update-k', text: '当前版本' }),
    el('div', { class: 'adm-update-v', text: d.current || '-' }),
    el('div', { class: 'adm-update-t', text: '站上正在跑的版本' }),
  ]);
  const nv = el('div', { class: 'adm-update-ver' }, [
    el('div', { class: 'adm-update-k', text: '最新版本' }),
    el('div', { class: 'adm-update-v', text: latest ? latest.version : '—' }),
    el('div', { class: 'adm-update-t', text: latest && latest.released_at ? '发布于 ' + latest.released_at : (d.error || '暂时读不到') }),
  ]);
  wrap.append(div('adm-update-hero', [cur, nv, div('adm-update-ver adm-update-state', [
    el('div', { class: 'adm-update-k', text: '状态' }),
    div('', [updateStateBadge(d)]),
    el('div', { class: 'adm-update-t', text: d.has_update ? '可以升级了' : '不需要升级' }),
  ])]));

  const acts = [];
  if (d.has_update && latest) {
    acts.push(btn('立即更新到 ' + latest.version, 'btn-primary', function () { doUpdate(latest.version); }, 'fa-cloud-arrow-down'));
  }
  if (latest && latest.url) {
    acts.push(btn('查看更新记录', d.has_update ? 'btn-ghost' : 'btn-primary', function () { window.open(latest.url, '_blank', 'noopener'); }, 'fa-arrow-up-right-from-square'));
  }
  if (d.error) {
    acts.push(btn('重试', 'btn-ghost', function () { go('update'); }, 'fa-rotate'));
  }

  if (latest && latest.notes) {
    wrap.append(card('更新说明', [
      el('p', { class: 'adm-update-notes', text: latest.notes }),
      acts.length ? div('adm-update-acts', acts) : null,
    ], { icon: 'fa-file-lines' }));
  } else if (acts.length) {
    wrap.append(card('更新', [div('adm-update-acts', acts)], { icon: 'fa-cloud-arrow-down' }));
  }

  if (d.error) {
    wrap.append(div('adm-error', [icon('fa-circle-exclamation'), el('div', { text: d.error })]));
  }
}

/** 打开后台时静默查一次：有新版本就从右上角滑进来一张提示卡 */
async function maybeUpdateToast() {
  try {
    const d = await updateInfo();
    if (!d.has_update || !d.latest) return;
    if (localStorage.getItem('lg.adm.skip_version') === d.latest.version) return;
    showUpdatePop(d);
  } catch (e) {
    // 后台首屏不该因为更新检查失败而弹东西
  }
}

function showUpdatePop(d) {
  const v = d.latest;
  const old = document.querySelector('.adm-update-pop');
  if (old) old.remove();

  const pop = div('adm-update-pop');
  let gone = false;
  function close() {
    if (gone) return;
    gone = true;
    pop.classList.remove('in');
    setTimeout(function () { pop.remove(); }, 280);
  }
  pop.append(div('adm-update-pop-head', [
    div('adm-update-pop-title', [icon('fa-cloud-arrow-down'), el('span', { text: '新版本可用' })]),
    el('button', { class: 'adm-update-pop-x', type: 'button', 'aria-label': '关闭', on: { click: close } }, [icon('fa-xmark')]),
  ]));
  pop.append(div('adm-update-pop-body', [
    el('div', { class: 'adm-update-line' }, [el('span', { text: '版本：' }), el('b', { text: v.version })]),
    v.released_at ? el('div', { class: 'adm-update-line' }, [el('span', { text: '发布日期：' }), el('span', { text: v.released_at })]) : null,
    v.notes ? el('div', { class: 'adm-update-notes', text: v.notes }) : null,
  ]));
  pop.append(div('adm-update-pop-foot', [
    btn('立即更新', 'btn-primary', function () { close(); doUpdate(v.version); }, 'fa-cloud-arrow-down'),
    btn('查看详情', 'btn-ghost', function () {
      if (v.url) window.open(v.url, '_blank', 'noopener');
      else go('update');
    }, 'fa-arrow-up-right-from-square'),
    el('button', {
      class: 'adm-update-skip', type: 'button',
      on: { click: function () { localStorage.setItem('lg.adm.skip_version', v.version); close(); } },
    }, [document.createTextNode('不再提示此版本')]),
  ]));
  document.body.append(pop);
  requestAnimationFrame(function () { pop.classList.add('in'); });
}

// ---------------------------------------------------------------- 启动

async function boot() {
  try {
    const o = await api('overview');
    S.overview = o;
    S.role = o.role || '';
    S.me = o.me || null;
    S.scopes = o.scopes || [];
    buildShell();
    const want = (location.hash || '').replace('#', '');
    const route = splitPath(want);
    go(RENDERERS[route.tab] ? route.tab : 'dashboard', route.arg);
    // 首屏渲染完再查更新，查到之后从右上角滑进来，不挡任何操作
    maybeUpdateToast();
    window.addEventListener('hashchange', function () {
      const t = (location.hash || '').replace('#', '');
      if (!t) return;
      const route = splitPath(t);
      if (route.tab !== S.tab || route.arg !== S.arg) go(RENDERERS[route.tab] ? route.tab : 'dashboard', route.arg);
    });
  } catch (e) {
    const msg = e.message || '';
    if (/登录|权限/.test(msg)) renderLogin(msg);
    else renderFatal(msg);
  }
}

function renderFatal(msg) {
  const adm = document.getElementById('adm');
  adm.innerHTML = '';
  adm.classList.add('adm-ready');
  adm.append(div('adm-login', [
    div('card', [div('card-body', [
      div('adm-error', [icon('fa-triangle-exclamation'), el('div', { text: msg || '后台加载失败' })]),
      btn('重试', 'btn-primary', function () { location.reload(); }, 'fa-rotate'),
      el('p', { class: 'field-hint', text: '如果提示数据库错误，请检查 data/ 目录与 config.php。' }),
    ])]),
  ]));
}

boot();
