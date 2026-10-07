<?php
/**
 * api/index.php — /api 前端控制器（php -S 经 router.php、Apache 经 .htaccess 转发）
 *
 * 兼容前端 js/chat.js 的全部契约（含历史双前缀 /api/api/... 归一化）：
 *   /api/auth  /api/logout  /api/auth/device/set-password
 *   /api/db/:store[/:id|/count?idx=&val=]
 *   /api/events(SSE)  /api/typing  /api/notify  /api/report
 *   /api/bots[/:username]  /api/admin/broadcast[/s/history]
 *   /api/ft/upload|/meta|/:code
 *   /api/novel/*（小说）  /api/userdata/*（工具·桌面）
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/chat_bots.php';

/* ===================== 认证 ===================== */
function api_auth_route(array $segs): void {
    if (($segs[1] ?? '') === 'device' && ($segs[2] ?? '') === 'set-password') {
        api_set_device_password();
        return;
    }
    api_auth();
}

function api_auth(): void {
    /* 全站统一账号：IM 不再提供独立登录接口，请从主站登录后进入即时通讯 */
    err_res(apiL('err_unified_account'), 403);
    return;

    $body = req_json();
    $type = (string)($body['type'] ?? '');
    if ($type === '') err_res(apiL('err_missing_login_type'));
    $user = null;
    $needSetPassword = false;

    if ($type === 'password') {
        $username = trim((string)($body['username'] ?? ''));
        $secret = (string)($body['secret'] ?? '');
        $found = kv_by_index('users', 'username', $username);
        if (!$found) err_res(apiL('err_username_not_found'), 401);
        $pc = null;
        foreach (kv_by_index('auth_creds', 'userId', $found[0]['id']) as $c) {
            if (($c['loginType'] ?? '') === 'password') { $pc = $c; break; }
        }
        if (!$pc || ($pc['credentialHash'] ?? '') !== $secret) err_res(apiL('err_pwd_incorrect'), 401);
        $user = $found[0];
    } elseif ($type === 'device') {
        $ident = (string)($body['identifier'] ?? '');
        $secret = $body['secret'] ?? null;
        $creds = kv_by_index('auth_creds', 'identifier', $ident);
        if (count($creds)) {
            $devCred = null;
            foreach ($creds as $c) { if (($c['loginType'] ?? '') === 'device') { $devCred = $c; break; } }
            $devCred = $devCred ?: $creds[0];
            $user = kv_get('users', $devCred['userId'] ?? '');
            if (!$user) err_res(apiL('err_user_not_found'), 401);
            if (empty($user['devicePwdSet'])) {
                $needSetPassword = true;
            } elseif ($secret === null || chat_hash_pass((string)$secret) !== (string)($user['devicePasswordHash'] ?? '')) {
                err_res(apiL('err_device_pwd_required'), 401, ['needPassword' => true]);
            }
        } else {
            // 首次设备登录：允许无密码创建
            $id = 'u_' . chat_uid();
            $user = [
                'id' => $id, 'username' => 'dev_' . substr($id, -6), 'name' => '设备用户_' . substr($id, -4),
                'avatar' => '', 'bio' => '', 'role' => 'user', 'status' => 'online', 'created' => chat_now(),
                'devicePwdSet' => false, 'devicePasswordHash' => '',
            ];
            kv_put('users', $id, $user);
            kv_put('auth_creds', chat_uid(), [
                'id' => chat_uid(), 'userId' => $id, 'loginType' => 'device', 'identifier' => $ident,
                'credentialHash' => chat_hash_pass((string)($secret === null ? $ident : $secret)), 'createdAt' => chat_now(), 'passwordSet' => $secret !== null && $secret !== '',
            ]);
            kv_put('devices', chat_uid(), [
                'id' => chat_uid(), 'userId' => $id, 'deviceName' => 'Server device',
                'deviceFingerprint' => $ident, 'lastIp' => client_ip(), 'lastActiveAt' => chat_now(), 'isTrusted' => true,
            ]);
            $token = issue_token($id);
            admin_log($id, 'login', '设备一键登录（首次），提醒设置密码');
            subscribe_channels($user);
            ensure_group_membership($user);
            event_send_to_online('user-online', ['userId' => $id, 'name' => $user['name'] ?: $user['username'], 'status' => 'online']);
            event_send($id, 'device-need-set-password', new stdClass());
            ok_res(['token' => $token, 'user' => $user, 'needSetPassword' => true]);
        }
    } elseif ($type === 'key') {
        $ident = (string)($body['identifier'] ?? '');
        $creds = kv_by_index('auth_creds', 'identifier', $ident);
        if (count($creds)) {
            $user = kv_get('users', $creds[0]['userId'] ?? '');
            if (!$user) err_res(apiL('err_user_not_found'), 401);
        } else {
            $id = 'u_' . chat_uid();
            $code = strtoupper(substr(chat_uid(), 0, 6));
            $user = [
                'id' => $id, 'username' => 'sen_' . $code, 'name' => '密匙用户_' . $code,
                'avatar' => '', 'bio' => '', 'role' => 'user', 'status' => 'online', 'created' => chat_now(),
            ];
            kv_put('users', $id, $user);
            kv_put('auth_creds', chat_uid(), [
                'id' => chat_uid(), 'userId' => $id, 'loginType' => 'key', 'identifier' => $ident,
                'credentialHash' => $ident, 'createdAt' => chat_now(),
            ]);
        }
    } elseif ($type === 'register') {
        $uname = trim((string)($body['username'] ?? ''));
        $secret = (string)($body['secret'] ?? '');
        if ($uname === '' || $secret === '') err_res(apiL('err_fill_creds'));
        if (count(kv_by_index('users', 'username', $uname))) err_res(apiL('err_username_taken'), 409);
        $id = 'u_' . chat_uid();
        $user = [
            'id' => $id, 'username' => $uname, 'password' => $secret, 'name' => $uname,
            'avatar' => '', 'bio' => '', 'role' => 'user', 'status' => 'online', 'created' => chat_now(),
        ];
        kv_put('users', $id, $user);
        kv_put('auth_creds', chat_uid(), [
            'id' => chat_uid(), 'userId' => $id, 'loginType' => 'password', 'identifier' => $uname,
            'credentialHash' => $secret, 'createdAt' => chat_now(),
        ]);
    } else {
        err_res(apiL('err_unsupported_login_type'));
    }

    $user['status'] = 'online';
    kv_put('users', $user['id'], $user);
    subscribe_channels($user);
    ensure_group_membership($user);

    $token = issue_token($user['id']);
    admin_log($user['id'], 'login', '用户通过 ' . $type . ' 登录');
    event_send_to_online('user-online', ['userId' => $user['id'], 'name' => $user['name'] ?: $user['username'], 'status' => 'online']);
    if ($needSetPassword) {
        event_send($user['id'], 'device-need-set-password', new stdClass());
        ok_res(['token' => $token, 'user' => $user, 'needSetPassword' => true]);
    }
    ok_res(['token' => $token, 'user' => $user]);
}

function api_set_device_password(): void {
    $user = chat_require_user();
    $secret = (string)(req_json()['secret'] ?? '');
    if (strlen($secret) < 4) err_res(apiL('err_pwd_min4'));
    $u = kv_get('users', $user['id']);
    if (!$u) err_res(apiL('err_user_not_found'), 404);
    $u['devicePasswordHash'] = chat_hash_pass($secret);
    $u['devicePwdSet'] = true;
    kv_put('users', $u['id'], $u);
    ok_res(['passwordSet' => true]);
}

function api_logout(): void {
    $user = chat_require_user();
    revoke_token((string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? ''));
    $user['status'] = 'offline';
    kv_put('users', $user['id'], $user);
    sse_clear($user['id']);
    event_send_to_online('user-online', ['userId' => $user['id'], 'name' => $user['name'] ?: $user['username'], 'status' => 'offline']);
    ok_res([]);
}

/* ===================== 通用数据接口 ===================== */
function api_db_route(array $segs, string $method): void {
    $user = chat_require_user();
    $store = (string)($segs[1] ?? '');
    if ($store === '') err_res(apiL('err_no_table'));
    $id = isset($segs[2]) ? urldecode((string)$segs[2]) : '';

    if ($method === 'GET') {
        if ($id === 'count') {
            ok_res(['count' => kv_count($store)]);
            return;
        }
        if ($id !== '') {
            $data = kv_get($store, $id);
            if (!$data) err_res(apiL('err_no_record'), 404);
            ok_res(['data' => $data]);
            return;
        }
        $idx = (string)($_GET['idx'] ?? '');
        $val = $_GET['val'] ?? null;
        if ($idx !== '' && $val !== null) {
            ok_res(['list' => kv_by_index($store, $idx, $val)]);
        } else {
            ok_res(['list' => kv_get_all($store)]);
        }
        return;
    }

    if ($method === 'POST') {
        $body = req_json();
        $data = $body['data'] ?? null;
        if (!is_array($data)) err_res(apiL('err_missing_data'));
        $newId = $data['id'] ?? chat_uid();
        if (!isset($data['id'])) $data['id'] = $newId;

        if ($store === 'channel_posts' && !empty($data['channelId']) && !kv_get('channel_posts', $newId)) {
            $ch = kv_get('channels', (string)$data['channelId']);
            if ($ch) {
                if (!empty($ch['adminPostOnly']) && ($user['role'] ?? '') !== 'admin') {
                    err_res(apiL('err_channel_admin_only'), 403);
                }
                if (!empty($ch['ownerPostOnly']) && ($user['role'] ?? '') !== 'admin' && ($user['id'] ?? '') !== ($ch['owner'] ?? '')) {
                    err_res(apiL('err_channel_owner_only'), 403);
                }
            }
        }
        if ($store === 'messages' && !empty($data['chatId'])) {
            $ch = kv_get('channels', (string)$data['chatId']);
            if ($ch) {
                if (!empty($ch['adminPostOnly']) && ($user['role'] ?? '') !== 'admin') {
                    err_res(apiL('err_channel_admin_only'), 403);
                }
                if (!empty($ch['ownerPostOnly']) && ($user['role'] ?? '') !== 'admin' && ($user['id'] ?? '') !== ($ch['owner'] ?? '')) {
                    err_res(apiL('err_channel_owner_only'), 403);
                }
            }
        }

        if ($store === 'messages') {
            $existed = kv_get('messages', $newId);
            if ($existed) {
                event_send_to_chat((string)($data['chatId'] ?? ''), 'message-update', ['id' => $newId, 'message' => $data]);
            } else {
                event_send_to_chat((string)($data['chatId'] ?? ''), 'message', ['id' => $newId, 'message' => $data]);
                bots_on_message($data);
            }
        }

        kv_put($store, $newId, $data);

        if ($store === 'contacts' && !empty($data['owner']) && !empty($data['contactId'])) {
            $revId = $data['contactId'] . '|' . $data['owner'];
            if (!kv_get('contacts', $revId)) {
                kv_put('contacts', $revId, [
                    'id' => $revId,
                    'owner' => $data['contactId'], 'contactId' => $data['owner'],
                    'created' => $data['created'] ?? chat_now(),
                ]);
            }
            event_send((string)$data['contactId'], 'contact-added', ['owner' => $data['contactId'], 'contactId' => $data['owner']]);
        }
        if ($store === 'groups' && isset($data['members']) && is_array($data['members'])) {
            $ids = array_values(array_filter(array_map(function ($m) {
                return is_array($m) && isset($m['id']) ? $m['id'] : null;
            }, $data['members'])));
            event_send_to_users($ids, 'group-update', ['groupId' => $newId]);
        }
        ok_res(['id' => $newId]);
    }

    if ($method === 'DELETE') {
        if ($store === 'messages') {
            $msg = kv_get('messages', $id);
            if ($msg) {
                event_send_to_chat((string)($msg['chatId'] ?? ''), 'message-delete', ['id' => $id, 'chatId' => $msg['chatId'] ?? '']);
            }
        }
        kv_del($store, $id);
        ok_res([]);
    }

    err_res(apiL('err_no_method'));
}

/* ===================== SSE 实时推送 ===================== */
function api_events_sse(): void {
    $user = chat_require_user();
    $userId = $user['id'];

    maybe_event_gc();

    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', 'off');
    @ini_set('implicit_flush', '1');
    @set_time_limit(0);
    @ob_end_clean();

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    echo "retry: 3000\n\n";
    flush();

    $lastSeq = sse_get_cursor($userId);
    // 触碰游标更新时间，用于在线状态判定（不删游标：删除会让下次重连从 seq=0 重放全部历史事件，引发请求风暴）
    sse_set_cursor($userId, $lastSeq);

    // cli-server 单线程：SSE 长期占用唯一工作进程会阻塞所有并发请求（连接被拒/超时）。
    // 退化为「单次轮询即返回」，客户端 3s 重连形成短轮询，符合 readme 的 cli-server 降级设计。
    if (is_cli_server()) {
        $events = event_poll($userId, $lastSeq);
        if (count($events)) {
            foreach ($events as $ev) {
                echo 'event: ' . $ev['event'] . "\n";
                echo 'data: ' . $ev['data'] . "\n\n";
                $lastSeq = (int)$ev['seq'];
            }
            sse_set_cursor($userId, $lastSeq);
            flush();
        }
        exit;
    }

    $start = microtime(true);
    $idle = 0.0;
    $pollMs = is_cli_server() ? 1500000 : 500000; // cli-server 短轮询，真实服务器 0.5s
    $maxDur = is_cli_server() ? 2.0 : 30.0;
    $maxIdle = is_cli_server() ? 0.0 : 25.0;

    while (true) {
        $events = event_poll($userId, $lastSeq);
        if (count($events)) {
            foreach ($events as $ev) {
                echo 'event: ' . $ev['event'] . "\n";
                echo 'data: ' . $ev['data'] . "\n\n";
                $lastSeq = (int)$ev['seq'];
            }
            sse_set_cursor($userId, $lastSeq);
            flush();
        }
        $elapsed = microtime(true) - $start;
        if (connection_aborted()) break;
        if ($elapsed >= $maxDur) break;
        if ($maxIdle > 0 && $idle >= $maxIdle) break;
        if ($pollMs > 0) {
            usleep($pollMs);
            $idle += $pollMs / 1000000;
        }
    }
    exit;
}

/* ===================== 输入状态 / 通知 / 举报 ===================== */
function api_typing(): void {
    $user = chat_require_user();
    $body = req_json();
    $chatId = (string)($body['chatId'] ?? '');
    if ($chatId === '') err_res(apiL('err_missing_chatid'));
    event_send_to_chat($chatId, 'typing', [
        'chatId' => $chatId, 'userId' => $user['id'],
        'name' => $user['name'] ?: $user['username'], 'typing' => !empty($body['typing']),
    ]);
    ok_res([]);
}

function api_notify(): void {
    $body = req_json();
    $userId = (string)($body['userId'] ?? '');
    $type = (string)($body['type'] ?? '');
    if ($userId === '' || $type === '') err_res(apiL('err_missing_userid_type'));
    event_send($userId, 'notification', ['type' => $type, 'extra' => $body['extra'] ?? new stdClass()]);
    ok_res([]);
}

function api_report(): void {
    $user = chat_require_user();
    $body = req_json();
    $targetUserId = (string)($body['targetUserId'] ?? '');
    if ($targetUserId === '') err_res(apiL('err_missing_target'));
    if ($targetUserId === $user['id']) err_res(apiL('err_no_self_report'));
    if (!kv_get('users', $targetUserId)) err_res(apiL('err_user_not_found'), 404);
    $id = chat_uid();
    kv_put('reports', $id, [
        'id' => $id, 'targetUserId' => $targetUserId, 'reporterId' => $user['id'],
        'reason' => (string)($body['reason'] ?? apiL('not_filled')), 'time' => chat_now(), 'status' => 'pending',
    ]);
    $tu = kv_get('users', $targetUserId);
    foreach (kv_by_index('users', 'role', 'admin') as $a) {
        event_send($a['id'], 'notification', [
            'type' => 'report',
            'extra' => [
                'reportId' => $id, 'targetUsername' => $tu['username'] ?? '',
                'reporterName' => $user['name'] ?: $user['username'], 'reason' => (string)($body['reason'] ?? ''),
            ],
        ]);
    }
    ok_res(['id' => $id]);
}

/* ===================== 机器人 ===================== */
function api_bots_route(array $segs): void {
    chat_require_user();
    $username = strtolower((string)($segs[1] ?? ''));
    if ($username === '') {
        $list = array_map(function ($b) {
            return [
                'id' => $b['id'], 'username' => $b['username'], 'name' => $b['name'],
                'kind' => $b['kind'] ?? '', 'template' => $b['template'] ?? null,
                'isOfficial' => !empty($b['isOfficial']), 'desc' => $b['desc'] ?? '',
                'note' => $b['note'] ?? '', 'status' => $b['status'] ?? '',
            ];
        }, kv_get_all('bots'));
        ok_res(['list' => $list]);
    }
    $rec = kv_get('bots', 'u_' . $username);
    if (!$rec || ($rec['status'] ?? '') !== 'active') err_res(apiL('err_bot_not_found'), 404);
    ok_res(['data' => [
        'id' => $rec['id'], 'username' => $rec['username'], 'name' => $rec['name'],
        'kind' => $rec['kind'] ?? '', 'template' => $rec['template'] ?? null,
        'isOfficial' => !empty($rec['isOfficial']), 'desc' => $rec['desc'] ?? '',
        'note' => $rec['note'] ?? '',
        'commands' => $rec['commands'] ?? new stdClass(), 'keywords' => $rec['keywords'] ?? [],
    ]]);
}

/* ===================== 管理员广播 ===================== */
function api_admin_route(array $segs): void {
    $user = chat_require_user();
    $sub = (string)($segs[1] ?? '');
    // 前端同时存在 /admin/broadcast 与 /admin/broadcasts 两种写法，统一接受
    $isBroadcast = ($sub === 'broadcast' || $sub === 'broadcasts');
    if ($isBroadcast && ($segs[2] ?? '') === 'history') {
        if (($user['role'] ?? '') !== 'admin') err_res(apiL('err_admin_only'), 403);
        $list = kv_get_all('broadcasts');
        usort($list, function ($a, $b) { return strcmp((string)($b['time'] ?? ''), (string)($a['time'] ?? '')); });
        ok_res(['list' => $list]);
    }
    if ($isBroadcast) {
        if (($user['role'] ?? '') !== 'admin') err_res(apiL('err_admin_only'), 403);
        $body = req_json();
        $title = (string)($body['title'] ?? apiL('admin_broadcast_label'));
        $text = (string)($body['text'] ?? '');
        if (trim($text) === '') err_res(apiL('err_broadcast_empty'));
        $id = chat_uid();
        $rec = [
            'id' => $id, 'title' => trim($title), 'text' => trim($text),
            'authorId' => $user['id'], 'authorName' => $user['name'] ?: $user['username'], 'time' => chat_now(),
        ];
        kv_put('broadcasts', $id, $rec);
        event_send_to_online('broadcast', [
            'id' => $id, 'title' => $rec['title'], 'text' => $rec['text'],
            'from' => $user['id'], 'name' => $rec['authorName'], 'time' => $rec['time'],
        ]);
        ok_res(['id' => $id]);
    }
    err_res(apiL('err_no_endpoint'), 404);
}

/* ===================== 文件快传 ===================== */
function api_ft_route(array $segs, string $method): void {
    $sub = (string)($segs[1] ?? '');
    $code = strtoupper(trim((string)$sub));

    if ($method === 'POST' && $code === 'UPLOAD') {
        $raw = req_raw();
        if ($raw === '') err_res(apiL('err_empty_file'));
        if (strlen($raw) > FT_MAX_BYTES) err_res(apiL('err_file_too_big'));
        api_ft_cleanup();
        if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0777, true);
        do { $code = api_ft_code(); } while (file_exists(UPLOAD_DIR . '/' . $code . '.bin'));
        $name = substr((string)($_SERVER['HTTP_X_FILENAME'] ?? 'file'), 0, 200);
        $size = strlen($raw);
        $type = (string)($_SERVER['HTTP_X_TYPE'] ?? '');
        file_put_contents(UPLOAD_DIR . '/' . $code . '.bin', $raw);
        $st = db()->prepare('INSERT INTO ft_files (code, name, type, size, path, created) VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([$code, $name, $type, $size, $code . '.bin', chat_now()]);
        ok_res(['code' => $code, 'name' => $name, 'size' => $size]);
    }

    if ($code === '') err_res(apiL('err_no_endpoint'), 404);

    if ($method === 'GET' && ($segs[2] ?? '') === 'meta') {
        $meta = api_ft_meta($code);
        if (!$meta) err_res(apiL('err_code_expired'), 404);
        ok_res(['code' => $meta['code'], 'name' => $meta['name'], 'size' => (int)$meta['size'], 'type' => $meta['type'], 'created' => (int)$meta['created']]);
    }

    if ($method === 'GET') {
        $meta = api_ft_meta($code);
        $file = UPLOAD_DIR . '/' . $code . '.bin';
        if (!file_exists($file)) err_res(apiL('err_code_expired'), 404);
        $name = safe_name($meta ? $meta['name'] : 'file');
        header('Content-Type: ' . ($meta && $meta['type'] ? $meta['type'] : 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    err_res(apiL('err_no_endpoint'), 404);
}
function api_ft_code(): string {
    $c = '';
    $len = strlen(FT_CHARS);
    for ($i = 0; $i < 6; $i++) $c .= FT_CHARS[mt_rand(0, $len - 1)];
    return $c;
}
function api_ft_meta(string $code) {
    $st = db()->prepare('SELECT * FROM ft_files WHERE code = ?');
    $st->execute([$code]);
    return $st->fetch() ?: null;
}
function api_ft_cleanup(): void {
    $cutoff = chat_now() - FT_TTL * 1000;
    $st = db()->prepare('SELECT code, path FROM ft_files WHERE created < ?');
    $st->execute([$cutoff]);
    foreach ($st->fetchAll() as $row) {
        @unlink(UPLOAD_DIR . '/' . $row['path']);
        db()->prepare('DELETE FROM ft_files WHERE code = ?')->execute([$row['code']]);
    }
}

/* ===================== 入口 ===================== */
$__method = $_SERVER['REQUEST_METHOD'];
$__uri = $_SERVER['REQUEST_URI'] ?? '/';
$__path = parse_url($__uri, PHP_URL_PATH) ?: '/';

// 历史代码把 /api/xxx 打成了 /api/api/xxx，统一归一化
while (starts_with($__path, '/api/api')) { $__path = substr($__path, 4); }
if (starts_with($__path, '/api')) { $__path = substr($__path, 4); }

$__segs = array_values(array_filter(explode('/', trim($__path, '/')), function ($s) { return $s !== ''; }));
$__top = $__segs[0] ?? '';

switch ($__top) {
    case 'auth': api_auth_route($__segs); break;
    case 'logout': api_logout(); break;
    case 'db': api_db_route($__segs, $__method); break;
    case 'events': api_events_sse(); break;
    case 'typing': api_typing(); break;
    case 'notify': api_notify(); break;
    case 'report': api_report(); break;
    case 'bots': api_bots_route($__segs); break;
    case 'admin': api_admin_route($__segs); break;
    case 'ft': api_ft_route($__segs, $__method); break;
    case 'novel':
        require_once __DIR__ . '/novel.php';
        novel_route($__segs, $__method);
        break;
    case 'userdata':
        require_once __DIR__ . '/userdata.php';
        userdata_route($__segs, $__method);
        break;
    default:
        err_res(apiL('err_no_endpoint'), 404);
}
