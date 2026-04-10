<?php
/**
 * Cron job: pošlje zakasnele ankete.
 * Zaženi vsake 15–30 minut.
 *
 * Synology Task Scheduler primer:
 *   /usr/local/bin/php /var/www/rezervacije-saas/cron/survey_send.php >> /var/log/survey_send.log 2>&1
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/survey_mailer.php';

$pdo = getDB();

$stmt = $pdo->prepare("
    SELECT sr.*
    FROM survey_responses sr
    WHERE sr.email_sent_at IS NULL
      AND sr.scheduled_send_at <= NOW()
    ORDER BY sr.scheduled_send_at ASC
    LIMIT 50
");
$stmt->execute();
$pending = $stmt->fetchAll();

$sent  = 0;
$failed = 0;

foreach ($pending as $sr) {
    $ok = send_survey_email($pdo, $sr);
    if ($ok) {
        $sent++;
        echo date('Y-m-d H:i:s') . " Poslano survey_response #{$sr['id']} na {$sr['email']}\n";
    } else {
        $failed++;
        echo date('Y-m-d H:i:s') . " NAPAKA survey_response #{$sr['id']} na {$sr['email']}\n";
    }
}

echo date('Y-m-d H:i:s') . " Zaključeno: {$sent} poslano, {$failed} napak.\n";
