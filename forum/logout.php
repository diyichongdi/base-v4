<?php
// 全站统一账号：退出由主站统一处理会话
require_once '../security.php';

logout();
header('Location: ../index.php');
exit;