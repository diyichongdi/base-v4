<?php
// 论坛模块初始化 - 独立数据库
require_once '../security.php';

// 论坛数据库路径
define('FORUM_DB', __DIR__ . '/forum.db');

// 初始化论坛数据库
function initForumDB() {
    if (file_exists(FORUM_DB)) return;
    
    $db = new SQLite3(FORUM_DB);
    $db->busyTimeout(5000);
    
    // 全站统一账号：论坛不再拥有独立用户体系，帖子/评论作者 user_id 直接关联主站 users 表（maindb）
    $db->exec("CREATE TABLE IF NOT EXISTS forum_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        name_en TEXT NOT NULL,
        description TEXT,
        icon TEXT DEFAULT 'bi-folder',
        sort_order INTEGER DEFAULT 0,
        is_hidden INTEGER DEFAULT 0
    )");
    
    // 帖子表
    $db->exec("CREATE TABLE IF NOT EXISTS forum_posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        is_pinned INTEGER DEFAULT 0,
        is_locked INTEGER DEFAULT 0,
        views INTEGER DEFAULT 0,
        reply_count INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES forum_categories(id)
    )");
    
    // 评论表
    $db->exec("CREATE TABLE IF NOT EXISTS forum_replies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        content TEXT NOT NULL,
        parent_id INTEGER DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (post_id) REFERENCES forum_posts(id) ON DELETE CASCADE,
        FOREIGN KEY (parent_id) REFERENCES forum_replies(id)
    )");
    
    // 点赞表
    $db->exec("CREATE TABLE IF NOT EXISTS forum_likes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER,
        reply_id INTEGER,
        user_id INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(post_id, user_id),
        UNIQUE(reply_id, user_id)
    )");
    
    // 插入默认板块
    $db->exec("INSERT OR IGNORE INTO forum_categories (id, name, name_en, description, icon, sort_order) VALUES
        (1, '综合讨论', 'General', '自由交流，分享想法', 'bi-chat-square-text', 1),
        (2, '技术交流', 'Technology', '编程、黑客技术、网络安全', 'bi-code-slash', 2),
        (3, '交易市场', 'Trading', '商品交易、服务讨论', 'bi-shop', 3),
        (4, '新手入门', 'Beginners', '新人求助，基础问题', 'bi-question-circle', 4),
        (5, '公告栏', 'Announcements', '官方公告，重要通知', 'bi-megaphone', 0)
    ");
    
    $db->close();
}

// 论坛数据库操作
function forumDB() {
    $db = new SQLite3(FORUM_DB);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA foreign_keys = ON');
    // 全站统一账号：把主站用户库 attach 为 maindb，论坛作者直接查询主站用户
    if (defined('DB_FILE') && is_file(DB_FILE)) {
        $db->exec("ATTACH DATABASE '" . str_replace("'", "''", DB_FILE) . "' AS maindb");
    }
    return $db;
}

function forumQuery($sql, $params = []) {
    $db = forumDB();
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue(':' . $key, $val);
    }
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    $db->close();
    return $rows;
}

function forumGetRow($sql, $params = []) {
    $rows = forumQuery($sql, $params);
    return $rows[0] ?? null;
}

function forumExec($sql, $params = []) {
    $db = forumDB();
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue(':' . $key, $val);
    }
    $result = $stmt->execute();
    $db->close();
    return $result;
}

// 论坛会话管理（全站统一账号：复用主站会话）
function isForumLoggedIn() {
    return isLoggedIn('user_id');
}

function getForumUser() {
    if (!isForumLoggedIn()) return null;
    return dbGetRow("SELECT id, username, nick, role, is_admin, is_arbiter, banned, email, created_at FROM users WHERE id = :id", ['id' => $_SESSION['user_id']]);
}

function forumLogout() {
    // 会话由主站 logout.php 统一管理
}

/**
 * bootstrap-icons 名称映射为 Font Awesome（设计系统用）
 */
function forumIcon($bi) {
    $map = [
        'bi-chat-square-text' => 'fas fa-comments',
        'bi-code-slash' => 'fas fa-code',
        'bi-shop' => 'fas fa-store',
        'bi-question-circle' => 'fas fa-circle-question',
        'bi-megaphone' => 'fas fa-bullhorn',
        'bi-folder' => 'fas fa-folder',
        'bi-pin-angle' => 'fas fa-thumbtack',
        'bi-clock-history' => 'fas fa-clock-rotate-left',
        'bi-bar-chart' => 'fas fa-chart-simple',
    ];
    return $map[$bi] ?? 'fas fa-folder';
}

// 初始化数据库
initForumDB();

/**
 * 主站管理员是否已登录（论坛管理操作使用主站管理员会话）
 */
function forumIsMainAdmin() {
    return isLoggedIn('user_id') && isAdmin();
}


