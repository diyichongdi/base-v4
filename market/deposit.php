<?php
require_once '../security.php';
require_once '../inc/rates.php';
require_once 'init.php';

if (!isLoggedIn()) {
    header('Location: ../login.php?next=' . urlencode(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?')));
    exit;
}

$user = dbGetRow("SELECT * FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);

$error = '';
$success = '';
$catalog = bmCryptoCatalog();
$rates = getRates();

/* 收款地址 */
$accounts = dbQuery("SELECT * FROM deposit_accounts");
$addrMap = [];
if (is_array($accounts)) {
    foreach ($accounts as $a) $addrMap[$a['currency_key']] = $a;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = L('csrf_invalid');
    } else {
        $ckey = (string)($_POST['currency_key'] ?? '');
        $amountCrypto = floatval($_POST['amount_crypto'] ?? 0);
        $txHash = trim($_POST['tx_hash'] ?? '');

        $acc = $addrMap[$ckey] ?? null;
        $cat = $catalog[$ckey] ?? null;

        if (!$cat || !$acc || intval($acc['is_active']) !== 1) {
            $error = getLang() === 'zh' ? '请选择有效的充值方式' : 'Please select a valid deposit method';
        } elseif ($amountCrypto < floatval($cat['min'])) {
            $error = (getLang() === 'zh' ? '最低充值 ' : 'Minimum deposit ') . fmtCrypto(floatval($cat['min'])) . ' ' . $cat['name'];
        } elseif (strlen($txHash) < 10) {
            $error = getLang() === 'zh' ? '请填写正确的交易哈希（TxID）' : 'Please enter a valid transaction hash (TxID)';
        } elseif (dbGetRow("SELECT id FROM transactions WHERE tx_hash = :hash", [':hash' => $txHash])) {
            $error = getLang() === 'zh' ? '该交易哈希已存在（处理中或已到账），请勿重复提交' : 'Transaction hash already exists (pending or settled), do not resubmit';
        } else {
            $rate = bmCryptoUsdRate($ckey);
            if ($rate <= 0) {
                $error = getLang() === 'zh' ? '汇率获取失败，请稍后重试' : 'Rate unavailable, please retry later';
            } else {
                $usd = round($amountCrypto * $rate, 2);
                dbQuery("INSERT INTO transactions (user_id, type, amount, status, tx_hash, address, currency, chain, amount_crypto, rate)
                    VALUES (:uid, 'deposit', :amount, 'pending', :hash, :addr, :cur, :chain, :ac, :rate)", [
                    ':uid' => $_SESSION['user_id'],
                    ':amount' => $usd,
                    ':hash' => $txHash,
                    ':addr' => $acc['address'],
                    ':cur' => $ckey,
                    ':chain' => $cat['chain'],
                    ':ac' => $amountCrypto,
                    ':rate' => $rate,
                ]);
                $success = L('deposit_request_submitted') . (getLang() === 'zh'
                    ? '（预计到账 $' . number_format($usd, 2) . '，管理员确认后入账）'
                    : ' (~$' . number_format($usd, 2) . ', credited after admin confirmation)');
            }
        }
    }
}

$deposits = dbQuery("SELECT * FROM transactions WHERE user_id = :user_id AND type = 'deposit' ORDER BY id DESC LIMIT 10",
    [':user_id' => $_SESSION['user_id']]);
if (!is_array($deposits)) $deposits = [];

$lang = getLang();
$pageTitle = L('deposit');
$active = 'market';
$BASE = '../';
require '../inc/header.php';

/* 给前端的数据：地址/最小值/汇率 */
$jsData = [];
foreach ($catalog as $key => $c) {
    $acc = $addrMap[$key] ?? null;
    $jsData[$key] = [
        'name' => $c['name'],
        'chain' => $c['chain'],
        'min' => floatval($c['min']),
        'rate' => bmCryptoUsdRate($key),
        'address' => ($acc && intval($acc['is_active']) === 1) ? $acc['address'] : '',
    ];
}
?>
<main class="main container">
    <div class="page-head reveal">
        <h1 class="page-title"><i class="fas fa-circle-plus" style="margin-right:10px;color:var(--primary);"></i><?php echo L('deposit'); ?></h1>
    </div>

    <div class="sell-cols">
        <div class="lg-card card reveal">
            <div class="balance-display">
                <h3><?php echo L('current_balance'); ?></h3>
                <div class="amount">$<span data-count="<?php echo round(userBalance($user), 2); ?>" data-decimals="2">0.00</span></div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error" style="margin-bottom:18px;"><i class="fas fa-circle-exclamation" style="margin-top:2px;"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success" style="margin-bottom:18px;"><i class="fas fa-check" style="margin-top:2px;"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="" id="dep-form">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="currency_key" id="currency-key" value="usdt_trc20">

                <div class="form-group">
                    <label class="form-label"><?php echo $lang === 'zh' ? '选择币种 / 网络' : 'Currency / Network'; ?></label>
                    <div class="filter-chips" style="gap:8px;">
                        <?php foreach ($jsData as $key => $d): ?>
                            <button type="button" class="chip dep-chip<?php echo $key === 'usdt_trc20' ? ' active' : ''; ?>" data-key="<?php echo $key; ?>">
                                <i class="<?php echo $catalog[$key]['icon']; ?>" style="margin-right:4px;"></i><?php echo $d['name']; ?>
                                <small style="opacity:.65;margin-left:3px;"><?php echo explode(' ', $d['chain'])[0]; ?></small>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div id="dep-address-box" class="lg-card card" style="padding:16px;margin-bottom:18px;">
                    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                        <div id="dep-qr" class="dep-qr-wrap" style="width:138px;height:138px;border-radius:10px;background:#fff;display:flex;align-items:center;justify-content:center;color:#999;font-size:12px;flex-shrink:0;"></div>
                        <div style="flex:1;min-width:200px;">
                            <div class="text-muted text-small"><?php echo $lang === 'zh' ? '收款网络' : 'Network'; ?></div>
                            <strong id="dep-chain" style="display:block;margin-bottom:8px;">—</strong>
                            <div class="text-muted text-small"><?php echo $lang === 'zh' ? '收款地址' : 'Deposit address'; ?></div>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <code id="dep-address" style="font-size:12px;word-break:break-all;flex:1;">—</code>
                                <button type="button" class="btn btn-sm btn-ghost" id="dep-copy"><i class="fas fa-copy"></i></button>
                            </div>
                            <small class="text-subtle" style="display:block;margin-top:6px;"><i class="fas fa-triangle-exclamation"></i> <?php echo $lang === 'zh' ? '务必核对网络一致，转错网络资产无法找回' : 'Double-check the network — wrong-network transfers are unrecoverable'; ?></small>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo $lang === 'zh' ? '充值数量' : 'Amount'; ?> (<span id="dep-unit">USDT</span>)</label>
                    <input type="number" class="form-control" name="amount_crypto" id="amount_crypto" step="any" min="0" required>
                    <small class="text-subtle" style="display:block;margin-top:6px;">
                        <?php echo $lang === 'zh' ? '最低' : 'Min'; ?>: <span id="dep-min">10</span>
                        &nbsp;·&nbsp; <?php echo $lang === 'zh' ? '实时折算' : 'Estimate'; ?>:
                        <b id="dep-est">$0.00</b>
                        <span class="text-muted">(<span id="dep-rate">1</span> USD/单位)</span>
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo $lang === 'zh' ? '交易哈希 TxID' : 'Transaction Hash (TxID)'; ?></label>
                    <input type="text" class="form-control" name="tx_hash" placeholder="<?php echo $lang === 'zh' ? '转账完成后粘贴链上交易哈希' : 'Paste the on-chain tx hash after transfer'; ?>" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg"><i class="fas fa-coins"></i> <?php echo L('confirm_deposit'); ?></button>
                <p class="text-subtle" style="margin-top:10px;text-align:center;"><i class="fas fa-shield-halved"></i> <?php echo $lang === 'zh' ? '提交后由管理员核对链上确认数后入账' : 'Credited after admin verifies on-chain confirmations'; ?></p>
            </form>
        </div>

        <div>
            <div class="lg-card card reveal">
                <div class="card-head"><h3><i class="fas fa-chart-line"></i> <?php echo $lang === 'zh' ? '实时汇率' : 'Live Rates'; ?></h3></div>
                <div style="display:grid;gap:8px;">
                    <?php foreach ($catalog as $key => $c):
                        $r = bmCryptoUsdRate($key);
                        if ($r <= 0) continue; ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--line-weak,rgba(128,128,128,.15));">
                            <span><i class="<?php echo $c['icon']; ?>" style="color:var(--primary);width:20px;"></i> <?php echo $c['name']; ?> <small class="text-muted"><?php echo $c['chain']; ?></small></span>
                            <b>$<?php echo fmtRate($r); ?></b>
                        </div>
                    <?php endforeach; ?>
                </div>
                <small class="text-subtle" style="display:block;margin-top:10px;"><i class="fas fa-rotate"></i> <?php echo $lang === 'zh' ? '每 10 分钟自动更新' : 'Auto-refreshed every 10 min'; ?></small>
            </div>

            <div class="lg-card card reveal">
                <div class="card-head"><h3><i class="fas fa-clock-rotate-left"></i> <?php echo L('deposit_history'); ?></h3></div>
                <?php if (empty($deposits)): ?>
                    <p class="text-muted"><?php echo L('no_deposits'); ?></p>
                <?php else: ?>
                    <?php foreach ($deposits as $tx): ?>
                        <div class="list-row" style="margin-bottom:10px;cursor:default;">
                            <div class="list-main">
                                <strong><?php echo htmlspecialchars($tx['currency'] ? (($catalog[$tx['currency']]['name'] ?? '') . ' · ' . $tx['chain']) : ('#' . $tx['id'])); ?></strong>
                                <span class="text-muted text-small"><?php echo htmlspecialchars($tx['created_at']); ?><?php if (!empty($tx['tx_hash'])): ?> &nbsp;·&nbsp; TxID: <?php echo htmlspecialchars(substr($tx['tx_hash'], 0, 14)); ?>…<?php endif; ?></span>
                            </div>
                            <div style="text-align:right;">
                                <div class="tx-amount">+<?php echo $tx['amount_crypto'] > 0 ? fmtCrypto($tx['amount_crypto']) . ' ' . ($catalog[$tx['currency']]['name'] ?? '') . ' ≈ ' : ''; ?>$<?php echo number_format((float)$tx['amount'], 2); ?></div>
                                <span class="badge badge-<?php echo $tx['status'] === 'completed' ? 'success' : ($tx['status'] === 'cancelled' ? 'error' : 'warning'); ?>"><?php echo L('status_' . $tx['status']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<script src="../assets/qrcode.min.js"></script>
<script>
    var DEP = <?php echo json_encode($jsData, JSON_UNESCAPED_SLASHES); ?>;
    var depCur = 'usdt_trc20';

    function fmtUnit(n) {
        return parseFloat(n.toFixed(6)).toString();
    }

    function depRender() {
        var d = DEP[depCur];
        if (!d) return;
        document.getElementById('dep-unit').textContent = d.name;
        document.getElementById('dep-chain').textContent = d.chain;
        document.getElementById('dep-address').textContent = d.address || '<?php echo $lang === "zh" ? "该币种暂未开放" : "Not available"; ?>';
        var qrEl = document.getElementById('dep-qr');
        if (d.address && typeof QRCode !== 'undefined') {
            qrEl.innerHTML = '';
            new QRCode(qrEl, { text: d.address, width: 132, height: 132, correctLevel: QRCode.CorrectLevel.M });
        } else {
            qrEl.textContent = d.address ? 'QR' : '—';
        }
        document.getElementById('dep-min').textContent = fmtUnit(d.min);
        document.getElementById('dep-rate').textContent = d.rate > 0 ? (d.rate >= 5 ? d.rate.toFixed(2) : d.rate.toFixed(4)) : '-';
        depEstimate();
    }

    function depEstimate() {
        var d = DEP[depCur];
        var v = parseFloat(document.getElementById('amount_crypto').value || '0');
        var est = (d && d.rate > 0 && v > 0) ? v * d.rate : 0;
        document.getElementById('dep-est').textContent = '$' + est.toFixed(2);
    }

    document.querySelectorAll('.dep-chip').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.dep-chip').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            depCur = btn.getAttribute('data-key');
            document.getElementById('currency-key').value = depCur;
            depRender();
        });
    });
    document.getElementById('amount_crypto').addEventListener('input', depEstimate);
    document.getElementById('dep-copy').addEventListener('click', function () {
        var addr = DEP[depCur] && DEP[depCur].address;
        if (!addr) return;
        if (navigator.clipboard) { navigator.clipboard.writeText(addr); }
        else {
            var t = document.createElement('textarea');
            t.value = addr; document.body.appendChild(t); t.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(t);
        }
        this.innerHTML = '<i class="fas fa-check"></i>';
        var b = this;
        setTimeout(function () { b.innerHTML = '<i class="fas fa-copy"></i>'; }, 1500);
    });

    depRender();
</script>
<?php
require '../inc/footer.php';
