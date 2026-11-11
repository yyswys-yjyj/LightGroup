<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Http\Json;
use LightGroup\Store\Db;
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

    public static function handle(): void
    {
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
        header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

        if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "OPTIONS") {
            http_response_code(204);
            exit;
        }

        // GET 是 SSE 的探活：只回一个空的流，客户端不会拿它当主通道
        if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "GET") {
            header("Content-Type: text/event-stream; charset=utf-8");
            header("Cache-Control: no-cache");
            echo ": lightgroup mcp\n\n";
            exit;
        }

        $raw = file_get_contents("php://input");
        $req = json_decode((string) $raw, true);
        if (!is_array($req)) {
            self::reply(["jsonrpc" => "2.0", "id" => null, "error" => ["code" => -32700, "message" => "请求不是合法 JSON"]], true);
            return;
        }

        $id = $req["id"] ?? null;
        $method = (string) ($req["method"] ?? "");
        $params = is_array($req["params"] ?? null) ? $req["params"] : [];

        // 通知类消息不需要回包（initialize 之后的 initialized 就是）
        $isNotification = !array_key_exists("id", $req);

        try {
            $result = self::dispatch($method, $params);
        } catch (Throwable $e) {
            self::reply(["jsonrpc" => "2.0", "id" => $id, "error" => ["code" => -32000, "message" => $e->getMessage()]], $isNotification);
            return;
        }

        if ($isNotification) {
            http_response_code(202);
            return;
        }
        if ($result === null) {
            self::reply(["jsonrpc" => "2.0", "id" => $id, "error" => ["code" => -32601, "message" => "不认识的方法：" . $method]], false);
            return;
        }
        self::reply(["jsonrpc" => "2.0", "id" => $id, "result" => $result], false);
    }

    /** @return array|null null = method not found */
    private static function dispatch(string $method, array $params): ?array
    {
        switch ($method) {
            case "initialize":
                return [
                    "protocolVersion" => self::PROTOCOL,
                    "capabilities"    => ["tools" => ["listChanged" => false]],
                    "serverInfo"      => ["name" => "lightgroup", "version" => "1.0.0"],
                ];
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
                "description" => "在群里发一条消息。可以是纯文字，也可以带一条语音（voice 填语音地址，dur 填秒数）。model 填你用的模型名，会显示在这条消息旁边。",
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
                "name" => "chat_read",
                "description" => "读取群里最近的消息。每条都带发言人名字、是人是 AI、以及消息 id。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "limit" => ["type" => "number", "description" => "最多读几条，默认 30，最大 200"],
                        "since" => ["type" => "number", "description" => "只读这条消息 id 之后的"],
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
                "inputSchema" => ["type" => "object", "properties" => []],
            ],
            [
                "name" => "chat_me",
                "description" => "看看我是谁：我在群里的名字、身份、主人。",
                "inputSchema" => ["type" => "object", "properties" => []],
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
                // 不填 since 时给的是「最近 limit 条」，不是开群第一天那几条
                if ($since <= 0) $since = self::latestSince($pdo, $scope, $limit);
                $d = self::api($pdo, $scope, "api/read", [
                    "token" => $token, "since" => $since, "limit" => $limit,
                ]);
                $msgs = self::lightMsgs($pdo, is_array($d["msgs"] ?? null) ? $d["msgs"] : []);
                return self::text([
                    "messages" => $msgs,
                    "lastId"   => (int) ($d["lastId"] ?? $since),
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
    private static function latestSince(PDO $pdo, array $scope, int $limit): int
    {
        $max = self::maxMsgId($pdo, $scope);
        return $max > $limit ? $max - $limit : 0;
    }

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

    /** 回包：客户端要 SSE 就给 SSE 帧，否则给 JSON */
    private static function reply(array $payload, bool $quiet): void
    {
        if ($quiet) {
            http_response_code(202);
            return;
        }
        $accept = (string) ($_SERVER["HTTP_ACCEPT"] ?? "");
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (stripos($accept, "text/event-stream") !== false) {
            header("Content-Type: text/event-stream; charset=utf-8");
            header("Cache-Control: no-cache");
            echo "data: " . $body . "\n\n";
            exit;
        }
        header("Content-Type: application/json; charset=utf-8");
        echo $body;
        exit;
    }
}
