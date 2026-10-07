<?php
/**
 * im/init.php — 即时通讯子系统初始化
 * chat.php 通过前端 JS + window.AQUA_CHAT_API 调用 /api/ 接口，本文件仅提供
 * 会话初始化 + 页面级 IM 配置，并在【全站统一账号】模式下把主站登录态桥接为 IM 登录位。
 */
if (!defined('IM_STANDALONE')) {
    define('IM_STANDALONE', true);
}

// IM 数据目录（供 router.php 保护规则参考）
$__imDataDir = dirname(__DIR__) . '/data';
if (!is_dir($__imDataDir)) {
    @mkdir($__imDataDir, 0777, true);
    @mkdir($__imDataDir . '/uploads', 0777, true);
    @mkdir($__imDataDir . '/files', 0777, true);
}

// 会话中是否有 IM token — 用于前端判断是否已认证
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// 如果 URL 中带 ?token=，记录到会话以供前端使用
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $_SESSION['im_token'] = $_GET['token'];
}

/* ===================== 全站统一账号桥 ===================== */
$imBridgeToken = '';
$imBridgeUid = 0;
$imBridgeNick = '';
$imBridgeUname = '';

/**
 * 主站登录态 → IM 登录位桥接：
 *  1. 以主站 users.id 作为 IM 用户 id（首次自动建档，字段对齐 im/lib/db.php users store）
 *  2. 通过 issue_token() 签发页面 token，注入 window.AQUA_CHAT_TOKEN
 *  3. 同一 PHP 会话内复用已签发 token；签发时顺带清理 30 天前过期 token
 */
function im_bridge_main_account(): void {
    global $imBridgeToken, $imBridgeUid, $imBridgeNick, $imBridgeUname;
    if (!function_exists('isLoggedIn') || !isLoggedIn('user_id')) return;

    require_once __DIR__ . '/lib/config.php';
    $uid = (string)(int)($_SESSION['user_id'] ?? 0);
    if ($uid === '0') return;

    // 确保 IM 用户记录存在（首次建档，以主站 uid 为 id）
    if (kv_get('users', $uid) === null) {
        $row = function_exists('dbGetRow')
            ? dbGetRow("SELECT id, username, nick, role, is_admin, banned, created_at FROM users WHERE id = :id", [':id' => (int)$uid])
            : null;
        if (!$row) return;
        $role = ((int)($row['is_admin'] ?? 0) === 1) ? 'admin' : (($row['role'] ?? '') === 'arbiter' ? 'arbiter' : 'user');
        $created = trim((string)($row['created_at'] ?? ''));
        kv_put('users', $uid, [
            'id' => $uid,
            'name' => trim((string)($row['nick'] ?? '')) !== '' ? $row['nick'] : $row['username'],
            'username' => $row['username'],
            'role' => $role,
            'created' => $created !== '' ? date('c', strtotime($created) ?: time()) : date('c'),
            'avatar' => '',
            'bio' => '',
            'status' => 'online',
            'banned' => (int)($row['banned'] ?? 0) === 1,
            'note' => '全站统一账号',
        ]);
    }

    // 会话内复用 token，避免每刷新一次页面堆积一条
    if (!empty($_SESSION['im_token'])) {
        $st = db()->prepare('SELECT userId FROM tokens WHERE token = ?');
        $st->execute([$_SESSION['im_token']]);
        $row = $st->fetch();
        if ($row && (string)($row['userId'] ?? '') === $uid) {
            $imBridgeToken = $_SESSION['im_token'];
        } else {
            unset($_SESSION['im_token']);
        }
    }

    // 签发新 token 并顺带清理 30 天前过期 token，防止无限膨胀
    if ($imBridgeToken === '') {
        $cut = chat_now() - 30 * 86400000;
        db()->prepare('DELETE FROM tokens WHERE createdAt < ?')->execute([$cut]);
        $imBridgeToken = issue_token($uid);
        $_SESSION['im_token'] = $imBridgeToken;
    }

    $imBridgeUid = (int)$uid;
    $u0 = kv_get('users', $uid);
    if (is_array($u0)) {
        $imBridgeUname = (string)($u0['username'] ?? '');
        $imBridgeNick = (string)($u0['name'] ?? '');
    }
}

im_bridge_main_account();