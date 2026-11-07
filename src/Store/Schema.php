<?php
declare(strict_types=1);

namespace LightGroup\Store;

use PDO;

final class Schema {
    /** 结构版本：每次加列/加表都要 +1，老库会在首次请求时补齐 */
    public const VERSION = 8;

    public static function install(PDO $pdo, string $driver): void {
        foreach (self::statements($driver) as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\Throwable $e) {
                // 老库的表已存在但缺列时，索引会先失败；交给 migrate() 补列后再建
            }
        }
    }

    /**
     * 老库升级：补齐后来新增的列与表。install() 已经把新列写进建表语句，
     * 这里只处理「库里已经有旧表」的情况，可以反复执行。
     */
    public static function migrate(PDO $pdo, string $driver): void {
        $mysql = $driver === "mysql";
        $wanted = [
            "scopes"   => [
                "theme"         => "VARCHAR(64) NULL",
                "recall_policy" => "VARCHAR(16) NOT NULL DEFAULT '5m'",
                "notice"        => $mysql ? "LONGTEXT NULL" : "TEXT NULL",
                "notice_at"     => "BIGINT NOT NULL DEFAULT 0",
                "notice_by"     => "BIGINT NULL",
            ],
            "members"  => [
                "theme"        => "VARCHAR(64) NULL",
                "bio"          => $mysql ? "LONGTEXT NULL" : "TEXT NULL",
                "styles_css"   => $mysql ? "LONGTEXT NULL" : "TEXT NULL",
                "wall"         => $mysql ? "LONGTEXT NULL" : "TEXT NULL",
                "last_read_id" => "BIGINT NOT NULL DEFAULT 0",
                "pass_hash"    => "VARCHAR(255) NULL",
                // 注销时把原名搬到这里，名字本身改成「已注销」，昵称才能重新注册
                "deleted_name" => $mysql ? "VARCHAR(191) NULL" : "TEXT NULL",
            ],
            "messages" => [
                "reply_to"   => "BIGINT NULL",
                "deleted_at" => "BIGINT NULL",
                "deleted_by" => "BIGINT NULL",
                "voice"      => $mysql ? "LONGTEXT NULL" : "TEXT NULL",
                "dur"        => "INT NOT NULL DEFAULT 0",
                "pinned_at"  => "BIGINT NULL",
                "pinned_by"  => "BIGINT NULL",
                "conv_id"    => "BIGINT NULL",
                "sys"        => "TINYINT NOT NULL DEFAULT 0",
            ],
        ];
        foreach ($wanted as $table => $cols) {
            foreach ($cols as $col => $ddl) {
                if (self::hasColumn($pdo, $driver, $table, $col)) continue;
                try {
                    $pdo->exec("ALTER TABLE " . $table . " ADD COLUMN " . $col . " " . $ddl);
                } catch (\Throwable $e) {
                    // 并发升级或权限不足时忽略，下次请求继续尝试
                }
            }
        }
        // 新表与索引：补完列之后再跑一遍，CREATE 都是 IF NOT EXISTS，可反复执行
        foreach (self::statements($driver) as $sql) {
            $isIdx = stripos($sql, "CREATE INDEX") !== false || stripos($sql, "CREATE UNIQUE INDEX") !== false;
            if (!self::isNewTable($sql) && !$isIdx) continue;
            try {
                $pdo->exec($sql);
            } catch (\Throwable $e) {
            }
        }
        self::ensureConversations($pdo);
    }

    /**
     * IM 化：每个作用域补一个公开大群，把没有 conv_id 的历史消息归进去，
     * 再回填会话的最后一条。可以反复执行。
     */
    private static function ensureConversations(PDO $pdo): void {
        try {
            $pdo->exec(
                "INSERT INTO conversations (scope_id, kind, title, is_public, dm_key, created_at, last_msg_id, last_msg_at)"
                . " SELECT s.id, 'group', s.title, 1, 'public', s.created_at, 0, 0 FROM scopes s"
                . " WHERE NOT EXISTS (SELECT 1 FROM conversations c WHERE c.scope_id = s.id AND c.dm_key = 'public')"
            );
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec(
                "UPDATE messages SET conv_id = ("
                . " SELECT c.id FROM conversations c WHERE c.scope_id = messages.scope_id AND c.is_public = 1 LIMIT 1"
                . ") WHERE conv_id IS NULL"
            );
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec(
                "UPDATE conversations SET"
                . " last_msg_id = COALESCE((SELECT MAX(m.id) FROM messages m WHERE m.conv_id = conversations.id), 0),"
                . " last_msg_at = COALESCE((SELECT MAX(m.ts_ms) FROM messages m WHERE m.conv_id = conversations.id), 0)"
                . " WHERE is_public = 1"
            );
        } catch (\Throwable $e) {
        }
    }

    /** migrate() 只补建后来新增的表，避免把全部语句再跑一遍 */
    private static function isNewTable(string $sql): bool {
        foreach (["api_keys", "todos", "todo_done", "conversations", "conv_members", "friends", "theme_packs", "login_fails"] as $t) {
            if (stripos($sql, $t) !== false) return true;
        }
        return false;
    }

    private static function hasColumn(PDO $pdo, string $driver, string $table, string $col): bool {
        try {
            if ($driver === "mysql") {
                $st = $pdo->query("SHOW COLUMNS FROM " . $table);
                foreach ($st->fetchAll() as $row) {
                    if (($row["Field"] ?? "") === $col) return true;
                }
                return false;
            }
            $st = $pdo->query("PRAGMA table_info(" . $table . ")");
            foreach ($st->fetchAll() as $row) {
                if (($row["name"] ?? "") === $col) return true;
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function statements(string $driver): array {
        $mysql = $driver === "mysql";
        $pk    = $mysql ? "BIGINT PRIMARY KEY AUTO_INCREMENT" : "INTEGER PRIMARY KEY AUTOINCREMENT";
        $text  = $mysql ? "LONGTEXT" : "TEXT";
        $short = $mysql ? "VARCHAR(191)" : "TEXT";
        $slug  = $mysql ? "VARCHAR(64)" : "TEXT";
        $hash  = $mysql ? "CHAR(64)" : "TEXT";
        $suffix = $mysql ? " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" : "";
        $idx    = $mysql ? "" : "IF NOT EXISTS ";

        return [
            "CREATE TABLE IF NOT EXISTS settings (k VARCHAR(128) PRIMARY KEY, v $text)" . $suffix,
            "CREATE TABLE IF NOT EXISTS clusters (id $pk, name $short, created_at BIGINT)$suffix",
            "CREATE TABLE IF NOT EXISTS scopes ("
                . "id $pk, slug $slug NOT NULL DEFAULT '', title $short, intro $text,"
                . " invite $slug, cluster_id BIGINT NULL, human_cap INT NOT NULL DEFAULT 0,"
                . " ai_cap INT NOT NULL DEFAULT 0, archived TINYINT NOT NULL DEFAULT 0,"
                . " recall_policy VARCHAR(16) NOT NULL DEFAULT '5m',"
                . " theme VARCHAR(64) NULL,"
                . " notice $text NULL, notice_at BIGINT NOT NULL DEFAULT 0, notice_by BIGINT NULL,"
                . " created_at BIGINT NOT NULL DEFAULT 0"
                . ")$suffix",
            "CREATE UNIQUE INDEX {$idx}idx_scopes_slug ON scopes(slug)",
            "CREATE UNIQUE INDEX {$idx}idx_scopes_invite ON scopes(invite)",
            "CREATE TABLE IF NOT EXISTS oauth_identities ("
                . "id $pk, provider $slug, sub $short, email $short, display $short,"
                . " created_at BIGINT NOT NULL DEFAULT 0"
                . ")$suffix",
            "CREATE UNIQUE INDEX {$idx}idx_oauth_sub ON oauth_identities(provider, sub)",
            "CREATE TABLE IF NOT EXISTS accounts ("
                . "id $pk, name $short, oauth_id BIGINT NULL, pass_hash VARCHAR(255) NULL,"
                . " role VARCHAR(16) NOT NULL DEFAULT 'member', scope_id BIGINT NULL,"
                . " banned TINYINT NOT NULL DEFAULT 0, deleted_at BIGINT NULL,"
                . " created_at BIGINT NOT NULL DEFAULT 0"
                . ")$suffix",
            "CREATE UNIQUE INDEX {$idx}idx_accounts_name ON accounts(name)",
            "CREATE TABLE IF NOT EXISTS members ("
                . "id $pk, scope_id BIGINT NOT NULL, name $short NOT NULL, kind VARCHAR(8) NOT NULL,"
                . " account_id BIGINT NULL, owner_id BIGINT NULL, oauth_id BIGINT NULL,"
                . " token_hash $hash NULL, avatar $short NULL, bio $text NULL, styles_css $text NULL, wall $text NULL,"
                . " theme VARCHAR(64) NULL, pass_hash VARCHAR(255) NULL,"
                . " last_read_id BIGINT NOT NULL DEFAULT 0, banned TINYINT NOT NULL DEFAULT 0,"
                . " deleted_at BIGINT NULL, deleted_name $short NULL, created_at BIGINT NOT NULL DEFAULT 0,"
                . " last_seen_at BIGINT NULL"
                . ")$suffix",
            "CREATE UNIQUE INDEX {$idx}idx_members_name ON members(scope_id, name)",
            "CREATE UNIQUE INDEX {$idx}idx_members_token ON members(token_hash)",
            "CREATE TABLE IF NOT EXISTS messages ("
                . "id $pk, scope_id BIGINT NOT NULL, member_id BIGINT NOT NULL,"
                . " ts_ms BIGINT NOT NULL, text $text, img $short NULL, card_json $text NULL,"
                . " reply_to BIGINT NULL, deleted_at BIGINT NULL, deleted_by BIGINT NULL,"
                . " voice $text NULL, dur INT NOT NULL DEFAULT 0,"
                . " pinned_at BIGINT NULL, pinned_by BIGINT NULL, conv_id BIGINT NULL,"
                . " sys TINYINT NOT NULL DEFAULT 0"
                . ")$suffix",
            "CREATE INDEX {$idx}idx_msg_scope ON messages(scope_id, id)",
            "CREATE INDEX {$idx}idx_msg_conv ON messages(conv_id, id)",
            // 会话：每个作用域一个公开大群（dm_key='public'），其余是两人私聊（dm_key='小id_大id'）
            "CREATE TABLE IF NOT EXISTS conversations ("
                . "id $pk, scope_id BIGINT NOT NULL, kind VARCHAR(8) NOT NULL, title $short NULL,"
                . " is_public TINYINT NOT NULL DEFAULT 0, dm_key $slug NULL, created_by BIGINT NULL,"
                . " created_at BIGINT NOT NULL DEFAULT 0, last_msg_id BIGINT NOT NULL DEFAULT 0,"
                . " last_msg_at BIGINT NOT NULL DEFAULT 0"
                . ")$suffix",
            "CREATE UNIQUE INDEX {$idx}idx_conv_key ON conversations(scope_id, dm_key)",
            "CREATE INDEX {$idx}idx_conv_scope ON conversations(scope_id, kind)",
            "CREATE TABLE IF NOT EXISTS conv_members ("
                . "conv_id BIGINT NOT NULL, member_id BIGINT NOT NULL,"
                . " last_read_id BIGINT NOT NULL DEFAULT 0, joined_at BIGINT NOT NULL DEFAULT 0,"
                . " PRIMARY KEY (conv_id, member_id)"
                . ")$suffix",
            "CREATE TABLE IF NOT EXISTS friends ("
                . "a_id BIGINT NOT NULL, b_id BIGINT NOT NULL, status VARCHAR(8) NOT NULL,"
                . " requested_by BIGINT NOT NULL, created_at BIGINT NOT NULL DEFAULT 0,"
                . " accepted_at BIGINT NULL, PRIMARY KEY (a_id, b_id)"
                . ")$suffix",
            "CREATE INDEX {$idx}idx_friends_b ON friends(b_id, status)",
            // 登录失败计数：k 是 ip:<地址> 或 user:<scope>:<昵称>，超阈值就锁一段时间
            "CREATE TABLE IF NOT EXISTS login_fails ("
                . "k VARCHAR(191) NOT NULL PRIMARY KEY, n INT NOT NULL DEFAULT 0,"
                . " first_at BIGINT NOT NULL DEFAULT 0, last_at BIGINT NOT NULL DEFAULT 0"
                . ")$suffix",
            "CREATE TABLE IF NOT EXISTS waiters ("
                . "token_hash $hash PRIMARY KEY, expires_at BIGINT NOT NULL"
                . ")$suffix",
            "CREATE TABLE IF NOT EXISTS api_keys ("
                . "id $pk, member_id BIGINT NOT NULL, name $short, key_hash $hash NOT NULL,"
                . " created_at BIGINT NOT NULL DEFAULT 0, last_used_at BIGINT NULL,"
                . " revoked_at BIGINT NULL"
                . ")$suffix",
            "CREATE UNIQUE INDEX {$idx}idx_apikey_hash ON api_keys(key_hash)",
            "CREATE INDEX {$idx}idx_apikey_member ON api_keys(member_id)",
            // 群待办：一条消息被设成待办后，成员各自点「完成」
            "CREATE TABLE IF NOT EXISTS todos ("
                . "id $pk, scope_id BIGINT NOT NULL, msg_id BIGINT NOT NULL,"
                . " created_by BIGINT NULL, created_at BIGINT NOT NULL DEFAULT 0,"
                . " closed_at BIGINT NULL"
                . ")$suffix",
            "CREATE INDEX {$idx}idx_todos_scope ON todos(scope_id, id)",
            // 主题包：scope_id + owner_id 决定归属（owner 空=本域共享，非空=成员私有）
            "CREATE TABLE IF NOT EXISTS theme_packs ("
                . "id $pk, scope_id BIGINT NOT NULL, owner_id BIGINT NULL, name $short NOT NULL,"
                . " dark TINYINT NOT NULL DEFAULT 0, vars $text NOT NULL, created_at BIGINT NOT NULL DEFAULT 0"
                . ")$suffix",
            "CREATE INDEX {$idx}idx_theme_scope ON theme_packs(scope_id, owner_id)",
            // 进群 IP：同一个 IP 10 分钟只放行一个新账号，防脚本批量刷号
            "CREATE TABLE IF NOT EXISTS join_guard ("
                . "id $pk, ip VARCHAR(64) NOT NULL, member_id BIGINT NOT NULL DEFAULT 0,"
                . " created_at BIGINT NOT NULL DEFAULT 0"
                . ")$suffix",
            "CREATE INDEX {$idx}idx_join_guard_ip ON join_guard(ip, created_at)",
            "CREATE TABLE IF NOT EXISTS todo_done ("
                . "todo_id BIGINT NOT NULL, member_id BIGINT NOT NULL,"
                . " done_at BIGINT NOT NULL DEFAULT 0,"
                . " PRIMARY KEY (todo_id, member_id)"
                . ")$suffix",
        ];
    }
}