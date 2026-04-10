<?php
/**
 * Blokirajoči zaslon za neuspelo plačilo – izpiše kompletno HTML stran.
 * Vključi pred HTML outputom in takoj klici exit.
 */
$fullNameBlock = htmlspecialchars($_SESSION['full_name'] ?? '', ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plačilo ni uspelo – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin.css">
</head>
<body>
<header class="app-header">
    <a href="<?= BASE_PATH ?>/pages/billing.php" class="header-logo">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
            <rect width="28" height="28" rx="7" fill="#F59E0B"/>
            <path d="M7 10h14M7 14h14M7 18h9" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <?= h(APP_NAME) ?>
    </a>
    <div class="header-actions">
        <?php if ($fullNameBlock): ?>
        <span class="header-user"><?= $fullNameBlock ?></span>
        <?php endif; ?>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
    </div>
</header>

<div style="min-height:calc(100vh - var(--header-h));display:flex;align-items:center;justify-content:center;padding:24px;background:var(--color-bg)">
    <div style="background:#fff;border:1px solid #FCA5A5;border-radius:var(--radius-lg);padding:40px 36px;max-width:480px;width:100%;text-align:center;box-shadow:var(--shadow-md)">
        <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="1.5" style="margin-bottom:20px">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <h2 style="font-size:1.3rem;font-weight:700;color:#111827;margin:0 0 10px">Plačilo ni uspelo</h2>
        <p style="color:#6B7280;font-size:.9rem;line-height:1.6;margin:0 0 28px">
            Vaša naročnina ni bila podaljšana. Posodobite plačilni način, da ohranite dostop do vseh funkcij.
            Poslali smo vam email z navodili.
        </p>
        <a href="<?= BASE_PATH ?>/pages/billing.php" class="btn btn-primary" style="display:inline-block;text-decoration:none;padding:.6rem 1.4rem">
            Posodobi plačilo →
        </a>
        <div style="margin-top:16px">
            <a href="<?= BASE_PATH ?>/logout.php" style="font-size:.8rem;color:var(--color-muted);text-decoration:none">Odjava</a>
        </div>
    </div>
</div>
</body>
</html>
