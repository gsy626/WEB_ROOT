<?php
declare(strict_types=1);

function current_user(): ?array
{
    static $resolved = false;
    static $user = null;
    if ($resolved) {
        return $user;
    }
    $resolved = true;

    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id) && !ctype_digit((string) $id)) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch();
    if ($row === false) {
        unset($_SESSION['user_id']);
        return null;
    }

    $row = ensure_daily_ap($row);

    $lastSeen = $row['last_seen_at'];
    if (!$lastSeen || strtotime($lastSeen . ' UTC') < time() - 60) {
        db()->prepare('UPDATE users SET last_seen_at = ? WHERE id = ?')
            ->execute([now_utc(), (int) $row['id']]);
        $row['last_seen_at'] = now_utc();
    }

    return $user = $row;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('info', '请先登录，再进入战场。');
        redirect('/login');
    }
    return $user;
}

function login_user(int $userId): void
{
    session_regenerate();
    $_SESSION['user_id'] = $userId;
    unset($_SESSION['csrf']);
}

function logout_user(): void
{
    unset($_SESSION['user_id']);
    session_regenerate();
}

/**
 * @return array{ok: bool, error?: string, user?: array}
 */
function attempt_login(string $username, string $password): array
{
    $now = time();
    $fails = $_SESSION['login_fails'] ?? [];
    $fails = array_values(array_filter($fails, static fn ($t) => is_int($t) && $t > $now - 600));
    $_SESSION['login_fails'] = $fails;
    if (count($fails) >= 6) {
        return ['ok' => false, 'error' => '尝试过于频繁，请 10 分钟后再试。'];
    }

    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => '请输入用户名和密码。'];
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user === false || !password_verify($password, $user['password_hash'])) {
        $_SESSION['login_fails'][] = $now;
        return ['ok' => false, 'error' => '用户名或密码不正确。'];
    }

    unset($_SESSION['login_fails']);
    login_user((int) $user['id']);
    return ['ok' => true, 'user' => $user];
}

/**
 * @return array{ok: bool, error?: string, user?: array}
 */
function attempt_register(array $input): array
{
    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $confirm = (string) ($input['password_confirm'] ?? '');
    $factionCode = (string) ($input['faction'] ?? '');
    $teamName = trim((string) ($input['team_name'] ?? ''));

    if (!preg_match('/^[\p{Han}A-Za-z0-9_]{2,16}$/u', $username)) {
        return ['ok' => false, 'error' => '用户名需为 2–16 位中文、字母、数字或下划线。'];
    }
    if (mb_strlen($password) < 8) {
        return ['ok' => false, 'error' => '密码至少 8 位。'];
    }
    if (strlen($password) > 72) {
        return ['ok' => false, 'error' => '密码过长，请控制在 72 字节以内。'];
    }
    if ($password !== $confirm) {
        return ['ok' => false, 'error' => '两次输入的密码不一致。'];
    }
    $faction = faction_by_code($factionCode);
    if (!$faction) {
        return ['ok' => false, 'error' => '请选择一个阵营。'];
    }
    if ($teamName !== '' && !preg_match('/^[\p{Han}A-Za-z0-9_ ·]{2,20}$/u', $teamName)) {
        return ['ok' => false, 'error' => '小队名需为 2–20 位中文、字母、数字、空格、下划线或间隔号。'];
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'error' => '这个用户名已经被使用。'];
    }

    $pdo->beginTransaction();
    try {
        $teamId = null;
        if ($teamName !== '') {
            $stmt = $pdo->prepare('SELECT * FROM teams WHERE name = ?');
            $stmt->execute([$teamName]);
            $team = $stmt->fetch();
            if ($team !== false) {
                if ((int) $team['faction_id'] !== (int) $faction['id']) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => '该小队属于另一阵营，无法加入。'];
                }
                $teamId = (int) $team['id'];
            } else {
                $pdo->prepare('INSERT INTO teams (name, faction_id, created_at) VALUES (?, ?, ?)')
                    ->execute([$teamName, (int) $faction['id'], now_utc()]);
                $teamId = (int) $pdo->lastInsertId();
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users
                (username, password_hash, faction_id, team_id, ap, ap_day, created_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            (int) $faction['id'],
            $teamId,
            AP_MAX,
            local_date(),
            now_utc(),
            now_utc(),
        ]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE')) {
            return ['ok' => false, 'error' => '用户名或小队名刚刚被占用，请换一个。'];
        }
        throw $e;
    }

    login_user($userId);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return ['ok' => true, 'user' => $stmt->fetch()];
}
