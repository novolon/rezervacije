<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';

if (!is_logged_in()) {
    redirect_to_login();
}
if ($_SESSION['role'] === 'superadmin') {
    header('Location: ' . BASE_PATH . '/pages/superadmin.php');
    exit;
}

$pdo = getDB();
refresh_subscription_session($pdo);

if (!empty($_SESSION['payment_failed'])) {
    require_once '../includes/payment_failed_block.php';
    exit;
}

$isAdmin  = $_SESSION['role'] === 'admin';
$fullName = $_SESSION['full_name'];

// Naloži restavracije za dropdown (samo admin z več rest.)
$restaurants = [];
if ($isAdmin) {
    $stmt = $pdo->prepare("
        SELECT r.id, r.name FROM restaurants r
        JOIN restaurant_admins ra ON r.id = ra.restaurant_id
        WHERE ra.user_id = ? AND r.is_active = 1 ORDER BY r.name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $restaurants = $stmt->fetchAll();
} elseif (!empty($_SESSION['restaurant_id'])) {
    $stmt = $pdo->prepare("SELECT id, name FROM restaurants WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['restaurant_id']]);
    $restaurants = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistika – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css?v=4">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/stats.css?v=1">
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────── -->
<header class="app-header">
    <a href="<?= BASE_PATH ?>/pages/main.php" class="header-logo" style="flex-shrink:0">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
            <rect width="28" height="28" rx="7" fill="#F59E0B"/>
            <path d="M7 10h14M7 14h14M7 18h9" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <?= h(APP_NAME) ?>
        <?php if ($isAdmin): ?><?= plan_badge($_SESSION['plan_slug'] ?? 'trial') ?><?php endif; ?>
    </a>

    <div class="header-restaurant">
        <span style="color:rgba(255,255,255,.5);font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600">Statistika</span>
    </div>

    <div class="header-actions">
        <span class="header-user">👤 <?= h($fullName) ?></span>
        <a href="<?= BASE_PATH ?>/pages/main.php" class="btn-header btn-header-admin">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            Razpored
        </a>
        <?php if ($isAdmin): ?>
        <a href="<?= BASE_PATH ?>/pages/admin.php" class="btn-header btn-header-admin">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
            Admin
        </a>
        <?php endif; ?>
        <a href="<?= BASE_PATH ?>/pages/profile.php" class="btn-header" title="Profil">
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
    <?php if ($isAdmin): ?>
    <a href="<?= BASE_PATH ?>/pages/admin.php" class="btn-header btn-header-admin">Admin</a>
    <?php endif; ?>
    <a href="<?= BASE_PATH ?>/pages/profile.php" class="btn-header">Profil</a>
    <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
</div>

<?php require_once '../includes/trial_banner.php'; ?>

<!-- ── Vsebina ─────────────────────────────────────────────── -->
<div class="stats-wrap">

    <!-- Filter vrstica -->
    <div class="stats-filters">
        <div class="stats-filters-left">
            <?php if ($isAdmin && count($restaurants) > 1): ?>
            <select id="filter-restaurant" class="filter-select">
                <option value="">Vse restavracije</option>
                <?php foreach ($restaurants as $r): ?>
                <option value="<?= $r['id'] ?>"><?= h($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php elseif (count($restaurants) === 1): ?>
            <span class="stats-rest-name"><?= h($restaurants[0]['name']) ?></span>
            <?php endif; ?>

            <div class="period-btns">
                <button class="period-btn active" data-days="30">30 dni</button>
                <button class="period-btn" data-days="90">3 mes.</button>
                <button class="period-btn" data-days="180">6 mes.</button>
                <button class="period-btn" data-days="365">1 leto</button>
                <button class="period-btn" data-days="custom">Po meri</button>
            </div>

            <div id="custom-dates" style="display:none;gap:8px;align-items:center" class="custom-dates">
                <input type="date" id="filter-from" class="filter-input">
                <span style="color:var(--color-muted)">–</span>
                <input type="date" id="filter-to" class="filter-input">
                <button id="btn-apply-dates" class="btn btn-primary btn-sm">Prikaži</button>
            </div>
        </div>

        <div class="stats-filters-right">
            <button id="btn-export" class="btn btn-outline btn-sm">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Izvozi CSV
            </button>
        </div>
    </div>

    <!-- KPI kartice -->
    <div class="kpi-grid" id="kpi-grid">
        <div class="kpi-card"><div class="kpi-label">Skupaj rezervacij</div><div class="kpi-value" id="kpi-total">–</div></div>
        <div class="kpi-card"><div class="kpi-label">Skupaj gostov</div><div class="kpi-value" id="kpi-guests">–</div></div>
        <div class="kpi-card"><div class="kpi-label">Povp. gostov / rez.</div><div class="kpi-value" id="kpi-avg">–</div></div>
        <div class="kpi-card"><div class="kpi-label">Stopnja prihoda</div><div class="kpi-value" id="kpi-arrival">–</div></div>
    </div>

    <!-- Trend po mesecih -->
    <div class="stats-card">
        <div class="stats-card-header">
            <h2 class="stats-card-title">Trend rezervacij (zadnjih 12 mesecev)</h2>
        </div>
        <div class="chart-wrap">
            <canvas id="chart-monthly"></canvas>
        </div>
    </div>

    <!-- Dnevi in ure (vzporedno) -->
    <div class="stats-grid-2">
        <div class="stats-card">
            <div class="stats-card-header">
                <h2 class="stats-card-title">Po dnevu v tednu</h2>
            </div>
            <div class="chart-wrap chart-wrap-bar">
                <canvas id="chart-days"></canvas>
            </div>
        </div>
        <div class="stats-card">
            <div class="stats-card-header">
                <h2 class="stats-card-title">Po uri (peak hours)</h2>
            </div>
            <div class="chart-wrap chart-wrap-bar">
                <canvas id="chart-hours"></canvas>
            </div>
        </div>
    </div>

    <!-- Vir rezervacij -->
    <div class="stats-grid-2">
        <div class="stats-card">
            <div class="stats-card-header">
                <h2 class="stats-card-title">Vir rezervacij</h2>
            </div>
            <div class="chart-wrap chart-wrap-pie">
                <canvas id="chart-sources"></canvas>
            </div>
        </div>
        <div class="stats-card">
            <div class="stats-card-header">
                <h2 class="stats-card-title">Velikost skupin</h2>
            </div>
            <div class="chart-wrap chart-wrap-bar">
                <canvas id="chart-groups"></canvas>
            </div>
        </div>
    </div>

    <!-- Stalni gostje -->
    <div class="stats-card" id="section-returning">
        <div class="stats-card-header">
            <h2 class="stats-card-title">Stalni gostje ⭐</h2>
            <div class="returning-filter">
                <button class="period-btn active" id="btn-all-guests">Vsi</button>
                <button class="period-btn" id="btn-returning-only">Samo vrnjeni</button>
            </div>
        </div>

        <!-- KPI -->
        <div class="returning-kpi">
            <div class="ret-kpi-item">
                <div class="ret-kpi-val" id="ret-unique">–</div>
                <div class="ret-kpi-lbl">Edinstveni gostje</div>
            </div>
            <div class="ret-kpi-item">
                <div class="ret-kpi-val" id="ret-returning">–</div>
                <div class="ret-kpi-lbl">Vrnjeni gostje</div>
            </div>
            <div class="ret-kpi-item">
                <div class="ret-kpi-val" id="ret-pct">–</div>
                <div class="ret-kpi-lbl">% vračanja</div>
            </div>
        </div>

        <!-- Tabela Top gostov -->
        <div class="admin-table-wrap" style="margin-top:16px">
            <table class="admin-table" id="guests-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Ime</th>
                        <th>Email</th>
                        <th>Obiski</th>
                        <th>Skupaj gostov</th>
                        <th>1. obisk</th>
                        <th>Zadnji obisk</th>
                    </tr>
                </thead>
                <tbody id="guests-tbody">
                    <tr><td colspan="7" class="table-empty">Nalagam...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- .stats-wrap -->

<div id="toast-container"></div>

<script>
window.APP_STATE = <?= json_encode([
    'base'         => BASE_PATH,
    'role'         => $_SESSION['role'],
    'restaurantId' => (int)($_SESSION['restaurant_id'] ?? 0),
], JSON_UNESCAPED_UNICODE) ?>;
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="<?= BASE_PATH ?>/assets/js/api.js?v=2"></script>
<script src="<?= BASE_PATH ?>/assets/js/stats.js?v=1"></script>

</body>
</html>
