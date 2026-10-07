<?php
/**
 * router.php — PHP 内置服务器单入口路由器（启动：php -S 127.0.0.1:80 router.php）
 *   - /api/* 与历史双前缀 /api/api/*  → 交给 im/api/index.php（即时通讯后端）
 *   - .htaccess 保护的敏感路径/文件类型  → 返回 404
 *   - 其余（.php 页面、css/js/img 静态资源）→ 返回 false，交给内置服务器处理
 */
declare(strict_types=1);

$__path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 1. IM API 路由
if (strncmp($__path, '/api', 4) === 0) {
    require __DIR__ . '/im/api/index.php';
    return true;
}

// 2. 阻止敏感路径访问（与 .htaccess 保持一致）
$__lowerPath = strtolower($__path);
$__blocked = [
    '/database/', '/im/data/', '/im/lib/', '/lang/', '/logs/', '/data/',
];
$__blockedExt = ['.db', '.sqlite', '.sqlite3', '.sql', '.lock', '.log', '.ini', '.txt', '.md', '.bak', '.env'];
foreach ($__blocked as $bp) {
    if (strpos($__lowerPath, $bp) === 0) {
        http_response_code(404);
        return true;
    }
}
foreach ($__blockedExt as $ext) {
    if (substr($__lowerPath, -strlen($ext)) === $ext) {
        http_response_code(404);
        return true;
    }
}

// 3. 隐藏的点文件（如 .git）
if (preg_match('#/\.[^/]+$#', $__path) || strpos($__path, '/.') !== false) {
    http_response_code(404);
    return true;
}

return false;
