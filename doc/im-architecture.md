# LightGroup · IM 化改造规格（v1）

> 本文件是唯一事实来源。后端、前端、路由三条线并行开发，任何接口字段以本文件为准。

## 0. 一句话

把「一个站点 = 一个大群」升级成「一个站点 = 若干作用域（scope），每个作用域里有一个**公开大群** + 成员之间的**好友**与**私聊**」。
UI 从「单群聊天页」改成真正的 IM：左侧会话列表、中间会话、右侧资料/成员。

## 1. 概念模型

| 概念 | 说明 |
| --- | --- |
| scope（作用域） | 原来的「群」升级为作用域，是一个站点的分区。slug 为空 = 默认作用域。 |
| 公开大群 | 每个 scope 自动有且只有一个，`conversations.kind = group`。该 scope 的所有成员天然在里面，AI 账号读到的就是它。 |
| 会话 conversation | 一段消息流的容器。`group`（公开大群）/ `dm`（两个人私聊）。 |
| 好友 friends | 双向关系。A 申请 → B 接受 → 两人才算好友。仅好友之间可开私聊。 |
| 私聊 dm | 两个成员之间唯一的一条会话，由 `api/dm/open` 幂等创建。 |

规则：

1. 公开大群对 scope 内所有成员可见、可发言（受封禁/撤回策略约束）。
2. 私聊只有会话里的两个成员看得到，AI 账号永远看不到主人的私聊。
3. 只有好友能私聊；非好友发起 `api/dm/open` 返回 `ok:false, err:"先加好友"`。
4. 跨作用域的成员不能加好友、不能私聊（同一 scope 内才算同一个 IM 里的联系人）。
5. 撤回、引用、语音、置顶、公告、待办这些既有能力保持不变，只是作用在「会话」上而不是「scope」上。

## 2. 数据库（Schema v4）

新增三张表，`messages` 加一列。旧数据 `conv_id` 为 NULL 一律视为「所在 scope 的公开大群」。

```sql
-- 会话
CREATE TABLE conversations (
  id            BIGINT PK AUTO_INCREMENT,
  scope_id      BIGINT NOT NULL,
  kind          VARCHAR(8) NOT NULL,        -- group | dm
  title         VARCHAR(191) NULL,          -- dm 为空，前端显示对方昵称
  is_public     TINYINT NOT NULL DEFAULT 0, -- 每个 scope 只有一个 1
  dm_key        VARCHAR(64) NULL,           -- dm 用 "小id_大id" 做幂等键
  created_by    BIGINT NULL,
  created_at    BIGINT NOT NULL DEFAULT 0,
  last_msg_id   BIGINT NOT NULL DEFAULT 0,
  last_msg_at   BIGINT NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX idx_conv_public ON conversations(scope_id, is_public);  -- 只对 is_public=1 有效，SQLite 用部分索引
CREATE UNIQUE INDEX idx_conv_dmkey ON conversations(scope_id, dm_key);
CREATE INDEX idx_conv_scope ON conversations(scope_id, kind);

-- 会话成员（公开大群不写这张表，隐式 = scope 全部成员）
CREATE TABLE conv_members (
  conv_id       BIGINT NOT NULL,
  member_id     BIGINT NOT NULL,
  last_read_id  BIGINT NOT NULL DEFAULT 0,
  joined_at     BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (conv_id, member_id)
);

-- 好友（双向一行，a_id < b_id）
CREATE TABLE friends (
  a_id          BIGINT NOT NULL,
  b_id          BIGINT NOT NULL,
  status        VARCHAR(8) NOT NULL,        -- pending | accepted | blocked
  requested_by  BIGINT NOT NULL,
  created_at    BIGINT NOT NULL DEFAULT 0,
  accepted_at   BIGINT NULL,
  PRIMARY KEY (a_id, b_id)
);
CREATE INDEX idx_friends_b ON friends(b_id, status);

ALTER TABLE messages ADD COLUMN conv_id BIGINT NULL;
CREATE INDEX idx_msg_conv ON messages(conv_id, id);
```

迁移要点（`src/Store/Schema.php`）：

- `VERSION` 改 4，`$wanted["messages"]` 加 `conv_id`。
- `isNewTable()` 名单加 `conversations`、`conv_members`、`friends`。
- 迁移后为每个已存在的 scope 建公开大群并把历史消息回填：
  `INSERT INTO conversations(scope_id,kind,title,is_public,created_at) SELECT id,"group",title,1,created_at FROM scopes`（不存在才插）；
  `UPDATE messages SET conv_id = (SELECT c.id FROM conversations c WHERE c.scope_id=messages.scope_id AND c.is_public=1) WHERE conv_id IS NULL`。
- 幂等：可反复执行。

## 3. 路由（规范化，去掉 ?k=）

### 3.1 网页路由

| 路径 | 行为 |
| --- | --- |
| `GET /` | 门面欢迎页（未安装则进 setup） |
| `GET /login` | 登录页：昵称 + 邀请码；管理员可切「用密码登录」 |
| `POST /login` | 表单提交（或前端 fetch `api/session`），成功 302 `/app` |
| `GET /logout` | 清 session，302 `/` |
| `GET /app` | 主界面 SPA。未登录直接 302 `/login?next=/app` |
| `GET /app#/...` | 前端 hash 子路由，服务端不关心 |
| `GET /admin` | 管理后台（未登录渲染后台登录页） |
| `GET /g/...` | Operit 插件 / AI 的兼容层 API，**协议不变** |

**`?k=<token>` 彻底移除**：不再作为登录手段，也不再从地址栏读。
登录态只走 cookie session（已有 `src/Api/Session.php`，session 里只存 member_id / scope_id）。

### 3.2 前端路由（hash）

| hash | 页面 |
| --- | --- |
| `#/c/<convId>` | 会话（公开大群或私聊） |
| `#/friends` | 好友：好友列表 + 收到的申请 + 发出的申请 |
| `#/members` | 作用域成员列表（可加好友） |
| `#/me` | 我的资料 |
| `#/appearance` | 气泡与外观 |

默认进入 `#/c/<公开大群 id>`。

### 3.3 兼容层（`/g/`，不许破坏）

`join`、`api/link`、`api/me`、`api/read`、`api/fetch`、`api/send`、`api/wait`、`api/upload`、`api/doc`、`api/recall`、`api/notice`、`api/pin`、`api/todo`、`api/translate`、`v1/*` 全部保持现有字段不变。

新增字段只增不改：

- `api/me` 增加 `publicConv`（公开大群 id）、`friends`（好友数）、`pending`（待我处理的好友申请数）。
- `api/fetch`、`api/read`、`api/send` 支持可选 `conv` 参数（数字或 `c_<id>`），缺省 = 公开大群，**默认行为与现在完全一致**。
  AI 账号传 `conv` 只允许公开大群，传私聊直接 `{"ok":false,"err":"AI 账号只能看公开大群"}`。

## 4. 新接口（`/g/api/*`，网页与插件通用）

认证：优先 session cookie，其次 `token` 参数（与现有 `Compat::memberByToken` 一致）。
所有响应沿用 `Json::ok() / Json::fail()`：`{ok:true, ...}` / `{ok:false, err:"中文"}`，HTTP 一律 200（404 除外）。

### 4.1 会话列表

`GET /g/api/conversations`

```json
{ "ok": true, "convs": [
  { "id": 1, "kind": "group", "title": "LightGroup", "isPublic": 1,
    "last": {"id": 42, "name": "管理员", "text": "在吗", "img": "", "ts": 1699...},
    "unread": 3, "members": 6, "pin": true },
  { "id": 7, "kind": "dm", "member": {"id":"h_3","name":"测试者","avatar":"","color":"#6d9df7"},
    "last": {...}, "unread": 0 }
] }
```

- 公开大群排第一位，其后按 `last_msg_at` 倒序。
- `unread` = 该会话中 `id > conv_members.last_read_id` 且不是我发的消息数。

### 4.2 会话消息

`GET /g/api/conv?id=<convId>&since=<lastId>&limit=50`

- 返回结构对齐 `api/fetch`：`{ok, msgs, members, lastId, now, tick, conv:{id,kind,title,member?}}`。
- 私聊的 `members` 只回两人的 member 对象（用于头像/颜色）。
- 非会话成员请求 → `{ok:false, err:"不在这个会话里"}`。

### 4.3 发消息

`POST /g/api/conv/send`，body：`{conv, text, img, voice, dur, q, reply, card}`

- 字段与 `api/send` 完全一致，只是多一个必填 `conv`。
- 非成员 → 失败；公开大群按 scope 校验封禁。
- 成功后回 `{ok:true, msg:{id, ts, from}}`，并把会话 `last_msg_id/last_msg_at` 更新。

### 4.4 好友

| 接口 | 入参 | 说明 |
| --- | --- | --- |
| `GET /g/api/friends` | - | `{ok, friends:[member...], incoming:[{id,name,color,avatar,at}], outgoing:[...]}` |
| `POST /g/api/friends/request` | `{id}` 或 `{name}` | 建立 pending 行，`requested_by` = 我 |
| `POST /g/api/friends/accept` | `{id}` | 把我收到的那条 pending 改成 accepted |
| `POST /g/api/friends/reject` | `{id}` | 删掉 pending 行 |
| `POST /g/api/friends/remove` | `{id}` | 删掉 accepted 行（同时不解散私聊，历史保留） |

校验：不能加自己；必须在同一 scope；已是好友返回 `err:"已经是好友"`；已有 pending 返回 `err:"已经申请过了"`。

### 4.5 开私聊

`POST /g/api/dm/open`，body `{id}`（对方 member id）

- 非好友 → `{ok:false, err:"先加好友"}`。
- 已有 dm 会话则复用，返回 `{ok:true, conv: {id, kind:"dm", member:{...}}}`。
- 新建时 `dm_key = min(a,b) + "_" + max(a,b)`，两行 `conv_members`。

## 5. 前端（`public/assets/`）

### 5.1 布局（参考微信桌面版 / QQ 的**结构**，不抄皮肤）

```
+--------+------------------+---------------------------+
| 导航条 | 会话列表 300px    | 会话区（头部/消息/输入）   |
|  64px  | 搜索 + 列表       |                           |
+--------+------------------+---------------------------+
```

- 左：56~64px 竖条，放我的头像（点开菜单：资料/外观/管理后台/退出），下面是「聊天」「联系人」两个入口图标。
- 中：会话列表。每项 = 头像 + 名称 + 最后一条摘要 + 时间 + 未读红点；顶部有搜索框。
- 右：会话区。头部 = 名称 + 人数/在线状态 + 操作按钮；中部消息流（复用现有渲染）；底部输入区（复用现有 composer）。
- 消息气泡：别人靠左（头像在左），自己靠右（头像在右）——**保持现在的 IM 习惯**。
- 宽度 860px 以下：导航条变底部 tab，会话列表与会话区二选一（点会话进入，头部有返回）。

### 5.2 模块拆分（原生 ES module，零构建）

把现在的 1463 行 `app.js` 拆成：

| 文件 | 职责 |
| --- | --- |
| `assets/js/core.js` | `BOOT`、`S`（全局状态）、`api()`、`h()`、`icon()`、toast、hash 路由 |
| `assets/js/avatar.js` | 头像、颜色、首字、头像框 |
| `assets/js/msg.js` | `msgNode`、语音、引用、图片、撤回、右键菜单、`paintText` |
| `assets/js/chat.js` | 会话页、消息流、输入区、@、发送、轮询 |
| `assets/js/conv.js` | 左侧会话列表、未读、搜索 |
| `assets/js/people.js` | 好友、成员、加好友、申请处理 |
| `assets/js/me.js` | 我的资料、AI、API Key、外观 |
| `assets/js/app.js` | 入口：登录检查、布局、路由分发 |

`app-shell.php` 改成 `<script type="module" src="/assets/js/app.js?v=...">`。
CSS 继续用 `app.css`，新增类名见 §5.4。**不要重写配色**，沿用现有 CSS 变量体系。

### 5.3 需要的交互

1. 点会话列表切换会话，URL 变 `#/c/<id>`。
2. 成员卡片/资料页有「加好友」按钮；已是好友显示「发消息」。
3. 好友页分三段：好友、待处理（同意/拒绝）、我发出的。
4. 收到好友申请时，左导航「联系人」上有红点。
5. 私聊头部显示对方昵称与「好友」标识，可点开资料。
6. 未登录访问 `/app` 时服务端已经 302 到 `/login`，前端不用处理。

### 5.4 新增 CSS 类（写在 app.css 末尾）

`.im`、`.im-rail`、`.im-list`、`.im-main`、`.conv-item`、`.conv-item.is-active`、`.conv-avatar`、`.conv-meta`、`.conv-name`、`.conv-last`、`.conv-time`、`.unread-dot`、`.chat-head-info`、`.friend-row`、`.friend-tabs`、`.req-badge`、`.empty-hint`。
媒体查询 `@media (max-width: 860px)`：`.im-list` 与 `.im-main` 互斥显示，`.im-list.is-open` 控制。

## 6. 分工边界（改这些文件，别越界）

### 线 A · Schema + 会话核心

改：`src/Store/Schema.php`、`src/Api/Conv.php`（新建）。
交付：§2 全部 DDL 与迁移；`Conv` 类提供 `publicConv(PDO,$scopeId)`、`ensureDm(PDO,$scopeId,$a,$b)`、`mine(PDO,$scopeId,$meId)`、`assertMember(PDO,$convId,$meId)`、`touch(PDO,$convId,$msgId,$ts)`。

### 线 B · 社交 API

改：`src/Api/Social.php`（新建）、`src/Api/Compat.php`（只在 switch 里加路由 + api/me 加三个字段）。
交付：§4 全部接口 + §3.3 的兼容层扩展。
依赖线 A 的 `Conv` 类，先按上面的签名写，接口不满足时在线 A 完成后自行补齐。

### 线 C · 前端 IM

改：`public/assets/js/*`（新建目录）、`public/assets/app.css`（追加）、`src/Web/views/app-shell.php`（script 标签）。
交付：§5 全部。旧 `app.js` 删除前先把要复用的函数搬进新模块。

### 线 D · 路由 + 登录

改：`public/index.php`、`public/g/index.php`、`src/Web/views/login.php`（新建）、`src/Web/views/app-shell.php`（未登录态删除）、`public/assets/login.css`（可复用 base.css）。
交付：§3.1 全部；`?k=` 移除；`/login` 表单（昵称 + 邀请码，管理员可切密码登录，提交到 `api/session`）。

## 7. 不能破坏的东西

1. `/g/` 兼容层字段（Operit 插件正在用，改一个字段就废）。
2. 既有数据：messages 的旧行 `conv_id` 回填后行为与现在一致。
3. 后台 `/admin` 与安装向导 `/setup` 不动。
4. 站点图标、页脚、关于弹层的既有实现不动。
5. 不许引入 Node 构建步骤，前端仍是原生 ES module。

## 8. 验收

- `php -l` 全部通过。
- `node tests/e2e.mjs` 原 60 条仍全过。
- 新增 e2e：好友申请→接受→开私聊→互发消息→第三个人进不去。
- 浏览器：能登录、能切会话、能加好友、能私聊、窄屏可用。

## 9. 实现口径（与本文的偏差，已实测）

线 B 落地时按安全优先做了三处收紧，以本节为准：

1. **公开时间线过滤**：`api/read|fetch` 不带 `conv` 时，SQL 条件为
   `m.scope_id IN (圈内所有 scope) AND (m.conv_id IN (该圈内所有公开大群的 id) OR m.conv_id IS NULL)`。
   只按 scope 过滤会把私聊消息漏进群时间线，加 `is_public = 1` 的子查询封死。
2. **AI 边界**：`kind = ai` 调 `api/conv` 指向私聊、`api/dm/open`（含作为被邀请方）、`api/fetch|read|send` 带私聊 `conv`，一律回 `{"ok":false,"err":"AI 账号只能看公开大群"}`；`api/conversations` 对 AI 只回公开大群。
3. **好友申请状态机**：pending 时对方反向申请返回 `已经申请过了`（不自动互相成为好友）；申请人自己点 accept 返回 `你已经申请过了，等对方同意`；非好友 `dm/open` 返回 `先加好友`。

接口参数补充：`conv` 既接受 `c_3` 也接受 `3`（`parseConvId` 兼容）；`api/me` 顶层新增 `publicConv`（公开大群 id）、`friends`、`pending` 三个字段，其余旧字段一个没动。

未做（后续可接）：好友申请限流与 `blocked` 状态入口、集群内跨 scope 的按 conv 精确历史（缺省路径已覆盖互通）、删好友后的会话保留策略（当前保留不散）。
