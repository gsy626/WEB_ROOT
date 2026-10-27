<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $storage = __DIR__ . '/../storage';
    if (!is_dir($storage) && !mkdir($storage, 0775, true) && !is_dir($storage)) {
        throw new RuntimeException('无法创建数据目录');
    }

    $pdo = new PDO('sqlite:' . $storage . '/game.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    db_schema($pdo);
    return $pdo;
}

function db_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS games (
    id TEXT PRIMARY KEY,
    week INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'playing',
    winner_school_id INTEGER,
    victory_type TEXT,
    ap INTEGER NOT NULL DEFAULT 4,
    player_school_id INTEGER,
    event_json TEXT,
    summary_json TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS schools (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    game_id TEXT NOT NULL,
    slot INTEGER NOT NULL,
    preset_id TEXT,
    name TEXT NOT NULL,
    nickname TEXT NOT NULL,
    short_name TEXT NOT NULL,
    color TEXT NOT NULL,
    emblem TEXT NOT NULL,
    ace TEXT NOT NULL,
    perk TEXT NOT NULL,
    flaw TEXT NOT NULL,
    motto TEXT NOT NULL DEFAULT '',
    is_player INTEGER NOT NULL DEFAULT 0,
    support REAL NOT NULL DEFAULT 75,
    grievance REAL NOT NULL DEFAULT 10,
    funds INTEGER NOT NULL DEFAULT 220,
    base_attack REAL NOT NULL DEFAULT 50,
    base_defense REAL NOT NULL DEFAULT 50,
    income_mult REAL NOT NULL DEFAULT 1,
    personality TEXT NOT NULL DEFAULT 'balanced',
    tech_red INTEGER NOT NULL DEFAULT 0,
    tech_white INTEGER NOT NULL DEFAULT 0,
    tech_black INTEGER NOT NULL DEFAULT 0,
    tech_points INTEGER NOT NULL DEFAULT 0,
    ace_used INTEGER NOT NULL DEFAULT 0,
    ult_red_cd INTEGER NOT NULL DEFAULT 0,
    ult_white_until INTEGER NOT NULL DEFAULT 0,
    ult_black_cd INTEGER NOT NULL DEFAULT 0,
    eliminated INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS districts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    game_id TEXT NOT NULL,
    slot_index INTEGER NOT NULL,
    school_id INTEGER,
    name TEXT NOT NULL,
    kind TEXT NOT NULL,
    x REAL NOT NULL,
    y REAL NOT NULL,
    w REAL NOT NULL,
    h REAL NOT NULL,
    income REAL NOT NULL DEFAULT 3,
    base_defense REAL NOT NULL DEFAULT 10,
    fortify REAL NOT NULL DEFAULT 0,
    owner_id INTEGER,
    is_neutral INTEGER NOT NULL DEFAULT 0,
    neighbors TEXT NOT NULL DEFAULT '[]',
    influence TEXT NOT NULL DEFAULT '{}',
    disabled_until INTEGER NOT NULL DEFAULT 0,
    captured_week INTEGER,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    game_id TEXT NOT NULL,
    week INTEGER NOT NULL,
    category TEXT NOT NULL,
    text TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_schools_game ON schools(game_id);
CREATE INDEX IF NOT EXISTS idx_districts_game ON districts(game_id);
CREATE INDEX IF NOT EXISTS idx_logs_game ON logs(game_id, id);
SQL
    );
}
