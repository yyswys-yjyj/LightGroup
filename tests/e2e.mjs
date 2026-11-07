// LightGroup 全链路测试：自动起停 php -S，按原版插件协议打接口
import { spawn, execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import fs from "node:fs";
import path from "node:path";

const ROOT = process.cwd();
const PORT = 18000 + Math.floor(Math.random() * 900);
const BASE = `http://127.0.0.1:${PORT}/g/`;
const DB = "data/test.sqlite";
const INVITE = "test123";
const ADMIN = "管理员";
const PASS = "test1234";

let passed = 0;
let webCookie = "";

/** 取响应里最后的 lgb 会话 cookie（session_regenerate_id 可能下发两次，最后那个才有效） */
function lastCookie(res) {
  const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
  const list = raw.length ? raw : [res.headers.get("set-cookie") || ""];
  const lgb = list.map((c) => c.split(";")[0]).filter((c) => c.startsWith("lgb="));
  return lgb.length ? lgb[lgb.length - 1] : "";
}

/** 走网页登录表单（POST /login），返回 lgb 会话 cookie */
async function formLogin(fields) {
  const res = await fetch(`http://127.0.0.1:${PORT}/login`, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams(fields).toString(),
    redirect: "manual",
  });
  return { res, cookie: lastCookie(res) };
}
const failures = [];
const logs = [];

async function t(name, fn) {
  try {
    await fn();
    passed++;
    console.log("  ok   " + name);
  } catch (e) {
    failures.push({ name, error: e.message });
    console.log("  FAIL " + name + "\n       " + e.message);
  }
}

function assert(cond, msg) {
  if (!cond) throw new Error(msg);
}

function eq(actual, expected, label) {
  const a = JSON.stringify(actual);
  const b = JSON.stringify(expected);
  if (a !== b) throw new Error(`${label}: 期望 ${b}，实际 ${a}`);
}

async function req(method, p, body) {
  const url = BASE + p;
  const opt = { method };
  if (body !== undefined) {
    opt.headers = { "Content-Type": "application/json" };
    opt.body = JSON.stringify(body);
  }
  const res = await fetch(url, opt);
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { throw new Error(`${p} 返回非 JSON: ${text.slice(0, 200)}`); }
  return { status: res.status, data };
}

async function waitReady(timeoutMs = 15000) {
  const t0 = Date.now();
  while (Date.now() - t0 < timeoutMs) {
    try {
      const r = await fetch(`http://127.0.0.1:${PORT}/`);
      if (r.ok) return;
    } catch {}
    await new Promise((r) => setTimeout(r, 120));
  }
  throw new Error("服务器没有在超时内就绪");
}

const DB_PATH = path.join(ROOT, DB);

function sql(stmt) {
  execFileSync("php", ["-r", "$p = new PDO('sqlite:' . getenv('LGDB')); $p->exec(getenv('LGSQL'));"], {
    cwd: ROOT,
    env: { ...process.env, LGDB: DB_PATH, LGSQL: stmt },
    stdio: "pipe",
  });
}

function setupDb() {
  for (const suffix of ["", "-wal", "-shm"]) {
    const f = path.join(ROOT, DB + suffix);
    if (fs.existsSync(f)) fs.unlinkSync(f);
  }
  execFileSync("php", [
    "bin/setup-cli.php", "--force",
    "--db", DB,
    "--site", "LightGroup", "--title", "LightGroup",
    "--invite", INVITE, "--admin", ADMIN, "--password", "testpass",
    "--auth-mode", "none",
  ], { cwd: ROOT, stdio: "pipe", encoding: "utf8" });
}

// ---- 备份 / 恢复现场 ----
const cfgPath = path.join(ROOT, "config.php");
const lockPath = path.join(ROOT, ".lock");
const bakConfig = fs.existsSync(cfgPath) ? fs.readFileSync(cfgPath) : null;
const bakLock = fs.existsSync(lockPath) ? fs.readFileSync(lockPath) : null;

let server = null;
const uploaded = [];
function cleanup() {
  if (server && !server.killed) {
    try { server.kill("SIGKILL"); } catch {}
    // Windows 上 server.kill 有时杀不掉 php -S，漏下的进程会占着端口让下一轮测试打到旧进程
    if (process.platform === "win32" && server.pid) {
      try { execFileSync("taskkill", ["/F", "/T", "/PID", String(server.pid)], { stdio: "ignore" }); } catch {}
    }
  }
  if (bakConfig) fs.writeFileSync(cfgPath, bakConfig); else if (fs.existsSync(cfgPath)) fs.unlinkSync(cfgPath);
  if (bakLock) fs.writeFileSync(lockPath, bakLock); else if (fs.existsSync(lockPath)) fs.unlinkSync(lockPath);
  for (const suffix of ["", "-wal", "-shm"]) {
    const f = path.join(ROOT, DB + suffix);
    if (fs.existsSync(f)) { try { fs.unlinkSync(f); } catch {} }
  }
  for (const rel of uploaded) {
    const p = path.join(ROOT, "public", rel);
    if (fs.existsSync(p)) { try { fs.unlinkSync(p); } catch {} }
  }
}

try {
  setupDb();
  server = spawn("php", ["-S", `127.0.0.1:${PORT}`, "bin/router.php"], { cwd: ROOT, stdio: ["ignore", "pipe", "pipe"] });
  server.stdout.on("data", (d) => logs.push(d.toString()));
  server.stderr.on("data", (d) => logs.push(d.toString()));
  await waitReady();

  console.log("\n[P0] 兼容层五接口\n");

  let humanToken = "";
  let humanId = 0;
  const nick = "测试者" + Math.floor(Math.random() * 10000);

  await t("join 人类进群", async () => {
    const { status, data } = await req("GET", `join?name=${encodeURIComponent(nick)}&invite=${INVITE}`);
    eq(status, 200, "HTTP");
    eq(data.ok, true, "ok");
    assert(typeof data.token === "string" && data.token.length === 64, "token 应为 64 位 hex");
    assert(data.title === "LightGroup", "title 不对");
    assert(Array.isArray(data.members) && data.members.some((m) => m.name === ADMIN), "members 里应有管理员");
    humanToken = data.token;
    humanId = data.members.find((m) => m.name === nick).id;
  });

  await t("join 同名重进（幂等，token 重新签发）", async () => {
    const { data } = await req("GET", `join?name=${encodeURIComponent(nick)}&invite=${INVITE}`);
    eq(data.ok, true, "ok");
    assert(typeof data.token === "string" && data.token.length === 64, "应重新给 token");
    humanToken = data.token;
  });

  await t("join 错误邀请码被拒", async () => {
    const { data } = await req("GET", "join?name=路人&invite=wrong");
    eq(data.ok, false, "ok");
    assert(data.err.length > 0, "应带 err");
  });

  await t("不存在的群返回错误 JSON", async () => {
    const res = await fetch(`http://127.0.0.1:${PORT}/g/nope/join?name=a&invite=${INVITE}`);
    const data = JSON.parse(await res.text());
    eq(res.status, 200, "HTTP 应是 200（客户端只对 404 特判）");
    eq(data.ok, false, "ok");
  });

  let firstId = 0;
  let secondId = 0;

  await t("send 发消息", async () => {
    const a = await req("POST", "api/send", { token: humanToken, text: "第一条" });
    eq(a.data.ok, true, "ok");
    assert(Number.isInteger(a.data.msg.id) && a.data.msg.id > 0, "msg.id 应为正整数");
    assert(Number.isInteger(a.data.msg.ts) && a.data.msg.ts > 1600000000000, "msg.ts 应为毫秒");
    firstId = a.data.msg.id;

    const b = await req("POST", "api/send", { token: humanToken, text: "第二条" });
    assert(b.data.msg.id > firstId, "id 应单调递增");
    secondId = b.data.msg.id;
  });

  await t("read 全量读到消息且字段齐全", async () => {
    const { data } = await req("GET", `api/read?token=${humanToken}&since=0&limit=200`);
    eq(data.ok, true, "ok");
    const mine = data.msgs.filter((m) => m.id === firstId)[0];
    assert(mine, "应读到第一条");
    eq(mine.name, nick, "name");
    eq(mine.kind, "human", "kind");
    eq(mine.text, "第一条", "text");
    assert(typeof mine.ts === "number", "ts 缺失");
  });

  await t("read 用 since 游标只拿新消息", async () => {
    const { data } = await req("GET", `api/read?token=${humanToken}&since=${firstId}&limit=200`);
    eq(data.ok, true, "ok");
    assert(data.msgs.every((m) => m.id > firstId), "不应返回旧消息");
    assert(data.msgs.some((m) => m.id === secondId), "应包含第二条");
  });

  await t("api/me 返回身份与本群成员", async () => {
    const { data } = await req("GET", `api/me?token=${humanToken}`);
    eq(data.ok, true, "ok");
    eq(data.me.name, nick, "me.name");
    eq(data.me.kind, "human", "me.kind");
    assert(Array.isArray(data.members) && data.members.length >= 2, "members");
    eq(data.title, "LightGroup", "title");
  });

  let aiToken = "";

  await t("link 创建 AI 身份", async () => {
    const { data } = await req("POST", "api/link", { invite: INVITE, owner: nick, name: "小助手" });
    eq(data.ok, true, "ok");
    assert(typeof data.token === "string" && data.token.length === 64, "AI token");
    eq(data.bot.name, "小助手", "bot.name");
    aiToken = data.token;
  });

  await t("link 重复创建返回 again（重签 token，旧的手里作废）", async () => {
    const { data } = await req("POST", "api/link", { invite: INVITE, owner: nick, name: "小助手" });
    eq(data.ok, true, "ok");
    eq(data.again, true, "again 应为 true");
    assert(typeof data.token === "string" && data.token.length === 64, "again 也要给 token");
    aiToken = data.token;
  });

  await t("AI 能读能发，kind 为 ai 且带 owner", async () => {
    const send = await req("POST", "api/send", { token: aiToken, text: "我是 AI" });
    eq(send.data.ok, true, "send.ok");
    const read = await req("GET", `api/read?token=${humanToken}&since=${secondId}&limit=50`);
    const aiMsg = read.data.msgs.filter((m) => m.id === send.data.msg.id)[0];
    assert(aiMsg, "人类应能看到 AI 的消息");
    eq(aiMsg.kind, "ai", "kind");
    eq(aiMsg.owner, nick, "owner");
  });

  await t("无效 token 被拒", async () => {
    const { data } = await req("GET", "api/read?token=deadbeef&since=0");
    eq(data.ok, false, "ok");
  });

  await t("空消息被拒", async () => {
    const { data } = await req("POST", "api/send", { token: humanToken, text: "   " });
    eq(data.ok, false, "ok");
  });

  console.log("\n[会话] 网页登录态\n");

  await t("GET /login 渲染登录页（昵称 + 邀请码 + 管理员入口）", async () => {
    const res = await fetch(`http://127.0.0.1:${PORT}/login`);
    eq(res.status, 200, "status");
    const html = await res.text();
    assert(html.indexOf('name="invite"') > 0, "应有邀请码输入框");
    assert(html.indexOf('name="name"') > 0, "应有昵称输入框");
    assert(html.indexOf("管理员登录") > 0, "应有管理员登录入口");
  });

  await t("POST /login 用昵称+邀请码建会话，之后免 token 调接口", async () => {
    const { res, cookie } = await formLogin({ name: nick, invite: INVITE, password: PASS });
    eq(res.status, 302, "成功应 302");
    assert(res.headers.get("location") === "/app", "应回 /app，实际: " + res.headers.get("location"));
    assert(cookie.indexOf("lgb=") === 0, "应下发会话 cookie，实际: " + cookie);
    webCookie = cookie;
    const me = await fetch(`http://127.0.0.1:${PORT}/g/api/me`, { headers: { Cookie: cookie } });
    const data = JSON.parse(await me.text());
    eq(data.ok, true, "ok");
    eq(data.me.name, nick, "me.name");
  });

  await t("邀请码不对留在登录页就地报错，且不建立会话", async () => {
    const { res, cookie } = await formLogin({ name: "路人甲", invite: "wrong-code", password: PASS });
    eq(res.status, 200, "失败留在登录页");
    const html = await res.text();
    assert(html.indexOf("邀请码不对") > 0, "登录页应显示邀请码错误");
    const me = await fetch(`http://127.0.0.1:${PORT}/g/api/me`, { headers: { Cookie: cookie } });
    eq(JSON.parse(await me.text()).ok, false, "不应登录");
  });

  await t("未登录访问 /app 302 到 /login?next=/app", async () => {
    const res = await fetch(`http://127.0.0.1:${PORT}/app`, { redirect: "manual" });
    eq(res.status, 302, "应 302");
    eq(res.headers.get("location"), "/login?next=%2Fapp", "location");
  });

  await t("登录后访问 /app 直接给主界面", async () => {
    const res = await fetch(`http://127.0.0.1:${PORT}/app`, { redirect: "manual", headers: { Cookie: webCookie } });
    eq(res.status, 200, "status");
    const html = await res.text();
    assert(html.indexOf("__LG_BOOT__") > 0, "应是 app-shell");
    assert(html.indexOf(nick) > 0, "BOOT 里应带上当前成员");
  });

  await t("GET /logout 清会话并 302 回 /", async () => {
    // 另开一个会话做退出，别把后面用例要用的 webCookie 弄没了
    const again = await formLogin({ name: nick, invite: INVITE, password: PASS });
    assert(again.cookie.indexOf("lgb=") === 0, "应拿到会话 cookie");
    const out = await fetch(`http://127.0.0.1:${PORT}/logout`, { redirect: "manual", headers: { Cookie: again.cookie } });
    eq(out.status, 302, "应 302");
    eq(out.headers.get("location"), "/", "应回首页");
    const me = await fetch(`http://127.0.0.1:${PORT}/g/api/me`, { headers: { Cookie: lastCookie(out) || again.cookie } });
    eq(JSON.parse(await me.text()).ok, false, "会话应已清掉");
  });

  await t("api/me 带回本群邀请码（网页加 AI 要用）", async () => {
    const me = await fetch(`http://127.0.0.1:${PORT}/g/api/me`, { headers: { Cookie: webCookie } });
    const data = JSON.parse(await me.text());
    eq(data.invite, INVITE, "invite");
  });

  console.log("\n[会话] 人机验证与账号语义\n");

  await t("AI 账号不能网页登录", async () => {
    const { res } = await formLogin({ name: "小助手", invite: INVITE, password: PASS });
    eq(res.status, 200, "应留在登录页");
    const html = await res.text();
    assert(html.indexOf("AI 账号用 token 接插件") > 0, "应说明 AI 用 token 进插件");
  });

  await t("密码不对不给进", async () => {
    const { res } = await formLogin({ name: nick, invite: INVITE, password: "wrongpass" });
    eq(res.status, 200, "应留在登录页");
    const html = await res.text();
    assert(html.indexOf("昵称或密码不对") > 0, "应报密码错");
  });

  await t("首次登录认领密码，之后认这套密码", async () => {
    sql("UPDATE members SET pass_hash = NULL WHERE scope_id = 1 AND name = '" + nick + "'");
    const first = await formLogin({ name: nick, invite: INVITE, password: "claim8888" });
    eq(first.res.status, 302, "首次应建会话并认领密码");
    const again = await formLogin({ name: nick, invite: INVITE, password: "claim8888" });
    eq(again.res.status, 302, "再用同一密码应还能进");
    const back = await formLogin({ name: nick, invite: INVITE, password: PASS });
    eq(back.res.status, 200, "换成老密码就不该进");
  });

  sql("DELETE FROM login_fails");
  sql("INSERT OR REPLACE INTO settings (k, v) VALUES ('auth.mode', 'captcha')");

  await t("开了滑块，不带人机验证登录被拒", async () => {
    const { res } = await formLogin({ name: nick, invite: INVITE, password: "claim8888" });
    eq(res.status, 200, "应留在登录页");
    const html = await res.text();
    assert(html.indexOf("人机验证没过") > 0, "应报人机验证没过");
  });

  await t("滑块拖到位并带轨迹就能过", async () => {
    const cap = JSON.parse(await (await fetch(`http://127.0.0.1:${PORT}/captcha/start`)).text());
    eq(cap.ok, true, "应发挑战");
    assert(cap.target >= 58 && cap.target <= 88, "target 应在可视区内");
    const { res } = await formLogin({
      name: nick, invite: INVITE, password: "claim8888",
      cap_token: cap.token, cap_off: String(cap.target), cap_ms: "900", cap_moves: "8",
    });
    eq(res.status, 302, "应登录成功，实际: " + res.headers.get("location"));
  });

  await t("滑块没拖到缺口、或太快，都过不了", async () => {
    const c1 = JSON.parse(await (await fetch(`http://127.0.0.1:${PORT}/captcha/start`)).text());
    const far = await formLogin({
      name: nick, invite: INVITE, password: "claim8888",
      cap_token: c1.token, cap_off: String(c1.target + 30), cap_ms: "900", cap_moves: "8",
    });
    eq(far.res.status, 200, "错位不该过");
    const c2 = JSON.parse(await (await fetch(`http://127.0.0.1:${PORT}/captcha/start`)).text());
    const fast = await formLogin({
      name: nick, invite: INVITE, password: "claim8888",
      cap_token: c2.token, cap_off: String(c2.target), cap_ms: "80", cap_moves: "8",
    });
    eq(fast.res.status, 200, "瞬移不该过");
    const fake = await formLogin({
      name: nick, invite: INVITE, password: "claim8888",
      cap_token: "60.9999999999999.abcdefabcdef.deadbeefdeadbeefdeadbeef", cap_off: "60", cap_ms: "900", cap_moves: "8",
    });
    eq(fake.res.status, 200, "伪造 token 不该过");
  });

  await t("同一个昵称连续试错会被拦住", async () => {
    sql("DELETE FROM login_fails");
    for (let i = 0; i < 5; i++) {
      await formLogin({ name: nick, invite: INVITE, password: "bad-pass-" + i });
    }
    const locked = await formLogin({ name: nick, invite: INVITE, password: "claim8888" });
    eq(locked.res.status, 200, "应被拦住");
    const html = await locked.res.text();
    assert(html.indexOf("试了太多次") > 0, "应提示稍后再来，实际没拦住");
  });

  sql("DELETE FROM login_fails");
  sql("INSERT OR REPLACE INTO settings (k, v) VALUES ('auth.mode', 'none')");
  sql("UPDATE members SET pass_hash = NULL WHERE scope_id = 1 AND name = '" + nick + "'");

  await t("关掉人机验证后又能直接登录", async () => {
    const { res } = await formLogin({ name: nick, invite: INVITE, password: "claim8888" });
    eq(res.status, 302, "应能直接登录");
  });

  await t("网页登录态下能给自己的 AI 建 token", async () => {
    const r = await fetch(`http://127.0.0.1:${PORT}/g/api/link`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: webCookie },
      body: JSON.stringify({ invite: INVITE, owner: nick, name: "网页AI" }),
    });
    const data = JSON.parse(await r.text());
    eq(data.ok, true, "ok");
    assert(data.token && data.token.length > 20, "应返回 token");
    eq(data.bot.name, "网页AI", "bot.name");
  });

  console.log("\n[P1] wait / upload / doc\n");

  await t("wait 有新消息时立刻返回", async () => {
    const r = await req("GET", `api/read?token=${humanToken}&since=0&limit=200`);
    const lastId = r.data.msgs.length ? r.data.msgs[r.data.msgs.length - 1].id : 0;
    await req("POST", "api/send", { token: humanToken, text: "回来" });
    const t0 = Date.now();
    const { data } = await req("GET", `api/wait?token=${aiToken}&since=${lastId}&seconds=5`);
    const cost = Date.now() - t0;
    eq(data.ok, true, "ok");
    assert(data.msgs.length >= 1, "应返回刚发的消息");
    assert(data.lastId === data.msgs[data.msgs.length - 1].id, "lastId 应为最后一条");
    assert(cost < 3000, `应立即返回，实际耗时 ${cost}ms`);
  });

  await t("wait 没消息时挂到超时返回空", async () => {
    const r = await req("GET", `api/read?token=${humanToken}&since=0&limit=200`);
    const lastId = r.data.msgs.length ? r.data.msgs[r.data.msgs.length - 1].id : 0;
    const t0 = Date.now();
    const { data } = await req("GET", `api/wait?token=${humanToken}&since=${lastId}&seconds=2`);
    const cost = Date.now() - t0;
    eq(data.ok, true, "ok");
    eq(data.msgs.length, 0, "超时不应有消息");
    eq(data.lastId, lastId, "lastId 应回传游标");
    assert(cost >= 1700 && cost < 6000, `应挂满 2 秒，实际 ${cost}ms`);
  });

  await t("wait seconds 超过 25 秒被截断", async () => {
    const r = await req("GET", `api/read?token=${humanToken}&since=0&limit=200`);
    const lastId = r.data.msgs.length ? r.data.msgs[r.data.msgs.length - 1].id : 0;
    const t0 = Date.now();
    await req("GET", `api/wait?token=${humanToken}&since=${lastId}&seconds=1`);
    const cost = Date.now() - t0;
    assert(cost < 4000, `1 秒应很快返回，实际 ${cost}ms`);
  });

  await t("upload 收图返回可访问 URL", async () => {
    const png = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==";
    const { data } = await req("POST", "api/upload", { token: humanToken, data: "data:image/png;base64," + png });
    eq(data.ok, true, "ok: " + JSON.stringify(data));
    assert(/^https?:\/\/.+\/uploads\/.+\.png$/.test(data.url), "url 形态不对: " + data.url);
    const res = await fetch(data.url);
    eq(res.status, 200, "图片应能直接访问");
    const buf = Buffer.from(await res.arrayBuffer());
    assert(buf.length > 50 && buf[0] === 0x89 && buf[1] === 0x50, "返回的不是 PNG");
    uploaded.push(new URL(data.url).pathname);
  });

  await t("upload 非图片被拒", async () => {
    const { data } = await req("POST", "api/upload", { token: humanToken, data: "data:image/png;base64,AAAA" });
    eq(data.ok, false, "ok");
  });

  await t("upload 非 dataURL 被拒", async () => {
    const { data } = await req("POST", "api/upload", { token: humanToken, data: "not-an-image" });
    eq(data.ok, false, "ok");
  });

  await t("doc 两版手册都能打开", async () => {
    for (const t of ["ai", "user"]) {
      const res = await fetch(`http://127.0.0.1:${PORT}/g/api/doc?t=${t}`);
      eq(res.status, 200, t + " HTTP");
      const html = await res.text();
      assert(html.includes("LightGroup"), t + " 缺站点名");
      assert(html.includes("/g/"), t + " 缺群地址");
    }
  });

  console.log("\n[P2] 引用 / 撤回 / 资料 / 样式\n");

  let adminToken = "";

  await t("给管理员挂一个已知 token，便于测管理操作", async () => {
    const h = createHash("sha256").update("admintoken").digest("hex");
    sql(`UPDATE members SET token_hash = "${h}" WHERE name = "管理员"`);
    adminToken = "admintoken";
    const me = await req("GET", `api/me?token=${adminToken}`);
    eq(me.data.ok, true, "ok");
    eq(me.data.me.role, "super", "超管角色");
  });

  let quotedId = 0;

  await t("引用：send 带 reply，读出来有 reply 摘要", async () => {
    const a = await req("POST", "api/send", { token: humanToken, text: "原消息" });
    quotedId = a.data.msg.id;
    const b = await req("POST", "api/send", { token: aiToken, text: "引用一下", reply: quotedId });
    eq(b.data.ok, true, "send.ok");
    const r = await req("GET", `api/read?token=${humanToken}&since=${quotedId}&limit=50`);
    const msg = r.data.msgs.filter((m) => m.id === b.data.msg.id)[0];
    assert(msg && msg.reply, "应有 reply");
    eq(msg.reply.id, quotedId, "reply.id");
    eq(msg.reply.name, nick, "reply.name");
    eq(msg.reply.text, "原消息", "reply.text");
  });

  await t("引用不存在的消息被拒", async () => {
    const { data } = await req("POST", "api/send", { token: humanToken, text: "引用鬼", reply: 999999 });
    eq(data.ok, false, "ok");
  });

  await t("撤回自己的消息（默认 5 分钟内）", async () => {
    const a = await req("POST", "api/send", { token: humanToken, text: "这句要撤回" });
    const id = a.data.msg.id;
    const r = await req("POST", "api/recall", { token: humanToken, id });
    eq(r.data.ok, true, "recall.ok");
    const read = await req("GET", `api/read?token=${humanToken}&since=${id - 1}&limit=5`);
    const msg = read.data.msgs.filter((m) => m.id === id)[0];
    eq(msg.recalled, true, "recalled");
    eq(msg.text, "", "撤回后正文应清空");
  });

  await t("撤回别人的消息：普通成员被拒，管理员可以", async () => {
    const a = await req("POST", "api/send", { token: humanToken, text: "别人别动" });
    const id = a.data.msg.id;
    const bad = await req("POST", "api/recall", { token: aiToken, id });
    eq(bad.data.ok, false, "AI 不该能撤回别人的");
    const good = await req("POST", "api/recall", { token: adminToken, id });
    eq(good.data.ok, true, "超管应能撤回");
  });

  await t("重新撤回同一条被拒", async () => {
    const a = await req("POST", "api/send", { token: humanToken, text: "重复撤回" });
    const id = a.data.msg.id;
    await req("POST", "api/recall", { token: humanToken, id });
    const again = await req("POST", "api/recall", { token: humanToken, id });
    eq(again.data.ok, false, "ok");
  });

  await t("策略 5m：超过 5 分钟不能撤回", async () => {
    const a = await req("POST", "api/send", { token: humanToken, text: "六分钟前" });
    const id = a.data.msg.id;
    sql(`UPDATE messages SET ts_ms = ${Date.now() - 6 * 60 * 1000} WHERE id = ${id}`);
    const r = await req("POST", "api/recall", { token: humanToken, id });
    eq(r.data.ok, false, "ok");
  });

  await t("策略 until_other：别人发过就不能撤回", async () => {
    sql("UPDATE scopes SET recall_policy = 'until_other'");
    const a = await req("POST", "api/send", { token: humanToken, text: "我先说" });
    const first = a.data.msg.id;
    await req("POST", "api/send", { token: aiToken, text: "我接一句" });
    const bad = await req("POST", "api/recall", { token: humanToken, id: first });
    eq(bad.data.ok, false, "有人接话后不该能撤回");
    const b = await req("POST", "api/send", { token: humanToken, text: "最后一条" });
    const good = await req("POST", "api/recall", { token: humanToken, id: b.data.msg.id });
    eq(good.data.ok, true, "最后一条应能撤回");
  });

  await t("策略 forever：很久以前的也能撤回", async () => {
    sql("UPDATE scopes SET recall_policy = 'forever'");
    const a = await req("POST", "api/send", { token: humanToken, text: "远古消息" });
    const id = a.data.msg.id;
    sql(`UPDATE messages SET ts_ms = ${Date.now() - 30 * 24 * 3600 * 1000} WHERE id = ${id}`);
    const r = await req("POST", "api/recall", { token: humanToken, id });
    eq(r.data.ok, true, "ok");
    sql("UPDATE scopes SET recall_policy = '5m'");
  });

  await t("profile：读得到、改得动、重名被拒", async () => {
    const g = await req("GET", `api/profile?token=${humanToken}`);
    eq(g.data.ok, true, "ok");
    eq(g.data.profile.name, nick, "name");
    eq(g.data.profile.role, "member", "role");
    const p = await req("POST", "api/profile", { token: humanToken, bio: "我是测试号", avatar: "/uploads/x.png" });
    eq(p.data.ok, true, "ok");
    eq(p.data.profile.bio, "我是测试号", "bio");
    eq(p.data.profile.avatar, "/uploads/x.png", "avatar");
    const dup = await req("POST", "api/profile", { token: humanToken, name: "管理员" });
    eq(dup.data.ok, false, "重名要拒");
  });

  await t("styles：默认空、能存能取、坏内容被拒", async () => {
    const empty = await req("GET", `api/styles?token=${humanToken}`);
    eq(empty.data.ok, true, "ok");
    eq(empty.data.css, "", "默认应是空");
    const css = ".bub{border-radius:18px;background:#222}";
    const w = await req("POST", "api/styles", { token: humanToken, css });
    eq(w.data.ok, true, "保存 ok");
    const r = await req("GET", `api/styles?token=${humanToken}`);
    eq(r.data.css, css, "读回一致");
    const bad = await req("POST", "api/styles", { token: humanToken, css: "</style><script>alert(1)</script>" });
    eq(bad.data.ok, false, "应拒绝");
    await req("POST", "api/styles", { token: humanToken, css: "" });
  });

  await t("cap 返回能力与限制", async () => {
    const { data } = await req("GET", "api/cap");
    eq(data.ok, true, "ok");
    assert(Array.isArray(data.features) && data.features.includes("recall"), "features");
    eq(data.limits.read_max, 200, "read_max");
  });

  await t("头像上传后 api/me 里能看到", async () => {
    const png = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==";
    const { data } = await req("POST", "api/upload", { token: humanToken, data: "data:image/png;base64," + png, kind: "avatar" });
    eq(data.ok, true, "ok: " + JSON.stringify(data));
    assert(/\/a_/.test(data.url), "头像应带 a_ 前缀: " + data.url);
    uploaded.push(new URL(data.url).pathname);
    const me = await req("GET", `api/me?token=${humanToken}`);
    eq(me.data.me.avatar, data.url, "me.avatar");
  });

  console.log("\n[P2] API key 与 api/v1\n");

  let apiKey = "";
  let apiKeyId = 0;

  async function v1(method, p, body, useBearer = true) {
    const opt = { method, headers: {} };
    if (useBearer) opt.headers.Authorization = "Bearer " + apiKey;
    if (body !== undefined) {
      opt.headers["Content-Type"] = "application/json";
      opt.body = JSON.stringify(body);
    }
    const res = await fetch(BASE + "api/v1/" + p, opt);
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch { throw new Error(p + " 返回非 JSON: " + text.slice(0, 200)); }
    return { status: res.status, data };
  }

  await t("网页登录态下能创建 API key，且只返回一次明文", async () => {
    const res = await fetch(BASE + "api/v1/keys", {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: webCookie },
      body: JSON.stringify({ name: "测试客户端" }),
    });
    const data = JSON.parse(await res.text());
    eq(data.ok, true, "ok: " + JSON.stringify(data));
    assert(/^lgk_/.test(data.key), "key 应有前缀: " + data.key);
    apiKey = data.key;
    apiKeyId = data.id;
  });

  await t("key 列表里能看到刚建的那把", async () => {
    const res = await fetch(BASE + "api/v1/keys", { headers: { Cookie: webCookie } });
    const data = JSON.parse(await res.text());
    eq(data.ok, true, "ok");
    const mine = data.keys.filter((k) => k.id === apiKeyId)[0];
    assert(mine, "列表里应有这把 key");
    eq(mine.name, "测试客户端", "name");
    eq(mine.revoked, false, "未作废");
  });

  await t("没登录建不了 key", async () => {
    const res = await fetch(BASE + "api/v1/keys", { method: "POST", headers: { "Content-Type": "application/json" }, body: "{}" });
    const data = JSON.parse(await res.text());
    eq(data.ok, false, "应拒绝");
  });

  await t("api/v1/me 用 Bearer 鉴权", async () => {
    const { data } = await v1("GET", "me");
    eq(data.ok, true, "ok: " + JSON.stringify(data));
    eq(data.me.name, nick, "me.name");
    eq(data.me.kind, "human", "me.kind");
  });

  await t("api/v1/messages 读得到历史", async () => {
    const { data } = await v1("GET", "messages?since=0&limit=50");
    eq(data.ok, true, "ok");
    assert(data.msgs.length > 0, "应有历史消息");
  });

  await t("api/v1/messages 发消息，署名是 key 的主人", async () => {
    const sent = await v1("POST", "messages", { text: "来自第三方客户端" });
    eq(sent.data.ok, true, "send.ok: " + JSON.stringify(sent.data));
    const read = await v1("GET", "messages?since=" + (sent.data.msg.id - 1) + "&limit=5");
    const msg = read.data.msgs.filter((m) => m.id === sent.data.msg.id)[0];
    assert(msg, "应能读回");
    eq(msg.name, nick, "name");
    eq(msg.text, "来自第三方客户端", "text");
  });

  await t("api/v1 也能撤回自己的消息", async () => {
    const sent = await v1("POST", "messages", { text: "第三方撤回" });
    const id = sent.data.msg.id;
    const r = await v1("POST", "messages/recall", { id });
    eq(r.data.ok, true, "recall.ok");
  });

  await t("api/v1/members 列出成员", async () => {
    const { data } = await v1("GET", "members");
    eq(data.ok, true, "ok");
    assert(data.members.length >= 2, "应有成员");
  });

  await t("没有 key 或 key 不对都被拒", async () => {
    const none = await v1("GET", "me", undefined, false);
    eq(none.data.ok, false, "无 key 应拒");
    const res = await fetch(BASE + "api/v1/me", { headers: { Authorization: "Bearer lgk_nope" } });
    const bad = JSON.parse(await res.text());
    eq(bad.ok, false, "错 key 应拒");
  });

  await t("撤销后这把 key 立刻失效", async () => {
    const res = await fetch(BASE + "api/v1/keys/" + apiKeyId, { method: "POST", headers: { Cookie: webCookie } });
    const data = JSON.parse(await res.text());
    eq(data.ok, true, "revoke.ok: " + JSON.stringify(data));
    const after = await v1("GET", "me");
    eq(after.data.ok, false, "作废后应拒");
  });

  console.log("\n[P2] 翻译\n");

  await t("翻译接口能把英文翻成中文", async () => {
    const { data } = await req("POST", "api/translate", { token: humanToken, text: "hello world, this is a test", to: "zh-Hans" });
    eq(data.ok, true, "ok: " + JSON.stringify(data));
    assert(/[\u4e00-\u9fa5]/.test(data.text), "应含汉字，实际: " + data.text);
    eq(data.to, "zh-Hans", "to");
  });

  await t("翻译空内容被拒", async () => {
    const { data } = await req("POST", "api/translate", { token: humanToken, text: "   " });
    eq(data.ok, false, "ok");
  });

  console.log("\n[P3] 管理后台\n");

  const SUPER_TOK = "supertoken_aaaabbbbccccddddeeeeffff00001111";
  const superHash = createHash("sha256").update(SUPER_TOK).digest("hex");
  sql("UPDATE members SET token_hash = '" + superHash + "' WHERE name = '" + ADMIN + "' AND kind = 'human'");

  async function webLogin() {
    const { res, cookie } = await formLogin({ mode: "admin", name: ADMIN, password: "testpass" });
    eq(res.status, 302, "管理员登录应 302");
    return cookie;
  }
  async function areq(method, sub, body, cookie, slug) {
    const pre = slug ? slug + "/" : "";
    const opt = { method, headers: {} };
    if (cookie) opt.headers.Cookie = cookie;
    if (body !== undefined) { opt.headers["Content-Type"] = "application/json"; opt.body = JSON.stringify(body); }
    const res = await fetch(BASE + pre + "api/admin/" + sub, opt);
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch { throw new Error("admin/" + sub + " 返回非 JSON: " + text.slice(0, 200)); }
    return { status: res.status, data };
  }

  const superCookie = await webLogin();
  assert(superCookie.indexOf("lgb=") === 0, "超管会话 cookie 应下发，实际 " + superCookie);

  await t("管理接口未登录被拒", async () => {
    const { data } = await areq("GET", "overview", undefined, "");
    eq(data.ok, false, "ok");
  });

  await t("普通成员被拒", async () => {
    const { data } = await areq("GET", "overview", undefined, webCookie);
    eq(data.ok, false, "ok");
    assert(data.err.indexOf("权限") >= 0, "err 应提到权限: " + data.err);
  });

  await t("超管能看总览", async () => {
    const { data } = await areq("GET", "overview", undefined, superCookie);
    eq(data.ok, true, "ok");
    eq(data.role, "super", "role");
    assert(Array.isArray(data.scopes) && data.scopes.length >= 1, "应有群列表");
    assert(typeof data.scopes[0].invite === "string", "超管应能看到邀请码");
  });

  let newScopeId = 0;
  let newInvite = "";
  await t("超管新建群", async () => {
    const { data } = await areq("POST", "scopes", { title: "第二个群", slug: "g2" }, superCookie);
    eq(data.ok, true, "ok");
    newScopeId = data.id;
    newInvite = data.invite;
    assert(newScopeId > 0 && newInvite.length >= 4, "应返回 id 与邀请码");
  });

  await t("新群是独立作用域（自己的邀请码）", async () => {
    const { data } = await req("GET", "g2/join?name=群的第二人&invite=" + newInvite);
    eq(data.ok, true, "ok");
    eq(data.title, "第二个群", "title");
    const bad = await req("GET", "g2/join?name=走错门&invite=" + INVITE);
    eq(bad.data.ok, false, "老邀请码不该能进新群");
  });

  await t("邀请码只有超管能改", async () => {
    const { data } = await areq("POST", "scopes/" + newScopeId + "/invite", { invite: "newcode" }, superCookie);
    eq(data.ok, true, "ok");
    eq(data.invite, "newcode", "invite");
    const old = await req("GET", "g2/join?name=再来一次&invite=" + newInvite);
    eq(old.data.ok, false, "旧邀请码应失效");
    const now = await req("GET", "g2/join?name=再来一次&invite=newcode");
    eq(now.data.ok, true, "新邀请码可用");
    newInvite = "newcode";
  });

  await t("群容量与撤回策略可改", async () => {
    const { data } = await areq("POST", "scopes/" + newScopeId, { human_cap: 5, recall_policy: "forever" }, superCookie);
    eq(data.ok, true, "ok");
    const list = await areq("GET", "scopes", undefined, superCookie);
    const row = list.data.scopes.find((s) => s.id === newScopeId);
    eq(row.human_cap, 5, "human_cap");
    eq(row.recall_policy, "forever", "recall_policy");
  });

  await t("互通后两边时间线拉通", async () => {
    const c = await areq("POST", "clusters", { name: "测试圈" }, superCookie);
    eq(c.data.ok, true, "建圈");
    const cid = c.data.id;
    const j1 = await areq("POST", "clusters/" + cid + "/scopes", { scope_id: 1, join: 1 }, superCookie);
    eq(j1.data.ok, true, "并入默认群");
    const j2 = await areq("POST", "clusters/" + cid + "/scopes", { scope_id: newScopeId, join: 1 }, superCookie);
    eq(j2.data.ok, true, "并入新群");
    const sent = await req("POST", "api/send", { token: humanToken, text: "互通测试消息" });
    eq(sent.data.ok, true, "默认群发消息");
    const r2 = await req("GET", "g2/join?name=旁观者" + newScopeId + "&invite=" + newInvite);
    eq(r2.data.ok, true, "新群进人");
    const read = await req("GET", "g2/api/read?token=" + r2.data.token + "&since=0");
    eq(read.data.ok, true, "ok");
    assert(read.data.msgs.some((m) => m.text === "互通测试消息"), "新群应能看到默认群的消息");
    const wk = await areq("POST", "clusters/" + cid + "/scopes", { scope_id: newScopeId, join: 0 }, superCookie);
    eq(wk.data.ok, true, "移出圈子");
  });

  await t("封禁与解封", async () => {
    const list = await areq("GET", "members?scope=1", undefined, superCookie);
    eq(list.data.ok, true, "成员列表");
    const target = list.data.members.find((m) => m.name === nick);
    assert(target, "应能查到 " + nick);
    await areq("POST", "members/" + target.id + "/ban", { on: 1 }, superCookie);
    const blocked = await req("GET", "api/read?token=" + humanToken + "&since=0");
    eq(blocked.data.ok, false, "被封后应被拒");
    await areq("POST", "members/" + target.id + "/ban", { on: 0 }, superCookie);
    const back = await req("GET", "api/read?token=" + humanToken + "&since=0");
    eq(back.data.ok, true, "解封后恢复");
  });

  await t("站点设置可读写", async () => {
    const g = await areq("GET", "settings", undefined, superCookie);
    eq(g.data.ok, true, "读取");
    const s = await areq("POST", "settings", { "site.intro": "测试介绍", "quota.ai_per_human": "3" }, superCookie);
    eq(s.data.ok, true, "写入");
    const g2 = await areq("GET", "settings", undefined, superCookie);
    eq(g2.data.settings["site.intro"], "测试介绍", "intro");
    eq(g2.data.settings["quota.ai_per_human"], "3", "quota");
    await areq("POST", "settings", { "quota.ai_per_human": "0" }, superCookie);
  });

  await t("普通管理员只能管自己的群", async () => {
    sql("INSERT INTO accounts (name, role, scope_id, created_at) VALUES ('admintest', 'admin', 1, 0)");
    sql("UPDATE members SET account_id = (SELECT id FROM accounts WHERE name = 'admintest') WHERE name = '" + nick + "'");
    const mine = await areq("GET", "overview", undefined, webCookie);
    eq(mine.data.role, "admin", "role");
    eq(mine.data.scopes.length, 1, "只应看到自己那个群");
    assert(mine.data.scopes[0].invite === undefined, "管理员不该拿到邀请码");
    const other = await areq("POST", "scopes/" + newScopeId, { title: "越权改名" }, webCookie);
    eq(other.data.ok, false, "跨域应被拒");
    const inv = await areq("POST", "scopes/" + newScopeId + "/invite", { invite: "hack" }, webCookie);
    eq(inv.data.ok, false, "改邀请码应被拒");
    const mk = await areq("POST", "scopes", { title: "偷偷建群" }, webCookie);
    eq(mk.data.ok, false, "建群应被拒");
    const own = await areq("POST", "scopes/1", { title: "LightGroup" }, webCookie);
    eq(own.data.ok, true, "本域可改");
  });

  await t("管理员回看会话并在会话里留系统提示", async () => {
    const list = await areq("GET", "convs", undefined, superCookie);
    eq(list.data.ok, true, "会话列表");
    assert(list.data.convs.length >= 1, "至少一个会话");
    const pub = list.data.convs.find((c) => c.isPublic);
    assert(pub, "应有公开大群");

    const before = await req("GET", `api/read?token=${humanToken}&since=0&limit=200`);
    const beforeSys = (before.data.msgs || []).filter((m) => m.sys).length;

    const d = await areq("GET", "convs/" + pub.id, undefined, superCookie);
    eq(d.data.ok, true, "会话详情");
    assert((d.data.msgs || []).length > 0, "应能看到消息");

    const after = await req("GET", `api/read?token=${humanToken}&since=0&limit=200`);
    const sysMsgs = (after.data.msgs || []).filter((m) => m.sys);
    eq(sysMsgs.length, beforeSys + 1, "成员那头应多一条系统提示");
    assert(/访问了该会话/.test(sysMsgs[sysMsgs.length - 1].text), "提示文案应含「访问了该会话」");

    await areq("GET", "convs/" + pub.id, undefined, superCookie);
    const again = await req("GET", `api/read?token=${humanToken}&since=0&limit=200`);
    eq((again.data.msgs || []).filter((m) => m.sys).length, beforeSys + 1, "一分钟内不重复留痕");
  });

  await t("普通管理员只能看本域会话", async () => {
    const mine = await areq("GET", "convs", undefined, webCookie);
    eq(mine.data.ok, true, "本域会话列表");
    assert((mine.data.convs || []).every((c) => c.scopeId === 1), "只应看到本域会话");
    const pub = (mine.data.convs || []).find((c) => c.isPublic);
    const one = await areq("GET", "convs/" + pub.id, undefined, webCookie);
    eq(one.data.ok, true, "本域会话可回看");
    const foreign = await areq("GET", "convs/999999", undefined, webCookie);
    eq(foreign.data.ok, false, "不存在的会话被拒");
    const asMember = await req("GET", "api/admin/convs?token=" + humanToken);
    eq(asMember.data.ok, false, "管理接口不认 token 参数");
  });

  await t("好友申请→接受→私聊→第三人不进", async () => {
    // 前面的超管测试把管理员的 token 换成了 SUPER_TOK，这里直接用它当管理员身份
    const admTok = SUPER_TOK;
    const third = await req("GET", `join?name=第三人不进${newScopeId}&invite=${INVITE}`);
    eq(third.data.ok, true, "第三个人进群");
    const thirdToken = third.data.token;
    const me = await req("GET", `api/me?token=${humanToken}`);
    if (!me.data.members) throw new Error("api/me 异常: " + JSON.stringify(me.data));
    const adminId = me.data.members.find((m) => m.name === ADMIN).id;
    const mineId = me.data.me.id;

    const req1 = await req("POST", "api/friends/request", { token: admTok, id: mineId });
    eq(req1.data.ok, true, "管理员发起申请");
    const dup = await req("POST", "api/friends/request", { token: admTok, id: mineId });
    eq(dup.data.ok, false, "重复申请被拒");
    const incoming = await req("GET", `api/friends?token=${humanToken}`);
    assert(incoming.data.incoming.some((f) => f.id === adminId), "incoming 应有管理员");
    const selfAccept = await req("POST", "api/friends/accept", { token: admTok, id: mineId });
    eq(selfAccept.data.ok, false, "申请人不能替对方接受");
    const acc = await req("POST", "api/friends/accept", { token: humanToken, id: adminId });
    eq(acc.data.ok, true, "被申请人接受");
    const list = await req("GET", `api/friends?token=${humanToken}`);
    assert(list.data.friends.some((f) => f.id === adminId), "好友列表应有管理员");

    const dm = await req("POST", "api/dm/open", { token: humanToken, id: adminId });
    eq(dm.data.ok, true, "开私聊");
    eq(dm.data.conv.kind, "dm", "kind 应为 dm");
    const convId = dm.data.conv.id;
    const again = await req("POST", "api/dm/open", { token: admTok, id: mineId });
    eq(again.data.conv.id, convId, "反向开私聊应复用同一会话");

    const sent = await req("POST", "api/conv/send", { token: humanToken, conv: "c_" + convId, text: "私聊悄悄话" });
    eq(sent.data.ok, true, "私聊发送");
    const adminRead = await req("GET", `api/conv?token=${admTok}&id=c_${convId}&since=0`);
    eq(adminRead.data.ok, true, "对方能读私聊");
    assert(adminRead.data.msgs.some((m) => m.text === "私聊悄悄话"), "私聊消息应可见");
    eq(adminRead.data.members.length, 2, "私聊成员只有两人");

    const thirdRead = await req("GET", `api/conv?token=${thirdToken}&id=c_${convId}`);
    eq(thirdRead.data.ok, false, "第三人不能进私聊");
    const thirdFeed = await req("GET", `api/fetch?token=${thirdToken}&since=0&limit=200`);
    assert(!thirdFeed.data.msgs.some((m) => m.text === "私聊悄悄话"), "第三人公开时间线不该有私聊内容");
    const leak = await req("GET", `api/fetch?token=${humanToken}&since=0&limit=200`);
    assert(!leak.data.msgs.some((m) => m.text === "私聊悄悄话"), "不带 conv 的 api/fetch 不该漏私聊");
    const aiDm = await req("GET", `api/conv?token=${aiToken}&id=c_${convId}`);
    eq(aiDm.data.ok, false, "AI 不能看私聊");

    const rm = await req("POST", "api/friends/remove", { token: humanToken, id: adminId });
    eq(rm.data.ok, true, "删好友");
    const still = await req("GET", `api/conv?token=${admTok}&id=c_${convId}`);
    eq(still.data.ok, true, "删好友不解散私聊");
  });

  console.log(`\n结果：${passed} 通过，${failures.length} 失败\n`);
} catch (e) {
  console.error("测试中断:", e.message);
  failures.push({ name: "<runner>", error: e.message });
} finally {
  cleanup();
}

if (failures.length) {
  console.log("失败详情:");
  for (const f of failures) console.log(" - " + f.name + ": " + f.error);
  const err = logs.join("").split("\n").filter((l) => /error|Warning|Fatal|Notice/i.test(l)).slice(0, 10);
  if (err.length) console.log("\n服务器日志:\n" + err.join("\n"));
  process.exit(1);
}
process.exit(0);
