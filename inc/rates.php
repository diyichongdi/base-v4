<?php
/**
 * 加密货币目录 + 法币实时汇率（CoinGecko 免费接口，10 分钟缓存）
 * 页面用法：
 *   require_once 'rates.php';
 *   $rates = getRates();                      // ['t'=>ts,'fiat'=>[usd=>1,cny=>..],'crypto'=>[tether=>1,...]]
 *   bmCryptoUsdRate('usdt_trc20')             // 1 USDT ≈ $0.999
 *   bmConvert(100, 'cny')                     // 100 USD ≈ ¥7xx
 */

/* 支持的充值/提现币种（key 存入 transactions.currency） */
if (!defined('BM_RATES_LOADED')) {
    define('BM_RATES_LOADED', 1);

    function bmCryptoCatalog() {
        return [
            'usdt_trc20' => ['name' => 'USDT',  'chain' => 'TRON (TRC20)',      'cg' => 'tether',     'min' => 10,  'icon' => 'fas fa-dollar-sign',   're' => '/^T[1-9A-HJ-NP-Za-km-z]{33}$/'],
            'usdt_erc20' => ['name' => 'USDT',  'chain' => 'Ethereum (ERC20)',  'cg' => 'tether',     'min' => 20,  'icon' => 'fas fa-dollar-sign',   're' => '/^0x[a-fA-F0-9]{40}$/'],
            'usdt_bep20' => ['name' => 'USDT',  'chain' => 'BNB Chain (BEP20)', 'cg' => 'tether',     'min' => 10,  'icon' => 'fas fa-dollar-sign',   're' => '/^0x[a-fA-F0-9]{40}$/'],
            'usdt_arb'   => ['name' => 'USDT',  'chain' => 'Arbitrum One',      'cg' => 'tether',     'min' => 10,  'icon' => 'fas fa-dollar-sign',   're' => '/^0x[a-fA-F0-9]{40}$/'],
            'eth_erc20'  => ['name' => 'ETH',   'chain' => 'Ethereum (ERC20)',  'cg' => 'ethereum',   'min' => 0.01,'icon' => 'fab fa-ethereum',      're' => '/^0x[a-fA-F0-9]{40}$/'],
            'btc'        => ['name' => 'BTC',   'chain' => 'Bitcoin',           'cg' => 'bitcoin',    'min' => 0.0005,'icon' => 'fab fa-btc',         're' => '/^(bc1|[13])[a-zA-HJ-NP-Z0-9]{25,62}$/'],
            'bnb_bep20'  => ['name' => 'BNB',   'chain' => 'BNB Chain (BEP20)', 'cg' => 'binancecoin','min' => 0.05,'icon' => 'fas fa-coins',         're' => '/^0x[a-fA-F0-9]{40}$/'],
            'sol'        => ['name' => 'SOL',   'chain' => 'Solana',            'cg' => 'solana',     'min' => 0.2, 'icon' => 'fas fa-sun',           're' => '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/'],
            'trx'        => ['name' => 'TRX',   'chain' => 'TRON',              'cg' => 'tron',       'min' => 50,  'icon' => 'fas fa-bolt',          're' => '/^T[1-9A-HJ-NP-Za-km-z]{33}$/'],
            'xmr'        => ['name' => 'XMR',   'chain' => 'Monero',            'cg' => 'monero',     'min' => 0.1, 'icon' => 'fas fa-user-secret',   're' => '/^[48][0-9AB][1-9A-HJ-NP-Za-km-z]{90,105}$/'],
        ];
    }

    /* 展示用法定货币（code => [符号, 中文名, 英文名]） */
    function bmFiatList() {
        return [
            'usd' => ['$',   '美元',   'US Dollar'],
            'cny' => ['¥',   '人民币', 'Chinese Yuan'],
            'eur' => ['€',   '欧元',   'Euro'],
            'jpy' => ['JP¥', '日元',   'Japanese Yen'],
            'krw' => ['₩',   '韩元',   'Korean Won'],
            'gbp' => ['£',   '英镑',   'British Pound'],
            'hkd' => ['HK$', '港币',   'Hong Kong Dollar'],
            'rub' => ['₽',   '卢布',   'Russian Ruble'],
            'sgd' => ['S$',  '新加坡元','Singapore Dollar'],
            'aud' => ['A$',  '澳元',   'Australian Dollar'],
        ];
    }

    /* 接口失败时的兜底汇率（约值，仅保证功能可用） */
    function bmRatesFallback() {
        return [
            't' => 0,
            'crypto' => ['tether' => 1.0, 'bitcoin' => 95000, 'ethereum' => 3500, 'binancecoin' => 650, 'solana' => 180, 'tron' => 0.24, 'monero' => 180],
            'fiat' => ['usd' => 1, 'cny' => 7.09, 'eur' => 0.92, 'jpy' => 152, 'krw' => 1360, 'gbp' => 0.79, 'hkd' => 7.8, 'rub' => 92, 'sgd' => 1.34, 'aud' => 1.52],
        ];
    }

    /**
     * 拉取实时汇率（一次请求同时取加密币 USD 价与各法币牌价）
     */
    function bmFetchRatesLive() {
        // PHP 若未启用 openssl/curl，则无 https 流包装器，直接跳过实时拉取走缓存/兜底
        if (!in_array('https', stream_get_wrappers(), true)) return null;
        $ids = 'tether,bitcoin,ethereum,binancecoin,solana,tron,monero';
        $vs = 'usd,cny,eur,jpy,krw,gbp,hkd,rub,sgd,aud';
        $url = 'https://api.coingecko.com/api/v3/simple/price?ids=' . $ids . '&vs_currencies=' . $vs;
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: BaseWallet/1.0\r\n"]]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!$raw) return null;
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['tether']) || !is_array($data['tether'])) return null;

        $fiat = [];
        foreach ($data['tether'] as $cur => $v) {
            if (is_numeric($v) && $v > 0) $fiat[strtolower($cur)] = floatval($v);
        }
        if (!isset($fiat['usd'])) return null;

        $crypto = [];
        foreach (bmCryptoCatalog() as $c) {
            if (isset($data[$c['cg']]['usd']) && is_numeric($data[$c['cg']]['usd'])) {
                $crypto[$c['cg']] = floatval($data[$c['cg']]['usd']);
            }
        }
        if (count($crypto) < 3) return null;
        return ['fiat' => $fiat, 'crypto' => $crypto];
    }

    /**
     * 汇率（带缓存；失败时回退旧缓存 → 内置兜底值）
     */
    function getRates($force = false) {
        static $mem = null;
        if ($mem !== null && !$force) return $mem;

        $cache = getSetting('rates_cache', '');
        $c = $cache ? json_decode($cache, true) : null;
        $isFresh = is_array($c) && isset($c['t']) && (time() - intval($c['t'])) < 600;

        if ($isFresh && !$force) {
            return $mem = $c;
        }

        $live = bmFetchRatesLive();
        if ($live) {
            $c = ['t' => time(), 'fiat' => $live['fiat'], 'crypto' => $live['crypto']];
            setSetting('rates_cache', json_encode($c));
        } elseif (!is_array($c)) {
            $c = bmRatesFallback();
        }
        return $mem = $c;
    }

    /** 某币种当前美元单价 */
    function bmCryptoUsdRate($key) {
        $cat = bmCryptoCatalog();
        if (!isset($cat[$key])) return 0;
        $r = getRates();
        return floatval($r['crypto'][$cat[$key]['cg']] ?? 0);
    }

    /** USD 数额转目标法币数额 */
    function bmConvert($usdAmount, $fiatCode) {
        $r = getRates();
        $rate = floatval($r['fiat'][strtolower($fiatCode)] ?? 1);
        return floatval($usdAmount) * $rate;
    }

    /** 按当前汇率把加密币数量折算为 USD（保留 2 位） */
    function bmCryptoToUsd($key, $amount) {
        return round(floatval($amount) * bmCryptoUsdRate($key), 2);
    }
}
