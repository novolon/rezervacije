<?php
/**
 * Cron: Čakalna lista – potek notified vnosov in prehod na naslednjega gosta.
 * Priporoča se: vsake 15 minut
 * Primer cron zapisa: */15 * * * * php /path/to/cron/waitlist_expire.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/waitlist_notifier.php';

$pdo = getDB();

// Poišči notified vnose kjer je expires_at pretečen
$stmt = $pdo->prepare("
    SELECT id, restaurant_id, date
    FROM waitlist
    WHERE status = 'notified'
      AND expires_at <= NOW()
");
$stmt->execute();
$expired = $stmt->fetchAll();

$count = 0;
foreach ($expired as $entry) {
    // Označi kot expired
    $pdo->prepare("UPDATE waitlist SET status = 'expired' WHERE id = ?")
        ->execute([$entry['id']]);

    // Obvesti naslednjega gosta v vrsti
    try {
        notify_waitlist($pdo, (int)$entry['restaurant_id'], $entry['date']);
    } catch (Throwable $e) {
        error_log('waitlist_expire cron notify error: ' . $e->getMessage());
    }
    $count++;
}

if ($count > 0) {
    error_log("waitlist_expire: {$count} vnosov poteklo, naslednji gostje obveščeni.");
}
