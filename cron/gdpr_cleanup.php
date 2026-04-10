<?php
/**
 * GDPR čiščenje – zaženi enkrat tedensko (npr. vsako nedeljo ob 02:00)
 * Cron: 0 2 * * 0 php /path/to/cron/gdpr_cleanup.php
 *
 * Akcije:
 *  1. Anonimizacija rezervacij starejših od 3 let
 *  2. Brisanje neizpolnjenih anket starejših od 6 mesecev
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = getDB();

// 1. Anonimiziraj rezervacije starejše od 3 let
//    Ohrani: restaurant_id, reservation_date, reservation_time, guest_count, status – za statistiko
$stmt = $pdo->prepare("
    UPDATE reservations
    SET guest_name = 'Anonimizirano',
        email      = NULL,
        phone      = NULL,
        notes      = NULL
    WHERE reservation_date < DATE_SUB(CURDATE(), INTERVAL 3 YEAR)
      AND email IS NOT NULL
");
$stmt->execute();
$anonCount = $stmt->rowCount();

// 2. Briši neizpolnjene ankete starejše od 6 mesecev
//    (survey_responses brez submitted_at)
$delCount = 0;
try {
    $del = $pdo->prepare("
        DELETE FROM survey_responses
        WHERE submitted_at IS NULL
          AND created_at < NOW() - INTERVAL 6 MONTH
    ");
    $del->execute();
    $delCount = $del->rowCount();
} catch (PDOException $e) {
    // Tabela morda še ne obstaja
    error_log('GDPR cleanup survey_responses: ' . $e->getMessage());
}

echo date('Y-m-d H:i:s') . " GDPR cleanup: {$anonCount} rezervacij anonimizirano, {$delCount} anket odstranjeno.\n";
