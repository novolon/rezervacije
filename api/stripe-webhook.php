<?php
/**
 * Stripe Webhook Handler
 * URL: /rezervacije-saas/api/stripe-webhook.php
 * Registriraj v Stripe Dashboard → Developers → Webhooks
 */
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/stripe_helper.php';
require_once '../includes/affiliate_helper.php';
require_once '../includes/discount_helper.php';

// Raw payload pred session startom
$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// ─── Podpis preverjanje ───────────────────────────────────────
if (STRIPE_WEBHOOK_SECRET) {
    $event = stripe_verify_signature($payload, $sigHeader, STRIPE_WEBHOOK_SECRET);
    if (!$event) {
        http_response_code(400);
        exit('Invalid signature');
    }
} else {
    // Brez webhook secret (dev/test brez CLI) – samo dekodiraj
    $event = json_decode($payload, true);
}

if (empty($event['type'])) {
    http_response_code(400);
    exit('Missing event type');
}

$pdo = getDB();

// ─── Event dispatcher ─────────────────────────────────────────
switch ($event['type']) {

    // Checkout uspešno zaključen
    case 'checkout.session.completed':
        handle_checkout_completed($pdo, $event['data']['object']);
        break;

    // Plačilo naročnine uspešno (mesečno/letno podaljšanje)
    case 'invoice.payment_succeeded':
        handle_invoice_paid($pdo, $event['data']['object']);
        break;

    // Plačilo neuspešno
    case 'invoice.payment_failed':
        handle_invoice_failed($pdo, $event['data']['object']);
        break;

    // Opomnik teden pred plačilom
    case 'invoice.upcoming':
        handle_invoice_upcoming($pdo, $event['data']['object']);
        break;

    // Naročnina preklicana (admin prekliče v portalu)
    case 'customer.subscription.deleted':
        handle_subscription_deleted($pdo, $event['data']['object']);
        break;

    // Povračilo (clawback provizije)
    case 'charge.refunded':
        handle_charge_refunded($pdo, $event['data']['object']);
        break;

    default:
        // Ignoriramo neznane evente
        break;
}

http_response_code(200);
echo json_encode(['received' => true]);

// ─── Handlers ────────────────────────────────────────────────

function handle_checkout_completed(PDO $pdo, array $obj): void {
    $customerId     = $obj['customer']        ?? null;
    $subscriptionId = $obj['subscription']    ?? null;
    $metadata       = $obj['metadata']        ?? [];
    $userId         = isset($metadata['user_id'])       ? (int)$metadata['user_id']   : 0;
    $planSlug       = $metadata['plan_slug']             ?? 'basic';
    $billingCycle   = $metadata['billing_cycle']         ?? 'monthly';

    if (!$userId || !$subscriptionId) return;

    // Pridobi ends_at iz Stripe subscription
    $endsAt = null;
    if ($subscriptionId) {
        $sub = stripe_request('GET', "/subscriptions/{$subscriptionId}");
        if (isset($sub['current_period_end'])) {
            $endsAt = date('Y-m-d H:i:s', (int)$sub['current_period_end']);
        }
    }

    // Deaktiviraj stare naročnine
    $pdo->prepare("UPDATE subscriptions SET status = 'canceled' WHERE user_id = ? AND status IN ('trial','active')")
        ->execute([$userId]);

    // Vstavi novo aktivno naročnino
    $pdo->prepare("
        INSERT INTO subscriptions (user_id, plan_slug, status, billing_cycle, payment_method, ends_at, stripe_subscription_id, stripe_customer_id)
        VALUES (?, ?, 'active', ?, 'stripe', ?, ?, ?)
    ")->execute([$userId, $planSlug, $billingCycle, $endsAt, $subscriptionId, $customerId]);

    // Posodobi users.subscription_status
    $pdo->prepare("UPDATE users SET subscription_status = 'active' WHERE id = ?")
        ->execute([$userId]);
}

function handle_invoice_paid(PDO $pdo, array $invoice): void {
    $subscriptionId = $invoice['subscription'] ?? null;
    if (!$subscriptionId) return;

    // Pridobi novo obdobje
    $sub = stripe_request('GET', "/subscriptions/{$subscriptionId}");
    if (!isset($sub['current_period_end'], $sub['metadata']['user_id'])) return;

    $endsAt = date('Y-m-d H:i:s', (int)$sub['current_period_end']);
    $userId = (int)$sub['metadata']['user_id'];

    $pdo->prepare("
        UPDATE subscriptions SET status = 'active', ends_at = ?
        WHERE stripe_subscription_id = ?
    ")->execute([$endsAt, $subscriptionId]);

    $pdo->prepare("UPDATE users SET subscription_status = 'active' WHERE id = ?")
        ->execute([$userId]);

    // Pridobi lokalen subscription ID
    $localSub = $pdo->prepare("SELECT id FROM subscriptions WHERE stripe_subscription_id = ? LIMIT 1");
    $localSub->execute([$subscriptionId]);
    $localSubId = (int)($localSub->fetchColumn() ?: 0);

    // Affiliate provizija
    affiliate_record_commission($pdo, $userId, $invoice, $localSubId);

    // Discount redemption
    discount_record_redemption($pdo, $invoice, $userId, $localSubId);
}

function handle_invoice_failed(PDO $pdo, array $invoice): void {
    require_once __DIR__ . '/../includes/mailer.php';
    require_once __DIR__ . '/../includes/plans.php';

    $subscriptionId = $invoice['subscription'] ?? null;
    if (!$subscriptionId) return;

    $attemptCount  = (int)($invoice['attempt_count'] ?? 1);
    $amountDue     = (float)(($invoice['amount_due'] ?? 0) / 100);
    $nextAttemptAt = isset($invoice['next_payment_attempt']) ? (int)$invoice['next_payment_attempt'] : null;

    // Status pustimo 'active' – Stripe sam dela retrie, dostop ohranimo.
    // Blokada pride šele ob customer.subscription.deleted.

    // Pridobi user podatke za email
    $stmt = $pdo->prepare("
        SELECT u.email, u.full_name, s.plan_slug
        FROM subscriptions s
        JOIN users u ON s.user_id = u.id
        WHERE s.stripe_subscription_id = ?
        LIMIT 1
    ");
    $stmt->execute([$subscriptionId]);
    $user = $stmt->fetch();
    if (!$user) return;

    $planName = PLANS[$user['plan_slug']]['name'] ?? ucfirst($user['plan_slug']);
    send_payment_failed_email($user['email'], $user['full_name'], $planName, $amountDue, $attemptCount, $nextAttemptAt);
}

function handle_subscription_deleted(PDO $pdo, array $stripeSub): void {
    $subscriptionId = $stripeSub['id']                  ?? null;
    $userId         = $stripeSub['metadata']['user_id'] ?? null;
    if (!$subscriptionId) return;

    $pdo->prepare("
        UPDATE subscriptions SET status = 'canceled', ends_at = NOW()
        WHERE stripe_subscription_id = ?
    ")->execute([$subscriptionId]);

    if ($userId) {
        $pdo->prepare("UPDATE users SET subscription_status = 'inactive' WHERE id = ?")
            ->execute([(int)$userId]);
    }
}

function handle_invoice_upcoming(PDO $pdo, array $invoice): void {
    require_once __DIR__ . '/../includes/mailer.php';
    require_once __DIR__ . '/../includes/plans.php';

    $subscriptionId = $invoice['subscription'] ?? null;
    if (!$subscriptionId) return;

    $amountDue  = (float)(($invoice['amount_due'] ?? 0) / 100);
    $billingAt  = (int)($invoice['period_end'] ?? time() + 604800);

    $stmt = $pdo->prepare("
        SELECT u.email, u.full_name, s.plan_slug
        FROM subscriptions s
        JOIN users u ON s.user_id = u.id
        WHERE s.stripe_subscription_id = ?
        LIMIT 1
    ");
    $stmt->execute([$subscriptionId]);
    $user = $stmt->fetch();
    if (!$user) return;

    $planName = PLANS[$user['plan_slug']]['name'] ?? ucfirst($user['plan_slug']);
    send_upcoming_invoice_email($user['email'], $user['full_name'], $planName, $amountDue, $billingAt);
}

function handle_charge_refunded(PDO $pdo, array $charge): void {
    // Resolve invoice ID from charge (charge → payment_intent → invoice)
    $invoiceId = $charge['invoice'] ?? null;
    if (!$invoiceId && isset($charge['payment_intent'])) {
        $pi = stripe_request('GET', "/payment_intents/{$charge['payment_intent']}");
        $invoiceId = $pi['invoice'] ?? null;
    }
    if (!$invoiceId) return;

    affiliate_handle_refund($pdo, (string)$invoiceId);
}

// ─── Stripe signature verification ───────────────────────────

function stripe_verify_signature(string $payload, string $sigHeader, string $secret): ?array {
    // Format: t=timestamp,v1=signature,...
    $parts = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        $parts[$k][] = $v;
    }

    $timestamp = $parts['t'][0] ?? null;
    $signatures = $parts['v1'] ?? [];

    if (!$timestamp || empty($signatures)) return null;

    // Zavrnemo stare evente (> 5 min)
    if (abs(time() - (int)$timestamp) > 300) return null;

    $signed = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed, $secret);

    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return json_decode($payload, true);
        }
    }

    return null;
}
