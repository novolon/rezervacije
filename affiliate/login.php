<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/affiliate_session.php';

aff_session_start();
if (aff_is_logged_in()) { header('Location: ' . BASE_PATH . '/affiliate/dashboard.php'); exit; }

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $errors[] = 'Vnesite email in geslo.';
    } else {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT * FROM affiliates WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $aff  = $stmt->fetch();

            if (!$aff || !password_verify($password, $aff['password_hash'])) {
                $errors[] = 'Napačen email ali geslo.';
            } elseif (!$aff['email_verified_at']) {
                $errors[] = 'Email naslov ni potrjen. Preverite poštni nabiralnik.';
            } elseif ($aff['status'] === 'rejected') {
                $errors[] = 'Vaša prijava je bila zavrnjena.';
            } else {
                session_regenerate_id(true);
                aff_session_set($aff);
                header('Location: ' . BASE_PATH . '/affiliate/dashboard.php');
                exit;
            }
        } catch (\Throwable $e) {
            error_log('Affiliate login error: ' . $e->getMessage());
            $errors[] = 'Napaka strežnika. Poskusite znova.';
        }
    }
}

$pageTitle = 'Prijava – Affiliate';
$extraCss  = ['design.css'];
require_once '../includes/html_head.php';
?>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);padding:40px 16px">
<div style="width:100%;max-width:420px">

    <a href="<?= BASE_PATH ?>/" style="display:flex;align-items:center;gap:10px;margin-bottom:32px;color:var(--ink);font-weight:700;font-size:17px;letter-spacing:-.02em;text-decoration:none">
        <svg width="28" height="28" viewBox="0 0 32 32" aria-hidden="true" style="flex:none">
            <rect width="32" height="32" rx="7" fill="var(--accent)"/>
            <path d="M9 8v16l4-4h5a5 5 0 0 0 5-5v-4a3 3 0 0 0-3-3H9Z" fill="#fff"/>
        </svg>
        Rezble <span style="font-weight:400;color:var(--ink-mute)">/ Affiliate</span>
    </a>

    <div class="rz-card">
        <h1 class="rz-auth-title">Prijava</h1>
        <p class="rz-auth-sub">Dostop do affiliate dashboarda</p>

        <?php if ($_GET['err'] ?? '' === 'suspended'): ?>
        <div style="background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border:1px solid color-mix(in oklab,var(--danger) 25%,transparent);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px">
            Vaš račun je bil suspendiran. Kontaktirajte nas za pomoč.
        </div>
        <?php endif; ?>
        <?php if ($errors): ?>
        <div style="background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border:1px solid color-mix(in oklab,var(--danger) 25%,transparent);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px">
            <?= htmlspecialchars($errors[0], ENT_QUOTES) ?>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on" class="rz-auth-form">
            <div class="rz-field">
                <label class="rz-field-label">Email naslov</label>
                <input type="email" name="email" class="rz-input" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>" required autofocus autocomplete="email">
            </div>
            <div class="rz-field">
                <div style="display:flex;align-items:center;justify-content:space-between">
                    <label class="rz-field-label">Geslo</label>
                    <a href="<?= BASE_PATH ?>/affiliate/forgot-password.php" class="rz-link">Pozabljeno geslo?</a>
                </div>
                <input type="password" name="password" class="rz-input" required autocomplete="current-password">
            </div>
            <button type="submit" class="rz-btn rz-btn-primary" style="width:100%;justify-content:center;padding:11px">Prijavi se</button>
        </form>
    </div>

    <p style="text-align:center;margin-top:16px;font-size:12px;color:var(--ink-mute)">
        Nimate računa? <a href="<?= BASE_PATH ?>/affiliate/register.php" class="rz-link">Registracija</a>
    </p>
</div>
</div>
</body>
</html>
