<?php
require_once '../security.php';
require_once '../inc/rates.php';
require_once 'init.php';

if (!isLoggedIn()) {
    header('Location: ../login.php?next=' . urlencode(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?')));
    exit;
}

$user = dbGetRow("SELECT * FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
$balance = userBalance($user);

$error = '';
$success = '';
$catalog = bmCryptoCatalog();
$lang = getLang();

/* 提现手续费率（%）与最低金额，可在后台 settings 调整 */
$feePercent = floatval(getSetting('withdraw_fee_percent', '2'));
if ($feePercent < 0 || $feePercent > 20) $feePercent = 2;
$minUsd = floatval(getSetting('withdraw_min_usd', '20'));

/* 可用币种（需有启用收款账户的对应链） */
$accounts = dbQuery("SELECT * FROM deposit_accounts WHERE is_active = 1");
$activeKeys = [];
if (is_array($accounts)) {
    foreach ($accounts as $a) $activeKeys[$a['currency_key']] = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = L('csrf_invalid');
    } else {
        $ckey = (string)($_POST['currency_key'] ?? '');
        $amountUsd = round(floatval($_POST['amount_usd'] ?? 0), 2);
        $toAddress = trim($_POST['to_address'] ?? '');
        $cat = $catalog[$ckey] ?? null;

        if (!$cat || empty($activeKeys[$ckey])) {
            $error = $lang === 'zh' ? '请选择有效的提现方式' : 'Please select a valid withdrawal method';
        } elseif ($amountUsd < $minUsd) {
            $error = ($lang === 'zh' ? '最低提现 $' : 'Minimum withdrawal $') . number_format($minUsd, 2);
        } elseif ($amountUsd > $balance) {
            $error = L('insufficient_balance');
        } elseif (!preg_match($cat['re'], $toAddress)) {
            $error = $lang === 'zh'
                ? $cat['name'] . '（' . $cat['chain'] . '）地址格式不正确，请仔细核对'
                : 'Invalid ' . $cat['name'] . ' (' . $cat['chain'] . ') address, please double-check';
        } else {
            $rate = bmCryptoUsdRate($ckey);
            if ($rate <= 0) {
                $error = $lang === 'zh' ? '汇率获取失败，请稍后重试' : 'Rate unavailable, please retry later';
            } else {
                $fee = round($amountUsd * $feePercent / 100, 2);
                $netUsd = round($amountUsd - $fee, 2);
                $cryptoAmt = $netUsd / $rate;

                /* 原子扣减余额并创建待审提现单 */
                $db = getDB();
                $db->exec('BEGIN IMMEDIATE');
                try {
                    $st = $db->prepare("SELECT balance FROM users WHERE id = :id");
                    $st->bindValue(':id', $_SESSION['user_id'], SQLITE3_INTEGER);
                    $curBal = floatval(($st->execute()->fetchArray(SQLITE3_ASSOC))['balance'] ?? 0);
                    if ($curBal < $amountUsd) {
                        $db->exec('ROLLBACK');
                        $error = L('insufficient_balance');
                    } else {
                        $st = $db->prepare("UPDATE users SET balance = balance - :a WHERE id = :id");
                        $st->bindValue(':a', $amountUsd);
                        $st->bindValue(':id', $_SESSION['user_id'], SQLITE3_INTEGER);
                        $st->execute();

                        $st = $db->prepare("INSERT INTO transactions (user_id, type, amount, status, address, currency, chain, amount_crypto, rate, fee)
                            VALUES (:uid, 'withdraw', :amount, 'pending', :addr, :cur, :chain, :ac, :rate, :fee)");
                        $st->bindValue(':uid', $_SESSION['user_id'], SQLITE3_INTEGER);
                        $st->bindValue(':amount', $amountUsd);
                        $st->bindValue(':addr', $toAddress);
                        $st->bindValue(':cur', $ckey);
                        $st->bindValue(':chain', $cat['chain']);
                        $st->bindValue(':ac', $cryptoAmt);
                        $st->bindValue(':rate', $rate);
                        $st->bindValue(':fee', $fee);
                        $st->execute();

                        $db->exec('COMMIT');
                        $success = $lang === 'zh'
                            ? '提现申请已提交，已冻结 $' . number_format($amountUsd, 2) . '；预计到账 ' . fmtCrypto($cryptoAmt) . ' ' . $cat['name'] . '（含 ' . $feePercent . '% 手续费）'
                            : 'Withdrawal submitted, $' . number_format($amountUsd, 2) . ' frozen; est. ' . fmtCrypto($cryptoAmt) . ' ' . $cat['name'] . ' (' . $feePercent . '% fee)';
                        $user = dbGetRow("SELECT * FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
                        $balance = userBalance($user);
                    }
                } catch (Exception $e) {
                    $db->exec('ROLLBACK');
                    $error = $lang === 'zh' ? '提交失败，请重试' : 'Submission failed, please retry';
                }
                $db->close();
            }
        }
    }
}

$withdrawals = dbQuery("SELECT * FROM transactions WHERE user_id = :user_id AND type = 'withdraw' ORDER BY id DESC LIMIT 10",
    [':user_id' => $_SESSION['user_id']]);
if (!is_array($withdrawals)) $withdrawals = [];

$pageTitle = L('withdraw');
$active = 'market';
$BASE = '../';
require '../inc/header.php';

$jsData = [];
foreach ($catalog as $key => $c) {
    if (empty($activeKeys[$key])) continue;
    $jsData[$key] = ['name' => $c['name'], 'chain' => $c['chain'], 'rate' => bmCryptoUsdRate($key)];
}
?>
<main class="main container">
    <div class="page-head reveal">
        <h1 class="page-title"><i class="fas fa-arrow-right-from-bracket" style="margin-right:10px;color:var(--primary);"></i><?php echo L('withdraw'); ?></h1>
    </div>

    <div class="sell-cols">
        <div class="lg-card card reveal">
            <div class="balance-display">
                <h3><?php echo L('current_balance'); ?></h3>
                <div class="amount">$<span data-count="<?php echo round($balance, 2); ?>" data-decimals="2">0.00</span></div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error" style="margin-bottom:18px;"><i class="fas fa-circle-exclamation" style="margin-top:2px;"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success" style="margin-bottom:18px;"><i class="fas fa-check" style="margin-top:2px;"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if (empty($jsData)): ?>
                <div class="empty-state"><i class="fas fa-plug-circle-xmark"></i><p><?php echo $lang === 'zh' ? '暂未开放提现渠道，请联系管理员' : 'No withdrawal channels available'; ?></p></div>
            <?php else: ?>
            <form method="POST" action="" id="wd-form">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="currency_key" id="currency-key" value="<?php echo htmlspecialchars(array_key_first($jsData)); ?>">

                <div class="form-group">
                    <label class="form-label"><?php echo $lang === 'zh' ? '提现到' : 'Withdraw to'; ?></label>
                    <select class="form-control" id="wd-select">
                        <?php foreach ($jsData as $key => $d): ?>
                            <option value="<?php echo $key; ?>"><?php echo $d['name']; ?> — <?php echo $d['chain']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo $lang === 'zh' ? '收款地址' : 'Receiving address'; ?> (<span id="wd-unit">USDT</span>)</label>
                    <input type="text" class="form-control" name="to_address" id="to_address" placeholder="<?php echo $lang === 'zh' ? '粘贴你的钱包地址，务必核对网络' : 'Paste your wallet address — verify the network'; ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo $lang === 'zh' ? '提现金额 (USD)' : 'Amount (USD)'; ?></label>
                    <input type="number" class="form-control" name="amount_usd" id="amount_usd" step="0.01" min="<?php echo $minUsd; ?>" max="<?php echo round($balance, 2); ?>" required>
                    <small class="text-subtle" style="display:block;margin-top:6px;">
                        <?php echo $lang === 'zh' ? '手续费' : 'Fee'; ?>: <span id="wd-fee"><?php echo $feePercent; ?></span>%
                        &nbsp;·&nbsp; <?php echo $lang === 'zh' ? '实际到账' : 'You receive'; ?>:
                        <b id="wd-est">—</b>
                    </small>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg"><i class="fas fa-paper-plane"></i> <?php echo L('confirm_withdraw') ?: ($lang === 'zh' ? '确认提现' : 'Confirm Withdrawal'); ?></button>
                <p class="text-subtle" style="margin-top:10px;text-align:center;"><i class="fas fa-lock"></i> <?php echo $lang === 'zh' ? '提交即冻结相应余额，管理员打款后完成；驳回将自动退回' : 'Balance is frozen on submit; refunded automatically if rejected'; ?></p>
            </form>
            <?php endif; ?>
        </div>

        <div class="lg-card card reveal">
            <div class="card-head"><h3><i class="fas fa-clock-rotate-left"></i> <?php echo $lang === 'zh' ? '提现记录' : 'Withdrawal History'; ?></h3></div>
            <?php if (empty($withdrawals)): ?>
                <div class="empty-state"><i class="fas fa-clock-rotate-left"></i><p><?php echo $lang === 'zh' ? '暂无提现记录' : 'No withdrawals yet'; ?></p></div>
            <?php else: ?>
                <?php foreach ($withdrawals as $tx): ?>
                    <div class="list-row" style="margin-bottom:10px;cursor:default;">
                        <div class="list-main">
                            <strong><?php echo htmlspecialchars(($catalog[$tx['currency']]['name'] ?? '') . ' · ' . $tx['chain']); ?></strong>
                            <span class="text-muted text-small"><?php echo htmlspecialchars($tx['created_at']); ?> &nbsp;·&nbsp; <?php echo htmlspecialchars(substr((string)$tx['address'], 0, 16)); ?>…</span>
                        </div>
                        <div style="text-align:right;">
                            <div class="tx-amount" style="color:var(--error);">-$<?php echo number_format((float)$tx['amount'], 2); ?></div>
                            <small class="text-muted"><?php echo $tx['amount_crypto'] > 0 ? fmtCrypto($tx['amount_crypto']) . ' ' . ($catalog[$tx['currency']]['name'] ?? '') : ''; ?></small>
                            <div><span class="badge badge-<?php echo $tx['status'] === 'completed' ? 'success' : ($tx['status'] === 'cancelled' ? 'error' : 'warning'); ?>"><?php echo L('status_' . $tx['status']); ?></span></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</main>
<script>
    var WD = <?php echo json_encode($jsData, JSON_UNESCAPED_SLASHES); ?>;
    var WD_FEE = <?php echo json_encode($feePercent); ?>;
    var wdCur = document.getElementById('currency-key') ? document.getElementById('currency-key').value : '';

    function wdRender() {
        var d = WD[wdCur];
        if (!d) return;
        document.getElementById('wd-unit').textContent = d.name;
        wdEstimate();
    }

    function wdEstimate() {
        var d = WD[wdCur];
        var v = parseFloat(document.getElementById('amount_usd').value || '0');
        var box = document.getElementById('wd-est');
        if (!d || !(v > 0)) { box.textContent = '—'; return; }
        var net = v * (1 - WD_FEE / 100);
        var amt = net / d.rate;
        box.textContent = (amt >= 1 ? amt.toFixed(4) : amt.toFixed(8)).replace(/\.?0+$/, '') + ' ' + d.name;
    }

    var sel = document.getElementById('wd-select');
    if (sel) {
        sel.addEventListener('change', function () {
            wdCur = this.value;
            document.getElementById('currency-key').value = wdCur;
            wdRender();
        });
        document.getElementById('amount_usd').addEventListener('input', wdEstimate);
        wdRender();
    }
</script>
<?php
require '../inc/footer.php';
