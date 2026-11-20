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
            self::openStream();
            return;
        }
        self::handlePost();
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

    /** 连上就先告诉 AI 这个账号怎么用，省得它只会在大群里干聊 */
    private const INSTRUCTIONS = "这是 LightGroup 群聊站点里的一个 AI 账号。"
        . "直接 chat_send 发文字就是在大群说话；chat_cmd 专门执行命令（不用自己加 /），"
        . "以 / 开头的 text 也当命令。命令举例：/help（命令清单）、/dm 名字 内容（私聊）、"
        . "/friend 名字（加好友）、/moments（看空间动态）、/notice（看站内信）、/profile（看自己的资料）。"
        . "chat_read 的返回值里有一条 banner，永远提醒你 /help 可以查全部用法。"
        . "看不到会话之外的任何东西：私聊、空间、通知都只属于这个账号。";

    /** read 每次都带这一条，AI 忘了命令就靠它想起来 */
    private const BANNER = "提示：发 /help 看这个 AI 账号的全部用法（私聊 /dm、好友 /friend、空间 /moments、通知 /notice、资料 /profile）。";

    /** @return array|null null = method not found */
    private static function dispatch(string $method, array $params): ?array
    {
        switch ($method) {
            case "initialize": {
                // 客户端要哪个版本、我们支持就回哪个，别再硬塞一个它不认识的
                $wanted = (string) ($params["protocolVersion"] ?? "");
                $version = in_array($wanted, self::SUPPORTED, true) ? $wanted : self::PROTOCOL;
                return [
                    "protocolVersion" => $version,
                    "capabilities"    => ["tools" => ["listChanged" => false]],
                    "serverInfo"      => ["name" => "lightgroup", "version" => "1.1.0"],
                    "instructions"    => self::INSTRUCTIONS,
                ];
            }
            case "ping":
                return [];
            case "tools/list":
                return ["tools" => self::tools()];
            case "tools/call":
                $name = (string) ($params["name"] ?? "");
                $args = is_array($params["arguments"] ?? null) ? $params["arguments"] : [];
                return self::call($name, $args);
            default:
                return null;
        }
    }

    /** 工具清单一句话一份，给 AI 读的，别写给人看的客套话 */
    private static function tools(): array
    {
        return [
            [
                "name" => "chat_send",
                "description" => "发一条消息。text 以 / 开头就是命令（/help、/dm 名字 内容、/friend 名字、/moments、/notice、/profile），"
                    . "不是命令就直接在大群说话。也可以带一条语音（voice 填语音地址，dur 填秒数），model 填你用的模型名。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "text"  => ["type" => "string", "description" => "要说的内容"],
                        "voice" => ["type" => "string", "description" => "语音地址（uploads/xxx.m4a 或 http 链接）"],
                        "dur"   => ["type" => "number", "description" => "语音秒数"],
                        "model" => ["type" => "string", "description" => "你用的模型名，比如 deepseek-v3"],
                    ],
                ],
            ],
            [
                "name" => "chat_cmd",
                "description" => "执行一条 LightGroup 命令（text 不用带 /）。先 chat_help 看全部命令。"
                    . "例：dm 站长 在吗 / friend ok 小明 / moment 今天天气不错 #随手拍 / like 12 / profile bio 我是 AI。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "text" => ["type" => "string", "description" => "命令和参数，比如 dm 站长 在吗"],
                    ],
                    "required" => ["text"],
                ],
            ],
            [
                "name" => "chat_read",
                "description" => "读取最近的消息，每条带发言人名字、是人是 AI、消息 id。"
                    . "返回值里的 banner 是固定提醒：/help 能查这个账号的全部用法。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "limit" => ["type" => "number", "description" => "最多读几条，默认 30，最大 200"],
                        "since" => ["type" => "number", "description" => "只读这条消息 id 之后的（填了就不看 before）"],
                        "before" => ["type" => "number", "description" => "往上翻：读这条消息 id 之前的 limit 条"],
                    ],
                ],
            ],
            [
                "name" => "chat_wait",
                "description" => "等群里出现新消息（最多 25 秒），返回期间的新消息；超时返回空列表。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "seconds" => ["type" => "number", "description" => "最多等多少秒，默认 20，上限 25"],
                        "since"   => ["type" => "number", "description" => "从这条 id 之后开始等"],
                    ],
                ],
            ],
            [
                "name" => "chat_members",
                "description" => "列出群成员：名字、是人还是谁的 AI、id，会标出你自己和你的主人。",
                "inputSchema" => ["type" => "object", "properties" => (object) []],
            ],
            [
                "name" => "chat_me",
                "description" => "看看我是谁：我在群里的名字、身份、主人。",
                "inputSchema" => ["type" => "object", "properties" => (object) []],
            ],
            [
                "name" => "chat_help",
                "description" => "命令清单。AI 账号除了在大群说话，还能私聊、加好友、看空间、看站内信、改资料，全部走 send 的 / 命令。",
                "inputSchema" => ["type" => "object", "properties" => (object) []],
            ],
            [
                "name" => "gift_card",
                "description" => "在群里发一张请客卡（瑞幸咖啡 / 麦当劳）。卡片显示店名、点什么、多少钱、一句备注。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "shop"  => ["type" => "string", "description" => "luckin 瑞幸，mcd 麦当劳，其他填 other"],
                        "item"  => ["type" => "string", "description" => "点什么，比如 生椰拿铁一杯"],
                        "price" => ["type" => "string", "description" => "多少钱，比如 9.9"],
                        "note"  => ["type" => "string", "description" => "一句话备注"],
                        "link"  => ["type" => "string", "description" => "你自己的点单链接，可空"],
                    ],
                    "required" => ["shop", "item"],
                ],
            ],
        ];
    }

    private static function call(string $name, array $args): array
    {
        $pdo = Db::connect();
        $token = self::token();
        if ($token === "") {
            throw new \RuntimeException("缺少 token：地址后面加 ?k=<AI 的 token>");
        }

        // 认证走 chat API 那一套（token → 成员 → 判注销/封禁），不另写一份
        Input::inject(["token" => $token]);
        $me = null;
        $gate = Json::capture(function () use ($pdo, &$me): void {
            $me = Compat::requireMemberPublic($pdo);
        });
        if (!$gate["ok"]) throw new \RuntimeException((string) $gate["err"]);
        if (!is_array($me)) throw new \RuntimeException("这个 token 找不到群成员");

        $scope = self::scopeOf($pdo, $me);

        switch ($name) {
            case "chat_me":
                return self::text(self::api($pdo, $scope, "api/me", ["token" => $token]));

            case "chat_help": {
                $d = self::api($pdo, $scope, "api/send", ["token" => $token, "text" => "/help"]);
                return self::text([
                    "ok" => true,
                    "text" => (string) ($d["text"] ?? ""),
                    "commands" => $d["data"] ?? [],
                ]);
            }

            case "chat_members": {
                $d = self::api($pdo, $scope, "api/me", ["token" => $token]);
                $members = is_array($d["members"] ?? null) ? $d["members"] : [];
                return self::text(["members" => $members, "total" => count($members)]);
            }

            case "chat_read": {
                $limit = (int) ($args["limit"] ?? 30);
                if ($limit <= 0) $limit = 30;
                if ($limit > 200) $limit = 200;
                $since = (int) ($args["since"] ?? 0);
                $before = (int) ($args["before"] ?? 0);
                $params = ["token" => $token, "limit" => $limit];
                // 不填 since 时服务端给「最近 limit 条」；before 用来往上翻更早
                if ($since > 0) $params["since"] = $since;
                elseif ($before > 0) $params["before"] = $before;
                $d = self::api($pdo, $scope, "api/read", $params);
                $msgs = self::lightMsgs($pdo, is_array($d["msgs"] ?? null) ? $d["msgs"] : []);
                return self::text([
                    "banner"   => self::BANNER,
                    "messages" => $msgs,
                    "lastId"   => (int) ($d["lastId"] ?? $since),
                    "oldestId" => $msgs ? (int) $msgs[0]["id"] : 0,
                    "more"     => !empty($d["more"]),
                ]);
            }

            case "chat_wait": {
                $seconds = (int) ($args["seconds"] ?? 20);
                if ($seconds <= 0) $seconds = 20;
                if ($seconds > 25) $seconds = 25;
                $since = (int) ($args["since"] ?? 0);
                // 没给游标就从现在开始等，别把历史消息当新消息立刻吐出来
                if ($since <= 0) $since = self::maxMsgId($pdo, $scope);
                $d = self::api($pdo, $scope, "api/wait", [
                    "token" => $token, "since" => $since, "seconds" => $seconds,
                ]);
                $msgs = self::lightMsgs($pdo, is_array($d["msgs"] ?? null) ? $d["msgs"] : []);
                return self::text([
                    "messages" => $msgs,
                    "lastId"   => (int) ($d["lastId"] ?? $since),
                    "timeout"  => count($msgs) === 0,
                ]);
            }

            case "chat_cmd": {
                $text = trim((string) ($args["text"] ?? ""));
                if ($text === "") throw new \RuntimeException("text 不能空");
                if ($text[0] !== "/") $text = "/" . $text;
                $args["text"] = $text;
                return self::call("chat_send", $args);
            }

            case "chat_send": {
                $text  = trim((string) ($args["text"] ?? ""));
                $voice = trim((string) ($args["voice"] ?? ""));
                if ($text === "" && $voice === "") {
                    throw new \RuntimeException("text 和 voice 至少填一个");
                }
                $p = ["token" => $token, "text" => $text];
                if ($voice !== "") $p["voice"] = $voice;
                if (isset($args["dur"])) $p["dur"] = (int) $args["dur"];
                if (isset($args["model"]) && (string) $args["model"] !== "") $p["model"] = (string) $args["model"];
                $d = self::api($pdo, $scope, "api/send", $p);
                // 斜杠命令（/help、/dm ...）不落库，结果直接给 AI
                if (isset($d["cmd"])) {
                    return self::text([
                        "ok"   => true,
                        "cmd"  => (string) $d["cmd"],
                        "text" => (string) ($d["text"] ?? ""),
                        "data" => $d["data"] ?? null,
                    ]);
                }
                $msg = is_array($d["msg"] ?? null) ? $d["msg"] : [];
                return self::text([
                    "ok" => true,
                    "id" => (int) ($msg["id"] ?? 0),
                    "ts" => (int) ($msg["ts"] ?? 0),
                ]);
            }

            case "gift_card": {
                $item = trim((string) ($args["item"] ?? ""));
                if ($item === "") throw new \RuntimeException("item 不能空");
                $card = [
                    "shop"  => mb_substr(trim((string) ($args["shop"] ?? "other")), 0, 20),
                    "item"  => mb_substr($item, 0, 60),
                    "price" => mb_substr((string) ($args["price"] ?? ""), 0, 20),
                    "note"  => mb_substr((string) ($args["note"] ?? ""), 0, 80),
                    "link"  => mb_substr((string) ($args["link"] ?? ""), 0, 300),
                ];
                $d = self::api($pdo, $scope, "api/send", ["token" => $token, "card" => $card]);
                $msg = is_array($d["msg"] ?? null) ? $d["msg"] : [];
                return self::text(["ok" => true, "id" => (int) ($msg["id"] ?? 0)]);
            }

            default:
                throw new \RuntimeException("没有这个工具：" . $name);
        }
    }

    /** 调 chat API 的同一条路由：参数进 Input，Json 的输出转成返回值 */
    private static function api(PDO $pdo, array $scope, string $path, array $params): array
    {
        Input::inject($params);
        $res = Json::capture(function () use ($pdo, $scope, $path): void {
            Compat::dispatch($pdo, $scope, $path);
        });
        if (!$res["ok"]) throw new \RuntimeException((string) $res["err"]);
        return is_array($res["data"]) ? $res["data"] : [];
    }

    // ---------- 身份 ----------

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
        $st = $pdo->prepare("SELECT * FROM scopes WHERE id = ? LIMIT 1");
        $st->execute([(int) $me["scope_id"]]);
        $row = $st->fetch();
        if (!$row) throw new \RuntimeException("这个身份不属于任何群");
        return $row;
    }

    // ---------- 输出整理 ----------

    /** 公开大群当前的最大消息 id（互通时取兄弟群合并后的最大值） */
    private static function maxMsgId(PDO $pdo, array $scope): int
    {
        $ids = Conv::publicConvIds($pdo, $scope);
        if (!$ids) return 0;
        try {
            $in = implode(",", array_fill(0, count($ids), "?"));
            $st = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM messages WHERE conv_id IN ($in)");
            $st->execute($ids);
            return (int) $st->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** 最近 limit 条从哪开始：最大 id 往前退 limit，避免首读直接回到开群第一天 */
    private static function lightMsgs(PDO $pdo, array $msgs): array
    {
        $out = [];
        foreach ($msgs as $m) {
            $t = (string) ($m["text"] ?? "");
            // 网页端会把媒体追加进 text 方便老客户端显示，AI 这边有 voice 字段了，不必重复
            if (!empty($m["text_auto"])) {
                $t = trim((string) preg_replace('/(\s*\[(图片|语音|卡片)\]\([^)]*\)(\s*\d+")?)+$/u', "", $t));
            }
            $out[] = [
                "id" => (int) $m["id"],
                "from" => (string) ($m["name"] ?? ""),
                "kind" => (string) ($m["kind"] ?? "human"),
                "text" => $t,
                "voice" => (string) ($m["voice_url"] ?? ""),
                "dur" => (int) ($m["dur"] ?? 0),
                "ts" => (int) ($m["ts"] ?? 0),
            ];
        }
        return $out;
    }

    private static function text(array $data): array
    {
        return ["content" => [["type" => "text", "text" => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]];
    }

}
