<?php
/**
 * Javni booking API – brez avtentikacije.
 * GET  ?t={token}            → info o restavraciji
 * GET  ?t={token}&date=...   → prosti termini za datum
 * POST ?t={token}            → ustvari rezervacijo
 */
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/mailer.php';
require_once '../includes/guest_helper.php';
require_once '../includes/table_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight OPTIONS zahtevek (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$token  = trim($_GET['t'] ?? '');

if (!$token) {
    json_response(false, null, 'Manjka token.', 400);
}

// ── Naloži restavracijo po tokenu ──────────────────────────────
function load_rest_by_token(PDO $pdo, string $token): array {
    $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE booking_token = ? AND is_active = 1");
    $stmt->execute([$token]);
    $rest = $stmt->fetch();
    if (!$rest)                   json_response(false, null, 'Rezervacijska stran ni na voljo.', 404);
    if (!$rest['booking_enabled']) json_response(false, null, 'Spletne rezervacije niso omogočene.', 403);
    return $rest;
}

function check_booking_feature(PDO $pdo, int $ownerId): void {
    if (!user_has_feature($pdo, $ownerId, 'public_booking')) {
        json_response(false, null, 'Ta funkcionalnost ni na voljo.', 403);
    }
}

// ── Pridobi urnik za določen dan (fallback na globalni) ────────
function get_day_schedule(PDO $pdo, int $restId, int $dayOfWeek, array $rest): array {
    $stmt = $pdo->prepare("SELECT is_open, start_time, end_time FROM restaurant_day_schedules WHERE restaurant_id = ? AND day_of_week = ?");
    $stmt->execute([$restId, $dayOfWeek]);
    $ds = $stmt->fetch();
    if ($ds) return $ds;
    // Fallback na globalni urnik
    return [
        'is_open'    => (($rest['booking_open_days'] >> $dayOfWeek) & 1) ? 1 : 0,
        'start_time' => (int)$rest['schedule_start'],
        'end_time'   => (int)$rest['schedule_end'],
    ];
}

// ── Preveri blokiran datum ──────────────────────────────────────
function is_blackout(PDO $pdo, int $restId, string $date): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM restaurant_blackouts WHERE restaurant_id = ? AND blackout_date = ?");
    $stmt->execute([$restId, $date]);
    return (bool) $stmt->fetchColumn();
}

// ── GET ────────────────────────────────────────────────────────
if ($method === 'GET') {
    $rest = load_rest_by_token($pdo, $token);
    check_booking_feature($pdo, (int)$rest['owner_id']);

    $date = trim($_GET['date'] ?? '');

    if ($date) {
        // Vrni proste termine za datum
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            json_response(false, null, 'Neveljaven datum.', 400);
        }
        if ($date < date('Y-m-d')) {
            json_response(true, ['slots' => [], 'reason' => 'past']);
        }

        // Blokiran datum?
        if (is_blackout($pdo, (int)$rest['id'], $date)) {
            json_response(true, ['slots' => [], 'reason' => 'blackout']);
        }

        $dayOfWeek = (int)date('N', strtotime($date)) - 1; // 0=Pon, 6=Ned
        $ds = get_day_schedule($pdo, (int)$rest['id'], $dayOfWeek, $rest);

        if (!$ds['is_open']) {
            json_response(true, ['slots' => [], 'reason' => 'closed']);
        }

        $start    = (int)$ds['start_time'];
        $end      = (int)$ds['end_time'];
        $interval = (int)($rest['booking_slot_interval'] ?? $rest['reservation_duration']);
        if ($interval < 15) $interval = 15;
        $slots    = [];
        for ($m = $start; $m + $interval <= $end; $m += $interval) {
            $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }

        // Filtriraj preteklost (če danes)
        if ($date === date('Y-m-d')) {
            $nowMins = (int)date('H') * 60 + (int)date('i');
            $slots = array_values(array_filter($slots, static function (string $s) use ($nowMins): bool {
                [$h, $i] = explode(':', $s);
                return ((int)$h * 60 + (int)$i) > $nowMins;
            }));
        }

        // Pripravi kontekst za status terminov
        $guestCount       = isset($_GET['guest_count']) ? max(1, (int)$_GET['guest_count']) : (int)$rest['booking_min_guests'];
        $duration         = (int)$rest['reservation_duration'];
        $restId           = (int)$rest['id'];
        $waitlistEnabled  = user_has_feature($pdo, (int)$rest['owner_id'], 'waitlist') && !empty($rest['waitlist_enabled']);
        $waitlistMax      = (int)($rest['waitlist_max_per_slot'] ?? 3);
        $useTableMgmt     = user_has_feature($pdo, (int)$rest['owner_id'], 'table_management')
                            && restaurant_has_tables($pdo, $restId);

        // Preštej čakalne vpise po terminu za ta datum (status = 'waiting')
        $waitlistCounts = [];
        if ($waitlistEnabled) {
            $stmtWL = $pdo->prepare("
                SELECT time_preference, COUNT(*) AS cnt
                FROM waitlist
                WHERE restaurant_id = ? AND date = ? AND status = 'waiting'
                GROUP BY time_preference
            ");
            $stmtWL->execute([$restId, $date]);
            foreach ($stmtWL->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $waitlistCounts[$row['time_preference']] = (int)$row['cnt'];
            }
        }

        // Določi status vsakega termina
        // Status: 'available' | 'waitlist' | 'full'
        $slotsOut = [];
        foreach ($slots as $slot) {
            $tableAvailable = true;
            if ($useTableMgmt) {
                $r = find_available_table($pdo, $restId, $date, $slot, $duration, $guestCount, null);
                $tableAvailable = ($r !== false && $r['mode'] !== 'no_tables') || ($r !== false && $r['mode'] === 'no_tables');
                // no_tables mode = backward compat = vedno available
                if ($r === false) {
                    $tableAvailable = false;
                }
            }

            if ($tableAvailable) {
                $slotsOut[] = ['time' => $slot, 'status' => 'available'];
                continue;
            }

            // Mize zasedene — ponudi čakalno listo?
            if ($waitlistEnabled) {
                $wlCount = $waitlistCounts[$slot] ?? 0;
                if ($waitlistMax > 0 && $wlCount >= $waitlistMax) {
                    $slotsOut[] = ['time' => $slot, 'status' => 'full'];
                } else {
                    $slotsOut[] = ['time' => $slot, 'status' => 'waitlist'];
                }
            } else {
                $slotsOut[] = ['time' => $slot, 'status' => 'full'];
            }
        }

        json_response(true, ['slots' => $slotsOut]);
    }

    // ── Info o restavraciji (za javno booking stran) ──────────────
    // Dnevni urniki
    $dayStmt = $pdo->prepare("SELECT day_of_week, is_open, start_time, end_time FROM restaurant_day_schedules WHERE restaurant_id = ? ORDER BY day_of_week");
    $dayStmt->execute([$rest['id']]);
    $daySchedules = $dayStmt->fetchAll();

    // Izračunaj open_days bitmask iz day_schedules (ali fallback)
    $openDays = 0;
    if ($daySchedules) {
        foreach ($daySchedules as $ds) {
            if ($ds['is_open']) $openDays |= (1 << $ds['day_of_week']);
        }
    } else {
        $openDays = (int)$rest['booking_open_days'];
    }

    // Blokirani datumi (prihodnji)
    $bStmt = $pdo->prepare("SELECT blackout_date FROM restaurant_blackouts WHERE restaurant_id = ? AND blackout_date >= CURDATE() ORDER BY blackout_date");
    $bStmt->execute([$rest['id']]);
    $blackoutDates = $bStmt->fetchAll(PDO::FETCH_COLUMN);

    // Polja po meri za javno rezervacijo
    $customFields = [];
    try {
        $cfStmt = $pdo->prepare("
            SELECT id, label, field_type, options, is_required
            FROM restaurant_custom_fields
            WHERE restaurant_id = ? AND applies_to IN ('public','both') AND is_active = 1
            ORDER BY sort_order, id
        ");
        $cfStmt->execute([$rest['id']]);
        $customFields = $cfStmt->fetchAll();
        foreach ($customFields as &$cf) {
            $cf['options']     = $cf['options'] ? json_decode($cf['options'], true) : [];
            $cf['is_required'] = (bool)$cf['is_required'];
        }
    } catch (PDOException $e) { /* tabela morda še ne obstaja */ }

    json_response(true, [
        'name'             => $rest['name'],
        'open_days'        => $openDays,
        'auto_confirm'     => (bool)$rest['booking_auto_confirm'],
        'duration'         => (int)$rest['reservation_duration'],
        'sched_start'      => (int)$rest['schedule_start'],
        'sched_end'        => (int)$rest['schedule_end'],
        'min_guests'       => (int)$rest['booking_min_guests'],
        'max_guests'       => (int)$rest['booking_max_guests'],
        'slot_interval'    => (int)($rest['booking_slot_interval'] ?? $rest['reservation_duration']),
        'blackout_dates'   => $blackoutDates,
        'custom_fields'    => $customFields,
        'waitlist_enabled'     => user_has_feature($pdo, (int)$rest['owner_id'], 'waitlist') && (bool)($rest['waitlist_enabled'] ?? 1),
        'waitlist_max_per_slot'=> (int)($rest['waitlist_max_per_slot'] ?? 3),
        'day_schedules'    => array_map(fn($ds) => [
            'day'    => (int)$ds['day_of_week'],
            'is_open'=> (bool)$ds['is_open'],
            'start'  => (int)$ds['start_time'],
            'end'    => (int)$ds['end_time'],
        ], $daySchedules),
    ]);
}

// ── POST ───────────────────────────────────────────────────────
if ($method === 'POST') {
    $rest = load_rest_by_token($pdo, $token);
    check_booking_feature($pdo, (int)$rest['owner_id']);

    $body = get_body();

    $date            = trim($body['date']       ?? '');
    $time            = trim($body['time']       ?? '');
    $guestName       = trim($body['guest_name'] ?? '');
    $email           = trim($body['email']      ?? '');
    $phone           = trim($body['phone']      ?? '');
    $guestCount      = max(1, (int)($body['guest_count'] ?? 1));
    $notes           = trim($body['notes']      ?? '');
    $gdprConsent     = !empty($body['gdpr_consent'])     ? 1 : 0;
    $marketingConsent = !empty($body['marketing_consent']) ? 1 : 0;

    // Validacija
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(false, null, 'Neveljaven datum.', 400);
    }
    if ($date < date('Y-m-d')) {
        json_response(false, null, 'Ne morete rezervirati za preteklost.', 400);
    }
    if (!$time || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        json_response(false, null, 'Neveljaven čas.', 400);
    }
    if (!$guestName) {
        json_response(false, null, 'Ime je obvezno.', 400);
    }
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, null, 'Vnesite veljaven email naslov.', 400);
    }
    $maxGuests = (int)$rest['booking_max_guests'];
    if ($guestCount < 1 || $guestCount > $maxGuests) {
        json_response(false, null, "Število gostov mora biti med 1 in {$maxGuests}.", 400);
    }

    // Blokiran datum?
    if (is_blackout($pdo, (int)$rest['id'], $date)) {
        json_response(false, null, 'Za ta datum rezervacije niso na voljo.', 400);
    }

    // Preverba odprtega dne (per-day schedule)
    $dayOfWeek = (int)date('N', strtotime($date)) - 1;
    $ds = get_day_schedule($pdo, (int)$rest['id'], $dayOfWeek, $rest);
    if (!$ds['is_open']) {
        json_response(false, null, 'V tem dnevu restavracija ne sprejema rezervacij.', 400);
    }

    $status      = $rest['booking_auto_confirm'] ? 'confirmed' : 'pending';
    $customFields = isset($body['custom_fields']) && is_array($body['custom_fields']) ? $body['custom_fields'] : [];

    try {
        $gdprIp  = $_SERVER['REMOTE_ADDR'] ?? null;
        $gdprNow = $gdprConsent ? date('Y-m-d H:i:s') : null;
        $durationMins = (int)$rest['reservation_duration'];
        $useTableMgmt = user_has_feature($pdo, (int)$rest['owner_id'], 'table_management')
                        && restaurant_has_tables($pdo, (int)$rest['id']);

        $pdo->beginTransaction();

        // Preveri razpoložljivost mize znotraj transakcije (prepreči race condition)
        if ($useTableMgmt) {
            $tableAssignment = find_available_table($pdo, (int)$rest['id'], $date, $time, $durationMins, $guestCount, null);
            if ($tableAssignment === false) {
                $pdo->rollBack();
                // Ponudi čakalno listo, če je omogočena
                $waitlistEnabled = user_has_feature($pdo, (int)$rest['owner_id'], 'waitlist')
                                   && !empty($rest['waitlist_enabled']);
                json_response(false, [
                    'no_availability'  => true,
                    'waitlist_enabled' => $waitlistEnabled,
                ], 'Za ta termin ni prostih miz.', 409);
            }
        }

        $pdo->prepare("
            INSERT INTO reservations
                (restaurant_id, reservation_date, reservation_time, duration,
                 guest_name, guest_count, email, phone, notes,
                 status, source, created_by,
                 gdpr_consent, gdpr_consent_at, gdpr_consent_ip, marketing_consent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'public', ?,
                    ?, ?, ?, ?)
        ")->execute([
            $rest['id'],
            $date,
            $time . ':00',
            $durationMins,
            $guestName,
            $guestCount,
            $email,
            $phone ?: null,
            $notes ?: null,
            $status,
            $rest['owner_id'],
            $gdprConsent,
            $gdprNow,
            $gdprConsent ? $gdprIp : null,
            $marketingConsent,
        ]);

        $newId = (int)$pdo->lastInsertId();

        // Dodeli mizo (če je table management aktiven)
        if ($useTableMgmt && isset($tableAssignment)) {
            assign_tables_to_reservation($pdo, $newId, $tableAssignment, null);
        }

        $pdo->commit();

        // Posodobi bazo gostov (Advanced/Premium)
        upsert_guest($pdo, (int)$rest['id'], $email, [
            'guest_name'       => $guestName,
            'phone'            => $phone,
            'reservation_date' => $date,
            'guest_count'      => $guestCount,
        ]);

        // Shrani custom field vrednosti (samo veljavna polja za to restavracijo)
        if ($customFields && $newId) {
            try {
                $cfCheck = $pdo->prepare("SELECT id FROM restaurant_custom_fields WHERE id = ? AND restaurant_id = ? AND applies_to IN ('public','both') AND is_active = 1");
                $cfIns   = $pdo->prepare("INSERT INTO reservation_field_values (reservation_id, field_id, value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
                foreach ($customFields as $fieldId => $value) {
                    $fid = (int)$fieldId;
                    $cfCheck->execute([$fid, $rest['id']]);
                    if ($cfCheck->fetchColumn()) {
                        $cfIns->execute([$newId, $fid, trim((string)$value)]);
                    }
                }
            } catch (PDOException $e) { /* tiho */ }
        }

        // Ustvari edit_token za vse rezervacije (veljavnost: do 1h po terminu)
        $editToken = null;
        try {
            $editToken        = bin2hex(random_bytes(32));
            $editTokenExpires = date('Y-m-d H:i:s', strtotime("{$date} {$time}") + 3600);
            $pdo->prepare("UPDATE reservations SET edit_token = ?, edit_token_expires = ? WHERE id = ?")
                ->execute([$editToken, $editTokenExpires, $newId]);
        } catch (PDOException $e) { $editToken = null; /* stolpec morda še ne obstaja */ }

        $cEmail = $rest['contact_email'] ?? '';
        $cPhone = $rest['contact_phone'] ?? '';
        if ($status === 'confirmed') {
            send_booking_confirmed_guest($email, $guestName, $rest['name'], $date, $time, $guestCount, (int)$rest['reservation_duration'], $editToken ?? '', $cEmail, $cPhone);
        } else {
            send_booking_pending_guest($email, $guestName, $rest['name'], $date, $time, $guestCount, $editToken ?? '', $cEmail, $cPhone);
        }

        $admin = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $admin->execute([$rest['owner_id']]);
        $adminRow = $admin->fetch();
        if ($adminRow && $adminRow['email']) {
            send_booking_notify_admin(
                $adminRow['email'], $adminRow['full_name'],
                $rest['name'], $guestName, $email,
                $date, $time, $guestCount, $status, $newId
            );
        }

        json_response(true, ['auto_confirm' => (bool)$rest['booking_auto_confirm']]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Booking POST error: ' . $e->getMessage());
        json_response(false, null, 'Napaka strežnika. Poskusite znova.', 500);
    }
}

json_response(false, null, 'Metoda ni podprta.', 405);
