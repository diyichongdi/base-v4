<?php
require_once '../security.php';
require_once 'init.php';

if (!isLoggedIn()) {
    header('Location: ../login.php?next=' . urlencode(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?')));
    exit;
}

$user = dbGetRow("SELECT * FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);

$category = $_GET['category'] ?? 'all';
$search = sanitizeInput($_GET['search'] ?? '');

$sql = "SELECT p.*, u.username as seller_name FROM products p
    JOIN users u ON p.seller_id = u.id
    WHERE p.status = 'active' AND p.stock > 0 AND p.seller_id != :user_id";
$params = [':user_id' => $_SESSION['user_id']];

if ($category !== 'all') {
    $sql .= " AND p.category = :category";
    $params[':category'] = $category;
}

if ($search) {
    $sql .= " AND (p.name LIKE :search OR p.description LIKE :search)";
    $params[':search'] = "%$search%";
}

$sql .= " ORDER BY p.created_at DESC";
$products = dbQuery($sql, $params);
if (!is_array($products)) $products = [];

$categories = [
    'all' => L('all_categories'),
    'data' => L('category_data'),
    'tools' => L('category_tools'),
    'accounts' => L('category_accounts'),
    'services' => L('category_services'),
    'other' => L('category_other')
];

$lang = getLang();
$pageTitle = L('mall');
$active = 'market';
$BASE = '../';
require '../inc/header.php';
?>
<main class="main container">
    <div class="page-head reveal">
        <div>
            <h1 class="page-title"><i class="fas fa-store" style="margin-right:10px;color:var(--primary);"></i><?php echo L('mall'); ?></h1>
            <p class="text-muted"><?php echo L('mall_subtitle'); ?></p>
        </div>
        <a href="deposit.php" class="btn btn-primary"><i class="fas fa-plus"></i> <?php echo L('deposit'); ?></a>
    </div>

    <?php
    $anns = dbQuery("SELECT * FROM announcements WHERE is_active = 1 ORDER BY id DESC LIMIT 5");
    if (is_array($anns) && !empty($anns)):
    ?>
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

    <form class="flex gap-3 reveal" style="flex-wrap:wrap;margin-bottom:20px;" method="GET" action="">
        <input type="text" name="search" class="form-control" style="flex:1;min-width:220px;" placeholder="<?php echo L('search_products'); ?>" value="<?php echo htmlspecialchars($search); ?>">
        <select name="category" class="form-control" style="width:auto;min-width:150px;">
            <?php foreach ($categories as $key => $name): ?>
                <option value="<?php echo $key; ?>" <?php echo $category === $key ? 'selected' : ''; ?>><?php echo $name; ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary"><i class="fas fa-magnifying-glass"></i> <?php echo L('search'); ?></button>
    </form>

    <div class="filter-chips reveal">
        <?php foreach ($categories as $key => $name): ?>
            <a href="?category=<?php echo $key; ?>" class="chip <?php echo $category === $key ? 'active' : ''; ?>"><?php echo $name; ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($products)): ?>
        <div class="lg-card card reveal">
            <div class="empty-state"><i class="fas fa-inbox"></i><p><?php echo L('no_products'); ?></p></div>
        </div>
    <?php else: ?>
        <div class="grid-auto" style="margin-top:24px;">
            <?php foreach ($products as $product): ?>
                <div class="lg-card product-card reveal">
                    <div class="product-thumb"><i class="fas fa-box"></i></div>
                    <div class="product-body">
                        <h4><?php echo htmlspecialchars($product['name']); ?></h4>
                        <p class="product-desc"><?php echo htmlspecialchars($product['description']); ?></p>
                        <div class="product-meta">
                            <span class="product-price">$<?php echo number_format($product['price'], 2); ?></span>
                            <span class="text-muted text-small"><?php echo L('stock'); ?>: <?php echo $product['stock']; ?></span>
                        </div>
                        <small class="text-subtle"><i class="fas fa-user"></i> <?php echo htmlspecialchars($product['seller_name']); ?></small>
                        <a href="buy.php?id=<?php echo $product['id']; ?>" class="btn btn-primary btn-block" style="margin-top:14px;"><i class="fas fa-bag-shopping"></i> <?php echo L('buy_now'); ?></a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<?php
require '../inc/footer.php';
?>
