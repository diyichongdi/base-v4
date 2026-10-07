<?php
/**
 * db.php — SQLite 数据层（PDO）
 *
 * 与原 Node 版 chat-server/db.js 同构：单表 kv(store,id,data) 存聊天全部数据；
 * 另加 tokens / events(SSE 事件队列) / sse_cursor(在线状态) / userdata(工具·桌面)
 * / tds_*(数据空间) / ft_files(文件快传)。
 */
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!is_dir(DATA_DIR)) {
            @mkdir(DATA_DIR, 0777, true);
        }
        $pdo = new PDO('sqlite:' . IM_DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL;');
        $pdo->exec('PRAGMA synchronous=NORMAL;');
        $pdo->exec('PRAGMA busy_timeout=8000;');
        ensure_schema($pdo);
        ensure_seed($pdo);
    }
    return $pdo;
}

function ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kv (
        store TEXT NOT NULL,
        id TEXT NOT NULL,
        data TEXT NOT NULL,
        PRIMARY KEY (store, id)
    );");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tokens (
        token TEXT PRIMARY KEY,
        userId TEXT NOT NULL,
        createdAt INTEGER NOT NULL
    );");
    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        seq INTEGER PRIMARY KEY AUTOINCREMENT,
        user TEXT NOT NULL,
        event TEXT NOT NULL,
        data TEXT NOT NULL DEFAULT '{}',
        created INTEGER NOT NULL
    );");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_user_seq ON events(user, seq);");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sse_cursor (
        user TEXT PRIMARY KEY,
        seq INTEGER NOT NULL DEFAULT 0,
        updated INTEGER NOT NULL
    );");
    $pdo->exec("CREATE TABLE IF NOT EXISTS userdata (
        user TEXT NOT NULL,
        key TEXT NOT NULL,
        value TEXT NOT NULL,
        updated INTEGER NOT NULL,
        PRIMARY KEY (user, key)
    );");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tds_users (
        id TEXT PRIMARY KEY,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'user',
        created INTEGER NOT NULL
    );");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tds_files (
        id TEXT PRIMARY KEY,
        owner TEXT NOT NULL,
        name TEXT NOT NULL,
        size INTEGER NOT NULL DEFAULT 0,
        type TEXT NOT NULL DEFAULT '',
        path TEXT NOT NULL,
        sha256 TEXT NOT NULL DEFAULT '',
        created INTEGER NOT NULL
    );");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tds_permissions (
        id TEXT PRIMARY KEY,
        file_id TEXT NOT NULL,
        user TEXT NOT NULL,
        permission TEXT NOT NULL DEFAULT 'view',
        created INTEGER NOT NULL
    );");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tds_requests (
        id TEXT PRIMARY KEY,
        filename TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT '',
        requester TEXT NOT NULL,
        reason TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'pending',
        note TEXT NOT NULL DEFAULT '',
        created INTEGER NOT NULL,
        handled_at INTEGER
    );");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tds_logs (
        id TEXT PRIMARY KEY,
        user TEXT NOT NULL,
        action TEXT NOT NULL,
        detail TEXT NOT NULL DEFAULT '',
        created INTEGER NOT NULL
    );");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ft_files (
        code TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT '',
        size INTEGER NOT NULL DEFAULT 0,
        path TEXT NOT NULL,
        created INTEGER NOT NULL
    );");
    db_migrate();
}

/* ---------- 数据空间字段迁移（兼容早期建库后补列） ---------- */
function db_has_col(string $table, string $col): bool {
    $cols = db()->query("PRAGMA table_info($table)")->fetchAll();
    foreach ($cols as $c) {
        if ($c['name'] === $col) return true;
    }
    return false;
}
function db_add_col(string $table, string $col, string $def): void {
    if (!db_has_col($table, $col)) {
        db()->exec("ALTER TABLE $table ADD COLUMN $col $def");
    }
}
function db_migrate(): void {
    db_add_col('tds_files', 'filename', "TEXT NOT NULL DEFAULT ''");
    db_add_col('tds_files', 'description', "TEXT NOT NULL DEFAULT ''");
    db_add_col('tds_files', 'upload_time', "TEXT NOT NULL DEFAULT ''");
    db_add_col('tds_permissions', 'file_name', "TEXT NOT NULL DEFAULT ''");
    db_add_col('tds_permissions', 'owner', "TEXT NOT NULL DEFAULT ''");
    db_add_col('tds_permissions', 'allowed_user', "TEXT NOT NULL DEFAULT ''");
    db_add_col('tds_permissions', 'expire_time', "TEXT NOT NULL DEFAULT '永久'");
    db_add_col('tds_permissions', 'granted_at', "TEXT NOT NULL DEFAULT ''");
    db_add_col('tds_requests', 'data_id', "TEXT NOT NULL DEFAULT ''");
    db_add_col('tds_requests', 'duration', "TEXT NOT NULL DEFAULT '永久'");
    db_add_col('tds_requests', 'created_at', "TEXT NOT NULL DEFAULT ''");
    db_add_col('tds_requests', 'handled_at', "TEXT NOT NULL DEFAULT ''");
    db_add_col('tds_requests', 'handled_by', "TEXT NOT NULL DEFAULT ''");
    db_add_col('tds_requests', 'expire_at', "INTEGER");
    db_add_col('tds_logs', 'role', "TEXT NOT NULL DEFAULT ''");
}

/* ===================== kv 通用操作（与原 Node 版一致） ===================== */
function kv_put(string $store, string $id, $data): void {
    $st = db()->prepare('INSERT INTO kv (store, id, data) VALUES (?, ?, ?)
        ON CONFLICT(store, id) DO UPDATE SET data = excluded.data');
    $st->execute([$store, $id, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}
function kv_get(string $store, string $id) {
    $st = db()->prepare('SELECT data FROM kv WHERE store = ? AND id = ?');
    $st->execute([$store, $id]);
    $row = $st->fetch();
    if (!$row) return null;
    $d = json_decode($row['data'], true);
    return is_array($d) ? $d : null;
}
function kv_del(string $store, string $id): void {
    $st = db()->prepare('DELETE FROM kv WHERE store = ? AND id = ?');
    $st->execute([$store, $id]);
}
/** 按插入顺序返回该 store 全部记录 */
function kv_get_all(string $store): array {
    $st = db()->prepare('SELECT data FROM kv WHERE store = ? ORDER BY rowid');
    $st->execute([$store]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $d = json_decode($row['data'], true);
        if (is_array($d)) $out[] = $d;
    }
    return $out;
}
/** 等价 Node byIndex：取 [idx]===val 的记录 */
function kv_by_index(string $store, string $idx, $val): array {
    return array_values(array_filter(kv_get_all($store), function ($it) use ($idx, $val) {
        return is_array($it) && array_key_exists($idx, $it) && $it[$idx] === $val;
    }));
}
function kv_count(string $store): int {
    $st = db()->prepare('SELECT COUNT(*) AS n FROM kv WHERE store = ?');
    $st->execute([$store]);
    return (int)$st->fetch()['n'];
}

/* ===================== token ===================== */
function issue_token(string $userId): string {
    $token = 'tok_' . bin2hex(random_bytes(16));
    $st = db()->prepare('INSERT INTO tokens (token, userId, createdAt) VALUES (?, ?, ?)');
    $st->execute([$token, $userId, chat_now()]);
    return $token;
}
function revoke_token(string $token): void {
    if ($token === '') return;
    $st = db()->prepare('DELETE FROM tokens WHERE token = ?');
    $st->execute([$token]);
}
function user_by_token(?string $token) {
    if (!$token) return null;
    $st = db()->prepare('SELECT userId FROM tokens WHERE token = ?');
    $st->execute([$token]);
    $row = $st->fetch();
    return $row ? kv_get('users', $row['userId']) : null;
}

/* ===================== SSE 事件队列（替代原内存 RT） ===================== */
function event_send(string $userId, string $event, $data): void {
    if ($userId === '') return;
    $st = db()->prepare('INSERT INTO events (user, event, data, created) VALUES (?, ?, ?, ?)');
    $st->execute([
        $userId,
        $event,
        json_encode($data === null ? new stdClass() : $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        chat_now()
    ]);
}
function event_send_to_users(array $userIds, string $event, $data): void {
    foreach (array_unique(array_filter($userIds)) as $id) {
        event_send((string)$id, $event, $data);
    }
}
/** 等价 Node getChatParticipants */
function chat_participants(string $chatId): array {
    if ($chatId === '') return [];
    if (strncmp($chatId, 'dm_', 3) === 0) {
        $rest = substr($chatId, 3);
        $sep = strpos($rest, '|');
        if ($sep === false) return [];
        return [substr($rest, 0, $sep), substr($rest, $sep + 1)];
    }
    if (strncmp($chatId, 'fh_', 3) === 0) {
        return [substr($chatId, 3)];
    }
    $g = kv_get('groups', $chatId);
    if (is_array($g) && isset($g['members']) && is_array($g['members'])) {
        return array_values(array_filter(array_map(function ($m) {
            return is_array($m) && isset($m['id']) ? $m['id'] : null;
        }, $g['members'])));
    }
    $ch = kv_get('channels', $chatId);
    if (is_array($ch) && isset($ch['subscribers']) && is_array($ch['subscribers'])) {
        return array_values(array_filter($ch['subscribers'], function ($s) {
            return $s !== null && $s !== '';
        }));
    }
    return [];
}
function event_send_to_chat(string $chatId, string $event, $data): void {
    event_send_to_users(chat_participants($chatId), $event, $data);
}
/** 当前在线用户 = 近期活跃的 SSE 连接（cursor 120s 内更新过） */
function online_user_ids(): array {
    $st = db()->prepare('SELECT user FROM sse_cursor WHERE updated >= ?');
    $st->execute([chat_now() - 120000]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}
function event_send_to_online(string $event, $data): void {
    event_send_to_users(online_user_ids(), $event, $data);
}
function sse_get_cursor(string $userId): int {
    $st = db()->prepare('SELECT seq FROM sse_cursor WHERE user = ?');
    $st->execute([$userId]);
    $row = $st->fetch();
    return $row ? (int)$row['seq'] : 0;
}
function sse_set_cursor(string $userId, int $seq): void {
    $st = db()->prepare('INSERT INTO sse_cursor (user, seq, updated) VALUES (?, ?, ?)
        ON CONFLICT(user) DO UPDATE SET seq = excluded.seq, updated = excluded.updated');
    $st->execute([$userId, $seq, chat_now()]);
}
function sse_clear(string $userId): void {
    $st = db()->prepare('DELETE FROM sse_cursor WHERE user = ?');
    $st->execute([$userId]);
}
/** 拉取 seq 之后的事件（按序） */
function event_poll(string $userId, int $afterSeq): array {
    $st = db()->prepare('SELECT seq, event, data FROM events WHERE user = ? AND seq > ? ORDER BY seq ASC LIMIT 100');
    $st->execute([$userId, $afterSeq]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[] = ['seq' => (int)$row['seq'], 'event' => $row['event'], 'data' => $row['data']];
    }
    return $out;
}
/** 事件表兜底清理：只保留最近 2 小时；清除过期游标（120s 无活跃） */
function event_gc(): void {
    $st = db()->prepare('DELETE FROM events WHERE created < ?');
    $st->execute([chat_now() - 7200000]);
    $st = db()->prepare('DELETE FROM sse_cursor WHERE updated < ?');
    $st->execute([chat_now() - 120000]);
}
/** 节流版兜底清理：每 60s 最多执行一次 */
function maybe_event_gc(): void {
    $last = (int)kv_get('sys', 'event_gc_at');
    if (chat_now() - $last < 60000) return;
    event_gc();
    kv_put('sys', 'event_gc_at', chat_now());
}

/* ===================== 服务端用户数据（工具·桌面，按会话匿名） ===================== */
function ud_get(string $user, string $key) {
    $st = db()->prepare('SELECT value FROM userdata WHERE user = ? AND key = ?');
    $st->execute([$user, $key]);
    $row = $st->fetch();
    return $row ? json_decode($row['value'], true) : null;
}
function ud_set(string $user, string $key, $val): void {
    $st = db()->prepare('INSERT INTO userdata (user, key, value, updated) VALUES (?, ?, ?, ?)
        ON CONFLICT(user, key) DO UPDATE SET value = excluded.value, updated = excluded.updated');
    $st->execute([$user, $key, json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), chat_now()]);
}
function ud_all(string $user): array {
    $st = db()->prepare('SELECT key, value FROM userdata WHERE user = ?');
    $st->execute([$user]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[$row['key']] = json_decode($row['value'], true);
    }
    return $out;
}
