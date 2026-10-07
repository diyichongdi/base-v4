<?php
require_once '../security.php';
requireAdmin();
require_once '../inc/rates.php';
require_once '../market/init.php';
require_once '../forum/init.php';

if (isBannedUser()) {
    logout();
    header('Location: ../login.php');
    exit;
}

$lang = getLang();
$tab = $_GET['tab'] ?? 'dashboard';
$allowed = ['dashboard', 'services', 'forum', 'products', 'orders', 'deposits', 'addresses', 'announcements', 'disputes', 'settings'];
if (!in_array($tab, $allowed)) $tab = 'dashboard';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

function adminPager(int $total, int $page, int $perPage, string $tab): string {
    $last = max(1, (int)ceil($total / $perPage));
    if ($last <= 1) return '';
    $mk = function(int $p) use ($tab): string {
        return htmlspecialchars('?tab=' . urlencode($tab) . '&page=' . $p);
    };
    $start = max(1, $page - 2);
    $end = min($last, $page + 2);
    if ($start > 2 && $end < $last) { $start++; $end++; }
    $html = '<div class="pager">';
    $html .= $page > 1
        ? '<a class="page-btn" href="' . $mk($page - 1) . '"><i class="fas fa-chevron-left"></i></a>'
        : '<span class="page-btn disabled"><i class="fas fa-chevron-left"></i></span>';
    for ($i = $start; $i <= $end; $i++) {
        $html .= $i === $page
            ? '<span class="page-btn active">' . $i . '</span>'
            : '<a class="page-btn" href="' . $mk($i) . '">' . $i . '</a>';
    }
    $html .= $page < $last
        ? '<a class="page-btn" href="' . $mk($page + 1) . '"><i class="fas fa-chevron-right"></i></a>'
        : '<span class="page-btn disabled"><i class="fas fa-chevron-right"></i></span>';
    $html .= '</div>';
    return $html;
}

$error = '';
$success = '';

/* ---------- 处理 POST 动作 ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = L('csrf_invalid');
    } else {
        $action = $_POST['action'] ?? '';

        /* 服务管理：保存黑客团队配置 */
        if ($action === 'save_teams' && $tab === 'services') {
            $names = $_POST['name'] ?? [];
            $descs = $_POST['desc'] ?? [];
            $busy = $_POST['busy'] ?? [];
            $stored = getSetting('service_teams', '');
            $cur = $stored ? json_decode($stored, true) : [];
            if (!is_array($cur)) $cur = [];
            for ($i = 0; $i < 4; $i++) {
                $cur[$i]['name'] = trim($names[$i] ?? '');
                $cur[$i]['desc'] = trim($descs[$i] ?? '');
                $cur[$i]['busy'] = !empty($busy[$i]);
            }
            setSetting('service_teams', json_encode($cur, JSON_UNESCAPED_UNICODE));
            $success = '团队配置已保存';
        }

        /* 服务管理：保存自动放款期限 */
        if ($action === 'save_auto_release' && $tab === 'services') {
            $hours = max(1, intval($_POST['auto_release_hours'] ?? 72));
            setSetting('auto_release_hours', (string)$hours);
            $success = '自动放款期限已保存';
        }

        /* 设置：保存页脚内容 */
        if ($action === 'save_footer' && $tab === 'settings') {
            $footerContent = $_POST['footer_content'] ?? '';
            $footerCustom = isset($_POST['footer_custom']) ? '1' : '0';
            setSetting('footer_content', $footerContent);
            setSetting('footer_custom', $footerCustom);
            $success = '页脚配置已保存';
        }

        /* 论坛管理：删除帖子 */
        if ($action === 'forum_delete_post' && $tab === 'forum') {
            $pid = intval($_POST['post_id'] ?? 0);
            if ($pid) {
                dbQuery("DELETE FROM forum_likes WHERE post_id = :id OR reply_id IN (
                    SELECT id FROM forum_replies WHERE post_id = :id)", [':id' => $pid], FORUM_DB);
                dbQuery("DELETE FROM forum_posts WHERE id = :id", [':id' => $pid], FORUM_DB);
                $success = '帖子已删除';
            }
        }

        /* 论坛管理：封禁 / 解封用户（全站统一账号，操作主站 users.banned） */
        if (($action === 'forum_ban' || $action === 'forum_unban') && $tab === 'forum') {
            $uid = intval($_POST['forum_uid'] ?? 0);
            $val = $action === 'forum_ban' ? 1 : 0;
            if ($uid) {
                dbQuery("UPDATE users SET banned = :v WHERE id = :id", [':v' => $val, ':id' => $uid]);
                $success = $action === 'forum_ban' ? '用户已封禁' : '用户已解封';
            }
        }

        /* 商品管理：上架 / 下架 */
        if (($action === 'product_up' || $action === 'product_down') && $tab === 'products') {
            $pid = intval($_POST['product_id'] ?? 0);
            $val = $action === 'product_up' ? 'active' : 'paused';
            if ($pid) {
                dbQuery("UPDATE products SET status = :s, updated_at = datetime('now') WHERE id = :id", [':s' => $val, ':id' => $pid]);
                $success = $action === 'product_up' ? '商品已上架' : '商品已下架';
            }
        }

        /* 订单管理：放款 */
        if ($action === 'order_release' && $tab === 'orders') {
            $oid = intval($_POST['order_id'] ?? 0);
            $order = dbGetRow("SELECT * FROM orders WHERE id = :id", [':id' => $oid]);
            if ($order && in_array($order['status'], ['paid', 'shipped'])) {
                $db = getDB();
                $db->exec('BEGIN TRANSACTION');
                try {
                    $st = $db->prepare("UPDATE users SET balance = balance + :amount WHERE id = :id");
                    $st->bindValue(':amount', $order['total_price']);
                    $st->bindValue(':id', $order['seller_id']);
                    $st->execute();
                    $st = $db->prepare("UPDATE orders SET status = 'completed', released_at = datetime('now'), updated_at = datetime('now') WHERE id = :id");
                    $st->bindValue(':id', $oid);
                    $st->execute();
                    $db->exec('COMMIT');
                    orderEvent($oid, 'release', '管理员手动放款', null);
                    $success = '订单已放款';
                } catch (Exception $e) {
                    $db->exec('ROLLBACK');
                    $error = '放款失败';
                }
                $db->close();
            }
        }

        /* 订单管理：取消并退款 */
        if ($action === 'order_cancel' && $tab === 'orders') {
            $oid = intval($_POST['order_id'] ?? 0);
            $order = dbGetRow("SELECT * FROM orders WHERE id = :id", [':id' => $oid]);
            if ($order && in_array($order['status'], ['paid', 'shipped'])) {
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
                    orderEvent($oid, 'cancel', '管理员取消订单并退款', null);
                    $success = '订单已取消并退款';
                } catch (Exception $e) {
                    $db->exec('ROLLBACK');
                    $error = '取消失败';
                }
                $db->close();
            }
        }

        /* 充值管理：通过（到账） */
        if ($action === 'deposit_approve' && $tab === 'deposits') {
            $tid = intval($_POST['tx_id'] ?? 0);
            $tx = dbGetRow("SELECT * FROM transactions WHERE id = :id AND type = 'deposit' AND status = 'pending'", [':id' => $tid]);
            if ($tx) {
                $db = getDB();
                $db->exec('BEGIN TRANSACTION');
                try {
                    $st = $db->prepare("UPDATE users SET balance = balance + :amount WHERE id = :id");
                    $st->bindValue(':amount', $tx['amount']);
                    $st->bindValue(':id', $tx['user_id']);
                    $st->execute();
                    $st = $db->prepare("UPDATE transactions SET status = 'completed' WHERE id = :id");
                    $st->bindValue(':id', $tid);
                    $st->execute();
                    $db->exec('COMMIT');
                    $success = '充值已到账';
                } catch (Exception $e) {
                    $db->exec('ROLLBACK');
                    $error = '处理失败';
                }
                $db->close();
            }
        }

        /* 充值管理：驳回 */
        if ($action === 'deposit_reject' && $tab === 'deposits') {
            $tid = intval($_POST['tx_id'] ?? 0);
            dbQuery("UPDATE transactions SET status = 'cancelled' WHERE id = :id AND type = 'deposit' AND status = 'pending'", [':id' => $tid]);
            $success = '充值已驳回';
        }

        /* 提现管理：通过（余额已在申请时冻结扣减，仅标记完成） */
        if ($action === 'withdraw_approve' && $tab === 'deposits') {
            $tid = intval($_POST['tx_id'] ?? 0);
            $txHashOut = trim($_POST['out_tx_hash'] ?? '');
            $st = dbQuery("UPDATE transactions SET status = 'completed', tx_hash = COALESCE(NULLIF(:h, ''), tx_hash) WHERE id = :id AND type = 'withdraw' AND status = 'pending'",
                [':id' => $tid, ':h' => $txHashOut]);
            $success = '提现已打款完成';
        }

        /* 提现管理：驳回（退回冻结余额） */
        if ($action === 'withdraw_reject' && $tab === 'deposits') {
            $tid = intval($_POST['tx_id'] ?? 0);
            $tx = dbGetRow("SELECT * FROM transactions WHERE id = :id AND type = 'withdraw' AND status = 'pending'", [':id' => $tid]);
            if ($tx) {
                $db = getDB();
                $db->exec('BEGIN TRANSACTION');
                try {
                    $st = $db->prepare("UPDATE users SET balance = balance + :amount WHERE id = :id");
                    $st->bindValue(':amount', $tx['amount']);
                    $st->bindValue(':id', $tx['user_id']);
                    $st->execute();
                    $st = $db->prepare("UPDATE transactions SET status = 'cancelled' WHERE id = :id");
                    $st->bindValue(':id', $tid);
                    $st->execute();
                    $db->exec('COMMIT');
                    $success = '提现已驳回，余额已退回';
                } catch (Exception $e) {
                    $db->exec('ROLLBACK');
                    $error = '处理失败';
                }
                $db->close();
            }
        }

        /* 收款地址管理：保存 */
        if ($action === 'address_save' && $tab === 'addresses') {
            $aid = intval($_POST['account_id'] ?? 0);
            $addr = trim($_POST['address'] ?? '');
            $active = isset($_POST['is_active']) ? 1 : 0;
            if ($aid > 0) {
                dbQuery("UPDATE deposit_accounts SET address = :a, is_active = :v, updated_at = datetime('now') WHERE id = :id",
                    [':a' => $addr, ':v' => $active, ':id' => $aid]);
                $success = '收款地址已更新';
            }
        }

        /* 公告管理：新增 */
        if ($action === 'announce_add' && $tab === 'announcements') {
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            if ($title === '' || $content === '') {
                $error = '标题和内容不能为空';
            } else {
                dbQuery("INSERT INTO announcements (title, content) VALUES (:t, :c)", [':t' => $title, ':c' => $content]);
                $success = '公告已发布';
            }
        }

        /* 公告管理：启用 / 停用 */
        if (($action === 'announce_on' || $action === 'announce_off') && $tab === 'announcements') {
            $aid = intval($_POST['announce_id'] ?? 0);
            $val = $action === 'announce_on' ? 1 : 0;
            dbQuery("UPDATE announcements SET is_active = :v WHERE id = :id", [':v' => $val, ':id' => $aid]);
            $success = '公告状态已更新';
        }

        /* 公告管理：删除 */
        if ($action === 'announce_delete' && $tab === 'announcements') {
            $aid = intval($_POST['announce_id'] ?? 0);
            dbQuery("DELETE FROM announcements WHERE id = :id", [':id' => $aid]);
            $success = '公告已删除';
        }
    }
}

/* ---------- 自动放款 ---------- */
if ($tab === 'orders') {
    processAutoRelease();
}

/* ---------- 数据 ---------- */
$csrf = generateCsrfToken();

$statUsers = dbGetRow("SELECT COUNT(*) as c FROM users")['c'] ?? 0;
$statProducts = dbGetRow("SELECT COUNT(*) as c FROM products")['c'] ?? 0;
$statOrders = dbGetRow("SELECT COUNT(*) as c FROM orders")['c'] ?? 0;
$statPendingDeposits = dbGetRow("SELECT COUNT(*) as c FROM transactions WHERE type IN ('deposit','withdraw') AND status = 'pending'")['c'] ?? 0;
$statOpenDisputes = dbGetRow("SELECT COUNT(*) as c FROM disputes WHERE status IN ('open','investigating')")['c'] ?? 0;

$teams = json_decode(getSetting('service_teams', ''), true);
if (!is_array($teams)) {
    $teams = [
        ['name' => 'Shadow Crew', 'desc' => '专注于网络安全渗透测试，拥有10年以上经验的精英团队', 'busy' => false],
        ['name' => 'Ghost Protocol', 'desc' => '数据恢复与取证分析专家，处理过数百个复杂案例', 'busy' => false],
        ['name' => 'Zero Day Labs', 'desc' => '0day漏洞研究与开发，提供高级定制化安全解决方案', 'busy' => true],
        ['name' => 'Phantom Squad', 'desc' => '匿名通信与隐私保护专家，保障您的数字身份安全', 'busy' => false],
    ];
}
$autoReleaseHours = intval(getSetting('auto_release_hours', '72'));
$footerContent = getSetting('footer_content', '');
$footerCustom = getSetting('footer_custom', false);

$pageTitle = '管理后台';
$active = 'admin';
$BASE = '../';
require '../inc/header.php';
?>
<main class="main container" style="max-width:1200px;">
    <div class="page-head reveal">
        <h1 class="page-title"><i class="fas fa-user-shield" style="margin-right:10px;color:var(--primary);"></i>管理后台</h1>
        <span class="badge badge-primary"><i class="fas fa-crown"></i> 管理员</span>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error reveal"><i class="fas fa-circle-exclamation" style="margin-top:2px;"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success reveal"><i class="fas fa-check" style="margin-top:2px;"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="order-tabs reveal">
        <a class="order-tab <?php echo $tab === 'dashboard' ? 'active' : ''; ?>" href="?tab=dashboard"><i class="fas fa-gauge-high"></i> <?php echo L('admin_dashboard'); ?></a>
        <a class="order-tab <?php echo $tab === 'services' ? 'active' : ''; ?>" href="?tab=services"><i class="fas fa-toolbox"></i> <?php echo L('admin_services'); ?></a>
        <a class="order-tab <?php echo $tab === 'forum' ? 'active' : ''; ?>" href="?tab=forum"><i class="fas fa-comments"></i> <?php echo L('admin_forum'); ?></a>
        <a class="order-tab <?php echo $tab === 'products' ? 'active' : ''; ?>" href="?tab=products"><i class="fas fa-box"></i> <?php echo L('admin_products'); ?></a>
        <a class="order-tab <?php echo $tab === 'orders' ? 'active' : ''; ?>" href="?tab=orders"><i class="fas fa-receipt"></i> <?php echo L('admin_orders'); ?></a>
        <a class="order-tab <?php echo $tab === 'deposits' ? 'active' : ''; ?>" href="?tab=deposits"><i class="fas fa-coins"></i> <?php echo L('admin_deposits'); ?> <span class="badge badge-warning" style="font-size:10px;padding:1px 7px;"><?php echo $statPendingDeposits; ?></span></a>
        <a class="order-tab <?php echo $tab === 'addresses' ? 'active' : ''; ?>" href="?tab=addresses"><i class="fas fa-wallet"></i> <?php echo L('admin_addresses'); ?></a>
        <a class="order-tab <?php echo $tab === 'announcements' ? 'active' : ''; ?>" href="?tab=announcements"><i class="fas fa-bullhorn"></i> <?php echo L('admin_announcements'); ?></a>
        <a class="order-tab <?php echo $tab === 'disputes' ? 'active' : ''; ?>" href="?tab=disputes"><i class="fas fa-gavel"></i> <?php echo L('admin_disputes'); ?> <span class="badge badge-warning" style="font-size:10px;padding:1px 7px;"><?php echo $statOpenDisputes; ?></span></a>
        <a class="order-tab <?php echo $tab === 'settings' ? 'active' : ''; ?>" href="?tab=settings"><i class="fas fa-cog"></i> <?php echo L('admin_settings'); ?></a>
    </div>

    <?php if ($tab === 'dashboard'): ?>
        <div class="stat-grid">
            <div class="lg-card stat-card reveal"><div class="stat-icon" style="--c:#58a6ff;"><i class="fas fa-users"></i></div><div class="stat-info"><h4>注册用户</h4><p><span data-count="<?php echo $statUsers; ?>">0</span></p></div></div>
            <div class="lg-card stat-card reveal"><div class="stat-icon" style="--c:#3df65a;"><i class="fas fa-box"></i></div><div class="stat-info"><h4>商品总数</h4><p><span data-count="<?php echo $statProducts; ?>">0</span></p></div></div>
            <div class="lg-card stat-card reveal"><div class="stat-icon" style="--c:#e3b341;"><i class="fas fa-receipt"></i></div><div class="stat-info"><h4>订单总数</h4><p><span data-count="<?php echo $statOrders; ?>">0</span></p></div></div>
            <div class="lg-card stat-card reveal"><div class="stat-icon" style="--c:#f47067;"><i class="fas fa-coins"></i></div><div class="stat-info"><h4>待处理资金</h4><p><span data-count="<?php echo $statPendingDeposits; ?>">0</span></p></div></div>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'services'): ?>
        <form method="POST" action="?tab=services" class="lg-card card reveal" style="padding:24px;">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="save_teams">
            <div class="card-head"><h3><i class="fas fa-user-secret"></i> 黑客团队展示配置</h3></div>
            <p class="text-muted" style="margin:6px 0 18px;">修改团队名称与忙碌状态，前台「隐世黑客团队」页面实时生效。</p>
            <?php foreach ($teams as $i => $t): ?>
                <div class="sell-cols" style="grid-template-columns:1fr;gap:8px;margin-bottom:16px;padding:16px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--card-bg);">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">团队 #<?php echo $i + 1; ?> 名称</label>
                            <input type="text" class="form-control" name="name[<?php echo $i; ?>]" value="<?php echo htmlspecialchars($t['name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group" style="display:flex;align-items:flex-end;gap:10px;">
                            <label class="checkbox-wrap" style="margin-bottom:10px;">
                                <input type="checkbox" name="busy[<?php echo $i; ?>]" value="1" <?php echo !empty($t['busy']) ? 'checked' : ''; ?>> 忙碌中
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">简介</label>
                        <input type="text" class="form-control" name="desc[<?php echo $i; ?>]" value="<?php echo htmlspecialchars($t['desc'] ?? ''); ?>">
                    </div>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> 保存团队配置</button>
        </form>

        <form method="POST" action="?tab=services" class="lg-card card reveal" style="padding:24px;margin-top:18px;">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="save_auto_release">
            <div class="card-head"><h3><i class="fas fa-clock-rotate-left"></i> 订单自动放款期限</h3></div>
            <p class="text-muted" style="margin:6px 0 14px;">卖家发货后超过该期限（小时）未确认收货，系统将自动放款给卖家。</p>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">期限（小时）</label>
                    <input type="number" class="form-control" name="auto_release_hours" value="<?php echo $autoReleaseHours; ?>" min="1" style="max-width:180px;" required>
                </div>
                <div class="form-group" style="align-self:flex-end;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> 保存</button>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($tab === 'settings'): ?>
        <form method="POST" action="?tab=settings" class="lg-card card reveal" style="padding:24px;">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="save_footer">
            <div class="card-head"><h3><i class="fas fa-edit"></i> 自定义页脚</h3></div>
            <p class="text-muted" style="margin:6px 0 14px;">自定义网站底部内容，支持 HTML。保存后全站页脚实时生效。</p>
            <div class="form-group">
                <label class="form-label">页脚 HTML 内容</label>
                <textarea class="form-control" name="footer_content" placeholder="输入页脚 HTML 内容，例如：<br><p style='text-align:center;color:var(--text-subtle);'>© 2025 基地 | 匿名安全交易平台</p><br><div style='text-align:center;'><a href='#'>联系我们</a> · <a href='#'>服务条款</a> · <a href='#'>隐私政策</a></div>" rows="6" style="font-family:monospace;"><?php echo htmlspecialchars($footerContent); ?></textarea>
            </div>
            <div class="form-group">
                <label class="checkbox-wrap">
                    <input type="checkbox" name="footer_custom" value="1" <?php echo $footerCustom ? 'checked' : ''; ?>> 启用自定义页脚
                </label>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> 保存页脚设置</button>
        </form>
    <?php endif; ?>

    <?php if ($tab === 'forum'):
        $fTotal = (int)(forumQuery("SELECT COUNT(*) AS c FROM forum_posts")[0]['c'] ?? 0);
        $fPosts = forumQuery("SELECT fp.id, fp.title, fp.created_at, fu.username, fc.name as cat
            FROM forum_posts fp JOIN maindb.users fu ON fp.user_id = fu.id
            JOIN forum_categories fc ON fp.category_id = fc.id
            ORDER BY fp.id DESC LIMIT :lim OFFSET :off",
            ['lim' => $perPage, 'off' => $offset]);
        $uTotal = (int)(dbGetRow("SELECT COUNT(*) AS c FROM users")['c'] ?? 0);
        $fUsers = dbQuery("SELECT id, username, nick, banned, created_at FROM users ORDER BY id DESC LIMIT :lim OFFSET :off", [':lim' => $perPage, ':off' => $offset]);
        if (!is_array($fUsers)) $fUsers = [];
    ?>
        <div class="sell-cols" style="grid-template-columns:1fr;">
            <div class="lg-card card reveal" style="padding:20px;">
                <div class="card-head"><h3><i class="fas fa-trash-can"></i> 帖子管理（最近 <?php echo $perPage; ?> 条 / 共 <?php echo $fTotal; ?> 条）</h3></div>
                <?php if (empty($fPosts)): ?>
                    <div class="empty-state" style="padding:24px 12px;"><i class="fas fa-inbox"></i><p>暂无帖子</p></div>
                <?php else: ?>
                    <?php foreach ($fPosts as $p): ?>
                        <div class="list-row" style="margin-bottom:8px;">
                            <div class="list-main">
                                <strong><?php echo htmlspecialchars($p['title']); ?></strong>
                                <span class="text-muted"><?php echo htmlspecialchars($p['username']); ?> · <?php echo htmlspecialchars($p['cat']); ?> · <?php echo htmlspecialchars($p['created_at']); ?></span>
                            </div>
                            <form method="POST" action="?tab=forum" onsubmit="return confirm('确定删除该帖？');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="forum_delete_post">
                                <input type="hidden" name="post_id" value="<?php echo $p['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i> 删除</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php echo adminPager($fTotal, $page, $perPage, 'forum'); ?>
            </div>

            <div class="lg-card card reveal" style="padding:20px;margin-top:18px;">
                <div class="card-head"><h3><i class="fas fa-user-slash"></i> 用户封禁管理（全站账号）</h3></div>
                <?php if (empty($fUsers)): ?>
                    <div class="empty-state" style="padding:24px 12px;"><i class="fas fa-user-slash"></i><p>暂无用户</p></div>
                <?php else: ?>
                    <?php foreach ($fUsers as $u): ?>
                        <div class="list-row" style="margin-bottom:8px;">
                            <div class="list-main">
                                <strong><?php echo htmlspecialchars($u['username']); ?></strong>
                                <span class="text-muted"><?php echo $u['nick'] !== '' ? htmlspecialchars($u['nick']) . ' · ' : ''; ?>注册于 <?php echo date('Y-m-d', (int)$u['created_at']); ?></span>
                            </div>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <span class="badge badge-<?php echo intval($u['banned']) === 1 ? 'error' : 'success'; ?>"><?php echo intval($u['banned']) === 1 ? '已封禁' : '正常'; ?></span>
                                <?php if (intval($u['banned']) === 1): ?>
                                    <form method="POST" action="?tab=forum">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="action" value="forum_unban">
                                        <input type="hidden" name="forum_uid" value="<?php echo $u['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-unlock"></i> 解封</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="?tab=forum" onsubmit="return confirm('确定封禁该用户？');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="action" value="forum_ban">
                                        <input type="hidden" name="forum_uid" value="<?php echo $u['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-ban"></i> 封禁</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php echo adminPager($uTotal, $page, $perPage, 'forum'); ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'products'):
        $pTotal = (int)(dbGetRow("SELECT COUNT(*) AS c FROM products")['c'] ?? 0);
        $products = dbQuery("SELECT p.*, u.username as seller FROM products p JOIN users u ON p.seller_id = u.id ORDER BY p.id DESC LIMIT :lim OFFSET :off", [':lim' => $perPage, ':off' => $offset]);
        if (!is_array($products)) $products = [];
    ?>
        <div class="lg-card card reveal" style="padding:20px;">
            <div class="card-head"><h3><i class="fas fa-box"></i> 商品上架 / 下架管理（共 <?php echo $pTotal; ?> 件）</h3></div>
            <?php if (empty($products)): ?>
                <div class="empty-state" style="padding:24px 12px;"><i class="fas fa-box"></i><p>暂无商品</p></div>
            <?php else: ?>
                <?php foreach ($products as $p): ?>
                    <?php $isActive = $p['status'] === 'active'; ?>
                    <div class="list-row" style="margin-bottom:8px;">
                        <div class="list-main">
                            <strong><?php echo htmlspecialchars($p['name']); ?></strong>
                            <span class="text-muted"><?php echo htmlspecialchars($p['seller']); ?> · $<?php echo number_format($p['price'], 2); ?> · 库存 <?php echo $p['stock']; ?></span>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <span class="badge badge-<?php echo $isActive ? 'success' : 'warning'; ?>"><?php echo $isActive ? L('active') : L('inactive'); ?></span>
                            <?php if ($isActive): ?>
                                <form method="POST" action="?tab=products">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="action" value="product_down">
                                    <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-arrow-down"></i> 下架</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="?tab=products">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="action" value="product_up">
                                    <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-arrow-up"></i> 上架</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php echo adminPager($pTotal, $page, $perPage, 'products'); ?>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'orders'):
        $oTotal = (int)(dbGetRow("SELECT COUNT(*) AS c FROM orders")['c'] ?? 0);
        $orders = dbQuery("SELECT o.*, p.name as product_name, ub.username as buyer_name, us.username as seller_name
            FROM orders o
            JOIN products p ON o.product_id = p.id
            JOIN users ub ON o.buyer_id = ub.id
            JOIN users us ON o.seller_id = us.id
            ORDER BY o.id DESC LIMIT :lim OFFSET :off", [':lim' => $perPage, ':off' => $offset]);
        if (!is_array($orders)) $orders = [];
        $statusMeta = [
            'pending' => ['label' => '待处理', 'cls' => 'warning'],
            'paid' => ['label' => '已付款（托管中）', 'cls' => 'info'],
            'shipped' => ['label' => '已发货', 'cls' => 'primary'],
            'completed' => ['label' => '已放款', 'cls' => 'success'],
            'cancelled' => ['label' => '已取消', 'cls' => 'error'],
        ];
    ?>
        <div class="lg-card card reveal" style="padding:20px;">
            <div class="card-head"><h3><i class="fas fa-receipt"></i> 全部订单（共 <?php echo $oTotal; ?> 笔）</h3></div>
            <?php if (empty($orders)): ?>
                <div class="empty-state" style="padding:24px 12px;"><i class="fas fa-receipt"></i><p>暂无订单</p></div>
            <?php else: ?>
                <?php foreach ($orders as $o): ?>
                    <?php $m = $statusMeta[$o['status']] ?? ['label' => $o['status'], 'cls' => 'info']; ?>
                    <div class="list-row" style="margin-bottom:8px;">
                        <div class="list-main">
                            <strong><?php echo htmlspecialchars($o['order_no']); ?></strong>
                            <span class="text-muted"><?php echo htmlspecialchars($o['product_name']); ?> · 买家 <?php echo htmlspecialchars($o['buyer_name']); ?> → 卖家 <?php echo htmlspecialchars($o['seller_name']); ?> · $<?php echo number_format($o['total_price'], 2); ?> · <?php echo htmlspecialchars($o['created_at']); ?></span>
                            <?php if ($o['auto_release_at']): ?><span class="text-muted" style="display:block;">自动放款: <?php echo htmlspecialchars($o['auto_release_at']); ?></span><?php endif; ?>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;flex-shrink:0;">
                            <span class="badge badge-<?php echo $m['cls']; ?>"><?php echo $m['label']; ?></span>
                            <?php if (in_array($o['status'], ['paid', 'shipped'])): ?>
                                <form method="POST" action="?tab=orders" onsubmit="return confirm('确定放款给卖家？');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="action" value="order_release">
                                    <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-hand-holding-dollar"></i> 放款</button>
                                </form>
                                <form method="POST" action="?tab=orders" onsubmit="return confirm('确定取消并退款给买家？');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="action" value="order_cancel">
                                    <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-rotate-left"></i> 取消退款</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php echo adminPager($oTotal, $page, $perPage, 'orders'); ?>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'deposits'):
        $dTotal = (int)(dbGetRow("SELECT COUNT(*) AS c FROM transactions WHERE type IN ('deposit','withdraw')")['c'] ?? 0);
        $deposits = dbQuery("SELECT t.*, u.username FROM transactions t JOIN users u ON t.user_id = u.id WHERE t.type IN ('deposit','withdraw') ORDER BY t.id DESC LIMIT :lim OFFSET :off", [':lim' => $perPage, ':off' => $offset]);
        if (!is_array($deposits)) $deposits = [];
        $admCatalog = bmCryptoCatalog();
    ?>
        <div class="lg-card card reveal" style="padding:20px;">
            <div class="card-head"><h3><i class="fas fa-coins"></i> 资金管理（充值 / 提现 · 共 <?php echo $dTotal; ?> 笔）</h3></div>
            <?php if (empty($deposits)): ?>
                <div class="empty-state" style="padding:24px 12px;"><i class="fas fa-coins"></i><p>暂无记录</p></div>
            <?php else: ?>
                <?php foreach ($deposits as $t):
                    $isWd = $t['type'] === 'withdraw';
                    $curInfo = !empty($t['currency']) ? ($admCatalog[$t['currency']] ?? null) : null;
                    $cryptoStr = '';
                    if ((float)($t['amount_crypto'] ?? 0) > 0 && $curInfo) {
                        $cryptoStr = fmtCrypto($t['amount_crypto']) . ' ' . $curInfo['name'];
                    }
                ?>
                    <div class="list-row" style="margin-bottom:8px;align-items:flex-start;">
                        <div class="list-main">
                            <strong>
                                <span class="badge badge-<?php echo $isWd ? 'error' : 'success'; ?>" style="margin-right:6px;"><?php echo $isWd ? '提现' : '充值'; ?></span>
                                <?php echo htmlspecialchars($t['username']); ?> <small class="text-muted">#<?php echo (int)$t['user_id']; ?></small>
                            </strong>
                            <span class="text-muted">
                                <?php echo $cryptoStr ? $cryptoStr . ' ≈ ' : ''; ?>$<?php echo number_format((float)$t['amount'], 2); ?>
                                <?php if ($curInfo): ?> · <?php echo htmlspecialchars($curInfo['chain']); ?><?php endif; ?>
                                <?php if (!empty($t['fee']) && (float)$t['fee'] > 0): ?> · 手续费 $<?php echo number_format((float)$t['fee'], 2); ?><?php endif; ?>
                                · <?php echo htmlspecialchars($t['created_at']); ?>
                            </span>
                            <?php if (!empty($t['tx_hash'])): ?><span class="text-muted text-small" style="word-break:break-all;">TxID: <?php echo htmlspecialchars($t['tx_hash']); ?></span><?php endif; ?>
                            <?php if (!empty($t['address'])): ?><span class="text-muted text-small" style="word-break:break-all;">地址: <?php echo htmlspecialchars($t['address']); ?></span><?php endif; ?>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end;">
                            <span class="badge badge-<?php echo $t['status'] === 'completed' ? 'success' : ($t['status'] === 'cancelled' ? 'error' : 'warning'); ?>"><?php echo $t['status'] === 'completed' ? '已完成' : ($t['status'] === 'cancelled' ? '已驳回' : '待处理'); ?></span>
                            <?php if ($t['status'] === 'pending' && !$isWd): ?>
                                <form method="POST" action="?tab=deposits" onsubmit="return confirm('确认到账 $<?php echo number_format((float)$t['amount'], 2); ?>？');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="action" value="deposit_approve">
                                    <input type="hidden" name="tx_id" value="<?php echo $t['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-check"></i> 到账</button>
                                </form>
                                <form method="POST" action="?tab=deposits">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="action" value="deposit_reject">
                                    <input type="hidden" name="tx_id" value="<?php echo $t['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-xmark"></i> 驳回</button>
                                </form>
                            <?php elseif ($t['status'] === 'pending' && $isWd): ?>
                                <form method="POST" action="?tab=deposits" onsubmit="return confirm('确认已向该地址打款 $<?php echo number_format((float)$t['amount'], 2); ?>？');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="action" value="withdraw_approve">
                                    <input type="hidden" name="tx_id" value="<?php echo $t['id']; ?>">
                                    <input type="text" name="out_tx_hash" placeholder="打款 TxID（选填）" style="width:150px;padding:4px 8px;font-size:12px;" class="form-control">
                                    <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-check"></i> 已打款</button>
                                </form>
                                <form method="POST" action="?tab=deposits" onsubmit="return confirm('驳回将退回冻结余额，确认？');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="action" value="withdraw_reject">
                                    <input type="hidden" name="tx_id" value="<?php echo $t['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-xmark"></i> 驳回退款</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php echo adminPager($dTotal, $page, $perPage, 'deposits'); ?>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'addresses'):
        $accounts = dbQuery("SELECT * FROM deposit_accounts ORDER BY id");
        if (!is_array($accounts)) $accounts = [];
        $admCatalog = bmCryptoCatalog();
    ?>
        <div class="lg-card card reveal" style="padding:20px;">
            <div class="card-head"><h3><i class="fas fa-wallet"></i> 充值收款地址管理</h3></div>
            <p class="text-muted" style="margin-bottom:16px;">维护各币种/网络的收款地址；停用后用户端将无法选择该渠道。上线前请务必替换占位地址为真实地址。</p>
            <?php foreach ($accounts as $a):
                $c = $admCatalog[$a['currency_key']] ?? null;
            ?>
                <form method="POST" action="?tab=addresses" class="list-row" style="margin-bottom:10px;flex-wrap:wrap;gap:10px;cursor:default;">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="address_save">
                    <input type="hidden" name="account_id" value="<?php echo $a['id']; ?>">
                    <div style="min-width:170px;">
                        <strong><?php echo $c ? htmlspecialchars($c['name'] . ' — ' . $c['chain']) : htmlspecialchars($a['currency_key']); ?></strong>
                        <div class="text-muted text-small">最低: <?php echo $c ? fmtCrypto($c['min']) . ' ' . $c['name'] : '-'; ?></div>
                    </div>
                    <input type="text" name="address" value="<?php echo htmlspecialchars($a['address']); ?>" class="form-control" style="flex:1;min-width:260px;font-size:12px;" placeholder="收款地址">
                    <label style="display:flex;align-items:center;gap:6px;white-space:nowrap;"><input type="checkbox" name="is_active"<?php echo intval($a['is_active']) === 1 ? ' checked' : ''; ?>> 启用</label>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-floppy-disk"></i> 保存</button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'announcements'):
        $aTotal = (int)(dbGetRow("SELECT COUNT(*) AS c FROM announcements")['c'] ?? 0);
        $anns = dbQuery("SELECT * FROM announcements ORDER BY id DESC LIMIT :lim OFFSET :off", [':lim' => $perPage, ':off' => $offset]);
        if (!is_array($anns)) $anns = [];
    ?>
        <form method="POST" action="?tab=announcements" class="lg-card card reveal" style="padding:20px;">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="announce_add">
            <div class="card-head"><h3><i class="fas fa-bullhorn"></i> 发布公告</h3></div>
            <div class="form-group">
                <label class="form-label">标题</label>
                <input type="text" class="form-control" name="title" required>
            </div>
            <div class="form-group">
                <label class="form-label">内容</label>
                <textarea class="form-control" name="content" rows="3" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> 发布</button>
        </form>

        <div class="lg-card card reveal" style="padding:20px;margin-top:18px;">
            <div class="card-head"><h3><i class="fas fa-list"></i> 已发布公告（共 <?php echo $aTotal; ?> 条）</h3></div>
            <?php if (empty($anns)): ?>
                <div class="empty-state" style="padding:24px 12px;"><i class="fas fa-bullhorn"></i><p>暂无公告</p></div>
            <?php else: ?>
                <?php foreach ($anns as $a): ?>
                    <div class="list-row" style="margin-bottom:8px;">
                        <div class="list-main">
                            <strong><?php echo htmlspecialchars($a['title']); ?></strong>
                            <span class="text-muted"><?php echo htmlspecialchars($a['content']); ?> · <?php echo htmlspecialchars($a['created_at']); ?></span>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;flex-shrink:0;">
                            <span class="badge badge-<?php echo intval($a['is_active']) === 1 ? 'success' : 'muted'; ?>"><?php echo intval($a['is_active']) === 1 ? '显示中' : '已隐藏'; ?></span>
                            <?php if (intval($a['is_active']) === 1): ?>
                                <form method="POST" action="?tab=announcements">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="action" value="announce_off">
                                    <input type="hidden" name="announce_id" value="<?php echo $a['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-ghost"><i class="fas fa-eye-slash"></i> 隐藏</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="?tab=announcements">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="action" value="announce_on">
                                    <input type="hidden" name="announce_id" value="<?php echo $a['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-ghost"><i class="fas fa-eye"></i> 显示</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" action="?tab=announcements" onsubmit="return confirm('确定删除该公告？');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="announce_delete">
                                <input type="hidden" name="announce_id" value="<?php echo $a['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php echo adminPager($aTotal, $page, $perPage, 'announcements'); ?>
        </div>
     <?php endif; ?>

     <?php if ($tab === 'disputes'):
         $openDisputes = dbQuery("SELECT d.*, o.total_price, p.name as product_name,
         u.username as applicant_no, a.username as arbiter_no
         FROM disputes d JOIN orders o ON d.order_id = o.id JOIN products p ON o.product_id = p.id
         JOIN users u ON d.applicant_id = u.id
         LEFT JOIN users a ON d.assigned_to = a.id
         WHERE d.status IN ('open','investigating') ORDER BY d.created_at ASC");
         if (!is_array($openDisputes)) $openDisputes = [];
         $rTotal = (int)(dbGetRow("SELECT COUNT(*) AS c FROM disputes WHERE status = 'resolved' OR status = 'rejected'")['c'] ?? 0);
         $resolvedDisputes = dbQuery("SELECT d.*, o.total_price, p.name as product_name, u.username as applicant_no, a.username as arbiter_no
             FROM disputes d JOIN orders o ON d.order_id = o.id JOIN products p ON o.product_id = p.id
             JOIN users u ON d.applicant_id = u.id
             LEFT JOIN users a ON d.assigned_to = a.id
             WHERE d.status = 'resolved' OR d.status = 'rejected' ORDER BY d.resolved_at DESC LIMIT :lim OFFSET :off", [':lim' => $perPage, ':off' => $offset]);
         if (!is_array($resolvedDisputes)) $resolvedDisputes = [];
     ?>
         <div class="lg-card card reveal" style="padding:20px;">
             <div class="card-head"><h3><i class="fas fa-gavel"></i> 待处理仲裁 <span class="badge badge-warning"><?php echo $statOpenDisputes; ?></span></h3></div>
             <?php if (empty($openDisputes)): ?>
                 <div class="empty-state"><i class="fas fa-check-circle"></i><p>暂无待处理仲裁</p></div>
             <?php else: ?>
                 <?php foreach ($openDisputes as $d): ?>
                     <div class="list-row" style="margin-bottom:12px;align-items:flex-start;flex-wrap:wrap;gap:14px;">
                         <div class="list-main">
                             <strong>#D<?php echo $d['id']; ?> · <?php echo htmlspecialchars($d['product_name']); ?> · $<?php echo number_format($d['total_price'], 2); ?></strong>
                             <span class="text-muted"><i class="fas fa-user"></i> 申诉人 #<?php echo htmlspecialchars($d['applicant_no']); ?> · <?php echo htmlspecialchars($d['created_at']); ?></span>
                             <span class="badge badge-<?php echo $d['status'] === 'open' ? 'warning' : 'info'; ?>"><?php echo $d['status'] === 'open' ? '待认领' : '调查中'; ?></span>
                         </div>
                         <div class="list-main">
                             <p style="margin:0 0 8px;font-size:13px;"><?php echo htmlspecialchars($d['reason']); ?></p>
                         </div>
                         <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                              <a href="../market/disputes.php" class="btn btn-sm btn-primary"><i class="fas fa-gavel"></i> <?php echo L('handle_disputes') ?: '处理仲裁'; ?></a>
                         </div>
                     </div>
                 <?php endforeach; ?>
             <?php endif; ?>
         </div>

         <div class="lg-card card reveal" style="padding:20px;margin-top:18px;">
             <div class="card-head"><h3><i class="fas fa-history"></i> 仲裁历史（共 <?php echo $rTotal; ?> 条）</h3></div>
             <?php if (empty($resolvedDisputes)): ?>
                 <div class="empty-state" style="padding:24px 12px;"><i class="fas fa-history"></i><p>暂无已解决的仲裁</p></div>
             <?php else: ?>
                 <?php foreach ($resolvedDisputes as $d): ?>
                     <div class="list-row" style="margin-bottom:8px;">
                         <div class="list-main">
                             <strong>#D<?php echo $d['id']; ?> · <?php echo htmlspecialchars($d['product_name']); ?> · <?php echo $d['decision'] === 'buyer_win' ? '买家胜' : ($d['decision'] === 'seller_win' ? '卖家胜' : '已驳回'); ?></strong>
                             <span class="text-muted"><?php echo htmlspecialchars($d['resolved_at']); ?> · 指派: #<?php echo $d['arbiter_no'] ?? '未分配'; ?></span>
                         </div>
                     </div>
                 <?php endforeach; ?>
             <?php endif; ?>
             <?php echo adminPager($rTotal, $page, $perPage, 'disputes'); ?>
         </div>
     <?php endif; ?>
 </main>
 <?php
 require '../inc/footer.php';
?>
