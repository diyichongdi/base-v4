<?php
/**
 * 即时通讯 / 数据空间 — PHP 版全局配置
 * 仅依赖 PHP 内置扩展：pdo_sqlite / session / json / mbstring / dom
 *
 * 注意：主站 security.php 会抢先 define('DB_FILE', .../users.db)。
 * 本子系统必须使用自己的 im/data/app.db，故下列 IM 常量一律无条件定义，
 * 覆盖同进程内主站的同名常量（主站 dbGetRow/dbQuery 走独立连接，不受影响）。
 */
declare(strict_types=1);

define('ACE_DIR', dirname(__DIR__));
define('ROOT_DIR', dirname(ACE_DIR));
define('DATA_DIR', ACE_DIR . DIRECTORY_SEPARATOR . 'data');
define('UPLOAD_DIR', DATA_DIR . DIRECTORY_SEPARATOR . 'uploads');
define('FILES_DIR', DATA_DIR . DIRECTORY_SEPARATOR . 'files');
define('BACKUP_DIR', FILES_DIR . DIRECTORY_SEPARATOR . 'backup');
/* 本子系统独立数据库：不得复用主站 DB_FILE（security.php 已定义，且 PHP 常量不可覆盖） */
define('IM_DB_FILE', DATA_DIR . DIRECTORY_SEPARATOR . 'app.db');

/* 文件快传（与原 Node 版一致：去易混淆字符，24h 过期，500MB 上限） */
define('FT_CHARS', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
define('FT_TTL', 86400);
define('FT_MAX_BYTES', 524288000);

define('AQUA_VERSION', '2.0-php');

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('PRC');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/seed.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
