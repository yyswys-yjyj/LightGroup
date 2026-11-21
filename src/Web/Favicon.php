<?php
declare(strict_types=1);

namespace LightGroup\Web;

/**
 * 浏览器标签页图标（public/favicon.ico）。
 *
 * 标签页图标得跟着站点图标走：装站时写一次，站点设置里换了图标还要再写一次。
 * 只装站时写、之后不管，浏览器就会一直显示上一份（仓库自带的默认稿），
 * 用户看到的现象就是「站点图标换了，标签页还是老图标」。
 */
final class Favicon
{
    /** ico 里打包的尺寸，浏览器按显示场景自己挑 */
    private const SIZES = [16, 32, 48, 64, 128, 256];

    /**
     * 按站点图标重建 public/favicon.ico。
     * 浅色稿优先，没有就用深色稿，再没有就画默认双圆。
     * 参数是相对 public 的路径，例如 /assets/icon/light-20261112-f27803fd.png。
     */
    public static function rebuild(?string $lightRel, ?string $darkRel = null): bool
    {
        $src = null;
        foreach ([$lightRel, $darkRel] as $rel) {
            $rel = (string) $rel;
            if ($rel === '') continue;
            $file = PUBLIC_ROOT . $rel;
            if (!is_file($file)) continue;
            $img = @imagecreatefromstring((string) @file_get_contents($file));
            if ($img) {
                $src = $img;
                break;
            }
        }
        if (!$src) $src = self::defaultIcon(512);

        $w = imagesx($src);
        $h = imagesy($src);
        $pngs = [];
        foreach (self::SIZES as $s) {
            $d = imagecreatetruecolor($s, $s);
            imagealphablending($d, false);
            imagesavealpha($d, true);
            $t = imagecolorallocatealpha($d, 0, 0, 0, 127);
            imagefilledrectangle($d, 0, 0, $s, $s, $t);
            imagealphablending($d, true);
            imagecopyresampled($d, $src, 0, 0, 0, 0, $s, $s, $w, $h);
            ob_start();
            imagepng($d);
            $pngs[$s] = (string) ob_get_clean();
        }

        $n = count($pngs);
        $offset = 6 + 16 * $n;
        $dir = pack('vvv', 0, 1, $n);
        $entries = '';
        $blob = '';
        foreach ($pngs as $size => $png) {
            // 256 的尺寸在 ico 头里写 0（一个字节放不下 256）
            $dim = $size >= 256 ? 0 : $size;
            $entries .= pack('CCCCvvVV', $dim, $dim, 0, 0, 1, 32, strlen($png), $offset);
            $blob .= $png;
            $offset += strlen($png);
        }
        return @file_put_contents(PUBLIC_ROOT . '/favicon.ico', $dir . $entries . $blob) !== false;
    }

    /**
     * 默认图标：蓝色圆角方块 + 两道白色细圆环，和 assets/favicon.svg 同一套画法。
     * 环的半径/线宽按 svg 的 64 格坐标系换算（外圆 r=15、内圆 r=6、描边 4.2），
     * 用「白色实心圆叠蓝色内孔」的方式画环，比 imagesetthickness 的描边干净。
     */
    public static function defaultIcon(int $size = 512)
    {
        $img = imagecreatetruecolor($size, $size);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $transparent);
        imagealphablending($img, true);
        $blue = imagecolorallocate($img, 37, 99, 235);
        $white = imagecolorallocate($img, 255, 255, 255);
        $r = (int) round($size * 0.22);
        // 圆角方块：两条十字矩形铺满直边，四个角再各补一个半径 r 的圆
        imagefilledrectangle($img, $r, 0, $size - 1 - $r, $size - 1, $blue);
        imagefilledrectangle($img, 0, $r, $size - 1, $size - 1 - $r, $blue);
        imagefilledellipse($img, $r, $r, $r * 2, $r * 2, $blue);
        imagefilledellipse($img, $size - 1 - $r, $r, $r * 2, $r * 2, $blue);
        imagefilledellipse($img, $r, $size - 1 - $r, $r * 2, $r * 2, $blue);
        imagefilledellipse($img, $size - 1 - $r, $size - 1 - $r, $r * 2, $r * 2, $blue);
        $c = (int) round($size / 2);
        foreach ([[15.0, 4.2], [6.0, 4.2]] as $ring) {
            $rad = $ring[0] / 64 * $size;
            $half = $ring[1] / 64 * $size / 2;
            $outerD = max(2, (int) round(($rad + $half) * 2));
            $innerD = (int) round(($rad - $half) * 2);
            imagefilledellipse($img, $c, $c, $outerD, $outerD, $white);
            if ($innerD > 2) {
                imagefilledellipse($img, $c, $c, $innerD, $innerD, $blue);
            }
        }
        return $img;
    }
}
