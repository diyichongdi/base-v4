<?php
require_once '../security.php';
require_once 'init.php';

if (!isForumLoggedIn()) {
    header('Location: ../login.php?next=' . urlencode(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?')));
    exit;
}

$forumUser = getForumUser();
$categories = forumQuery("SELECT * FROM forum_categories WHERE is_hidden = 0 ORDER BY sort_order, id");

$error = '';
$preset = intval($_GET['category'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = L('csrf_invalid');
    } else {
        $categoryId = intval($_POST['category_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');

        if ($categoryId <= 0) {
            $error = L('select_category');
        } elseif (empty($title)) {
            $error = L('title_empty');
        } elseif (strlen($title) < 5) {
            $error = L('title_short');
        } elseif (empty($content)) {
            $error = L('content_empty');
        } elseif (strlen($content) < 10) {
            $error = L('content_short');
        } else {
            forumExec("INSERT INTO forum_posts (category_id, user_id, title, content) VALUES (:category_id, :user_id, :title, :content)", [
                'category_id' => $categoryId,
                'user_id' => $forumUser['id'],
                'title' => $title,
                'content' => $content
            ]);
            header('Location: index.php');
            exit;
        }
    }
}

$lang = getLang();
$pageTitle = L('new_post');
$active = 'forum';
$BASE = '../';
require '../inc/header.php';
?>
<main class="main container" style="max-width:820px;">
    <div class="page-head reveal">
        <div>
            <h1 class="page-title"><i class="fas fa-plus-circle" style="margin-right:10px;color:var(--primary);"></i><?php echo L('new_post'); ?></h1>
            <p class="text-muted"><?php echo L('new_post_subtitle'); ?></p>
        </div>
    </div>

    <div class="lg-card card reveal">
        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom:20px;"><i class="fas fa-circle-exclamation" style="margin-top:2px;"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

            <div class="form-group">
                <label class="form-label"><?php echo L('category'); ?></label>
                <select name="category_id" class="form-control" required>
                    <option value=""><?php echo L('select_category'); ?></option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $cat['id'] === $preset ? 'selected' : ''; ?>>
                            <?php echo $lang === 'zh' ? $cat['name'] : $cat['name_en']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo L('title'); ?></label>
                <input type="text" name="title" class="form-control" placeholder="<?php echo L('title_placeholder'); ?>" minlength="5" required>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo L('content'); ?></label>
                <textarea name="content" class="form-control" style="min-height:280px;" placeholder="<?php echo L('content_placeholder'); ?>" minlength="10" required></textarea>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> <?php echo L('publish'); ?></button>
                <a href="index.php" class="btn btn-ghost"><?php echo L('cancel'); ?></a>
            </div>
        </form>
    </div>
</main>
<?php
require '../inc/footer.php';
?>
