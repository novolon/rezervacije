<?php
/**
 * Cron: 24h opomniki za goste.
 * Pošlje opomnik za vse potrjene rezervacije z emailom za jutri.
 * Samo za restavracije katerih admin ima vklopljeno 'guest_reminders' (Advanced/Premium).
 *
 * Zaženi vsak dan ob 9:00:
 *   0 9 * * * php /volume1/web/rezervacije-saas/cron/reminders.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/plans.php';

$pdo = getDB();
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// Pridobi vse potrjene rezervacije z emailom za jutri skupaj z lastnik adminom restavracije
$stmt = $pdo->prepare("
    SELECT r.id, r.restaurant_id, r.guest_name, r.email, r.reservation_date, r.reservation_time, r.guest_count,
           res.name AS restaurant_name,
           COALESCE(r.duration, res.reservation_duration, 60) AS effective_duration,
           res.contact_email AS restaurant_contact_email,
           res.contact_phone AS restaurant_contact_phone,
           res.address       AS restaurant_address,
           ra.user_id AS admin_user_id
    FROM reservations r
    JOIN restaurants res ON r.restaurant_id = res.id
    LEFT JOIN restaurant_admins ra ON ra.restaurant_id = res.id
    WHERE r.reservation_date = ?
      AND r.status = 'confirmed'
      AND r.email IS NOT NULL
      AND r.email != ''
    GROUP BY r.id, ra.user_id
");
$stmt->execute([$tomorrow]);
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent   = 0;
$skip   = 0;
$errors = 0;

foreach ($reservations as $r) {
    // Preveri ali admin restavracije ima guest_reminders feature
    $adminId = (int)($r['admin_user_id'] ?? 0);
    if ($adminId && !user_has_feature($pdo, $adminId, 'guest_reminders')) {
        $skip++;
        continue;
    }

    $time = substr($r['reservation_time'], 0, 5);
    mailer_use_restaurant($pdo, (int)$r['restaurant_id']);
    try {
        $ok = send_booking_reminder_guest(
            $r['email'],
            $r['guest_name'],
            $r['restaurant_name'],
            $r['reservation_date'],
            $time,
            (int) $r['guest_count'],
            (int) $r['effective_duration'],
            (string)($r['restaurant_contact_email'] ?? ''),
            (string)($r['restaurant_contact_phone'] ?? ''),
            'sl',
            (string)($r['restaurant_address'] ?? '')
        );
    } finally { mailer_use_default(); }
    if ($ok) {
        $sent++;
    } else {
        $errors++;
        error_log("Reminder failed for reservation #{$r['id']} ({$r['email']})");
    }
}

$date = date('Y-m-d H:i:s');
echo "[{$date}] Opomniki poslani: {$sent}, preskočeni (plan): {$skip}, napake: {$errors}\n";
