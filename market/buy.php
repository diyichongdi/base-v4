<?php
require_once '../security.php';
require_once 'init.php';

if (!isLoggedIn()) {
    header('Location: ../login.php?next=' . urlencode(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?')));
    exit;
}

$productId = intval($_GET['id'] ?? 0);
if (!$productId) {
    header('Location: mall.php');
    exit;
}

$product = dbGetRow("SELECT p.*, u.username as seller_name FROM products p
    JOIN users u ON p.seller_id = u.id
    WHERE p.id = :id AND p.status = 'active'", [':id' => $productId]);

if (!$product) {
    header('Location: mall.php');
    exit;
}

$buyer = dbGetRow("SELECT * FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = L('csrf_invalid');
    } else {
        $quantity = max(1, intval($_POST['quantity'] ?? 1));
        $totalPrice = $product['price'] * $quantity;

        if ($quantity > $product['stock']) {
            $error = L('insufficient_stock');
        } elseif ($product['seller_id'] == $buyer['id']) {
            $error = '不能购买自己的商品';
        } elseif (userBalance($buyer) < $totalPrice) {
            $error = L('insufficient_balance');
        } else {
            $db = getDB();
            $db->exec('BEGIN IMMEDIATE');
            try {
                // 托管模式：先冻结买家余额，资金暂不给卖家
                // 用 WHERE balance >= :amount 防止并发导致余额负数
                $stmt = $db->prepare("UPDATE users SET balance = balance - :amount WHERE id = :id AND balance >= :amount");
                $stmt->bindValue(':amount', $totalPrice);
                $stmt->bindValue(':id', $buyer['id']);
                $stmt->execute();
                // getDB() 返回 SQLite3，其 SQLite3Stmt 无 rowCount()；受影响行数用连接的 changes()
                if ($db->changes() === 0) {
                    throw new Exception('余额不足');
                }

                // 用 WHERE stock >= :quantity 防止并发导致库存负数
                $stmt = $db->prepare("UPDATE products SET stock = stock - :quantity WHERE id = :id AND stock >= :quantity");
                $stmt->bindValue(':quantity', $quantity);
                $stmt->bindValue(':id', $productId);
                $stmt->execute();
                if ($db->changes() === 0) {
                    throw new Exception('库存不足');
                }

                $orderNo = generateOrderNo();
                $stmt = $db->prepare("INSERT INTO orders (order_no, buyer_id, seller_id, product_id, quantity, total_price, status, delivery_info)
                    VALUES (:order_no, :buyer_id, :seller_id, :product_id, :quantity, :total_price, 'paid', :delivery_info)");
                $stmt->bindValue(':order_no', $orderNo);
                $stmt->bindValue(':buyer_id', $buyer['id']);
                $stmt->bindValue(':seller_id', $product['seller_id']);
                $stmt->bindValue(':product_id', $productId);
                $stmt->bindValue(':quantity', $quantity);
                $stmt->bindValue(':total_price', $totalPrice);
                $stmt->bindValue(':delivery_info', sanitizeInput($_POST['delivery_info'] ?? ''));
                $stmt->execute();
                $orderId = $db->lastInsertRowID();

                $db->exec('COMMIT');
                $db->close();

                orderEvent($orderId, 'buy', '买家下单并完成支付，资金已托管', $buyer['id']);
                $success = $orderNo;
            } catch (Exception $e) {
                $db->exec('ROLLBACK');
                $db->close();
                $error = L('insufficient_balance');
            }
        }
    }
}

$lang = getLang();
$pageTitle = L('buy');
$active = 'market';
$BASE = '../';
require '../inc/header.php';
?>
<main class="main container" style="max-width:640px;">
    <div class="lg-card card reveal">
        <h1 class="page-title" style="font-size:22px;margin-bottom:24px;"><i class="fas fa-cart-shopping" style="margin-right:10px;color:var(--primary);"></i><?php echo L('checkout'); ?></h1>

        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom:20px;"><i class="fas fa-circle-exclamation" style="margin-top:2px;"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="text-center" style="padding:10px 0;">
                <div style="width:84px;height:84px;border-radius:50%;margin:0 auto 18px;display:grid;place-items:center;font-size:38px;background:var(--success-bg);color:var(--success);animation:pop .5s cubic-bezier(.34,1.56,.64,1);"><i class="fas fa-check"></i></div>
                <h2 style="font-size:20px;margin-bottom:8px;"><?php echo L('order_success'); ?></h2>
                <p class="text-muted"><?php echo htmlspecialchars($success); ?></p>
                <p class="text-muted" style="font-size:13px;margin-top:6px;"><i class="fas fa-shield-halved"></i> <?php echo L('escrow_success_notice'); ?></p>
                <a href="orders.php" class="btn btn-primary" style="margin-top:18px;"><i class="fas fa-receipt"></i> <?php echo L('view_orders'); ?></a>
            </div>
        <?php else: ?>
            <div class="product-summary">
                <div class="product-summary-ico"><i class="fas fa-box"></i></div>
                <div class="product-summary-info">
                    <h4><?php echo htmlspecialchars($product['name']); ?></h4>
                    <p class="text-muted"><?php echo htmlspecialchars($product['description']); ?></p>
                    <span class="product-price">$<?php echo number_format($product['price'], 2); ?></span>
                    <small class="text-subtle"><i class="fas fa-user"></i> <?php echo htmlspecialchars($product['seller_name']); ?></small>
                </div>
            </div>

            <div class="balance-info">
                <span><?php echo L('your_balance'); ?>: </span>
                <strong>$<?php echo number_format(userBalance($buyer), 2); ?></strong>
            </div>

            <div class="alert" style="margin-bottom:20px;background:color-mix(in srgb, var(--info) 10%, transparent);border:1px solid color-mix(in srgb, var(--info) 30%, transparent);color:var(--info);"><i class="fas fa-shield-halved" style="margin-top:2px;"></i> <?php echo L('escrow_notice'); ?></div>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

                <div class="form-group">
                    <label class="form-label"><?php echo L('quantity'); ?></label>
                    <input type="number" class="form-control" name="quantity" id="qty" value="1" min="1" max="<?php echo $product['stock']; ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo L('delivery_info'); ?> (<?php echo L('optional'); ?>)</label>
                    <textarea class="form-control" name="delivery_info" rows="3" style="min-height:90px;" placeholder="<?php echo L('delivery_placeholder'); ?>"></textarea>
                </div>

                <div class="total-box">
                    <span><?php echo L('total'); ?></span>
                    <strong id="totalVal">$<?php echo number_format($product['price'], 2); ?></strong>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg"><i class="fas fa-shield-halved"></i> <?php echo L('confirm_purchase'); ?></button>
                <a href="mall.php" class="btn btn-ghost btn-block" style="margin-top:10px;"><?php echo L('cancel'); ?></a>
            </form>
        <?php endif; ?>
    </div>
</main>
<script>
    (function () {
        var qty = document.getElementById('qty');
        var total = document.getElementById('totalVal');
        if (!qty || !total) return;
        var price = <?php echo (float)$product['price']; ?>;
        qty.addEventListener('input', function () {
            var q = parseInt(this.value) || 1;
            if (q < 1) q = 1;
            total.textContent = '$' + (price * q).toFixed(2);
        });
    })();
</script>
<?php
require '../inc/footer.php';
?>
