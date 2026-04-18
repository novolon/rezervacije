<?php
require_once 'includes/auth_check.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/mailer.php';

if (is_logged_in()) {
    redirect_to_main();
}

$errors  = [];
$success = false;
$post    = [];

$validPlans    = ['basic', 'advanced', 'premium'];
$selectedPlan  = in_array($_GET['plan'] ?? '', $validPlans) ? $_GET['plan'] : 'basic';
$planNames     = ['basic' => 'Basic', 'advanced' => 'Advanced', 'premium' => 'Premium'];

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
        $errors[] = 'Ime in priimek sta obvezna.';
    }
    if (!$post['email'] || !filter_var($post['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Vnesite veljaven email naslov.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Geslo mora imeti vsaj 8 znakov.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Gesli se ne ujemata.';
    }
    if (!$post['company_name']) {
        $errors[] = 'Naziv podjetja je obvezen.';
    }
    if (!$post['company_address']) {
        $errors[] = 'Naslov podjetja je obvezen.';
    }
    if (empty($_POST['gdpr_consent'])) {
        $errors[] = 'Strinjanje s pogoji uporabe in politiko zasebnosti je obvezno.';
    }
    if ($post['is_vat_registered']) {
        if (!$post['vat_id']) {
            $errors[] = 'ID za DDV je obvezen za davčne zavezance.';
        } elseif (!preg_match('/^[A-Z]{2}[A-Z0-9]{2,15}$/', $post['vat_id'])) {
            $errors[] = 'ID za DDV ni veljavne oblike (npr. SI12345678).';
        }
    } else {
        if (!$post['tax_number']) {
            $errors[] = 'Davčna številka je obvezna.';
        } elseif (!preg_match('/^[A-Z0-9]{4,20}$/i', $post['tax_number'])) {
            $errors[] = 'Davčna številka ni veljavna (4–20 alfanumeričnih znakov).';
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
                     marketing_consent, marketing_consent_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'admin', ?, 'trial', 1, ?,
                        ?, ?, ?, ?, ?)
            ")->execute([
                $post['email'], $hash, $post['full_name'],
                $post['company_name'], $post['company_address'],
                $post['is_vat_registered'] ? null : $post['tax_number'],
                $post['is_vat_registered'] ? 1 : 0,
                $post['is_vat_registered'] ? $post['vat_id'] : null,
                $trialEnds, $verificationToken,
                $gdprNow, $gdprIp, $gdprNow,
                $marketingConsent, $marketingConsent ? $gdprNow : null,
            ]);

            $newUserId = (int) $pdo->lastInsertId();

            // Ustvari trial subscription z izbranim paketom.
            // status='trial' označuje brezplačno obdobje; po preteku mora zakupiti.
            $pdo->prepare("
                INSERT INTO subscriptions (user_id, plan_slug, status, ends_at)
                VALUES (?, ?, 'trial', ?)
            ")->execute([$newUserId, $selectedPlan, $trialEnds]);

            send_verification_email($post['email'], $post['full_name'], $verificationToken);

            $success = true;

        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'Ta email naslov je že registriran.';
            } else {
                error_log('Register error: ' . $e->getMessage());
                $errors[] = 'Napaka strežnika. Poskusite znova.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registracija – <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/login.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/register.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-card register-card">
        <div class="login-logo">
            <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                <rect width="40" height="40" rx="10" fill="#F59E0B"/>
                <path d="M10 14h20M10 20h20M10 26h12" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
        </div>
        <h1><?= APP_NAME ?></h1>
        <p class="subtitle">Registracija</p>

        <!-- Plan selector -->
        <div style="margin-bottom:20px">
            <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:#6B7280;font-weight:600;margin-bottom:8px">Izberite paket za preizkus</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
                <?php foreach ($planNames as $slug => $name):
                    $active = $slug === $selectedPlan;
                    $prices = ['basic' => '4,99 €', 'advanced' => '6,99 €', 'premium' => '9,99 €'];
                ?>
                <a href="?plan=<?= $slug ?>" style="text-decoration:none;display:block;border:2px solid <?= $active ? '#F59E0B' : '#E5E7EB' ?>;border-radius:8px;padding:10px 8px;text-align:center;background:<?= $active ? '#FFFBEB' : '#fff' ?>;cursor:pointer;transition:border-color .15s">
                    <div style="font-size:.8rem;font-weight:700;color:<?= $active ? '#92400E' : '#374151' ?>"><?= h($name) ?></div>
                    <div style="font-size:.7rem;color:#9CA3AF;margin-top:2px"><?= $prices[$slug] ?>/mes</div>
                </a>
                <?php endforeach; ?>
            </div>
            <div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;margin-top:10px;text-align:center;font-size:.82rem;color:#92400E">
                Začnete z <strong>30-dnevnim brezplačnim trialom</strong> paketa <strong><?= h($planNames[$selectedPlan]) ?></strong>.<br>
                <span style="color:#B45309;font-size:.75rem">Med trialom lahko kadar koli prosto preklapljate med paketi.</span>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="register-success">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="1.8"><path d="M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z"/></svg>
                <h2>Preverite vaš email!</h2>
                <p>Poslali smo potrditveno sporočilo na<br><strong><?= h($post['email']) ?></strong></p>
                <p style="font-size:.8rem;color:#9CA3AF">Kliknite link v emailu, da aktivirate račun. Preverite tudi mapo Spam.</p>
                <a href="<?= BASE_PATH ?>/login.php" class="btn-register-login">Nazaj na prijavo</a>
            </div>
        <?php else: ?>

        <?php if (!empty($errors)): ?>
            <div class="error-msg" style="display:block">
                <?= implode('<br>', array_map(fn($e) => h($e), $errors)) ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label for="full_name">Ime in priimek</label>
                <input type="text" id="full_name" name="full_name"
                       value="<?= h($post['full_name'] ?? '') ?>" required autofocus>
            </div>
            <div class="form-group">
                <label for="email">Email naslov</label>
                <input type="email" id="email" name="email"
                       value="<?= h($post['email'] ?? '') ?>" required autocomplete="email">
            </div>
            <div class="form-group">
                <label for="password">Geslo (min. 8 znakov)</label>
                <input type="password" id="password" name="password" required autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="password_confirm">Potrdi geslo</label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>

            <div class="billing-section-title">Podatki o podjetju</div>

            <div class="form-group">
                <label for="company_name">Naziv podjetja / organizacije</label>
                <input type="text" id="company_name" name="company_name"
                       value="<?= h($post['company_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="company_address">Naslov podjetja</label>
                <input type="text" id="company_address" name="company_address"
                       value="<?= h($post['company_address'] ?? '') ?>"
                       placeholder="Ulica 1, 1000 Ljubljana" required>
            </div>
            <div class="vat-checkbox-row">
                <input type="checkbox" id="is_vat_registered" name="is_vat_registered"
                       <?= !empty($post['is_vat_registered']) ? 'checked' : '' ?>>
                <label for="is_vat_registered">Sem zavezanec za DDV</label>
            </div>
            <div class="form-group" id="tax-number-group">
                <label for="tax_number">Davčna številka</label>
                <input type="text" id="tax_number" name="tax_number"
                       value="<?= h($post['tax_number'] ?? '') ?>"
                       inputmode="numeric" maxlength="20" placeholder="12345678" required>
            </div>
            <div class="form-group" id="vat-id-group" style="display:none">
                <label for="vat_id">ID za DDV</label>
                <input type="text" id="vat_id" name="vat_id"
                       value="<?= h($post['vat_id'] ?? '') ?>"
                       placeholder="SI12345678" maxlength="30"
                       style="text-transform:uppercase">
            </div>

            <div style="border-top:1px solid #E5E7EB;margin:20px 0 16px"></div>

            <div class="vat-checkbox-row" style="align-items:flex-start;gap:10px;margin-bottom:10px">
                <input type="checkbox" id="gdpr_consent" name="gdpr_consent"
                       <?= !empty($_POST['gdpr_consent']) ? 'checked' : '' ?> required
                       style="margin-top:3px;flex-shrink:0">
                <label for="gdpr_consent" style="font-size:.83rem;color:#374151;cursor:pointer">
                    Strinjam se s <a href="<?= BASE_PATH ?>/pages/terms.php" target="_blank" style="color:#F59E0B">Pogoji uporabe</a>,
                    <a href="<?= BASE_PATH ?>/pages/privacy.php" target="_blank" style="color:#F59E0B">Politiko zasebnosti</a>
                    in Pogodbo o obdelavi podatkov (DPA). <span style="color:#EF4444">*</span>
                </label>
            </div>

            <div class="vat-checkbox-row" style="align-items:flex-start;gap:10px;margin-bottom:20px">
                <input type="checkbox" id="marketing_consent" name="marketing_consent"
                       <?= !empty($_POST['marketing_consent']) ? 'checked' : '' ?>
                       style="margin-top:3px;flex-shrink:0">
                <label for="marketing_consent" style="font-size:.83rem;color:#374151;cursor:pointer">
                    Strinjam se s prejemanjem novic, nasvetov in ponudb (neobvezno).
                </label>
            </div>

            <button type="submit">Ustvari brezplačen račun</button>
        </form>

        <p style="text-align:center;margin-top:20px;font-size:.875rem;color:#6B7280">
            Že imaš račun?
            <a href="<?= BASE_PATH ?>/login.php" style="color:#F59E0B;font-weight:600;text-decoration:none">Prijava</a>
        </p>

        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    const chk      = document.getElementById('is_vat_registered');
    const taxGroup = document.getElementById('tax-number-group');
    const vatGroup = document.getElementById('vat-id-group');
    const taxInput = document.getElementById('tax_number');
    const vatInput = document.getElementById('vat_id');
    if (!chk) return;
    function toggle() {
        const isDDV = chk.checked;
        taxGroup.style.display = isDDV ? 'none' : 'block';
        vatGroup.style.display = isDDV ? 'block' : 'none';
        taxInput.required = !isDDV;
        vatInput.required = isDDV;
    }
    chk.addEventListener('change', toggle);
    toggle();
})();
</script>
</body>
</html>
