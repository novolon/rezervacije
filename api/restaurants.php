<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/survey_helper.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_admin(); // admin ali superadmin
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

// Pomožna: vrni day_schedules in blackouts za restavracijo
function fetch_day_schedules(PDO $pdo, int $id): array {
    try {
        $stmt = $pdo->prepare("SELECT day_of_week, is_open, start_time, end_time FROM restaurant_day_schedules WHERE restaurant_id = ? ORDER BY day_of_week");
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

function fetch_blackouts(PDO $pdo, int $id): array {
    try {
        $stmt = $pdo->prepare("SELECT blackout_date, reason FROM restaurant_blackouts WHERE restaurant_id = ? AND blackout_date >= CURDATE() ORDER BY blackout_date");
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

// Pomožna: shrani day_schedules in posodobi schedule_start/end ter booking_open_days
function save_day_schedules(PDO $pdo, int $id, array $daySchedules): void {
    $stmt = $pdo->prepare("INSERT INTO restaurant_day_schedules (restaurant_id, day_of_week, is_open, start_time, end_time)
        VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE is_open=VALUES(is_open), start_time=VALUES(start_time), end_time=VALUES(end_time)");
    $openDays = 0;
    $starts = []; $ends = [];
    foreach ($daySchedules as $ds) {
        $dow   = max(0, min(6, (int)$ds['day_of_week']));
        $open  = $ds['is_open'] ? 1 : 0;
        $start = max(0, min(1439, (int)$ds['start_time']));
        $end   = max(1, min(1440, (int)$ds['end_time']));
        $stmt->execute([$id, $dow, $open, $start, $end]);
        if ($open) { $openDays |= (1 << $dow); $starts[] = $start; $ends[] = $end; }
    }
    // Posodobi globalne vrednosti (fallback za staro kodo)
    $schedStart = $starts ? min($starts) : 480;
    $schedEnd   = $ends   ? max($ends)   : 1380;
    $pdo->prepare("UPDATE restaurants SET schedule_start=?, schedule_end=?, booking_open_days=? WHERE id=?")
        ->execute([$schedStart, $schedEnd, $openDays, $id]);
}

// ─── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($id) {
        if ($session['role'] === 'superadmin') {
            $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = ?");
            $stmt->execute([$id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT r.* FROM restaurants r
                JOIN restaurant_admins ra ON r.id = ra.restaurant_id
                WHERE r.id = ? AND ra.user_id = ?
            ");
            $stmt->execute([$id, $session['user_id']]);
        }
        $row = $stmt->fetch();
        if (!$row) json_response(false, null, 'Restavracija ne obstaja.', 404);
        $row['day_schedules'] = fetch_day_schedules($pdo, $id);
        $row['blackouts']     = fetch_blackouts($pdo, $id);
        json_response(true, $row);
    }

    // Seznam restavracij
    if ($session['role'] === 'superadmin') {
        $stmt = $pdo->query("SELECT r.*, u.full_name AS owner_name FROM restaurants r JOIN users u ON r.owner_id = u.id ORDER BY r.name");
    } else {
        $stmt = $pdo->prepare("SELECT r.* FROM restaurants r JOIN restaurant_admins ra ON r.id = ra.restaurant_id WHERE ra.user_id = ? ORDER BY r.name");
        $stmt->execute([$session['user_id']]);
    }
    json_response(true, $stmt->fetchAll());
}

// ─── POST (ustvari) ────────────────────────────────────────────
if ($method === 'POST') {
    if ($session['role'] === 'superadmin') {
        json_response(false, null, 'Superadmin ne ustvarja restavracij.', 403);
    }

    $body = get_body();
    $name = trim($body['name'] ?? '');
    if (!$name) json_response(false, null, 'Ime restavracije je obvezno.', 400);

    $duration     = isset($body['reservation_duration']) ? max(15, (int)$body['reservation_duration']) : 60;
    $allow_custom = isset($body['allow_custom_duration']) ? ($body['allow_custom_duration'] ? 1 : 0) : 0;
    $color        = preg_match('/^#[0-9A-Fa-f]{6}$/', $body['color'] ?? '') ? $body['color'] : '#F59E0B';

    // Izračunaj schedule_start/end iz day_schedules (če so podane), sicer default
    $daySchedules = !empty($body['day_schedules']) && is_array($body['day_schedules']) ? $body['day_schedules'] : null;
    $sched_start  = 480; $sched_end = 1380; $openDaysMask = 127;
    if ($daySchedules) {
        $starts = []; $ends = []; $mask = 0;
        foreach ($daySchedules as $ds) {
            if ($ds['is_open']) {
                $mask |= (1 << (int)$ds['day_of_week']);
                $starts[] = (int)$ds['start_time'];
                $ends[]   = (int)$ds['end_time'];
            }
        }
        if ($starts) { $sched_start = min($starts); $sched_end = max($ends); }
        $openDaysMask = $mask;
    }

    try {
        $pdo->beginTransaction();
        $bookingToken = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO restaurants (name, owner_id, reservation_duration, allow_custom_duration, schedule_start, schedule_end, color, booking_token, booking_open_days) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$name, $session['user_id'], $duration, $allow_custom, $sched_start, $sched_end, $color, $bookingToken, $openDaysMask]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO restaurant_admins (restaurant_id, user_id) VALUES (?,?)")->execute([$id, $session['user_id']]);

        // Shrani day_schedules
        if ($daySchedules) {
            save_day_schedules($pdo, $id, $daySchedules);
        } else {
            // Default: vsi dnevi odprti, 08:00–23:00
            $default = [];
            for ($i = 0; $i < 7; $i++) $default[] = ['day_of_week' => $i, 'is_open' => 1, 'start_time' => 480, 'end_time' => 1380];
            save_day_schedules($pdo, $id, $default);
        }

        $pdo->commit();

        // Seed prednastavjene ankete za Advanced/Premium
        if (user_has_feature($pdo, (int)$session['user_id'], 'survey')) {
            try { seed_default_survey($pdo, $id); } catch (Exception $ex) {
                error_log('Survey seed error: ' . $ex->getMessage());
            }
        }

        $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $row['day_schedules'] = fetch_day_schedules($pdo, $id);
        $row['blackouts']     = [];
        json_response(true, $row, '', 201);

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Restaurant create error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju.', 500);
    }
}

// ─── PUT (uredi / add_blackout) ────────────────────────────────
if ($method === 'PUT') {
    $id     = isset($_GET['id'])     ? (int)$_GET['id']    : 0;
    $action = trim($_GET['action'] ?? '');
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    if (!admin_owns_restaurant($pdo, $session, $id)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    $body = get_body();

    // ── Dodaj blokiran datum ──────────────────────────────────────
    if ($action === 'add_blackout') {
        $date   = trim($body['date']   ?? '');
        $reason = trim($body['reason'] ?? '') ?: null;
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            json_response(false, null, 'Neveljaven datum.', 400);
        }
        try {
            $pdo->prepare("INSERT INTO restaurant_blackouts (restaurant_id, blackout_date, reason) VALUES (?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason)")
                ->execute([$id, $date, $reason]);
            json_response(true, ['blackout_date' => $date, 'reason' => $reason]);
        } catch (PDOException $e) {
            json_response(false, null, 'Napaka pri dodajanju datuma.', 500);
        }
    }

    // ── Navadni PUT (posodobitev nastavitev) ──────────────────────
    $name         = trim($body['name'] ?? '');
    $duration     = isset($body['reservation_duration']) ? max(15, (int)$body['reservation_duration']) : null;
    $allow_custom = isset($body['allow_custom_duration']) ? ($body['allow_custom_duration'] ? 1 : 0) : null;
    $color        = preg_match('/^#[0-9A-Fa-f]{6}$/', $body['color'] ?? '') ? $body['color'] : null;
    $active       = isset($body['is_active']) ? ($body['is_active'] ? 1 : 0) : null;

    $booking_enabled       = isset($body['booking_enabled'])       ? ($body['booking_enabled'] ? 1 : 0) : null;
    $booking_slot_interval = isset($body['booking_slot_interval']) ? max(15, (int)$body['booking_slot_interval']) : null;
    $booking_auto_confirm  = isset($body['booking_auto_confirm'])  ? ($body['booking_auto_confirm'] ? 1 : 0) : null;
    $booking_min_guests    = isset($body['booking_min_guests'])    ? max(1, (int)$body['booking_min_guests']) : null;
    $booking_max_guests    = isset($body['booking_max_guests'])    ? max(1, (int)$body['booking_max_guests']) : null;

    $sets = []; $params = [];
    if ($name)                          { $sets[] = 'name = ?';                   $params[] = $name; }
    if ($duration)                      { $sets[] = 'reservation_duration = ?';   $params[] = $duration; }
    if ($allow_custom !== null)         { $sets[] = 'allow_custom_duration = ?';  $params[] = $allow_custom; }
    if ($color)                         { $sets[] = 'color = ?';                  $params[] = $color; }
    if ($active !== null)               { $sets[] = 'is_active = ?';              $params[] = $active; }
    if ($booking_enabled !== null)      { $sets[] = 'booking_enabled = ?';        $params[] = $booking_enabled; }
    if ($booking_slot_interval !== null){ $sets[] = 'booking_slot_interval = ?';  $params[] = $booking_slot_interval; }
    if ($booking_auto_confirm !== null) { $sets[] = 'booking_auto_confirm = ?';   $params[] = $booking_auto_confirm; }
    if ($booking_min_guests !== null)   { $sets[] = 'booking_min_guests = ?';     $params[] = $booking_min_guests; }
    if ($booking_max_guests !== null)   { $sets[] = 'booking_max_guests = ?';     $params[] = $booking_max_guests; }

    try {
        // Day schedules
        if (!empty($body['day_schedules']) && is_array($body['day_schedules'])) {
            save_day_schedules($pdo, $id, $body['day_schedules']);
        }

        if (!empty($sets)) {
            $params[] = $id;
            $pdo->prepare("UPDATE restaurants SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        }

        $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) json_response(false, null, 'Restavracija ne obstaja.', 404);
        $row['day_schedules'] = fetch_day_schedules($pdo, $id);
        $row['blackouts']     = fetch_blackouts($pdo, $id);
        json_response(true, $row);

    } catch (PDOException $e) {
        error_log('Restaurant update error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri posodabljanju.', 500);
    }
}

// ─── DELETE (deaktiviraj / izbriši / remove_blackout) ─────────
if ($method === 'DELETE') {
    $id     = isset($_GET['id'])     ? (int)$_GET['id']    : 0;
    $action = trim($_GET['action'] ?? '');
    $force  = isset($_GET['force'])  && $_GET['force'] === '1';
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    if (!admin_owns_restaurant($pdo, $session, $id)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    // ── Odstrani blokiran datum ───────────────────────────────────
    if ($action === 'remove_blackout') {
        $date = trim($_GET['date'] ?? '');
        if (!$date) json_response(false, null, 'Datum ni določen.', 400);
        $pdo->prepare("DELETE FROM restaurant_blackouts WHERE restaurant_id = ? AND blackout_date = ?")->execute([$id, $date]);
        json_response(true);
    }

    try {
        if ($force) {
            $pdo->prepare("DELETE FROM restaurants WHERE id = ?")->execute([$id]);
        } else {
            $pdo->prepare("UPDATE restaurants SET is_active = 0 WHERE id = ?")->execute([$id]);
        }
        json_response(true);
    } catch (PDOException $e) {
        error_log('Restaurant delete error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri ' . ($force ? 'brisanju' : 'deaktiviranju') . '.', 500);
    }
}

json_response(false, null, 'Metoda ni podprta.', 405);
