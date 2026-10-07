<?php
require_once '../security.php';
require_once 'init.php';

if (!isLoggedIn()) {
    header('Location: ../login.php?next=' . urlencode(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?')));
    exit;
}

$user = dbGetRow("SELECT * FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
$lang = getLang();
$pageTitle = L('mariana_web');
$active = 'market';
$BASE = '../';
require '../inc/header.php';
?>
<main class="main container">
    <section class="hero-section reveal">
        <div class="hero-ico"><i class="fas fa-water"></i></div>
        <h1><?php echo L('mariana_web'); ?></h1>
        <p><?php echo L('mariana_desc'); ?></p>
        <a href="mall.php" class="btn btn-primary btn-lg"><i class="fas fa-store"></i> <?php echo L('enter_market'); ?></a>
    </section>

    <div class="alert alert-warning reveal"><i class="fas fa-triangle-exclamation" style="margin-top:2px;"></i> <?php echo L('mariana_warning'); ?></div>

    <div class="grid-3" style="margin-top:24px;">
        <div class="feature-card reveal">
            <div class="feature-ico" style="--c:var(--success);"><i class="fas fa-user-shield"></i></div>
            <h4><?php echo L('feature_anonymous'); ?></h4>
            <p><?php echo L('feature_anonymous_desc'); ?></p>
        </div>
        <div class="feature-card reveal">
            <div class="feature-ico" style="--c:#58a6ff;"><i class="fas fa-shield-halved"></i></div>
            <h4><?php echo L('feature_secure'); ?></h4>
            <p><?php echo L('feature_secure_desc'); ?></p>
        </div>
        <div class="feature-card reveal">
            <div class="feature-ico" style="--c:#e3b341;"><i class="fas fa-bolt"></i></div>
            <h4><?php echo L('feature_fast'); ?></h4>
            <p><?php echo L('feature_fast_desc'); ?></p>
        </div>
    </div>
</main>
<?php
require '../inc/footer.php';
?>
