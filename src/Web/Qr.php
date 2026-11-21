<?php

declare(strict_types=1);

namespace LightGroup\Web;

/**
 * 极简二维码生成器：字节模式、纠错等级 M、版本 1–10。
 * 只输出 SVG，不依赖 gd / imagick / composer —— 分享页、群二维码、
 * 个人二维码都用它，生成的是矢量图，放大到多少都不糊。
 *
 * 算法参照 ISO/IEC 18004：数据编码 → Reed-Solomon 纠错 → 分块交织 →
 * 功能图案 → 数据放置 → 8 种掩码评分取最优 → 格式信息。
 */
final class Qr
{
    /** 再长就不是二维码该干的事了（需要版本 11+） */
    private const MAX_VERSION = 10;

    /**
     * 版本 => [数据码字数, 每块纠错码字数, [[块数, 每块数据码字数], ...]]（ECC M）
     */
    private const TBL = [
        1  => [16, 10, [[1, 16]]],
        2  => [28, 16, [[1, 28]]],
        3  => [44, 26, [[1, 44]]],
        4  => [64, 18, [[2, 32]]],
        5  => [86, 24, [[2, 43]]],
        6  => [108, 16, [[4, 27]]],
        7  => [124, 18, [[4, 31]]],
        8  => [154, 22, [[2, 38], [2, 39]]],
        9  => [182, 22, [[3, 36], [2, 37]]],
        10 => [216, 26, [[4, 43], [1, 44]]],
    ];

    /** 各版本对齐图案的中心坐标 */
    private const ALIGN = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** @var int[] */
    private static $exp = [];
    /** @var int[] */
    private static $log = [];
    private static $gfReady = false;

    /**
     * 生成 SVG 字符串（白底 + 深色模块 + 静区）。
     */
    public static function svg(string $text, int $px = 240, int $margin = 4): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $total = $n + $margin * 2;
        $paths = '';
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($m[$r][$c] === 1) {
                    $paths .= 'M' . ($c + $margin) . ' ' . ($r + $margin) . 'h1v1h-1z';
                }
            }
        }
        $size = max(64, min(1024, $px));
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '"'
            . ' viewBox="0 0 ' . $total . ' ' . $total . '" shape-rendering="crispEdges" role="img">'
            . '<rect width="100%" height="100%" fill="#ffffff"/>'
            . '<path d="' . $paths . '" fill="#111111"/>'
            . '</svg>';
    }

    /**
     * 返回模块矩阵：$m[row][col] === 1 表示深色。
     *
     * @return array<int, array<int, int>>
     */
    public static function matrix(string $text): array
    {
        self::gfInit();
        $len = strlen($text);
        if ($len === 0) {
            throw new InvalidArgumentException('内容不能为空');
        }

        $ver = 0;
        $countBits = 8;
        for ($v = 1; $v <= self::MAX_VERSION; $v++) {
            $cb = $v <= 9 ? 8 : 16;
            if (4 + $cb + $len * 8 <= self::TBL[$v][0] * 8) {
                $ver = $v;
                $countBits = $cb;
                break;
            }
        }
        if ($ver === 0) {
            throw new InvalidArgumentException('内容太长，二维码装不下');
        }

        $bits = [];
        self::pushBits($bits, 4, 4); // 字节模式 0100
        self::pushBits($bits, $len, $countBits);
        for ($i = 0; $i < $len; $i++) {
            self::pushBits($bits, ord($text[$i]), 8);
        }

        $cap = self::TBL[$ver][0] * 8;
        $padTail = min(4, $cap - count($bits));
        if ($padTail > 0) {
            self::pushBits($bits, 0, $padTail);
        }
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        $pads = [0xEC, 0x11];
        $pi = 0;
        while (count($bits) < $cap) {
            self::pushBits($bits, $pads[$pi % 2], 8);
            $pi++;
        }

        $data = [];
        $nbits = count($bits);
        for ($i = 0; $i < $nbits; $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $data[] = $byte;
        }

        $ecc = self::TBL[$ver][1];
        $groups = self::TBL[$ver][2];
        $chunks = [];
        $eccBlocks = [];
        $pos = 0;
        foreach ($groups as $g) {
            $cnt = $g[0];
            $dlen = $g[1];
            for ($i = 0; $i < $cnt; $i++) {
                $chunk = array_slice($data, $pos, $dlen);
                $pos += $dlen;
                $chunks[] = $chunk;
                $eccBlocks[] = self::rs($chunk, $ecc);
            }
        }

        $streamBytes = [];
        $maxLen = 0;
        foreach ($chunks as $c) {
            $maxLen = max($maxLen, count($c));
        }
        for ($i = 0; $i < $maxLen; $i++) {
            foreach ($chunks as $c) {
                if (isset($c[$i])) {
                    $streamBytes[] = $c[$i];
                }
            }
        }
        $eccLen = count($eccBlocks) > 0 ? count($eccBlocks[0]) : 0;
        for ($i = 0; $i < $eccLen; $i++) {
            foreach ($eccBlocks as $b) {
                $streamBytes[] = $b[$i];
            }
        }

        $stream = [];
        foreach ($streamBytes as $b) {
            for ($i = 7; $i >= 0; $i--) {
                $stream[] = ($b >> $i) & 1;
            }
        }

        $size = 17 + 4 * $ver;
        $mod = [];
        $fun = [];
        for ($r = 0; $r < $size; $r++) {
            $mod[$r] = array_fill(0, $size, 0);
            $fun[$r] = array_fill(0, $size, false);
        }

        // 定位图案的时序线先铺，finder 再盖上去（finder 覆盖的部分本来就该是它的样子）
        for ($i = 0; $i < $size; $i++) {
            self::setMod($mod, $fun, 6, $i, $i % 2 === 0 ? 1 : 0);
            self::setMod($mod, $fun, $i, 6, $i % 2 === 0 ? 1 : 0);
        }
        self::drawFinder($mod, $fun, $size, 3, 3);
        self::drawFinder($mod, $fun, $size, $size - 4, 3);
        self::drawFinder($mod, $fun, $size, 3, $size - 4);

        $align = self::ALIGN[$ver];
        $np = count($align);
        for ($a = 0; $a < $np; $a++) {
            for ($b = 0; $b < $np; $b++) {
                if (($a === 0 && $b === 0) || ($a === 0 && $b === $np - 1) || ($a === $np - 1 && $b === 0)) {
                    continue;
                }
                $cx = $align[$b];
                $cy = $align[$a];
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $dark = max(abs($dx), abs($dy)) !== 1 ? 1 : 0;
                        self::setMod($mod, $fun, $cy + $dy, $cx + $dx, $dark);
                    }
                }
            }
        }

        if ($ver >= 7) {
            $rem = $ver;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ ((($rem >> 11) & 1) === 1 ? 0x1F25 : 0);
            }
            $vbits = ($ver << 12) | ($rem & 0xFFF);
            for ($i = 0; $i < 18; $i++) {
                $bit = ($vbits >> $i) & 1;
                $a1 = $size - 11 + $i % 3;
                $b1 = intdiv($i, 3);
                self::setMod($mod, $fun, $b1, $a1, $bit);
                self::setMod($mod, $fun, $a1, $b1, $bit);
            }
        }

        // 先把格式信息的位置占住，否则数据会写到这里、随后又被格式信息盖掉
        self::drawFormat($mod, $fun, $size, 0);

        // 数据放置：右下角起，两列一组，蛇形往上往下
        $idx = 0;
        $totalBits = count($stream);
        $upward = true;
        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }
            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? $size - 1 - $i : $i;
                for ($c = 0; $c < 2; $c++) {
                    $cc = $col - $c;
                    if ($fun[$row][$cc]) {
                        continue;
                    }
                    $mod[$row][$cc] = $idx < $totalBits ? $stream[$idx] : 0;
                    $idx++;
                }
            }
            $upward = !$upward;
        }

        $best = 0;
        $bestScore = PHP_INT_MAX;
        $bestMat = $mod;
        for ($mask = 0; $mask < 8; $mask++) {
            $m2 = $mod;
            $f2 = $fun;
            for ($r = 0; $r < $size; $r++) {
                for ($c = 0; $c < $size; $c++) {
                    if (!$fun[$r][$c] && self::maskBit($mask, $r, $c)) {
                        $m2[$r][$c] ^= 1;
                    }
                }
            }
            self::drawFormat($m2, $f2, $size, $mask);
            $score = self::penalty($m2, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $mask;
                $bestMat = $m2;
            }
        }

        return $bestMat;
    }

    /** @param int[] $bits */
    private static function pushBits(array &$bits, int $val, int $n): void
    {
        for ($i = $n - 1; $i >= 0; $i--) {
            $bits[] = ($val >> $i) & 1;
        }
    }

    private static function gfInit(): void
    {
        if (self::$gfReady) {
            return;
        }
        self::$exp = array_fill(0, 512, 0);
        self::$log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if (($x & 0x100) !== 0) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
        self::$gfReady = true;
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /**
     * Reed-Solomon 纠错码字。
     *
     * @param int[] $data
     * @return int[]
     */
    private static function rs(array $data, int $ecc): array
    {
        $gen = [1];
        for ($i = 0; $i < $ecc; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= self::gfMul($coef, self::$exp[$i]);
            }
            $gen = $next;
        }

        $rem = array_fill(0, $ecc, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $rem[0];
            array_shift($rem);
            $rem[] = 0;
            for ($i = 0; $i < $ecc; $i++) {
                $rem[$i] ^= self::gfMul($gen[$i + 1], $factor);
            }
        }
        return $rem;
    }

    /**
     * @param array<int, array<int, int>> $mod
     * @param array<int, array<int, bool>> $fun
     */
    private static function setMod(array &$mod, array &$fun, int $r, int $c, int $v): void
    {
        $mod[$r][$c] = $v === 1 ? 1 : 0;
        $fun[$r][$c] = true;
    }

    /**
     * @param array<int, array<int, int>> $mod
     * @param array<int, array<int, bool>> $fun
     */
    private static function drawFinder(array &$mod, array &$fun, int $size, int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x < 0 || $x >= $size || $y < 0 || $y >= $size) {
                    continue;
                }
                self::setMod($mod, $fun, $y, $x, ($dist !== 2 && $dist !== 4) ? 1 : 0);
            }
        }
    }

    private static function maskBit(int $mask, int $r, int $c): bool
    {
        switch ($mask) {
            case 0:
                return ($r + $c) % 2 === 0;
            case 1:
                return $r % 2 === 0;
            case 2:
                return $c % 3 === 0;
            case 3:
                return ($r + $c) % 3 === 0;
            case 4:
                return (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0;
            case 5:
                return ((($r * $c) % 2) + (($r * $c) % 3)) === 0;
            case 6:
                return (((($r * $c) % 2) + (($r * $c) % 3)) % 2) === 0;
            case 7:
                return (((($r + $c) % 2) + (($r * $c) % 3)) % 2) === 0;
        }
        return false;
    }

    /**
     * @param array<int, array<int, int>> $mod
     * @param array<int, array<int, bool>> $fun
     */
    private static function drawFormat(array &$mod, array &$fun, int $size, int $mask): void
    {
        // 纠错等级 M 的 formatBits 是 0
        $data = $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) === 1 ? 0x537 : 0);
        }
        $bits = (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;

        for ($i = 0; $i <= 5; $i++) {
            self::setMod($mod, $fun, $i, 8, ($bits >> $i) & 1);
        }
        self::setMod($mod, $fun, 7, 8, ($bits >> 6) & 1);
        self::setMod($mod, $fun, 8, 8, ($bits >> 7) & 1);
        self::setMod($mod, $fun, 8, 7, ($bits >> 8) & 1);
        for ($i = 9; $i < 15; $i++) {
            self::setMod($mod, $fun, 8, 14 - $i, ($bits >> $i) & 1);
        }
        for ($i = 0; $i < 8; $i++) {
            self::setMod($mod, $fun, 8, $size - 1 - $i, ($bits >> $i) & 1);
        }
        for ($i = 8; $i < 15; $i++) {
            self::setMod($mod, $fun, $size - 15 + $i, 8, ($bits >> $i) & 1);
        }
        self::setMod($mod, $fun, $size - 8, 8, 1);
    }

    /**
     * 四个标准的罚分规则，分数越低越好扫。
     *
     * @param array<int, array<int, int>> $m
     */
    private static function penalty(array $m, int $size): int
    {
        $score = 0;

        // 规则 1：同色连续 5 个以上
        for ($r = 0; $r < $size; $r++) {
            $run = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($m[$r][$c] === $m[$r][$c - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
        }
        for ($c = 0; $c < $size; $c++) {
            $run = 1;
            for ($r = 1; $r < $size; $r++) {
                if ($m[$r][$c] === $m[$r - 1][$c]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
        }

        // 规则 2：2x2 同色块
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = $m[$r][$c];
                if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) {
                    $score += 3;
                }
            }
        }

        // 规则 3：类似定位图案的 1:1:3:1:1 组合
        $p1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $p2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c <= $size - 11; $c++) {
                $a = true;
                $b = true;
                for ($i = 0; $i < 11; $i++) {
                    if ($m[$r][$c + $i] !== $p1[$i]) {
                        $a = false;
                    }
                    if ($m[$r][$c + $i] !== $p2[$i]) {
                        $b = false;
                    }
                }
                if ($a) {
                    $score += 40;
                }
                if ($b) {
                    $score += 40;
                }
            }
        }
        for ($c = 0; $c < $size; $c++) {
            for ($r = 0; $r <= $size - 11; $r++) {
                $a = true;
                $b = true;
                for ($i = 0; $i < 11; $i++) {
                    if ($m[$r + $i][$c] !== $p1[$i]) {
                        $a = false;
                    }
                    if ($m[$r + $i][$c] !== $p2[$i]) {
                        $b = false;
                    }
                }
                if ($a) {
                    $score += 40;
                }
                if ($b) {
                    $score += 40;
                }
            }
        }

        // 规则 4：深色比例偏离 50% 每 5% 加 10 分
        $dark = 0;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($m[$r][$c] === 1) {
                    $dark++;
                }
            }
        }
        $total = $size * $size;
        $percent = $dark * 100 / $total;
        $k = (int) (abs($percent - 50) / 5);
        $score += $k * 10;

        return $score;
    }
}
