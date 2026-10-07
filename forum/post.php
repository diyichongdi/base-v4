<?php
require_once '../security.php';
require_once 'init.php';

$postId = intval($_GET['id'] ?? 0);
if (!$postId) {
    header('Location: index.php');
    exit;
}

// 帖子详情需登录后查看
if (!isForumLoggedIn()) {
    header('Location: ../login.php?next=' . urlencode(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?')));
    exit;
}

$post = forumGetRow("SELECT p.*, u.username, c.name as category_name, c.name_en as category_name_en
    FROM forum_posts p
    JOIN maindb.users u ON p.user_id = u.id
    JOIN forum_categories c ON p.category_id = c.id
    WHERE p.id = :id", ['id' => $postId]);

if (!$post) {
    header('Location: index.php');
    exit;
}

forumExec("UPDATE forum_posts SET views = views + 1 WHERE id = :id", ['id' => $postId]);

$replies = forumQuery("SELECT r.*, u.username
    FROM forum_replies r
    JOIN maindb.users u ON r.user_id = u.id
    WHERE r.post_id = :post_id AND r.parent_id IS NULL
    ORDER BY r.created_at ASC", ['post_id' => $postId]);

foreach ($replies as &$reply) {
    $reply['children'] = forumQuery("SELECT r.*, u.username
        FROM forum_replies r
        JOIN maindb.users u ON r.user_id = u.id
        WHERE r.parent_id = :parent_id
        ORDER BY r.created_at ASC", ['parent_id' => $reply['id']]);
}
unset($reply);

$error = '';
$forumUser = getForumUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $forumUser) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = L('csrf_invalid');
    } else {
        $content = trim($_POST['content'] ?? '');
        $parentId = intval($_POST['parent_id'] ?? 0);

        if (empty($content)) {
            $error = L('content_empty');
        } elseif (strlen($content) < 5) {
            $error = L('content_short');
        } else {
            forumExec("INSERT INTO forum_replies (post_id, user_id, content, parent_id) VALUES (:post_id, :user_id, :content, :parent_id)", [
                'post_id' => $postId,
                'user_id' => $forumUser['id'],
                'content' => $content,
                'parent_id' => $parentId > 0 ? $parentId : null
            ]);
            forumExec("UPDATE forum_posts SET reply_count = reply_count + 1, updated_at = datetime('now') WHERE id = :id", ['id' => $postId]);
            header('Location: post.php?id=' . $postId);
            exit;
        }
    }
}

$lang = getLang();
$pageTitle = htmlspecialchars($post['title']);
$active = 'forum';
$BASE = '../';
require '../inc/header.php';
?>
<main class="main container" style="max-width:960px;">
    <div class="lg-card card reveal">
        <div class="post-detail-head">
            <div>
                <h1 class="page-title" style="font-size:23px;"><?php echo htmlspecialchars($post['title']); ?></h1>
                <div class="post-detail-meta">
                    <span><i class="fas fa-folder"></i> <?php echo $lang === 'zh' ? $post['category_name'] : $post['category_name_en']; ?></span>
                    <span><i class="fas fa-clock"></i> <?php echo timeAgo($post['created_at']); ?></span>
                    <span><i class="fas fa-eye"></i> <?php echo $post['views']; ?></span>
                    <span><i class="fas fa-comment"></i> <?php echo $post['reply_count']; ?></span>
                </div>
            </div>
            <div class="author-box">
                <span class="avatar lg"><?php echo strtoupper(substr($post['username'], 0, 1)); ?></span>
                <div class="author-info">
                    <strong><?php echo htmlspecialchars($post['username']); ?></strong>
                    <span class="text-muted">#<?php echo (int)$post['user_id']; ?></span>
                </div>
            </div>
        </div>
        <div class="post-detail-body"><?php echo nl2br(htmlspecialchars($post['content'])); ?></div>
    </div>

    <div class="lg-card card reveal">
        <div class="card-head"><h3><i class="fas fa-comment-dots"></i> <?php echo L('replies'); ?> (<?php echo $post['reply_count']; ?>)</h3></div>
        <div class="card-body">
            <?php if (empty($replies)): ?>
                <p class="text-muted"><?php echo L('no_replies'); ?></p>
            <?php else: ?>
                <?php foreach ($replies as $reply): ?>
                    <div class="reply-item" id="reply-<?php echo $reply['id']; ?>">
                        <span class="avatar sm"><?php echo strtoupper(substr($reply['username'], 0, 1)); ?></span>
                        <div class="reply-content">
                            <div class="reply-head">
                                <span class="reply-author"><?php echo htmlspecialchars($reply['username']); ?></span>
                                <span class="reply-time"><?php echo timeAgo($reply['created_at']); ?></span>
                            </div>
                            <div class="reply-text"><?php echo nl2br(htmlspecialchars($reply['content'])); ?></div>
                            <?php if ($forumUser): ?>
                                <div class="reply-actions">
                                    <a href="#" onclick="replyTo(<?php echo $reply['id']; ?>, '<?php echo htmlspecialchars(addslashes($reply['username'])); ?>');return false;">
                                        <i class="fas fa-reply"></i> <?php echo L('reply'); ?>
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($reply['children'])): ?>
                                <div class="child-replies">
                                    <?php foreach ($reply['children'] as $child): ?>
                                        <div class="reply-item child">
                                            <span class="avatar xs"><?php echo strtoupper(substr($child['username'], 0, 1)); ?></span>
                                            <div class="reply-content">
                                                <div class="reply-head">
                                                    <span class="reply-author"><?php echo htmlspecialchars($child['username']); ?></span>
                                                    <span class="reply-time"><?php echo timeAgo($child['created_at']); ?></span>
                                                </div>
                                                <div class="reply-text"><?php echo nl2br(htmlspecialchars($child['content'])); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($forumUser): ?>
                <div class="reply-form">
                    <h3 class="section-title" style="margin:0 0 12px;"><i class="fas fa-pen-to-square"></i> <?php echo L('post_reply'); ?></h3>
                    <?php if ($error): ?>
                        <div class="alert alert-error"><i class="fas fa-circle-exclamation" style="margin-top:2px;"></i> <?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="parent_id" id="parent_id" value="0">
                        <textarea name="content" id="reply-content" class="form-control" style="min-height:130px;" placeholder="<?php echo L('reply_placeholder'); ?>" required></textarea>
                        <button type="submit" class="btn btn-primary" style="margin-top:12px;"><i class="fas fa-paper-plane"></i> <?php echo L('submit_reply'); ?></button>
                    </form>
                </div>
            <?php else: ?>
                <div class="login-prompt">
                    <i class="fas fa-right-to-bracket"></i>
                    <p><?php echo L('login_to_reply'); ?></p>
                    <a href="../login.php?next=<?php echo urlencode(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?')); ?>" class="btn btn-primary"><?php echo L('login'); ?></a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<script>
    function replyTo(id, name) {
        document.getElementById('parent_id').value = id;
        var box = document.getElementById('reply-content');
        box.value = '@' + name + ' ';
        box.focus();
    }
</script>
<?php
require '../inc/footer.php';
?>
