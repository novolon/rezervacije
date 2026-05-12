<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/lang.php';
require_once '../includes/affiliate_auth.php';
require_once '../includes/affiliate_helper.php';
require_once '../includes/affiliate_session.php';

$sess  = require_affiliate();
$pdo   = getDB();
$affId = (int)$sess['id'];
$aff   = affiliate_get($pdo, $affId);

$pageTitle = t('aff.materials.page_title');
$extraCss  = ['design.css'];

// Filesystem layout: assets/affiliate-materials/{slug}/*
// Datoteke se naložijo ročno (FTP) – ena mapa na kategorijo. Affiliate jih le bere.
$matsRoot    = __DIR__ . '/../assets/affiliate-materials';
$matsBaseUrl = APP_URL . BASE_PATH . '/assets/affiliate-materials';

$categories = [
    ['logos',             'aff.materials.cat_logos_title',             'aff.materials.cat_logos_specs'],
    ['social-square',     'aff.materials.cat_social_square_title',     'aff.materials.cat_social_square_specs'],
    ['social-story',      'aff.materials.cat_social_story_title',      'aff.materials.cat_social_story_specs'],
    ['social-landscape',  'aff.materials.cat_social_landscape_title',  'aff.materials.cat_social_landscape_specs'],
    ['og',                'aff.materials.cat_og_title',                'aff.materials.cat_og_specs'],
    ['banners',           'aff.materials.cat_banners_title',           'aff.materials.cat_banners_specs'],
    ['email-banner',      'aff.materials.cat_email_title',             'aff.materials.cat_email_specs'],
];

function fmtFileSize(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
}

function loadCategoryFiles(string $rootDir, string $slug): array {
    $dir = $rootDir . '/' . $slug;
    if (!is_dir($dir)) return [];
    $files = [];
    foreach (glob($dir . '/*') as $f) {
        if (!is_file($f)) continue;
        $name = basename($f);
        // Skip dot files / readme
        if ($name[0] === '.' || strtolower($name) === 'readme.md' || strtolower($name) === 'readme.txt') continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $isImage = in_array($ext, ['jpg','jpeg','png','webp','gif','svg'], true);
        $w = $h = null;
        if ($isImage && $ext !== 'svg') {
            $info = @getimagesize($f);
            if ($info) { $w = (int)$info[0]; $h = (int)$info[1]; }
        }
        $files[] = [
            'name'    => $name,
            'ext'     => $ext,
            'size'    => filesize($f),
            'width'   => $w,
            'height'  => $h,
            'isImage' => $isImage,
        ];
    }
    usort($files, fn($a,$b) => strcmp($a['name'], $b['name']));
    return $files;
}

require_once '../includes/html_head.php';
?>
<body>
<div class="rz-app" id="rz-app">
<?php require_once '_nav.php'; ?>

<main class="rz-main">
    <div class="rz-topbar">
        <div>
            <h1 class="rz-h1"><?= t('aff.materials.title') ?></h1>
            <p class="rz-top-sub"><?= t('aff.materials.subtitle') ?></p>
        </div>
    </div>

    <!-- Referenčna povezava (kopirajte poleg slike) -->
    <div class="rz-card" style="margin-bottom:16px">
        <div style="background:var(--accent-soft);border:1px solid color-mix(in oklab,var(--accent) 30%,transparent);border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:12px">
            <div style="flex:1;min-width:0">
                <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-mute);margin-bottom:4px"><?= t('aff.materials.copy_link_label') ?></div>
                <span id="ref-link" style="font-size:13px;font-family:var(--font-mono);color:var(--ink);word-break:break-all"><?= htmlspecialchars(APP_URL . BASE_PATH . '/?ref=' . ($aff['ref_code'] ?? ''), ENT_QUOTES) ?></span>
            </div>
            <button class="rz-btn" onclick="copyText(document.getElementById('ref-link').textContent, this)" style="white-space:nowrap;flex-shrink:0"><?= t('aff.common.copy') ?></button>
        </div>
        <p style="margin:10px 0 0;font-size:12px;color:var(--ink-mute)"><?= t('aff.materials.intro') ?></p>
    </div>

    <?php foreach ($categories as [$slug, $titleKey, $specsKey]):
        $files = loadCategoryFiles($matsRoot, $slug);
    ?>
    <div class="rz-card" style="margin-bottom:16px">
        <div class="rz-card-head" style="flex-direction:column;align-items:flex-start;gap:4px">
            <h2 class="rz-card-title" style="font-size:15px"><?= t($titleKey) ?></h2>
            <p style="font-size:12px;color:var(--ink-mute);margin:0"><?= t($specsKey) ?></p>
        </div>

        <?php if (empty($files)): ?>
        <div style="background:var(--bg-sunken);border:1px dashed var(--line);border-radius:10px;padding:20px;text-align:center;color:var(--ink-mute);font-size:13px">
            <?= t('aff.materials.empty_category') ?>
            <div style="margin-top:6px;font-family:var(--font-mono);font-size:11px"><?= htmlspecialchars('assets/affiliate-materials/' . $slug . '/', ENT_QUOTES) ?></div>
        </div>
        <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px">
            <?php foreach ($files as $f):
                $url = $matsBaseUrl . '/' . rawurlencode($slug) . '/' . rawurlencode($f['name']);
                $dim = ($f['width'] && $f['height']) ? $f['width'] . '×' . $f['height'] . ' px' : strtoupper($f['ext']);
            ?>
            <div style="border:1px solid var(--line);border-radius:12px;overflow:hidden;background:#fff;display:flex;flex-direction:column">
                <div style="aspect-ratio:1/1;background:repeating-conic-gradient(#f3f0ea 0 25%, #fff 0 50%) 0/16px 16px;display:flex;align-items:center;justify-content:center;overflow:hidden">
                    <?php if ($f['isImage']): ?>
                    <img src="<?= htmlspecialchars($url, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($f['name'], ENT_QUOTES) ?>" loading="lazy" style="max-width:100%;max-height:100%;object-fit:contain;display:block">
                    <?php else: ?>
                    <div style="text-align:center;font-size:11px;color:var(--ink-mute)">
                        <div style="font-size:24px;margin-bottom:4px">📄</div>
                        <?= strtoupper(htmlspecialchars($f['ext'], ENT_QUOTES)) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div style="padding:10px 12px 12px;display:flex;flex-direction:column;gap:8px">
                    <div style="font-size:12px;font-weight:600;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($f['name'], ENT_QUOTES) ?>">
                        <?= htmlspecialchars($f['name'], ENT_QUOTES) ?>
                    </div>
                    <div style="font-size:11px;color:var(--ink-mute);font-family:var(--font-mono);display:flex;justify-content:space-between">
                        <span><?= htmlspecialchars($dim, ENT_QUOTES) ?></span>
                        <span><?= htmlspecialchars(fmtFileSize($f['size']), ENT_QUOTES) ?></span>
                    </div>
                    <div style="display:flex;gap:6px">
                        <a class="rz-btn" href="<?= htmlspecialchars($url, ENT_QUOTES) ?>" download style="flex:1;justify-content:center;padding:6px 8px;font-size:12px"><?= t('aff.materials.download') ?></a>
                        <button type="button" class="rz-btn" data-url="<?= htmlspecialchars($url, ENT_QUOTES) ?>" onclick="copyMaterialUrl(this)" style="flex:1;justify-content:center;padding:6px 8px;font-size:12px"><?= t('aff.materials.copy_url') ?></button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</main>
</div>

<script>
const COPIED = <?= json_encode(t('aff.common.copied'), JSON_UNESCAPED_UNICODE) ?>;
function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.textContent;
        btn.textContent = COPIED;
        setTimeout(() => btn.textContent = orig, 2000);
    });
}
function copyMaterialUrl(btn) {
    copyText(btn.dataset.url, btn);
}
</script>
</body>
</html>
