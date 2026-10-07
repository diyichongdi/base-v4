<?php
/**
 * 公共头部模板
 * 页面需先 require security.php，并可选设置：
 *   $BASE      - 相对根目录的路径前缀（默认 ''）
 *   $pageTitle - 页面标题（默认「基地」）
 *   $hideNav   - true 时隐藏顶部导航（登录/注册页用）
 *   $active    - 当前高亮菜单项（dashboard/service/market/im/forum）
 */
if (!isset($BASE)) $BASE = '';
if (!isset($pageTitle)) $pageTitle = '基地';
if (!isset($hideNav)) $hideNav = false;
if (!isset($active)) $active = '';
if (!isset($extraCss)) $extraCss = array();
if (!isset($extraHeadJS)) $extraHeadJS = '';
$lang = getLang();
$logged = isLoggedIn();
$uid = intval($_SESSION['user_id'] ?? 0);
// 自视昵称：优先助记用户名（仅自己可见），无则显示数字ID
$unameRaw = trim((string)($_SESSION['nick'] ?? ''));
if ($logged && $unameRaw === '') {
    $uRow = dbGetRow("SELECT username, nick FROM users WHERE id = :id", [':id' => $uid]);
    if ($uRow) {
        $unameRaw = trim((string)($uRow['nick'] ?? ''));
        if ($unameRaw === '') $unameRaw = (string)$uRow['username'];
    }
}
if (!$logged) $unameRaw = '';
$uname = htmlspecialchars($unameRaw);
$uLetter = $unameRaw !== '' ? strtoupper(mb_substr($unameRaw, 0, 1)) : 'U';
// 登录入口携带回跳目标（登录成功后回到当前页）
$navReturnPath = (string)($_SERVER['REQUEST_URI'] ?? '');
$navLoginHref = $BASE . 'login.php' . ($navReturnPath !== '' ? '?next=' . urlencode($navReturnPath) : '');
$navRegisterHref = $navLoginHref . (strpos($navLoginHref, '?') === false ? '?' : '&') . 'panel=register';
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" data-theme="aqua">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="<?php echo $BASE; ?>im/favicon.svg">
<title><?php echo htmlspecialchars($pageTitle); ?> · 基地</title>
<script>
    (function () {
        var fx = 'frost', dark = false;
        try {
            fx = localStorage.getItem('bm-cardfx') || 'frost';
            dark = localStorage.getItem('bm-fxdark') === '1';
        } catch (e) {}
        if (['frost', 'aurora', 'particles', 'blueprint'].indexOf(fx) === -1) fx = 'frost';
        document.documentElement.setAttribute('data-fx-active', fx);
        if (dark) document.documentElement.classList.add('fx-dark');
        /* 卡片风格 → 基础主题映射（chat.css 等按 [data-theme] 取色） */
        var tmap = { frost: 'aqua', aurora: 'aurora', particles: 'midnight', blueprint: 'kodachi' };
        document.documentElement.dataset.theme = tmap[fx] || 'aqua';
    })();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo $BASE; ?>assets/app.css">
<?php foreach ($extraCss as $cssFile): ?>
<link rel="stylesheet" href="<?php echo $BASE; ?><?php echo htmlspecialchars($cssFile); ?>">
<?php endforeach; ?>
<?php if ($extraHeadJS !== ''): ?>
<script>
<?php echo $extraHeadJS; ?>
</script>
<?php endif; ?>
</head>
<body>

<div class="orb-bg" aria-hidden="true">
    <span class="orb orb-1"></span>
    <span class="orb orb-2"></span>
    <span class="orb orb-3"></span>
    <span class="orb orb-4"></span>
</div>

<?php if (!$hideNav): ?>
<nav class="navbar">
    <div class="nav-inner">
        <a class="nav-brand" href="<?php echo $logged ? $BASE . 'dashboard.php' : $BASE . 'index.php'; ?>">
            <span class="nav-logo">基</span>
            <span>基地</span>
        </a>

        <ul class="nav-menu">
            <li class="nav-item">
                <a class="nav-link <?php echo $active === 'market' ? 'active' : ''; ?>" href="<?php echo $BASE; ?>market/mall.php"><i class="fas fa-store"></i> <?php echo L('nav_market'); ?></a>
                <button type="button" class="nav-caret" aria-label="<?php echo L('nav_market'); ?>" tabindex="0"><i class="fas fa-chevron-down"></i></button>
                <div class="dropdown">
                    <a class="dropdown-item" href="<?php echo $BASE; ?>market/sell.php"><i class="fas fa-tags"></i> <?php echo L('nav_sell'); ?></a>
                    <a class="dropdown-item" href="<?php echo $BASE; ?>market/deposit.php"><i class="fas fa-circle-plus"></i> <?php echo L('nav_deposit'); ?></a>
                    <a class="dropdown-item" href="<?php echo $BASE; ?>market/withdraw.php"><i class="fas fa-arrow-right-from-bracket"></i> <?php echo L('nav_withdraw'); ?></a>
                    <a class="dropdown-item" href="<?php echo $BASE; ?>market/disputes.php"><i class="fas fa-gavel"></i> <?php echo L('nav_arbitration'); ?></a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $BASE; ?>market/mariana.php"><i class="fas fa-globe"></i> <?php echo L('nav_mariana'); ?></a>
                    <a class="dropdown-item" href="<?php echo $BASE; ?>market/armory.php"><i class="fas fa-gun"></i> <?php echo L('nav_armory'); ?></a>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $active === 'im' ? 'active' : ''; ?>" href="<?php echo $BASE; ?>im/chat.php"><i class="fas fa-comment-dots"></i> <?php echo L('nav_im'); ?></a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $active === 'forum' ? 'active' : ''; ?>" href="<?php echo $BASE; ?>forum/index.php"><i class="fas fa-comments"></i> <?php echo L('nav_forum'); ?></a>
                <button type="button" class="nav-caret" aria-label="<?php echo L('nav_forum'); ?>" tabindex="0"><i class="fas fa-chevron-down"></i></button>
                <div class="dropdown">
                    <a class="dropdown-item" href="<?php echo $BASE; ?>forum/index.php"><i class="fas fa-list"></i> <?php echo L('nav_forum_home'); ?></a>
                    <a class="dropdown-item" href="<?php echo $BASE; ?>forum/new-post.php"><i class="fas fa-pen-to-square"></i> <?php echo L('nav_new_post'); ?></a>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $active === 'darkweb' ? 'active' : ''; ?>" href="<?php echo $BASE; ?>dark_web_nav.php"><i class="fas fa-globe-africa"></i> <?php echo L('nav_darkweb'); ?></a>
            </li>
        </ul>

        <div class="nav-actions">
            <div class="flex gap-2">
                <a href="?lang=zh" class="btn btn-sm btn-ghost lang-switch <?php echo $lang === 'zh' ? 'active' : ''; ?>" style="<?php echo $lang === 'zh' ? 'color:var(--primary);' : ''; ?>">中</a>
                <a href="?lang=en" class="btn btn-sm btn-ghost lang-switch <?php echo $lang === 'en' ? 'active' : ''; ?>" style="<?php echo $lang === 'en' ? 'color:var(--primary);' : ''; ?>">EN</a>
            </div>
            <div class="theme-menu-wrap">
                <button class="icon-btn" data-theme-btn title="<?php echo L('nav_theme'); ?>"><i class="fas fa-drafting-compass"></i></button>
                <div class="dropdown">
                    <div class="dropdown-title"><i class="fas fa-swatchbook"></i> <?php echo L('nav_theme'); ?></div>
                    <a class="dropdown-item fx-opt" data-fx-opt="frost"><i class="fas fa-layer-group"></i> <?php echo L('nav_style_frost'); ?> <i class="fas fa-check theme-check"></i></a>
                    <a class="dropdown-item fx-opt" data-fx-opt="aurora"><i class="fas fa-wand-magic-sparkles"></i> <?php echo L('nav_style_aurora'); ?> <i class="fas fa-check theme-check"></i></a>
                    <a class="dropdown-item fx-opt" data-fx-opt="particles"><i class="fas fa-satellite"></i> <?php echo L('nav_style_particles'); ?> <i class="fas fa-check theme-check"></i></a>
                    <a class="dropdown-item fx-opt" data-fx-opt="blueprint"><i class="fas fa-drafting-compass"></i> <?php echo L('nav_style_blueprint'); ?> <i class="fas fa-check theme-check"></i></a>
                    <div class="dropdown-divider"></div>
                    <div class="fx-dark-row" data-dark-switch>
                        <span><i class="fas fa-moon"></i> <?php echo L('nav_dark_mode'); ?></span>
                        <span class="switch" data-dark-switch></span>
                    </div>
                </div>
            </div>

            <?php if ($logged): ?>
            <div class="user-menu-wrap">
                <div class="user-chip">
                    <span class="avatar"><?php echo $uLetter; ?></span>
                    <span>
                        <span class="u-name"><?php echo $uname; ?></span>
                        <span class="u-meta">#<?php echo $uid; ?></span>
                    </span>
                    <i class="fas fa-chevron-down" style="font-size:10px;color:var(--text-subtle);"></i>
                </div>
                <div class="dropdown">
                    <a class="dropdown-item" href="<?php echo $BASE; ?>dashboard.php"><i class="fas fa-gauge-high"></i> <?php echo L('nav_console'); ?></a>
                    <a class="dropdown-item" href="<?php echo $BASE; ?>wallet.php"><i class="fas fa-wallet"></i> <?php echo L('nav_wallet'); ?></a>
                    <a class="dropdown-item" href="<?php echo $BASE; ?>market/orders.php"><i class="fas fa-receipt"></i> <?php echo L('nav_orders'); ?></a>
                    <?php if (isAdmin()): ?>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $BASE; ?>admin/index.php" style="color:var(--primary);"><i class="fas fa-user-shield"></i> <?php echo L('nav_admin'); ?></a>
                    <?php endif; ?>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $BASE; ?>logout.php" style="color:var(--error);"><i class="fas fa-right-from-bracket"></i> <?php echo L('nav_logout'); ?></a>
                </div>
            </div>
            <?php else: ?>
            <a class="btn btn-sm btn-ghost" href="<?php echo $navLoginHref; ?>"><?php echo L('login'); ?></a>
            <a class="btn btn-sm btn-primary" href="<?php echo $navRegisterHref; ?>"><?php echo L('register'); ?></a>
            <?php endif; ?>
            <button class="nav-toggle" data-nav-toggle aria-label="<?php echo L('nav_menu'); ?>" aria-expanded="false" aria-controls="navDrawer">
                <i class="fas fa-bars nav-ico-open"></i>
                <i class="fas fa-xmark nav-ico-close"></i>
            </button>
        </div>
    </div>

    <div class="nav-drawer" id="navDrawer">
        <div class="nav-drawer-inner">
            <a class="nav-drawer-link <?php echo $active === 'market' ? 'active' : ''; ?>" href="<?php echo $BASE; ?>market/mall.php"><i class="fas fa-store"></i> <?php echo L('nav_market'); ?></a>
            <a class="nav-drawer-sub" href="<?php echo $BASE; ?>market/sell.php"><i class="fas fa-tags"></i> <?php echo L('nav_sell'); ?></a>
            <a class="nav-drawer-sub" href="<?php echo $BASE; ?>market/orders.php"><i class="fas fa-receipt"></i> <?php echo L('nav_orders'); ?></a>
            <a class="nav-drawer-sub" href="<?php echo $BASE; ?>market/deposit.php"><i class="fas fa-circle-plus"></i> <?php echo L('nav_deposit'); ?></a>
            <a class="nav-drawer-sub" href="<?php echo $BASE; ?>market/withdraw.php"><i class="fas fa-arrow-right-from-bracket"></i> <?php echo L('nav_withdraw'); ?></a>
            <a class="nav-drawer-sub" href="<?php echo $BASE; ?>market/disputes.php"><i class="fas fa-gavel"></i> <?php echo L('nav_arbitration'); ?></a>
            <div class="nav-drawer-divider"></div>
            <a class="nav-drawer-sub" href="<?php echo $BASE; ?>market/mariana.php"><i class="fas fa-globe"></i> <?php echo L('nav_mariana'); ?></a>
            <a class="nav-drawer-sub" href="<?php echo $BASE; ?>market/armory.php"><i class="fas fa-gun"></i> <?php echo L('nav_armory'); ?></a>
            <a class="nav-drawer-link <?php echo $active === 'im' ? 'active' : ''; ?>" href="<?php echo $BASE; ?>im/chat.php"><i class="fas fa-comment-dots"></i> <?php echo L('nav_im'); ?></a>
            <a class="nav-drawer-link <?php echo $active === 'forum' ? 'active' : ''; ?>" href="<?php echo $BASE; ?>forum/index.php"><i class="fas fa-comments"></i> <?php echo L('nav_forum'); ?></a>
            <a class="nav-drawer-sub" href="<?php echo $BASE; ?>forum/index.php"><i class="fas fa-list"></i> <?php echo L('nav_forum_home'); ?></a>
            <a class="nav-drawer-sub" href="<?php echo $BASE; ?>forum/new-post.php"><i class="fas fa-pen-to-square"></i> <?php echo L('nav_new_post'); ?></a>
            <a class="nav-drawer-link <?php echo $active === 'darkweb' ? 'active' : ''; ?>" href="<?php echo $BASE; ?>dark_web_nav.php"><i class="fas fa-globe-africa"></i> <?php echo L('nav_darkweb'); ?></a>
            <div class="nav-drawer-divider"></div>
            <?php if ($logged): ?>
            <a class="nav-drawer-link <?php echo $active === 'dashboard' ? 'active' : ''; ?>" href="<?php echo $BASE; ?>dashboard.php"><i class="fas fa-gauge-high"></i> <?php echo L('nav_console'); ?></a>
            <a class="nav-drawer-sub" href="<?php echo $BASE; ?>wallet.php"><i class="fas fa-wallet"></i> <?php echo L('nav_wallet'); ?></a>
            <?php if (isAdmin()): ?>
            <div class="nav-drawer-divider"></div>
            <a class="nav-drawer-link" href="<?php echo $BASE; ?>admin/index.php"><i class="fas fa-user-shield"></i> <?php echo L('nav_admin'); ?></a>
            <?php endif; ?>
            <div class="nav-drawer-divider"></div>
            <a class="nav-drawer-link" href="<?php echo $BASE; ?>logout.php" style="color:var(--error);"><i class="fas fa-right-from-bracket"></i> <?php echo L('nav_logout'); ?></a>
            <?php else: ?>
            <a class="nav-drawer-link" href="<?php echo $navLoginHref; ?>"><i class="fas fa-right-to-bracket"></i> <?php echo L('login'); ?></a>
            <a class="nav-drawer-link" href="<?php echo $navRegisterHref; ?>" style="color:var(--primary);"><i class="fas fa-user-plus"></i> <?php echo L('register'); ?></a>
            <?php endif; ?>
        </div>
    </div>
</nav>
<?php endif; ?>
