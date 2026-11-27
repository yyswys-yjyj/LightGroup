<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Http\Json;
use LightGroup\Store\Db;
use LightGroup\Web\Site;
use PDO;
use Throwable;

/**
 * MCP 端点：/g/mcp?k=<AI 的 token>
 *
 * Operit 那边把 MCP 当「第三个客户端」用：拿到地址和 token 就能当群里的一员说话。
 * 我们这边走的是 JSON-RPC 2.0 over HTTP（和官方示例一致：POST 一个 JSON，回一个 JSON）。
 * 客户端如果带 Accept: text/event-stream，就按 SSE 帧回（data: {...}\n\n），两种都认。
 *
 * 认证只认 token（?k= 或 Authorization: Bearer），因为 AI 没有浏览器的 cookie。
 *
 * MCP 不是第二套账号：这里的 token 就是 chat API 用的那个 token，成员还是 members 里那个 AI。
 * 每个工具都只是把参数塞进 Input、再调 Compat::dispatch() 的同一条路由，
 * 所以两边认证、校验、落库、互通完全一致，改一处不会漏另一处。
 */
final class Mcp
{
    private const PROTOCOL = "2025-06-18";
    /** 一条 SSE 流最多挂多久；到点断开，客户端要重连 */
    private const STREAM_SECONDS = 120;
    private const SESSION_RE = '/^[a-f0-9]{16}$/';

    public static function handle(): void
    {
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID");
        header("Access-Control-Expose-Headers: Mcp-Session-Id");
        header("Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS");

        $method = (string) ($_SERVER["REQUEST_METHOD"] ?? "GET");
        if ($method === "OPTIONS") {
            http_response_code(204);
            exit;
        }
        if ($method === "DELETE") {
            // Streamable 客户端关闭会话时会 DELETE，我们没有服务端状态可清
            self::jsonOut(["ok" => true], 200);
            return;
        }
        if ($method === "GET") {
            // 权限组把 MCP 限定成只走 HTTP Stream 时，别再挂 SSE 长连接
            if (self::sseBlocked()) {
                http_response_code(405);
                header("Content-Type: application/json; charset=utf-8");
                echo json_encode(
                    ["error" => "本站的 MCP 只支持 HTTP Stream，请在客户端里选 HTTP Stream"],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                return;
            }
            self::openStream();
            return;
        }
        // 只开了 SSE 时 POST 不收：客户端要么先 GET 建流，要么改成 Streamable HTTP
        if (self::transportOf() === "sse") {
            http_response_code(405);
            header("Allow: GET, OPTIONS");
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(
                ["error" => "本站的 MCP 只支持 SSE，请在客户端里选 SSE"],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            return;
        }
        self::handlePost();
    }

    /** 站点给 MCP 开了哪种传输：both / stream（只 HTTP Stream） / sse（只 SSE） */
    private static function transportOf(): string
    {
        if (self::token() === "") return "both";
        try {
            [$me] = self::auth(Db::connect());
        } catch (Throwable $e) {
            return "both";
        }
        $t = Features::transport(Db::connect());
        return in_array($t, ["both", "stream", "sse"], true) ? $t : "both";
    }

    /** 这个 AI 的权限组是否禁止了 SSE 长连接（只允许 HTTP Stream） */
    private static function sseBlocked(): bool
    {
        return self::transportOf() === "stream";
    }

    /**
     * MCP 有两代传输，Operit 两个都支持，这里都认：
     *   1) Streamable HTTP（httpStream）：POST 直接回 application/json。
     *      GET 只在客户端明确要 SSE 时才开流，否则回 405 —— 官方 SDK 把 405 当「没有服务端推送」，
     *      自动退回 JSON-only 模式，不会报连接失败。
     *   2) 旧 HTTP+SSE（2024-11-05，sse）：GET 挂一条流，先发 event: endpoint 告诉客户端往哪 POST，
     *      之后每条响应都从这条流以 event: message 推回去，POST 只回 202。
     */
    private static function openStream(): void
    {
        $accept = strtolower((string) ($_SERVER["HTTP_ACCEPT"] ?? ""));
        $session = self::sessionParam();

        if ($session === "" && strpos($accept, "text/event-stream") === false) {
            http_response_code(405);
            header("Allow: POST, OPTIONS");
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(["error" => "this endpoint takes JSON-RPC over POST"], JSON_UNESCAPED_SLASHES);
            return;
        }
        if ($session === "") $session = bin2hex(random_bytes(8));

        @set_time_limit(0);
        ignore_user_abort(false);
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        header("Content-Type: text/event-stream; charset=utf-8");
        header("Cache-Control: no-cache, no-transform");
        header("X-Accel-Buffering: no");
        header("Connection: keep-alive");

        echo "retry: 2000\n\n";
        echo "event: endpoint\n";
        echo "data: " . self::endpointUrl($session) . "\n\n";
        self::pushOut();

        $deadline = microtime(true) + self::STREAM_SECONDS;
        $idle = 0;
        while (microtime(true) < $deadline) {
            if (connection_aborted()) break;
            $frames = self::drain($session);
            if ($frames) {
                $idle = 0;
                foreach ($frames as $frame) {
                    echo "event: message\n";
                    echo "data: " . $frame . "\n\n";
                }
                self::pushOut();
            } else {
                $idle++;
                if ($idle % 15 === 0) {
                    // 约 3 秒一次心跳，别让 nginx / 手机网络把空闲连接掐了
                    echo ": ping\n\n";
                    self::pushOut();
                }
            }
            usleep(200000);
        }
        self::dropSession($session);
    }

    private static function handlePost(): void
    {
        $session = self::sessionParam();
        $raw = (string) file_get_contents("php://input");
        $req = json_decode($raw, true);
        if (!is_array($req)) {
            self::jsonOut(["jsonrpc" => "2.0", "id" => null, "error" => ["code" => -32700, "message" => "请求不是合法 JSON"]], 200, $session);
            return;
        }

        // 批量：规范允许数组里塞多条，响应也必须是数组
        if (array_is_list($req) && $req !== []) {
            $batch = [];
            $hasRequest = false;
            foreach ($req as $one) {
                if (!is_array($one)) continue;
                if (!array_key_exists("id", $one)) {
                    self::processNotification($one);
                    continue;
                }
                $hasRequest = true;
                $batch[] = self::handleOne($one);
            }
            if (!$hasRequest) {
                http_response_code(202);
                return;
            }
            self::jsonOut($batch, 200, $session);
            return;
        }

        // 通知没有 id，不需要回包（initialize 之后的 notifications/initialized 就是）
        if (!array_key_exists("id", $req)) {
            self::processNotification($req);
            http_response_code(202);
            return;
        }
        self::jsonOut(self::handleOne($req), 200, $session);
    }

    private static function processNotification(array $req): void
    {
        $method = (string) ($req["method"] ?? "");
        if ($method === "notifications/initialized") {
            self::debug("initialized");
        }
    }

    private static function handleOne(array $req): array
    {
        $id = $req["id"] ?? null;
        $method = (string) ($req["method"] ?? "");
        $params = is_array($req["params"] ?? null) ? $req["params"] : [];
        try {
            $result = self::dispatch($method, $params);
        } catch (Throwable $e) {
            return ["jsonrpc" => "2.0", "id" => $id, "error" => ["code" => -32000, "message" => $e->getMessage()]];
        }
        if ($result === null) {
            return ["jsonrpc" => "2.0", "id" => $id, "error" => ["code" => -32601, "message" => "不认识的方法：" . $method]];
        }
        return ["jsonrpc" => "2.0", "id" => $id, "result" => $result];
    }

    /** 回包：带 session 说明这是旧 SSE 客户端，响应必须走流，不能写进 POST 的 body */
    private static function jsonOut(array $payload, int $code, string $session = ""): void
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($session !== "") {
            self::enqueue($session, $body);
            http_response_code(202);
            return;
        }
        http_response_code($code);
        header("Content-Type: application/json; charset=utf-8");
        header("Cache-Control: no-store");
        echo $body;
    }

    private static function sessionParam(): string
    {
        $s = (string) ($_GET["session"] ?? "");
        return preg_match(self::SESSION_RE, $s) ? $s : "";
    }

    private static function endpointUrl(string $session): string
    {
        return rtrim(Site::baseUrl(), "/") . "/mcp?k=" . rawurlencode(self::token()) . "&session=" . $session;
    }

    // ---------- 旧 SSE 传输的发件箱（文件队列，PHP 多进程之间靠它交接） ----------

    private static function queueDir(string $session): string
    {
        return APP_ROOT . "/data/mcp/" . $session;
    }

    private static function enqueue(string $session, string $json): void
    {
        $dir = self::queueDir($session);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $name = sprintf("%016d-%s.json", (int) round(microtime(true) * 1000), bin2hex(random_bytes(4)));
        $tmp = $dir . "/." . $name . ".tmp";
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        @rename($tmp, $dir . "/" . $name);
    }

    /** @return string[] 已发送过的帧，取走即删 */
    private static function drain(string $session): array
    {
        $dir = self::queueDir($session);
        if (!is_dir($dir)) return [];
        $files = glob($dir . "/*.json");
        if (!$files) return [];
        sort($files, SORT_STRING);
        $out = [];
        foreach ($files as $file) {
            $data = @file_get_contents($file);
            @unlink($file);
            if ($data !== false && $data !== "") $out[] = $data;
            if (count($out) >= 50) break;
        }
        return $out;
    }

    private static function dropSession(string $session): void
    {
        $dir = self::queueDir($session);
        foreach (glob($dir . "/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private static function pushOut(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    private static function debug(string $line): void
    {
        @file_put_contents(APP_ROOT . "/data/mcp.log", lg_now() . " " . $line . "\n", FILE_APPEND);
    }

    private const SUPPORTED = ["2025-11-25", "2025-06-18", "2025-03-26", "2024-11-05"];

    /** 连上就先告诉 AI 这个账号怎么用。只写事实，不写内部实现。 */
    private const INSTRUCTIONS = "你在 LightGroup（一个自建的群聊站点）里有一个独立的 AI 账号，适配器是 MCP。"
        . "先调 me 看清自己是谁、在哪个群、默认会话是哪个，再用 read 读消息、send 发消息、wait 等新消息。"
        . "私聊用 dm，好友用 friends，空间动态用 moments，站内信用 notices，资料用 profile，"
        . "图片和语音先用 upload 拿地址再发，撤回用 recall，群待办用 todo。"
        . "这些工具只在这个账号的权限范围内：看不到别人的私聊，也看不到别的群。";

    /** @return array|null null = method not found */
    private static function dispatch(string $method, array $params): ?array
    {
        switch ($method) {
            case "initialize": {
                $wanted = (string) ($params["protocolVersion"] ?? "");
                $version = in_array($wanted, self::SUPPORTED, true) ? $wanted : self::PROTOCOL;
                return [
                    "protocolVersion" => $version,
                    "capabilities"    => ["tools" => ["listChanged" => false]],
                    "serverInfo"      => ["name" => "lightgroup", "version" => "1.2.0"],
                    "instructions"    => self::INSTRUCTIONS,
                ];
            }
            case "ping":
                return [];
            case "tools/list":
                // 列工具也要认人：不然 xsj 适配器或随便一个错 token 都能拿到完整清单，
                // 客户端会以为连上了，直到第一次调用才报错
                self::auth(Db::connect());
                return ["tools" => self::tools()];
            case "tools/call":
                $name = (string) ($params["name"] ?? "");
                $args = is_array($params["arguments"] ?? null) ? $params["arguments"] : [];
                return self::call($name, $args);
            default:
                return null;
        }
    }

    /** 工具清单：给 AI 读的一句话说明，不带客套 */
    private static function tools(): array
    {
        $conv = ["type" => "string", "description" => "会话：不填是公开大群，私聊填会话 id（conversations 里能查到）"];
        return [
            [
                "name" => "me",
                "description" => "我是谁：名字、id、身份、主人、所在群、默认会话。开头先调这个。",
                "inputSchema" => ["type" => "object", "properties" => (object) []],
            ],
            [
                "name" => "members",
                "description" => "群成员列表：名字、id、是人还是 AI、是谁的 AI。",
                "inputSchema" => ["type" => "object", "properties" => (object) []],
            ],
            [
                "name" => "read",
                "description" => "读消息。不填 conv 就读公开大群；since 往后读，before 往上翻。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "conv"   => $conv,
                        "limit"  => ["type" => "number", "description" => "最多几条，默认 30，最大 200"],
                        "since"  => ["type" => "number", "description" => "只读这个 id 之后的（填了就不看 before）"],
                        "before" => ["type" => "number", "description" => "读这个 id 之前的 limit 条"],
                    ],
                ],
            ],
            [
                "name" => "send",
                "description" => "发消息。不填 conv 就发公开大群。text 是正文；img/voice 先 upload 拿地址；card 是请客卡。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "conv"   => $conv,
                        "text"   => ["type" => "string", "description" => "正文，最多 10000 字"],
                        "img"    => ["type" => "string", "description" => "图片地址（upload 返回的 url）"],
                        "voice"  => ["type" => "string", "description" => "语音地址"],
                        "dur"    => ["type" => "number", "description" => "语音秒数"],
                        "reply"  => ["type" => "number", "description" => "引用哪条消息的 id"],
                        "model"  => ["type" => "string", "description" => "你用的模型名"],
                        "card"   => [
                            "type" => "object",
                            "description" => "请客卡：shop / item / price / note / link",
                            "properties" => [
                                "shop"  => ["type" => "string"],
                                "item"  => ["type" => "string"],
                                "price" => ["type" => "string"],
                                "note"  => ["type" => "string"],
                                "link"  => ["type" => "string"],
                            ],
                        ],
                    ],
                ],
            ],
            [
                "name" => "wait",
                "description" => "等新消息（最多 25 秒）。返回期间的新消息；超时返回空列表。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "conv"    => $conv,
                        "seconds" => ["type" => "number", "description" => "最多等多少秒，默认 20，上限 25"],
                        "since"   => ["type" => "number", "description" => "从这个 id 之后开始等；不填从最新一条开始"],
                    ],
                ],
            ],
            [
                "name" => "conversations",
                "description" => "我的会话列表：公开大群和私聊，带最后一条和未读数。",
                "inputSchema" => ["type" => "object", "properties" => (object) []],
            ],
            [
                "name" => "dm",
                "description" => "私聊：open 打开和某人的会话，send 发一条，list 列出现有私聊。只对好友生效。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "action" => ["type" => "string", "description" => "open / send / list"],
                        "name"   => ["type" => "string", "description" => "对方的名字"],
                        "id"     => ["type" => "string", "description" => "对方的成员 uid，如 8f3k2a9c1d"],
                        "text"   => ["type" => "string", "description" => "action=send 时要发的内容"],
                    ],
                    "required" => ["action"],
                ],
            ],
            [
                "name" => "friends",
                "description" => "好友：list 列表，request 申请，accept 同意，reject 拒绝，remove 删除。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "action" => ["type" => "string", "description" => "list / request / accept / reject / remove"],
                        "name"   => ["type" => "string", "description" => "对方名字"],
                        "id"     => ["type" => "string", "description" => "对方成员 id"],
                    ],
                    "required" => ["action"],
                ],
            ],
            [
                "name" => "moments",
                "description" => "空间动态：list 看，post 发（text 最多 300 字、images 最多 9 张、tags 最多 5 个），like 点赞，comment 评论。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "action"  => ["type" => "string", "description" => "list / post / like / comment"],
                        "text"    => ["type" => "string", "description" => "正文，可以用 #话题"],
                        "images"  => ["type" => "array", "items" => ["type" => "string"], "description" => "图片地址"],
                        "tags"    => ["type" => "array", "items" => ["type" => "string"], "description" => "话题"],
                        "id"      => ["type" => "number", "description" => "动态 id（like / comment）"],
                        "comment" => ["type" => "string", "description" => "评论内容"],
                    ],
                    "required" => ["action"],
                ],
            ],
            [
                "name" => "notices",
                "description" => "站内信：list 列表（带未读数），read 标一条已读，readAll 全部已读。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "action" => ["type" => "string", "description" => "list / read / readAll"],
                        "id"     => ["type" => "number", "description" => "站内信 id"],
                    ],
                ],
            ],
            [
                "name" => "profile",
                "description" => "我的资料：get 看，set 改简介（bio，最多 500 字）。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "action" => ["type" => "string", "description" => "get / set"],
                        "bio"    => ["type" => "string", "description" => "新的简介"],
                    ],
                ],
            ],
            [
                "name" => "upload",
                "description" => "上传一个文件：data 是 data:image/...;base64,... 或 data:audio/...;base64,...，返回可发给 send 的地址。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "data" => ["type" => "string", "description" => "data URL"],
                        "kind" => ["type" => "string", "description" => "image / voice / avatar，默认 image"],
                    ],
                    "required" => ["data"],
                ],
            ],
            [
                "name" => "recall",
                "description" => "撤回自己发的一条消息（受群里的撤回策略限制）。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => ["id" => ["type" => "number", "description" => "消息 id"]],
                    "required" => ["id"],
                ],
            ],
            [
                "name" => "todo",
                "description" => "群待办：list 看，add 把一条消息设成待办，close 完成。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "action" => ["type" => "string", "description" => "list / add / close"],
                        "id"     => ["type" => "number", "description" => "待办 id（close）或消息 id（add）"],
                    ],
                    "required" => ["action"],
                ],
            ],
        ];
    }

    private static function call(string $name, array $args): array
    {
        $pdo = Db::connect();
        [$me, $scope] = self::auth($pdo);

        switch ($name) {
            case "me":            return self::text(self::toolMe($pdo, $scope, $me));
            case "members":       return self::text(self::toolMembers($pdo, $scope, $me));
            case "read":          return self::text(self::toolRead($pdo, $scope, $me, $args));
            case "send":          return self::text(self::toolSend($pdo, $scope, $me, $args));
            case "wait":          return self::text(self::toolWait($pdo, $scope, $me, $args));
            case "conversations": return self::text(["items" => Conv::mine($pdo, $scope, $me)]);
            case "dm":            return self::text(self::toolDm($pdo, $scope, $me, $args));
            case "friends":       return self::text(self::toolFriends($pdo, $scope, $me, $args));
            case "moments":       return self::text(self::toolMoments($pdo, $scope, $me, $args));
            case "notices":       return self::text(self::toolNotices($pdo, $scope, $me, $args));
            case "profile":       return self::text(self::toolProfile($pdo, $me, $args));
            case "upload":        return self::text(self::toolUpload($pdo, $me, $args));
            case "recall":        return self::text(self::toolRecall($pdo, $scope, $me, (int) ($args["id"] ?? 0)));
            case "todo":          return self::text(self::toolTodo($pdo, $scope, $me, $args));
            default:
                throw new \RuntimeException("没有这个工具：" . $name);
        }
    }

    // ---------- 身份 ----------

    /** token → 成员；必须是 AI 且适配器是 mcp */
    private static function auth(PDO $pdo): array
    {
        $token = self::token();
        if ($token === "") throw new \RuntimeException("缺少 token：地址后面加 ?k=<AI 的 token>");
        $st = $pdo->prepare("SELECT * FROM members WHERE token_hash = ? LIMIT 1");
        $st->execute([Token::hash($token)]);
        $me = $st->fetch();
        if (!$me) throw new \RuntimeException("这个 token 找不到成员");
        if ((string) ($me["kind"] ?? "") !== "ai") throw new \RuntimeException("这个 token 不是 AI 账号的");
        if (!empty($me["deleted_at"])) throw new \RuntimeException("这个账号已经注销");
        if ((int) ($me["banned"] ?? 0) === 1) throw new \RuntimeException("这个账号已被封禁");
        if (!Features::on($pdo, "ai")) {
            throw new \RuntimeException("本站已关闭 AI 账号，这个身份暂时不可用");
        }
        if (!Features::adapter($pdo, "mcp")) {
            throw new \RuntimeException("本站已关闭 MCP 适配器，这个 AI 暂时不可用");
        }
        $adapter = (string) ($me["adapter"] ?? "mcp");
        if ($adapter !== "mcp") {
            $label = $adapter === "xsj" ? "小群（xsj）" : $adapter;
            $hint = $adapter === "api" ? "请用 /g/api/v1 的 REST 接口" : "只能用 /g/api/* 的接口";
            throw new \RuntimeException("这个账号是「" . $label . "」适配器，" . $hint
                . "；要用 MCP 请新建一个 MCP 适配器的 AI");
        }

        $scope = self::scopeOf($pdo, $me);
        $pdo->prepare("UPDATE members SET last_seen_at = ? WHERE id = ?")->execute([lg_now(), (int) $me["id"]]);
        return [$me, $scope];
    }

    // ---------- 工具 ----------

    private static function toolMe(PDO $pdo, array $scope, array $me): array
    {
        $owner = (int) ($me["owner_id"] ?? 0) > 0 ? Conv::nameOf($pdo, (int) $me["owner_id"]) : "";
        $pub = Conv::publicConv($pdo, $scope);
        return [
            "id"      => self::mid($me),
            "name"    => (string) $me["name"],
            "kind"    => "ai",
            "adapter" => "mcp",
            "owner"   => $owner,
            "bio"     => (string) ($me["bio"] ?? ""),
            "scope"   => [
                "id"    => (int) $scope["id"],
                "slug"  => (string) ($scope["slug"] ?? ""),
                "title" => (string) ($scope["title"] ?? ""),
            ],
            "defaultConv" => $pub ? ["id" => (int) $pub["id"], "title" => (string) ($scope["title"] ?? "")] : null,
            "tools"   => ["me", "members", "read", "send", "wait", "conversations", "dm", "friends", "moments", "notices", "profile", "upload", "recall", "todo"],
        ];
    }

    private static function toolMembers(PDO $pdo, array $scope, array $me): array
    {
        $st = $pdo->prepare("SELECT id, uid, name, kind, owner_id, adapter, deleted_at FROM members WHERE scope_id = ? AND deleted_at IS NULL ORDER BY kind ASC, id ASC");
        $st->execute([(int) $scope["id"]]);
        $out = [];
        foreach ($st->fetchAll() as $m) {
            $out[] = [
                "id"      => self::mid($m),
                "name"    => (string) $m["name"],
                "kind"    => (string) $m["kind"],
                "owner"   => (int) $m["owner_id"] > 0 ? Conv::nameOf($pdo, (int) $m["owner_id"]) : "",
                "adapter" => (string) $m["kind"] === "ai" ? (string) ($m["adapter"] ?? "mcp") : "",
                "me"      => (int) $m["id"] === (int) $me["id"] ? 1 : 0,
            ];
        }
        return ["members" => $out, "total" => count($out)];
    }

    private static function toolRead(PDO $pdo, array $scope, array $me, array $a): array
    {
        $target = self::pickConv($pdo, $scope, $me, (string) ($a["conv"] ?? ""));
        $limit = max(1, min(200, (int) ($a["limit"] ?? 30)));
        $since = (int) ($a["since"] ?? 0);
        $before = (int) ($a["before"] ?? 0);
        $rows = self::msgs($pdo, $target["ids"], $limit, $since, $before);
        return [
            "conv"     => $target["id"],
            "title"    => $target["title"],
            "messages" => self::lightMsgs($pdo, $rows),
            "oldestId" => $rows ? (int) $rows[0]["id"] : 0,
            "lastId"   => $rows ? (int) $rows[count($rows) - 1]["id"] : $since,
            "more"     => count($rows) >= $limit,
        ];
    }

    private static function toolSend(PDO $pdo, array $scope, array $me, array $a): array
    {
        $target = self::pickConv($pdo, $scope, $me, (string) ($a["conv"] ?? ""));
        return self::insert($pdo, $scope, $me, $target, $a);
    }

    private static function toolWait(PDO $pdo, array $scope, array $me, array $a): array
    {
        $target = self::pickConv($pdo, $scope, $me, (string) ($a["conv"] ?? ""));
        $seconds = max(1, min(25, (int) ($a["seconds"] ?? 20)));
        $since = (int) ($a["since"] ?? 0);
        if ($since <= 0) $since = self::maxId($pdo, $target["ids"]);
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $rows = self::msgs($pdo, $target["ids"], 50, $since, 0);
            if ($rows) {
                return [
                    "conv"     => $target["id"],
                    "messages" => self::lightMsgs($pdo, $rows),
                    "lastId"   => (int) $rows[count($rows) - 1]["id"],
                    "timeout"  => false,
                ];
            }
            usleep(250000);
        }
        return ["conv" => $target["id"], "messages" => [], "lastId" => $since, "timeout" => true];
    }

    private static function toolDm(PDO $pdo, array $scope, array $me, array $a): array
    {
        $action = strtolower(trim((string) ($a["action"] ?? "")));
        if ($action === "list") {
            $out = [];
            foreach (Conv::mine($pdo, $scope, $me) as $c) {
                if ((string) $c["kind"] === "dm") $out[] = $c;
            }
            return ["items" => $out];
        }
        if ($action !== "open" && $action !== "send") self::fail("action 只认 open / send / list");

        $other = self::findMember($pdo, $scope, $me, (string) ($a["name"] ?? ""), (string) ($a["id"] ?? ""));
        if ($other === null) self::fail("找不到这个人");
        if ((int) $other["id"] === (int) $me["id"]) self::fail("不能和自己私聊");
        if (!self::isFriend($pdo, (int) $me["id"], (int) $other["id"])) self::fail("先加好友才能私聊");

        $conv = Conv::ensureDm($pdo, $scope, (int) $me["id"], (int) $other["id"], (int) $me["id"]);
        $target = ["id" => (int) $conv["id"], "ids" => [(int) $conv["id"]], "kind" => "dm", "title" => (string) $other["name"], "conv" => $conv];
        if ($action === "send") {
            $text = trim((string) ($a["text"] ?? ""));
            if ($text === "") self::fail("text 不能空");
            $r = self::insert($pdo, $scope, $me, $target, ["text" => $text, "model" => (string) ($a["model"] ?? "")]);
            return $r + ["conv" => (int) $conv["id"], "with" => (string) $other["name"]];
        }
        return ["conv" => (int) $conv["id"], "with" => ["id" => self::mid($other), "name" => (string) $other["name"]]];
    }

    private static function toolFriends(PDO $pdo, array $scope, array $me, array $a): array
    {
        $action = strtolower(trim((string) ($a["action"] ?? "list")));
        if ($action === "list") {
            $raw = Social::listFor($pdo, $scope, $me, false);
            $out = ["friends" => [], "incoming" => [], "outgoing" => []];
            foreach ($raw as $k => $list) {
                foreach ($list as $row) {
                    $id = (int) ($row["id"] ?? 0);
                    if ($id <= 0) continue;
                    $out[$k][] = [
                        "id"   => self::uidOf($pdo, $id),
                        "name" => Conv::nameOf($pdo, $id),
                        "at"   => (int) ($row["at"] ?? 0),
                    ];
                }
            }
            return $out;
        }

        $other = self::findMember($pdo, $scope, $me, (string) ($a["name"] ?? ""), (string) ($a["id"] ?? ""));
        if ($other === null) self::fail("找不到这个人");
        $targetId = (int) $other["id"];

        $res = Json::capture(function () use ($pdo, $scope, $me, $action, $targetId): void {
            switch ($action) {
                case "request": Social::request($pdo, $scope, $me, $targetId); break;
                case "accept":  Social::accept($pdo, $scope, $me, $targetId); break;
                case "reject":  Social::reject($pdo, $scope, $me, $targetId); break;
                case "remove":  Social::remove($pdo, $scope, $me, $targetId); break;
                default: Json::fail("action 只认 list / request / accept / reject / remove");
            }
        });
        if (!$res["ok"]) self::fail((string) $res["err"]);
        return ["ok" => true, "action" => $action, "with" => (string) $other["name"]];
    }

    private static function toolMoments(PDO $pdo, array $scope, array $me, array $a): array
    {
        $action = strtolower(trim((string) ($a["action"] ?? "list")));
        $path = "moments";
        $method = "GET";
        $in = [];
        if ($action === "post") {
            $method = "POST";
            $in = [
                "text"   => (string) ($a["text"] ?? ""),
                "images" => is_array($a["images"] ?? null) ? $a["images"] : [],
                "tags"   => is_array($a["tags"] ?? null) ? $a["tags"] : [],
            ];
        } elseif ($action === "like") {
            $path = "like";
            $method = "POST";
            $in = ["id" => (int) ($a["id"] ?? 0)];
        } elseif ($action === "comment") {
            $path = "comment";
            $method = "POST";
            $in = ["id" => (int) ($a["id"] ?? 0), "text" => (string) ($a["comment"] ?? "")];
        } elseif ($action !== "list") {
            self::fail("action 只认 list / post / like / comment");
        }
        return self::viaCapture($method, $in, function () use ($pdo, $scope, $path): void {
            Moments::handle($pdo, $scope, $path);
        });
    }

    private static function toolNotices(PDO $pdo, array $scope, array $me, array $a): array
    {
        $action = strtolower(trim((string) ($a["action"] ?? "list")));
        $method = "GET";
        $in = [];
        if ($action === "read") {
            $method = "POST";
            $in = ["op" => "read", "id" => (int) ($a["id"] ?? 0)];
        } elseif ($action === "readall") {
            $method = "POST";
            $in = ["op" => "readAll"];
        } elseif ($action !== "list") {
            self::fail("action 只认 list / read / readAll");
        }
        return self::viaCapture($method, $in, function () use ($pdo, $scope): void {
            Notices::handle($pdo, $scope, "notices");
        });
    }

    private static function toolProfile(PDO $pdo, array $me, array $a): array
    {
        $action = strtolower(trim((string) ($a["action"] ?? "get")));
        if ($action === "set") {
            $bio = trim((string) ($a["bio"] ?? ""));
            if (mb_strlen($bio) > 500) self::fail("简介最多 500 字");
            $pdo->prepare("UPDATE members SET bio = ? WHERE id = ?")->execute([$bio, (int) $me["id"]]);
            return ["ok" => true, "bio" => $bio];
        }
        if ($action !== "get") self::fail("action 只认 get / set");
        return [
            "id"      => self::mid($me),
            "name"    => (string) $me["name"],
            "adapter" => "mcp",
            "bio"     => (string) ($me["bio"] ?? ""),
            "avatar"  => self::abs((string) ($me["avatar"] ?? "")),
            "owner"   => (int) ($me["owner_id"] ?? 0) > 0 ? Conv::nameOf($pdo, (int) $me["owner_id"]) : "",
        ];
    }

    private static function toolUpload(PDO $pdo, array $me, array $a): array
    {
        $data = (string) ($a["data"] ?? "");
        $kind = strtolower(trim((string) ($a["kind"] ?? "image")));
        if (!preg_match('#^data:(image/(png|jpe?g|gif|webp)|audio/[a-z0-9.+-]+|video/[a-z0-9.+-]+);base64,(.+)$#i', $data, $mm)) {
            self::fail("data 必须是 data:<类型>;base64,<内容>");
        }
        $raw = base64_decode($mm[3], true);
        if ($raw === false || $raw === "") self::fail("内容解不出来");
        if (strlen($raw) > 8 * 1024 * 1024) self::fail("文件最大 8MB");

        $mime = strtolower($mm[1]);
        $ext = match (true) {
            $mime === "image/png"  => "png",
            $mime === "image/jpeg" => "jpg",
            $mime === "image/gif"  => "gif",
            $mime === "image/webp" => "webp",
            str_starts_with($mime, "audio/") => "m4a",
            default => "bin",
        };
        $dir = PUBLIC_ROOT . "/uploads/" . date("Y") . "/" . date("m");
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) self::fail("上传目录建不出来");
        $name = bin2hex(random_bytes(8)) . "." . $ext;
        $target = $dir . "/" . $name;
        if (file_put_contents($target, $raw) === false) self::fail("保存失败");
        $url = self::abs("/uploads/" . date("Y") . "/" . date("m") . "/" . $name);
        if ($kind === "avatar") {
            $pdo->prepare("UPDATE members SET avatar = ? WHERE id = ?")->execute([$url, (int) $me["id"]]);
        }
        return ["url" => $url, "size" => strlen($raw), "kind" => $kind];
    }

    private static function toolRecall(PDO $pdo, array $scope, array $me, int $id): array
    {
        if ($id <= 0) self::fail("要指定消息 id");
        $st = $pdo->prepare("SELECT * FROM messages WHERE id = ? AND scope_id = ? LIMIT 1");
        $st->execute([$id, (int) $scope["id"]]);
        $msg = $st->fetch();
        if (!$msg) self::fail("消息不存在");
        if (!empty($msg["deleted_at"])) self::fail("已经撤回过了");
        if ((int) $msg["member_id"] !== (int) $me["id"]) self::fail("只能撤回自己的消息");
        $up = $pdo->prepare("UPDATE messages SET deleted_at = ?, deleted_by = ? WHERE id = ?");
        $up->execute([lg_now(), (int) $me["id"], $id]);
        return ["ok" => true, "id" => $id];
    }

    private static function toolTodo(PDO $pdo, array $scope, array $me, array $a): array
    {
        $action = strtolower(trim((string) ($a["action"] ?? "list")));
        if ($action === "list") {
            $st = $pdo->prepare(
                "SELECT t.id, t.msg_id, t.created_at, t.closed_at, m.text, m.member_id"
                . " FROM todos t LEFT JOIN messages m ON m.id = t.msg_id"
                . " WHERE t.scope_id = ? ORDER BY t.closed_at IS NULL DESC, t.id DESC LIMIT 50"
            );
            $st->execute([(int) $scope["id"]]);
            $out = [];
            foreach ($st->fetchAll() as $r) {
                $out[] = [
                    "id"     => (int) $r["id"],
                    "msgId"  => (int) $r["msg_id"],
                    "text"   => (string) ($r["text"] ?? ""),
                    "done"   => empty($r["closed_at"]) ? 0 : 1,
                    "at"     => (int) $r["created_at"],
                ];
            }
            return ["items" => $out];
        }
        $id = (int) ($a["id"] ?? 0);
        if ($id <= 0) self::fail("要指定 id");
        if ($action === "add") {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE id = ? AND scope_id = ? AND deleted_at IS NULL");
            $chk->execute([$id, (int) $scope["id"]]);
            if ((int) $chk->fetchColumn() === 0) self::fail("找不到这条消息");
            $pdo->prepare("INSERT INTO todos (scope_id, msg_id, created_by, created_at) VALUES (?,?,?,?)")
                ->execute([(int) $scope["id"], $id, (int) $me["id"], lg_now()]);
            return ["ok" => true, "id" => (int) $pdo->lastInsertId()];
        }
        if ($action === "close") {
            $up = $pdo->prepare("UPDATE todos SET closed_at = ? WHERE id = ? AND scope_id = ? AND closed_at IS NULL");
            $up->execute([lg_now(), $id, (int) $scope["id"]]);
            return ["ok" => true, "id" => $id, "closed" => $up->rowCount() > 0 ? 1 : 0];
        }
        self::fail("action 只认 list / add / close");
    }

    // ---------- 会话与消息 ----------

    /** @return array{id:int,ids:int[],kind:string,title:string,conv:?array} */
    private static function pickConv(PDO $pdo, array $scope, array $me, string $raw): array
    {
        $raw = trim($raw);
        if ($raw === "" || $raw === "group" || $raw === "public") {
            $c = Conv::publicConv($pdo, $scope);
            if (!$c) self::fail("这个群还没有公开大群");
            $ids = Conv::publicConvIds($pdo, $scope);
            return [
                "id"    => (int) $c["id"],
                "ids"   => $ids ?: [(int) $c["id"]],
                "kind"  => "group",
                "title" => (string) ($scope["title"] ?? ""),
                "conv"  => $c,
            ];
        }
        if (str_starts_with($raw, "dm:")) {
            $other = self::findMember($pdo, $scope, $me, substr($raw, 3), "");
            if ($other === null) self::fail("找不到这个人");
            if (!self::isFriend($pdo, (int) $me["id"], (int) $other["id"])) self::fail("先加好友才能私聊");
            $c = Conv::ensureDm($pdo, $scope, (int) $me["id"], (int) $other["id"], (int) $me["id"]);
            return ["id" => (int) $c["id"], "ids" => [(int) $c["id"]], "kind" => "dm", "title" => (string) $other["name"], "conv" => $c];
        }
        $cid = preg_match('/^c?_?(\d+)$/', $raw, $mm) ? (int) $mm[1] : 0;
        $c = $cid > 0 ? Conv::convById($pdo, $cid) : null;
        if (!$c) self::fail("会话不存在");
        if ((int) $c["is_public"] !== 1 && !Conv::isMember($pdo, $c, (int) $me["id"])) self::fail("你不在这个会话里");
        $title = (string) ($c["title"] ?? "");
        if ((int) $c["is_public"] === 1) {
            $ids = Conv::publicConvIds($pdo, $scope);
            return ["id" => (int) $c["id"], "ids" => $ids ?: [(int) $c["id"]], "kind" => "group", "title" => (string) ($scope["title"] ?? ""), "conv" => $c];
        }
        foreach (Conv::memberIds($pdo, $c) as $x) {
            if ((int) $x !== (int) $me["id"]) { $title = Conv::nameOf($pdo, (int) $x); break; }
        }
        return ["id" => (int) $c["id"], "ids" => [(int) $c["id"]], "kind" => "dm", "title" => $title, "conv" => $c];
    }

    private static function msgs(PDO $pdo, array $convIds, int $limit, int $since, int $before): array
    {
        $ids = array_values(array_filter(array_map("intval", $convIds), fn($x) => $x > 0));
        if (!$ids) return [];
        $in = implode(",", array_fill(0, count($ids), "?"));
        $sql = "SELECT m.*, mem.name AS mname, mem.kind AS mkind FROM messages m"
            . " LEFT JOIN members mem ON mem.id = m.member_id"
            . " WHERE m.conv_id IN ($in)";
        $args = $ids;
        if ($since > 0) {
            $sql .= " AND m.id > ? ORDER BY m.id ASC LIMIT " . $limit;
            $args[] = $since;
        } elseif ($before > 0) {
            $sql .= " AND m.id < ? ORDER BY m.id DESC LIMIT " . $limit;
            $args[] = $before;
        } else {
            $sql .= " ORDER BY m.id DESC LIMIT " . $limit;
        }
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll();
        if ($since <= 0) $rows = array_reverse($rows);
        return $rows;
    }

    private static function maxId(PDO $pdo, array $convIds): int
    {
        $ids = array_values(array_filter(array_map("intval", $convIds), fn($x) => $x > 0));
        if (!$ids) return 0;
        $in = implode(",", array_fill(0, count($ids), "?"));
        $st = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM messages WHERE conv_id IN ($in)");
        $st->execute($ids);
        return (int) $st->fetchColumn();
    }

    private static function insert(PDO $pdo, array $scope, array $me, array $target, array $a): array
    {
        $text  = trim((string) ($a["text"] ?? ""));
        $img   = trim((string) ($a["img"] ?? ""));
        $voice = trim((string) ($a["voice"] ?? ""));
        $dur   = max(0, min(600, (int) ($a["dur"] ?? 0)));
        $card  = is_array($a["card"] ?? null) ? $a["card"] : null;
        $model = trim((string) ($a["model"] ?? ""));
        if (mb_strlen($model) > 40) $model = mb_substr($model, 0, 40);
        if ($text === "" && $img === "" && $voice === "" && !$card) self::fail("不能发空消息");
        if (mb_strlen($text) > 10000) self::fail("单条消息最多 10000 字");

        $replyTo = null;
        $reply = (int) ($a["reply"] ?? 0);
        if ($reply > 0) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE id = ? AND conv_id = ?");
            $chk->execute([$reply, (int) $target["id"]]);
            if ((int) $chk->fetchColumn() > 0) $replyTo = $reply;
        }

        $ts = lg_now();
        $ins = $pdo->prepare(
            "INSERT INTO messages (scope_id, conv_id, member_id, ts_ms, text, img, card_json, reply_to, voice, dur, model)"
            . " VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $ins->execute([
            (int) $scope["id"], (int) $target["id"], (int) $me["id"], $ts,
            $text,
            $img !== "" ? $img : null,
            $card ? json_encode($card, JSON_UNESCAPED_UNICODE) : null,
            $replyTo,
            $voice !== "" ? $voice : null,
            $dur,
            $model !== "" ? $model : null,
        ]);
        $id = (int) $pdo->lastInsertId();
        Conv::touch($pdo, (int) $target["id"], $id, $ts);
        return ["ok" => true, "id" => $id, "ts" => $ts, "conv" => (int) $target["id"]];
    }

    private static function lightMsgs(PDO $pdo, array $msgs): array
    {
        $out = [];
        foreach ($msgs as $m) {
            $t = (string) ($m["text"] ?? "");
            if (!empty($m["text_auto"])) {
                $t = trim((string) preg_replace('/(\s*\[(图片|语音|卡片)\]\([^)]*\)(\s*\d+")?)+$/u', "", $t));
            }
            $reply = (int) ($m["reply_to"] ?? 0);
            $card = null;
            if (!empty($m["card_json"])) {
                $dec = json_decode((string) $m["card_json"], true);
                if (is_array($dec)) $card = $dec;
            }
            $out[] = [
                "id"   => (int) $m["id"],
                "from" => (string) ($m["mname"] ?? ""),
                "kind" => (string) ($m["mkind"] ?? "human"),
                "text" => $t,
                "img"  => self::abs((string) ($m["img"] ?? "")),
                "voice" => self::abs((string) ($m["voice"] ?? "")),
                "dur"  => (int) ($m["dur"] ?? 0),
                "card" => $card,
                "reply" => $reply > 0 ? $reply : null,
                "ts"   => (int) ($m["ts_ms"] ?? 0),
                "recalled" => empty($m["deleted_at"]) ? 0 : 1,
            ];
        }
        return $out;
    }

    // ---------- 通用 ----------

    private static function findMember(PDO $pdo, array $scope, array $me, string $name, string $idRaw): ?array
    {
        $name = trim($name);
        $idRaw = trim($idRaw);
        // 先按 uid 认人；认不到再当名字查
        $id = $idRaw !== "" ? Ids::resolve($pdo, $idRaw, (int) $scope["id"]) : 0;
        if ($id <= 0 && $name !== "") $id = Ids::resolve($pdo, $name, (int) $scope["id"]);
        if ($id > 0) {
            $st = $pdo->prepare("SELECT * FROM members WHERE id = ? AND scope_id = ? AND deleted_at IS NULL LIMIT 1");
            $st->execute([$id, (int) $scope["id"]]);
            $row = $st->fetch();
            if ($row) return $row;
        }
        if ($name === "" && $idRaw !== "") $name = $idRaw;
        if ($name === "") return null;
        $st = $pdo->prepare("SELECT * FROM members WHERE scope_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([(int) $scope["id"], $name]);
        $row = $st->fetch();
        return $row ?: null;
    }

    private static function isFriend(PDO $pdo, int $a, int $b): bool
    {
        if ($a <= 0 || $b <= 0) return false;
        [$x, $y] = $a < $b ? [$a, $b] : [$b, $a];
        $st = $pdo->prepare("SELECT COUNT(*) FROM friends WHERE a_id = ? AND b_id = ? AND status = 'accepted'");
        $st->execute([$x, $y]);
        return (int) $st->fetchColumn() > 0;
    }

    /** 在捕获模式下跑一段业务代码，并把请求方法改成指定的那个 */
    private static function viaCapture(string $method, array $input, callable $fn): array
    {
        $prevMethod = $_SERVER["REQUEST_METHOD"] ?? "GET";
        $_SERVER["REQUEST_METHOD"] = $method;
        Input::inject($input);
        $res = Json::capture($fn);
        $_SERVER["REQUEST_METHOD"] = $prevMethod;
        if (!$res["ok"]) self::fail((string) $res["err"]);
        return is_array($res["data"]) ? $res["data"] : [];
    }

    private static function mid(array $m): string
    {
        return Ids::of($m);
    }

    /** 内部 id → 对外 uid；找不到就给空串 */
    private static function uidOf(PDO $pdo, int $id): string
    {
        if ($id <= 0) return "";
        $st = $pdo->prepare("SELECT id, uid FROM members WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ? Ids::of($row) : "";
    }

    private static function abs(string $path): string
    {
        $path = trim($path);
        if ($path === "") return "";
        if (preg_match('#^(https?://|data:)#i', $path)) return $path;
        if (str_starts_with($path, "//")) return "https:" . $path;
        return Site::baseUrl() . "/" . ltrim($path, "/");
    }

    private static function fail(string $msg): void
    {
        throw new \RuntimeException($msg);
    }

    private static function token(): string
    {
        $t = trim((string) ($_GET["k"] ?? $_GET["token"] ?? ""));
        if ($t !== "") return $t;
        $h = (string) ($_SERVER["HTTP_AUTHORIZATION"] ?? "");
        if (stripos($h, "bearer ") === 0) return trim(substr($h, 7));
        return "";
    }

    private static function scopeOf(PDO $pdo, array $me): array
    {
        $st = $pdo->prepare("SELECT * FROM scopes WHERE id = ? AND archived = 0 LIMIT 1");
        $st->execute([(int) $me["scope_id"]]);
        $row = $st->fetch();
        if (!$row) self::fail("这个身份不属于任何群");
        return $row;
    }

    private static function text(array $data): array
    {
        return ["content" => [["type" => "text", "text" => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]];
    }
}
