<?php
/**
 * 暗网导航页面 — 解析书签文件并渲染为分类导航
 */
require_once 'security.php';

$logged = isLoggedIn();
$BASE = '';
$pageTitle = '暗网导航';
$active = 'darkweb';

$bookmarkFile = __DIR__ . '/data/bookmarks.html';

function parseBookmarks($file) {
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);

    // Detect and convert to UTF-8
    $encoding = mb_detect_encoding($content, ['UTF-8', 'GB2312', 'GBK', 'Latin1'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
    }

    // Add UTF-8 BOM if missing and content has multibyte
    if (!preg_match('/^\xEF\xBB\xBF/', $content) && function_exists('mb_check_encoding')) {
        $content = preg_replace('/ï»¿/', '', $content); // Remove any BOM artifacts
    }

    $folders = [];

    // Extract top-level folders (H3 tags with following DL blocks) — u modifier for UTF-8
    preg_match_all('#<DT><H3[^>]*>(.+?)</H3>\s*<DL>(.*?)</DL>#isu', $content, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        $folderName = trim($m[1]);
        $innerDl = $m[2];

        $links = [];

        // Extract direct links in this folder — u modifier for UTF-8
        preg_match_all('#<DT><A\s+HREF="([^"]+)"[^>]*>(.+?)</A>#isu', $innerDl, $linkMatches, PREG_SET_ORDER);
        foreach ($linkMatches as $lm) {
            $links[] = [
                'url' => trim($lm[1]),
                'name' => trim(html_entity_decode($lm[2], ENT_QUOTES, 'UTF-8')),
            ];
        }

        // Extract subfolders (nested H3 with DL) — u modifier for UTF-8
        preg_match_all('#<DT><H3[^>]*>(.+?)</H3>\s*<DL>(.*?)</DL>#isu', $innerDl, $subMatches, PREG_SET_ORDER);
        foreach ($subMatches as $sm) {
            $subName = trim($sm[1]);
            $subLinks = [];
            preg_match_all('#<DT><A\s+HREF="([^"]+)"[^>]*>(.+?)</A>#isu', $sm[2], $subLinkMatches, PREG_SET_ORDER);
            foreach ($subLinkMatches as $slm) {
                $subLinks[] = [
                    'url' => trim($slm[1]),
                    'name' => trim(html_entity_decode($slm[2], ENT_QUOTES, 'UTF-8')),
                ];
            }
            if ($subLinks) {
                $links[] = [
                    'is_subfolder' => true,
                    'name' => $subName,
                    'links' => $subLinks,
                ];
            }
        }

        if ($links) {
            $folders[] = [
                'name' => $folderName,
                'links' => $links,
            ];
        }
    }

    return $folders;
}

function isOnion($url) {
    return strpos($url, '.onion/') !== false || strpos($url, '.onion') !== false;
}

$bookmarks = parseBookmarks($bookmarkFile);

require 'inc/header.php';
?>

<style>
    .dw-link { transition: background .2s ease, color .2s ease; color: var(--text); }
    .dw-link:hover { background: var(--primary, #0071e3); color: #fff; }
    .dw-link:focus-visible { outline: 2px solid var(--primary, #0071e3); outline-offset: 2px; }
</style>

<main class="main container" style="max-width:1200px;margin:0 auto;padding:24px;">
    <div class="card" style="background:var(--glass-bg);backdrop-filter:blur(20px);border:1px solid var(--border);border-radius:20px;box-shadow:var(--glass-shadow);margin-bottom:24px;">
        <div style="padding:24px 32px;border-bottom:1px solid var(--border);">
            <h1 style="font-size:24px;font-weight:700;color:var(--text);margin:0;display:flex;align-items:center;gap:10px;">
                <i class="fas fa-globe-africa" style="color:var(--primary);"></i>
                <span>暗网导航 Dark Web Navigation</span>
            </h1>
            <p style="color:var(--text-subtle);font-size:13px;margin-top:4px;">
                收录 Tor / I2P / 零网等暗网资源 — 仅用于安全研究与教育
            </p>
        </div>

        <?php if (empty($bookmarks)): ?>
            <div style="padding:40px;text-align:center;color:var(--text-subtle);">
                书签文件未找到或解析失败
            </div>
        <?php else: ?>
            <div id="darkNavContent">
            <?php foreach ($bookmarks as $folder): ?>
                <div style="margin-bottom:8px;">
                    <div style="padding:14px 24px;background:var(--bg-soft, rgba(0,0,0,.04));border-bottom:1px solid var(--border);font-weight:600;color:var(--text-strong);font-size:15px;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-folder" style="color:var(--primary);font-size:13px;"></i>
                        <span><?php echo htmlspecialchars($folder['name']); ?></span>
                        <span style="margin-left:auto;font-size:12px;color:var(--text-subtle);"><?php echo count($folder['links']); ?> 项</span>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:2px;background:var(--bg-soft, rgba(0,0,0,.04));border-bottom:1px solid var(--border);">
                        <?php foreach ($folder['links'] as $link): ?>
                            <?php if (isset($link['is_subfolder'])): ?>
                                <div style="padding:12px 16px;border-bottom:1px solid var(--border);">
                                    <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:8px;"><?php echo htmlspecialchars($link['name']); ?></div>
                                    <?php foreach ($link['links'] as $subLink): ?>
                                        <a href="<?php echo htmlspecialchars($subLink['url']); ?>" target="_blank" rel="noopener noreferrer" class="dw-link"
                                           style="display:block;padding:6px 8px;margin:2px 0;border-radius:6px;font-size:12px;text-decoration:none;">
                                            <?php echo htmlspecialchars($subLink['name']); ?>
                                            <?php if (isOnion($subLink['url'])): ?>
                                                <span style="font-size:10px;color:var(--warning);margin-left:4px;">.onion</span>
                                            <?php endif; ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank" rel="noopener noreferrer" class="dw-link"
                                   style="display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1px solid var(--border);text-decoration:none;font-size:14px;">
                                    <?php
                                    $icon = isOnion($link['url']) ? 'globe' :
                                           (strpos($link['url'], 'github.com') !== false ? 'github' :
                                           (filter_var($link['url'], FILTER_VALIDATE_URL) ? 'link' : 'globe'));
                                    ?>
                                    <i class="fas fa-<?php echo $icon; ?>" style="color:var(--primary);font-size:13px;min-width:16px;"></i>
                                    <span style="flex:1;"><?php echo htmlspecialchars($link['name']); ?></span>
                                    <?php if (isOnion($link['url'])): ?>
                                        <span style="font-size:10px;color:var(--warning);background:var(--bg-soft, rgba(0,0,0,.04));padding:1px 6px;border-radius:4px;">Onion</span>
                                    <?php endif; ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php
$hideFooter = true;
require 'inc/footer.php';
?>
