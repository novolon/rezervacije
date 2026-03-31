<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_superadmin();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    json_response(false, null, 'Metoda ni podprta.', 405);
}

$action = $_GET['action'] ?? '';

// ─── Vsi admini z restavracijami ───────────────────────────────
if ($action === 'admins') {
    $stmt = $pdo->query("
        SELECT u.id, u.email, u.full_name, u.is_active,
               u.trial_ends_at, u.subscription_status, u.created_at,
               COUNT(ra.restaurant_id) AS restaurant_count
        FROM users u
        LEFT JOIN restaurant_admins ra ON u.id = ra.user_id
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

json_response(false, null, 'Neznan action parameter.', 400);
