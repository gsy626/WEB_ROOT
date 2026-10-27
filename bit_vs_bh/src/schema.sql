PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS factions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT NOT NULL UNIQUE,
    name        TEXT NOT NULL,
    short_name  TEXT NOT NULL,
    motto       TEXT NOT NULL,
    color       TEXT NOT NULL,
    created_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS seasons (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT NOT NULL,
    started_at        TEXT NOT NULL,
    ends_at           TEXT NOT NULL,
    status            TEXT NOT NULL DEFAULT 'active',
    winner_faction_id INTEGER,
    summary           TEXT,
    finalized_at      TEXT
);

CREATE TABLE IF NOT EXISTS teams (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL UNIQUE,
    faction_id INTEGER NOT NULL REFERENCES factions(id),
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    username            TEXT NOT NULL UNIQUE,
    password_hash       TEXT NOT NULL,
    faction_id          INTEGER NOT NULL REFERENCES factions(id),
    team_id             INTEGER REFERENCES teams(id),
    ap                  INTEGER NOT NULL DEFAULT 20,
    ap_day              TEXT NOT NULL,
    contribution        INTEGER NOT NULL DEFAULT 0,
    total_contribution  INTEGER NOT NULL DEFAULT 0,
    season_attack       INTEGER NOT NULL DEFAULT 0,
    season_defense      INTEGER NOT NULL DEFAULT 0,
    season_scout        INTEGER NOT NULL DEFAULT 0,
    season_captures     INTEGER NOT NULL DEFAULT 0,
    wins                INTEGER NOT NULL DEFAULT 0,
    losses              INTEGER NOT NULL DEFAULT 0,
    created_at          TEXT NOT NULL,
    last_action_at      TEXT,
    last_seen_at        TEXT,
    title               TEXT,
    is_demo             INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS nodes (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    code              TEXT NOT NULL UNIQUE,
    name              TEXT NOT NULL,
    zone              TEXT NOT NULL,
    kind              TEXT NOT NULL,
    x                 REAL NOT NULL,
    y                 REAL NOT NULL,
    owner_faction_id  INTEGER REFERENCES factions(id),
    garrison          INTEGER NOT NULL,
    base_defense      INTEGER NOT NULL,
    neutral_floor     INTEGER NOT NULL,
    garrison_cap      INTEGER NOT NULL,
    value             INTEGER NOT NULL,
    fortify           INTEGER NOT NULL DEFAULT 0,
    captured_at       TEXT,
    last_battle_at    TEXT
);

CREATE TABLE IF NOT EXISTS orders (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL REFERENCES users(id),
    faction_id    INTEGER NOT NULL REFERENCES factions(id),
    node_id       INTEGER NOT NULL REFERENCES nodes(id),
    unit_code     TEXT NOT NULL,
    action        TEXT NOT NULL,
    ap_cost       INTEGER NOT NULL,
    power         INTEGER NOT NULL,
    status        TEXT NOT NULL DEFAULT 'pending',
    battle_id     INTEGER,
    contribution  INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT NOT NULL,
    resolved_at   TEXT
);

CREATE TABLE IF NOT EXISTS battles (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    season_id             INTEGER NOT NULL REFERENCES seasons(id),
    node_id               INTEGER NOT NULL REFERENCES nodes(id),
    tick_no               INTEGER NOT NULL,
    kind                  TEXT NOT NULL,
    attacker_faction_id   INTEGER,
    defender_faction_id   INTEGER,
    attack_power          INTEGER NOT NULL,
    defense_power         INTEGER NOT NULL,
    attacker_units        INTEGER NOT NULL,
    defender_units        INTEGER NOT NULL,
    attacker_losses       INTEGER NOT NULL,
    defender_losses       INTEGER NOT NULL,
    winner_faction_id     INTEGER,
    captured              INTEGER NOT NULL DEFAULT 0,
    garrison_after        INTEGER NOT NULL,
    headline              TEXT NOT NULL,
    report                TEXT NOT NULL,
    created_at            TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS intel (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    node_id     INTEGER NOT NULL REFERENCES nodes(id),
    faction_id  INTEGER NOT NULL REFERENCES factions(id),
    user_id     INTEGER NOT NULL REFERENCES users(id),
    power_min   INTEGER NOT NULL,
    power_max   INTEGER NOT NULL,
    accuracy    INTEGER NOT NULL,
    tick_at     TEXT NOT NULL,
    created_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS tick_state (
    id            INTEGER PRIMARY KEY CHECK (id = 1),
    tick_no       INTEGER NOT NULL DEFAULT 0,
    last_tick_at  TEXT,
    next_tick_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS app_meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_orders_pending ON orders(status, node_id);
CREATE INDEX IF NOT EXISTS idx_orders_user ON orders(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_battles_node ON battles(node_id, created_at);
CREATE INDEX IF NOT EXISTS idx_battles_season ON battles(season_id, created_at);
CREATE INDEX IF NOT EXISTS idx_intel_lookup ON intel(node_id, faction_id, created_at);
CREATE INDEX IF NOT EXISTS idx_users_faction ON users(faction_id);
