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

async function api(path, body) {
  const opt = { method: body === undefined ? 'GET' : 'POST', headers: {}, credentials: 'same-origin' };
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
  mask.addEventListener('click', function (e) { if (e.target === mask) close(); });
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
    { id: 'clusters', icon: 'fa-circle-nodes', text: '互通圈子', superOnly: true },
  ] },
  { group: '成员', items: [ { id: 'members', icon: 'fa-users', text: '群成员' } ] },
  { group: '认证', items: [
    { id: 'accounts', icon: 'fa-user-shield', text: '管理员账号', superOnly: true },
    { id: 'oauth', icon: 'fa-right-to-bracket', text: 'OAuth 登录', superOnly: true },
    { id: 'captcha', icon: 'fa-shield-halved', text: '人机验证', superOnly: true },
  ] },
  { group: '主题', items: [ { id: 'themes', icon: 'fa-palette', text: '主题包' } ] },
  { group: '审计', items: [ { id: 'convs', icon: 'fa-magnifying-glass-chart', text: '会话审计' } ] },
  { group: '设置', items: [
    { id: 'settings', icon: 'fa-sliders', text: '站点设置', superOnly: true },
    { id: 'update', icon: 'fa-cloud-arrow-down', text: '更新检查' },
  ] },
];

const TITLES = {
  dashboard: '仪表盘',
  scopes: '群组管理',
  clusters: '互通圈子',
  members: '群成员',
  accounts: '管理员账号',
  oauth: 'OAuth 登录',
  captcha: '人机验证',
  themes: '主题包',
  convs: '会话审计',
  settings: '站点设置',
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

function setCrumb(tab) {
  const c = document.getElementById('adm-crumb');
  if (!c) return;
  c.innerHTML = '';
  c.append(el('span', { class: 'adm-crumb-root', text: '管理后台' }));
  c.append(icon('fa-chevron-right', 'adm-crumb-sep'));
  c.append(el('b', { text: TITLES[tab] || tab }));
}

const RENDERERS = {
  dashboard: renderDashboard,
  scopes: renderScopes,
  clusters: renderClusters,
  members: renderMembers,
  accounts: renderAccounts,
  oauth: renderOAuth,
  captcha: renderCaptcha,
  themes: renderThemes,
  convs: renderConvs,
  settings: renderSettings,
  update: renderUpdate,
};

async function go(tab) {
  if (!TITLES[tab]) tab = 'dashboard';
  S.tab = tab;
  document.querySelectorAll('.adm-nav-item').forEach(function (a) {
    a.classList.toggle('on', a.getAttribute('data-tab') === tab);
  });
  setCrumb(tab);
  if (location.hash !== '#' + tab) history.replaceState(null, '', '#' + tab);
  const body = document.getElementById('adm-body');
  if (!body) return;
  body.innerHTML = '';
  body.scrollTop = 0;
  const wrap = div('adm-wrap', []);
  body.append(wrap);
  try {
    await RENDERERS[tab](wrap);
  } catch (e) {
    wrap.innerHTML = '';
    wrap.append(div('card', [div('card-body', [
      div('adm-error', [icon('fa-triangle-exclamation'), el('div', { text: e.message || '加载失败' })]),
      btn('重试', 'btn-ghost', function () { go(tab); }, 'fa-rotate'),
    ])]));
  }
}

// ---------------------------------------------------------------- 登录

function renderLogin(msg) {
  const adm = document.getElementById('adm');
  adm.innerHTML = '';
  adm.classList.add('adm-ready');
  const err = div('login-err', msg && msg !== '请先登录' ? [el('span', { text: msg })] : []);
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
    el('p', { text: '主群（slug 为空）是安装时创建的默认群，地址就是 /g/。其它群地址形如 /g/<slug>/。' }),
    el('p', { text: '归档后群从列表里隐藏，数据保留；成员的登录与消息不会丢。' }),
  ])], { icon: 'fa-circle-info' }));
}

function newScopeDialog() {
  const f = el('form', { class: 'adm-form' }, [
    div('form-grid', [
      field('群名', textInput('title', '', '例如：夜航船')),
      field('Slug（可空）', textInput('slug', '', '例如：night')),
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
  const f = el('form', { class: 'adm-form' }, [
    div('form-grid', [
      field('群名', textInput('title', s.title)),
      field('简介', textInput('intro', s.intro || '')),
      field('人类上限', textInput('human_cap', String(s.human_cap), '0 不限', 'number')),
      field('AI 上限', textInput('ai_cap', String(s.ai_cap), '0 不限', 'number')),
      field('撤回策略', selectInput('recall_policy', [
        { value: '5m', label: '5 分钟内可撤回' },
        { value: 'until_other', label: '对方未读可撤回' },
        { value: 'forever', label: '始终可撤回' },
      ], s.recall_policy)),
    ]),
  ]);
  const m = modal('编辑群组 · ' + (s.title || ''), [f], [
    btn('取消', 'btn-ghost', function () { m.close(); }),
    btn('保存', 'btn-primary', async function () {
      try {
        await api('scopes/' + s.id, {
          title: formValue(f, 'title'),
          intro: formValue(f, 'intro'),
          human_cap: formValue(f, 'human_cap') || '0',
          ai_cap: formValue(f, 'ai_cap') || '0',
          recall_policy: formValue(f, 'recall_policy'),
        });
        m.close(); toast('群组已保存'); go('scopes');
      } catch (e) { toast(e.message, 'err'); }
    }, 'fa-check'),
  ]);
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
    ? '账号存在 accounts 表；门禁开着，新增管理员时要一并填 OAuth 归属（提供方 + sub）'
    : '账号存在 accounts 表；每个账号在群里有一条成员身份，登录后显示对应 badge', [
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
      status.append(el('div', { class: 'discover-err', text: '请先填 Basic URL，例如 https://accounts.google.com' }));
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
      field('Client Secret', fSecret, '只写不读；留空表示沿用已保存的值'),
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
  const keyField = field('Secret Key', secret, '只写不读；留空表示沿用已保存的值');
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

async function renderConvs(box) {
  box.append(loading());
  const d = await api('convs');
  box.innerHTML = '';
  box.append(pageHead('会话审计', '管理员的查看会留下系统提示（同一会话一分钟内只记一次）'));

  const rows = (d.convs || []).map(function (c) {
    return [
      el('td', {}, [div('cell-name', [
        el('b', { text: c.title || ('会话 #' + c.id) }),
        c.isPublic ? el('span', { class: 'badge admin', text: '群聊' }) : el('span', { class: 'badge muted', text: '私聊' }),
      ])]),
      el('td', { class: 'muted', text: c.scopeTitle }),
      el('td', { class: 'num', text: String(c.msgCount) }),
      el('td', { class: 'muted', text: c.lastAt ? ago(c.lastAt) : '—' }),
      el('td', {}, [btn('查看', 'btn-ghost btn-sm', function () { viewConv(c); }, 'fa-eye')]),
    ];
  });

  box.append(card('最近 200 个会话', [table(['会话', '群组', '消息数', '最后活跃', '操作'], rows, '还没有会话', 'fa-comments')], {
    icon: 'fa-magnifying-glass-chart', flush: true,
  }));
}

/** 会话审计里的一条消息预览：没有文字不等于撤回，语音/卡片/图片各自有标签 */
function adminMsgText(msg) {
  if (!msg) return '';
  if (msg.recalled) return '（已撤回）';
  const t = String(msg.text || '').trim();
  if (t) return t;
  if (msg.img) return '[图片]';
  if (msg.voice) return msg.dur ? '[语音 ' + msg.dur + '"]' : '[语音]';
  if (msg.card) return '[卡片]';
  return '[消息]';
}

async function viewConv(c) {
  const body = div('conv-view', [loading()]);
  const m = modal((c.isPublic ? '群聊' : '私聊') + ' · ' + (c.title || ''), [body], [btn('关闭', 'btn-ghost', function () { m.close(); })]);
  try {
    const d = await api('convs/' + c.id);
    body.innerHTML = '';
    const info = div('conv-info', [
      el('span', { class: 'muted', text: (d.conv.members || []).map(function (x) { return x.name; }).join(' · ') }),
      el('span', { class: 'muted', text: '共 ' + (d.msgs || []).length + ' 条' }),
    ]);
    body.append(info);
    const list = div('conv-msgs', []);
    (d.msgs || []).forEach(function (msg) {
      list.append(div('conv-msg' + (msg.sys ? ' sys' : ''), [
        div('conv-msg-head', [
          el('b', { text: msg.name || '?' }),
          msg.kind === 'ai' ? badge('ai') : null,
          el('span', { class: 'muted', text: fmtDT(msg.ts) }),
        ]),
        div('conv-msg-text', [document.createTextNode(adminMsgText(msg))]),
      ]));
    });
    if (!(d.msgs || []).length) list.append(div('adm-empty', [icon('fa-comments'), el('div', { text: '这个会话还没有消息' })]));
    body.append(list);
  } catch (e) {
    body.innerHTML = '';
    body.append(div('adm-error', [icon('fa-triangle-exclamation'), el('div', { text: e.message })]));
  }
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

async function renderUpdate(box) {
  box.append(pageHead('更新检查',
    '清单是仓库里的 api/manifest.json，从 GitHub 取；取不到会自动改用 jsDelivr。当前版本取自安装时写入的 .lock',
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
    el('div', { class: 'adm-update-t', text: '站点 .lock 里记录的版本' }),
  ]);
  const nv = el('div', { class: 'adm-update-ver' }, [
    el('div', { class: 'adm-update-k', text: '最新版本' }),
    el('div', { class: 'adm-update-v', text: latest ? latest.version : '—' }),
    el('div', { class: 'adm-update-t', text: latest && latest.released_at ? '发布于 ' + latest.released_at : (d.error || '还没读到清单') }),
  ]);
  wrap.append(div('adm-update-hero', [cur, nv, div('adm-update-ver adm-update-state', [
    el('div', { class: 'adm-update-k', text: '状态' }),
    div('', [updateStateBadge(d)]),
    el('div', { class: 'adm-update-t', text: d.has_update ? '可以升级了' : '不需要升级' }),
  ])]));

  if (latest && latest.notes) {
    wrap.append(card('更新说明', [el('p', { class: 'adm-update-notes', text: latest.notes })], { icon: 'fa-file-lines' }));
  }

  const acts = [];
  if (latest && latest.url) {
    acts.push(btn('查看详情', 'btn-primary', function () { window.open(latest.url, '_blank', 'noopener'); }, 'fa-arrow-up-right-from-square'));
  }
  if (d.error) {
    acts.push(btn('重试', 'btn-ghost', function () { go('update'); }, 'fa-rotate'));
  }
  wrap.append(card('清单地址', [
    el('p', { class: 'adm-update-url', text: d.manifest_url || '' }),
    el('p', { class: 'field-hint', text: d.source === 'jsdelivr' ? 'GitHub 没取到，这次用的是 jsDelivr 的镜像。' : '取自 GitHub 仓库（raw.githubusercontent.com）。' }),
    acts.length ? div('adm-update-acts', acts) : null,
  ], { icon: 'fa-link' }));

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
    btn('查看详情', 'btn-primary', function () {
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
    const known = Object.keys(TITLES);
    go(known.indexOf(want) >= 0 ? want : 'dashboard');
    // 首屏渲染完再查更新，查到之后从右上角滑进来，不挡任何操作
    maybeUpdateToast();
    window.addEventListener('hashchange', function () {
      const t = (location.hash || '').replace('#', '');
      if (t && t !== S.tab) go(t);
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
