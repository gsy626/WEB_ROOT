<?php
declare(strict_types=1);

/**
 * 阵营积分 = 占领节点价值 + 夺点次数×5 + 胜场×2。
 */
function faction_standings(): array
{
    $season = active_season();
    $seasonId = $season ? (int) $season['id'] : 0;

    $rows = db()->query(
        'SELECT f.id, f.code, f.name, f.short_name, f.color, f.motto,
                COALESCE(SUM(CASE WHEN n.owner_faction_id = f.id THEN n.value END), 0) AS node_value,
                COALESCE(SUM(CASE WHEN n.owner_faction_id = f.id THEN 1 ELSE 0 END), 0) AS node_count
           FROM factions f
           LEFT JOIN nodes n ON 1 = 1
          GROUP BY f.id
          ORDER BY f.id'
    )->fetchAll();

    $battleStats = [];
    if ($seasonId > 0) {
        $stmt = db()->prepare('SELECT * FROM battles WHERE season_id = ?');
        $stmt->execute([$seasonId]);
        foreach ($stmt->fetchAll() as $battle) {
            $attackerId = (int) $battle['attacker_faction_id'];
            $battleStats[$attackerId] ??= ['wins' => 0, 'captures' => 0, 'battles' => 0];
            $battleStats[$attackerId]['battles']++;
            if ((int) $battle['captured'] === 1) {
                $battleStats[$attackerId]['captures']++;
            }
            if ($battle['winner_faction_id'] !== null) {
                $wid = (int) $battle['winner_faction_id'];
                $battleStats[$wid] ??= ['wins' => 0, 'captures' => 0, 'battles' => 0];
                $battleStats[$wid]['wins']++;
            }
        }
    }

    $out = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $stats = $battleStats[$id] ?? ['wins' => 0, 'captures' => 0, 'battles' => 0];
        $row['node_value'] = (int) $row['node_value'];
        $row['node_count'] = (int) $row['node_count'];
        $row['wins'] = $stats['wins'];
        $row['captures'] = $stats['captures'];
        $row['battles'] = $stats['battles'];
        $row['score'] = $row['node_value'] + $row['captures'] * 5 + $row['wins'] * 2;
        $out[$id] = $row;
    }
    uasort($out, static fn ($a, $b) =>
        ($b['score'] <=> $a['score'])
        ?: ($b['node_count'] <=> $a['node_count'])
        ?: ($a['id'] <=> $b['id'])
    );
    return $out;
}

function leading_faction_id(array $standings): ?int
{
    $rows = array_values($standings);
    if (!$rows || (int) $rows[0]['score'] === 0) {
        return null;
    }
    if (isset($rows[1])
        && (int) $rows[0]['score'] === (int) $rows[1]['score']
        && (int) $rows[0]['node_count'] === (int) $rows[1]['node_count']
    ) {
        return null;
    }
    return (int) $rows[0]['id'];
}

function individual_standings(int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $sql = "SELECT u.*, t.name AS team_name
              FROM users u
              LEFT JOIN teams t ON t.id = u.team_id
             WHERE u.contribution > 0
             ORDER BY u.contribution DESC, u.season_captures DESC, u.wins DESC, u.id ASC
             LIMIT {$limit}";
    return db()->query($sql)->fetchAll();
}

function team_standings(int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $sql = "SELECT t.id, t.name, t.faction_id, COUNT(u.id) AS members,
                   COALESCE(SUM(u.contribution), 0) AS score,
                   COALESCE(SUM(u.wins), 0) AS wins
              FROM teams t
              LEFT JOIN users u ON u.team_id = t.id
             GROUP BY t.id
             ORDER BY score DESC, members DESC, t.id ASC
             LIMIT {$limit}";
    return db()->query($sql)->fetchAll();
}

function my_rank(array $user): array
{
    if ((int) $user['contribution'] <= 0) {
        return ['overall' => null, 'faction' => null];
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*) + 1 FROM users WHERE contribution > ?'
    );
    $stmt->execute([(int) $user['contribution']]);
    $overall = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        'SELECT COUNT(*) + 1 FROM users WHERE contribution > ? AND faction_id = ?'
    );
    $stmt->execute([(int) $user['contribution'], (int) $user['faction_id']]);
    $faction = (int) $stmt->fetchColumn();

    return ['overall' => $overall, 'faction' => $faction];
}

function season_overview(): array
{
    $season = active_season();
    $standings = faction_standings();
    $leader = $standings ? reset($standings) : null;
    $total = 0;
    foreach ($standings as $row) {
        $total += $row['score'];
    }
    return [
        'season' => $season,
        'standings' => $standings,
        'leader' => $leader,
        'total_score' => $total,
    ];
}

function last_finished_season(): ?array
{
    $stmt = db()->query("SELECT * FROM seasons WHERE status = 'finished' ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}
