<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!is_logged_in()) {
    redirect_to_login();
}

// Superadmin gre na lasten dashboard
if ($_SESSION['role'] === 'superadmin') {
    header('Location: ' . BASE_PATH . '/pages/superadmin.php');
    exit;
}

$pdo = getDB();

// Naloži restavracije glede na vlogo
if ($_SESSION['role'] === 'admin') {
    $stmt = $pdo->prepare("
        SELECT r.id, r.name, r.reservation_duration, r.allow_custom_duration, r.schedule_start, r.schedule_end, r.color
        FROM restaurants r
        JOIN restaurant_admins ra ON r.id = ra.restaurant_id
        WHERE ra.user_id = ? AND r.is_active = 1
        ORDER BY r.name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $restaurants = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT id, name, reservation_duration, allow_custom_duration, schedule_start, schedule_end, color
        FROM restaurants WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$_SESSION['restaurant_id']]);
    $restaurants = $stmt->fetchAll();
}

$isAdmin  = $_SESSION['role'] === 'admin';
$today    = date('Y-m-d');
$fullName = $_SESSION['full_name'];
$restId   = $_SESSION['restaurant_id'];
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/calendar.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/schedule.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/modal.css">
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
    </a>

    <div class="header-restaurant">
        <?php if ($isAdmin && count($restaurants) > 1): ?>
            <span class="restaurant-label">Restavracija:</span>
            <select id="restaurant-select">
                <option value="">— Vse —</option>
                <?php foreach ($restaurants as $r): ?>
                    <option value="<?= $r['id'] ?>"><?= h($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php elseif (count($restaurants) === 1): ?>
            <span class="restaurant-name-static"><?= h($restaurants[0]['name']) ?></span>
        <?php endif; ?>
    </div>

    <div class="header-actions">
        <span class="header-user">👤 <?= h($fullName) ?></span>
        <?php if ($isAdmin): ?>
            <a href="<?= BASE_PATH ?>/pages/admin.php" class="btn-header btn-header-admin">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
                Admin
            </a>
        <?php endif; ?>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
    </div>
</header>

<!-- ── Dvosteberna postavitev ─────────────────────────────── -->
<div class="app-layout">

    <!-- ── Levi panel: dnevni razpored ─────────────────────── -->
    <aside class="panel-schedule">
        <div class="schedule-header">
            <div id="schedule-date-label" class="schedule-date-label">Danes</div>
            <div class="schedule-meta">
                <div class="schedule-stats">
                    <div class="stat-pill">Rezervacije: <span id="stat-count">0</span></div>
                    <div class="stat-pill">Osebe: <span id="stat-guests">0</span></div>
                </div>
                <button id="btn-today" style="display:none">↩ Danes</button>
            </div>
        </div>

        <div class="schedule-add-btn">
            <button id="btn-add-reservation" class="btn-add-reservation">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Nova rezervacija
            </button>
        </div>

        <div id="schedule-body" class="schedule-body">
            <!-- Dinamično generirano z JS -->
        </div>
    </aside>

    <!-- ── Desni panel: mesečni koledar ────────────────────── -->
    <main class="panel-calendar">
        <div class="calendar-wrapper">
            <div class="calendar-nav">
                <button id="cal-prev" class="cal-nav-btn" title="Prejšnji mesec">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
                </button>
                <div id="cal-title" class="calendar-title"></div>
                <button id="cal-next" class="cal-nav-btn" title="Naslednji mesec">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
                </button>
            </div>
            <div id="cal-grid" class="calendar-grid">
                <!-- Dinamično generirano z JS -->
            </div>
        </div>
    </main>

</div>

<!-- ── Toast kontejner ────────────────────────────────────── -->
<div id="toast-container"></div>

<!-- ── APP_STATE ──────────────────────────────────────────── -->
<script>
window.APP_STATE = <?= json_encode([
    'userId'       => (int) $_SESSION['user_id'],
    'role'         => $_SESSION['role'],
    'restaurantId' => $restId ? (int)$restId : null,
    'fullName'     => $fullName,
    'restaurants'  => array_map(function($r) {
        return [
            'id'                    => (int)$r['id'],
            'name'                  => $r['name'],
            'reservation_duration'  => (int)$r['reservation_duration'],
            'allow_custom_duration' => (bool)$r['allow_custom_duration'],
            'schedule_start'        => (int)$r['schedule_start'],
            'schedule_end'          => (int)$r['schedule_end'],
            'color'                 => $r['color'],
        ];
    }, $restaurants),
    'today'        => $today,
    'base'         => BASE_PATH,
], JSON_UNESCAPED_UNICODE) ?>;
</script>

<!-- ── JavaScript ─────────────────────────────────────────── -->
<script src="<?= BASE_PATH ?>/assets/js/api.js"></script>
<script src="<?= BASE_PATH ?>/assets/js/calendar.js"></script>
<script src="<?= BASE_PATH ?>/assets/js/schedule.js"></script>
<script src="<?= BASE_PATH ?>/assets/js/modal.js"></script>
<script src="<?= BASE_PATH ?>/assets/js/app.js"></script>

</body>
</html>
