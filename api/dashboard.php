<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_auth();
$pdo     = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, null, 'Metoda ni podprta.', 405);
}

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    json_response(false, null, 'Neveljaven mesec.', 400);
}

$from = $month . '-01';
$to   = date('Y-m-t', strtotime($from));

$rest_id = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : null;

try {
    if ($session['role'] === 'superadmin') {
        if ($rest_id) {
            $stmt = $pdo->prepare("
                SELECT reservation_date,
                       COUNT(*) AS reservation_count,
                       SUM(guest_count) AS total_guests
                FROM reservations
                WHERE reservation_date BETWEEN ? AND ? AND restaurant_id = ?
                GROUP BY reservation_date
            ");
            $stmt->execute([$from, $to, $rest_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT r.reservation_date,
                       COUNT(*) AS reservation_count,
                       SUM(r.guest_count) AS total_guests
                FROM reservations r
                JOIN restaurants res ON r.restaurant_id = res.id
                WHERE r.reservation_date BETWEEN ? AND ? AND res.is_active = 1
                GROUP BY r.reservation_date
            ");
            $stmt->execute([$from, $to]);
        }
    } elseif ($session['role'] === 'admin') {
        if ($rest_id) {
            if (!admin_owns_restaurant($pdo, $session, $rest_id)) {
                json_response(false, null, 'Dostop zavrnjen.', 403);
            }
            $stmt = $pdo->prepare("
                SELECT reservation_date,
                       COUNT(*) AS reservation_count,
                       SUM(guest_count) AS total_guests
                FROM reservations
                WHERE reservation_date BETWEEN ? AND ? AND restaurant_id = ?
                GROUP BY reservation_date
            ");
            $stmt->execute([$from, $to, $rest_id]);
        } else {
            // Admin brez filtra: samo lastne restavracije (prek restaurant_admins)
            $stmt = $pdo->prepare("
                SELECT r.reservation_date,
                       COUNT(*) AS reservation_count,
                       SUM(r.guest_count) AS total_guests
                FROM reservations r
                JOIN restaurants res ON r.restaurant_id = res.id
                WHERE r.reservation_date BETWEEN ? AND ? AND res.is_active = 1
                  AND EXISTS (SELECT 1 FROM restaurant_admins ra
                              WHERE ra.restaurant_id = r.restaurant_id AND ra.user_id = ?)
                GROUP BY r.reservation_date
            ");
            $stmt->execute([$from, $to, $session['user_id']]);
        }
    } else {
        // user: samo lastna restavracija
        $stmt = $pdo->prepare("
            SELECT reservation_date,
                   COUNT(*) AS reservation_count,
                   SUM(guest_count) AS total_guests
            FROM reservations
            WHERE reservation_date BETWEEN ? AND ? AND restaurant_id = ?
            GROUP BY reservation_date
        ");
        $stmt->execute([$from, $to, (int)$session['restaurant_id']]);
    }

    $data = [];
    foreach ($stmt->fetchAll() as $row) {
        $data[$row['reservation_date']] = [
            'count'  => (int) $row['reservation_count'],
            'guests' => (int) $row['total_guests'],
        ];
    }

    json_response(true, $data);

} catch (PDOException $e) {
    error_log('Dashboard error: ' . $e->getMessage());
    json_response(false, null, 'Napaka strežnika.', 500);
}
