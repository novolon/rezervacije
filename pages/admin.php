<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/lang.php';

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

$isAdmin = true;

$stmt = $pdo->prepare("
    SELECT r.id, r.name, r.color FROM restaurants r
    JOIN restaurant_admins ra ON r.id = ra.restaurant_id
    WHERE ra.user_id = ? AND r.is_active = 1 ORDER BY r.name
");
$stmt->execute([$_SESSION['user_id']]);
$restaurants = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM reservations r
    JOIN restaurant_admins ra ON r.restaurant_id = ra.restaurant_id
    WHERE ra.user_id = ? AND r.status = 'pending'
");
$stmt->execute([$_SESSION['user_id']]);
$pendingCount = (int) $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('admin.page_title') ?> – <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css?v=3">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin.css?v=3">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/modal.css?v=3">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/design.css?v=1">
    <script>
    window.__T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
    window.t = function(k, p) { var s = window.__T__[k] || k; if (p) { for (var x in p) s = s.split('{'+x+'}').join(p[x]); } return s; };
    </script>
    <script src="<?= BASE_PATH ?>/assets/js/i18n.js?v=1"></script>
</head>
<body>

<div id="rz-app" class="rz-app">
<?php require_once '../includes/sidebar.php'; ?>
<main class="rz-main">

<?php require_once '../includes/trial_banner.php'; ?>

<!-- ── Vsebina ─────────────────────────────────────────────── -->
<div class="admin-layout">
    <div class="admin-content">
        <h1 class="admin-page-title"><?= t('admin.settings_title') ?></h1>

        <!-- Panel: Restavracije + Zaposleni -->
        <div id="panel-restaurants" class="admin-panel active">

            <!-- Restavracije -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h2><?= t('admin.restaurants_title') ?></h2>
                    <button id="btn-add-restaurant" class="btn btn-primary btn-sm">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                        <?= t('admin.add_restaurant') ?>
                    </button>
                </div>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th><?= t('admin.col_name') ?></th>
                                <th><?= t('admin.col_duration') ?></th>
                                <th><?= t('admin.col_color') ?></th>
                                <th><?= t('admin.col_status') ?></th>
                                <th><?= t('admin.col_booking') ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="rest-tbody">
                            <tr><td colspan="6" class="table-empty"><?= t('admin.loading') ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Zaposleni po restavraciji -->
            <div class="admin-card" style="margin-top:20px">
                <div class="admin-card-header">
                    <h2><?= t('admin.staff_title') ?></h2>
                    <button id="btn-add-user" class="btn btn-primary btn-sm">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                        <?= t('admin.add_staff') ?>
                    </button>
                </div>
                <div id="users-by-rest">
                    <div style="padding:16px 20px;color:var(--color-muted);font-size:.875rem"><?= t('admin.loading') ?></div>
                </div>
            </div>

            <!-- skrita tabela za JS kompatibilnost -->
            <table style="display:none"><tbody id="users-tbody"></tbody></table>

        </div><!-- /panel-restaurants -->
    </div>
</div>

<!-- Toast kontejner -->
<div id="toast-container"></div>

<!-- APP_STATE (minimalen) -->
<script>
window.APP_STATE = <?= json_encode([
    'userId'    => (int) $_SESSION['user_id'],
    'role'      => $_SESSION['role'],
    'planSlug'  => $_SESSION['plan_slug'] ?? 'basic',
    'restaurants' => [],
    'today'     => date('Y-m-d'),
    'base'      => BASE_PATH,
], JSON_UNESCAPED_UNICODE) ?>;
</script>

<script src="<?= BASE_PATH ?>/assets/js/api.js?v=2"></script>
<script src="<?= BASE_PATH ?>/assets/js/admin.js?v=10"></script>

</main>
</div><!-- /rz-app -->
</body>
</html>
