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

$currentPlan  = $sub['plan_slug'] ?? 'basic';
if ($currentPlan === 'trial') $currentPlan = 'basic'; // legacy fallback
$isOnTrial    = is_on_trial($sub);
$isActive     = ($sub['status'] ?? '') === 'active';
$trialDaysLeft= get_trial_days_left($sub);
$fullName     = $_SESSION['full_name'];

// Popusti za vsak paket
$discounts = [];
foreach (['basic', 'advanced', 'premium'] as $slug) {
    $discounts[$slug] = get_active_discount($pdo, $slug);
}

// Proration izračun za plačljive upgrade opcije
$prorations = [];
if ($isActive) {
    foreach (['basic', 'advanced', 'premium'] as $slug) {
        if (get_plan_rank($slug) > get_plan_rank($currentPlan)) {
            $prorations[$slug] = calculate_upgrade_proration($sub, $slug, $pdo);
        }
    }
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
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/design.css?v=1">
</head>
<body>

<div id="rz-app" class="rz-app">
<?php require_once '../includes/sidebar.php'; ?>
<main class="rz-main">

<?php require_once '../includes/trial_banner.php'; ?>

<div class="admin-layout">
<div class="admin-content" style="max-width:920px">

    <!-- Trenutni paket -->
    <div style="margin-bottom:28px;padding:20px 24px;background:var(--color-surface);border:1px solid var(--color-border);border-radius:var(--radius);display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
        <div>
            <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:var(--color-muted);font-weight:600;margin-bottom:4px">
                <?= $isOnTrial ? t('billing.trial_testing') : t('billing.active_plan') ?>
            </div>
            <div style="font-size:1.4rem;font-weight:700;color:#111827"><?= h(PLANS[$currentPlan]['name']) ?></div>
            <?php if ($sub): ?>
                <?php if ($isOnTrial && $sub['ends_at']): ?>
                    <div style="font-size:.85rem;color:var(--color-muted);margin-top:3px">
                        <?= t('billing.trial_expires') ?> <?= date('d. m. Y', strtotime($sub['ends_at'])) ?>
                        <?php if ($trialDaysLeft > 0): ?>
                            <span style="color:#92400E;font-weight:600"><?= t('billing.days_left', ['days' => $trialDaysLeft]) ?></span>
                        <?php endif; ?>
                    </div>
                <?php elseif ($isActive && $sub['ends_at']): ?>
                    <div style="font-size:.85rem;color:var(--color-muted);margin-top:3px">
                        <?= t('billing.subscription_until') ?> <?= date('d. m. Y', strtotime($sub['ends_at'])) ?>
                        <span style="margin-left:6px;color:#6B7280">(<?= $sub['billing_cycle'] === 'yearly' ? t('billing.billing_yearly') : t('billing.billing_monthly') ?>)</span>
                    </div>
                <?php elseif ($isActive && !$sub['ends_at']): ?>
                    <div style="font-size:.85rem;color:#065F46;margin-top:3px"><?= t('billing.active_permanent') ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($isActive && ($sub['payment_method'] ?? '') === 'stripe'): ?>
            <button onclick="openCustomerPortal()" class="btn btn-ghost" style="font-size:.85rem">
                <?= t('billing.manage_subscription') ?>
            </button>
        <?php endif; ?>
    </div>

    <?php if ($isOnTrial): ?>
    <!-- ═══════════════════════════════════════════════════════
         TRIAL POGLED – preklop paketov + zakup
         ═══════════════════════════════════════════════════════ -->

    <div style="margin-bottom:20px;padding:14px 18px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:var(--radius);font-size:.85rem;color:#1E40AF">
        <?= t_raw('billing.trial_info') ?>
    </div>

    <!-- Billing cycle toggle -->
    <div style="display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:28px">
        <span style="font-size:.9rem;font-weight:500;color:#374151"><?= t('billing.toggle_monthly') ?></span>
        <label class="billing-toggle">
            <input type="checkbox" id="billing-yearly">
            <span class="billing-toggle-track"></span>
        </label>
        <span style="font-size:.9rem;font-weight:500;color:#374151"><?= t('billing.toggle_yearly') ?>
            <span style="background:#D1FAE5;color:#065F46;font-size:.7rem;font-weight:700;padding:2px 7px;border-radius:20px;margin-left:5px"><?= t('billing.yearly_save') ?></span>
        </span>
    </div>

    <!-- Pricing kartice za trial -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;margin-bottom:28px">
        <?php foreach (['basic','advanced','premium'] as $slug):
            $plan        = PLANS[$slug];
            $discount    = $discounts[$slug];
            $isCurrent   = $slug === $currentPlan;
            $isHighlighted = $slug === 'advanced';
        ?>
        <div class="pricing-card <?= $isHighlighted ? 'pricing-card-featured' : '' ?> <?= $isCurrent ? 'pricing-card-current' : '' ?>">
            <?php if ($isHighlighted && !$isCurrent): ?><div class="pricing-badge"><?= t('billing.recommended') ?></div><?php endif; ?>
            <?php if ($isCurrent): ?><div class="pricing-badge pricing-badge-current"><?= t('billing.currently_testing') ?></div><?php endif; ?>

            <div class="pricing-name"><?= h($plan['name']) ?></div>

            <div class="pricing-price" data-monthly="<?= $plan['monthly_price'] ?>" data-yearly="<?= $plan['yearly_price'] ?>"
                 data-disc-monthly="<?= $discount ? $discount['discounted_monthly'] : '' ?>"
                 data-disc-yearly="<?= $discount ? $discount['discounted_yearly'] : '' ?>">
                <?php if ($discount && $discount['discounted_monthly']): ?>
                    <span class="pricing-orig"><?= number_format($plan['monthly_price'], 2) ?> €</span>
                    <span class="pricing-amount"><?= number_format($discount['discounted_monthly'], 2) ?> €</span>
                <?php else: ?>
                    <span class="pricing-amount"><?= number_format($plan['monthly_price'], 2) ?> €</span>
                <?php endif; ?>
                <span class="pricing-period"><?= t('billing.period_month') ?></span>
            </div>
            <?php if ($discount): ?>
                <div style="font-size:.72rem;color:#065F46;margin:-8px 0 10px;font-weight:600"><?= h($discount['label']) ?> – do <?= date('d. m.', strtotime($discount['valid_until'])) ?></div>
            <?php endif; ?>

            <ul class="pricing-features">
                <?php foreach (FEATURE_LABELS as $fSlug => $fLabel):
                    $included = in_array($fSlug, $plan['features']);
                ?>
                <li class="<?= $included ? 'feat-yes' : 'feat-no' ?>">
                    <?php if ($included): ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php else: ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <?php endif; ?>
                    <?= h($fLabel) ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <!-- Preklop trial paketa (brezplačno) -->
            <?php if ($isCurrent): ?>
                <button class="btn btn-ghost" disabled style="width:100%;margin-top:auto;margin-bottom:8px"><?= t('billing.currently_testing_btn') ?></button>
            <?php else: ?>
                <button class="btn <?= $isHighlighted ? 'btn-primary' : 'btn-outline' ?>"
                        style="width:100%;margin-top:auto;margin-bottom:8px"
                        onclick="switchTrialPlan('<?= $slug ?>', this)">
                    <?= t('billing.try_plan', ['name' => h($plan['name'])]) ?>
                </button>
            <?php endif; ?>

            <!-- Zakup (Stripe checkout) -->
            <button class="btn-checkout-main" data-plan="<?= $slug ?>"
                    onclick="selectPlan('<?= $slug ?>')">
                <?= t('billing.buy_plan', ['name' => h($plan['name'])]) ?>
            </button>
            <button class="btn-invoice" data-invoice-plan="<?= $slug ?>"
                    onclick="requestInvoice('<?= $slug ?>')" style="display:none">
                <?= t('billing.invoice_link') ?>
            </button>
        </div>
        <?php endforeach; ?>
    </div>

    <div style="text-align:center;font-size:.8rem;color:var(--color-muted);margin-bottom:40px">
        <?= t('billing.secure_payment') ?>
    </div>

    <?php elseif ($isActive): ?>
    <!-- ═══════════════════════════════════════════════════════
         PLAČLJIV NAROČNIK – funkcionalnosti + morebitna nadgradnja
         ═══════════════════════════════════════════════════════ -->

    <!-- Vključene funkcionalnosti -->
    <div style="max-width:520px;margin:0 auto 32px">
        <h3 style="font-size:.85rem;text-transform:uppercase;letter-spacing:.06em;color:var(--color-muted);font-weight:600;margin-bottom:14px"><?= t('billing.included_features') ?></h3>
        <ul class="pricing-features" style="background:var(--color-surface);border:1px solid var(--color-border);border-radius:var(--radius);padding:18px 20px;gap:10px">
            <?php foreach (FEATURE_LABELS as $fSlug => $fLabel):
                $included = in_array($fSlug, PLANS[$currentPlan]['features']);
            ?>
            <li class="<?= $included ? 'feat-yes' : 'feat-no' ?>">
                <?php if ($included): ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <?php else: ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?php endif; ?>
                <?= h($fLabel) ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- Nadgradnja (samo če obstaja višji paket) -->
    <?php
    $upgradeSlugs = array_filter(['basic','advanced','premium'], fn($s) => get_plan_rank($s) > get_plan_rank($currentPlan));
    ?>
    <?php if (!empty($upgradeSlugs)): ?>
    <div style="margin-bottom:40px">
        <h3 style="font-size:.85rem;text-transform:uppercase;letter-spacing:.06em;color:var(--color-muted);font-weight:600;margin-bottom:16px"><?= t('billing.available_upgrades') ?></h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
            <?php foreach ($upgradeSlugs as $slug):
                $plan      = PLANS[$slug];
                $proration = $prorations[$slug] ?? [];
                $discount  = $discounts[$slug];
                $isHighlighted = $slug === 'advanced';
            ?>
            <div class="pricing-card <?= $isHighlighted ? 'pricing-card-featured' : '' ?>">
                <?php if ($isHighlighted): ?><div class="pricing-badge"><?= t('billing.recommended') ?></div><?php endif; ?>

                <div class="pricing-name"><?= h($plan['name']) ?></div>

                <div style="display:flex;align-items:baseline;gap:4px;margin-bottom:6px;flex-wrap:wrap">
                    <?php if ($discount && $discount['discounted_monthly']): ?>
                        <span style="font-size:1rem;color:#9CA3AF;text-decoration:line-through"><?= number_format($plan['monthly_price'], 2) ?> €</span>
                        <span style="font-size:1.6rem;font-weight:700;color:#111827"><?= number_format($discount['discounted_monthly'], 2) ?> €</span>
                    <?php else: ?>
                        <span style="font-size:1.6rem;font-weight:700;color:#111827"><?= number_format($plan['monthly_price'], 2) ?> €</span>
                    <?php endif; ?>
                    <span style="font-size:.8rem;color:var(--color-muted)"><?= t('billing.period_month') ?></span>
                </div>

                <?php if (!empty($proration)): ?>
                <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:.78rem;color:#166534">
                    <div style="font-weight:600;margin-bottom:3px"><?= t('billing.proration_title') ?></div>
                    <div><?= t('billing.proration_remaining', ['remaining' => $proration['remaining_days'], 'total' => $proration['period_days']]) ?></div>
                    <div><?= t('billing.proration_credit', ['name' => h(PLANS[$proration['old_plan']]['name']), 'amount' => number_format($proration['credit'], 2)]) ?></div>
                    <div><?= t('billing.proration_charge_now') ?> <strong><?= number_format($proration['charge_now'], 2) ?> €</strong></div>
                    <div style="margin-top:3px;color:#065F46"><?= t('billing.proration_next', ['price' => number_format($proration['next_period_price'], 2), 'cycle' => $proration['cycle'] === 'yearly' ? t('billing.proration_cycle_yearly') : t('billing.proration_cycle_monthly')]) ?></div>
                </div>
                <?php endif; ?>

                <ul class="pricing-features" style="margin-bottom:16px">
                    <?php foreach (FEATURE_LABELS as $fSlug => $fLabel):
                        $included = in_array($fSlug, $plan['features']);
                        $wasIncluded = in_array($fSlug, PLANS[$currentPlan]['features']);
                        if (!$included) continue; // prikaži samo kar paket ima
                    ?>
                    <li class="feat-yes" <?= !$wasIncluded ? 'style="font-weight:600"' : '' ?>>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="<?= !$wasIncluded ? '#F59E0B' : '#10B981' ?>" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        <?= h($fLabel) ?>
                        <?php if (!$wasIncluded): ?>
                            <span style="font-size:.68rem;background:#FEF3C7;color:#92400E;border-radius:10px;padding:1px 5px;margin-left:4px"><?= t('billing.new') ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <?php if (!empty($proration)): ?>
                <button class="btn <?= $isHighlighted ? 'btn-primary' : 'btn-outline' ?>"
                        style="width:100%"
                        onclick="upgradePlan('<?= $slug ?>', this, <?= json_encode($proration['charge_now']) ?>)">
                    <?= t('billing.upgrade_to', ['name' => h($plan['name'])]) ?>
                    <?php if ($proration['charge_now'] > 0): ?>
                        (<?= number_format($proration['charge_now'], 2) ?> €)
                    <?php endif; ?>
                </button>
                <?php else: ?>
                <button class="btn <?= $isHighlighted ? 'btn-primary' : 'btn-outline' ?>"
                        style="width:100%"
                        onclick="upgradePlan('<?= $slug ?>', this, 0)">
                    <?= t('billing.upgrade_to', ['name' => h($plan['name'])]) ?>
                </button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php else: ?>
    <p style="font-size:.85rem;color:var(--color-muted);text-align:center;margin-bottom:40px">
        <?= t('billing.highest_plan') ?>
    </p>
    <?php endif; ?>

    <?php else: ?>
    <!-- ═══════════════════════════════════════════════════════
         POTEKEL TRIAL / BREZ NAROČNINE
         ═══════════════════════════════════════════════════════ -->

    <div style="margin-bottom:20px;padding:14px 18px;background:#FEF2F2;border:1px solid #FECACA;border-radius:var(--radius);font-size:.85rem;color:#991B1B">
        <?= t('billing.trial_expired') ?>
    </div>

    <!-- Billing cycle toggle -->
    <div style="display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:28px">
        <span style="font-size:.9rem;font-weight:500;color:#374151"><?= t('billing.toggle_monthly') ?></span>
        <label class="billing-toggle">
            <input type="checkbox" id="billing-yearly">
            <span class="billing-toggle-track"></span>
        </label>
        <span style="font-size:.9rem;font-weight:500;color:#374151"><?= t('billing.toggle_yearly') ?>
            <span style="background:#D1FAE5;color:#065F46;font-size:.7rem;font-weight:700;padding:2px 7px;border-radius:20px;margin-left:5px"><?= t('billing.yearly_save') ?></span>
        </span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;margin-bottom:28px">
        <?php foreach (['basic','advanced','premium'] as $slug):
            $plan        = PLANS[$slug];
            $discount    = $discounts[$slug];
            $isHighlighted = $slug === 'advanced';
        ?>
        <div class="pricing-card <?= $isHighlighted ? 'pricing-card-featured' : '' ?>">
            <?php if ($isHighlighted): ?><div class="pricing-badge"><?= t('billing.recommended') ?></div><?php endif; ?>

            <div class="pricing-name"><?= h($plan['name']) ?></div>

            <div class="pricing-price" data-monthly="<?= $plan['monthly_price'] ?>" data-yearly="<?= $plan['yearly_price'] ?>"
                 data-disc-monthly="<?= $discount ? $discount['discounted_monthly'] : '' ?>"
                 data-disc-yearly="<?= $discount ? $discount['discounted_yearly'] : '' ?>">
                <?php if ($discount && $discount['discounted_monthly']): ?>
                    <span class="pricing-orig"><?= number_format($plan['monthly_price'], 2) ?> €</span>
                    <span class="pricing-amount"><?= number_format($discount['discounted_monthly'], 2) ?> €</span>
                <?php else: ?>
                    <span class="pricing-amount"><?= number_format($plan['monthly_price'], 2) ?> €</span>
                <?php endif; ?>
                <span class="pricing-period"><?= t('billing.period_month') ?></span>
            </div>

            <ul class="pricing-features">
                <?php foreach (FEATURE_LABELS as $fSlug => $fLabel):
                    $included = in_array($fSlug, $plan['features']);
                ?>
                <li class="<?= $included ? 'feat-yes' : 'feat-no' ?>">
                    <?php if ($included): ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php else: ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <?php endif; ?>
                    <?= h($fLabel) ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <button class="btn <?= $isHighlighted ? 'btn-primary' : 'btn-outline' ?>" style="width:100%;margin-top:auto;margin-bottom:8px"
                    onclick="selectPlan('<?= $slug ?>')">
                <?= t('billing.buy_plan', ['name' => h($plan['name'])]) ?>
            </button>
            <button class="btn-invoice" data-invoice-plan="<?= $slug ?>"
                    onclick="requestInvoice('<?= $slug ?>')" style="display:none">
                <?= t('billing.invoice_link') ?>
            </button>
        </div>
        <?php endforeach; ?>
    </div>

    <div style="text-align:center;font-size:.8rem;color:var(--color-muted);margin-bottom:40px">
        <?= t('billing.secure_payment_short') ?>
    </div>
    <?php endif; ?>

</div>
</div>

<div id="toast-container"></div>

<style>
.billing-toggle { position:relative; display:inline-block; width:44px; height:24px; }
.billing-toggle input { opacity:0; width:0; height:0; }
.billing-toggle-track {
    position:absolute; inset:0; border-radius:24px;
    background:#D1D5DB; cursor:pointer; transition:background .2s;
}
.billing-toggle-track::before {
    content:''; position:absolute; width:18px; height:18px; border-radius:50%;
    left:3px; top:3px; background:#fff; transition:transform .2s;
}
.billing-toggle input:checked + .billing-toggle-track { background:var(--color-primary); }
.billing-toggle input:checked + .billing-toggle-track::before { transform:translateX(20px); }

.pricing-card {
    position:relative; display:flex; flex-direction:column; gap:0;
    background:var(--color-surface); border:1px solid var(--color-border);
    border-radius:var(--radius); padding:24px 20px 20px;
}
.pricing-card-featured { border-color:var(--color-primary); box-shadow:0 0 0 1px var(--color-primary); }
.pricing-card-current  { border-color:#10B981; box-shadow:0 0 0 1px #10B981; }
.pricing-badge {
    position:absolute; top:-12px; left:50%; transform:translateX(-50%);
    background:var(--color-primary); color:#fff; font-size:.7rem; font-weight:700;
    padding:3px 12px; border-radius:20px; white-space:nowrap; letter-spacing:.03em;
}
.pricing-badge-current { background:#10B981; }
.pricing-name { font-size:1.1rem; font-weight:700; color:#111827; margin-bottom:12px; }
.pricing-price { display:flex; align-items:baseline; gap:4px; margin-bottom:14px; flex-wrap:wrap; }
.pricing-amount { font-size:1.8rem; font-weight:700; color:#111827; }
.pricing-orig   { font-size:1rem; color:#9CA3AF; text-decoration:line-through; }
.pricing-period { font-size:.8rem; color:var(--color-muted); }
.pricing-features { list-style:none; margin:0 0 20px; padding:0; display:flex; flex-direction:column; gap:7px; flex:1; }
.pricing-features li { display:flex; align-items:center; gap:8px; font-size:.82rem; color:#374151; }
.feat-no { color:#9CA3AF; }
.btn-outline {
    border:1.5px solid var(--color-primary); color:var(--color-primary);
    background:transparent; padding:.5rem 1rem; border-radius:var(--radius);
    font-size:.875rem; font-weight:600; cursor:pointer;
}
.btn-outline:hover { background:rgba(245,158,11,.08); }
.btn-checkout-main {
    display:block; width:100%; margin-top:0;
    padding:.48rem 1rem; border:1.5px solid #10B981; border-radius:var(--radius);
    background:#F0FDF4; color:#065F46; font-size:.82rem; font-weight:600;
    cursor:pointer; text-align:center; transition:background .15s, border-color .15s;
}
.btn-checkout-main:hover { background:#D1FAE5; border-color:#059669; }
.btn-invoice {
    display: block; width: 100%; margin-top: 8px;
    padding: .42rem 1rem; border: 1.5px dashed #D1D5DB; border-radius: var(--radius);
    background: transparent; color: var(--color-muted); font-size: .78rem;
    font-weight: 500; cursor: pointer; text-align: center;
    transition: border-color .15s, color .15s;
}
.btn-invoice:hover { border-color: #9CA3AF; color: #374151; }
</style>

<script>
window.__T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
window.t = function(k, p) { var s = window.__T__[k] || k; if (p) { for (var x in p) s = s.split('{'+x+'}').join(p[x]); } return s; };
window.APP_STATE = { base: '<?= BASE_PATH ?>' };

// ─── Billing cycle toggle ──────────────────────────────────────
const yearly  = document.getElementById('billing-yearly');
const prices  = document.querySelectorAll('.pricing-price');
const periods = document.querySelectorAll('.pricing-period');

function updatePricing() {
    if (!yearly) return;
    const isYearly = yearly.checked;
    prices.forEach(el => {
        const mPrice = parseFloat(el.dataset.monthly);
        const yPrice = parseFloat(el.dataset.yearly);
        const mDisc  = el.dataset.discMonthly ? parseFloat(el.dataset.discMonthly) : null;
        const yDisc  = el.dataset.discYearly  ? parseFloat(el.dataset.discYearly)  : null;
        const amount = el.querySelector('.pricing-amount');
        const orig   = el.querySelector('.pricing-orig');
        if (isYearly) {
            amount.textContent = (yDisc ?? yPrice).toFixed(2).replace('.', ',') + ' €';
            if (orig) orig.textContent = yPrice.toFixed(2).replace('.', ',') + ' €';
        } else {
            amount.textContent = (mDisc ?? mPrice).toFixed(2).replace('.', ',') + ' €';
            if (orig) orig.textContent = mPrice.toFixed(2).replace('.', ',') + ' €';
        }
    });
    periods.forEach(el => { el.textContent = isYearly ? window.t('billing.period_year') : window.t('billing.period_month'); });
    document.querySelectorAll('.btn-invoice').forEach(el => {
        el.style.display = isYearly ? 'block' : 'none';
    });
}
if (yearly) yearly.addEventListener('change', updatePricing);

// ─── Toast helper ──────────────────────────────────────────────
function toast(msg, type = 'success') {
    const c = document.getElementById('toast-container');
    const t = document.createElement('div');
    t.className = `toast toast-${type}`;
    t.textContent = msg;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(() => t.remove(), 300); }, 3200);
}

// ─── Preklop paketa med trialom (brezplačno) ──────────────────
async function switchTrialPlan(slug, btn) {
    const origText = btn?.textContent;
    if (btn) { btn.disabled = true; btn.textContent = window.t('billing.switching'); }

    try {
        const res  = await fetch(APP_STATE.base + '/api/billing.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'switch_trial_plan', plan_slug: slug }),
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

// ─── Zakup paketa (Stripe Checkout) ───────────────────────────
async function selectPlan(slug) {
    const cycle = document.getElementById('billing-yearly')?.checked ? 'yearly' : 'monthly';
    const btn   = event?.currentTarget;
    if (btn) { btn.disabled = true; btn.textContent = window.t('billing.redirecting'); }

    try {
        const res = await fetch(APP_STATE.base + '/api/billing.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'create_checkout_session', plan_slug: slug, billing_cycle: cycle }),
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

// ─── Nadgradnja plačljive naročnine s proracijo ───────────────
async function upgradePlan(slug, btn, chargeNow) {
    const planName = { basic: 'Basic', advanced: 'Advanced', premium: 'Premium' }[slug] || slug;
    let confirm_msg = window.t('billing.confirm_upgrade', {name: planName});
    if (chargeNow > 0) {
        confirm_msg += window.t('billing.confirm_upgrade_charge', {charge: chargeNow.toFixed(2).replace('.', ',')});
    }
    if (!confirm(confirm_msg)) return;

    const origText = btn?.textContent;
    if (btn) { btn.disabled = true; btn.textContent = window.t('billing.upgrading'); }

    try {
        const res  = await fetch(APP_STATE.base + '/api/billing.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'upgrade_plan', plan_slug: slug }),
        });
        const data = await res.json();
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

// ─── Zahtevek za predračun (letno) ────────────────────────────
async function requestInvoice(slug) {
    const btn  = document.querySelector(`[data-invoice-plan="${slug}"]`);
    const orig = btn?.textContent;
    if (btn) { btn.disabled = true; btn.textContent = window.t('billing.sending'); }

    try {
        const res  = await fetch(APP_STATE.base + '/api/billing.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'request_invoice', plan_slug: slug, billing_cycle: 'yearly' }),
        });
        const data = await res.json();
        if (data.success) {
            toast(data.message || window.t('billing.request_sent'));
            if (btn) { btn.textContent = window.t('billing.request_sent'); }
        } else {
            toast(data.error || 'Napaka.', 'error');
            if (btn) { btn.disabled = false; btn.textContent = orig; }
        }
    } catch(e) {
        toast(window.t('billing.err_connection'), 'error');
        if (btn) { btn.disabled = false; btn.textContent = orig; }
    }
}

// ─── Stripe Customer Portal ────────────────────────────────────
async function openCustomerPortal() {
    try {
        const res  = await fetch(APP_STATE.base + '/api/billing.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'customer_portal' }),
        });
        const data = await res.json();
        if (data.success && data.data?.url) {
            window.location.href = data.data.url;
        } else {
            toast(data.error || window.t('billing.portal_unavailable'), 'error');
        }
    } catch(e) { toast(window.t('billing.err_connection'), 'error'); }
}

// ─── Auto-select ob prihodu z landing page ─────────────────────
(function () {
    const autoselect = <?= json_encode($_GET['autoselect'] ?? '') ?>;
    const valid = ['basic', 'advanced', 'premium'];
    if (autoselect && valid.includes(autoselect)) {
        selectPlan(autoselect);
    }
})();
</script>
</main>
</div><!-- /rz-app -->
</body>
</html>
