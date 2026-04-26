<?php
require_once '../config.php';
require_once '../includes/db.php';

$token = trim($_GET['token'] ?? '');
$msg   = '';
$ok    = false;

if ($token) {
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT id FROM affiliates WHERE verification_token = ? AND email_verified_at IS NULL");
        $stmt->execute([$token]);
        $aff  = $stmt->fetch();

        if ($aff) {
            $pdo->prepare("UPDATE affiliates SET email_verified_at = NOW(), verification_token = NULL WHERE id = ?")
                ->execute([$aff['id']]);
            $ok  = true;
            $msg = 'Email potrjen! Vaša prijava čaka na odobritev. Ko jo pregledamo, vas obvestimo.';
        } else {
            $msg = 'Neveljaven ali porabljen token.';
        }
    } catch (\Throwable $e) {
        error_log('Affiliate verify-email error: ' . $e->getMessage());
        $msg = 'Napaka strežnika. Poskusite znova.';
    }
} else {
    $msg = 'Manjka token.';
}

$pageTitle = 'Potrditev emaila – Affiliate';
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

    <div class="rz-card" style="text-align:center;padding:40px 32px">
        <?php if ($ok): ?>
        <div style="width:64px;height:64px;border-radius:50%;background:color-mix(in oklab,var(--success) 12%,transparent);display:flex;align-items:center;justify-content:center;margin:0 auto 20px">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5 9-11"/></svg>
        </div>
        <h1 style="margin:0 0 10px;font-size:22px;font-weight:700;color:var(--ink)">Email potrjen!</h1>
        <?php else: ?>
        <div style="width:64px;height:64px;border-radius:50%;background:color-mix(in oklab,var(--danger) 10%,transparent);display:flex;align-items:center;justify-content:center;margin:0 auto 20px">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M6 18 18 6"/></svg>
        </div>
        <h1 style="margin:0 0 10px;font-size:22px;font-weight:700;color:var(--ink)">Napaka</h1>
        <?php endif; ?>
        <p style="color:var(--ink-mute);margin:0 0 28px;font-size:14px;line-height:1.6"><?= htmlspecialchars($msg, ENT_QUOTES) ?></p>
        <a href="<?= BASE_PATH ?>/affiliate/login.php" class="rz-btn rz-btn-primary" style="display:inline-flex">Na prijavo →</a>
    </div>
</div>
</div>
</body>
</html>
