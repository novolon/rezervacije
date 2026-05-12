<?php
require_once 'includes/auth_check.php';
require_once 'includes/lang.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/mailer.php';
require_once 'includes/affiliate_helper.php';

if (is_logged_in()) {
    redirect_to_main();
}

$errors  = [];
$success = false;
$post    = [];

require_once 'includes/plans.php';

$validPlans    = ['basic', 'advanced', 'premium'];
$selectedPlan  = in_array($_POST['plan'] ?? $_GET['plan'] ?? '', $validPlans) ? ($_POST['plan'] ?? $_GET['plan']) : 'basic';
$planNames     = ['basic' => 'Basic', 'advanced' => 'Advanced', 'premium' => 'Premium'];

// Plan discount + UI info ────────────────────────────────────────────────────
$pdoForPlans = function() {
    static $p = null;
    if ($p === null) { $p = getDB(); }
    return $p;
};
$planUiData = [];
foreach ($validPlans as $_slug) {
    $monthly = (float) PLAN_PRICES[$_slug]['monthly'];
    $yearly  = (float) PLAN_PRICES[$_slug]['yearly'];
    $disc    = get_active_discount($pdoForPlans(), $_slug);
    $finalMonthly = $monthly;
    $finalYearly  = $yearly;
    $discPct = null;
    if ($disc) {
        if (isset($disc['discounted_monthly']) && $disc['discounted_monthly'] !== null) {
            $finalMonthly = (float)$disc['discounted_monthly'];
        }
        if (isset($disc['discounted_yearly']) && $disc['discounted_yearly'] !== null) {
            $finalYearly = (float)$disc['discounted_yearly'];
        }
        if ($monthly > 0 && $finalMonthly < $monthly) {
            $discPct = (int) round(100 * (1 - $finalMonthly / $monthly));
        }
    }
    $planUiData[$_slug] = [
        'name'           => $planNames[$_slug],
        'orig_monthly'   => $monthly,
        'final_monthly'  => $finalMonthly,
        'has_discount'   => ($finalMonthly < $monthly),
        'discount_pct'   => $discPct,
    ];
}

// Feature seznami za tooltip — single source of truth na strani.
// Sklicuje se na obstoječe lang ključe (uporabljene tudi na landingu).
$planFeaturesUi = [
    'basic' => [
        'landing.feat_basic_1', 'landing.feat_basic_2', 'landing.feat_basic_3',
        'landing.feat_basic_4', 'landing.feat_basic_5',
        'landing.feat_stats_title', 'landing.feat_devices_title',
    ],
    'advanced' => [
        'auth.plan_features.adv_all_basic',
        'landing.adv_email_title', 'landing.adv_link_title', 'landing.adv_confirm_title',
        'landing.adv_tables_title', 'landing.adv_guests_title', 'landing.adv_fields_title',
    ],
    'premium' => [
        'auth.plan_features.prem_all_advanced',
        'landing.prem_widget_title', 'landing.prem_merge_title', 'landing.prem_auto_title',
        'landing.prem_survey_title', 'landing.adv_waitlist_title', 'landing.prem_export_title',
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post['full_name']         = trim($_POST['full_name']         ?? '');
    $post['email']             = trim($_POST['email']             ?? '');
    $post['company_name']      = trim($_POST['company_name']      ?? '');
    $post['company_address']   = trim($_POST['company_address']   ?? '');
    $post['tax_number']        = trim($_POST['tax_number']        ?? '');
    $post['is_vat_registered'] = !empty($_POST['is_vat_registered']);
    $post['vat_id']            = strtoupper(trim($_POST['vat_id'] ?? ''));
    $password                  = $_POST['password']               ?? '';
    $confirm                   = $_POST['password_confirm']       ?? '';

    if (!$post['full_name']) {
        $errors[] = t('auth.err_name_required');
    }
    if (!$post['email'] || !filter_var($post['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('auth.err_email_invalid');
    }
    if (strlen($password) < 8) {
        $errors[] = t('auth.err_password_short');
    }
    if ($password !== $confirm) {
        $errors[] = t('auth.err_passwords_mismatch');
    }
    if (!$post['company_name']) {
        $errors[] = t('auth.err_company_name');
    }
    if (!$post['company_address']) {
        $errors[] = t('auth.err_company_address');
    }
    if (empty($_POST['gdpr_consent'])) {
        $errors[] = t('auth.err_gdpr_required');
    }
    if ($post['is_vat_registered']) {
        if (!$post['vat_id']) {
            $errors[] = t('auth.err_vat_id_required');
        } elseif (!preg_match('/^[A-Z]{2}[A-Z0-9]{2,15}$/', $post['vat_id'])) {
            $errors[] = t('auth.err_vat_id_format');
        }
    } else {
        if (!$post['tax_number']) {
            $errors[] = t('auth.err_tax_number_required');
        } elseif (!preg_match('/^[A-Z0-9]{4,20}$/i', $post['tax_number'])) {
            $errors[] = t('auth.err_tax_number_format');
        }
    }

    if (empty($errors)) {
        try {
            $pdo = getDB();

            $hash              = password_hash($password, PASSWORD_BCRYPT);
            $trialEnds         = date('Y-m-d H:i:s', strtotime('+30 days'));
            $verificationToken = bin2hex(random_bytes(32));
            $gdprIp            = $_SERVER['REMOTE_ADDR'] ?? null;
            $gdprNow           = date('Y-m-d H:i:s');
            $marketingConsent  = !empty($_POST['marketing_consent']) ? 1 : 0;

            $pdo->prepare("
                INSERT INTO users
                    (email, password_hash, full_name, company_name, company_address,
                     tax_number, is_vat_registered, vat_id,
                     role, trial_ends_at, subscription_status, is_active, verification_token,
                     gdpr_consent_at, gdpr_consent_ip, dpa_consent_at,
                     marketing_consent, marketing_consent_at, language)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'admin', ?, 'trial', 1, ?,
                        ?, ?, ?, ?, ?, ?)
            ")->execute([
                $post['email'], $hash, $post['full_name'],
                $post['company_name'], $post['company_address'],
                $post['is_vat_registered'] ? null : $post['tax_number'],
                $post['is_vat_registered'] ? 1 : 0,
                $post['is_vat_registered'] ? $post['vat_id'] : null,
                $trialEnds, $verificationToken,
                $gdprNow, $gdprIp, $gdprNow,
                $marketingConsent, $marketingConsent ? $gdprNow : null,
                get_lang(),
            ]);

            $newUserId = (int) $pdo->lastInsertId();

            // Affiliate atribucija: cookie ima prednost, ?code= kot fallback
            // Funkcija sama bere rez_aff cookie; $taxNumber za anti-self-referral check
            $affDiscCode = strtoupper(trim($_GET['code'] ?? '')) ?: null;
            $taxNumber   = $post['is_vat_registered'] ? null : ($post['tax_number'] ?? null);
            attach_affiliate_on_signup($pdo, $newUserId, $post['email'], $taxNumber, $affDiscCode);

            // Ustvari trial subscription z izbranim paketom.
            // status='trial' označuje brezplačno obdobje; po preteku mora zakupiti.
            $pdo->prepare("
                INSERT INTO subscriptions (user_id, plan_slug, status, ends_at)
                VALUES (?, ?, 'trial', ?)
            ")->execute([$newUserId, $selectedPlan, $trialEnds]);

            send_verification_email($post['email'], $post['full_name'], $verificationToken, get_lang());

            $success = true;

            // Analytics: register success (server-side, da ujamemo tudi če JS zataji)
            require_once __DIR__ . '/includes/analytics.php';
            analytics_capture('register_success', $newUserId, [
                'plan'         => $selectedPlan,
                'email'        => $post['email'],
                'is_vat'       => (bool)$post['is_vat_registered'],
                'lang'         => get_lang(),
            ]);
            analytics_set_person($newUserId, [
                'email' => $post['email'],
                'name'  => $post['full_name'],
                'role'  => 'admin',
                'plan'  => $selectedPlan,
            ]);

        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = t('auth.err_email_taken');
                require_once __DIR__ . '/includes/analytics.php';
                analytics_capture('register_failed', null, ['reason' => 'email_taken', 'email' => $post['email']]);
            } else {
                error_log('Register error: ' . $e->getMessage());
                $errors[] = t('auth.err_server');
                require_once __DIR__ . '/includes/analytics.php';
                analytics_capture('register_failed', null, ['reason' => 'server_error']);
            }
        }
    }
}
?>
<?php
$pageTitle = t('auth.register_title');
$extraCss  = ['design.css', 'register.css'];
require_once 'includes/html_head.php';
?>
<body>
<div class="rz-auth rz-auth--register">

    <!-- Leva stran — branding -->
    <div class="rz-auth-left">
        <div class="rz-auth-brand">
            <svg viewBox="0 0 104 32" xmlns="http://www.w3.org/2000/svg" aria-label="Rezble" style="height:26px;width:auto">
                <path fill="currentColor" d="M31.04,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.87-.21,1.56-.64,2.08-1.07,1.3-2.16,2.3-3.25,3.02-1.1.69-2.39,1.04-3.89,1.04s-2.69-.39-3.72-1.17c-1.01-.78-1.97-2.12-2.88-4.02-.74-1.52-1.32-2.64-1.74-3.35-.42-.74-.85-1.26-1.27-1.57-.4-.31-.91-.5-1.51-.57-.09.47-.35,1.94-.77,4.42-.18,1.12-.29,1.8-.34,2.04-.22,1.36-.67,2.41-1.34,3.15-.67.71-1.69,1.07-3.05,1.07-1.5,0-2.83-.44-3.99-1.31-1.14-.89-2.02-2.12-2.65-3.69-.63-1.59-.94-3.38-.94-5.39,0-3.75.65-6.99,1.94-9.72,1.32-2.73,3.15-4.8,5.5-6.23,2.37-1.45,5.1-2.18,8.18-2.18,2.15,0,3.94.32,5.4.97,1.45.65,2.53,1.54,3.22,2.68.72,1.14,1.07,2.42,1.07,3.85,0,1.25-.3,2.48-.91,3.69-.58,1.18-1.46,2.23-2.65,3.15-1.18.92-2.63,1.6-4.32,2.04,1.07.29,1.9.76,2.48,1.41s1.16,1.6,1.74,2.85c.63,1.34,1.24,2.32,1.84,2.95.63.63,1.34.94,2.15.94.72,0,1.4-.23,2.04-.7.65-.49,1.46-1.32,2.45-2.48.27-.31.57-.47.91-.47ZM8.45,20.94c-.76,0-1.29-.18-1.58-.54-.27-.36-.4-.76-.4-1.21,0-.54.17-.96.5-1.27.36-.31.76-.47,1.21-.47h.84c.36-2.19.69-4.08,1.01-5.66.29-1.45,1.23-2.18,2.82-2.18,1.27,0,1.91.57,1.91,1.71,0,.25-.01.44-.03.57l-1.01,5.56c1.21-.07,2.3-.36,3.29-.87,1.01-.51,1.8-1.23,2.38-2.14.6-.92.91-1.95.91-3.12,0-1.41-.48-2.51-1.44-3.32-.96-.8-2.37-1.21-4.22-1.21-2.19,0-4.11.55-5.77,1.64-1.63,1.07-2.91,2.68-3.82,4.83-.92,2.12-1.37,4.71-1.37,7.77,0,1.43.15,2.66.44,3.69.29,1.03.65,1.8,1.07,2.31.42.51.83.77,1.21.77.29,0,.53-.15.7-.44.2-.29.36-.76.47-1.41l.91-5.03Z"/>
                <path fill="currentColor" d="M43.56,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.89-.21,1.59-.64,2.08-.83,1.01-2,1.93-3.52,2.78-1.5.85-3.11,1.27-4.83,1.27-2.35,0-4.17-.64-5.46-1.91-1.3-1.27-1.94-3.02-1.94-5.23,0-1.54.32-2.97.97-4.29.65-1.34,1.54-2.4,2.68-3.18,1.16-.78,2.47-1.17,3.92-1.17,1.3,0,2.34.39,3.12,1.17.78.76,1.17,1.8,1.17,3.12,0,1.54-.56,2.87-1.68,3.99-1.1,1.09-2.96,1.97-5.6,2.61.56,1.03,1.62,1.54,3.18,1.54,1.01,0,2.15-.35,3.42-1.04,1.3-.71,2.41-1.64,3.35-2.78.27-.31.57-.47.91-.47ZM35.11,19.17c-.83,0-1.53.48-2.11,1.44-.56.96-.84,2.12-.84,3.48v.07c1.32-.31,2.36-.78,3.12-1.41.76-.63,1.14-1.35,1.14-2.18,0-.42-.12-.76-.37-1.01-.22-.27-.54-.4-.94-.4Z"/>
                <path fill="currentColor" d="M58.17,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.83-.21,1.52-.64,2.08-1.05,1.36-2.36,2.43-3.92,3.22-1.54.78-3.29,1.17-5.23,1.17-1.52,0-2.84-.22-3.96-.67-1.12-.47-1.98-1.09-2.58-1.88-.58-.8-.87-1.7-.87-2.68,0-1.47.59-2.78,1.78-3.92,1.18-1.14,2.88-2.23,5.1-3.28l-5.5.13c-.49.02-.87-.15-1.14-.5-.25-.38-.37-.83-.37-1.34s.12-1.01.37-1.41c.27-.42.63-.64,1.07-.64,1.03,0,2.4.08,4.12.23.36.02,1.01.07,1.94.13.96.07,1.77.1,2.41.1.22,0,.65-.09,1.27-.27.11-.02.32-.08.64-.17.34-.09.61-.13.84-.13.36,0,.65.16.87.47.25.31.37.79.37,1.44,0,.71-.17,1.27-.5,1.68-.34.4-.86.76-1.58,1.07-2.03.87-3.72,1.81-5.06,2.81-1.34.98-2.01,1.97-2.01,2.95,0,.63.29,1.14.87,1.54.58.4,1.44.6,2.58.6,1.25,0,2.51-.31,3.79-.94,1.3-.63,2.46-1.57,3.49-2.85.27-.31.57-.47.91-.47Z"/>
                <path fill="currentColor" d="M74.2,21.21c.29,0,.51.15.67.44.16.29.23.66.23,1.11,0,.56-.08.99-.23,1.31-.16.29-.4.49-.74.6-1.34.47-2.82.74-4.43.8-.45,1.85-1.3,3.35-2.55,4.49-1.23,1.14-2.59,1.71-4.09,1.71-2.26,0-3.9-.86-4.93-2.58-1.03-1.72-1.54-4.21-1.54-7.47,0-2.88.36-6.01,1.07-9.38.72-3.4,1.75-6.28,3.12-8.65,1.39-2.39,3.03-3.59,4.93-3.59,1.03,0,1.85.45,2.48,1.34.63.87.94,2.01.94,3.42,0,1.83-.35,3.65-1.04,5.46-.69,1.81-1.84,3.71-3.45,5.7,1.5.11,2.72.74,3.65,1.88.94,1.12,1.5,2.5,1.68,4.15,1.05-.07,2.3-.29,3.75-.67.13-.04.29-.07.47-.07ZM64.95,3.32c-.45,0-.94.67-1.47,2.01-.51,1.32-.99,3.12-1.44,5.39-.45,2.28-.78,4.77-1.01,7.47,1.48-2.7,2.65-5.08,3.52-7.14.89-2.08,1.34-3.92,1.34-5.53,0-.71-.09-1.26-.27-1.64-.16-.38-.38-.57-.67-.57ZM63.21,28.11c.69,0,1.31-.29,1.84-.87.54-.58.89-1.42,1.07-2.51-.69-.47-1.23-1.08-1.61-1.84-.36-.76-.54-1.56-.54-2.41,0-.31.04-.74.13-1.27h-.1c-.92,0-1.69.46-2.31,1.37-.6.89-.91,2.1-.91,3.62,0,1.27.23,2.25.7,2.92.49.67,1.06,1.01,1.71,1.01Z"/>
                <path fill="currentColor" d="M84.92,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.89-.21,1.59-.64,2.08-.96,1.18-2.01,2.16-3.15,2.92-1.12.76-2.39,1.14-3.82,1.14-1.97,0-3.43-.89-4.39-2.68-.94-1.79-1.41-4.1-1.41-6.94s.35-5.83,1.04-9.32c.72-3.48,1.75-6.48,3.12-8.98,1.39-2.5,3.03-3.75,4.93-3.75,1.07,0,1.91.5,2.51,1.51.63.98.94,2.4.94,4.26,0,2.66-.74,5.74-2.21,9.25-1.47,3.51-3.48,6.98-6,10.42.16.92.41,1.57.77,1.98.36.38.83.57,1.41.57.92,0,1.72-.26,2.41-.77.69-.54,1.58-1.44,2.65-2.71.27-.31.57-.47.91-.47ZM80.79,3.32c-.51,0-1.1.93-1.74,2.78-.65,1.85-1.22,4.15-1.71,6.9-.49,2.75-.76,5.38-.8,7.91,1.59-2.61,2.85-5.23,3.79-7.84.94-2.64,1.41-5.04,1.41-7.2,0-1.7-.31-2.55-.94-2.55Z"/>
                <path fill="currentColor" d="M95.15,25.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.8-.19,1.5-.57,2.08-.63.96-1.45,1.71-2.48,2.25-1.01.54-2.21.8-3.62.8-2.15,0-3.81-.64-4.99-1.91-1.18-1.3-1.78-3.04-1.78-5.23,0-1.54.32-2.97.97-4.29.65-1.34,1.54-2.4,2.68-3.18,1.16-.78,2.47-1.17,3.92-1.17,1.3,0,2.34.39,3.12,1.17.78.76,1.17,1.8,1.17,3.12,0,1.54-.56,2.87-1.68,3.99-1.1,1.09-2.97,1.97-5.63,2.61.54,1.03,1.44,1.54,2.72,1.54.92,0,1.66-.21,2.25-.64.6-.42,1.3-1.14,2.08-2.14.27-.34.57-.5.91-.5ZM89.65,19.17c-.83,0-1.53.48-2.11,1.44-.56.96-.84,2.12-.84,3.48v.07c1.32-.31,2.36-.78,3.12-1.41.76-.63,1.14-1.35,1.14-2.18,0-.42-.12-.76-.37-1.01-.22-.27-.54-.4-.94-.4Z"/>
                <path fill="#c8542b" d="M100.75,31.66c-.98,0-1.73-.27-2.25-.8-.49-.54-.74-1.24-.74-2.11,0-1.01.28-1.81.84-2.41.58-.6,1.39-.9,2.41-.9s1.72.25,2.21.74c.51.47.77,1.17.77,2.11,0,1.03-.29,1.85-.87,2.48-.58.6-1.37.9-2.38.9Z"/>
            </svg>
        </div>
        <div class="rz-auth-quote">
            <div class="rz-auth-eyebrow"><?= t('auth.register_start') ?></div>
            <h1 class="rz-auth-h"><?= nl2br(t('auth.register_hero')) ?></h1>
            <p class="rz-auth-p"><?= t('auth.register_hero_text') ?></p>
            <div class="rz-auth-stats">
                <div><span class="rz-auth-stat-val">30 dni</span><span class="rz-auth-stat-lbl"><?= t('auth.trial_stat_1') ?></span></div>
                <div><span class="rz-auth-stat-val">0 €</span><span class="rz-auth-stat-lbl"><?= t('auth.trial_stat_2') ?></span></div>
                <div><span class="rz-auth-stat-val">10 min</span><span class="rz-auth-stat-lbl"><?= t('auth.trial_stat_3') ?></span></div>
            </div>
        </div>
        <div class="rz-auth-foot"><?= t('auth.footer', ['year' => date('Y')]) ?></div>
    </div>

    <!-- Desna stran — forma -->
    <div class="rz-auth-right">
        <div class="rz-auth-card">
            <div style="display:flex;justify-content:flex-end;margin-bottom:14px">
                <?php $langSwitcherTheme = 'light'; require __DIR__ . '/includes/lang_switcher.php'; ?>
            </div>
            <div class="rz-auth-switch">
                <a href="<?= BASE_PATH ?>/login.php" class="rz-btn" style="flex:1;justify-content:center;border:0;font-size:13px;font-weight:600;color:var(--ink-mute);border-radius:7px"><?= t('auth.login_btn') ?></a>
                <button class="is-sel" type="button"><?= t('auth.register_title') ?></button>
            </div>

        <?php if ($success): ?>
            <div class="reg-success" style="text-align:center;padding:32px 16px">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.8" style="margin:0 auto 12px;display:block"><path d="M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z"/></svg>
                <h2 class="rz-auth-title" style="text-align:center"><?= t('auth.email_confirmed_title') ?></h2>
                <p style="color:var(--ink-mute);font-size:14px"><?= t('auth.email_confirmed_text') ?><br><strong style="color:var(--ink)"><?= h($post['email']) ?></strong></p>
                <p style="font-size:12px;color:var(--ink-mute);margin-top:10px"><?= t('auth.email_spam_hint') ?></p>
                <a href="<?= BASE_PATH ?>/login.php" class="rz-btn rz-btn-primary" style="margin-top:18px;display:inline-flex"><?= t('auth.back_to_login_btn') ?></a>
            </div>
        <?php else: ?>

            <?php if (!empty($errors)): ?>
                <div class="rz-auth-error" style="margin-bottom:14px"><?= implode('<br>', array_map(fn($e) => h($e), $errors)) ?></div>
            <?php endif; ?>

            <!-- Stepper -->
            <div class="reg-stepper">
                <?php for ($i = 1; $i <= 3; $i++): ?>
                <div class="reg-step-pill <?= $i === 1 ? 'is-active' : '' ?> <?= $i > 1 ? 'is-future' : '' ?>" data-step-pill="<?= $i ?>">
                    <span class="reg-step-num"><?= $i ?></span>
                    <span class="reg-step-lbl">
                        <?= $i === 1 ? t('auth.step1_label') : ($i === 2 ? t('auth.step2_label') : t('auth.step3_label')) ?>
                    </span>
                </div>
                <?php endfor; ?>
            </div>

            <form method="POST" autocomplete="off" id="reg-form" class="reg-form">
                <input type="hidden" name="plan" value="<?= h($selectedPlan) ?>" id="reg-plan-input">

                <!-- ── Step 1: Paket ────────────────────────────────────────── -->
                <div class="reg-step is-active" data-step="1">
                    <h2 class="rz-auth-title"><?= t('auth.step1_heading') ?></h2>
                    <p class="rz-auth-sub"><?= t('auth.create_account_subtitle') ?></p>

                    <div class="reg-plans">
                        <?php foreach ($planUiData as $slug => $info):
                            $isSel = $slug === $selectedPlan;
                            $origStr  = number_format($info['orig_monthly'],  2, ',', '.') . ' €';
                            $finalStr = number_format($info['final_monthly'], 2, ',', '.') . ' €';
                        ?>
                        <label class="reg-plan-card <?= $isSel ? 'is-sel' : '' ?>" data-plan-card="<?= h($slug) ?>">
                            <input type="radio" name="plan_pick" value="<?= h($slug) ?>" <?= $isSel ? 'checked' : '' ?> hidden>
                            <div class="reg-plan-head">
                                <div class="reg-plan-name"><?= h($info['name']) ?></div>
                                <button type="button" class="reg-plan-info" aria-label="<?= t('auth.plan_features_label') ?>" data-tooltip="<?= h($slug) ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                                </button>
                            </div>

                            <!-- Primarna cena: 0,00 € prvi mesec -->
                            <div class="reg-plan-prices">
                                <span class="reg-plan-final">0,00 €</span>
                                <span class="reg-plan-suffix"><?= t('auth.first_month_label') ?></span>
                            </div>

                            <!-- Sekundarna: redna cena (z opcijskim popustom) po preizkusu -->
                            <div class="reg-plan-after">
                                <?php if ($info['has_discount']): ?>
                                    <?= t_raw('auth.after_first_month_html', [
                                        'orig'  => h($origStr),
                                        'final' => h($finalStr),
                                    ]) ?>
                                <?php else: ?>
                                    <?= t_raw('auth.after_first_month_plain_html', [
                                        'price' => h($finalStr),
                                    ]) ?>
                                <?php endif; ?>
                            </div>

                            <?php if ($info['has_discount'] && $info['discount_pct']): ?>
                                <div class="reg-plan-badge"><?= sprintf(t('auth.discount_pct_label'), $info['discount_pct']) ?></div>
                            <?php endif; ?>

                            <div class="reg-plan-tooltip" role="tooltip">
                                <div class="reg-plan-tooltip-head"><?= t('auth.plan_features_label') ?></div>
                                <ul>
                                    <?php foreach ($planFeaturesUi[$slug] as $featKey): ?>
                                    <li>
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                        <span><?= t($featKey) ?></span>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <p class="reg-trial-info"><?= t('auth.trial_plan_info') ?></p>

                    <div class="reg-step-actions">
                        <button type="button" class="rz-btn rz-btn-primary reg-next" data-go="2"><?= t('auth.next_btn') ?> →</button>
                    </div>
                </div>

                <!-- ── Step 2: Email + geslo ────────────────────────────────── -->
                <div class="reg-step" data-step="2">
                    <h2 class="rz-auth-title"><?= t('auth.step2_heading') ?></h2>
                    <p class="rz-auth-sub"><?= t('auth.step2_sub') ?></p>

                    <div class="rz-field">
                        <label class="rz-field-label" for="email"><?= t('auth.email_label') ?></label>
                        <input class="rz-input" type="email" id="email" name="email" value="<?= h($post['email'] ?? '') ?>" required autocomplete="email">
                    </div>
                    <div class="rz-field">
                        <label class="rz-field-label" for="password"><?= t('auth.new_password_label') ?></label>
                        <input class="rz-input" type="password" id="password" name="password" required autocomplete="new-password">
                    </div>
                    <div class="rz-field">
                        <label class="rz-field-label" for="password_confirm"><?= t('auth.confirm_password_label') ?></label>
                        <input class="rz-input" type="password" id="password_confirm" name="password_confirm" required>
                    </div>

                    <div class="reg-step-actions">
                        <button type="button" class="rz-btn reg-back" data-go="1">← <?= t('auth.back_btn') ?></button>
                        <button type="button" class="rz-btn rz-btn-primary reg-next" data-go="3"><?= t('auth.next_btn') ?> →</button>
                    </div>
                </div>

                <!-- ── Step 3: Podatki + GDPR ───────────────────────────────── -->
                <div class="reg-step" data-step="3">
                    <h2 class="rz-auth-title"><?= t('auth.step3_heading') ?></h2>
                    <p class="rz-auth-sub"><?= t('auth.step3_sub') ?></p>

                    <div class="rz-field">
                        <label class="rz-field-label" for="full_name"><?= t('auth.name_label') ?></label>
                        <input class="rz-input" type="text" id="full_name" name="full_name" value="<?= h($post['full_name'] ?? '') ?>" required>
                    </div>

                    <div class="reg-section-divider"><?= t('auth.company_data') ?></div>

                    <div class="rz-field">
                        <label class="rz-field-label" for="company_name"><?= t('auth.company_name_label') ?></label>
                        <input class="rz-input" type="text" id="company_name" name="company_name" value="<?= h($post['company_name'] ?? '') ?>" required>
                    </div>
                    <div class="rz-field">
                        <label class="rz-field-label" for="company_address"><?= t('auth.company_address_label') ?></label>
                        <input class="rz-input" type="text" id="company_address" name="company_address" value="<?= h($post['company_address'] ?? '') ?>" placeholder="<?= t('auth.company_address_placeholder') ?>" required>
                    </div>
                    <label class="rz-check">
                        <input type="checkbox" id="is_vat_registered" name="is_vat_registered" <?= !empty($post['is_vat_registered']) ? 'checked' : '' ?>>
                        <?= t('auth.vat_checkbox') ?>
                    </label>
                    <div class="rz-field" id="tax-number-group">
                        <label class="rz-field-label" for="tax_number"><?= t('auth.tax_number_label') ?></label>
                        <input class="rz-input" type="text" id="tax_number" name="tax_number" value="<?= h($post['tax_number'] ?? '') ?>" inputmode="numeric" maxlength="20" placeholder="12345678" required>
                    </div>
                    <div class="rz-field" id="vat-id-group" style="display:none">
                        <label class="rz-field-label" for="vat_id"><?= t('auth.vat_id_label') ?></label>
                        <input class="rz-input" type="text" id="vat_id" name="vat_id" value="<?= h($post['vat_id'] ?? '') ?>" placeholder="SI12345678" maxlength="30" style="text-transform:uppercase">
                    </div>

                    <div class="reg-section-divider"></div>

                    <label class="rz-check" style="align-items:flex-start;gap:10px">
                        <input type="checkbox" id="gdpr_consent" name="gdpr_consent" <?= !empty($_POST['gdpr_consent']) ? 'checked' : '' ?> required style="margin-top:3px">
                        <span style="font-size:13px;color:var(--ink-soft);line-height:1.5">
                            <?= t_raw('auth.gdpr_consent_html', [
                                'terms_url'   => BASE_PATH . '/pages/terms.php',
                                'privacy_url' => BASE_PATH . '/pages/privacy.php',
                            ]) ?>
                        </span>
                    </label>

                    <label class="rz-check" style="align-items:flex-start;gap:10px;margin-top:10px">
                        <input type="checkbox" id="marketing_consent" name="marketing_consent" <?= !empty($_POST['marketing_consent']) ? 'checked' : '' ?> style="margin-top:3px">
                        <span style="font-size:13px;color:var(--ink-soft);line-height:1.5"><?= t('auth.marketing_agree') ?></span>
                    </label>

                    <div class="reg-step-actions">
                        <button type="button" class="rz-btn reg-back" data-go="2">← <?= t('auth.back_btn') ?></button>
                        <button type="submit" class="rz-btn rz-btn-primary"><?= t('auth.create_btn') ?></button>
                    </div>
                </div>
            </form>

            <!-- Trust badges -->
            <div class="reg-trust">
                <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><?= t('common.trust.no_card') ?></span>
                <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><?= t('common.trust.no_fees') ?></span>
                <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><?= t('common.trust.no_commission') ?></span>
                <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><?= t('common.trust.self_serve') ?></span>
            </div>

            <p style="text-align:center;margin-top:18px;font-size:13px;color:var(--ink-mute)">
                <?= t('auth.already_have_account') ?>
                <a href="<?= BASE_PATH ?>/login.php" class="rz-link"><?= t('auth.login_link') ?></a>
            </p>

        <?php endif; ?>
        </div>
    </div>

</div>
<script>
(function () {
    // ── DDV toggle ────────────────────────────────────────────────────
    const chk      = document.getElementById('is_vat_registered');
    const taxGroup = document.getElementById('tax-number-group');
    const vatGroup = document.getElementById('vat-id-group');
    const taxInput = document.getElementById('tax_number');
    const vatInput = document.getElementById('vat_id');
    if (chk) {
        function toggleVat() {
            const isDDV = chk.checked;
            taxGroup.style.display = isDDV ? 'none' : 'block';
            vatGroup.style.display = isDDV ? 'block' : 'none';
            taxInput.required = !isDDV;
            vatInput.required = isDDV;
        }
        chk.addEventListener('change', toggleVat);
        toggleVat();
    }

    // ── Stepper ───────────────────────────────────────────────────────
    function _track(event, props) {
        if (window.posthog && typeof window.posthog.capture === 'function') {
            window.posthog.capture(event, props || {});
        }
    }
    function setStep(n) {
        document.querySelectorAll('.reg-step').forEach(s => {
            s.classList.toggle('is-active', parseInt(s.dataset.step) === n);
        });
        document.querySelectorAll('[data-step-pill]').forEach(p => {
            const i = parseInt(p.dataset.stepPill);
            p.classList.toggle('is-active', i === n);
            p.classList.toggle('is-done',   i < n);
            p.classList.toggle('is-future', i > n);
        });
        // Scroll na vrh kartice
        document.querySelector('.rz-auth-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        _track('register_step_viewed', { step: n });
    }
    // Začetni dogodek — uporabnik vidi prvi korak
    _track('register_step_viewed', { step: 1 });

    document.querySelectorAll('.reg-next, .reg-back').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            const target = parseInt(btn.dataset.go);
            const isNext = btn.classList.contains('reg-next');
            const cur = btn.closest('.reg-step');
            const fromStep = parseInt(cur?.dataset.step || '0');

            // Validacija pred premikom naprej
            if (isNext) {
                const inputs = cur.querySelectorAll('input[required]:not([style*="display:none"])');
                let ok = true;
                let firstFail = null;
                inputs.forEach(i => {
                    if (!i.checkValidity()) {
                        if (!firstFail) firstFail = i.name || i.id || 'unknown';
                        i.reportValidity(); ok = false;
                    }
                });
                if (!ok) {
                    _track('register_step_validation_failed', { step: fromStep, field: firstFail });
                    return;
                }
                // Preveri ujemanje gesel
                const p1 = cur.querySelector('#password');
                const p2 = cur.querySelector('#password_confirm');
                if (p1 && p2 && p1.value !== p2.value) {
                    p2.setCustomValidity(<?= json_encode(t('auth.err_passwords_mismatch')) ?>);
                    p2.reportValidity();
                    _track('register_step_validation_failed', { step: fromStep, field: 'password_mismatch' });
                    return;
                }
                if (p2) p2.setCustomValidity('');
                _track('register_step_completed', { step: fromStep });
            } else {
                _track('register_step_back', { from_step: fromStep, to_step: target });
            }
            if (!isNaN(target)) setStep(target);
        });
    });
    // Pillsi za skok na obiskane korake
    document.querySelectorAll('[data-step-pill]').forEach(p => {
        p.addEventListener('click', () => {
            if (p.classList.contains('is-done')) {
                setStep(parseInt(p.dataset.stepPill));
            }
        });
    });

    // ── Plan selektor ─────────────────────────────────────────────────
    const planInput = document.getElementById('reg-plan-input');
    document.querySelectorAll('.reg-plan-card').forEach(card => {
        card.addEventListener('click', e => {
            // Klik na info gumb naj ne sproži select
            if (e.target.closest('.reg-plan-info')) return;
            document.querySelectorAll('.reg-plan-card').forEach(c => c.classList.remove('is-sel'));
            card.classList.add('is-sel');
            const radio = card.querySelector('input[type=radio]');
            if (radio) {
                radio.checked = true; planInput.value = radio.value;
                _track('register_plan_selected', { plan: radio.value });
            }
        });
    });

    // ── Submit ────────────────────────────────────────────────────────
    document.querySelector('form.rz-auth-form, form#register-form, form[action]')?.addEventListener('submit', function () {
        _track('register_submitted', { plan: planInput?.value || null });
    });

    // ── Tooltip (info gumb na plan kartici) ──────────────────────────
    let openTooltip = null;
    document.querySelectorAll('.reg-plan-info').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            const card = btn.closest('.reg-plan-card');
            const tooltip = card.querySelector('.reg-plan-tooltip');
            if (!tooltip) return;
            const wasOpen = card.classList.contains('is-tooltip-open');
            // Zapri vse
            document.querySelectorAll('.reg-plan-card.is-tooltip-open').forEach(c => c.classList.remove('is-tooltip-open'));
            if (!wasOpen) {
                card.classList.add('is-tooltip-open');
                openTooltip = card;
            } else {
                openTooltip = null;
            }
        });
    });
    document.addEventListener('click', e => {
        if (openTooltip && !openTooltip.contains(e.target)) {
            openTooltip.classList.remove('is-tooltip-open');
            openTooltip = null;
        }
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && openTooltip) {
            openTooltip.classList.remove('is-tooltip-open');
            openTooltip = null;
        }
    });

    // ── Če server zaznal napake → prikaži korak 3 (kjer je gumb submit) ──
    <?php if (!empty($errors)): ?>
    setStep(3);
    <?php endif; ?>
})();
</script>
</body>
</html>
