<?php
declare(strict_types=1);

namespace LightGroup\Api;

/**
 * 翻译：借 Bing 网页版的免费接口（ttranslatev3）。
 * 流程是先在 translator 页拿 IG + 防滥用 token，再带同一份 cookie 去 POST。
 * token 约几分钟失效，所以缓存到 data/cache/bing.json，过期或 403 时重新取。
 */
final class Translator {
    private const CACHE = '/cache/bing.json';
    private const TTL = 420; // 7 分钟，比 Bing 的 token 有效期短一点

    private static ?array $mem = null;

    /** 返回 ['text' => 译文, 'from' => 识别语言, 'to' => 目标语言] */
    public static function translate(string $text, string $to): array {
        $text = trim($text);
        if ($text === '') throw new \RuntimeException('没有要翻译的内容');
        if (mb_strlen($text) > 2000) throw new \RuntimeException('一次最多翻译 2000 字');

        $lastErr = '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $s = self::session($attempt === 1);
            if ($s === null) { $lastErr = '拿不到翻译凭据'; continue; }
            $r = self::call($s, $text, $to);
            if ($r['ok']) return $r['data'];
            $lastErr = $r['err'];
        }
        throw new \RuntimeException($lastErr !== '' ? $lastErr : '翻译服务暂时不可用');
    }

    private static function session(bool $force = false): ?array {
        if (!$force) {
            if (self::$mem !== null && (time() - (int) self::$mem['at']) < self::TTL) return self::$mem;
            $file = APP_ROOT . '/data' . self::CACHE;
            if (is_file($file)) {
                $j = json_decode((string) @file_get_contents($file), true);
                if (is_array($j) && isset($j['at'], $j['ig'], $j['key'], $j['token'], $j['cookie'])) {
                    if ((time() - (int) $j['at']) < self::TTL) { self::$mem = $j; return $j; }
                }
            }
        }

        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
        $jar = tempnam(sys_get_temp_dir(), 'lgbing');
        if ($jar === false) return null;
        $ch = curl_init('https://cn.bing.com/translator');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => $ua, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        ]);
        $html = (string) curl_exec($ch);

        $ig = $key = $token = '';
        if (preg_match('/IG:"([A-Fa-f0-9]+)"/', $html, $m)) $ig = $m[1];
        if (preg_match('/params_AbusePreventionHelper\s*=\s*\[(\d+),"([^"]+)",(\d+)\]/', $html, $m)) { $key = $m[1]; $token = $m[2]; }

        $cookies = [];
        foreach (file($jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') continue;
            $p = explode("\t", $line);
            if (count($p) >= 7) $cookies[] = $p[5] . '=' . $p[6];
        }
        @unlink($jar);

        if ($ig === '' || $key === '' || $token === '') return null;

        $s = ['at' => time(), 'ig' => $ig, 'key' => $key, 'token' => $token, 'cookie' => implode('; ', $cookies), 'ua' => $ua];
        self::$mem = $s;
        $dir = APP_ROOT . '/data/cache';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/bing.json', json_encode($s));
        return $s;
    }

    private static function call(array $s, string $text, string $to): array {
        $url = 'https://cn.bing.com/ttranslatev3?isVertical=1&IG=' . rawurlencode($s['ig']) . '&IID=translator.5023.1';
        $body = http_build_query(['fromLang' => 'auto-detect', 'to' => $to, 'text' => $text, 'token' => $s['token'], 'key' => $s['key']]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => $s['ua'],
            CURLOPT_COOKIE => $s['cookie'],
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Referer: https://cn.bing.com/translator', 'Origin: https://cn.bing.com'],
        ]);
        $out = (string) curl_exec($ch);
        $err = curl_error($ch);

        if ($err !== '') return ['ok' => false, 'err' => '网络不通：' . $err];
        $j = json_decode($out, true);
        if (!is_array($j)) return ['ok' => false, 'err' => '翻译服务返回了看不懂的东西'];
        if (isset($j['statusCode'])) return ['ok' => false, 'err' => '翻译失败：' . (string) ($j['statusCode'] ?? '')];
        if (!isset($j[0]['translations'][0]['text'])) return ['ok' => false, 'err' => '翻译失败'];

        return ['ok' => true, 'data' => [
            'text' => (string) $j[0]['translations'][0]['text'],
            'from' => (string) ($j[0]['detectedLanguage']['language'] ?? ''),
            'to'   => (string) ($j[0]['translations'][0]['to'] ?? $to),
        ]];
    }
}