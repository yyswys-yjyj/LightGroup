# LightGroup 核心规则

本文件是本仓库的硬性约定。改代码前先读，改完按这里的检查项自检。

## 1. UI 铁律（用户已多次强调，不得再犯）

### 1.1 弹层必须留窗口边缘
- 所有弹层（前台 `openSheet`、后台 `modal`）与窗口四边必须留白：**桌面 24px，移动端 16px**。
- 卡片尺寸一律由容器 padding 约束：
  - 宽：`width:100%` + `max-width:<设计宽>`；
  - 高：`max-height:calc(100dvh - 2×留白)`。
- **禁止**用 `calc(100vw - 24px)` / `calc(100vh - 24px)` 这类按视口硬算的尺寸——它会和父级 padding 叠加，导致卡片溢出、贴边或超出屏幕。
- 移动端只允许缩小留白（24→16），不允许改卡片尺寸公式。

### 1.2 内容必须占满容器
- 页面主内容、弹层内容区必须撑满可用宽高，不允许右侧/底部出现大片空白。
- 容器用 flex 列布局时，内容区必须 `flex:1;min-height:0`（滚动交给内容区）。
- 输入框、主按钮、表单行一律 `width:100%`；列表卡片不得无理由收窄。
- 内容与卡片边缘之间保留内边距（≥14px），按钮/列表项不得贴着卡片边框。

### 1.3 文案只写事实
界面文案只写用户需要知道的事实：当前状态、限制、操作后果。
- 禁止**内部实现说明**：接口路径、缓存/压缩/解码方式、"XX 是 XX 的实现"。
- 禁止**操作指导**："点这里…""先点 XX 再点 YY"（按钮本身已经说明）。
- 禁止**常识解释**："用户名是登录用的""token 只显示一次"这类把显而易见的事讲一遍的长句。
- 允许：错误原因、数量限制、不可逆提醒、配置项的必要说明（一句话）。

## 2. 项目基线
- PHP 最低 8.1（`src/Setup/Installer.php` 校验），生产跑 8.5+；不引入 composer，不新增扩展依赖。
- 前端是原生 ES module，零构建；新文件放 `public/assets/js/` 会自动进 importmap。
- 数据库 SQLite / MySQL 双驱动，SQL 不得只用一家语法（`INSERT OR REPLACE` 仅 SQLite）。
- 全站单入口 `public/index.php`；API 走 `/g/api/*`，后台 `/admin`。
- 所有 SQL 用 prepare 占位符；输出到 HTML 的地方用 `e()` 或 textContent，禁止拼 HTML。

## 3. 版本与迁移
- 版本号在 `api/manifest.json`。**未经用户明确同意不得改版本号**（当前 0.1.3）。
- 改表结构：`src/Store/Schema.php` 的 `VERSION` 加一并补 `migrate()` 与 `statements()`，迁移在首次连接时自动跑。

## 4. 部署
- 本机 `scp` 整包会被掐断，用分块推送脚本（`.dsh/try/push_chunks.sh`）。
- 远程：解压到站点根 → `chown -R www:www` → `php -l` 全量 → `/etc/init.d/php-fpm-85 reload` → HTTPS 冒烟。
- 生产站点根 `/www/wwwroot/support.im.lightgroup.xclcs.top`，ssh 端口 22312，密钥在 `.dsh/`（正文禁止读取）。

## 5. 自检清单（提交前）
- [ ] 弹层四边留白符合 1.1；没有 `calc(100vw - N)` 尺寸。
- [ ] 页面/弹层内容 `flex:1`、输入框与主按钮整行。
- [ ] 新文案没有内部实现 / 操作指导 / 常识解释。
- [ ] `php -l` 与 `node --check`（复制成 `.mjs`）通过。
- [ ] 本地浏览器看过改动的页面与弹层，截图对比无贴边、无大片空白。
