<?php
require_once 'security.php';

// 全站统一退出：撤销 IM token，IM 登录态随主站一并失效
$imTok = $_SESSION['im_token'] ?? '';
if ($imTok !== '') {
    require_once 'im/lib/config.php';
    revoke_token($imTok);
    unset($_SESSION['im_token']);
}

// 清理"记住我"cookie
if (isset($_COOKIE['remember'])) {
    $token = hash('sha256', $_COOKIE['remember']);
    dbQuery("UPDATE users SET remember_token = NULL WHERE remember_token = :token", ['token' => $token]);
    setcookie('remember', '', time() - 3600, '/', '', true, true);
}

// 销毁会话
session_destroy();

// 重定向到登录页
header('Location: login.php');
exit;
