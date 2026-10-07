<?php
/**
 * Enhanced Security Core Library
 */

/* === Error & Log Mechanism === */
// Suppress error display on public pages — log to file instead
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Log directory
define('LOG_DIR', __DIR__ . '/logs');
if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0750, true);
}

if (!defined('ERROR_LOG_FILE')) {
    define('ERROR_LOG_FILE', LOG_DIR . '/error.log');
}
ini_set('error_log', ERROR_LOG_FILE);

/**
 * Custom error handler — logs but never displays to end users
 */
function handleError($errno, $errstr, $errfile, $errline) {
    // 尊重 @ 抑制：PHP 8 下 @ 仍会调用自定义 error handler，
    // 若不判断 error_reporting()，被 @ 抑制的告警（如无 https 包装器）会持续刷日志。
    if (!(error_reporting() & $errno)) {
        return true;
    }
    $errtypes = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_PARSE => 'PARSE',
        E_NOTICE => 'NOTICE',
        E_CORE_ERROR => 'CORE_ERROR',
        E_CORE_WARNING => 'CORE_WARNING',
        E_COMPILE_ERROR => 'COMPILE_ERROR',
        E_COMPILE_WARNING => 'COMPILE_WARNING',
        E_USER_ERROR => 'USER_ERROR',
        E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE',
        // E_STRICT 自 PHP 8.4 起已移除（引用即触发 Deprecated），改由下方 ?? 兜底为 UNKNOWN
        E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
    ];
    $type = $errtypes[$errno] ?? "UNKNOWN($errno)";
    $msg = sprintf(
        '[%s] %s: %s in %s on line %d',
        date('Y-m-d H:i:s'),
        $type,
        $errstr,
        $errfile,
        $errline
    );
    error_log($msg, 3, ERROR_LOG_FILE);

    // Suppress default PHP error handler
    return true;
}
set_error_handler('handleError');

/**
 * Custom exception handler — logs but never displays
 */
function handleException($e) {
    $msg = sprintf(
        '[%s] EXCEPTION: %s in %s on line %d',
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    );
    error_log($msg, 3, ERROR_LOG_FILE);
    // Don't display anything to the user
}
set_exception_handler('handleException');

/**
 * Shutdown handler — catches fatal errors
 */
function handleShutdown() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        $type = 'FATAL';
        $msg = sprintf(
            '[%s] %s: %s in %s on line %d',
            date('Y-m-d H:i:s'),
            $type,
            $error['message'],
            $error['file'],
            $error['line']
        );
        error_log($msg, 3, ERROR_LOG_FILE);
    }
}
register_shutdown_function('handleShutdown');

/* === Basic Configuration === */
// Basic Configuration
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', '3600');
ini_set('session.cookie_samesite', 'Strict');

// Start Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: blob:; connect-src 'self'");

// Constants
define('DB_DIR', __DIR__ . '/database');
define('DB_FILE', DB_DIR . '/users.db');
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 1800); // 30 minutes
define('CSRF_TOKEN_LIFETIME', 3600); // 1 hour

// Ensure directory exists
if (!is_dir(DB_DIR)) {
    @mkdir(DB_DIR, 0750, true);
}

// Language support
function getLang() {
    return $_SESSION['lang'] ?? 'zh';
}

function setLang($lang) {
    if (in_array($lang, ['zh', 'en'])) {
        $_SESSION['lang'] = $lang;
    }
}

function L($key, $params = []) {
    $lang = getLang();
    $file = __DIR__ . '/lang/' . $lang . '.php';
    if (file_exists($file)) {
        $translations = require $file;
        $txt = $translations[$key] ?? $key;
    } else {
        $txt = $key;
    }
    if ($params) {
        foreach ($params as $k => $v) {
            $txt = str_replace('{' . $k . '}', (string)$v, $txt);
        }
    }
    return $txt;
}

/* 返回当前语言完整词典（IM 前端 window.AQUA_I18N 一次性注入用） */
function L_All() {
    $lang = getLang();
    $file = __DIR__ . '/lang/' . $lang . '.php';
    return file_exists($file) ? require $file : [];
}

// Initialize language from request
if (isset($_GET['lang']) && in_array($_GET['lang'], ['zh', 'en'])) {
    setLang($_GET['lang']);
}

/**
 * Initialize Database with enhanced schema
 */
function initDB() {
    static $initialized = false;
    if ($initialized) return;
    
    if (!file_exists(DB_FILE)) {
        $db = new SQLite3(DB_FILE);
        $db->exec('PRAGMA journal_mode = DELETE');
        $db->exec('PRAGMA foreign_keys = ON');
        
        // Main users table
        $db->exec('
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL COLLATE NOCASE,
                password TEXT NOT NULL,
                email TEXT,
                role TEXT DEFAULT "user",
                language TEXT DEFAULT "zh",
                login_attempts INTEGER DEFAULT 0,
                locked_until INTEGER DEFAULT 0,
                created_at INTEGER NOT NULL,
                last_login INTEGER,
                is_active INTEGER DEFAULT 1,
                remember_token TEXT
            )
        ');
        
        // Login logs
        $db->exec('
            CREATE TABLE IF NOT EXISTS login_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                username TEXT,
                ip TEXT NOT NULL,
                user_agent TEXT,
                success INTEGER DEFAULT 0,
                created_at INTEGER NOT NULL
            )
        ');
        
        // Sessions for enhanced tracking
        $db->exec('
            CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                user_id INTEGER,
                ip TEXT NOT NULL,
                user_agent TEXT,
                created_at INTEGER NOT NULL,
                last_activity INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            )
        ');
        
        $db->close();
    }
    
    $initialized = true;
}

initDB();

/**
 * 数据库结构迁移（每次请求运行，幂等）
 * 保证旧库自动补齐缺失列，不影响已有数据。
 */
function migrateDB() {
    if (!file_exists(DB_FILE)) return;
    static $migrated = false;
    if ($migrated) return;

    $db = new SQLite3(DB_FILE);
    $db->busyTimeout(5000);

    $columns = [];
    $result = @$db->query("PRAGMA table_info(users)");
    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[$row['name']] = true;
        }
    }

    $additions = [
        'role' => 'TEXT DEFAULT "user"',
        'language' => 'TEXT DEFAULT "zh"',
        'remember_token' => 'TEXT',
        'balance' => 'REAL DEFAULT 0',
        'is_admin' => 'INTEGER DEFAULT 0',
        'banned' => 'INTEGER DEFAULT 0',
        'nick' => 'TEXT DEFAULT ""',
        'is_arbiter' => 'INTEGER DEFAULT 0',
    ];

    foreach ($additions as $col => $def) {
        if (!isset($columns[$col])) {
            try { $db->exec("ALTER TABLE users ADD COLUMN $col $def"); } catch (Exception $e) {}
        }
    }

    // 隐私改造：公开用户名一律为系统分配的纯数字ID，原用户名转入 nick（仅自己可见）
    $r = @$db->query("SELECT id, username FROM users WHERE username GLOB '*[^0-9]*' OR username = ''");
    if ($r) {
        $pending = [];
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) $pending[] = $row;
        foreach ($pending as $pu) {
            do {
                $newNo = (string)random_int(20000000, 99999999);
                $dup = @$db->querySingle("SELECT COUNT(*) FROM users WHERE username = '" . SQLite3::escapeString($newNo) . "'");
            } while ($dup);
            $st = $db->prepare("UPDATE users SET username = :n, nick = :o WHERE id = :id");
            $st->bindValue(':n', $newNo, SQLITE3_TEXT);
            $st->bindValue(':o', $pu['username'], SQLITE3_TEXT);
            $st->bindValue(':id', $pu['id'], SQLITE3_INTEGER);
            $st->execute();
        }
    }

    // 免密登录凭据（设备一键登录 / 长句密钥登录，与即时通讯方式一致）
    $db->exec('CREATE TABLE IF NOT EXISTS auth_methods (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        login_type TEXT NOT NULL,
        identifier TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        device_pwd_hash TEXT,
        UNIQUE(login_type, identifier)
    )');

    // 为旧库补充 device_pwd_hash 列
    $cols = [];
    $mc = @$db->query('PRAGMA table_info(auth_methods)');
    if ($mc) { while ($mrow = $mc->fetchArray(SQLITE3_ASSOC)) { $cols[] = $mrow['name']; } }
    if (!in_array('device_pwd_hash', $cols)) {
        try { $db->exec('ALTER TABLE auth_methods ADD COLUMN device_pwd_hash TEXT'); } catch (Exception $e) {}
    }

    // 旧角色体系迁移到 is_admin
    $db->exec("UPDATE users SET is_admin = 1 WHERE is_admin = 0 AND role = 'admin'");

    $db->close();
    $migrated = true;
}

migrateDB();
ensureArbiterAccount();

/* ==================== 纯数字公开ID ==================== */

/**
 * 生成全局唯一的 8 位纯数字用户名（系统分配，公开显示）
 */
function generateNumericUsername() {
    do {
        $n = (string)random_int(20000000, 99999999);
        if (!dbGetRow("SELECT id FROM users WHERE username = :u", [':u' => $n])) return $n;
    } while (true);
}

/* ==================== 角色 / 管理员 ==================== */

/**
 * 当前登录用户是否为管理员
 */
function isAdmin() {
    if (!isset($_SESSION['user_id'])) return false;
    $u = dbGetRow("SELECT is_admin, role FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
    if (!$u) return false;
    return intval($u['is_admin'] ?? 0) === 1 || ($u['role'] ?? '') === 'admin';
}

function requireAdmin() {
    if (!isAdmin()) {
        header('Location: ../login.php');
        exit;
    }
}

/**
 * 当前用户是否为仲裁员（审批充值/提现/仲裁订单）
 */
function isArbiter() {
    if (!isset($_SESSION['user_id'])) return false;
    if (isAdmin()) return true;
    if (!empty($_SESSION['is_arbiter']) && intval($_SESSION['is_arbiter']) === 1) return true;
    $u = dbGetRow("SELECT is_arbiter FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
    return $u && intval($u['is_arbiter'] ?? 0) === 1;
}

/**
 * 确保存在默认仲裁员账号（纯数字ID，nick=ArbiterBot），幂等
 */
function ensureArbiterAccount() {
    $exists = dbGetRow("SELECT id FROM users WHERE is_arbiter = 1 LIMIT 1");
    if ($exists) {
        setSetting('arbiter_user_id', (string)$exists['id']);
        return false;
    }
    $uid = dbGetRow("SELECT id FROM users WHERE username = '80000001' LIMIT 1");
    if ($uid) {
        dbQuery("UPDATE users SET is_arbiter = 1, nick = 'ArbiterBot' WHERE id = :id", [':id' => $uid['id']]);
        setSetting('arbiter_user_id', (string)$uid['id']);
        return true;
    }
    $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_ARGON2ID, [
        'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3
    ]);
    $id = dbInsert(
        "INSERT INTO users (username, nick, password, email, role, is_arbiter, created_at) VALUES (:u, :n, :p, :e, 'arbiter', 1, :t)",
        [':u' => '80000001', ':n' => 'ArbiterBot', ':p' => $hash, ':e' => 'arbiter@local', ':t' => time()]
    );
    if ($id) setSetting('arbiter_user_id', (string)$id);
    return true;
}

/**
 * 当前登录用户是否被禁止
 */
function isBannedUser() {
    if (!isset($_SESSION['user_id'])) return false;
    $u = dbGetRow("SELECT banned FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
    return $u && intval($u['banned'] ?? 0) === 1;
}

/* ==================== 站点设置（KV 存储） ==================== */

function getSetting($key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $rows = dbQuery("SELECT key, value FROM settings");
        if (is_array($rows)) {
            foreach ($rows as $r) $cache[$r['key']] = $r['value'];
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function setSetting($key, $value) {
    dbQuery("INSERT INTO settings (key, value) VALUES (:k, :v)
        ON CONFLICT(key) DO UPDATE SET value = :v", [':k' => $key, ':v' => $value]);
}

/**
 * 确保存在默认管理员账号（无管理员时自动创建）
 * 返回是否本次新建
 */
function ensureAdminAccount() {
    $exists = dbGetRow("SELECT id FROM users WHERE is_admin = 1 OR role = 'admin' LIMIT 1");
    if ($exists) return false;

    // 密码通过环境变量 ADMIN_INIT_PASSWORD 配置；若未设置则生�随机密码并记录到日志
    $password = getenv('ADMIN_INIT_PASSWORD') ?: bin2hex(random_bytes(12));
    $username = getenv('ADMIN_INIT_USERNAME') ?: 'admin';
    $hash = password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3
    ]);
    $id = dbInsert(
        "INSERT INTO users (username, password, email, role, created_at) VALUES (:u, :p, :e, 'admin', :t)",
        [':u' => $username, ':p' => $hash, ':e' => 'admin@local.dev', ':t' => time()]
    );
    if ($id) {
        dbQuery("UPDATE users SET is_admin = 1 WHERE id = :id", [':id' => $id]);
        // 仅在自动生成密码时记录到日志（部署环境应设置 ADMIN_INIT_PASSWORD）
        if (!getenv('ADMIN_INIT_PASSWORD')) {
            error_log('[SECURITY] 自动创建管理员账号 ' . $username . '，初始密码: ' . $password . ' — 请尽快修改密码！');
        }
        return true;
    }
    return false;
}

/**
 * 用户余额（防御式读取）
 */
function userBalance($user) {
    $b = $user['balance'] ?? 0;
    return is_numeric($b) ? (float)$b : 0.0;
}

/**
 * 统一金额展示：法币固定 2 位小数，前缀 $
 */
function fmtMoney($n): string {
    return '$' . number_format((float)$n, 2);
}

/**
 * 统一加密币展示：最多 8 位小数，去尾零（0 → '0'）
 */
function fmtCrypto($n): string {
    $s = rtrim(rtrim(number_format((float)$n, 8, '.', ''), '0'), '.');
    return ($s === '' || $s === '-') ? '0' : $s;
}

/**
 * 统一汇率/币价：≥5 保留 2 位，<5 保留 4 位，去尾零
 */
function fmtRate($n): string {
    $v = (float)$n;
    return rtrim(rtrim(number_format($v, $v >= 5 ? 2 : 4, '.', ''), '0'), '.');
}

ensureAdminAccount();

/**
 * Enhanced Database Query with File Lock and prepared statements
 */
function dbQuery($sql, $params = [], $dbFile = null) {
    $dbFile = $dbFile ?: DB_FILE;
    $lockFile = $dbFile . '.lock';
    
    $lockFp = @fopen($lockFile, 'c');
    if (!$lockFp) {
        error_log("Cannot create lock file: " . $lockFile);
        return false;
    }
    
    $locked = false;
    $waitTime = 0;
    $maxWait = 5000000; // 5 seconds
    
    while (!$locked && $waitTime < $maxWait) {
        $locked = flock($lockFp, LOCK_EX | LOCK_NB);
        if (!$locked) {
            usleep(100000); // 100ms
            $waitTime += 100000;
        }
    }
    
    if (!$locked) {
        fclose($lockFp);
        error_log("Database lock timeout");
        return false;
    }
    
    try {
        $db = new SQLite3($dbFile);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA foreign_keys = ON');
        
        $stmt = @$db->prepare($sql);
        if (!$stmt) {
            $db->close();
            return false;
        }
        
        foreach ($params as $key => $val) {
            if (is_int($val)) {
                $stmt->bindValue($key, $val, SQLITE3_INTEGER);
            } elseif (is_float($val)) {
                $stmt->bindValue($key, $val, SQLITE3_FLOAT);
            } elseif (is_null($val)) {
                $stmt->bindValue($key, null, SQLITE3_NULL);
            } else {
                $stmt->bindValue($key, (string)$val, SQLITE3_TEXT);
            }
        }
        
        $result = @$stmt->execute();
        if (!$result) {
            $stmt->close();
            $db->close();
            return false;
        }
        
        // Check if it's a SELECT query
        if (stripos(trim($sql), 'SELECT') === 0 || stripos(trim($sql), 'PRAGMA') === 0) {
            $data = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $data[] = $row;
            }
            $stmt->close();
            $db->close();
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            return $data;
        }
        
        $stmt->close();
        $db->close();
        
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        
        return true;
        
    } catch (Exception $e) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        error_log("Database error: " . $e->getMessage());
        return false;
    }
}

function dbGetRow($sql, $params = [], $dbFile = null) {
    $result = dbQuery($sql, $params, $dbFile);
    return (is_array($result) && !empty($result)) ? $result[0] : null;
}

/**
 * 获取主数据库连接（市场/IM 模块初始化使用）
 */
function getDB() {
    $db = new SQLite3(DB_FILE);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA foreign_keys = ON');
    return $db;
}

function dbInsert($sql, $params = [], $dbFile = null) {
    $dbFile = $dbFile ?: DB_FILE;
    $lockFile = $dbFile . '.lock';
    
    $lockFp = @fopen($lockFile, 'c');
    if (!$lockFp) return false;
    
    $locked = false;
    $waitTime = 0;
    while (!$locked && $waitTime < 5000000) {
        $locked = flock($lockFp, LOCK_EX | LOCK_NB);
        if (!$locked) usleep(100000);
        $waitTime += 100000;
    }
    
    if (!$locked) {
        fclose($lockFp);
        return false;
    }
    
    try {
        $db = new SQLite3($dbFile);
        $db->busyTimeout(5000);
        
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            fclose($lockFp);
            return false;
        }
        
        foreach ($params as $key => $val) {
            $type = is_int($val) ? SQLITE3_INTEGER : (is_float($val) ? SQLITE3_FLOAT : SQLITE3_TEXT);
            $stmt->bindValue($key, $val, $type);
        }
        
        $result = $stmt->execute();
        $lastId = $db->lastInsertRowID();
        
        $stmt->close();
        $db->close();
        
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        
        return $lastId;
        
    } catch (Exception $e) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        return false;
    }
}

function initCustomDB($dbFile, $schema) {
    if (file_exists($dbFile)) return;
    
    $db = new SQLite3($dbFile);
    $db->exec('PRAGMA journal_mode = DELETE');
    $db->exec('PRAGMA foreign_keys = ON');
    foreach ($schema as $sql) {
        $db->exec($sql);
    }
    $db->close();
}

// ==================== Security Functions ====================

function checkRateLimit($action, $maxAttempts = 5, $window = 300) {
    $ip = getClientIP();
    $key = hash('sha256', $ip . $action);
    $file = sys_get_temp_dir() . '/rl_' . substr($key, 0, 16) . '.dat';
    
    $now = time();
    $attempts = [];
    
    if (file_exists($file)) {
        $data = @file_get_contents($file);
        if ($data) {
            $times = @unserialize($data);
            if (is_array($times)) {
                foreach ($times as $t) {
                    if ($now - intval($t) < $window) {
                        $attempts[] = $t;
                    }
                }
            }
        }
    }
    
    if (count($attempts) >= $maxAttempts) {
        return false;
    }
    
    $attempts[] = $now;
    @file_put_contents($file, serialize($attempts), LOCK_EX);
    
    return true;
}

function generateCaptcha() {
    $code = substr(str_shuffle("ABCDEFGHJKLMNPQRSTUVWXYZ23456789"), 0, 5);
    $_SESSION['captcha_code'] = $code;
    $_SESSION['captcha_time'] = time();
    $_SESSION['captcha_attempts'] = 0;
    return $code;
}

function validateCaptcha($input) {
    if (empty($_SESSION['captcha_code'])) {
        return ['valid' => false, 'msg' => L('err_invalid_captcha')];
    }
    
    if (time() - $_SESSION['captcha_time'] > 300) {
        unset($_SESSION['captcha_code'], $_SESSION['captcha_time'], $_SESSION['captcha_attempts']);
        return ['valid' => false, 'msg' => L('err_invalid_captcha')];
    }
    
    if (($_SESSION['captcha_attempts'] ?? 0) >= 3) {
        unset($_SESSION['captcha_code'], $_SESSION['captcha_time'], $_SESSION['captcha_attempts']);
        return ['valid' => false, 'msg' => L('err_invalid_captcha')];
    }
    
    if (strtoupper(trim($input)) !== $_SESSION['captcha_code']) {
        $_SESSION['captcha_attempts']++;
        return ['valid' => false, 'msg' => L('err_invalid_captcha')];
    }
    
    unset($_SESSION['captcha_code'], $_SESSION['captcha_time'], $_SESSION['captcha_attempts']);
    return ['valid' => true, 'msg' => 'OK'];
}

function checkPasswordStrength($password) {
    if (strlen($password) < 8) {
        return ['valid' => false, 'msg' => 'Password must be at least 8 characters'];
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return ['valid' => false, 'msg' => 'Password must contain uppercase letter'];
    }
    if (!preg_match('/[a-z]/', $password)) {
        return ['valid' => false, 'msg' => 'Password must contain lowercase letter'];
    }
    if (!preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'msg' => 'Password must contain number'];
    }
    return ['valid' => true, 'msg' => 'OK'];
}

function isUserLocked($username, $dbFile = null) {
    $row = dbGetRow(
        "SELECT login_attempts, locked_until FROM users WHERE username = :user COLLATE NOCASE",
        [':user' => $username],
        $dbFile
    );
    
    if (!$row) return false;
    
    $lockedUntil = intval($row['locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        return ceil(($lockedUntil - time()) / 60);
    }
    
    // Reset if lock expired
    if ($lockedUntil > 0 && $lockedUntil <= time()) {
        dbQuery(
            "UPDATE users SET login_attempts = 0, locked_until = 0 WHERE username = :user COLLATE NOCASE",
            [':user' => $username],
            $dbFile
        );
    }
    
    return false;
}

function recordLoginFailure($username, $dbFile = null) {
    $user = strtolower($username);
    
    $row = dbGetRow(
        "SELECT id, login_attempts FROM users WHERE username = :user COLLATE NOCASE",
        [':user' => $user],
        $dbFile
    );
    
    if (!$row) return;
    
    $attempts = intval($row['login_attempts']) + 1;
    $lockedUntil = ($attempts >= MAX_LOGIN_ATTEMPTS) ? time() + LOCKOUT_TIME : 0;
    
    dbQuery(
        "UPDATE users SET login_attempts = :a, locked_until = :l WHERE id = :id",
        [':a' => $attempts, ':l' => $lockedUntil, ':id' => $row['id']],
        $dbFile
    );
    
    dbQuery(
        "INSERT INTO login_logs (user_id, username, ip, user_agent, success, created_at) VALUES (:uid, :u, :ip, :ua, 0, :t)",
        [
            ':uid' => $row['id'],
            ':u' => $user,
            ':ip' => getClientIP(),
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ':t' => time()
        ],
        $dbFile
    );
}

function recordLoginSuccess($userId, $username, $dbFile = null) {
    dbQuery(
        "UPDATE users SET login_attempts = 0, locked_until = 0, last_login = :t WHERE id = :id",
        [':t' => time(), ':id' => $userId],
        $dbFile
    );
    
    dbQuery(
        "INSERT INTO login_logs (user_id, username, ip, user_agent, success, created_at) VALUES (:uid, :u, :ip, :ua, 1, :t)",
        [
            ':uid' => $userId,
            ':u' => strtolower($username),
            ':ip' => getClientIP(),
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ':t' => time()
        ],
        $dbFile
    );
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time']) || 
        (time() - $_SESSION['csrf_token_time'] > CSRF_TOKEN_LIFETIME)) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    if (time() - ($_SESSION['csrf_token_time'] ?? 0) > CSRF_TOKEN_LIFETIME) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function isLoggedIn($sessionKey = 'user_id') {
    if (!isset($_SESSION[$sessionKey])) {
        return false;
    }
    
    // Check session timeout (1 hour)
    if (!isset($_SESSION['last_activity']) || (time() - $_SESSION['last_activity'] > 3600)) {
        logout($sessionKey);
        return false;
    }
    
    // Check IP binding
    if (isset($_SESSION['ip']) && $_SESSION['ip'] !== getClientIP()) {
        logout($sessionKey);
        return false;
    }
    
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * 登录后回跳目标校验：仅允许站内相对路径（拒绝绝对地址/协议相对地址/控制字符），
 * 防止开放重定向与 header Location 注入。
 */
function cleanRedirectTarget($url) {
    $raw = trim((string)$url);
    if ($raw === '') return '';
    if (preg_match("/[\r\n\\\\]/", $raw)) return '';
    if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $raw)) return '';
    if (strpos($raw, '//') === 0) return '';
    if (preg_match('#^[a-z]+\s*:#i', $raw)) return '';
    return $raw;
}

/**
 * 决定登录成功后的跳转地址：有合法回跳则用之，否则回到控制台。
 */
function redirectTarget($returnTo = '') {
    $next = cleanRedirectTarget($returnTo);
    return $next !== '' ? $next : 'dashboard.php';
}

function logout($sessionKey = 'user_id') {
    // Clear specific session data
    $keysToRemove = [$sessionKey, 'username', 'login_time', 'last_activity', 'ip'];
    foreach ($keysToRemove as $key) {
        unset($_SESSION[$key]);
    }
    
    // Only destroy session if main user logged out
    if ($sessionKey === 'user_id') {
        session_unset();
        session_destroy();
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
    }
}

/**
 * 获取客户端真实 IP。
 * 安全原则：仅在受信任的代理/CDN 之后时才信任 X-Forwarded-* / CF-Connecting-IP。
 * 受信任代理列表通过 TRUSTED_PROXIES 常量配置（CIDR 或单 IP）。
 * 开发环境（php -S）下不配置任何代理 → 始终使用 REMOTE_ADDR。
 */
function getClientIP() {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // 检查是否来自受信任的代理
    $trustedProxies = [];
    if (defined('TRUSTED_PROXIES')) {
        $trustedProxies = is_array(TRUSTED_PROXIES)
            ? TRUSTED_PROXIES
            : explode(',', TRUSTED_PROXIES);
    }

    $isTrusted = false;
    foreach ($trustedProxies as $proxy) {
        $proxy = trim($proxy);
        if ($proxy === '' || $proxy === '0.0.0.0') continue;
        if (cidrMatch($remoteAddr, $proxy)) {
            $isTrusted = true;
            break;
        }
    }

    if ($isTrusted) {
        $proxyHeaders = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
        ];
        foreach ($proxyHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // 校验 IP 格式，但允许私有/内网范围（部署在内网代理后）
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    }

    // 非受信环境：始终返回直接连接的 REMOTE_ADDR，防止伪造
    return $remoteAddr;
}

/**
 * CIDR 匹配：检查 $ip 是否在 $cidr 范围内
 */
function cidrMatch($ip, $cidr): bool {
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }
    [$subnet, $maskBits] = explode('/', $cidr);
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($ipLong === false || $subnetLong === false) return false;
    $mask = -1 << (32 - (int)$maskBits);
    $subnetLong &= $mask;
    return ($ipLong & $mask) === $subnetLong;
}

function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * 简单 HTML 白名单过滤 — 允许有限的标签和属性，防止存储型 XSS。
 * 仅过滤前端渲染可信区域（如页脚自定义内容）。
 */
function sanitizeHtml(string $html): string {
    $allowedTags = ['br', 'p', 'a', 'span', 'div', 'strong', 'em', 'b', 'i', 'small', 'ul', 'ol', 'li'];
    $allowedAttrs = ['href', 'title', 'style', 'class', 'target'];
    $clean = strip_tags($html, '<' . implode('><', $allowedTags) . '>');
    // 移除非白名单属性
    $clean = preg_replace_callback('/<(\w+)(\s[^>]*)>/', function ($m) use ($allowedAttrs) {
        $tag = $m[1];
        $attrs = $m[2];
        $result = '';
        preg_match_all('/(\w+)\s*=\s*"[^"]*"|(\w+)\s*=\s*\'[^\']*\'|(\w+)/', $attrs, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $attr = $match[3] ?? ($match[1] ? strtolower(preg_replace('/^.*?\s+/', '', $match[1])) : '');
            if (in_array(strtolower($match[0]), $allowedAttrs)) {
                $result .= ' ' . $match[0];
            }
        }
        // 安全处理 style 属性 — 仅允许 color, text-align, font-size, margin, padding
        $result = preg_replace_callback('/style="([^"]*)"/i', function ($sm) {
            $safeStyles = ['color', 'text-align', 'font-size', 'font-weight', 'margin', 'padding', 'text-decoration'];
            $pairs = [];
            foreach (explode(';', $sm[1]) as $decl) {
                $decl = trim($decl);
                if ($decl === '') continue;
                $parts = explode(':', $decl);
                $prop = strtolower(trim($parts[0]));
                $val = trim($parts[1] ?? '');
                if (in_array($prop, $safeStyles) && preg_match('/^[a-zA-Z0-9%#.\s\-]+$/', $val)) {
                    $pairs[] = $prop . ':' . $val;
                }
            }
            return $pairs ? ' style="' . implode('; ', $pairs) . '"' : '';
        }, $result);
        // 强制 a 标签 target="_blank" rel="noopener noreferrer"
        if (strtolower($tag) === 'a' && strpos($result, 'target=') === false) {
            $result .= ' target="_blank" rel="noopener noreferrer"';
        }
        return '<' . $tag . $result . '>';
    }, $clean);
    // 处理 self-closed br 标签
    $clean = preg_replace('/<(br)\/?>/i', '<br>', $clean);
    return $clean;
}

function regenerateSession() {
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
}

function checkSessionSecurity() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    if (!isset($_SESSION['last_activity']) || (time() - $_SESSION['last_activity'] > 3600)) {
        logout();
        return false;
    }
    if (isset($_SESSION['ip']) && $_SESSION['ip'] !== getClientIP()) {
        logout();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

function generateRandomToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * 相对时间显示（i18n）
 */
function timeAgo($datetime) {
    $time = strtotime($datetime);
    if ($time === false || $time <= 0) {
        return htmlspecialchars((string)$datetime);
    }
    $diff = time() - $time;
    $lang = getLang();
    if ($diff < 60) return $lang === 'zh' ? '刚刚' : 'just now';
    if ($diff < 3600) return ($lang === 'zh' ? floor($diff / 60) . ' 分钟前' : floor($diff / 60) . ' min ago');
    if ($diff < 86400) return ($lang === 'zh' ? floor($diff / 3600) . ' 小时前' : floor($diff / 3600) . ' hr ago');
    if ($diff < 604800) return ($lang === 'zh' ? floor($diff / 86400) . ' 天前' : floor($diff / 86400) . ' days ago');
    return date('Y-m-d', $time);
}
?>
