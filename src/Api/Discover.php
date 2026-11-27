<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Http\Json;
use PDO;

/**
 * 发现：按资料相似度给同群的人排个序（只排人类成员，AI 不参与）。
 *
 * 打分（从高到低）：
 *   年龄差  —— 越接近越高，最多 40 分
 *   标签    —— 共同标签每个 12 分，最多 36 分
 *   性别    —— 不同 10 分、相同 4 分
 *   签名    —— 中文二字词交集，每个 3 分，最多 12 分
 * 同群基础分 5 分，保证人人有分、列表稳定。
 *
 * 只看同作用域、未注销、未封禁、自己没关掉「出现在发现里」的人。
 */
final class Discover {
    private const LIMIT = 30;

    public static function handle(PDO $pdo, array $scope): void {
        $me = Compat::requireMemberPublic($pdo);
        if (!Features::social($pdo, "discover")) Json::fail("本站没有开放「发现」", 403);

        $st = $pdo->prepare(
            "SELECT id, name, username, kind, avatar, bio, bio_mode, tags, birthday, gender, signature, discover, last_seen_at"
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

        $items = [];
        foreach ($rows as $r) {
            $tags = self::tagsOf($r);
            $age  = self::ageOf((string) ($r["birthday"] ?? ""));
            $sex  = strtolower((string) ($r["gender"] ?? ""));
            $score = 5;
            $why = ["同一群"];

            if ($myAge > 0 && $age > 0) {
                $diff = abs($myAge - $age);
                $score += max(0, 40 - $diff * 4);
                if ($diff <= 3) $why[] = "年龄相近";
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

            $items[] = [
                "id"        => (int) $r["id"],
                "uid"       => "h_" . (int) $r["id"],
                "name"      => (string) $r["name"],
                "username"  => (string) ($r["username"] ?? $r["name"]),
                "avatar"    => (string) ($r["avatar"] ?? ""),
                "signature" => (string) ($r["signature"] ?? ""),
                "tags"      => $tags,
                "age"       => $age,
                "gender"    => $sex,
                "bio"       => mb_substr((string) ($r["bio"] ?? ""), 0, 60),
                "score"     => $score,
                "why"       => array_values(array_unique($why)),
                "last_seen" => (int) ($r["last_seen_at"] ?? 0),
            ];
        }
        usort($items, function ($a, $b) {
            if ($a["score"] !== $b["score"]) return $b["score"] <=> $a["score"];
            return $b["last_seen"] <=> $a["last_seen"];
        });
        $items = array_slice($items, 0, self::LIMIT);

        Json::ok([
            "items" => $items,
            // 自己的资料越全，匹配越准：前端据此提示去补资料
            "mine"  => [
                "age"       => $myAge,
                "gender"    => $mySex,
                "tags"      => $myTags,
                "signature" => (string) ($me["signature"] ?? ""),
                "complete"  => ($myAge > 0 ? 1 : 0) + (count($myTags) > 0 ? 1 : 0) + ($mySex !== "" ? 1 : 0) + (trim((string) ($me["signature"] ?? "")) !== "" ? 1 : 0),
            ],
        ]);
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
