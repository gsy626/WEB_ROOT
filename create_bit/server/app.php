<?php
/**
 * 合成北理工 · 排行榜 API
 *
 * 入口：api.php?action=...
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';

final class BitHttpError extends RuntimeException
{
    public int $status;
    public string $errorCode;

    public function __construct(int $status, string $code, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $code;
    }
}

function bit_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $forwarded === 'https';
}

function bit_send_json_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

/** @param mixed $data */
function bit_respond($data, int $status = 200): void
{
    http_response_code($status);
    bit_send_json_headers();
    $json = json_encode(
        ['ok' => true, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($json === false) {
        $json = '{"ok":false,"error":{"code":"ENCODE_ERROR","message":"响应编码失败。"}}';
        http_response_code(500);
    }
    echo $json;
    exit;
}

function bit_fail(int $status, string $code, string $message): void
{
    http_response_code($status);
    bit_send_json_headers();
    $json = json_encode(
        ['ok' => false, 'error' => ['code' => $code, 'message' => $message]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($json === false) {
        $json = '{"ok":false,"error":{"code":"ENCODE_ERROR","message":"响应编码失败。"}}';
    }
    echo $json;
    exit;
}

function bit_method(string $expected): void
{
    $actual = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($actual !== $expected) {
        throw new BitHttpError(405, 'METHOD_NOT_ALLOWED', '请求方式不正确。');
    }
}

function bit_check_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!is_string($origin) || $origin === '') {
        return;
    }
    $parts = parse_url($origin);
    if (!is_array($parts) || empty($parts['host'])) {
        throw new BitHttpError(403, 'BAD_ORIGIN', '请求来源不正确。');
    }
    $originHost = strtolower((string) $parts['host']);
    $requestHost = parse_url('http://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    $requestHost = is_string($requestHost) ? strtolower($requestHost) : '';
    // 只比较主机名，不比较端口：反向代理经常会去掉或改写端口，
    // 而 SameSite Cookie + JSON Content-Type 已经能挡住跨站表单提交。
    if ($requestHost === '' || !hash_equals($requestHost, $originHost)) {
        throw new BitHttpError(403, 'BAD_ORIGIN', '请求来源不正确。');
    }
}

/** @return array<string,mixed> */
function bit_read_json_body(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'application/json') === false) {
        throw new BitHttpError(415, 'UNSUPPORTED_MEDIA_TYPE', '请使用 application/json 提交请求。');
    }

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        throw new BitHttpError(400, 'INVALID_JSON', '无法读取请求内容。');
    }
    if (strlen($raw) > 32768) {
        throw new BitHttpError(413, 'PAYLOAD_TOO_LARGE', '请求内容过大。');
    }
    if (trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new BitHttpError(400, 'INVALID_JSON', '请求内容不是有效的 JSON。');
    }
    return $data;
}

function bit_strlen(string $value): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($value, 'UTF-8');
    }
    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? strlen($value) : (int) $count;
}

function bit_lower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return (string) mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}

function bit_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function bit_ip_hash(): string
{
    return hash('sha256', bit_client_ip() . '|' . BIT_IP_SALT);
}

function bit_session_token(): string
{
    $value = $_COOKIE[BIT_SESSION_COOKIE] ?? '';
    return is_string($value) ? $value : '';
}

function bit_set_session_cookie(string $token, int $expiresAt): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(BIT_SESSION_COOKIE, $token, [
        'expires' => $expiresAt,
        'path' => '/',
        'secure' => bit_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function bit_clear_session_cookie(): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(BIT_SESSION_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => bit_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function bit_current_player_id(array $state): ?int
{
    $token = bit_session_token();
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $hash = hash('sha256', $token);
    if (!isset($state['sessions'][$hash])) {
        return null;
    }
    $session = $state['sessions'][$hash];
    if ((int) $session['expires_at'] <= time()) {
        return null;
    }
    $playerId = (int) $session['player_id'];
    return isset($state['players'][$playerId]) ? $playerId : null;
}

/** @return array{token:string,expires:int} */
function bit_create_session(array &$state, int $playerId): array
{
    $now = time();
    $expiresAt = $now + BIT_SESSION_TTL;

    do {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
    } while (isset($state['sessions'][$hash]));

    $state['sessions'][$hash] = [
        'token_hash' => $hash,
        'player_id' => $playerId,
        'created_at' => $now,
        'expires_at' => $expiresAt,
    ];

    $owned = [];
    foreach ($state['sessions'] as $sessionHash => $session) {
        if ((int) $session['player_id'] === $playerId) {
            $owned[$sessionHash] = (int) $session['created_at'];
        }
    }
    if (count($owned) > 10) {
        asort($owned, SORT_NUMERIC);
        $remove = count($owned) - 10;
        foreach (array_keys($owned) as $sessionHash) {
            if ($remove <= 0) {
                break;
            }
            unset($state['sessions'][$sessionHash]);
            $remove--;
        }
    }

    return ['token' => $token, 'expires' => $expiresAt];
}

function bit_prune_state(array &$state): void
{
    $now = time();
    foreach ($state['sessions'] as $hash => $session) {
        if ((int) $session['expires_at'] <= $now) {
            unset($state['sessions'][$hash]);
        }
    }

    foreach ($state['runs'] as $runId => $run) {
        if ((int) $run['expires_at'] < $now - 86400) {
            unset($state['runs'][$runId]);
        }
    }
    if (count($state['runs']) > 5000) {
        uasort($state['runs'], static function (array $a, array $b): int {
            return (int) $a['started_at'] <=> (int) $b['started_at'];
        });
        $state['runs'] = array_slice($state['runs'], -5000, null, true);
    }

    $state['attempts'] = array_values(array_filter(
        $state['attempts'],
        static function (array $attempt) use ($now): bool {
            return (int) $attempt['at'] >= $now - 86400;
        }
    ));
    if (count($state['attempts']) > 5000) {
        $state['attempts'] = array_slice($state['attempts'], -5000);
    }

    if (count($state['sessions']) > 5000) {
        uasort($state['sessions'], static function (array $a, array $b): int {
            return (int) $a['created_at'] <=> (int) $b['created_at'];
        });
        $state['sessions'] = array_slice($state['sessions'], -5000, null, true);
    }
}

function bit_record_attempt(
    array &$state,
    string $ipHash,
    string $action,
    string $usernameLower,
    int $ok
): void {
    $state['attempts'][] = [
        'ip_hash' => $ipHash,
        'action' => $action,
        'username_lower' => $usernameLower,
        'at' => time(),
        'ok' => $ok ? 1 : 0,
    ];
}

function bit_count_attempts(
    array $state,
    string $ipHash,
    string $action,
    bool $onlyFailures,
    int $windowSeconds,
    ?string $usernameLower = null
): int {
    $threshold = time() - $windowSeconds;
    $count = 0;
    foreach ($state['attempts'] as $attempt) {
        if ((string) $attempt['ip_hash'] !== $ipHash) {
            continue;
        }
        if ((string) $attempt['action'] !== $action) {
            continue;
        }
        if ((int) $attempt['at'] < $threshold) {
            continue;
        }
        if ($onlyFailures && (int) $attempt['ok'] !== 0) {
            continue;
        }
        if ($usernameLower !== null && (string) $attempt['username_lower'] !== $usernameLower) {
            continue;
        }
        $count++;
    }
    return $count;
}

function bit_clear_login_failures(array &$state, string $ipHash, string $usernameLower): void
{
    $state['attempts'] = array_values(array_filter(
        $state['attempts'],
        static function (array $attempt) use ($ipHash, $usernameLower): bool {
            return !(
                (string) $attempt['ip_hash'] === $ipHash
                && (string) $attempt['action'] === 'login'
                && (string) $attempt['username_lower'] === $usernameLower
                && (int) $attempt['ok'] === 0
            );
        }
    ));
}

function bit_username_problem(string $username): string
{
    $length = bit_strlen($username);
    if ($length < 2 || $length > 20) {
        return '用户名需要 2–20 个字符。';
    }
    if (!preg_match('/^[A-Za-z0-9_\-\x{00B7}\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]+$/u', $username)) {
        return '用户名只能包含中文、字母、数字、下划线、短横线或中点。';
    }
    $reserved = ['admin', 'administrator', 'root', 'system', 'official', '管理员', '官方', '系统', 'test'];
    if (in_array(bit_lower($username), $reserved, true)) {
        return '这个用户名已被保留，请换一个。';
    }
    return '';
}

function bit_password_problem(string $password): string
{
    if (bit_strlen($password) < 6) {
        return '密码至少需要 6 位。';
    }
    if (trim($password) === '') {
        return '密码不能全是空格。';
    }
    if (strlen($password) > 72) {
        return '密码过长，请控制在 72 个字节以内。';
    }
    return '';
}

function bit_find_player_by_lower(array $state, string $lower): ?array
{
    foreach ($state['players'] as $player) {
        if ((string) $player['username_lower'] === $lower) {
            return $player;
        }
    }
    return null;
}

function bit_rank_for_player(array $target, array $state): ?int
{
    $score = (int) $target['best_score'];
    if ($score <= 0) {
        return null;
    }
    $targetAt = $target['best_at'] !== null ? (int) $target['best_at'] : PHP_INT_MAX;
    $targetId = (int) $target['id'];
    $rank = 1;

    foreach ($state['players'] as $player) {
        $playerScore = (int) $player['best_score'];
        if ($playerScore <= 0) {
            continue;
        }
        if ($playerScore > $score) {
            $rank++;
            continue;
        }
        if ($playerScore !== $score) {
            continue;
        }
        $playerAt = $player['best_at'] !== null ? (int) $player['best_at'] : PHP_INT_MAX;
        if ($playerAt < $targetAt) {
            $rank++;
            continue;
        }
        if ($playerAt === $targetAt && (int) $player['id'] < $targetId) {
            $rank++;
        }
    }

    return $rank;
}

/** @return array<string,mixed> */
function bit_player_public(array $player, array $state): array
{
    return [
        'id' => (int) $player['id'],
        'username' => (string) $player['username'],
        'bestScore' => (int) $player['best_score'],
        'bestLevel' => (int) $player['best_level'],
        'bestAt' => $player['best_at'] !== null ? (int) $player['best_at'] : null,
        'plays' => (int) $player['plays'],
        'highestMerged' => (bool) $player['highest_merged'],
        'lastScore' => (int) $player['last_score'],
        'lastAt' => $player['last_at'] !== null ? (int) $player['last_at'] : null,
        'rank' => bit_rank_for_player($player, $state),
    ];
}

/** @return array<string,mixed> */
function bit_leaderboard_data(array $state, int $limit): array
{
    $ranked = [];
    foreach ($state['players'] as $player) {
        if ((int) $player['best_score'] > 0) {
            $ranked[] = $player;
        }
    }

    usort($ranked, static function (array $a, array $b): int {
        $scoreCompare = (int) $b['best_score'] <=> (int) $a['best_score'];
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }
        $aAt = $a['best_at'] !== null ? (int) $a['best_at'] : PHP_INT_MAX;
        $bAt = $b['best_at'] !== null ? (int) $b['best_at'] : PHP_INT_MAX;
        if ($aAt !== $bAt) {
            return $aAt <=> $bAt;
        }
        return (int) $a['id'] <=> (int) $b['id'];
    });

    $currentId = bit_current_player_id($state);
    $entries = [];
    foreach (array_slice($ranked, 0, $limit) as $index => $player) {
        $entries[] = [
            'rank' => $index + 1,
            'username' => (string) $player['username'],
            'score' => (int) $player['best_score'],
            'level' => (int) $player['best_level'],
            'at' => $player['best_at'] !== null ? (int) $player['best_at'] : null,
            'plays' => (int) $player['plays'],
            'isMe' => $currentId !== null && (int) $player['id'] === $currentId,
        ];
    }

    $me = null;
    if ($currentId !== null && isset($state['players'][$currentId])) {
        $me = bit_player_public($state['players'][$currentId], $state);
    }

    return [
        'entries' => $entries,
        'total' => count($ranked),
        'me' => $me,
        'updatedAt' => time(),
    ];
}

/** @return array<string,mixed> */
function bit_error_result(int $status, string $code, string $message): array
{
    return [
        'error' => [
            'status' => $status,
            'code' => $code,
            'message' => $message,
        ],
    ];
}

/** @param array<string,mixed> $result */
function bit_throw_if_error(array $result): void
{
    if (!isset($result['error'])) {
        return;
    }
    $error = $result['error'];
    throw new BitHttpError(
        (int) $error['status'],
        (string) $error['code'],
        (string) $error['message']
    );
}

function bit_dummy_password_verify(string $password): void
{
    // 固定 bcrypt 哈希（"password"），让不存在的用户名也走一次同等成本的校验。
    password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
}

function bit_handle_ping(): void
{
    bit_method('GET');
    $format = 'unavailable';
    try {
        $format = bit_store()->format();
    } catch (Throwable $error) {
        // 数据库暂时不可用时也让 ping 成功，前端才能显示明确的错误提示。
    }
    bit_respond([
        'pong' => true,
        'time' => time(),
        'version' => BIT_APP_VERSION,
        'format' => $format,
    ]);
}

function bit_handle_me(): void
{
    bit_method('GET');
    $store = bit_store();
    $data = $store->read(static function (array $state): array {
        $playerId = bit_current_player_id($state);
        return [
            'player' => $playerId !== null ? bit_player_public($state['players'][$playerId], $state) : null,
            'serverTime' => time(),
        ];
    });
    bit_respond($data);
}

function bit_handle_leaderboard(): void
{
    bit_method('GET');
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : BIT_LEADERBOARD_LIMIT;
    $limit = max(1, min(100, $limit));
    $store = bit_store();
    $data = $store->read(static function (array $state) use ($limit): array {
        return bit_leaderboard_data($state, $limit);
    });
    bit_respond($data);
}

function bit_handle_register(): void
{
    bit_method('POST');
    $input = bit_read_json_body();
    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');

    $problem = bit_username_problem($username);
    if ($problem !== '') {
        throw new BitHttpError(422, 'INVALID_INPUT', $problem);
    }
    $problem = bit_password_problem($password);
    if ($problem !== '') {
        throw new BitHttpError(422, 'INVALID_INPUT', $problem);
    }

    $ipHash = bit_ip_hash();
    $lower = bit_lower($username);
    $store = bit_store();
    $result = $store->write(static function (array &$state) use ($username, $password, $lower, $ipHash): array {
        bit_prune_state($state);

        if (count($state['players']) >= BIT_MAX_PLAYERS) {
            return bit_error_result(503, 'PLAYER_LIMIT', '排行榜玩家数量已达上限。');
        }
        if (bit_count_attempts($state, $ipHash, 'register', false, 3600) >= 100) {
            return bit_error_result(429, 'RATE_LIMITED', '注册太频繁了，请一小时后再试。');
        }
        if (bit_find_player_by_lower($state, $lower) !== null) {
            bit_record_attempt($state, $ipHash, 'register', $lower, 0);
            return bit_error_result(409, 'USERNAME_TAKEN', '这个用户名已经被注册了。');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            return bit_error_result(500, 'HASH_FAILED', '注册失败，请稍后再试。');
        }

        $now = time();
        $playerId = (int) $state['next_player_id'];
        $state['next_player_id'] = $playerId + 1;
        $state['players'][$playerId] = [
            'id' => $playerId,
            'username' => $username,
            'username_lower' => $lower,
            'password_hash' => $hash,
            'created_at' => $now,
            'best_score' => 0,
            'best_level' => 0,
            'best_at' => null,
            'plays' => 0,
            'highest_merged' => 0,
            'last_score' => 0,
            'last_at' => null,
        ];
        $session = bit_create_session($state, $playerId);
        bit_record_attempt($state, $ipHash, 'register', $lower, 1);

        return [
            'player' => bit_player_public($state['players'][$playerId], $state),
            'token' => $session['token'],
            'expires' => $session['expires'],
        ];
    });

    bit_throw_if_error($result);
    bit_set_session_cookie((string) $result['token'], (int) $result['expires']);
    bit_respond(['player' => $result['player']]);
}

function bit_handle_login(): void
{
    bit_method('POST');
    $input = bit_read_json_body();
    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    if ($username === '' || $password === '') {
        throw new BitHttpError(422, 'INVALID_INPUT', '请输入用户名和密码。');
    }

    $ipHash = bit_ip_hash();
    $lower = bit_lower($username);
    $store = bit_store();
    $result = $store->write(static function (array &$state) use ($lower, $password, $ipHash): array {
        bit_prune_state($state);

        $failures = bit_count_attempts($state, $ipHash, 'login', true, 600, $lower);
        $allAttempts = bit_count_attempts($state, $ipHash, 'login', false, 600);
        if ($failures >= 8 || $allAttempts >= 120) {
            return bit_error_result(429, 'RATE_LIMITED', '尝试次数过多，请 10 分钟后再试。');
        }

        $player = bit_find_player_by_lower($state, $lower);
        if ($player === null) {
            bit_dummy_password_verify($password);
            bit_record_attempt($state, $ipHash, 'login', $lower, 0);
            return bit_error_result(401, 'INVALID_CREDENTIALS', '用户名或密码不正确。');
        }
        if (!password_verify($password, (string) $player['password_hash'])) {
            bit_record_attempt($state, $ipHash, 'login', $lower, 0);
            return bit_error_result(401, 'INVALID_CREDENTIALS', '用户名或密码不正确。');
        }

        bit_clear_login_failures($state, $ipHash, $lower);
        bit_record_attempt($state, $ipHash, 'login', $lower, 1);
        $session = bit_create_session($state, (int) $player['id']);
        return [
            'player' => bit_player_public($player, $state),
            'token' => $session['token'],
            'expires' => $session['expires'],
        ];
    });

    bit_throw_if_error($result);
    bit_set_session_cookie((string) $result['token'], (int) $result['expires']);
    bit_respond(['player' => $result['player']]);
}

function bit_handle_logout(): void
{
    bit_method('POST');
    $token = bit_session_token();
    $store = bit_store();
    $store->write(static function (array &$state) use ($token): array {
        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            unset($state['sessions'][hash('sha256', $token)]);
        }
        return ['loggedOut' => true];
    });
    bit_clear_session_cookie();
    bit_respond(['loggedOut' => true]);
}

function bit_handle_start(): void
{
    bit_method('POST');
    bit_read_json_body();
    $ipHash = bit_ip_hash();

    $store = bit_store();
    $result = $store->write(static function (array &$state) use ($ipHash): array {
        bit_prune_state($state);

        $recentRuns = 0;
        $threshold = time() - 600;
        foreach ($state['runs'] as $run) {
            if ((string) $run['ip_hash'] === $ipHash && (int) $run['started_at'] >= $threshold) {
                $recentRuns++;
            }
        }
        if ($recentRuns >= 600) {
            return bit_error_result(429, 'RATE_LIMITED', '开始得太频繁了，请稍后再试。');
        }

        $playerId = bit_current_player_id($state);
        $now = time();
        $runId = bin2hex(random_bytes(16));
        $state['runs'][$runId] = [
            'run_id' => $runId,
            'player_id' => $playerId,
            'ip_hash' => $ipHash,
            'started_at' => $now,
            'expires_at' => $now + BIT_RUN_TTL,
            'used' => 0,
            'score' => null,
            'max_level' => null,
            'submitted_at' => null,
        ];

        return [
            'runId' => $runId,
            'startedAt' => $now,
            'expiresAt' => $now + BIT_RUN_TTL,
            'serverTime' => $now,
            'minDurationMs' => BIT_MIN_RUN_SECONDS * 1000,
            'playerId' => $playerId,
        ];
    });

    bit_throw_if_error($result);
    bit_respond($result);
}

function bit_handle_submit(): void
{
    bit_method('POST');
    $input = bit_read_json_body();
    $runId = trim((string) ($input['runId'] ?? ''));
    $score = filter_var($input['score'] ?? null, FILTER_VALIDATE_INT);
    $maxLevel = filter_var($input['maxLevel'] ?? null, FILTER_VALIDATE_INT);
    $durationMs = filter_var($input['durationMs'] ?? null, FILTER_VALIDATE_INT);
    $highestMerged = !empty($input['highestMerged']);

    if (!preg_match('/^[a-f0-9]{32}$/', $runId)) {
        throw new BitHttpError(422, 'INVALID_INPUT', '排行会话编号无效。');
    }
    if ($score === false || $score < 0 || $score > BIT_MAX_SCORE) {
        throw new BitHttpError(422, 'INVALID_INPUT', '分数无效。');
    }
    if ($maxLevel === false || $maxLevel < 0 || $maxLevel > BIT_MAX_LEVEL) {
        throw new BitHttpError(422, 'INVALID_INPUT', '等级无效。');
    }
    if ($durationMs === false || $durationMs < 0 || $durationMs > BIT_RUN_TTL * 1000) {
        throw new BitHttpError(422, 'INVALID_INPUT', '游戏时长无效。');
    }

    $store = bit_store();
    $result = $store->write(static function (array &$state) use (
        $runId,
        $score,
        $maxLevel,
        $durationMs,
        $highestMerged
    ): array {
        bit_prune_state($state);

        $playerId = bit_current_player_id($state);
        if ($playerId === null) {
            return bit_error_result(401, 'AUTH_REQUIRED', '请先登录再提交成绩。');
        }
        if (!isset($state['runs'][$runId])) {
            return bit_error_result(404, 'RUN_NOT_FOUND', '本局排行会话不存在，请重新开始一局。');
        }

        $run = $state['runs'][$runId];
        if ($run['player_id'] !== null && (int) $run['player_id'] !== $playerId) {
            return bit_error_result(403, 'RUN_FORBIDDEN', '这局成绩不属于当前账号。');
        }
        if ((int) $run['used'] !== 0) {
            return bit_error_result(409, 'RUN_USED', '这局成绩已经提交过了。');
        }

        $now = time();
        if ((int) $run['expires_at'] <= $now) {
            return bit_error_result(410, 'RUN_EXPIRED', '本局排行会话已过期，请重新开始一局。');
        }
        $elapsed = $now - (int) $run['started_at'];
        if ($elapsed < BIT_MIN_RUN_SECONDS) {
            return bit_error_result(422, 'TOO_FAST', '这局结束得太快了，请完整玩一局再提交。');
        }
        if ($durationMs > ($elapsed + 120) * 1000) {
            return bit_error_result(422, 'SCORE_REJECTED', '游戏时长与服务器记录不一致，成绩未收录。');
        }

        $maxAllowed = BIT_MAX_SCORE_BASE + $elapsed * BIT_MAX_SCORE_PER_SECOND;
        if ($score > $maxAllowed) {
            $state['runs'][$runId]['used'] = 1;
            $state['runs'][$runId]['submitted_at'] = $now;
            return bit_error_result(422, 'SCORE_REJECTED', '分数异常，成绩未收录。');
        }

        $player = $state['players'][$playerId];
        $isBest = $score > (int) $player['best_score'];
        $state['players'][$playerId]['plays'] = (int) $player['plays'] + 1;
        $state['players'][$playerId]['last_score'] = $score;
        $state['players'][$playerId]['last_at'] = $now;
        if ($isBest) {
            $state['players'][$playerId]['best_score'] = $score;
            $state['players'][$playerId]['best_level'] = $maxLevel;
            $state['players'][$playerId]['best_at'] = $now;
            $state['players'][$playerId]['highest_merged'] = $highestMerged ? 1 : 0;
        }

        $state['runs'][$runId]['used'] = 1;
        $state['runs'][$runId]['score'] = $score;
        $state['runs'][$runId]['max_level'] = $maxLevel;
        $state['runs'][$runId]['submitted_at'] = $now;

        $updated = $state['players'][$playerId];
        return [
            'isBest' => $isBest,
            'rank' => bit_rank_for_player($updated, $state),
            'player' => bit_player_public($updated, $state),
            'runId' => $runId,
        ];
    });

    bit_throw_if_error($result);
    bit_respond($result);
}

function bit_dispatch(): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        bit_check_origin();
    }

    $action = $_GET['action'] ?? '';
    if (!is_string($action)) {
        throw new BitHttpError(400, 'INVALID_ACTION', '接口参数不正确。');
    }

    switch ($action) {
        case 'ping':
            bit_handle_ping();
            break;
        case 'me':
            bit_handle_me();
            break;
        case 'leaderboard':
            bit_handle_leaderboard();
            break;
        case 'register':
            bit_handle_register();
            break;
        case 'login':
            bit_handle_login();
            break;
        case 'logout':
            bit_handle_logout();
            break;
        case 'start':
            bit_handle_start();
            break;
        case 'submit':
            bit_handle_submit();
            break;
        default:
            throw new BitHttpError(404, 'UNKNOWN_ACTION', '接口不存在。');
    }
}

try {
    bit_dispatch();
} catch (BitHttpError $error) {
    bit_fail($error->status, $error->errorCode, $error->getMessage());
} catch (RuntimeException $error) {
    error_log('GreatBIT DB: ' . $error->getMessage());
    bit_fail(503, 'DB_UNAVAILABLE', BIT_DEBUG
        ? $error->getMessage()
        : '排行榜数据库暂时不可用，请稍后再试。');
} catch (Throwable $error) {
    error_log('GreatBIT: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine());
    bit_fail(500, 'SERVER_ERROR', BIT_DEBUG
        ? $error->getMessage()
        : '服务器开小差了，请稍后再试。');
}
