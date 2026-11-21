<?php
declare(strict_types=1);

namespace LightGroup\Net;

/**
 * 传输层工具：HTTP 客户端/服务端、SSE、WebSocket。
 *
 * 现在只给 onebot11 之类的适配器预留，业务代码还没调用。
 * 全部只用 PHP 自带扩展（curl + stream_socket_*），不依赖 Swoole/Workerman。
 * 纯阻塞、单进程，别在网页请求里起服务器。
 */
final class Transport {
    // ---------------- HTTP 客户端 ----------------

    /**
     * 发一个 HTTP 请求。
     * @param array<int,string> $headers 形如 ["Accept: application/json"]
     * @return array{status:int,headers:array<string,string>,body:string,err:string}
     */
    public static function http(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array {
        $out = ["status" => 0, "headers" => [], "body" => "", "err" => ""];
        if (!function_exists("curl_init")) {
            $out["err"] = "没有 curl 扩展";
            return $out;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            $out["err"] = "curl 初始化失败";
            return $out;
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => function ($c, string $line) use (&$respHeaders): int {
                $p = strpos($line, ":");
                if ($p !== false) {
                    $k = strtolower(trim(substr($line, 0, $p)));
                    $v = trim(substr($line, $p + 1));
                    if ($k !== "") $respHeaders[$k] = $v;
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $out["status"] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $out["err"] = curl_error($ch);
        $out["headers"] = $respHeaders;
        $out["body"] = is_string($raw) ? $raw : "";
        curl_close($ch);
        return $out;
    }

    /** JSON 请求；返回 [解码后的数组或 null, 原始响应] */
    public static function httpJson(string $method, string $url, ?array $payload = null, array $headers = [], int $timeout = 15): array {
        $hd = $headers;
        $hd[] = "Accept: application/json";
        $body = null;
        if ($payload !== null) {
            $hd[] = "Content-Type: application/json";
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $res = self::http($method, $url, $hd, $body, $timeout);
        $data = json_decode($res["body"], true);
        return [is_array($data) ? $data : null, $res];
    }

    // ---------------- HTTP 服务端 ----------------

    /** 起一个阻塞式 HTTP 服务器。$handler(array $req): array{status?:int,headers?:array,body?:string} */
    public static function serveHttp(string $addr, callable $handler, int $maxRequests = 0): void {
        $srv = @stream_socket_server("tcp://" . $addr, $errno, $errstr);
        if ($srv === false) throw new \RuntimeException("监听失败: " . $errstr . " (" . $errno . ")");
        $served = 0;
        while (true) {
            $conn = @stream_socket_accept($srv, 30);
            if ($conn === false) continue;
            try {
                $req = self::readRequest($conn);
                if ($req === null) continue;
                $res = $handler($req);
                self::writeResponse($conn, $res);
            } catch (\Throwable $e) {
                // 一个连接出错不能拖垮整个服务
            } finally {
                @fclose($conn);
            }
            $served++;
            if ($maxRequests > 0 && $served >= $maxRequests) break;
        }
        @fclose($srv);
    }

    /** @return array{method:string,path:string,query:array<string,string>,headers:array<string,string>,body:string}|null */
    public static function readRequest($conn): ?array {
        $head = "";
        while (strpos($head, "\r\n\r\n") === false) {
            $line = fgets($conn, 8192);
            if ($line === false) return null;
            $head .= $line;
            if (strlen($head) > 65536) return null;
        }
        [$rawHead, $rest] = explode("\r\n\r\n", $head, 2);
        $lines = explode("\r\n", $rawHead);
        $first = explode(" ", (string) array_shift($lines));
        $method = strtoupper($first[0] ?? "GET");
        $target = $first[1] ?? "/";
        $q = strpos($target, "?");
        $path = $q === false ? $target : substr($target, 0, $q);
        parse_str($q === false ? "" : substr($target, $q + 1), $query);
        $headers = [];
        foreach ($lines as $l) {
            $p = strpos($l, ":");
            if ($p !== false) $headers[strtolower(trim(substr($l, 0, $p)))] = trim(substr($l, $p + 1));
        }
        $body = $rest;
        $len = (int) ($headers["content-length"] ?? 0);
        while (strlen($body) < $len) {
            $chunk = fread($conn, $len - strlen($body));
            if ($chunk === false || $chunk === "") break;
            $body .= $chunk;
        }
        return ["method" => $method, "path" => $path, "query" => $query, "headers" => $headers, "body" => $body];
    }

    /** @param array{status?:int,headers?:array<string,string>,body?:string} $res */
    public static function writeResponse($conn, array $res): void {
        $status = (int) ($res["status"] ?? 200);
        $body = (string) ($res["body"] ?? "");
        $headers = $res["headers"] ?? [];
        $headers["Content-Length"] ??= (string) strlen($body);
        $headers["Connection"] ??= "close";
        $out = "HTTP/1.1 " . $status . " " . self::reason($status) . "\r\n";
        foreach ($headers as $k => $v) $out .= $k . ": " . $v . "\r\n";
        $out .= "\r\n" . $body;
        @fwrite($conn, $out);
    }

    public static function reason(int $code): string {
        static $map = [
            200 => "OK", 201 => "Created", 202 => "Accepted", 204 => "No Content",
            400 => "Bad Request", 401 => "Unauthorized", 403 => "Forbidden", 404 => "Not Found",
            405 => "Method Not Allowed", 409 => "Conflict", 429 => "Too Many Requests",
            500 => "Internal Server Error", 503 => "Service Unavailable",
        ];
        return $map[$code] ?? "OK";
    }

    // ---------------- SSE 服务端 ----------------

    /** 先发这一组响应头，之后用 sseSend 推事件 */
    public static function sseStart($conn, array $headers = []): void {
        $headers["Content-Type"] = "text/event-stream; charset=utf-8";
        $headers["Cache-Control"] = "no-cache, no-transform";
        $headers["Connection"] = "keep-alive";
        $headers["X-Accel-Buffering"] = "no";
        $out = "HTTP/1.1 200 OK\r\n";
        foreach ($headers as $k => $v) $out .= $k . ": " . $v . "\r\n";
        $out .= "\r\n";
        @fwrite($conn, $out);
        @fflush($conn);
    }

    /** 推一个 SSE 事件；$data 是数组时自动 JSON。 */
    public static function sseSend($conn, string $event, $data, ?string $id = null): bool {
        $buf = "";
        if ($id !== null) $buf .= "id: " . $id . "\n";
        if ($event !== "") $buf .= "event: " . $event . "\n";
        $payload = is_string($data) ? $data : (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (explode("\n", $payload) as $line) $buf .= "data: " . $line . "\n";
        $buf .= "\n";
        $ok = @fwrite($conn, $buf);
        @fflush($conn);
        return $ok !== false;
    }

    /** 心跳：什么都不推，只打注释行，防止中间代理掐连接 */
    public static function ssePing($conn, string $comment = "ping"): bool {
        $ok = @fwrite($conn, ": " . $comment . "\n\n");
        @fflush($conn);
        return $ok !== false;
    }

    // ---------------- WebSocket 服务端 ----------------

    /** 服务端握手：校验请求头，返回要写回去的 101 响应（null=不是合法握手） */
    public static function wsHandshake(array $headers): ?string {
        $key = (string) ($headers["sec-websocket-key"] ?? "");
        if ($key === "") return null;
        $accept = self::wsAccept($key);
        return "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: " . $accept . "\r\n\r\n";
    }

    public static function wsAccept(string $key): string {
        return base64_encode(sha1(trim($key) . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
    }

    /** 组一帧。服务端发出去不用掩码，客户端发过来必须掩码。 */
    public static function wsFrame(string $payload, int $opcode = 0x1, bool $mask = false, bool $fin = true): string {
        $len = strlen($payload);
        $b0 = ($fin ? 0x80 : 0x00) | ($opcode & 0x0f);
        $head = chr($b0);
        $mbit = $mask ? 0x80 : 0x00;
        if ($len < 126) {
            $head .= chr($mbit | $len);
        } elseif ($len < 65536) {
            $head .= chr($mbit | 126) . pack("n", $len);
        } else {
            $head .= chr($mbit | 127) . pack("J", $len);
        }
        if (!$mask) return $head . $payload;
        $key = random_bytes(4);
        $masked = "";
        for ($i = 0; $i < $len; $i++) $masked .= $payload[$i] ^ $key[$i % 4];
        return $head . $key . $masked;
    }

    /**
     * 从缓冲区里解一帧。
     * @return array{opcode:int,payload:string,fin:bool,used:int}|null null=还不够
     */
    public static function wsUnframe(string $buf): ?array {
        $n = strlen($buf);
        if ($n < 2) return null;
        $b0 = ord($buf[0]);
        $b1 = ord($buf[1]);
        $fin = ($b0 & 0x80) !== 0;
        $opcode = $b0 & 0x0f;
        $masked = ($b1 & 0x80) !== 0;
        $len = $b1 & 0x7f;
        $pos = 2;
        if ($len === 126) {
            if ($n < 4) return null;
            $len = unpack("n", substr($buf, 2, 2))[1];
            $pos = 4;
        } elseif ($len === 127) {
            if ($n < 10) return null;
            $len = unpack("J", substr($buf, 2, 8))[1];
            $pos = 10;
        }
        $key = "";
        if ($masked) {
            if ($n < $pos + 4) return null;
            $key = substr($buf, $pos, 4);
            $pos += 4;
        }
        if ($n < $pos + $len) return null;
        $payload = substr($buf, $pos, $len);
        if ($masked && $key !== "") {
            $out = "";
            for ($i = 0; $i < $len; $i++) $out .= $payload[$i] ^ $key[$i % 4];
            $payload = $out;
        }
        return ["opcode" => $opcode, "payload" => $payload, "fin" => $fin, "used" => $pos + $len];
    }

    // ---------------- WebSocket 客户端 ----------------

    /**
     * 连一个 ws:// 或 wss:// 地址并握手。
     * @return array{sock:resource|null,rest:string,err:string}
     */
    public static function wsConnect(string $url, array $extraHeaders = [], int $timeout = 10): array {
        $out = ["sock" => null, "rest" => "", "err" => ""];
        $p = parse_url($url);
        if (!is_array($p) || !isset($p["host"])) {
            $out["err"] = "地址不对";
            return $out;
        }
        $tls = ($p["scheme"] ?? "ws") === "wss";
        $port = (int) ($p["port"] ?? ($tls ? 443 : 80));
        $host = (string) $p["host"];
        $path = (string) ($p["path"] ?? "/");
        if (isset($p["query"])) $path .= "?" . $p["query"];

        $sock = @stream_socket_client(($tls ? "tls://" : "tcp://") . $host . ":" . $port, $errno, $errstr, $timeout);
        if ($sock === false) {
            $out["err"] = "连不上: " . $errstr;
            return $out;
        }
        $key = base64_encode(random_bytes(16));
        $req = "GET " . $path . " HTTP/1.1\r\n"
            . "Host: " . $host . ($port === 80 || $port === 443 ? "" : ":" . $port) . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: " . $key . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n";
        foreach ($extraHeaders as $h) $req .= $h . "\r\n";
        $req .= "\r\n";
        @fwrite($sock, $req);

        $head = "";
        while (strpos($head, "\r\n\r\n") === false) {
            $line = fgets($sock, 4096);
            if ($line === false) break;
            $head .= $line;
            if (strlen($head) > 32768) break;
        }
        if (!preg_match("#^HTTP/1\.[01] 101#", $head)) {
            @fclose($sock);
            $out["err"] = "握手失败";
            return $out;
        }
        $parts = explode("\r\n\r\n", $head, 2);
        $out["sock"] = $sock;
        $out["rest"] = $parts[1] ?? "";
        return $out;
    }
}
