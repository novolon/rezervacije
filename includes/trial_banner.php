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
<?php elseif ($daysLeft <= 7): ?>
<div class="trial-banner trial-banner-warning">
    <span>Vaš trial poteče čez <strong><?= $daysLeft ?> <?= $daysLeft === 1 ? 'dan' : ($daysLeft <= 4 ? 'dni' : 'dni') ?></strong>.</span>
    <a href="<?= BASE_PATH ?>/pages/billing.php" class="trial-banner-btn">Izberi paket →</a>
</div>
<?php endif; ?>
