<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';

if (!is_logged_in()) {
    redirect_to_login();
}

// Superadmin gre na lasten dashboard
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

// Naloži restavracije glede na vlogo
if ($_SESSION['role'] === 'admin') {
    $stmt = $pdo->prepare("
        SELECT r.id, r.name, r.reservation_duration, r.allow_custom_duration, r.schedule_start, r.schedule_end, r.color,
               r.booking_token, r.booking_enabled
        FROM restaurants r
        JOIN restaurant_admins ra ON r.id = ra.restaurant_id
        WHERE ra.user_id = ? AND r.is_active = 1
        ORDER BY r.name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $restaurants = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT id, name, reservation_duration, allow_custom_duration, schedule_start, schedule_end, color,
               booking_token, booking_enabled
        FROM restaurants WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$_SESSION['restaurant_id']]);
    $restaurants = $stmt->fetchAll();
}

$isAdmin  = $_SESSION['role'] === 'admin';
$today    = date('Y-m-d');
$fullName = $_SESSION['full_name'];
$restId   = $_SESSION['restaurant_id'];

// Za hasTableMgmt: admin preverimo po user_id, user vloga po owner_id restavracije
$hasTableMgmt = false;
if ($_SESSION['role'] === 'superadmin') {
    $hasTableMgmt = true;
} elseif ($isAdmin) {
    $hasTableMgmt = user_has_feature($pdo, (int)$_SESSION['user_id'], 'table_management');
} elseif ($_SESSION['role'] === 'user' && $restId) {
    try {
        $owQ = $pdo->prepare("SELECT owner_id FROM restaurants WHERE id = ? LIMIT 1");
        $owQ->execute([$restId]);
        $owId = (int)$owQ->fetchColumn();
        if ($owId) $hasTableMgmt = user_has_feature($pdo, $owId, 'table_management');
    } catch (PDOException $e) { /* tiho */ }
}

// Pending count (admin in user)
$pendingCount = 0;
if ($isAdmin) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM reservations r
        JOIN restaurant_admins ra ON r.restaurant_id = ra.restaurant_id
        WHERE ra.user_id = ? AND r.status = 'pending'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $pendingCount = (int) $stmt->fetchColumn();
} elseif ($restId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE restaurant_id = ? AND status = 'pending'");
    $stmt->execute([$restId]);
    $pendingCount = (int) $stmt->fetchColumn();
}

// Naloži day_schedules za vse restavracije
$daySchedulesMap = [];
$hasTablesMap    = [];
if ($restaurants) {
    try {
        $restIds = array_column($restaurants, 'id');
        $placeholders = implode(',', array_fill(0, count($restIds), '?'));
        $dsStmt = $pdo->prepare("SELECT restaurant_id, day_of_week, is_open, start_time, end_time FROM restaurant_day_schedules WHERE restaurant_id IN ($placeholders) ORDER BY restaurant_id, day_of_week");
        $dsStmt->execute($restIds);
        foreach ($dsStmt->fetchAll() as $ds) {
            $daySchedulesMap[$ds['restaurant_id']][] = $ds;
        }
    } catch (PDOException $e) { /* tabela še ne obstaja */ }
    try {
        $restIds = array_column($restaurants, 'id');
        $placeholders = implode(',', array_fill(0, count($restIds), '?'));
        $tStmt = $pdo->prepare("SELECT restaurant_id FROM restaurant_tables WHERE restaurant_id IN ($placeholders) AND is_active = 1 GROUP BY restaurant_id");
        $tStmt->execute($restIds);
        foreach ($tStmt->fetchAll(PDO::FETCH_COLUMN) as $rid) {
            $hasTablesMap[(int)$rid] = true;
        }
    } catch (PDOException $e) { /* tabela še ne obstaja */ }
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css?v=4">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/calendar.css?v=2">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/schedule.css?v=2">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/modal.css?v=2">
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
        <?php if ($pendingCount > 0): ?>
        <button id="btn-pending" class="btn-header btn-header-admin" onclick="PendingSection.loadAndScroll()" style="position:relative;gap:6px">
            ⏳ Čakajoče
            <span id="pending-badge" style="background:#EF4444;color:#fff;border-radius:999px;font-size:.7rem;font-weight:700;padding:1px 7px;min-width:20px;display:inline-flex;align-items:center;justify-content:center"><?= $pendingCount ?></span>
        </button>
        <?php endif; ?>
        <span class="header-user">👤 <?= h($fullName) ?></span>
        <a href="<?= BASE_PATH ?>/pages/stats.php" class="btn-header btn-header-admin">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            Statistika
        </a>
        <?php if ($isAdmin && user_has_feature($pdo, (int)$_SESSION['user_id'], 'guest_database')): ?>
        <a href="<?= BASE_PATH ?>/pages/guests.php" class="btn-header btn-header-admin">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Gostje
        </a>
        <?php endif; ?>
        <?php if ($isAdmin && user_has_feature($pdo, (int)$_SESSION['user_id'], 'waitlist')): ?>
        <a href="<?= BASE_PATH ?>/pages/waitlist.php" class="btn-header btn-header-admin">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            Čakalna lista
        </a>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
            <a href="<?= BASE_PATH ?>/pages/admin.php" class="btn-header btn-header-admin">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
                Admin
            </a>
            <a href="<?= BASE_PATH ?>/pages/billing.php" class="btn-header btn-header-admin">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                Paketi
            </a>
        <?php endif; ?>
        <a href="<?= BASE_PATH ?>/pages/profile.php" class="btn-header" title="Nastavitve profila">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            Profil
        </a>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
    </div>

    <!-- Hamburger (mobile) -->
    <button class="hamburger-btn" id="hamburger-btn" onclick="document.getElementById('mobile-nav').classList.toggle('open')">
        <span></span><span></span><span></span>
    </button>
</header>

<!-- Mobile nav -->
<div class="mobile-nav" id="mobile-nav">
    <div class="mobile-nav-user">👤 <?= h($fullName) ?></div>
    <?php if ($pendingCount > 0): ?>
    <a href="#" class="btn-header btn-header-admin" onclick="document.getElementById('mobile-nav').classList.remove('open');setTimeout(()=>PendingSection.loadAndScroll(),200)">
        ⏳ Čakajoče <span style="background:#EF4444;color:#fff;border-radius:999px;font-size:.7rem;font-weight:700;padding:1px 7px;margin-left:4px"><?= $pendingCount ?></span>
    </a>
    <?php endif; ?>
    <a href="<?= BASE_PATH ?>/pages/stats.php" class="btn-header btn-header-admin">Statistika</a>
    <?php if ($isAdmin): ?>
    <?php if (user_has_feature($pdo, (int)$_SESSION['user_id'], 'guest_database')): ?>
    <a href="<?= BASE_PATH ?>/pages/guests.php" class="btn-header btn-header-admin">Gostje</a>
    <?php endif; ?>
    <?php if (user_has_feature($pdo, (int)$_SESSION['user_id'], 'waitlist')): ?>
    <a href="<?= BASE_PATH ?>/pages/waitlist.php" class="btn-header btn-header-admin">Čakalna lista</a>
    <?php endif; ?>
    <a href="<?= BASE_PATH ?>/pages/admin.php" class="btn-header btn-header-admin">Admin</a>
    <a href="<?= BASE_PATH ?>/pages/billing.php" class="btn-header btn-header-admin">Paketi</a>
    <?php endif; ?>
    <a href="<?= BASE_PATH ?>/pages/profile.php" class="btn-header">Profil</a>
    <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
</div>

<?php require_once '../includes/trial_banner.php'; ?>

<!-- ── Prazno stanje: admin brez restavracij ──────────────── -->
<?php if ($isAdmin && empty($restaurants)): ?>
<div style="display:flex;align-items:center;justify-content:center;min-height:calc(100vh - 64px);padding:24px">
    <div style="text-align:center;max-width:400px">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="1.5" style="margin-bottom:20px"><path d="M3 2h18v4H3zM3 10h18v4H3zM3 18h18v4H3z"/></svg>
        <h2 style="font-size:1.25rem;font-weight:700;color:#111827;margin:0 0 10px">Nimate še nobene restavracije</h2>
        <p style="color:#6B7280;font-size:.9rem;margin:0 0 24px;line-height:1.5">Dodajte svojo prvo restavracijo in začnite sprejemati rezervacije.</p>
        <a href="<?= BASE_PATH ?>/pages/admin.php" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            Dodaj restavracijo
        </a>
    </div>
</div>
<?php else: ?>
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

        <!-- ── Čakajoče rezervacije ──────────────────────── -->
        <div id="pending-section" class="pending-section" style="display:none">
            <div class="pending-section-header">
                <div class="pending-section-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Čakajoče rezervacije
                    <span id="pending-section-badge" class="pending-section-badge">0</span>
                </div>
                <button class="btn btn-ghost btn-sm" onclick="PendingSection.load()" title="Osveži seznam">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                </button>
            </div>
            <div id="pending-list"></div>
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
            'booking_token'         => $r['booking_token'] ?? null,
            'booking_enabled'       => (bool)($r['booking_enabled'] ?? false),
            'has_tables'            => isset($hasTablesMap[(int)$r['id']]),
            'day_schedules'         => array_map(function($ds) {
                return [
                    'day_of_week' => (int)$ds['day_of_week'],
                    'is_open'     => (bool)$ds['is_open'],
                    'start_time'  => (int)$ds['start_time'],
                    'end_time'    => (int)$ds['end_time'],
                ];
            }, $daySchedulesMap[(int)$r['id']] ?? []),
        ];
    }, $restaurants),
    'pendingCount' => $pendingCount,
    'today'        => $today,
    'base'         => BASE_PATH,
    'hasSurvey'       => $isAdmin ? user_has_feature($pdo, (int)$_SESSION['user_id'], 'survey') : false,
    'hasGuestDatabase'=> $isAdmin ? user_has_feature($pdo, (int)$_SESSION['user_id'], 'guest_database') : false,
    'hasTableMgmt'    => $hasTableMgmt,
], JSON_UNESCAPED_UNICODE) ?>;
</script>

<!-- ── JavaScript ─────────────────────────────────────────── -->
<script src="<?= BASE_PATH ?>/assets/js/api.js?v=3"></script>
<script src="<?= BASE_PATH ?>/assets/js/calendar.js?v=2"></script>
<script src="<?= BASE_PATH ?>/assets/js/schedule.js?v=3"></script>
<script src="<?= BASE_PATH ?>/assets/js/modal.js?v=9"></script>
<script src="<?= BASE_PATH ?>/assets/js/app.js?v=4"></script>

</body>
</html>
<?php endif; ?>
