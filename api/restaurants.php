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

// Pomožna: vrni day_schedules + periode za restavracijo
function fetch_day_periods_map(PDO $pdo, int $id): array {
    try {
        $stmt = $pdo->prepare("SELECT day_of_week, start_time, end_time FROM restaurant_day_periods WHERE restaurant_id = ? ORDER BY day_of_week, start_time");
        $stmt->execute([$id]);
        $map = [];
        foreach ($stmt->fetchAll() as $p) {
            $map[(int)$p['day_of_week']][] = ['start_time' => (int)$p['start_time'], 'end_time' => (int)$p['end_time']];
        }
        return $map;
    } catch (PDOException $e) { return []; }
}

function fetch_day_schedules(PDO $pdo, int $id): array {
    try {
        $stmt = $pdo->prepare("SELECT day_of_week, is_open, start_time, end_time FROM restaurant_day_schedules WHERE restaurant_id = ? ORDER BY day_of_week");
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll();
        $periodsMap = fetch_day_periods_map($pdo, $id);
        foreach ($rows as &$r) {
            $dow = (int)$r['day_of_week'];
            $r['periods'] = $periodsMap[$dow] ?? [['start_time' => (int)$r['start_time'], 'end_time' => (int)$r['end_time']]];
        }
        return $rows;
    } catch (PDOException $e) { return []; }
}

function fetch_blackouts(PDO $pdo, int $id): array {
    try {
        $stmt = $pdo->prepare("SELECT blackout_date, reason, block_start, block_end FROM restaurant_blackouts WHERE restaurant_id = ? AND blackout_date >= CURDATE() ORDER BY blackout_date");
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

// Pomožna: shrani day_schedules + periode; posodobi schedule_start/end ter booking_open_days
function save_day_schedules(PDO $pdo, int $id, array $daySchedules): void {
    $dsStmt = $pdo->prepare("INSERT INTO restaurant_day_schedules (restaurant_id, day_of_week, is_open, start_time, end_time)
        VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE is_open=VALUES(is_open), start_time=VALUES(start_time), end_time=VALUES(end_time)");

    // Preverimo ali ima kateri dan več kot eno periodo
    $hasMultiPeriod = false;
    foreach ($daySchedules as $ds) {
        if (is_array($ds['periods'] ?? null) && count($ds['periods']) > 1) {
            $hasMultiPeriod = true;
            break;
        }
    }

    // Izbriši stare periode za vse dni, ki jih posodabljamo
    $dows = array_map(fn($d) => (int)$d['day_of_week'], $daySchedules);
    if ($dows) {
        $placeholders = implode(',', array_fill(0, count($dows), '?'));
        try {
            $pdo->prepare("DELETE FROM restaurant_day_periods WHERE restaurant_id = ? AND day_of_week IN ($placeholders)")
                ->execute(array_merge([$id], $dows));
        } catch (PDOException $e) {
            if ($hasMultiPeriod) {
                throw new PDOException('Tabela restaurant_day_periods ne obstaja. Zaženite sql/migrate_multi_period.sql na strežniku.');
            }
        }
    }

    $periodStmt = null;
    try {
        $periodStmt = $pdo->prepare("INSERT INTO restaurant_day_periods (restaurant_id, day_of_week, start_time, end_time) VALUES (?,?,?,?)");
    } catch (PDOException $e) {
        if ($hasMultiPeriod) {
            throw new PDOException('Tabela restaurant_day_periods ne obstaja. Zaženite sql/migrate_multi_period.sql na strežniku.');
        }
    }

    $openDays = 0;
    $starts = []; $ends = [];
    foreach ($daySchedules as $ds) {
        $dow   = max(0, min(6, (int)$ds['day_of_week']));
        $open  = $ds['is_open'] ? 1 : 0;
        $periods = is_array($ds['periods'] ?? null) ? $ds['periods'] : [];

        // Preveri prekrivanja med periodami (server-side)
        if ($open && count($periods) > 1) {
            usort($periods, fn($a, $b) => (int)$a['start_time'] - (int)$b['start_time']);
            for ($i = 1; $i < count($periods); $i++) {
                if ((int)$periods[$i]['start_time'] < (int)$periods[$i-1]['end_time']) {
                    throw new \InvalidArgumentException("Dan $dow: termini se prekrivajo.");
                }
            }
        }

        // Izračunaj fallback start/end iz period (ali default)
        if ($periods) {
            $pStarts = array_column($periods, 'start_time');
            $pEnds   = array_column($periods, 'end_time');
            $dsStart = min($pStarts);
            $dsEnd   = max($pEnds);
        } else {
            $dsStart = max(0, min(1439, (int)($ds['start_time'] ?? 480)));
            $dsEnd   = max(1, min(1440, (int)($ds['end_time']   ?? 1380)));
            $periods = [['start_time' => $dsStart, 'end_time' => $dsEnd]];
        }

        $dsStmt->execute([$id, $dow, $open, $dsStart, $dsEnd]);

        if ($periodStmt) {
            foreach ($periods as $p) {
                $pStart = max(0, min(1439, (int)$p['start_time']));
                $pEnd   = max(1, min(1440, (int)$p['end_time']));
                if ($pEnd > $pStart) {
                    $periodStmt->execute([$id, $dow, $pStart, $pEnd]);
                }
            }
        }

        if ($open) {
            $openDays |= (1 << $dow);
            foreach ($periods as $p) {
                $starts[] = (int)$p['start_time'];
                $ends[]   = (int)$p['end_time'];
            }
        }
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
        $date       = trim($body['date']   ?? '');
        $reason     = trim($body['reason'] ?? '') ?: null;
        $blockStart = isset($body['block_start']) && $body['block_start'] !== '' ? max(0, min(1439, (int)$body['block_start'])) : null;
        $blockEnd   = isset($body['block_end'])   && $body['block_end']   !== '' ? max(1, min(1440, (int)$body['block_end']))   : null;
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            json_response(false, null, 'Neveljaven datum.', 400);
        }
        if (($blockStart !== null) !== ($blockEnd !== null)) {
            json_response(false, null, 'Določiti morata oba časa ali nobenega.', 400);
        }
        if ($blockStart !== null && $blockEnd !== null && $blockEnd <= $blockStart) {
            json_response(false, null, 'Končni čas mora biti večji od začetnega.', 400);
        }
        try {
            $pdo->prepare("INSERT INTO restaurant_blackouts (restaurant_id, blackout_date, reason, block_start, block_end)
                VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason), block_start=VALUES(block_start), block_end=VALUES(block_end)")
                ->execute([$id, $date, $reason, $blockStart, $blockEnd]);
            json_response(true, ['blackout_date' => $date, 'reason' => $reason, 'block_start' => $blockStart, 'block_end' => $blockEnd]);
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

    $allow_guest_edit          = isset($body['allow_guest_edit'])          ? ($body['allow_guest_edit'] ? 1 : 0) : null;
    $guest_edit_cutoff_hours   = isset($body['guest_edit_cutoff_hours'])   ? max(1, (int)$body['guest_edit_cutoff_hours']) : null;
    $allow_guest_cancel        = isset($body['allow_guest_cancel'])        ? ($body['allow_guest_cancel'] ? 1 : 0) : null;
    $guest_cancel_cutoff_hours = isset($body['guest_cancel_cutoff_hours']) ? max(1, (int)$body['guest_cancel_cutoff_hours']) : null;
    $waitlist_enabled          = isset($body['waitlist_enabled'])          ? ($body['waitlist_enabled'] ? 1 : 0) : null;
    $waitlist_max_per_slot     = isset($body['waitlist_max_per_slot'])     ? max(0, (int)$body['waitlist_max_per_slot']) : null;

    $contact_email = array_key_exists('contact_email', $body) ? (trim($body['contact_email']) ?: null) : false;
    $contact_phone = array_key_exists('contact_phone', $body) ? (trim($body['contact_phone']) ?: null) : false;

    $all_tables_mergeable              = isset($body['all_tables_mergeable'])              ? ($body['all_tables_mergeable'] ? 1 : 0) : null;
    $allow_area_choice                 = isset($body['allow_area_choice'])                 ? ($body['allow_area_choice'] ? 1 : 0) : null;
    $employees_can_override_schedule   = isset($body['employees_can_override_schedule'])   ? ($body['employees_can_override_schedule'] ? 1 : 0) : null;

    $track_no_shows    = isset($body['track_no_shows'])    ? ($body['track_no_shows'] ? 1 : 0) : null;
    $no_show_threshold = isset($body['no_show_threshold']) ? max(1, (int)$body['no_show_threshold']) : null;

    $sets = []; $params = [];
    if ($name)                               { $sets[] = 'name = ?';                        $params[] = $name; }
    if ($duration)                           { $sets[] = 'reservation_duration = ?';        $params[] = $duration; }
    if ($allow_custom !== null)              { $sets[] = 'allow_custom_duration = ?';       $params[] = $allow_custom; }
    if ($color)                              { $sets[] = 'color = ?';                       $params[] = $color; }
    if ($active !== null)                    { $sets[] = 'is_active = ?';                   $params[] = $active; }
    if ($booking_enabled !== null)           { $sets[] = 'booking_enabled = ?';             $params[] = $booking_enabled; }
    if ($booking_slot_interval !== null)     { $sets[] = 'booking_slot_interval = ?';       $params[] = $booking_slot_interval; }
    if ($booking_auto_confirm !== null)      { $sets[] = 'booking_auto_confirm = ?';        $params[] = $booking_auto_confirm; }
    if ($booking_min_guests !== null)        { $sets[] = 'booking_min_guests = ?';          $params[] = $booking_min_guests; }
    if ($booking_max_guests !== null)        { $sets[] = 'booking_max_guests = ?';          $params[] = $booking_max_guests; }
    if ($allow_guest_edit !== null)          { $sets[] = 'allow_guest_edit = ?';            $params[] = $allow_guest_edit; }
    if ($guest_edit_cutoff_hours !== null)   { $sets[] = 'guest_edit_cutoff_hours = ?';     $params[] = $guest_edit_cutoff_hours; }
    if ($allow_guest_cancel !== null)        { $sets[] = 'allow_guest_cancel = ?';          $params[] = $allow_guest_cancel; }
    if ($guest_cancel_cutoff_hours !== null) { $sets[] = 'guest_cancel_cutoff_hours = ?';   $params[] = $guest_cancel_cutoff_hours; }
    if ($waitlist_enabled !== null)          { $sets[] = 'waitlist_enabled = ?';             $params[] = $waitlist_enabled; }
    if ($waitlist_max_per_slot !== null)    { $sets[] = 'waitlist_max_per_slot = ?';        $params[] = $waitlist_max_per_slot; }
    if ($contact_email !== false)            { $sets[] = 'contact_email = ?';               $params[] = $contact_email; }
    if ($contact_phone !== false)            { $sets[] = 'contact_phone = ?';               $params[] = $contact_phone; }
    if ($all_tables_mergeable !== null)            { $sets[] = 'all_tables_mergeable = ?';                  $params[] = $all_tables_mergeable; }
    if ($allow_area_choice !== null)               { $sets[] = 'allow_area_choice = ?';                     $params[] = $allow_area_choice; }
    if ($employees_can_override_schedule !== null) { $sets[] = 'employees_can_override_schedule = ?';        $params[] = $employees_can_override_schedule; }
    if ($track_no_shows !== null)                  { $sets[] = 'track_no_shows = ?';                        $params[] = $track_no_shows; }
    if ($no_show_threshold !== null)               { $sets[] = 'no_show_threshold = ?';                     $params[] = $no_show_threshold; }

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

    } catch (\InvalidArgumentException $e) {
        json_response(false, null, $e->getMessage(), 400);
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
