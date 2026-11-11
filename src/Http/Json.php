<?php
declare(strict_types=1);

namespace LightGroup\Http;

/**
 * 捕获模式下的控制流：Json::ok/fail 抛它，调用方接住当返回值用。
 * 只在 Json::capture() 里抛，正常 HTTP 请求永远走不到这里。
 */
final class JsonHalt extends \Exception {
    public bool $isOk;
    public array $data;
    public string $err;

    public function __construct(bool $isOk, array $data = [], string $err = "") {
        parent::__construct($isOk ? "ok" : $err);
        $this->isOk = $isOk;
        $this->data = $data;
        $this->err  = $err;
    }
}

final class Json {
    private static bool $capturing = false;

    public static function out(array $data, int $code = 200): void {
        if (self::$capturing) throw new JsonHalt(true, $data);
        if (!headers_sent()) {
            http_response_code($code);
            header("Content-Type: application/json; charset=utf-8");
            header("Cache-Control: no-store");
            header("X-Content-Type-Options: nosniff");
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(array $data = []): void {
        if (self::$capturing) throw new JsonHalt(true, ["ok" => true] + $data);
        self::out(["ok" => true] + $data);
    }

    public static function fail(string $err, int $code = 200): void {
        if (self::$capturing) throw new JsonHalt(false, [], $err);
        self::out(["ok" => false, "err" => $err], $code);
    }

    /**
     * 在捕获模式里跑一段会调 Json::ok/fail 的业务代码，把结果当数组返回：
     * ["ok" => true, "data" => [...]] 或 ["ok" => false, "err" => "..."]。
     *
     * MCP 要跟网页聊天用同一套逻辑（认证、校验、落库、互通），靠的就是这个：
     * 同一个函数原样跑，只是不往响应里写，而是把结果交回给调用方。
     */
    public static function capture(callable $fn): array {
        $prev = self::$capturing;
        self::$capturing = true;
        try {
            $fn();
            return ["ok" => true, "data" => []];
        } catch (JsonHalt $h) {
            return $h->isOk ? ["ok" => true, "data" => $h->data] : ["ok" => false, "err" => $h->err];
        } finally {
            self::$capturing = $prev;
        }
    }
}
