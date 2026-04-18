<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';

if (!is_logged_in()) {
    redirect_to_login();
}

// Samo admin (ne superadmin – ta ima lasten dashboard)
if ($_SESSION['role'] === 'superadmin') {
    header('Location: ' . BASE_PATH . '/pages/superadmin.php');
    exit;
}
if ($_SESSION['role'] !== 'admin') {
    header('Location: ' . BASE_PATH . '/pages/main.php');
    exit;
}

$fullName = $_SESSION['full_name'];
$pdo = getDB();
refresh_subscription_session($pdo);

if (!empty($_SESSION['payment_failed'])) {
    require_once '../includes/payment_failed_block.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin panel – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css?v=3">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin.css?v=2">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/modal.css?v=2">
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────── -->
<header class="app-header">
    <a href="<?= BASE_PATH ?>/pages/main.php" class="header-logo">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
            <rect width="28" height="28" rx="7" fill="#F59E0B"/>
            <path d="M7 10h14M7 14h14M7 18h9" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <?= h(APP_NAME) ?>
        <?= plan_badge($_SESSION['plan_slug'] ?? 'trial') ?>
    </a>

    <div class="header-restaurant">
        <span style="color:rgba(255,255,255,.5);font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600">Admin panel</span>
    </div>

    <div class="header-actions">
        <span class="header-user">👤 <?= h($fullName) ?></span>
        <a href="<?= BASE_PATH ?>/pages/main.php" class="btn-header btn-header-admin">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            Razpored
        </a>
        <a href="<?= BASE_PATH ?>/pages/stats.php" class="btn-header btn-header-admin">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            Statistika
        </a>
        <a href="<?= BASE_PATH ?>/pages/billing.php" class="btn-header btn-header-admin">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            Paketi
        </a>
        <a href="<?= BASE_PATH ?>/pages/profile.php" class="btn-header" title="Nastavitve profila">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            Profil
        </a>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
    </div>

    <button class="hamburger-btn" id="hamburger-btn" onclick="document.getElementById('mobile-nav').classList.toggle('open')">
        <span></span><span></span><span></span>
    </button>
</header>

<div class="mobile-nav" id="mobile-nav">
    <div class="mobile-nav-user">👤 <?= h($fullName) ?></div>
    <a href="<?= BASE_PATH ?>/pages/main.php" class="btn-header btn-header-admin">Razpored</a>
    <a href="<?= BASE_PATH ?>/pages/stats.php" class="btn-header btn-header-admin">Statistika</a>
    <a href="<?= BASE_PATH ?>/pages/billing.php" class="btn-header btn-header-admin">Paketi</a>
    <a href="<?= BASE_PATH ?>/pages/profile.php" class="btn-header">Profil</a>
    <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
</div>

<?php require_once '../includes/trial_banner.php'; ?>

<!-- ── Vsebina ─────────────────────────────────────────────── -->
<div class="admin-layout">
    <div class="admin-content">
        <h1 class="admin-page-title">Upravljanje</h1>

        <!-- Tabs -->
        <div class="admin-tabs">
            <button class="admin-tab active" data-tab="restaurants">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 2h18v4H3zM3 10h18v4H3zM3 18h18v4H3z"/></svg>
                Restavracije
            </button>
            <button class="admin-tab" data-tab="users">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
                Uporabniki
            </button>
        </div>

        <!-- Panel: Restavracije -->
        <div id="panel-restaurants" class="admin-panel active">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h2>Moje restavracije</h2>
                    <button id="btn-add-restaurant" class="btn btn-primary btn-sm">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                        Dodaj restavracijo
                    </button>
                </div>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Ime</th>
                                <th>Trajanje rez.</th>
                                <th>Barva</th>
                                <th>Status</th>
                                <th>Booking</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="rest-tbody">
                            <tr><td colspan="6" class="table-empty">Nalagam...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="margin-top:16px;padding:14px 16px;background:#FFF7ED;border:1px solid #FED7AA;border-radius:var(--radius);font-size:.825rem;color:#92400E">
                <strong>Trajanje rezervacije</strong> določa, kako dolgi so časovni intervali v dnevnem razporedu. Nastavitev velja za vsako restavracijo posebej.
            </div>
        </div>

        <!-- Panel: Uporabniki -->
        <div id="panel-users" class="admin-panel">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h2>Uporabniki</h2>
                    <button id="btn-add-user" class="btn btn-primary btn-sm">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                        Dodaj uporabnika
                    </button>
                </div>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Ime</th>
                                <th>Email / Uporabniško ime</th>
                                <th>Restavracija</th>
                                <th>Vloga</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="users-tbody">
                            <tr><td colspan="6" class="table-empty">Nalagam...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="margin-top:16px;padding:14px 16px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:var(--radius);font-size:.825rem;color:#1D4ED8">
                <strong>Vloge:</strong> <strong>Uporabnik</strong> – vidi in ureja rezervacije za dodeljeno restavracijo. <strong>Admin</strong> – upravlja restavracijo (urnik, nastavitve, zaposleni). Dodajate jih lahko samo k lastnim restavracijam.
            </div>
        </div>
    </div>
</div>

<!-- Toast kontejner -->
<div id="toast-container"></div>

<!-- APP_STATE (minimalen) -->
<script>
window.APP_STATE = <?= json_encode([
    'userId'      => (int) $_SESSION['user_id'],
    'role'        => $_SESSION['role'],
    'restaurants' => [],
    'today'       => date('Y-m-d'),
    'base'        => BASE_PATH,
], JSON_UNESCAPED_UNICODE) ?>;
</script>

<script src="<?= BASE_PATH ?>/assets/js/api.js?v=2"></script>
<script src="<?= BASE_PATH ?>/assets/js/admin.js?v=9"></script>

</body>
</html>
