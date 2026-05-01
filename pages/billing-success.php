<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/stripe_helper.php';
require_once '../includes/racunhub.php';
require_once '../includes/lang.php';

if (!is_logged_in()) redirect_to_login();
if ($_SESSION['role'] !== 'admin') {
    header('Location: ' . BASE_PATH . '/pages/main.php'); exit;
}

$pdo = getDB();

// ── Verificiraj Stripe checkout session in aktiviraj naročnino ──
$sessionId = trim($_GET['session_id'] ?? '');
if ($sessionId) {
    try {
        $cs           = stripe_request('GET', "/checkout/sessions/{$sessionId}");
        $csStatus     = $cs['status']         ?? '';
        $subId        = $cs['subscription']   ?? null;
        $meta         = $cs['metadata']       ?? [];
        $userId       = (int)($meta['user_id']       ?? 0);
        $planSlug     = $meta['plan_slug']     ?? 'basic';
        $billingCycle = $meta['billing_cycle'] ?? 'monthly';

        if ($csStatus === 'complete' && $userId && $subId) {

            // Preveri ali webhook ni že aktiviral naročnine
            $activeStmt = $pdo->prepare("SELECT id FROM subscriptions WHERE stripe_subscription_id = ? AND status = 'active' LIMIT 1");
            $activeStmt->execute([$subId]);
            $alreadyActive = $activeStmt->fetchColumn();

            // Pridobi Stripe subscription (potrebujemo ends_at + latest_invoice)
            $stripeSub      = stripe_request('GET', "/subscriptions/{$subId}");
            $endsAt         = isset($stripeSub['current_period_end'])
                ? date('Y-m-d H:i:s', (int)$stripeSub['current_period_end'])
                : null;
            $customerId     = $stripeSub['customer'] ?? null;
            $latestInvoice  = $stripeSub['latest_invoice'] ?? null;

            if (!$alreadyActive) {
                $pdo->prepare("UPDATE subscriptions SET status = 'canceled' WHERE user_id = ? AND status IN ('trial','active','pending_invoice')")
                    ->execute([$userId]);

                $pdo->prepare("
                    INSERT INTO subscriptions
                        (user_id, plan_slug, status, billing_cycle, payment_method, ends_at, stripe_subscription_id, stripe_customer_id)
                    VALUES (?, ?, 'active', ?, 'stripe', ?, ?, ?)
                ")->execute([$userId, $planSlug, $billingCycle, $endsAt, $subId, $customerId]);

                $pdo->prepare("UPDATE users SET subscription_status = 'active' WHERE id = ?")
                    ->execute([$userId]);
            }

            // ── Hub račun (idempotency = stripe invoice ID → brez duplikatov z webhookom) ──
            try {
                $amountPaid      = 0;
                $stripeInvoiceId = '';

                if ($latestInvoice) {
                    $inv             = stripe_request('GET', "/invoices/{$latestInvoice}");
                    $amountPaid      = (int)($inv['amount_paid'] ?? 0);
                    $stripeInvoiceId = $inv['id'] ?? '';
                }
                // Fallback na katalog cen
                if (!$amountPaid) {
                    $amountPaid = (int)(PLANS[$planSlug][$billingCycle . '_price'] * 100);
                }

                $userStmt = $pdo->prepare("SELECT full_name, email, company_name, company_address, tax_number, is_vat_registered, vat_id FROM users WHERE id = ? LIMIT 1");
                $userStmt->execute([$userId]);
                $userData = $userStmt->fetch();

                if ($userData && $stripeInvoiceId) {
                    $planName = PLANS[$planSlug]['name'] ?? ucfirst($planSlug);
                    $cycleTag = $billingCycle === 'yearly' ? 'letno' : 'mesecno';
                    $today    = date('Y-m-d');

                    $clientData = hub_build_client($userData);

                    $hub        = get_racunhub();
                    $hubInvoice = $hub->createInvoice('sub-invoice-' . $stripeInvoiceId, [
                        'client' => $clientData,
                        'issue_date'     => $today,
                        'due_date'       => $today,
                        'items'          => [[
                            'description' => "Naročnina Rezervacije – {$planName} ({$cycleTag})",
                            'quantity'    => 1,
                            'unit'        => 'kos',
                            'unit_price'  => $amountPaid / 100,
                        ]],
                        'source_app'     => 'rezervacije',
                        'reference'      => $stripeInvoiceId,
                        'payment_method' => 'stripe',
                        'mark_paid'      => true,
                        'paid_date'      => $today,
                        'category'       => 'Rezble',
                        'tags'           => [$cycleTag, $planSlug],
                    ]);

                    if (!empty($hubInvoice['id'])) {
                        $localSubStmt = $pdo->prepare("SELECT id FROM subscriptions WHERE stripe_subscription_id = ? LIMIT 1");
                        $localSubStmt->execute([$subId]);
                        $localSubIdForInv = (int)($localSubStmt->fetchColumn() ?: 0);

                        $pdo->prepare("INSERT IGNORE INTO subscription_invoices
                            (user_id, subscription_id, hub_invoice_id, stripe_invoice_id, plan_slug, billing_cycle)
                            VALUES (?, ?, ?, ?, ?, ?)")
                            ->execute([$userId, $localSubIdForInv, $hubInvoice['id'], $stripeInvoiceId, $planSlug, $billingCycle]);

                        // Ohrani tudi na subscription vrstici (backwards compat)
                        if ($localSubIdForInv) {
                            $pdo->prepare("UPDATE subscriptions SET hub_invoice_id = ? WHERE id = ?")
                                ->execute([$hubInvoice['id'], $localSubIdForInv]);
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('billing-success Hub error: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('billing-success Stripe verify error: ' . $e->getMessage());
    }
}

// Razveljavi session cache da se naroč. takoj osveži
unset($_SESSION['_sub_cached_at']);
refresh_subscription_session($pdo);

$sub      = get_active_subscription($pdo, (int)$_SESSION['user_id']);
$planName = PLANS[$sub['plan_slug'] ?? 'basic']['name'] ?? 'paket';
$fullName = $_SESSION['full_name'];
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('billing_success.page_title') ?> – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
</head>
<body>
<header class="app-header">
    <a href="<?= BASE_PATH ?>/pages/main.php" class="header-logo">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
            <rect width="28" height="28" rx="7" fill="#F59E0B"/>
            <path d="M7 10h14M7 14h14M7 18h9" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <?= h(APP_NAME) ?>
    </a>
    <div class="header-actions">
        <span class="header-user">👤 <?= h($fullName) ?></span>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout"><?= t('billing_success.logout') ?></a>
    </div>
</header>

<div style="display:flex;align-items:center;justify-content:center;min-height:calc(100vh - 64px);padding:24px">
    <div style="text-align:center;max-width:440px">
        <div style="width:64px;height:64px;background:#D1FAE5;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h1 style="font-size:1.5rem;font-weight:700;color:#111827;margin:0 0 10px"><?= t('billing_success.page_title') ?></h1>
        <p style="color:#6B7280;margin:0 0 8px"><?= t('billing_success.plan_prefix') ?> <strong><?= h($planName) ?></strong> <?= t('billing_success.plan_activated') ?></p>
        <p style="color:#6B7280;font-size:.875rem;margin:0 0 28px"><?= t('billing_success.thank_you') ?></p>
        <a href="<?= BASE_PATH ?>/pages/main.php" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            <?= t('billing_success.go_schedule') ?>
        </a>
    </div>
</div>
</body>
</html>
