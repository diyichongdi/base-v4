<?php
require_once '../security.php';
require_once 'init.php';

if (!isLoggedIn()) {
    header('Location: ../login.php?next=' . urlencode(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?')));
    exit;
}

$user = dbGetRow("SELECT * FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = L('csrf_invalid');
    } else {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $category = $_POST['category'] ?? '';
        $stock = max(1, intval($_POST['stock'] ?? 1));

        if (empty($name)) {
            $error = L('product_name_empty');
        } elseif (strlen($name) < 3) {
            $error = L('product_name_short');
        } elseif ($price <= 0) {
            $error = L('price_invalid');
        } elseif (empty($category)) {
            $error = L('category_required');
        } else {
            dbQuery("INSERT INTO products (seller_id, name, description, price, category, stock)
                VALUES (:seller_id, :name, :description, :price, :category, :stock)", [
                ':seller_id' => $_SESSION['user_id'],
                ':name' => $name,
                ':description' => $description,
                ':price' => $price,
                ':category' => $category,
                ':stock' => $stock
            ]);
            $success = L('product_added');
        }
    }
}

$myProducts = dbQuery("SELECT * FROM products WHERE seller_id = :user_id ORDER BY created_at DESC",
    [':user_id' => $_SESSION['user_id']]);
if (!is_array($myProducts)) $myProducts = [];

$categories = [
    'data' => L('category_data'),
    'tools' => L('category_tools'),
    'accounts' => L('category_accounts'),
    'services' => L('category_services'),
    'other' => L('category_other')
];

$lang = getLang();
$pageTitle = L('sell');
$active = 'market';
$BASE = '../';
require '../inc/header.php';
?>
<main class="main container">
    <div class="page-head reveal">
        <h1 class="page-title"><i class="fas fa-tags" style="margin-right:10px;color:var(--primary);"></i><?php echo L('sell'); ?></h1>
    </div>

    <div class="sell-cols">
        <div class="lg-card card reveal">
            <div class="card-head"><h3><i class="fas fa-plus-circle"></i> <?php echo L('add_product'); ?></h3></div>

            <?php if ($error): ?>
                <div class="alert alert-error" style="margin-bottom:18px;"><i class="fas fa-circle-exclamation" style="margin-top:2px;"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success" style="margin-bottom:18px;"><i class="fas fa-check" style="margin-top:2px;"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

                <div class="form-group">
                    <label class="form-label"><?php echo L('product_name'); ?></label>
                    <input type="text" class="form-control" name="name" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo L('description'); ?></label>
                    <textarea class="form-control" name="description" rows="3" style="min-height:80px;"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo L('category'); ?></label>
                    <select class="form-control" name="category" required>
                        <option value=""><?php echo L('select_category'); ?></option>
                        <?php foreach ($categories as $key => $name): ?>
                            <option value="<?php echo $key; ?>"><?php echo $name; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?php echo L('price'); ?> ($)</label>
                        <input type="number" class="form-control" name="price" step="0.01" min="0.01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo L('stock'); ?></label>
                        <input type="number" class="form-control" name="stock" value="1" min="1" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-box-open"></i> <?php echo L('publish_product'); ?></button>
            </form>
        </div>

        <div class="lg-card card reveal">
            <div class="card-head"><h3><i class="fas fa-box"></i> <?php echo L('my_products'); ?></h3></div>
            <?php if (empty($myProducts)): ?>
                <div class="empty-state"><i class="fas fa-box-open"></i><p><?php echo L('no_products_yet'); ?></p></div>
            <?php else: ?>
                <?php foreach ($myProducts as $product): ?>
                    <div class="list-row" style="margin-bottom:10px;cursor:default;">
                        <div class="list-main">
                            <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                            <span class="text-muted"><?php echo $categories[$product['category']] ?? $product['category']; ?> &nbsp;·&nbsp; <?php echo L('stock'); ?>: <?php echo $product['stock']; ?></span>
                        </div>
                        <div style="text-align:right;">
                            <div class="product-price">$<?php echo number_format($product['price'], 2); ?></div>
                            <span class="badge badge-<?php echo $product['status'] === 'active' ? 'success' : 'warning'; ?>"><?php echo $product['status'] === 'active' ? L('active') : L('inactive'); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php
require '../inc/footer.php';
?>
