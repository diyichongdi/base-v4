<?php
/**
 * 公共尾部模板 (可管理员配置)
 * $hideFooter - true 时隐藏页脚
 */
if (!isset($hideFooter)) $hideFooter = false;
if (!isset($BASE)) $BASE = '';
$footerContent = getSetting('footer_content', '');
$footerCustom = getSetting('footer_custom', true);
?>
<?php if (!$hideFooter): ?>
<footer class="footer">
    <div class="container">
        <?php if ($footerCustom && $footerContent): ?>
            <?php echo sanitizeHtml($footerContent); ?>
        <?php else: ?>
            <p style="margin-top:4px;">
                <a href="<?php echo $BASE; ?>dashboard.php">控制台</a> ·
                <a href="<?php echo $BASE; ?>market/mall.php">市场</a> ·
                <a href="<?php echo $BASE; ?>forum/index.php">论坛</a>
            </p>
            <p style="font-size:12px;color:var(--text-subtle);margin-top:4px;">
                <i class="fas fa-shield-halberd"></i> 基地 —  anonymous marketplace · 仅供安全研究
            </p>
        <?php endif; ?>
    </div>
</footer>
<?php endif; ?>

<script src="<?php echo $BASE; ?>assets/app.js"></script>
</body>
</html>
