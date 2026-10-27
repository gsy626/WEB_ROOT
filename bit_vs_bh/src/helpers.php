<?php
declare(strict_types=1);

function tz(): DateTimeZone
{
    return new DateTimeZone('Asia/Shanghai');
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function local_date(): string
{
    return (new DateTimeImmutable('now', tz()))->format('Y-m-d');
}

function to_utc(DateTimeImmutable $dt): string
{
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function fmt_time(?string $utc, string $format = 'm-d H:i'): string
{
    if (!$utc) {
        return '—';
    }
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $dt->setTimezone(tz())->format($format);
}

function fmt_full(?string $utc): string
{
    return fmt_time($utc, 'Y-m-d H:i');
}

function fmt_ago(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    $diff = time() - strtotime($utc . ' UTC');
    if ($diff < 45) {
        return '刚刚';
    }
    if ($diff < 3600) {
        return max(1, (int) floor($diff / 60)) . ' 分钟前';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . ' 小时前';
    }
    return (int) floor($diff / 86400) . ' 天前';
}

function js_time(?string $utc): string
{
    if (!$utc) {
        return '';
    }
    return gmdate('Y-m-d\TH:i:s\Z', strtotime($utc . ' UTC'));
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $env = getenv('APP_BASE');
    if (!is_string($env) || $env === '') {
        // PHP-FPM 下 fastcgi_param APP_BASE 会出现在 $_SERVER，而不是进程环境变量。
        $env = $_SERVER['APP_BASE'] ?? '';
    }
    if (is_string($env) && $env !== '') {
        return $base = rtrim($env, '/');
    }
    if (PHP_SAPI === 'cli') {
        return $base = '';
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '/' || $dir === '.') {
        return $base = '';
    }
    return $base = rtrim($dir, '/');
}

function url(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    if ($path === '/') {
        $path = '/';
    }
    return base_path() . $path;
}

function asset(string $path): string
{
    $file = dirname(__DIR__) . '/public/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return url($path) . '?v=' . $v;
}

function redirect(string $path): never
{
    header('Location: ' . url($path), true, 303);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);
    return $flashes;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        render_error(419, '页面已过期', '表单校验失败，请返回上一页刷新后重试。');
        exit;
    }
}

function session_regenerate(): void
{
    if (PHP_SAPI !== 'cli') {
        session_regenerate_id(true);
    }
}

function clamp(float|int $value, float|int $min, float|int $max): float|int
{
    return max($min, min($max, $value));
}

function ap_cost(string $action): int
{
    return ACTIONS[$action]['ap'] ?? 0;
}

function unit(string $code): ?array
{
    return UNITS[$code] ?? null;
}

function node_kind_label(string $kind): string
{
    return NODE_KIND_LABELS[$kind] ?? $kind;
}

function season_bounds_for(DateTimeImmutable $nowLocal): array
{
    $now = $nowLocal->setTimezone(tz());
    $dow = (int) $now->format('N');
    $startLocal = $now->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);
    $endLocal = $startLocal->modify('+' . SEASON_DAYS . ' days');
    return [
        'start_local' => $startLocal,
        'end_local' => $endLocal,
        'start' => to_utc($startLocal),
        'end' => to_utc($endLocal),
    ];
}

function season_name_from_bounds(int $number, array $bounds): string
{
    return sprintf(
        '第 %d 赛季 · %s–%s',
        $number,
        $bounds['start_local']->format('m.d'),
        $bounds['end_local']->modify('-1 day')->format('m.d')
    );
}

function rank_label(?int $rank): string
{
    return $rank === null ? '未上榜' : '#' . $rank;
}

function random_flavor(): string
{
    return BATTLE_FLAVOR[array_rand(BATTLE_FLAVOR)];
}

/**
 * 阵营色彩与文案。传入 faction 行或 code。
 */
function faction_meta(?array $faction): array
{
    if (!$faction) {
        return [
            'id' => null,
            'code' => 'neutral',
            'name' => '中立',
            'short' => '中立',
            'motto' => '暂不站队',
            'color' => '#94a3b8',
            'deep' => '#334155',
            'trait' => '',
        ];
    }
    $base = FACTIONS[$faction['code']] ?? [];
    return array_merge($base, [
        'id' => (int) $faction['id'],
        'code' => $faction['code'],
        'name' => $faction['name'],
        'short' => $faction['short_name'] ?? ($base['short'] ?? $faction['name']),
    ]);
}

function unit_counter_bonus(string $attackerUnit, string $defenderUnit): float
{
    if ((UNIT_COUNTERS[$attackerUnit] ?? null) === $defenderUnit) {
        return 1.20;
    }
    if ((UNIT_COUNTERS[$defenderUnit] ?? null) === $attackerUnit) {
        return 0.80;
    }
    return 1.0;
}

function majority_unit(array $orders, string $fallback = ''): string
{
    $counts = [];
    foreach ($orders as $order) {
        $unit = $order['unit_code'] ?? '';
        if ($unit !== '') {
            $counts[$unit] = ($counts[$unit] ?? 0) + 1;
        }
    }
    if (!$counts) {
        return $fallback;
    }
    arsort($counts);
    return (string) array_key_first($counts);
}

function unit_names_summary(array $orders): string
{
    $counts = [];
    foreach ($orders as $order) {
        $code = $order['unit_code'] ?? '';
        $counts[$code] = ($counts[$code] ?? 0) + 1;
    }
    arsort($counts);
    $parts = [];
    foreach ($counts as $code => $count) {
        $unit = unit((string) $code);
        $parts[] = ($unit['name'] ?? $code) . '×' . $count;
    }
    return $parts ? implode('、', $parts) : '零散部队';
}

function faction_balance_bonus(): array
{
    $pdo = db();
    $rows = $pdo->query(
        "SELECT faction_id, COUNT(*) AS c
           FROM users
          WHERE last_seen_at >= datetime('now', '-7 days')
          GROUP BY faction_id"
    )->fetchAll();
    $counts = [];
    foreach ($rows as $row) {
        $counts[(int) $row['faction_id']] = (int) $row['c'];
    }
    $factions = factions();
    foreach ($factions as $faction) {
        $counts[(int) $faction['id']] = $counts[(int) $faction['id']] ?? 0;
    }
    $max = max($counts ?: [0]);
    $bonuses = [];
    foreach ($counts as $factionId => $count) {
        $bonus = 0.0;
        if ($max > 0 && $count > 0) {
            $bonus = clamp(($max - $count) / $max * 0.30, 0.0, 0.15);
        }
        $bonuses[$factionId] = $bonus;
    }
    return ['counts' => $counts, 'bonuses' => $bonuses, 'max' => $max];
}
