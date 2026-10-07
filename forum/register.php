<?php
// 全站统一账号：论坛账号并入主站，注册入口统一为主站登录页的注册面板
require_once '../security.php';

if (isLoggedIn('user_id')) {
    header('Location: index.php');
    exit;
}
$next = trim((string)($_GET['next'] ?? ''));
if ($next === '') $next = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
header('Location: ../login.php?panel=register&next=' . urlencode($next));
exit;