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

// Nastavitve URL – direktno na restaurant-edit.php za izbrano restavracijo
$_settingsUrl = BASE_PATH . '/pages/admin.php';
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
    return '<a href="' . htmlspecialchars($href) . '" class="' . $cls . '">'
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
            'cog'        => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2"/>',
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
        <a href="<?= BASE_PATH ?>/pages/main.php" class="rz-logo">
            <svg width="28" height="28" viewBox="0 0 32 32" aria-hidden="true" style="flex:none">
                <rect width="32" height="32" rx="7" fill="var(--accent)"/>
                <path d="M9 8v16l4-4h5a5 5 0 0 0 5-5v-4a3 3 0 0 0-3-3H9Z" fill="var(--accent-ink)"/>
            </svg>
            <span class="rz-logo-name display" style="font-size:18px;font-weight:700;letter-spacing:-0.02em">Rezble</span>
            <span class="rz-plan-badge"><?= h($_planLabel) ?></span>
        </a>
        <button class="rz-collapse" id="rz-collapse-btn" type="button" title="<?= t('nav.collapse') ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" id="rz-collapse-icon">
                <path d="m14 6-6 6 6 6"/>
            </svg>
        </button>
    </div>

    <?php if (!empty($restaurants)): ?>
    <div class="rz-restaurant-switcher">
        <?php if ($isAdmin && count($restaurants) > 1): ?>
            <button class="rz-rest-btn" type="button" id="rz-rest-toggle" aria-haspopup="true" aria-expanded="false">
                <span class="rz-rest-dot"></span>
                <span class="rz-rest-name"><?= h($_restName) ?></span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>
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
            </div>
        <?php else: ?>
            <div class="rz-rest-btn" style="cursor:default">
                <span class="rz-rest-dot"></span>
                <span class="rz-rest-name"><?= h($_restName) ?></span>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ((int)($pendingCount ?? 0) > 0): ?>
    <a href="<?= BASE_PATH ?>/pages/pending.php" class="rz-pending" title="<?= (int)$pendingCount ?> <?= t('nav.pending') ?>">
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

    <?php if ($isAdmin): ?>
    <nav class="rz-nav">
        <?= _rz_nav_item($_settingsUrl, 'cog', t('nav.settings'), in_array($_cp, ['admin.php','restaurant-edit.php'])) ?>
        <?= _rz_nav_item(BASE_PATH . '/pages/billing.php', 'card', t('nav.billing'), $_cp === 'billing.php') ?>
    </nav>
    <?php endif; ?>

    <div class="rz-side-bottom">
        <div class="rz-user">
            <a href="<?= BASE_PATH ?>/pages/profile.php" class="rz-user-avatar" title="<?= t('nav.profile') ?>" style="text-decoration:none"><?= h($_initials) ?></a>
            <a href="<?= BASE_PATH ?>/pages/profile.php" class="rz-user-info" style="text-decoration:none;color:inherit">
                <div class="rz-user-name"><?= h($fullName) ?></div>
                <div class="rz-user-role"><?= $isAdmin ? t('nav.role_admin') : t('nav.role_staff') ?></div>
            </a>
            <a href="<?= BASE_PATH ?>/logout.php" class="rz-user-more" title="<?= t('nav.logout') ?>">
                <?= _rz_icon('logout', 15) ?>
            </a>
        </div>
    </div>

</aside>

<script>
(function() {
    const app = document.getElementById('rz-app');
    const sidebar = document.getElementById('rz-sidebar');
    const btn = document.getElementById('rz-collapse-btn');
    const icon = document.getElementById('rz-collapse-icon');
    const COLLAPSED = 'rz-sidebar-collapsed';

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
