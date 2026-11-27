<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Http\Json;
use PDO;

/**
 * 发现：一个「推荐好友」列表 + 按 uid 精确找人。
 *
 * 推荐逻辑是自己的，不照搬任何现成产品：
 *   同群      5 分（基础，保证列表稳定）
 *   年龄差    越接近越高，最多 40 分
 *   同城      +15 分（住址前两级市名相同）
 *   标签      共同标签每个 12 分，最多 36 分
 *   性别      +10 / +4（不同 / 相同）
 *   签名      中文二字词交集，每个 3 分，最多 12 分
 *
 * 搜索只认 uid：用户名、昵称、邮箱都能被拿去撞库对号，不提供按它们搜索。
 * 只看同作用域、未注销、未封禁、自己没关掉「出现在发现里」的人。
 */
final class Discover {
    private const LIMIT = 30;

    public static function handle(PDO $pdo, array $scope): void {
        $me = Compat::requireMemberPublic($pdo);
        if (!Features::social($pdo, "discover")) Json::fail("本站没有开放「发现」", 403);

        $q = trim((string) Input::get("q"));
        if ($q !== "") {
            self::search($pdo, $scope, $me, $q);
            return;
        }

        $st = $pdo->prepare(
            "SELECT id, uid, name, username, kind, avatar, bio, bio_mode, tags, birthday, gender, signature, address, discover, last_seen_at"
            . " FROM members WHERE scope_id = ? AND kind = 'human' AND id <> ?"
            . " AND (deleted_at IS NULL OR deleted_at = 0) AND banned = 0 AND discover = 1"
            . " ORDER BY last_seen_at DESC LIMIT 300"
        );
        $st->execute([(int) $scope["id"], (int) $me["id"]]);
        $rows = $st->fetchAll();

        $myTags = self::tagsOf($me);
        $myAge  = self::ageOf((string) ($me["birthday"] ?? ""));
        $mySex  = strtolower((string) ($me["gender"] ?? ""));
        $mySig  = self::bigrams((string) ($me["signature"] ?? ""));
        $myCity = self::cityOf((string) ($me["address"] ?? ""));

        $items = [];
        foreach ($rows as $r) {
            $tags = self::tagsOf($r);
            $age  = self::ageOf((string) ($r["birthday"] ?? ""));
            $sex  = strtolower((string) ($r["gender"] ?? ""));
            $city = self::cityOf((string) ($r["address"] ?? ""));
            $score = 5;
            $why = ["同一群"];

            if ($myAge > 0 && $age > 0) {
                $diff = abs($myAge - $age);
                $score += max(0, 40 - $diff * 4);
                if ($diff <= 3) $why[] = "年龄相近";
            }
            if ($myCity !== "" && $city !== "" && $myCity === $city) {
                $score += 15;
                $why[] = "同城";
            }
            $same = array_values(array_intersect($myTags, $tags));
            if ($same) {
                $score += min(36, count($same) * 12);
                $why[] = "同样喜欢 " . implode("、", array_slice($same, 0, 3));
            }
            if ($mySex !== "" && $sex !== "") {
                if ($mySex !== $sex) { $score += 10; }
                else { $score += 4; $why[] = "性别相同"; }
            }
            $sigHit = array_values(array_intersect($mySig, self::bigrams((string) ($r["signature"] ?? ""))));
            if ($sigHit) {
                $score += min(12, count($sigHit) * 3);
                $why[] = "签名里有共同的字眼";
            }

            $it = self::brief($r);
            $it["score"] = $score;
            $it["why"]   = array_values(array_unique($why));
            $items[] = $it;
        }
        usort($items, function ($a, $b) {
            if ($a["score"] !== $b["score"]) return $b["score"] <=> $a["score"];
            return $b["last_seen"] <=> $a["last_seen"];
        });
        $items = array_slice($items, 0, self::LIMIT);

        Json::ok([
            "mode"  => "match",
            "items" => $items,
            // 自己的资料越全，推荐越准：前端据此提示去补资料
            "mine"  => [
                "age"       => $myAge,
                "gender"    => $mySex,
                "tags"      => $myTags,
                "signature" => (string) ($me["signature"] ?? ""),
                "city"      => $myCity,
                "complete"  => ($myAge > 0 ? 1 : 0) + (count($myTags) > 0 ? 1 : 0) + ($mySex !== "" ? 1 : 0) + (trim((string) ($me["signature"] ?? "")) !== "" ? 1 : 0) + ($myCity !== "" ? 1 : 0),
            ],
        ]);
    }

    /** 只能按 uid 找人：只出精确命中的那一个人 */
    private static function search(PDO $pdo, array $scope, array $me, string $q): void {
        if (mb_strlen($q) > 40) Json::fail("搜索词太长");
        $uid = strtolower(ltrim($q, "@"));
        if (!preg_match('/^[a-z0-9]{4,32}$/', $uid)) {
            Json::ok([
                "mode"  => "search",
                "q"     => $q,
                "items" => [],
                "total" => 0,
                "hint"  => "只能按对方的 uid 找人",
            ]);
            return;
        }
        $st = $pdo->prepare(
            "SELECT id, uid, name, username, kind, avatar, bio, bio_mode, tags, birthday, gender, signature, address, discover, last_seen_at"
            . " FROM members WHERE scope_id = ? AND kind = 'human' AND id <> ?"
            . " AND (deleted_at IS NULL OR deleted_at = 0) AND banned = 0 AND uid = ? LIMIT 1"
        );
        $st->execute([(int) $scope["id"], (int) $me["id"], $uid]);
        $row = $st->fetch();

        $items = [];
        if ($row) {
            $it = self::brief($row);
            $it["score"] = 0;
            $it["why"] = ["搜索命中"];
            $items[] = $it;
        }
        Json::ok([
            "mode"  => "search",
            "q"     => $q,
            "items" => $items,
            "total" => count($items),
            "hint"  => $row ? "" : "没找到这个 uid 的人",
        ]);
    }

    /** 列表里用的精简资料（推荐与搜索共用） */
    private static function brief(array $r): array {
        return [
            "id"        => Ids::of($r),
            "uid"       => Ids::of($r),
            "name"      => (string) $r["name"],
            "avatar"    => (string) ($r["avatar"] ?? ""),
            "signature" => (string) ($r["signature"] ?? ""),
            "tags"      => self::tagsOf($r),
            "age"       => self::ageOf((string) ($r["birthday"] ?? "")),
            "gender"    => strtolower((string) ($r["gender"] ?? "")),
            "city"      => self::cityOf((string) ($r["address"] ?? "")),
            "bio"       => mb_substr((string) ($r["bio"] ?? ""), 0, 60),
            "last_seen" => (int) ($r["last_seen_at"] ?? 0),
        ];
    }

    /** 住址取到市：广东省深圳市南山区 → 深圳市；北京市朝阳区 → 北京市 */
    private static function cityOf(string $addr): string {
        $addr = trim($addr);
        if ($addr === "") return "";
        if (preg_match('/([^省市]{2,10}市)/u', $addr, $m)) return $m[1];
        if (preg_match('/([^省市]{2,10}(?:州|盟|地区))/u', $addr, $m)) return $m[1];
        return mb_substr($addr, 0, 3);
    }

    private static function tagsOf(array $m): array {
        $raw = (string) ($m["tags"] ?? "");
        if ($raw === "") return [];
        $row = json_decode($raw, true);
        if (!is_array($row)) return [];
        $out = [];
        foreach ($row as $t) {
            if (is_scalar($t) && trim((string) $t) !== "") $out[] = (string) $t;
        }
        return array_slice($out, 0, 5);
    }

    private static function ageOf(string $birthday): int {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthday, $m)) return 0;
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
        if (!checkdate($mo, $d, $y)) return 0;
        $ty = (int) date("Y"); $tm = (int) date("n"); $td = (int) date("j");
        $age = $ty - $y;
        if ($tm < $mo || ($tm === $mo && $td < $d)) $age--;
        return $age >= 0 && $age <= 150 ? $age : 0;
    }

    /** 中文按二字词切，英文数字按词切；只用来算签名相似度 */
    private static function bigrams(string $s): array {
        $s = preg_replace('/[^\p{Han}\p{L}\p{N}]+/u', ' ', $s);
        $out = [];
        foreach (explode(' ', (string) $s) as $w) {
            $len = mb_strlen($w);
            if ($len < 2) continue;
            for ($i = 0; $i < $len - 1; $i++) $out[mb_substr($w, $i, 2)] = true;
        }
        return array_keys($out);
    }
}
