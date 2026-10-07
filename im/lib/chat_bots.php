<?php
/**
 * chat_bots.php — 聊天机器人引擎（PHP 移植自 chat-server/bots.js）
 *
 * 架构与原版一致：users 表中 role='bot' 的账号 + 'bots' store 的元数据。
 * 用户给机器人发私聊时，由 bots_on_message() 生成机器人回复，写入同一会话并推送 SSE。
 */
declare(strict_types=1);

const BOTS_FILTERED_WORDS = ['spam', '赌博', '色情', '暴力', '博彩', '代购', '刷单', '兼职'];

const BOTS_OFFICIAL = [
    ['username' => 'moss', 'name' => 'MOSS', 'bio' => "即时通讯 官方助手机器人\n使用方法和命令，发送 /start 或 /help 查看全部功能", 'note' => '官方助手机器人'],
    ['username' => 'spam_bot', 'name' => '垃圾审查员', 'bio' => "账号审查 / 垃圾检测 / 举报处理\n使用方法：/check <用户名> 检查账号；/report <用户名> <原因> 举报", 'note' => '账号检测与举报机器人'],
    ['username' => 'bot_father', 'name' => '机器人之父', 'bio' => "创建和管理你自己的机器人\n使用方法：发送 /newbot 开始创建专属机器人", 'note' => '创建机器人工具的官方机器人'],
];

const BOTS_TEMPLATES = [
    'echo' => ['name' => '复读机', 'desc' => '将收到的消息原样回复', 'note' => '复读机模板机器人'],
    'dice' => ['name' => '骰子', 'desc' => '/dice 掷骰子；/num 随机数字', 'note' => '骰子模板机器人'],
    'keyword' => ['name' => '关键词回复', 'desc' => '设置关键词自动回复，可使用 /setkeyword 配置', 'note' => '关键词回复模板机器人'],
];

const BOTS_RESERVED = ['admin', 'moss', 'spam_bot', 'bot_father'];

function bots_parse_cmd(string $text): array {
    $text = trim((string)$text);
    if ($text === '' || $text[0] !== '/') return ['name' => '', 'args' => $text];
    $parts = preg_split('/\s+/', $text);
    $name = strtolower($parts[0]);
    $args = trim((string)implode(' ', array_slice($parts, 1)));
    return ['name' => $name, 'args' => $args];
}

function bots_fmt_time($t): string {
    if (!$t) return '未知';
    return date('Y-m-d H:i:s', (int)floor((int)$t / 1000));
}

function bots_user_to_bot_id(string $username): string { return 'u_' . $username; }

function bots_is_bot(string $uid): bool {
    $u = kv_get('users', $uid);
    if (!$u) return false;
    if (($u['role'] ?? '') === 'bot') return true;
    return kv_get('bots', $uid) !== null;
}

function bots_gen_token(): string {
    return 'b' . substr(chat_uid(), 0, 10) . ':' . substr(chat_uid(), 0, 22);
}

function bots_is_reserved(string $username): bool {
    if (in_array($username, BOTS_RESERVED, true)) return true;
    return count(kv_by_index('users', 'username', $username)) > 0;
}

function bots_reply(string $chatId, string $botId, string $text): void {
    $m = [
        'id' => chat_uid(), 'chatId' => $chatId, 'sender' => $botId, 'type' => 'text',
        'content' => $text, 'time' => chat_now(), 'edited' => false, 'recalled' => false, 'isExpired' => false,
    ];
    kv_put('messages', $m['id'], $m);
    event_send_to_chat($chatId, 'message', ['id' => $m['id'], 'message' => $m]);
}

function bots_resolve_target(string $raw) {
    if ($raw === '') return null;
    $key = str_replace('@', '', ltrim($raw));
    if ($key === '') return null;
    $byName = kv_by_index('users', 'username', $key);
    if (count($byName)) return $byName[0];
    return kv_get('users', $key) ?: null;
}

/* ===================== 垃圾评分 / 账号报告 ===================== */
function bots_spam_score(array $target): array {
    $score = 0;
    $reports = kv_by_index('reports', 'targetUserId', $target['id']);
    $pending = count(array_filter($reports, function ($r) { return ($r['status'] ?? '') === 'pending'; }));
    $resolved = count(array_filter($reports, function ($r) { return ($r['status'] ?? '') === 'resolved'; }));
    $score += $pending * 5 + $resolved * 2;
    $age = chat_now() - (int)($target['created'] ?? chat_now());
    if ($age < 86400000) $score += 3;
    elseif ($age < 604800000) $score += 1;
    if (preg_match('/^(ad|spam|bot)[0-9]{3,}/i', (string)($target['username'] ?? ''))) $score += 4;
    if (preg_match('/^[0-9]{6,}$/', (string)($target['username'] ?? ''))) $score += 3;
    $bioText = strtolower(((string)($target['bio'] ?? '')) . ' ' . ((string)($target['name'] ?? '')) . ' ' . ((string)($target['username'] ?? '')));
    foreach (BOTS_FILTERED_WORDS as $w) {
        if (mb_strpos($bioText, mb_strtolower($w)) !== false) $score += 3;
    }
    $score = min(100, $score);
    $verdict = $score === 0 ? '正常' : ($score < 15 ? '轻微' : ($score < 35 ? '中等风险' : ($score < 60 ? '高风险疑似垃圾账号' : '高危垃圾账号')));
    return ['score' => $score, 'verdict' => $verdict, 'pending' => $pending, 'resolved' => $resolved, 'total' => count($reports)];
}

function bots_account_report(array $target): string {
    $s = bots_spam_score($target);
    $role = ($target['role'] ?? '') === 'admin' ? '管理员' : (($target['role'] ?? '') === 'bot' ? '机器人' : '用户');
    $isBotUser = ($target['role'] ?? '') === 'bot';
    return implode("\n", [
        '🚩 账号信息',
        '用户名: ' . ($target['username'] ?? '') . ($isBotUser ? ' 🤖' : ''),
        '昵称: ' . ($target['name'] ?? '无'),
        'ID: ' . ($target['id'] ?? ''),
        '角色: ' . $role,
        '状态: ' . (($target['status'] ?? '') === 'online' ? '在线' : '离线'),
        '注册时间: ' . bots_fmt_time($target['created'] ?? null),
        '个人签名: ' . (($target['bio'] ?? '') ?: '无'),
        '',
        '🚩 举报记录: 共 ' . $s['total'] . ' 条（待处理 ' . $s['pending'] . '，已处理 ' . $s['resolved'] . '）',
        '⚠️ 垃圾评分: ' . $s['score'] . '/100 → ' . $s['verdict'],
    ]);
}

/* ===================== 官方机器人：MOSS ===================== */
function bots_moss_handler(array $ctx): string {
    $user = $ctx['user'];
    $c = bots_parse_cmd($ctx['text']);
    switch ($c['name']) {
        case '':
            return '你好，我是 MOSS 🤖' . "\n" . '发送 /start 或 /help 查看可用命令。';
        case '/start':
            return implode("\n", [
                '👋 欢迎使用 MOSS —— 即时通讯 官方助手机器人',
                '',
                '可用命令：',
                '/start 重新开始',
                '/help 帮助',
                '/info 我的账号信息',
                '/time 当前时间',
                '/date 当前日期',
                '/ping 网络延迟测试',
                '/random 随机数',
                '/echo <内容> 复读',
                '/about 关于本平台',
            ]);
        case '/help':
            return implode("\n", [
                '📖 MOSS 帮助',
                '/start 欢迎信息',
                '/help 本帮助',
                '/info 显示我的账号信息',
                '/time 当前时间',
                '/date 当前日期',
                '/ping Ping 测试',
                '/random 随机生成 0-9999',
                '/echo <内容> 原样复读',
                '/about 关于 即时通讯',
            ]);
        case '/whoami':
            return '🤖 我是 MOSS，即时通讯 官方助手机器人。' . "\n" . '管理员机器人：spam_bot（账号检查/举报）、bot_father（创建机器人）。';
        case '/info':
            return implode("\n", [
                '👤 账号信息',
                '用户: ' . ($user['name'] ?: $user['username']),
                '用户名: @' . $user['username'],
                'ID: ' . $user['id'],
                '角色: ' . (($user['role'] ?? '') === 'admin' ? '管理员' : '用户'),
                '注册时间: ' . bots_fmt_time($user['created'] ?? null),
                '签名: ' . (($user['bio'] ?? '') ?: '—'),
            ]);
        case '/time':
            return '🕐 当前时间: ' . date('Y-m-d H:i:s');
        case '/date':
            return '📅 当前日期: ' . date('Y-m-d');
        case '/ping':
            return '🏓 Pong! (' . mt_rand(0, 100) . 'ms)';
        case '/random':
            return '🎲 随机数: ' . mt_rand(0, 9999);
        case '/echo':
            return $c['args'] !== '' ? '🔁 ' . $c['args'] : '用法：/echo <内容>';
        case '/about':
            return '即时通讯 —— 实时聊天平台' . "\n" . '服务端: PHP + SQLite + SSE' . "\n" . '由机器人 bot_father 创建专属机器人。';
        default:
            return '🤖 MOSS：未知命令「' . $c['name'] . '」，发送 /help 查看可用命令。';
    }
}

/* ===================== 官方机器人：spam_bot ===================== */
function bots_spam_handler(array $ctx): string {
    $user = $ctx['user'];
    $c = bots_parse_cmd($ctx['text']);
    switch ($c['name']) {
        case '':
            return '你好，我是垃圾审查机器人 🛡️' . "\n" . '发送 /start 查看账号检查功能。';
        case '/start':
            return implode("\n", [
                '🛡️ 垃圾审查机器人 spam_bot',
                '',
                '功能：',
                '/check <用户名或ID> 检查账号（含举报记录 + 垃圾评分）',
                '/report <用户名> <原因> 举报违规账号',
                '/me 查看我自己的账号状态',
                '/help 帮助',
                '',
                '例如：/check alice',
            ]);
        case '/help':
            return implode("\n", [
                '📖 spam_bot 命令',
                '/check <用户名|ID> 账号信息 + 举报记录 + 垃圾评分',
                '/report <用户名> <原因> 提交举报',
                '/me 查看自己的账号状态',
                '/start 欢迎信息',
            ]);
        case '/me':
            return bots_account_report($user);
        case '/check':
            if ($c['args'] === '') return '用法：/check <用户名或ID>' . "\n" . '例如：/check alice';
            $target = bots_resolve_target($c['args']);
            if (!$target) return '未找到账号：' . $c['args'];
            return bots_account_report($target);
        case '/report': {
            $parts = preg_split('/\s+/', trim($c['args']));
            $name = isset($parts[0]) ? str_replace('@', '', $parts[0]) : '';
            $reason = trim((string)implode(' ', array_slice($parts, 1)));
            if ($name === '') return '用法：/report <用户名> <原因>' . "\n" . '例如：/report bob 广告骚扰';
            $target = bots_resolve_target($name);
            if (!$target) return '未找到账号：' . $name;
            if ($target['id'] === $user['id']) return '不能举报自己哦';
            $id = chat_uid();
            kv_put('reports', $id, [
                'id' => $id, 'targetUserId' => $target['id'], 'reporterId' => $user['id'],
                'reason' => $reason !== '' ? $reason : '未填写', 'time' => chat_now(), 'status' => 'pending',
            ]);
            foreach (kv_by_index('users', 'role', 'admin') as $a) {
                event_send($a['id'], 'notification', [
                    'type' => 'report',
                    'extra' => [
                        'reportId' => $id, 'targetUsername' => $target['username'],
                        'reporterName' => $user['name'] ?: $user['username'], 'reason' => $reason,
                    ],
                ]);
            }
            return '✅ 已提交举报：@' . $target['username'] . "\n" . '原因: ' . ($reason !== '' ? $reason : '未填写') . "\n" . '管理员将尽快处理。';
        }
        default:
            return '🛡️ spam_bot：未知命令「' . $c['name'] . '」，发送 /help 查看帮助。';
    }
}

/* ===================== 官方机器人：bot_father ===================== */
function bots_bf_session(string $userId) {
    return kv_get('bot_sessions', 'bf_' . $userId) ?: null;
}
function bots_bf_set(string $userId, string $step, array $pending): void {
    kv_put('bot_sessions', 'bf_' . $userId, [
        'id' => 'bf_' . $userId, 'userId' => $userId, 'step' => $step, 'pending' => $pending, 'time' => chat_now(),
    ]);
}
function bots_bf_clear(string $userId): void {
    kv_del('bot_sessions', 'bf_' . $userId);
}
function bots_create_record(array $owner, array $pending, ?string $templateId, array $keywords): array {
    $username = strtolower($pending['username']);
    $id = bots_user_to_bot_id($username);
    $token = bots_gen_token();
    $note = trim((string)($pending['note'] ?? '')) ?: (($templateId !== null && isset(BOTS_TEMPLATES[$templateId])) ? BOTS_TEMPLATES[$templateId]['note'] : '') ?: '由用户创建的机器人';
    $rec = [
        'id' => $id, 'username' => $username, 'name' => $pending['name'], 'ownerId' => $owner['id'],
        'kind' => 'custom', 'template' => $templateId, 'keywords' => $keywords,
        'commands' => new stdClass(), 'desc' => '', 'note' => $note, 'token' => $token, 'status' => 'active', 'isOfficial' => false,
        'created' => chat_now(), 'updated' => chat_now(),
    ];
    $botUser = [
        'id' => $id, 'username' => $username, 'name' => $pending['name'], 'avatar' => '',
        'bio' => '由 @' . $owner['username'] . ' 创建的机器人',
        'note' => $note, 'role' => 'bot', 'status' => 'offline', 'created' => chat_now(),
    ];
    kv_put('users', $id, $botUser);
    kv_put('bots', $id, $rec);
    return $rec;
}
function bots_valid_username(string $username): bool {
    return preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,30}bot$/i', $username) === 1;
}
function bots_botfather_handler(array $ctx): string {
    $user = $ctx['user'];
    $text = $ctx['text'];
    $chatId = $ctx['chatId'];
    $c = bots_parse_cmd($text);
    $sess = bots_bf_session($user['id']);

    if ($sess && isset($sess['step']) && $c['name'] !== '/cancel') {
        $p = is_array($sess['pending']) ? $sess['pending'] : [];
        if ($sess['step'] === 'name') {
            if (trim($text) === '' || mb_strlen($text) > 30) return '名字太长或为空，请重新发送机器人名字（1-30 字符）。';
            $p['name'] = trim($text);
            bots_bf_set($user['id'], 'username', $p);
            return '👌 好名字：' . $p['name'] . "\n" . '现在给它一个用户名（必须以 bot 结尾，仅字母数字下划线）：' . "\n" . '例如 my_echo_bot' . "\n" . '发送 /cancel 取消。';
        }
        if ($sess['step'] === 'username') {
            $uname = strtolower(trim($text));
            if (!bots_valid_username($uname)) return '❌ 用户名不合法。必须以 bot 结尾，仅字母数字下划线，3-32 字符。' . "\n" . '例如 my_echo_bot' . "\n" . '发送 /cancel 取消。';
            if (bots_is_reserved($uname)) return '❌ 用户名 ' . $uname . ' 已被占用，换一个吧。' . "\n" . '发送 /cancel 取消。';
            $p['username'] = $uname;
            bots_bf_set($user['id'], 'template', $p);
            $tpl = implode("\n", array_map(function ($id) {
                return '  /template ' . $id . ' - ' . BOTS_TEMPLATES[$id]['name'] . '（' . BOTS_TEMPLATES[$id]['desc'] . '）';
            }, array_keys(BOTS_TEMPLATES)));
            return implode("\n", [
                '✅ 用户名可用：@' . $uname,
                '',
                '选择机器人类型（模板或自定义）：',
                $tpl,
                '  /none - 无模板（仅基础回复）',
                '  /custom - 自定义关键词回复（推荐，可自由配置）',
                '',
                '发送 /cancel 取消。',
            ]);
        }
        if ($sess['step'] === 'template') {
            $choice = $c['name'] === '/none' ? 'none' : ($c['name'] === '/custom' ? 'keyword' : ($c['name'] === '/template' ? strtolower(trim($c['args'])) : ''));
            if ($choice === '' || ($choice !== 'none' && $choice !== 'keyword' && !isset(BOTS_TEMPLATES[$choice]))) {
                return '请从上面选择一个类型：/template echo / /template dice / /custom / /none';
            }
            $rec = bots_create_record($user, $p, $choice === 'none' ? null : $choice, []);
            if (($rec['template'] ?? null) === 'keyword') {
                bots_bf_set($user['id'], 'keyword', ['username' => $rec['username']]);
                return implode("\n", [
                    '🎉 机器人创建成功！',
                    '名称: ' . $rec['name'],
                    '用户名: @' . $rec['username'],
                    '令牌: ' . $rec['token'],
                    '',
                    '它使用「关键词回复」模板，现在配置关键词：',
                    '发送格式：关键词 => 回复内容',
                    '例如：你好 => 你好呀！',
                    '配置完成后发送 done 结束（发送 /cancel 跳过）。',
                ]);
            }
            bots_bf_clear($user['id']);
            return implode("\n", [
                '🎉 机器人创建成功！',
                '名称: ' . $rec['name'],
                '用户名: @' . $rec['username'],
                '令牌: ' . $rec['token'],
                '',
                '把它添加为联系人即可对话。',
                '管理命令：/mybots /token /setcommands /setdesc /delete',
            ]);
        }
        if ($sess['step'] === 'keyword') {
            if (strtolower($c['name']) === 'done' || trim($text) === 'done') {
                bots_bf_clear($user['id']);
                $rec = kv_get('bots', bots_user_to_bot_id($sess['pending']['username'] ?? ''));
                $cnt = is_array($rec) ? count($rec['keywords'] ?? []) : 0;
                return '✅ 关键词配置完成，共 ' . $cnt . ' 条。' . "\n" . ($rec ? '随时可用 /setkeyword @' . $rec['username'] . ' 关键词 => 回复 添加新规则。' : '');
            }
            if (!preg_match('/^(.+?)\s*=>\s*(.+)$/', $text, $m)) return '格式错误。请用：关键词 => 回复内容' . "\n" . '例如：你好 => 你好呀！' . "\n" . '发送 done 完成，/cancel 取消。';
            $rec = kv_get('bots', bots_user_to_bot_id($sess['pending']['username'] ?? ''));
            if (!$rec) { bots_bf_clear($user['id']); return '机器人不存在，流程已重置。'; }
            if (!isset($rec['keywords']) || !is_array($rec['keywords'])) $rec['keywords'] = [];
            $rec['keywords'][] = ['pattern' => trim($m[1]), 'reply' => trim($m[2])];
            $rec['updated'] = chat_now();
            kv_put('bots', $rec['id'], $rec);
            return '✅ 已添加：' . trim($m[1]) . ' => ' . trim($m[2]) . '（共 ' . count($rec['keywords']) . ' 条）' . "\n" . '继续发送下一条，或发送 done 完成。';
        }
        return '流程状态异常，发送 /cancel 重置。';
    }

    switch ($c['name']) {
        case '':
        case '/start':
            bots_bf_clear($user['id']);
            return implode("\n", [
                '🤖 欢迎使用机器人之父 bot_father',
                '在这里创建和管理你自己的机器人。',
                '',
                '命令：',
                '/newbot 创建新机器人（名字 → 用户名 → 类型）',
                '/templates 查看可用模板',
                '/mybots 我创建的机器人',
                '/token <用户名> 查看令牌',
                '/setcommands <用户名> 命令 描述 | 命令 描述',
                '/setkeyword <用户名> 关键词 => 回复',
                '/setdesc <用户名> 描述',
                '/delete <用户名> 删除机器人',
                '/help 帮助',
                '/cancel 取消当前流程',
            ]);
        case '/help':
            return implode("\n", [
                '📖 bot_father 帮助',
                '/newbot 开始创建机器人',
                '/templates 列出模板',
                '/mybots 列出我的机器人',
                '/token <用户名> 显示令牌',
                '/setcommands <用户名> /cmd 描述 | /cmd2 描述',
                '/setkeyword <用户名> 关键词 => 回复',
                '/setdesc <用户名> 新描述',
                '/delete <用户名> 删除',
                '/cancel 取消流程',
            ]);
        case '/cancel':
            bots_bf_clear($user['id']);
            return '已取消当前操作。';
        case '/templates':
            return '📦 机器人模板：' . "\n" . implode("\n", array_map(function ($id) {
                return '  ' . $id . ' - ' . BOTS_TEMPLATES[$id]['name'] . '（' . BOTS_TEMPLATES[$id]['desc'] . '）';
            }, array_keys(BOTS_TEMPLATES))) . "\n" . "\n" . '创建时在类型选择中发送 /template <id> 使用。';
        case '/newbot':
            bots_bf_set($user['id'], 'name', []);
            return '🤖 好的，开始创建机器人！' . "\n" . '第一步：给它起个名字（1-30 字符）：' . "\n" . '例如：我的小助手' . "\n" . '发送 /cancel 取消。';
        case '/mybots': {
            $mine = array_values(array_filter(kv_get_all('bots'), function ($b) use ($user) {
                return ($b['ownerId'] ?? '') === $user['id'] && ($b['kind'] ?? '') === 'custom';
            }));
            if (!$mine) return '你还没有创建机器人。发送 /newbot 开始创建吧。';
            return '📋 我创建的机器人：' . "\n\n" . implode("\n", array_map(function ($b) {
                $t = ($b['template'] ?? null) ? '(' . (isset(BOTS_TEMPLATES[$b['template']]) ? BOTS_TEMPLATES[$b['template']]['name'] : $b['template']) . ')' : '';
                return '  @' . $b['username'] . ' ' . $t . "\n" . '    令牌: ' . $b['token'];
            }, $mine));
        }
        case '/token': {
            $uname = strtolower(str_replace('@', '', trim($c['args'])));
            if ($uname === '') return '用法：/token <用户名>';
            $rec = kv_get('bots', bots_user_to_bot_id($uname));
            if (!$rec || ($rec['ownerId'] ?? '') !== $user['id']) return '未找到你拥有的机器人 @' . $uname;
            return '🔑 机器人令牌：' . $rec['token'];
        }
        case '/setcommands': {
            if (!preg_match('/^(@?\S+)\s+(.+)$/', $c['args'], $m)) return '用法：/setcommands <用户名> /命令 描述 | /命令2 描述2';
            $rec = kv_get('bots', bots_user_to_bot_id(strtolower(str_replace('@', '', $m[1]))));
            if (!$rec || ($rec['ownerId'] ?? '') !== $user['id']) return '未找到你拥有的机器人：' . $m[1];
            $map = [];
            foreach (explode('|', $m[2]) as $pair) {
                $parts = preg_split('/[\s,;]+/', trim($pair), 2);
                if (isset($parts[0], $parts[1]) && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
                    $map[trim($parts[0])] = trim($parts[1]);
                }
            }
            if (count($map) === 0) return '命令格式错误，示例：/setcommands mybot /hi 打招呼 | /help 帮助';
            $rec['commands'] = $map;
            $rec['updated'] = chat_now();
            kv_put('bots', $rec['id'], $rec);
            return '✅ 已设置 ' . count($map) . ' 条命令：' . "\n" . implode("\n", array_map(function ($k) use ($map) {
                return '  ' . $k . ' - ' . $map[$k];
            }, array_keys($map)));
        }
        case '/setkeyword': {
            if (!preg_match('/^(@?\S+)\s+(.+?)\s*=>\s*(.+)$/', $c['args'], $m)) return '用法：/setkeyword <用户名> 关键词 => 回复内容';
            $rec = kv_get('bots', bots_user_to_bot_id(strtolower(str_replace('@', '', $m[1]))));
            if (!$rec || ($rec['ownerId'] ?? '') !== $user['id']) return '未找到你拥有的机器人：' . $m[1];
            if (!isset($rec['keywords']) || !is_array($rec['keywords'])) $rec['keywords'] = [];
            $rec['keywords'][] = ['pattern' => trim($m[2]), 'reply' => trim($m[3])];
            $rec['updated'] = chat_now();
            kv_put('bots', $rec['id'], $rec);
            return '✅ 已添加关键词：' . trim($m[2]) . ' => ' . trim($m[3]) . '（共 ' . count($rec['keywords']) . ' 条）';
        }
        case '/setdesc': {
            if (!preg_match('/^(@?\S+)\s+(.+)$/', $c['args'], $m)) return '用法：/setdesc <用户名> 描述';
            $rec = kv_get('bots', bots_user_to_bot_id(strtolower(str_replace('@', '', $m[1]))));
            if (!$rec || ($rec['ownerId'] ?? '') !== $user['id']) return '未找到你拥有的机器人：' . $m[1];
            $rec['desc'] = trim($m[2]);
            $rec['updated'] = chat_now();
            kv_put('bots', $rec['id'], $rec);
            $bu = kv_get('users', $rec['id']);
            if ($bu) { $bu['bio'] = trim($m[2]); kv_put('users', $bu['id'], $bu); }
            return '✅ 已更新描述：' . $rec['desc'];
        }
        case '/delete': {
            $uname = strtolower(str_replace('@', '', trim($c['args'])));
            if ($uname === '') return '用法：/delete <用户名>';
            $rec = kv_get('bots', bots_user_to_bot_id($uname));
            if (!$rec || ($rec['ownerId'] ?? '') !== $user['id']) return '未找到你拥有的机器人：@' . $uname;
            kv_del('bots', $rec['id']);
            kv_del('users', $rec['id']);
            return '🗑️ 机器人 @' . $uname . ' 已删除。';
        }
        default:
            return '🤖 bot_father：未知命令「' . $c['name'] . '」，发送 /help 查看帮助。';
    }
}

/* ===================== 自定义机器人 ===================== */
function bots_custom_handler(array $ctx): ?string {
    $bot = $ctx['bot'];
    $text = $ctx['text'];
    $c = bots_parse_cmd($text);
    if ($c['name'] !== '' && isset($bot['commands'][$c['name']])) {
        return '📌 ' . $bot['name'] . '：' . $bot['commands'][$c['name']];
    }
    if (($bot['template'] ?? null) === 'echo') {
        if ($text === '' || $text[0] === '/') return '🔁 我是复读机，发送任意文字即可。';
        return '🔁 ' . $text;
    }
    if (($bot['template'] ?? null) === 'dice') {
        if ($c['name'] === '/dice') return '🎲 你掷出了 ' . (1 + mt_rand(0, 5));
        if ($c['name'] === '/num') {
            $n = (int)$c['args'];
            if ($n > 0) return '🔢 随机数 (0-' . $n . '): ' . mt_rand(0, $n);
            return '用法：/num <上限>';
        }
        return '🎲 骰子机器人' . "\n" . '/dice 掷骰子' . "\n" . '/num <上限> 随机数字';
    }
    if (($bot['template'] ?? null) === 'keyword' && isset($bot['keywords']) && is_array($bot['keywords']) && count($bot['keywords'])) {
        foreach ($bot['keywords'] as $k) {
            if (mb_strpos($text, (string)($k['pattern'] ?? '')) !== false) return $k['reply'] ?? null;
        }
        return null;
    }
    return '🤖 我是 ' . $bot['name'] . '（@' . $bot['username'] . '）' . "\n" . '由 @' . ($ctx['ownerName'] ?? '?') . ' 创建。';
}

/* ===================== 入口 ===================== */
function bots_ensure(): void {
    foreach (BOTS_OFFICIAL as $b) {
        $id = bots_user_to_bot_id($b['username']);
        $u = kv_get('users', $id);
        if (!$u) {
            $u = [
                'id' => $id, 'username' => $b['username'], 'name' => $b['name'], 'avatar' => '',
                'bio' => $b['bio'], 'note' => $b['note'], 'role' => 'bot', 'status' => 'offline', 'created' => chat_now(),
            ];
            kv_put('users', $id, $u);
        } else {
            if (($u['bio'] ?? '') !== $b['bio']) { $u['bio'] = $b['bio']; kv_put('users', $id, $u); }
            if (($u['note'] ?? '') !== $b['note']) { $u['note'] = $b['note']; kv_put('users', $id, $u); }
            if (($u['role'] ?? '') !== 'bot') { $u['role'] = 'bot'; kv_put('users', $id, $u); }
        }
        $rec = kv_get('bots', $id);
        if (!$rec) {
            kv_put('bots', $id, [
                'id' => $id, 'username' => $b['username'], 'name' => $b['name'], 'ownerId' => null, 'kind' => 'official',
                'template' => null, 'keywords' => [], 'commands' => new stdClass(), 'desc' => $b['bio'], 'note' => $b['note'], 'token' => null,
                'status' => 'active', 'isOfficial' => true, 'created' => chat_now(), 'updated' => chat_now(),
            ]);
        } else {
            if (($rec['desc'] ?? '') !== $b['bio']) { $rec['desc'] = $b['bio']; $rec['updated'] = chat_now(); }
            if (($rec['note'] ?? '') !== $b['note']) { $rec['note'] = $b['note']; $rec['updated'] = chat_now(); }
            kv_put('bots', $id, $rec);
        }
    }
}

/** 用户给机器人发私聊时由消息写入方调用 */
function bots_on_message($msg): void {
    if (!$msg || empty($msg['chatId']) || empty($msg['sender'])) return;
    if (($msg['type'] ?? '') !== 'text') return;
    if (bots_is_bot($msg['sender'])) return;
    $chatId = $msg['chatId'];
    if (strncmp($chatId, 'dm_', 3) !== 0) return;
    $rest = substr($chatId, 3);
    $sep = strpos($rest, '|');
    if ($sep === false) return;
    $a = substr($rest, 0, $sep);
    $b = substr($rest, $sep + 1);
    $botId = bots_is_bot($a) ? $a : (bots_is_bot($b) ? $b : null);
    if (!$botId) return;
    $bot = kv_get('bots', $botId);
    if (!$bot || ($bot['status'] ?? '') !== 'active') return;
    $user = kv_get('users', $msg['sender']);
    if (!$user) return;

    $ctx = ['user' => $user, 'bot' => $bot, 'text' => (string)($msg['content'] ?? ''), 'chatId' => $chatId];
    $out = null;
    if (($bot['username'] ?? '') === 'moss') $out = bots_moss_handler($ctx);
    elseif (($bot['username'] ?? '') === 'spam_bot') $out = bots_spam_handler($ctx);
    elseif (($bot['username'] ?? '') === 'bot_father') $out = bots_botfather_handler($ctx);
    else {
        $owner = kv_get('users', $bot['ownerId'] ?? '');
        $ctx['ownerName'] = $owner ? $owner['username'] : '?';
        $out = bots_custom_handler($ctx);
    }
    if ($out !== null && $out !== '') bots_reply($chatId, $botId, $out);
}
