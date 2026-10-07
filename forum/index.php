<?php
require_once '../security.php';
require_once 'init.php';

$lang = getLang();

// 获取板块列表
$categories = forumQuery("SELECT c.*,
    (SELECT COUNT(*) FROM forum_posts WHERE category_id = c.id) as post_count,
    (SELECT COUNT(*) FROM forum_replies r JOIN forum_posts p ON r.post_id = p.id WHERE p.category_id = c.id) as reply_count
    FROM forum_categories c WHERE is_hidden = 0 ORDER BY sort_order, id");

// 获取最新帖子
$latestPosts = forumQuery("SELECT p.*, u.username, c.name as category_name, c.name_en as category_name_en
    FROM forum_posts p
    JOIN maindb.users u ON p.user_id = u.id
    JOIN forum_categories c ON p.category_id = c.id
    WHERE p.is_pinned = 0
    ORDER BY p.created_at DESC LIMIT 10");

// 获取置顶帖子
$pinnedPosts = forumQuery("SELECT p.*, u.username, c.name as category_name, c.name_en as category_name_en
    FROM forum_posts p
    JOIN maindb.users u ON p.user_id = u.id
    JOIN forum_categories c ON p.category_id = c.id
    WHERE p.is_pinned = 1
    ORDER BY p.created_at DESC LIMIT 5");

$stats = [
    'users' => (int)(dbGetRow("SELECT COUNT(*) as c FROM users")['c'] ?? 0),
    'posts' => (int)(forumGetRow("SELECT COUNT(*) as c FROM forum_posts")['c'] ?? 0),
    'replies' => (int)(forumGetRow("SELECT COUNT(*) as c FROM forum_replies")['c'] ?? 0),
    'today' => (int)(forumGetRow("SELECT COUNT(*) as c FROM forum_posts WHERE date(created_at) = date('now')")['c'] ?? 0)
];

$forumUser = getForumUser();
$forumAuthNext = urlencode(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?'));
$pageTitle = L('forum');
$active = 'forum';
$BASE = '../';
require '../inc/header.php';
?>
<main class="main container">
    <div class="page-head reveal">
        <div>
            <h1 class="page-title"><?php echo L('forum'); ?></h1>
            <p class="text-muted"><?php echo L('forum_subtitle'); ?></p>
        </div>
        <?php if ($forumUser): ?>
            <a class="btn btn-primary" href="new-post.php"><i class="fas fa-plus"></i> <?php echo L('new_post'); ?></a>
        <?php endif; ?>
    </div>

    <?php
    // 公告栏（与商城共用主站公告）
    $anns = dbQuery("SELECT * FROM announcements WHERE is_active = 1 ORDER BY id DESC LIMIT 5");
    if (is_array($anns) && !empty($anns)):
    ?>
        <div class="announce-bar reveal">
            <div class="announce-head"><i class="fas fa-bullhorn"></i> <?php echo L('announcements'); ?></div>
            <?php foreach ($anns as $a): ?>
                <div class="announce-item">
                    <strong><?php echo htmlspecialchars($a['title']); ?></strong>
                    <span><?php echo htmlspecialchars($a['content']); ?></span>
                    <small><?php echo htmlspecialchars($a['created_at']); ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="forum-cols">
        <div class="forum-main">
            <div class="lg-card card reveal">
                <div class="card-head"><h3><i class="fas fa-th-large"></i> <?php echo L('categories'); ?></h3></div>
                <div class="card-body">
                    <?php foreach ($categories as $cat): ?>
                        <a href="category.php?id=<?php echo $cat['id']; ?>" class="list-row">
                            <div class="cat-icon"><i class="<?php echo forumIcon($cat['icon']); ?>"></i></div>
                            <div class="list-main">
                                <strong><?php echo $lang === 'zh' ? htmlspecialchars($cat['name']) : htmlspecialchars($cat['name_en']); ?></strong>
                                <span class="text-muted"><?php echo htmlspecialchars($cat['description']); ?></span>
                            </div>
                            <div class="list-meta">
                                <span><i class="fas fa-file-lines"></i> <?php echo $cat['post_count']; ?></span>
                                <span><i class="fas fa-comment"></i> <?php echo $cat['reply_count']; ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!empty($pinnedPosts)): ?>
                <div class="lg-card card reveal">
                    <div class="card-head"><h3><i class="fas fa-thumbtack"></i> <?php echo L('pinned_posts'); ?></h3></div>
                    <div class="card-body">
                        <?php foreach ($pinnedPosts as $post): ?>
                            <div class="post-row pinned">
                                <span class="avatar sm"><?php echo strtoupper(substr($post['username'], 0, 1)); ?></span>
                                <div class="list-main">
                                    <div>
                                        <span class="badge badge-primary"><?php echo L('pinned'); ?></span>
                                        <a class="post-title-link" href="post.php?id=<?php echo $post['id']; ?>"><?php echo htmlspecialchars($post['title']); ?></a>
                                    </div>
                                    <span class="text-muted">
                                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($post['username']); ?>
                                        &nbsp;·&nbsp; <i class="fas fa-folder"></i> <?php echo $lang === 'zh' ? $post['category_name'] : $post['category_name_en']; ?>
                                        &nbsp;·&nbsp; <?php echo timeAgo($post['created_at']); ?>
                                    </span>
                                </div>
                                <div class="list-meta">
                                    <span><i class="fas fa-eye"></i> <?php echo $post['views']; ?></span>
                                    <span><i class="fas fa-comment"></i> <?php echo $post['reply_count']; ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="lg-card card reveal">
                <div class="card-head"><h3><i class="fas fa-clock-rotate-left"></i> <?php echo L('latest_posts'); ?></h3></div>
                <div class="card-body">
                    <?php if (empty($latestPosts)): ?>
                        <p class="text-muted"><?php echo L('no_posts'); ?></p>
                    <?php else: ?>
                        <?php foreach ($latestPosts as $post): ?>
                            <div class="post-row">
                                <span class="avatar sm"><?php echo strtoupper(substr($post['username'], 0, 1)); ?></span>
                                <div class="list-main">
                                    <a class="post-title-link" href="post.php?id=<?php echo $post['id']; ?>"><?php echo htmlspecialchars($post['title']); ?></a>
                                    <span class="text-muted">
                                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($post['username']); ?>
                                        &nbsp;·&nbsp; <i class="fas fa-folder"></i> <?php echo $lang === 'zh' ? $post['category_name'] : $post['category_name_en']; ?>
                                        &nbsp;·&nbsp; <?php echo timeAgo($post['created_at']); ?>
                                    </span>
                                </div>
                                <div class="list-meta">
                                    <span><i class="fas fa-eye"></i> <?php echo $post['views']; ?></span>
                                    <span><i class="fas fa-comment"></i> <?php echo $post['reply_count']; ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <aside class="forum-side">
            <?php if ($forumUser): ?>
                <div class="lg-card card reveal">
                    <div class="card-body text-center">
                        <span class="avatar xl" style="margin:0 auto 14px;"><?php echo strtoupper(substr($forumUser['username'], 0, 1)); ?></span>
                        <h4 style="margin-bottom:4px;"><?php echo htmlspecialchars($forumUser['nick'] !== '' ? $forumUser['nick'] : $forumUser['username']); ?></h4>
                        <p class="text-muted"><?php echo strtoupper($forumUser['role'] ?? 'user'); ?> · <span class="badge badge-accent">#<?php echo $forumUser['id']; ?></span></p>
                        <a href="new-post.php" class="btn btn-primary btn-block" style="margin-top:14px;"><?php echo L('new_post'); ?></a>
                        <a href="../logout.php" class="btn btn-ghost btn-block" style="margin-top:10px;"><i class="fas fa-right-from-bracket"></i> <?php echo L('logout'); ?></a>
                    </div>
                </div>
            <?php else: ?>
                <div class="lg-card card reveal">
                    <div class="card-head"><h3><i class="fas fa-user-plus"></i> <?php echo L('join_forum'); ?></h3></div>
                    <div class="card-body text-center">
                        <p class="text-muted"><?php echo L('join_forum_desc'); ?></p>
                        <a href="../login.php?panel=register&next=<?php echo $forumAuthNext; ?>" class="btn btn-primary btn-block" style="margin-top:14px;"><?php echo L('register_now'); ?></a>
                        <a href="../login.php?next=<?php echo $forumAuthNext; ?>" class="btn btn-ghost btn-block" style="margin-top:10px;"><?php echo L('login'); ?></a>
                    </div>
                </div>
            <?php endif; ?>

        </aside>
    </div>
</main>
<?php
require '../inc/footer.php';
?>
