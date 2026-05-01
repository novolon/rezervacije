<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/lang.php';

if (!is_logged_in()) redirect_to_login();
if ($_SESSION['role'] === 'superadmin') {
    header('Location: ' . BASE_PATH . '/pages/superadmin.php'); exit;
}
if ($_SESSION['role'] !== 'admin') {
    header('Location: ' . BASE_PATH . '/pages/main.php'); exit;
}

$pdo  = getDB();
refresh_subscription_session($pdo);
$sub  = get_active_subscription($pdo, (int)$_SESSION['user_id']);

$userId      = (int)$_SESSION['user_id'];
$fullName    = $_SESSION['full_name'] ?? '';
$isAdmin     = true;

$stmt = $pdo->prepare("SELECT r.id, r.name, r.color FROM restaurants r JOIN restaurant_admins ra ON r.id = ra.restaurant_id WHERE ra.user_id = ? AND r.is_active = 1 ORDER BY r.name");
$stmt->execute([$userId]);
$restaurants = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations r JOIN restaurant_admins ra ON r.restaurant_id = ra.restaurant_id WHERE ra.user_id = ? AND r.status = 'pending'");
$stmt->execute([$userId]);
$pendingCount = (int)$stmt->fetchColumn();

$currentPlan   = $sub['plan_slug'] ?? 'basic';
if ($currentPlan === 'trial') $currentPlan = 'basic';
$isOnTrial     = is_on_trial($sub);
$isActive      = ($sub['status'] ?? '') === 'active';
$trialDaysLeft = get_trial_days_left($sub);

// Preveri ali ima user kakšen hub_invoice_id (varno – stolpec morda ni migriran)
$hasInvoices = false;
try {
    try {
        $invCheck = $pdo->prepare("SELECT COUNT(*) FROM subscription_invoices WHERE user_id = ?");
        $invCheck->execute([(int)$_SESSION['user_id']]);
        $hasInvoices = (int)$invCheck->fetchColumn() > 0;
    } catch (Throwable $e2) {
        // Fallback: migracija še ni pognana
        $invCheck = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE user_id = ? AND hub_invoice_id IS NOT NULL");
        $invCheck->execute([(int)$_SESSION['user_id']]);
        $hasInvoices = (int)$invCheck->fetchColumn() > 0;
    }
} catch (Throwable $e) {
    $hasInvoices = false;
}

$discounts = [];
foreach (['basic', 'advanced', 'premium'] as $slug) {
    $discounts[$slug] = get_active_discount($pdo, $slug);
}

$prorations = [];
if ($isActive) {
    foreach (['basic', 'advanced', 'premium'] as $slug) {
        if (get_plan_rank($slug) > get_plan_rank($currentPlan)) {
            $prorations[$slug] = calculate_upgrade_proration($sub, $slug, $pdo);
        }
    }
}

$planNameMap = ['basic' => 'Basic', 'advanced' => 'Advanced', 'premium' => 'Premium'];

$cycle = ($isActive || $isOnTrial) ? ($sub['billing_cycle'] ?? 'monthly') : 'monthly';
$price = $isActive ? (float)(PLANS[$currentPlan][$cycle . '_price'] ?? 0) : 0;

foreach ($prorations as $pSlug => &$_p) {
    $_p['ends_at_label']     = ($sub && $sub['ends_at']) ? date('d. m. Y', strtotime($sub['ends_at'])) : '';
    $_p['current_cycle']     = $cycle;
    $pDisc = $discounts[$pSlug] ?? null;
    $_p['new_monthly_price'] = ($pDisc && isset($pDisc['discounted_monthly']))
        ? (float)$pDisc['discounted_monthly']
        : (float)PLANS[$pSlug]['monthly_price'];
    $_p['new_yearly_price']  = ($pDisc && isset($pDisc['discounted_yearly']))
        ? (float)$pDisc['discounted_yearly']
        : (float)PLANS[$pSlug]['yearly_price'];
}
unset($_p);

// Načrtovane spremembe (stolpci so NULL-safe pred migracijo)
$pendingPlanSlug     = $isActive ? ($sub['pending_plan_slug']     ?? null) : null;
$pendingBillingCycle = $isActive ? ($sub['pending_billing_cycle'] ?? null) : null;

// Podatki za preklop na mesečno (samo letna + Stripe + brez načrtovane spremembe)
$monthlySwitch = null;
if ($isActive && $cycle === 'yearly' && ($sub['payment_method'] ?? '') === 'stripe'
    && !$pendingPlanSlug && !$pendingBillingCycle) {
    $monthlySwitch = [
        'planName'     => PLANS[$currentPlan]['name'],
        'monthlyPrice' => (float)(PLANS[$currentPlan]['monthly_price'] ?? 0),
        'endsAt'       => ($sub['ends_at'] ?? null) ? date('j. n. Y', strtotime($sub['ends_at'])) : '',
    ];
}

// Cene za JS modal
$planPricesForJs = [];
foreach (['basic', 'advanced', 'premium'] as $_s) {
    $planPricesForJs[$_s] = [
        'monthly' => (float)PLANS[$_s]['monthly_price'],
        'yearly'  => (float)PLANS[$_s]['yearly_price'],
        'name'    => PLANS[$_s]['name'],
    ];
}

$yearlySwitch = null;
if ($isActive && $cycle === 'monthly' && ($sub['payment_method'] ?? '') === 'stripe'
    && !$pendingPlanSlug && !$pendingBillingCycle) {
    $ysDiscount  = get_active_discount($pdo, $currentPlan);
    $ysYearly    = ($ysDiscount && isset($ysDiscount['discounted_yearly']))
        ? (float)$ysDiscount['discounted_yearly']
        : (float)PLANS[$currentPlan]['yearly_price'];

    // Izračun sorazmernega zneska za preklop na letno
    $ysNow = new DateTime('now', new DateTimeZone('UTC'));
    $ysEnd = new DateTime($sub['ends_at'] ?? 'now', new DateTimeZone('UTC'));
    if ($ysNow < $ysEnd) {
        $ysRemDays    = (int)$ysNow->diff($ysEnd)->days;
        $ysPeriodStart = clone $ysEnd;
        $ysPeriodStart->modify('-1 month');
        $ysPeriodDays  = max(1, (int)$ysPeriodStart->diff($ysEnd)->days);
        $ysCredit      = round(($ysRemDays / $ysPeriodDays) * $price, 2);
        $ysChargeNow   = max(0.0, round($ysYearly - $ysCredit, 2));
    } else {
        $ysRemDays = 0; $ysCredit = 0.0; $ysChargeNow = $ysYearly;
    }

    $yearlySwitch = [
        'planName'       => PLANS[$currentPlan]['name'],
        'monthlyPrice'   => $price,
        'yearlyPrice'    => $ysYearly,
        'yearlySavings'  => round($price * 12 - $ysYearly, 2),
        'monthlyEquiv'   => round($ysYearly / 12, 2),
        'remainingDays'  => $ysRemDays,
        'credit'         => $ysCredit,
        'chargeNow'      => $ysChargeNow,
        'endsAt'         => ($sub['ends_at'] ?? null) ? date('d. m. Y', strtotime($sub['ends_at'])) : '',
    ];
}
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('billing.page_title') ?> – <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&family=Fraunces:opsz,wght@9..144,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/design.css?v=1">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/rezble.css?v=1">
</head>
<body>

<div id="rz-app" class="rz-app">
<?php require_once '../includes/sidebar.php'; ?>
<main class="rz-main">

<?php require_once '../includes/trial_banner.php'; ?>

<div class="admin-layout">
<div class="admin-content" style="max-width:960px">

<div class="rz-billing">

    <!-- ══ CURRENT PLAN CARD ═══════════════════════════════════ -->
    <div class="rz-card rz-billing-current">
        <div>
            <div style="font-size:11px;font-weight:700;letter-spacing:.12em;opacity:.8;text-transform:uppercase">
                <?php if ($isOnTrial): ?>
                    <?= t('billing.trial_testing') ?>
                <?php elseif ($isActive): ?>
                    <?= t('billing.active_plan') ?>
                <?php else: ?>
                    <?= t('billing.no_active_plan') ?>
                <?php endif; ?>
            </div>

            <?php if ($isOnTrial): ?>
                <div class="rz-billing-price"><?= h(PLANS[$currentPlan]['name']) ?> <span><?= t('billing.free') ?></span></div>
                <p class="rz-billing-sub">
                    <?php if ($sub && $sub['ends_at']): ?>
                        <?= t('billing.trial_expires') ?> <?= date('d. m. Y', strtotime($sub['ends_at'])) ?>
                        <?php if ($trialDaysLeft > 0): ?>
                            · <?= t('billing.days_left', ['days' => $trialDaysLeft]) ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            <?php elseif ($isActive): ?>
                <?php
                $cycle = $sub['billing_cycle'] ?? 'monthly';
                $price = PLANS[$currentPlan][$cycle . '_price'] ?? 0;
                ?>
                <div class="rz-billing-price">
                    <?= number_format($price, 2, ',', '.') ?> €
                    <span>/ <?= $cycle === 'yearly' ? t('billing.period_year_short') : t('billing.period_month_short') ?></span>
                </div>
                <p class="rz-billing-sub">
                    <?= h(PLANS[$currentPlan]['name']) ?>
                    <?php if ($sub['ends_at']): ?>
                        · <?= t('billing.subscription_until') ?> <?= date('d. m. Y', strtotime($sub['ends_at'])) ?>
                    <?php endif; ?>
                    · <?= $cycle === 'yearly' ? t('billing.billing_yearly') : t('billing.billing_monthly') ?>
                </p>
            <?php else: ?>
                <div class="rz-billing-price" style="font-size:22px"><?= t('billing.plan_expired') ?></div>
                <p class="rz-billing-sub"><?= t('billing.trial_expired_short') ?></p>
            <?php endif; ?>
        </div>

        <div class="rz-billing-right">
            <?php if ($isActive && ($sub['payment_method'] ?? '') === 'stripe'): ?>
                <button onclick="openCustomerPortal()" class="rz-btn"><?= t('billing.manage_subscription') ?></button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ YEARLY SAVINGS BANNER (mesečna aktivna naročnina) ═══ -->
    <?php if ($yearlySwitch && $yearlySwitch['yearlySavings'] > 0): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:14px 20px;background:color-mix(in oklab,var(--success) 8%,var(--bg-elev));border:1px solid color-mix(in oklab,var(--success) 30%,transparent);border-radius:var(--card-radius)">
        <div>
            <div style="font-size:13px;font-weight:700;color:var(--success)">Prihranite <?= number_format($yearlySwitch['yearlySavings'], 2, ',', '.') ?> € letno s preklopom na letno plačilo</div>
            <div style="font-size:12px;color:var(--ink-mute);margin-top:2px">
                Letna naročnina: <strong><?= number_format($yearlySwitch['yearlyPrice'], 2, ',', '.') ?> €/leto</strong>
                · le <?= number_format($yearlySwitch['monthlyEquiv'], 2, ',', '.') ?> €/mes namesto <?= number_format($yearlySwitch['monthlyPrice'], 2, ',', '.') ?> €/mes
            </div>
        </div>
        <button onclick="openYearlySwitch()" class="rz-btn rz-btn-primary" style="white-space:nowrap;flex:none">
            Preklopi na letno →
        </button>
    </div>
    <?php endif; ?>

    <!-- ══ PREKLOP NA MESEČNO (letna aktivna naročnina) ═══════════ -->
    <?php if ($monthlySwitch): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:14px 20px;background:var(--bg-elev);border:1px solid var(--line);border-radius:var(--card-radius)">
        <div>
            <div style="font-size:13px;font-weight:600;color:var(--ink)"><?= t('billing.monthly_switch_title') ?></div>
            <div style="font-size:12px;color:var(--ink-mute);margin-top:2px">
                <?= t_raw('billing.monthly_switch_active_until', [
                    'date'  => '<strong>' . h($monthlySwitch['endsAt']) . '</strong>',
                    'price' => '<strong>' . number_format($monthlySwitch['monthlyPrice'], 2, ',', '.') . ' €/mes</strong>',
                ]) ?>
            </div>
        </div>
        <button onclick="openMonthlySwitch()" class="rz-btn" style="white-space:nowrap;flex:none">
            <?= t('billing.monthly_switch_btn') ?>
        </button>
    </div>
    <?php endif; ?>

    <!-- ══ NAČRTOVANA SPREMEMBA (pending downgrade/cycle) ══════════ -->
    <?php if ($pendingPlanSlug || $pendingBillingCycle): ?>
    <?php
        $pendingDesc = '';
        $pendingEndsAt = ($sub && $sub['ends_at']) ? date('j. n. Y', strtotime($sub['ends_at'])) : '';
        if ($pendingPlanSlug) {
            $pName  = PLANS[$pendingPlanSlug]['name'] ?? ucfirst($pendingPlanSlug);
            $pPrice = number_format((float)(PLANS[$pendingPlanSlug][$cycle . '_price'] ?? 0), 2, ',', '.');
            $pCycle = $cycle === 'yearly' ? 'letno' : 'mesečno';
            $pendingDesc = t_raw('billing.pending_downgrade_desc', [
                'name'  => '<strong>' . h($pName) . '</strong>',
                'date'  => $pendingEndsAt,
                'price' => '<strong>' . $pPrice . '</strong>',
                'cycle' => '<strong>' . $pCycle . '</strong>',
            ]);
        } elseif ($pendingBillingCycle === 'monthly') {
            $mPrice = number_format((float)(PLANS[$currentPlan]['monthly_price'] ?? 0), 2, ',', '.');
            $pendingDesc = t_raw('billing.pending_monthly_desc', [
                'date'  => $pendingEndsAt,
                'price' => '<strong>' . $mPrice . '</strong>',
            ]);
        }
    ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:14px 20px;background:color-mix(in oklab,var(--warning,#F59E0B) 8%,var(--bg-elev));border:1px solid color-mix(in oklab,var(--warning,#F59E0B) 30%,transparent);border-radius:var(--card-radius)">
        <div style="display:flex;gap:10px;align-items:flex-start">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2" style="flex:none;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div>
                <div style="font-size:13px;font-weight:600;color:#92400E"><?= t('billing.pending_title') ?></div>
                <div style="font-size:12px;color:#92400E;margin-top:2px"><?= $pendingDesc ?></div>
            </div>
        </div>
        <button onclick="cancelPendingDowngrade(this)" class="rz-btn" style="white-space:nowrap;flex:none;font-size:12px">
            <?= t('billing.pending_cancel_btn') ?>
        </button>
    </div>
    <?php endif; ?>

    <!-- ══ BILLING CYCLE TOGGLE + DISCOUNT (za trial / expired) ══ -->
    <?php if ($isOnTrial || !$isActive): ?>
    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:16px 28px">
        <!-- Billing cycle toggle -->
        <div style="display:flex;align-items:center;gap:10px">
            <span style="font-size:13px;font-weight:500;color:var(--ink)"><?= t('billing.toggle_monthly') ?></span>
            <button id="billing-yearly-toggle" role="switch" aria-checked="false"
                    class="rz-toggle" onclick="toggleCycle()"
                    style="width:38px;height:22px">
                <span class="rz-toggle-dot" style="width:14px;height:14px;top:4px;left:4px"></span>
            </button>
            <span style="font-size:13px;font-weight:500;color:var(--ink)">
                <?= t('billing.toggle_yearly') ?>
                <span style="background:color-mix(in oklab,var(--success) 15%,transparent);color:var(--success);font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;margin-left:5px"><?= t('billing.yearly_save') ?></span>
            </span>
        </div>
        <!-- Discount code -->
        <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:240px;max-width:380px">
            <input type="text" id="discount-code-input"
                   placeholder="<?= t('billing.discount_code_placeholder') ?>"
                   class="rz-input" style="flex:1;text-transform:uppercase;font-size:13px"
                   oninput="this.value=this.value.toUpperCase()" maxlength="30">
            <button onclick="applyDiscountCode()" class="rz-btn" style="white-space:nowrap"><?= t('billing.apply_code_btn') ?></button>
        </div>
    </div>
    <div id="discount-code-msg" style="font-size:12px;margin-top:-8px"></div>
    <?php endif; ?>

    <!-- ══ PLAN GRID ══════════════════════════════════════════════ -->
    <div class="rz-grid-3">
        <?php foreach (['basic', 'advanced', 'premium'] as $slug):
            $plan        = PLANS[$slug];
            $discount    = $discounts[$slug];
            $proration   = $prorations[$slug] ?? [];
            $isCurrent   = $slug === $currentPlan && ($isOnTrial || $isActive);
        ?>
        <div class="rz-plan <?= $isCurrent ? 'is-active' : '' ?>">
            <div class="rz-plan-head">
                <h3 class="rz-plan-name display"><?= h($plan['name']) ?></h3>
                <?php if ($isCurrent && $isActive): ?>
                    <span class="rz-chip rz-chip-premium">TRENUTNO</span>
                <?php elseif ($isCurrent && $isOnTrial): ?>
                    <span class="rz-chip rz-chip-ok" style="font-size:10px">PREIZKUŠAM</span>
                <?php elseif ($slug === 'advanced' && !$isCurrent): ?>
                    <span class="rz-chip" style="background:color-mix(in oklab,var(--accent) 12%,transparent);color:var(--accent);border-color:transparent;font-size:10px"><?= t('billing.recommended') ?></span>
                <?php endif; ?>
            </div>

            <!-- Cena -->
            <div class="rz-plan-price" id="price-<?= $slug ?>">
                <?php if ($discount && $discount['discounted_monthly']): ?>
                    <span style="font-size:14px;color:var(--ink-mute);text-decoration:line-through;font-weight:500" class="orig-price"
                          data-monthly="<?= number_format($plan['monthly_price'], 2, ',', '.') ?>"
                          data-yearly="<?= number_format($plan['yearly_price'], 2, ',', '.') ?>">
                        <?= number_format($plan['monthly_price'], 2, ',', '.') ?> €
                    </span>
                    <span class="cur-price"
                          data-monthly="<?= $discount['discounted_monthly'] ?>"
                          data-yearly="<?= $discount['discounted_yearly'] ?? $plan['yearly_price'] ?>">
                        <?= number_format($discount['discounted_monthly'], 2, ',', '.') ?> €
                    </span>
                <?php else: ?>
                    <span class="cur-price"
                          data-monthly="<?= $plan['monthly_price'] ?>"
                          data-yearly="<?= $plan['yearly_price'] ?>">
                        <?= number_format($plan['monthly_price'], 2, ',', '.') ?> €
                    </span>
                <?php endif; ?>
                <span class="cycle-label" style="font-size:13px;color:var(--ink-mute);font-weight:500">/ <?= t('billing.period_month_short') ?></span>
            </div>

            <?php if ($discount): ?>
                <div style="font-size:11px;color:var(--success);font-weight:600;margin-top:-10px"><?= h($discount['label']) ?> – do <?= date('d. m.', strtotime($discount['valid_until'])) ?></div>
            <?php endif; ?>

            <!-- Features -->
            <ul class="rz-plan-feats" style="flex:1">
                <?php foreach (FEATURE_LABELS as $fSlug => $fLabel):
                    $included    = in_array($fSlug, $plan['features']);
                    $wasIncluded = $isActive ? in_array($fSlug, PLANS[$currentPlan]['features']) : true;
                    if (!$included) continue;
                ?>
                <li style="<?= (!$wasIncluded && $isActive) ? 'font-weight:600' : '' ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="<?= (!$wasIncluded && $isActive) ? 'var(--accent)' : 'var(--success)' ?>"
                         stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    <?= h($fLabel) ?>
                    <?php if (!$wasIncluded && $isActive): ?>
                        <span style="font-size:10px;background:color-mix(in oklab,var(--accent) 12%,transparent);color:var(--accent);border-radius:10px;padding:1px 5px;margin-left:2px"><?= t('billing.new') ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <!-- Akcije -->
            <div style="display:flex;flex-direction:column;gap:8px;margin-top:auto">
                <?php if ($isCurrent && $isActive): ?>
                    <button class="rz-btn" style="width:100%;justify-content:center" onclick="openCustomerPortal()">
                        <?= t('billing.manage_subscription') ?>
                    </button>

                <?php elseif ($isCurrent && $isOnTrial): ?>
                    <button class="rz-btn" style="width:100%;justify-content:center" disabled>
                        <?= t('billing.currently_testing_btn') ?>
                    </button>
                    <button class="rz-btn rz-btn-primary" style="width:100%;justify-content:center"
                            onclick="selectPlan('<?= $slug ?>')">
                        <?= t('billing.buy_plan', ['name' => h($plan['name'])]) ?>
                    </button>

                <?php elseif ($isOnTrial): ?>
                    <button class="rz-btn" style="width:100%;justify-content:center"
                            onclick="switchTrialPlan('<?= $slug ?>', this)">
                        <?= t('billing.try_plan', ['name' => h($plan['name'])]) ?>
                    </button>
                    <button class="rz-btn rz-btn-primary" style="width:100%;justify-content:center"
                            onclick="selectPlan('<?= $slug ?>')">
                        <?= t('billing.buy_plan', ['name' => h($plan['name'])]) ?>
                    </button>

                <?php elseif ($isActive && !empty($proration)): ?>
                    <?php if (!empty($proration['remaining_days'])): ?>
                    <div style="background:color-mix(in oklab,var(--success) 8%,transparent);border:1px solid color-mix(in oklab,var(--success) 25%,transparent);border-radius:8px;padding:10px 12px;font-size:11px;color:var(--success)">
                        <div style="font-weight:700;margin-bottom:2px"><?= t('billing.proration_title') ?></div>
                        <div><?= t('billing.proration_charge_now') ?> <strong><?= number_format($proration['charge_now'], 2) ?> €</strong></div>
                        <div style="color:color-mix(in oklab,var(--success) 80%,var(--ink))">
                            <?= t('billing.proration_next', ['price' => number_format($proration['next_period_price'], 2), 'cycle' => $proration['cycle'] === 'yearly' ? t('billing.proration_cycle_yearly') : t('billing.proration_cycle_monthly')]) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <button class="rz-btn rz-btn-primary" style="width:100%;justify-content:center"
                            onclick="upgradePlan('<?= $slug ?>', this)">
                        <?= t('billing.upgrade_to', ['name' => h($plan['name'])]) ?>
                        <?php if (($proration['charge_now'] ?? 0) > 0): ?>
                            (<?= number_format($proration['charge_now'], 2) ?> €)
                        <?php endif; ?>
                    </button>

                <?php elseif ($isActive && get_plan_rank($slug) < get_plan_rank($currentPlan)): ?>
                    <?php if ($pendingPlanSlug === $slug): ?>
                        <div style="background:color-mix(in oklab,var(--warning,#F59E0B) 8%,transparent);border:1px solid color-mix(in oklab,var(--warning,#F59E0B) 30%,transparent);border-radius:8px;padding:10px 12px;font-size:11px;color:#92400E">
                            <div style="font-weight:700;margin-bottom:2px"><?= t('billing.downgrade_scheduled') ?></div>
                            <div><?= t('billing.downgrade_activates', ['date' => ($sub['ends_at'] ? date('j. n. Y', strtotime($sub['ends_at'])) : '')]) ?></div>
                        </div>
                        <button class="rz-btn" style="width:100%;justify-content:center;font-size:12px"
                                onclick="cancelPendingDowngrade(this)">
                            <?= t('billing.cancel_downgrade_btn') ?>
                        </button>
                    <?php elseif (!$pendingPlanSlug && !$pendingBillingCycle): ?>
                        <button class="rz-btn" style="width:100%;justify-content:center"
                                onclick="openDowngradePlanModal('<?= $slug ?>')">
                            <?= t('billing.downgrade_to', ['name' => h($plan['name'])]) ?>
                        </button>
                    <?php endif; ?>

                <?php elseif (!$isActive): ?>
                    <button class="rz-btn rz-btn-primary" style="width:100%;justify-content:center"
                            onclick="selectPlan('<?= $slug ?>')">
                        <?= t('billing.buy_plan', ['name' => h($plan['name'])]) ?>
                    </button>
                <?php endif; ?>

                <?php if (!$isActive || $isOnTrial): ?>
                <button class="btn-invoice-link" data-invoice-plan="<?= $slug ?>"
                        onclick="requestInvoice('<?= $slug ?>')"
                        style="display:none;width:100%;padding:.4rem 1rem;border:1.5px dashed var(--line-strong);border-radius:var(--card-radius);background:transparent;color:var(--ink-mute);font-size:12px;font-weight:500;cursor:pointer;text-align:center">
                    <?= t('billing.invoice_link') ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ══ INVOICE HISTORY ════════════════════════════════════════ -->
    <?php if ($hasInvoices): ?>
    <div class="rz-card">
        <div class="rz-card-head">
            <div>
                <div class="rz-card-eyebrow mono"><?= t('billing.invoices_eyebrow') ?></div>
                <h2 class="rz-card-title display"><?= t('billing.invoices_title') ?></h2>
            </div>
        </div>
        <table class="rz-table" id="invoice-table">
            <thead>
                <tr>
                    <th><?= t('billing.inv_date') ?></th>
                    <th><?= t('billing.inv_desc') ?></th>
                    <th class="rz-th-num"><?= t('billing.inv_amount') ?></th>
                    <th><?= t('billing.inv_status') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="invoice-tbody">
                <tr><td colspan="5" style="text-align:center;color:var(--ink-mute);padding:24px;font-size:13px">
                    <span style="animation:rz-spin 1s linear infinite;display:inline-block">⟳</span>
                    <?= t('billing.invoices_loading') ?>
                </td></tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div><!-- /rz-billing -->
</div>
</div>

<div id="toast-container"></div>

<!-- ══ UPGRADE MODAL ════════════════════════════════════════════ -->
<div id="upgrade-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(4px);z-index:60;align-items:center;justify-content:center;padding:20px">
    <div style="background:var(--bg-elev);border-radius:16px;border:1px solid var(--line);box-shadow:var(--shadow-pop);width:100%;max-width:440px;padding:28px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;gap:12px">
            <div>
                <div style="font-size:10px;font-weight:700;letter-spacing:.12em;color:var(--ink-mute);text-transform:uppercase;margin-bottom:4px;font-family:var(--font-mono)">NADGRADNJA PAKETA</div>
                <h2 id="upg-title" style="font-size:22px;font-weight:700;margin:0;letter-spacing:-0.02em;color:var(--ink)"></h2>
            </div>
            <button onclick="closeUpgradeModal()" style="width:32px;height:32px;border-radius:8px;display:grid;place-items:center;border:1px solid var(--line);color:var(--ink-mute);background:var(--bg-elev);cursor:pointer;font-size:18px;flex:none;line-height:1">×</button>
        </div>
        <div id="upg-cycle-selector" style="display:none;margin-bottom:16px">
            <div class="rz-seg" style="width:100%">
                <button id="upg-cycle-monthly" class="is-sel" onclick="setUpgradeCycle('monthly')" style="flex:1;justify-content:center">Mesečno</button>
                <button id="upg-cycle-yearly" onclick="setUpgradeCycle('yearly')" style="flex:1;justify-content:center;gap:6px">
                    Letno <span id="upg-yearly-save" style="font-size:10px;font-weight:500;opacity:.75"></span>
                </button>
            </div>
        </div>
        <div id="upg-body"></div>
        <div style="display:flex;gap:10px;margin-top:20px">
            <button onclick="closeUpgradeModal()" class="rz-btn" style="flex:1;justify-content:center">Prekliči</button>
            <button id="upg-confirm-btn" class="rz-btn rz-btn-primary" style="flex:2;justify-content:center">Potrdi nadgradnjo →</button>
        </div>
    </div>
</div>

<!-- ══ YEARLY SWITCH MODAL ═══════════════════════════════════════ -->
<?php if ($yearlySwitch): ?>
<div id="yearly-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(4px);z-index:60;align-items:center;justify-content:center;padding:20px">
    <div style="background:var(--bg-elev);border-radius:16px;border:1px solid var(--line);box-shadow:var(--shadow-pop);width:100%;max-width:420px;padding:28px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;gap:12px">
            <div>
                <div style="font-size:10px;font-weight:700;letter-spacing:.12em;color:var(--ink-mute);text-transform:uppercase;margin-bottom:4px;font-family:var(--font-mono)">PREKLOP NA LETNO PLAČILO</div>
                <h2 style="font-size:22px;font-weight:700;margin:0;letter-spacing:-0.02em;color:var(--ink)">Prihranite <?= number_format($yearlySwitch['yearlySavings'], 2, ',', '.') ?> €/leto</h2>
            </div>
            <button onclick="closeYearlyModal()" style="width:32px;height:32px;border-radius:8px;display:grid;place-items:center;border:1px solid var(--line);color:var(--ink-mute);background:var(--bg-elev);cursor:pointer;font-size:18px;flex:none;line-height:1">×</button>
        </div>

        <div style="background:var(--bg-sunken);border-radius:10px;padding:16px 18px;display:flex;flex-direction:column;gap:10px;margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;font-size:13px">
                <span style="color:var(--ink-mute)">Dobropis za preostale dni (<?= $yearlySwitch['remainingDays'] ?> dni)</span>
                <span style="color:var(--success);font-weight:600">−<?= number_format($yearlySwitch['credit'], 2, ',', '.') ?> €</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:13px">
                <div>
                    <span style="color:var(--ink-mute)">Letna naročnina <?= h($yearlySwitch['planName']) ?></span>
                    <div style="font-size:11px;color:var(--ink-mute);margin-top:1px">≈ <?= number_format($yearlySwitch['monthlyEquiv'], 2, ',', '.') ?> €/mes</div>
                </div>
                <span style="font-weight:600">+<?= number_format($yearlySwitch['yearlyPrice'], 2, ',', '.') ?> €</span>
            </div>
            <div style="height:1px;background:var(--line)"></div>
            <div style="display:flex;justify-content:space-between;font-size:15px;font-weight:700;color:var(--ink)">
                <span>Skupaj danes</span>
                <span><?= number_format($yearlySwitch['chargeNow'], 2, ',', '.') ?> €</span>
            </div>
        </div>

        <div style="font-size:12px;color:var(--ink-soft);background:var(--accent-soft);border:1px solid color-mix(in oklab,var(--accent) 25%,transparent);border-radius:8px;padding:12px 14px;display:flex;gap:10px;align-items:flex-start;margin-bottom:20px">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" style="flex:none;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span>Bremenili bomo vaš obstoječ način plačila – enako kot pri sedanji naročnini. Naslednje letno podaljšanje bo <?= number_format($yearlySwitch['yearlyPrice'], 2, ',', '.') ?> €<?= $yearlySwitch['endsAt'] ? ' (od ' . $yearlySwitch['endsAt'] . ')' : '' ?>.</span>
        </div>

        <div style="display:flex;gap:10px">
            <button onclick="closeYearlyModal()" class="rz-btn" style="flex:1;justify-content:center">Prekliči</button>
            <button onclick="doSwitchYearly(this)" class="rz-btn rz-btn-primary" style="flex:2;justify-content:center">Preklopi na letno →</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ DOWNGRADE / SWITCH-TO-MONTHLY MODAL ══════════════════════ -->
<div id="downgrade-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(4px);z-index:60;align-items:center;justify-content:center;padding:20px">
    <div style="background:var(--bg-elev);border-radius:16px;border:1px solid var(--line);box-shadow:var(--shadow-pop);width:100%;max-width:420px;padding:28px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;gap:12px">
            <div>
                <div id="dwn-eyebrow" style="font-size:10px;font-weight:700;letter-spacing:.12em;color:var(--ink-mute);text-transform:uppercase;margin-bottom:4px;font-family:var(--font-mono)"></div>
                <h2 id="dwn-title" style="font-size:22px;font-weight:700;margin:0;letter-spacing:-0.02em;color:var(--ink)"></h2>
            </div>
            <button onclick="closeDowngradeModal()" style="width:32px;height:32px;border-radius:8px;display:grid;place-items:center;border:1px solid var(--line);color:var(--ink-mute);background:var(--bg-elev);cursor:pointer;font-size:18px;flex:none;line-height:1">×</button>
        </div>
        <div id="dwn-body"></div>
        <div style="display:flex;gap:10px;margin-top:20px">
            <button onclick="closeDowngradeModal()" class="rz-btn" style="flex:1;justify-content:center">Prekliči</button>
            <button id="dwn-confirm-btn" class="rz-btn" style="flex:2;justify-content:center;background:var(--bg-sunken);border:1px solid var(--line);color:var(--ink)">Potrdi →</button>
        </div>
    </div>
</div>

<style>
@keyframes rz-spin { to { transform: rotate(360deg); } }
</style>

<script>
window.__T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
window.t = function(k, p) { var s = window.__T__[k] || k; if (p) { for (var x in p) s = s.split('{'+x+'}').join(p[x]); } return s; };
window.APP_STATE = { base: '<?= BASE_PATH ?>' };
window.__PRORATIONS__   = <?= json_encode($prorations, JSON_UNESCAPED_UNICODE) ?>;
window.__PLAN_PRICES__  = <?= json_encode($planPricesForJs, JSON_UNESCAPED_UNICODE) ?>;
window.__DOWNGRADE_CTX__ = {
    currentPlan:  '<?= $currentPlan ?>',
    currentCycle: '<?= $cycle ?>',
    endsAt:       '<?= $sub && $sub['ends_at'] ? date('j. n. Y', strtotime($sub['ends_at'])) : '' ?>',
    monthlySwitch: <?= json_encode($monthlySwitch, JSON_UNESCAPED_UNICODE) ?>,
};

let isYearly = <?= json_encode($cycle === 'yearly') ?>;
let appliedDiscountCode = '';

// ─── Billing cycle toggle ──────────────────────────────────────
function toggleCycle() {
    isYearly = !isYearly;
    const btn = document.getElementById('billing-yearly-toggle');
    if (btn) {
        btn.classList.toggle('is-on', isYearly);
        btn.setAttribute('aria-checked', isYearly ? 'true' : 'false');
    }
    document.querySelectorAll('.cur-price').forEach(el => {
        const v = isYearly ? parseFloat(el.dataset.yearly) : parseFloat(el.dataset.monthly);
        if (!isNaN(v)) el.textContent = v.toFixed(2).replace('.', ',') + ' €';
    });
    document.querySelectorAll('.orig-price').forEach(el => {
        const v = isYearly ? el.dataset.yearly : el.dataset.monthly;
        if (v) el.textContent = v + ' €';
    });
    document.querySelectorAll('.cycle-label').forEach(el => {
        el.textContent = '/ ' + window.t(isYearly ? 'billing.period_year_short' : 'billing.period_month_short');
    });
    document.querySelectorAll('.btn-invoice-link').forEach(el => {
        el.style.display = isYearly ? 'block' : 'none';
    });
}

// ─── Toast ────────────────────────────────────────────────────
function toast(msg, type = 'success') {
    const c = document.getElementById('toast-container');
    const t = document.createElement('div');
    t.className = 'toast toast-' + type;
    t.textContent = msg;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(() => t.remove(), 300); }, 3200);
}

// ─── Discount code ─────────────────────────────────────────────
function _applyCodeDiscountToCards(pct, amtEur) {
    document.querySelectorAll('.cur-price').forEach(el => {
        const mOrig = parseFloat(el.dataset.monthly) || 0;
        const yOrig = parseFloat(el.dataset.yearly)  || 0;
        let mDisc, yDisc;
        if (pct > 0) {
            mDisc = Math.round(mOrig * (1 - pct / 100) * 100) / 100;
            yDisc = Math.round(yOrig * (1 - pct / 100) * 100) / 100;
        } else if (amtEur > 0) {
            mDisc = Math.max(0, Math.round((mOrig - amtEur) * 100) / 100);
            yDisc = Math.max(0, Math.round((yOrig - amtEur) * 100) / 100);
        } else return;
        el.dataset.monthly = mDisc.toFixed(2);
        el.dataset.yearly  = yDisc.toFixed(2);
        const cur = isYearly ? yDisc : mDisc;
        el.textContent = cur.toFixed(2).replace('.', ',') + ' €';
        let origEl = el.previousElementSibling;
        if (!origEl || !origEl.classList.contains('orig-price')) {
            origEl = document.createElement('span');
            origEl.className = 'orig-price';
            origEl.style.cssText = 'font-size:14px;color:var(--ink-mute);text-decoration:line-through;font-weight:500';
            el.parentNode.insertBefore(origEl, el);
        }
        origEl.textContent = (isYearly ? yOrig : mOrig).toFixed(2).replace('.', ',') + ' €';
    });
}

async function applyDiscountCode() {
    const input = document.getElementById('discount-code-input');
    const msg   = document.getElementById('discount-code-msg');
    const code  = (input?.value ?? '').trim().toUpperCase();
    if (!code) { msg.innerHTML = ''; appliedDiscountCode = ''; return; }
    msg.innerHTML = '<span style="color:var(--ink-mute)">Preverjam…</span>';
    try {
        const res = await fetch(APP_STATE.base + '/api/discount_codes.php?action=validate&code=' + encodeURIComponent(code));
        const d   = await res.json();
        if (d.success && d.data.valid) {
            appliedDiscountCode = code;
            const pct = d.data.percent_off   || 0;
            const amt = d.data.amount_off_eur || 0;
            const dur = d.data.duration === 'repeating' ? ' za ' + d.data.duration_months + ' mes.' : (d.data.duration === 'forever' ? ' za vedno' : '');
            const label = pct > 0 ? pct + '% popust' + dur : amt.toFixed(2) + ' € popust' + dur;
            msg.innerHTML = '<span style="color:var(--success);font-weight:600">✓ Koda uveljavljena: ' + label + '</span>';
            _applyCodeDiscountToCards(pct, amt);
        } else {
            appliedDiscountCode = '';
            msg.innerHTML = '<span style="color:var(--danger)">' + (d.error || d.data?.error || 'Neveljavna koda.') + '</span>';
        }
    } catch(e) {
        msg.innerHTML = '<span style="color:var(--danger)">Napaka pri preverjanju.</span>';
    }
}

// ─── Switch trial plan ─────────────────────────────────────────
async function switchTrialPlan(slug, btn) {
    const origText = btn?.textContent;
    if (btn) { btn.disabled = true; btn.textContent = window.t('billing.switching'); }
    try {
        const res  = await fetch(APP_STATE.base + '/api/billing.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'switch_trial_plan', plan_slug: slug }),
        });
        const data = await res.json();
        if (data.success) {
            toast(data.message || window.t('billing.plan_switched'));
            setTimeout(() => location.reload(), 800);
        } else {
            toast(data.error || 'Napaka.', 'error');
            if (btn) { btn.disabled = false; btn.textContent = origText; }
        }
    } catch(e) {
        toast(window.t('billing.err_connection'), 'error');
        if (btn) { btn.disabled = false; btn.textContent = origText; }
    }
}

// ─── Stripe Checkout ───────────────────────────────────────────
async function selectPlan(slug) {
    const cycle = isYearly ? 'yearly' : 'monthly';
    const btn   = event?.currentTarget;
    if (btn) { btn.disabled = true; btn.textContent = window.t('billing.redirecting'); }
    try {
        const body = { action: 'create_checkout_session', plan_slug: slug, billing_cycle: cycle };
        if (appliedDiscountCode) body.discount_code = appliedDiscountCode;
        const res  = await fetch(APP_STATE.base + '/api/billing.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const data = await res.json();
        if (data.success && data.data?.url) {
            window.location.href = data.data.url;
        } else {
            toast(data.error || window.t('billing.err_payment'), 'error');
            if (btn) { btn.disabled = false; btn.textContent = window.t('billing.buy_plan', {name: slug.charAt(0).toUpperCase() + slug.slice(1)}); }
        }
    } catch(e) {
        toast(window.t('billing.err_connection'), 'error');
        if (btn) { btn.disabled = false; }
    }
}

// ─── Upgrade modal ─────────────────────────────────────────────
let _upgradeBtn = null, _upgradeSlug = null, _upgradeCycle = 'monthly', _upgradeProration = null;

const _pNames = { basic: 'Basic', advanced: 'Advanced', premium: 'Premium' };
const _fmt    = v => parseFloat(v || 0).toFixed(2).replace('.', ',');

function _calcUpgrade(proration, targetCycle) {
    const ratio  = (proration.remaining_days || 0) / Math.max(1, proration.period_days || 1);
    const credit = ratio * parseFloat(proration.old_price || 0);
    let newPrice, chargeNow, newPriceLabel;
    if (targetCycle === 'yearly') {
        newPrice      = parseFloat(proration.new_yearly_price || 0);
        chargeNow     = Math.max(0, newPrice - credit);
        newPriceLabel = 'Letna naročnina (' + (_pNames[_upgradeSlug] || _upgradeSlug) + ')';
    } else {
        newPrice      = parseFloat(proration.new_monthly_price || proration.new_price || 0);
        chargeNow     = Math.max(0, ratio * newPrice - credit);
        newPriceLabel = 'Sorazmerni znesek (' + (_pNames[_upgradeSlug] || _upgradeSlug) + ' / mesečno)';
    }
    return { credit, chargeNow, newPrice, newPriceLabel, prorated: credit + chargeNow };
}

function _renderUpgradeBody(proration, targetCycle) {
    const { credit, chargeNow, newPrice, newPriceLabel, prorated } = _calcUpgrade(proration, targetCycle);
    const oldCycleTxt = (proration.current_cycle || proration.cycle) === 'yearly' ? 'letno' : 'mesečno';
    const newCycleTxt = targetCycle === 'yearly' ? 'letno' : 'mesečno';

    document.getElementById('upg-body').innerHTML = `
        <div style="background:var(--bg-sunken);border-radius:10px;padding:16px 18px;display:flex;flex-direction:column;gap:10px">
            <div style="display:flex;justify-content:space-between;font-size:13px">
                <span style="color:var(--ink-mute)">Preostali dnevi (${_pNames[proration.old_plan] || proration.old_plan} / ${oldCycleTxt})</span>
                <span>${proration.remaining_days || 0} dni</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:13px">
                <span style="color:var(--ink-mute)">Dobropis za neporabljeni čas</span>
                <span style="color:var(--success);font-weight:600">−${_fmt(credit)} €</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:13px">
                <span style="color:var(--ink-mute)">${newPriceLabel}</span>
                <span style="font-weight:600">+${_fmt(targetCycle === 'yearly' ? newPrice : prorated)} €</span>
            </div>
            <div style="height:1px;background:var(--line)"></div>
            <div style="display:flex;justify-content:space-between;font-size:15px;font-weight:700;color:var(--ink)">
                <span>Skupaj danes</span>
                <span>${chargeNow > 0 ? _fmt(chargeNow) + ' €' : '<span style="color:var(--success)">0,00 €</span>'}</span>
            </div>
        </div>
        <div style="font-size:12px;color:var(--ink-soft);background:var(--accent-soft);border:1px solid color-mix(in oklab,var(--accent) 25%,transparent);border-radius:8px;padding:12px 14px;display:flex;gap:10px;align-items:flex-start;margin-top:12px">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" style="flex:none;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span>Bremenili bomo vaš obstoječ način plačila – enako kot pri sedanji naročnini. Naslednja redna naročnina bo <strong>${_fmt(newPrice)} € / ${newCycleTxt}</strong>${proration.ends_at_label ? ' od ' + proration.ends_at_label : ''}.</span>
        </div>`;
}

function setUpgradeCycle(cycle) {
    _upgradeCycle = cycle;
    document.getElementById('upg-cycle-monthly').classList.toggle('is-sel', cycle === 'monthly');
    document.getElementById('upg-cycle-yearly').classList.toggle('is-sel',  cycle === 'yearly');
    _renderUpgradeBody(_upgradeProration, cycle);
}

function upgradePlan(slug, btn) {
    _upgradeBtn       = btn;
    _upgradeSlug      = slug;
    _upgradeProration = (window.__PRORATIONS__ || {})[slug] || {};
    _upgradeCycle     = _upgradeProration.current_cycle || 'monthly';

    document.getElementById('upg-title').textContent = 'Nadgradnja na ' + (_pNames[slug] || slug);

    const selector = document.getElementById('upg-cycle-selector');
    if (_upgradeProration.current_cycle === 'monthly') {
        const savings = Math.round((parseFloat(_upgradeProration.new_monthly_price || 0) * 12
            - parseFloat(_upgradeProration.new_yearly_price || 0)) * 100) / 100;
        document.getElementById('upg-yearly-save').textContent =
            savings > 0 ? '· prihranite ' + _fmt(savings) + ' €/leto' : '';
        selector.style.display = 'block';
        document.getElementById('upg-cycle-monthly').classList.toggle('is-sel', _upgradeCycle === 'monthly');
        document.getElementById('upg-cycle-yearly').classList.toggle('is-sel',  _upgradeCycle === 'yearly');
    } else {
        selector.style.display = 'none';
    }

    _renderUpgradeBody(_upgradeProration, _upgradeCycle);
    document.getElementById('upg-confirm-btn').onclick = function() { doUpgrade(this); };
    document.getElementById('upgrade-modal').style.display = 'flex';
}

function closeUpgradeModal() {
    document.getElementById('upgrade-modal').style.display = 'none';
}

async function doUpgrade(btn) {
    const origText = btn?.textContent;
    if (btn) { btn.disabled = true; btn.textContent = window.t('billing.upgrading'); }
    try {
        const res  = await fetch(APP_STATE.base + '/api/billing.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'upgrade_plan', plan_slug: _upgradeSlug, billing_cycle: _upgradeCycle }),
        });
        const data = await res.json();
        closeUpgradeModal();
        if (data.success) {
            toast(data.message || window.t('billing.plan_upgraded'));
            setTimeout(() => location.reload(), 1000);
        } else {
            toast(data.error || window.t('billing.err_upgrade'), 'error');
            if (btn) { btn.disabled = false; btn.textContent = origText; }
        }
    } catch(e) {
        toast(window.t('billing.err_connection'), 'error');
        if (btn) { btn.disabled = false; btn.textContent = origText; }
    }
}

// ─── Yearly switch modal ───────────────────────────────────────
function openYearlySwitch() {
    const el = document.getElementById('yearly-modal');
    if (el) el.style.display = 'flex';
}

function closeYearlyModal() {
    const el = document.getElementById('yearly-modal');
    if (el) el.style.display = 'none';
}

async function doSwitchYearly(btn) {
    const origText = btn?.textContent;
    if (btn) { btn.disabled = true; btn.textContent = 'Preklapljam…'; }
    try {
        const res  = await fetch(APP_STATE.base + '/api/billing.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'switch_to_yearly' }),
        });
        const data = await res.json();
        closeYearlyModal();
        if (data.success) {
            toast(data.message || 'Preklopljeno na letno plačilo.');
            setTimeout(() => location.reload(), 1200);
        } else {
            toast(data.error || 'Napaka pri preklopu.', 'error');
            if (btn) { btn.disabled = false; btn.textContent = origText; }
        }
    } catch(e) {
        toast(window.t('billing.err_connection'), 'error');
        if (btn) { btn.disabled = false; btn.textContent = origText; }
    }
}

// ─── Downgrade / switch-to-monthly modal ──────────────────────
let _dwnAction = 'downgrade_plan', _dwnSlug = null;

function _dwnFmt(v) { return parseFloat(v || 0).toFixed(2).replace('.', ','); }

function _buildDowngradeBody(title, eyebrow, newPriceStr, endsAt, infoNote) {
    document.getElementById('dwn-eyebrow').textContent = eyebrow;
    document.getElementById('dwn-title').textContent   = title;
    document.getElementById('dwn-body').innerHTML = `
        <div style="background:var(--bg-sunken);border-radius:10px;padding:16px 18px;display:flex;flex-direction:column;gap:10px;margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;font-size:13px">
                <span style="color:var(--ink-mute)">${window.t('billing.dwn_active_until')}</span>
                <span style="font-weight:600">${endsAt || '–'}</span>
            </div>
            <div style="height:1px;background:var(--line)"></div>
            <div style="display:flex;justify-content:space-between;font-size:15px;font-weight:700;color:var(--ink)">
                <span>${window.t('billing.dwn_from', {date: endsAt || window.t('billing.dwn_fallback_date')})}</span>
                <span>${newPriceStr}</span>
            </div>
        </div>
        <div style="font-size:12px;color:var(--ink-soft);background:color-mix(in oklab,var(--warning,#F59E0B) 8%,var(--bg-elev));border:1px solid color-mix(in oklab,var(--warning,#F59E0B) 25%,transparent);border-radius:8px;padding:12px 14px;display:flex;gap:10px;align-items:flex-start">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2" style="flex:none;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span style="color:#92400E">${infoNote}</span>
        </div>`;
}

function openDowngradePlanModal(slug) {
    const ctx   = window.__DOWNGRADE_CTX__;
    const plans = window.__PLAN_PRICES__ || {};
    const plan  = plans[slug] || {};
    const cycle = ctx.currentCycle;
    const price = cycle === 'yearly' ? plan.yearly : plan.monthly;
    const cycleLabel = cycle === 'yearly' ? 'letno' : 'mes';
    _dwnAction = 'downgrade_plan';
    _dwnSlug   = slug;
    _buildDowngradeBody(
        window.t('billing.dwn_title_plan', {name: plan.name || slug}),
        window.t('billing.dwn_eyebrow_plan'),
        _dwnFmt(price) + ' € / ' + cycleLabel,
        ctx.endsAt,
        window.t('billing.dwn_note_plan')
    );
    document.getElementById('dwn-confirm-btn').textContent = window.t('billing.confirm_downgrade_btn');
    document.getElementById('dwn-confirm-btn').onclick = function() { doDowngrade(this); };
    document.getElementById('downgrade-modal').style.display = 'flex';
}

function openMonthlySwitch() {
    const ctx = window.__DOWNGRADE_CTX__;
    const ms  = ctx.monthlySwitch || {};
    _dwnAction = 'switch_to_monthly';
    _dwnSlug   = null;
    _buildDowngradeBody(
        window.t('billing.monthly_switch_title'),
        window.t('billing.dwn_eyebrow_cycle'),
        _dwnFmt(ms.monthlyPrice) + ' € / mes',
        ctx.endsAt,
        window.t('billing.dwn_note_cycle')
    );
    document.getElementById('dwn-confirm-btn').textContent = window.t('billing.confirm_monthly_btn');
    document.getElementById('dwn-confirm-btn').onclick = function() { doDowngrade(this); };
    document.getElementById('downgrade-modal').style.display = 'flex';
}

function closeDowngradeModal() {
    document.getElementById('downgrade-modal').style.display = 'none';
}

async function doDowngrade(btn) {
    const origText = btn?.textContent;
    if (btn) { btn.disabled = true; btn.textContent = window.t('billing.saving'); }
    const body = _dwnAction === 'switch_to_monthly'
        ? { action: 'switch_to_monthly' }
        : { action: 'downgrade_plan', plan_slug: _dwnSlug };
    try {
        const res  = await fetch(APP_STATE.base + '/api/billing.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const data = await res.json();
        closeDowngradeModal();
        if (data.success) {
            toast(data.message || window.t('billing.change_scheduled'));
            setTimeout(() => location.reload(), 1000);
        } else {
            toast(data.error || 'Napaka.', 'error');
            if (btn) { btn.disabled = false; btn.textContent = origText; }
        }
    } catch(e) {
        toast(window.t('billing.err_connection'), 'error');
        if (btn) { btn.disabled = false; btn.textContent = origText; }
    }
}

async function cancelPendingDowngrade(btn) {
    const origText = btn?.textContent;
    if (btn) { btn.disabled = true; btn.textContent = window.t('billing.cancelling'); }
    try {
        const res  = await fetch(APP_STATE.base + '/api/billing.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'cancel_pending_downgrade' }),
        });
        const data = await res.json();
        if (data.success) {
            toast(data.message || window.t('billing.change_cancelled'));
            setTimeout(() => location.reload(), 800);
        } else {
            toast(data.error || 'Napaka.', 'error');
            if (btn) { btn.disabled = false; btn.textContent = origText; }
        }
    } catch(e) {
        toast(window.t('billing.err_connection'), 'error');
        if (btn) { btn.disabled = false; btn.textContent = origText; }
    }
}

// ─── Invoice request ───────────────────────────────────────────
async function requestInvoice(slug) {
    const btn  = document.querySelector('[data-invoice-plan="' + slug + '"]');
    const orig = btn?.textContent;
    if (btn) { btn.disabled = true; btn.textContent = window.t('billing.sending'); }
    try {
        const res  = await fetch(APP_STATE.base + '/api/billing.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'request_invoice', plan_slug: slug, billing_cycle: 'yearly' }),
        });
        const data = await res.json();
        if (data.success) {
            toast(data.message || window.t('billing.request_sent'));
            if (btn) btn.textContent = window.t('billing.request_sent');
        } else {
            toast(data.error || 'Napaka.', 'error');
            if (btn) { btn.disabled = false; btn.textContent = orig; }
        }
    } catch(e) {
        toast(window.t('billing.err_connection'), 'error');
        if (btn) { btn.disabled = false; btn.textContent = orig; }
    }
}

// ─── Customer portal ───────────────────────────────────────────
async function openCustomerPortal() {
    try {
        const res  = await fetch(APP_STATE.base + '/api/billing.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'customer_portal' }),
        });
        const data = await res.json();
        if (data.success && data.data?.url) {
            window.location.href = data.data.url;
        } else {
            toast(data.error || window.t('billing.portal_unavailable'), 'error');
        }
    } catch(e) { toast(window.t('billing.err_connection'), 'error'); }
}

// ─── Invoice history ───────────────────────────────────────────
const planNames = { basic: 'Basic', advanced: 'Advanced', premium: 'Premium' };
const cycleNames = { monthly: 'mesečno', yearly: 'letno' };
const statusMap = {
    synced: ['PLAČANO', 'rz-chip-ok'],
    pending_sync: ['V OBDELAVI', 'rz-chip-mute'],
    sync_error: ['NAPAKA', ''],
    draft: ['OSNUTEK', 'rz-chip-mute'],
    unknown: ['–', 'rz-chip-mute'],
};

async function loadInvoices() {
    const tbody = document.getElementById('invoice-tbody');
    if (!tbody) return;
    try {
        const res  = await fetch(APP_STATE.base + '/api/billing.php?action=invoice_list');
        const data = await res.json();
        if (!data.success || !data.data?.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--ink-mute);padding:24px;font-size:13px">' + window.t('billing.invoices_empty') + '</td></tr>';
            return;
        }
        tbody.innerHTML = data.data.map(inv => {
            const d  = inv.date ? inv.date.split('-').reverse().join('. ') : '–';
            const desc = 'Naročnina ' + (planNames[inv.plan_slug] || inv.plan_slug) + ' · ' + (cycleNames[inv.billing_cycle] || inv.billing_cycle);
            const amt  = inv.total ? parseFloat(inv.total).toFixed(2).replace('.', ',') + ' €' : '–';
            const [sLabel, sCls] = statusMap[inv.status] || ['–', 'rz-chip-mute'];
            const pdfBtn = inv.has_pdf
                ? '<a href="' + APP_STATE.base + '/api/billing.php?action=download_pdf&invoice_id=' + encodeURIComponent(inv.hub_invoice_id) + '" target="_blank" class="rz-link">PDF</a>'
                : '<span style="color:var(--ink-mute);font-size:12px">–</span>';
            return '<tr>' +
                '<td style="font-family:var(--font-mono);font-size:12px">' + d + '</td>' +
                '<td>' + desc + (inv.number ? ' <span style="font-size:11px;color:var(--ink-mute)">#' + inv.number + '</span>' : '') + '</td>' +
                '<td class="rz-td-num" style="font-weight:700;font-family:var(--font-mono)">' + amt + '</td>' +
                '<td><span class="rz-chip ' + sCls + '">' + sLabel + '</span></td>' +
                '<td>' + pdfBtn + '</td>' +
                '</tr>';
        }).join('');
    } catch(e) {
        const tbody = document.getElementById('invoice-tbody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--danger);padding:16px;font-size:13px">Napaka pri nalaganju računov.</td></tr>';
    }
}

<?php if ($hasInvoices): ?>
loadInvoices();
<?php endif; ?>

// ─── Inicializacija cen (letno če je aktivna letna naročnina) ──
if (isYearly) {
    document.querySelectorAll('.cur-price').forEach(el => {
        const v = parseFloat(el.dataset.yearly);
        if (!isNaN(v)) el.textContent = v.toFixed(2).replace('.', ',') + ' €';
    });
    document.querySelectorAll('.orig-price').forEach(el => {
        if (el.dataset.yearly) el.textContent = el.dataset.yearly + ' €';
    });
    document.querySelectorAll('.cycle-label').forEach(el => {
        el.textContent = '/ ' + window.t('billing.period_year_short');
    });
}

// ─── Auto-select ob prihodu z landing page ─────────────────────
(function () {
    const autoselect = <?= json_encode($_GET['autoselect'] ?? '') ?>;
    const valid = ['basic', 'advanced', 'premium'];
    if (autoselect && valid.includes(autoselect)) selectPlan(autoselect);
})();
</script>

</main>
</div><!-- /rz-app -->
</body>
</html>
