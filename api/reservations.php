<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_auth();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

/**
 * Določi restaurant_id filter glede na vlogo:
 * - superadmin: spoštuje zahtevani filter (vidi vse ali filtrirano)
 * - admin:      omejen na lastne restavracije; če $requested ni med lastnimi, vrne null (= vse lastne)
 * - user:       vedno zaklenjen na lastno restavracijo
 */
function resolve_restaurant_filter(PDO $pdo, array $session, ?int $requested): ?int {
    if ($session['role'] === 'superadmin') {
        return $requested; // superadmin sme videti katerokoli ali vse
    }
    if ($session['role'] === 'admin') {
        if ($requested === null) return null; // "vse lastne" – SQL ga bo filtriral z JOIN
        // Preveri da admin ima dostop do zahtevane restavracije
        $stmt = $pdo->prepare("SELECT 1 FROM restaurant_admins WHERE restaurant_id = ? AND user_id = ?");
        $stmt->execute([$requested, $session['user_id']]);
        return $stmt->fetchColumn() ? $requested : null;
    }
    // user: vedno lastna restavracija
    return (int) $session['restaurant_id'];
}

/**
 * Zgradi SQL WHERE pogoj za admin z omejitvijo na lastne restavracije (NULL = vse lastne).
 * Vrne ['where' => string, 'params' => array]
 */
function admin_rest_filter(PDO $pdo, array $session, ?int $rest_id, string $resAlias = 'r', string $restAlias = 'res'): array {
    if ($session['role'] === 'superadmin') {
        if ($rest_id !== null) {
            return ['where' => "{$resAlias}.restaurant_id = ?", 'params' => [$rest_id]];
        }
        return ['where' => "{$restAlias}.is_active = 1", 'params' => []];
    }
    // admin: omejen na restaurant_admins
    if ($rest_id !== null) {
        return ['where' => "{$resAlias}.restaurant_id = ?", 'params' => [$rest_id]];
    }
    // null = vse adminove restavracije
    return [
        'where'  => "EXISTS (SELECT 1 FROM restaurant_admins ra WHERE ra.restaurant_id = {$resAlias}.restaurant_id AND ra.user_id = ?)",
        'params' => [$session['user_id']],
    ];
}

// ─── Pomožna: priloži custom field vrednosti k rezervacijam ───
function attach_field_values(PDO $pdo, array &$reservations): void {
    if (empty($reservations)) return;
    $ids = array_column($reservations, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare("
            SELECT rfv.reservation_id, rcf.label, rfv.value
            FROM reservation_field_values rfv
            JOIN restaurant_custom_fields rcf ON rfv.field_id = rcf.id
            WHERE rfv.reservation_id IN ($placeholders)
            ORDER BY rcf.sort_order, rcf.id
        ");
        $stmt->execute($ids);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['reservation_id']][] = ['label' => $row['label'], 'value' => $row['value']];
        }
        foreach ($reservations as &$r) {
            $r['field_values'] = $map[$r['id']] ?? [];
        }
    } catch (PDOException $e) {
        // Tabela morda še ne obstaja
        foreach ($reservations as &$r) { $r['field_values'] = []; }
    }
}

// ─── Pomožna: shrani custom field vrednosti ────────────────────
function save_field_values(PDO $pdo, int $reservationId, array $fieldValues): void {
    if (empty($fieldValues)) return;
    $stmt = $pdo->prepare("
        INSERT INTO reservation_field_values (reservation_id, field_id, value)
        VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE value = VALUES(value)
    ");
    foreach ($fieldValues as $fieldId => $value) {
        $fid = (int)$fieldId;
        if ($fid > 0) {
            $stmt->execute([$reservationId, $fid, trim((string)$value)]);
        }
    }
}

// ─── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $date          = $_GET['date']  ?? null;
    $month         = $_GET['month'] ?? null;
    $rest_id_param = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : null;
    $rest_id       = resolve_restaurant_filter($pdo, $session, $rest_id_param);

    // Posamezna rezervacija po ID (za view z vsemi podrobnostmi)
    if (isset($_GET['id'])) {
        $rid = (int)$_GET['id'];
        $stmt = $pdo->prepare("
            SELECT r.*, res.name AS restaurant_name, res.color AS restaurant_color,
                   COALESCE(r.duration, res.reservation_duration) AS reservation_duration,
                   res.allow_custom_duration,
                   s.name AS staff_name
            FROM reservations r
            JOIN restaurants res ON r.restaurant_id = res.id
            LEFT JOIN restaurant_staff s ON r.staff_id = s.id
            WHERE r.id = ?
        ");
        $stmt->execute([$rid]);
        $row = $stmt->fetch();
        if (!$row) json_response(false, null, 'Rezervacija ne obstaja.', 404);
        // Preveri dostop
        if ($session['role'] === 'user' && (int)$session['restaurant_id'] !== (int)$row['restaurant_id']) {
            json_response(false, null, 'Dostop zavrnjen.', 403);
        }
        if ($session['role'] === 'admin' && !admin_owns_restaurant($pdo, $session, (int)$row['restaurant_id'])) {
            json_response(false, null, 'Dostop zavrnjen.', 403);
        }
        $rows = [$row];
        attach_field_values($pdo, $rows);
        json_response(true, $rows[0]);
    }

    // Polling – last_updated za dan + za mesec
    if ($date && isset($_GET['poll'])) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            json_response(false, null, 'Neveljaven datum.', 400);
        }
        $monthPrefix = substr($date, 0, 7);

        if ($session['role'] === 'user') {
            $stmtDay = $pdo->prepare("SELECT MAX(updated_at) AS v FROM reservations WHERE reservation_date = ? AND restaurant_id = ?");
            $stmtDay->execute([$date, $rest_id]);
            $stmtMonth = $pdo->prepare("SELECT MAX(updated_at) AS v FROM reservations WHERE reservation_date LIKE ? AND restaurant_id = ?");
            $stmtMonth->execute([$monthPrefix . '%', $rest_id]);
        } elseif ($rest_id !== null) {
            $stmtDay = $pdo->prepare("SELECT MAX(updated_at) AS v FROM reservations WHERE reservation_date = ? AND restaurant_id = ?");
            $stmtDay->execute([$date, $rest_id]);
            $stmtMonth = $pdo->prepare("SELECT MAX(updated_at) AS v FROM reservations WHERE reservation_date LIKE ? AND restaurant_id = ?");
            $stmtMonth->execute([$monthPrefix . '%', $rest_id]);
        } else {
            // admin/superadmin: vse lastne (ali vse)
            $f = admin_rest_filter($pdo, $session, null, 'r', 'res');
            $stmtDay = $pdo->prepare("SELECT MAX(r.updated_at) AS v FROM reservations r JOIN restaurants res ON r.restaurant_id = res.id WHERE r.reservation_date = ? AND {$f['where']}");
            $stmtDay->execute(array_merge([$date], $f['params']));
            $stmtMonth = $pdo->prepare("SELECT MAX(r.updated_at) AS v FROM reservations r JOIN restaurants res ON r.restaurant_id = res.id WHERE r.reservation_date LIKE ? AND {$f['where']}");
            $stmtMonth->execute(array_merge([$monthPrefix . '%'], $f['params']));
        }
        json_response(true, [
            'day_updated'   => $stmtDay->fetch()['v']   ?? '',
            'month_updated' => $stmtMonth->fetch()['v'] ?? '',
        ]);
    }

    // Čakajoče rezervacije (?pending=1)
    if (isset($_GET['pending'])) {
        if ($session['role'] === 'user') {
            $rid  = (int)$session['restaurant_id'];
            $stmt = $pdo->prepare("
                SELECT r.*, res.name AS restaurant_name, res.color AS restaurant_color
                FROM reservations r
                JOIN restaurants res ON r.restaurant_id = res.id
                WHERE r.status = 'pending' AND r.restaurant_id = ? AND res.is_active = 1
                ORDER BY r.reservation_date, r.reservation_time, r.guest_name
            ");
            $stmt->execute([$rid]);
        } else {
            $f = admin_rest_filter($pdo, $session, null, 'r', 'res');
            $stmt = $pdo->prepare("
                SELECT r.*, res.name AS restaurant_name, res.color AS restaurant_color
                FROM reservations r
                JOIN restaurants res ON r.restaurant_id = res.id
                WHERE r.status = 'pending' AND {$f['where']} AND res.is_active = 1
                ORDER BY r.reservation_date, r.reservation_time, r.guest_name
            ");
            $stmt->execute($f['params']);
        }
        json_response(true, $stmt->fetchAll());
    }

    // Rezervacije za določen dan
    if ($date) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            json_response(false, null, 'Neveljaven datum.', 400);
        }

        if ($rest_id !== null || $session['role'] === 'user') {
            $rid = $rest_id ?? (int)$session['restaurant_id'];
            $stmt = $pdo->prepare("
                SELECT r.*, res.name AS restaurant_name, res.color AS restaurant_color,
                       COALESCE(r.duration, res.reservation_duration) AS reservation_duration,
                       res.allow_custom_duration,
                       s.name AS staff_name
                FROM reservations r
                JOIN restaurants res ON r.restaurant_id = res.id
                LEFT JOIN restaurant_staff s ON r.staff_id = s.id
                WHERE r.reservation_date = ? AND r.restaurant_id = ? AND res.is_active = 1
                  AND r.status NOT IN ('rejected', 'cancelled')
                ORDER BY r.reservation_time, r.guest_name
            ");
            $stmt->execute([$date, $rid]);
        } else {
            $f = admin_rest_filter($pdo, $session, null, 'r', 'res');
            $stmt = $pdo->prepare("
                SELECT r.*, res.name AS restaurant_name, res.color AS restaurant_color,
                       COALESCE(r.duration, res.reservation_duration) AS reservation_duration,
                       res.allow_custom_duration,
                       s.name AS staff_name
                FROM reservations r
                JOIN restaurants res ON r.restaurant_id = res.id
                LEFT JOIN restaurant_staff s ON r.staff_id = s.id
                WHERE r.reservation_date = ? AND {$f['where']} AND res.is_active = 1
                  AND r.status NOT IN ('rejected', 'cancelled')
                ORDER BY r.reservation_time, res.name, r.guest_name
            ");
            $stmt->execute(array_merge([$date], $f['params']));
        }

        $rows = $stmt->fetchAll();
        attach_field_values($pdo, $rows);
        json_response(true, $rows);
    }

    // Mesečni agregati za koledar
    if ($month) {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            json_response(false, null, 'Neveljaven mesec.', 400);
        }
        $from = $month . '-01';
        $to   = date('Y-m-t', strtotime($from));

        if ($rest_id !== null || $session['role'] === 'user') {
            $rid = $rest_id ?? (int)$session['restaurant_id'];
            $stmt = $pdo->prepare("
                SELECT reservation_date,
                       COUNT(*) AS reservation_count,
                       SUM(guest_count) AS total_guests
                FROM reservations
                WHERE reservation_date BETWEEN ? AND ? AND restaurant_id = ?
                GROUP BY reservation_date
            ");
            $stmt->execute([$from, $to, $rid]);
        } else {
            $f = admin_rest_filter($pdo, $session, null, 'r', 'res');
            $stmt = $pdo->prepare("
                SELECT r.reservation_date,
                       COUNT(*) AS reservation_count,
                       SUM(r.guest_count) AS total_guests
                FROM reservations r
                JOIN restaurants res ON r.restaurant_id = res.id
                WHERE r.reservation_date BETWEEN ? AND ? AND {$f['where']} AND res.is_active = 1
                GROUP BY r.reservation_date
            ");
            $stmt->execute(array_merge([$from, $to], $f['params']));
        }

        $data = [];
        foreach ($stmt->fetchAll() as $row) {
            $data[$row['reservation_date']] = [
                'count'  => (int) $row['reservation_count'],
                'guests' => (int) $row['total_guests'],
            ];
        }
        json_response(true, $data);
    }

    json_response(false, null, 'Potreben parameter date ali month.', 400);
}

// ─── POST mark_arrived ────────────────────────────────────────
if ($method === 'POST' && ($_GET['action'] ?? '') === 'mark_arrived') {
    require_once '../includes/plans.php';
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    $stmt = $pdo->prepare("SELECT r.*, res.name AS restaurant_name FROM reservations r JOIN restaurants res ON r.restaurant_id = res.id WHERE r.id = ?");
    $stmt->execute([$id]);
    $res = $stmt->fetch();
    if (!$res) json_response(false, null, 'Rezervacija ne obstaja.', 404);

    if ($session['role'] === 'user' && (int)$session['restaurant_id'] !== (int)$res['restaurant_id'])
        json_response(false, null, 'Dostop zavrnjen.', 403);
    if ($session['role'] === 'admin' && !admin_owns_restaurant($pdo, $session, (int)$res['restaurant_id']))
        json_response(false, null, 'Dostop zavrnjen.', 403);

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $undo = !empty($body['undo']);

    if ($undo) {
        $pdo->prepare("UPDATE reservations SET arrived_at = NULL WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM survey_responses WHERE reservation_id = ? AND email_sent_at IS NULL")->execute([$id]);
        json_response(true, ['arrived_at' => null]);
    }

    $pdo->prepare("UPDATE reservations SET arrived_at = NOW() WHERE id = ?")->execute([$id]);
    $arrivedAt = date('Y-m-d H:i:s');

    $surveyCreated = false;
    if (!empty($res['email']) && user_has_feature($pdo, (int)$session['user_id'], 'survey')) {
        $stmt = $pdo->prepare("SELECT * FROM survey_forms WHERE restaurant_id = ? AND is_active = 1 AND send_enabled = 1 LIMIT 1");
        $stmt->execute([$res['restaurant_id']]);
        $form = $stmt->fetch();
        if ($form) {
            $stmt = $pdo->prepare("SELECT id FROM survey_responses WHERE reservation_id = ? LIMIT 1");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                $token       = bin2hex(random_bytes(32));
                $scheduledAt = date('Y-m-d H:i:s', time() + $form['send_delay_hours'] * 3600);
                $pdo->prepare("INSERT INTO survey_responses (survey_id, reservation_id, email, token, scheduled_send_at) VALUES (?,?,?,?,?)")
                    ->execute([$form['id'], $id, $res['guest_email'], $token, $scheduledAt]);
                $surveyCreated = true;
            }
        }
    }

    json_response(true, ['arrived_at' => $arrivedAt, 'survey_scheduled' => $surveyCreated]);
}

// ─── POST (ustvari) ────────────────────────────────────────────
if ($method === 'POST') {
    $body = get_body();

    if ($session['role'] === 'user') {
        $rest_id = (int)$session['restaurant_id'];
    } elseif ($session['role'] === 'superadmin') {
        $rest_id = isset($body['restaurant_id']) ? (int)$body['restaurant_id'] : 0;
    } else {
        // admin: preveri da ima dostop do zahtevane restavracije
        $rest_id = isset($body['restaurant_id']) ? (int)$body['restaurant_id'] : 0;
        if ($rest_id && !admin_owns_restaurant($pdo, $session, $rest_id)) {
            json_response(false, null, 'Dostop do te restavracije je zavrnjen.', 403);
        }
    }

    if (!$rest_id) json_response(false, null, 'Restavracija ni določena.', 400);

    $date  = trim($body['reservation_date'] ?? '');
    $time  = trim($body['reservation_time'] ?? '');
    $name  = trim($body['guest_name'] ?? '');
    $count = isset($body['guest_count']) ? (int)$body['guest_count'] : 0;

    if (!$date || !$time || !$name || $count < 1) {
        json_response(false, null, 'Datum, čas, ime in število oseb so obvezni.', 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_response(false, null, 'Neveljaven datum.', 400);
    if ($date < date('Y-m-d')) json_response(false, null, 'Rezervacij v preteklosti ni mogoče dodajati.', 400);
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) json_response(false, null, 'Neveljaven čas.', 400);

    // Blokiran datum?
    $blStmt = $pdo->prepare("SELECT 1 FROM restaurant_blackouts WHERE restaurant_id = ? AND blackout_date = ?");
    $blStmt->execute([$rest_id, $date]);
    if ($blStmt->fetchColumn()) {
        json_response(false, null, 'Za ta datum rezervacije niso na voljo (blokiran datum).', 400);
    }

    // Trajanje: samo če restavracija dovoljuje custom
    $custom_duration = null;
    if (!empty($body['duration'])) {
        $restRow = $pdo->prepare("SELECT allow_custom_duration FROM restaurants WHERE id = ?");
        $restRow->execute([$rest_id]);
        $restData = $restRow->fetch();
        if ($restData && $restData['allow_custom_duration']) {
            $d = (int)$body['duration'];
            if ($d >= 15) $custom_duration = $d;
        }
    }

    $staff_id = null;
    if (!empty($body['staff_id'])) {
        $sid = (int)$body['staff_id'];
        $sChk = $pdo->prepare("SELECT 1 FROM restaurant_staff WHERE id = ? AND restaurant_id = ? AND is_active = 1");
        $sChk->execute([$sid, $rest_id]);
        if ($sChk->fetchColumn()) $staff_id = $sid;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO reservations
                (restaurant_id, reservation_date, reservation_time, duration, guest_name, guest_count, email, phone, notes, created_by, staff_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $rest_id,
            $date,
            substr($time, 0, 5) . ':00',
            $custom_duration,
            $name,
            $count,
            trim($body['email'] ?? '') ?: null,
            trim($body['phone'] ?? '') ?: null,
            trim($body['notes'] ?? '') ?: null,
            $session['user_id'],
            $staff_id,
        ]);

        $id = (int)$pdo->lastInsertId();

        // Shrani custom field vrednosti
        if (!empty($body['custom_fields']) && is_array($body['custom_fields'])) {
            save_field_values($pdo, $id, $body['custom_fields']);
        }

        $stmt = $pdo->prepare("
            SELECT r.*, res.name AS restaurant_name, res.color AS restaurant_color,
                   COALESCE(r.duration, res.reservation_duration) AS reservation_duration,
                   res.allow_custom_duration,
                   s.name AS staff_name
            FROM reservations r
            JOIN restaurants res ON r.restaurant_id = res.id
            LEFT JOIN restaurant_staff s ON r.staff_id = s.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $rows = [$row];
        attach_field_values($pdo, $rows);
        json_response(true, $rows[0], '', 201);

    } catch (PDOException $e) {
        error_log('Reservation create error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju.', 500);
    }
}

// ─── PUT (uredi / approve / reject) ───────────────────────────
if ($method === 'PUT') {
    $id     = isset($_GET['id'])     ? (int)$_GET['id']    : 0;
    $action = trim($_GET['action'] ?? '');
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    $stmt = $pdo->prepare("SELECT r.*, res.name AS restaurant_name, res.reservation_duration AS restaurant_duration FROM reservations r JOIN restaurants res ON r.restaurant_id = res.id WHERE r.id = ?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) json_response(false, null, 'Rezervacija ne obstaja.', 404);

    // Preveri dostop
    if ($session['role'] === 'user' && (int)$session['restaurant_id'] !== (int)$existing['restaurant_id']) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }
    if ($session['role'] === 'admin' && !admin_owns_restaurant($pdo, $session, (int)$existing['restaurant_id'])) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    // ── Approve ──────────────────────────────────────────────────
    if ($action === 'approve') {
        if ($existing['status'] !== 'pending') {
            json_response(false, null, 'Rezervacija ni v čakanju.', 400);
        }
        try {
            $pdo->prepare("UPDATE reservations SET status = 'confirmed' WHERE id = ?")->execute([$id]);
            if ($existing['email']) {
                $time     = substr($existing['reservation_time'], 0, 5);
                $duration = (int)($existing['duration'] ?? $existing['restaurant_duration'] ?? 60);
                send_booking_confirmed_guest(
                    $existing['email'], $existing['guest_name'],
                    $existing['restaurant_name'],
                    $existing['reservation_date'], $time, (int)$existing['guest_count'], $duration
                );
            }
            json_response(true, ['status' => 'confirmed']);
        } catch (PDOException $e) {
            error_log('Approve error: ' . $e->getMessage());
            json_response(false, null, 'Napaka pri potrjevanju.', 500);
        }
    }

    // ── Reject ───────────────────────────────────────────────────
    if ($action === 'reject') {
        if ($existing['status'] !== 'pending') {
            json_response(false, null, 'Rezervacija ni v čakanju.', 400);
        }
        try {
            $pdo->prepare("UPDATE reservations SET status = 'rejected' WHERE id = ?")->execute([$id]);
            if ($existing['email']) {
                $time = substr($existing['reservation_time'], 0, 5);
                send_booking_rejected_guest(
                    $existing['email'], $existing['guest_name'],
                    $existing['restaurant_name'],
                    $existing['reservation_date'], $time, (int)$existing['guest_count']
                );
            }
            json_response(true, ['status' => 'rejected']);
        } catch (PDOException $e) {
            error_log('Reject error: ' . $e->getMessage());
            json_response(false, null, 'Napaka pri zavrnitvi.', 500);
        }
    }

    $body  = get_body();
    $date  = trim($body['reservation_date'] ?? (string)$existing['reservation_date']);
    $time  = trim($body['reservation_time'] ?? (string)$existing['reservation_time']);
    $name  = trim($body['guest_name']       ?? $existing['guest_name']);
    $count = isset($body['guest_count']) ? (int)$body['guest_count'] : (int)$existing['guest_count'];

    if (!$name || $count < 1) json_response(false, null, 'Ime in število oseb sta obvezna.', 400);

    $custom_duration = $existing['duration'];
    if (!empty($body['duration'])) {
        $restRow = $pdo->prepare("SELECT allow_custom_duration FROM restaurants WHERE id = ?");
        $restRow->execute([$existing['restaurant_id']]);
        $restData = $restRow->fetch();
        if ($restData && $restData['allow_custom_duration']) {
            $d = (int)$body['duration'];
            $custom_duration = ($d >= 15) ? $d : null;
        }
    }

    // staff_id pri PUT
    $put_staff_id = $existing['staff_id']; // privzeto ohrani obstoječi
    if (array_key_exists('staff_id', $body)) {
        if (empty($body['staff_id'])) {
            $put_staff_id = null;
        } else {
            $sid = (int)$body['staff_id'];
            $sChk = $pdo->prepare("SELECT 1 FROM restaurant_staff WHERE id = ? AND restaurant_id = ? AND is_active = 1");
            $sChk->execute([$sid, (int)$existing['restaurant_id']]);
            $put_staff_id = $sChk->fetchColumn() ? $sid : $existing['staff_id'];
        }
    }

    try {
        $pdo->prepare("
            UPDATE reservations
            SET reservation_date = ?, reservation_time = ?, duration = ?, guest_name = ?,
                guest_count = ?, email = ?, phone = ?, notes = ?, staff_id = ?
            WHERE id = ?
        ")->execute([
            $date,
            substr($time, 0, 5) . ':00',
            $custom_duration,
            $name,
            $count,
            trim($body['email'] ?? $existing['email'] ?? '') ?: null,
            trim($body['phone'] ?? $existing['phone'] ?? '') ?: null,
            trim($body['notes'] ?? $existing['notes'] ?? '') ?: null,
            $put_staff_id,
            $id,
        ]);

        // Posodobi custom field vrednosti
        if (!empty($body['custom_fields']) && is_array($body['custom_fields'])) {
            save_field_values($pdo, $id, $body['custom_fields']);
        }

        $stmt = $pdo->prepare("
            SELECT r.*, res.name AS restaurant_name, res.color AS restaurant_color,
                   COALESCE(r.duration, res.reservation_duration) AS reservation_duration,
                   res.allow_custom_duration,
                   s.name AS staff_name
            FROM reservations r
            JOIN restaurants res ON r.restaurant_id = res.id
            LEFT JOIN restaurant_staff s ON r.staff_id = s.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $rows = [$row];
        attach_field_values($pdo, $rows);
        json_response(true, $rows[0]);

    } catch (PDOException $e) {
        error_log('Reservation update error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri posodabljanju.', 500);
    }
}

// ─── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) json_response(false, null, 'Rezervacija ne obstaja.', 404);

    if ($session['role'] === 'user' && (int)$session['restaurant_id'] !== (int)$existing['restaurant_id']) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }
    if ($session['role'] === 'admin' && !admin_owns_restaurant($pdo, $session, (int)$existing['restaurant_id'])) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    try {
        $pdo->prepare("DELETE FROM reservations WHERE id = ?")->execute([$id]);
        json_response(true);
    } catch (PDOException $e) {
        error_log('Reservation delete error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri brisanju.', 500);
    }
}

json_response(false, null, 'Metoda ni podprta.', 405);
