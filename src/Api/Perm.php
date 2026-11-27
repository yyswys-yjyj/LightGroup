<?php
declare(strict_types=1);

namespace LightGroup\Api;

use PDO;

/**
 * 权限组：控制「一个群」能用哪些群功能（群公告、邀请、设管理员……）。
 *
 * 注意分层：注册、AI 账号、适配器、空间总开关这些是站点/作用域设置（见 Features），
 * 不在这里。权限组只回答「这个群的群功能开没开」。
 *
 * 解析顺序（后者覆盖前者）：
 *   1. 内置默认值（群功能默认全开）
 *   2. 平台默认权限组（perm_groups.scope_id = 0, is_default = 1）
 *   3. 作用域自己套的权限组（scopes.perm_group_id）
 *
 * perms 存 JSON 对象，只存「被改过的键」。以后新增键时旧组不用迁移。
 */
final class Perm {
    /**
     * key => [类型, 默认值, 中文名]
     */
    public const KEYS = [
        "group.notice"      => ["bool", true, "群公告"],
        "group.invite"      => ["bool", true, "邀请成员进群"],
        "group.admin"       => ["bool", true, "管理成员与设置管理员"],
        "group.rename"      => ["bool", true, "修改群名与群资料"],
        "group.todo"        => ["bool", true, "群待办"],
        "group.pin"         => ["bool", true, "置顶消息"],
        "group.member_list" => ["bool", true, "查看群成员列表"],
        "group.qr"          => ["bool", true, "群二维码"],
    ];

    private static array $scopeCache = [];
    private static array $groupCache = [];
    private static ?int $defaultId = null;

    public static function defaults(): array {
        $out = [];
        foreach (self::KEYS as $k => $meta) $out[$k] = $meta[1];
        return $out;
    }

    /** 后台 UI 用的元信息 */
    public static function meta(): array {
        $out = [];
        foreach (self::KEYS as $k => $m) {
            $out[] = ["key" => $k, "type" => $m[0], "default" => $m[1], "label" => $m[2], "values" => []];
        }
        return $out;
    }

    /** 平台默认组：不存在就建一条（scope_id=0, is_default=1） */
    public static function defaultGroupId(PDO $pdo): int {
        if (self::$defaultId !== null) return self::$defaultId;
        $st = $pdo->query("SELECT id FROM perm_groups WHERE is_default = 1 LIMIT 1");
        $row = $st ? $st->fetch() : false;
        if ($row) return self::$defaultId = (int) $row["id"];
        $now = (int) round(microtime(true) * 1000);
        try {
            $st = $pdo->prepare("INSERT INTO perm_groups (scope_id, name, perms, is_default, created_at, updated_at) VALUES (0, ?, '{}', 1, ?, ?)");
            $st->execute(["默认权限组", $now, $now]);
        } catch (\Throwable $e) {
            // 并发下可能已经有人插了
        }
        $st = $pdo->query("SELECT id FROM perm_groups WHERE is_default = 1 LIMIT 1");
        $row = $st ? $st->fetch() : false;
        return self::$defaultId = $row ? (int) $row["id"] : 0;
    }

    /** 作用域套哪个组：没单独指定就用平台默认组 */
    public static function scopeGroupId(PDO $pdo, int $scopeId): int {
        if ($scopeId > 0) {
            $st = $pdo->prepare("SELECT perm_group_id FROM scopes WHERE id = ? LIMIT 1");
            $st->execute([$scopeId]);
            $row = $st->fetch();
            if ($row && (int) ($row["perm_group_id"] ?? 0) > 0) return (int) $row["perm_group_id"];
        }
        return self::defaultGroupId($pdo);
    }

    /** 组的覆盖项（已过滤未知键、校验类型的 JSON 对象） */
    public static function groupPerms(PDO $pdo, int $id): array {
        if ($id <= 0) return [];
        if (array_key_exists($id, self::$groupCache)) return self::$groupCache[$id];
        $st = $pdo->prepare("SELECT perms FROM perm_groups WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        return self::$groupCache[$id] = $row ? self::decode((string) ($row["perms"] ?? "")) : [];
    }

    /** 某个群最终生效的群功能开关 */
    public static function forScope(PDO $pdo, int $scopeId): array {
        if (isset(self::$scopeCache[$scopeId])) return self::$scopeCache[$scopeId];
        $out = self::defaults();
        self::apply($out, self::groupPerms($pdo, self::defaultGroupId($pdo)));
        if ($scopeId > 0) self::apply($out, self::groupPerms($pdo, self::scopeGroupId($pdo, $scopeId)));
        return self::$scopeCache[$scopeId] = $out;
    }

    /** 兼容旧调用：作用域级解析 */
    public static function scopeResolve(PDO $pdo, int $scopeId): array {
        return self::forScope($pdo, $scopeId);
    }

    /** 给前端的精简群功能表 */
    public static function forClient(PDO $pdo, int $scopeId): array {
        $all = self::forScope($pdo, $scopeId);
        return [
            "notice"     => !empty($all["group.notice"]),
            "invite"     => !empty($all["group.invite"]),
            "admin"      => !empty($all["group.admin"]),
            "rename"     => !empty($all["group.rename"]),
            "todo"       => !empty($all["group.todo"]),
            "pin"        => !empty($all["group.pin"]),
            "memberList" => !empty($all["group.member_list"]),
            "qr"         => !empty($all["group.qr"]),
        ];
    }

    public static function can(PDO $pdo, int $scopeId, string $key): bool {
        $all = self::forScope($pdo, $scopeId);
        if (!array_key_exists($key, $all)) return true;
        return (bool) $all[$key];
    }

    // ---- 后台管理 ----

    /** 组列表：平台默认组 + 指定作用域的组 */
    public static function groups(PDO $pdo, int $scopeId, bool $isSuper): array {
        $default = self::defaultGroupId($pdo);
        $st = $pdo->prepare("SELECT * FROM perm_groups WHERE id = ? OR scope_id = ? ORDER BY is_default DESC, id ASC");
        $st->execute([$default, $scopeId]);
        $rows = $st->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $isDef = (int) $r["is_default"] === 1;
            // 管理员不能改平台默认组，但可以看见它（本群没指定组时就是它在生效）
            $out[] = [
                "id" => (int) $r["id"],
                "scope_id" => (int) $r["scope_id"],
                "name" => (string) $r["name"],
                "is_default" => $isDef,
                "editable" => $isSuper || !$isDef,
                "perms" => self::decode((string) ($r["perms"] ?? "")),
                "created_at" => (int) $r["created_at"],
                "updated_at" => (int) $r["updated_at"],
            ];
        }
        return $out;
    }

    public static function save(PDO $pdo, int $id, int $scopeId, string $name, array $perms, bool $isSuper): int {
        $name = trim($name);
        if ($name === "") Json::fail("权限组要有名字");
        if (mb_strlen($name) > 40) Json::fail("权限组名字最多 40 个字");
        $clean = self::encode($perms);
        $now = (int) round(microtime(true) * 1000);
        if ($id > 0) {
            $st = $pdo->prepare("SELECT * FROM perm_groups WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) Json::fail("权限组不存在");
            if ((int) $row["is_default"] === 1 && !$isSuper) Json::fail("默认权限组只有超级管理员能改");
            if (!$isSuper && (int) $row["scope_id"] !== $scopeId) Json::fail("这个权限组不属于本群");
            $pdo->prepare("UPDATE perm_groups SET name = ?, perms = ?, updated_at = ? WHERE id = ?")
                ->execute([$name, $clean, $now, $id]);
            self::$groupCache = [];
            self::$scopeCache = [];
            return $id;
        }
        if ($scopeId <= 0 && !$isSuper) Json::fail("只有超级管理员能建平台级权限组");
        $st = $pdo->prepare("INSERT INTO perm_groups (scope_id, name, perms, is_default, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)");
        $st->execute([$scopeId, $name, $clean, $now, $now]);
        self::$groupCache = [];
        self::$scopeCache = [];
        return (int) $pdo->lastInsertId();
    }

    public static function remove(PDO $pdo, int $id, int $scopeId, bool $isSuper): void {
        $st = $pdo->prepare("SELECT * FROM perm_groups WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) Json::fail("权限组不存在");
        if ((int) $row["is_default"] === 1) Json::fail("默认权限组不能删");
        if (!$isSuper && (int) $row["scope_id"] !== $scopeId) Json::fail("这个权限组不属于本群");
        $st = $pdo->prepare("SELECT COUNT(*) FROM scopes WHERE perm_group_id = ?");
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) Json::fail("还有群在用这个权限组，先换掉");
        $pdo->prepare("DELETE FROM perm_groups WHERE id = ?")->execute([$id]);
        self::$groupCache = [];
        self::$scopeCache = [];
        self::$defaultId = null;
    }

    /** 把作用域指到某个组（$groupId=0 表示回到默认） */
    public static function assignScope(PDO $pdo, int $scopeId, int $groupId): void {
        if ($groupId > 0) self::requireGroup($pdo, $groupId);
        $pdo->prepare("UPDATE scopes SET perm_group_id = ? WHERE id = ?")->execute([$groupId > 0 ? $groupId : null, $scopeId]);
        self::$scopeCache = [];
    }

    // ---- 内部 ----

    private static function requireGroup(PDO $pdo, int $id): array {
        $st = $pdo->prepare("SELECT * FROM perm_groups WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) Json::fail("权限组不存在");
        return $row;
    }

    /** 只接受已知键与合法值，别的丢掉；只存与默认不同的键 */
    private static function encode(array $in): string {
        $out = [];
        foreach (self::KEYS as $k => $meta) {
            if (!array_key_exists($k, $in)) continue;
            $v = $in[$k];
            if ($meta[0] === "bool") {
                if (is_bool($v)) $b = $v;
                elseif (is_int($v) || is_float($v)) $b = (int) $v !== 0;
                elseif (is_string($v)) $b = in_array(strtolower(trim($v)), ["1", "true", "on", "yes"], true);
                else continue;
                if ($b !== (bool) $meta[1]) $out[$k] = $b ? 1 : 0;
            }
        }
        return json_encode((object) $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: "{}";
    }

    private static function decode(string $json): array {
        if (trim($json) === "") return [];
        $row = json_decode($json, true);
        if (!is_array($row)) return [];
        $out = [];
        foreach (self::KEYS as $k => $meta) {
            if (!array_key_exists($k, $row)) continue;
            $v = $row[$k];
            if ($meta[0] === "bool") {
                $out[$k] = is_string($v) ? in_array(strtolower($v), ["1", "true", "on", "yes"], true) : (bool) $v;
            }
        }
        return $out;
    }

    private static function apply(array &$out, array $over): void {
        foreach ($over as $k => $v) {
            if (array_key_exists($k, $out)) $out[$k] = $v;
        }
    }
}
