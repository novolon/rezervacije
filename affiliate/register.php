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
            $pdo = getDB();

            $dup = $pdo->prepare("SELECT 1 FROM affiliates WHERE email = ?");
            $dup->execute([$post['email']]);
            if ($dup->fetchColumn()) {
                $errors[] = 'Email naslov je že registriran.';
            } else {
                require_once '../includes/affiliate_helper.php';
                $refCode = affiliate_generate_ref_code($pdo);
                $token   = bin2hex(random_bytes(32));

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

                send_affiliate_verify_email($post['email'], $post['full_name'], $token, _resolve_email_lang());
                $success = true;
            }
        } catch (\Throwable $e) {
            error_log('Affiliate register error: ' . $e->getMessage());
            $errors[] = 'Napaka strežnika. Preverite, ali so bili SQL migracije izvedene, in poskusite znova.';
        }
    }
}

$pageTitle = 'Registracija – Affiliate';
$extraCss  = ['design.css'];
require_once '../includes/html_head.php';
?>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);padding:40px 16px">
<div style="width:100%;max-width:460px">

    <a href="<?= BASE_PATH ?>/" style="display:flex;align-items:center;gap:10px;margin-bottom:32px;color:var(--ink);font-weight:700;font-size:17px;letter-spacing:-.02em;text-decoration:none">
        <svg width="28" height="28" viewBox="0 0 32 32" aria-hidden="true" style="flex:none">
            <rect width="32" height="32" rx="7" fill="var(--accent)"/>
            <path d="M9 8v16l4-4h5a5 5 0 0 0 5-5v-4a3 3 0 0 0-3-3H9Z" fill="#fff"/>
        </svg>
        Rezble <span style="font-weight:400;color:var(--ink-mute)">/ Affiliate</span>
    </a>

    <?php if ($success): ?>
    <div class="rz-card" style="text-align:center;padding:40px 32px">
        <div style="font-size:2.5rem;margin-bottom:16px">✉️</div>
        <h2 style="margin:0 0 10px;font-size:22px;font-weight:700;color:var(--ink)">Prijavnica oddana!</h2>
        <p style="margin:0 0 24px;color:var(--ink-mute);font-size:14px;line-height:1.6">Poslali smo vam potrditveni email. Po potrditvi emaila bomo pregledali vašo prijavo in vas obvestili.</p>
        <a href="<?= BASE_PATH ?>/affiliate/login.php" class="rz-btn rz-btn-primary" style="display:inline-flex">Na prijavo →</a>
    </div>
    <?php else: ?>
    <div class="rz-card">
        <h1 class="rz-auth-title">Postanite affiliate</h1>
        <p class="rz-auth-sub">Zaslužite 20% provizije za vsako priporočeno restavracijo</p>

        <?php if ($errors): ?>
        <div style="background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border:1px solid color-mix(in oklab,var(--danger) 25%,transparent);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px">
            <?= implode('<br>', array_map(fn($e) => htmlspecialchars($e, ENT_QUOTES), $errors)) ?>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off" class="rz-auth-form">
            <div class="rz-form-2">
                <div class="rz-field">
                    <label class="rz-field-label">Ime in priimek *</label>
                    <input type="text" name="full_name" class="rz-input" value="<?= htmlspecialchars($post['full_name'] ?? '', ENT_QUOTES) ?>" required autofocus>
                </div>
                <div class="rz-field">
                    <label class="rz-field-label">Email naslov *</label>
                    <input type="email" name="email" class="rz-input" value="<?= htmlspecialchars($post['email'] ?? '', ENT_QUOTES) ?>" required autocomplete="email">
                </div>
            </div>
            <div class="rz-field">
                <label class="rz-field-label">Pravna oblika *</label>
                <select name="legal_form" class="rz-input">
                    <option value="individual" <?= ($post['legal_form'] ?? '') === 'individual' ? 'selected' : '' ?>>Fizična oseba</option>
                    <option value="sole_trader" <?= ($post['legal_form'] ?? '') === 'sole_trader' ? 'selected' : '' ?>>Samostojni podjetnik (s.p.)</option>
                    <option value="company" <?= ($post['legal_form'] ?? '') === 'company' ? 'selected' : '' ?>>Podjetje (d.o.o., d.d.)</option>
                    <option value="foreign" <?= ($post['legal_form'] ?? '') === 'foreign' ? 'selected' : '' ?>>Tujina</option>
                </select>
            </div>
            <div class="rz-field">
                <label class="rz-field-label">IBAN za izplačila</label>
                <input type="text" name="iban" class="rz-input" value="<?= htmlspecialchars($post['iban'] ?? '', ENT_QUOTES) ?>" placeholder="SI56...">
            </div>
            <div class="rz-form-2">
                <div class="rz-field">
                    <label class="rz-field-label">Geslo *</label>
                    <input type="password" name="password" class="rz-input" required autocomplete="new-password">
                </div>
                <div class="rz-field">
                    <label class="rz-field-label">Potrdi geslo *</label>
                    <input type="password" name="password_confirm" class="rz-input" required>
                </div>
            </div>

            <div style="border-top:1px solid var(--line);padding-top:16px;display:flex;flex-direction:column;gap:10px">
                <label class="rz-check">
                    <input type="checkbox" name="terms_consent" required>
                    Strinjam se s <a href="<?= BASE_PATH ?>/affiliate/terms.php" target="_blank" class="rz-link">pogoji affiliate programa</a>. *
                </label>
                <label class="rz-check">
                    <input type="checkbox" name="marketing_consent">
                    Strinjam se s prejemanjem novic in nasvetov programa.
                </label>
            </div>

            <button type="submit" class="rz-btn rz-btn-primary" style="width:100%;justify-content:center;padding:11px;margin-top:4px">Oddaj prijavnico</button>
        </form>
    </div>

    <p style="text-align:center;margin-top:16px;font-size:12px;color:var(--ink-mute)">
        Že imate račun? <a href="<?= BASE_PATH ?>/affiliate/login.php" class="rz-link">Prijava</a>
    </p>
    <?php endif; ?>
</div>
</div>
</body>
</html>
