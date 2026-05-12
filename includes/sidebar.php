<?php
/**
 * Sidebar – shared navigation (Rezble design)
 * Zahteva: $isAdmin, $fullName, $restaurants, $pdo, $pendingCount
 * Opcijsko: $currentPage (basename), $_SESSION['plan_slug']
 */

$_cp = basename($_SERVER['PHP_SELF']);

// Iniciali iz imena
$_nameParts = explode(' ', trim($fullName ?? ''));
$_initials  = '';
foreach (array_slice($_nameParts, 0, 2) as $_p) {
    $_initials .= mb_strtoupper(mb_substr($_p, 0, 1));
}

// Ime restavracije za prikaz
$_restName = '';
if (!empty($restaurants)) {
    if (count($restaurants) === 1) {
        $_restName = $restaurants[0]['name'];
    } else {
        if (!empty($_SESSION['restaurant_id'])) {
            foreach ($restaurants as $_r) {
                if ((int)$_r['id'] === (int)$_SESSION['restaurant_id']) {
                    $_restName = $_r['name'];
                    break;
                }
            }
        }
        if (!$_restName) $_restName = $restaurants[0]['name'];
    }
}

// Feature checks (samo za admin)
$_hasSurvey  = $isAdmin && isset($pdo) ? user_has_feature($pdo, (int)$_SESSION['user_id'], 'survey') : false;
$_hasGuests  = $isAdmin && isset($pdo) ? user_has_feature($pdo, (int)$_SESSION['user_id'], 'guest_database') : false;
$_hasWait    = $isAdmin && isset($pdo) ? user_has_feature($pdo, (int)$_SESSION['user_id'], 'waitlist') : false;

// Nastavitve URL – direktno na restaurant-edit.php za izbrano restavracijo;
// če restavracij še ni, gremo na seznam restavracij (kjer admin doda prvo).
$_settingsUrl = BASE_PATH . '/pages/restaurants.php';
if ($isAdmin && !empty($restaurants)) {
    $_selRestId = 0;
    if (!empty($_SESSION['restaurant_id'])) {
        foreach ($restaurants as $_r) {
            if ((int)$_r['id'] === (int)$_SESSION['restaurant_id']) {
                $_selRestId = (int)$_r['id'];
                break;
            }
        }
    }
    if (!$_selRestId) $_selRestId = (int)$restaurants[0]['id'];
    $_settingsUrl = BASE_PATH . '/pages/restaurant-edit.php?id=' . $_selRestId;
}
$_addRestUrl = BASE_PATH . '/pages/restaurants.php?add=1';

// Pending count – vedno reizračunamo tu z upoštevanjem izbrane restavracije
if (isset($pdo) && !empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') !== 'superadmin') {
    $_sbRestId = isset($_SESSION['restaurant_id']) ? (int)$_SESSION['restaurant_id'] : 0;
    if ($_sbRestId) {
        $_sbStmt = $pdo->prepare("
            SELECT COUNT(*) FROM reservations WHERE restaurant_id = ? AND status = 'pending'
        ");
        $_sbStmt->execute([$_sbRestId]);
    } elseif ($_SESSION['role'] === 'admin') {
        $_sbStmt = $pdo->prepare("
            SELECT COUNT(*) FROM reservations r
            JOIN restaurant_admins ra ON r.restaurant_id = ra.restaurant_id
            WHERE ra.user_id = ? AND r.status = 'pending'
        ");
        $_sbStmt->execute([$_SESSION['user_id']]);
    } else {
        $_sbStmt = null;
    }
    $pendingCount = $_sbStmt ? (int)$_sbStmt->fetchColumn() : 0;
}

// Paket slug
$_planSlug   = $_SESSION['plan_slug'] ?? 'basic';
$_planLabel  = strtoupper(defined('PLANS') && isset(PLANS[$_planSlug]['name']) ? PLANS[$_planSlug]['name'] : ucfirst($_planSlug));

// Nav item helper
function _rz_nav_item(string $href, string $icon, string $label, bool $active, int $pulseCount = 0): string {
    $cls = 'rz-nav-item' . ($active ? ' is-active' : '');
    $mark = $active ? '<span class="rz-nav-mark"></span>' : '';
    $pulse = $pulseCount > 0
        ? '<span class="rz-nav-pulse">' . (int)$pulseCount . '</span>'
        : '';
    return '<a href="' . htmlspecialchars($href) . '" class="' . $cls . '" data-tip="' . htmlspecialchars($label) . '">'
        . _rz_icon($icon)
        . '<span class="rz-nav-label">' . htmlspecialchars($label) . '</span>'
        . $pulse
        . $mark
        . '</a>';
}

function _rz_icon(string $name, int $size = 17): string {
    static $icons = null;
    if ($icons === null) {
        $icons = [
            'calendar'   => '<rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
            'list'       => '<path d="M4 6h16M4 12h16M4 18h10"/>',
            'users'      => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.2"/><path d="M3 19c0-3 3-5 6-5s6 2 6 5M14 18c0-2 2-4 5-4s5 1.5 5 3"/>',
            'chart'      => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
            'survey'     => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
            'wait'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'cog'        => '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.05a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.05a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
            'help'       => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 4.5 1.5c-.7.7-1.5 1.2-1.5 2.5"/><circle cx="12" cy="17" r=".7" fill="currentColor"/>',
            'card'       => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h3"/>',
            'bell'       => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 7 3 9H3c0-2 3-2 3-9ZM10 21a2 2 0 0 0 4 0"/>',
            'chevLeft'   => '<path d="m14 6-6 6 6 6"/>',
            'chevRight'  => '<path d="m10 6 6 6-6 6"/>',
            'chevDown'   => '<path d="m6 9 6 6 6-6"/>',
            'more'       => '<circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>',
            'user'       => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-7 8-7s8 3 8 7"/>',
            'logout'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
            'search'     => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
            'plus'       => '<path d="M12 5v14M5 12h14"/>',
            'close'      => '<path d="M6 6l12 12M6 18 18 6"/>',
            'phone'      => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2A19.8 19.8 0 0 1 2 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7 12.8 12.8 0 0 0 .7 2.8 2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.1-.5 12.8 12.8 0 0 0 2.8.7 2 2 0 0 1 1.7 2Z"/>',
            'mail'       => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
            'check'      => '<path d="m5 12 5 5 9-11"/>',
            'trash'      => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
            'edit'       => '<path d="M4 20h4l11-11-4-4L4 16v4ZM13 6l4 4"/>',
            'info'       => '<circle cx="12" cy="12" r="9"/><path d="M12 8v1M12 11v6"/>',
            'arrowRight' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        ];
    }
    $path = $icons[$name] ?? '';
    return '<svg class="rz-nav-icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}
?>
<aside class="rz-sidebar" id="rz-sidebar">

    <div class="rz-side-top">
        <a href="<?= BASE_PATH ?>/pages/main.php" class="rz-logo" id="rz-logo" data-tip="<?= t('nav.collapse') ?>">
            <img src="<?= BASE_PATH ?>/assets/images/rezble-white.svg" alt="Rezble" class="rz-logo-img-full display" style="height:24px;width:auto;flex:none">
            <?php
            // Inline icon.svg z zamenjavo temne barve na currentColor (svetlo na temnem sidebaru).
            $_iconSvg = @file_get_contents(__DIR__ . '/../assets/images/icon.svg');
            if ($_iconSvg !== false) {
                $_iconSvg = preg_replace('/<\?xml[^>]+\?>\s*/', '', $_iconSvg);
                $_iconSvg = str_ireplace('#1d4433', 'currentColor', $_iconSvg);
                // Vbrizgaj class + style za prikaz samo v collapsed stanju
                $_iconSvg = preg_replace(
                    '/<svg\b/',
                    '<svg class="rz-logo-img-icon" style="height:28px;width:28px;flex:none" aria-hidden="true"',
                    $_iconSvg,
                    1
                );
                echo $_iconSvg;
            }
            ?>
            <span class="rz-plan-badge"><?= h($_planLabel) ?></span>
        </a>
        <button class="rz-collapse" id="rz-collapse-btn" type="button" title="<?= t('nav.collapse') ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" id="rz-collapse-icon">
                <path d="m14 6-6 6 6 6"/>
            </svg>
        </button>
    </div>

    <?php if (!empty($restaurants) || $isAdmin): ?>
    <div class="rz-restaurant-switcher">
        <?php if ($isAdmin): ?>
            <?php if (!empty($restaurants)): ?>
            <button class="rz-rest-btn" type="button" id="rz-rest-toggle" aria-haspopup="true" aria-expanded="false" data-tip="<?= h($_restName) ?>">
                <span class="rz-rest-dot"></span>
                <span class="rz-rest-name"><?= h($_restName) ?></span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <?php else: ?>
            <a class="rz-rest-btn" href="<?= h($_addRestUrl) ?>" style="text-decoration:none" data-tip="<?= t('restaurants.add_first') ?>">
                <span class="rz-rest-dot" style="background:var(--sidebar-line)"></span>
                <span class="rz-rest-name"><?= t('restaurants.add_first') ?></span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            </a>
            <?php endif; ?>
            <?php if (!empty($restaurants)): ?>
            <div class="rz-rest-menu" id="rz-rest-menu" hidden>
                <?php $_activeRestId = !empty($_SESSION['restaurant_id']) ? (int)$_SESSION['restaurant_id'] : (int)$restaurants[0]['id']; ?>
                <?php foreach ($restaurants as $_r): ?>
                    <?php $_isSel = $_activeRestId === (int)$_r['id']; ?>
                    <button type="button"
                            class="rz-rest-menu-item<?= $_isSel ? ' is-active' : '' ?>"
                            data-rest-id="<?= (int)$_r['id'] ?>">
                        <span class="rz-rest-dot" style="background:<?= h($_r['color'] ?? 'var(--accent)') ?>"></span>
                        <?= h($_r['name']) ?>
                    </button>
                <?php endforeach; ?>
                <div style="height:1px;background:var(--line);margin:4px 2px"></div>
                <a class="rz-rest-menu-item" href="<?= BASE_PATH ?>/pages/restaurants.php" style="color:var(--ink-mute)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true" style="flex:none"><path d="M4 20h4l11-11-4-4L4 16v4ZM13 6l4 4"/></svg>
                    <span><?= t('restaurants.manage_btn') ?></span>
                </a>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="rz-rest-btn" style="cursor:default">
                <span class="rz-rest-dot"></span>
                <span class="rz-rest-name"><?= h($_restName) ?></span>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ((int)($pendingCount ?? 0) > 0): ?>
    <a href="<?= BASE_PATH ?>/pages/pending.php" class="rz-pending" title="<?= (int)$pendingCount ?> <?= t('nav.pending') ?>" data-tip="<?= t('nav.pending') ?> (<?= (int)$pendingCount ?>)">
        <span class="rz-pending-left">
            <?= _rz_icon('bell', 14) ?>
            <span><?= t('nav.pending') ?></span>
        </span>
        <span class="rz-pending-count"><?= (int)$pendingCount ?></span>
    </a>
    <?php endif; ?>

    <nav class="rz-nav">
        <?= _rz_nav_item(BASE_PATH . '/pages/main.php', 'calendar', t('nav.today'), $_cp === 'main.php') ?>
        <?php if ($_hasGuests): ?>
            <?= _rz_nav_item(BASE_PATH . '/pages/guests.php', 'users', t('nav.guests'), $_cp === 'guests.php') ?>
        <?php endif; ?>
        <?php if ($_hasWait): ?>
            <?= _rz_nav_item(BASE_PATH . '/pages/waitlist.php', 'wait', t('nav.waitlist'), $_cp === 'waitlist.php') ?>
        <?php endif; ?>
        <?= _rz_nav_item(BASE_PATH . '/pages/stats.php', 'chart', t('nav.stats'), $_cp === 'stats.php') ?>
        <?php if ($_hasSurvey): ?>
            <?= _rz_nav_item(BASE_PATH . '/pages/survey_results.php', 'survey', t('nav.survey'), in_array($_cp, ['survey.php','survey_builder.php','survey_results.php'])) ?>
        <?php endif; ?>
    </nav>

    <div class="rz-side-spacer"></div>

    <nav class="rz-nav">
        <button type="button" class="rz-nav-item" id="rz-nav-help" data-tip="<?= t('nav.help') ?>" style="background:none;border:none;width:100%;cursor:pointer;font-family:inherit">
            <?= _rz_icon('help') ?>
            <span class="rz-nav-label"><?= t('nav.help') ?></span>
        </button>
        <?php if ($isAdmin): ?>
        <?= _rz_nav_item($_settingsUrl, 'cog', t('nav.settings'), in_array($_cp, ['restaurants.php','restaurant-edit.php'])) ?>
        <?= _rz_nav_item(BASE_PATH . '/pages/billing.php', 'card', t('nav.billing'), $_cp === 'billing.php') ?>
        <?php endif; ?>
    </nav>
    <script>
    (function(){
        var btn = document.getElementById('rz-nav-help');
        if (!btn) return;
        btn.addEventListener('click', function(e){
            e.preventDefault();
            if (window.RezbleHelpChat && typeof window.RezbleHelpChat.open === 'function') {
                window.RezbleHelpChat.open();
            }
        });
    })();
    </script>

    <div class="rz-side-bottom">
        <div class="rz-user">
            <a href="<?= BASE_PATH ?>/pages/profile.php" class="rz-user-avatar" title="<?= t('nav.profile') ?>" data-tip="<?= h($fullName) ?>" style="text-decoration:none"><?= h($_initials) ?></a>
            <a href="<?= BASE_PATH ?>/pages/profile.php" class="rz-user-info" style="text-decoration:none;color:inherit">
                <div class="rz-user-name"><?= h($fullName) ?></div>
                <div class="rz-user-role"><?= $isAdmin ? t('nav.role_admin') : t('nav.role_staff') ?></div>
            </a>
            <a href="<?= BASE_PATH ?>/logout.php" class="rz-user-more" title="<?= t('nav.logout') ?>" data-tip="<?= t('nav.logout') ?>">
                <?= _rz_icon('logout', 15) ?>
            </a>
        </div>
    </div>

</aside>

<!-- Globalni APP_STATE (nastavi le če stran ga ni že definirala). -->
<script>
(function() {
    if (window.APP_STATE && typeof window.APP_STATE === 'object') return;
    window.APP_STATE = <?= json_encode([
        'base'         => BASE_PATH,
        'role'         => $_SESSION['role'] ?? null,
        'userId'       => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
        'restaurantId' => isset($_SESSION['restaurant_id']) ? (int)$_SESSION['restaurant_id'] : null,
    ], JSON_UNESCAPED_UNICODE) ?>;
})();
</script>

<div class="rz-mobile-bar" id="rz-mobile-bar">
    <button type="button" class="rz-mobile-toggle" id="rz-mobile-toggle" aria-label="Menu" aria-expanded="false">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="6" x2="21" y2="6"/>
            <line x1="3" y1="12" x2="21" y2="12"/>
            <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
    </button>
    <span class="rz-mobile-bar-brand" aria-label="Rezble">
        <svg viewBox="0 0 104 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M31.04,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.87-.21,1.56-.64,2.08-1.07,1.3-2.16,2.3-3.25,3.02-1.1.69-2.39,1.04-3.89,1.04s-2.69-.39-3.72-1.17c-1.01-.78-1.97-2.12-2.88-4.02-.74-1.52-1.32-2.64-1.74-3.35-.42-.74-.85-1.26-1.27-1.57-.4-.31-.91-.5-1.51-.57-.09.47-.35,1.94-.77,4.42-.18,1.12-.29,1.8-.34,2.04-.22,1.36-.67,2.41-1.34,3.15-.67.71-1.69,1.07-3.05,1.07-1.5,0-2.83-.44-3.99-1.31-1.14-.89-2.02-2.12-2.65-3.69-.63-1.59-.94-3.38-.94-5.39,0-3.75.65-6.99,1.94-9.72,1.32-2.73,3.15-4.8,5.5-6.23,2.37-1.45,5.1-2.18,8.18-2.18,2.15,0,3.94.32,5.4.97,1.45.65,2.53,1.54,3.22,2.68.72,1.14,1.07,2.42,1.07,3.85,0,1.25-.3,2.48-.91,3.69-.58,1.18-1.46,2.23-2.65,3.15-1.18.92-2.63,1.6-4.32,2.04,1.07.29,1.9.76,2.48,1.41s1.16,1.6,1.74,2.85c.63,1.34,1.24,2.32,1.84,2.95.63.63,1.34.94,2.15.94.72,0,1.4-.23,2.04-.7.65-.49,1.46-1.32,2.45-2.48.27-.31.57-.47.91-.47ZM8.45,20.94c-.76,0-1.29-.18-1.58-.54-.27-.36-.4-.76-.4-1.21,0-.54.17-.96.5-1.27.36-.31.76-.47,1.21-.47h.84c.36-2.19.69-4.08,1.01-5.66.29-1.45,1.23-2.18,2.82-2.18,1.27,0,1.91.57,1.91,1.71,0,.25-.01.44-.03.57l-1.01,5.56c1.21-.07,2.3-.36,3.29-.87,1.01-.51,1.8-1.23,2.38-2.14.6-.92.91-1.95.91-3.12,0-1.41-.48-2.51-1.44-3.32-.96-.8-2.37-1.21-4.22-1.21-2.19,0-4.11.55-5.77,1.64-1.63,1.07-2.91,2.68-3.82,4.83-.92,2.12-1.37,4.71-1.37,7.77,0,1.43.15,2.66.44,3.69.29,1.03.65,1.8,1.07,2.31.42.51.83.77,1.21.77.29,0,.53-.15.7-.44.2-.29.36-.76.47-1.41l.91-5.03Z" fill="currentColor"/>
            <path d="M43.56,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.89-.21,1.59-.64,2.08-.83,1.01-2,1.93-3.52,2.78-1.5.85-3.11,1.27-4.83,1.27-2.35,0-4.17-.64-5.46-1.91-1.3-1.27-1.94-3.02-1.94-5.23,0-1.54.32-2.97.97-4.29.65-1.34,1.54-2.4,2.68-3.18,1.16-.78,2.47-1.17,3.92-1.17,1.3,0,2.34.39,3.12,1.17.78.76,1.17,1.8,1.17,3.12,0,1.54-.56,2.87-1.68,3.99-1.1,1.09-2.96,1.97-5.6,2.61.56,1.03,1.62,1.54,3.18,1.54,1.01,0,2.15-.35,3.42-1.04,1.3-.71,2.41-1.64,3.35-2.78.27-.31.57-.47.91-.47ZM35.11,19.17c-.83,0-1.53.48-2.11,1.44-.56.96-.84,2.12-.84,3.48v.07c1.32-.31,2.36-.78,3.12-1.41.76-.63,1.14-1.35,1.14-2.18,0-.42-.12-.76-.37-1.01-.22-.27-.54-.4-.94-.4Z" fill="currentColor"/>
            <path d="M58.17,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.83-.21,1.52-.64,2.08-1.05,1.36-2.36,2.43-3.92,3.22-1.54.78-3.29,1.17-5.23,1.17-1.52,0-2.84-.22-3.96-.67-1.12-.47-1.98-1.09-2.58-1.88-.58-.8-.87-1.7-.87-2.68,0-1.47.59-2.78,1.78-3.92,1.18-1.14,2.88-2.23,5.1-3.28l-5.5.13c-.49.02-.87-.15-1.14-.5-.25-.38-.37-.83-.37-1.34s.12-1.01.37-1.41c.27-.42.63-.64,1.07-.64,1.03,0,2.4.08,4.12.23.36.02,1.01.07,1.94.13.96.07,1.77.1,2.41.1.22,0,.65-.09,1.27-.27.11-.02.32-.08.64-.17.34-.09.61-.13.84-.13.36,0,.65.16.87.47.25.31.37.79.37,1.44,0,.71-.17,1.27-.5,1.68-.34.4-.86.76-1.58,1.07-2.03.87-3.72,1.81-5.06,2.81-1.34.98-2.01,1.97-2.01,2.95,0,.63.29,1.14.87,1.54.58.4,1.44.6,2.58.6,1.25,0,2.51-.31,3.79-.94,1.3-.63,2.46-1.57,3.49-2.85.27-.31.57-.47.91-.47Z" fill="currentColor"/>
            <path d="M74.2,21.21c.29,0,.51.15.67.44.16.29.23.66.23,1.11,0,.56-.08.99-.23,1.31-.16.29-.4.49-.74.6-1.34.47-2.82.74-4.43.8-.45,1.85-1.3,3.35-2.55,4.49-1.23,1.14-2.59,1.71-4.09,1.71-2.26,0-3.9-.86-4.93-2.58-1.03-1.72-1.54-4.21-1.54-7.47,0-2.88.36-6.01,1.07-9.38.72-3.4,1.75-6.28,3.12-8.65,1.39-2.39,3.03-3.59,4.93-3.59,1.03,0,1.85.45,2.48,1.34.63.87.94,2.01.94,3.42,0,1.83-.35,3.65-1.04,5.46-.69,1.81-1.84,3.71-3.45,5.7,1.5.11,2.72.74,3.65,1.88.94,1.12,1.5,2.5,1.68,4.15,1.05-.07,2.3-.29,3.75-.67.13-.04.29-.07.47-.07ZM64.95,3.32c-.45,0-.94.67-1.47,2.01-.51,1.32-.99,3.12-1.44,5.39-.45,2.28-.78,4.77-1.01,7.47,1.48-2.7,2.65-5.08,3.52-7.14.89-2.08,1.34-3.92,1.34-5.53,0-.71-.09-1.26-.27-1.64-.16-.38-.38-.57-.67-.57ZM63.21,28.11c.69,0,1.31-.29,1.84-.87.54-.58.89-1.42,1.07-2.51-.69-.47-1.23-1.08-1.61-1.84-.36-.76-.54-1.56-.54-2.41,0-.31.04-.74.13-1.27h-.1c-.92,0-1.69.46-2.31,1.37-.6.89-.91,2.1-.91,3.62,0,1.27.23,2.25.7,2.92.49.67,1.06,1.01,1.71,1.01Z" fill="currentColor"/>
            <path d="M84.92,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.89-.21,1.59-.64,2.08-.96,1.18-2.01,2.16-3.15,2.92-1.12.76-2.39,1.14-3.82,1.14-1.97,0-3.43-.89-4.39-2.68-.94-1.79-1.41-4.1-1.41-6.94s.35-5.83,1.04-9.32c.72-3.48,1.75-6.48,3.12-8.98,1.39-2.5,3.03-3.75,4.93-3.75,1.07,0,1.91.5,2.51,1.51.63.98.94,2.4.94,4.26,0,2.66-.74,5.74-2.21,9.25-1.47,3.51-3.48,6.98-6,10.42.16.92.41,1.57.77,1.98.36.38.83.57,1.41.57.92,0,1.72-.26,2.41-.77.69-.54,1.58-1.44,2.65-2.71.27-.31.57-.47.91-.47ZM80.79,3.32c-.51,0-1.1.93-1.74,2.78-.65,1.85-1.22,4.15-1.71,6.9-.49,2.75-.76,5.38-.8,7.91,1.59-2.61,2.85-5.23,3.79-7.84.94-2.64,1.41-5.04,1.41-7.2,0-1.7-.31-2.55-.94-2.55Z" fill="currentColor"/>
            <path d="M95.15,25.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.8-.19,1.5-.57,2.08-.63.96-1.45,1.71-2.48,2.25-1.01.54-2.21.8-3.62.8-2.15,0-3.81-.64-4.99-1.91-1.18-1.3-1.78-3.04-1.78-5.23,0-1.54.32-2.97.97-4.29.65-1.34,1.54-2.4,2.68-3.18,1.16-.78,2.47-1.17,3.92-1.17,1.3,0,2.34.39,3.12,1.17.78.76,1.17,1.8,1.17,3.12,0,1.54-.56,2.87-1.68,3.99-1.1,1.09-2.97,1.97-5.63,2.61.54,1.03,1.44,1.54,2.72,1.54.92,0,1.66-.21,2.25-.64.6-.42,1.3-1.14,2.08-2.14.27-.34.57-.5.91-.5ZM89.65,19.17c-.83,0-1.53.48-2.11,1.44-.56.96-.84,2.12-.84,3.48v.07c1.32-.31,2.36-.78,3.12-1.41.76-.63,1.14-1.35,1.14-2.18,0-.42-.12-.76-.37-1.01-.22-.27-.54-.4-.94-.4Z" fill="currentColor"/>
            <path d="M100.75,31.66c-.98,0-1.73-.27-2.25-.8-.49-.54-.74-1.24-.74-2.11,0-1.01.28-1.81.84-2.41.58-.6,1.39-.9,2.41-.9s1.72.25,2.21.74c.51.47.77,1.17.77,2.11,0,1.03-.29,1.85-.87,2.48-.58.6-1.37.9-2.38.9Z" fill="#c8542b"/>
        </svg>
    </span>
    <?php
    // Najdi trenutno aktivno restavracijo (color)
    $_curRest = null;
    if (!empty($restaurants)) {
        $_sid = (int)($_SESSION['restaurant_id'] ?? 0);
        foreach ($restaurants as $_r) {
            if ((int)$_r['id'] === $_sid) { $_curRest = $_r; break; }
        }
        if (!$_curRest) $_curRest = $restaurants[0];
    }
    ?>
    <?php if (!empty($restaurants) && count($restaurants) > 1): ?>
    <div class="rz-mobile-rest" id="rz-mobile-rest">
        <button type="button" class="rz-mobile-rest-btn" id="rz-mobile-rest-toggle" aria-haspopup="listbox" aria-expanded="false">
            <span class="rz-mobile-rest-dot" style="background:<?= h($_curRest['color'] ?? '#c8542b') ?>"></span>
            <span class="rz-mobile-rest-name"><?= h($_curRest['name'] ?? '') ?></span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div class="rz-mobile-rest-menu" id="rz-mobile-rest-menu" hidden role="listbox">
            <?php foreach ($restaurants as $r): ?>
                <button type="button" class="rz-mobile-rest-item<?= ($r['id'] ?? null) == ($_curRest['id'] ?? null) ? ' is-active' : '' ?>" data-rest-id="<?= (int)$r['id'] ?>">
                    <span class="rz-mobile-rest-dot" style="background:<?= h($r['color'] ?? '#c8542b') ?>"></span>
                    <span><?= h($r['name']) ?></span>
                </button>
            <?php endforeach; ?>
            <?php if ($isAdmin): ?>
            <a class="rz-mobile-rest-item" href="<?= BASE_PATH ?>/pages/restaurants.php" style="color:var(--ink-mute);text-decoration:none">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true" style="flex:none"><path d="M4 20h4l11-11-4-4L4 16v4ZM13 6l4 4"/></svg>
                <span><?= t('restaurants.manage_btn') ?></span>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif (!empty($_curRest['name'])): ?>
    <div class="rz-mobile-rest rz-mobile-rest--single">
        <span class="rz-mobile-rest-dot" style="background:<?= h($_curRest['color'] ?? '#c8542b') ?>"></span>
        <span class="rz-mobile-rest-name"><?= h($_curRest['name']) ?></span>
    </div>
    <?php endif; ?>
</div>
<div class="rz-mobile-backdrop" id="rz-mobile-backdrop" hidden></div>

<script>
(function() {
    const app = document.getElementById('rz-app');
    const sidebar = document.getElementById('rz-sidebar');
    const btn = document.getElementById('rz-collapse-btn');
    const icon = document.getElementById('rz-collapse-icon');
    const COLLAPSED = 'rz-sidebar-collapsed';

    // Mobile drawer toggle — bind on click delegation, ker se #rz-mobile-toggle
    // (iz topbar.php) vključi POZNEJE kot ta skript.
    const mBackdrop = document.getElementById('rz-mobile-backdrop');
    function setMobileOpen(on) {
        if (!sidebar) return;
        const mToggle = document.getElementById('rz-mobile-toggle');
        if (on) {
            sidebar.classList.add('is-mobile-open');
            if (mBackdrop) mBackdrop.removeAttribute('hidden');
            if (mToggle) mToggle.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        } else {
            sidebar.classList.remove('is-mobile-open');
            if (mBackdrop) mBackdrop.setAttribute('hidden', '');
            if (mToggle) mToggle.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }
    }
    // Event delegation: zajeme klik na hamburger ali katerikoli element znotraj njega
    document.addEventListener('click', function(e) {
        const t = e.target.closest('#rz-mobile-toggle');
        if (t) {
            e.preventDefault();
            setMobileOpen(!sidebar.classList.contains('is-mobile-open'));
        }
    });
    if (mBackdrop) mBackdrop.addEventListener('click', function() { setMobileOpen(false); });
    // Close drawer when navigating
    sidebar && sidebar.querySelectorAll('.rz-nav-item').forEach(function(a) {
        a.addEventListener('click', function() { setMobileOpen(false); });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar && sidebar.classList.contains('is-mobile-open')) setMobileOpen(false);
    });

    // Mobile bar restaurant selector dropdown
    const mRestToggle = document.getElementById('rz-mobile-rest-toggle');
    const mRestMenu = document.getElementById('rz-mobile-rest-menu');
    if (mRestToggle && mRestMenu) {
        mRestToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            const open = !mRestMenu.hasAttribute('hidden');
            if (open) { mRestMenu.setAttribute('hidden', ''); mRestToggle.setAttribute('aria-expanded', 'false'); }
            else      { mRestMenu.removeAttribute('hidden'); mRestToggle.setAttribute('aria-expanded', 'true'); }
        });
        document.addEventListener('click', function(e) {
            if (!mRestMenu.contains(e.target) && e.target !== mRestToggle) {
                mRestMenu.setAttribute('hidden', '');
                mRestToggle.setAttribute('aria-expanded', 'false');
            }
        });
        mRestMenu.querySelectorAll('[data-rest-id]').forEach(function(el) {
            el.addEventListener('click', function() {
                if (typeof window.switchRestaurant === 'function') {
                    window.switchRestaurant(parseInt(el.dataset.restId));
                }
            });
        });
    }

    function setCollapsed(on) {
        if (on) {
            app.classList.add('is-collapsed');
            sidebar.classList.add('is-collapsed');
            icon.innerHTML = '<path d="m10 6 6 6-6 6"/>';
        } else {
            app.classList.remove('is-collapsed');
            sidebar.classList.remove('is-collapsed');
            icon.innerHTML = '<path d="m14 6-6 6 6 6"/>';
        }
        try { localStorage.setItem(COLLAPSED, on ? '1' : '0'); } catch(e) {}
    }

    try {
        if (localStorage.getItem(COLLAPSED) === '1') setCollapsed(true);
    } catch(e) {}

    if (btn) btn.addEventListener('click', function() {
        setCollapsed(!app.classList.contains('is-collapsed'));
    });

    // Klik na logo/ikono razširi sidebar, če je skrit (sicer normalna navigacija na main.php)
    const logo = document.getElementById('rz-logo');
    if (logo) {
        logo.addEventListener('click', function(e) {
            if (app.classList.contains('is-collapsed')) {
                e.preventDefault();
                setCollapsed(false);
            }
        });
    }

    // ── Tooltipi v skritem stanju (JS, position:fixed — bypassa stacking context) ──
    let _tipEl = null;
    let _tipTarget = null;
    function ensureTipEl() {
        if (_tipEl) return _tipEl;
        _tipEl = document.createElement('div');
        _tipEl.className = 'rz-tip-float';
        _tipEl.setAttribute('role', 'tooltip');
        document.body.appendChild(_tipEl);
        return _tipEl;
    }
    function showTip(target) {
        const text = target.getAttribute('data-tip');
        if (!text) return;
        const tip = ensureTipEl();
        tip.textContent = text;
        const rect = target.getBoundingClientRect();
        tip.style.left = (rect.right + 12) + 'px';
        tip.style.top  = (rect.top + rect.height / 2) + 'px';
        tip.classList.add('is-show');
        _tipTarget = target;
    }
    function hideTip() {
        if (_tipEl) _tipEl.classList.remove('is-show');
        _tipTarget = null;
    }
    sidebar.addEventListener('mouseover', function(e) {
        if (!sidebar.classList.contains('is-collapsed')) return;
        const el = e.target.closest('[data-tip]');
        if (!el || !sidebar.contains(el)) return;
        // Ne prikaži, če je rest dropdown menu odprt
        if (el.id === 'rz-rest-toggle' && el.getAttribute('aria-expanded') === 'true') return;
        if (el !== _tipTarget) showTip(el);
    });
    sidebar.addEventListener('mouseout', function(e) {
        const to = e.relatedTarget;
        if (to && _tipTarget && _tipTarget.contains(to)) return;
        hideTip();
    });
    // Sidebar dobi/izgubi collapsed → skrij tooltip
    const _hideOnScroll = () => hideTip();
    window.addEventListener('scroll', _hideOnScroll, true);
    window.addEventListener('resize', _hideOnScroll);

    // Restavracija switcher
    const restToggle = document.getElementById('rz-rest-toggle');
    const restMenu = document.getElementById('rz-rest-menu');
    if (restToggle && restMenu) {
        restToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            const open = !restMenu.hasAttribute('hidden');
            if (open) { restMenu.setAttribute('hidden', ''); restToggle.setAttribute('aria-expanded', 'false'); }
            else { restMenu.removeAttribute('hidden'); restToggle.setAttribute('aria-expanded', 'true'); }
        });
        document.addEventListener('click', function(e) {
            if (!restMenu.contains(e.target) && e.target !== restToggle) {
                restMenu.setAttribute('hidden', '');
                restToggle.setAttribute('aria-expanded', 'false');
            }
        });
        restMenu.querySelectorAll('[data-rest-id]').forEach(function(el) {
            el.addEventListener('click', function() {
                window.switchRestaurant(parseInt(el.dataset.restId));
            });
        });
    }

    window.switchRestaurant = function(id) {
        fetch('<?= BASE_PATH ?>/api/users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'switch_restaurant', restaurant_id: parseInt(id) })
        }).then(function() {
            // Strani, ki imajo restaurant_id v URL-ju, morajo dobiti nov URL – ne reload istega
            var cp = '<?= $_cp ?>';
            if (cp === 'restaurant-edit.php') {
                location.href = '<?= BASE_PATH ?>/pages/restaurant-edit.php?id=' + id;
            } else {
                location.reload();
            }
        });
    };
})();
</script>
