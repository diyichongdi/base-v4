<?php
/**
 * demos/index.php — 会话视图 Demo 对比入口
 * 汇总 A/B/C 三个会话视图样板，供选型后用某一版接入正式 IM。
 */
define('IM_STANDALONE', true);
require_once dirname(dirname(dirname(__FILE__))) . '/security.php';

if (!isLoggedIn('user_id')) {
    header('Location: ../../login.php?next=' . rawurlencode('im/demos/index.php'));
    exit;
}

$demos = [
    ['file' => 'demo_a.php', 'tag' => 'A', 'title' => '双栏·中栏', 'icon' => 'columns', 'desc' => '左侧会话列表 + 中间消息区 + 右侧资料卡。中栏宽度可调，信息密度高，一次可同时看到列表与消息，适合多任务沉浸处理。', 'pro' => ['列表与消息同屏可见', '中栏宽度可拖拽', '信息密度最高']],
    ['file' => 'demo_b.php', 'tag' => 'B', 'title' => '全屏覆盖层', 'icon' => 'expand', 'desc' => '仅会话列表一列，点击某会话 → 全屏消息覆盖层，列表保留在底层，返回键/遮罩关闭。仿论坛贴文页，聚焦单会话、干扰少。', 'pro' => ['单会话强聚焦', '返回键/遮罩关闭', '仿论坛贴文页']],
    ['file' => 'demo_c.php', 'tag' => 'C', 'title' => '行内联展开', 'icon' => 'inbox', 'desc' => '邮件式收件箱。点击某行 → 会话在本行下方行内展开消息，其余内容自动下移，无独立消息栏，列表形态最简洁。', 'pro' => ['列表最简洁', '邮件式直觉', '无独立消息栏']],
];
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="aqua">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>会话视图 Demo 对比</title>
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
<link rel="stylesheet" href="../styles.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  body { margin: 0; background: var(--app-bg, #f5f5f7); color: var(--text); }
  .demo-top { max-width: 1080px; margin: 0 auto; padding: 26px 20px 6px; text-align: center; }
  .demo-top h1 { font-size: 22px; margin: 0 0 6px; }
  .demo-top p { font-size: 13px; color: var(--text-muted); margin: 0 0 18px; }
  .grid { max-width: 1080px; margin: 0 auto 40px; padding: 0 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 18px; }
  .card { background: var(--glass-bg, rgba(255,255,255,.8)); border: 1px solid var(--border, #e5e5ea); border-radius: var(--radius-lg, 18px); padding: 22px; display: flex; flex-direction: column; transition: var(--transition, .3s); }
  .card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg, 0 8px 28px rgba(0,0,0,.08)); }
  .card .tag { width: 44px; height: 44px; border-radius: var(--radius-md); display: grid; place-items: center; font-size: 20px; font-weight: 800; color: #fff; background: var(--primary); margin-bottom: 14px; }
  .card h2 { margin: 0 0 4px; font-size: 18px; display: flex; align-items: center; gap: 8px; }
  .card h2 .tt { font-size: 11px; font-weight: 600; color: var(--text-muted); }
  .card .desc { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin-bottom: 16px; flex: 1; }
  .card ul { list-style: none; margin: 0 0 20px; padding: 0; }
  .card li { font-size: 12px; color: var(--text); padding: 5px 0; }
  .card li i { color: var(--primary); margin-right: 7px; width: 14px; }
  .card a { display: block; text-align: center; padding: 10px; border-radius: 12px; background: var(--primary); color: #fff; text-decoration: none; font-size: 14px; font-weight: 600; }
  .card a:hover { background: var(--primary-dark); }
</style>
</head>
<body>
  <div class="demo-top">
    <h1>会话视图 Demo 对比</h1>
    <p>三版独立的会话呈现样板（仅展示、只读示例数据）。请逐一打开体验，选定后我再将所选方案应用到正式即时通讯。</p>
  </div>
  <div class="grid">
    <?php foreach ($demos as $d): ?>
    <div class="card">
      <div class="tag"><?php echo $d['tag']; ?></div>
      <h2><i class="fas fa-<?php echo $d['icon']; ?>"></i> <?php echo $d['title']; ?> <span class="tt">Demo <?php echo $d['tag']; ?></span></h2>
      <p class="desc"><?php echo $d['desc']; ?></p>
      <ul>
        <?php foreach ($d['pro'] as $p): ?><li><i class="fas fa-check-circle"></i><?php echo $p; ?></li><?php endforeach; ?>
      </ul>
      <a href="<?php echo $d['file']; ?>">体验 Demo <?php echo $d['tag']; ?> <i class="fas fa-arrow-right"></i></a>
    </div>
    <?php endforeach; ?>
  </div>
</body>
</html>
