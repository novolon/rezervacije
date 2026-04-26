<?php
// Skupna navigacija za affiliate portal.
// Vključi po require_affiliate() v vsaki zaščiteni strani.
$currentUri = $_SERVER['REQUEST_URI'] ?? '';
function aff_nav_active(string $path): string {
    global $currentUri;
    return str_contains($currentUri, $path) ? ' is-active' : '';
}
$sess = aff_session_get();
?>
<aside class="aff-sidebar">
    <div class="aff-sidebar-logo">
        <div class="aff-sidebar-logo-icon">R</div>
        Affiliate
    </div>

    <a href="<?= BASE_PATH ?>/affiliate/dashboard.php" class="aff-nav-link<?= aff_nav_active('dashboard') ?>">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Pregled
    </a>
    <a href="<?= BASE_PATH ?>/affiliate/links.php" class="aff-nav-link<?= aff_nav_active('links') ?>">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
        Povezave & kode
    </a>
    <a href="<?= BASE_PATH ?>/affiliate/referrals.php" class="aff-nav-link<?= aff_nav_active('referrals') ?>">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Priporočeni
    </a>
    <a href="<?= BASE_PATH ?>/affiliate/earnings.php" class="aff-nav-link<?= aff_nav_active('earnings') ?>">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        Zaslužki
    </a>
    <a href="<?= BASE_PATH ?>/affiliate/profile.php" class="aff-nav-link<?= aff_nav_active('profile') ?>">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Profil
    </a>

    <div class="aff-sidebar-foot">
        <?= htmlspecialchars($sess['full_name'], ENT_QUOTES) ?><br>
        <a href="<?= BASE_PATH ?>/affiliate/logout.php" style="color:rgba(255,255,255,.4);font-size:.78rem">Odjava</a>
    </div>
</aside>
