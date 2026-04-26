<?php
/**
 * Affiliate portal sidebar nav — uses Rezble design tokens (design.css).
 */
$_cp  = basename($_SERVER['PHP_SELF']);
$_aff = aff_session_get();

function _aff_icon(string $name): string {
    static $i = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'link'      => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'dollar'    => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-7 8-7s8 3 8 7"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    ];
    $p = $i[$name] ?? '';
    return '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

$_parts    = explode(' ', trim($_aff['full_name'] ?? ''));
$_initials = '';
foreach (array_slice($_parts, 0, 2) as $_p) {
    $_initials .= mb_strtoupper(mb_substr($_p, 0, 1));
}

function _aff_nav(string $href, string $icon, string $label, bool $active): string {
    $cls = 'rz-nav-item' . ($active ? ' is-active' : '');
    $mark = $active ? '<span class="rz-nav-mark"></span>' : '';
    return '<a href="' . htmlspecialchars($href) . '" class="' . $cls . '">'
        . _aff_icon($icon)
        . '<span class="rz-nav-label">' . htmlspecialchars($label) . '</span>'
        . $mark
        . '</a>';
}
?>
<aside class="rz-sidebar" id="rz-sidebar">

    <div class="rz-side-top">
        <a href="<?= BASE_PATH ?>/affiliate/dashboard.php" class="rz-logo">
            <svg width="28" height="28" viewBox="0 0 32 32" aria-hidden="true" style="flex:none">
                <rect width="32" height="32" rx="7" fill="var(--accent)"/>
                <path d="M9 8v16l4-4h5a5 5 0 0 0 5-5v-4a3 3 0 0 0-3-3H9Z" fill="var(--accent-ink)"/>
            </svg>
            <span class="rz-logo-name">Rezble</span>
            <span class="rz-plan-badge">AFF</span>
        </a>
    </div>

    <nav class="rz-nav" style="margin-top:8px">
        <?= _aff_nav(BASE_PATH . '/affiliate/dashboard.php',  'dashboard', 'Pregled',         $_cp === 'dashboard.php') ?>
        <?= _aff_nav(BASE_PATH . '/affiliate/links.php',      'link',      'Povezave & kode', $_cp === 'links.php') ?>
        <?= _aff_nav(BASE_PATH . '/affiliate/referrals.php',  'users',     'Priporočeni',     $_cp === 'referrals.php') ?>
        <?= _aff_nav(BASE_PATH . '/affiliate/earnings.php',   'dollar',    'Zaslužki',        $_cp === 'earnings.php') ?>
        <?= _aff_nav(BASE_PATH . '/affiliate/profile.php',    'user',      'Profil',          $_cp === 'profile.php') ?>
    </nav>

    <div class="rz-side-spacer"></div>

    <div class="rz-side-bottom">
        <div class="rz-user">
            <div class="rz-user-avatar" style="background:var(--accent)"><?= htmlspecialchars($_initials, ENT_QUOTES) ?></div>
            <div class="rz-user-info">
                <div class="rz-user-name"><?= htmlspecialchars($_aff['full_name'] ?? '', ENT_QUOTES) ?></div>
                <div class="rz-user-role">Affiliate</div>
            </div>
            <a href="<?= BASE_PATH ?>/affiliate/logout.php" class="rz-user-more" title="Odjava">
                <?= _aff_icon('logout') ?>
            </a>
        </div>
    </div>

</aside>
