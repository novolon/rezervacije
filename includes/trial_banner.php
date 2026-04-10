<?php
/**
 * Trial warning banner – vključi takoj za <body> v admin straneh.
 * Pričakuje, da je refresh_subscription_session() že bil klican.
 */
if (($_SESSION['role'] ?? '') !== 'admin') return;

$daysLeft     = (int)($_SESSION['trial_days_left'] ?? 0);
$trialExpired = (bool)($_SESSION['trial_expired']  ?? false);
$planSlug     = $_SESSION['plan_slug'] ?? 'trial';

if ($planSlug !== 'trial') return; // plačljivi paket – ni bannerja
?>
<?php if ($trialExpired): ?>
<div class="trial-banner trial-banner-expired">
    <span>Vaš brezplačni trial je potekel. Za nadaljevanje izberite paket.</span>
    <a href="<?= BASE_PATH ?>/pages/billing.php" class="trial-banner-btn">Izberi paket</a>
</div>
<?php elseif ($daysLeft > 0 && $daysLeft <= 7): ?>
<div class="trial-banner trial-banner-warning">
    <span>Vaš trial poteče čez <strong><?= $daysLeft ?> <?= $daysLeft === 1 ? 'dan' : 'dni' ?></strong>.</span>
    <a href="<?= BASE_PATH ?>/pages/billing.php" class="trial-banner-btn">Izberi paket →</a>
</div>
<?php else: ?>
<div class="trial-banner" style="background:#F0FDF4;border-bottom:1px solid #BBF7D0;color:#166534">
    <span>Brezplačni trial je aktiven.</span>
    <a href="<?= BASE_PATH ?>/pages/billing.php" class="trial-banner-btn" style="background:rgba(0,0,0,.07)">Poglej pakete →</a>
</div>
<?php endif; ?>
<script>document.body.classList.add('has-trial-banner');</script>
