<?php
/**
 * Database —— 极简 SQLite 访问层
 *
 * 本机 PHP 默认没有编译 pdo_sqlite / sqlite3，因此这里做两件事：
 *   1. 优先用 PDO，其次用 SQLite3 类，最后给出可读的提示；
 *   2. 若两个扩展都没有，尝试载入 vendor/php-ext 中随站点附带的扩展。
 *
 * 对外只暴露三个方法：run() / all() / one()，评论系统够用。
 */
declare(strict_types=1);

final class Database
{
    private static ?DatabaseDriver $driver = null;
    private static bool $initialized = false;
    private static ?string $error = null;

    /** 载入缺失的 sqlite 扩展（幂等） */
    private static function loadVendoredExtension(): void
    {
        if (extension_loaded('pdo_sqlite') || extension_loaded('sqlite3')) {
            return;
        }
        // dl() 只在部分 SAPI 且 enable_dl=On 时可用，这里只是尽力而为；
        // 正常路径是 bin/serve.sh 用 -d extension=... 在启动时加载。
        if (!function_exists('dl') || !ini_get('enable_dl')) {
            return;
        }
        $apiDir = self::extensionDir();
        if ($apiDir === null) {
            return;
        }
        foreach (['sqlite3.so', 'pdo_sqlite.so'] as $so) {
            if (is_file($apiDir . DIRECTORY_SEPARATOR . $so)) {
                @dl($so);
            }
        }
    }

    /** vendor/php-ext 里对应本机 PHP API 版本的扩展目录 */
    private static function extensionDir(): ?string
    {
        $base = Config::path('vendor', 'php-ext', 'usr', 'lib', 'php');
        $api  = basename((string) ini_get('extension_dir'));
        if ($api !== '' && is_dir($base . DIRECTORY_SEPARATOR . $api)) {
            return $base . DIRECTORY_SEPARATOR . $api;
        }
        foreach ((array) glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $dir) {
            return $dir;
        }
        return null;
    }

    public static function available(): bool
    {
        return self::driver() !== null;
    }

    public static function error(): ?string
    {
        self::driver();
        return self::$error;
    }

    private static function driver(): ?DatabaseDriver
    {
        if (self::$driver !== null) {
            return self::$driver;
        }
        self::loadVendoredExtension();

        try {
            if (extension_loaded('pdo_sqlite')) {
                self::$driver = new PdoSqliteDriver(Config::databaseFile());
            } elseif (extension_loaded('sqlite3')) {
                self::$driver = new NativeSqliteDriver(Config::databaseFile());
            } else {
                self::$error = '当前 PHP 缺少 pdo_sqlite / sqlite3 扩展。';
            }
        } catch (Throwable $e) {
            self::$error = '无法打开 SQLite 数据库：' . $e->getMessage();
        }

        if (self::$driver !== null && !self::$initialized) {
            self::migrate(self::$driver);
            self::$initialized = true;
        }
        return self::$driver;
    }

    /** 建表：只在第一次访问时执行 */
    private static function migrate(DatabaseDriver $db): void
    {
        $db->run(
            'CREATE TABLE IF NOT EXISTS comments (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                post_slug  TEXT    NOT NULL,
                author     TEXT    NOT NULL,
                body       TEXT    NOT NULL,
                created_at TEXT    NOT NULL,
                ip_hash    TEXT    NOT NULL DEFAULT ""
            )'
        );
        $db->run('CREATE INDEX IF NOT EXISTS idx_comments_post ON comments (post_slug, id)');
    }

    /** 写操作 */
    public static function run(string $sql, array $params = []): void
    {
        $db = self::driver();
        if ($db === null) {
            throw new RuntimeException(self::$error ?? '数据库不可用');
        }
        $db->run($sql, $params);
    }

    /** 多行查询 */
    public static function all(string $sql, array $params = []): array
    {
        $db = self::driver();
        if ($db === null) {
            throw new RuntimeException(self::$error ?? '数据库不可用');
        }
        return $db->all($sql, $params);
    }
}

interface DatabaseDriver
{
    public function run(string $sql, array $params = []): void;
    public function all(string $sql, array $params = []): array;
}

final class PdoSqliteDriver implements DatabaseDriver
{
    private PDO $pdo;

    public function __construct(string $file)
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function run(string $sql, array $params = []): void
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

final class NativeSqliteDriver implements DatabaseDriver
{
    private SQLite3 $db;

    public function __construct(string $file)
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->db = new SQLite3($file);
        $this->db->enableExceptions(true);
        $this->db->busyTimeout(5000);
    }

    public function run(string $sql, array $params = []): void
    {
        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        }
        $stmt->execute();
        $stmt->close();
    }

    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        }
        $result = $stmt->execute();
        $rows   = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}
