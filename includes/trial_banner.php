<?php
/**
 * Trial warning banner – vključi takoj za <body> v admin straneh.
 * Pričakuje, da je refresh_subscription_session() že bil klican.
 */
if (($_SESSION['role'] ?? '') !== 'admin') return;

$isOnTrial    = (bool)($_SESSION['is_on_trial']    ?? false);
$daysLeft     = (int) ($_SESSION['trial_days_left'] ?? 0);
$trialExpired = (bool)($_SESSION['trial_expired']   ?? false);
$planSlug     = $_SESSION['plan_slug'] ?? 'basic';
$planName     = PLANS[$planSlug]['name'] ?? 'Basic';

if (!$isOnTrial && !$trialExpired) return; // plačljivi aktivni paket – ni bannerja
?>
<?php
$dayWord = $daysLeft === 1 ? t('trial_banner.day_singular') : t('trial_banner.day_plural');
?>
<?php if ($trialExpired): ?>
<div class="trial-banner trial-banner-expired">
    <span><?= t('trial_banner.expired') ?></span>
    <a href="<?= BASE_PATH ?>/pages/billing.php" class="trial-banner-btn"><?= t('trial_banner.expired_btn') ?></a>
</div>
<?php elseif ($daysLeft > 0 && $daysLeft <= 7): ?>
<div class="trial-banner trial-banner-warning">
    <span><?= t_raw('trial_banner.warning_html', ['plan' => h($planName), 'days' => $daysLeft, 'dayWord' => $dayWord]) ?></span>
    <a href="<?= BASE_PATH ?>/pages/billing.php" class="trial-banner-btn"><?= t('trial_banner.warning_btn') ?></a>
</div>
<?php else: ?>
<div class="trial-banner" style="background:#F0FDF4;border-bottom:1px solid #BBF7D0;color:#166534">
    <span><?= t_raw('trial_banner.active_html', ['plan' => h($planName)]) ?></span>
    <a href="<?= BASE_PATH ?>/pages/billing.php" class="trial-banner-btn" style="background:rgba(0,0,0,.07)"><?= t('trial_banner.active_btn') ?></a>
</div>
<?php endif; ?>
<script>document.body.classList.add('has-trial-banner');</script>
