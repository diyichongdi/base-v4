<?php
/**
 * demos/demo_a.php — 会话视图 Demo A：双栏·中栏（保留可调中栏宽度）
 * 独立样板页（只读、示例数据），用于对比三版会话视图后选型。
 * 仅作呈现样板，不接入真实聊天交互。
 */
define('IM_STANDALONE', true);
require_once dirname(dirname(dirname(__FILE__))) . '/security.php';

if (!isLoggedIn('user_id')) {
    header('Location: ../../login.php?next=' . rawurlencode('im/demos/demo_a.php'));
    exit;
}

$demoChats = [
    ['id' => 'ann', 'kind' => 'channel', 'name' => '系统公告', 'icon' => 'bullhorn', 'last' => '平台已升级至全站统一账号体系', 'time' => '09:12', 'unread' => 3],
    ['id' => 'dev', 'kind' => 'channel', 'name' => '开发者频道', 'icon' => 'code', 'last' => 'Docker 部署文档已更新', 'time' => '昨天', 'unread' => 0],
    ['id' => '90000002', 'kind' => 'contact', 'name' => '用户 90000002', 'icon' => 'user', 'last' => '在吗？关于订单的事', 'time' => '14:30', 'unread' => 2],
    ['id' => 'grp', 'kind' => 'group', 'name' => '基地闲聊群', 'icon' => 'users', 'last' => '今晚八点线上聚会', 'time' => '13:05', 'unread' => 1],
    ['id' => 'u_moss', 'kind' => 'bot', 'name' => 'MOSS 助手', 'icon' => 'robot', 'last' => '需要我帮你查询什么？', 'time' => '昨天', 'unread' => 0],
    ['id' => 'file', 'kind' => 'filehelper', 'name' => '文件传输助手', 'icon' => 'inbox', 'last' => '已收到压缩包 backup.zip', 'time' => '周一', 'unread' => 0],
];

$msgs = [
    ['me' => false, 'name' => '用户 90000002', 'time' => '14:28', 'text' => '你好，昨天在商城下了一单，显示待发货。'],
    ['me' => true,  'name' => '你', 'time' => '14:29', 'text' => '您好！我帮您查一下订单状态。'],
    ['me' => false, 'name' => '用户 90000002', 'time' => '14:30', 'text' => '好的，订单号尾号 8842，麻烦尽快处理。'],
    ['me' => true,  'name' => '你', 'time' => '14:31', 'text' => '已查到，今天内会安排发货，发货后系统会通知你。'],
];
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="aqua">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>会话视图 Demo A · 双栏中栏</title>
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
<link rel="stylesheet" href="../styles.css">
<link rel="stylesheet" href="../chat.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  body { margin: 0; background: var(--app-bg, #f5f5f7); color: var(--text); }
  .demo-top { max-width: 1180px; margin: 0 auto; padding: 18px 20px 4px; display: flex; align-items: center; gap: 12px; }
  .demo-top h1 { font-size: 18px; margin: 0; }
  .demo-top .pill { font-size: 12px; padding: 3px 10px; border-radius: 20px; background: color-mix(in srgb, var(--primary) 12%, transparent); color: var(--primary); }
  .demo-bar { max-width: 1180px; margin: 6px auto 0; padding: 0 20px; display: flex; gap: 8px; flex-wrap: wrap; }
  .demo-bar a { text-decoration: none; font-size: 13px; padding: 6px 12px; border-radius: 10px; border: 1px solid var(--border); color: var(--text); background: var(--bg-card); }
  .demo-bar a.on { color: #fff; background: var(--primary); border-color: var(--primary); }
  .demo-bar .hint { font-size: 12px; color: var(--text-muted); align-self: center; }
  .app { max-width: 1180px; margin: 14px auto 40px; padding: 0 20px; display: grid; grid-template-columns: 1.2fr 1.6fr 1fr; gap: 16px; align-items: start; }
  .col { background: var(--glass-bg, rgba(255,255,255,.8)); border: 1px solid var(--border, #e5e5ea); border-radius: var(--radius-lg, 18px); overflow: hidden; }
  .col h2 { margin: 0; padding: 14px 16px; font-size: 14px; border-bottom: 1px solid var(--border, #e5e5ea); display: flex; align-items: center; gap: 8px; }
  .row { display: flex; align-items: center; gap: 12px; padding: 12px 16px; cursor: pointer; border-bottom: 1px solid var(--border, #f0f0f2); }
  .row:hover { background: var(--bg-card-hover, #f7f7fa); }
  .row.on { background: color-mix(in srgb, var(--primary) 8%, transparent); box-shadow: inset 3px 0 0 var(--primary); }
  .row .ic { width: 42px; height: 42px; border-radius: var(--radius-md); display: grid; place-items: center; font-size: 17px; background: color-mix(in srgb, var(--primary) 14%, transparent); color: var(--primary); flex-shrink: 0; }
  .row .inf { flex: 1; min-width: 0; }
  .row .nm { font-size: 14px; font-weight: 600; }
  .row .pr { font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .row .mt { font-size: 11px; color: var(--text-subtle); }
  .row .bd { min-width: 18px; height: 18px; padding: 0 6px; border-radius: 10px; font-size: 11px; font-weight: 700; background: var(--primary); color: #fff; display: inline-flex; align-items: center; justify-content: center; }
  .msg { display: flex; gap: 10px; padding: 12px 16px; }
  .msg .av { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; font-size: 13px; color: #fff; flex-shrink: 0; }
  .msg .bub { background: var(--bg-card-hover, #f2f2f5); border-radius: 4px 12px 12px 12px; padding: 9px 12px; font-size: 14px; max-width: 70%; }
  .msg.me { flex-direction: row-reverse; }
  .msg.me .bub { background: var(--primary); color: #fff; border-radius: 12px 4px 12px 12px; }
  .msg .time { font-size: 11px; color: var(--text-subtle); margin-top: 4px; }
  .resizer-hint { font-size: 11px; color: var(--text-subtle); text-align: center; padding: 3px; }
  .dtl { padding: 16px; font-size: 13px; }
  .dtl .dp { width: 60px; height: 60px; border-radius: 50%; margin: 0 auto 8px; display: grid; place-items: center; font-size: 22px; color: #fff; }
  .dtl h3 { text-align: center; margin: 0 0 2px; font-size: 15px; }
  .dtl .sub { text-align: center; font-size: 12px; color: var(--text-muted); margin-bottom: 14px; }
  @media (max-width: 900px) { .app { grid-template-columns: 1fr; } }
</style>
</head>
<body>
  <div class="demo-top">
    <h1>会话视图 <span class="pill">Demo A · 双栏（可调中栏）</span></h1>
  </div>
  <div class="demo-bar">
    <a href="index.php">← 返回对比页</a>
    <a class="on" href="demo_a.php">A · 中栏</a>
    <a href="demo_b.php">B · 全屏覆盖</a>
    <a href="demo_c.php">C · 行内展开</a>
  </div>
  <div class="app">
    <!-- 左：会话列表 -->
    <div class="col">
      <h2><i class="fas fa-comment"></i> 会话</h2>
      <?php foreach ($demoChats as $i => $c): ?>
      <div class="row <?php echo $i === 0 ? 'on' : ''; ?>" data-c="<?php echo $c['id']; ?>">
        <span class="ic"><i class="fas fa-<?php echo $c['icon']; ?>"></i></span>
        <div class="inf"><div class="nm"><?php echo $c['name']; ?></div><div class="pr"><?php echo $c['last']; ?></div></div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
          <div class="mt"><?php echo $c['time']; ?></div>
          <?php if ($c['unread'] > 0): ?><span class="bd"><?php echo $c['unread'] > 99 ? '99+' : $c['unread']; ?></span><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <!-- 中：消息区 -->
    <div class="col">
      <h2><i class="fas fa-comments"></i> 与你（可拖拽调中栏宽度）</h2>
      <div class="resizer-hint"><i class="fas fa-arrows-alt-h"></i> 中栏宽度可调（拖拽边界）</div>
      <?php foreach ($msgs as $m): ?>
      <div class="msg <?php echo $m['me'] ? 'me' : ''; ?>">
        <?php if (!$m['me']): ?><span class="av" style="background:var(--primary);"><?php echo mb_substr($m['name'], 0, 1); ?></span><?php endif; ?>
        <div>
          <div class="bub"><?php echo $m['text']; ?></div>
          <div class="time"><?php echo $m['time']; ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <!-- 右：资料卡 -->
    <div class="col">
      <h2><i class="fas fa-id-card"></i> 资料</h2>
      <div class="dtl">
        <div class="dp" style="background:var(--primary);">用</div>
        <h3>用户 90000002</h3>
        <div class="sub">@90000002 · 在线</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div style="text-align:center;background:var(--bg-card-hover);border-radius:12px;padding:12px;"><b style="font-size:20px;display:block;color:var(--primary);">12</b>消息</div>
          <div style="text-align:center;background:var(--bg-card-hover);border-radius:12px;padding:12px;"><b style="font-size:20px;display:block;color:var(--primary);">3</b>群组</div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
