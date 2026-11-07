# nginx 404 排查（/setup、/login、/app 打不开）

## 判定顺序

1. `nginx -t` — 语法有没有过。**没过就别重启了**，nginx 会继续跑旧配置，看起来像「重启没用」。
2. `nginx -T | grep -c "client_max_body_size 12m"` — 你改的 site.conf 到底有没有被加载。0 = 没加载。
3. `nginx -T | grep -c "^location / {"` — 出现 2 次说明面板模板自己也有 location /，include 那段会 duplicate。
4. `curl -sS -o /dev/null -w "%{http_code}\n" https://域名/probe.php` — 200 = PHP 通；404 = root 或 PHP 处理没配上。

## 两种修法

### A. nginx -t 通过、配置已加载
检查 `root` 是否指向 `public/`（不是项目根）。

### B. nginx -t 报 `duplicate location "/"`
删掉 site.conf 最后那段 `location / { ... }`，改成在面板模板自己的 `location /` 里加一行：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
    # 面板原有的内容保持不动
}
```

# ---------- 面板模板已有 location / 时用这个 ----------
# 如果你 /www/server/panel/vhost/nginx/站点.conf 里已经有一行 location / {
# 就不要再 include site.conf 的 location /（会 duplicate location "/"），
# 直接在那个已有的 location / 内部第一行加：
#     try_files $uri $uri/ /index.php?$query_string;
#
# 剩下的 /g/、/assets/、安全头等仍可照常 include。

