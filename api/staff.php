<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_auth();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

// Vrni true, če ima trenutni user dostop do restavracije
function can_access_restaurant(PDO $pdo, array $session, int $restId): bool {
    if ($session['role'] === 'superadmin') return true;
    if ($session['role'] === 'admin') {
        $stmt = $pdo->prepare("SELECT 1 FROM restaurant_admins WHERE restaurant_id = ? AND user_id = ?");
        $stmt->execute([$restId, $session['user_id']]);
        return (bool)$stmt->fetchColumn();
    }
    // user: samo lastna restavracija
    return (int)$session['restaurant_id'] === $restId;
}

// ─── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $restId = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
    if (!$restId) json_response(false, null, 'restaurant_id je obvezen.', 400);
    if (!can_access_restaurant($pdo, $session, $restId)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    $stmt = $pdo->prepare("SELECT id, name, is_active FROM restaurant_staff WHERE restaurant_id = ? ORDER BY name");
    $stmt->execute([$restId]);
    json_response(true, $stmt->fetchAll());
}

// ─── POST (ustvari) ────────────────────────────────────────────
if ($method === 'POST') {
    if ($session['role'] === 'user') json_response(false, null, 'Dostop zavrnjen.', 403);

    $body   = get_body();
    $restId = isset($body['restaurant_id']) ? (int)$body['restaurant_id'] : 0;
    $name   = trim($body['name'] ?? '');

    if (!$restId) json_response(false, null, 'restaurant_id je obvezen.', 400);
    if (!$name)  json_response(false, null, 'Ime zaposlenega je obvezno.', 400);
    if (!can_access_restaurant($pdo, $session, $restId)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO restaurant_staff (restaurant_id, name) VALUES (?,?)");
        $stmt->execute([$restId, $name]);
        $id = (int)$pdo->lastInsertId();
        json_response(true, ['id' => $id, 'name' => $name, 'is_active' => 1], '', 201);
    } catch (PDOException $e) {
        error_log('Staff create: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju.', 500);
    }
}

// ─── PUT (posodobi) ────────────────────────────────────────────
if ($method === 'PUT') {
    if ($session['role'] === 'user') json_response(false, null, 'Dostop zavrnjen.', 403);

    $id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    $body = get_body();

    // Preveri dostop prek restavracije
    $stmt = $pdo->prepare("SELECT restaurant_id FROM restaurant_staff WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_response(false, null, 'Zaposleni ne obstaja.', 404);
    if (!can_access_restaurant($pdo, $session, (int)$row['restaurant_id'])) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    $name      = isset($body['name'])      ? trim($body['name'])              : null;
    $is_active = isset($body['is_active']) ? ($body['is_active'] ? 1 : 0)    : null;

    $sets = []; $params = [];
    if ($name !== null && $name !== '') { $sets[] = 'name = ?';      $params[] = $name; }
    if ($is_active !== null)            { $sets[] = 'is_active = ?'; $params[] = $is_active; }

    if ($sets) {
        $params[] = $id;
        $pdo->prepare("UPDATE restaurant_staff SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    }

    $stmt = $pdo->prepare("SELECT id, name, is_active FROM restaurant_staff WHERE id = ?");
    $stmt->execute([$id]);
    json_response(true, $stmt->fetch());
}

// ─── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if ($session['role'] === 'user') json_response(false, null, 'Dostop zavrnjen.', 403);

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    $stmt = $pdo->prepare("SELECT restaurant_id FROM restaurant_staff WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_response(false, null, 'Zaposleni ne obstaja.', 404);
    if (!can_access_restaurant($pdo, $session, (int)$row['restaurant_id'])) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    // Namesto brisanja: deaktiviraj (ohranjamo referenco v rezervacijah)
    $pdo->prepare("UPDATE restaurant_staff SET is_active = 0 WHERE id = ?")->execute([$id]);
    json_response(true);
}

json_response(false, null, 'Metoda ni podprta.', 405);
