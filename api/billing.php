<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/stripe_helper.php';
require_once '../includes/discount_helper.php';
require_once '../includes/racunhub.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_admin();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];
$body    = $method === 'POST' ? get_body() : [];
$action  = $body['action'] ?? ($_GET['action'] ?? '');

// ─── GET: seznam Hub računov za trenutnega uporabnika ─────────
if ($method === 'GET' && $action === 'invoice_list') {
    $userId = (int)$session['user_id'];

    try {
        $stmt = $pdo->prepare("
            SELECT hub_invoice_id, plan_slug, billing_cycle, created_at
            FROM subscription_invoices
            WHERE user_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        // Fallback: migracija še ni pognana – beri iz stare tabele
        try {
            $stmt = $pdo->prepare("
                SELECT hub_invoice_id, plan_slug, billing_cycle, created_at
                FROM subscriptions
                WHERE user_id = ? AND hub_invoice_id IS NOT NULL
                ORDER BY created_at DESC
            ");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e2) {
            json_response(true, []);
        }
    }

    $invoices = [];
    $hub      = get_racunhub();
    foreach ($rows as $row) {
        try {
            $inv = $hub->getInvoice($row['hub_invoice_id']);
            $invoices[] = [
                'hub_invoice_id' => $row['hub_invoice_id'],
                'date'           => $inv['issue_date']    ?? substr($row['created_at'], 0, 10),
                'number'         => $inv['number']        ?? null,
                'plan_slug'      => $row['plan_slug'],
                'billing_cycle'  => $row['billing_cycle'],
                'total'          => $inv['total']         ?? null,
                'status'         => $inv['status']        ?? 'unknown',
                'has_pdf'        => !empty($inv['pdf_url']),
            ];
        } catch (Throwable $e) {
            $invoices[] = [
                'hub_invoice_id' => $row['hub_invoice_id'],
                'date'           => substr($row['created_at'], 0, 10),
                'number'         => null,
                'plan_slug'      => $row['plan_slug'],
                'billing_cycle'  => $row['billing_cycle'],
                'total'          => null,
                'status'         => 'unknown',
                'has_pdf'        => false,
            ];
        }
    }

    json_response(true, $invoices);
}

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

    // Potrditveni email (trial – brez plačila)
    try {
        require_once '../includes/mailer.php';
        $planName = PLANS[$newPlanSlug]['name'] ?? ucfirst($newPlanSlug);
        send_plan_changed_email($session['email'] ?? '', $session['full_name'] ?? '', $planName, 'monthly', 0.0);
    } catch (Throwable $e) {
        error_log('switch_trial_plan email error: ' . $e->getMessage());
    }

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

    // Uporabnik lahko ob nadgradnji hkrati preklopi na letno plačilo
    $requestedCycle = $body['billing_cycle'] ?? null;
    $billingCycle   = in_array($requestedCycle, ['monthly', 'yearly'])
        ? $requestedCycle
        : ($sub['billing_cycle'] ?? 'monthly');

    $priceId = STRIPE_PRICES[$newPlanSlug][$billingCycle] ?? null;
    if (!$priceId) {
        json_response(false, null, 'Cena za ta paket ni konfigurirana.', 500);
    }

    // Posodobi Stripe naročnino z always_invoice → takoj zaračuna proration
    $updated = stripe_request('POST', '/subscriptions/' . $sub['stripe_subscription_id'], [
        'items' => [[
            'id'    => $itemId,
            'price' => $priceId,
        ]],
        'proration_behavior' => 'always_invoice',
        'metadata' => [
            'user_id'       => (string)$userId,
            'plan_slug'     => $newPlanSlug,
            'billing_cycle' => $billingCycle,
        ],
    ]);

    if (isset($updated['error'])) {
        error_log('Stripe upgrade error: ' . json_encode($updated));
        json_response(false, null, 'Napaka pri nadgradnji pri Stripe: ' . ($updated['error']['message'] ?? 'Neznana napaka.'), 500);
    }

    // Posodobi plan_slug in billing_cycle takoj (webhook bo osvežil ends_at)
    $pdo->prepare("UPDATE subscriptions SET plan_slug = ?, billing_cycle = ? WHERE id = ?")
        ->execute([$newPlanSlug, $billingCycle, $sub['id']]);

    unset($_SESSION['_sub_cached_at']);

    $cycleLabel = $billingCycle === 'yearly' ? 'letno' : 'mesečno';
    json_response(true, ['plan_slug' => $newPlanSlug], 'Paket uspešno nadgrajen na ' . PLANS[$newPlanSlug]['name'] . ' (' . $cycleLabel . '). Sorazmerni znesek bo zaračunan na vaš plačilni način.');
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

// ─── POST: preklop iz mesečnega na letno plačilo ─────────────
if ($method === 'POST' && $action === 'switch_to_yearly') {
    $userId = (int)$session['user_id'];
    $sub    = get_active_subscription($pdo, $userId);

    if (!$sub || $sub['status'] !== 'active') {
        json_response(false, null, 'Ni aktivne naročnine.', 400);
    }
    if (($sub['billing_cycle'] ?? '') !== 'monthly') {
        json_response(false, null, 'Naročnina je že letna.', 400);
    }
    if (($sub['payment_method'] ?? '') !== 'stripe' || !$sub['stripe_subscription_id']) {
        json_response(false, null, 'Preklop je možen samo za Stripe naročnine.', 400);
    }

    $planSlug = $sub['plan_slug'];
    $priceId  = STRIPE_PRICES[$planSlug]['yearly'] ?? null;
    if (!$priceId) {
        json_response(false, null, 'Letna cena za ta paket ni konfigurirana.', 500);
    }

    $stripeSub = stripe_request('GET', '/subscriptions/' . $sub['stripe_subscription_id']);
    $itemId    = $stripeSub['items']['data'][0]['id'] ?? null;
    if (!$itemId) {
        error_log('switch_to_yearly: cannot find subscription item for ' . $sub['stripe_subscription_id']);
        json_response(false, null, 'Napaka pri pridobivanju podatkov naročnine.', 500);
    }

    $updated = stripe_request('POST', '/subscriptions/' . $sub['stripe_subscription_id'], [
        'items' => [[
            'id'    => $itemId,
            'price' => $priceId,
        ]],
        'proration_behavior' => 'always_invoice',
        'metadata' => [
            'user_id'       => (string)$userId,
            'plan_slug'     => $planSlug,
            'billing_cycle' => 'yearly',
        ],
    ]);

    if (isset($updated['error'])) {
        error_log('Stripe switch_to_yearly error: ' . json_encode($updated));
        json_response(false, null, 'Napaka pri preklopu pri Stripe: ' . ($updated['error']['message'] ?? 'Neznana napaka.'), 500);
    }

    $pdo->prepare("UPDATE subscriptions SET billing_cycle = 'yearly' WHERE id = ?")
        ->execute([$sub['id']]);

    unset($_SESSION['_sub_cached_at']);

    $planName = PLANS[$planSlug]['name'] ?? ucfirst($planSlug);
    json_response(true, null, 'Naročnina ' . $planName . ' uspešno preklopljena na letno plačilo. Razlika bo zaračunana na vaš plačilni način.');
}

// ─── POST: znižanje plačljive naročnine (konec période) ──────
if ($method === 'POST' && $action === 'downgrade_plan') {
    $newPlanSlug = $body['plan_slug'] ?? '';
    $validPlans  = ['basic', 'advanced', 'premium'];

    if (!in_array($newPlanSlug, $validPlans)) {
        json_response(false, null, 'Neveljaven paket.', 400);
    }

    $userId = (int)$session['user_id'];
    $sub    = get_active_subscription($pdo, $userId);

    if (!$sub || $sub['status'] !== 'active') {
        json_response(false, null, 'Ni aktivne naročnine.', 400);
    }
    if (get_plan_rank($newPlanSlug) >= get_plan_rank($sub['plan_slug'])) {
        json_response(false, null, 'Za znižanje izberite nižji paket.', 400);
    }
    if (!$sub['stripe_subscription_id']) {
        json_response(false, null, 'Naročnina ni Stripe naročnina. Kontaktirajte podporo.', 400);
    }

    $billingCycle = $sub['billing_cycle'] ?? 'monthly';
    $priceId = STRIPE_PRICES[$newPlanSlug][$billingCycle] ?? null;
    if (!$priceId) {
        json_response(false, null, 'Cena za ta paket ni konfigurirana.', 500);
    }

    $stripeSub = stripe_request('GET', '/subscriptions/' . $sub['stripe_subscription_id']);
    $itemId    = $stripeSub['items']['data'][0]['id'] ?? null;
    if (!$itemId) {
        error_log('downgrade_plan: cannot find subscription item for ' . $sub['stripe_subscription_id']);
        json_response(false, null, 'Napaka pri pridobivanju podatkov naročnine.', 500);
    }

    // proration_behavior none → brez takojšnjega zaračuna, sprememba velja od naslednjega cikla
    $updated = stripe_request('POST', '/subscriptions/' . $sub['stripe_subscription_id'], [
        'items' => [[
            'id'    => $itemId,
            'price' => $priceId,
        ]],
        'proration_behavior' => 'none',
        'metadata' => [
            'user_id'       => (string)$userId,
            'plan_slug'     => $newPlanSlug,
            'billing_cycle' => $billingCycle,
        ],
    ]);

    if (isset($updated['error'])) {
        error_log('Stripe downgrade error: ' . json_encode($updated));
        json_response(false, null, 'Napaka pri Stripe: ' . ($updated['error']['message'] ?? 'Neznana napaka.'), 500);
    }

    // Shrani načrtovano spremembo; plan_slug ostane nespremenjen do podaljšanja
    try {
        $pdo->prepare("UPDATE subscriptions SET pending_plan_slug = ?, pending_billing_cycle = NULL WHERE id = ?")
            ->execute([$newPlanSlug, $sub['id']]);
    } catch (PDOException $e) {
        error_log('downgrade_plan DB error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju. Preverite, da je SQL migracija za pending_plan_slug poignana.', 500);
    }

    unset($_SESSION['_sub_cached_at']);

    $planName   = PLANS[$newPlanSlug]['name'] ?? ucfirst($newPlanSlug);
    $endsAtFmt  = $sub['ends_at'] ? date('j. n. Y', strtotime($sub['ends_at'])) : '';
    $cycleLabel = $billingCycle === 'yearly' ? 'letno' : 'mesečno';
    $newPrice   = number_format((float)(PLANS[$newPlanSlug][$billingCycle . '_price'] ?? 0), 2, ',', '.');

    json_response(true, ['pending_plan_slug' => $newPlanSlug],
        "Znižanje na {$planName} je načrtovano za {$endsAtFmt}. Do takrat ostaja vaš trenutni paket aktiven. Od {$endsAtFmt} naprej: {$newPrice} € / {$cycleLabel}.");
}

// ─── POST: preklop na mesečno ob koncu période (letna → mesečna) ─
if ($method === 'POST' && $action === 'switch_to_monthly') {
    $userId = (int)$session['user_id'];
    $sub    = get_active_subscription($pdo, $userId);

    if (!$sub || $sub['status'] !== 'active') {
        json_response(false, null, 'Ni aktivne naročnine.', 400);
    }
    if (($sub['billing_cycle'] ?? '') !== 'yearly') {
        json_response(false, null, 'Naročnina je že mesečna.', 400);
    }
    if (!$sub['stripe_subscription_id']) {
        json_response(false, null, 'Preklop je možen samo za Stripe naročnine.', 400);
    }

    $planSlug = $sub['plan_slug'];
    $priceId  = STRIPE_PRICES[$planSlug]['monthly'] ?? null;
    if (!$priceId) {
        json_response(false, null, 'Mesečna cena za ta paket ni konfigurirana.', 500);
    }

    $stripeSub = stripe_request('GET', '/subscriptions/' . $sub['stripe_subscription_id']);
    $itemId    = $stripeSub['items']['data'][0]['id'] ?? null;
    if (!$itemId) {
        error_log('switch_to_monthly: cannot find subscription item for ' . $sub['stripe_subscription_id']);
        json_response(false, null, 'Napaka pri pridobivanju podatkov naročnine.', 500);
    }

    $updated = stripe_request('POST', '/subscriptions/' . $sub['stripe_subscription_id'], [
        'items' => [[
            'id'    => $itemId,
            'price' => $priceId,
        ]],
        'proration_behavior' => 'none',
        'metadata' => [
            'user_id'       => (string)$userId,
            'plan_slug'     => $planSlug,
            'billing_cycle' => 'monthly',
        ],
    ]);

    if (isset($updated['error'])) {
        error_log('Stripe switch_to_monthly error: ' . json_encode($updated));
        json_response(false, null, 'Napaka pri Stripe: ' . ($updated['error']['message'] ?? 'Neznana napaka.'), 500);
    }

    try {
        $pdo->prepare("UPDATE subscriptions SET pending_billing_cycle = 'monthly', pending_plan_slug = NULL WHERE id = ?")
            ->execute([$sub['id']]);
    } catch (PDOException $e) {
        error_log('switch_to_monthly DB error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju. Preverite, da je SQL migracija za pending_billing_cycle poignana.', 500);
    }

    unset($_SESSION['_sub_cached_at']);

    $planName  = PLANS[$planSlug]['name'] ?? ucfirst($planSlug);
    $endsAtFmt = $sub['ends_at'] ? date('j. n. Y', strtotime($sub['ends_at'])) : '';
    $newPrice  = number_format((float)(PLANS[$planSlug]['monthly_price'] ?? 0), 2, ',', '.');

    json_response(true, ['pending_billing_cycle' => 'monthly'],
        "Preklop na mesečno plačilo načrtovan za {$endsAtFmt}. Vaša letna naročnina {$planName} ostane aktivna do takrat. Od {$endsAtFmt} naprej: {$newPrice} € / mesec.");
}

// ─── POST: preklic načrtovane spremembe ──────────────────────
if ($method === 'POST' && $action === 'cancel_pending_downgrade') {
    $userId = (int)$session['user_id'];
    $sub    = get_active_subscription($pdo, $userId);

    if (!$sub || $sub['status'] !== 'active') {
        json_response(false, null, 'Ni aktivne naročnine.', 400);
    }
    if (!$sub['stripe_subscription_id']) {
        json_response(false, null, 'Naročnina ni Stripe naročnina.', 400);
    }
    $pendingPlan  = $sub['pending_plan_slug']     ?? null;
    $pendingCycle = $sub['pending_billing_cycle'] ?? null;
    if (!$pendingPlan && !$pendingCycle) {
        json_response(false, null, 'Ni načrtovane spremembe za preklic.', 400);
    }

    // Obnovi izvirno ceno v Stripe
    $planSlug     = $sub['plan_slug'];
    $billingCycle = $sub['billing_cycle'] ?? 'monthly';
    $priceId = STRIPE_PRICES[$planSlug][$billingCycle] ?? null;
    if (!$priceId) {
        json_response(false, null, 'Cena za ta paket ni konfigurirana.', 500);
    }

    $stripeSub = stripe_request('GET', '/subscriptions/' . $sub['stripe_subscription_id']);
    $itemId    = $stripeSub['items']['data'][0]['id'] ?? null;
    if (!$itemId) {
        json_response(false, null, 'Napaka pri pridobivanju podatkov naročnine.', 500);
    }

    $updated = stripe_request('POST', '/subscriptions/' . $sub['stripe_subscription_id'], [
        'items' => [[
            'id'    => $itemId,
            'price' => $priceId,
        ]],
        'proration_behavior' => 'none',
        'metadata' => [
            'user_id'       => (string)$userId,
            'plan_slug'     => $planSlug,
            'billing_cycle' => $billingCycle,
        ],
    ]);

    if (isset($updated['error'])) {
        error_log('Stripe cancel_pending error: ' . json_encode($updated));
        json_response(false, null, 'Napaka pri Stripe: ' . ($updated['error']['message'] ?? 'Neznana napaka.'), 500);
    }

    try {
        $pdo->prepare("UPDATE subscriptions SET pending_plan_slug = NULL, pending_billing_cycle = NULL WHERE id = ?")
            ->execute([$sub['id']]);
    } catch (PDOException $e) {
        error_log('cancel_pending DB error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju. Preverite, da je SQL migracija poignana.', 500);
    }

    unset($_SESSION['_sub_cached_at']);

    json_response(true, null, 'Načrtovana sprememba je bila preklicana. Vaša naročnina ostane nespremenjena.');
}

// ─── GET: proxy PDF prenosa (Hub zahteva Bearer token) ───────
if ($method === 'GET' && $action === 'download_pdf') {
    $userId       = (int)$session['user_id'];
    $hubInvoiceId = trim($_GET['invoice_id'] ?? '');

    if (!$hubInvoiceId) {
        json_response(false, null, 'Manjka invoice_id.', 400);
    }

    // Preveri da račun pripada temu uporabniku (nova tabela, fallback na staro)
    $owned = false;
    try {
        $stmt = $pdo->prepare("SELECT id FROM subscription_invoices WHERE user_id = ? AND hub_invoice_id = ? LIMIT 1");
        $stmt->execute([$userId, $hubInvoiceId]);
        $owned = (bool)$stmt->fetchColumn();
    } catch (Throwable $_) {}
    if (!$owned) {
        $stmt = $pdo->prepare("SELECT id FROM subscriptions WHERE user_id = ? AND hub_invoice_id = ? LIMIT 1");
        $stmt->execute([$userId, $hubInvoiceId]);
        $owned = (bool)$stmt->fetchColumn();
    }
    if (!$owned) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    // Pridobi pdf_url iz Huba
    $hub    = get_racunhub();
    $inv    = $hub->getInvoice($hubInvoiceId);
    $pdfUrl = $inv['pdf_url'] ?? null;

    if (!$pdfUrl) {
        json_response(false, null, 'PDF ni na voljo.', 404);
    }

    // Prenesi PDF z Bearer autentikacijo in posreduj brskalnik
    $ch = curl_init($pdfUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . HUB_API_KEY],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $pdfData  = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($pdfData === false || $httpCode >= 400) {
        json_response(false, null, 'Napaka pri prenašanju PDF.', 502);
    }

    $filename = 'racun-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $hubInvoiceId) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfData));
    header('Cache-Control: private, max-age=0');
    echo $pdfData;
    exit;
}

json_response(false, null, 'Neznan action.', 400);

// stripe_request() and stripe_encode() are defined in includes/stripe_helper.php
