<?php
require_once 'includes/auth_check.php';
require_once 'includes/db.php';
require_once 'includes/affiliate_helper.php';

// Track affiliate ref before redirecting (cookie survives the redirect)
$_refParam = strtoupper(trim($_GET['ref'] ?? ''));
if ($_refParam && preg_match('/^[A-Z2-9]{8}$/', $_refParam)) {
    try {
        $_pdo     = getDB();
        $_affData = affiliate_get_by_code($_pdo, $_refParam);
        if ($_affData) {
            affiliate_set_cookie($_refParam, [
                'utm_source'   => $_GET['utm_source']   ?? 'affiliate',
                'utm_medium'   => $_GET['utm_medium']   ?? 'referral',
                'utm_campaign' => $_GET['utm_campaign'] ?? '',
            ]);
            affiliate_log_click($_pdo, (int)$_affData['id'], $_refParam);
        }
    } catch (Throwable $_e) {
        error_log('Affiliate ref tracking (index.php): ' . $_e->getMessage());
    }
}

if (is_logged_in()) {
    redirect_to_main();
} else {
    redirect_to_login();
}
