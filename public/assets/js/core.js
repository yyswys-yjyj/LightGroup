// LightGroup · 前端核心（原生 ES module，零构建）
// 全局状态 / 接口 / DOM 小工具 / toast / 弹层 / hash 路由

export const BOOT = window.__LG_BOOT__ || {};
export const BASE = BOOT.base || '/g/';

// 站内页面地址由服务端按部署环境生成（有的服务器没有 rewrite，得带 .php）
export const URLS = BOOT.urls || { app: '/app', login: '/login', logout: '/logout', admin: '/admin' };
export const LSK = (k) => 'lg.' + k;

export const S = {
  me: null,
  members: [],
  memberSig: '',
  convs: [],
  convsReady: false,
  convApi: false,       // 会话接口（§4）是否可用；不可用时退回 api/fetch 公开大群
  curId: 0,
  cur: null,
  mode: 'legacy',       // conv | legacy
  msgs: [],
  lastId: 0,
  reply: null,
  notice: null,
  pins: [],
  todos: [],
  gcss: '',
  css: '',
  theme: localStorage.getItem(LSK('theme')) || '',
  themeMode: 'auto',      // 深浅模式：auto | light | dark
  themeUserPicked: false, // 用户是否显式点过深浅（安装器默认的 light 不算）
  themeSaved: '',         // 服务端最近一次确认过的编码值
  themeChoice: '',      // 我选的主题（内建 id 或 p_N）
  themePacks: [],       // 本群可见的主题包
  scopeTheme: '',       // 本群默认主题
  siteTheme: '',        // 站点默认主题
  wall: localStorage.getItem(LSK('wall')) || '',
  frame: localStorage.getItem(LSK('frame')) || '',
  friends: { friends: [], incoming: [], outgoing: [] },
  page: 'chat',
  listOpen: true,
  search: '',
  polling: true,
  timer: 0,
  chatEl: null,
};

export function siteName() { return (BOOT.site && BOOT.site.name) || 'LightGroup'; }

// ---------- DOM 小工具 ----------
export function h(tag, cls, text) {
  const n = document.createElement(tag);
  if (cls) n.className = cls;
  if (text !== undefined && text !== null) n.textContent = String(text);
  return n;
}

export function icon(name) {
  const i = document.createElement('i');
  i.className = /\bfa-(solid|regular|brands)\b/.test(name) ? name : 'fa-solid ' + name;
  i.setAttribute('aria-hidden', 'true');
  return i;
}

export function iconBtn(ic, title, fn) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'icon-btn';
  b.title = title;
  b.setAttribute('aria-label', title);
  b.appendChild(icon(ic));
  b.addEventListener('click', fn);
  return b;
}

export function btn(text, cls, fn) {
  const b = document.createElement('button');
  b.type = 'button';
  // 调用方只传了 btn-primary / btn-sm 这类修饰类时也要带上基类，
  // 否则按钮拿到的是浏览器默认样式（凸起的那一套）
  const c = String(cls || '').trim();
  b.className = c === '' ? 'btn' : (/(^|\s)btn(\s|$)/.test(c) ? c : 'btn ' + c);
  b.textContent = text;
  if (fn) b.addEventListener('click', fn);
  return b;
}

export function copyNote(text) { return h('div', 'page-tip', text); }

export function field(parent, label, build) {
  const row = h('div', 'f-row');
  row.appendChild(h('label', '', label));
  build(row);
  parent.appendChild(row);
  return row;
}

// 站点图标：有自定义图标就用图标，没有就回退到内置靶心。外层统一是圆角方框蒙版。
export function siteMark(cls) {
  const mark = h('span', cls);
  const site = BOOT.site || {};
  const light = site.icon || '';
  const dark = site.icon_dark || '';
  if (!light && !dark) {
    mark.appendChild(icon('fa-bullseye'));
    return mark;
  }
  mark.classList.add('icon-mask');
  const imgL = document.createElement('img');
  imgL.className = 'i-light';
  imgL.src = light || dark;
  imgL.alt = '';
  mark.appendChild(imgL);
  const imgD = document.createElement('img');
  imgD.className = 'i-dark';
  imgD.src = dark || light;
  imgD.alt = '';
  mark.appendChild(imgD);
  return mark;
}

// 二级页通用头：返回 + 标题 +（可选）右侧按钮
export function pageHead(title, sub, acts) {
  const head = h('header', 'chat-head');
  const back = iconBtn('fa-chevron-left', '返回聊天', function () {
    setListOpen(true);
    go('#/c/' + (S.curId || 0));
  });
  back.classList.add('only-narrow');
  head.appendChild(back);
  const t = h('div', 'chat-title');
  t.appendChild(h('b', '', title));
  if (sub) t.appendChild(h('span', 'chat-sub', sub));
  head.appendChild(t);
  if (acts && acts.length) {
    const box = h('div', 'chat-acts');
    acts.forEach(function (a) { box.appendChild(a); });
    head.appendChild(box);
  }
  return head;
}

// ---------- 接口 ----------
// 登录态只认 cookie session（lgb，HttpOnly），前端不碰任何 token；
// 302 到 /login 或 {ok:false,err:"登录已失效…"} 都说明会话没了，统一跳登录页。
export function toLogin() {
  const next = location.pathname + location.search;
  location.replace('/login?next=' + encodeURIComponent(next));
}

function isAuthErr(j) {
  const e = String((j && j.err) || '');
  return e.indexOf('登录') >= 0 || e.indexOf('注销') >= 0 || e.indexOf('封禁') >= 0;
}

// 会话越界：登录身份属于别的群。这里绝不能跳登录页——已经登录的人跳过去，
// /login 看他已登录又按 next 弹回来，来回跳就成了死循环（用户报的「软封禁」）。
// 改成一次性提示，让人自己选回哪边。
let scopeWarned = false;
function showScopeMismatch(j) {
  if (scopeWarned) return;
  scopeWarned = true;
  const home = String(j.home || '/g/');
  const homeName = String(j.home_name || '我的群');
  const hereName = String(j.here_name || '这个群');
  const here = String(j.here || '');

  const wrap = document.createElement('div');
  wrap.className = 'sm-wrap';
  const card = document.createElement('div');
  card.className = 'sm-card';

  const ic = icon('fa-door-closed');
  ic.className = 'fa-solid fa-door-closed sm-ic';
  card.appendChild(ic);
  card.appendChild(h('h3', 'sm-title', '这里不是你的群'));
  card.appendChild(h('p', 'sm-text',
    '你现在是「' + homeName + '」的成员，这里是「' + hereName + '」。两个群的成员和消息各自分开，所以这个地址用不了。'));

  const acts = h('div', 'sm-acts');
  const a1 = h('a', 'btn btn-primary', '回到「' + homeName + '」');
  a1.href = home;
  acts.appendChild(a1);
  if (here) {
    const a2 = h('a', 'btn', '用这个群的邀请码进来');
    a2.href = '/login?switch=1&next=' + encodeURIComponent(here);
    acts.appendChild(a2);
  }
  card.appendChild(acts);
  wrap.appendChild(card);
  document.body.appendChild(wrap);
}

export async function api(path, body) {
  const url = BASE + path;
  const opt = { method: body === undefined ? 'GET' : 'POST', headers: {}, credentials: 'same-origin' };
  if (body !== undefined) {
    opt.headers['Content-Type'] = 'application/json';
    opt.body = JSON.stringify(body);
  }
  let res;
  try { res = await fetch(url, opt); }
  catch (e) { return { ok: false, err: '网络断了' }; }
  // 未登录时接口层可能直接 302 到 /login，fetch 会跟随并拿回一个 HTML 页面
  if (res.redirected && /\/login\b/.test(res.url)) { toLogin(); return { ok: false, err: '登录已失效，请重新登录' }; }
  const text = await res.text();
  let j;
  try { j = JSON.parse(text); }
  catch (e) { return { ok: false, err: '服务器返回异常（' + res.status + '）', status: res.status }; }
  if (j && j.scope_mismatch) { showScopeMismatch(j); return j; }
  if (isAuthErr(j)) toLogin();
  return j;
}

// 接口还没上线时的特征：404 / 非 JSON / 未知接口
export function apiMissing(j) {
  if (!j) return true;
  if (j.ok) return false;
  if (j.status === 404) return true;
  const e = String(j.err || '');
  return e.indexOf('异常') >= 0 || e.indexOf('未知') >= 0 || e.indexOf('不存在') >= 0;
}

// ---------- 时间 ----------
export function hhmm(ts) {
  const d = new Date(ts);
  const p = (n) => (n < 10 ? '0' + n : String(n));
  return p(d.getHours()) + ':' + p(d.getMinutes());
}
function sameDay(a, b) {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}
export function dayLabel(ts) {
  const d = new Date(ts), n = new Date();
  if (sameDay(d, n)) return '今天';
  const y = new Date(n.getTime() - 86400000);
  if (sameDay(d, y)) return '昨天';
  return (d.getMonth() + 1) + '月' + d.getDate() + '日';
}
// 会话列表里的时间：今天只显示时分，更早显示日期
export function convTime(ts) {
  if (!ts) return '';
  const d = new Date(ts), n = new Date();
  if (sameDay(d, n)) return hhmm(ts);
  const y = new Date(n.getTime() - 86400000);
  if (sameDay(d, y)) return '昨天';
  return (d.getMonth() + 1) + '/' + d.getDate();
}

// ---------- 主题：内建纯色主题 + 分类 ----------
// 只做普通（纯色）主题：每个主题给一个主色 accent 和一块浅底 soft，
// 分类维度参照开放平台的做法（色谱 / 情感 / 性格 / 具体 / 系列 / 季节），默认先看色谱。
export const THEMES = [
  { id: 'dadi', name: '大地红', accent: '#b91c1c', soft: '#fee2e2' },
  { id: 'zhuanhong', name: '砖红', accent: '#9a3412', soft: '#ffedd5' },
  { id: 'shuguang', name: '曙光', accent: '#e05a45', soft: '#fde4df' },
  { id: 'daoxiang', name: '稻香橙', accent: '#ea580c', soft: '#ffedd5' },
  { id: 'nuanyangcheng', name: '暖阳橙', accent: '#f59e0b', soft: '#fdedd0' },
  { id: 'kafei', name: '咖啡', accent: '#78350f', soft: '#fef9c3' },
  { id: 'naicha', name: '奶茶', accent: '#c8a27a', soft: '#f2e8db' },
  { id: 'xingse', name: '杏色', accent: '#f2b98c', soft: '#f8ead9' },
  { id: 'shechijin', name: '奢侈金', accent: '#b8860b', soft: '#f9eec6' },
  { id: 'luori', name: '落日黄', accent: '#ca8a04', soft: '#fef9c3' },
  { id: 'dianjihuang', name: '典籍黄', accent: '#a16207', soft: '#f7f0da' },
  { id: 'mocha', name: '抹茶', accent: '#65a30d', soft: '#ecfccb' },
  { id: 'xiaocao', name: '小草绿', accent: '#16a34a', soft: '#dcfce7' },
  { id: 'songlv', name: '松绿', accent: '#15803d', soft: '#ecfccb' },
  { id: 'danbohe', name: '淡薄荷', accent: '#34d399', soft: '#d9f8ec' },
  { id: 'qingbi', name: '青碧', accent: '#0d9488', soft: '#ccfbf1' },
  { id: 'yunqing', name: '云青青', accent: '#0891b2', soft: '#cffafe' },
  { id: 'minghuanqing', name: '明焕青', accent: '#06b6d4', soft: '#d7f6fb' },
  { id: 'dailan', name: '呆蓝', accent: '#dbeafe', soft: '#e6efff' },
  { id: 'tianqing', name: '天青', accent: '#0284c7', soft: '#e0f2fe' },
  { id: 'wumailan', name: '雾霾蓝', accent: '#7d94a8', soft: '#e7edf1' },
  { id: 'hulan', name: '湖蓝', accent: '#2563eb', soft: '#dbeafe' },
  { id: 'xuanfenglan', name: '旋风蓝', accent: '#1d4ed8', soft: '#e0e9ff' },
  { id: 'menghuanlan', name: '梦幻蓝', accent: '#5b7cfa', soft: '#e4ecff' },
  { id: 'beijixing', name: '北极星', accent: '#172554', soft: '#e4e9f8' },
  { id: 'shenkongming', name: '深空冥', accent: '#0b1026', soft: '#e0e6f5' },
  { id: 'ziluolan', name: '紫罗兰', accent: '#7c3aed', soft: '#ede9fe' },
  { id: 'dingxiangzi', name: '丁香紫', accent: '#c4b5fd', soft: '#ede8ff' },
  { id: 'huopo', name: '活泼', accent: '#c026d3', soft: '#fbe0fd' },
  { id: 'mengdong', name: '萌动粉', accent: '#db2777', soft: '#fce7f3' },
  { id: 'daifen', name: '呆粉', accent: '#fce7f3', soft: '#fdeaf4' },
  { id: 'caixiafen', name: '彩霞粉', accent: '#f472b6', soft: '#fce7f3' },
  { id: 'yinghuafen', name: '樱花', accent: '#fbcfe8', soft: '#fde9f4' },
  { id: 'mitao', name: '蜜桃', accent: '#ffb3a7', soft: '#ffe7df' },
  { id: 'naihuang', name: '奶黄', accent: '#fde68a', soft: '#fef5d3' },
  { id: 'bohenai', name: '薄荷奶', accent: '#a7f3d0', soft: '#dcfcec' },
  { id: 'yunduolan', name: '云朵蓝', accent: '#bae6fd', soft: '#ddf3fd' },
  { id: 'yuni', name: '芋泥', accent: '#ddd6fe', soft: '#ece7ff' },
  { id: 'oufen', name: '藕粉', accent: '#e8c4d0', soft: '#f7e8ee' },
  { id: 'tongtou', name: '通透白', accent: '#ffffff', soft: '#f1f5f9' },
  { id: 'mibai', name: '米白', accent: '#f7f3ea', soft: '#f1ede2' },
  { id: 'yuebai', name: '月白', accent: '#eef2f7', soft: '#eaeff5' },
  { id: 'shiban', name: '石板灰', accent: '#334155', soft: '#e2e8f0' },
  { id: 'yanhui', name: '岩灰', accent: '#64748b', soft: '#e2e8f0' },
  { id: 'menglonghui', name: '朦胧灰', accent: '#9ca8b8', soft: '#eaecef' },
  { id: 'didiao', name: '低调黑', accent: '#18181b', soft: '#e4e4e7' },
  { id: 'gaoyahei', name: '高雅黑', accent: '#0b0b0f', soft: '#26262c' },
  { id: 'wuye', name: '午夜', accent: '#0b1220', soft: '#1b2740' },
];

// 分类维度：每组 = [维度键, 显示名, [[分组键, 分组名], ...]]，顺序即展示顺序
export const THEME_GROUPS = [
  ['temp', '色谱', [['warm', '暖色'], ['cool', '冷色'], ['neutral', '中性']]],
  ['mood', '情感', [['vivid', '活力'], ['warm', '温暖'], ['fresh', '清新'], ['calm', '宁静'], ['elegant', '雅致'], ['steady', '沉稳']]],
  ['char', '性格', [['gentle', '温柔'], ['cute', '可爱'], ['lively', '活泼'], ['crisp', '清爽'], ['poetic', '诗意'], ['steady', '沉稳']]],
  ['thing', '具体', [['plants', '草木'], ['sky', '天空'], ['night', '星夜'], ['earth', '大地'], ['light', '光影'], ['flowers', '花草'], ['plain', '素净'], ['other', '其他']]],
  ['series', '系列', [['soft', '柔和系'], ['strong', '强烈系'], ['neutral', '中性系']]],
  ['season', '季节', [['spring', '春'], ['summer', '夏'], ['autumn', '秋'], ['winter', '冬']]],
];

// 每个主题在六个维度上的分组（没登记的 id 会在建索引时被过滤掉）
const TAG_SRC = {
  temp: {
    warm: ['dadi', 'zhuanhong', 'shuguang', 'daoxiang', 'nuanyangcheng', 'kafei', 'naicha', 'xingse', 'shechijin', 'luori', 'dianjihuang', 'mengdong', 'daifen', 'mibai', 'caixiafen', 'yinghuafen', 'mitao', 'naihuang', 'oufen'],
    cool: ['mocha', 'xiaocao', 'songlv', 'danbohe', 'qingbi', 'yunqing', 'minghuanqing', 'dailan', 'tianqing', 'wumailan', 'hulan', 'xuanfenglan', 'menghuanlan', 'beijixing', 'shenkongming', 'ziluolan', 'dingxiangzi', 'huopo', 'bohenai', 'yunduolan', 'yuni'],
    neutral: ['tongtou', 'yuebai', 'shiban', 'yanhui', 'menglonghui', 'didiao', 'gaoyahei', 'wuye'],
  },
  mood: {
    vivid: ['dadi', 'zhuanhong', 'daoxiang', 'hulan', 'xuanfenglan', 'huopo', 'mengdong'],
    warm: ['shuguang', 'nuanyangcheng', 'kafei', 'naicha', 'xingse', 'luori', 'caixiafen', 'yinghuafen', 'mitao', 'naihuang'],
    fresh: ['mocha', 'xiaocao', 'songlv', 'danbohe', 'qingbi', 'yunqing', 'dailan', 'minghuanqing', 'menghuanlan', 'bohenai', 'yunduolan'],
    calm: ['tianqing', 'wumailan', 'tongtou', 'mibai', 'shiban', 'yanhui', 'menglonghui', 'yuebai', 'beijixing', 'shenkongming'],
    elegant: ['shechijin', 'ziluolan', 'dingxiangzi', 'daifen', 'gaoyahei', 'yuni', 'oufen'],
    steady: ['didiao', 'wuye', 'dianjihuang'],
  },
  char: {
    gentle: ['mibai', 'xingse', 'danbohe', 'dingxiangzi', 'naicha', 'kafei', 'luori', 'caixiafen', 'oufen', 'yuebai'],
    cute: ['daifen', 'dailan', 'mengdong', 'yinghuafen', 'mitao', 'naihuang', 'bohenai', 'yunduolan', 'yuni'],
    lively: ['xuanfenglan', 'huopo', 'dadi', 'zhuanhong', 'daoxiang', 'shechijin'],
    crisp: ['hulan', 'mocha', 'xiaocao', 'songlv', 'qingbi', 'yunqing', 'nuanyangcheng', 'minghuanqing'],
    poetic: ['tianqing', 'ziluolan', 'wumailan', 'shuguang', 'menghuanlan', 'beijixing', 'shenkongming'],
    steady: ['shiban', 'yanhui', 'didiao', 'gaoyahei', 'wuye', 'menglonghui', 'tongtou', 'dianjihuang'],
  },
  thing: {
    plants: ['mocha', 'xiaocao', 'songlv', 'danbohe', 'qingbi', 'bohenai'],
    sky: ['yunqing', 'dailan', 'tianqing', 'wumailan', 'hulan', 'xuanfenglan', 'menghuanlan', 'minghuanqing', 'yunduolan'],
    night: ['beijixing', 'shenkongming', 'wuye'],
    earth: ['dadi', 'zhuanhong', 'daoxiang', 'kafei', 'naicha', 'xingse', 'dianjihuang'],
    light: ['shuguang', 'nuanyangcheng', 'luori'],
    flowers: ['ziluolan', 'dingxiangzi', 'mengdong', 'daifen', 'yinghuafen', 'oufen', 'caixiafen'],
    plain: ['tongtou', 'mibai', 'yuebai', 'shiban', 'yanhui', 'menglonghui', 'didiao', 'gaoyahei'],
    other: ['shechijin', 'huopo', 'naihuang', 'mitao', 'yuni'],
  },
  series: {
    soft: ['dailan', 'daifen', 'mibai', 'xingse', 'danbohe', 'dingxiangzi', 'mengdong', 'menglonghui', 'tianqing', 'yunqing', 'naicha', 'mocha', 'xiaocao', 'songlv', 'qingbi', 'menghuanlan', 'minghuanqing', 'caixiafen', 'yinghuafen', 'mitao', 'naihuang', 'bohenai', 'yunduolan', 'yuni', 'oufen', 'yuebai'],
    strong: ['dadi', 'zhuanhong', 'hulan', 'xuanfenglan', 'daoxiang', 'luori', 'shechijin', 'huopo', 'ziluolan', 'nuanyangcheng'],
    neutral: ['tongtou', 'shiban', 'yanhui', 'didiao', 'gaoyahei', 'wuye', 'beijixing', 'wumailan', 'shenkongming', 'dianjihuang'],
  },
  season: {
    spring: ['mengdong', 'daifen', 'xingse', 'mocha', 'xiaocao', 'danbohe', 'ziluolan', 'dingxiangzi', 'caixiafen', 'yinghuafen', 'bohenai', 'oufen'],
    summer: ['songlv', 'qingbi', 'yunqing', 'dailan', 'tianqing', 'hulan', 'xuanfenglan', 'huopo', 'menghuanlan', 'minghuanqing', 'yunduolan', 'mengdong'],
    autumn: ['dadi', 'zhuanhong', 'shuguang', 'daoxiang', 'nuanyangcheng', 'kafei', 'naicha', 'shechijin', 'luori', 'dianjihuang', 'naihuang', 'mitao'],
    winter: ['beijixing', 'wumailan', 'tongtou', 'mibai', 'shiban', 'yanhui', 'menglonghui', 'didiao', 'gaoyahei', 'wuye', 'shenkongming', 'yuebai', 'yuni'],
  },
};

// ---------- 主题 / 外观 ----------
// 主题分两层，彼此独立：
//   ① 模式 auto|light|dark —— 只决定底色与文字色，落在 <html data-theme> 上
//   ② 强调色 theme id / p_N —— 只覆盖 --accent 系，深浅由模式决定
export const THEME_MODES = [['auto', '跟随系统'], ['light', '浅色'], ['dark', '深色']];
const MODE_SET = { auto: 1, light: 1, dark: 1 };

const MQ_DARK = (typeof window !== 'undefined' && window.matchMedia)
  ? window.matchMedia('(prefers-color-scheme: dark)') : null;

/** 系统当前深浅：'light' | 'dark' */
export function systemMode() {
  return MQ_DARK && MQ_DARK.matches ? 'dark' : 'light';
}

/** 规整成三选一，未知值一律跟随系统 */
export function normalizeMode(mode) {
  const m = String(mode || '').toLowerCase();
  return MODE_SET[m] ? m : 'auto';
}

/** 当前模式（用户选的，默认跟随系统） */
export function themeMode() {
  return S.themeMode || 'auto';
}

/** 实际生效的深浅：auto 时看系统 */
export function effectiveMode() {
  const m = themeMode();
  return m === 'auto' ? systemMode() : m;
}

function storedMode() {
  try { return normalizeMode(localStorage.getItem(LSK('theme'))); } catch (e) { void e; return 'auto'; }
}

/** 只把模式打到 <html> 上（不写存储），app.js 启动时也直接调它 */
export function applyThemeMode(mode, opts) {
  let m = normalizeMode(mode);
  // 安装器把站点默认主题写成 'light'，那不算用户的选择；没自己选过的老用户一律
  // 跟随系统，否则深色系统会被强行刷成白底（就是「深色不生效」的老毛病）
  if (m === 'light' && !S.themeUserPicked && storedMode() !== 'light') m = 'auto';
  const el = document.documentElement;
  S.themeMode = m;
  S.theme = m;
  if (m === 'auto') el.removeAttribute('data-theme');
  else el.setAttribute('data-theme', m);
  el.setAttribute('data-theme-mode', m);      // auto 时也能从 DOM 看出在跟随系统
  syncMetaColor();
  // 首帧的底色已经由服务端 style + 上次的颜色铺好，启动时别重画（会白一下再换回来）
  if (!(opts && opts.keepPaint)) repaintAccent(); // 主题色跟着深浅重算（页面底、浅底块都变）
}

// 浏览器状态栏 / 地址栏颜色跟随当前底色
function syncMetaColor() {
  const meta = document.querySelector('meta[name="theme-color"]');
  if (!meta) return;
  const v = getComputedStyle(document.documentElement).getPropertyValue('--bg').trim();
  if (v) meta.setAttribute('content', v);
}

/** 用户显式选模式：本地立刻生效 + 存 localStorage + 同步服务端（换设备能恢复） */
export function setThemeMode(mode, opts) {
  const m = normalizeMode(mode);
  S.themeUserPicked = true;
  try { localStorage.setItem(LSK('theme'), m); } catch (e) { void e; }
  applyThemeMode(m);
  if (!(opts && opts.silent)) saveThemeRemote(m, S.themeChoice);
  emit('theme');
}

/** 「模式 + 强调色」编码成一个字符串（后端只校验 id 字符集，不动后端也能同步模式） */
export function encodeThemeChoice(mode, accent) {
  const m = normalizeMode(mode);
  const a = String(accent || '');
  return a ? m + '-' + a : m;
}

/** 解出 { mode, accent }；老数据只存了强调色时 mode 为空串 */
export function decodeThemeChoice(val) {
  const s = String(val || '');
  if (!s) return { mode: '', accent: '' };
  if (MODE_SET[s]) return { mode: s, accent: '' };
  const i = s.indexOf('-');
  if (i > 0) {
    const head = s.slice(0, i);
    if (MODE_SET[head]) return { mode: head, accent: s.slice(i + 1) };
  }
  return { mode: '', accent: s };
}

function saveThemeRemote(mode, accent) {
  if (!S.me) return;                            // 没登录（登录页/引导页）只存本地
  const val = encodeThemeChoice(mode, accent);
  Promise.resolve(api('api/theme/set', { theme: val })).then(function (j) {
    if (j && j.ok) S.themeSaved = val;
  }).catch(function () { /* 接口不可用或离线：本地已经生效，不打扰用户 */ });
}

function onSystemModeChange() {
  if (themeMode() !== 'auto') return;           // 锁了浅/深的用户不受系统变化影响
  applyThemeMode('auto');
  emit('theme');
}
// 开放平台的纯色主题一个主题给 5 个颜色：顶栏 / 侧栏底 / 侧栏字 / 悬停 / 内容区底。
// 这里的 accent 就是顶栏色、THEMES 里的 soft 是悬停色，另外补两列：
//   ink —— 浅底上的强调文字（选中菜单、链接、标签），比 accent 压深一档才看得清
//   bg  —— 内容区底色，整站会跟着泛一层主色调，不再只有按钮变色
const THEME_EXTRA = {
  dadi: ['#7f1d1d', '#fbeceb'],
  zhuanhong: ['#7c2d12', '#fdeee6'],
  shuguang: ['#7c2d22', '#fcf2f0'],
  daoxiang: ['#9a3412', '#fdefe2'],
  nuanyangcheng: ['#7d4a06', '#fdf5e6'],
  kafei: ['#422006', '#f6efe3'],
  naicha: ['#6b4a2a', '#f8f3ec'],
  xingse: ['#7a4a1f', '#fbf4ec'],
  shechijin: ['#6b4f0a', '#fbf5e4'],
  luori: ['#713f12', '#fdf6da'],
  dianjihuang: ['#5b4306', '#faf7ec'],
  mocha: ['#3f6212', '#f3f9e0'],
  xiaocao: ['#14532d', '#e9f8ed'],
  songlv: ['#14532d', '#edf8e4'],
  danbohe: ['#0f5c4a', '#ecfbf5'],
  qingbi: ['#134e4a', '#e5f7f4'],
  yunqing: ['#155e75', '#e5f6fa'],
  minghuanqing: ['#0e5c6b', '#f1fbfd'],
  dailan: ['#334867', '#f7faff'],
  tianqing: ['#0c4a6e', '#e6f1fb'],
  wumailan: ['#3f4f5e', '#f3f7f9'],
  hulan: ['#1e3a8a', '#e8f0fd'],
  xuanfenglan: ['#1e3a8a', '#eff4ff'],
  menghuanlan: ['#33407a', '#f5f8ff'],
  beijixing: ['#22307a', '#f1f4fb'],
  shenkongming: ['#28305a', '#f4f6fc'],
  ziluolan: ['#4c1d95', '#f1ebfd'],
  dingxiangzi: ['#55409b', '#f7f4ff'],
  huopo: ['#701a75', '#fcf0fd'],
  mengdong: ['#831843', '#fdebf5'],
  daifen: ['#6b3b52', '#fff7fb'],
  caixiafen: ['#9d174d', '#fff5fa'],
  yinghuafen: ['#7a3352', '#fffafd'],
  mitao: ['#7c3b2e', '#fffaf8'],
  naihuang: ['#78530a', '#fffefa'],
  bohenai: ['#14543f', '#f9fffc'],
  yunduolan: ['#1d4f66', '#fafdff'],
  yuni: ['#4c3a86', '#fcfbff'],
  oufen: ['#6b4451', '#fffbfc'],
  tongtou: ['#334155', '#f6f7f9'],
  mibai: ['#4a453c', '#f9f6ef'],
  yuebai: ['#455468', '#fcfdff'],
  shiban: ['#1e293b', '#edf1f6'],
  yanhui: ['#334155', '#eff1f4'],
  menglonghui: ['#3d4451', '#f5f7f9'],
  didiao: ['#18181b', '#f2f3f4'],
  gaoyahei: ['#3a3a42', '#f6f6f8'],
  wuye: ['#39445e', '#f4f7fb'],
};

export const THEME_INDEX = (() => {
  const m = {};
  THEMES.forEach((t) => {
    const e = THEME_EXTRA[t.id] || [];
    m[t.id] = { id: t.id, name: t.name, accent: t.accent, soft: t.soft, ink: e[0] || '', bg: e[1] || '' };
  });
  return m;
})();

// 主题 id => { 维度: 分组键 }，只收内建表里存在的 id
export const TAG_INDEX = (() => {
  const m = {};
  Object.keys(TAG_SRC).forEach(dim => {
    Object.keys(TAG_SRC[dim]).forEach(key => {
      TAG_SRC[dim][key].forEach(id => {
        if (!THEME_INDEX[id]) return;
        if (!m[id]) m[id] = {};
        m[id][dim] = key;
      });
    });
  });
  return m;
})();

window.__lgTagIndex = TAG_INDEX;

export function tagOf(id, dim) { return (TAG_INDEX[id] || {})[dim] || ""; }

// 亮底配深字、暗底配浅字
export function contrastText(hex) {
  const m = /^#?([0-9a-fA-F]{6})$/.exec(String(hex || ""));
  if (!m) return "#ffffff";
  const v = parseInt(m[1], 16);
  const r = (v >> 16) & 255, g = (v >> 8) & 255, b = v & 255;
  const lin = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
  const l = 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
  return l > 0.45 ? "#111827" : "#ffffff";
}

// 强调色这一系全交给这里写：主色 / 字色 / 浅底 / 浅底文字 / 描边 / 页面底。
// 深浅两套由 mode 决定，主题包在深色下再也不会把浅底深字盖回来。
// 首帧缓存：app-shell.php 的内联脚本会读它，先按上次的颜色铺一层再等接口
const ACCENT_CACHE = 'lg.accent';

const ACCENT_VARS = ['--accent', '--accent-text', '--self-bubble', '--self-bubble-text',
  '--accent-soft', '--accent-ink', '--accent-line', '--page-bg'];

function hexRgb(hex) {
  const m = /^#?([0-9a-fA-F]{6})$/.exec(String(hex || ''));
  if (!m) return null;
  const v = parseInt(m[1], 16);
  return [(v >> 16) & 255, (v >> 8) & 255, v & 255];
}

function rgbHex(c) {
  return '#' + c.map(function (x) {
    const s = Math.round(Math.max(0, Math.min(255, x))).toString(16);
    return s.length < 2 ? '0' + s : s;
  }).join('');
}

function lumOf(hex) {
  const c = hexRgb(hex);
  if (!c) return 0;
  const lin = function (x) { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); };
  return 0.2126 * lin(c[0]) + 0.7152 * lin(c[1]) + 0.0722 * lin(c[2]);
}

function contrastRatio(a, b) {
  const x = lumOf(a) + 0.05, y = lumOf(b) + 0.05;
  return x > y ? x / y : y / x;
}

// a 占 t，b 占 1-t
function mixHex(a, b, t) {
  const x = hexRgb(a), y = hexRgb(b);
  if (!x || !y) return a;
  return rgbHex([x[0] * t + y[0] * (1 - t), x[1] * t + y[1] * (1 - t), x[2] * t + y[2] * (1 - t)]);
}

// 把颜色往黑或白推，直到亮度贴近 target（二分，够用就行）
function lumTo(hex, target) {
  const l = lumOf(hex);
  if (Math.abs(l - target) < 0.015) return hex;
  const other = target > l ? '#ffffff' : '#000000';
  let lo = 1, hi = 0;
  for (let i = 0; i < 18; i += 1) {
    const t = (lo + hi) / 2;
    const v = lumOf(mixHex(hex, other, t));
    if ((target > l && v < target) || (target < l && v > target)) lo = t; else hi = t;
  }
  return mixHex(hex, other, (lo + hi) / 2);
}

// base 是内建主题那一行（带 soft / ink / bg），主题包传 null 走现算
function paintAccent(accent, accentText, base) {
  const el = document.documentElement;
  if (!accent) { ACCENT_VARS.forEach(function (k) { el.style.removeProperty(k); }); return; }
  const src = hexRgb(accent) ? String(accent) : '#2563eb';
  const dark = effectiveMode() === 'dark';
  let main, soft, ink, line, pageBg = '';
  if (dark) {
    // 深色底上过暗的主色要提亮一档才看得清（0.30 差不多就是原 #4f8cff）
    main = lumTo(src, 0.30);
    soft = mixHex(main, '#1b222b', 0.18);
    ink = lumTo(src, 0.72);
    line = mixHex(main, '#1b222b', 0.5);
  } else {
    main = src;
    soft = (base && base.soft) ? base.soft : mixHex(src, '#ffffff', 0.13);
    const want = (base && base.ink) ? base.ink : '';
    // ink 只会往深里走：主色本身够暗就别再提亮，浅色主色按亮度压下去
    ink = want && lumOf(want) < 0.40 ? want : lumTo(src, Math.min(lumOf(src) * 0.55, 0.20));
    line = (base && base.soft) ? mixHex(src, base.soft, 0.35) : mixHex(src, '#ffffff', 0.32);
    pageBg = (base && base.bg) ? base.bg : mixHex(src, '#ffffff', 0.07);
  }
  // 主题包给的字色只在对比够用时采纳，否则按主色重算（浅主题配白字就是这么看不见的）
  const given = accentText ? String(accentText) : '';
  const tc = given && contrastRatio(given, main) >= 3 ? given : contrastText(main);
  el.style.setProperty('--accent', main);
  el.style.setProperty('--accent-text', tc);
  el.style.setProperty('--self-bubble', main);
  el.style.setProperty('--self-bubble-text', tc);
  el.style.setProperty('--accent-soft', soft);
  el.style.setProperty('--accent-ink', ink);
  el.style.setProperty('--accent-line', line);
  if (pageBg) el.style.setProperty('--page-bg', pageBg); else el.style.removeProperty('--page-bg');
  try {
    const vars = {
      '--accent': main, '--accent-text': tc, '--self-bubble': main, '--self-bubble-text': tc,
      '--accent-soft': soft, '--accent-ink': ink, '--accent-line': line
    };
    if (pageBg) vars['--page-bg'] = pageBg;
    localStorage.setItem(ACCENT_CACHE, JSON.stringify({ mode: dark ? 'dark' : 'light', vars: vars }));
  } catch (e) { void e; }
  syncMetaColor();
}

function clearAccent() {
  const el = document.documentElement;
  ACCENT_VARS.forEach(function (k) { el.style.removeProperty(k); });
  try { localStorage.removeItem(ACCENT_CACHE); } catch (e) { void e; }
}

/** 兜底主题：本群默认 → 站点默认 → 湖蓝，永远给得出一个内建主题 */
function fallbackTheme() {
  const list = [S.scopeTheme, S.siteTheme, 'hulan'];
  for (let i = 0; i < list.length; i += 1) if (THEME_INDEX[list[i]]) return THEME_INDEX[list[i]];
  return THEME_INDEX.hulan || null;
}

function paintById(id) {
  if (!id) { clearAccent(); return; }
  const t = THEME_INDEX[id];
  if (t) { paintAccent(t.accent, '', t); return; }
  const pack = (S.themePacks || []).find(function (x) { return x.id === id; });
  const v = pack ? (pack.vars || {}) : {};
  const acc = v.accent || v.bubble || '';
  // 主题包还没拉到 / 包被删了 / 包里没给主色：退回本群或站点主题。
  // 这里以前是 clearAccent()，那一瞬间页面底会掉回默认近白色，看着就是「主题被白盖了一下」。
  if (!acc) {
    const fb = fallbackTheme();
    if (fb) paintAccent(fb.accent, '', fb);
    return;
  }
  // 主题包里的 bg/surface/text/border 一律忽略，只认 accent 系
  paintAccent(acc, v.accentText || v.bubbleText || '', null);
}

/** 换浅/深后重画：页面底与浅底块都跟模式相关，不重画就会留上一套 */
export function repaintAccent() {
  paintById(resolveThemeId());
}

/** 应用强调色：传内建 id 或主题包 p_N；空串 = 跟随本群 */
export function applyThemeChoice(id) {
  S.themeChoice = id || '';
  paintById(id);
}

function themeExists(id) {
  if (!id) return false;
  if (THEME_INDEX[id]) return true;
  return (S.themePacks || []).some(function (x) { return x.id === id; });
}

/** 生效强调色：我选的 → 本群默认 → 站点默认 → 湖蓝（包被删掉就逐级回退） */
export function resolveThemeId() {
  const mine = S.themeChoice !== undefined ? S.themeChoice : '';
  const list = [mine, S.scopeTheme, S.siteTheme, 'hulan'];
  for (let i = 0; i < list.length; i += 1) if (themeExists(list[i])) return list[i];
  return 'hulan';
}

/** 拉本群可见的主题并应用（启动、切换、建包后都走这里） */
export async function refreshThemes() {
  try {
    const t = await api('api/themes');
    if (!t || !t.ok) return;
    S.themePacks = t.packs || [];
    S.scopeTheme = t.scope || '';
    S.siteTheme = t.site || '';
    const dec = decodeThemeChoice(t.mine || '');
    S.themeChoice = dec.accent || '';
    if (dec.mode) {
      // 服务端存了模式（换设备登录）：以服务端为准，同时回写本地
      S.themeUserPicked = true;
      try { localStorage.setItem(LSK('theme'), dec.mode); } catch (e) { void e; }
      // 只切深浅模式，别在这里重画：重画会用「还没换过来的主题」先刷一帧站点主题色
      applyThemeMode(dec.mode, { keepPaint: true });
    } else if (dec.accent && S.themeMode && S.themeMode !== 'auto') {
      // 老数据只存了强调色：把本地模式补写回服务端，做一次性迁移
      saveThemeRemote(S.themeMode, dec.accent);
    }
    applyThemeChoice(resolveThemeId());
  } catch (e) { void e; }
}

/** 主题显示名（内建或主题包） */
export function themeName(id) {
  if (!id) return "跟随本群";
  if (THEME_INDEX[id]) return THEME_INDEX[id].name;
  const pack = (S.themePacks || []).find(x => x.id === id);
  return pack ? pack.name : id;
}

// 模块求值即生效（早于 app.js 启动），少闪一下浅色；
// 同时挂上系统深浅变化监听，让「跟随系统」真的实时跟随
(function initTheme() {
  let saved = '';
  try { saved = localStorage.getItem(LSK('theme')) || ''; } catch (e) { void e; }
  applyThemeMode(saved || 'auto', { keepPaint: true });
  if (MQ_DARK) {
    if (MQ_DARK.addEventListener) MQ_DARK.addEventListener('change', onSystemModeChange);
    else if (MQ_DARK.addListener) MQ_DARK.addListener(onSystemModeChange);
  }
})();

export function applyScopeCss(css) {
  let node = document.getElementById('scope-css');
  if (!node) {
    node = document.createElement('style');
    node.id = 'scope-css';
    document.head.appendChild(node);
  }
  node.textContent = css || '';
}
export function applyAllCss() {
  applyScopeCss((S.gcss || '') + '\n' + (S.css || ''));
  applyWall();
}
export function applyWall() {
  if (S.wall) {
    document.body.style.backgroundImage = 'url("' + S.wall + '")';
    document.body.classList.add('has-wall');
  } else {
    document.body.style.backgroundImage = '';
    document.body.classList.remove('has-wall');
  }
}

// ---------- 提示 / 弹层 ----------
let toastTimer = 0;
export function toast(msg) {
  let t = document.getElementById('toast');
  if (!t) { t = h('div', 'toast'); t.id = 'toast'; document.body.appendChild(t); }
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 2200);
}

export function closeSheet() {
  const s = document.getElementById('sheet');
  if (!s || !s.parentNode) return;
  // 弹层自己的清理钩子（keydown 之类），不清理会一直挂在 document 上
  if (typeof s.__lgCleanup === 'function') {
    try { s.__lgCleanup(); } catch (e) { /* 清理失败不影响关闭 */ }
  }
  s.parentNode.removeChild(s);
}

/**
 * 打开一个弹层。
 * opts.onBeforeClose：返回 false 就拦下这次关闭（编辑器用来防手滑）。
 * 右上角 ×、ESC 走同一条关闭路径；点遮罩不关，免得误触丢输入。
 */
export function openSheet(title, body, opts) {
  closeSheet();
  const o = opts || {};
  const mask = h('div', 'sheet');
  mask.id = 'sheet';
  const card = h('div', 'sheet-card');
  // sm（默认 460）/ md 600 / lg 920 / xl 1120 / editor 编辑器专用（高也撑满）
  if (o.size) card.className = 'sheet-card sheet-' + o.size;
  const head = h('div', 'sheet-head');
  head.appendChild(h('b', '', title));
  const x = document.createElement('button');
  x.type = 'button';
  x.className = 'bar-x';
  x.appendChild(icon('fa-xmark'));
  head.appendChild(x);
  card.appendChild(head);
  card.appendChild(body);
  mask.appendChild(card);

  function tryClose() {
    if (typeof o.onBeforeClose === 'function' && o.onBeforeClose() === false) return;
    closeSheet();
    if (typeof o.onClosed === 'function') o.onClosed();
  }

  function onKey(ev) {
    if (ev.key === 'Escape') {
      ev.preventDefault();
      tryClose();
    }
  }

  x.addEventListener('click', tryClose);
  // 点遮罩不关：弹层里常有输入框，鼠标偏一下就把内容丢了；只认右上角 × 和 ESC
  document.addEventListener('keydown', onKey);
  mask.__lgCleanup = function () { document.removeEventListener('keydown', onKey); };
  document.body.appendChild(mask);
  return mask;
}

export async function copyText(t) {
  try { await navigator.clipboard.writeText(t); toast('已复制'); }
  catch (e) { toast('复制不了，手动选一下吧'); }
}

export function readDataUrl(file) {
  return new Promise((resolve, reject) => {
    const fr = new FileReader();
    fr.onload = () => resolve(String(fr.result));
    fr.onerror = () => reject(new Error('读取失败'));
    fr.readAsDataURL(file);
  });
}

// ---------- 事件总线（模块之间不互相 import 页面逻辑） ----------
const bus = new Map();
export function on(ev, fn) {
  if (!bus.has(ev)) bus.set(ev, []);
  bus.get(ev).push(fn);
}
export function emit(ev, data) {
  const list = bus.get(ev) || [];
  list.forEach(function (fn) {
    try { fn(data); } catch (e) { console.error(e); }
  });
}

// ---------- 路由 ----------
// #/c/<id>  #/friends  #/members  #/me  #/appearance
const PAGES = ['chat', 'friends', 'members', 'me', 'appearance', 'space'];

export function parseRoute() {
  const raw = (location.hash || '').replace(/^#\/?/, '');
  if (!raw) return { page: 'chat', id: 0 };
  const seg = raw.split('/');
  const head = seg[0];
  if (head === 'c') return { page: 'chat', id: parseInt(seg[1] || '0', 10) || 0 };
  if (PAGES.indexOf(head) >= 0) return { page: head, id: 0 };
  return { page: 'chat', id: 0 };
}

export function go(hash) {
  const target = hash.charAt(0) === '#' ? hash : '#' + hash;
  if (location.hash === target) { emit('route'); return; }
  location.hash = target;
}

let layoutFn = null;
export function onLayout(fn) { layoutFn = fn; }
export function applyLayout() { if (layoutFn) layoutFn(); }
export function setListOpen(v) { S.listOpen = !!v; applyLayout(); }

let routerFn = null;
export function startRouter(fn) {
  routerFn = fn;
  window.addEventListener('hashchange', function () { fn(); });
  fn();
}
