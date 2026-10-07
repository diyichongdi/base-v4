<?php
require_once 'security.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
checkSessionSecurity();

$user = dbGetRow("SELECT * FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
if (!$user) {
    logout();
    header('Location: login.php');
    exit;
}

if (isset($_GET['lang']) && in_array($_GET['lang'], ['zh', 'en'])) {
    setLang($_GET['lang']);
    dbQuery("UPDATE users SET language = :lang WHERE id = :id", [
        ':lang' => $_GET['lang'],
        ':id' => $_SESSION['user_id']
    ]);
    header('Location: dashboard.php');
    exit;
}

$lang = getLang();
$pageTitle = L('dashboard');
$active = 'dashboard';

$stats = [
    'balance' => userBalance($user),
    'products' => intval(dbGetRow("SELECT COUNT(*) as c FROM products WHERE seller_id = :id", [':id' => $_SESSION['user_id']])['c'] ?? 0),
    'orders' => intval(dbGetRow("SELECT COUNT(*) as c FROM orders WHERE buyer_id = :id AND status IN ('paid','shipped')", [':id' => $_SESSION['user_id']])['c'] ?? 0),
    'history' => intval(dbGetRow("SELECT COUNT(*) as c FROM orders WHERE buyer_id = :id", [':id' => $_SESSION['user_id']])['c'] ?? 0)
];

$announcements = dbQuery("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 3");
if (!is_array($announcements)) $announcements = [];

require 'inc/header.php';
?>
<main class="main container">
    <div class="page-head reveal">
        <h1 class="page-title"><?php echo L('welcome_back'); ?>, <?php echo htmlspecialchars((string)($user['username'] ?? '')); ?></h1>
        <p class="text-muted"><?php echo L('dashboard_subtitle'); ?></p>
    </div>

    <div class="stat-grid">
        <div class="lg-card stat-card reveal">
            <div class="stat-icon" style="--c:var(--success);"><i class="fas fa-wallet"></i></div>
            <div class="stat-info">
                <h4><?php echo L('balance'); ?></h4>
                <p>$<span data-count="<?php echo round($stats['balance'], 2); ?>" data-decimals="2">0.00</span></p>
                <a href="wallet.php" class="stat-link"><?php echo L('deposit'); ?> <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
        <div class="lg-card stat-card reveal">
            <div class="stat-icon" style="--c:#58a6ff;"><i class="fas fa-box"></i></div>
            <div class="stat-info">
                <h4><?php echo L('my_products'); ?></h4>
                <p><span data-count="<?php echo $stats['products']; ?>">0</span></p>
                <a href="market/sell.php" class="stat-link"><?php echo L('market'); ?> <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
        <div class="lg-card stat-card reveal">
            <div class="stat-icon" style="--c:#e3b341;"><i class="fas fa-box-open"></i></div>
            <div class="stat-info">
                <h4><?php echo L('pending_orders'); ?></h4>
                <p><span data-count="<?php echo $stats['orders']; ?>">0</span></p>
                <a href="market/orders.php" class="stat-link"><?php echo L('view_orders'); ?> <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
        <div class="lg-card stat-card reveal">
            <div class="stat-icon" style="--c:#f47067;"><i class="fas fa-receipt"></i></div>
            <div class="stat-info">
                <h4><?php echo L('my_orders'); ?></h4>
                <p><span data-count="<?php echo $stats['history']; ?>">0</span></p>
                <a href="market/orders.php" class="stat-link"><?php echo L('view_orders'); ?> <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
    </div>

    <h2 class="section-title reveal"><i class="fas fa-th-large"></i> <?php echo L('quick_links'); ?></h2>
    <div class="module-grid">
        <a class="lg-card module-card reveal" href="market/mall.php">
            <div class="module-icon" style="--c:#3df65a;"><i class="fas fa-store"></i></div>
            <h4><?php echo L('mall'); ?></h4>
            <p><?php echo L('browse_mall'); ?></p>
        </a>
        <a class="lg-card module-card reveal" href="market/mariana.php">
            <div class="module-icon" style="--c:#58a6ff;"><i class="fas fa-globe"></i></div>
            <h4><?php echo L('mariana_web'); ?></h4>
            <p><?php echo L('mariana_desc'); ?></p>
        </a>
        <a class="lg-card module-card reveal" href="market/armory.php">
            <div class="module-icon" style="--c:#e3b341;"><i class="fas fa-shield-halved"></i></div>
            <h4><?php echo L('hacker_armory'); ?></h4>
            <p><?php echo L('armory_desc'); ?></p>
        </a>
        <a class="lg-card module-card reveal" href="im/chat.php">
            <div class="module-icon" style="--c:#c792ea;"><i class="fas fa-comment-dots"></i></div>
            <h4><?php echo L('im'); ?></h4>
            <p><?php echo L('start_chat'); ?></p>
        </a>
        <a class="lg-card module-card reveal" href="forum/index.php">
            <div class="module-icon" style="--c:#f47067;"><i class="fas fa-comments"></i></div>
            <h4><?php echo L('forum'); ?></h4>
            <p><?php echo L('new_post'); ?></p>
        </a>
    </div>

    <div class="dashboard-cols">
        <div class="lg-card card reveal">
            <div class="card-head"><h3><i class="fas fa-bullhorn"></i> <?php echo L('announcements'); ?></h3></div>
            <div class="card-body">
                <?php if (empty($announcements)): ?>
                    <p class="text-muted"><?php echo L('no_announcements'); ?></p>
                <?php else: ?>
                    <?php foreach ($announcements as $ann): ?>
                        <div class="list-item">
                            <div class="list-main">
                                <strong><?php echo htmlspecialchars($ann['title']); ?></strong>
                                <span class="text-muted"><?php echo htmlspecialchars($ann['content']); ?></span>
                            </div>
                            <small class="text-subtle"><?php echo htmlspecialchars($ann['created_at']); ?></small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="lg-card card reveal">
            <div class="card-head"><h3><i class="fas fa-shield-halved"></i> <?php echo L('security_tips'); ?></h3></div>
            <div class="card-body">
                <div class="list-item">
                    <div class="list-main"><strong>HTTPS</strong><span class="text-muted"><?php echo L('tip_https'); ?></span></div>
                </div>
                <div class="list-item">
                    <div class="list-main"><strong>强密码</strong><span class="text-muted"><?php echo L('tip_password'); ?></span></div>
                </div>
                <div class="list-item">
                    <div class="list-main"><strong>VPN</strong><span class="text-muted"><?php echo L('tip_vpn'); ?></span></div>
                </div>
            </div>
        </div>
    </div>
</main>
<?php
require 'inc/footer.php';
?>
