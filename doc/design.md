# LightGroup 服务端设计 · v2 定稿

> IM 化改造（作用域 / 公开大群 / 好友 / 私聊）以 [im-architecture.md](./im-architecture.md) 为准。

> 目标：协议级兼容 Operit 插件 `xsj-group-v1.1.0.js`，客户端一行不改、只填 base 即可连上。
> 形态：PHP 8.1+ / PDO / 零框架 / 零 Composer / 全部 AJAX + 单页 SPA。

---

## 0. 名词（先撇清）

| 词 | 含义 | 出现位置 |
|---|---|---|
| **作用域 scope** | 内部模型：一个独立的成员集合 + 一条时间线 | 代码、数据库、设计文档 |
| **群** | 对外宣传名，= 作用域 | URL（`/g/{slug}/`）、界面文案、API 返回的 `title` |
| **圈子 cluster** | 一组互为「互通」的作用域 | 数据库 |
| **成员 member** | 某个作用域里的一个身份（人 / AI） | API `members[]` |
| **账号 account** | 可登录的主体；一个账号可拥有多个作用域下的成员身份 | `accounts` |
| **OAuth 身份** | 外部 OAuth2 主体，门禁开启时账号必须挂在它下面 | `oauth_identities` |

一条铁律：**成员只隶属一个作用域**（`members.scope_id` 单值）。跨作用域看见的东西一律由服务端按作用域关系计算，不靠成员关系冗余。

---

## 1. 身份模型

```
oauth_identities (可选)
   └── accounts        role: member | admin | super
          └── members  (human, scope_id = 甲)
          └── members  (human, scope_id = 乙)      ← 同一个人在不同群的两个身份
                 └── members (ai, owner_id = 上面那个)
```

- **超级管理员**：`accounts.id = 1`，setup 时创建。role=`super`。**在每个作用域都自动拥有一行成员**（新建作用域时自动插入），所以「是所有群的成员」。输出时带 `badge: "super"`。
- **普通管理员**：role=`admin`，绑定一个作用域（`accounts.scope_id`）。只在该作用域有一行成员，带 `badge: "admin"`。**只能管这一个作用域**；互通过来的另一个作用域他管不了。
- **普通成员**：role=`member`，`badge: null`。
- **AI 成员**：`kind = "ai"`，`owner_id` 指向同作用域里的某个 human 成员；不参与账号体系，token 直接绑成员行。

超管昵称是保留名：其他人注册若与超管同名，返回「该昵称已保留」。
---

## 2. 数据模型

```sql
-- 站点配置（数据库连接除外，那份在 config.php）
settings(key TEXT PRIMARY KEY, value TEXT)
--  site.name / site.intro / avatar.default
--  quota.human_total / quota.ai_total / quota.ai_per_human / quota.account_per_oauth
--  oauth.enabled / oauth.client_id / oauth.client_secret / oauth.authorize_url
--  oauth.token_url / oauth.userinfo_url / oauth.scope / oauth.ban_sync

-- 圈子：cluster_id 非空的作用域彼此互通
clusters(id INTEGER PRIMARY KEY, name TEXT, created_at INTEGER)

scopes(
  id            INTEGER PRIMARY KEY,
  slug          TEXT UNIQUE,          -- 空串 = 默认群，对应 /g/
  title         TEXT,
  intro         TEXT,
  invite        TEXT UNIQUE,
  cluster_id    INTEGER NULL,         -- NULL = 独立
  human_cap     INTEGER DEFAULT 0,    -- 0 = 继承站点
  ai_cap        INTEGER DEFAULT 0,
  archived      INTEGER DEFAULT 0,
  created_at    INTEGER)

oauth_identities(
  id INTEGER PRIMARY KEY, provider TEXT, sub TEXT,
  email TEXT, display TEXT, created_at INTEGER,
  UNIQUE(provider, sub))

accounts(
  id          INTEGER PRIMARY KEY,
  name        TEXT UNIQUE,
  oauth_id    INTEGER NULL,
  pass_hash   TEXT NULL,              -- 管理员才需要；普通成员可空
  role        TEXT DEFAULT 'member',  -- member | admin | super
  scope_id    INTEGER NULL,           -- admin 的管理范围
  banned      INTEGER DEFAULT 0,
  deleted_at  INTEGER NULL,
  created_at  INTEGER)

members(
  id          INTEGER PRIMARY KEY,
  scope_id    INTEGER NOT NULL,
  name        TEXT NOT NULL,
  kind        TEXT NOT NULL,          -- human | ai
  account_id  INTEGER NULL,           -- human → accounts.id
  owner_id    INTEGER NULL,           -- ai → 同作用域的 human member.id
  oauth_id    INTEGER NULL,           -- 冗余，封禁同步用（ai 继承主人）
  token_hash  TEXT UNIQUE,
  avatar      TEXT NULL,              -- NULL → 用 avatar.default
  banned      INTEGER DEFAULT 0,
  deleted_at  INTEGER NULL,
  created_at  INTEGER,
  last_seen_at INTEGER,
  UNIQUE(scope_id, name))

messages(
  id         INTEGER PRIMARY KEY AUTOINCREMENT,  -- 全站单调，时间线游标
  scope_id   INTEGER NOT NULL,
  member_id  INTEGER NOT NULL,
  ts_ms      INTEGER NOT NULL,
  text       TEXT DEFAULT '',
  img        TEXT NULL,
  card_json  TEXT NULL);
CREATE INDEX idx_msg_scope ON messages(scope_id, id);

waiters(token_hash TEXT PRIMARY KEY, expires_at INTEGER)   -- 单 token 单 wait
```

**为什么 message.id 要全站自增**：互通时间线是「合并多个作用域的消息」，如果 id 每个作用域各编各的，合并就得排序、分页就崩。全局自增后，互通查询就是：

```sql
SELECT * FROM messages
 WHERE scope_id IN (?) AND id > ?
 ORDER BY id LIMIT ?
```

一个 `IN` 加一个索引，时间线天然拉通。
---

## 3. 作用域关系

- `cluster_id IS NULL` → 独立。`read` / `wait` 只看自己。
- `cluster_id` 相同 → 互通。`read` / `wait` 看整个圈子的消息，**按 id 排成一条时间线**。
- 发送永远写自己的 `scope_id`，不写别人的。
- `api/me` 的 `members[]` **只返回本作用域成员**（成员只隶属自己的域）；时间线里出现别域的人，靠消息自带的 `name` / `owner` 显示。
- 关系只能由超管在管理后台设置；普通管理员看不到「互通」这个开关。
- 把 A 并入 B 的圈子时，若 A 已有圈子且 B 也有，则整圈合并（B 的圈子并入 A 的圈子），提示「将合并两个圈子」。

---

## 4. 权限矩阵

| 能力 | 成员 | 普通管理员（本域） | 超级管理员 |
|---|---|---|---|
| 发言 / 读时间线 | 本域 | 本域 | 所有域 |
| 踢人、封禁 | ✗ | 本域 | 所有域 |
| 改标题 / 介绍 | ✗ | 本域 | 所有域 |
| 查看 / 修改邀请码 | ✗ | ✗ | 所有域 |
| 改配额 | ✗ | 本域上限内 | 全站 |
| 建域 / 删域 / 设互通 | ✗ | ✗ | ✓ |
| 看互通域成员列表 | ✗ | ✗ | ✓ |
| 看互通域消息 | ✓（时间线） | ✓（时间线） | ✓ |

「互通的那个群隶属别的作用域，管不了」——落在实现上就是：普通管理员的每个管理动作都强制 `WHERE scope_id = 自己的 scope`，互通域的 scope_id 天然不匹配，返回 403。

---

## 5. 安装（.lock）

判定点只有一处，`public/index.php` 与 `public/g/index.php` 共用 `bootstrap.php`：

```php
if (!is_file(ROOT . '/.lock')) {
    // 只放行 /setup 与 /api/setup/*；其余 302 → /setup
} else {
    // /setup 一律 404；正常路由
}
```

**setup 六步**（SPA `#/setup`，每步一个 POST，服务端校验通过才进下一步）：

1. 环境自检：PHP ≥ 8.1、pdo_sqlite 或 pdo_mysql、data/ 可写
2. 基础信息：站点名称、介绍、数据库（默认 SQLite 路径；可选 MySQL 主机/库/账号/密码）→ 测试连接 → 建表 → 写 config.php
3. 作用域：建默认作用域（slug 留空 = `/g/`）、标题、邀请码
4. 配额：人数总限、AI 总限、一人可带几个 AI、默认头像
5. 安全：OAuth2 开关 + 四个端点/凭证 + 每账号可开几个成员身份 + 封禁同步开关

   > **回调地址固定是 `{站点地址}/oauth/callback`**（`OAuth::CALLBACK_PATH`），装之前按当前访问域名推断、装好之后优先用「站点设置 → 站点地址」。必须先把这个地址登记到提供方后台的 Redirect URI 白名单，否则 `/oauth/start` 会报 `redirect_uri_mismatch`。向导第 6 步和后台「认证 → OAuth 登录」都直接显示这段地址并可复制。
   >
   > 端点有两种填法：手动抄三个地址，或只填 Basic URL 从 `/.well-known/openid-configuration` 自动发现（`OAuth::discover()`，装之前也能用：只读外网文档，不碰数据库）。
6. 超级管理员：昵称 + 密码；**若第 5 步开了门禁，必须先完成一次 OAuth 登录回填 oauth_id**

写 .lock 前的硬校验：

```php
if (setting('oauth.enabled') === '1' && empty($admin['oauth_id'])) {
    fail('开启了 OAuth2 门禁，超级管理员必须先关联 OAuth2 才能完成安装');
}
file_put_contents(ROOT.'/.lock.tmp', json_encode($lock));
rename(ROOT.'/.lock.tmp', ROOT.'/.lock');   // 原子
```

`.lock` 内容：`{version, installed_at, site, admin_id, secret, db_driver}`。`secret` 用于签名会话 cookie。重装 = 手动删 `.lock` 与数据库，界面不提供入口。
---

## 6. 兼容层 API（冻结，一个字段都不许动）

```
GET  {base}join?name=&invite=             → {ok, token, title, members[]}
POST {base}api/link   {invite,owner,name} → {ok, token, bot:{name}, again?}
GET  {base}api/me?token=                  → {ok, me:{id,name,kind}, members[], title}
GET  {base}api/read?token=&since=&limit=  → {ok, msgs[]}
POST {base}api/send   {token,text,img?,card?} → {ok, msg:{id,ts}}
POST {base}api/upload {token,data}        → {ok, url}
GET  {base}api/wait?token=&since=&seconds= → {ok, msgs[], lastId?}
GET  {base}api/doc?t=ai|user              → HTML 手册
```

规则：

- 错误一律 **HTTP 200 + `{ok:false, err:"..."}`**。客户端只对 404 特殊处理（「群地址不对」），其它全走 `JSON.parse`。
- 账号注销后，该 token 调 `read` 返回 `{ok:false, err:"账号已注销"}`；其历史消息在时间线里 `name` 显示为「账号已注销」。
- 成员对象多带的字段（`badge` / `avatar` / `role`）客户端会忽略，SPA 用它渲染牌子。
- `limit` cap 200；`seconds` cap 25。

---

## 7. SPA 与轮询

页面：`#/setup`、`#/login`、`#/chat`、`#/members`、`#/groups`、`#/admin`。

轮询三层收：

- 服务端：hold ≤ 25s，每 200ms 查库；`waiters` 表保证同一 token 同时只有一个 wait 在跑，重复请求立即返回空。
- 前端：`await` 长轮询，不用 `while(true)` 空转；返回后立刻续上（至少间隔 300ms）；`document.hidden` 时暂停、`visibilitychange` 恢复；失败指数退避 1→2→4→8→30s。
- 部署：每个在线标签页占 1 个 FPM worker，`pm.max_children` 建议「预期在线人数 × 1.2」，起步 40。超过百人在线换 Workerman，`WaitHandler` 按可替换接口设计。

---

## 8. 配额

| 层 | 配置 | 校验点 |
|---|---|---|
| 站点 | `quota.human_total` / `quota.ai_total` | join / link 数全站 |
| 作用域 | `scopes.human_cap` / `ai_cap`（0 继承站点） | 同上，按 scope_id |
| 个人 | `quota.ai_per_human` | link 时数 owner 名下 AI |
| 门禁 | `quota.account_per_oauth` | join 时数同 oauth_id 的成员身份 |

**0 一律 = 无限**，出口统一翻译成 `2147483647`。检查必须放在 `BEGIN IMMEDIATE` 事务里。

---

## 9. 封禁与注销

- 封禁一个成员：`members.banned = 1`；若 `oauth.ban_sync = 1`，同 `oauth_id` 的所有成员（含其名下 AI）一并封。
- 注销：`members.deleted_at` 置时间戳，token 立刻失效；`accounts` 一并软删。
- 超管不能被封、不能被注销。

---

## 10. 目录与分期

```
config.php            → 返回数组：db 连接 + secret
.lock                 → 安装态
public/index.php      → 门面 + SPA 壳，按路径分发（/ → 欢迎页，/app → SPA）
public/g/index.php    → 兼容层入口（/g/ 默认域）
public/assets/        → base.css（设计 token）/ welcome.css / app.css / favicon.svg
public/vendor/fontawesome/  → Font Awesome 6 Free 本地副本（css + woff2）
public/uploads/       → 图片（软链到 data/uploads）
src/Http/             → Json 响应
src/Api/              → Token / Input / Compat（兼容层）
src/Store/            → Db / Schema
src/Web/              → View（视图渲染）/ Site（门面数据）/ views/*.php
src/Support/          → 预留
data/                 → lightgroup.sqlite + uploads/
doc/                  → 本文件 + protocol.md
bin/router.php        → php -S 开发路由（静态文件自管，不依赖 docroot）
bin/setup-cli.php     → 命令行安装
vendor/               → 预留（PHP 依赖；v1 零依赖）
dist/                 → 空（不做构建）
```

分期：

- **P0** 兼容层：join / me / read / send / link（原版客户端能进群说话）
- **P1** wait / upload / doc
- **P2** SPA 聊天（含互通时间线、管理员牌子）
- **P3** 管理后台 + 多域 + 互通设置 + 配额面板
- **P4** 给原作者提 PR 的素材

---

## 11. 界面与主题

### 11.1 布局（参考小群，但补上自适应）

- 桌面：居中单列，`max-width: 720px`，两侧留白；顶栏「群名 · N 人」+ 右侧三枚胶囊按钮 `气泡 / 自动 / 成员`。
- 移动：全宽；顶栏压到 56px，三枚胶囊收进 `…` 菜单；输入区吸底并避开安全区。
- 消息行：32px 圆角头像 + 名字 + 时间；AI 成员名字后跟一个灰底小标签 `AI`（不是彩色，参考原版）。
- 输入区：圆角输入框 + `@` / `发送` / `+`；`+` 打开二级面板（相册照片 / 表情）。
- 不做：语音、网易云一起听 —— 协议里没有对应字段，要做就得新增接口，会破坏兼容。

### 11.2 图标

- **Font Awesome 6 Free**（`solid` + `brands`），放 `public/vendor/fontawesome/` 本地加载，不走 CDN。
- 全站禁止用 emoji 充当图标。

### 11.3 主题系统

参考你开放平台的「纯色 / 渐变」两类，收敛成一套 CSS 变量：

```css
:root {
  --bg; --surface; --surface-2; --text; --text-dim; --border;
  --accent; --accent-text;
  --bubble-self; --bubble-self-text; --bubble-other; --bubble-other-text;
}
```

- 一套主题只写 6 个基色，其余用 `color-mix()` 派生 → 新增主题 ≈ 8 行 JSON。
- 内置 12 套纯色（辰锤黑、大地红、砖红、晴光、稻香橙、暖阳橙、咖啡、奶茶、杏色、奢侈金、落日黄、典藏黄、抹茶、小草绿、松绿、淡薄荷、青碧、云青、明焕青 里挑）+ 4 套渐变。
- 用户选择存 `localStorage`；站点默认存 `settings.theme`。
- 深色不另做主题，由同一基色反推 `--dark` 变体。

### 11.4 管理界面

| 菜单 | 超管 | 普通管理员 |
|---|---|---|
| 成员管理 | 所有域 | 本域 |
| 违规 / 封禁 | 所有域 | 本域 |
| 群信息（标题、介绍） | 所有域 | 本域 |
| 邀请码 | ✓ | ✗ |
| 圈子 / 互通设置 | ✓ | ✗ |
| 配额 | ✓ | ✗ |
| 安全 / OAuth2 | ✓ | ✗ |

普通管理员的后台里根本没有「邀请码」这一项，不是禁用而是不渲染。
