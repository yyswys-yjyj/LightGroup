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

    /**
     * 依次尝试的入口。cn.bing.com 在海外机房会被 302 到 www.bing.com，
     * 不跟随就会拿到一个空 body，正则匹配不到 IG / token —— 以前报的
     * 「凭据获取失败」就是这个。两个都试，解析成功后记住落地域名。
     */
    private const HOSTS = ['https://www.bing.com', 'https://cn.bing.com'];

    private static ?array $mem = null;

    /**
     * 返回 ['text' => 译文, 'from' => 识别语言, 'to' => 目标语言,
     *       'provider' => 通道代号, 'provider_name' => 给人看的名字]
     * 界面要像微信那样在译文下面写「翻译服务由 … 提供」，所以这条信息必须从后端带出来：
     * 主通道 Bing，兜底通道 Google，两条路走哪条不能在前端猜。
     */
    public static function translate(string $text, string $to): array {
        $text = trim($text);
        if ($text === '') throw new \RuntimeException('没有要翻译的内容');
        if (mb_strlen($text) > 2000) throw new \RuntimeException('一次最多翻译 2000 字');

        $lastErr = '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $s = self::session($attempt === 1);
            if ($s === null) continue;
            $r = self::call($s, $text, $to);
            if ($r['ok']) return self::named($r['data']);
            $lastErr = $r['err'];
        }
        // Bing 页面改版、限流、机房网络都可能让上面这条路不通，
        // 兜底换成 Google 的公开端点，用户那边不该因为一边挂了就翻不了。
        try {
            return self::named(self::google($text, $to));
        } catch (\RuntimeException $e) {
            $lastErr = $e->getMessage();
        }
        // 两条路都不通才留一行记录，方便下次在 error log 里看是哪边挂了
        error_log('[translate] 全部通道失败：' . $lastErr);
        throw new \RuntimeException($lastErr !== '' ? $lastErr : '翻译服务暂时不可用，稍后再试');
    }

    private static function session(bool $force = false): ?array {
        if (!$force) {
            if (self::$mem !== null && (time() - (int) self::$mem['at']) < self::TTL) return self::$mem;
            $file = APP_ROOT . '/data' . self::CACHE;
            if (is_file($file)) {
                $j = json_decode((string) @file_get_contents($file), true);
                if (is_array($j) && isset($j['at'], $j['ig'], $j['key'], $j['token'], $j['cookie'])) {
                    if (!isset($j['base'])) $j['base'] = 'https://cn.bing.com';
                    if ((time() - (int) $j['at']) < self::TTL) { self::$mem = $j; return $j; }
                }
            }
        }

        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

        foreach (self::HOSTS as $host) {
            $jar = tempnam(sys_get_temp_dir(), 'lgbing');
            if ($jar === false) return null;
            $ch = curl_init($host . '/translator');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_USERAGENT => $ua, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
            ]);
            $html = (string) curl_exec($ch);
            // 落地域名可能和入口不同（cn.bing.com -> www.bing.com），cookie 只对它有效
            $landed = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $base = $host;
            if (preg_match('#^(https?://[^/]+)#', $landed, $hm)) $base = $hm[1];

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

            if ($ig === '' || $key === '' || $token === '') continue;

            $s = ['at' => time(), 'ig' => $ig, 'key' => $key, 'token' => $token, 'cookie' => implode('; ', $cookies), 'ua' => $ua, 'base' => $base];
            self::$mem = $s;
            $dir = APP_ROOT . '/data/cache';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents($dir . '/bing.json', json_encode($s));
            return $s;
        }

        return null;
    }

    private static function call(array $s, string $text, string $to): array {
        // 用拿凭据时落地的域名，cookie 只在那个域下有效
        $base = (string) ($s['base'] ?? 'https://cn.bing.com');
        $url = $base . '/ttranslatev3?isVertical=1&IG=' . rawurlencode($s['ig']) . '&IID=translator.5023.1';
        $body = http_build_query(['fromLang' => 'auto-detect', 'to' => $to, 'text' => $text, 'token' => $s['token'], 'key' => $s['key']]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => $s['ua'],
            CURLOPT_COOKIE => $s['cookie'],
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Referer: ' . $base . '/translator', 'Origin: ' . $base],
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
            'provider' => 'bing',
        ]];
    }

    /**
     * 兜底通道：Google 那个不需要 key 的公开端点，返回的是一个嵌套数组，
     * 每个分句的译文在第一列，拼起来就是整段。
     */
    private static function google(string $text, string $to): array {
        $tl = self::googleLang($to);
        $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&dt=t'
             . '&tl=' . rawurlencode($tl) . '&q=' . rawurlencode($text);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'Mozilla/5.0', CURLOPT_FOLLOWLOCATION => true,
        ]);
        $out = (string) curl_exec($ch);
        $err = curl_error($ch);
        if ($err !== '') throw new \RuntimeException('翻译服务连不上，稍后再试');

        $j = json_decode($out, true);
        if (!is_array($j) || !isset($j[0]) || !is_array($j[0])) throw new \RuntimeException('翻译服务暂时不可用，稍后再试');
        $buf = '';
        foreach ($j[0] as $seg) {
            if (is_array($seg) && isset($seg[0])) $buf .= (string) $seg[0];
        }
        if ($buf === '') throw new \RuntimeException('翻译服务暂时不可用，稍后再试');

        return ['text' => $buf, 'from' => (string) ($j[2] ?? ''), 'to' => $tl, 'provider' => 'google'];
    }

    /** 给译文补上「谁翻的」，前端照着显示在译文下面 */
    private static function named(array $d): array {
        $names = ['bing' => '微软 Bing', 'google' => 'Google'];
        $p = (string) ($d['provider'] ?? '');
        $d['provider'] = $p;
        $d['provider_name'] = $names[$p] ?? '第三方服务';
        return $d;
    }

    /** 目标语言写法统一：界面传的是 zh-Hans 这种，Google 要 zh-CN */
    private static function googleLang(string $to): string {
        $map = [
            'zh-hans' => 'zh-CN', 'zh-hant' => 'zh-TW', 'zh' => 'zh-CN',
            'en' => 'en', 'ja' => 'ja', 'ko' => 'ko', 'fr' => 'fr',
            'de' => 'de', 'ru' => 'ru', 'es' => 'es', 'pt' => 'pt', 'it' => 'it',
        ];
        $k = strtolower($to);
        return $map[$k] ?? $to;
    }
}