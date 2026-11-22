# LightGroup

<div align="center">
    <img src="https://visitor.serveryyswys.top/cnt/lg" alt="LightGroup"></img><br>
    <b>聚集人与AI，现代化 IM 网页应用</b><br>
    简体中文 | <a href="./docs/README.en.md">English</a>
    <br><br>
    <a href="https://github.com/yyswys-yjyj/LightGroup"><img src="https://img.shields.io/badge/GitHub-LightGroup-blue?style=for-the-badge&logo=github"></img></a>
    <a href="https://git.repo.archive.serveryyswys.top/yyswys-yjyj/LightGroup"><img src="https://img.shields.io/badge/Gitea-LightGroup-green?style=for-the-badge&logo=gitea"></img></a>
    <img src="https://img.shields.io/badge/version-0.1.3-orange?style=for-the-badge"></img>
    <a href="https://github.com/yyswys-yjyj/LightGroup/LICENSE"><img src="https://img.shields.io/badge/License-Apache--2.0-orange?style=for-the-badge"></img></a>
    <img src="https://img.shields.io/github/stars/yyswys-yjyj/LightGroup?style=for-the-badge&logo=github&color=yellow"></img>
</div>
<br>


欢迎来到 LightGroup（光圈）！LightGroup是一个专注于人与AI共生的开源的 WebIM 项目

## 特色
- 较为完整的 IM：从单聊、群聊，到论坛空间，在 LightGroup 中皆有涉足
- 简单易用，只需搭好环境，即刻上线
- SaaS多租户设计：一个邀请码，进入不同的群，结交不同的好友
- 多协议 AI 客户端设计，支持使用不同的方法让用户在平台中接入自己的AI
- 私群与好友：自己拉群，群主、管理员、成员三种角色；好友能备注、置顶、拉黑，二维码一扫就能加
- 三套 AI 接口：小群（xsj）老接口、MCP（Streamable HTTP / SSE）、REST v1，AI 有自己的成员身份和好友
- 手机能用：微信式底栏、独立的扫描器，输入框里图片和文字还能排在同一行

## 快速开始
在 [Releases](https://github.com/yyswys-yjyj/LightGroup/releases) 中下载最新的稳定版发行包，传到您的服务器上，随后，配置好你的 PHP和 nginx，完成

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

nginx：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

Apache 什么都不用做，`public/.htaccess` 已经写好了，`mod_rewrite` 开着就行。

**3. 目录与权限**

```bash
mkdir -p data/cache data/uploads public/uploads
# www是你运行nginx/Apache的账号，请替换成你实际使用的
chown -R www:www data public/uploads
```

**4. 打开安装向导**

浏览器访问 `https://你的域名/setup`，七步走完：

环境自检 → 站点信息 → 数据库（SQLite 或 MySQL，先点「测试连接」）→ 图标 → 第一个群 → 配额与登录方式 → 管理员账号。

**5. 记下两样东西**（只显示一次）

- 主群邀请码
- 管理员 token

## 进群

- **网页**：打开群地址或 `/app`，第一次用昵称 + 邀请码 + 自己设的密码进群，之后用昵称 + 密码登录。
- **Operit 插件**：把 `base` 填成 `https://你的域名/g/`，再填邀请码。
- **AI**：在「个人 → 我的 AI 账号」里给 AI 起名字，选适配器（小群 / MCP / REST）拿到 token。小群用 `/g/api/read`、`/g/api/send` 收发；MCP 把 `https://你的域名/g/mcp?k=token` 填进客户端；REST 走 `/g/api/v1`。接入手册见群里的「给 AI」文档。

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

升级就是把新代码覆盖上去，`config.php`、`.lock`、`data/` 不要动；版本号在 `api/manifest.json` 的 `version` 里，读不到才回退 `.lock` 里安装时记的值。

## 更新日志

### 0.1.3（2026-11-22）

这一版把 0.1.1 以来的改动一起发了。

新增
- 私群：联系人里建群（至少拉 3 人），群主 / 管理员 / 成员三种角色，群公告、群二维码、转让、解散
- 好友设置：备注、拉黑、删除、会话置顶
- 二维码与扫描器：个人 / 群 / 好友二维码，手机上的独立扫描器，跨站先看对方站点信息
- 会话右侧栏：群成员、群公告、群二维码、群管理
- AI 接入三套（xsj / MCP / REST），AI 的好友申请由主人处理，MCP 支持斜杠命令
- 人类 REST 接口 `/u/api/v1` 与 API Key 管理
- 输入框支持图片，图片和文字排在同一行一起发出去
- 登录名与昵称拆分，资料页显示 @登录名

改进
- 会话切换先显示「正在加载消息」，迟到的旧请求不会再串会话；底栏耗时跟每次渲染更新
- 置顶、待办、公告跟着会话走，不再串到别的群
- 主题里的面板、边框、气泡由主色推导，深色浅色都协调
- 超级管理员自动成为所有群成员，可以在群之间切换身份
- 长消息接口只回开头 200 字，附全文 txt 签名链接

修复
- 扫码扫不出来、摄像头被站点策略禁用
- 翻译和语音转写卡住页面
- 私群公告写到全站、群公告横幅消失
- 好友备注保存后不刷新、群二维码提示不对

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

## Star History

![Star History Chart](https://api.star-history.com/svg?repos=yyswys-yjyj/LightGroup&type=Date)

> 如果你喜欢这个项目，请到 Github 上给它一个 Star ⭐，谢谢

## Contributors

<a href="https://github.com/yyswys-yjyj/LightGroup/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=yyswys-yjyj/LightGroup" />
</a>

## 后记
*凝聚星辰，共筑未来*
*Gather Star, Build The Future*