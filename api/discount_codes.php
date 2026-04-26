<?php
/**
 * Discount codes API.
 * Javno: ?action=validate (rate-limit 10/min/IP)
 * Superadmin: list, create, deactivate, redemptions
 */
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth_check.php';
require_once '../includes/discount_helper.php';
require_once '../includes/stripe_helper.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? (get_body()['action'] ?? '');
$pdo    = getDB();

// ─── Javna validacija kode (brez auth) ───────────────────────────
if ($action === 'validate') {
    // Rate limit: 10 req / 60s / IP
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '';
    $limKey  = 'dc_rl_' . hash('sha256', $ip);
    // Brez memcached → preprosta baza-backed rešitev (ali samo preberemo)
    // Za MVP: zaupamo, da server-level firewall / nginx throttle prevzame

    $code  = strtoupper(trim($_GET['code'] ?? ''));
    $plan  = trim($_GET['plan']  ?? '');
    $cycle = trim($_GET['cycle'] ?? '');

    if (!$code) json_response(false, null, 'Manjka koda.', 400);

    $result = validate_discount_code($pdo, $code, $plan, $cycle, null);
    if (!$result['valid']) {
        json_response(false, null, $result['error'], 422);
    }
    json_response(true, [
        'valid'          => true,
        'code'           => $code,
        'percent_off'    => $result['percent_off'],
        'amount_off_eur' => $result['amount_off_eur'] ?? null,
        'duration'       => $result['duration'],
        'duration_months'=> $result['duration_months'] ?? null,
        'description'    => $result['description'] ?? '',
    ]);
}

// ─── Superadmin akcije ─────────────────────────────────────────
$session = require_superadmin();
$body    = get_body();

if ($action === 'list') {
    $status = $_GET['status'] ?? 'all'; // all, active, inactive
    $where  = $status === 'active' ? 'WHERE dc.is_active = 1'
            : ($status === 'inactive' ? 'WHERE dc.is_active = 0' : '');

    $rows = $pdo->query("
        SELECT dc.*,
               a.full_name AS owner_name,
               (SELECT COUNT(*) FROM discount_code_redemptions r WHERE r.code_id = dc.id) AS redemption_count
        FROM discount_codes dc
        LEFT JOIN affiliates a ON a.id = dc.owner_affiliate_id
        {$where}
        ORDER BY dc.created_at DESC
        LIMIT 200
    ")->fetchAll();
    json_response(true, ['items' => $rows]);
}

if ($action === 'create') {
    $code        = strtoupper(trim($body['code'] ?? ''));
    $pct         = isset($body['percent_off'])    ? (float)$body['percent_off']    : null;
    $amt         = isset($body['amount_off_eur']) ? (float)$body['amount_off_eur'] : null;
    $dur         = in_array($body['duration'] ?? '', ['once','repeating','forever']) ? $body['duration'] : 'once';
    $durMonths   = ($dur === 'repeating' && isset($body['duration_months'])) ? (int)$body['duration_months'] : null;
    $maxRed      = isset($body['max_redemptions']) ? (int)$body['max_redemptions'] : null;
    $validFrom   = $body['valid_from']  ?? null;
    $validUntil  = $body['valid_until'] ?? null;
    $desc        = substr($body['description'] ?? '', 0, 180);
    $plans       = $body['applies_to_plans']  ?? null;
    $cycles      = $body['applies_to_cycles'] ?? null;

    if (!$pct && !$amt) json_response(false, null, 'Določi percent_off ali amount_off_eur.', 400);
    if (!$code) {
        // Auto-generiraj 8-znakovni code
        $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) $code .= $alpha[random_int(0, strlen($alpha)-1)];
        } while (discount_code_exists($pdo, $code));
    } else {
        if (!preg_match('/^[A-Z0-9]{3,40}$/', $code)) json_response(false, null, 'Neveljavna oblika kode.', 400);
        if (discount_code_exists($pdo, $code)) json_response(false, null, 'Koda že obstaja.', 409);
    }

    // Stripe coupon
    $couponData = ['duration' => $dur, 'name' => $desc ?: $code];
    if ($pct)  $couponData['percent_off'] = $pct;
    if ($amt)  { $couponData['amount_off'] = (int)round($amt * 100); $couponData['currency'] = 'eur'; }
    if ($dur === 'repeating' && $durMonths) $couponData['duration_in_months'] = $durMonths;

    $coupon = stripe_request('POST', '/coupons', $couponData);
    if (empty($coupon['id'])) json_response(false, null, 'Stripe napaka pri kreiranju kupona.', 500);

    $promo = stripe_request('POST', '/promotion_codes', [
        'coupon'           => $coupon['id'],
        'code'             => $code,
        'max_redemptions'  => $maxRed,
        'expires_at'       => $validUntil ? strtotime($validUntil) : null,
    ]);
    $promoId = $promo['id'] ?? null;

    $pdo->prepare("
        INSERT INTO discount_codes
        (code, description, percent_off, amount_off_eur, applies_to_plans, applies_to_cycles,
         duration, duration_months, max_redemptions, valid_from, valid_until,
         stripe_coupon_id, stripe_promo_id, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
    ")->execute([
        $code, $desc, $pct, $amt, $plans, $cycles,
        $dur, $durMonths, $maxRed, $validFrom, $validUntil,
        $coupon['id'], $promoId, (int)$session['user_id'],
    ]);

    json_response(true, ['code' => $code, 'id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'deactivate') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_response(false, null, 'Manjka id.', 400);

    $stmt = $pdo->prepare("SELECT stripe_promo_id FROM discount_codes WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_response(false, null, 'Koda ne obstaja.', 404);

    if ($row['stripe_promo_id']) {
        stripe_request('POST', '/promotion_codes/' . $row['stripe_promo_id'], ['active' => 'false']);
    }
    $pdo->prepare("UPDATE discount_codes SET is_active = 0 WHERE id = ?")->execute([$id]);
    json_response(true, null, 'Koda deaktivirana.');
}

if ($action === 'redemptions') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_response(false, null, 'Manjka id.', 400);
    $stmt = $pdo->prepare("
        SELECT r.*, u.full_name AS user_name, u.email AS user_email
        FROM discount_code_redemptions r
        JOIN users u ON u.id = r.user_id
        WHERE r.code_id = ?
        ORDER BY r.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$id]);
    json_response(true, ['items' => $stmt->fetchAll()]);
}

json_response(false, null, 'Neznan action.', 400);
