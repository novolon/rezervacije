<?php
/**
 * Cron: 24h opomniki za goste.
 * Pošlje opomnik za vse potrjene rezervacije z emailom za jutri.
 *
 * Zaženi vsak dan ob 9:00:
 *   0 9 * * * php /volume1/web/rezervacije-saas/cron/reminders.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';

$pdo = getDB();
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$stmt = $pdo->prepare("
    SELECT r.id, r.guest_name, r.email, r.reservation_date, r.reservation_time, r.guest_count,
           res.name AS restaurant_name,
           COALESCE(r.duration, res.reservation_duration, 60) AS effective_duration
    FROM reservations r
    JOIN restaurants res ON r.restaurant_id = res.id
    WHERE r.reservation_date = ?
      AND r.status = 'confirmed'
      AND r.email IS NOT NULL
      AND r.email != ''
");
$stmt->execute([$tomorrow]);
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent  = 0;
$errors = 0;

foreach ($reservations as $r) {
    $time = substr($r['reservation_time'], 0, 5);
    $ok = send_booking_reminder_guest(
        $r['email'],
        $r['guest_name'],
        $r['restaurant_name'],
        $r['reservation_date'],
        $time,
        (int) $r['guest_count'],
        (int) $r['effective_duration']
    );
    if ($ok) {
        $sent++;
    } else {
        $errors++;
        error_log("Reminder failed for reservation #{$r['id']} ({$r['email']})");
    }
}

$date = date('Y-m-d H:i:s');
echo "[{$date}] Opomniki poslani: {$sent}, napake: {$errors}\n";
