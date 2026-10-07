<?php
// 全站统一账号：论坛不再拥有独立登录系统，转发主站登录页（next 原样传递，登录后回跳）
require_once '../security.php';

if (isLoggedIn('user_id')) {
    header('Location: index.php');
    exit;
}
$next = trim((string)($_GET['next'] ?? ''));
if ($next === '') $next = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
header('Location: ../login.php?next=' . urlencode($next));
exit;