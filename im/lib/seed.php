<?php
/**
 * seed.php — 首次启动种子数据（幂等）
 *  - 聊天：演示账号 + 官方频道 + 超级群组 + 官方机器人（对齐 Node 启动顺序）
 *  - 数据空间：演示账号
 */
declare(strict_types=1);

require_once __DIR__ . '/chat_bots.php';
// ensure_tds_seed() 会调用 auth.php 的 tds_log()；本文件原先未加载它，
// 导致首次建库时抛 "Call to undefined function tds_log()"。auth.php 为纯函数定义，
// 且 db() 在赋值 $pdo 之后才调 ensure_seed()，嵌套调用 db() 会直接返回，无重入风险。
require_once __DIR__ . '/auth.php';

/** 幂等：仅在无用户时初始化 */
function ensure_seed(PDO $pdo): void {
    chat_seed();
    bots_ensure();
    ensure_official_channels();
    ensure_official_group_all();
    ensure_tds_seed();
}

/* ===================== 聊天：演示账号 ===================== */
/**
 * 全站统一账号：IM 用户直接以【主站 users 表】为准，id 即主站 users.id。
 * 不再生成独立的 alice/bob/carol 等随机 u_* 演示号；仅保留官方机器人 MOSS。
 */
function chat_seed(): void {
    // 读取主站账号（独立连接，不依赖 security.php）
    $main = null;
    try {
        $main = new PDO('sqlite:' . ROOT_DIR . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'users.db');
    } catch (Throwable $e) {
        $main = null;
    }

    $mainUsers = [];
    if ($main) {
        $rows = $main->query('SELECT id, username, nick, role, is_admin, banned, created_at FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $role = ((int)($r['is_admin'] ?? 0) === 1) ? 'admin' : (($r['role'] ?? '') === 'arbiter' ? 'arbiter' : 'user');
            $mainUsers[] = [
                'id' => (string)($r['id']),
                'username' => (string)($r['username']),
                'nick' => trim((string)($r['nick'] ?? '')) !== '' ? $r['nick'] : $r['username'],
                'role' => $role,
                'banned' => (int)($r['banned'] ?? 0) === 1,
                'created_at' => trim((string)($r['created_at'] ?? '')),
            ];
        }
    }

    // 主站账号 → IM 用户（id = 主站 users.id）
    foreach ($mainUsers as $u) {
        kv_put('users', $u['id'], [
            'id' => $u['id'],
            'username' => $u['username'],
            'name' => $u['nick'],
            'avatar' => '',
            'bio' => '',
            'role' => $u['role'],
            'status' => 'offline',
            'created' => $u['created_at'] !== '' ? date('c', strtotime($u['created_at']) ?: time()) : date('c'),
            'banned' => $u['banned'],
            'note' => '全站统一账号',
        ]);
    }

    // 官方机器人 MOSS
    kv_put('users', 'u_moss', [
        'id' => 'u_moss', 'username' => 'moss', 'password' => chat_hash_pass('mossbot'),
        'name' => 'MOSS', 'avatar' => '', 'bio' => '即时通讯 官方助手机器人', 'role' => 'bot',
        'status' => 'offline', 'created' => chat_now(),
    ]);

    $admin = null;
    foreach (kv_get_all('users') as $u) {
        if (($u['role'] ?? '') === 'admin') { $admin = $u; break; }
    }
    $subs = array_map(function ($u) { return $u['id']; }, kv_get_all('users'));

    // 频道/消息仅在首次（官方频道尚不存在）时预置，避免重复堆积
    if (kv_get('channels', 'c_ann') === null) {
        kv_put('channels', 'c_ann', [
            'id' => 'c_ann', 'name' => '系统公告', 'desc' => '平台更新与通知',
            'owner' => $admin['id'] ?? '',
            'subscribers' => $subs,
            'postApproval' => false, 'adminPostOnly' => true, 'created' => chat_now(),
        ]);
        kv_put('channel_posts', 'post_welcome', [
            'id' => 'post_welcome', 'channelId' => 'c_ann', 'authorId' => $admin['id'] ?? '',
            'content' => '欢迎使用 即时通讯 即时通讯平台！', 'status' => 'approved', 'likeCount' => 0, 'created' => chat_now(),
        ]);
        kv_put('channels', 'c_official', [
            'id' => 'c_official', 'name' => '官方频道', 'desc' => '即时通讯 官方信息与公告，仅管理员可发布',
            'owner' => $admin['id'] ?? '',
            'subscribers' => $subs,
            'postApproval' => false, 'adminPostOnly' => true, 'created' => chat_now(),
        ]);
        kv_put('channel_posts', 'post_official_welcome', [
            'id' => 'post_official_welcome', 'channelId' => 'c_official', 'authorId' => $admin['id'] ?? '',
            'content' => '欢迎来到官方频道！这里将发布 即时通讯 的平台动态，仅管理员可以发帖。', 'status' => 'approved', 'likeCount' => 0, 'created' => chat_now(),
        ]);
        if ($admin) {
            kv_put('messages', chat_uid(), [
                'id' => chat_uid(), 'chatId' => 'c_official', 'sender' => $admin['id'], 'type' => 'text',
                'content' => '欢迎来到官方频道！本频道由管理员发布，大家可阅读。',
                'time' => chat_now(), 'edited' => false, 'recalled' => false, 'isExpired' => false,
            ]);
        }

        admin_log($admin['id'] ?? '', 'seed', '服务器初始化，预置演示账号');
    }
}

/** 追加管理员日志（聊天侧） */
function admin_log(string $userId, string $action, string $detail = ''): void {
    $id = chat_uid();
    kv_put('admin_logs', $id, [
        'id' => $id, 'time' => chat_now(), 'userId' => $userId, 'action' => $action, 'detail' => $detail,
    ]);
}

/* ===================== 官方频道：全员订阅（幂等） ===================== */
function ensure_official_channels(): void {
    $ensure = function (string $chId, string $name, string $desc, bool $adminPostOnly): void {
        $ch = kv_get('channels', $chId);
        $userIds = array_map(function ($u) { return $u['id']; }, kv_get_all('users'));
        $admins = kv_by_index('users', 'role', 'admin');
        $admin = $admins[0] ?? null;

        if (!$ch) {
            $ch = [
                'id' => $chId, 'name' => $name, 'desc' => $desc,
                'owner' => $admin ? $admin['id'] : '',
                'subscribers' => $userIds,
                'postApproval' => false, 'adminPostOnly' => $adminPostOnly,
                'created' => chat_now(), 'type' => 'channel',
            ];
            kv_put('channels', $chId, $ch);
        } else {
            $changed = false;
            if (($ch['adminPostOnly'] ?? null) !== $adminPostOnly) { $ch['adminPostOnly'] = $adminPostOnly; $changed = true; }
            if (!isset($ch['subscribers']) || !is_array($ch['subscribers'])) { $ch['subscribers'] = []; }
            foreach ($userIds as $id) {
                if (!in_array($id, $ch['subscribers'], true)) { $ch['subscribers'][] = $id; $changed = true; }
            }
            if ($changed) kv_put('channels', $chId, $ch);
        }

        $hasWelcomePost = false;
        foreach (kv_get_all('channel_posts') as $p) {
            if (($p['channelId'] ?? '') === $chId && mb_strpos((string)($p['content'] ?? ''), '欢迎') !== false) { $hasWelcomePost = true; break; }
        }
        if (!$hasWelcomePost) {
            kv_put('channel_posts', 'post_' . $chId . '_welcome', [
                'id' => 'post_' . $chId . '_welcome', 'channelId' => $chId,
                'authorId' => $admin ? $admin['id'] : '',
                'content' => '欢迎来到' . $name . '！' . ($adminPostOnly ? '本频道仅管理员可发布。' : ''),
                'status' => 'approved', 'likeCount' => 0, 'created' => chat_now(),
            ]);
        }
        $hasWelcomeMsg = false;
        if ($admin) {
            foreach (kv_by_index('messages', 'chatId', $chId) as $m) {
                if (mb_strpos((string)($m['content'] ?? ''), '欢迎') !== false) { $hasWelcomeMsg = true; break; }
            }
        }
        if ($admin && !$hasWelcomeMsg) {
            kv_put('messages', chat_uid(), [
                'id' => chat_uid(), 'chatId' => $chId, 'sender' => $admin['id'], 'type' => 'text',
                'content' => '欢迎来到' . $name . '！' . ($adminPostOnly ? '本频道由管理员发布，大家可阅读。' : ''),
                'time' => chat_now(), 'edited' => false, 'recalled' => false, 'isExpired' => false,
            ]);
        }
    };

    $ensure('c_ann', '系统公告', '平台更新与通知', true);
    $ensure('c_official', '官方频道', '即时通讯 官方信息与公告，仅管理员可发布', true);

    // 用户自建频道：无任何发布限制时补 ownerPostOnly（兼容历史库）
    foreach (kv_get_all('channels') as $ch) {
        if (($ch['id'] ?? '') === 'c_ann' || ($ch['id'] ?? '') === 'c_official') continue;
        if (empty($ch['ownerPostOnly']) && empty($ch['adminPostOnly'])) {
            $ch['ownerPostOnly'] = true;
            kv_put('channels', $ch['id'], $ch);
        }
    }
}

/* ===================== 超级群组：全员自动加入（幂等） ===================== */
function ensure_official_group_all(): void {
    ensure_official_group('');
}

/** 等价 Node ensureOfficialGroup(userId) */
function ensure_official_group(string $userId): bool {
    $admins = kv_by_index('users', 'role', 'admin');
    $admin = $admins[0] ?? null;
    $g = kv_get('groups', 'g_official_group');

    if (!$g) {
        $members = array_map(function ($u) {
            return ['id' => $u['id'], 'role' => ($u['role'] ?? '') === 'admin' ? 'owner' : 'member'];
        }, array_filter(kv_get_all('users'), function ($u) { return ($u['role'] ?? '') !== 'bot'; }));
        $g = [
            'id' => 'g_official_group', 'name' => '即时通讯 官方群', 'desc' => '所有用户自动加入的超级群组',
            'type' => 'super', 'owner' => $admin ? $admin['id'] : '', 'members' => $members, 'created' => chat_now(),
        ];
        kv_put('groups', $g['id'], $g);
        if ($admin) {
            kv_put('messages', chat_uid(), [
                'id' => chat_uid(), 'chatId' => 'g_official_group', 'sender' => $admin['id'], 'type' => 'text',
                'content' => '欢迎来到 即时通讯 官方群！所有用户自动加入的超级群组。',
                'time' => chat_now(), 'edited' => false, 'recalled' => false, 'isExpired' => false,
            ]);
        }
        return true;
    }
    if ($userId !== '' && !in_array($userId, array_map(function ($m) { return $m['id']; }, $g['members'] ?? []), true)) {
        $g['members'][] = ['id' => $userId, 'role' => 'member'];
        kv_put('groups', $g['id'], $g);
        return true;
    }
    return false;
}

/** 登录时自动订阅官方频道 + 入群（幂等） */
function subscribe_channels(array $user): void {
    foreach (['c_ann', 'c_official'] as $chId) {
        $ch = kv_get('channels', $chId);
        if ($ch && isset($ch['subscribers']) && is_array($ch['subscribers'])) {
            if (!in_array($user['id'], $ch['subscribers'], true)) {
                $ch['subscribers'][] = $user['id'];
                kv_put('channels', $chId, $ch);
            }
            event_send($user['id'], 'channel-subscribe', ['channelId' => $chId]);
        }
    }
}

/** 登录时确保入群，新加入时推送刷新 */
function ensure_group_membership(array $user): void {
    if (ensure_official_group($user['id'] ?? '')) {
        event_send($user['id'] ?? '', 'group-update', ['groupId' => 'g_official_group']);
    }
}

/* ===================== 数据空间演示账号 ===================== */
function ensure_tds_seed(): void {
    $st = db()->query('SELECT COUNT(*) AS n FROM tds_users');
    if ((int)$st->fetch()['n'] > 0) return;
    $ins = db()->prepare('INSERT INTO tds_users (id, username, password, role, created) VALUES (?, ?, ?, ?, ?)');
    $ins->execute(['u_admin', 'admin', password_hash('123456', PASSWORD_DEFAULT), 'admin', chat_now()]);
    $ins->execute(['u_user', 'user', password_hash('123456', PASSWORD_DEFAULT), 'user', chat_now()]);
    tds_log('system', 'seed', '数据空间初始化，预置演示账号 admin/user（密码 123456）');
}
