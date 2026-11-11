<?php
declare(strict_types=1);

namespace LightGroup\Api;

use PDO;

/**
 * 语音转文字。
 *
 * 站内没有识别引擎，转写走外部 OpenAI 兼容接口（/v1/audio/transcriptions）。
 * 超管在后台填地址、Key、模型才算开启；没配就明确告诉用户「站点没开语音转文字」，
 * 不要假装在转、转半天不出字。
 */
final class Stt {
    private const DEFAULT_MODEL = "whisper-1";

    public static function configured(PDO $pdo): bool {
        return self::conf($pdo, "enabled", "0") === "1" && trim(self::conf($pdo, "url", "")) !== "";
    }

    /** 后台用：只回「配没配 Key」，不回明文 */
    public static function hasKey(PDO $pdo): bool {
        return trim(self::conf($pdo, "key", "")) !== "";
    }

    private static function conf(PDO $pdo, string $k, string $d = ""): string {
        $st = $pdo->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
        $st->execute(["stt." . $k]);
        $row = $st->fetch();
        return ($row !== false && $row !== null) ? (string) $row["v"] : $d;
    }

    /**
     * 转写一条语音。$voice 是消息里的 voice 字段（本站上传后是完整 URL）。
     * 返回 ["ok"=>bool, "text"=>string, "err"=>string]。
     */
    public static function transcribe(PDO $pdo, string $voice): array {
        if (!self::configured($pdo)) return ["ok" => false, "text" => "", "err" => "站点没开语音转文字"];

        $file = self::localFile($voice);
        $tmp  = "";
        if ($file === null) {
            // 站内上传的语音找不到文件，别再当成外链去下载，报个说得清的原因
            if (strpos($voice, "/uploads/") !== false) {
                return ["ok" => false, "text" => "", "err" => "语音文件找不到了"];
            }
            $tmp = self::download($voice);
            if ($tmp === "") return ["ok" => false, "text" => "", "err" => "下载语音失败"];
            $file = $tmp;
        }

        $url = trim(self::conf($pdo, "url", ""));
        // 允许只填到主机（https://api.openai.com），剩下的路径由我们补全
        if (!preg_match('#/audio/(transcriptions|translations)$#', $url)) {
            $url = rtrim($url, "/") . "/v1/audio/transcriptions";
        }
        $model = trim(self::conf($pdo, "model", ""));
        if ($model === "") $model = self::DEFAULT_MODEL;
        $key = trim(self::conf($pdo, "key", ""));

        $ch = curl_init($url);
        $headers = ["Accept: application/json"];
        if ($key !== "") $headers[] = "Authorization: Bearer " . $key;
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_POSTFIELDS     => [
                "file"  => new \CURLFile($file, self::mime($file), basename($file)),
                "model" => $model,
            ],
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_MAXREDIRS      => 0,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        // PHP 8 起 curl_close 是空操作，8.5 还会报弃用，这里不再调用
        unset($ch);

        if ($tmp !== "" && is_file($tmp)) @unlink($tmp);

        if ($resp === false || $code < 200 || $code >= 300) {
            $detail = "";
            if (is_string($resp) && $resp !== "") {
                $j = json_decode($resp, true);
                if (is_array($j) && isset($j["error"]["message"])) $detail = (string) $j["error"]["message"];
                elseif (is_array($j) && isset($j["error"]) && is_string($j["error"])) $detail = $j["error"];
            }
            if ($detail === "") $detail = $cerr !== "" ? $cerr : ("接口返回 HTTP " . $code);
            return ["ok" => false, "text" => "", "err" => "转写失败：" . mb_substr($detail, 0, 200)];
        }

        $j = json_decode((string) $resp, true);
        $text = is_array($j) ? trim((string) ($j["text"] ?? "")) : "";
        if ($text === "") return ["ok" => false, "text" => "", "err" => "接口没返回文字"];
        return ["ok" => true, "text" => $text, "err" => ""];
    }

    /** 本站上传的文件直接读本地，省一次回环下载 */
    private static function localFile(string $voice): ?string {
        $p = strpos($voice, "/uploads/");
        if ($p === false) return null;
        $root = dirname(__DIR__, 2);
        $path = $root . "/public" . substr($voice, $p);
        return is_file($path) ? $path : null;
    }

    private static function download(string $voice): string {
        if (!preg_match('#^https?://#i', $voice)) return "";
        $root = dirname(__DIR__, 2);
        $dir  = $root . "/data/tmp";
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return "";
        $tmp = $dir . "/stt-" . bin2hex(random_bytes(6)) . ".webm";
        $fh = @fopen($tmp, "wb");
        if ($fh === false) return "";
        $ch = curl_init($voice);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        fclose($fh);
        if ($code < 200 || $code >= 300) { @unlink($tmp); return ""; }
        return $tmp;
    }

    private static function mime(string $file): string {
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $map = [
            "webm" => "audio/webm", "ogg" => "audio/ogg", "oga" => "audio/ogg",
            "wav" => "audio/wav", "mp3" => "audio/mpeg", "m4a" => "audio/mp4",
            "mp4" => "audio/mp4", "aac" => "audio/aac", "flac" => "audio/flac",
        ];
        return $map[$ext] ?? "application/octet-stream";
    }
}
