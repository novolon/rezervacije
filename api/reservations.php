<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

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

// ─── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $date          = $_GET['date']  ?? null;
    $month         = $_GET['month'] ?? null;
    $rest_id_param = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : null;
    $rest_id       = resolve_restaurant_filter($pdo, $session, $rest_id_param);

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
                       res.allow_custom_duration
                FROM reservations r
                JOIN restaurants res ON r.restaurant_id = res.id
                WHERE r.reservation_date = ? AND r.restaurant_id = ? AND res.is_active = 1
                ORDER BY r.reservation_time, r.guest_name
            ");
            $stmt->execute([$date, $rid]);
        } else {
            $f = admin_rest_filter($pdo, $session, null, 'r', 'res');
            $stmt = $pdo->prepare("
                SELECT r.*, res.name AS restaurant_name, res.color AS restaurant_color,
                       COALESCE(r.duration, res.reservation_duration) AS reservation_duration,
                       res.allow_custom_duration
                FROM reservations r
                JOIN restaurants res ON r.restaurant_id = res.id
                WHERE r.reservation_date = ? AND {$f['where']} AND res.is_active = 1
                ORDER BY r.reservation_time, res.name, r.guest_name
            ");
            $stmt->execute(array_merge([$date], $f['params']));
        }

        json_response(true, $stmt->fetchAll());
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

    try {
        $stmt = $pdo->prepare("
            INSERT INTO reservations
                (restaurant_id, reservation_date, reservation_time, duration, guest_name, guest_count, email, phone, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        ]);

        $id   = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare("
            SELECT r.*, res.name AS restaurant_name, res.color AS restaurant_color,
                   COALESCE(r.duration, res.reservation_duration) AS reservation_duration,
                   res.allow_custom_duration
            FROM reservations r JOIN restaurants res ON r.restaurant_id = res.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        json_response(true, $stmt->fetch(), '', 201);

    } catch (PDOException $e) {
        error_log('Reservation create error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju.', 500);
    }
}

// ─── PUT (uredi) ───────────────────────────────────────────────
if ($method === 'PUT') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ?");
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

    $body  = get_body();
    $date  = trim($body['reservation_date'] ?? $existing['reservation_date']);
    $time  = trim($body['reservation_time'] ?? $existing['reservation_time']);
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

    try {
        $pdo->prepare("
            UPDATE reservations
            SET reservation_date = ?, reservation_time = ?, duration = ?, guest_name = ?,
                guest_count = ?, email = ?, phone = ?, notes = ?
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
            $id,
        ]);

        $stmt = $pdo->prepare("
            SELECT r.*, res.name AS restaurant_name, res.color AS restaurant_color,
                   COALESCE(r.duration, res.reservation_duration) AS reservation_duration,
                   res.allow_custom_duration
            FROM reservations r JOIN restaurants res ON r.restaurant_id = res.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        json_response(true, $stmt->fetch());

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
