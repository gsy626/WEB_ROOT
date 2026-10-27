<?php
declare(strict_types=1);

function data_path(): string
{
    $path = dirname(__DIR__) . '/data';
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
    return $path;
}

function game_db_path(): string
{
    $env = getenv('GAME_DB_PATH');
    if (is_string($env) && $env !== '') {
        $dir = dirname($env);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $env;
    }
    return data_path() . '/game.sqlite';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = game_db_path();
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    if (!db_has_schema($pdo)) {
        $lock = fopen($path . '.install.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('无法创建数据库初始化锁文件');
        }
        flock($lock, LOCK_EX);
        try {
            if (!db_has_schema($pdo)) {
                db_install($pdo);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    return $pdo;
}

function db_has_schema(PDO $pdo): bool
{
    $stmt = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'factions'");
    return (int) $stmt->fetchColumn() > 0;
}

function db_install(PDO $pdo): void
{
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('无法读取数据库结构文件');
    }
    $pdo->exec($schema);

    $pdo->beginTransaction();
    try {
        $insertFaction = $pdo->prepare(
            'INSERT INTO factions (code, name, short_name, motto, color, created_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach (FACTIONS as $code => $meta) {
            $insertFaction->execute([
                $code,
                $meta['name'],
                $meta['short'],
                $meta['motto'],
                $meta['color'],
                now_utc(),
            ]);
        }

        $factionIds = [];
        foreach ($pdo->query('SELECT id, code FROM factions') as $row) {
            $factionIds[$row['code']] = (int) $row['id'];
        }

        $bounds = season_bounds_for(new DateTimeImmutable('now', tz()));
        $stmt = $pdo->prepare(
            'INSERT INTO seasons (name, started_at, ends_at, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['第 1 赛季', $bounds['start'], $bounds['end'], 'active']);
        $seasonId = (int) $pdo->lastInsertId();
        $seasonName = season_name_from_bounds(1, $bounds);
        $pdo->prepare('UPDATE seasons SET name = ? WHERE id = ?')->execute([$seasonName, $seasonId]);

        $insertNode = $pdo->prepare(
            'INSERT INTO nodes
                (code, name, zone, kind, x, y, owner_faction_id, garrison, base_defense,
                 neutral_floor, garrison_cap, value, fortify, captured_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        foreach (NODE_SEED as $node) {
            $ownerId = $node['owner'] !== null ? $factionIds[$node['owner']] : null;
            $garrison = $node['base_defense'] + ($ownerId !== null ? 25 : 0);
            $insertNode->execute([
                $node['code'],
                $node['name'],
                $node['zone'],
                $node['kind'],
                $node['x'],
                $node['y'],
                $ownerId,
                $garrison,
                $node['base_defense'],
                (int) round($node['base_defense'] * 0.9),
                $node['garrison_cap'],
                $node['value'],
                $ownerId !== null ? now_utc() : null,
            ]);
        }

        $next = (int) (ceil((time() + 1) / (TICK_MINUTES * 60)) * (TICK_MINUTES * 60));
        $pdo->prepare(
            'INSERT INTO tick_state (id, tick_no, last_tick_at, next_tick_at) VALUES (1, 0, NULL, ?)'
        )->execute([gmdate('Y-m-d H:i:s', $next)]);

        $pdo->prepare("INSERT INTO app_meta (key, value) VALUES ('schema_version', '1')")
            ->execute();

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function factions(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = db()->query('SELECT * FROM factions ORDER BY id')->fetchAll();
    return $cache;
}

function faction_by_code(string $code): ?array
{
    foreach (factions() as $faction) {
        if ($faction['code'] === $code) {
            return $faction;
        }
    }
    return null;
}

function faction_by_id(?int $id): ?array
{
    if ($id === null) {
        return null;
    }
    foreach (factions() as $faction) {
        if ((int) $faction['id'] === $id) {
            return $faction;
        }
    }
    return null;
}

function active_season(): ?array
{
    $stmt = db()->query("SELECT * FROM seasons WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    $season = $stmt->fetch();
    return $season === false ? null : $season;
}

function tick_state(): array
{
    $state = db()->query('SELECT * FROM tick_state WHERE id = 1')->fetch();
    if ($state === false) {
        throw new RuntimeException('结算状态缺失');
    }
    return $state;
}
