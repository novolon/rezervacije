<?php
/**
 * Cron: premakne affiliate provizije iz 'pending' → 'payable'
 * ko poteče hold period (available_at <= NOW()).
 * Pogostost: dnevno ob 03:00
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

$pdo = getDB();

$affected = $pdo->exec("
    UPDATE affiliate_commissions
    SET status = 'payable'
    WHERE status = 'pending' AND available_at <= NOW()
");

echo date('Y-m-d H:i:s') . " affiliate_payable: {$affected} provizij premaknjenih na payable\n";
