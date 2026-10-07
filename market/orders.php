<?php
require_once '../security.php';
require_once 'init.php';

if (!isLoggedIn()) {
    header('Location: ../login.php?next=' . urlencode(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?')));
    exit;
}

// 自动放款：已发货且超过期限的订单放款给卖家
processAutoRelease();

$userId = $_SESSION['user_id'];
$lang = getLang();
$csrf = generateCsrfToken();
$error = '';
$success = '';

/* ---------- 订单操作 ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = L('csrf_invalid');
    } else {
        $action = $_POST['action'] ?? '';
        $oid = intval($_POST['order_id'] ?? 0);
        $order = $oid ? dbGetRow("SELECT * FROM orders WHERE id = :id", [':id' => $oid]) : null;

        if ($order) {
            $isBuyer = intval($order['buyer_id']) === $userId;
            $isSeller = intval($order['seller_id']) === $userId;

            /* 卖家发货 */
            if ($action === 'order_ship' && $isSeller && $order['status'] === 'paid') {
                $hours = max(1, intval(getSetting('auto_release_hours', '72')));
                $db = getDB();
                $db->exec('BEGIN TRANSACTION');
                try {
                    $st = $db->prepare("UPDATE orders SET status = 'shipped', auto_release_at = datetime('now', '+' || :h || ' hours'), updated_at = datetime('now') WHERE id = :id");
                    $st->bindValue(':h', $hours);
                    $st->bindValue(':id', $oid);
                    $st->execute();
                    $db->exec('COMMIT');
                    $db->close();
                    orderEvent($oid, 'ship', '卖家已发货', $userId);
                    $success = L('order_shipped');
                } catch (Exception $e) {
                    $db->exec('ROLLBACK');
                    $db->close();
                    $error = L('order_failed');
                }
            }

            /* 买家确认收货 → 放款给卖家 */
            if ($action === 'order_confirm' && $isBuyer && in_array($order['status'], ['paid', 'shipped'])) {
                $db = getDB();
                $db->exec('BEGIN TRANSACTION');
                try {
                    $st = $db->prepare("UPDATE users SET balance = balance + :amount WHERE id = :id");
                    $st->bindValue(':amount', $order['total_price']);
                    $st->bindValue(':id', $order['seller_id']);
                    $st->execute();
                    $st = $db->prepare("UPDATE orders SET status = 'completed', released_at = datetime('now'), auto_release_at = NULL, updated_at = datetime('now') WHERE id = :id");
                    $st->bindValue(':id', $oid);
                    $st->execute();
                    $db->exec('COMMIT');
                    $db->close();
                    orderEvent($oid, 'confirm', '买家确认收货，资金已放款给卖家', $userId);
                    $success = L('order_completed');
                } catch (Exception $e) {
                    $db->exec('ROLLBACK');
                    $db->close();
                    $error = L('order_failed');
                }
            }

            /* 买家取消（仅未发货状态）→ 退款 + 回库存 */
            if ($action === 'order_cancel' && $isBuyer && $order['status'] === 'paid') {
                $db = getDB();
                $db->exec('BEGIN TRANSACTION');
                try {
                    $st = $db->prepare("UPDATE users SET balance = balance + :amount WHERE id = :id");
                    $st->bindValue(':amount', $order['total_price']);
                    $st->bindValue(':id', $order['buyer_id']);
                    $st->execute();
                    $st = $db->prepare("UPDATE products SET stock = stock + :q WHERE id = :id");
                    $st->bindValue(':q', $order['quantity']);
                    $st->bindValue(':id', $order['product_id']);
                    $st->execute();
                    $st = $db->prepare("UPDATE orders SET status = 'cancelled', updated_at = datetime('now') WHERE id = :id");
                    $st->bindValue(':id', $oid);
                    $st->execute();
                    $db->exec('COMMIT');
                    $db->close();
                    orderEvent($oid, 'cancel', '买家取消订单，资金已退回', $userId);
                    $success = L('order_cancelled');
                } catch (Exception $e) {
                    $db->exec('ROLLBACK');
                    $db->close();
                    $error = L('order_failed');
                }
            }

            /* 订单沟通留言 */
            if ($action === 'order_message' && ($isBuyer || $isSeller)) {
                $content = trim($_POST['content'] ?? '');
                if ($content === '') {
                    $error = L('message_empty');
                } else {
                    dbInsert("INSERT INTO order_messages (order_id, user_id, content) VALUES (:o, :u, :c)",
                        [':o' => $oid, ':u' => $userId, ':c' => mb_substr($content, 0, 1000)]);
                    $success = L('message_sent');
                }
            }

            /* 买家申请仲裁 */
            if ($action === 'order_dispute' && $isBuyer) {
                $existing = dbGetRow("SELECT id FROM disputes WHERE order_id = :o AND status IN ('open', 'investigating')", [':o' => $oid]);
                if ($existing) {
                    $error = L('dispute_already_open') ?: '该订单已有仲裁正在处理';
                } else {
                    $reason = trim($_POST['reason'] ?? '');
                    if ($reason === '') {
                        $error = L('dispute_reason_required') ?: '请填写仲裁原因';
                    } else {
                        $db = getDB();
                        $db->exec('BEGIN IMMEDIATE');
                        try {
                            dbQuery(
                                "INSERT INTO disputes (order_id, applicant_id, reason, status) VALUES (:o, :u, :r, 'open')",
                                [':o' => $oid, ':u' => $userId, ':r' => mb_substr($reason, 0, 2000)]
                            );
                            dbQuery("UPDATE orders SET status = 'disputed' WHERE id = :id", [':id' => $oid]);
                            $db->exec('COMMIT');
                            $db->close();
                            orderEvent($oid, 'dispute_open', '买家已申请仲裁：' . mb_substr($reason, 0, 200), $userId);
                            $success = L('dispute_filed') ?: '仲裁申请已提交，等待审核员处理';
                        } catch (Exception $e) {
                            $db->exec('ROLLBACK');
                            $db->close();
                            $error = L('order_failed');
                        }
                    }
                }
            }
        }
    }
}

$buyOrders = dbQuery("SELECT o.*, p.name as product_name, u.username as seller_name
    FROM orders o
    JOIN products p ON o.product_id = p.id
    JOIN users u ON o.seller_id = u.id
    WHERE o.buyer_id = :user_id
    ORDER BY o.created_at DESC", [':user_id' => $userId]);
if (!is_array($buyOrders)) $buyOrders = [];

$sellOrders = dbQuery("SELECT o.*, p.name as product_name, u.username as buyer_name
    FROM orders o
    JOIN products p ON o.product_id = p.id
    JOIN users u ON o.buyer_id = u.id
    WHERE o.seller_id = :user_id
    ORDER BY o.created_at DESC", [':user_id' => $userId]);
if (!is_array($sellOrders)) $sellOrders = [];

$statusMeta = [
    'pending' => ['label' => L('status_pending'), 'cls' => 'warning'],
    'paid' => ['label' => L('status_paid'), 'cls' => 'info'],
    'shipped' => ['label' => L('status_shipped'), 'cls' => 'primary'],
    'disputed' => ['label' => L('status_disputed') ?: '仲裁中', 'cls' => 'error'],
    'completed' => ['label' => L('status_completed'), 'cls' => 'success'],
    'cancelled' => ['label' => L('status_cancelled'), 'cls' => 'error'],
    'refunded' => ['label' => L('status_refunded') ?: '已退款', 'cls' => 'warning']
];

/**
 * 渲染单个订单卡片
 */
function orderCardHtml($order, $role, $csrf, $statusMeta) {
    global $userId;
    $m = $statusMeta[$order['status']] ?? ['label' => $order['status'], 'cls' => 'info'];
    $tl = orderTimeline($order['id']);
    $msgs = orderMessages($order['id']);

    $html = '<div class="order-card">';
    $html .= '<div class="order-head">'
        . '<span class="order-no">' . htmlspecialchars($order['order_no']) . '</span>'
        . '<span class="badge badge-' . $m['cls'] . '">' . $m['label'] . '</span>'
        . '</div>';

    $html .= '<div class="order-body"><div class="list-main">';
    $html .= '<strong>' . htmlspecialchars($order['product_name']) . '</strong>';
    $html .= '<span class="text-muted"><i class="fas fa-user"></i> '
        . ($role === 'buyer'
            ? L('seller') . ': ' . htmlspecialchars($order['seller_name'])
            : L('buyer') . ': ' . htmlspecialchars($order['buyer_name']))
        . ' &nbsp;·&nbsp; ' . L('quantity') . ': ' . $order['quantity'] . '</span>';
    if (!empty($order['delivery_info'])) {
        $html .= '<span class="text-subtle" style="font-size:12.5px;"><i class="fas fa-envelope"></i> ' . htmlspecialchars($order['delivery_info']) . '</span>';
    }
    $html .= '</div><div class="order-price">$' . number_format($order['total_price'], 2) . '</div></div>';

    $html .= '<div class="order-foot"><i class="fas fa-clock"></i> ' . htmlspecialchars($order['created_at']);
    if (!empty($order['auto_release_at']) && $order['status'] === 'shipped') {
        $html .= '<span class="dot-sep">·</span><i class="fas fa-hourglass-half"></i> ' . L('auto_release_at') . ': ' . htmlspecialchars($order['auto_release_at']);
    }
    $html .= '</div>';

    if (!empty($tl)) {
        $html .= '<div class="order-tl">';
        foreach ($tl as $ev) {
            $html .= '<div class="tl-item"><i class="fas fa-circle-dot"></i>'
                . '<div><strong>' . htmlspecialchars(orderEventLabel($ev['event'])) . '</strong>'
                . '<span class="text-subtle">' . htmlspecialchars($ev['note']) . ' · ' . htmlspecialchars($ev['created_at']) . '</span>'
                . '</div></div>';
        }
        $html .= '</div>';
    }

    $actions = '';
    if ($role === 'buyer' && in_array($order['status'], ['paid', 'shipped'])) {
        $actions .= '<form method="POST" action="" onsubmit="return confirm(\'' . htmlspecialchars(L('confirm_receipt_q'), ENT_QUOTES) . '\');">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="action" value="order_confirm">'
            . '<input type="hidden" name="order_id" value="' . $order['id'] . '">'
            . '<button type="submit" class="btn btn-sm btn-success"><i class="fas fa-check-double"></i> ' . L('confirm_receipt') . '</button></form>';
    }
    if ($role === 'buyer' && $order['status'] === 'paid') {
        $actions .= '<form method="POST" action="" onsubmit="return confirm(\'' . htmlspecialchars(L('cancel_order_q'), ENT_QUOTES) . '\');">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="action" value="order_cancel">'
            . '<input type="hidden" name="order_id" value="' . $order['id'] . '">'
            . '<button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-rotate-left"></i> ' . L('cancel_order') . '</button></form>';
    }
    if ($role === 'seller' && $order['status'] === 'paid') {
        $actions .= '<form method="POST" action="">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="action" value="order_ship">'
            . '<input type="hidden" name="order_id" value="' . $order['id'] . '">'
            . '<button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-truck-fast"></i> ' . L('mark_shipped') . '</button></form>';
    }
    /* 买家申请仲裁按钮 */
    if ($role === 'buyer' && in_array($order['status'], ['paid', 'shipped'])) {
        $openD = dbGetRow("SELECT id FROM disputes WHERE order_id = :o AND status IN ('open','investigating')", [':o' => $order['id']]);
        $actions .= '<button type="button" class="btn btn-sm btn-ghost" onclick="openDispute(' . $order['id'] . ')"><i class="fas fa-gavel"></i> ' . (L('apply_dispute') ?: '申请仲裁') . '</button>';
        if (!$openD) {
            $actions .= '<form method="POST" action="" id="dispute-form-' . $order['id'] . '" style="display:none;">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<input type="hidden" name="action" value="order_dispute">'
                . '<input type="hidden" name="order_id" value="' . $order['id'] . '">'
                . '<textarea name="reason" class="form-control" style="margin:8px 0;font-size:13px;" rows="2" placeholder="' . htmlspecialchars(L('dispute_reason_placeholder') ?: '请描述问题（未收到货/质量不符/卖家不响应等）', ENT_QUOTES) . '" required maxlength="2000"></textarea>'
                . '<div style="display:flex;gap:6px;"><button type="button" class="btn btn-sm btn-ghost" onclick="closeDispute(' . $order['id'] . ')">' . (L('cancel') ?: '取消') . '</button>'
                . '<button type="submit" class="btn btn-sm btn-error" onclick="return confirm(\'' . htmlspecialchars(L('confirm_dispute_q') ?: '确认申请仲裁？仲裁期间订单资金将冻结。', ENT_QUOTES) . '\')">' . (L('submit_dispute') ?: '提交申请') . '</button></div>'
                . '</form>';
        } elseif ($openD) {
            $actions .= '<span class="badge badge-error">' . (L('status_disputed') ?: '仲裁中') . ' #' . $openD['id'] . '</span>';
        }
    }
    if ($actions !== '') {
        $html .= '<div class="order-actions">' . $actions . '</div>';
    }

    $html .= '<div class="order-chat">'
        . '<div class="order-chat-head"><i class="fas fa-comments"></i> ' . L('order_discussion') . '</div>';
    if (empty($msgs)) {
        $html .= '<p class="text-subtle" style="font-size:13px;margin:6px 0;">' . L('no_order_messages') . '</p>';
    } else {
        $html .= '<div class="order-msgs">';
        foreach ($msgs as $msg) {
            $mine = intval($msg['user_id']) === $userId;
            $html .= '<div class="msg ' . ($mine ? 'out' : 'in') . '">'
                . '<div class="msg-bubble">' . htmlspecialchars($msg['content']) . '</div>'
                . '<div class="msg-meta">' . htmlspecialchars($msg['username']) . ' · ' . htmlspecialchars($msg['created_at']) . '</div>'
                . '</div>';
        }
        $html .= '</div>';
    }
    $html .= '<form method="POST" action="" class="order-msg-form">'
        . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
        . '<input type="hidden" name="action" value="order_message">'
        . '<input type="hidden" name="order_id" value="' . $order['id'] . '">'
        . '<input type="text" class="form-control" name="content" placeholder="' . htmlspecialchars(L('order_msg_placeholder'), ENT_QUOTES) . '" required maxlength="1000">'
        . '<button type="submit" class="btn btn-primary btn-sm" title="' . L('send') . '"><i class="fas fa-paper-plane"></i></button>'
        . '</form>';
    $html .= '</div>';

    $html .= '</div>';
    return $html;
}

$pageTitle = L('my_orders');
$active = 'market';
$BASE = '../';
require '../inc/header.php';
?>
<main class="main container">
    <div class="page-head reveal">
        <div>
            <h1 class="page-title"><i class="fas fa-receipt" style="margin-right:10px;color:var(--primary);"></i><?php echo L('my_orders'); ?></h1>
        </div>
        <a href="mall.php" class="btn btn-ghost"><i class="fas fa-store"></i> <?php echo L('continue_shopping'); ?></a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error reveal"><i class="fas fa-circle-exclamation" style="margin-top:2px;"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success reveal"><i class="fas fa-check" style="margin-top:2px;"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="order-tabs reveal">
        <button type="button" class="order-tab active" data-target="buyTab"><i class="fas fa-cart-shopping"></i> <?php echo L('purchases'); ?> (<?php echo count($buyOrders); ?>)</button>
        <button type="button" class="order-tab" data-target="sellTab"><i class="fas fa-shop"></i> <?php echo L('sales'); ?> (<?php echo count($sellOrders); ?>)</button>
    </div>

    <div id="buyTab" class="order-panel active reveal">
        <?php if (empty($buyOrders)): ?>
            <div class="lg-card card"><div class="empty-state"><i class="fas fa-receipt"></i><p><?php echo L('no_purchase_orders'); ?></p><a href="mall.php" class="btn btn-primary" style="margin-top:14px;"><?php echo L('go_shopping'); ?></a></div></div>
        <?php else: ?>
            <?php foreach ($buyOrders as $order): ?>
                <?php echo orderCardHtml($order, 'buyer', $csrf, $statusMeta); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div id="sellTab" class="order-panel reveal" style="display:none;">
        <?php if (empty($sellOrders)): ?>
            <div class="lg-card card"><div class="empty-state"><i class="fas fa-shop"></i><p><?php echo L('no_sales_orders'); ?></p><a href="sell.php" class="btn btn-primary" style="margin-top:14px;"><?php echo L('start_selling'); ?></a></div></div>
        <?php else: ?>
            <?php foreach ($sellOrders as $order): ?>
                <?php echo orderCardHtml($order, 'seller', $csrf, $statusMeta); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>
<script>
    document.querySelectorAll('.order-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.order-tab').forEach(function (b) { b.classList.remove('active'); });
            document.querySelectorAll('.order-panel').forEach(function (p) { p.style.display = 'none'; });
            btn.classList.add('active');
            document.getElementById(btn.getAttribute('data-target')).style.display = 'block';
        });
    });
    function openDispute(oid) {
        document.getElementById('dispute-form-' + oid).style.display = 'block';
    }
    function closeDispute(oid) {
        document.getElementById('dispute-form-' + oid).style.display = 'none';
    }
</script>
<?php
require '../inc/footer.php';
?>
