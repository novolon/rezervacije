<?php
/**
 * Admin API za čakalno listo.
 * GET  → seznam vnosov (filtrirano po restaurant_id, status, date)
 * POST → akcija na vnosu (notify, remove)
 */
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/waitlist_notifier.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_auth();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

// Superadmin ima dostop brez feature checka
$isSuperadmin = $session['role'] === 'superadmin';
$isAdmin      = $session['role'] === 'admin';

if ($isAdmin && !user_has_feature($pdo, (int)$session['user_id'], 'waitlist')) {
    json_response(false, null, 'Čakalna lista ni na voljo v vašem paketu.', 403);
}

// ── Pomočnik: preveri da admin ima dostop do restavracije ──────
function admin_owns_wl_restaurant(PDO $pdo, array $session, int $restaurantId): bool {
    if ($session['role'] === 'superadmin') return true;
    $stmt = $pdo->prepare("SELECT 1 FROM restaurant_admins WHERE restaurant_id = ? AND user_id = ?");
    $stmt->execute([$restaurantId, $session['user_id']]);
    return (bool)$stmt->fetchColumn();
}

// ── GET: seznam vnosov ────────────────────────────────────────
if ($method === 'GET') {
    $restId = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
    $status = trim($_GET['status'] ?? '');
    $date   = trim($_GET['date']   ?? '');

    // Validacija statusa
    $validStatuses = ['waiting','notified','confirmed','expired','removed'];
    if ($status && !in_array($status, $validStatuses)) $status = '';

    // Zgradi WHERE
    $where  = ['1=1'];
    $params = [];

    if ($isSuperadmin) {
        if ($restId) {
            $where[]  = 'w.restaurant_id = ?';
            $params[] = $restId;
        }
    } elseif ($isAdmin) {
        if ($restId) {
            if (!admin_owns_wl_restaurant($pdo, $session, $restId)) {
                json_response(false, null, 'Dostop zavrnjen.', 403);
            }
            $where[]  = 'w.restaurant_id = ?';
            $params[] = $restId;
        } else {
            // Vse lastne restavracije
            $where[]  = 'w.restaurant_id IN (SELECT restaurant_id FROM restaurant_admins WHERE user_id = ?)';
            $params[] = $session['user_id'];
        }
    } else {
        // user: samo lastna restavracija
        $where[]  = 'w.restaurant_id = ?';
        $params[] = (int)$session['restaurant_id'];
    }

    if ($status) {
        $where[]  = 'w.status = ?';
        $params[] = $status;
    }
    if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $where[]  = 'w.date = ?';
        $params[] = $date;
    }

    $whereStr = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT w.id, w.restaurant_id, r.name AS rest_name,
               w.date, w.time_preference, w.guests,
               w.first_name, w.last_name, w.email, w.phone,
               w.status, w.notified_at, w.expires_at, w.confirmed_at, w.created_at
        FROM waitlist w
        JOIN restaurants r ON r.id = w.restaurant_id
        WHERE {$whereStr}
        ORDER BY w.date ASC, w.created_at ASC
        LIMIT 500
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Dodaj rest_count (da UI ve ali prikazati ime restavracije)
    $restCount = count(array_unique(array_column($rows, 'restaurant_id')));

    // Poiščemo rezervacijo + custom polja za vsak vnos (ne samo potrjene)
    $resStmt = $pdo->prepare("
        SELECT res.id, res.guest_name, res.guest_count, res.reservation_time,
               res.duration, res.email AS res_email, res.phone AS res_phone,
               res.notes, res.status AS res_status
        FROM reservations res
        WHERE res.restaurant_id = ?
          AND res.email = ?
          AND res.reservation_date = ?
          AND res.status NOT IN ('cancelled')
        ORDER BY res.created_at DESC
        LIMIT 1
    ");
    $cfStmt = $pdo->prepare("
        SELECT rcf.label, rfv.value
        FROM reservation_field_values rfv
        JOIN restaurant_custom_fields rcf ON rfv.field_id = rcf.id
        WHERE rfv.reservation_id = ?
        ORDER BY rcf.sort_order, rcf.id
    ");
    $gpStmt = $pdo->prepare("
        SELECT total_visits, last_visit, tags
        FROM guests
        WHERE restaurant_id = ? AND email = ?
        LIMIT 1
    ");

    foreach ($rows as &$row) {
        $row['rest_count']    = $restCount;
        $row['reservation']   = null;
        $row['field_values']  = [];
        $row['guest_profile'] = null;

        $resStmt->execute([$row['restaurant_id'], $row['email'], $row['date']]);
        $res = $resStmt->fetch();
        if ($res) {
            $row['reservation'] = $res;
            try {
                $cfStmt->execute([$res['id']]);
                $row['field_values'] = $cfStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) { /* tabela morda ne obstaja */ }
        }

        // Profil gosta iz baze gostov
        try {
            $gpStmt->execute([$row['restaurant_id'], $row['email']]);
            $gp = $gpStmt->fetch();
            if ($gp) $row['guest_profile'] = $gp;
        } catch (\Throwable $e) { /* tabela morda ne obstaja */ }
    }
    unset($row);

    json_response(true, $rows);
}

// ── POST: akcija ──────────────────────────────────────────────
if ($method === 'POST') {
    $wlId = (int)($_POST['wl_id'] ?? 0);
    $act  = trim($_POST['act']    ?? '');

    if (!$wlId || !in_array($act, ['notify', 'remove'])) {
        json_response(false, null, 'Neveljaven zahtevek.', 400);
    }

    // Naloži vnos + preveri lastništvo
    $stmt = $pdo->prepare("
        SELECT w.*, r.name AS rest_name, r.contact_email, r.contact_phone
        FROM waitlist w
        JOIN restaurants r ON r.id = w.restaurant_id
        WHERE w.id = ?
    ");
    $stmt->execute([$wlId]);
    $entry = $stmt->fetch();

    if (!$entry) json_response(false, null, 'Vnos ne obstaja.', 404);
    if (!admin_owns_wl_restaurant($pdo, $session, (int)$entry['restaurant_id'])) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    if ($act === 'remove') {
        $pdo->prepare("UPDATE waitlist SET status = 'removed' WHERE id = ?")->execute([$wlId]);
        json_response(true, ['message' => 'Vnos odstranjen.']);
    }

    if ($act === 'notify') {
        if ($entry['status'] !== 'waiting') {
            json_response(false, null, 'Vnos ni v stanju čakanja.', 400);
        }
        $expiresAt = date('Y-m-d H:i:s', time() + 2 * 3600);
        $pdo->prepare("
            UPDATE waitlist SET status = 'notified', notified_at = NOW(), expires_at = ?
            WHERE id = ?
        ")->execute([$expiresAt, $wlId]);
        try {
            _send_waitlist_notify_email($entry);
        } catch (Throwable $e) {
            error_log('Admin manual notify email error: ' . $e->getMessage());
        }
        json_response(true, ['message' => 'Obvestilo poslano.']);
    }
}

json_response(false, null, 'Metoda ni podprta.', 405);
