<?php
/**
 * demos/demo_b.php — 会话视图 Demo B：全屏覆盖层（仿论坛贴文页）
 * 独立样板页（只读、示例数据）。会话列表为一列，点击某会话 → 全屏消息覆盖层展示，
 * 列表保留在底层，返回键/遮罩关闭。仅作呈现样板。
 */
define('IM_STANDALONE', true);
require_once dirname(dirname(dirname(__FILE__))) . '/security.php';

if (!isLoggedIn('user_id')) {
    header('Location: ../../login.php?next=' . rawurlencode('im/demos/demo_b.php'));
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

// 每个会话对应一段演示消息（覆盖层内展示）
$demoMsgs = [
  'ann' => [['me'=>0,'n'=>'系统','t'=>'09:00','x'=>'平台已升级为全站统一账号体系，论坛与即时通讯共用主站账号。'],['me'=>0,'n'=>'系统','t'=>'09:12','x'=>'新增统一的会话视图对比 Demo，请在导航选择你喜欢的呈现方式。']],
  'dev' => [['me'=>0,'n'=>'开发者频道','t'=>'昨天','x'=>'Docker 部署文档已更新，新增 HTTPS 反向代理示例。'],['me'=>0,'n'=>'开发者频道','t'=>'昨天','x'=>'欢迎大家提交 PR 完善文档。']],
  '90000002' => [['me'=>0,'n'=>'用户 90000002','t'=>'14:28','x'=>'你好，昨天在商城下了一单，显示待发货。'],['me'=>1,'n'=>'你','t'=>'14:29','x'=>'您好！我帮您查一下订单状态。'],['me'=>0,'n'=>'用户 90000002','t'=>'14:30','x'=>'好的，订单号尾号 8842，麻烦尽快处理。'],['me'=>1,'n'=>'你','t'=>'14:31','x'=>'已查到，今天内会安排发货，发货后系统会通知你。']],
  'grp' => [['me'=>0,'n'=>'基地闲聊群','t'=>'13:00','x'=>'今晚八点线上聚会，欢迎参加！'],['me'=>1,'n'=>'你','t'=>'13:05','x'=>'收到，会准时到场。']],
  'u_moss' => [['me'=>0,'n'=>'MOSS 助手','t'=>'昨天','x'=>'你好，我是平台官方助手，可以帮你查询订单、余额等。'],['me'=>0,'n'=>'MOSS 助手','t'=>'昨天','x'=>'需要我帮你查询什么？']],
  'file' => [['me'=>0,'n'=>'文件传输助手','t'=>'周一','x'=>'已收到压缩包 backup.zip（2.4 MB）'],['me'=>0,'n'=>'文件传输助手','t'=>'周一','x'=>'文件已自动同步到你的云端。']],
];
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="aqua">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>会话视图 Demo B · 全屏覆盖层</title>
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
  .wrap { max-width: 760px; margin: 14px auto 40px; padding: 0 20px; }
  .panel { background: var(--glass-bg, rgba(255,255,255,.8)); border: 1px solid var(--border, #e5e5ea); border-radius: var(--radius-lg, 18px); overflow: hidden; }
  .panel h2 { margin: 0; padding: 14px 16px; font-size: 14px; border-bottom: 1px solid var(--border, #e5e5ea); }
  .row { display: flex; align-items: center; gap: 12px; padding: 12px 16px; cursor: pointer; border-bottom: 1px solid var(--border, #f0f0f2); }
  .row:hover { background: var(--bg-card-hover, #f7f7fa); }
  .row .ic { width: 42px; height: 42px; border-radius: var(--radius-md); display: grid; place-items: center; font-size: 17px; background: color-mix(in srgb, var(--primary) 14%, transparent); color: var(--primary); flex-shrink: 0; }
  .row .inf { flex: 1; min-width: 0; }
  .row .nm { font-size: 14px; font-weight: 600; }
  .row .pr { font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .row .mt { font-size: 11px; color: var(--text-subtle); }
  .row .bd { min-width: 18px; height: 18px; padding: 0 6px; border-radius: 10px; font-size: 11px; font-weight: 700; background: var(--primary); color: #fff; display: inline-flex; align-items: center; justify-content: center; }
  .hint-tap { font-size: 12px; color: var(--text-muted); text-align: center; padding: 6px 0; }
  /* 全屏覆盖层 */
  .overlay { position: fixed; inset: 0; background: var(--app-bg, #f5f5f7); z-index: 1000; overflow-y: auto; transform: translateY(100%); transition: transform .25s ease; }
  .overlay.open { transform: translateY(0); }
  .ov-head { position: sticky; top: 0; display: flex; align-items: center; gap: 12px; padding: 14px 20px; background: var(--glass-bg, rgba(255,255,255,.9)); backdrop-filter: blur(14px); border-bottom: 1px solid var(--border); z-index: 5; }
  .ov-back { border: 0; background: var(--bg-card-hover); width: 36px; height: 36px; border-radius: 50%; font-size: 15px; cursor: pointer; color: var(--text); }
  .ov-title { font-size: 16px; font-weight: 700; }
  .ov-sub { font-size: 12px; color: var(--text-muted); }
  .ov-body { max-width: 760px; margin: 0 auto; padding: 20px; }
  .msg { display: flex; gap: 10px; margin-bottom: 14px; }
  .msg .av { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; font-size: 13px; color: #fff; flex-shrink: 0; }
  .msg .bub { background: var(--bg-card-hover, #f2f2f5); border-radius: 4px 12px 12px 12px; padding: 9px 12px; font-size: 14px; max-width: 75%; }
  .msg.me { flex-direction: row-reverse; }
  .msg.me .bub { background: var(--primary); color: #fff; border-radius: 12px 4px 12px 12px; }
  .msg .time { font-size: 11px; color: var(--text-subtle); margin-top: 4px; }
</style>
</head>
<body>
  <div class="demo-top"><h1>会话视图 <span class="pill">Demo B · 全屏覆盖层</span></h1></div>
  <div class="demo-bar">
    <a href="index.php">← 返回对比页</a>
    <a href="demo_a.php">A · 中栏</a>
    <a class="on" href="demo_b.php">B · 全屏覆盖</a>
    <a href="demo_c.php">C · 行内展开</a>
  </div>
  <div class="wrap">
    <div class="hint-tap"><i class="fas fa-hand-pointer"></i> 点击任意会话 → 全屏消息覆盖层（列表保留在底层，返回键关闭）</div>
    <div class="panel">
      <h2><i class="fas fa-comment"></i> 会话列表</h2>
      <div id="list"></div>
    </div>
  </div>

  <!-- 覆盖层 -->
  <div class="overlay" id="ov">
    <div class="ov-head">
      <button class="ov-back" id="ovBack"><i class="fas fa-arrow-left"></i></button>
      <div><div class="ov-title" id="ovTitle"></div><div class="ov-sub" id="ovSub"></div></div>
    </div>
    <div class="ov-body" id="ovBody"></div>
  </div>

<script>
var chats = <?php echo json_encode($demoChats, JSON_UNESCAPED_UNICODE); ?>;
var msgs  = <?php echo json_encode($demoMsgs, JSON_UNESCAPED_UNICODE); ?>;
var icons = { channel:'bullhorn', contact:'user', group:'users', bot:'robot', filehelper:'inbox' };
var list = document.getElementById('list');
chats.forEach(function (c) {
  var r = document.createElement('div'); r.className = 'row';
  r.innerHTML = '<span class="ic"><i class="fas fa-'+c.icon+'"></i></span>'
    + '<div class="inf"><div class="nm">'+c.name+'</div><div class="pr">'+c.last+'</div></div>'
    + '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">'
    + '<div class="mt">'+c.time+'</div>'
    + (c.unread>0 ? '<span class="bd">'+(c.unread>99?'99+':c.unread)+'</span>' : '')
    + '</div>';
  r.onclick = function () { open(c); };
  list.appendChild(r);
});
function open(c) {
  document.getElementById('ovTitle').textContent = c.name;
  document.getElementById('ovSub').textContent = c.last;
  var body = document.getElementById('ovBody');
  body.innerHTML = '';
  (msgs[c.id] || []).forEach(function (m) {
    var d = document.createElement('div'); d.className = 'msg' + (m.me ? ' me' : '');
    var meta = '<div><div class="bub">'+m.x+'</div><div class="time">'+m.t+'</div></div>';
    if (m.me) { d.innerHTML = meta; }
    else { d.innerHTML = '<span class="av" style="background:var(--primary);">'+m.n.charAt(0)+'</span>'+meta; }
    body.appendChild(d);
  });
  document.getElementById('ov').classList.add('open');
}
document.getElementById('ovBack').onclick = function () { document.getElementById('ov').classList.remove('open'); };
document.querySelector('.overlay').addEventListener('click', function (e) { if (e.target === this) this.classList.remove('open'); });
</script>
</body>
</html>
