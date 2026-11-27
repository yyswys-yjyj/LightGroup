// LightGroup · 消息本地库（IndexedDB）
// 漫游式：管理员允许时，拉过的消息在本机留一份；进会话先出本地，再向服务端要增量。
// 管理员（站点总配置 / 本群配置）不让留时，本机库整个关掉，历史每次都从服务端按页取。
import { S } from './core.js';

const DB_NAME = 'lg-msg';
const DB_VER = 1;
const STORE = 'msgs';
const META = 'meta';
// 每会话本机最多留这么多条，大概够翻两天的群聊
const MAX_PER_CONV = 2000;
// 本机总条数上限，超了从最旧的会话开始删
const MAX_TOTAL = 20000;

let dbPromise = null;
// 管理员（站点总配置 / 本群配置）允许不允许在客户端留一份
let serverAllowed = true;

/** 服务端策略变了就同步过来；不让留时把已有的也清掉，别留旧数据在设备上 */
export function setServerAllowed(v) {
  const on = v !== false;
  if (serverAllowed && !on) clearAll().catch(function () { /* 静默 */ });
  serverAllowed = on;
}

export function serverAllows() { return serverAllowed; }

/** 本机是否保存消息：完全由管理员策略决定，客户端没有开关 */
export function localEnabled() {
  return serverAllowed;
}

function openDb() {
  if (dbPromise) return dbPromise;
  dbPromise = new Promise(function (resolve, reject) {
    let req;
    try { req = indexedDB.open(DB_NAME, DB_VER); } catch (e) { reject(e); return; }
    req.onupgradeneeded = function () {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORE)) {
        const st = db.createObjectStore(STORE, { keyPath: 'k' });
        // 按会话查、按会话+id 排序删旧
        st.createIndex('conv', 'conv', { unique: false });
        st.createIndex('convId', ['conv', 'id'], { unique: false });
      }
      if (!db.objectStoreNames.contains(META)) db.createObjectStore(META, { keyPath: 'k' });
    };
    req.onsuccess = function () { resolve(req.result); };
    req.onerror = function () { reject(req.error || new Error('indexedDB 打不开')); };
    req.onblocked = function () { reject(new Error('indexedDB 被其它标签页占着')); };
  });
  return dbPromise;
}

function tx(store, mode, fn) {
  return openDb().then(function (db) {
    return new Promise(function (resolve, reject) {
      const t = db.transaction(store, mode);
      const os = t.objectStore(store);
      let out;
      try { out = fn(os); } catch (e) { reject(e); return; }
      t.oncomplete = function () { resolve(out && out.result !== undefined ? out.result : out); };
      t.onerror = function () { reject(t.error || new Error('本机库写入失败')); };
      t.onabort = function () { reject(t.error || new Error('本机库事务中止')); };
    });
  });
}

function keyOf(convId, id) { return String(convId) + ':' + String(id); }

/** 把一批消息存进本机；同一个 key 覆盖（撤回等状态变化也能更新） */
export async function putMsgs(convId, list) {
  if (!localEnabled() || !convId || !list || !list.length) return 0;
  try {
    const db = await openDb();
    await new Promise(function (resolve, reject) {
      const t = db.transaction(STORE, 'readwrite');
      const os = t.objectStore(STORE);
      list.forEach(function (m) {
        if (!m || !m.id) return;
        os.put({ k: keyOf(convId, m.id), conv: Number(convId), id: Number(m.id), ts: Number(m.ts) || 0, m: m });
      });
      t.oncomplete = function () { resolve(true); };
      t.onerror = function () { reject(t.error); };
      t.onabort = function () { reject(t.error); };
    });
    await trimConv(convId);
    return list.length;
  } catch (e) {
    // 无痕模式 / 配额满 / 隐私设置禁掉 indexedDB 时静默降级回内存
    return 0;
  }
}

/** 取某会话的本机消息，按 id 升序 */
export async function getMsgs(convId, limit) {
  if (!localEnabled() || !convId) return [];
  try {
    const db = await openDb();
    return await new Promise(function (resolve, reject) {
      const t = db.transaction(STORE, 'readonly');
      const idx = t.objectStore(STORE).index('conv');
      const req = idx.getAll(IDBKeyRange.only(Number(convId)));
      req.onsuccess = function () {
        const rows = (req.result || []).sort(function (a, b) { return a.id - b.id; });
        const cut = limit && rows.length > limit ? rows.slice(rows.length - limit) : rows;
        resolve(cut.map(function (r) { return r.m; }));
      };
      req.onerror = function () { reject(req.error); };
    });
  } catch (e) {
    return [];
  }
}

/** 某会话本机最大的消息 id（用来向服务端要增量） */
export async function lastIdOf(convId) {
  const rows = await getMsgs(convId, 1);
  return rows.length ? rows[rows.length - 1].id : 0;
}

async function trimConv(convId) {
  const db = await openDb();
  return new Promise(function (resolve) {
    const t = db.transaction(STORE, 'readwrite');
    const idx = t.objectStore(STORE).index('conv');
    const req = idx.getAllKeys(IDBKeyRange.only(Number(convId)));
    req.onsuccess = function () {
      const keys = req.result || [];
      if (keys.length <= MAX_PER_CONV) { resolve(0); return; }
      const drop = keys.slice(0, keys.length - MAX_PER_CONV);
      const os = t.objectStore(STORE);
      drop.forEach(function (k) { os.delete(k); });
      resolve(drop.length);
    };
    req.onerror = function () { resolve(0); };
  });
}

/** 本机一共存了多少条 */
export async function countAll() {
  try {
    const db = await openDb();
    return await new Promise(function (resolve) {
      const t = db.transaction(STORE, 'readonly');
      const req = t.objectStore(STORE).count();
      req.onsuccess = function () { resolve(req.result || 0); };
      req.onerror = function () { resolve(0); };
    });
  } catch (e) { return 0; }
}

/** 超总量时按会话从旧到新删一批；登录后调用一次即可 */
export async function trimTotal() {
  const n = await countAll();
  if (n <= MAX_TOTAL) return 0;
  try {
    const db = await openDb();
    return await new Promise(function (resolve) {
      const t = db.transaction(STORE, 'readwrite');
      const os = t.objectStore(STORE);
      const req = os.openCursor();
      let left = n - MAX_TOTAL;
      let dropped = 0;
      req.onsuccess = function () {
        const cur = req.result;
        if (!cur || left <= 0) { resolve(dropped); return; }
        os.delete(cur.primaryKey);
        dropped++;
        left--;
        cur.continue();
      };
      req.onerror = function () { resolve(dropped); };
    });
  } catch (e) { return 0; }
}

/** 清空本机消息（退出登录、换设备、隐私清理都用它） */
export async function clearAll() {
  try {
    const db = await openDb();
    return await new Promise(function (resolve) {
      const t = db.transaction([STORE, META], 'readwrite');
      t.objectStore(STORE).clear();
      t.objectStore(META).clear();
      t.oncomplete = function () { resolve(true); };
      t.onerror = function () { resolve(false); };
    });
  } catch (e) { return false; }
}

/** 本机消息条数概括，设置页展示用 */
export async function stats() {
  const n = await countAll();
  return { count: n, max: MAX_TOTAL, perConv: MAX_PER_CONV };
}

/** 当前会话把 S.msgs 最新的那一截落盘（节流，避免每条都写事务） */
let flushTimer = 0;
export function remember(list) {
  if (!localEnabled() || !S.curId || !list || !list.length) return;
  const convId = S.curId;
  const copy = list.slice(-80);
  if (flushTimer) clearTimeout(flushTimer);
  flushTimer = setTimeout(function () {
    flushTimer = 0;
    putMsgs(convId, copy).catch(function () { /* 静默 */ });
  }, 400);
}
