<?php
/**
 * Ročna prestavitev mize za rezervacijo (s strani osebja).
 *
 * PUT body: { reservation_id, table_ids: [id,...], merge_group_id: null|N }
 *
 * Preveri dostop, najde razpoložljivo mizo (z excludeResId), zamenja dodelitev.
 */

require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/table_helper.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_auth();
$pdo     = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    json_response(false, null, 'Metoda ni podprta.', 405);
}

$body          = get_body();
$reservationId = isset($body['reservation_id']) ? (int)$body['reservation_id'] : 0;
$tableIds      = isset($body['table_ids']) ? array_map('intval', (array)$body['table_ids']) : [];
$mergeGroupId  = isset($body['merge_group_id']) && $body['merge_group_id'] ? (int)$body['merge_group_id'] : null;

if (!$reservationId) json_response(false, null, 'reservation_id je obvezen.', 400);
if (empty($tableIds)) json_response(false, null, 'table_ids so obvezni.', 400);

// Naloži rezervacijo
$stmtR = $pdo->prepare("
    SELECT r.id, r.restaurant_id, r.reservation_date, r.reservation_time,
           r.duration, r.guest_count, r.status,
           res.reservation_duration AS default_duration
    FROM reservations r
    JOIN restaurants res ON r.restaurant_id = res.id
    WHERE r.id = ?
");
$stmtR->execute([$reservationId]);
$reservation = $stmtR->fetch(PDO::FETCH_ASSOC);

if (!$reservation) json_response(false, null, 'Rezervacija ne obstaja.', 404);

// Preveri dostop do restavracije
$restId = (int)$reservation['restaurant_id'];
$hasAccess = false;
if ($session['role'] === 'superadmin') {
    $hasAccess = true;
} elseif ($session['role'] === 'admin') {
    $stmtA = $pdo->prepare("SELECT 1 FROM restaurant_admins WHERE restaurant_id = ? AND user_id = ?");
    $stmtA->execute([$restId, $session['user_id']]);
    $hasAccess = (bool)$stmtA->fetchColumn();
} elseif ($session['role'] === 'user') {
    $hasAccess = (int)$session['restaurant_id'] === $restId;
}
if (!$hasAccess) json_response(false, null, 'Dostop zavrnjen.', 403);

// Feature check
if ($session['role'] !== 'superadmin') {
    // Pridobi owner_id restavracije za feature check
    $stmtOwner = $pdo->prepare("SELECT owner_id FROM restaurants WHERE id = ?");
    $stmtOwner->execute([$restId]);
    $ownerId = (int)$stmtOwner->fetchColumn();
    if (!user_has_feature($pdo, $ownerId, 'table_management')) {
        json_response(false, null, 'Ta funkcionalnost ni na voljo v vašem paketu.', 403);
    }
}

// Preveri, da vse zahtevane mize pripadajo tej restavraciji
$placeholders = implode(',', array_fill(0, count($tableIds), '?'));
$stmtCheck = $pdo->prepare("
    SELECT COUNT(*) FROM restaurant_tables
    WHERE id IN ($placeholders) AND restaurant_id = ? AND is_active = 1
");
$stmtCheck->execute([...$tableIds, $restId]);
if ((int)$stmtCheck->fetchColumn() !== count($tableIds)) {
    json_response(false, null, 'Ena ali več miz ne obstaja ali ne pripada tej restavraciji.', 400);
}

// Preveri, da so zahtevane mize proste (z izključitvijo te rezervacije)
$durationMins = (int)($reservation['duration'] ?? $reservation['default_duration']);
$time         = $reservation['reservation_time'];
$date         = $reservation['reservation_date'];

// Poišči zasedene table_id-je za ta termin (izključi to rezervacijo)
[$h, $m] = explode(':', $time);
$newStart = (int)$h * 60 + (int)$m;
$newEnd   = $newStart + $durationMins;

$stmtOcc = $pdo->prepare("
    SELECT DISTINCT rta.table_id
    FROM reservation_table_assignments rta
    JOIN reservations r   ON rta.reservation_id = r.id
    JOIN restaurants  res ON r.restaurant_id    = res.id
    WHERE r.restaurant_id = :restId
      AND r.reservation_date = :date
      AND r.status IN ('confirmed', 'pending')
      AND r.id != :excludeId
      AND (TIME_TO_SEC(r.reservation_time) / 60) < :newEnd
      AND (TIME_TO_SEC(r.reservation_time) / 60
           + COALESCE(r.duration, res.reservation_duration)) > :newStart
");
$stmtOcc->execute([
    ':restId'    => $restId,
    ':date'      => $date,
    ':excludeId' => $reservationId,
    ':newEnd'    => $newEnd,
    ':newStart'  => $newStart,
]);
$occupiedIds = array_column($stmtOcc->fetchAll(PDO::FETCH_ASSOC), 'table_id');
$occupiedSet = array_flip($occupiedIds);

foreach ($tableIds as $tid) {
    if (isset($occupiedSet[$tid])) {
        json_response(false, null, 'Ena ali več zahtevanih miz je zasedenih v tem terminu.', 409);
    }
}

// Zamenjaj dodelitev v transakciji
try {
    $pdo->beginTransaction();

    clear_table_assignments($pdo, $reservationId);

    $stmtIns = $pdo->prepare("
        INSERT INTO reservation_table_assignments
            (reservation_id, table_id, merge_group_id, assigned_by)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($tableIds as $tid) {
        $stmtIns->execute([$reservationId, $tid, $mergeGroupId, (int)$session['user_id']]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('Table reassignment: ' . $e->getMessage());
    json_response(false, null, 'Napaka pri shranjevanju.', 500);
}

// Vrni posodobljene dodelitve
$assignments = get_table_assignments($pdo, $reservationId);
json_response(true, ['table_assignments' => $assignments]);
