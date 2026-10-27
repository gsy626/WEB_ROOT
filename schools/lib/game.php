<?php
declare(strict_types=1);

/**
 * 大学城动态淘汰赛 · 游戏引擎
 * 单机人机局。服务端为唯一权威状态源。
 */

class GameError extends RuntimeException
{
}

function now_string(): string
{
    return date('Y-m-d H:i:s');
}

function decode_json(?string $json, $default = [])
{
    if ($json === null || $json === '') {
        return $default;
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : $default;
}

function clean_text(string $text, int $max, string $fallback = ''): string
{
    $text = trim(strip_tags($text));
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    if ($text === '') {
        return $fallback;
    }
    return mb_substr($text, 0, $max);
}

function clamp(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

function normalize_hex(string $color, string $fallback = '#6A4C93'): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtoupper($color) : $fallback;
}

function school_tile_positions(): array
{
    return [
        0 => [[180, 136], [305, 136], [180, 246], [305, 246]],
        1 => [[815, 136], [940, 136], [815, 246], [940, 246]],
        2 => [[175, 512], [300, 512], [175, 622], [300, 622]],
        3 => [[820, 512], [945, 512], [820, 622], [945, 622]],
        4 => [[485, 298], [610, 298], [485, 402], [610, 402]],
    ];
}

function neutral_definitions(): array
{
    return [
        20 => ['x' => 565, 'y' => 95, 'w' => 126, 'h' => 72, 'name' => '大学城图书馆', 'kind' => 'library', 'income' => 3],
        21 => ['x' => 565, 'y' => 486, 'w' => 126, 'h' => 72, 'name' => '地铁站', 'kind' => 'metro', 'income' => 2],
        22 => ['x' => 350, 'y' => 342, 'w' => 120, 'h' => 72, 'name' => '大学城体育中心', 'kind' => 'sports', 'income' => 2],
        23 => ['x' => 780, 'y' => 342, 'w' => 120, 'h' => 72, 'name' => '大学城美食广场', 'kind' => 'food', 'income' => 3],
    ];
}

function adjacency_pairs(): array
{
    $pairs = [];
    // 每所校区内部四区块全部互通。
    for ($schoolSlot = 0; $schoolSlot < 5; $schoolSlot++) {
        $base = $schoolSlot * 4;
        for ($a = 0; $a < 4; $a++) {
            for ($b = $a + 1; $b < 4; $b++) {
                $pairs[] = [$base + $a, $base + $b];
            }
        }
    }

    // 校区、公共区域与中央校区之间的步道。
    $cross = [
        [0, 20], [0, 22], [1, 20], [2, 22], [2, 16], [3, 22], [3, 19],
        [4, 20], [4, 23], [5, 20], [6, 23], [6, 17], [7, 23], [7, 18],
        [8, 22], [8, 21], [9, 22], [10, 21], [10, 16], [11, 21], [11, 17],
        [12, 23], [12, 21], [13, 23], [14, 21], [14, 19], [15, 21], [15, 18],
        [16, 20], [16, 22], [17, 20], [17, 23], [18, 22], [18, 21],
        [19, 21], [19, 23],
    ];
    return array_merge($pairs, $cross);
}

function normalize_lineup(array $lineup): array
{
    if (count($lineup) !== 5) {
        throw new GameError('必须恰好选择 5 所高校参战。');
    }

    $config = game_config();
    $presetMap = [];
    foreach ($config['presets'] as $preset) {
        $presetMap[$preset['id']] = $preset;
    }

    $normalized = [];
    $seenNames = [];
    foreach (array_values($lineup) as $index => $item) {
        if (!is_array($item)) {
            throw new GameError('高校阵容格式不正确。');
        }
        $kind = $item['kind'] ?? 'preset';
        $isPlayer = $index === 0;

        if ($kind === 'preset') {
            $presetId = (string)($item['preset'] ?? '');
            if (!isset($presetMap[$presetId])) {
                throw new GameError('未知的预设高校。');
            }
            $preset = $presetMap[$presetId];
            $school = [
                'preset_id' => $preset['id'],
                'name' => $preset['name'],
                'nickname' => $preset['nickname'],
                'short_name' => $preset['short'],
                'color' => $preset['color'],
                'emblem' => $preset['emblem'],
                'ace' => $preset['ace'],
                'perk' => $preset['perk'],
                'flaw' => $preset['flaw'],
                'motto' => $preset['motto'],
                'base_attack' => (float)$preset['base_attack'],
                'base_defense' => (float)$preset['base_defense'],
                'income_mult' => (float)$preset['income_mult'],
                'support' => (float)$preset['support'],
                'grievance' => (float)$preset['grievance'],
                'funds' => (int)$preset['funds'],
                'personality' => $preset['personality'],
                'blurb' => $preset['blurb'],
            ];
        } else {
            $name = clean_text((string)($item['name'] ?? ''), 20);
            $nickname = clean_text((string)($item['nickname'] ?? ''), 24);
            $short = clean_text((string)($item['short'] ?? ''), 8);
            $ace = clean_text((string)($item['ace'] ?? ''), 12, '交叉学科');
            $motto = clean_text((string)($item['motto'] ?? ''), 24, '自强不息');
            if (mb_strlen($name) < 2 || mb_strlen($nickname) < 2) {
                throw new GameError('自定义高校的校名与绰号至少需要 2 个字。');
            }
            if ($short === '') {
                $short = mb_substr($nickname, 0, 4);
            }
            $perk = (string)($item['perk'] ?? '');
            $flaw = (string)($item['flaw'] ?? '');
            if (!isset($config['perks'][$perk]) || !isset($config['flaws'][$flaw])) {
                throw new GameError('请为自定义高校选择有效的特长与短板。');
            }
            $base = $config['custom_base'];
            $school = [
                'preset_id' => null,
                'name' => $name,
                'nickname' => $nickname,
                'short_name' => $short,
                'color' => normalize_hex((string)($item['color'] ?? ''), $config['colors'][0]),
                'emblem' => mb_substr($name, 0, 1),
                'ace' => $ace,
                'perk' => $perk,
                'flaw' => $flaw,
                'motto' => $motto,
                'base_attack' => (float)$base['base_attack'],
                'base_defense' => (float)$base['base_defense'],
                'income_mult' => (float)$base['income_mult'],
                'support' => (float)$base['support'],
                'grievance' => (float)$base['grievance'],
                'funds' => (int)$base['funds'],
                'personality' => $base['personality'],
                'blurb' => '一所拒绝被定义的大学，正在大学城寻找自己的位置。',
            ];
        }

        $nameKey = mb_strtolower($school['name']);
        if (isset($seenNames[$nameKey])) {
            throw new GameError('参战高校不能重名：' . $school['name']);
        }
        $seenNames[$nameKey] = true;

        // 特性带来的开场修正。
        if ($school['perk'] === 'giant') {
            $school['funds'] += 80;
            $school['grievance'] += 2;
        }
        if ($school['flaw'] === 'small') {
            $school['funds'] -= 50;
        }

        $school['is_player'] = $isPlayer ? 1 : 0;
        $school['tech_points'] = $isPlayer ? 2 : 0;
        $normalized[] = $school;
    }

    return $normalized;
}

function create_game(array $lineup): array
{
    $db = db();
    $schools = normalize_lineup($lineup);
    $gameId = bin2hex(random_bytes(8));
    $now = now_string();
    $config = game_config();

    // 玩家坐镇中央，四名对手分列大学城四角。
    $mapSlots = [4, 0, 1, 2, 3];

    $pdo = $db;
    $pdo->beginTransaction();
    try {
        $insertGame = $pdo->prepare('INSERT INTO games (id, week, status, ap, event_json, created_at, updated_at) VALUES (?, 1, ?, ?, NULL, ?, ?)');
        $insertGame->execute([$gameId, 'playing', $config['starting_ap'], $now, $now]);

        $insertSchool = $pdo->prepare(
            'INSERT INTO schools
            (game_id, slot, preset_id, name, nickname, short_name, color, emblem, ace, perk, flaw, motto, is_player, support, grievance, funds, base_attack, base_defense, income_mult, personality, tech_red, tech_white, tech_black, tech_points, ace_used, ult_red_cd, ult_white_until, ult_black_cd, eliminated, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, 0, 0, 0, 0, 0, ?)'
        );

        $schoolIds = [];
        $playerSchoolId = null;
        foreach ($schools as $i => $school) {
            $mapSlot = $mapSlots[$i];
            $insertSchool->execute([
                $gameId,
                $mapSlot,
                $school['preset_id'],
                $school['name'],
                $school['nickname'],
                $school['short_name'],
                $school['color'],
                $school['emblem'],
                $school['ace'],
                $school['perk'],
                $school['flaw'],
                $school['motto'],
                $school['is_player'],
                $school['support'],
                $school['grievance'],
                $school['funds'],
                $school['base_attack'],
                $school['base_defense'],
                $school['income_mult'],
                $school['personality'],
                $school['tech_points'],
                $now,
            ]);
            $schoolId = (int)$pdo->lastInsertId();
            $schoolIds[$mapSlot] = $schoolId;
            if ($school['is_player']) {
                $playerSchoolId = $schoolId;
            }
        }
        $pdo->prepare('UPDATE games SET player_school_id = ? WHERE id = ?')->execute([$playerSchoolId, $gameId]);

        $insertDistrict = $pdo->prepare(
            'INSERT INTO districts (game_id, slot_index, school_id, name, kind, x, y, w, h, income, base_defense, fortify, owner_id, is_neutral, neighbors, influence, disabled_until, captured_week, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 0, ?, ?)'
        );

        $positions = school_tile_positions();
        $districtIdsBySlot = [];
        $districtKinds = ['ace', 'lab', 'life', 'admin'];
        $districtNames = null;
        $districtIncome = [5, 4, 2, 3];
        $districtDefense = [14, 12, 9, 13];

        foreach ($schoolIds as $mapSlot => $schoolId) {
            // 找回该槽位对应的学校数据。
            $schoolData = null;
            foreach ($schools as $i => $candidate) {
                if ($mapSlots[$i] === $mapSlot) {
                    $schoolData = $candidate;
                    break;
                }
            }
            if ($schoolData === null) {
                continue;
            }
            $names = [
                $schoolData['ace'],
                '交叉学科中心',
                '学生生活区',
                '行政中心',
            ];
            foreach ($positions[$mapSlot] as $local => [$x, $y]) {
                $globalSlot = $mapSlot * 4 + $local;
                $insertDistrict->execute([
                    $gameId,
                    $globalSlot,
                    $schoolId,
                    $names[$local],
                    $districtKinds[$local],
                    $x,
                    $y,
                    108,
                    84,
                    $districtIncome[$local],
                    $districtDefense[$local],
                    $schoolId,
                    0,
                    '[]',
                    json_encode([(string)$schoolId => 100], JSON_UNESCAPED_UNICODE),
                    1,
                    $now,
                ]);
                $districtIdsBySlot[$globalSlot] = (int)$pdo->lastInsertId();
            }
        }

        foreach (neutral_definitions() as $globalSlot => $neutral) {
            $insertDistrict->execute([
                $gameId,
                $globalSlot,
                null,
                $neutral['name'],
                $neutral['kind'],
                $neutral['x'],
                $neutral['y'],
                $neutral['w'],
                $neutral['h'],
                $neutral['income'],
                8,
                null,
                1,
                '[]',
                '{}',
                null,
                $now,
            ]);
            $districtIdsBySlot[$globalSlot] = (int)$pdo->lastInsertId();
        }

        $neighborSlots = [];
        foreach ($districtIdsBySlot as $slot => $_) {
            $neighborSlots[$slot] = [];
        }
        foreach (adjacency_pairs() as [$a, $b]) {
            if (isset($districtIdsBySlot[$a], $districtIdsBySlot[$b])) {
                $neighborSlots[$a][] = $b;
                $neighborSlots[$b][] = $a;
            }
        }
        $updateNeighbors = $pdo->prepare('UPDATE districts SET neighbors = ? WHERE id = ?');
        foreach ($districtIdsBySlot as $slot => $districtId) {
            $neighborIds = [];
            foreach (array_values(array_unique($neighborSlots[$slot])) as $neighborSlot) {
                if (isset($districtIdsBySlot[$neighborSlot])) {
                    $neighborIds[] = $districtIdsBySlot[$neighborSlot];
                }
            }
            $updateNeighbors->execute([json_encode($neighborIds), $districtId]);
        }

        $player = null;
        foreach ($schools as $i => $school) {
            if ($school['is_player']) {
                $player = $school;
                break;
            }
        }

        add_log($pdo, $gameId, 1, 'news', '【教育部】五所高校即刻进驻大学城，动态淘汰赛正式启动，终局仅保留一所独立建制。');
        add_log($pdo, $gameId, 1, 'news', '【校园快讯】' . $player['nickname'] . '宣布进入一级战备，食堂营业时间延长至凌晨两点。');
        add_log($pdo, $gameId, 1, 'news', '【匿名投稿】某高校战略指挥部被拍到在打印店批量打印《大学城吞并方案（第三版）》。');

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['state' => get_state($gameId), 'new_game' => true];
}

function add_log(PDO $db, string $gameId, int $week, string $category, string $text): void
{
    $stmt = $db->prepare('INSERT INTO logs (game_id, week, category, text, created_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$gameId, $week, $category, $text, now_string()]);
}

function load_game_bundle(PDO $db, string $gameId): array
{
    $stmt = $db->prepare('SELECT * FROM games WHERE id = ?');
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) {
        throw new GameError('没有找到这局游戏，可能已经被清理。');
    }

    $stmt = $db->prepare('SELECT * FROM schools WHERE game_id = ? ORDER BY is_player DESC, slot ASC');
    $stmt->execute([$gameId]);
    $schools = [];
    foreach ($stmt->fetchAll() as $school) {
        $school['id'] = (int)$school['id'];
        $school['slot'] = (int)$school['slot'];
        $school['is_player'] = (int)$school['is_player'];
        $school['support'] = (float)$school['support'];
        $school['grievance'] = (float)$school['grievance'];
        $school['funds'] = (int)$school['funds'];
        $school['base_attack'] = (float)$school['base_attack'];
        $school['base_defense'] = (float)$school['base_defense'];
        $school['income_mult'] = (float)$school['income_mult'];
        $school['tech_red'] = (int)$school['tech_red'];
        $school['tech_white'] = (int)$school['tech_white'];
        $school['tech_black'] = (int)$school['tech_black'];
        $school['tech_points'] = (int)$school['tech_points'];
        $school['ace_used'] = (int)$school['ace_used'];
        $school['ult_red_cd'] = (int)$school['ult_red_cd'];
        $school['ult_white_until'] = (int)$school['ult_white_until'];
        $school['ult_black_cd'] = (int)$school['ult_black_cd'];
        $school['eliminated'] = (int)$school['eliminated'];
        $schools[$school['id']] = $school;
    }

    $stmt = $db->prepare('SELECT * FROM districts WHERE game_id = ? ORDER BY slot_index ASC');
    $stmt->execute([$gameId]);
    $districts = [];
    foreach ($stmt->fetchAll() as $district) {
        $district['id'] = (int)$district['id'];
        $district['slot_index'] = (int)$district['slot_index'];
        $district['school_id'] = $district['school_id'] !== null ? (int)$district['school_id'] : null;
        $district['x'] = (float)$district['x'];
        $district['y'] = (float)$district['y'];
        $district['w'] = (float)$district['w'];
        $district['h'] = (float)$district['h'];
        $district['income'] = (float)$district['income'];
        $district['base_defense'] = (float)$district['base_defense'];
        $district['fortify'] = (float)$district['fortify'];
        $district['owner_id'] = $district['owner_id'] !== null ? (int)$district['owner_id'] : null;
        $district['is_neutral'] = (int)$district['is_neutral'];
        $district['disabled_until'] = (int)$district['disabled_until'];
        $district['captured_week'] = $district['captured_week'] !== null ? (int)$district['captured_week'] : null;
        $district['neighbors'] = array_map('intval', decode_json($district['neighbors'], []));
        $district['influence'] = json_decode($district['influence'] ?: '{}', true) ?: [];
        $cleanInfluence = [];
        foreach ($district['influence'] as $sid => $value) {
            $cleanInfluence[(int)$sid] = (float)$value;
        }
        $district['influence'] = $cleanInfluence;
        $districts[$district['id']] = $district;
    }

    $game['week'] = (int)$game['week'];
    $game['ap'] = (int)$game['ap'];
    $game['player_school_id'] = $game['player_school_id'] !== null ? (int)$game['player_school_id'] : null;

    return ['game' => $game, 'schools' => $schools, 'districts' => $districts];
}

function save_game(PDO $db, array $game): void
{
    $stmt = $db->prepare(
        'UPDATE games SET week = ?, status = ?, winner_school_id = ?, victory_type = ?, ap = ?, event_json = ?, summary_json = ?, updated_at = ? WHERE id = ?'
    );
    $stmt->execute([
        $game['week'],
        $game['status'],
        $game['winner_school_id'] ?? null,
        $game['victory_type'] ?? null,
        $game['ap'],
        $game['event_json'] ?? null,
        $game['summary_json'] ?? null,
        now_string(),
        $game['id'],
    ]);
}

function save_school(PDO $db, array $school): void
{
    $stmt = $db->prepare(
        'UPDATE schools SET support = ?, grievance = ?, funds = ?, tech_red = ?, tech_white = ?, tech_black = ?, tech_points = ?, ace_used = ?, ult_red_cd = ?, ult_white_until = ?, ult_black_cd = ?, eliminated = ? WHERE id = ? AND game_id = ?'
    );
    $stmt->execute([
        $school['support'],
        $school['grievance'],
        $school['funds'],
        $school['tech_red'],
        $school['tech_white'],
        $school['tech_black'],
        $school['tech_points'],
        $school['ace_used'],
        $school['ult_red_cd'],
        $school['ult_white_until'],
        $school['ult_black_cd'],
        $school['eliminated'],
        $school['id'],
        $school['game_id'],
    ]);
}

function save_district(PDO $db, array $district): void
{
    $stmt = $db->prepare(
        'UPDATE districts SET fortify = ?, owner_id = ?, influence = ?, disabled_until = ?, captured_week = ? WHERE id = ? AND game_id = ?'
    );
    $stmt->execute([
        $district['fortify'],
        $district['owner_id'],
        json_encode($district['influence'], JSON_UNESCAPED_UNICODE),
        $district['disabled_until'],
        $district['captured_week'],
        $district['id'],
        $district['game_id'],
    ]);
}

function player_school(array $schools, array $game): array
{
    $id = $game['player_school_id'];
    if ($id === null || !isset($schools[$id])) {
        throw new GameError('本局玩家高校数据缺失。');
    }
    return $schools[$id];
}

function player_max_ap(int $districtCount): int
{
    return min(7, 3 + (int)ceil($districtCount / 4));
}

function count_territories(array $districts, int $schoolId): int
{
    $count = 0;
    foreach ($districts as $district) {
        if ($district['owner_id'] === $schoolId) {
            $count++;
        }
    }
    return $count;
}

function public_territories(array $districts, int $schoolId): int
{
    $count = 0;
    foreach ($districts as $district) {
        if ($district['owner_id'] === $schoolId && $district['slot_index'] >= 20) {
            $count++;
        }
    }
    return $count;
}

function active_event(array $game): ?array
{
    $event = decode_json($game['event_json'] ?? null, []);
    if (!$event || (int)($event['until'] ?? 0) < (int)$game['week']) {
        return null;
    }
    return $event;
}

function active_event_id(array $game): ?string
{
    $event = active_event($game);
    return $event['id'] ?? null;
}

function event_target_id(array $game): ?int
{
    $event = active_event($game);
    return isset($event['target_id']) ? (int)$event['target_id'] : null;
}

function tech_level(array $school, string $branch): int
{
    return (int)($school['tech_' . $branch] ?? 0);
}

function effective_attack(array $school, array $game): float
{
    $value = $school['base_attack'] + tech_level($school, 'red') * 4.0;
    if ($school['perk'] === 'scientist') {
        $value *= 1.10;
    }
    if ($school['flaw'] === 'engineering') {
        $value *= 0.85;
    }
    if (event_target_id($game) === (int)$school['id'] && active_event_id($game) === 'score_drop') {
        $value *= 0.90;
    }
    return round($value, 1);
}

function effective_defense(array $school, array $game): float
{
    $value = $school['base_defense'] + tech_level($school, 'white') * 4.0;
    if ($school['perk'] === 'infra') {
        $value *= 1.10;
    }
    if ($school['flaw'] === 'small') {
        $value *= 0.90;
    }
    if (event_target_id($game) === (int)$school['id'] && active_event_id($game) === 'score_drop') {
        $value *= 0.90;
    }
    return round($value, 1);
}

function school_income_multiplier(array $school): float
{
    $mult = $school['income_mult'];
    if ($school['perk'] === 'giant') {
        $mult += 0.20;
    }
    if ($school['perk'] === 'scenic') {
        $mult += 0.10;
    }
    if (tech_level($school, 'white') >= 3) {
        $mult += 0.15;
    }
    return $mult;
}

function school_weekly_income(array $school, array $districts): int
{
    $base = 28.0; // 财政基本拨款，保证不会第一周就破产。
    foreach ($districts as $district) {
        if ($district['owner_id'] === (int)$school['id']) {
            $base += $district['income'];
        }
    }
    return (int)round($base * school_income_multiplier($school));
}

function district_defense_power(array $district, array $school, array $game): float
{
    $fortify = $district['fortify'];
    if (tech_level($school, 'red') >= 3) {
        $fortify *= 0.70;
    }
    return round(effective_defense($school, $game) + $fortify * 0.90 + 6.0, 1);
}

function school_shielded(array $school, array $game): bool
{
    return (int)$school['ult_white_until'] >= (int)$game['week'];
}

function player_reachable_ids(array $districts, array $school, int $range = 1): array
{
    $owned = [];
    foreach ($districts as $district) {
        if ($district['owner_id'] === (int)$school['id']) {
            $owned[$district['id']] = true;
        }
    }
    if (!$owned) {
        return [];
    }

    $reachable = [];
    $frontier = array_keys($owned);
    $visited = $owned;
    for ($depth = 1; $depth <= $range; $depth++) {
        $next = [];
        foreach ($frontier as $id) {
            if (!isset($districts[$id])) {
                continue;
            }
            foreach ($districts[$id]['neighbors'] as $neighborId) {
                if (isset($visited[$neighborId])) {
                    continue;
                }
                $visited[$neighborId] = true;
                $reachable[$neighborId] = true;
                $next[] = $neighborId;
            }
        }
        $frontier = $next;
    }
    return array_keys($reachable);
}

function all_adjacent_targets(array $districts, int $schoolId): array
{
    $owned = [];
    foreach ($districts as $district) {
        if ($district['owner_id'] === $schoolId) {
            $owned[$district['id']] = true;
        }
    }
    $targets = [];
    foreach (array_keys($owned) as $id) {
        if (!isset($districts[$id])) {
            continue;
        }
        foreach ($districts[$id]['neighbors'] as $neighborId) {
            if (isset($owned[$neighborId]) || !isset($districts[$neighborId])) {
                continue;
            }
            $targets[$neighborId] = true;
        }
    }
    return array_keys($targets);
}

function tech_state(array $player, array $config): array
{
    $result = [];
    foreach ($config['branches'] as $branch => $data) {
        $level = tech_level($player, $branch);
        $nodes = [];
        foreach ($data['nodes'] as $node) {
            $unlocked = $level >= $node['level'];
            $isNext = $level + 1 === $node['level'];
            $nodes[] = [
                'level' => $node['level'],
                'name' => $node['name'],
                'cost' => $node['cost'],
                'desc' => $node['desc'],
                'flavor' => $node['flavor'],
                'unlocked' => $unlocked,
                'next' => $isNext,
                'can_unlock' => $isNext && $player['tech_points'] >= $node['cost'],
            ];
        }
        $result[$branch] = [
            'id' => $branch,
            'name' => $data['name'],
            'subtitle' => $data['subtitle'],
            'color' => $data['color'],
            'icon' => $data['icon'],
            'level' => $level,
            'nodes' => $nodes,
        ];
    }
    return $result;
}

function ace_definition(array $school): array
{
    $preset = (string)($school['preset_id'] ?? '');
    $map = [
        'tsinghua' => ['name' => '姚班天降', 'desc' => '所有自有校区城防 +10，支持率 +4。'],
        'pku' => ['name' => '自由辩论周', 'desc' => '全体对手支持率 -5，并对可达敌区渗透 +12%。'],
        'fudan' => ['name' => '附属医院专家团', 'desc' => '支持率 +12，怨气 -25。'],
        'sjtu' => ['name' => '下饺子工程队', 'desc' => '行政点 +3，并对可达敌区渗透 +10%。'],
        'zju' => ['name' => '万人誓师大会', 'desc' => '经费 +100，支持率 +6。'],
        'ustc' => ['name' => '少年班奇袭', 'desc' => '校策点 +3，并对可达敌区渗透 +10%。'],
        'whu' => ['name' => '樱花节流量', 'desc' => '经费 +80，支持率 +7，怨气 -8。'],
    ];
    if (isset($map[$preset])) {
        return $map[$preset];
    }
    return ['name' => '神秘王牌', 'desc' => '行政点 +2，经费 +60，支持率 +5。'];
}

function base_state(string $gameId): array
{
    $db = db();
    $bundle = load_game_bundle($db, $gameId);
    $game = $bundle['game'];
    $schools = $bundle['schools'];
    $districts = $bundle['districts'];
    $config = game_config();
    $player = player_school($schools, $game);

    $totalDistricts = count($districts);
    $threshold = (int)ceil($totalDistricts * $config['victory_ratio']);
    $counts = [];
    foreach ($schools as $sid => $school) {
        $counts[$sid] = count_territories($districts, $sid);
    }

    $reachable = player_reachable_ids($districts, $player, $player['perk'] === 'ship' ? 2 : 1);
    $reachableMap = array_fill_keys($reachable, true);
    $lockdown = active_event_id($game) === 'lockdown';
    $playing = $game['status'] === 'playing';

    $schoolList = [];
    foreach ($schools as $sid => $school) {
        $schoolList[] = [
            'id' => $sid,
            'slot' => $school['slot'],
            'preset_id' => $school['preset_id'],
            'name' => $school['name'],
            'nickname' => $school['nickname'],
            'short_name' => $school['short_name'],
            'color' => $school['color'],
            'emblem' => $school['emblem'],
            'ace' => $school['ace'],
            'motto' => $school['motto'],
            'perk' => $school['perk'],
            'flaw' => $school['flaw'],
            'is_player' => (bool)$school['is_player'],
            'support' => round($school['support'], 1),
            'grievance' => round($school['grievance'], 1),
            'funds' => $school['funds'],
            'territories' => $counts[$sid] ?? 0,
            'income' => school_weekly_income($school, $districts),
            'attack' => effective_attack($school, $game),
            'defense' => effective_defense($school, $game),
            'perk_info' => $config['perks'][$school['perk']] ?? ['name' => $school['perk'], 'desc' => '', 'icon' => '?'],
            'flaw_info' => $config['flaws'][$school['flaw']] ?? ['name' => $school['flaw'], 'desc' => '', 'flavor' => ''],
            'tech' => ['red' => tech_level($school, 'red'), 'white' => tech_level($school, 'white'), 'black' => tech_level($school, 'black')],
            'tech_points' => $school['tech_points'],
            'ace_used' => (bool)$school['ace_used'],
            'ace_info' => ace_definition($school),
            'ult_red_cd' => $school['ult_red_cd'],
            'ult_white_until' => $school['ult_white_until'],
            'ult_black_cd' => $school['ult_black_cd'],
            'eliminated' => (bool)$school['eliminated'],
        ];
    }

    $districtList = [];
    foreach ($districts as $district) {
        $ownerId = $district['owner_id'];
        $influence = [];
        foreach ($district['influence'] as $sid => $value) {
            if ($value > 0.01 && isset($schools[$sid])) {
                $influence[(string)$sid] = round($value, 1);
            }
        }
        $ownerAlive = $ownerId !== null && isset($schools[$ownerId]) && !$schools[$ownerId]['eliminated'];
        $targetable = $ownerId === null || $ownerAlive;
        $ownerShielded = $ownerId !== null && isset($schools[$ownerId]) && school_shielded($schools[$ownerId], $game);
        $canReach = isset($reachableMap[$district['id']]);
        $districtList[] = [
            'id' => $district['id'],
            'slot_index' => $district['slot_index'],
            'name' => $district['name'],
            'kind' => $district['kind'],
            'x' => $district['x'],
            'y' => $district['y'],
            'w' => $district['w'],
            'h' => $district['h'],
            'income' => $district['income'],
            'base_defense' => $district['base_defense'],
            'fortify' => $district['fortify'],
            'owner_id' => $ownerId,
            'is_neutral' => (bool)$district['is_neutral'],
            'neighbors' => $district['neighbors'],
            'influence' => $influence,
            'disabled_until' => $district['disabled_until'],
            'captured_week' => $district['captured_week'],
            'can_reach' => $canReach,
            'can_infiltrate' => $playing && $ownerId !== (int)$player['id'] && $canReach && !$lockdown && $targetable && !$ownerShielded,
            'can_battle' => $playing && $ownerId !== null && $ownerId !== (int)$player['id'] && $canReach && !$lockdown && $ownerAlive,
            'can_campaign' => $playing && $ownerId !== null && $ownerId !== (int)$player['id'] && $ownerAlive,
        ];
    }

    $newsStmt = $db->prepare("SELECT week, text FROM logs WHERE game_id = ? AND category = 'news' ORDER BY id DESC LIMIT 24");
    $newsStmt->execute([$gameId]);
    $news = array_reverse($newsStmt->fetchAll());

    $logStmt = $db->prepare('SELECT week, category, text FROM logs WHERE game_id = ? ORDER BY id DESC LIMIT 50');
    $logStmt->execute([$gameId]);
    $logs = array_reverse($logStmt->fetchAll());

    $event = active_event($game);
    $end = null;
    if ($game['status'] !== 'playing') {
        $winner = $game['winner_school_id'] !== null && isset($schools[(int)$game['winner_school_id']])
            ? $schools[(int)$game['winner_school_id']] : null;
        $end = [
            'status' => $game['status'],
            'title' => $game['status'] === 'won' ? '吞并成功' : '淘汰出局',
            'victory_type' => $game['victory_type'],
            'winner' => $winner ? ['name' => $winner['name'], 'nickname' => $winner['nickname'], 'color' => $winner['color']] : null,
            'reason' => game_end_reason($game, $schools, $districts, $threshold),
        ];
    }

    return [
        'game' => [
            'id' => $game['id'],
            'week' => $game['week'],
            'max_week' => $config['max_week'],
            'status' => $game['status'],
            'ap' => $game['ap'],
            'max_ap' => max(player_max_ap($counts[(int)$player['id']] ?? 0), (int)$game['ap']),
            'player_school_id' => (int)$player['id'],
            'total_districts' => $totalDistricts,
            'threshold' => $threshold,
            'controlled' => $counts[(int)$player['id']] ?? 0,
            'income' => school_weekly_income($player, $districts),
            'active_event' => $event,
            'end' => $end,
            'created_at' => $game['created_at'],
            'updated_at' => $game['updated_at'],
        ],
        'player' => [
            'id' => (int)$player['id'],
            'name' => $player['name'],
            'nickname' => $player['nickname'],
            'short_name' => $player['short_name'],
            'color' => $player['color'],
            'emblem' => $player['emblem'],
            'perk' => $player['perk'],
            'flaw' => $player['flaw'],
            'support' => round($player['support'], 1),
            'grievance' => round($player['grievance'], 1),
            'funds' => $player['funds'],
            'tech_points' => $player['tech_points'],
            'perk_info' => $config['perks'][$player['perk']] ?? [],
            'flaw_info' => $config['flaws'][$player['flaw']] ?? [],
            'ace_info' => ace_definition($player),
            'ace_used' => (bool)$player['ace_used'],
            'shielded' => school_shielded($player, $game),
            'ult_red_cd' => $player['ult_red_cd'],
            'ult_white_until' => $player['ult_white_until'],
            'ult_black_cd' => $player['ult_black_cd'],
            'tech' => ['red' => tech_level($player, 'red'), 'white' => tech_level($player, 'white'), 'black' => tech_level($player, 'black')],
        ],
        'schools' => $schoolList,
        'districts' => $districtList,
        'techs' => tech_state($player, $config),
        'event' => $event,
        'news' => $news,
        'logs' => $logs,
        'reachable_ids' => $reachable,
    ];
}

function get_state(string $gameId): array
{
    return base_state($gameId);
}

function game_end_reason(array $game, array $schools, array $districts, int $threshold): string
{
    $playerId = (int)$game['player_school_id'];
    $player = $schools[$playerId] ?? null;
    if (!$player) {
        return '数据缺失。';
    }
    if ($game['status'] === 'won') {
        if ($game['victory_type'] === 'funding') {
            return '第 60 周结算，本校累计科研经费位列大学城第一。';
        }
        if ($game['victory_type'] === 'headcut') {
            return '剩余高校已全部丧失独立建制资格，本校自动胜出。';
        }
        return '本校控制区块超过大学城 60%，吞并计划完成。';
    }
    if ($game['victory_type'] === 'funding') {
        return '第 60 周结算，本校科研经费未能位列第一。';
    }
    if ($game['victory_type'] === 'headcut' || $player['support'] <= 0) {
        return '校长支持率归零，教育部宣布本校提前出局。';
    }
    if ($player['eliminated'] || count_territories($districts, $playerId) === 0) {
        return '本校最后一个校区被接管，独立建制撤销。';
    }
    return '大学城出现了新的霸主。';
}

/* ---------------- 核心结算 ---------------- */

function add_influence(PDO $db, array &$game, array &$schools, array &$districts, int $targetId, int $actorId, float $amount, string $cause): array
{
    if (!isset($districts[$targetId], $schools[$actorId])) {
        throw new GameError('目标区块不存在。');
    }
    $target = &$districts[$targetId];
    if ($target['owner_id'] === $actorId) {
        return ['amount' => 0, 'progress' => 100, 'captured' => false, 'blocked' => false, 'owner_before' => $actorId];
    }
    if ($target['owner_id'] !== null && isset($schools[$target['owner_id']])) {
        $owner = $schools[$target['owner_id']];
        if (school_shielded($owner, $game) && $target['owner_id'] !== $actorId) {
            return ['amount' => 0, 'progress' => 0, 'captured' => false, 'blocked' => true, 'owner_before' => $target['owner_id']];
        }
    }

    $actor = $schools[$actorId];
    $ownerBefore = $target['owner_id'];
    $amount = round(max(0, $amount), 1);
    $influence = $target['influence'];
    $current = (float)($influence[(string)$actorId] ?? $influence[$actorId] ?? 0);
    $newValue = min(100, $current + $amount);
    $influence[(string)$actorId] = $newValue;
    $target['influence'] = $influence;
    $captured = false;

    if ($newValue >= 100) {
        $capture = capture_district($db, $game, $schools, $districts, $targetId, $actorId, $cause);
        $captured = true;
        return [
            'amount' => $amount,
            'progress' => 100,
            'captured' => true,
            'blocked' => false,
            'owner_before' => $ownerBefore,
            'capture' => $capture,
        ];
    }

    save_district($db, $target);
    return ['amount' => $amount, 'progress' => round($newValue, 1), 'captured' => false, 'blocked' => false, 'owner_before' => $ownerBefore];
}

function capture_district(PDO $db, array &$game, array &$schools, array &$districts, int $targetId, int $actorId, string $cause): array
{
    $target = &$districts[$targetId];
    $actor = &$schools[$actorId];
    $previousOwner = $target['owner_id'];
    $targetName = $target['name'];

    $target['owner_id'] = $actorId;
    $target['influence'] = [(string)$actorId => 100.0];
    $target['fortify'] = 0.0;
    $target['disabled_until'] = 0;
    $target['captured_week'] = (int)$game['week'];
    save_district($db, $target);

    $actor['support'] = clamp($actor['support'] + 2, 0, 100);
    if ($actorId === (int)$game['player_school_id']) {
        $actor['tech_points'] += $previousOwner === null ? 1 : 2;
        $actor['funds'] += $previousOwner === null ? 10 : 25;
    }
    save_school($db, $actor);

    $previousSchoolName = $previousOwner !== null && isset($schools[$previousOwner]) ? $schools[$previousOwner]['nickname'] : '大学城管委会';
    add_log($db, $game['id'], (int)$game['week'], $previousOwner === null ? 'action' : 'battle',
        '【版图变更】' . $actor['nickname'] . '接管了' . $previousSchoolName . '的「' . $targetName . '」。');

    $supportDrop = null;
    $eliminated = null;
    if ($previousOwner !== null && isset($schools[$previousOwner])) {
        $prev = &$schools[$previousOwner];
        $prev['support'] = clamp($prev['support'] - 5, 0, 100);
        $prev['grievance'] = clamp($prev['grievance'] + 4, 0, 100);
        $supportDrop = 5;
        save_school($db, $prev);
        if ($prev['support'] <= 0 && !$prev['eliminated']) {
            $eliminated = eliminate_school($db, $game, $schools, $districts, (int)$prev['id'], $actorId);
        }
        unset($prev);
    }

    $newsText = $previousOwner === null
        ? '【大学城快讯】' . $actor['nickname'] . '宣布接管「' . $targetName . '」，现场学生表示：正好缺个自习位。'
        : '【战报】' . $actor['nickname'] . '的' . $cause . '突破「' . $targetName . '」防线，' . $previousSchoolName . '连夜召开紧急校务会。';
    add_log($db, $game['id'], (int)$game['week'], 'news', $newsText);

    return [
        'actor_id' => $actorId,
        'previous_owner_id' => $previousOwner,
        'previous_owner_name' => $previousSchoolName,
        'district_name' => $targetName,
        'support_drop' => $supportDrop,
        'eliminated' => $eliminated,
    ];
}

function eliminate_school(PDO $db, array &$game, array &$schools, array &$districts, int $schoolId, ?int $bySchoolId = null): array
{
    if (!isset($schools[$schoolId])) {
        return [];
    }
    $school = &$schools[$schoolId];
    if ($school['eliminated']) {
        return ['school_id' => $schoolId, 'already' => true];
    }
    $school['eliminated'] = 1;
    $school['support'] = 0;
    save_school($db, $school);

    // 校长下台后，剩余校区暂时收回大学城管委会。
    $vacated = [];
    foreach ($districts as &$district) {
        if ($district['owner_id'] === $schoolId) {
            $district['owner_id'] = null;
            $district['influence'] = [];
            $district['fortify'] = 0;
            $district['disabled_until'] = 0;
            save_district($db, $district);
            $vacated[] = $district['name'];
        }
    }
    unset($district);

    $isPlayer = (int)$school['is_player'] === 1;
    add_log($db, $game['id'], (int)$game['week'], 'news',
        '【突发】' . $school['name'] . '校长支持率归零，教育部宣布其提前出局' . ($vacated ? '，名下 ' . count($vacated) . ' 个区块暂时无主。' : '。'));
    if ($isPlayer) {
        $game['status'] = 'lost';
        $game['victory_type'] = 'headcut';
        $game['winner_school_id'] = $bySchoolId;
        $game['ended_at'] = now_string();
    }

    return ['school_id' => $schoolId, 'name' => $school['name'], 'vacated' => $vacated, 'is_player' => $isPlayer];
}

function determine_victory(PDO $db, array &$game, array &$schools, array &$districts): ?array
{
    if ($game['status'] !== 'playing') {
        return null;
    }
    $config = game_config();
    $playerId = (int)$game['player_school_id'];
    $player = $schools[$playerId] ?? null;
    if (!$player) {
        return null;
    }
    $total = count($districts);
    $threshold = (int)ceil($total * $config['victory_ratio']);
    $playerCount = count_territories($districts, $playerId);

    $activeSchools = [];
    foreach ($schools as $school) {
        if (!$school['eliminated'] && $school['support'] > 0) {
            $activeSchools[] = $school;
        }
    }

    $winnerId = null;
    $status = null;
    $type = null;

    if ($player['eliminated'] || $player['support'] <= 0 || $playerCount === 0) {
        $status = 'lost';
        $type = 'headcut';
    } elseif ($playerCount >= $threshold) {
        $status = 'won';
        $winnerId = $playerId;
        $type = 'annex';
    } elseif (count($activeSchools) === 1 && (int)$activeSchools[0]['id'] === $playerId) {
        $status = 'won';
        $winnerId = $playerId;
        $type = 'headcut';
    } else {
        foreach ($activeSchools as $school) {
            if ((int)$school['id'] === $playerId) {
                continue;
            }
            if (count_territories($districts, (int)$school['id']) >= $threshold) {
                $status = 'lost';
                $winnerId = (int)$school['id'];
                $type = 'annex';
                break;
            }
        }
    }

    if ((int)$game['week'] >= (int)$config['max_week'] && $status === null) {
        $best = $player;
        foreach ($activeSchools as $school) {
            if ((int)$school['id'] !== $playerId && $school['funds'] > $best['funds']) {
                $best = $school;
            }
        }
        $status = (int)$best['id'] === $playerId ? 'won' : 'lost';
        $winnerId = (int)$best['id'];
        $type = 'funding';
    }

    if ($status === null) {
        return null;
    }

    $game['status'] = $status;
    $game['winner_school_id'] = $winnerId;
    $game['victory_type'] = $type;
    $game['ended_at'] = now_string();
    $winner = $winnerId !== null && isset($schools[$winnerId]) ? $schools[$winnerId] : null;
    add_log($db, $game['id'], (int)$game['week'], 'news',
        '【终局】' . ($winner ? $winner['name'] . '获得“超一流大学”称号与无限经费。' : '大学城本赛季以悬念收场。'));

    return ['status' => $status, 'winner_id' => $winnerId, 'type' => $type];
}

/* ---------------- 玩家行动 ---------------- */

function perform_action(string $gameId, string $type, array $payload): array
{
    $db = db();
    $db->beginTransaction();
    try {
        $bundle = load_game_bundle($db, $gameId);
        $game = $bundle['game'];
        $schools = $bundle['schools'];
        $districts = $bundle['districts'];
        if ($game['status'] !== 'playing') {
            throw new GameError('本局已经结束，可以点击“再开一局”重新开始。');
        }
        $player = player_school($schools, $game);
        $playerId = (int)$player['id'];
        $config = game_config();

        switch ($type) {
            case 'infiltrate':
                $result = action_infiltrate($db, $game, $schools, $districts, $player, $payload);
                break;
            case 'battle':
                $result = action_battle($db, $game, $schools, $districts, $player, $payload);
                break;
            case 'campaign':
                $result = action_campaign($db, $game, $schools, $districts, $player, $payload);
                break;
            case 'fortify':
                $result = action_fortify($db, $game, $schools, $districts, $player, $payload);
                break;
            case 'stabilize':
                $result = action_stabilize($db, $game, $schools, $districts, $player);
                break;
            case 'ace':
                $result = action_ace($db, $game, $schools, $districts, $player);
                break;
            case 'tech_ult':
                $result = action_tech_ult($db, $game, $schools, $districts, $player, $payload);
                break;
            case 'tech_unlock':
                $result = action_tech_unlock($db, $game, $schools, $player, $payload, $config);
                break;
            default:
                throw new GameError('未知行动。');
        }

        determine_victory($db, $game, $schools, $districts);
        save_game($db, $game);
        $db->commit();
        $state = get_state($gameId);
        return ['state' => $state, 'result' => $result, 'ok' => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function consume_resources(array &$game, array &$player, int $ap, int $funds): void
{
    if ($game['ap'] < $ap) {
        throw new GameError('行政点不足。本周还剩 ' . $game['ap'] . ' 点。');
    }
    if ($player['funds'] < $funds) {
        throw new GameError('科研经费不足，需要 ' . $funds . ' 万。');
    }
    $game['ap'] -= $ap;
    $player['funds'] -= $funds;
}

function get_target(array $districts, array $payload): array
{
    $targetId = (int)($payload['district_id'] ?? 0);
    if (!isset($districts[$targetId])) {
        throw new GameError('请先在地图上选择一个区块。');
    }
    return [$targetId, $districts[$targetId]];
}

function action_infiltrate(PDO $db, array &$game, array &$schools, array &$districts, array &$player, array $payload): array
{
    [$targetId, $target] = get_target($districts, $payload);
    $playerId = (int)$player['id'];
    if ($target['owner_id'] === $playerId) {
        throw new GameError('这是本校区块，不用再渗透了。');
    }
    $range = $player['perk'] === 'ship' ? 2 : 1;
    $reachable = player_reachable_ids($districts, $player, $range);
    if (!in_array($targetId, $reachable, true)) {
        throw new GameError('目标不与本校控制区相邻。先拿下公共区域或向邻近区块推进。');
    }
    if (active_event_id($game) === 'lockdown') {
        throw new GameError('疫情封控期间，所有跨校渗透暂停。可以先维稳或研究校策。');
    }
    if ($target['owner_id'] !== null && isset($schools[$target['owner_id']]) && school_shielded($schools[$target['owner_id']], $game)) {
        throw new GameError($schools[$target['owner_id']]['nickname'] . '已启动封校结界，渗透被挡在校门外。');
    }
    consume_resources($game, $player, 1, 10);
    $schools[$playerId] = $player;

    $ownerSchool = $target['owner_id'] !== null ? ($schools[$target['owner_id']] ?? null) : null;
    $mult = 1.0;
    if (tech_level($player, 'red') >= 1) {
        $mult += 0.15;
    }
    if (tech_level($player, 'black') >= 2) {
        $mult += 0.10;
    }
    if ($player['perk'] === 'ship') {
        $mult += 0.08;
    }
    if ($target['disabled_until'] >= (int)$game['week']) {
        $mult += 0.20;
    }
    $gain = 21.0 * $mult;
    $gain -= $target['fortify'] * 0.35;
    if ($ownerSchool) {
        $gain -= tech_level($ownerSchool, 'white') * 2.0;
        if (tech_level($ownerSchool, 'white') >= 1) {
            $gain *= 0.90;
        }
    }
    if (active_event_id($game) === 'inspection') {
        $gain *= 0.60;
    }
    $gain = max(7.0, round($gain, 1));

    $inf = add_influence($db, $game, $schools, $districts, $targetId, $playerId, $gain, '学术交流');
    $player = $schools[$playerId];
    save_school($db, $player);
    save_game($db, $game);

    $lines = [
        '执行单位：' . $player['nickname'],
        '投放项目：「' . $player['ace'] . '」前沿讲座与联合培养名额',
        '渗透成果：+' . $inf['amount'] . '%（当前 ' . $inf['progress'] . '%）',
        '经费支出：10 万 · 行政点 1',
    ];
    if ($inf['captured']) {
        $lines[] = '【重大进展】「' . $target['name'] . '」已正式并入本校势力范围。';
        add_log($db, $game['id'], (int)$game['week'], 'action', '【行动】' . $player['nickname'] . '渗透并接管「' . $target['name'] . '」。');
    } else {
        $lines[] = '下一步建议：继续加码，或转投相邻区块形成包围。';
        add_log($db, $game['id'], (int)$game['week'], 'action', '【行动】' . $player['nickname'] . '向「' . $target['name'] . '」渗透 +' . $inf['amount'] . '%。');
    }
    if ($inf['owner_before'] !== null && isset($schools[$inf['owner_before']])) {
        $lines[] = '对方警报：' . $schools[$inf['owner_before']]['nickname'] . '已将该区块列为“重点关注对象”。';
    }

    return [
        'kind' => $inf['captured'] ? 'capture' : 'infiltrate',
        'title' => '关于向「' . $target['name'] . '」投放学术资源的批复',
        'lines' => $lines,
        'stamp' => $inf['captured'] ? '已接管' : '已备案',
        'notice' => $inf['captured'] ? '版图已更新' : '渗透进度已更新',
        'captured' => $inf['captured'],
    ];
}

function action_battle(PDO $db, array &$game, array &$schools, array &$districts, array &$player, array $payload): array
{
    [$targetId, $target] = get_target($districts, $payload);
    $playerId = (int)$player['id'];
    if ($target['owner_id'] === null) {
        throw new GameError('公共区域不参与学科评估，请直接渗透。');
    }
    if ($target['owner_id'] === $playerId) {
        throw new GameError('不能对自家学院发动学科评估。');
    }
    if (active_event_id($game) === 'lockdown') {
        throw new GameError('疫情封控期间，跨校学科评估战暂停。');
    }
    $reachable = player_reachable_ids($districts, $player, 1);
    if (!in_array($targetId, $reachable, true)) {
        throw new GameError('学科评估战只能对相邻高校发动。');
    }
    $enemy = $schools[$target['owner_id']] ?? null;
    if (!$enemy || $enemy['eliminated']) {
        throw new GameError('目标高校已经出局。');
    }
    consume_resources($game, $player, 2, 45);
    $schools[$playerId] = $player;

    $attack = effective_attack($player, $game) * 1.10 + random_int(-6, 10);
    $defense = district_defense_power($target, $enemy, $game) + random_int(-4, 6);
    $win = $attack >= $defense;
    $battleLines = [
        '我方战力：' . round($attack, 1) . '（师资 · 生源 · 经费）',
        '对方防线：' . round($defense, 1) . '（含城防 ' . round($target['fortify'], 0) . '）',
    ];

    if ($win) {
        $dmg = 30 + tech_level($player, 'red') * 3;
        $districts[$targetId]['disabled_until'] = max($districts[$targetId]['disabled_until'], (int)$game['week'] + 2);
        save_district($db, $districts[$targetId]);
        $inf = add_influence($db, $game, $schools, $districts, $targetId, $playerId, $dmg, '学科评估战');
        $player = $schools[$playerId];
        $enemy = $schools[$enemy['id']];
        $enemy['support'] = clamp($enemy['support'] - 6, 0, 100);
        $enemy['grievance'] = clamp($enemy['grievance'] + 5, 0, 100);
        save_school($db, $enemy);
        if ($enemy['support'] <= 0 && !$enemy['eliminated']) {
            eliminate_school($db, $game, $schools, $districts, (int)$enemy['id'], $playerId);
        }
        $player['funds'] += 12;
        $player['support'] = clamp($player['support'] + 1, 0, 100);
        save_school($db, $player);
        save_game($db, $game);
        $battleLines[] = '结果：本校胜出。对方「' . $target['name'] . '」瘫痪 2 周，渗透 +' . $inf['amount'] . '%。';
        $battleLines[] = '战利品：科研经费 +12 万，敌方支持率 -6。';
        if ($inf['captured']) {
            $battleLines[] = '【重大进展】学科评估战直接打穿了对方防线，该区块已被接管。';
        }
        add_log($db, $game['id'], (int)$game['week'], 'battle', '【学科评估战】' . $player['nickname'] . '击败' . $enemy['nickname'] . '，攻占「' . $target['name'] . '」防线。');
        add_log($db, $game['id'], (int)$game['week'], 'news', '【学术圈】' . $player['nickname'] . '在「' . $target['name'] . '」学科评估中以微弱优势胜出，评审专家集体沉默。');
        $outcome = 'win';
    } else {
        $player['support'] = clamp($player['support'] - 3, 0, 100);
        $player['grievance'] = clamp($player['grievance'] + 3, 0, 100);
        $target['fortify'] = min(60, $target['fortify'] + 4);
        save_school($db, $player);
        save_district($db, $target);
        save_game($db, $game);
        $battleLines[] = '结果：本校惜败。对方加强了城防（+' . round($target['fortify'], 0) . '），本校支持率 -4。';
        $battleLines[] = '复盘意见：红专路线等级不足，或需要先削弱对方城防。';
        add_log($db, $game['id'], (int)$game['week'], 'battle', '【学科评估战】' . $player['nickname'] . '不敌' . $enemy['nickname'] . '，评审组建议“回去再改改本子”。');
        $outcome = 'lose';
    }

    return [
        'kind' => 'battle',
        'title' => '关于对「' . $target['name'] . '」发动学科评估战的批复',
        'lines' => $battleLines,
        'battle' => [
            'outcome' => $outcome,
            'attack' => round($attack, 1),
            'defense' => round($defense, 1),
            'attacker' => $player['nickname'],
            'defender' => $enemy['nickname'],
        ],
        'stamp' => $outcome === 'win' ? '胜' : '败',
        'notice' => $outcome === 'win' ? '学科评估战胜利' : '学科评估战失利',
    ];
}

function action_campaign(PDO $db, array &$game, array &$schools, array &$districts, array &$player, array $payload): array
{
    [$targetId, $target] = get_target($districts, $payload);
    $playerId = (int)$player['id'];
    if ($target['owner_id'] === null || $target['owner_id'] === $playerId) {
        throw new GameError('舆论战只能针对其他高校的区块。');
    }
    $enemy = $schools[$target['owner_id']] ?? null;
    if (!$enemy || $enemy['eliminated']) {
        throw new GameError('目标高校已经出局。');
    }
    consume_resources($game, $player, 2, 25);
    $schools[$playerId] = $player;

    $mult = 1.0;
    if ($player['perk'] === 'debate') {
        $mult += 0.30;
    }
    if ($player['flaw'] === 'humanities') {
        $mult -= 0.30;
    }
    if (tech_level($player, 'black') >= 1) {
        $mult += 0.20;
    }
    if (tech_level($player, 'black') >= 2) {
        $mult += 0.10;
    }
    if (tech_level($player, 'black') >= 3) {
        $mult += 0.20;
    }
    if (tech_level($player, 'red') >= 4) {
        $mult += 0.10;
    }
    if (active_event_id($game) === 'inspection') {
        $mult *= 0.80;
    }
    $damage = (int)round((7 + random_int(0, 3)) * max(0.3, $mult));
    $enemyId = (int)$enemy['id'];
    $enemy['support'] = clamp($enemy['support'] - $damage, 0, 100);
    $enemy['grievance'] = clamp($enemy['grievance'] + 3, 0, 100);
    save_school($db, $enemy);
    $schools[$enemyId] = $enemy;
    $inf = add_influence($db, $game, $schools, $districts, $targetId, $playerId, 8.0, '舆论战');
    $player = $schools[$playerId];
    $enemy = $schools[$enemyId] ?? $enemy;
    save_school($db, $player);
    save_game($db, $game);

    $lines = [
        '打击对象：' . $enemy['nickname'] . ' · 「' . $target['name'] . '」',
        '投放素材：' . $enemy['nickname'] . '历年绰号合集、食堂刺客测评、早八出勤率曲线',
        '舆论伤害：支持率 -' . $damage . '（当前 ' . round($enemy['support'], 1) . '%）',
        '附带效果：渗透 +' . $inf['amount'] . '% · 对方怨气 +3',
        '经费支出：25 万 · 行政点 2',
    ];
    if ($enemy['support'] <= 0 && !$enemy['eliminated']) {
        eliminate_school($db, $game, $schools, $districts, $enemyId, $playerId);
        $lines[] = '【斩首成功】' . $enemy['name'] . '校长支持率归零，学校提前出局。';
        add_log($db, $game['id'], (int)$game['week'], 'news', '【大结局？】' . $player['nickname'] . '的舆论攻势让' . $enemy['nickname'] . '校长支持率归零，校内论坛瞬间被“赢”刷屏。');
    } else {
        add_log($db, $game['id'], (int)$game['week'], 'action', '【舆论战】' . $player['nickname'] . '对' . $enemy['nickname'] . '发动舆论战，支持率 -' . $damage . '。');
        add_log($db, $game['id'], (int)$game['week'], 'news', '【热搜】#' . $enemy['nickname'] . '早八现状# 冲上同城榜，评论区已沦陷。');
    }

    return [
        'kind' => 'campaign',
        'title' => '关于对「' . $enemy['nickname'] . '」启动舆论攻势的批复',
        'lines' => $lines,
        'stamp' => '已发布',
        'notice' => '舆论战已投放',
    ];
}

function action_fortify(PDO $db, array &$game, array &$schools, array &$districts, array &$player, array $payload): array
{
    [$targetId, $target] = get_target($districts, $payload);
    $playerId = (int)$player['id'];
    if ($target['owner_id'] !== $playerId) {
        throw new GameError('只能加固本校控制的区块。');
    }
    $cost = $player['perk'] === 'infra' ? 11 : 15;
    consume_resources($game, $player, 1, $cost);
    $add = 12 + tech_level($player, 'white') * 2;
    $before = $target['fortify'];
    $target['fortify'] = min(60, $target['fortify'] + $add);
    save_district($db, $target);
    save_school($db, $player);
    add_log($db, $game['id'], (int)$game['week'], 'action', '【加固】' . $player['nickname'] . '加固「' . $target['name'] . '」，城防 ' . round($before) . ' → ' . round($target['fortify']) . '。');

    return [
        'kind' => 'fortify',
        'title' => '关于加固「' . $target['name'] . '」校园防线的批复',
        'lines' => [
            '施工内容：门禁升级、围墙加高、外卖柜外移',
            '城防变化：' . round($before) . ' → ' . round($target['fortify']) . '（上限 60）',
            '经费支出：' . $cost . ' 万 · 行政点 1',
            '施工队留言：只要不查消防，三天就能封顶。',
        ],
        'stamp' => '已开工',
        'notice' => '校区城防提升',
    ];
}

function action_stabilize(PDO $db, array &$game, array &$schools, array &$districts, array &$player): array
{
    $cost = $player['perk'] === 'medic' ? 20 : 25;
    consume_resources($game, $player, 1, $cost);
    $grievanceBefore = $player['grievance'];
    $supportBefore = $player['support'];
    $reduce = $player['perk'] === 'medic' ? 27 : 18;
    $supportGain = $player['perk'] === 'medic' ? 6 : 4;
    $player['grievance'] = clamp($player['grievance'] - $reduce, 0, 100);
    $player['support'] = clamp($player['support'] + $supportGain, 0, 100);
    save_school($db, $player);
    save_game($db, $game);
    add_log($db, $game['id'], (int)$game['week'], 'action', '【维稳】' . $player['nickname'] . '宣布食堂半价并取消本周早操，怨气下降。');
    add_log($db, $game['id'], (int)$game['week'], 'news', '【校园通知】' . $player['nickname'] . '食堂糖醋里脊恢复供应，学生代表表示“可以再爱学校一次”。');

    return [
        'kind' => 'stabilize',
        'title' => '关于开展「食堂半价 · 取消早操」维稳行动的批复',
        'lines' => [
            '政策组合：食堂半价 + 取消本周早操 + 宿舍延迟熄灯 30 分钟',
            '怨气变化：' . round($grievanceBefore, 1) . ' → ' . round($player['grievance'], 1),
            '支持率变化：' . round($supportBefore, 1) . ' → ' . round($player['support'], 1),
            '经费支出：' . $cost . ' 万 · 行政点 1',
            '学工处提醒：请勿在通知中写“永久”。',
        ],
        'stamp' => '已安抚',
        'notice' => '校内怨气下降',
    ];
}

function action_tech_unlock(PDO $db, array &$game, array &$schools, array &$player, array $payload, array $config): array
{
    $branch = (string)($payload['branch'] ?? '');
    if (!isset($config['branches'][$branch])) {
        throw new GameError('未知的校策路线。');
    }
    $level = tech_level($player, $branch);
    if ($level >= 4) {
        throw new GameError('该路线已经点满。');
    }
    $node = $config['branches'][$branch]['nodes'][$level];
    if ($player['tech_points'] < $node['cost']) {
        throw new GameError('校策点不足，需要 ' . $node['cost'] . ' 点。');
    }
    $player['tech_points'] -= $node['cost'];
    $player['tech_' . $branch] = $level + 1;
    save_school($db, $player);
    save_game($db, $game);
    add_log($db, $game['id'], (int)$game['week'], 'action', '【校策】' . $player['nickname'] . '解锁「' . $node['name'] . '」。');
    add_log($db, $game['id'], (int)$game['week'], 'news', '【学术动态】' . $player['nickname'] . '宣布推进「' . $node['name'] . '」，兄弟院校表示密切关注。');

    return [
        'kind' => 'tech',
        'title' => '关于批准「' . $node['name'] . '」立项的批复',
        'lines' => [
            '所属路线：' . $config['branches'][$branch]['name'],
            '节点效果：' . $node['desc'],
            '项目批注：' . $node['flavor'],
            '消耗校策点：' . $node['cost'],
        ],
        'stamp' => '已立项',
        'notice' => '校策已解锁',
    ];
}

function action_ace(PDO $db, array &$game, array &$schools, array &$districts, array &$player): array
{
    if ($player['ace_used']) {
        throw new GameError('本校王牌已经用过了。');
    }
    $playerId = (int)$player['id'];
    $ace = ace_definition($player);
    $preset = (string)($player['preset_id'] ?? '');
    $lines = ['王牌名称：「' . $ace['name'] . '」', '效果说明：' . $ace['desc']];
    $player['ace_used'] = 1;

    $influenceTargets = [];
    switch ($preset) {
        case 'tsinghua':
            foreach ($districts as &$district) {
                if ($district['owner_id'] === $playerId) {
                    $district['fortify'] = min(60, $district['fortify'] + 10);
                    save_district($db, $district);
                }
            }
            unset($district);
            $player['support'] = clamp($player['support'] + 4, 0, 100);
            $lines[] = '执行结果：所有自有校区城防 +10，支持率 +4。';
            break;
        case 'pku':
            foreach ($schools as &$school) {
                if ((int)$school['id'] !== $playerId && !$school['eliminated']) {
                    $school['support'] = clamp($school['support'] - 5, 0, 100);
                    save_school($db, $school);
                }
            }
            unset($school);
            $influenceTargets = player_reachable_ids($districts, $player, 1);
            $lines[] = '执行结果：全体对手支持率 -5，对可达区域发动学术奇袭。';
            break;
        case 'fudan':
            $player['support'] = clamp($player['support'] + 12, 0, 100);
            $player['grievance'] = clamp($player['grievance'] - 25, 0, 100);
            $lines[] = '执行结果：支持率 +12，怨气 -25。';
            break;
        case 'sjtu':
            $game['ap'] += 3;
            $influenceTargets = player_reachable_ids($districts, $player, 1);
            $lines[] = '执行结果：行政点 +3，船海工程队向邻近区域输送学术资源。';
            break;
        case 'zju':
            $player['funds'] += 100;
            $player['support'] = clamp($player['support'] + 6, 0, 100);
            $lines[] = '执行结果：经费 +100 万，支持率 +6。';
            break;
        case 'ustc':
            $player['tech_points'] += 3;
            $influenceTargets = player_reachable_ids($districts, $player, 1);
            $lines[] = '执行结果：校策点 +3，少年班突击队完成一次学术空降。';
            break;
        case 'whu':
            $player['funds'] += 80;
            $player['support'] = clamp($player['support'] + 7, 0, 100);
            $player['grievance'] = clamp($player['grievance'] - 8, 0, 100);
            $lines[] = '执行结果：经费 +80 万，支持率 +7，怨气 -8。';
            break;
        default:
            $game['ap'] += 2;
            $player['funds'] += 60;
            $player['support'] = clamp($player['support'] + 5, 0, 100);
            $lines[] = '执行结果：行政点 +2，经费 +60 万，支持率 +5。';
    }

    save_school($db, $player);
    if (!empty($influenceTargets)) {
        $amount = in_array($preset, ['pku', 'ustc', 'sjtu'], true) ? 10.0 : 12.0;
        foreach ($influenceTargets as $tid) {
            if (!isset($districts[$tid]) || $districts[$tid]['owner_id'] === $playerId) {
                continue;
            }
            add_influence($db, $game, $schools, $districts, $tid, $playerId, $amount, '王牌行动');
        }
        $player = $schools[$playerId];
        save_school($db, $player);
        $lines[] = '波及区域：' . count($influenceTargets) . ' 个邻近区块获得额外渗透。';
    }
    add_log($db, $game['id'], (int)$game['week'], 'action', '【王牌】' . $player['nickname'] . '启用「' . $ace['name'] . '」。');
    add_log($db, $game['id'], (int)$game['week'], 'news', '【大事件】' . $player['nickname'] . '祭出王牌「' . $ace['name'] . '」，大学城上空出现不明学术云团。');

    // 王牌也可能直接击穿对手支持率。
    foreach ($schools as $sid => $school) {
        if ($sid !== $playerId && !$school['eliminated'] && $school['support'] <= 0) {
            eliminate_school($db, $game, $schools, $districts, $sid, $playerId);
        }
    }
    save_game($db, $game);

    return [
        'kind' => 'ace',
        'title' => '关于启用王牌「' . $ace['name'] . '」的批复',
        'lines' => $lines,
        'stamp' => '王牌',
        'notice' => '王牌已启用',
    ];
}

function action_tech_ult(PDO $db, array &$game, array &$schools, array &$districts, array &$player, array $payload): array
{
    $branch = (string)($payload['branch'] ?? '');
    $playerId = (int)$player['id'];
    if (!in_array($branch, ['red', 'white', 'black'], true) || tech_level($player, $branch) < 4) {
        throw new GameError('该杀招尚未解锁。');
    }
    if (active_event_id($game) === 'inspection' && $branch === 'red') {
        throw new GameError('教育部突击检查期间，QS 排名注水术暂停使用。');
    }

    $lines = [];
    if ($branch === 'red') {
        if ($player['ult_red_cd'] > (int)$game['week']) {
            throw new GameError('QS 注水术冷却中，还要等 ' . ($player['ult_red_cd'] - (int)$game['week']) . ' 周。');
        }
        $player['ult_red_cd'] = (int)$game['week'] + 4;
        foreach ($schools as &$school) {
            if ((int)$school['id'] !== $playerId && !$school['eliminated']) {
                $school['support'] = clamp($school['support'] - 7, 0, 100);
                $school['grievance'] = clamp($school['grievance'] + 4, 0, 100);
                save_school($db, $school);
            }
        }
        unset($school);
        $lines = ['杀招：QS 排名注水术', '执行方式：向四大排行榜同时提交 23 份“国际化特色材料”。', '效果：全体对手支持率 -7，怨气 +4。', '冷却：4 周'];
        $title = '关于启动「QS 排名注水术」的批复';
        $news = '【国际排名】某高校国际排名一夜上升 47 位，评审委员会表示“材料非常厚”。';
    } elseif ($branch === 'black') {
        if ($player['ult_black_cd'] > (int)$game['week']) {
            throw new GameError('绰号刷屏冷却中，还要等 ' . ($player['ult_black_cd'] - (int)$game['week']) . ' 周。');
        }
        $player['ult_black_cd'] = (int)$game['week'] + 5;
        foreach ($schools as &$school) {
            if ((int)$school['id'] !== $playerId && !$school['eliminated']) {
                $school['support'] = clamp($school['support'] - 6, 0, 100);
                $school['grievance'] = clamp($school['grievance'] + 18, 0, 100);
                save_school($db, $school);
            }
        }
        unset($school);
        $lines = ['杀招：跨校绰号刷屏', '执行方式：买下同城热搜前二十，统一使用对方民间绰号。', '效果：全体对手怨气 +18，支持率 -6。', '冷却：5 周'];
        $title = '关于实施「跨校绰号刷屏」的批复';
        $news = '【同城热搜】前二十名被高校绰号占据，网友表示“今天大学城团建吗”。';
    } else {
        if (school_shielded($player, $game)) {
            throw new GameError('封校结界仍在生效。');
        }
        if ($player['ult_white_until'] > 0 && (int)$game['week'] < $player['ult_white_until'] + 4) {
            throw new GameError('封校结界冷却中。');
        }
        $player['ult_white_until'] = (int)$game['week'] + 2;
        $lines = ['杀招：封校结界', '执行方式：闸机全部升级为人脸识别，外卖只送到三公里外。', '效果：2 周内免疫敌方渗透与夺区。', '冷却：6 周'];
        $title = '关于启动「封校结界」的批复';
        $news = '【校园通知】即日起校外人员不得入内，连校长忘带卡也要登记。';
    }

    // 被舆论击穿支持率的学校立即出局。
    foreach ($schools as $sid => $school) {
        if ($sid !== $playerId && !$school['eliminated'] && $school['support'] <= 0) {
            eliminate_school($db, $game, $schools, $districts, $sid, $playerId);
        }
    }

    save_school($db, $player);
    save_game($db, $game);
    add_log($db, $game['id'], (int)$game['week'], 'action', '【杀招】' . $player['nickname'] . '实施「' . $title . '」。');
    add_log($db, $game['id'], (int)$game['week'], 'news', $news);

    return [
        'kind' => 'tech_ult',
        'title' => $title,
        'lines' => $lines,
        'stamp' => '已执行',
        'notice' => '终极杀招已执行',
    ];
}

/* ---------------- 周结算与 AI ---------------- */

function end_turn(string $gameId): array
{
    $db = db();
    $db->beginTransaction();
    try {
        $bundle = load_game_bundle($db, $gameId);
        $game = $bundle['game'];
        $schools = $bundle['schools'];
        $districts = $bundle['districts'];
        if ($game['status'] !== 'playing') {
            throw new GameError('本局已经结束。');
        }
        $config = game_config();
        $player = player_school($schools, $game);
        $playerId = (int)$player['id'];
        $oldWeek = (int)$game['week'];
        $newWeek = $oldWeek + 1;
        $game['week'] = $newWeek;

        $summary = [
            'week' => $newWeek,
            'income' => 0,
            'support_before' => round($player['support'], 1),
            'grievance_before' => round($player['grievance'], 1),
            'ai_actions' => [],
            'events' => [],
            'battles' => [],
            'captures' => [],
            'protest' => null,
            'headline' => '大学城周报',
            'event' => null,
            'status' => 'playing',
        ];

        // 1. 收入与校内状态。
        $playerIncome = 0;
        foreach ($schools as &$school) {
            if ($school['eliminated']) {
                continue;
            }
            $income = school_weekly_income($school, $districts);
            $school['funds'] += $income;
            if ((int)$school['id'] === $playerId) {
                $playerIncome = $income;
            }

            $grievanceGain = 1.0;
            if ($school['flaw'] === 'ratio') {
                $grievanceGain += 1.5;
            } elseif ($school['flaw'] === 'vacation') {
                $grievanceGain += 2.0;
            } elseif ($school['flaw'] === 'tourists') {
                $grievanceGain += 1.0;
            }
            if ($school['perk'] === 'giant') {
                $grievanceGain += 1.0;
            }
            if (tech_level($school, 'white') >= 2) {
                $grievanceGain -= 2.0;
            }
            if (active_event_id($game) === 'lockdown') {
                $grievanceGain *= 2.0;
            }
            $school['grievance'] = clamp($school['grievance'] + max(0.0, $grievanceGain), 0, 100);

            $supportGain = 1.0;
            if ($school['grievance'] > 70) {
                $supportGain = -1.2;
            }
            if ($school['perk'] === 'medic') {
                $supportGain += 1.0;
            }
            if (tech_level($school, 'white') >= 3) {
                $supportGain += 1.0;
            }
            if ($school['perk'] === 'scenic' && public_territories($districts, (int)$school['id']) > 0) {
                $supportGain += public_territories($districts, (int)$school['id']);
            }
            $school['support'] = clamp($school['support'] + $supportGain, 0, 100);
            save_school($db, $school);
        }
        unset($school);
        $summary['income'] = $playerIncome;

        // 2. 校内游行：怨气爆表。
        foreach ($schools as &$school) {
            if ($school['eliminated']) {
                continue;
            }
            if ($school['grievance'] >= 80 && ($school['grievance'] >= 92 || random_int(1, 100) <= 45)) {
                $school['funds'] = max(0, $school['funds'] - 30);
                $school['support'] = clamp($school['support'] - 4, 0, 100);
                $school['grievance'] = clamp($school['grievance'] - 12, 0, 100);
                save_school($db, $school);
                $isPlayer = (int)$school['id'] === $playerId;
                $line = '【校内游行】' . $school['nickname'] . '学生因课程过密发起“还我周末”行动，经费 -30 万，支持率 -4。';
                add_log($db, $game['id'], $newWeek, 'news', $line);
                if ($isPlayer) {
                    $summary['protest'] = $line;
                }
                if ($school['support'] <= 0 && !$school['eliminated']) {
                    eliminate_school($db, $game, $schools, $districts, (int)$school['id']);
                }
            }
        }
        unset($school);

        // 3. AI 回合。
        run_ai_turns($db, $game, $schools, $districts, $summary);

        // 4. 死亡判定与支援率归零。
        foreach ($schools as &$school) {
            if (!$school['eliminated'] && $school['support'] <= 0) {
                eliminate_school($db, $game, $schools, $districts, (int)$school['id']);
            }
        }
        unset($school);

        // 5. 随机事件。
        maybe_trigger_event($db, $game, $schools, $districts, $summary);

        // 6. 行政点与校策点。
        $player = $schools[$playerId];
        $playerCount = count_territories($districts, $playerId);
        $maxAp = player_max_ap($playerCount);
        if ($summary['protest']) {
            $maxAp = max(2, $maxAp - 1);
        }
        $game['ap'] = $maxAp;
        $player['tech_points'] += 2 + ($player['perk'] === 'scientist' ? 1 : 0);
        save_school($db, $player);
        $game['summary_json'] = json_encode($summary, JSON_UNESCAPED_UNICODE);

        // 7. AI 校策成长。
        foreach ($schools as &$school) {
            if ($school['is_player'] || $school['eliminated']) {
                continue;
            }
            if ($newWeek % 3 === 0) {
                $branch = match ($school['personality']) {
                    'aggressive' => 'red',
                    'builder' => 'white',
                    'media' => 'black',
                    'tech' => 'red',
                    'steady' => 'white',
                    default => ['red', 'white', 'black'][random_int(0, 2)],
                };
                if (tech_level($school, $branch) < 4) {
                    $school['tech_' . $branch] = tech_level($school, $branch) + 1;
                    save_school($db, $school);
                }
            }
        }
        unset($school);

        $summary['support_after'] = round($schools[$playerId]['support'], 1);
        $summary['grievance_after'] = round($schools[$playerId]['grievance'], 1);
        $summary['event'] = active_event($game);
        $summary['headline'] = $summary['event']['name'] ?? ($newWeek >= $config['max_week'] ? '经费终局结算' : '大学城周报');

        determine_victory($db, $game, $schools, $districts);
        $summary['status'] = $game['status'];
        $game['summary_json'] = json_encode($summary, JSON_UNESCAPED_UNICODE);
        save_game($db, $game);
        $db->commit();

        return [
            'ok' => true,
            'state' => get_state($gameId),
            'summary' => $summary,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function run_ai_turns(PDO $db, array &$game, array &$schools, array &$districts, array &$summary): void
{
    $playerId = (int)$game['player_school_id'];
    foreach ($schools as &$ai) {
        if ($ai['is_player'] || $ai['eliminated'] || $game['status'] !== 'playing') {
            continue;
        }
        $aiId = (int)$ai['id'];
        $actionsLeft = (int)$game['week'] <= 8 ? 1 : 2;
        while ($actionsLeft > 0 && $game['status'] === 'playing' && !$ai['eliminated']) {
            $ai = $schools[$aiId];
            if ($ai['funds'] < 12) {
                break;
            }

            // 怨气过高，先发食堂券。
            if (($ai['grievance'] >= 66 || $ai['support'] <= 42) && $ai['funds'] >= 28) {
                $ai['funds'] -= 28;
                $ai['grievance'] = clamp($ai['grievance'] - 18, 0, 100);
                $ai['support'] = clamp($ai['support'] + 2, 0, 100);
                save_school($db, $ai);
                $text = $ai['nickname'] . '发放食堂五折券，校内怨气暂时回落。';
                $summary['ai_actions'][] = ['school_id' => $aiId, 'school' => $ai['nickname'], 'color' => $ai['color'], 'text' => $text];
                add_log($db, $game['id'], (int)$game['week'], 'ai', '【' . $ai['nickname'] . '】' . $text);
                $actionsLeft--;
                continue;
            }

            $targetSlots = all_adjacent_targets($districts, $aiId);
            if (!$targetSlots) {
                if ($ai['funds'] >= 15) {
                    $ownDistricts = array_filter($districts, fn($d) => $d['owner_id'] === $aiId);
                    if ($ownDistricts) {
                        $one = $ownDistricts[array_rand($ownDistricts)];
                        $one['fortify'] = min(60, $one['fortify'] + 10);
                        save_district($db, $one);
                        $ai['funds'] -= 15;
                        save_school($db, $ai);
                        $summary['ai_actions'][] = ['school_id' => $aiId, 'school' => $ai['nickname'], 'color' => $ai['color'], 'text' => '加固了「' . $one['name'] . '」的城防。'];
                    }
                }
                break;
            }

            $lockdown = active_event_id($game) === 'lockdown';
            $target = null;
            $bestScore = -PHP_INT_MAX;
            foreach ($targetSlots as $targetId) {
                if (!isset($districts[$targetId])) {
                    continue;
                }
                $candidate = $districts[$targetId];
                if ($candidate['owner_id'] === $aiId) {
                    continue;
                }
                $score = 0;
                if ($candidate['owner_id'] === null) {
                    $score += (int)$game['week'] <= 10 ? 10 : 3;
                } elseif ($candidate['owner_id'] === $playerId) {
                    $score += (int)$game['week'] <= 8 ? 2 : (in_array($ai['personality'], ['aggressive', 'media'], true) ? 7 : 5);
                } else {
                    $score += 4;
                }
                $influence = (float)($candidate['influence'][(string)$aiId] ?? 0);
                $score += $influence * 0.25;
                $score += max(0, 20 - $candidate['fortify']) * 0.2;
                $score += random_int(0, 4);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $target = $candidate;
                }
            }
            if ($target === null) {
                break;
            }

            // 偶尔发动学科评估战。
            if (!$lockdown && $target['owner_id'] !== null && $ai['funds'] >= 48 && random_int(1, 100) <= 20) {
                $defender = $schools[$target['owner_id']] ?? null;
                if ($defender && !$defender['eliminated']) {
                    $attack = effective_attack($ai, $game) * 1.05 + random_int(-6, 8);
                    $defense = district_defense_power($target, $defender, $game) + random_int(-4, 6);
                    $ai['funds'] -= 48;
                    if ($attack >= $defense) {
                        $districts[$target['id']]['disabled_until'] = (int)$game['week'] + 2;
                        $districts[$target['id']]['fortify'] = max(0, $districts[$target['id']]['fortify'] - 3);
                        save_district($db, $districts[$target['id']]);
                        $inf = add_influence($db, $game, $schools, $districts, (int)$target['id'], $aiId, 26.0, '学科评估战');
                        $defender = $schools[$defender['id']];
                        $defender['support'] = clamp($defender['support'] - 5, 0, 100);
                        save_school($db, $defender);
                        $schools[(int)$defender['id']] = $defender;
                        $ai = $schools[$aiId];
                        $summary['battles'][] = ['school_id' => $aiId, 'school' => $ai['nickname'], 'color' => $ai['color'], 'text' => '在学科评估战中击败' . $defender['nickname'] . '，目标区块瘫痪。'];
                        if ($inf['captured']) {
                            $summary['captures'][] = ['school_id' => $aiId, 'school' => $ai['nickname'], 'color' => $ai['color'], 'text' => '接管了「' . $target['name'] . '」。'];
                        }
                    } else {
                        $ai['support'] = clamp($ai['support'] - 3, 0, 100);
                        $target['fortify'] = min(60, $target['fortify'] + 3);
                        save_district($db, $target);
                        save_school($db, $ai);
                        $summary['battles'][] = ['school_id' => $aiId, 'school' => $ai['nickname'], 'color' => $ai['color'], 'text' => '对' . ($defender['nickname'] ?? '邻校') . '发动学科评估战，惜败。'];
                    }
                    save_school($db, $ai);
                    $actionsLeft--;
                    continue;
                }
            }

            // 低概率舆论战。
            if (!$lockdown && $target['owner_id'] === $playerId && $ai['funds'] >= 25 && random_int(1, 100) <= 7) {
                $ai['funds'] -= 25;
                $player = school_exists($schools, $playerId);
                $damage = random_int(3, 6);
                $player['support'] = clamp($player['support'] - $damage, 0, 100);
                $player['grievance'] = clamp($player['grievance'] + 2, 0, 100);
                save_school($db, $player);
                $schools[$playerId] = $player;
                $ai['support'] = clamp($ai['support'], 0, 100);
                save_school($db, $ai);
                $summary['ai_actions'][] = ['school_id' => $aiId, 'school' => $ai['nickname'], 'color' => $ai['color'], 'text' => '对我校发动舆论战，支持率 -' . $damage . '。'];
                add_log($db, $game['id'], (int)$game['week'], 'ai', '【' . $ai['nickname'] . '】在热搜投放我校黑稿，支持率 -' . $damage . '。');
                $actionsLeft--;
                continue;
            }

            if ($lockdown) {
                break;
            }

            // 默认渗透。
            $ai['funds'] -= 12;
            $gain = 19.0;
            if (tech_level($ai, 'red') >= 1) {
                $gain *= 1.15;
            }
            if (tech_level($ai, 'black') >= 2) {
                $gain *= 1.10;
            }
            $gain -= $target['fortify'] * 0.30;
            $inf = add_influence($db, $game, $schools, $districts, (int)$target['id'], $aiId, max(6, round($gain, 1)), '学术交流');
            $ai = $schools[$aiId];
            save_school($db, $ai);
            $targetOwner = $inf['owner_before'] !== null && isset($schools[$inf['owner_before']]) ? $schools[$inf['owner_before']]['nickname'] : '公共区域';
            $text = '向' . $targetOwner . '的「' . $target['name'] . '」渗透 +' . $inf['amount'] . '%。';
            $summary['ai_actions'][] = ['school_id' => $aiId, 'school' => $ai['nickname'], 'color' => $ai['color'], 'text' => $text];
            attn_log($db, $game, $ai, $text);
            if ($inf['captured']) {
                $summary['captures'][] = ['school_id' => $aiId, 'school' => $ai['nickname'], 'color' => $ai['color'], 'text' => '接管了「' . $target['name'] . '」。'];
            }
            $actionsLeft--;
        }
    }
    unset($ai);
}

function school_exists(array $schools, int $id): array
{
    if (!isset($schools[$id])) {
        throw new GameError('高校数据缺失。');
    }
    return $schools[$id];
}

function attn_log(PDO $db, array $game, array $ai, string $text): void
{
    if (random_int(1, 100) <= 28) {
        add_log($db, $game['id'], (int)$game['week'], 'news', '【' . $ai['nickname'] . '】' . $text);
    }
}

function maybe_trigger_event(PDO $db, array &$game, array &$schools, array &$districts, array &$summary): void
{
    $week = (int)$game['week'];
    $existing = active_event($game);
    if ($existing && (int)$existing['until'] >= $week) {
        $summary['event'] = $existing;
        return;
    }
    if ($existing) {
        add_log($db, $game['id'], $week, 'news', '【大学城通知】“' . $existing['name'] . '”影响结束，各校恢复常态化内卷。');
    }
    $game['event_json'] = null;
    if (random_int(1, 100) > 34) {
        return;
    }

    $playerId = (int)$game['player_school_id'];
    $activeSchools = array_values(array_filter($schools, fn($s) => !$s['eliminated'] && (int)$s['id'] !== $playerId));
    if (!$activeSchools) {
        return;
    }
    $roll = random_int(1, 12);
    $event = null;
    switch ($roll) {
        case 1:
            $event = [
                'id' => 'lockdown',
                'name' => '疫情封控',
                'icon' => '封',
                'desc' => '所有跨校渗透与学科战暂停 2 周，校内怨气增长翻倍。',
                'until' => $week + 1,
            ];
            add_log($db, $game['id'], $week, 'news', '【紧急通知】大学城启动临时封控，跨校学术交流暂停两周，校内超市泡面售罄。');
            break;
        case 2:
            $event = [
                'id' => 'inspection',
                'name' => '教育部突击检查',
                'icon' => '查',
                'desc' => '渗透效率 -40%，QS 排名注水术暂停使用，持续 2 周。',
                'until' => $week + 1,
            ];
            add_log($db, $game['id'], $week, 'news', '【检查组进驻】教育部突击检查各校材料，多所高校连夜删除官网“国际排名”新闻。');
            break;
        case 3:
            $target = $activeSchools[array_rand($activeSchools)];
            $event = [
                'id' => 'score_drop',
                'name' => '分数线波动',
                'icon' => '线',
                'desc' => $target['nickname'] . '录取分数线意外暴跌，攻防 -10%，持续 3 周。',
                'until' => $week + 2,
                'target_id' => (int)$target['id'],
            ];
            add_log($db, $game['id'], $week, 'news', '【招生季】' . $target['nickname'] . '录取分数线出现波动，家长群连夜开会，校方称“属于正常大小年”。');
            break;
        case 4:
            $player = $schools[$playerId];
            $player['grievance'] = clamp($player['grievance'] + 12, 0, 100);
            save_school($db, $player);
            $event = [
                'id' => 'food_assassin',
                'name' => '食堂刺客',
                'icon' => '饭',
                'desc' => '本校食堂推出 38 元“轻奢套餐”，学生怨气 +12。',
                'until' => $week,
            ];
            add_log($db, $game['id'], $week, 'news', '【校园民生】本校食堂上新“轻奢减脂套餐”，售价 38 元，窗口前出现短暂沉默。');
            break;
        case 5:
            $player = $schools[$playerId];
            $player['funds'] += 80;
            $player['support'] = clamp($player['support'] + 2, 0, 100);
            save_school($db, $player);
            $event = [
                'id' => 'celebrity',
                'name' => '网红教授直播',
                'icon' => '播',
                'desc' => '本校教授直播讲《线性代数》，打赏与横向课题入账 80 万。',
                'until' => $week,
            ];
            add_log($db, $game['id'], $week, 'news', '【直播切片】本校教授用“双曲抛物面”讲解恋爱观，直播间同时在线 10 万+，经费 +80 万。');
            break;
        case 6:
            foreach ($schools as &$school) {
                if (!$school['eliminated']) {
                    $school['funds'] += 35;
                    save_school($db, $school);
                }
            }
            unset($school);
            $event = [
                'id' => 'subsidy',
                'name' => '专项经费到账',
                'icon' => '钱',
                'desc' => '教育部追加“双一流建设”专项经费，每校 +35 万。',
                'until' => $week,
            ];
            add_log($db, $game['id'], $week, 'news', '【经费公示】各校账户同时到账 35 万，财务处表示“不是诈骗链接”。');
            break;
        case 7:
            foreach ($schools as &$school) {
                if (!$school['eliminated']) {
                    $extra = $school['perk'] === 'scenic' ? 8 : 0;
                    $school['grievance'] = clamp($school['grievance'] + 6 + $extra, 0, 100);
                    save_school($db, $school);
                }
            }
            unset($school);
            $event = [
                'id' => 'tourist',
                'name' => '樱花季游客潮',
                'icon' => '花',
                'desc' => '大学城迎来赏花高峰，所有高校怨气 +6，景区型高校额外 +8。',
                'until' => $week,
            ];
            add_log($db, $game['id'], $week, 'news', '【人从众】大学城樱花季单日游客突破 20 万，学生表示“已经三天没挤上食堂了”。');
            break;
        case 8:
            $target = $activeSchools[array_rand($activeSchools)];
            $target['grievance'] = clamp($target['grievance'] + 15, 0, 100);
            $target['support'] = clamp($target['support'] - 4, 0, 100);
            save_school($db, $target);
            $event = [
                'id' => 'meme_wave',
                'name' => '绰号刷屏',
                'icon' => '梗',
                'desc' => $target['nickname'] . '的民间绰号突然全网刷屏，怨气 +15，支持率 -4。',
                'until' => $week,
            ];
            add_log($db, $game['id'], $week, 'news', '【梗图大赛】' . $target['nickname'] . '的旧绰号被做成表情包，校宣传部回应“不予置评”。');
            break;
        case 9:
            foreach ($schools as &$school) {
                if (!$school['eliminated']) {
                    $school['grievance'] = clamp($school['grievance'] - 8, 0, 100);
                    $school['support'] = clamp($school['support'] + 2, 0, 100);
                    save_school($db, $school);
                }
            }
            unset($school);
            $event = [
                'id' => 'sunshine',
                'name' => '阳光政策周',
                'icon' => '晴',
                'desc' => '大学城统一取消早操、延长假期，所有高校怨气 -8、支持率 +2。',
                'until' => $week,
            ];
            add_log($db, $game['id'], $week, 'news', '【喜大普奔】大学城统一取消本周早操，学生们表示“这才是真正的双一流”。');
            break;
        case 10:
            $player = $schools[$playerId];
            $game['ap'] += 1;
            $player['grievance'] = clamp($player['grievance'] - 5, 0, 100);
            save_school($db, $player);
            $event = [
                'id' => 'metro',
                'name' => '地铁新线开通',
                'icon' => '铁',
                'desc' => '大学城地铁新线直达本校，本周行政点 +1，怨气 -5。',
                'until' => $week,
            ];
            add_log($db, $game['id'], $week, 'news', '【交通利好】地铁新线开通，本校成为大学城唯一“出站即宿舍”的高校，行政点 +1。');
            break;
        case 11:
            $target = $activeSchools[array_rand($activeSchools)];
            $event = [
                'id' => 'ranking',
                'name' => '排名乌龙',
                'icon' => '榜',
                'desc' => $target['nickname'] . '被误列入“全球最美校园”榜单，游客激增，怨气 +8。',
                'until' => $week + 1,
                'target_id' => (int)$target['id'],
            ];
            $target['grievance'] = clamp($target['grievance'] + 8, 0, 100);
            save_school($db, $target);
            add_log($db, $game['id'], $week, 'news', '【榜单乌龙】' . $target['nickname'] . '被列入“全球最美校园”，校门口突然多出三辆旅游大巴。');
            break;
        default:
            $player = $schools[$playerId];
            $player['grievance'] = clamp($player['grievance'] - 4, 0, 100);
            save_school($db, $player);
            $event = [
                'id' => 'quiet',
                'name' => '风平浪静',
                'icon' => '静',
                'desc' => '本周大学城意外地平静，本校怨气 -4。',
                'until' => $week,
            ];
            add_log($db, $game['id'], $week, 'news', '【本周快讯】大学城风平浪静，各校师生度过了一个罕见的正常周末。');
    }

    $game['event_json'] = json_encode($event, JSON_UNESCAPED_UNICODE);
    $summary['event'] = $event;
    if ($event) {
        $summary['events'][] = $event['desc'];
    }
}
