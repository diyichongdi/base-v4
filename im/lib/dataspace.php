<?php
/**
 * dataspace.php — 可信数据空间（服务端渲染）共享布局与辅助函数
 * 依赖：css/style.css、js/icons.js、js/modal.js（登录页 alert 保留）、js/auth.js 不再使用
 */
declare(strict_types=1);

function ds_page_style(): string {
    return <<<CSS
<style>
    html{opacity:0;transition:opacity .15s ease-out}
    .header,.nav{background:#6a4ec8}
    [data-ico]{display:inline-block;width:24px;height:24px;vertical-align:-2px}
    h1,h2{display:flex;align-items:baseline;gap:10px}
    h1 [data-ico],h2 [data-ico]{width:26px;height:26px;vertical-align:baseline}
    .panel {
        background: rgba(255,255,255,.7);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        padding: 35px;
        margin-top: 30px;
        border-radius: 16px;
        border: 1px solid rgba(255,255,255,.6);
        box-shadow: 0 8px 24px rgba(106,78,200,.1);
    }
    .panel form { display:flex; flex-direction:column; gap:12px; max-width:520px; width:100%; }
    .panel label { font-weight:bold; margin-top:8px; color:#4a4266; }
    .panel input[type=text], .panel input[type=password], .panel input[type=number],
    .panel input[type=date], .panel textarea, .panel select {
        width:100%; padding:11px 14px;
        border:1px solid rgba(155,109,255,.3); border-radius:10px;
        font-size:15px; background:rgba(255,255,255,.7); color:#333;
        box-sizing:border-box; transition:all .3s ease;
    }
    .panel input:focus, .panel textarea:focus, .panel select:focus {
        outline:none; border-color:#9b6dff; background:rgba(255,255,255,.9);
        box-shadow:0 0 0 3px rgba(155,109,255,.12);
    }
    .panel textarea { height:100px; resize:vertical; }
    .data-table { width:100%; border-collapse:collapse; margin-top:20px; }
    .data-table th,.data-table td { padding:12px 15px; text-align:left;
        border-bottom:1px solid rgba(155,109,255,.15); white-space:nowrap; font-size:14px; }
    .data-table th { background:rgba(155,109,255,.15); color:#6a4ec8; font-weight:bold; }
    .data-table tr:hover { background:rgba(155,109,255,.06); }
    .table-wrapper { overflow-x:auto; }
    .danger-btn { padding:6px 16px; background:linear-gradient(135deg,#e74c3c,#c0392b);
        color:#fff; border:none; border-radius:20px; cursor:pointer; font-size:13px; white-space:nowrap;
        box-shadow:0 4px 10px rgba(231,76,60,.3); transition:all .3s ease; }
    .danger-btn:hover { transform:translateY(-2px); box-shadow:0 6px 14px rgba(231,76,60,.4); }
    .btn-approve { padding:5px 14px; background:linear-gradient(135deg,#27ae60,#219150);
        color:#fff; border:none; border-radius:20px; cursor:pointer; white-space:nowrap;
        box-shadow:0 3px 8px rgba(39,174,96,.3); transition:all .3s ease; }
    .btn-approve:hover { transform:translateY(-2px); box-shadow:0 5px 12px rgba(39,174,96,.4); }
    .btn-reject { padding:5px 14px; background:linear-gradient(135deg,#e74c3c,#c0392b);
        color:#fff; border:none; border-radius:20px; cursor:pointer; white-space:nowrap;
        box-shadow:0 3px 8px rgba(231,76,60,.3); transition:all .3s ease; }
    .btn-reject:hover { transform:translateY(-2px); box-shadow:0 5px 12px rgba(231,76,60,.4); }
    .btn-download { padding:5px 14px; background:linear-gradient(135deg,#6a4ec8,#9b6dff);
        color:#fff; border:none; border-radius:20px; cursor:pointer; font-size:13px; white-space:nowrap;
        box-shadow:0 3px 8px rgba(106,78,200,.3); transition:all .3s ease; }
    .btn-download:hover { transform:translateY(-2px); box-shadow:0 5px 12px rgba(106,78,200,.4); }
    .badge { display:inline-block; padding:3px 10px; border-radius:4px; font-size:12px; font-weight:bold; }
    .badge-pending,.badge-expired { background:#fff3cd; color:#856404; }
    .badge-approved { background:#d4edda; color:#155724; }
    .badge-rejected { background:#f8d7da; color:#721c24; }
    .badge-revoked { background:#e2e3e5; color:#383d41; }
    .badge-upload { background:#d4edda; color:#155724; }
    .badge-download { background:#cce5ff; color:#004085; }
    .badge-query { background:#fff3cd; color:#856404; }
    .badge-login { background:#e2e3e5; color:#383d41; }
    .badge-permission { background:#f8d7da; color:#721c24; }
    .success-msg { padding:12px; background:rgba(39,174,96,.15); color:#155724;
        border-radius:10px; margin-top:10px; border:1px solid rgba(39,174,96,.25); }
    .upload-result { margin-top:14px; }
    .empty-state { text-align:center; padding:40px; color:#9b8eb5; }
    .ico-inline { vertical-align:-2px; margin-right:4px; }
    .list-tip { color:#8a7bb8; font-size:13px; }
    .duration-row { display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
    .duration-opt { display:inline-flex; align-items:center; gap:6px; font-weight:500;
        color:#4a4266; cursor:pointer; margin:0 !important; white-space:nowrap; }
    .duration-opt input[type=radio] { accent-color:#6a4ec8; cursor:pointer; margin:0; width:auto; }
    .duration-input,.duration-select { width:110px; height:40px; padding:0 12px;
        border:1px solid rgba(155,109,255,.3); border-radius:10px; font-size:14px;
        background:rgba(255,255,255,.7); color:#333; }
    .duration-select { width:auto; min-width:80px; cursor:pointer; }
    .duration-input:disabled,.duration-select:disabled { opacity:.5; cursor:not-allowed; }
    .req-tip { color:#856404; font-size:13px; }
    .stat-mini { display:inline-flex; align-items:center; gap:8px; padding:10px 22px;
        background:rgba(155,109,255,.12); border:1px solid rgba(155,109,255,.2);
        border-radius:12px; font-size:14px; color:#4a4266; }
    .stat-mini strong { color:#6a4ec8; font-weight:600; }
    .stats-row { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
    .log-filter { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:20px; }
    .log-filter select,.log-filter input { height:44px; padding:0 18px;
        border:1.5px solid rgba(155,109,255,.25); border-radius:22px; font-size:15px;
        background:rgba(255,255,255,.75); color:#333; outline:none; flex:1 1 180px;
        min-width:140px; max-width:260px; box-sizing:border-box; }
    .log-filter select:focus,.log-filter input:focus { border-color:#9b6dff;
        box-shadow:0 0 0 4px rgba(155,109,255,.12); }
    @media screen and (max-width:768px){
        .panel{padding:20px 15px}
        .data-table th,.data-table td{padding:8px 10px;font-size:13px}
        .log-filter select,.log-filter input{width:100%;max-width:100%}
    }
</style>
CSS;
}

function ds_head(string $title): void {
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . e($title) . '</title>';
    echo '<script>(function(){var v="";try{v=localStorage.getItem("aqua-theme")||"";}catch(e){}if(v==="kodachi")document.documentElement.setAttribute("data-theme","kodachi");})();</script>';
    echo '<link rel="stylesheet" href="css/style.css?v=2">';
    echo ds_page_style();
    echo '<script src="js/icons.js?v=2"></script>';
    echo '<script src="js/modal.js"></script>';
    echo '</head><body onload="document.documentElement.style.opacity=1">';
}

/** $active: home|upload|data|permission|request|log */
function ds_header(string $title, string $active, bool $login_required = true): array {
    $user = $login_required ? tds_require_login() : tds_logged_user();
    ds_head($title);
    echo '<header class="header"><div class="logo"><span data-ico="lock"></span> 可信数据空间数据共享平台</div>';
    echo '<div class="user-info">当前用户：<span id="username">';
    if ($user) echo e($user['username'] . ' (' . ($user['role'] ?? 'user') . ')');
    else echo e('未登录');
    echo '</span>';
    echo ' <a href="tools.php" class="home-btn">返回主界面</a>';
    if ($user) {
        echo ' <a href="logout.php" class="home-btn">退出登录</a>';
    }
    echo ' <button class="theme-toggle" id="themeToggle" onclick="toggleSiteTheme()">暗色</button>';
    echo '</div></header>';
    $nav = [
        'home' => ['dataspace.php', '首页'],
        'upload' => ['upload.php', '数据上传'],
        'data' => ['data.php', '数据查询'],
        'permission' => ['permission.php', '权限管理'],
        'request' => ['request.php', '授权申请'],
        'log' => ['log.php', '访问日志'],
    ];
    echo '<nav class="nav">';
    foreach ($nav as $key => $item) {
        $cls = $key === $active ? ' class="active"' : '';
        echo '<a href="' . e($item[0]) . '"' . $cls . '>' . e($item[1]) . '</a>';
    }
    echo '</nav><main class="container">';
    return $user ?: [];
}

function ds_footer(): void {
    echo '</main><footer>© 2026 可信数据空间安全与隐私保护研究 Demo</footer>';
    echo '<script>(function(){var b=document.getElementById("themeToggle");if(b&&document.documentElement.dataset.theme==="kodachi")b.textContent="浅色";})();
function toggleSiteTheme(){var cur=document.documentElement.dataset.theme==="kodachi"?"":"kodachi";document.documentElement.dataset.theme=cur;try{localStorage.setItem("aqua-theme",cur);}catch(e){}var b=document.getElementById("themeToggle");if(b)b.textContent=cur==="kodachi"?"浅色":"暗色";}
</script></body></html>';
}

/* ===================== 数据访问 ===================== */
/** 数据空间全部文件（按上传时间倒序） */
function ds_files(): array {
    $rows = db()->query('SELECT * FROM tds_files ORDER BY created DESC')->fetchAll();
    return $rows ?: [];
}
function ds_file(string $id): ?array {
    $st = db()->prepare('SELECT * FROM tds_files WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}
function ds_user(string $username): ?array {
    $st = db()->prepare('SELECT * FROM tds_users WHERE username = ?');
    $st->execute([$username]);
    $r = $st->fetch();
    return $r ?: null;
}
function ds_all_permissions(): array {
    $rows = db()->query('SELECT * FROM tds_permissions ORDER BY created DESC')->fetchAll();
    return $rows ?: [];
}
function ds_user_permissions(string $user): array {
    $st = db()->prepare('SELECT * FROM tds_permissions WHERE user = ? OR allowed_user = ? ORDER BY created DESC');
    $st->execute([$user, $user]);
    $rows = $st->fetchAll();
    return $rows ?: [];
}
function ds_requests(): array {
    $rows = db()->query('SELECT * FROM tds_requests ORDER BY created DESC')->fetchAll();
    return $rows ?: [];
}
function ds_user_requests(string $user): array {
    $st = db()->prepare('SELECT * FROM tds_requests WHERE requester = ? ORDER BY created DESC');
    $st->execute([$user]);
    $rows = $st->fetchAll();
    return $rows ?: [];
}
function ds_logs(): array {
    $rows = db()->query('SELECT * FROM tds_logs ORDER BY created DESC')->fetchAll();
    return $rows ?: [];
}

/** new Date().toLocaleString() 同款文本 */
function ds_localtime(int $ms): string {
    return date('Y/n/j G:i:s', (int)($ms / 1000));
}
function ds_now_ms(): int {
    return chat_now();
}
function ds_time_text(): string {
    return ds_localtime(chat_now());
}

/** 申请状态徽章 */
function ds_req_status(string $status): string {
    $map = ['pending' => ['badge-pending', '待审批'], 'approved' => ['badge-approved', '已批准'],
        'rejected' => ['badge-rejected', '已拒绝'], 'revoked' => ['badge-revoked', '已撤销'],
        'expired' => ['badge-expired', '已过期']];
    [$cls, $txt] = $map[$status] ?? ['badge-login', $status];
    return '<span class="badge ' . $cls . '">' . e($txt) . '</span>';
}

/** 把已批准的过期申请置为 expired（页面加载时调用） */
function ds_expire_requests(): void {
    $now = chat_now();
    $st = db()->prepare("UPDATE tds_requests SET status = 'expired' WHERE status = 'approved' AND expire_at IS NOT NULL AND expire_at > 0 AND expire_at < ?");
    $st->execute([$now]);
}
