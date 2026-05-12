<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/lang.php';

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

$restaurants = [];
if ($isAdmin) {
    $stmt = $pdo->prepare("
        SELECT r.id, r.name, r.color FROM restaurants r
        JOIN restaurant_admins ra ON r.id = ra.restaurant_id
        WHERE ra.user_id = ? AND r.is_active = 1 ORDER BY r.name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $restaurants = $stmt->fetchAll();
} elseif (!empty($_SESSION['restaurant_id'])) {
    $stmt = $pdo->prepare("SELECT id, name, color FROM restaurants WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['restaurant_id']]);
    $restaurants = $stmt->fetchAll();
}

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM reservations r
    JOIN restaurant_admins ra ON r.restaurant_id = ra.restaurant_id
    WHERE ra.user_id = ? AND r.status = 'pending'
");
$stmt->execute([$_SESSION['user_id']]);
$pendingCount = (int) $stmt->fetchColumn();

// Aktivna restavracija iz seje
$activeRestId = 0;
if (!empty($_SESSION['restaurant_id'])) {
    foreach ($restaurants as $r) {
        if ((int)$r['id'] === (int)$_SESSION['restaurant_id']) {
            $activeRestId = (int)$r['id'];
            break;
        }
    }
}
if (!$activeRestId && !empty($restaurants)) {
    $activeRestId = (int)$restaurants[0]['id'];
}
?>
<?php
$pageTitle = t('stats.title');
$extraCss  = ['main.css?v=4', 'stats.css?v=1', 'design.css?v=2'];
require_once '../includes/html_head.php';
?>
<body>

<div id="rz-app" class="rz-app">
<?php require_once '../includes/sidebar.php'; ?>
<main class="rz-main">

<?php require_once '../includes/trial_banner.php'; ?>

<?php
    $topbarTitle    = t('stats.title');
    $topbarSubtitle = t('stats.subtitle');
    ob_start(); ?>
    <button id="btn-export" type="button" class="rz-btn">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span><?= t('stats.export_csv') ?></span>
    </button>
<?php $topbarActions = ob_get_clean(); require_once '../includes/topbar.php'; ?>

<!-- ── Vsebina ─────────────────────────────────────────────── -->
<div class="stats-wrap">

    <!-- Filter vrstica -->
    <div class="rz-card" style="margin-bottom:20px">
        <div class="stats-filters" style="margin:0">
            <div class="stats-filters-left">
                <div class="period-btns">
                    <button class="period-btn active" data-days="30"><?= t('stats.period_30') ?></button>
                    <button class="period-btn" data-days="90"><?= t('stats.period_90') ?></button>
                    <button class="period-btn" data-days="180"><?= t('stats.period_180') ?></button>
                    <button class="period-btn" data-days="365"><?= t('stats.period_365') ?></button>
                    <button class="period-btn" data-days="custom"><?= t('stats.period_custom') ?></button>
                </div>

                <div id="custom-dates" style="display:none;gap:8px;align-items:center" class="custom-dates">
                    <input type="date" id="filter-from" class="filter-input rz-input">
                    <span style="color:var(--ink-mute)">–</span>
                    <input type="date" id="filter-to" class="filter-input rz-input">
                    <button id="btn-apply-dates" class="btn btn-primary btn-sm rz-btn rz-btn-accent"><?= t('stats.custom_show') ?></button>
                </div>

                <!-- Točni datumi izbranega obdobja -->
                <div id="period-range-label" style="font-size:.825rem;color:var(--color-muted);margin-top:8px"></div>
            </div>
        </div>
    </div>

    <!-- KPI kartice -->
    <div class="rz-kpis" id="kpi-grid">
        <div class="rz-kpi">
            <div class="rz-kpi-label"><?= t('stats.kpi_total') ?></div>
            <div class="rz-kpi-value" id="kpi-total">–</div>
            <div class="rz-kpi-delta" id="kpi-total-delta"></div>
            <div class="rz-kpi-hint"><?= t('stats.kpi_total_hint') ?></div>
        </div>
        <div class="rz-kpi">
            <div class="rz-kpi-label"><?= t('stats.kpi_guests') ?></div>
            <div class="rz-kpi-value" id="kpi-guests">–</div>
            <div class="rz-kpi-delta" id="kpi-guests-delta"></div>
            <div class="rz-kpi-hint"><?= t('stats.kpi_guests_hint') ?></div>
        </div>
        <div class="rz-kpi">
            <div class="rz-kpi-label"><?= t('stats.kpi_avg') ?></div>
            <div class="rz-kpi-value" id="kpi-avg">–</div>
            <div class="rz-kpi-hint"><?= t('stats.kpi_avg_hint') ?></div>
        </div>
        <div class="rz-kpi">
            <div class="rz-kpi-label"><?= t('stats.kpi_arrival') ?></div>
            <div class="rz-kpi-value" id="kpi-arrival">–</div>
            <div class="rz-kpi-delta" id="kpi-arrival-delta"></div>
            <div class="rz-kpi-hint"><?= t('stats.kpi_arrival_hint') ?></div>
        </div>
    </div>

    <!-- Trend po mesecih -->
    <div class="rz-card">
        <div class="rz-card-head">
            <div>
                <div class="rz-card-eyebrow"><?= t('stats.trend_eyebrow') ?></div>
                <h2 class="rz-card-title"><?= t('stats.trend_title') ?></h2>
                <div class="rz-card-subtitle" id="trend-range" style="font-size:.825rem;color:var(--color-muted);margin-top:4px"></div>
            </div>
        </div>
        <div class="chart-wrap">
            <canvas id="chart-monthly"></canvas>
        </div>
    </div>

    <!-- Dnevi in ure (vzporedno) -->
    <div class="stats-grid-2" style="margin-top:20px">
        <div class="rz-card">
            <div class="rz-card-head">
                <h2 class="rz-card-title"><?= t('stats.chart_days') ?></h2>
            </div>
            <div class="chart-wrap chart-wrap-bar">
                <canvas id="chart-days"></canvas>
            </div>
        </div>
        <div class="rz-card">
            <div class="rz-card-head">
                <h2 class="rz-card-title"><?= t('stats.chart_hours') ?></h2>
            </div>
            <div class="chart-wrap chart-wrap-bar">
                <canvas id="chart-hours"></canvas>
            </div>
        </div>
    </div>

    <!-- Vir rezervacij -->
    <div class="stats-grid-2" style="margin-top:20px">
        <div class="rz-card">
            <div class="rz-card-head">
                <h2 class="rz-card-title"><?= t('stats.chart_sources') ?></h2>
            </div>
            <div class="chart-wrap chart-wrap-pie">
                <canvas id="chart-sources"></canvas>
            </div>
        </div>
        <div class="rz-card">
            <div class="rz-card-head">
                <h2 class="rz-card-title"><?= t('stats.chart_groups') ?></h2>
            </div>
            <div class="chart-wrap chart-wrap-bar">
                <canvas id="chart-groups"></canvas>
            </div>
        </div>
    </div>

    <!-- Stalni gostje -->
    <div class="rz-card" id="section-returning" style="margin-top:20px">
        <div class="rz-card-head">
            <h2 class="rz-card-title"><?= t('stats.returning_title') ?></h2>
            <div class="returning-filter rz-card-tools">
                <button class="period-btn active" id="btn-all-guests"><?= t('stats.btn_all_guests') ?></button>
                <button class="period-btn" id="btn-returning-only"><?= t('stats.btn_returning_only') ?></button>
            </div>
        </div>

        <!-- KPI -->
        <div class="rz-kpis" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px">
            <div class="rz-kpi">
                <div class="rz-kpi-label"><?= t('stats.ret_unique') ?></div>
                <div class="rz-kpi-value" id="ret-unique">–</div>
            </div>
            <div class="rz-kpi">
                <div class="rz-kpi-label"><?= t('stats.ret_returning') ?></div>
                <div class="rz-kpi-value" id="ret-returning">–</div>
            </div>
            <div class="rz-kpi">
                <div class="rz-kpi-label"><?= t('stats.ret_pct') ?></div>
                <div class="rz-kpi-value" id="ret-pct">–</div>
            </div>
        </div>

        <!-- Tabela Top gostov -->
        <div class="admin-table-wrap">
            <table class="rz-table admin-table" id="guests-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?= t('stats.table_name') ?></th>
                        <th><?= t('stats.table_email') ?></th>
                        <th><?= t('stats.table_visits') ?></th>
                        <th><?= t('stats.table_total_guests') ?></th>
                        <th><?= t('stats.table_first_visit') ?></th>
                        <th><?= t('stats.table_last_visit') ?></th>
                    </tr>
                </thead>
                <tbody id="guests-tbody">
                    <tr><td colspan="7" class="table-empty"><?= t('common.loading') ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- AI Insights -->
    <div class="rz-card" id="section-insights" style="margin-top:20px">
        <div class="rz-card-head">
            <div>
                <div class="rz-card-eyebrow"><?= t('stats.insights_eyebrow') ?></div>
                <h2 class="rz-card-title"><?= t('stats.insights_title') ?></h2>
            </div>
        </div>
        <div id="insights-list" style="display:flex;flex-direction:column;gap:10px">
            <div style="text-align:center;color:var(--ink-mute);padding:24px;font-size:13px"><?= t('common.loading') ?></div>
        </div>
    </div>

</div><!-- .stats-wrap -->

<div id="toast-container"></div>

<script>
window.APP_STATE = <?= json_encode([
    'base'         => BASE_PATH,
    'role'         => $_SESSION['role'],
    'restaurantId' => $activeRestId,
], JSON_UNESCAPED_UNICODE) ?>;
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="<?= BASE_PATH ?>/assets/js/api.js?v=2"></script>
<script src="<?= BASE_PATH ?>/assets/js/stats.js?v=1"></script>
<script src="<?= BASE_PATH ?>/assets/js/rezble-shell.js?v=1"></script>

</main>
</div><!-- /rz-app -->
</body>
</html>
