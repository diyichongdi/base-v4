<?php
/**
 * 公开首页：进入网站先看首页，点击商品/帖子等详情时由各页面守卫转入登录
 */
require_once 'security.php';

$lang = getLang();
$logged = isLoggedIn();

// 最新商品（公开浏览；购买页有登录守卫）
$products = [];
try {
    $rows = dbQuery("SELECT p.id, p.name, p.description, p.price, p.category, u.username as seller_name
        FROM products p JOIN users u ON p.seller_id = u.id
        WHERE p.status = 'active' AND p.stock > 0
        ORDER BY p.created_at DESC LIMIT 8");
    if (is_array($rows)) $products = $rows;
} catch (Exception $e) {}

// 论坛最新帖（只读 forum.db；帖子详情有登录守卫）
$forumPosts = [];
$fdbPath = __DIR__ . '/forum/forum.db';
if (file_exists($fdbPath)) {
    try {
        $fdb = new SQLite3($fdbPath);
        $fdb->busyTimeout(3000);
        if (defined('DB_FILE') && is_file(DB_FILE)) {
            $fdb->exec("ATTACH DATABASE '" . str_replace("'", "''", DB_FILE) . "' AS maindb");
        }
        $r = @$fdb->query("SELECT p.id, p.title, p.views, p.reply_count, p.created_at, u.username
            FROM forum_posts p JOIN maindb.users u ON u.id = p.user_id
            ORDER BY p.id DESC LIMIT 6");
        if ($r) {
            while ($row = $r->fetchArray(SQLITE3_ASSOC)) $forumPosts[] = $row;
        }
        $fdb->close();
    } catch (Exception $e) {}
}

$categoryIcons = [
    'data' => 'fas fa-database',
    'tools' => 'fas fa-screwdriver-wrench',
    'accounts' => 'fas fa-user-circle',
    'services' => 'fas fa-handshake',
    'other' => 'fas fa-box',
];

$pageTitle = L('home') !== 'home' ? L('home') : '首页';
$active = '';
$BASE = '';

// 公告栏（与论坛/商城共用主站公告）
$anns = [];
try {
    $rows = dbQuery("SELECT * FROM announcements WHERE is_active = 1 ORDER BY id DESC LIMIT 3");
    if (is_array($rows)) $anns = $rows;
} catch (Exception $e) {}

require 'inc/header.php';
?>
<main class="main container">

    <?php /* Hero */ ?>
    <section class="lg-card reveal" style="padding:56px 40px;text-align:center;position:relative;overflow:hidden;">
        <div style="position:relative;z-index:1;">
            <div style="width:76px;height:76px;border-radius:22px;margin:0 auto 20px;display:grid;place-items:center;font-size:34px;font-weight:800;color:#fff;background:var(--primary-gradient,linear-gradient(135deg,var(--primary),var(--primary-2,#0891b2)));box-shadow:0 12px 30px -10px var(--primary);">基</div>
            <h1 style="font-size:clamp(28px,5vw,44px);font-weight:800;letter-spacing:-.02em;margin:0 0 14px;">
                <?php echo $lang === 'zh' ? '基地 · 数字资产与安全社区' : 'The Base · Digital Assets & Secure Community'; ?>
            </h1>
            <p class="text-muted" style="max-width:560px;margin:0 auto 28px;font-size:16px;">
                <?php echo $lang === 'zh'
                    ? '匿名交易市场 · 安全论坛 · 即时通讯。全站以系统分配的纯数字ID示人，不暴露任何真实身份。'
                    : 'Anonymous marketplace · Secure forum · Instant messaging. Everyone is shown as a system-assigned numeric ID — no real identities exposed.'; ?>
            </p>
            <div class="flex gap-3" style="justify-content:center;flex-wrap:wrap;">
                <?php if ($logged): ?>
                    <a href="dashboard.php" class="btn btn-primary btn-lg"><i class="fas fa-gauge-high"></i> <?php echo $lang === 'zh' ? '进入控制台' : 'Go to Dashboard'; ?></a>
                    <a href="market/mall.php" class="btn btn-ghost btn-lg"><i class="fas fa-store"></i> <?php echo L('mall'); ?></a>
                <?php else: ?>
                    <a href="login.php?panel=register" class="btn btn-primary btn-lg"><i class="fas fa-user-plus"></i> <?php echo L('register'); ?></a>
                    <a href="login.php" class="btn btn-ghost btn-lg"><i class="fas fa-right-to-bracket"></i> <?php echo L('login'); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php /* 公告栏（与论坛/商城统一来源，避免重复） */ ?>
    <?php if (!empty($anns)): ?>
        <div class="announce-bar reveal">
            <div class="announce-head"><i class="fas fa-bullhorn"></i> <?php echo L('announcements'); ?></div>
            <?php foreach ($anns as $a): ?>
                <div class="announce-item">
                    <strong><?php echo htmlspecialchars($a['title']); ?></strong>
                    <span><?php echo htmlspecialchars($a['content']); ?></span>
                    <small><?php echo htmlspecialchars($a['created_at']); ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php /* 最新商品 */ ?>
    <div class="page-head reveal">
        <div>
            <h2 class="page-title" style="font-size:24px;"><i class="fas fa-store" style="margin-right:10px;color:var(--primary);"></i><?php echo $lang === 'zh' ? '最新上架' : 'Latest Listings'; ?></h2>
            <p class="text-muted"><?php echo $lang === 'zh' ? '点击商品查看详情并购买（需登录）' : 'Click a product to view details & buy (login required)'; ?></p>
        </div>
        <a href="market/mall.php" class="btn btn-ghost"><?php echo $lang === 'zh' ? '进入商城' : 'Browse Mall'; ?> <i class="fas fa-arrow-right"></i></a>
    </div>

    <?php if (empty($products)): ?>
        <div class="lg-card card reveal">
            <div class="empty-state"><i class="fas fa-inbox"></i><p><?php echo L('no_products') ?: ($lang === 'zh' ? '暂无商品' : 'No products yet'); ?></p></div>
        </div>
    <?php else: ?>
        <div class="grid-auto" style="margin-top:18px;">
            <?php foreach ($products as $i => $p): ?>
                <div class="lg-card product-card reveal">
                    <div class="product-thumb"><i class="<?php echo $categoryIcons[$p['category']] ?? 'fas fa-box'; ?>"></i></div>
                    <div class="product-body">
                        <h4><?php echo htmlspecialchars($p['name']); ?></h4>
                        <p class="product-desc"><?php echo htmlspecialchars(mb_substr((string)$p['description'], 0, 60)); ?></p>
                        <div class="product-meta">
                            <span class="product-price">$<?php echo number_format((float)$p['price'], 2); ?></span>
                            <span class="text-muted text-small"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($p['seller_name']); ?></span>
                        </div>
                        <a href="market/buy.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-primary btn-block" style="margin-top:14px;"><i class="fas fa-bag-shopping"></i> <?php echo L('buy_now') ?: ($lang === 'zh' ? '立即购买' : 'Buy Now'); ?></a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php /* 论坛动态 */ ?>
    <div class="page-head reveal">
        <div>
            <h2 class="page-title" style="font-size:24px;"><i class="fas fa-comments" style="margin-right:10px;color:var(--primary);"></i><?php echo $lang === 'zh' ? '论坛动态' : 'Forum Activity'; ?></h2>
            <p class="text-muted"><?php echo $lang === 'zh' ? '最新帖子预览，查看详情需登录' : 'Latest topics preview — login required to read'; ?></p>
        </div>
        <a href="forum/index.php" class="btn btn-ghost"><?php echo L('forum'); ?> <i class="fas fa-arrow-right"></i></a>
    </div>

    <div class="lg-card card reveal">
        <div class="card-body">
            <?php if (empty($forumPosts)): ?>
                <div class="empty-state"><i class="fas fa-comments"></i><p><?php echo $lang === 'zh' ? '暂无帖子' : 'No posts yet'; ?></p></div>
            <?php else: ?>
                <?php foreach ($forumPosts as $fp): ?>
                    <a href="forum/post.php?id=<?php echo (int)$fp['id']; ?>" class="list-row">
                        <span class="avatar sm"><?php echo htmlspecialchars(mb_substr((string)$fp['username'], 0, 1)); ?></span>
                        <div class="list-main">
                            <strong><?php echo htmlspecialchars($fp['title']); ?></strong>
                            <span class="text-muted"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($fp['username']); ?>
                                &nbsp;·&nbsp; <i class="fas fa-eye"></i> <?php echo (int)$fp['views']; ?>
                                &nbsp;·&nbsp; <i class="fas fa-comment"></i> <?php echo (int)$fp['reply_count']; ?></span>
                        </div>
                        <i class="fas fa-chevron-right" style="color:var(--text-subtle);"></i>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php
require 'inc/footer.php';
