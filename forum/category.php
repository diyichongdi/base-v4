<?php
require_once '../security.php';
require_once 'init.php';

$categoryId = intval($_GET['id'] ?? 0);
if (!$categoryId) {
    header('Location: index.php');
    exit;
}

$category = forumGetRow("SELECT * FROM forum_categories WHERE id = :id AND is_hidden = 0", ['id' => $categoryId]);
if (!$category) {
    header('Location: index.php');
    exit;
}

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$posts = forumQuery("SELECT p.*, u.username
    FROM forum_posts p
    JOIN maindb.users u ON p.user_id = u.id
    WHERE p.category_id = :category_id
    ORDER BY p.is_pinned DESC, p.created_at DESC
    LIMIT :limit OFFSET :offset",
    ['category_id' => $categoryId, 'limit' => $perPage, 'offset' => $offset]);

$total = forumGetRow("SELECT COUNT(*) as c FROM forum_posts WHERE category_id = :category_id", ['category_id' => $categoryId])['c'] ?? 0;
$totalPages = max(1, (int)ceil($total / $perPage));

$forumUser = getForumUser();
$lang = getLang();
$pageTitle = $lang === 'zh' ? $category['name'] : $category['name_en'];
$active = 'forum';
$BASE = '../';
require '../inc/header.php';
?>
<main class="main container">
    <div class="page-head reveal">
        <div>
            <h1 class="page-title"><i class="fas <?php echo forumIcon($category['icon']); ?>" style="margin-right:10px;color:var(--primary);"></i><?php echo $lang === 'zh' ? htmlspecialchars($category['name']) : htmlspecialchars($category['name_en']); ?></h1>
            <p class="text-muted"><?php echo htmlspecialchars($category['description']); ?></p>
        </div>
        <?php if ($forumUser): ?>
            <a class="btn btn-primary" href="new-post.php?category=<?php echo $categoryId; ?>"><i class="fas fa-plus"></i> <?php echo L('new_post'); ?></a>
        <?php endif; ?>
    </div>

    <div class="lg-card card reveal">
        <div class="card-body">
            <?php if (empty($posts)): ?>
                <div class="empty-state"><i class="fas fa-folder-open"></i><p><?php echo L('no_posts'); ?></p></div>
            <?php else: ?>
                <?php foreach ($posts as $post): ?>
                    <div class="post-row <?php echo $post['is_pinned'] ? 'pinned' : ''; ?>">
                        <span class="avatar sm"><?php echo strtoupper(substr($post['username'], 0, 1)); ?></span>
                        <div class="list-main">
                            <div>
                                <?php if ($post['is_pinned']): ?>
                                    <span class="badge badge-primary"><?php echo L('pinned'); ?></span>
                                <?php endif; ?>
                                <a class="post-title-link" href="post.php?id=<?php echo $post['id']; ?>"><?php echo htmlspecialchars($post['title']); ?></a>
                            </div>
                            <span class="text-muted">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($post['username']); ?>
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

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a class="page-btn" href="?id=<?php echo $categoryId; ?>&page=<?php echo $page - 1; ?>"><i class="fas fa-chevron-left"></i></a>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a class="page-btn <?php echo $i === $page ? 'active' : ''; ?>" href="?id=<?php echo $categoryId; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="?id=<?php echo $categoryId; ?>&page=<?php echo $page + 1; ?>"><i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php
require '../inc/footer.php';
?>
