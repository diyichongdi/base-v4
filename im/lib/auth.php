<?php
/**
 * auth.php — 认证
 *  - 聊天：X-Auth-Token → kv users（与原 Node requireAuth 一致）
 *  - 数据空间：PHP session（服务端渲染）
 */
declare(strict_types=1);

/** 聊天接口鉴权：失败直接 401 退出 */
function chat_require_user(): array {
    $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    $user = user_by_token($token);
    if (!$user) err_res(apiL('err_not_logged_in'), 401);

    /* 全站统一账号：实时核对主站权威数据（封禁 / 角色 / 昵称），
       主站封禁、改名即时落入 IM，被删或被封直接撤销 token */
    $uid = (int)($user['id'] ?? 0);
    if ($uid > 0) {
        $mainPath = dirname(__DIR__, 2) . '/database/users.db';
        if (is_file($mainPath)) {
            $row = null;
            try {
                $m = new PDO('sqlite:' . $mainPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $st = $m->prepare('SELECT id, username, nick, is_admin, role, banned FROM users WHERE id = ?');
                $st->execute([$uid]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $row = false;
            }
            if (!$row) {
                revoke_token($token);
                err_res(apiL('err_not_logged_in'), 401);
            }
            if ((int)($row['banned'] ?? 0) === 1) {
                revoke_token($token);
                err_res(apiL('err_account_banned'), 403);
            }
            $mRole = ((int)($row['is_admin'] ?? 0) === 1) ? 'admin'
                : ((($row['role'] ?? '') === 'arbiter') ? 'arbiter' : 'user');
            $mNick = trim((string)($row['nick'] ?? '')) !== '' ? (string)$row['nick'] : (string)($row['username'] ?? '');
            $u = $user;
            if (is_array($u)) {
                $u['role'] = $mRole;
                $u['name'] = $mNick;
                $u['username'] = (string)($row['username'] ?? $u['username'] ?? '');
                $u['banned'] = 0;
                if (($user['role'] ?? '') !== $mRole || (string)($user['name'] ?? '') !== $mNick) {
                    kv_put('users', (string)$uid, $u);
                }
                $user = $u;
            }
        }
    }

    return $user;
}

/* ===================== 数据空间（PHP session） ===================== */
function tds_login(string $username, string $password): bool {
    $st = db()->prepare('SELECT * FROM tds_users WHERE username = ?');
    $st->execute([$username]);
    $row = $st->fetch();
    if (!$row) return false;
    if (!password_verify($password, $row['password'])) return false;
    $_SESSION['tds_user'] = [
        'id' => $row['id'],
        'username' => $row['username'],
        'role' => $row['role'],
    ];
    session_regenerate_id(true);
    return true;
}
function tds_logged_user(): ?array {
    return isset($_SESSION['tds_user']) ? $_SESSION['tds_user'] : null;
}
function tds_logout(): void {
    unset($_SESSION['tds_user']);
}
function tds_require_login(): array {
    $u = tds_logged_user();
    if (!$u) redirect('login.php');
    return $u;
}
function tds_require_admin(): array {
    $u = tds_require_login();
    if (($u['role'] ?? '') !== 'admin') redirect('login.php');
    return $u;
}
function tds_is_admin(): bool {
    $u = tds_logged_user();
    return $u !== null && ($u['role'] ?? '') === 'admin';
}

/** 追加数据空间日志 */
function tds_log(string $username, string $action, string $detail = ''): void {
    $role = '';
    $st = db()->prepare('SELECT role FROM tds_users WHERE username = ?');
    $st->execute([$username]);
    $r = $st->fetch();
    if ($r) $role = (string)$r['role'];
    $st = db()->prepare('INSERT INTO tds_logs (id, user, role, action, detail, created) VALUES (?, ?, ?, ?, ?, ?)');
    $st->execute([chat_uid(), $username, $role, $action, $detail, chat_now()]);
}
