<?php
/**
 * Stripe Webhook Handler
 * URL: /rezervacije-saas/api/stripe-webhook.php
 * Registriraj v Stripe Dashboard → Developers → Webhooks
 */
require_once '../config.php';
require_once '../includes/db.php';

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

    // Naročnina preklicana (admin prekliče v portalu)
    case 'customer.subscription.deleted':
        handle_subscription_deleted($pdo, $event['data']['object']);
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
        $sub = stripe_get("/subscriptions/{$subscriptionId}");
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
    $sub = stripe_get("/subscriptions/{$subscriptionId}");
    if (!isset($sub['current_period_end'], $sub['metadata']['user_id'])) return;

    $endsAt = date('Y-m-d H:i:s', (int)$sub['current_period_end']);
    $userId = (int)$sub['metadata']['user_id'];

    $pdo->prepare("
        UPDATE subscriptions SET status = 'active', ends_at = ?
        WHERE stripe_subscription_id = ?
    ")->execute([$endsAt, $subscriptionId]);

    $pdo->prepare("UPDATE users SET subscription_status = 'active' WHERE id = ?")
        ->execute([$userId]);
}

function handle_invoice_failed(PDO $pdo, array $invoice): void {
    $subscriptionId = $invoice['subscription'] ?? null;
    if (!$subscriptionId) return;

    $pdo->prepare("
        UPDATE subscriptions SET status = 'payment_failed'
        WHERE stripe_subscription_id = ?
    ")->execute([$subscriptionId]);
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

// ─── Stripe helpers ───────────────────────────────────────────

function stripe_get(string $endpoint): array {
    $ch = curl_init('https://api.stripe.com/v1' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?? [];
}

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
