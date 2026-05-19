<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/lang.php';

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
               r.booking_token, r.booking_enabled, r.employees_can_override_schedule
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
               booking_token, booking_enabled, employees_can_override_schedule
        FROM restaurants WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$_SESSION['restaurant_id']]);
    $restaurants = $stmt->fetchAll();
}

$isAdmin  = $_SESSION['role'] === 'admin';
$today    = date('Y-m-d');
$fullName = $_SESSION['full_name'];
$restId   = $_SESSION['restaurant_id'];

// Admin: če session nima restaurant_id, nastavi prvo restavracijo
if ($isAdmin && empty($restId) && !empty($restaurants)) {
    $restId = $restaurants[0]['id'];
    $_SESSION['restaurant_id'] = $restId;
}

// Za hasTableMgmt: admin preverimo po user_id, user vloga po owner_id restavracije
$hasTableMgmt = false;
$hasRealtime  = false;
if ($_SESSION['role'] === 'superadmin') {
    $hasTableMgmt = true;
    $hasRealtime  = true;
} elseif ($isAdmin) {
    $hasTableMgmt = user_has_feature($pdo, (int)$_SESSION['user_id'], 'table_management');
    $hasRealtime  = user_has_feature($pdo, (int)$_SESSION['user_id'], 'realtime_sync');
} elseif ($_SESSION['role'] === 'user' && $restId) {
    try {
        $owQ = $pdo->prepare("SELECT owner_id FROM restaurants WHERE id = ? LIMIT 1");
        $owQ->execute([$restId]);
        $owId = (int)$owQ->fetchColumn();
        if ($owId) {
            $hasTableMgmt = user_has_feature($pdo, $owId, 'table_management');
            $hasRealtime  = user_has_feature($pdo, $owId, 'realtime_sync');
        }
    } catch (PDOException $e) { /* tiho */ }
}

// Pending count – spoštuje izbrano restavracijo ($restId je vedno aktiven)
$pendingCount = 0;
if ($restId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE restaurant_id = ? AND status = 'pending'");
    $stmt->execute([$restId]);
    $pendingCount = (int) $stmt->fetchColumn();
} elseif ($isAdmin) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM reservations r
        JOIN restaurant_admins ra ON r.restaurant_id = ra.restaurant_id
        WHERE ra.user_id = ? AND r.status = 'pending'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $pendingCount = (int) $stmt->fetchColumn();
}

// Topbar stats za današnji dan (prva restavracija za admin, sicer trenutna)
$topbarRestId = $restId ? (int)$restId : (isset($restaurants[0]['id']) ? (int)$restaurants[0]['id'] : null);
$todayConfirmed = 0;
$todayPending   = 0;
$todayGuests    = 0;
if ($topbarRestId) {
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS cnt_confirmed,
            SUM(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END) AS cnt_pending,
            COALESCE(SUM(CASE WHEN status IN ('confirmed','pending','arrived') THEN guest_count ELSE 0 END), 0) AS sum_guests
        FROM reservations
        WHERE restaurant_id = ? AND reservation_date = ?
    ");
    $stmt->execute([$topbarRestId, $today]);
    $row = $stmt->fetch();
    $todayConfirmed = (int)($row['cnt_confirmed'] ?? 0);
    $todayPending   = (int)($row['cnt_pending']   ?? 0);
    $todayGuests    = (int)($row['sum_guests']    ?? 0);
}

// Naloži day_schedules + blackouts za vse restavracije
$daySchedulesMap = [];
$blackoutDatesMap = [];
$hasTablesMap    = [];
$tablesMap       = [];
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
        $restIds2 = array_column($restaurants, 'id');
        $placeholders2 = implode(',', array_fill(0, count($restIds2), '?'));
        // Poskusi z block_start/block_end (migrate_multi_period.sql)
        try {
            $blStmt = $pdo->prepare("SELECT restaurant_id, blackout_date, block_start, block_end FROM restaurant_blackouts WHERE restaurant_id IN ($placeholders2) AND blackout_date >= CURDATE() ORDER BY blackout_date");
            $blStmt->execute($restIds2);
            foreach ($blStmt->fetchAll() as $bl) {
                $blackoutDatesMap[(int)$bl['restaurant_id']][] = [
                    'date'        => $bl['blackout_date'],
                    'block_start' => $bl['block_start'] !== null ? (int)$bl['block_start'] : null,
                    'block_end'   => $bl['block_end']   !== null ? (int)$bl['block_end']   : null,
                ];
            }
        } catch (PDOException $e) {
            // Fallback: kolone block_start/block_end še ne obstajajo – naloži brez delnih blokiranj
            $blStmt2 = $pdo->prepare("SELECT restaurant_id, blackout_date FROM restaurant_blackouts WHERE restaurant_id IN ($placeholders2) AND blackout_date >= CURDATE() ORDER BY blackout_date");
            $blStmt2->execute($restIds2);
            foreach ($blStmt2->fetchAll() as $bl) {
                $blackoutDatesMap[(int)$bl['restaurant_id']][] = [
                    'date'        => $bl['blackout_date'],
                    'block_start' => null,
                    'block_end'   => null,
                ];
            }
        }
    } catch (PDOException $e) { /* restaurant_blackouts tabela ne obstaja */ }

    // has_tables: uporabimo COUNT(*) direktno – zanesljivo na vseh MySQL verzijah
    foreach ($restaurants as $r) {
        try {
            $chk = $pdo->prepare("SELECT COUNT(*), SUM(is_active) FROM restaurant_tables WHERE restaurant_id = ?");
            $chk->execute([(int)$r['id']]);
            $row = $chk->fetch(PDO::FETCH_NUM);
            if ((int)$row[0] > 0) {
                $hasTablesMap[(int)$r['id']] = true;
            }
        } catch (PDOException $e) { /* tabela še ne obstaja */ }
    }

    // Več terminov na dan (restaurant_day_periods)
    $dayPeriodsMap = [];
    try {
        $dpStmt = $pdo->prepare("SELECT restaurant_id, day_of_week, start_time, end_time FROM restaurant_day_periods WHERE restaurant_id IN ($placeholders) ORDER BY restaurant_id, day_of_week, start_time");
        $dpStmt->execute($restIds);
        foreach ($dpStmt->fetchAll() as $dp) {
            $dayPeriodsMap[(int)$dp['restaurant_id']][] = [
                'day_of_week' => (int)$dp['day_of_week'],
                'start_time'  => (int)$dp['start_time'],
                'end_time'    => (int)$dp['end_time'],
            ];
        }
    } catch (PDOException $e) { /* tabela še ne obstaja */ }

    // Mize za Gantt timeline rows
    try {
        $tblIds = array_column($restaurants, 'id');
        $tblPh  = implode(',', array_fill(0, count($tblIds), '?'));
        $tblStmt = $pdo->prepare("
            SELECT t.id, t.restaurant_id, t.name, t.capacity,
                   a.name AS area_name, COALESCE(a.sort_order, 999) AS asort, t.sort_order
            FROM restaurant_tables t
            LEFT JOIN restaurant_areas a ON t.area_id = a.id
            WHERE t.restaurant_id IN ($tblPh)
            ORDER BY t.restaurant_id, asort, a.name, t.sort_order, t.name
        ");
        $tblStmt->execute($tblIds);
        foreach ($tblStmt->fetchAll() as $tbl) {
            $tablesMap[(int)$tbl['restaurant_id']][] = [
                'id'        => (int)$tbl['id'],
                'name'      => $tbl['name'],
                'area_name' => $tbl['area_name'],
                'capacity'  => (int)$tbl['capacity'],
            ];
        }
    } catch (PDOException $e) { /* tabela še ne obstaja */ }
}
?>
<?php
$pageTitle = t('nav.today');
$extraCss  = ['main.css?v=4', 'calendar.css?v=2', 'schedule.css?v=7', 'modal.css?v=3', 'design.css?v=2'];
require_once '../includes/html_head.php';
?>
<body>

<div id="rz-app" class="rz-app">
<?php require_once '../includes/sidebar.php'; ?>
<main class="rz-main">

<?php require_once '../includes/trial_banner.php'; ?>

<!-- ── Prazno stanje: admin brez restavracij ──────────────── -->
<?php if ($isAdmin && empty($restaurants)): ?>
<div style="display:flex;align-items:center;justify-content:center;min-height:calc(100vh - 64px);padding:24px">
    <div style="text-align:center;max-width:400px">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="1.5" style="margin-bottom:20px"><path d="M3 2h18v4H3zM3 10h18v4H3zM3 18h18v4H3z"/></svg>
        <h2 style="font-size:1.25rem;font-weight:700;color:#111827;margin:0 0 10px"><?= t('main.no_restaurants') ?></h2>
        <p style="color:#6B7280;font-size:.9rem;margin:0 0 24px;line-height:1.5"><?= t('main.no_restaurants_desc') ?></p>
        <a href="<?= BASE_PATH ?>/pages/restaurants.php?add=1" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            <?= t('main.add_restaurant') ?>
        </a>
    </div>
</div>
<?php else: ?>
<?php
    $_mainRest = $restaurants[0] ?? null;
    $_slDays   = [t('days.6'),t('days.0'),t('days.1'),t('days.2'),t('days.3'),t('days.4'),t('days.5')];
    $_slMonths = array_map(fn($i) => mb_strtolower(t('months.'.$i)), range(1,12));
    $_ts       = strtotime($today);
    $topbarTitle = $_slDays[(int)date('w', $_ts)] . ', ' . (int)date('j', $_ts) . '. ' . $_slMonths[(int)date('n', $_ts) - 1];
    $topbarSubtitle =
          '<span id="topbar-stat-confirmed">' . $todayConfirmed . '</span> ' . t('main.topbar_confirmed') . ' · '
        . '<span id="topbar-stat-pending">'   . $todayPending   . '</span> ' . t('main.topbar_pending') . ' · '
        . '<span id="topbar-stat-guests">'    . $todayGuests    . '</span> ' . t('main.topbar_guests');
    ob_start(); ?>
    <button id="btn-daily-report" type="button" class="rz-btn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
        <span><?= t('main.daily_report') ?></span>
    </button>
    <button id="btn-add-reservation" type="button" class="rz-btn rz-btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        <span><?= t('main.new_reservation') ?></span>
    </button>
<?php $topbarActions = ob_get_clean(); require_once '../includes/topbar.php'; ?>

<!-- ── KPI strip TODO: Skrijemo za enkrat ──────────────────────────────────────────── -->
<!-- <div class="rz-kpis">
    <div class="rz-kpi">
        <div class="rz-kpi-label">Rezervacije danes</div>
        <div class="rz-kpi-value"><span id="stat-count">0</span></div>
        <div class="rz-kpi-hint">Skupaj vnosov za današnji dan</div>
    </div>
    <div class="rz-kpi">
        <div class="rz-kpi-label">Gostje</div>
        <div class="rz-kpi-value"><span id="stat-guests">0</span></div>
        <div class="rz-kpi-hint">Pričakovano število oseb</div>
    </div>
    <div class="rz-kpi<?= $pendingCount > 0 ? ' is-accent' : '' ?>">
        <div class="rz-kpi-label">Čakajoče</div>
        <div class="rz-kpi-value"><?= $pendingCount ?></div>
        <div class="rz-kpi-hint">Potrebujejo potrditev</div>
    </div>
    <div class="rz-kpi">
        <div class="rz-kpi-label">Zasedenost</div>
        <div class="rz-kpi-value"><span id="stat-occupancy">—</span></div>
        <div class="rz-kpi-hint">Rezerviranih od razpoložljivih mest</div>
    </div>
</div> -->

<!-- ── Nova postavitev: razpored zgoraj, koledar spodaj ─────── -->
<div class="app-layout-v2">

    <!-- ── Gantt timeline ─────────────────────────────────────── -->
    <div class="rz-card rz-tl-card">
        <div class="rz-card-head">
            <div>
                <div class="rz-card-eyebrow mono">
                    <span id="sched-eyebrow-view"><?= t('main.timeline_label') ?></span> · <span id="sched-eyebrow-date"></span>
                </div>
                <h2 class="rz-card-title display"><?= t('main.schedule_title') ?></h2>
            </div>
            <div class="rz-card-tools">
                <!-- Day navigation (prev/next) -->
                <div class="rz-day-nav" style="display:inline-flex;align-items:center;gap:6px;margin-right:8px">
                    <button type="button" class="rz-iconbtn" id="btn-prev-day" title="<?= t('main.prev_day') ?>" aria-label="<?= t('main.prev_day') ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <button type="button" class="rz-iconbtn" id="btn-next-day" title="<?= t('main.next_day') ?>" aria-label="<?= t('main.next_day') ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                </div>
                <div class="rz-seg" id="sched-view-seg">
                    <button data-view="timeline" class="is-sel"><?= t('main.view_timeline') ?></button>
                    <button data-view="list"><?= t('main.view_list') ?></button>
                </div>
            </div>
        </div>
        <div id="schedule-body" class="schedule-body"></div>
        <div class="rz-tl-legend">
            <span><i class="rz-leg-dot rz-leg-past"></i> <?= t('main.legend_past') ?></span>
            <span><i class="rz-leg-dot rz-leg-now"></i> <?= t('main.legend_now') ?></span>
            <span><i class="rz-leg-dot rz-leg-next"></i> <?= t('main.legend_confirmed') ?></span>
            <span><i class="rz-leg-dot rz-leg-pending"></i> <?= t('main.legend_pending') ?></span>
            <span style="margin-left:auto;font-family:var(--font-mono)" id="tl-legend-now"></span>
        </div>
    </div>

    <!-- ── Spodnja vrstica: kdo prihaja + koledar ────────────── -->
    <div class="rz-grid-2">

        <!-- ── Kdo prihaja ────────────────────────────────────── -->
        <div class="rz-card">
            <div class="rz-card-head">
                <div>
                    <div id="upnext-eyebrow" class="rz-card-eyebrow mono"><?= t('main.upnext_eyebrow') ?></div>
                    <h2 class="rz-card-title display"><?= t('main.upnext_title') ?>
                        <span id="upnext-count" class="rz-upnext-count"></span>
                    </h2>
                </div>
                <!-- <button class="rz-btn rz-btn-ghost" onclick="document.querySelector('.rz-tl-card')?.scrollIntoView({behavior:'smooth'})">
                    Vse
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
                </button> -->
            </div>
            <div id="upnext-list" class="rz-upnext"></div>
        </div>

        <!-- ── Koledar zasedenosti ────────────────────────────── -->
        <div class="rz-card">
            <div class="rz-card-head">
                <div>
                    <div id="cal-title" class="rz-card-eyebrow mono"></div>
                    <h2 class="rz-card-title display"><?= t('main.calendar_title') ?></h2>
                </div>
                <div class="rz-card-tools">
                    <button id="cal-prev" class="rz-iconbtn" title="Prejšnji mesec">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <button id="cal-next" class="rz-iconbtn" title="Naslednji mesec">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                </div>
            </div>
            <div class="rz-mc">
                <div class="rz-mc-dow">
                    <span><?= t('days_short.0') ?></span><span><?= t('days_short.1') ?></span><span><?= t('days_short.2') ?></span><span><?= t('days_short.3') ?></span><span><?= t('days_short.4') ?></span><span><?= t('days_short.5') ?></span><span><?= t('days_short.6') ?></span>
                </div>
                <div id="cal-grid" class="rz-mc-grid"></div>
            </div>
            <div class="rz-card-sep"></div>
            <div class="rz-cal-summary">
                <span><?= t('main.cal_selected') ?>: <strong id="cal-sel-label">–</strong></span>
                <span id="cal-sel-stats"></span>
            </div>
        </div>

    </div>

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
    'restaurants'  => array_map(function($r) use ($hasTablesMap, $tablesMap, $daySchedulesMap, $blackoutDatesMap, $dayPeriodsMap) {
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
            'tables'                => $tablesMap[(int)$r['id']] ?? [],
            'employees_can_override_schedule' => (bool)($r['employees_can_override_schedule'] ?? false),
            'blackout_dates'        => $blackoutDatesMap[(int)$r['id']] ?? [],
            'day_schedules'         => array_map(function($ds) {
                return [
                    'day_of_week' => (int)$ds['day_of_week'],
                    'is_open'     => (bool)$ds['is_open'],
                    'start_time'  => (int)$ds['start_time'],
                    'end_time'    => (int)$ds['end_time'],
                ];
            }, $daySchedulesMap[(int)$r['id']] ?? []),
            'day_periods'           => $dayPeriodsMap[(int)$r['id']] ?? [],
        ];
    }, $restaurants),
    'pendingCount' => $pendingCount,
    'today'        => $today,
    'base'         => BASE_PATH,
    'hasSurvey'       => $isAdmin ? user_has_feature($pdo, (int)$_SESSION['user_id'], 'survey') : false,
    'hasGuestDatabase'=> $isAdmin ? user_has_feature($pdo, (int)$_SESSION['user_id'], 'guest_database') : false,
    'hasTableMgmt'    => $hasTableMgmt,
    'hasRealtime'     => $hasRealtime,
], JSON_UNESCAPED_UNICODE) ?>;
</script>

<!-- ── JavaScript ─────────────────────────────────────────── -->
<?php $cv = time(); ?>
<script>
// Per-page command-palette items — main.php nima dodatkov, ker default
// NAV_COMMANDS že vsebuje "Nova rezervacija" in "Danes". Preprečimo duplikate.
window.RZ_CMD_ITEMS = [];

// Lokalni override-i: ker smo na main.php, akcija opravi dejansko delo
// (klik gumba), brez redirect-a.
window.rz_newReservation = function() {
    var btn = document.getElementById('btn-add-reservation');
    if (btn) btn.click();
};
window.rz_goToday = function() {
    var btn = document.getElementById('btn-today');
    if (btn) btn.click();
};

// Če smo prišli z ?new=1 (deep-link iz cmd palette na drugi strani),
// avtomatsko odpri formo za novo rezervacijo.
if (new URLSearchParams(location.search).get('new') === '1') {
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            var btn = document.getElementById('btn-add-reservation');
            if (btn) btn.click();
            // počisti URL, da se F5 ne ponovi
            try {
                var u = new URL(location.href);
                u.searchParams.delete('new');
                history.replaceState(null, '', u.toString());
            } catch (e) {}
        }, 200);
    });
}

// Deep-link iz cmd palette: ?date=YYYY-MM-DD&res=ID → preklopi datum + odpri rezervacijo.
(function() {
    var params = new URLSearchParams(location.search);
    var deepDate = params.get('date');
    var deepRes  = params.get('res');
    if (!deepDate && !deepRes) return;

    document.addEventListener('DOMContentLoaded', function() {
        function tryOpen(retries) {
            retries = retries || 0;
            if (retries > 50) return; // ~5s
            var appReady   = window.App   && typeof window.App.onDayClick === 'function';
            var modalReady = typeof window.ReservationModal === 'object' && typeof window.ReservationModal.open === 'function';
            if (!appReady) return setTimeout(function() { tryOpen(retries + 1); }, 100);

            // 1. Preklopi datum (App.onDayClick pričakuje Date objekt).
            if (deepDate && /^\d{4}-\d{2}-\d{2}$/.test(deepDate)) {
                try {
                    var parts = deepDate.split('-');
                    var d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
                    window.App.onDayClick(d);
                } catch (e) {}
            }

            // 2. Odpri modal po krajši zamiki, da se schedule naloži.
            if (deepRes && /^\d+$/.test(deepRes) && modalReady) {
                setTimeout(function() {
                    var rid = parseInt(deepRes);
                    // Najprej preveri, če je rezervacija že v UI-ju (z restaurant_id).
                    var card = document.querySelector('[data-reservation-id="' + rid + '"]');
                    if (card) card.click();
                    else {
                        // Fallback: pokliči API za podrobnosti, nato odpri modal.
                        fetch(APP_STATE.base + '/api/reservations.php?id=' + rid)
                            .then(function(r) { return r.json(); })
                            .then(function(json) {
                                if (json && json.success && json.data) {
                                    window.ReservationModal.open('view', json.data);
                                }
                            }).catch(function() {});
                    }
                }, 600);
            }

            // Počisti URL.
            try {
                var u = new URL(location.href);
                u.searchParams.delete('date');
                u.searchParams.delete('res');
                history.replaceState(null, '', u.toString());
            } catch (e) {}
        }
        tryOpen();
    });
})();
</script>
<script src="<?= BASE_PATH ?>/assets/js/api.js?v=<?= $cv ?>"></script>
<script src="<?= BASE_PATH ?>/assets/js/calendar.js?v=<?= $cv ?>"></script>
<script src="<?= BASE_PATH ?>/assets/js/upnext.js?v=<?= $cv ?>"></script>
<script src="<?= BASE_PATH ?>/assets/js/schedule.js?v=<?= $cv ?>"></script>
<script src="<?= BASE_PATH ?>/assets/js/modal.js?v=<?= $cv ?>"></script>
<script src="<?= BASE_PATH ?>/assets/js/daily-report.js?v=<?= $cv ?>"></script>
<script src="<?= BASE_PATH ?>/assets/js/app.js?v=<?= $cv ?>"></script>
<?php if ($hasRealtime): ?>
<script src="<?= BASE_PATH ?>/assets/js/realtime.js?v=<?= $cv ?>"></script>
<script>
// Bootstrap real-time SSE — počakaj na App.init() in nato connect
(function() {
    function bootRealtime() {
        if (!window.Realtime || !window.App || typeof App.getState !== 'function') {
            return setTimeout(bootRealtime, 150);
        }
        const st = App.getState();
        if (st && st.restaurantId) {
            Realtime.connect(st.restaurantId);
        }
        // Spremljaj menjavo restavracije v dropdown-u
        const sel = document.getElementById('restaurant-select');
        if (sel) {
            sel.addEventListener('change', () => {
                const rid = parseInt(sel.value, 10);
                if (rid) Realtime.connect(rid);
                else Realtime.disconnect();
            });
        }
    }
    document.addEventListener('DOMContentLoaded', () => setTimeout(bootRealtime, 200));
})();
</script>
<?php endif; ?>
<script src="<?= BASE_PATH ?>/assets/js/rezble-shell.js?v=<?= $cv ?>"></script>

</main>
</div><!-- /rz-app -->
</body>
</html>
<?php endif; ?>
