<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/stripe_helper.php';
require_once '../includes/discount_helper.php';

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
    $discountCode = strtoupper(trim($body['discount_code'] ?? ''));

    if (!isset(STRIPE_PRICES[$planSlug])) {
        json_response(false, null, 'Neveljaven paket.', 400);
    }
    if (!in_array($billingCycle, ['monthly', 'yearly'])) {
        json_response(false, null, 'Neveljaven billing cycle.', 400);
    }

    $priceId = STRIPE_PRICES[$planSlug][$billingCycle];
    $userId  = (int)$session['user_id'];
    $email   = $session['email'] ?? '';

    // Discount code ima prednost pred plan_discounts; ne stackamo
    $couponId      = null;
    $promoCodeId   = null;  // Stripe promotion_code ID (za discount kode)
    $appliedCodeId = null;  // local discount_codes.id (za redemption tracking)

    if ($discountCode !== '') {
        $valid = validate_discount_code($pdo, $discountCode, $email, $planSlug, $userId);
        if (!$valid['valid']) {
            json_response(false, null, $valid['error'], 400);
        }
        $promoCodeId   = $valid['stripe_promo_id'];
        $appliedCodeId = $valid['id'];
    } else {
        // Fallback na plan_discounts (kampanjski popusti)
        $discount = get_active_discount($pdo, $planSlug);
        if ($discount) {
            $discPrice = $billingCycle === 'yearly'
                ? ($discount['discounted_yearly']  ?? null)
                : ($discount['discounted_monthly'] ?? null);
            $origPrice = PLANS[$planSlug][$billingCycle . '_price'];
            if ($discPrice !== null && (float)$discPrice < (float)$origPrice) {
                $amountOff = (int)round(((float)$origPrice - (float)$discPrice) * 100);
                $coupon = stripe_request('POST', '/coupons', [
                    'amount_off' => $amountOff,
                    'currency'   => 'eur',
                    'duration'   => 'once',
                    'name'       => $discount['label'],
                ]);
                if (isset($coupon['id'])) {
                    $couponId = $coupon['id'];
                } else {
                    error_log('Stripe coupon creation failed: ' . json_encode($coupon));
                }
            }
        }
    }

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

    $checkoutParams = [
        'customer'             => $customerId,
        'payment_method_types' => ['card'],
        'mode'                 => 'subscription',
        'line_items'           => [[
            'price'    => $priceId,
            'quantity' => 1,
        ]],
        'success_url'          => $baseUrl . '/pages/billing-success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'           => $baseUrl . '/pages/billing.php?canceled=1',
        'locale'               => 'sl',
        'subscription_data'    => [
            'metadata' => [
                'user_id'           => $userId,
                'plan_slug'         => $planSlug,
                'billing_cycle'     => $billingCycle,
                'discount_code_id'  => $appliedCodeId ?? '',
            ],
        ],
        'metadata' => [
            'user_id'           => $userId,
            'plan_slug'         => $planSlug,
            'billing_cycle'     => $billingCycle,
            'discount_code_id'  => $appliedCodeId ?? '',
        ],
    ];

    if ($promoCodeId) {
        $checkoutParams['discounts'] = [['promotion_code' => $promoCodeId]];
    } elseif ($couponId) {
        $checkoutParams['discounts'] = [['coupon' => $couponId]];
    }

    $checkoutData = stripe_request('POST', '/checkout/sessions', $checkoutParams);

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

// ─── POST: preklop paketa med trialom (brez plačila) ─────────
if ($method === 'POST' && $action === 'switch_trial_plan') {
    $newPlanSlug = $body['plan_slug'] ?? '';
    $validPlans  = ['basic', 'advanced', 'premium'];

    if (!in_array($newPlanSlug, $validPlans)) {
        json_response(false, null, 'Neveljaven paket.', 400);
    }

    $userId = (int)$session['user_id'];
    $sub    = get_active_subscription($pdo, $userId);

    if (!$sub || $sub['status'] !== 'trial') {
        json_response(false, null, 'Preklop je možen samo med brezplačnim trialom.', 400);
    }
    if ($sub['plan_slug'] === $newPlanSlug) {
        json_response(true, ['plan_slug' => $newPlanSlug], 'Paket je že aktiven.');
    }

    $pdo->prepare("UPDATE subscriptions SET plan_slug = ? WHERE id = ?")
        ->execute([$newPlanSlug, $sub['id']]);

    // Razveljavi session cache za takojšen efekt
    unset($_SESSION['_sub_cached_at']);

    json_response(true, ['plan_slug' => $newPlanSlug], 'Paket uspešno preklopljen na ' . PLANS[$newPlanSlug]['name'] . '.');
}

// ─── POST: nadgradnja plačljive naročnine s proracijo ─────────
if ($method === 'POST' && $action === 'upgrade_plan') {
    $newPlanSlug = $body['plan_slug'] ?? '';
    $validPlans  = ['basic', 'advanced', 'premium'];

    if (!in_array($newPlanSlug, $validPlans)) {
        json_response(false, null, 'Neveljaven paket.', 400);
    }

    $userId = (int)$session['user_id'];
    $sub    = get_active_subscription($pdo, $userId);

    if (!$sub || $sub['status'] !== 'active') {
        json_response(false, null, 'Nadgradnja je možna samo za aktivne plačljive naročnine.', 400);
    }
    if (get_plan_rank($newPlanSlug) <= get_plan_rank($sub['plan_slug'])) {
        json_response(false, null, 'Izberite višji paket za nadgradnjo.', 400);
    }
    if (!$sub['stripe_subscription_id']) {
        json_response(false, null, 'Naročnina ni Stripe naročnina. Kontaktirajte podporo.', 400);
    }

    // Pridobi subscription item ID iz Stripe
    $stripeSub = stripe_request('GET', '/subscriptions/' . $sub['stripe_subscription_id']);
    $itemId    = $stripeSub['items']['data'][0]['id'] ?? null;
    if (!$itemId) {
        error_log('upgrade_plan: cannot find subscription item for ' . $sub['stripe_subscription_id']);
        json_response(false, null, 'Napaka pri pridobivanju podatkov naročnine.', 500);
    }

    $billingCycle = $sub['billing_cycle'] ?? 'monthly';
    $priceId      = STRIPE_PRICES[$newPlanSlug][$billingCycle] ?? null;
    if (!$priceId) {
        json_response(false, null, 'Cena za ta paket ni konfigurirana.', 500);
    }

    // Posodobi Stripe naročnino – proration se ustvari samodejno
    $updated = stripe_request('POST', '/subscriptions/' . $sub['stripe_subscription_id'], [
        'items' => [[
            'id'    => $itemId,
            'price' => $priceId,
        ]],
        'proration_behavior'    => 'create_prorations',
        'metadata' => [
            'plan_slug'     => $newPlanSlug,
            'billing_cycle' => $billingCycle,
        ],
    ]);

    if (isset($updated['error'])) {
        error_log('Stripe upgrade error: ' . json_encode($updated));
        json_response(false, null, 'Napaka pri nadgradnji pri Stripe: ' . ($updated['error']['message'] ?? 'Neznana napaka.'), 500);
    }

    // Posodobi plan_slug v naši bazi
    $pdo->prepare("UPDATE subscriptions SET plan_slug = ? WHERE id = ?")
        ->execute([$newPlanSlug, $sub['id']]);

    unset($_SESSION['_sub_cached_at']);

    json_response(true, ['plan_slug' => $newPlanSlug], 'Paket uspešno nadgrajen na ' . PLANS[$newPlanSlug]['name'] . '. Sorazmerni znesek bo zaračunan na vaš plačilni način.');
}

// ─── POST: zahtevek za predračun (letno plačilo) ─────────────
if ($method === 'POST' && $action === 'request_invoice') {
    require_once '../includes/mailer.php';

    $planSlug     = $body['plan_slug'] ?? '';
    $billingCycle = $body['billing_cycle'] ?? '';

    if (!in_array($planSlug, ['basic', 'advanced', 'premium'])) {
        json_response(false, null, 'Neveljaven paket.', 400);
    }
    if ($billingCycle !== 'yearly') {
        json_response(false, null, 'Predračun je možen samo za letno plačilo.', 400);
    }

    $userId = (int)$session['user_id'];
    $email  = $session['email'] ?? '';
    $name   = $session['full_name'] ?? '';

    $sub = get_active_subscription($pdo, $userId);
    if ($sub && $sub['status'] === 'active' && $sub['plan_slug'] === $planSlug) {
        json_response(false, null, 'Že imate aktivno naročnino tega paketa.', 400);
    }
    if ($sub && $sub['status'] === 'pending_invoice' && $sub['plan_slug'] === $planSlug) {
        json_response(false, null, 'Zahtevek za ta paket je že bil poslan. Kmalu vas bomo kontaktirali.', 400);
    }

    try {
        $pdo->prepare("UPDATE subscriptions SET status = 'canceled' WHERE user_id = ? AND status IN ('trial','pending_invoice')")
            ->execute([$userId]);

        $pdo->prepare("
            INSERT INTO subscriptions (user_id, plan_slug, status, billing_cycle, payment_method)
            VALUES (?, ?, 'pending_invoice', 'yearly', 'invoice')
        ")->execute([$userId, $planSlug]);

        $superadminEmail = $pdo->query("SELECT email FROM users WHERE role = 'superadmin' LIMIT 1")->fetchColumn();
        if ($superadminEmail) {
            $yearlyPrice = PLANS[$planSlug]['yearly_price'];
            send_invoice_request_email($superadminEmail, $name, $email, $planSlug, $yearlyPrice);
        }

        json_response(true, null, 'Zahtevek poslan! Kontaktirali vas bomo v kratkem s predračunom.');
    } catch (PDOException $e) {
        error_log('request_invoice error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri oddaji zahtevka.', 500);
    }
}

json_response(false, null, 'Neznan action.', 400);

// stripe_request() and stripe_encode() are defined in includes/stripe_helper.php
