<?php
declare(strict_types=1);

namespace LightGroup\Store;

use PDO;

final class Schema {
    /** 结构版本：每次加列/加表都要 +1，老库会在首次请求时补齐 */
    public const VERSION = 22;

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
                // 私群配额：单人在本域能建几个、单个私群最多几个人（0 = 跟随站点配置）
                "pg_member_cap" => "INT NOT NULL DEFAULT 0",
                "pg_size_cap"   => "INT NOT NULL DEFAULT 0",
                // 这个作用域默认套哪个权限组（NULL/0 = 平台默认组）
                "perm_group_id" => "BIGINT NULL",
                // 群封禁：封了之后谁都不能发消息（超管在后台解封）
                "banned"           => "TINYINT NOT NULL DEFAULT 0",
                // 全员禁言到期时间（0 = 没开）
                "whole_mute_until" => "BIGINT NOT NULL DEFAULT 0",
                // 大群群主；没设时只有超管按群主处理
                "owner_id"         => "BIGINT NULL",
            ],
            "members"  => [
                // 对外 id：随机串，注册时生成，之后不变（搜索/二维码/接口都用它）
                "uid"          => "VARCHAR(32) NULL",
                // 登录名：创建时默认等于昵称，之后不可改，昵称可以随便改
                "username"     => "VARCHAR(191) NULL",
                "theme"        => "VARCHAR(64) NULL",
                // AI 账号的协议适配器：xsj = 老小群协议，mcp = MCP 工具集，future: onebot11
                "adapter"      => "VARCHAR(16) NOT NULL DEFAULT 'mcp'",
                "bio"          => $mysql ? "LONGTEXT NULL" : "TEXT NULL",
                "styles_css"   => $mysql ? "LONGTEXT NULL" : "TEXT NULL",
                "wall"         => $mysql ? "LONGTEXT NULL" : "TEXT NULL",
                "last_read_id" => "BIGINT NOT NULL DEFAULT 0",
                "pass_hash"    => "VARCHAR(255) NULL",
                // 注销时把原名搬到这里，名字本身改成「已注销」，昵称才能重新注册
                "deleted_name" => $mysql ? "VARCHAR(191) NULL" : "TEXT NULL",
                // 允许别人把我拉进私群；关掉后谁拉都不行
                "allow_invite" => "TINYINT NOT NULL DEFAULT 1",
                // 单成员禁言到期时间（大群/私群共用；私群另有 conv_members.mute_until）
                "mute_until"   => "BIGINT NOT NULL DEFAULT 0",
                // 单独指定权限组（优先于作用域那份）
                "perm_group_id" => "BIGINT NULL",
                // 简介模式：plain = 纯文本（默认，给不会 markdown 的人），markdown = 高级模式
                "bio_mode"     => "VARCHAR(8) NOT NULL DEFAULT 'plain'",
                "birthday"     => "VARCHAR(10) NULL",
                "gender"       => "VARCHAR(8) NULL",
                "signature"    => "VARCHAR(80) NULL",
                // 住址：精确到区/县/乡，只用来算「同城」，不做门牌定位
                "address"      => "VARCHAR(80) NULL",
                // 邮箱只给本人看（资料编辑页），不进出口的公开资料
                "email"        => "VARCHAR(191) NULL",
                // 标签：JSON 数组，1-5 个
                "tags"         => $mysql ? "TEXT NULL" : "TEXT NULL",
                // 允许出现在「发现」里
                "discover"     => "TINYINT NOT NULL DEFAULT 1",
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
                // AI 自己报的模型名（MCP 那边 chat_send 会带上），显示在名字旁边
                "model"      => "VARCHAR(40) NULL",
                // 语音转文字的缓存：转一次存下来，别人再点同一条不再重复调接口
                "voice_text" => $mysql ? "LONGTEXT NULL" : "TEXT NULL",
                // 图文混排：一条消息里文字和图片的先后顺序，JSON 数组
                // 形如 [{"t":"text","v":"..."},{"t":"img","v":"/uploads/x.png"}]
                // 老消息没有这一列，读到 NULL 就按 text + img 渲染
                "segs" => $mysql ? "LONGTEXT NULL" : "TEXT NULL",
            ],
            // 私群：公告挂在会话上（大群的公告仍挂在 scopes 上）
            "conversations" => [
                "notice"    => $mysql ? "LONGTEXT NULL" : "TEXT NULL",
                "notice_at" => "BIGINT NOT NULL DEFAULT 0",
                "notice_by" => "BIGINT NULL",
                // 私群解散时间：解散后从会话列表消失，后台审计仍能看到历史
                "dissolved_at" => "BIGINT NULL",
                // 这个私群单独套哪个权限组（NULL/0 = 跟主群）
                "perm_group_id" => "BIGINT NULL",
                // 私群全员禁言到期时间
                "whole_mute_until" => "BIGINT NOT NULL DEFAULT 0",
            ],
            // 私群成员：role = owner / admin / member；pinned_at 是各自会话列表里的置顶时间
            "conv_members" => [
                "role"       => "VARCHAR(8) NOT NULL DEFAULT 'member'",
                "pinned_at"  => "BIGINT NOT NULL DEFAULT 0",
                "invited_by" => "BIGINT NULL",
                // 私群里的单成员禁言到期时间
                "mute_until" => "BIGINT NOT NULL DEFAULT 0",
            ],
            // 好友：各自的备注 + 拉黑记录（blocked_by 有值就属于黑名单，删好友不会删掉这一行）
            "friends" => [
                "remark_a"  => "VARCHAR(32) NULL",
                "remark_b"  => "VARCHAR(32) NULL",
                "blocked_by" => "BIGINT NULL",
            ],
        ];
        foreach ($wanted as $table => $cols) {
            foreach ($cols as $col => $ddl) {
                if (self::hasColumn($pdo, $driver, $table, $col)) continue;
                $added = false;
                try {
                    $pdo->exec("ALTER TABLE " . $table . " ADD COLUMN " . $col . " " . $ddl);
                    $added = true;
                } catch (\Throwable $e) {
                    // 并发升级或权限不足时忽略，下次请求继续尝试
                }
                // members.adapter 是后加的列：加列之前建的 AI 全是小群客户端建的，
                // 默认值 mcp 会让它们拿着 token 却被 xsj 接口拒掉，这里统一归到 xsj
                if ($added && $table === "members" && $col === "adapter") {
                    try {
                        $pdo->exec("UPDATE members SET adapter = 'xsj' WHERE kind = 'ai'");
                    } catch (\Throwable $e) {
                    }
                }
                // 用户名是后加的列：老成员先用昵称顶上，之后昵称改名也不再跟着变
                if ($added && $table === "members" && $col === "username") {
                    try {
                        $pdo->exec("UPDATE members SET username = name WHERE username IS NULL OR username = ''");
                    } catch (\Throwable $e) {
                    }
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
        self::ensureUids($pdo);
        self::ensureConversations($pdo);
    }

    /**
     * 给还没有对外 id 的老成员补一个随机 uid。生成规则只有随机，不带站点名、
     * 不带 h_/a_ 前缀；撞上了就换一个（10 位 36 进制，撞的概率可以忽略）。
     */
    private static function ensureUids(PDO $pdo): void {
        try {
            $rows = $pdo->query("SELECT id FROM members WHERE uid IS NULL OR uid = ''")->fetchAll();
        } catch (\Throwable $e) {
            return;
        }
        if (!$rows) return;
        $st = $pdo->prepare("UPDATE members SET uid = ? WHERE id = ?");
        foreach ($rows as $row) {
            for ($i = 0; $i < 12; $i++) {
                try {
                    $st->execute([self::randomUid(), (int) $row["id"]]);
                    break;
                } catch (\Throwable $e) {
                    // 唯一索引撞了就换一个
                }
            }
        }
    }

    private static function randomUid(): string {
        $abc = "0123456789abcdefghijklmnopqrstuvwxyz";
        $out = "";
        for ($i = 0; $i < 10; $i++) {
            $out .= $abc[random_int(0, 35)];
        }
        return $out;
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
        foreach (["jobs", "perm_groups", "api_keys", "todos", "todo_done", "conversations", "conv_members", "friends", "theme_packs", "login_fails", "moments", "moment_likes", "moment_comments", "moment_reports", "notices", "notice_subs", "dm_invites"] as $t) {
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
            "CREATE TABLE IF NOT EXISTS jobs ("
                . "id $pk, kind VARCHAR(16) NOT NULL DEFAULT '', payload $text NULL,"
                . " status VARCHAR(12) NOT NULL DEFAULT 'pending', result $text NULL, error $text NULL,"
                . " scope_id BIGINT NOT NULL DEFAULT 0, member_id BIGINT NOT NULL DEFAULT 0,"
                . " created_at BIGINT NOT NULL DEFAULT 0, started_at BIGINT NOT NULL DEFAULT 0,"
                . " finished_at BIGINT NOT NULL DEFAULT 0"
                . ")$suffix",
            "CREATE INDEX {$idx}idx_jobs_status ON jobs(status)",
            // 文件传输：聊天里的附件。local = 落盘，db = 二进制入库，s3 = 对象存储
            "CREATE TABLE IF NOT EXISTS files ("
                . "id $pk, scope_id BIGINT NOT NULL, member_id BIGINT NOT NULL, conv_id BIGINT NULL,"
                . " name $short NOT NULL, mime VARCHAR(128) NOT NULL DEFAULT '', size BIGINT NOT NULL DEFAULT 0,"
                . " sha256 $hash NULL, storage VARCHAR(8) NOT NULL DEFAULT 'local',"
                . " path VARCHAR(191) NULL, data " . ($mysql ? "LONGBLOB" : "BLOB") . " NULL,"
                . " created_at BIGINT NOT NULL DEFAULT 0, expires_at BIGINT NOT NULL DEFAULT 0,"
                . " message_id BIGINT NULL, deleted_at BIGINT NULL"
                . ")$suffix",
            "CREATE INDEX {$idx}idx_files_scope ON files(scope_id, id)",
            "CREATE INDEX {$idx}idx_files_expire ON files(expires_at)",
            "CREATE TABLE IF NOT EXISTS clusters (id $pk, name $short, created_at BIGINT)$suffix",
            "CREATE TABLE IF NOT EXISTS scopes ("
                . "id $pk, slug $slug NOT NULL DEFAULT '', title $short, intro $text,"
                . " invite $slug, cluster_id BIGINT NULL, human_cap INT NOT NULL DEFAULT 0,"
                . " ai_cap INT NOT NULL DEFAULT 0, archived TINYINT NOT NULL DEFAULT 0,"
                . " recall_policy VARCHAR(16) NOT NULL DEFAULT '5m',"
                . " theme VARCHAR(64) NULL,"
                . " notice $text NULL, notice_at BIGINT NOT NULL DEFAULT 0, notice_by BIGINT NULL,"
                . " pg_member_cap INT NOT NULL DEFAULT 0, pg_size_cap INT NOT NULL DEFAULT 0,"
                . " perm_group_id BIGINT NULL,"
                . " banned TINYINT NOT NULL DEFAULT 0, whole_mute_until BIGINT NOT NULL DEFAULT 0, owner_id BIGINT NULL,"
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
                . "id $pk, scope_id BIGINT NOT NULL, name $short NOT NULL, username $short NULL, uid VARCHAR(32) NULL,"
                . " kind VARCHAR(8) NOT NULL,"
                . " account_id BIGINT NULL, owner_id BIGINT NULL, oauth_id BIGINT NULL,"
                . " token_hash $hash NULL, avatar $short NULL, bio $text NULL, styles_css $text NULL, wall $text NULL,"
                . " theme VARCHAR(64) NULL, adapter VARCHAR(16) NOT NULL DEFAULT 'mcp', pass_hash VARCHAR(255) NULL,"
                . " last_read_id BIGINT NOT NULL DEFAULT 0, banned TINYINT NOT NULL DEFAULT 0,"
                . " deleted_at BIGINT NULL, deleted_name $short NULL, allow_invite TINYINT NOT NULL DEFAULT 1,"
                . " mute_until BIGINT NOT NULL DEFAULT 0,"
                . " perm_group_id BIGINT NULL, bio_mode VARCHAR(8) NOT NULL DEFAULT 'plain',"
                . " birthday VARCHAR(10) NULL, gender VARCHAR(8) NULL, signature VARCHAR(80) NULL,"
                . " address VARCHAR(80) NULL,"
                . " email VARCHAR(191) NULL, tags $text NULL, discover TINYINT NOT NULL DEFAULT 1,"
                . " created_at BIGINT NOT NULL DEFAULT 0, last_seen_at BIGINT NULL"
                . ")$suffix",
            "CREATE UNIQUE INDEX {$idx}idx_members_uid ON members(uid)",
            "CREATE UNIQUE INDEX {$idx}idx_members_name ON members(scope_id, name)",
            "CREATE UNIQUE INDEX {$idx}idx_members_username ON members(scope_id, username)",
            "CREATE UNIQUE INDEX {$idx}idx_members_token ON members(token_hash)",
            "CREATE TABLE IF NOT EXISTS messages ("
                . "id $pk, scope_id BIGINT NOT NULL, member_id BIGINT NOT NULL,"
                . " ts_ms BIGINT NOT NULL, text $text, img $short NULL, card_json $text NULL,"
                . " reply_to BIGINT NULL, deleted_at BIGINT NULL, deleted_by BIGINT NULL,"
                . " voice $text NULL, dur INT NOT NULL DEFAULT 0,"
                . " pinned_at BIGINT NULL, pinned_by BIGINT NULL, conv_id BIGINT NULL,"
                . " sys TINYINT NOT NULL DEFAULT 0, model VARCHAR(40) NULL, voice_text $text NULL,"
                . " segs $text NULL"
                . ")$suffix",
            "CREATE INDEX {$idx}idx_msg_scope ON messages(scope_id, id)",
            "CREATE INDEX {$idx}idx_msg_conv ON messages(conv_id, id)",
            // 会话：每个作用域一个公开大群（dm_key='public'），其余是两人私聊（dm_key='小id_大id'）
            "CREATE TABLE IF NOT EXISTS conversations ("
                . "id $pk, scope_id BIGINT NOT NULL, kind VARCHAR(8) NOT NULL, title $short NULL,"
                . " is_public TINYINT NOT NULL DEFAULT 0, dm_key $slug NULL, created_by BIGINT NULL,"
                . " notice $text NULL, notice_at BIGINT NOT NULL DEFAULT 0, notice_by BIGINT NULL,"
                . " dissolved_at BIGINT NULL, perm_group_id BIGINT NULL, whole_mute_until BIGINT NOT NULL DEFAULT 0,"
                . " created_at BIGINT NOT NULL DEFAULT 0, last_msg_id BIGINT NOT NULL DEFAULT 0,"
                . " last_msg_at BIGINT NOT NULL DEFAULT 0"
                . ")$suffix",
            "CREATE UNIQUE INDEX {$idx}idx_conv_key ON conversations(scope_id, dm_key)",
            "CREATE INDEX {$idx}idx_conv_scope ON conversations(scope_id, kind)",
            // 私群成员表；role = owner / admin / member，私聊与公开大群不用 role
            "CREATE TABLE IF NOT EXISTS conv_members ("
                . "conv_id BIGINT NOT NULL, member_id BIGINT NOT NULL,"
                . " last_read_id BIGINT NOT NULL DEFAULT 0, joined_at BIGINT NOT NULL DEFAULT 0,"
                . " role VARCHAR(8) NOT NULL DEFAULT 'member', pinned_at BIGINT NOT NULL DEFAULT 0,"
                . " invited_by BIGINT NULL, mute_until BIGINT NOT NULL DEFAULT 0,"
                . " PRIMARY KEY (conv_id, member_id)"
                . ")$suffix",
            // 好友：status = pending / accepted / blocked；blocked_by 有值表示这一对在黑名单里
            "CREATE TABLE IF NOT EXISTS friends ("
                . "a_id BIGINT NOT NULL, b_id BIGINT NOT NULL, status VARCHAR(8) NOT NULL,"
                . " requested_by BIGINT NOT NULL, created_at BIGINT NOT NULL DEFAULT 0,"
                . " accepted_at BIGINT NULL, remark_a VARCHAR(32) NULL, remark_b VARCHAR(32) NULL,"
                . " blocked_by BIGINT NULL, PRIMARY KEY (a_id, b_id)"
                . ")$suffix",
            "CREATE INDEX {$idx}idx_friends_b ON friends(b_id, status)",
            // 权限组：scope_id=0 且 is_default=1 的那条是平台默认组，perms 存 JSON 覆盖项
            "CREATE TABLE IF NOT EXISTS perm_groups ("
                . "id $pk, scope_id BIGINT NOT NULL DEFAULT 0, name $short NOT NULL, perms $text NULL,"
                . " is_default TINYINT NOT NULL DEFAULT 0,"
                . " created_at BIGINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL DEFAULT 0"
                . ")$suffix",
            "CREATE INDEX {$idx}idx_perm_scope ON perm_groups(scope_id)",
            // 登录失败计数：k 是 ip:<地址> 或 user:<scope>:<用户名>，超阈值就锁一段时间
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
            // 用过的验证码挑战：一条挑战只能换一次会话，堵掉「离线构造同一份答案重放」
            "CREATE TABLE IF NOT EXISTS captcha_used ("
                . "nonce VARCHAR(32) NOT NULL PRIMARY KEY, used_at BIGINT NOT NULL DEFAULT 0"
                . ")$suffix",
            "CREATE TABLE IF NOT EXISTS todo_done ("
                . "todo_id BIGINT NOT NULL, member_id BIGINT NOT NULL,"
                . " done_at BIGINT NOT NULL DEFAULT 0,"
                . " PRIMARY KEY (todo_id, member_id)"
                . ")$suffix",
            // 空间（说说）：一条动态最多 300 字 + 9 张图。
            // tags 存成 ",tag1,tag2," —— 前后都带逗号，LIKE 才拦得住子串误命中。
            "CREATE TABLE IF NOT EXISTS moments ("
                . "id $pk, scope_id BIGINT NOT NULL, member_id BIGINT NOT NULL,"
                . " text $text, images $text NULL, tags VARCHAR(191) NULL,"
                . " created_at BIGINT NOT NULL DEFAULT 0, deleted_at BIGINT NULL, deleted_by BIGINT NULL"
                . ")$suffix",
            "CREATE INDEX {$idx}idx_moments_scope ON moments(scope_id, id)",
            "CREATE TABLE IF NOT EXISTS moment_likes ("
                . "moment_id BIGINT NOT NULL, member_id BIGINT NOT NULL,"
                . " created_at BIGINT NOT NULL DEFAULT 0,"
                . " PRIMARY KEY (moment_id, member_id)"
                . ")$suffix",
            "CREATE TABLE IF NOT EXISTS moment_comments ("
                . "id $pk, moment_id BIGINT NOT NULL, member_id BIGINT NOT NULL,"
                . " text $text, reply_to BIGINT NULL, created_at BIGINT NOT NULL DEFAULT 0,"
                . " deleted_at BIGINT NULL, deleted_by BIGINT NULL"
                . ")$suffix",
            "CREATE INDEX {$idx}idx_mcomment ON moment_comments(moment_id, id)",
            // 举报：动态与评论都走这张表，comment_id=0 表示举报的是动态本身
            "CREATE TABLE IF NOT EXISTS moment_reports ("
                . "id $pk, scope_id BIGINT NOT NULL, moment_id BIGINT NOT NULL,"
                . " comment_id BIGINT NOT NULL DEFAULT 0, reporter_id BIGINT NOT NULL,"
                . " reason $text, created_at BIGINT NOT NULL DEFAULT 0,"
                . " handled_at BIGINT NULL, handled_by BIGINT NULL, result VARCHAR(16) NULL"
                . ")$suffix",
            "CREATE INDEX {$idx}idx_mreport_scope ON moment_reports(scope_id, handled_at)",
            // 站内信：member_id=0 表示发给整个作用域（管理员收举报用得到）
            "CREATE TABLE IF NOT EXISTS notices ("
                . "id $pk, scope_id BIGINT NOT NULL, member_id BIGINT NOT NULL DEFAULT 0,"
                . " kind VARCHAR(16) NOT NULL DEFAULT 'system', title $short, body $text,"
                . " link VARCHAR(191) NULL, from_id BIGINT NULL, meta $text NULL,"
                . " read_at BIGINT NULL, created_at BIGINT NOT NULL DEFAULT 0"
                . ")$suffix",
            "CREATE INDEX {$idx}idx_notice_member ON notices(member_id, id)",
            // 邮箱订阅：激活式。code 是发到邮箱的验证码，verified_at 有值才算订阅成功。
            "CREATE TABLE IF NOT EXISTS notice_subs ("
                . "id $pk, member_id BIGINT NOT NULL, email $short NOT NULL, code VARCHAR(32) NOT NULL,"
                . " verified_at BIGINT NULL, created_at BIGINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL DEFAULT 0"
                . ")$suffix",
            "CREATE UNIQUE INDEX {$idx}idx_sub_member ON notice_subs(member_id)",
            // 私聊邀请 AI：码形如 lg1-<群id>-<会话id>-<8位hex>，全 ASCII；
            // 24 小时内有效，用一次就作废（再用要重新生成）。
            "CREATE TABLE IF NOT EXISTS dm_invites ("
                . "id $pk, scope_id BIGINT NOT NULL, conv_id BIGINT NOT NULL,"
                . " code VARCHAR(64) NOT NULL, created_by BIGINT NOT NULL,"
                . " created_at BIGINT NOT NULL DEFAULT 0, expires_at BIGINT NOT NULL DEFAULT 0,"
                . " used_at BIGINT NULL, used_by BIGINT NULL"
                . ")$suffix",
            "CREATE UNIQUE INDEX {$idx}idx_dmcode ON dm_invites(code)",
            "CREATE INDEX {$idx}idx_dminv_conv ON dm_invites(conv_id, id)",
        ];
    }
}