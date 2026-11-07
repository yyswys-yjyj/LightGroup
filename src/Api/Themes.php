<?php
declare(strict_types=1);

namespace LightGroup\Api;

/**
 * 内建纯色主题的 id 与显示名。色值在前端 assets/js/core.js 的 THEMES 里，
 * 这里只提供后台下拉需要的名字，两边 id 必须一致。
 */
final class Themes
{
    public const NAME = [
        'dadi' => '大地红',
        'zhuanhong' => '砖红',
        'shuguang' => '曙光',
        'daoxiang' => '稻香橙',
        'nuanyangcheng' => '暖阳橙',
        'kafei' => '咖啡',
        'naicha' => '奶茶',
        'xingse' => '杏色',
        'shechijin' => '奢侈金',
        'luori' => '落日黄',
        'dianjihuang' => '典籍黄',
        'mocha' => '抹茶',
        'xiaocao' => '小草绿',
        'songlv' => '松绿',
        'danbohe' => '淡薄荷',
        'qingbi' => '青碧',
        'yunqing' => '云青青',
        'minghuanqing' => '明焕青',
        'dailan' => '呆蓝',
        'tianqing' => '天青',
        'wumailan' => '雾霾蓝',
        'hulan' => '湖蓝',
        'xuanfenglan' => '旋风蓝',
        'menghuanlan' => '梦幻蓝',
        'beijixing' => '北极星',
        'shenkongming' => '深空冥',
        'ziluolan' => '紫罗兰',
        'dingxiangzi' => '丁香紫',
        'huopo' => '活泼',
        'mengdong' => '萌动粉',
        'daifen' => '呆粉',
        'caixiafen' => '彩霞粉',
        'yinghuafen' => '樱花',
        'mitao' => '蜜桃',
        'naihuang' => '奶黄',
        'bohenai' => '薄荷奶',
        'yunduolan' => '云朵蓝',
        'yuni' => '芋泥',
        'oufen' => '藕粉',
        'tongtou' => '通透白',
        'mibai' => '米白',
        'yuebai' => '月白',
        'shiban' => '石板灰',
        'yanhui' => '岩灰',
        'menglonghui' => '朦胧灰',
        'didiao' => '低调黑',
        'gaoyahei' => '高雅黑',
        'wuye' => '午夜',
    ];

    /** 深浅模式：auto 跟随系统（系统深色 / 浅色），light / dark 是用户手选 */
    public const MODE = [
        'auto' => '跟随系统',
        'light' => '浅色',
        'dark' => '深色',
    ];

    /** @return list<array{id:string,name:string}> */
    public static function modes(): array
    {
        $out = [];
        foreach (self::MODE as $id => $name) {
            $out[] = ['id' => $id, 'name' => $name];
        }
        return $out;
    }

    /** 规整成三选一，未知值一律跟随系统 */
    public static function mode(?string $mode): string
    {
        $m = strtolower(trim((string) $mode));
        return isset(self::MODE[$m]) ? $m : 'auto';
    }

    /**
     * 把「模式 + 强调色」编码成 members.theme 里存的那个串（与前端 encodeThemeChoice 同一套规则）：
     * auto / light / dark / dark-hulan / light-p_3。
     */
    public static function encode(?string $mode, ?string $accent): string
    {
        $m = self::mode($mode);
        $a = trim((string) $accent);
        return $a === '' ? $m : $m . '-' . $a;
    }

    /**
     * 解出模式与强调色；老数据只存了强调色时 mode 为空串（前端据此做一次性迁移）。
     *
     * @return array{mode:string,accent:string}
     */
    public static function decode(?string $value): array
    {
        $s = trim((string) $value);
        if ($s === '') {
            return ['mode' => '', 'accent' => ''];
        }
        if (isset(self::MODE[$s])) {
            return ['mode' => $s, 'accent' => ''];
        }
        $p = strpos($s, '-');
        if ($p !== false) {
            $head = substr($s, 0, $p);
            if (isset(self::MODE[$head])) {
                return ['mode' => $head, 'accent' => substr($s, $p + 1)];
            }
        }
        return ['mode' => '', 'accent' => $s];
    }

    /** @return list<array{id:string,name:string}> */
    public static function builtin(): array
    {
        $out = [];
        foreach (self::NAME as $id => $name) {
            $out[] = ["id" => $id, "name" => $name];
        }
        return $out;
    }
}
