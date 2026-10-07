<?php
/**
 * 登录 / 注册（同页 Tab 切换）
 * index.php 复用本文件，默认切换到「注册」标签
 */
require_once 'security.php';

/* 登录后回跳：由 ?next= 携带并暂存于会话，登录成功即消费（单次使用） */
if (isset($_GET['next'])) {
    $cleanNext = cleanRedirectTarget((string)$_GET['next']);
    if ($cleanNext !== '') $_SESSION['login_redirect'] = $cleanNext;
}
$returnTo = (string)($_SESSION['login_redirect'] ?? '');
unset($_SESSION['login_redirect']);

if (isLoggedIn()) {
    header('Location: ' . redirectTarget($returnTo));
    exit;
}

/**
 * 免密快捷登录：建立会话并跳转目标页面（默认控制台）
 */
function loginUserSession($user, $returnTo = '') {
    regenerateSession();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['nick'] = $user['nick'] ?? '';
    $_SESSION['role'] = $user['role'] ?? 'user';
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['ip'] = getClientIP();
    if (!empty($user['language'])) setLang($user['language']);
    recordLoginSuccess($user['id'], $user['username']);
    header('Location: ' . redirectTarget($returnTo));
    exit;
}

/**
 * 为设备一键登录 / 长句密钥登录自动创建账号（与即时通讯的自动注册一致）
 */
function createQuickUser($username, $identifier, $loginType) {
    $randPass = bin2hex(random_bytes(16));
    $hash = password_hash($randPass, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3
    ]);
    $uid = dbInsert(
        "INSERT INTO users (username, password, email, role, created_at) VALUES (:u, :p, :e, 'user', :t)",
        [':u' => $username, ':p' => $hash, ':e' => $username . '@' . $loginType . '.local', ':t' => time()]
    );
    if (!$uid) return null;
    dbQuery(
        "INSERT OR IGNORE INTO auth_methods (user_id, login_type, identifier, created_at) VALUES (:uid, :lt, :id, :t)",
        [':uid' => $uid, ':lt' => $loginType, ':id' => $identifier, ':t' => time()]
    );
    return dbGetRow("SELECT * FROM users WHERE id = :id", [':id' => $uid]);
}

$err = '';
$panel = $panel ?? (($_GET['panel'] ?? '') === 'register' ? 'register' : 'login');
$username = '';
$success = false;
$devicePwdRequired = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';
    $panel = $action === 'register' ? 'register' : 'login';

    // Honeypot
    if (!empty($_POST['email'])) {
        http_response_code(403);
        exit('Access denied');
    }

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $err = L('csrf_invalid');
    } elseif (!checkRateLimit($action, 5, 300)) {
        $err = L('err_rate_limit');
    } elseif ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $captcha = (string)($_POST['captcha'] ?? '');

        if ($username === '' || $password === '') {
            $err = L('fill_all_fields');
        } elseif (!validateCaptcha($captcha)) {
            $err = L('captcha_error');
        } else {
            $locked = isUserLocked($username);
            if ($locked !== false) {
                $err = str_replace('{n}', $locked, L('err_locked'));
            } else {
                $user = dbGetRow(
                    "SELECT * FROM users WHERE username = :user COLLATE NOCASE",
                    [':user' => $username]
                );
                if (!$user && $username !== '') {
                    $user = dbGetRow(
                        "SELECT * FROM users WHERE email = :user COLLATE NOCASE",
                        [':user' => $username]
                    );
                }
                if (!$user && $username !== '') {
                    $nickRows = dbQuery(
                        "SELECT * FROM users WHERE nick = :user",
                        [':user' => $username]
                    );
                    if (is_array($nickRows) && count($nickRows) === 1) {
                        $user = $nickRows[0];
                    } elseif (is_array($nickRows) && count($nickRows) > 1) {
                        $err = getLang() === 'zh'
                            ? '该助记用户名对应多个账号，请使用数字ID登录'
                            : 'This nickname matches multiple accounts, please login with your numeric ID';
                    }
                }
                if ($user && password_verify($password, $user['password'])) {
                    regenerateSession();
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['nick'] = $user['nick'] ?? '';
                    $_SESSION['role'] = $user['role'] ?? 'user';
                    $_SESSION['login_time'] = time();
                    $_SESSION['last_activity'] = time();
                    $_SESSION['ip'] = getClientIP();

                    if (!empty($user['language'])) setLang($user['language']);

                    if (!empty($_POST['remember'])) {
                        $token = bin2hex(random_bytes(32));
                        dbQuery("UPDATE users SET remember_token = :t WHERE id = :id",
                            [':t' => hash('sha256', $token), ':id' => $user['id']]);
                        setcookie('remember', $token, time() + 30 * 24 * 3600, '/', '', true, true);
                    }

                    recordLoginSuccess($user['id'], $user['username']);
                    header('Location: ' . redirectTarget($returnTo));
                    exit;
                } else {
                    recordLoginFailure($username);
                    $err = L('login_error');
                }
            }
        }
    } elseif ($action === 'device_login') {
        $deviceFp = (string)($_POST['device_fp'] ?? '');
        $devicePwd = (string)($_POST['device_pwd'] ?? '');
        if (!preg_match('/^[a-f0-9]{32,128}$/', $deviceFp)) {
            $err = L('login_error');
        } else {
            $ident = hash('sha256', $deviceFp);
            $method = dbGetRow(
                "SELECT * FROM auth_methods WHERE login_type = 'device' AND identifier = :id",
                [':id' => $ident]
            );
            if ($method && !empty($method['device_pwd_hash'])) {
                // 本设备已设置密码：必须输入正确后才能登录
                if ($devicePwd === '' || !password_verify($devicePwd, $method['device_pwd_hash'])) {
                    $devicePwdRequired = true;
                    $err = L('device_pwd_required');
                } else {
                    $u = dbGetRow("SELECT * FROM users WHERE id = :id", [':id' => $method['user_id']]);
                    if ($u && (intval($u['banned'] ?? 0) === 1 || intval($u['is_active'] ?? 1) !== 1)) {
                        $err = L('account_banned');
                    } elseif ($u) {
                        loginUserSession($u, $returnTo);
                    } else {
                        $err = L('login_error');
                    }
                }
            } else {
                $u = dbGetRow(
                    "SELECT u.* FROM users u JOIN auth_methods m ON m.user_id = u.id WHERE m.login_type = 'device' AND m.identifier = :id",
                    [':id' => $ident]
                );
                if (!$u) {
                    $u = createQuickUser('dev_' . substr($ident, 0, 12), $ident, 'device');
                }
                if ($u && (intval($u['banned'] ?? 0) === 1 || intval($u['is_active'] ?? 1) !== 1)) {
                    $err = L('account_banned');
                } elseif ($u) {
                    if ($devicePwd !== '') {
                        dbQuery(
                            "UPDATE auth_methods SET device_pwd_hash = :h WHERE login_type = 'device' AND identifier = :id",
                            [':h' => password_hash($devicePwd, PASSWORD_ARGON2ID, [
                                'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3
                            ]), ':id' => $ident]
                        );
                    }
                    loginUserSession($u, $returnTo);
                } else {
                    $err = L('login_error');
                }
            }
        }
    } elseif ($action === 'key_login') {
        $sentence = trim((string)($_POST['sentence'] ?? ''));
        $sentenceLen = function_exists('mb_strlen') ? mb_strlen($sentence) : strlen($sentence);
        if ($sentenceLen < 6) {
            $err = L('sentence_short');
        } else {
            $ident = 'sen_' . hash('sha256', strtolower($sentence));
            $u = dbGetRow(
                "SELECT u.* FROM users u JOIN auth_methods m ON m.user_id = u.id WHERE m.login_type = 'key' AND m.identifier = :id",
                [':id' => $ident]
            );
            if (!$u) {
                $u = createQuickUser('sen_' . substr($ident, 4, 12), $ident, 'key');
            }
            if ($u && (intval($u['banned'] ?? 0) === 1 || intval($u['is_active'] ?? 1) !== 1)) {
                $err = L('account_banned');
            } elseif ($u) {
                loginUserSession($u, $returnTo);
            } else {
                $err = L('login_error');
            }
        }
    } elseif ($action === 'register') {
        // 注册：助记用户名仅自己可见，公开身份为系统分配的纯数字ID
        $nick = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $captcha = (string)($_POST['captcha'] ?? '');

        $nickLen = function_exists('mb_strlen') ? mb_strlen($nick) : strlen($nick);
        if ($nick !== '' && ($nickLen < 2 || $nickLen > 20)) {
            $err = getLang() === 'zh' ? '助记用户名需 2-20 个字符' : 'Nickname must be 2-20 characters';
        } elseif ($password !== $confirm) {
            $err = L('password_not_match');
        } else {
            $pwdCheck = checkPasswordStrength($password);
            if (!$pwdCheck['valid']) {
                $err = L('password_weak');
            } elseif (!validateCaptcha($captcha)) {
                $err = L('captcha_error');
            } else {
                $hash = password_hash($password, PASSWORD_ARGON2ID, [
                    'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3
                ]);
                $numericId = generateNumericUsername();
                $userId = dbInsert(
                    "INSERT INTO users (username, nick, password, created_at) VALUES (:u, :n, :p, :t)",
                    [':u' => $numericId, ':n' => $nick, ':p' => $hash, ':t' => time()]
                );
                if ($userId) {
                    $_SESSION['reg_assigned_id'] = $numericId;
                    if ($nick !== '') $_SESSION['reg_assigned_nick'] = $nick;
                    $success = true;
                } else {
                    $err = L('err_register_failed');
                }
            }
        }
    }
}

$csrfToken = generateCsrfToken();
$lang = getLang();
$pageTitle = L('login');
$hideNav = true;
$hideFooter = true;
$nextQuery = $returnTo !== '' ? '&next=' . urlencode($returnTo) : '';
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" data-theme="aqua">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="im/favicon.svg">
<title><?php echo htmlspecialchars($pageTitle); ?> · 基地</title>
<script>
    (function () {
        var fx = 'frost', dark = false;
        try {
            fx = localStorage.getItem('bm-cardfx') || 'frost';
            dark = localStorage.getItem('bm-fxdark') === '1';
        } catch (e) {}
        if (['frost', 'aurora', 'particles', 'blueprint'].indexOf(fx) === -1) fx = 'frost';
        document.documentElement.setAttribute('data-fx-active', fx);
        if (dark) document.documentElement.classList.add('fx-dark');
    })();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-body">

<div class="orb-bg" aria-hidden="true">
    <span class="orb orb-1"></span>
    <span class="orb orb-2"></span>
    <span class="orb orb-3"></span>
    <span class="orb orb-4"></span>
</div>

<div class="auth-corner">
    <div class="flex gap-2">
        <a href="?lang=zh<?php echo $nextQuery; ?>" class="btn btn-sm btn-ghost lang-switch <?php echo $lang === 'zh' ? 'active' : ''; ?>" style="<?php echo $lang === 'zh' ? 'color:var(--primary);' : ''; ?>">中</a>
        <a href="?lang=en<?php echo $nextQuery; ?>" class="btn btn-sm btn-ghost lang-switch <?php echo $lang === 'en' ? 'active' : ''; ?>" style="<?php echo $lang === 'en' ? 'color:var(--primary);' : ''; ?>">EN</a>
    </div>
    <div class="theme-menu-wrap">
        <button class="icon-btn" data-theme-btn title="卡片风格"><i class="fas fa-drafting-compass"></i></button>
        <div class="dropdown">
            <div class="dropdown-title"><i class="fas fa-swatchbook"></i> 卡片风格</div>
            <a class="dropdown-item fx-opt" data-fx-opt="frost"><i class="fas fa-layer-group"></i> 雾面玻璃 <i class="fas fa-check theme-check"></i></a>
            <a class="dropdown-item fx-opt" data-fx-opt="aurora"><i class="fas fa-wand-magic-sparkles"></i> 极光流光 <i class="fas fa-check theme-check"></i></a>
            <a class="dropdown-item fx-opt" data-fx-opt="particles"><i class="fas fa-satellite"></i> 星域粒子 <i class="fas fa-check theme-check"></i></a>
            <a class="dropdown-item fx-opt" data-fx-opt="blueprint"><i class="fas fa-drafting-compass"></i> 工程蓝图 <i class="fas fa-check theme-check"></i></a>
            <div class="dropdown-divider"></div>
            <div class="fx-dark-row" data-dark-switch>
                <span><i class="fas fa-moon"></i> 暗色模式</span>
                <span class="switch" data-dark-switch></span>
            </div>
        </div>
    </div>
</div>

<div class="auth-box">
    <div class="auth-card">
        <?php if ($success): ?>
            <div class="text-center" style="padding:14px 0;">
                <div style="width:84px;height:84px;border-radius:50%;margin:0 auto 18px;display:grid;place-items:center;font-size:38px;background:var(--success-bg);color:var(--success);animation:pop .5s cubic-bezier(.34,1.56,.64,1);"><i class="fas fa-check"></i></div>
                <h1 class="auth-title"><?php echo L('register_success'); ?></h1>
                <?php $regId = $_SESSION['reg_assigned_id'] ?? ''; unset($_SESSION['reg_assigned_id'], $_SESSION['reg_assigned_nick']); ?>
                <?php if ($regId !== ''): ?>
                <div class="alert alert-success" style="margin:14px 0;text-align:left;">
                    <i class="fas fa-id-badge" style="margin-top:2px;"></i>
                    <span><?php echo $lang === 'zh' ? '您的数字ID：' : 'Your numeric ID: '; ?><b style="font-size:18px;letter-spacing:1px;"><?php echo htmlspecialchars($regId); ?></b><br>
                    <small><?php echo $lang === 'zh' ? '请牢记，登录与对外展示均使用此纯数字ID；助记用户名仅自己可见。' : 'Keep it safe — it is used for login and shown publicly. Your nickname is visible only to you.'; ?></small></span>
                </div>
                <?php endif; ?>
                <a href="login.php?next=<?php echo urlencode($returnTo); ?>" class="btn btn-primary btn-block"><i class="fas fa-right-to-bracket"></i> <?php echo L('login_now'); ?></a>
            </div>
        <?php else: ?>
            <div class="auth-logo">基</div>
            <h1 class="auth-title">基地</h1>
            <p class="auth-sub"><?php echo $lang === 'zh' ? '数字资产交易 · 即时通讯 · 安全论坛' : 'Digital Asset Trading · IM · Secure Forum'; ?></p>

            <?php if ($err): ?>
                <div class="alert alert-error"><i class="fas fa-circle-exclamation" style="margin-top:2px;"></i> <?php echo htmlspecialchars($err); ?></div>
            <?php endif; ?>

            <div class="auth-switch" data-active="<?php echo $panel; ?>">
                <button type="button" class="auth-tab<?php echo $panel === 'login' ? ' active' : ''; ?>" onclick="switchAuth('login')"><?php echo L('login'); ?></button>
                <button type="button" class="auth-tab<?php echo $panel === 'register' ? ' active' : ''; ?>" onclick="switchAuth('register')"><?php echo L('register'); ?></button>
                <span class="auth-slider"></span>
            </div>

            <form method="post" autocomplete="off" class="auth-panel<?php echo $panel === 'login' ? ' active' : ''; ?>" id="loginPanel">
                <input type="hidden" name="action" value="login" id="authAction">
                <input type="hidden" name="device_fp" id="authDeviceFp" value="">
                <input type="hidden" name="device_pwd" id="authDevicePwd" value="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <?php if ($returnTo !== ''): ?><input type="hidden" name="next" value="<?php echo htmlspecialchars($returnTo); ?>"><?php endif; ?>
                <div class="form-group">
                    <label class="form-label"><?php echo L('username'); ?></label>
                    <input type="text" name="username" class="form-control" placeholder="<?php echo $lang === 'zh' ? '数字ID / 用户名 / 邮箱' : 'Numeric ID / username / email'; ?>" value="<?php echo htmlspecialchars($username); ?>" required autofocus>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo L('password'); ?></label>
                    <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                </div>
                <div class="captcha-box">
                    <img src="captcha.php" alt="Captcha" class="captcha-img" onclick="this.src='captcha.php?'+Date.now()" title="<?php echo L('click_refresh'); ?>">
                    <input type="text" name="captcha" class="form-control" placeholder="<?php echo L('captcha'); ?>" maxlength="5" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="checkbox-wrap">
                        <input type="checkbox" name="remember" value="1"> <?php echo L('remember_me'); ?>
                    </label>
                </div>
                <div class="honeypot" style="position:absolute;left:-9999px;opacity:0;">
                    <input type="text" name="email" tabindex="-1" autocomplete="off">
                </div>
                <button type="submit" class="btn btn-primary btn-block btn-lg"><i class="fas fa-right-to-bracket"></i> <?php echo L('login'); ?></button>

                <div class="auth-divider"><span><?php echo L('other_login'); ?></span></div>
                <div class="auth-alt">
                    <button type="button" class="auth-alt-btn" id="btnDeviceLogin"><i class="fas fa-mobile-screen-button"></i> <?php echo L('device_login'); ?></button>
                    <button type="button" class="auth-alt-btn" id="btnKeyToggle"><i class="fas fa-key"></i> <?php echo L('key_login'); ?></button>
                </div>
                <p class="auth-alt-note"><i class="fas fa-circle-info"></i> <?php echo $lang === 'zh' ? '免密快捷登录：本设备或一句密钥即可直接进入，无需设置密码也可随时补设。' : 'Quick passwordless login via this device or a secret sentence.'; ?></p>
                <div class="auth-key-panel" id="authKeyPanel" style="display:none;">
                    <textarea id="authSentence" class="form-control" rows="3" placeholder="<?php echo htmlspecialchars(L('key_login_hint')); ?>"></textarea>
                    <button type="button" class="auth-alt-btn" id="btnSentenceLogin"><i class="fas fa-unlock-keyhole"></i> <?php echo L('key_login_btn'); ?></button>
                </div>
                <div class="auth-key-panel" id="devicePwdPrompt" style="<?php echo $devicePwdRequired ? 'display:block;' : 'display:none;'; ?>">
                    <label class="form-label"><?php echo L('device_pwd_required'); ?></label>
                    <input type="password" id="devicePwdInput" class="form-control" placeholder="<?php echo L('device_pwd_required'); ?>" autocomplete="off">
                </div>
            </form>

            <form method="post" autocomplete="off" class="auth-panel<?php echo $panel === 'register' ? ' active' : ''; ?>" id="registerPanel">
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <?php if ($returnTo !== ''): ?><input type="hidden" name="next" value="<?php echo htmlspecialchars($returnTo); ?>"><?php endif; ?>
                <div class="auth-form-grid">
                <div class="form-group span2">
                    <label class="form-label"><?php echo $lang === 'zh' ? '助记用户名（选填，仅自己可见）' : 'Nickname (optional, private)'; ?></label>
                    <input type="text" name="username" class="form-control" placeholder="<?php echo $lang === 'zh' ? '给自己起个好记的名字，不会公开展示' : 'A memorable name, never shown publicly'; ?>" value="<?php echo htmlspecialchars($username); ?>" maxlength="20">
                    <small class="text-subtle" style="display:block;margin-top:6px;"><i class="fas fa-shield-halved"></i> <?php echo $lang === 'zh' ? '系统将分配纯数字ID作为您的公开身份' : 'The system will assign a numeric ID as your public identity'; ?></small>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo L('password'); ?></label>
                    <input type="password" name="password" class="form-control" id="regPassword" placeholder="8+ chars, A-Z, a-z, 0-9" minlength="8" required>
                    <div class="strength-bar"><div class="strength-fill" id="regStrength"></div></div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo L('confirm_password'); ?></label>
                    <input type="password" name="confirm_password" class="form-control" placeholder="••••••••" minlength="8" required>
                </div>
                <div class="captcha-box span2">
                    <img src="captcha.php" alt="Captcha" class="captcha-img" onclick="this.src='captcha.php?'+Date.now()" title="<?php echo L('click_refresh'); ?>">
                    <input type="text" name="captcha" class="form-control" placeholder="<?php echo L('captcha'); ?>" maxlength="5" required autocomplete="off">
                </div>
                <div class="honeypot" style="position:absolute;left:-9999px;opacity:0;">
                    <input type="text" name="email" tabindex="-1" autocomplete="off">
                </div>
                <button type="submit" class="btn btn-primary btn-block btn-lg span2"><i class="fas fa-user-plus"></i> <?php echo L('register'); ?></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="auth-modal-mask" id="deviceNoticeModal">
    <div class="auth-modal">
        <div class="auth-modal-head">
            <span class="auth-modal-icon"><i class="fas fa-shield-halved"></i></span>
            <h3><?php echo L('device_notice_title'); ?></h3>
        </div>
        <div class="auth-modal-body">
            <p class="auth-modal-p"><?php echo L('device_notice_1'); ?></p>
            <p class="auth-modal-p"><?php echo L('device_notice_2'); ?></p>
            <ul class="auth-modal-list">
                <li><?php echo L('device_notice_3'); ?></li>
                <li><?php echo L('device_notice_4'); ?></li>
                <li><?php echo L('device_notice_5'); ?></li>
            </ul>
        </div>
        <div class="auth-modal-foot">
            <button type="button" class="btn btn-ghost" id="deviceNoticeLater"><?php echo L('device_later'); ?></button>
            <button type="button" class="btn btn-primary" id="deviceNoticeSetPwd"><i class="fas fa-key"></i> <?php echo L('device_set_pwd_now'); ?></button>
        </div>
    </div>
</div>

<div class="auth-modal-mask" id="deviceSetPwdModal">
    <div class="auth-modal">
        <div class="auth-modal-head">
            <span class="auth-modal-icon"><i class="fas fa-lock"></i></span>
            <h3><?php echo L('device_set_pwd_title'); ?></h3>
            <button type="button" class="auth-modal-x" id="deviceSetPwdClose" aria-label="close">&times;</button>
        </div>
        <div class="auth-modal-body">
            <div class="form-group">
                <label class="form-label"><?php echo L('device_new_pwd'); ?></label>
                <input type="password" id="deviceNewPwd" class="form-control" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo L('device_confirm_pwd'); ?></label>
                <input type="password" id="deviceNewPwd2" class="form-control" autocomplete="new-password">
            </div>
            <div class="auth-modal-tip" id="devicePwdTip"></div>
        </div>
        <div class="auth-modal-foot">
            <button type="button" class="btn btn-primary btn-block" id="deviceSetPwdConfirm"><?php echo L('confirm'); ?></button>
        </div>
    </div>
</div>

<script>
    function switchAuth(target) {
        var sw = document.querySelector('.auth-switch');
        var loginP = document.getElementById('loginPanel');
        var regP = document.getElementById('registerPanel');
        if (!sw || !loginP || !regP) return;
        sw.setAttribute('data-active', target);
        loginP.classList.toggle('active', target === 'login');
        regP.classList.toggle('active', target === 'register');
        sw.querySelectorAll('.auth-tab').forEach(function (t) {
            t.classList.toggle('active', t.textContent === (target === 'login' ? '<?php echo L('login'); ?>' : '<?php echo L('register'); ?>'));
        });
    }
</script>
<script src="assets/app.js"></script>
<script>
    window.bmTheme && window.bmTheme.bindStrength('regPassword', 'regStrength');
</script>
<script>
    (function () {
        var fp = null;
        try { fp = localStorage.getItem('site-device-fp'); } catch (e) {}
        if (!fp) {
            try {
                var arr = new Uint8Array(32);
                window.crypto.getRandomValues(arr);
                fp = Array.prototype.map.call(arr, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
                localStorage.setItem('site-device-fp', fp);
            } catch (e) { fp = null; }
        }
        var fpInput = document.getElementById('authDeviceFp');
        if (fpInput && fp) fpInput.value = fp;

        var loginPanel = document.getElementById('loginPanel');
        var authAction = document.getElementById('authAction');
        var pwdHidden = document.getElementById('authDevicePwd');

        var noticeSeen = function () {
            try {
                var key = 'aquaChat2_deviceNoticeShown_' + fp;
                if (localStorage.getItem(key)) return true;
                var imFp = localStorage.getItem('aquaChat2_deviceFp');
                if (imFp && localStorage.getItem('aquaChat2_deviceNoticeShown_' + imFp)) return true;
            } catch (e) {}
            return false;
        };
        var markSeen = function () {
            try { if (fp) localStorage.setItem('aquaChat2_deviceNoticeShown_' + fp, '1'); } catch (e) {}
        };
        var submitDeviceLogin = function (pwd) {
            if (pwdHidden) pwdHidden.value = pwd || '';
            authAction.value = 'device_login';
            loginPanel.submit();
        };
        var showDeviceSetPwd = function (onDone) {
            var modal = document.getElementById('deviceSetPwdModal');
            var p1 = document.getElementById('deviceNewPwd'), p2 = document.getElementById('deviceNewPwd2');
            var tip = document.getElementById('devicePwdTip');
            p1.value = ''; p2.value = ''; tip.textContent = '';
            modal.classList.add('show');
            setTimeout(function () { p1.focus(); }, 50);
            document.getElementById('deviceSetPwdConfirm').onclick = function () {
                if (p1.value.length < 4) { tip.textContent = '<?php echo addslashes(L('device_pwd_min')); ?>'; p1.focus(); return; }
                if (p1.value !== p2.value) { tip.textContent = '<?php echo addslashes(L('device_pwd_mismatch')); ?>'; p2.focus(); return; }
                modal.classList.remove('show');
                onDone(p1.value);
            };
            document.getElementById('deviceSetPwdClose').onclick = function () { modal.classList.remove('show'); onDone(''); };
        };
        var showDeviceNotice = function (onDone) {
            var modal = document.getElementById('deviceNoticeModal');
            modal.classList.add('show');
            document.getElementById('deviceNoticeLater').onclick = function () {
                modal.classList.remove('show'); markSeen(); onDone('');
            };
            document.getElementById('deviceNoticeSetPwd').onclick = function () {
                modal.classList.remove('show'); markSeen();
                showDeviceSetPwd(onDone);
            };
        };

        document.getElementById('btnDeviceLogin').addEventListener('click', function () {
            if (!fpInput || !fpInput.value) { alert('无法生成设备标识，请检查浏览器设置'); return; }
            var prompt = document.getElementById('devicePwdPrompt');
            if (prompt && prompt.style.display !== 'none') {
                var pwd = document.getElementById('devicePwdInput').value;
                if (!pwd) { alert('<?php echo addslashes(L('device_pwd_required')); ?>'); document.getElementById('devicePwdInput').focus(); return; }
                submitDeviceLogin(pwd);
                return;
            }
            if (noticeSeen()) { submitDeviceLogin(''); return; }
            showDeviceNotice(function (pwd) { submitDeviceLogin(pwd || ''); });
        });
        document.getElementById('btnKeyToggle').addEventListener('click', function () {
            var p = document.getElementById('authKeyPanel');
            p.style.display = p.style.display === 'none' ? 'block' : 'none';
        });
        document.getElementById('btnSentenceLogin').addEventListener('click', function () {
            var v = document.getElementById('authSentence').value.trim();
            if (v.length < 6) { alert('<?php echo addslashes(L('sentence_short')); ?>'); return; }
            authAction.value = 'key_login';
            loginPanel.submit();
        });
    })();
</script>
</body>
</html>