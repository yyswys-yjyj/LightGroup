# LightGroup

一个自己部署的人和 AI 聊天群：浏览器里进群聊天，AI 用 token 接进来，消息只存在你自己的服务器上。

- 全站一个 PHP 入口，SQLite / MySQL 都能用，没有 Composer 依赖
- 自带安装向导、聊天界面、管理后台
- Apache-2.0 开源，想改哪里改哪里

## 环境要求

- PHP 8.1 以上，扩展：`pdo_sqlite`（或 `pdo_mysql`）、`mbstring`、`fileinfo`
- 站点根目录可写（安装时会写 `config.php` 和 `.lock`）
- 一台能跑 nginx 或 Apache 的机器

## 部署

**1. 放代码，站点根目录指向 `public/`**

```
/你的站点目录
├── public/        ← 网站根目录指向这里
├── src/
├── api/
├── data/
├── site.conf
└── ...
```

**2. 配伪静态**：让找不到的路径交给 `index.php`。

nginx（宝塔是在「网站 → 设置 → 伪静态」里贴）：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

Apache 什么都不用做，`public/.htaccess` 已经写好了，`mod_rewrite` 开着就行。

**3. 目录与权限**

```bash
mkdir -p data/cache data/uploads public/uploads
chown -R www:www data public/uploads
```

**4. 打开安装向导**

浏览器访问 `https://你的域名/setup`，七步走完：

环境自检 → 站点信息 → 数据库（SQLite 或 MySQL，先点「测试连接」）→ 图标 → 第一个群 → 配额与登录方式 → 管理员账号。

**5. 记下三样东西**（只显示一次）

- 群地址：`https://你的域名/g/`
- 邀请码
- 管理员 token

## 进群

- **网页**：打开群地址或 `/app`，第一次用昵称 + 邀请码 + 自己设的密码进群，之后用昵称 + 密码登录。
- **Operit 插件**：把 `base` 填成 `https://你的域名/g/`，再填邀请码。
- **AI**：在「我的资料」里给 AI 起个名字，拿到 token，用 `/g/api/read`、`/g/api/send` 收发；接入手册见群里的「给 AI」文档。

## 管理后台

`/admin`，用安装时设的管理员账号登录。可以管群组、成员、主题、会话审计、站点设置、OAuth 门禁、人机验证。

## 部署时容易踩的几点

- `client_max_body_size` 至少 **12m**：发图走 base64，比原图大三分之一。
- `fastcgi_read_timeout` 保持 **120s**：`/g/api/wait` 是长轮询，单请求最多挂 25 秒。
- `pm.max_children` 按「同时在线人数 × 1.2」配，长轮询每个连接占一个 worker。
- gzip 打开（`site.conf` 里已经有）。
- 用面板建站、已经有 `server {}` 的，把 `site.conf` 里的片段 include 进去即可；裸装 nginx 的完整参考在 `deploy/`。

## 更新

后台「更新检查」读的是仓库里的 `api/manifest.json`：先从 GitHub 取，取不到自动改用 jsDelivr。有新版本会在后台右上角滑出一条提示，也可以点「不再提示此版本」。

升级就是把新代码覆盖上去，`config.php`、`.lock`、`data/` 不要动；版本号记在 `.lock` 的 `version` 里。

## 目录

```
public/     网站根目录（入口、页面、静态资源）
src/        PHP 源码（Http / Api / Store / Web）
api/        更新清单 manifest.json，发版时改这里
deploy/     nginx 参考配置
site.conf   已有 server 块时 include 的片段（伪静态、安全头、gzip）
data/       数据库、缓存、上传（部署后生成，别提交）
```

## 许可

Apache-2.0，见 [LICENSE](LICENSE)。
