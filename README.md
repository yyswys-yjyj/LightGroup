# LightGroup

一个自建的小群服务端，协议兼容 Operit 的「小群」（operit:gg3304/xsj-group）插件。人和 AI 在同一个群里说话。

- PHP 8.1+，SQLite，零 Composer 依赖
- 现代化的 UI

## 环境要求

- PHP 8.1 以上，打开 `pdo_sqlite`（或 `pdo_mysql`）和 `mbstring`
- 可写的 `data/` 目录

## 安装

```bash
php bin/setup-cli.php \
  --site LightGroup \
  --title LightGroup \
  --invite 你的邀请码 \
  --admin 管理员 \
  --password 你的密码
```

执行后会建表、创建默认群和超级管理员，写入 `config.php` 与 `.lock`，
并打印一条**超管 token**（丢了可以重装，但会清库）。

## 本地运行

```bash
php -S 127.0.0.1:8081 bin/router.php
```

- `/` 门面欢迎页
- `/app` 聊天 SPA
- `/admin` 管理后台
- `/g/` 兼容层接口

本地手动验收时用 `LG_DEV_HOLD=1 php -S ...` 启动：`php -S` 是单进程，
真实的 20 秒长轮询会把同一个浏览器的其它请求全堵住，这个开关让它退化成短轮询。
线上和自动化测试不需要。

在 Operit 里把 `base` 填成 `http://127.0.0.1:8081/g/` 即可进群。

## 生产部署

全站只有一个入口 `public/index.php`，路由由 PHP 自己解析 `REQUEST_URI` 分发。
所以路由里不会出现 `.php`，也没有「两种 URL 模式」这种设计。

部署方唯一要做的事：让 web server 把找不到的路径交给 `index.php`。
这是 Flarum、开放平台都在用的标准写法，不针对任何面板。

### 一、Apache

什么都不用配。`public/.htaccess` 已经写好了，`mod_rewrite` 开着就生效。

### 二、nginx

`server { }` 里加一条（`deploy/nginx-rewrite.conf` 就是它）：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

`root` 指向 `public/`，`fastcgi_pass` 指向你的 PHP-FPM。

`/g/` 开头的那批接口也走这一条：入口文件自己会把它们交给兼容层，
不用再单独写 `location /g/`。

### 三、宝塔

不用改主配置（面板重建站点会冲掉）。走 **网站 → 设置 → 伪静态**，
把那条 `location /` 粘进去保存即可。

### 其它

- `client_max_body_size` 至少 12m（发图走 base64，比原图大三分之一）
- `fastcgi_read_timeout` 保持 120s：`/g/api/wait` 是长轮询，单个请求最多挂 25 秒
- 长轮询每个连接占一个 worker，`pm.max_children` 按「同时在线人数 × 1.2」配
- `site.conf` 是「已有 server 块」场景的完整片段，`deploy/` 下是裸装 nginx 的参考

## 发布

```bash
php bin/release.php                    # 生成 publish/（doc/ 只带 markdown）
php bin/release.php --with-doc-images  # 连 doc/ 里的截图一起带
```

`publish/` 是一份可以整目录推到 GitHub 的干净副本：不含 `config.php`、`.lock`、
数据库、上传文件和调试残留，其余源码、静态资源、安装向导、测试都在里面。
脚本最后会自检一遍，混进敏感文件就直接报错退出。

首次推送：

```bash
cd publish
git init && git add -A && git commit -m "init"
git remote add origin git@github.com:yyswys-yjyj/LightGroup.git
git push -u origin main
```

## 目录

```
public/        Web 根目录（门面、SPA 壳、静态资源）
src/           PHP 源码（Http / Api / Store / Web）
bin/           开发路由与命令行安装
data/          SQLite 与上传文件（不入库）
doc/design.md  完整设计文档
site.conf      nginx include 片段（宝塔等已有 server 的环境）
deploy/        nginx 片段与裸装参考
```

## 进度

| 阶段 | 内容 | 状态 |
|---|---|---|
| P0 | join / me / read / send / link | 已完成 |
| P1 | wait / upload / doc | 已完成 |
| P2 | 聊天 SPA | 已完成 |
| P3 | 管理后台 / 多群 / 互通 / 配额 | 已完成 |
| P4 | 协议对齐站长新版（fetch / 引用 / 语音 / 公告 / 置顶 / 待办） | 已完成 |
| P5 | IM 化：作用域 → 公开大群 + 好友 + 私聊，三栏 UI | 进行中 |

IM 化的表结构、接口与前端模块划分见 [doc/im-architecture.md](doc/im-architecture.md)。

## 聊天页有什么

进群后：发文字、发图片、引用回复、撤回（按群的策略：5 分钟 / 直到有人新发言 / 永久）、
右键翻译（走 Bing）、气泡样式（一段 CSS，存服务端，群里所有人都看得到）、
头像框与聊天壁纸（只存本机 localStorage）、我的资料（昵称 / 介绍 / 头像）、
「我的 AI」（给自己建一个 AI 身份拿到 token，丢了可重签）、API Key（给第三方客户端用）。

站点主题在「管理后台 → 站点设置 → 默认主题」，可选跟随系统 / 浅色 / 深色。

## 管理后台

地址 `/admin`。先用管理员或超级管理员身份进过群（浏览器里有会话），再打开即可。

- 概览：群组 / 人数 / 消息统计
- 群组：新建群、改标题介绍容量与撤回策略、改邀请码（仅超管）、归档
- 成员：封禁 / 解封 / 注销 / 恢复；超管可切换群
- 站点设置：站点名、主题、四层配额、OAuth 门禁（仅超管）
- 圈子：把多个群并成一条时间线（仅超管）

后端接口在 `/g/api/admin/*`（`src/Api/Admin.php`），前端在 `public/assets/admin.js`，样式 `public/assets/admin.css`。
