<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';

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
$currentPlan = $sub['plan_slug'] ?? 'trial';
$fullName    = $_SESSION['full_name'];

// Popusti za vsak paket
$discounts = [];
foreach (['basic', 'advanced', 'premium'] as $slug) {
    $discounts[$slug] = get_active_discount($pdo, $slug);
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paketi – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin.css">
</head>
<body>

<header class="app-header">
    <a href="<?= BASE_PATH ?>/pages/main.php" class="header-logo">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
            <rect width="28" height="28" rx="7" fill="#F59E0B"/>
            <path d="M7 10h14M7 14h14M7 18h9" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <?= h(APP_NAME) ?>
        <?= plan_badge($currentPlan) ?>
    </a>
    <div class="header-restaurant">
        <span style="color:rgba(255,255,255,.5);font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600">Paketi</span>
    </div>
    <div class="header-actions">
        <span class="header-user">👤 <?= h($fullName) ?></span>
        <a href="<?= BASE_PATH ?>/pages/main.php" class="btn-header btn-header-admin">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            Razpored
        </a>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
    </div>
</header>

<?php require_once '../includes/trial_banner.php'; ?>

<div class="admin-layout">
<div class="admin-content" style="max-width:900px">

    <!-- Trenutni paket -->
    <div style="margin-bottom:32px;padding:20px 24px;background:var(--color-surface);border:1px solid var(--color-border);border-radius:var(--radius);display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
        <div>
            <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:var(--color-muted);font-weight:600;margin-bottom:4px">Vaš trenutni paket</div>
            <div style="font-size:1.4rem;font-weight:700;color:#111827"><?= h(PLANS[$currentPlan]['name']) ?></div>
            <?php if ($sub): ?>
                <?php if ($sub['status'] === 'trial' && $sub['ends_at']): ?>
                    <div style="font-size:.85rem;color:var(--color-muted);margin-top:3px">
                        Trial poteče: <?= date('d. m. Y', strtotime($sub['ends_at'])) ?>
                    </div>
                <?php elseif ($sub['status'] === 'active' && $sub['ends_at']): ?>
                    <div style="font-size:.85rem;color:var(--color-muted);margin-top:3px">
                        Naročnina do: <?= date('d. m. Y', strtotime($sub['ends_at'])) ?>
                    </div>
                <?php elseif ($sub['status'] === 'active' && !$sub['ends_at']): ?>
                    <div style="font-size:.85rem;color:#065F46;margin-top:3px">Aktivna naročnina (trajno)</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($currentPlan !== 'trial' && ($sub['payment_method'] ?? '') === 'stripe'): ?>
            <button onclick="openCustomerPortal()" class="btn btn-ghost" style="font-size:.85rem">
                Upravljaj naročnino →
            </button>
        <?php endif; ?>
    </div>

    <?php $isSubscribed = $currentPlan !== 'trial' && ($sub['status'] ?? '') === 'active'; ?>

    <?php if (!$isSubscribed): ?>
    <!-- Billing cycle toggle -->
    <div style="display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:28px">
        <span style="font-size:.9rem;font-weight:500;color:#374151">Mesečno</span>
        <label class="billing-toggle">
            <input type="checkbox" id="billing-yearly">
            <span class="billing-toggle-track"></span>
        </label>
        <span style="font-size:.9rem;font-weight:500;color:#374151">Letno
            <span style="background:#D1FAE5;color:#065F46;font-size:.7rem;font-weight:700;padding:2px 7px;border-radius:20px;margin-left:5px">prihrani ~17%</span>
        </span>
    </div>

    <!-- Pricing kartice -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;margin-bottom:36px">
        <?php foreach (['basic','advanced','premium'] as $slug):
            $plan     = PLANS[$slug];
            $discount = $discounts[$slug];
            $isCurrent= $slug === $currentPlan;
            $isHighlighted = $slug === 'advanced';
        ?>
        <div class="pricing-card <?= $isHighlighted ? 'pricing-card-featured' : '' ?> <?= $isCurrent ? 'pricing-card-current' : '' ?>">
            <?php if ($isHighlighted): ?><div class="pricing-badge">Priporočeno</div><?php endif; ?>
            <?php if ($isCurrent): ?><div class="pricing-badge pricing-badge-current">Vaš paket</div><?php endif; ?>

            <div class="pricing-name"><?= h($plan['name']) ?></div>

            <!-- Cena (mesečno) -->
            <div class="pricing-price" data-monthly="<?= $plan['monthly_price'] ?>" data-yearly="<?= $plan['yearly_price'] ?>"
                 data-disc-monthly="<?= $discount ? $discount['discounted_monthly'] : '' ?>"
                 data-disc-yearly="<?= $discount ? $discount['discounted_yearly'] : '' ?>">
                <?php if ($discount && $discount['discounted_monthly']): ?>
                    <span class="pricing-orig"><?= number_format($plan['monthly_price'], 2) ?> €</span>
                    <span class="pricing-amount"><?= number_format($discount['discounted_monthly'], 2) ?> €</span>
                <?php else: ?>
                    <span class="pricing-amount"><?= number_format($plan['monthly_price'], 2) ?> €</span>
                <?php endif; ?>
                <span class="pricing-period">/mesec</span>
            </div>
            <?php if ($discount): ?>
                <div style="font-size:.72rem;color:#065F46;margin:-8px 0 10px;font-weight:600"><?= h($discount['label']) ?> – do <?= date('d. m.', strtotime($discount['valid_until'])) ?></div>
            <?php endif; ?>

            <!-- Funkcionalnosti -->
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

            <?php if ($isCurrent): ?>
                <button class="btn btn-ghost" disabled style="width:100%;margin-top:auto">Trenutni paket</button>
            <?php else: ?>
                <button class="btn <?= $isHighlighted ? 'btn-primary' : 'btn-outline' ?>" style="width:100%;margin-top:auto"
                        onclick="selectPlan('<?= $slug ?>')">
                    Izberi <?= h($plan['name']) ?>
                </button>
                <button class="btn-invoice" data-invoice-plan="<?= $slug ?>"
                        onclick="requestInvoice('<?= $slug ?>')" style="display:none">
                    ali po predračunu →
                </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Opomba -->
    <div style="text-align:center;font-size:.8rem;color:var(--color-muted);margin-bottom:40px">
        Plačilo je varno in šifrirano.
        Vsak paket vključuje 30-dnevni trial za testiranje.
    </div>

    <?php else: ?>
    <!-- Naročnik – prikaz funkcionalnosti trenutnega paketa -->
    <div style="max-width:480px;margin:0 auto 40px">
        <h3 style="font-size:.85rem;text-transform:uppercase;letter-spacing:.06em;color:var(--color-muted);font-weight:600;margin-bottom:14px">Vključeno v vašem paketu</h3>
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
        <p style="font-size:.8rem;color:var(--color-muted);text-align:center;margin-top:16px">
            Za spremembo paketa uporabite gumb "Upravljaj naročnino" zgoraj ali nas kontaktirajte.
        </p>
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
.btn-invoice {
    display: block;
    width: 100%;
    margin-top: 8px;
    padding: .42rem 1rem;
    border: 1.5px dashed #D1D5DB;
    border-radius: var(--radius);
    background: transparent;
    color: var(--color-muted);
    font-size: .78rem;
    font-weight: 500;
    cursor: pointer;
    text-align: center;
    transition: border-color .15s, color .15s;
}
.btn-invoice:hover { border-color: #9CA3AF; color: #374151; }
</style>

<script>
window.APP_STATE = { base: '<?= BASE_PATH ?>' };

const yearly = document.getElementById('billing-yearly');
const prices  = document.querySelectorAll('.pricing-price');
const periods = document.querySelectorAll('.pricing-period');

function updatePricing() {
    const isYearly = yearly.checked;
    prices.forEach(el => {
        const mPrice  = parseFloat(el.dataset.monthly);
        const yPrice  = parseFloat(el.dataset.yearly);
        const mDisc   = el.dataset.discMonthly ? parseFloat(el.dataset.discMonthly) : null;
        const yDisc   = el.dataset.discYearly  ? parseFloat(el.dataset.discYearly)  : null;
        const amount  = el.querySelector('.pricing-amount');
        const orig    = el.querySelector('.pricing-orig');
        if (isYearly) {
            amount.textContent = (yDisc ?? yPrice).toFixed(2).replace('.', ',') + ' €';
            if (orig) orig.textContent = yPrice.toFixed(2).replace('.', ',') + ' €';
        } else {
            amount.textContent = (mDisc ?? mPrice).toFixed(2).replace('.', ',') + ' €';
            if (orig) orig.textContent = mPrice.toFixed(2).replace('.', ',') + ' €';
        }
    });
    periods.forEach(el => { el.textContent = isYearly ? '/leto' : '/mesec'; });
    document.querySelectorAll('.btn-invoice').forEach(el => {
        el.style.display = isYearly ? 'block' : 'none';
    });
}
yearly.addEventListener('change', updatePricing);

function toast(msg, type = 'success') {
    const c = document.getElementById('toast-container');
    const t = document.createElement('div');
    t.className = `toast toast-${type}`;
    t.textContent = msg;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(() => t.remove(), 300); }, 3200);
}

async function requestInvoice(slug) {
    const btn  = document.querySelector(`[data-invoice-plan="${slug}"]`);
    const orig = btn?.textContent;
    if (btn) { btn.disabled = true; btn.textContent = 'Pošiljam…'; }

    try {
        const res  = await fetch(APP_STATE.base + '/api/billing.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'request_invoice', plan_slug: slug, billing_cycle: 'yearly' }),
        });
        const data = await res.json();
        if (data.success) {
            toast(data.message || 'Zahtevek poslan!');
            if (btn) { btn.textContent = 'Zahtevek poslan ✓'; }
        } else {
            toast(data.error || 'Napaka.', 'error');
            if (btn) { btn.disabled = false; btn.textContent = orig; }
        }
    } catch(e) {
        toast('Napaka pri povezavi.', 'error');
        if (btn) { btn.disabled = false; btn.textContent = orig; }
    }
}

async function selectPlan(slug) {
    const cycle = document.getElementById('billing-yearly').checked ? 'yearly' : 'monthly';
    const btn   = document.querySelector(`[onclick="selectPlan('${slug}')"]`);
    if (btn) { btn.disabled = true; btn.textContent = 'Preusmerjam…'; }

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
            alert(data.error || 'Napaka pri plačilu.');
            if (btn) { btn.disabled = false; btn.textContent = 'Izberi ' + slug.charAt(0).toUpperCase() + slug.slice(1); }
        }
    } catch(e) {
        alert('Napaka pri povezavi.');
        if (btn) { btn.disabled = false; }
    }
}

// Auto-select paket ob prihodu z landing page
(function () {
    const autoselect = <?= json_encode($_GET['autoselect'] ?? '') ?>;
    const valid = ['basic', 'advanced', 'premium'];
    if (autoselect && valid.includes(autoselect)) {
        selectPlan(autoselect);
    }
})();

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
            alert(data.error || 'Portal ni na voljo.');
        }
    } catch(e) { alert('Napaka pri povezavi.'); }
}
</script>
</body>
</html>
