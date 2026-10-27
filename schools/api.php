<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/game.php';

date_default_timezone_set('Asia/Shanghai');
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function api_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_read_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new GameError('请求格式不正确。');
    }
    return $decoded;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? '';

    if ($method === 'GET' && $action === 'state') {
        $gameId = (string)($_GET['game_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $gameId)) {
            throw new GameError('对局编号不正确。');
        }
        api_respond(['ok' => true, 'state' => get_state($gameId)]);
    }

    if ($method === 'GET' && $action === 'bootstrap') {
        $config = game_config();
        api_respond(['ok' => true, 'config' => $config]);
    }

    if ($method !== 'POST') {
        api_respond(['ok' => false, 'message' => '不支持的请求方式。'], 405);
    }

    $body = api_read_body();
    $action = (string)($body['action'] ?? $action);

    if ($action === 'new_game') {
        $lineup = $body['lineup'] ?? [];
        if (!is_array($lineup)) {
            throw new GameError('高校阵容格式不正确。');
        }
        $result = create_game($lineup);
        $result['ok'] = true;
        api_respond($result);
    }

    if ($action === 'end_turn') {
        $gameId = (string)($body['game_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $gameId)) {
            throw new GameError('对局编号不正确。');
        }
        api_respond(end_turn($gameId));
    }

    if ($action === 'action') {
        $gameId = (string)($body['game_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $gameId)) {
            throw new GameError('对局编号不正确。');
        }
        $type = (string)($body['type'] ?? '');
        $payload = isset($body['payload']) && is_array($body['payload']) ? $body['payload'] : [];
        api_respond(perform_action($gameId, $type, $payload));
    }

    api_respond(['ok' => false, 'message' => '未知接口动作。'], 404);
} catch (GameError $e) {
    api_respond(['ok' => false, 'message' => $e->getMessage(), 'error' => 'game'], 400);
} catch (Throwable $e) {
    error_log('[college-game] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    api_respond([
        'ok' => false,
        'message' => '服务器结算时打了个喷嚏：' . $e->getMessage(),
        'error' => 'server',
    ], 500);
}
