<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_superadmin();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

// ─── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = get_body();
    $action = $body['action'] ?? '';

    // Ročno dodeljevanje paketa
    if ($action === 'assign_plan') {
        $userId   = isset($body['user_id'])   ? (int)$body['user_id']   : 0;
        $planSlug = $body['plan_slug'] ?? '';
        $endsAt   = $body['ends_at']   ?? null; // null = trajno

        if (!$userId) json_response(false, null, 'user_id je obvezen.', 400);
        if (!array_key_exists($planSlug, PLANS)) json_response(false, null, 'Neveljaven paket.', 400);
        if ($endsAt && !preg_match('/^\d{4}-\d{2}-\d{2}/', $endsAt)) {
            json_response(false, null, 'Neveljaven datum poteka.', 400);
        }

        // Preveri da user obstaja in je admin
        $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin'");
        $chk->execute([$userId]);
        if (!$chk->fetchColumn()) json_response(false, null, 'Admin ne obstaja.', 404);

        try {
            // Deaktiviraj obstoječe aktivne naročnine
            $pdo->prepare("UPDATE subscriptions SET status = 'canceled' WHERE user_id = ? AND status IN ('trial','active')")
                ->execute([$userId]);

            // Vstavi novo
            $status = $planSlug === 'trial' ? 'trial' : 'active';
            $pdo->prepare("
                INSERT INTO subscriptions (user_id, plan_slug, status, ends_at, assigned_by)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$userId, $planSlug, $status, $endsAt ?: null, $session['user_id']]);

            // Posodobi users.subscription_status za kompatibilnost
            $pdo->prepare("UPDATE users SET subscription_status = ? WHERE id = ?")
                ->execute([$status, $userId]);

            json_response(true, null, 'Paket dodeljen.');
        } catch (PDOException $e) {
            error_log('assign_plan error: ' . $e->getMessage());
            json_response(false, null, 'Napaka pri dodeljevanju.', 500);
        }
    }

    // Testni email
    require_once '../includes/mailer.php';
    $body  = get_body();
    $to    = trim($body['email'] ?? '');
    $type  = $body['type'] ?? 'verification';

    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        json_response(false, null, 'Vnesite veljaven email.', 400);
    }

    $name = $session['full_name'] ?? 'Superadmin';

    if ($type === 'reset') {
        $ok = send_password_reset_email($to, $name, 'TEST_TOKEN_12345');
    } else {
        $ok = send_verification_email($to, $name, 'TEST_TOKEN_12345');
    }

    if ($ok) {
        json_response(true, null, 'Email poslan na ' . $to);
    } else {
        json_response(false, null, 'Pošiljanje ni uspelo. Preverite Mailgun nastavitve.', 500);
    }
}

if ($method !== 'GET') {
    json_response(false, null, 'Metoda ni podprta.', 405);
}

$action = $_GET['action'] ?? '';

// ─── Vsi admini z restavracijami ───────────────────────────────
if ($action === 'admins') {
    $stmt = $pdo->query("
        SELECT u.id, u.email, u.full_name, u.is_active,
               u.trial_ends_at, u.subscription_status, u.created_at,
               COUNT(ra.restaurant_id) AS restaurant_count,
               COALESCE(s.plan_slug, 'trial') AS plan_slug
        FROM users u
        LEFT JOIN restaurant_admins ra ON u.id = ra.user_id
        LEFT JOIN subscriptions s ON s.user_id = u.id
                                  AND s.status IN ('trial','active','pending_invoice')
                                  AND (s.ends_at IS NULL OR s.ends_at > NOW())
        WHERE u.role = 'admin'
        GROUP BY u.id
        ORDER BY u.created_at DESC
    ");
    json_response(true, $stmt->fetchAll());
}

// ─── Vse restavracije z lastnikom ──────────────────────────────
if ($action === 'restaurants') {
    $stmt = $pdo->query("
        SELECT r.id, r.name, r.is_active, r.created_at,
               u.full_name AS owner_name, u.email AS owner_email,
               COUNT(res.id) AS reservation_count
        FROM restaurants r
        JOIN users u ON r.owner_id = u.id
        LEFT JOIN reservations res ON r.id = res.restaurant_id
        GROUP BY r.id
        ORDER BY r.created_at DESC
    ");
    json_response(true, $stmt->fetchAll());
}

// ─── Rezervacije z filtrom ─────────────────────────────────────
if ($action === 'reservations') {
    $date  = $_GET['date']  ?? null;
    $month = $_GET['month'] ?? null;
    $restId = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : null;

    if ($date) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            json_response(false, null, 'Neveljaven datum.', 400);
        }
        $sql = "
            SELECT r.*, res.name AS restaurant_name, res.color AS restaurant_color,
                   COALESCE(r.duration, res.reservation_duration) AS reservation_duration,
                   u.full_name AS owner_name
            FROM reservations r
            JOIN restaurants res ON r.restaurant_id = res.id
            JOIN users u ON res.owner_id = u.id
            WHERE r.reservation_date = ?
        ";
        $params = [$date];
        if ($restId) { $sql .= " AND r.restaurant_id = ?"; $params[] = $restId; }
        $sql .= " ORDER BY r.reservation_time, res.name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_response(true, $stmt->fetchAll());
    }

    if ($month) {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            json_response(false, null, 'Neveljaven mesec.', 400);
        }
        $from = $month . '-01';
        $to   = date('Y-m-t', strtotime($from));
        $sql = "
            SELECT r.reservation_date,
                   COUNT(*) AS reservation_count,
                   SUM(r.guest_count) AS total_guests
            FROM reservations r
            WHERE r.reservation_date BETWEEN ? AND ?
        ";
        $params = [$from, $to];
        if ($restId) { $sql .= " AND r.restaurant_id = ?"; $params[] = $restId; }
        $sql .= " GROUP BY r.reservation_date";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = [];
        foreach ($stmt->fetchAll() as $row) {
            $data[$row['reservation_date']] = [
                'count'  => (int)$row['reservation_count'],
                'guests' => (int)$row['total_guests'],
            ];
        }
        json_response(true, $data);
    }

    json_response(false, null, 'Potreben parameter date ali month.', 400);
}

// ─── Statistika (dashboard overview) ──────────────────────────
if ($action === 'stats') {
    $admins      = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    $restaurants = $pdo->query("SELECT COUNT(*) FROM restaurants WHERE is_active = 1")->fetchColumn();
    $users       = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user' AND is_active = 1")->fetchColumn();
    $resTodayStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE reservation_date = ?");
    $resTodayStmt->execute([date('Y-m-d')]);
    $today_reservations = $resTodayStmt->fetchColumn();
    $trials = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND subscription_status='trial'")->fetchColumn();

    json_response(true, [
        'admins'             => (int)$admins,
        'restaurants'        => (int)$restaurants,
        'users'              => (int)$users,
        'today_reservations' => (int)$today_reservations,
        'trials'             => (int)$trials,
    ]);
}

// ─── Naročnina admina ─────────────────────────────────────────
if ($action === 'subscription') {
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    if (!$userId) json_response(false, null, 'user_id je obvezen.', 400);
    $sub = get_active_subscription($pdo, $userId);
    json_response(true, $sub ?: ['plan_slug' => 'brez', 'status' => 'expired']);
}

json_response(false, null, 'Neznan action parameter.', 400);
