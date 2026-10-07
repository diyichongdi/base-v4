<?php
require_once '../security.php';
require_once 'init.php';

if (!isLoggedIn()) {
    header('Location: ../login.php?next=' . urlencode(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?')));
    exit;
}

$user = dbGetRow("SELECT * FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
$lang = getLang();

$tools = [
    'security' => [
        'title' => L('security_tools'),
        'icon' => 'fas fa-shield-halved',
        'items' => [
            ['tool_scanner', 'tool_scanner_desc', 'fas fa-magnifying-glass', 99, '#3df65a'],
            ['tool_proxy', 'tool_proxy_desc', 'fas fa-globe', 49, '#58a6ff'],
            ['tool_vpn', 'tool_vpn_desc', 'fas fa-lock', 29, '#e3b341'],
            ['tool_rdp', 'tool_rdp_desc', 'fas fa-desktop', 79, '#c792ea']
        ]
    ],
    'exploit' => [
        'title' => L('exploit_tools'),
        'icon' => 'fas fa-microchip',
        'items' => [
            ['tool_sqli', 'tool_sqli_desc', 'fas fa-syringe', 149, '#f47067'],
            ['tool_xss', 'tool_xss_desc', 'fas fa-bullseye', 89, '#ff9d5c'],
            ['tool_brute', 'tool_brute_desc', 'fas fa-unlock', 69, '#58a6ff'],
            ['tool_malware', 'tool_malware_desc', 'fas fa-bug', 199, '#c792ea']
        ]
    ]
];

$pageTitle = L('hacker_armory');
$active = 'market';
$BASE = '../';
require '../inc/header.php';
?>
<main class="main container">
    <section class="hero-section reveal" style="--c:#f47067;">
        <div class="hero-ico"><i class="fas fa-gun"></i></div>
        <h1><?php echo L('hacker_armory'); ?></h1>
        <p><?php echo L('armory_desc'); ?></p>
        <a href="mall.php" class="btn btn-primary btn-lg"><i class="fas fa-toolbox"></i> <?php echo L('browse_tools'); ?></a>
    </section>

    <div class="alert alert-warning reveal"><i class="fas fa-triangle-exclamation" style="margin-top:2px;"></i> <?php echo L('mariana_warning'); ?></div>

    <?php foreach ($tools as $group): ?>
        <h2 class="section-title reveal" style="margin-top:34px;"><i class="<?php echo $group['icon']; ?>"></i> <?php echo $group['title']; ?></h2>
        <div class="grid-4">
            <?php foreach ($group['items'] as $tool): ?>
                <div class="tool-card reveal">
                    <div class="tool-ico" style="--c:<?php echo $tool[4]; ?>;"><i class="<?php echo $tool[2]; ?>"></i></div>
                    <h4><?php echo L($tool[0]); ?></h4>
                    <p><?php echo L($tool[1]); ?></p>
                    <div class="tool-price">$<?php echo $tool[3]; ?>/<?php echo L('month'); ?></div>
                    <a href="deposit.php" class="btn btn-ghost btn-block" style="margin-top:14px;"><i class="fas fa-bolt"></i> <?php echo L('rent_now'); ?></a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</main>
<?php
require '../inc/footer.php';
?>
