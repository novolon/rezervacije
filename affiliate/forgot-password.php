<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/lang.php';
require_once '../includes/mailer.php';
require_once '../includes/affiliate_session.php';
aff_session_start();

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('aff.forgot.err_email');
    } else {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT id, full_name FROM affiliates WHERE email = ?");
            $stmt->execute([$email]);
            $aff  = $stmt->fetch();

            if ($aff) {
                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $pdo->prepare("UPDATE affiliates SET reset_token = ?, reset_token_expires = ? WHERE id = ?")
                    ->execute([$token, $expires, $aff['id']]);

                $link = APP_URL . BASE_PATH . '/affiliate/reset-password.php?token=' . urlencode($token);
                $heading  = htmlspecialchars(t('aff.forgot.email_heading'),  ENT_QUOTES);
                $intro    = htmlspecialchars(t('aff.forgot.email_intro'),    ENT_QUOTES);
                $btnLabel = htmlspecialchars(t('aff.forgot.email_btn'),      ENT_QUOTES);
                $validity = htmlspecialchars(t('aff.forgot.email_validity'), ENT_QUOTES);
                $body = "<h2 style='margin:0 0 16px;font-size:1.3rem;color:#111827'>{$heading}</h2>
                         <p style='margin:0 0 16px'>{$intro}</p>
                         <p style='margin:0 0 16px'><a href='{$link}' style='background:#C4704B;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;display:inline-block'>{$btnLabel}</a></p>
                         <p style='margin:0;font-size:.82rem;color:#9CA3AF'>{$validity}</p>";
                $html = email_wrap(APP_NAME, $body, '');
                send_email($email, t('aff.forgot.email_subject'), $html);
            }
            $success = true; // anti-enumeration
        } catch (\Throwable $e) {
            error_log('Affiliate forgot-password error: ' . $e->getMessage());
            $errors[] = t('aff.forgot.err_server');
        }
    }
}

$pageTitle = t('aff.forgot.page_title');
$extraCss  = ['design.css'];
require_once '../includes/html_head.php';
?>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);padding:40px 16px">
<div style="width:100%;max-width:420px">

    <a href="<?= BASE_PATH ?>/affiliate/login.php" style="display:flex;align-items:center;gap:10px;margin-bottom:32px;text-decoration:none">
        <img src="<?= BASE_PATH ?>/assets/images/Rezble.svg" alt="Rezble" style="height:24px;width:auto;display:block">
        <span style="font-weight:500;color:var(--ink-mute);font-size:15px"><?= t('aff.brand_suffix') ?></span>
    </a>

    <div class="rz-card">
        <h1 class="rz-auth-title"><?= t('aff.forgot.title') ?></h1>
        <p class="rz-auth-sub"><?= t('aff.forgot.subtitle') ?></p>

        <?php if ($success): ?>
        <div style="background:color-mix(in oklab,var(--success) 12%,transparent);color:var(--success);border:1px solid color-mix(in oklab,var(--success) 25%,transparent);border-radius:8px;padding:12px 14px;font-size:13px">
            <?= t('aff.forgot.success') ?>
        </div>
        <?php else: ?>
        <?php if ($errors): ?>
        <div style="background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border:1px solid color-mix(in oklab,var(--danger) 25%,transparent);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px">
            <?= htmlspecialchars($errors[0], ENT_QUOTES) ?>
        </div>
        <?php endif; ?>
        <form method="POST" class="rz-auth-form">
            <div class="rz-field">
                <label class="rz-field-label"><?= t('aff.forgot.email') ?></label>
                <input type="email" name="email" class="rz-input" required autofocus autocomplete="email">
            </div>
            <button type="submit" class="rz-btn rz-btn-primary" style="width:100%;justify-content:center;padding:11px"><?= t('aff.forgot.submit') ?></button>
        </form>
        <?php endif; ?>
    </div>

    <p style="text-align:center;margin-top:16px;font-size:12px;color:var(--ink-mute)">
        <a href="<?= BASE_PATH ?>/affiliate/login.php" class="rz-link"><?= t('aff.forgot.back_to_login') ?></a>
    </p>
</div>
</div>
</body>
</html>
