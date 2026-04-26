<?php
/**
 * Affiliate click tracking endpoint.
 * Klic: GET /api/affiliate_track.php?ref=CODE&utm_source=...
 * Vrne 204 No Content ali 1x1 piksel.
 */
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/affiliate_helper.php';
require_once '../includes/affiliate_session.php';

aff_session_start();

$refCode = strtoupper(trim($_GET['ref'] ?? ''));
if (!$refCode || !preg_match('/^[A-Z2-9]{8}$/', $refCode)) {
    http_response_code(204);
    exit;
}

$pdo = getDB();
$aff = affiliate_get_by_code($pdo, $refCode);
if (!$aff) {
    http_response_code(204);
    exit;
}

// Nastavi cookie
affiliate_set_cookie($refCode, [
    'utm_source'   => $_GET['utm_source']   ?? '',
    'utm_medium'   => $_GET['utm_medium']   ?? '',
    'utm_campaign' => $_GET['utm_campaign'] ?? '',
]);

// Logiranje (samo če ni bot)
$ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isBot = preg_match('/bot|crawl|spider|slurp|curl|wget/i', $ua);
if (!$isBot) {
    affiliate_log_click($pdo, (int)$aff['id'], $refCode);
}

http_response_code(204);
