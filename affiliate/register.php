<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/affiliate_session.php';
require_once '../includes/mailer.php';

aff_session_start();
if (aff_is_logged_in()) { header('Location: ' . BASE_PATH . '/affiliate/dashboard.php'); exit; }

$errors  = [];
$success = false;
$post    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post['full_name']  = trim($_POST['full_name']  ?? '');
    $post['email']      = trim($_POST['email']      ?? '');
    $post['legal_form'] = in_array($_POST['legal_form'] ?? '', ['individual','sole_trader','company','foreign'])
                          ? $_POST['legal_form'] : 'individual';
    $post['iban']       = strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? ''));
    $password           = $_POST['password']         ?? '';
    $confirm            = $_POST['password_confirm'] ?? '';

    if (!$post['full_name']) $errors[] = 'Ime in priimek sta obvezna.';
    if (!filter_var($post['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Neveljaven email naslov.';
    if (strlen($password) < 8) $errors[] = 'Geslo mora imeti vsaj 8 znakov.';
    if ($password !== $confirm) $errors[] = 'Gesli se ne ujemata.';
    if ($post['iban'] && !preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $post['iban'])) {
        $errors[] = 'IBAN ni v pravilni obliki (npr. SI56...).';
    }
    if (empty($_POST['terms_consent'])) $errors[] = 'Sprejeti morate pogoje programa.';

    if (empty($errors)) {
        try {
            $pdo  = getDB();

            // Preveri duplicate
            $dup = $pdo->prepare("SELECT 1 FROM affiliates WHERE email = ?");
            $dup->execute([$post['email']]);
            if ($dup->fetchColumn()) {
                $errors[] = 'Email naslov je že registriran.';
            } else {
                // Generiraj ref_code
                require_once '../includes/affiliate_helper.php';
                $refCode = affiliate_generate_ref_code($pdo);
                $token   = bin2hex(random_bytes(32));

                // Pridobi verzijo pogojev
                $termsVer = $pdo->query("SELECT setting_value FROM affiliate_settings WHERE setting_key = 'terms_version'")->fetchColumn() ?: '1.0';

                $pdo->prepare("
                    INSERT INTO affiliates
                    (ref_code, email, password_hash, full_name, legal_form, iban,
                     status, terms_accepted_at, terms_version, marketing_consent, verification_token)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), ?, ?, ?)
                ")->execute([
                    $refCode,
                    $post['email'],
                    password_hash($password, PASSWORD_BCRYPT),
                    $post['full_name'],
                    $post['legal_form'],
                    $post['iban'] ?: null,
                    $termsVer,
                    !empty($_POST['marketing_consent']) ? 1 : 0,
                    $token,
                ]);

                send_affiliate_verify_email($post['email'], $post['full_name'], $token);
                $success = true;
            }
        } catch (PDOException $e) {
            error_log('Affiliate register error: ' . $e->getMessage());
            $errors[] = 'Napaka strežnika. Poskusite znova.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Affiliate registracija – <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/affiliate.css">
</head>
<body>
<div class="aff-auth" style="padding:40px 16px">
<div class="aff-auth-card" style="max-width:480px">
    <div class="aff-auth-logo">
        <div class="aff-auth-logo-icon">R</div>
        <?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?> Affiliati
    </div>

    <?php if ($success): ?>
    <div class="aff-success" style="padding:20px;text-align:center">
        <div style="font-size:2rem;margin-bottom:8px">✉️</div>
        <h2 style="margin:0 0 8px;color:#065F46">Prijavnica oddana!</h2>
        <p style="margin:0;color:#047857">Poslali smo vam potrditveni email. Po potrditvi emaila bomo pregledali vašo prijavo in vas obvestili.</p>
        <div style="margin-top:16px"><a href="<?= BASE_PATH ?>/affiliate/login.php" class="aff-btn" style="display:inline-block;width:auto;padding:10px 24px">Na prijavo</a></div>
    </div>
    <?php else: ?>
    <h1 class="aff-auth-title">Postanite affiliate</h1>
    <p class="aff-auth-sub">Zaslužite 20% provizije za vsako priporočeno restavracijo</p>

    <?php if ($errors): ?>
    <div class="aff-error"><?= implode('<br>', array_map(fn($e) => htmlspecialchars($e, ENT_QUOTES), $errors)) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <div class="aff-form-group">
            <label for="full_name">Ime in priimek *</label>
            <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($post['full_name'] ?? '', ENT_QUOTES) ?>" required autofocus>
        </div>
        <div class="aff-form-group">
            <label for="email">Email naslov *</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($post['email'] ?? '', ENT_QUOTES) ?>" required autocomplete="email">
        </div>
        <div class="aff-form-group">
            <label for="legal_form">Pravna oblika *</label>
            <select id="legal_form" name="legal_form">
                <option value="individual" <?= ($post['legal_form'] ?? '') === 'individual' ? 'selected' : '' ?>>Fizična oseba</option>
                <option value="sole_trader" <?= ($post['legal_form'] ?? '') === 'sole_trader' ? 'selected' : '' ?>>Samostojni podjetnik (s.p.)</option>
                <option value="company" <?= ($post['legal_form'] ?? '') === 'company' ? 'selected' : '' ?>>Podjetje (d.o.o., d.d.)</option>
                <option value="foreign" <?= ($post['legal_form'] ?? '') === 'foreign' ? 'selected' : '' ?>>Tujina</option>
            </select>
        </div>
        <div class="aff-form-group">
            <label for="iban">IBAN za izplačila</label>
            <input type="text" id="iban" name="iban" value="<?= htmlspecialchars($post['iban'] ?? '', ENT_QUOTES) ?>" placeholder="SI56...">
        </div>
        <div class="aff-form-group">
            <label for="password">Geslo *</label>
            <input type="password" id="password" name="password" required autocomplete="new-password">
        </div>
        <div class="aff-form-group">
            <label for="password_confirm">Potrdi geslo *</label>
            <input type="password" id="password_confirm" name="password_confirm" required>
        </div>

        <div style="border-top:1px solid var(--aff-border);margin:16px 0"></div>

        <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:10px">
            <input type="checkbox" id="terms_consent" name="terms_consent" style="margin-top:3px;flex-shrink:0" required>
            <label for="terms_consent" style="font-size:.83rem;color:#374151;cursor:pointer">
                Strinjam se s <a href="<?= BASE_PATH ?>/affiliate/terms.php" target="_blank">pogoji affiliate programa</a>. *
            </label>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:20px">
            <input type="checkbox" id="marketing_consent" name="marketing_consent" style="margin-top:3px;flex-shrink:0">
            <label for="marketing_consent" style="font-size:.83rem;color:#374151;cursor:pointer">
                Strinjam se s prejemanjem novic in nasvetov affiliate programa.
            </label>
        </div>

        <button type="submit" class="aff-btn">Oddaj prijavnico</button>
    </form>

    <div class="aff-auth-foot">
        Že imate račun? <a href="<?= BASE_PATH ?>/affiliate/login.php">Prijava</a>
    </div>
    <?php endif; ?>
</div>
</div>
</body>
</html>
