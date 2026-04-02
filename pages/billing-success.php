<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';

if (!is_logged_in()) redirect_to_login();
if ($_SESSION['role'] !== 'admin') {
    header('Location: ' . BASE_PATH . '/pages/main.php'); exit;
}

$pdo = getDB();
// Razveljavi session cache da se naroč. takoj osveži
unset($_SESSION['_sub_cached_at']);
refresh_subscription_session($pdo);

$sub      = get_active_subscription($pdo, (int)$_SESSION['user_id']);
$planName = PLANS[$sub['plan_slug'] ?? 'basic']['name'] ?? 'paket';
$fullName = $_SESSION['full_name'];
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plačilo uspešno – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
</head>
<body>
<header class="app-header">
    <a href="<?= BASE_PATH ?>/pages/main.php" class="header-logo">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
            <rect width="28" height="28" rx="7" fill="#F59E0B"/>
            <path d="M7 10h14M7 14h14M7 18h9" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <?= h(APP_NAME) ?>
    </a>
    <div class="header-actions">
        <span class="header-user">👤 <?= h($fullName) ?></span>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
    </div>
</header>

<div style="display:flex;align-items:center;justify-content:center;min-height:calc(100vh - 64px);padding:24px">
    <div style="text-align:center;max-width:440px">
        <div style="width:64px;height:64px;background:#D1FAE5;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h1 style="font-size:1.5rem;font-weight:700;color:#111827;margin:0 0 10px">Plačilo uspešno!</h1>
        <p style="color:#6B7280;margin:0 0 8px">Paket <strong><?= h($planName) ?></strong> je aktiviran.</p>
        <p style="color:#6B7280;font-size:.875rem;margin:0 0 28px">Zahvaljujemo se za zaupanje. Vse funkcionalnosti so zdaj na voljo.</p>
        <a href="<?= BASE_PATH ?>/pages/main.php" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            Na razpored
        </a>
    </div>
</div>
</body>
</html>
