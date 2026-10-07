<?php
// 市场模块初始化
require_once '../security.php';

// 初始化市场数据库表
function initMarketTables() {
    $db = getDB();

    // 商品表
    $db->exec("CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        seller_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        description TEXT,
        price REAL NOT NULL,
        category TEXT NOT NULL,
        stock INTEGER DEFAULT 1,
        status TEXT DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (seller_id) REFERENCES users(id)
    )");

    // 订单表
    $db->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_no TEXT UNIQUE NOT NULL,
        buyer_id INTEGER NOT NULL,
        seller_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity INTEGER DEFAULT 1,
        total_price REAL NOT NULL,
        status TEXT DEFAULT 'pending',
        delivery_info TEXT,
        auto_release_at DATETIME,
        released_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (buyer_id) REFERENCES users(id),
        FOREIGN KEY (seller_id) REFERENCES users(id),
        FOREIGN KEY (product_id) REFERENCES products(id)
    )");

    // 订单事件时间线
    $db->exec("CREATE TABLE IF NOT EXISTS order_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        event TEXT NOT NULL,
        note TEXT,
        actor_id INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    )");

    // 订单沟通留言
    $db->exec("CREATE TABLE IF NOT EXISTS order_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        content TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // 站点设置 KV
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    )");

    // 充值/提现记录表
    $db->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        amount REAL NOT NULL,
        status TEXT DEFAULT 'pending',
        tx_hash TEXT,
        address TEXT,
        currency TEXT,
        chain TEXT,
        amount_crypto REAL,
        rate REAL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(tx_hash) WHERE tx_hash IS NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // 公告表
    $db->exec("CREATE TABLE IF NOT EXISTS announcements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 仲裁/纠纷表
    $db->exec("CREATE TABLE IF NOT EXISTS disputes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        applicant_id INTEGER NOT NULL,
        reason TEXT NOT NULL,
        evidence TEXT,
        status TEXT DEFAULT 'open',
        assigned_to INTEGER,
        decision TEXT,
        decision_note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME,
        FOREIGN KEY (order_id) REFERENCES orders(id),
        FOREIGN KEY (applicant_id) REFERENCES users(id),
        FOREIGN KEY (assigned_to) REFERENCES users(id)
    )");

    // 为旧订单补充 'disputed' 状态的处理（status 仍以 TEXT 存储）

    // 迁移：订单补列
    $orderCols = [];
    $r = @$db->query('PRAGMA table_info(orders)');
    if ($r) {
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) $orderCols[] = $row['name'];
    }
    if (!in_array('auto_release_at', $orderCols)) {
        @$db->exec('ALTER TABLE orders ADD COLUMN auto_release_at DATETIME');
    }
    if (!in_array('released_at', $orderCols)) {
        @$db->exec('ALTER TABLE orders ADD COLUMN released_at DATETIME');
    }

    // 迁移：交易表补列（多币种充值/提现）
    $txCols = [];
    $r = @$db->query('PRAGMA table_info(transactions)');
    if ($r) {
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) $txCols[] = $row['name'];
    }
    foreach ([
        'currency' => "TEXT DEFAULT ''",
        'chain' => "TEXT DEFAULT ''",
        'amount_crypto' => 'REAL DEFAULT 0',
        'rate' => 'REAL DEFAULT 0',
        'fee' => 'REAL DEFAULT 0',
    ] as $col => $def) {
        if (!in_array($col, $txCols)) {
            @$db->exec("ALTER TABLE transactions ADD COLUMN $col $def");
        }
    }

    // 充值收款地址（管理员在后台维护）
    $db->exec("CREATE TABLE IF NOT EXISTS deposit_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        currency_key TEXT UNIQUE NOT NULL,
        address TEXT DEFAULT '',
        is_active INTEGER DEFAULT 1,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->close();

    // 种子收款地址（占位，格式合法；上线前请在后台替换为真实地址）
    $seedAddr = [
        'usdt_trc20' => 'TXkAkGrFuMi2hQ7dLJcV1o9xRbWmSyEzP3',
        'usdt_erc20' => '0x9A7f4B2Cd81e35Fa6b0Dc72Ea19f84Cb53d6E102',
        'usdt_bep20' => '0x3Ef85a21Bc94d70F6aC258De90b147Fc62a38D95',
        'usdt_arb'   => '0x71cB49a05Fd23e86a1B07dC45f982ea36Db0C514',
        'eth_erc20'  => '0xB42d8cF17a60e395Dc28f04Ab7613Ed95c07Fa82',
        'btc'        => 'bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh',
        'bnb_bep20'  => '0x58Da3f91c27Be804aF165c3Eb79240d16Ca93B27',
        'sol'        => '5FHwkrdxntdK24hgQU8qgBjn35Y1zwhz1GZwCkP2UJnM',
        'trx'        => 'TQrY8tryqsYVCYS3MFbtffiPp2ccyn4STm',
        'xmr'        => '44AFFq5kSiGBoZ4NMDwYtN18obc8AemS33DBLWs3H7otXft3XjrpDtQGv7SqssaMRBKKmhSSE88WvScAGtk7jts99aYvPCbXBGofGmNbHc',
    ];
    foreach ($seedAddr as $key => $addr) {
        dbQuery("INSERT OR IGNORE INTO deposit_accounts (currency_key, address, is_active) VALUES (:k, :a, 1)",
            [':k' => $key, ':a' => $addr]);
    }

    // 隐私保护公告（只播种一次；公告栏见商城/论坛/首页）
    if (getSetting('privacy_notice_v1', '') === '') {
        $seedLang = getLang() === 'en' ? 'en' : 'zh';
        dbQuery("INSERT INTO announcements (title, content) VALUES (:t, :c)", [
            ':t' => $seedLang === 'en' ? 'Privacy: Numeric-Only IDs' : '隐私保护：全站启用纯数字ID',
            ':c' => $seedLang === 'en'
                ? 'To protect privacy, all users appear as system-assigned numeric IDs across the forum and market. Nicknames are visible only to yourself.'
                : '为保护用户隐私，论坛与商城中所有用户均以系统分配的纯数字ID显示；注册时填写的助记用户名仅自己可见，不会公开展示。请勿在助记名中填写真实姓名等敏感信息。'
        ]);
        setSetting('privacy_notice_v1', '1');
    }
}

// 生成订单号
function generateOrderNo() {
    return 'ORD' . date('Ymd') . strtoupper(substr(uniqid(), -8));
}

/**
 * 写入订单时间线事件
 */
function orderEvent($orderId, $event, $note = '', $actorId = null) {
    dbQuery("INSERT INTO order_events (order_id, event, note, actor_id) VALUES (:o, :e, :n, :a)", [
        ':o' => $orderId,
        ':e' => $event,
        ':n' => $note,
        ':a' => $actorId !== null ? $actorId : ($_SESSION['user_id'] ?? null)
    ]);
}

/**
 * 自动放款处理：已发货且超过自动放款期限的订单，自动放款给卖家
 */
function processAutoRelease() {
    $hours = max(1, intval(getSetting('auto_release_hours', '72')));
    $expired = dbQuery(
        "SELECT * FROM orders WHERE status = 'shipped' AND auto_release_at IS NOT NULL
         AND auto_release_at <= datetime('now')"
    );
    if (!is_array($expired)) return;

    $db = getDB();
    foreach ($expired as $order) {
        $db->exec('BEGIN TRANSACTION');
        try {
            $stmt = $db->prepare("UPDATE users SET balance = balance + :amount WHERE id = :id");
            $stmt->bindValue(':amount', $order['total_price']);
            $stmt->bindValue(':id', $order['seller_id']);
            $stmt->execute();

            $stmt = $db->prepare("UPDATE orders SET status = 'completed', released_at = datetime('now'), updated_at = datetime('now') WHERE id = :id");
            $stmt->bindValue(':id', $order['id']);
            $stmt->execute();

            $db->exec('COMMIT');
            orderEvent($order['id'], 'release', '自动放款：超过 ' . $hours . ' 小时期限，资金已释放给卖家', null);
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
        }
    }
    $db->close();
}

/**
 * 读取订单时间线
 */
function orderTimeline($orderId) {
    $rows = dbQuery("SELECT * FROM order_events WHERE order_id = :o ORDER BY created_at ASC, id ASC", [':o' => $orderId]);
    return is_array($rows) ? $rows : [];
}

/**
 * 订单事件标签（i18n）
 */
function orderEventLabel($event) {
    return L('ev_' . $event) ?: $event;
}

/**
 * 读取订单沟通留言
 */
function orderMessages($orderId) {
    $rows = dbQuery("SELECT m.*, u.username FROM order_messages m JOIN users u ON m.user_id = u.id WHERE m.order_id = :o ORDER BY m.id ASC", [':o' => $orderId]);
    return is_array($rows) ? $rows : [];
}

// 初始化 — 仅在首次运行时执行表创建 + 种子数据
if (getSetting('market_tables_initialized', '') !== '1') {
    initMarketTables();
    setSetting('market_tables_initialized', '1');
}

// 添加缺失的索引（加速商城商品查询）
$db = getDB();
$db->exec("CREATE INDEX IF NOT EXISTS idx_products_active ON products(status, stock, created_at DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_products_seller ON products(seller_id)");
$db->close();
