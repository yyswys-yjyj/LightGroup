<?php
declare(strict_types=1);

namespace LightGroup\Store;

use PDO;
use PDOException;
use RuntimeException;

final class Db {
    private static ?PDO $pdo = null;

    public static function connect(?array $cfg = null): PDO {
        if (self::$pdo instanceof PDO) return self::$pdo;
        $cfg = $cfg ?? lg_config();
        $driver = $cfg["driver"] ?? "sqlite";

        if ($driver === "sqlite") {
            $path = $cfg["path"];
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("无法创建数据目录: " . $dir);
            }
            $pdo = new PDO("sqlite:" . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec("PRAGMA journal_mode = WAL");
            $pdo->exec("PRAGMA synchronous = NORMAL");
            $pdo->exec("PRAGMA busy_timeout = 5000");
            $pdo->exec("PRAGMA foreign_keys = ON");
        } elseif ($driver === "mysql") {
            $dsn = sprintf(
                "mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
                $cfg["host"] ?? "127.0.0.1",
                (int) ($cfg["port"] ?? 3306),
                $cfg["database"] ?? "lightgroup"
            );
            $pdo = new PDO($dsn, $cfg["user"] ?? "root", $cfg["password"] ?? "", [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } else {
            throw new RuntimeException("不支持的数据库驱动: " . $driver);
        }

        self::$pdo = $pdo;
        self::ensureSchema($pdo, $driver);
        return $pdo;
    }

    /**
     * 老库自动升级。第一次连接时按 settings.schema_version 判断要不要补列，
     * 升级过的库后面每次请求只多一条 SELECT。
     */
    private static function ensureSchema(PDO $pdo, string $driver): void {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            Schema::install($pdo, $driver);
            $st = $pdo->query("SELECT v FROM settings WHERE k = 'schema_version' LIMIT 1");
            $row = $st->fetch();
            $current = $row ? (int) $row["v"] : 0;
            if ($current >= Schema::VERSION) return;
            Schema::migrate($pdo, $driver);
            $pdo->prepare("REPLACE INTO settings (k, v) VALUES ('schema_version', ?)")
                ->execute([(string) Schema::VERSION]);
        } catch (\Throwable $e) {
            // 未安装（表还没有）时静默跳过
        }
    }

    public static function driver(): string {
        return lg_config()["driver"] ?? "sqlite";
    }

    public static function tx(callable $fn) {
        $pdo = self::connect();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            if ($own) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}