<?php
/**
 * Javni pricing endpoint – brez autentikacije.
 * Vrne plane s cenami in aktivnimi popusti.
 * Uporablja ga landing page.
 */
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $pdo = getDB();

    $result = [];
    foreach (['basic', 'advanced', 'premium'] as $slug) {
        $plan     = PLANS[$slug];
        $discount = get_active_discount($pdo, $slug);

        $result[$slug] = [
            'name'          => $plan['name'],
            'monthly_price' => $plan['monthly_price'],
            'yearly_price'  => $plan['yearly_price'],
            'discount'      => $discount ? [
                'label'              => $discount['label'],
                'discounted_monthly' => $discount['discounted_monthly'] !== null ? (float)$discount['discounted_monthly'] : null,
                'discounted_yearly'  => $discount['discounted_yearly']  !== null ? (float)$discount['discounted_yearly']  : null,
                'valid_until'        => substr($discount['valid_until'], 0, 10),
            ] : null,
        ];
    }

    echo json_encode(['success' => true, 'data' => $result]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
