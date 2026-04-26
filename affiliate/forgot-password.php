<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/mailer.php';
require_once '../includes/affiliate_session.php';
aff_session_start();

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Neveljaven email naslov.';
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
                $body = "<h2 style='margin:0 0 16px;font-size:1.3rem;color:#111827'>Ponastavitev gesla</h2>
                         <p style='margin:0 0 16px'>Klikni spodnji gumb za ponastavitev gesla:</p>
                         <p style='margin:0 0 16px'><a href='{$link}' style='background:#C4704B;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;display:inline-block'>Ponastavi geslo →</a></p>
                         <p style='margin:0;font-size:.82rem;color:#9CA3AF'>Povezava velja 1 uro. Če niste zahtevali ponastavitve, ignorirajte to sporočilo.</p>";
                $html = email_wrap(APP_NAME, $body, '');
                send_email($email, 'Ponastavitev gesla – Affiliate', $html);
            }
            $success = true; // anti-enumeration
        } catch (\Throwable $e) {
            error_log('Affiliate forgot-password error: ' . $e->getMessage());
            $errors[] = 'Napaka strežnika. Poskusite znova.';
        }
    }
}

$pageTitle = 'Pozabljeno geslo – Affiliate';
$extraCss  = ['design.css'];
require_once '../includes/html_head.php';
?>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);padding:40px 16px">
<div style="width:100%;max-width:420px">

    <a href="<?= BASE_PATH ?>/affiliate/login.php" style="display:flex;align-items:center;gap:10px;margin-bottom:32px;color:var(--ink);font-weight:700;font-size:17px;letter-spacing:-.02em;text-decoration:none">
        <svg width="28" height="28" viewBox="0 0 32 32" aria-hidden="true" style="flex:none">
            <rect width="32" height="32" rx="7" fill="var(--accent)"/>
            <path d="M9 8v16l4-4h5a5 5 0 0 0 5-5v-4a3 3 0 0 0-3-3H9Z" fill="#fff"/>
        </svg>
        Rezble <span style="font-weight:400;color:var(--ink-mute)">/ Affiliate</span>
    </a>

    <div class="rz-card">
        <h1 class="rz-auth-title">Pozabljeno geslo</h1>
        <p class="rz-auth-sub">Vnesite email in poslali vam bomo navodila za ponastavitev.</p>

        <?php if ($success): ?>
        <div style="background:color-mix(in oklab,var(--success) 12%,transparent);color:var(--success);border:1px solid color-mix(in oklab,var(--success) 25%,transparent);border-radius:8px;padding:12px 14px;font-size:13px">
            Če ta email obstaja v sistemu, ste prejeli sporočilo z navodili.
        </div>
        <?php else: ?>
        <?php if ($errors): ?>
        <div style="background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border:1px solid color-mix(in oklab,var(--danger) 25%,transparent);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px">
            <?= htmlspecialchars($errors[0], ENT_QUOTES) ?>
        </div>
        <?php endif; ?>
        <form method="POST" class="rz-auth-form">
            <div class="rz-field">
                <label class="rz-field-label">Email naslov</label>
                <input type="email" name="email" class="rz-input" required autofocus autocomplete="email">
            </div>
            <button type="submit" class="rz-btn rz-btn-primary" style="width:100%;justify-content:center;padding:11px">Pošlji navodila</button>
        </form>
        <?php endif; ?>
    </div>

    <p style="text-align:center;margin-top:16px;font-size:12px;color:var(--ink-mute)">
        <a href="<?= BASE_PATH ?>/affiliate/login.php" class="rz-link">← Nazaj na prijavo</a>
    </p>
</div>
</div>
</body>
</html>
