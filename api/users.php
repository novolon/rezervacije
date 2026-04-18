<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_admin();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

$adminRestIds = get_admin_restaurant_ids($pdo, $session);

// ─── Pomožna: ustvari subscription za sub-admina ───────────────
function create_sub_admin_subscription(PDO $pdo, int $newUserId, int $creatorUserId): void {
    $existing = $pdo->prepare("SELECT 1 FROM subscriptions WHERE user_id = ? AND status IN ('trial','active','pending_invoice') AND (ends_at IS NULL OR ends_at > NOW())");
    $existing->execute([$newUserId]);
    if ($existing->fetchColumn()) return; // že ima naročnino

    $mainSub  = get_active_subscription($pdo, $creatorUserId);
    $planSlug = $mainSub['plan_slug'] ?? 'basic';
    if ($planSlug === 'trial') $planSlug = 'basic';
    $pdo->prepare("INSERT INTO subscriptions (user_id, plan_slug, status, started_at) VALUES (?, ?, 'active', NOW())")
        ->execute([$newUserId, $planSlug]);
}

// ─── Pomožna: prestavi user→admin (premakne restaurant_id v restaurant_admins) ─
function upgrade_user_to_admin(PDO $pdo, int $userId, int $creatorId): void {
    $row = $pdo->prepare("SELECT restaurant_id FROM users WHERE id = ?")->execute([$userId]) ? null : null;
    $stmt = $pdo->prepare("SELECT restaurant_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $oldRestId = (int)($stmt->fetchColumn() ?: 0);

    $pdo->prepare("UPDATE users SET role = 'admin', restaurant_id = NULL WHERE id = ?")->execute([$userId]);

    if ($oldRestId) {
        $pdo->prepare("INSERT IGNORE INTO restaurant_admins (restaurant_id, user_id) VALUES (?, ?)")
            ->execute([$oldRestId, $userId]);
    }
    create_sub_admin_subscription($pdo, $userId, $creatorId);
}

// ─── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {

    // action=check_email – preveri ali email/username že obstaja
    if (isset($_GET['action']) && $_GET['action'] === 'check_email') {
        $email    = trim($_GET['email']    ?? '');
        $username = trim($_GET['username'] ?? '');
        if (!$email && !$username) json_response(false, null, 'Email ali username je obvezen.', 400);

        if ($email) {
            $stmt = $pdo->prepare("SELECT id, full_name, role FROM users WHERE email = ? AND role != 'superadmin'");
            $stmt->execute([$email]);
        } else {
            $stmt = $pdo->prepare("SELECT id, full_name, role FROM users WHERE username = ? AND role != 'superadmin'");
            $stmt->execute([$username]);
        }
        $found = $stmt->fetch();
        json_response(true, $found ? ['found' => true, 'user' => ['id' => $found['id'], 'full_name' => $found['full_name'], 'role' => $found['role']]] : ['found' => false]);
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($id) {
        if ($session['role'] === 'superadmin') {
            $stmt = $pdo->prepare("
                SELECT u.id, u.email, u.username, u.full_name, u.role, u.restaurant_id, u.is_active,
                       u.trial_ends_at, u.subscription_status, u.created_at,
                       r.name AS restaurant_name, NULL AS linked_restaurant_id
                FROM users u LEFT JOIN restaurants r ON u.restaurant_id = r.id
                WHERE u.id = ?
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
        } else {
            if (empty($adminRestIds)) json_response(false, null, 'Uporabnik ne obstaja.', 404);
            $placeholders = implode(',', array_fill(0, count($adminRestIds), '?'));
            $stmt = $pdo->prepare("
                SELECT u.id, u.email, u.username, u.full_name, u.role, u.restaurant_id, u.is_active, u.created_at,
                       r.name AS restaurant_name, NULL AS linked_restaurant_id
                FROM users u LEFT JOIN restaurants r ON u.restaurant_id = r.id
                WHERE u.id = ? AND u.role = 'user' AND u.restaurant_id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$id], $adminRestIds));
            $row = $stmt->fetch();
            if (!$row) {
                // Preverimo kot sub-admin
                $stmt = $pdo->prepare("
                    SELECT u.id, u.email, u.username, u.full_name, u.role, u.restaurant_id, u.is_active, u.created_at,
                           (SELECT r2.name FROM restaurant_admins ra2
                            JOIN restaurants r2 ON ra2.restaurant_id = r2.id
                            WHERE ra2.user_id = u.id AND ra2.restaurant_id IN ($placeholders)
                            LIMIT 1) AS restaurant_name,
                           (SELECT ra2.restaurant_id FROM restaurant_admins ra2
                            WHERE ra2.user_id = u.id AND ra2.restaurant_id IN ($placeholders)
                            LIMIT 1) AS linked_restaurant_id
                    FROM users u
                    WHERE u.id = ? AND u.role = 'admin' AND u.id != ?
                      AND EXISTS (
                          SELECT 1 FROM restaurant_admins ra
                          WHERE ra.user_id = u.id AND ra.restaurant_id IN ($placeholders)
                      )
                ");
                $stmt->execute(array_merge($adminRestIds, $adminRestIds, [$id, (int)$session['user_id']], $adminRestIds));
                $row = $stmt->fetch();
            }
        }
        if (!$row) json_response(false, null, 'Uporabnik ne obstaja.', 404);
        json_response(true, $row);
    }

    // Seznam
    if ($session['role'] === 'superadmin') {
        $stmt = $pdo->query("
            SELECT u.id, u.email, u.username, u.full_name, u.role, u.restaurant_id, u.is_active,
                   u.trial_ends_at, u.subscription_status, u.created_at,
                   r.name AS restaurant_name, NULL AS linked_restaurant_id
            FROM users u LEFT JOIN restaurants r ON u.restaurant_id = r.id
            WHERE u.role != 'superadmin'
            ORDER BY u.role, u.full_name
        ");
        json_response(true, $stmt->fetchAll());
    }

    if (empty($adminRestIds)) json_response(true, []);
    $placeholders = implode(',', array_fill(0, count($adminRestIds), '?'));

    // Navadni userji
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.username, u.full_name, u.role, u.restaurant_id, u.is_active, u.created_at,
               r.name AS restaurant_name, NULL AS linked_restaurant_id
        FROM users u LEFT JOIN restaurants r ON u.restaurant_id = r.id
        WHERE u.role = 'user' AND u.restaurant_id IN ($placeholders)
        ORDER BY u.full_name
    ");
    $stmt->execute($adminRestIds);
    $users = $stmt->fetchAll();

    // Sub-admini
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.username, u.full_name, u.role, u.restaurant_id, u.is_active, u.created_at,
               MIN(r2.name) AS restaurant_name,
               MIN(ra.restaurant_id) AS linked_restaurant_id
        FROM users u
        JOIN restaurant_admins ra ON ra.user_id = u.id
        JOIN restaurants r2 ON ra.restaurant_id = r2.id
        WHERE ra.restaurant_id IN ($placeholders) AND u.id != ? AND u.role = 'admin'
        GROUP BY u.id, u.email, u.username, u.full_name, u.role, u.restaurant_id, u.is_active, u.created_at
        ORDER BY u.full_name
    ");
    $stmt->execute(array_merge($adminRestIds, [(int)$session['user_id']]));
    $subAdmins = $stmt->fetchAll();

    json_response(true, array_merge($users, $subAdmins));
}

// ─── POST (ustvari ali poveži obstoječega) ─────────────────────
if ($method === 'POST') {
    if ($session['role'] === 'superadmin') {
        json_response(false, null, 'Superadmin ne ustvarja userjev.', 403);
    }

    $body      = get_body();
    $rest_id   = isset($body['restaurant_id']) ? (int)$body['restaurant_id'] : null;
    $role      = isset($body['role']) && $body['role'] === 'admin' ? 'admin' : 'user';

    if (!$rest_id) json_response(false, null, 'Restavracija je obvezna.', 400);
    if (!in_array($rest_id, $adminRestIds)) json_response(false, null, 'Dostop do te restavracije je zavrnjen.', 403);

    // ── Poveži obstoječega uporabnika ─────────────────────────
    $linkId = isset($body['link_existing_id']) ? (int)$body['link_existing_id'] : null;
    if ($linkId) {
        // Preveri da je to validen user (ne superadmin)
        $existStmt = $pdo->prepare("SELECT id, full_name, role, restaurant_id FROM users WHERE id = ? AND role != 'superadmin'");
        $existStmt->execute([$linkId]);
        $existing = $existStmt->fetch();
        if (!$existing) json_response(false, null, 'Uporabnik ne obstaja.', 404);

        // Preveri da ni že dodeljen tej restavraciji
        $alreadyUser = ($existing['role'] === 'user' && (int)$existing['restaurant_id'] === $rest_id);
        $alreadyAdmin = false;
        if ($existing['role'] === 'admin') {
            $chk = $pdo->prepare("SELECT 1 FROM restaurant_admins WHERE user_id = ? AND restaurant_id = ?");
            $chk->execute([$linkId, $rest_id]);
            $alreadyAdmin = (bool)$chk->fetchColumn();
        }
        if ($alreadyUser || $alreadyAdmin) {
            json_response(false, null, 'Uporabnik je že dodeljen tej restavraciji.', 409);
        }

        try {
            $pdo->beginTransaction();

            if ($existing['role'] === 'user') {
                // Upgrade na admin da podpre več restavracij
                upgrade_user_to_admin($pdo, $linkId, (int)$session['user_id']);
            }

            // Dodaj v restaurant_admins za novo restavracijo
            $pdo->prepare("INSERT IGNORE INTO restaurant_admins (restaurant_id, user_id) VALUES (?, ?)")
                ->execute([$rest_id, $linkId]);

            create_sub_admin_subscription($pdo, $linkId, (int)$session['user_id']);
            $pdo->commit();

            $stmt = $pdo->prepare("
                SELECT u.id, u.email, u.username, u.full_name, u.role, u.restaurant_id, u.is_active, u.created_at,
                       r.name AS restaurant_name
                FROM users u LEFT JOIN restaurants r ON u.restaurant_id = r.id WHERE u.id = ?
            ");
            $stmt->execute([$linkId]);
            json_response(true, $stmt->fetch(), '', 200);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('User link error: ' . $e->getMessage());
            json_response(false, null, 'Napaka pri povezovanju.', 500);
        }
    }

    // ── Nov uporabnik ─────────────────────────────────────────
    $email     = trim($body['email']    ?? '') ?: null;
    $username  = trim($body['username'] ?? '') ?: null;
    $full_name = trim($body['full_name'] ?? '');
    $password  = $body['password']       ?? '';

    if (!$email && !$username) json_response(false, null, 'Vnesite email ali uporabniško ime.', 400);
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(false, null, 'Email naslov ni veljaven.', 400);
    if ($username && (strlen($username) < 3 || !preg_match('/^[a-zA-Z0-9._-]+$/', $username))) {
        json_response(false, null, 'Uporabniško ime mora imeti vsaj 3 znake (a-z, 0-9, . _ -).', 400);
    }
    if (!$full_name || !$password) json_response(false, null, 'Ime in geslo sta obvezna.', 400);

    try {
        $pdo->beginTransaction();
        $hash       = password_hash($password, PASSWORD_BCRYPT);
        $userRestId = $role === 'user' ? $rest_id : null;

        $stmt = $pdo->prepare("
            INSERT INTO users (email, username, password_hash, full_name, role, restaurant_id,
                               email_verified_at, subscription_status, is_active)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), 'active', 1)
        ");
        $stmt->execute([$email, $username, $hash, $full_name, $role, $userRestId]);
        $newId = (int)$pdo->lastInsertId();

        if ($role === 'admin') {
            $pdo->prepare("INSERT IGNORE INTO restaurant_admins (restaurant_id, user_id) VALUES (?, ?)")
                ->execute([$rest_id, $newId]);
            create_sub_admin_subscription($pdo, $newId, (int)$session['user_id']);
        }

        $pdo->commit();

        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.username, u.full_name, u.role, u.restaurant_id, u.is_active, u.created_at,
                   r.name AS restaurant_name
            FROM users u LEFT JOIN restaurants r ON u.restaurant_id = r.id WHERE u.id = ?
        ");
        $stmt->execute([$newId]);
        json_response(true, $stmt->fetch(), '', 201);
    } catch (PDOException $e) {
        $pdo->rollBack();
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

    // Preveri dostop + pridobi trenutno vlogo
    $userStmt = $pdo->prepare("SELECT role, restaurant_id FROM users WHERE id = ?");
    $userStmt->execute([$id]);
    $targetUser = $userStmt->fetch();
    if (!$targetUser) json_response(false, null, 'Uporabnik ne obstaja.', 404);

    if ($session['role'] !== 'superadmin') {
        if (empty($adminRestIds)) json_response(false, null, 'Dostop zavrnjen.', 403);
        $placeholders = implode(',', array_fill(0, count($adminRestIds), '?'));
        $chk = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'user' AND restaurant_id IN ($placeholders)");
        $chk->execute(array_merge([$id], $adminRestIds));
        if (!$chk->fetchColumn()) {
            if ($id === (int)$session['user_id']) json_response(false, null, 'Dostop zavrnjen.', 403);
            $chk = $pdo->prepare("SELECT 1 FROM restaurant_admins WHERE user_id = ? AND restaurant_id IN ($placeholders)");
            $chk->execute(array_merge([$id], $adminRestIds));
            if (!$chk->fetchColumn()) json_response(false, null, 'Dostop zavrnjen.', 403);
        }
    }

    $body    = get_body();
    $newRole = isset($body['role']) ? ($body['role'] === 'admin' ? 'admin' : 'user') : null;
    $oldRole = $targetUser['role'];
    $sets    = [];
    $params  = [];

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
    if (isset($body['is_active']) && $id !== (int)$session['user_id']) {
        $sets[] = 'is_active = ?'; $params[] = $body['is_active'] ? 1 : 0;
    }

    // ── Sprememba vloge ────────────────────────────────────────
    $newRestId = isset($body['restaurant_id']) ? ((int)$body['restaurant_id'] ?: null) : null;

    try {
        $pdo->beginTransaction();

        if ($newRole && $newRole !== $oldRole) {

            if ($newRole === 'admin' && $oldRole === 'user') {
                // user → admin
                $linkRestId = $newRestId ?: (int)$targetUser['restaurant_id'];
                if ($session['role'] !== 'superadmin' && $linkRestId && !in_array($linkRestId, $adminRestIds)) {
                    $pdo->rollBack();
                    json_response(false, null, 'Dostop do te restavracije je zavrnjen.', 403);
                }
                $sets[] = 'role = ?';          $params[] = 'admin';
                $sets[] = 'restaurant_id = ?'; $params[] = null;
                if ($linkRestId) {
                    $pdo->prepare("INSERT IGNORE INTO restaurant_admins (restaurant_id, user_id) VALUES (?, ?)")
                        ->execute([$linkRestId, $id]);
                }
                create_sub_admin_subscription($pdo, $id, (int)$session['user_id']);

            } elseif ($newRole === 'user' && $oldRole === 'admin') {
                // admin → user
                if (!$newRestId) { $pdo->rollBack(); json_response(false, null, 'Restavracija je obvezna pri spremembi vloge na Uporabnik.', 400); }
                if ($session['role'] !== 'superadmin' && !in_array($newRestId, $adminRestIds)) {
                    $pdo->rollBack();
                    json_response(false, null, 'Dostop do te restavracije je zavrnjen.', 403);
                }
                $sets[] = 'role = ?';          $params[] = 'user';
                $sets[] = 'restaurant_id = ?'; $params[] = $newRestId;
                // Odstrani iz restaurant_admins za restavracije tega admina
                if ($session['role'] !== 'superadmin' && !empty($adminRestIds)) {
                    $ph = implode(',', array_fill(0, count($adminRestIds), '?'));
                    $pdo->prepare("DELETE FROM restaurant_admins WHERE user_id = ? AND restaurant_id IN ($ph)")
                        ->execute(array_merge([$id], $adminRestIds));
                } else {
                    $pdo->prepare("DELETE FROM restaurant_admins WHERE user_id = ?")->execute([$id]);
                }
            }

        } else {
            // Ni spremembe vloge – samo posodobi restavracijo
            if ($newRestId !== null) {
                if ($session['role'] !== 'superadmin' && !in_array($newRestId, $adminRestIds)) {
                    $pdo->rollBack();
                    json_response(false, null, 'Dostop do te restavracije je zavrnjen.', 403);
                }
                if ($oldRole === 'admin') {
                    // Admin: dodaj novo restavracijo v restaurant_admins
                    $pdo->prepare("INSERT IGNORE INTO restaurant_admins (restaurant_id, user_id) VALUES (?, ?)")
                        ->execute([$newRestId, $id]);
                } else {
                    $sets[] = 'restaurant_id = ?'; $params[] = $newRestId;
                }
            } elseif (array_key_exists('restaurant_id', $body) && $session['role'] === 'superadmin') {
                $sets[] = 'restaurant_id = ?'; $params[] = null;
            }
        }

        if (!empty($sets)) {
            $params[] = $id;
            $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        }

        $pdo->commit();

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
        $pdo->rollBack();
        if ($e->getCode() === '23000') {
            json_response(false, null, 'Email naslov je že zaseden.', 409);
        }
        error_log('User update error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri posodabljanju.', 500);
    }
}

// ─── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id    = isset($_GET['id'])    ? (int)$_GET['id']       : 0;
    $force = isset($_GET['force']) && $_GET['force'] === '1';
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    if ($id === (int)$session['user_id']) {
        json_response(false, null, 'Ne morete ' . ($force ? 'izbrisati' : 'deaktivirati') . ' lastnega računa.', 400);
    }

    if ($session['role'] !== 'superadmin') {
        if (empty($adminRestIds)) json_response(false, null, 'Dostop zavrnjen.', 403);
        $placeholders = implode(',', array_fill(0, count($adminRestIds), '?'));
        $chk = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'user' AND restaurant_id IN ($placeholders)");
        $chk->execute(array_merge([$id], $adminRestIds));
        if (!$chk->fetchColumn()) {
            $chk = $pdo->prepare("SELECT 1 FROM restaurant_admins WHERE user_id = ? AND restaurant_id IN ($placeholders)");
            $chk->execute(array_merge([$id], $adminRestIds));
            if (!$chk->fetchColumn()) json_response(false, null, 'Dostop zavrnjen.', 403);
        }
    }

    try {
        if ($force) {
            $pdo->prepare("UPDATE reservations SET created_by = ? WHERE created_by = ?")->execute([$session['user_id'], $id]);
            $pdo->prepare("DELETE FROM restaurant_admins WHERE user_id = ?")->execute([$id]);
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
