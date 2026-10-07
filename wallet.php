<?php
require_once 'security.php';
require_once 'inc/rates.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = dbGetRow("SELECT * FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
$balance = userBalance($user);
$rates = getRates();
$fiats = bmFiatList();
$catalog = bmCryptoCatalog();

$lang = getLang();
$pageTitle = L('wallet');
$active = 'wallet';
$BASE = '';
require 'inc/header.php';

/* 嵌入前端的汇率数据 */
$fiatRates = [];
foreach ($fiats as $code => $f) {
    $fiatRates[$code] = floatval($rates['fiat'][$code] ?? 1);
}
$ticker = [];
foreach ($catalog as $key => $c) {
    $r = bmCryptoUsdRate($key);
    if ($r > 0) $ticker[] = ['name' => $c['name'], 'chain' => $c['chain'], 'rate' => $r, 'icon' => $c['icon']];
}

$transactions = dbQuery("SELECT * FROM transactions WHERE user_id = :user_id ORDER BY id DESC LIMIT 20",
    [':user_id' => $_SESSION['user_id']]);
if (!is_array($transactions)) $transactions = [];
?>
<main class="main container">
    <div class="page-head reveal">
        <h1 class="page-title"><i class="fas fa-wallet" style="margin-right:10px;color:var(--primary);"></i><?php echo L('wallet'); ?></h1>
    </div>

    <div class="sell-cols">
        <div>
            <div class="lg-card card reveal">
                <div class="balance-display">
                    <h3><?php echo L('total_balance'); ?></h3>
                    <div class="amount"><span id="bal-symbol">$</span><span id="bal-value" data-usd="<?php echo htmlspecialchars((string)round($balance, 6)); ?>"><?php echo number_format($balance, 2); ?></span></div>
                    <small class="text-muted" style="margin-top:4px;display:block;">
                        <?php echo $lang === 'zh' ? '基准' : 'Base'; ?>: $<?php echo number_format($balance, 2); ?> USD
                        <span id="bal-rate-note"></span>
                    </small>
                </div>

                <div class="filter-chips" style="gap:8px;margin-bottom:20px;" id="fiat-chips">
                    <?php foreach ($fiats as $code => $f): ?>
                        <button type="button" class="chip fiat-chip<?php echo $code === 'usd' ? ' active' : ''; ?>" data-code="<?php echo $code; ?>">
                            <?php echo $lang === 'zh' ? $f[1] : $f[2]; ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:22px;">
                    <a href="market/deposit.php" class="btn btn-primary btn-lg"><i class="fas fa-circle-plus"></i> <?php echo L('deposit'); ?></a>
                    <a href="market/withdraw.php" class="btn btn-ghost btn-lg"><i class="fas fa-arrow-right-from-bracket"></i> <?php echo L('withdraw'); ?></a>
                </div>
            </div>

            <div class="lg-card card reveal">
                <div class="card-head"><h3><i class="fas fa-chart-line"></i> <?php echo $lang === 'zh' ? '币种行情' : 'Market Rates'; ?></h3></div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;">
                    <?php foreach ($ticker as $t): ?>
                        <div style="padding:10px 12px;border-radius:12px;background:var(--surface-2,rgba(128,128,128,.08));">
                            <div class="text-small text-muted"><i class="<?php echo $t['icon']; ?>" style="color:var(--primary);margin-right:5px;"></i><?php echo $t['name']; ?> <span style="opacity:.6;"><?php echo explode(' ', $t['chain'])[0]; ?></span></div>
                            <b class="ticker-price" data-rate="<?php echo htmlspecialchars((string)$t['rate']); ?>">$<?php echo fmtRate($t['rate']); ?></b>
                        </div>
                    <?php endforeach; ?>
                </div>
                <small class="text-subtle" style="display:block;margin-top:10px;"><i class="fas fa-rotate"></i> <?php echo $lang === 'zh' ? '实时汇率 · 每 10 分钟更新' : 'Live rates · refreshed every 10 min'; ?></small>
            </div>

            <div class="lg-card card reveal">
                <div class="card-head"><h3><i class="fas fa-shield-halved"></i> <?php echo L('security_tips'); ?></h3></div>
                <ul style="list-style:none;margin:0;padding:0;display:grid;gap:10px;">
                    <li style="display:flex;gap:10px;align-items:flex-start;"><i class="fas fa-lock" style="color:var(--primary);margin-top:3px;"></i><span><?php echo L('tip_https'); ?></span></li>
                    <li style="display:flex;gap:10px;align-items:flex-start;"><i class="fas fa-key" style="color:var(--primary);margin-top:3px;"></i><span><?php echo L('tip_password'); ?></span></li>
                    <li style="display:flex;gap:10px;align-items:flex-start;"><i class="fas fa-globe" style="color:var(--primary);margin-top:3px;"></i><span><?php echo L('tip_vpn'); ?></span></li>
                </ul>
            </div>
        </div>

        <div class="lg-card card reveal">
            <div class="card-head"><h3><i class="fas fa-clock-rotate-left"></i> <?php echo L('transaction_history'); ?></h3></div>
            <?php if (empty($transactions)): ?>
                <p class="text-muted"><?php echo L('no_transactions'); ?></p>
            <?php else: ?>
                <?php foreach ($transactions as $tx):
                    $isIn = $tx['type'] === 'deposit';
                    $label = $tx['type'] === 'deposit' ? L('deposit')
                        : ($tx['type'] === 'withdraw' ? L('withdraw') : $tx['type']);
                    $curName = '';
                    if (!empty($tx['currency']) && isset($catalog[$tx['currency']])) {
                        $curName = $catalog[$tx['currency']]['name'];
                    }
                    ?>
                    <div class="list-row" style="margin-bottom:10px;cursor:default;">
                        <div class="list-main">
                            <strong><?php echo ucfirst($label); ?><?php echo $curName ? ' · ' . $curName : ''; ?> <?php echo !empty($tx['chain']) ? '<small class="text-muted">' . htmlspecialchars($tx['chain']) . '</small>' : ''; ?></strong>
                            <span class="text-muted text-small"><?php echo htmlspecialchars($tx['created_at']); ?>
                                <?php if (!empty($tx['tx_hash'])): ?>&nbsp;·&nbsp; TxID: <?php echo htmlspecialchars(substr($tx['tx_hash'], 0, 14)); ?>…<?php endif; ?>
                                <?php if ($tx['type'] === 'withdraw' && !empty($tx['address'])): ?>&nbsp;·&nbsp; → <?php echo htmlspecialchars(substr($tx['address'], 0, 16)); ?>…<?php endif; ?>
                            </span>
                        </div>
                        <div style="text-align:right;">
                            <div class="tx-amount" style="color:<?php echo $isIn ? 'var(--success)' : 'var(--error)'; ?>;">
                                <?php echo $isIn ? '+' : '-'; ?>$<?php echo number_format((float)$tx['amount'], 2); ?>
                            </div>
                            <?php if ((float)($tx['amount_crypto'] ?? 0) > 0): ?>
                                <small class="text-muted"><?php echo fmtCrypto($tx['amount_crypto']); ?> <?php echo $curName; ?></small><br>
                            <?php endif; ?>
                            <span class="badge badge-<?php echo $tx['status'] === 'completed' ? 'success' : ($tx['status'] === 'cancelled' ? 'error' : 'warning'); ?>"><?php echo L('status_' . $tx['status']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</main>
<script>
    /* 法币汇率与符号（服务端注入，客户端即时换算） */
    var FIAT = <?php echo json_encode([
        'list' => array_map(function ($code, $f) use ($lang) {
            return ['code' => $code, 'symbol' => $f[0], 'label' => $lang === 'zh' ? $f[1] : $f[2]];
        }, array_keys($fiats), $fiats),
        'rates' => $fiatRates,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var balUsd = parseFloat(document.getElementById('bal-value').getAttribute('data-usd')) || 0;
    var curFiat = 'usd';
    try { curFiat = localStorage.getItem('bm-fiat') || 'usd'; } catch (e) {}
    if (!FIAT.rates[curFiat]) curFiat = 'usd';

    function fmtBal(v) {
        return v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function applyFiat() {
        var f = null;
        for (var i = 0; i < FIAT.list.length; i++) if (FIAT.list[i].code === curFiat) f = FIAT.list[i];
        if (!f) return;
        var rate = FIAT.rates[curFiat] || 1;
        var v = balUsd * rate;
        document.getElementById('bal-symbol').textContent = f.symbol;
        document.getElementById('bal-value').textContent = fmtBal(curFiat === 'jpy' || curFiat === 'krw' ? Math.round(v) : v);
        document.getElementById('bal-rate-note').textContent =
            '(1 USD ≈ ' + f.symbol + (rate >= 100 ? Math.round(rate) : rate.toFixed(rate >= 5 ? 2 : 4)) + ')';
        document.querySelectorAll('.fiat-chip').forEach(function (c) {
            c.classList.toggle('active', c.getAttribute('data-code') === curFiat);
        });
        document.querySelectorAll('.ticker-price').forEach(function (el) {
            var r = parseFloat(el.getAttribute('data-rate')) * rate;
            el.textContent = f.symbol + (r >= 100 ? Math.round(r).toLocaleString() : r >= 5 ? r.toFixed(2) : r.toFixed(4).replace(/0+$/, '').replace(/\.$/, ''));
        });
    }

    document.querySelectorAll('.fiat-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            curFiat = this.getAttribute('data-code');
            try { localStorage.setItem('bm-fiat', curFiat); } catch (e) {}
            applyFiat();
        });
    });

    applyFiat();
</script>
<?php
require 'inc/footer.php';
