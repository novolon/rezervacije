<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_admin();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];
$body    = $method === 'POST' ? get_body() : [];
$action  = $body['action'] ?? ($_GET['action'] ?? '');

// ─── POST: ustvari Stripe Checkout Session ────────────────────
if ($method === 'POST' && $action === 'create_checkout_session') {
    $planSlug     = $body['plan_slug']     ?? '';
    $billingCycle = $body['billing_cycle'] ?? 'monthly'; // monthly | yearly

    if (!isset(STRIPE_PRICES[$planSlug])) {
        json_response(false, null, 'Neveljaven paket.', 400);
    }
    if (!in_array($billingCycle, ['monthly', 'yearly'])) {
        json_response(false, null, 'Neveljaven billing cycle.', 400);
    }

    $priceId = STRIPE_PRICES[$planSlug][$billingCycle];
    $userId  = (int)$session['user_id'];
    $email   = $session['email'] ?? '';

    // Pridobi ali ustvari stripe_customer_id
    $sub = get_active_subscription($pdo, $userId);
    $customerId = $sub['stripe_customer_id'] ?? null;

    if (!$customerId) {
        // Ustvari Stripe Customer
        $customerData = stripe_request('POST', '/customers', [
            'email'    => $email,
            'metadata' => ['user_id' => $userId],
        ]);
        if (!isset($customerData['id'])) {
            json_response(false, null, 'Napaka pri kreiranju Stripe stranke.', 500);
        }
        $customerId = $customerData['id'];
        // Shrani customer_id
        if ($sub) {
            $pdo->prepare("UPDATE subscriptions SET stripe_customer_id = ? WHERE id = ?")
                ->execute([$customerId, $sub['id']]);
        }
    }

    $baseUrl = APP_URL . BASE_PATH;

    $checkoutData = stripe_request('POST', '/checkout/sessions', [
        'customer'            => $customerId,
        'payment_method_types' => ['card'],
        'mode'                => 'subscription',
        'line_items'          => [[
            'price'    => $priceId,
            'quantity' => 1,
        ]],
        'success_url'         => $baseUrl . '/pages/billing-success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'          => $baseUrl . '/pages/billing.php?canceled=1',
        'locale'              => 'sl',
        'subscription_data'   => [
            'metadata' => [
                'user_id'       => $userId,
                'plan_slug'     => $planSlug,
                'billing_cycle' => $billingCycle,
            ],
        ],
        'metadata' => [
            'user_id'       => $userId,
            'plan_slug'     => $planSlug,
            'billing_cycle' => $billingCycle,
        ],
    ]);

    if (!isset($checkoutData['url'])) {
        error_log('Stripe checkout error: ' . json_encode($checkoutData));
        json_response(false, null, 'Napaka pri kreiranju plačila.', 500);
    }

    json_response(true, ['url' => $checkoutData['url']]);
}

// ─── POST: customer portal (upravljanje naročnine) ────────────
if ($method === 'POST' && $action === 'customer_portal') {
    $userId = (int)$session['user_id'];
    $sub    = get_active_subscription($pdo, $userId);

    if (!$sub || !$sub['stripe_customer_id']) {
        json_response(false, null, 'Ni aktivne Stripe naročnine.', 400);
    }

    $portal = stripe_request('POST', '/billing_portal/sessions', [
        'customer'   => $sub['stripe_customer_id'],
        'return_url' => APP_URL . BASE_PATH . '/pages/billing.php',
    ]);

    if (!isset($portal['url'])) {
        json_response(false, null, 'Napaka pri odpiranju portala.', 500);
    }

    json_response(true, ['url' => $portal['url']]);
}

json_response(false, null, 'Neznan action.', 400);

// ─── Stripe HTTP helper ───────────────────────────────────────
function stripe_request(string $method, string $endpoint, array $data = []): array {
    $url = 'https://api.stripe.com/v1' . $endpoint;

    // Stripe pričakuje nested arrays kot foo[bar]=baz
    $payload = stripe_encode($data);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true) ?? [];
}

function stripe_encode(array $data, string $prefix = ''): string {
    $parts = [];
    foreach ($data as $key => $value) {
        $fullKey = $prefix ? "{$prefix}[{$key}]" : $key;
        if (is_array($value)) {
            // Indexed arrays (line_items[0][price])
            foreach ($value as $i => $v) {
                if (is_array($v)) {
                    $parts[] = stripe_encode($v, "{$fullKey}[{$i}]");
                } else {
                    $parts[] = urlencode("{$fullKey}[{$i}]") . '=' . urlencode($v);
                }
            }
        } else {
            $parts[] = urlencode($fullKey) . '=' . urlencode((string)$value);
        }
    }
    return implode('&', $parts);
}
