<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_admin(); // admin ali superadmin
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

// Pridobi seznam restaurant_id ki jih admin upravlja
$adminRestIds = get_admin_restaurant_ids($pdo, $session);

// ─── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($id) {
        if ($session['role'] === 'superadmin') {
            $stmt = $pdo->prepare("
                SELECT u.id, u.email, u.username, u.full_name, u.role, u.restaurant_id, u.is_active,
                       u.trial_ends_at, u.subscription_status, u.created_at,
                       r.name AS restaurant_name
                FROM users u LEFT JOIN restaurants r ON u.restaurant_id = r.id
                WHERE u.id = ?
            ");
            $stmt->execute([$id]);
        } else {
            // Admin vidi samo userje v lastnih restavracijah
            if (empty($adminRestIds)) json_response(false, null, 'Uporabnik ne obstaja.', 404);
            $placeholders = implode(',', array_fill(0, count($adminRestIds), '?'));
            $stmt = $pdo->prepare("
                SELECT u.id, u.email, u.username, u.full_name, u.role, u.restaurant_id, u.is_active, u.created_at,
                       r.name AS restaurant_name
                FROM users u LEFT JOIN restaurants r ON u.restaurant_id = r.id
                WHERE u.id = ? AND u.role = 'user' AND u.restaurant_id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$id], $adminRestIds));
        }
        $row = $stmt->fetch();
        if (!$row) json_response(false, null, 'Uporabnik ne obstaja.', 404);
        json_response(true, $row);
    }

    // Seznam
    if ($session['role'] === 'superadmin') {
        $stmt = $pdo->query("
            SELECT u.id, u.email, u.username, u.full_name, u.role, u.restaurant_id, u.is_active,
                   u.trial_ends_at, u.subscription_status, u.created_at,
                   r.name AS restaurant_name
            FROM users u LEFT JOIN restaurants r ON u.restaurant_id = r.id
            WHERE u.role != 'superadmin'
            ORDER BY u.role, u.full_name
        ");
    } else {
        // Admin vidi samo userje v lastnih restavracijah
        if (empty($adminRestIds)) {
            json_response(true, []);
        }
        $placeholders = implode(',', array_fill(0, count($adminRestIds), '?'));
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.username, u.full_name, u.role, u.restaurant_id, u.is_active, u.created_at,
                   r.name AS restaurant_name
            FROM users u LEFT JOIN restaurants r ON u.restaurant_id = r.id
            WHERE u.role = 'user' AND u.restaurant_id IN ($placeholders)
            ORDER BY u.full_name
        ");
        $stmt->execute($adminRestIds);
    }
    json_response(true, $stmt->fetchAll());
}

// ─── POST (ustvari) ────────────────────────────────────────────
if ($method === 'POST') {
    // Superadmin ne ustvarja userjev direktno
    if ($session['role'] === 'superadmin') {
        json_response(false, null, 'Superadmin ne ustvarja userjev.', 403);
    }

    $body      = get_body();
    $email     = trim($body['email']    ?? '') ?: null;
    $username  = trim($body['username'] ?? '') ?: null;
    $full_name = trim($body['full_name'] ?? '');
    $password  = $body['password']       ?? '';
    $rest_id   = isset($body['restaurant_id']) ? (int)$body['restaurant_id'] : null;

    // Potreben email ali username (ne oba skupaj obvezna)
    if (!$email && !$username) {
        json_response(false, null, 'Vnesite email ali uporabniško ime.', 400);
    }
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, null, 'Email naslov ni veljaven.', 400);
    }
    if ($username && (strlen($username) < 3 || !preg_match('/^[a-zA-Z0-9._-]+$/', $username))) {
        json_response(false, null, 'Uporabniško ime mora imeti vsaj 3 znake (a-z, 0-9, . _ -).', 400);
    }
    if (!$full_name || !$password) {
        json_response(false, null, 'Ime in geslo sta obvezna.', 400);
    }
    if (!$rest_id) {
        json_response(false, null, 'Restavracija je obvezna.', 400);
    }

    // Preveri, da restavracija pripada temu adminu
    if (!in_array($rest_id, $adminRestIds)) {
        json_response(false, null, 'Dostop do te restavracije je zavrnjen.', 403);
    }

    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO users (email, username, password_hash, full_name, role, restaurant_id,
                               email_verified_at, subscription_status, is_active)
            VALUES (?, ?, ?, ?, 'user', ?, NOW(), 'active', 1)
        ");
        $stmt->execute([$email, $username, $hash, $full_name, $rest_id]);
        $id   = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.username, u.full_name, u.role, u.restaurant_id, u.is_active, u.created_at,
                   r.name AS restaurant_name
            FROM users u LEFT JOIN restaurants r ON u.restaurant_id = r.id WHERE u.id = ?
        ");
        $stmt->execute([$id]);
        json_response(true, $stmt->fetch(), '', 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_response(false, null, 'Email ali uporabniško ime je že zasedeno.', 409);
        }
        error_log('User create error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju.', 500);
    }
}

// ─── PUT (uredi) ───────────────────────────────────────────────
if ($method === 'PUT') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    // Preveri dostop
    if ($session['role'] !== 'superadmin') {
        if (empty($adminRestIds)) json_response(false, null, 'Dostop zavrnjen.', 403);
        $placeholders = implode(',', array_fill(0, count($adminRestIds), '?'));
        $chk = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'user' AND restaurant_id IN ($placeholders)");
        $chk->execute(array_merge([$id], $adminRestIds));
        if (!$chk->fetchColumn()) json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    $body   = get_body();
    $sets   = [];
    $params = [];

    if (isset($body['full_name']) && trim($body['full_name'])) {
        $sets[] = 'full_name = ?'; $params[] = trim($body['full_name']);
    }
    if (array_key_exists('email', $body)) {
        $newEmail = trim($body['email']) ?: null;
        if ($newEmail && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            json_response(false, null, 'Email naslov ni veljaven.', 400);
        }
        $sets[] = 'email = ?'; $params[] = $newEmail;
    }
    if (array_key_exists('username', $body)) {
        $newUsername = trim($body['username']) ?: null;
        if ($newUsername && (strlen($newUsername) < 3 || !preg_match('/^[a-zA-Z0-9._-]+$/', $newUsername))) {
            json_response(false, null, 'Uporabniško ime mora imeti vsaj 3 znake (a-z, 0-9, . _ -).', 400);
        }
        $sets[] = 'username = ?'; $params[] = $newUsername;
    }
    if (!empty($body['password'])) {
        $sets[] = 'password_hash = ?'; $params[] = password_hash($body['password'], PASSWORD_BCRYPT);
    }
    if (array_key_exists('restaurant_id', $body) && $session['role'] !== 'superadmin') {
        $newRestId = $body['restaurant_id'] ? (int)$body['restaurant_id'] : null;
        if ($newRestId && !in_array($newRestId, $adminRestIds)) {
            json_response(false, null, 'Dostop do te restavracije je zavrnjen.', 403);
        }
        $sets[] = 'restaurant_id = ?'; $params[] = $newRestId;
    }
    if (array_key_exists('restaurant_id', $body) && $session['role'] === 'superadmin') {
        $sets[] = 'restaurant_id = ?'; $params[] = $body['restaurant_id'] ? (int)$body['restaurant_id'] : null;
    }
    if (isset($body['is_active']) && $id !== (int)$session['user_id']) {
        $sets[] = 'is_active = ?'; $params[] = $body['is_active'] ? 1 : 0;
    }

    if (empty($sets)) json_response(false, null, 'Ni podatkov za posodobitev.', 400);

    $params[] = $id;

    try {
        $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.username, u.full_name, u.role, u.restaurant_id, u.is_active, u.created_at,
                   r.name AS restaurant_name
            FROM users u LEFT JOIN restaurants r ON u.restaurant_id = r.id WHERE u.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) json_response(false, null, 'Uporabnik ne obstaja.', 404);
        json_response(true, $row);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_response(false, null, 'Email naslov je že zaseden.', 409);
        }
        error_log('User update error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri posodabljanju.', 500);
    }
}

// ─── DELETE (deaktiviraj ali trajno izbriši) ───────────────────
if ($method === 'DELETE') {
    $id    = isset($_GET['id'])    ? (int)$_GET['id']       : 0;
    $force = isset($_GET['force']) && $_GET['force'] === '1';
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    if ($id === (int)$session['user_id']) {
        json_response(false, null, 'Ne morete ' . ($force ? 'izbrisati' : 'deaktivirati') . ' lastnega računa.', 400);
    }

    // Preveri dostop
    if ($session['role'] !== 'superadmin') {
        if (empty($adminRestIds)) json_response(false, null, 'Dostop zavrnjen.', 403);
        $placeholders = implode(',', array_fill(0, count($adminRestIds), '?'));
        $chk = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'user' AND restaurant_id IN ($placeholders)");
        $chk->execute(array_merge([$id], $adminRestIds));
        if (!$chk->fetchColumn()) json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    try {
        if ($force) {
            // Prepiši created_by na brisalca (FK RESTRICT prepreči brisanje, dokler obstajajo rezervacije)
            $pdo->prepare("UPDATE reservations SET created_by = ? WHERE created_by = ?")->execute([$session['user_id'], $id]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        } else {
            $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$id]);
        }
        json_response(true);
    } catch (PDOException $e) {
        error_log('User delete error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri ' . ($force ? 'brisanju' : 'deaktiviranju') . '.', 500);
    }
}

json_response(false, null, 'Metoda ni podprta.', 405);
