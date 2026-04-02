<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_admin(); // admin ali superadmin
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

// ─── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($id) {
        // Superadmin vidi katerokoli, admin samo lastne
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
        json_response(true, $row);
    }

    // Seznam restavracij
    if ($session['role'] === 'superadmin') {
        $stmt = $pdo->query("
            SELECT r.*, u.full_name AS owner_name
            FROM restaurants r
            JOIN users u ON r.owner_id = u.id
            ORDER BY r.name
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT r.* FROM restaurants r
            JOIN restaurant_admins ra ON r.id = ra.restaurant_id
            WHERE ra.user_id = ?
            ORDER BY r.name
        ");
        $stmt->execute([$session['user_id']]);
    }
    json_response(true, $stmt->fetchAll());
}

// ─── POST (ustvari) ────────────────────────────────────────────
if ($method === 'POST') {
    // Superadmin ne sme ustvarjati restavracij za druge (to delajo admini)
    if ($session['role'] === 'superadmin') {
        json_response(false, null, 'Superadmin ne ustvarja restavracij.', 403);
    }

    $body = get_body();
    $name = trim($body['name'] ?? '');

    if (!$name) json_response(false, null, 'Ime restavracije je obvezno.', 400);

    $duration     = isset($body['reservation_duration']) ? max(15, (int)$body['reservation_duration']) : 60;
    $allow_custom = isset($body['allow_custom_duration']) ? ($body['allow_custom_duration'] ? 1 : 0) : 0;
    $sched_start  = isset($body['schedule_start']) ? max(0, min(1439, (int)$body['schedule_start'])) : 480;
    $sched_end    = isset($body['schedule_end'])   ? max(1, min(1440, (int)$body['schedule_end']))   : 1380;
    $color        = preg_match('/^#[0-9A-Fa-f]{6}$/', $body['color'] ?? '') ? $body['color'] : '#F59E0B';

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO restaurants (name, owner_id, reservation_duration, allow_custom_duration, schedule_start, schedule_end, color)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $session['user_id'], $duration, $allow_custom, $sched_start, $sched_end, $color]);
        $id = (int) $pdo->lastInsertId();

        // Admin avtomatično dobi dostop do nove restavracije
        $pdo->prepare("INSERT INTO restaurant_admins (restaurant_id, user_id) VALUES (?, ?)")
            ->execute([$id, $session['user_id']]);

        $pdo->commit();

        $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = ?");
        $stmt->execute([$id]);
        json_response(true, $stmt->fetch(), '', 201);

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Restaurant create error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju.', 500);
    }
}

// ─── PUT (uredi) ───────────────────────────────────────────────
if ($method === 'PUT') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    // Preveri dostop
    if (!admin_owns_restaurant($pdo, $session, $id)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    $body         = get_body();
    $name         = trim($body['name'] ?? '');
    $duration     = isset($body['reservation_duration']) ? max(15, (int)$body['reservation_duration']) : null;
    $allow_custom = isset($body['allow_custom_duration']) ? ($body['allow_custom_duration'] ? 1 : 0) : null;
    $sched_start  = isset($body['schedule_start']) ? max(0, min(1439, (int)$body['schedule_start'])) : null;
    $sched_end    = isset($body['schedule_end'])   ? max(1, min(1440, (int)$body['schedule_end']))   : null;
    $color        = preg_match('/^#[0-9A-Fa-f]{6}$/', $body['color'] ?? '') ? $body['color'] : null;
    $active       = isset($body['is_active']) ? ($body['is_active'] ? 1 : 0) : null;

    $sets   = [];
    $params = [];
    if ($name)                  { $sets[] = 'name = ?';                  $params[] = $name; }
    if ($duration)              { $sets[] = 'reservation_duration = ?';  $params[] = $duration; }
    if ($allow_custom !== null) { $sets[] = 'allow_custom_duration = ?'; $params[] = $allow_custom; }
    if ($sched_start !== null)  { $sets[] = 'schedule_start = ?';        $params[] = $sched_start; }
    if ($sched_end !== null)    { $sets[] = 'schedule_end = ?';          $params[] = $sched_end; }
    if ($color)                 { $sets[] = 'color = ?';                 $params[] = $color; }
    if ($active !== null)       { $sets[] = 'is_active = ?';             $params[] = $active; }

    if (empty($sets)) json_response(false, null, 'Ni podatkov za posodobitev.', 400);

    $params[] = $id;

    try {
        $pdo->prepare("UPDATE restaurants SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) json_response(false, null, 'Restavracija ne obstaja.', 404);
        json_response(true, $row);
    } catch (PDOException $e) {
        error_log('Restaurant update error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri posodabljanju.', 500);
    }
}

// ─── DELETE (deaktiviraj ali trajno izbriši) ───────────────────
if ($method === 'DELETE') {
    $id    = isset($_GET['id'])    ? (int)$_GET['id']       : 0;
    $force = isset($_GET['force']) && $_GET['force'] === '1';
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    if (!admin_owns_restaurant($pdo, $session, $id)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
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
