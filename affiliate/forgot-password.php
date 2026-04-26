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
            $html = "<h2>Ponastavitev gesla</h2><p>Klikni spodnji gumb za ponastavitev gesla:</p>
                     <p><a href='{$link}' style='background:#F59E0B;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:700'>Ponastavi geslo →</a></p>
                     <p style='color:#9CA3AF;font-size:.82rem'>Povezava velja 1 uro.</p>";
            require_once '../includes/functions.php';
            // Direkten send_email
            send_email($email, 'Ponastavitev gesla – Affiliate', "<html><body style='font-family:sans-serif'>{$html}</body></html>");
        }
        // Vedno pokažemo uspeh (anti-enumeration)
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pozabljeno geslo – Affiliate</title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/affiliate.css">
</head>
<body>
<div class="aff-auth">
<div class="aff-auth-card">
    <div class="aff-auth-logo"><div class="aff-auth-logo-icon">R</div> Affiliate</div>
    <h1 class="aff-auth-title">Pozabljeno geslo</h1>

    <?php if ($success): ?>
    <div class="aff-success">Če ta email obstaja, ste prejeli sporočilo z navodili.</div>
    <?php else: ?>
    <?php if ($errors): ?><div class="aff-error"><?= htmlspecialchars($errors[0], ENT_QUOTES) ?></div><?php endif; ?>
    <form method="POST">
        <div class="aff-form-group">
            <label for="email">Email naslov</label>
            <input type="email" id="email" name="email" required autofocus>
        </div>
        <button type="submit" class="aff-btn">Pošlji navodila</button>
    </form>
    <?php endif; ?>

    <div class="aff-auth-foot"><a href="<?= BASE_PATH ?>/affiliate/login.php">← Nazaj na prijavo</a></div>
</div>
</div>
</body>
</html>
