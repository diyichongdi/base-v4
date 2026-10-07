<?php
require_once '../security.php';
require_once 'init.php';

if (!isLoggedIn()) {
    header('Location: ../login.php?next=' . urlencode(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?')));
    exit;
}

$userId = $_SESSION['user_id'];
$arbiter = isArbiter() || isAdmin();
$lang = getLang();
$csrf = generateCsrfToken();
$error = '';
$success = '';

require_once '../inc/rates.php';

/* ---------- 操作处理 ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = L('csrf_invalid');
    } else {
        $action = $_POST['action'] ?? '';
        $did = intval($_POST['dispute_id'] ?? 0);
        $d = dbGetRow("SELECT d.*, o.*, p.name as product_name FROM disputes d JOIN orders o ON d.order_id = o.id JOIN products p ON o.product_id = p.id WHERE d.id = :id", [':id' => $did]);

        if (!$d) {
            $error = '纠纷不存在';
        } elseif (!$arbiter && $d['applicant_id'] !== $userId) {
            $error = '无权操作';
        } else {
            $db = getDB();
            $db->exec('BEGIN IMMEDIATE');
            try {
                if ($action === 'claim' && $arbiter) {
                    dbQuery("UPDATE disputes SET assigned_to = :u, status = 'investigating' WHERE id = :id AND assigned_to IS NULL",
                        [':u' => $userId, ':id' => $did]);
                    $success = '已认领该纠纷，进入调查阶段';
                }

                if ($action === 'resolve_buyer' && $arbiter) {
                    // 状态守卫：只能处理未解决/调查中的纠纷
                    if (!in_array($d['status'], ['open', 'investigating'])) {
                        $error = '该纠纷已关闭，无法重复处理';
                    } else {
                        // 买家胜诉：退款给买家（买家在下单时已扣款，此处退回托管资金）
                        // 卖家余额不受影响 — 卖家从未收到过这笔款项（托管未释放）
                        $db->prepare("UPDATE users SET balance = balance + :a WHERE id = :id")
                            ->bindValue(':a', $d['total_price'])->bindValue(':id', $d['buyer_id'])->execute();
                        // 商品回库存（买家胜诉即视为退货）
                        $db->prepare("UPDATE products SET stock = stock + :q WHERE id = :pid")
                            ->bindValue(':q', intval($d['quantity'] ?? 1))->bindValue(':pid', intval($d['product_id']))->execute();
                        $db->prepare("UPDATE orders SET status = 'refunded', updated_at = datetime('now') WHERE id = :id")
                            ->bindValue(':id', $d['order_id'])->execute();
                        orderEvent($d['order_id'], 'refund', '仲裁结果：买家胜诉，已退款 $' . number_format($d['total_price'], 2), $userId);
                        $success = '仲裁完成：买家胜诉，已退款';
                    }
                }

                if ($action === 'resolve_seller' && $arbiter) {
                    // 状态守卫
                    if (!in_array($d['status'], ['open', 'investigating'])) {
                        $error = '该纠纷已关闭，无法重复处理';
                    } else {
                        // 卖家胜诉：从托管释放给卖家
                        $db->prepare("UPDATE users SET balance = balance + :a WHERE id = :id")
                            ->bindValue(':a', $d['total_price'])->bindValue(':id', $d['seller_id'])->execute();
                        $db->prepare("UPDATE orders SET status = 'completed', released_at = datetime('now'), auto_release_at = NULL, updated_at = datetime('now') WHERE id = :id")
                            ->bindValue(':id', $d['order_id'])->execute();
                        orderEvent($d['order_id'], 'arbitrate', '仲裁结果：卖家胜诉，订单完成并放款', $userId);
                        $success = '仲裁完成：卖家胜诉，订单完成';
                    }
                }

                if ($action === 'reject_dispute' && $arbiter) {
                    // 状态守卫
                    if (!in_array($d['status'], ['open', 'investigating'])) {
                        $error = '该纠纷已关闭，无法重复处理';
                    } else {
                        // 驳回：恢复订单为 paid 状态（资金仍托管）
                        $db->prepare("UPDATE orders SET status = 'paid', updated_at = datetime('now') WHERE id = :id")
                            ->bindValue(':id', $d['order_id'])->execute();
                        orderEvent($d['order_id'], 'dispute_close', '仲裁驳回，订单正常处理', $userId);
                        $success = '仲裁已驳回，订单恢复处理';
                    }
                }

                if (in_array($action, ['resolve_buyer', 'resolve_seller', 'reject_dispute']) && $success !== '') {
                    $note = mb_substr(trim($_POST['decision_note'] ?? ''), 0, 2000);
                    $decision = $action === 'resolve_buyer' ? 'buyer_win'
                        : ($action === 'resolve_seller' ? 'seller_win' : 'rejected');
                    $newStatus = $action === 'reject_dispute' ? 'rejected' : 'resolved';
                    $db->prepare("UPDATE disputes SET status = :st, decision = :dec, decision_note = :note, resolved_at = datetime('now') WHERE id = :id")
                        ->bindValue(':st', $newStatus)->bindValue(':dec', $decision)->bindValue(':note', $note)->bindValue(':id', $did)->execute();
                }
            } catch (Exception $e) {
                $db->exec('ROLLBACK');
                $error = '操作失败：' . $e->getMessage();
            }
            if ($success !== '') $db->exec('COMMIT');
            $db->close();
        }
    }
}

/* ---------- 数据 ---------- */
$myDisputes = dbQuery("SELECT d.*, o.total_price, o.status as order_status, p.name as product_name,
     a.username as arbiter_name
     FROM disputes d JOIN orders o ON d.order_id = o.id JOIN products p ON o.product_id = p.id
     LEFT JOIN users a ON d.assigned_to = a.id
     WHERE d.applicant_id = :u ORDER BY d.created_at DESC", [':u' => $userId]);
if (!is_array($myDisputes)) $myDisputes = [];

$openDisputes = [];
if ($arbiter) {
    $openDisputes = dbQuery("SELECT d.*, o.total_price, o.buyer_id, o.seller_id, p.name as product_name,
         b.username as buyer_no, s.username as seller_no
         FROM disputes d JOIN orders o ON d.order_id = o.id JOIN products p ON o.product_id = p.id
         JOIN users b ON o.buyer_id = b.id JOIN users s ON o.seller_id = s.id
         WHERE d.status IN ('open', 'investigating') ORDER BY d.created_at ASC");
    if (!is_array($openDisputes)) $openDisputes = [];
}

$pageTitle = L('disputes') ?: '仲裁';
$active = 'market';
$BASE = '../';
require '../inc/header.php';
?>
<main class="main container">
    <div class="page-head reveal">
        <div>
            <h1 class="page-title"><i class="fas fa-gavel" style="margin-right:10px;color:var(--primary);"></i><?php echo L('disputes') ?: '仲裁'; ?></h1>
            <p class="text-muted"><?php echo $lang === 'zh'
                ? ($arbiter ? '仲裁员工作台 — 处理待处理的纠纷' : '您的仲裁申请记录')
                : ($arbiter ? 'Arbiter workspace — handle open disputes' : 'Your dispute applications'); ?></p>
        </div>
        <?php if ($arbiter): ?>
            <a href="orders.php" class="btn btn-ghost btn-sm"><i class="fas fa-receipt"></i> <?php echo L('my_orders'); ?></a>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error reveal"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success reveal"><i class="fas fa-check"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if ($arbiter && !empty($openDisputes)): ?>
        <div class="lg-card card reveal">
            <div class="card-head"><h3><i class="fas fa-clipboard-list"></i> <?php echo $lang === 'zh' ? '待处理纠纷' : 'Open Disputes'; ?> <span class="text-muted" style="font-weight:normal;">(<?php echo count($openDisputes); ?>)</span></h3></div>
            <div class="card-body">
                <?php foreach ($openDisputes as $d): ?>
                    <div class="list-row pending" style="margin-bottom:14px;align-items:flex-start;flex-wrap:wrap;gap:12px;">
                        <div class="list-main">
                            <strong>#D<?php echo $d['id']; ?> · <?php echo htmlspecialchars($d['product_name']); ?> $<?php echo number_format($d['total_price'], 2); ?></strong>
                            <span class="text-muted"><i class="fas fa-user"></i> #<?php echo htmlspecialchars($d['buyer_no']); ?> (买家) → #<?php echo htmlspecialchars($d['seller_no']); ?> (卖家)</span>
                            <span class="badge badge-<?php echo $d['status'] === 'open' ? 'warning' : 'primary'; ?>"><?php echo $d['status'] === 'open' ? '待认领' : '调查中'; ?></span>
                        </div>
                        <div class="list-main" style="margin-top:8px;">
                            <strong><?php echo $lang === 'zh' ? '申述原因' : 'Reason'; ?></strong>
                            <p style="margin:4px 0 0;font-size:13px;"><?php echo htmlspecialchars($d['reason']); ?></p>
                        </div>
                        <?php if (!$d['assigned_to']): ?>
                            <form method="POST" class="flex gap-2">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="claim">
                                <input type="hidden" name="dispute_id" value="<?php echo $d['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-hand-pointer"></i> <?php echo $lang === 'zh' ? '认领调查' : 'Claim'; ?></button>
                            </form>
                        <?php else: ?>
                            <span class="badge badge-info">已指派 #<?php echo $d['assigned_to']; ?></span>
                        <?php endif; ?>
                        <form method="POST" class="flex gap-2" onsubmit="return confirm('<?php echo addslashes($lang === 'zh' ? '确认裁定买家胜诉并退款？' : 'Confirm buyer wins?'); ?>');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="action" value="resolve_buyer">
                            <input type="hidden" name="dispute_id" value="<?php echo $d['id']; ?>">
                            <input type="text" name="decision_note" class="form-control" style="width:200px;font-size:12px;" placeholder="<?php echo $lang === 'zh' ? '裁定备注（选填）' : 'Note (opt)'; ?>">
                            <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-hand-holding-heart"></i> <?php echo $lang === 'zh' ? '买家胜' : 'Buyer wins'; ?></button>
                        </form>
                        <form method="POST" class="flex gap-2" onsubmit="return confirm('<?php echo addslashes($lang === 'zh' ? '确认裁定卖家胜诉？' : 'Confirm seller wins?'); ?>');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="action" value="resolve_seller">
                            <input type="hidden" name="dispute_id" value="<?php echo $d['id']; ?>">
                            <input type="text" name="decision_note" class="form-control" style="width:200px;font-size:12px;" placeholder="<?php echo $lang === 'zh' ? '裁定备注（选填）' : 'Note (opt)'; ?>">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-scales"></i> <?php echo $lang === 'zh' ? '卖家胜' : 'Seller wins'; ?></button>
                        </form>
                        <form method="POST" class="flex gap-2" onsubmit="return confirm('<?php echo addslashes($lang === 'zh' ? '确认驳回此次仲裁申请吗？' : 'Reject this dispute?'); ?>');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="action" value="reject_dispute">
                            <input type="hidden" name="dispute_id" value="<?php echo $d['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-xmark"></i> <?php echo $lang === 'zh' ? '驳回' : 'Reject'; ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php elseif ($arbiter && empty($openDisputes)): ?>
        <div class="lg-card card reveal"><div class="empty-state"><i class="fas fa-check-circle"></i><p><?php echo $lang === 'zh' ? '暂无待处理纠纷' : 'No open disputes'; ?></p></div></div>
    <?php endif; ?>

    <div class="lg-card card reveal">
        <div class="card-head"><h3><i class="fas fa-history"></i> <?php echo $lang === 'zh' ? '我的申诉记录' : 'My Appeals'; ?></h3></div>
        <?php if (empty($myDisputes)): ?>
            <div class="empty-state"><i class="fas fa-hand"></i><p><?php echo $lang === 'zh' ? '暂无仲裁记录' : 'No dispute history'; ?></p></div>
        <?php else: ?>
            <?php foreach ($myDisputes as $d): ?>
                <div class="list-row" style="margin-bottom:10px;cursor:default;">
                    <div class="list-main">
                        <strong>#D<?php echo $d['id']; ?> · <?php echo htmlspecialchars($d['product_name']); ?> · <?php echo htmlspecialchars($d['order_status']); ?></strong>
                        <span class="text-muted text-small"><?php echo htmlspecialchars($d['created_at']); ?> · <?php echo htmlspecialchars(substr($d['reason'], 0, 40)); ?>…</span>
                    </div>
                    <div style="text-align:right;">
                        <span class="badge badge-<?php echo $d['status'] === 'resolved' ? 'success' : ($d['status'] === 'rejected' ? 'error' : 'warning'); ?>">
                            <?php echo $d['status'] === 'resolved' ? '已解决' : ($d['status'] === 'rejected' ? '已驳回' : '进行中'); ?>
                        </span>
                        <?php if ($d['arbiter_name']): ?><small class="text-muted">指派: #<?php echo $d['arbiter_name']; ?></small><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>
<?php
require '../inc/footer.php';
