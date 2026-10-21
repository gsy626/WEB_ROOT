<?php
/**
 * 单文件数据库层。
 *
 * - 优先使用 SQLite（data/player.db）。
 * - 主机没有 pdo_sqlite 时，退化为同文件的 JSON 文档数据库。
 * - 所有读写都经过 data/player.lock，保证「读-改-写」不会互相覆盖。
 *
 * 排行榜规模不大（几千名玩家），所以每次写入都保存完整快照；
 * 逻辑简单，便于直接拷贝整个文件夹部署。
 */
declare(strict_types=1);

interface BitStore
{
    /** @return mixed */
    public function read(callable $callback);

    /** @return mixed */
    public function write(callable $callback);

    public function format(): string;

    public function path(): string;
}

final class BitFileLock
{
    /** @var resource|null */
    private $handle;

    public function __construct(string $file)
    {
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            throw new RuntimeException('无法创建数据库锁文件，请检查 data/ 目录的写权限。');
        }
        @chmod($file, 0600);
        $this->handle = $handle;
    }

    public function acquire(int $mode): void
    {
        if ($this->handle === null || !flock($this->handle, $mode)) {
            throw new RuntimeException('无法锁定数据库，请稍后重试。');
        }
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}

abstract class BitAbstractStore implements BitStore
{
    protected string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    public function path(): string
    {
        return $this->file;
    }

    /** @return mixed */
    public function read(callable $callback)
    {
        $lock = new BitFileLock(BIT_LOCK_FILE);
        $lock->acquire(LOCK_SH);
        try {
            $state = $this->loadState();
            return $callback($state);
        } finally {
            $lock->release();
        }
    }

    /** @return mixed */
    public function write(callable $callback)
    {
        $lock = new BitFileLock(BIT_LOCK_FILE);
        $lock->acquire(LOCK_EX);
        try {
            $state = $this->loadState();
            $state = bit_normalize_state($state);
            $result = $callback($state);
            $this->saveState($state);
            return $result;
        } finally {
            $lock->release();
        }
    }

    abstract protected function loadState(): array;

    abstract protected function saveState(array $state): void;
}

final class BitSqliteStore extends BitAbstractStore
{
    public function format(): string
    {
        return 'sqlite';
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }

    private function ensureSchema(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS meta (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS players (
                id             INTEGER PRIMARY KEY,
                username       TEXT    NOT NULL,
                username_lower TEXT    NOT NULL UNIQUE,
                password_hash  TEXT    NOT NULL,
                created_at     INTEGER NOT NULL,
                best_score     INTEGER NOT NULL DEFAULT 0,
                best_level     INTEGER NOT NULL DEFAULT 0,
                best_at        INTEGER,
                plays          INTEGER NOT NULL DEFAULT 0,
                highest_merged INTEGER NOT NULL DEFAULT 0,
                last_score     INTEGER NOT NULL DEFAULT 0,
                last_at        INTEGER
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sessions (
                token_hash TEXT PRIMARY KEY,
                player_id  INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_player ON sessions (player_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions (expires_at)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS runs (
                run_id       TEXT PRIMARY KEY,
                player_id    INTEGER,
                ip_hash      TEXT    NOT NULL,
                started_at   INTEGER NOT NULL,
                expires_at   INTEGER NOT NULL,
                used         INTEGER NOT NULL DEFAULT 0,
                score        INTEGER,
                max_level    INTEGER,
                submitted_at INTEGER
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_started ON runs (started_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_ip ON runs (ip_hash, started_at)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS attempts (
                ip_hash        TEXT    NOT NULL,
                action         TEXT    NOT NULL,
                username_lower TEXT    NOT NULL DEFAULT "",
                at             INTEGER NOT NULL,
                ok             INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attempts_ip ON attempts (ip_hash, action, at)');
        $pdo->exec('PRAGMA user_version = ' . BIT_SCHEMA_VERSION);
    }

    protected function loadState(): array
    {
        if (!is_file($this->file) || filesize($this->file) === 0) {
            return bit_default_state();
        }

        $pdo = $this->pdo();
        $exists = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'players'"
        )->fetchColumn();
        if ($exists === false) {
            return bit_default_state();
        }

        $state = bit_default_state();
        $state['players'] = [];
        $state['sessions'] = [];
        $state['runs'] = [];
        $state['attempts'] = [];

        foreach ($pdo->query('SELECT * FROM players ORDER BY id ASC') as $row) {
            $id = (int) $row['id'];
            $state['players'][$id] = [
                'id' => $id,
                'username' => (string) $row['username'],
                'username_lower' => (string) $row['username_lower'],
                'password_hash' => (string) $row['password_hash'],
                'created_at' => (int) $row['created_at'],
                'best_score' => (int) $row['best_score'],
                'best_level' => (int) $row['best_level'],
                'best_at' => $row['best_at'] !== null ? (int) $row['best_at'] : null,
                'plays' => (int) $row['plays'],
                'highest_merged' => (int) $row['highest_merged'],
                'last_score' => (int) $row['last_score'],
                'last_at' => $row['last_at'] !== null ? (int) $row['last_at'] : null,
            ];
        }

        foreach ($pdo->query('SELECT * FROM sessions') as $row) {
            $state['sessions'][(string) $row['token_hash']] = [
                'token_hash' => (string) $row['token_hash'],
                'player_id' => (int) $row['player_id'],
                'created_at' => (int) $row['created_at'],
                'expires_at' => (int) $row['expires_at'],
            ];
        }

        foreach ($pdo->query('SELECT * FROM runs') as $row) {
            $state['runs'][(string) $row['run_id']] = [
                'run_id' => (string) $row['run_id'],
                'player_id' => $row['player_id'] !== null ? (int) $row['player_id'] : null,
                'ip_hash' => (string) $row['ip_hash'],
                'started_at' => (int) $row['started_at'],
                'expires_at' => (int) $row['expires_at'],
                'used' => (int) $row['used'],
                'score' => $row['score'] !== null ? (int) $row['score'] : null,
                'max_level' => $row['max_level'] !== null ? (int) $row['max_level'] : null,
                'submitted_at' => $row['submitted_at'] !== null ? (int) $row['submitted_at'] : null,
            ];
        }

        foreach ($pdo->query('SELECT * FROM attempts ORDER BY at ASC') as $row) {
            $state['attempts'][] = [
                'ip_hash' => (string) $row['ip_hash'],
                'action' => (string) $row['action'],
                'username_lower' => (string) $row['username_lower'],
                'at' => (int) $row['at'],
                'ok' => (int) $row['ok'],
            ];
        }

        $meta = [];
        foreach ($pdo->query('SELECT key, value FROM meta') as $row) {
            $meta[(string) $row['key']] = (string) $row['value'];
        }
        if (isset($meta['next_player_id'])) {
            $state['next_player_id'] = max(1, (int) $meta['next_player_id']);
        }
        if (isset($meta['created_at'])) {
            $state['meta']['created_at'] = (int) $meta['created_at'];
        }
        $state['meta']['format'] = 'sqlite';
        $state['meta']['schema'] = BIT_SCHEMA_VERSION;

        return $state;
    }

    protected function saveState(array $state): void
    {
        $pdo = $this->pdo();
        $this->ensureSchema($pdo);
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM attempts');
            $pdo->exec('DELETE FROM runs');
            $pdo->exec('DELETE FROM sessions');
            $pdo->exec('DELETE FROM players');
            $pdo->exec('DELETE FROM meta');

            $playerStmt = $pdo->prepare(
                'INSERT INTO players
                    (id, username, username_lower, password_hash, created_at,
                     best_score, best_level, best_at, plays, highest_merged, last_score, last_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($state['players'] as $player) {
                $playerStmt->execute([
                    (int) $player['id'],
                    (string) $player['username'],
                    (string) $player['username_lower'],
                    (string) $player['password_hash'],
                    (int) $player['created_at'],
                    (int) $player['best_score'],
                    (int) $player['best_level'],
                    $player['best_at'] !== null ? (int) $player['best_at'] : null,
                    (int) $player['plays'],
                    (int) $player['highest_merged'],
                    (int) $player['last_score'],
                    $player['last_at'] !== null ? (int) $player['last_at'] : null,
                ]);
            }

            $sessionStmt = $pdo->prepare(
                'INSERT INTO sessions (token_hash, player_id, created_at, expires_at) VALUES (?, ?, ?, ?)'
            );
            foreach ($state['sessions'] as $session) {
                $sessionStmt->execute([
                    (string) $session['token_hash'],
                    (int) $session['player_id'],
                    (int) $session['created_at'],
                    (int) $session['expires_at'],
                ]);
            }

            $runStmt = $pdo->prepare(
                'INSERT INTO runs
                    (run_id, player_id, ip_hash, started_at, expires_at, used, score, max_level, submitted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($state['runs'] as $run) {
                $runStmt->execute([
                    (string) $run['run_id'],
                    $run['player_id'] !== null ? (int) $run['player_id'] : null,
                    (string) $run['ip_hash'],
                    (int) $run['started_at'],
                    (int) $run['expires_at'],
                    (int) $run['used'],
                    $run['score'] !== null ? (int) $run['score'] : null,
                    $run['max_level'] !== null ? (int) $run['max_level'] : null,
                    $run['submitted_at'] !== null ? (int) $run['submitted_at'] : null,
                ]);
            }

            $attemptStmt = $pdo->prepare(
                'INSERT INTO attempts (ip_hash, action, username_lower, at, ok) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($state['attempts'] as $attempt) {
                $attemptStmt->execute([
                    (string) $attempt['ip_hash'],
                    (string) $attempt['action'],
                    (string) $attempt['username_lower'],
                    (int) $attempt['at'],
                    (int) $attempt['ok'],
                ]);
            }

            $metaStmt = $pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?)');
            $metaStmt->execute(['next_player_id', (string) $state['next_player_id']]);
            $metaStmt->execute(['schema_version', (string) BIT_SCHEMA_VERSION]);
            $metaStmt->execute(['created_at', (string) ($state['meta']['created_at'] ?? time())]);

            $pdo->commit();
            @chmod($this->file, 0600);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}

final class BitJsonStore extends BitAbstractStore
{
    public function format(): string
    {
        return 'json';
    }

    protected function loadState(): array
    {
        if (!is_file($this->file) || filesize($this->file) === 0) {
            return bit_default_state();
        }

        $raw = @file_get_contents($this->file);
        if ($raw === false) {
            throw new RuntimeException('无法读取 player.db。');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $backup = $this->file . '.corrupt-' . date('Ymd-His');
            @rename($this->file, $backup);
            error_log('GreatBIT: player.db JSON 解析失败，已备份到 ' . $backup);
            return bit_default_state();
        }
        $decoded['meta']['format'] = 'json';
        return bit_normalize_state($decoded);
    }

    protected function saveState(array $state): void
    {
        $state['meta']['format'] = 'json';
        $state['meta']['schema'] = BIT_SCHEMA_VERSION;
        $json = json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        if ($json === false) {
            throw new RuntimeException('排行榜数据编码失败。');
        }

        $temporary = $this->file . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $written = @file_put_contents($temporary, $json . "\n", LOCK_EX);
        if ($written === false) {
            @unlink($temporary);
            throw new RuntimeException('无法写入 player.db，请检查 data/ 目录的写权限。');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $this->file)) {
            @unlink($temporary);
            throw new RuntimeException('无法替换 player.db，请检查 data/ 目录的写权限。');
        }
        @chmod($this->file, 0600);
    }
}

function bit_sqlite_available(): bool
{
    if (!class_exists('PDO')) {
        return false;
    }
    try {
        return in_array('sqlite', PDO::getAvailableDrivers(), true);
    } catch (Throwable $error) {
        return false;
    }
}

function bit_store(): BitStore
{
    static $store = null;
    if ($store instanceof BitStore) {
        return $store;
    }

    if (!is_dir(BIT_DATA_DIR)) {
        @mkdir(BIT_DATA_DIR, 0700, true);
    }
    if (!is_dir(BIT_DATA_DIR) || !is_writable(BIT_DATA_DIR)) {
        throw new RuntimeException('data/ 目录不存在或不可写。');
    }

    $file = BIT_DB_FILE;
    if (is_file($file) && filesize($file) > 0) {
        $handle = @fopen($file, 'rb');
        $head = $handle !== false ? (string) fread($handle, 64) : '';
        if ($handle !== false) {
            fclose($handle);
        }
        if (substr($head, 0, 15) === 'SQLite format 3') {
            if (!bit_sqlite_available()) {
                throw new RuntimeException(
                    'player.db 是 SQLite 数据库，但当前 PHP 没有启用 pdo_sqlite。'
                    . '请启用该扩展，或删除 data/player.db 让程序自动改用 JSON 存储。'
                );
            }
            $store = new BitSqliteStore($file);
            return $store;
        }
        if (ltrim($head) !== '' && substr(ltrim($head), 0, 1) === '{') {
            $store = new BitJsonStore($file);
            return $store;
        }
        throw new RuntimeException('无法识别 data/player.db 的格式，请检查文件是否损坏。');
    }

    $store = bit_sqlite_available() ? new BitSqliteStore($file) : new BitJsonStore($file);
    return $store;
}

function bit_default_state(): array
{
    return [
        'players' => [],
        'sessions' => [],
        'runs' => [],
        'attempts' => [],
        'next_player_id' => 1,
        'meta' => [
            'created_at' => time(),
            'schema' => BIT_SCHEMA_VERSION,
            'format' => bit_sqlite_available() ? 'sqlite' : 'json',
        ],
    ];
}

function bit_normalize_state(array $state): array
{
    $defaults = bit_default_state();
    $normalized = $defaults;
    $normalized['next_player_id'] = max(1, (int) ($state['next_player_id'] ?? 1));
    $normalized['meta'] = is_array($state['meta'] ?? null) ? $state['meta'] : $defaults['meta'];
    $normalized['meta']['created_at'] = (int) ($normalized['meta']['created_at'] ?? time());

    if (isset($state['players']) && is_array($state['players'])) {
        foreach ($state['players'] as $player) {
            if (!is_array($player) || !isset($player['id'])) {
                continue;
            }
            $id = (int) $player['id'];
            $normalized['players'][$id] = [
                'id' => $id,
                'username' => (string) ($player['username'] ?? ''),
                'username_lower' => (string) ($player['username_lower'] ?? ''),
                'password_hash' => (string) ($player['password_hash'] ?? ''),
                'created_at' => (int) ($player['created_at'] ?? time()),
                'best_score' => max(0, (int) ($player['best_score'] ?? 0)),
                'best_level' => max(0, (int) ($player['best_level'] ?? 0)),
                'best_at' => isset($player['best_at']) ? (int) $player['best_at'] : null,
                'plays' => max(0, (int) ($player['plays'] ?? 0)),
                'highest_merged' => !empty($player['highest_merged']) ? 1 : 0,
                'last_score' => max(0, (int) ($player['last_score'] ?? 0)),
                'last_at' => isset($player['last_at']) ? (int) $player['last_at'] : null,
            ];
            $normalized['next_player_id'] = max($normalized['next_player_id'], $id + 1);
        }
    }

    if (isset($state['sessions']) && is_array($state['sessions'])) {
        foreach ($state['sessions'] as $session) {
            if (!is_array($session) || empty($session['token_hash']) || empty($session['player_id'])) {
                continue;
            }
            $hash = (string) $session['token_hash'];
            $normalized['sessions'][$hash] = [
                'token_hash' => $hash,
                'player_id' => (int) $session['player_id'],
                'created_at' => (int) ($session['created_at'] ?? time()),
                'expires_at' => (int) ($session['expires_at'] ?? 0),
            ];
        }
    }

    if (isset($state['runs']) && is_array($state['runs'])) {
        foreach ($state['runs'] as $run) {
            if (!is_array($run) || empty($run['run_id'])) {
                continue;
            }
            $runId = (string) $run['run_id'];
            $normalized['runs'][$runId] = [
                'run_id' => $runId,
                'player_id' => isset($run['player_id']) ? (int) $run['player_id'] : null,
                'ip_hash' => (string) ($run['ip_hash'] ?? ''),
                'started_at' => (int) ($run['started_at'] ?? 0),
                'expires_at' => (int) ($run['expires_at'] ?? 0),
                'used' => !empty($run['used']) ? 1 : 0,
                'score' => isset($run['score']) ? (int) $run['score'] : null,
                'max_level' => isset($run['max_level']) ? (int) $run['max_level'] : null,
                'submitted_at' => isset($run['submitted_at']) ? (int) $run['submitted_at'] : null,
            ];
        }
    }

    if (isset($state['attempts']) && is_array($state['attempts'])) {
        foreach ($state['attempts'] as $attempt) {
            if (!is_array($attempt)) {
                continue;
            }
            $normalized['attempts'][] = [
                'ip_hash' => (string) ($attempt['ip_hash'] ?? ''),
                'action' => (string) ($attempt['action'] ?? ''),
                'username_lower' => (string) ($attempt['username_lower'] ?? ''),
                'at' => (int) ($attempt['at'] ?? 0),
                'ok' => !empty($attempt['ok']) ? 1 : 0,
            ];
        }
    }

    return $normalized;
}
