<?php
/**
 * helpers.php — 通用小函数（JSON 响应 / 与前端一致的 uid·hash / 转义）
 */
declare(strict_types=1);

if (!function_exists('starts_with')) {
    function starts_with($s, $p): bool {
        $s = (string)$s; $p = (string)$p;
        return $p === '' || strncmp($s, $p, strlen($p)) === 0;
    }
}
if (!function_exists('ends_with')) {
    function ends_with($s, $p): bool {
        $s = (string)$s; $p = (string)$p;
        return $p === '' || substr($s, -strlen($p)) === $p;
    }
}

/** HTML 转义（服务端渲染页面用） */
function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** 文件名安全化 */
function safe_name(string $n): string {
    return preg_replace('/[\\\\\/:*?"<>|\r\n]+/', '_', $n);
}

/* ---------- JSON 响应 ---------- */
function json_res($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function ok_res(array $data = []): void {
    json_res(array_merge(['ok' => true], $data));
}
function err_res(string $msg, int $code = 400, array $extra = []): void {
    json_res(array_merge(['ok' => false, 'error' => $msg, 'status' => $code], $extra), $code);
}

/** IM 接口词典取值：跟随站点语言（session/query），返回 lang/*.php 译文或原 key */
function apiL(string $key): string {
    static $dict = null;
    if ($dict === null) {
        $raw = $_GET['lang'] ?? ($_SESSION['lang'] ?? 'zh');
        $lang = ($raw === 'en') ? 'en' : 'zh';
        $file = dirname(__DIR__, 2) . '/lang/' . $lang . '.php';
        $dict = is_file($file) ? require $file : [];
    }
    return $dict[$key] ?? $key;
}

function req_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/** 读原始请求体（文件快传等） */
function req_raw(): string {
    return (string)file_get_contents('php://input');
}

/* ---------- 与前端 js/chat.js 一致的 ID 与哈希 ---------- */
/** Date.now()（毫秒时间戳，int） */
function chat_now(): int {
    return (int)floor(microtime(true) * 1000);
}
/** new Date().toISOString() 同款 */
function chat_now_iso(): string {
    return gmdate('Y-m-d\TH:i:s.v\Z');
}
/** Date.now().toString(36) + Math.random().toString(36).slice(2,8) 同款 */
function chat_uid(): string {
    $t = base_convert((string)chat_now(), 10, 36);
    $r = '';
    for ($i = 0; $i < 6; $i++) {
        $r .= base_convert((string)mt_rand(0, 35), 10, 36);
    }
    return $t . $r;
}
/**
 * Node 端 hashPass：32 位有符号整数逐字符 (h<<5)-h+c，再取绝对值 base36，前缀 'h'。
 * 必须与前端/旧库完全一致，否则旧密码凭证无法通过校验。
 */
function chat_hash_pass(string $p): string {
    if ($p === '') return '';
    $h = 0;
    $len = strlen($p);
    for ($i = 0; $i < $len; $i++) {
        $h = ($h * 31 + ord($p[$i])) & 0xFFFFFFFF;
        if ($h >= 0x80000000) $h -= 0x100000000;
    }
    return 'h' . base_convert((string)abs($h), 10, 36);
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/** 是否为 PHP 内置开发服务器（单线程，SSE 用短轮询避免阻塞） */
function is_cli_server(): bool {
    return PHP_SAPI === 'cli-server';
}
