<?php
declare(strict_types=1);

// PHP 内置服务器：静态资源直接交给服务器处理。
if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = realpath(__DIR__ . $requestPath);
    if ($file !== false
        && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)
        && is_file($file)
    ) {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

$path = current_path();
$segments = $path === '/' ? [] : explode('/', trim($path, '/'));
$route = $segments[0] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($route) {
        case '':
            if (current_user()) {
                redirect('/map');
            }
            $standings = faction_standings();
            $season = active_season();
            $tick = tick_state();
            $playerCount = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $battleCount = (int) db()->query('SELECT COUNT(*) FROM battles')->fetchColumn();
            $recentBattles = db()->query(
                'SELECT b.*, n.name AS node_name, n.zone
                   FROM battles b
                   JOIN nodes n ON n.id = b.node_id
                  ORDER BY b.id DESC LIMIT 5'
            )->fetchAll();
            render('home', [
                'title' => APP_NAME . ' · ' . APP_SUBTITLE,
                'standings' => $standings,
                'season' => $season,
                'tick' => $tick,
                'playerCount' => $playerCount,
                'battleCount' => $battleCount,
                'recentBattles' => $recentBattles,
            ]);
            break;

        case 'register':
            if (current_user()) {
                redirect('/map');
            }
            if ($method === 'POST') {
                verify_csrf();
                $result = attempt_register($_POST);
                if ($result['ok']) {
                    flash('success', '注册成功。你已加入 ' . (faction_by_id((int) $result['user']['faction_id'])['name'] ?? '阵营') . '，今日 20 行动点已到账。');
                    redirect('/map');
                }
                $_SESSION['old'] = [
                    'username' => (string) ($_POST['username'] ?? ''),
                    'faction' => (string) ($_POST['faction'] ?? ''),
                    'team_name' => (string) ($_POST['team_name'] ?? ''),
                ];
                flash('error', $result['error'] ?? '注册失败。');
                redirect('/register' . (($_POST['faction'] ?? '') ? '?faction=' . urlencode((string) $_POST['faction']) : ''));
            }
            $old = $_SESSION['old'] ?? [];
            unset($_SESSION['old']);
            $presetFaction = (string) ($_GET['faction'] ?? ($old['faction'] ?? 'bit'));
            render('register', [
                'title' => '注册 · ' . APP_NAME,
                'old' => $old,
                'presetFaction' => $presetFaction,
            ]);
            break;

        case 'login':
            if (current_user()) {
                redirect('/map');
            }
            if ($method === 'POST') {
                verify_csrf();
                $result = attempt_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
                if ($result['ok']) {
                    flash('success', '欢迎回来，' . $result['user']['username'] . '。');
                    redirect('/map');
                }
                $_SESSION['old'] = ['username' => (string) ($_POST['username'] ?? '')];
                flash('error', $result['error'] ?? '登录失败。');
                redirect('/login');
            }
            $old = $_SESSION['old'] ?? [];
            unset($_SESSION['old']);
            render('login', [
                'title' => '登录 · ' . APP_NAME,
                'old' => $old,
            ]);
            break;

        case 'logout':
            post_only();
            verify_csrf();
            logout_user();
            flash('info', '已退出登录。');
            redirect('/');
            break;

        case 'map':
            $user = require_login();
            $season = active_season();
            $tick = tick_state();
            $balance = faction_balance_bonus();
            $standings = faction_standings();
            $nodeList = nodes();
            foreach ($nodeList as &$node) {
                $node['owner'] = faction_by_id($node['owner_faction_id'] !== null ? (int) $node['owner_faction_id'] : null);
                $node['pending'] = pending_summary_for_node((int) $node['id']);
            }
            unset($node);
            $myPending = user_pending_orders((int) $user['id']);
            $rank = my_rank($user);
            $faction = faction_meta(faction_by_id((int) $user['faction_id']));
            $seasonStart = strtotime($season['started_at'] . ' UTC');
            $seasonEnd = strtotime($season['ends_at'] . ' UTC');
            $seasonProgress = clamp(
                ($seasonEnd - $seasonStart) > 0 ? (time() - $seasonStart) / ($seasonEnd - $seasonStart) * 100 : 0,
                0,
                100
            );
            render('map', [
                'title' => '战区地图 · ' . APP_NAME,
                'user' => $user,
                'faction' => $faction,
                'season' => $season,
                'tick' => $tick,
                'balance' => $balance,
                'standings' => $standings,
                'nodeList' => $nodeList,
                'myPending' => $myPending,
                'rank' => $rank,
                'seasonProgress' => $seasonProgress,
            ]);
            break;

        case 'node':
            $user = require_login();
            $nodeId = (int) ($segments[1] ?? 0);
            $node = node_by_id($nodeId);
            if (!$node) {
                render_error(404, '节点不存在', '这个校区节点不在当前地图上。');
            }
            $faction = faction_meta(faction_by_id((int) $user['faction_id']));
            $ownerId = $node['owner_faction_id'] !== null ? (int) $node['owner_faction_id'] : null;
            $myFactionId = (int) $user['faction_id'];
            $isOwner = $ownerId === $myFactionId;
            $isNewPlayer = strtotime($user['created_at'] . ' UTC') > time() - NEW_PLAYER_HOURS * 3600;
            $availability = [
                'attack' => !$isOwner && !$isNewPlayer,
                'defend' => $isOwner,
                'support' => true,
                'scout' => true,
                'repair' => $isOwner,
            ];
            $reasons = [
                'attack' => $isOwner ? '不能进攻本方节点' : ($isNewPlayer ? '新玩家保护期内不可进攻' : ''),
                'defend' => $isOwner ? '' : '只能驻防本方节点',
                'support' => '',
                'scout' => '',
                'repair' => $isOwner ? '仅工程兵可用' : '只能修复本方节点',
            ];
            $defaultAction = $isOwner ? 'defend' : ($isNewPlayer ? 'support' : 'attack');
            $pending = pending_summary_for_node($nodeId);
            $ownPending = $pending['by_faction'][$myFactionId] ?? null;
            $enemyCount = (int) $pending['total_count'] - (int) ($ownPending['count'] ?? 0);
            $intel = latest_intel_for($nodeId, $myFactionId);
            $intelStale = $intel !== null && now_utc() > $intel['tick_at'];
            $battles = node_battles($nodeId, 6);
            $myOrdersHere = node_orders_pending_detail($nodeId, $myFactionId);
            foreach ($myOrdersHere as &$order) {
                $order['unit'] = unit($order['unit_code']);
            }
            unset($order);
            $tick = tick_state();
            render('node', [
                'title' => $node['name'] . ' · ' . APP_NAME,
                'user' => $user,
                'faction' => $faction,
                'node' => $node,
                'owner' => faction_by_id($ownerId),
                'isOwner' => $isOwner,
                'isNewPlayer' => $isNewPlayer,
                'availability' => $availability,
                'reasons' => $reasons,
                'defaultAction' => $defaultAction,
                'pending' => $pending,
                'ownPending' => $ownPending,
                'enemyCount' => $enemyCount,
                'intel' => $intel,
                'intelStale' => $intelStale,
                'battles' => $battles,
                'myOrdersHere' => $myOrdersHere,
                'tick' => $tick,
            ]);
            break;

        case 'order':
            post_only();
            verify_csrf();
            $user = require_login();
            if (($segments[1] ?? '') === 'cancel') {
                $result = cancel_order($user, (int) ($_POST['order_id'] ?? 0));
                flash($result['type'], $result['message']);
                redirect('/orders');
            }
            $nodeId = (int) ($_POST['node_id'] ?? 0);
            $result = submit_order(
                $user,
                $nodeId,
                (string) ($_POST['unit_code'] ?? ''),
                (string) ($_POST['action'] ?? '')
            );
            flash($result['type'], $result['message']);
            redirect($nodeId > 0 ? '/node/' . $nodeId : '/map');
            break;

        case 'orders':
            $user = require_login();
            $pending = user_pending_orders((int) $user['id']);
            $stmt = db()->prepare(
                "SELECT o.*, n.name AS node_name, b.headline
                   FROM orders o
                   JOIN nodes n ON n.id = o.node_id
                   LEFT JOIN battles b ON b.id = o.battle_id
                  WHERE o.user_id = ? AND o.status <> 'pending'
                  ORDER BY o.id DESC LIMIT 30"
            );
            $stmt->execute([(int) $user['id']]);
            $history = $stmt->fetchAll();
            $team = null;
            if ($user['team_id'] !== null) {
                $stmt = db()->prepare('SELECT * FROM teams WHERE id = ?');
                $stmt->execute([(int) $user['team_id']]);
                $team = $stmt->fetch() ?: null;
            }
            render('orders', [
                'title' => '我的行动 · ' . APP_NAME,
                'user' => $user,
                'faction' => faction_meta(faction_by_id((int) $user['faction_id'])),
                'pending' => $pending,
                'history' => $history,
                'rank' => my_rank($user),
                'team' => $team,
                'tick' => tick_state(),
            ]);
            break;

        case 'reports':
            $nodeFilter = (int) ($_GET['node'] ?? 0);
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = 12;
            $where = '';
            $params = [];
            if ($nodeFilter > 0) {
                $where = 'WHERE b.node_id = ?';
                $params[] = $nodeFilter;
            }
            $countStmt = db()->prepare('SELECT COUNT(*) FROM battles b ' . $where);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();
            $pages = max(1, (int) ceil($total / $perPage));
            $page = min($page, $pages);
            $offset = ($page - 1) * $perPage;
            $sql = "SELECT b.*, n.name AS node_name, n.zone,
                           af.short_name AS attacker_short, df.short_name AS defender_short,
                           wf.short_name AS winner_short
                      FROM battles b
                      JOIN nodes n ON n.id = b.node_id
                      LEFT JOIN factions af ON af.id = b.attacker_faction_id
                      LEFT JOIN factions df ON df.id = b.defender_faction_id
                      LEFT JOIN factions wf ON wf.id = b.winner_faction_id
                      {$where}
                     ORDER BY b.id DESC LIMIT {$perPage} OFFSET {$offset}";
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $battles = $stmt->fetchAll();
            $nodeOptions = db()->query('SELECT id, name FROM nodes ORDER BY id')->fetchAll();
            render('reports', [
                'title' => '战报 · ' . APP_NAME,
                'battles' => $battles,
                'nodeOptions' => $nodeOptions,
                'nodeFilter' => $nodeFilter,
                'page' => $page,
                'pages' => $pages,
                'total' => $total,
                'tick' => tick_state(),
            ]);
            break;

        case 'ranking':
            $user = current_user();
            render('ranking', [
                'title' => '排行榜 · ' . APP_NAME,
                'user' => $user,
                'standings' => faction_standings(),
                'individuals' => individual_standings(20),
                'teams' => team_standings(20),
                'lastSeason' => last_finished_season(),
                'myRank' => $user ? my_rank($user) : null,
                'tick' => tick_state(),
            ]);
            break;

        case 'rules':
            render('rules', [
                'title' => '规则 · ' . APP_NAME,
                'tick' => tick_state(),
            ]);
            break;

        case 'team':
            $user = require_login();
            $pdo = db();
            if ($method === 'POST') {
                verify_csrf();
                $action = (string) ($_POST['team_action'] ?? '');

                if ($action === 'leave') {
                    $pdo->prepare('UPDATE users SET team_id = NULL WHERE id = ?')
                        ->execute([(int) $user['id']]);
                    flash('info', '已退出小队。');
                    redirect('/team');
                }

                if ($user['team_id'] !== null) {
                    flash('error', '你已经有小队了，请先退出当前小队。');
                    redirect('/team');
                }

                if ($action === 'create') {
                    $name = trim((string) ($_POST['name'] ?? ''));
                    if (!preg_match('/^[\p{Han}A-Za-z0-9_ ·]{2,20}$/u', $name)) {
                        flash('error', '小队名需为 2–20 位中文、字母、数字、空格、下划线或间隔号。');
                        redirect('/team');
                    }
                    try {
                        $pdo->prepare('INSERT INTO teams (name, faction_id, created_at) VALUES (?, ?, ?)')
                            ->execute([$name, (int) $user['faction_id'], now_utc()]);
                        $teamId = (int) $pdo->lastInsertId();
                        $pdo->prepare('UPDATE users SET team_id = ? WHERE id = ?')
                            ->execute([$teamId, (int) $user['id']]);
                        flash('success', '小队「' . $name . '」创建成功。');
                    } catch (PDOException $e) {
                        flash('error', '这个小队名已经被占用。');
                    }
                } elseif ($action === 'join') {
                    $teamId = (int) ($_POST['team_id'] ?? 0);
                    $stmt = $pdo->prepare('SELECT * FROM teams WHERE id = ?');
                    $stmt->execute([$teamId]);
                    $team = $stmt->fetch();
                    if (!$team) {
                        flash('error', '小队不存在。');
                    } elseif ((int) $team['faction_id'] !== (int) $user['faction_id']) {
                        flash('error', '只能加入本阵营的小队。');
                    } else {
                        $pdo->prepare('UPDATE users SET team_id = ? WHERE id = ?')
                            ->execute([$teamId, (int) $user['id']]);
                        flash('success', '已加入小队「' . $team['name'] . '」。');
                    }
                }
                redirect('/team');
            }

            $team = null;
            $members = [];
            if ($user['team_id'] !== null) {
                $stmt = $pdo->prepare('SELECT * FROM teams WHERE id = ?');
                $stmt->execute([(int) $user['team_id']]);
                $team = $stmt->fetch() ?: null;
                if ($team) {
                    $stmt = $pdo->prepare(
                        'SELECT id, username, contribution, wins, losses, title, last_seen_at
                           FROM users WHERE team_id = ? ORDER BY contribution DESC, id ASC'
                    );
                    $stmt->execute([(int) $team['id']]);
                    $members = $stmt->fetchAll();
                }
            }
            $teamRank = null;
            foreach (team_standings(100) as $index => $row) {
                if ($team && (int) $row['id'] === (int) $team['id']) {
                    $teamRank = $index + 1;
                    break;
                }
            }
            render('team', [
                'title' => '小队 · ' . APP_NAME,
                'user' => $user,
                'faction' => faction_meta(faction_by_id((int) $user['faction_id'])),
                'team' => $team,
                'members' => $members,
                'teams' => team_standings(12),
                'teamRank' => $teamRank,
                'tick' => tick_state(),
            ]);
            break;

        default:
            render_error(404, '页面不存在', '你走进了地图之外的一条小路。');
    }
} catch (Throwable $e) {
    error_log('[app] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (getenv('APP_DEBUG') === '1') {
        render_error(500, '调试错误', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
    render_error(500, '服务器开小差', '页面渲染时出了点问题，请稍后再试。');
}
