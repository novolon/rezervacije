<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

// POST = prijava
if ($method === 'POST') {
    $body     = get_body();
    $email    = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    if (!$email || !$password) {
        json_response(false, null, 'Vnesite email in geslo.', 400);
    }

    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            SELECT u.*, r.name AS restaurant_name, r.reservation_duration, r.color AS restaurant_color
            FROM users u
            LEFT JOIN restaurants r ON u.restaurant_id = r.id
            WHERE u.email = ? AND u.is_active = 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            json_response(false, null, 'Napačen email ali geslo.', 401);
        }

        // Blokiraj nepotrjene emaile (superadmin in user sta vedno potrjena)
        if ($user['role'] === 'admin' && empty($user['email_verified_at'])) {
            json_response(false, null, 'email_not_verified', 403);
        }

        // Ustvari novo sejo (preprečimo session fixation)
        session_regenerate_id(true);

        $_SESSION['user_id']       = (int) $user['id'];
        $_SESSION['role']          = $user['role'];
        $_SESSION['email']         = $user['email'];
        $_SESSION['restaurant_id'] = $user['restaurant_id'] ? (int) $user['restaurant_id'] : null;
        $_SESSION['full_name']     = $user['full_name'];
        $_SESSION['last_activity'] = time();

        // Remember me
        if (!empty($body['remember_me'])) {
            $raw     = bin2hex(random_bytes(32));
            $hash    = hash('sha256', $raw);
            $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
            $pdo->prepare("UPDATE users SET remember_token = ?, remember_expires = ? WHERE id = ?")
                ->execute([$hash, $expires, $user['id']]);
            setcookie('rem_tok', $user['id'] . ':' . $raw, [
                'expires'  => time() + 30 * 86400,
                'path'     => BASE_PATH . '/',
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }

        // Redirect URL glede na vlogo
        $redirect = ($user['role'] === 'superadmin')
            ? BASE_PATH . '/pages/superadmin.php'
            : BASE_PATH . '/pages/main.php';

        json_response(true, [
            'userId'       => $_SESSION['user_id'],
            'role'         => $_SESSION['role'],
            'restaurantId' => $_SESSION['restaurant_id'],
            'fullName'     => $_SESSION['full_name'],
            'redirect'     => $redirect,
        ]);

    } catch (PDOException $e) {
        error_log('Auth error: ' . $e->getMessage());
        json_response(false, null, 'Napaka strežnika.', 500);
    }
}

// DELETE ali POST z action=logout
if ($method === 'DELETE' || ($method === 'POST' && (get_body()['action'] ?? '') === 'logout')) {
    // Počisti remember-me token v bazi
    if (!empty($_SESSION['user_id'])) {
        try {
            $pdo = getDB();
            $pdo->prepare("UPDATE users SET remember_token = NULL, remember_expires = NULL WHERE id = ?")
                ->execute([$_SESSION['user_id']]);
        } catch (Exception $e) {}
    }
    setcookie('rem_tok', '', ['expires' => time() - 1, 'path' => BASE_PATH . '/']);
    session_unset();
    session_destroy();
    json_response(true);
}

json_response(false, null, 'Metoda ni podprta.', 405);
