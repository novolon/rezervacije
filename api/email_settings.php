<?php
/**
 * Per-restaurant email provider nastavitve (Premium only: custom_from_email).
 *
 * GET    ?id={restId}                  → pridobi (sanitiziran) status + provider info
 * PUT    ?id={restId}                  → posodobi provider/creds (NE pošlji test maila)
 * POST   ?id={restId}&action=test      → pošlji test email z trenutnimi nastavitvami,
 *                                         če uspe zapiši `email_verified_at`
 * DELETE ?id={restId}                  → resetiraj na default Mailgun (počisti creds)
 */

require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/email_provider.php';
require_once '../includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_admin();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

if (!user_has_feature($pdo, (int)$session['user_id'], 'custom_from_email')) {
    json_response(false, null, 'Custom email je na voljo samo v Premium paketu.', 403);
}

$restId = (int)($_GET['id'] ?? 0);
if (!$restId) json_response(false, null, 'ID manjka.', 400);
if (!admin_owns_restaurant($pdo, $session, $restId)) {
    json_response(false, null, 'Dostop zavrnjen.', 403);
}

// ─── GET: vrni status + (NE)izpostavi credentials (geslo skrij) ────
if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT email_provider, email_settings_enc, email_from_name, email_from_address, email_verified_at FROM restaurants WHERE id = ?");
    $stmt->execute([$restId]);
    $row = $stmt->fetch();
    $creds = email_decrypt($row['email_settings_enc'] ?? null) ?: [];

    $sanitized = $creds;
    // Skrij občutljive polja iz odgovora
    if (isset($sanitized['api_key'])) $sanitized['api_key'] = '••••••••';
    if (isset($sanitized['pass']))    $sanitized['pass']    = '••••••••';

    json_response(true, [
        'provider'      => $row['email_provider'] ?? 'default',
        'from_name'     => $row['email_from_name'] ?? null,
        'from_address'  => $row['email_from_address'] ?? null,
        'verified_at'   => $row['email_verified_at'] ?? null,
        'creds'         => $sanitized,
    ]);
}

// ─── PUT: posodobi config ───────────────────────────────────────────
if ($method === 'PUT') {
    $body     = get_body();
    $provider = $body['provider'] ?? 'default';
    if (!in_array($provider, ['default','mailgun','smtp'], true)) {
        json_response(false, null, 'Neveljaven provider.', 400);
    }

    $fromName    = trim((string)($body['from_name']    ?? '')) ?: null;
    $fromAddress = trim((string)($body['from_address'] ?? '')) ?: null;
    if ($fromAddress && !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        json_response(false, null, 'Neveljaven email naslov v "from".', 400);
    }

    $creds = [];
    if ($provider === 'mailgun') {
        $domain  = trim((string)($body['domain']  ?? ''));
        $apiKey  = trim((string)($body['api_key'] ?? ''));
        $region  = (($body['region'] ?? 'eu') === 'us') ? 'us' : 'eu';
        if (!$domain) json_response(false, null, 'Mailgun domain je obvezen.', 400);
        // Če api_key prikrit ("••••"), obdrži obstoječi
        if (preg_match('/^•+$/u', $apiKey)) {
            $cur = email_decrypt($pdo->query("SELECT email_settings_enc FROM restaurants WHERE id = " . (int)$restId)->fetchColumn());
            $apiKey = $cur['api_key'] ?? '';
        }
        if (!$apiKey) json_response(false, null, 'Mailgun API ključ je obvezen.', 400);
        $creds = ['domain' => $domain, 'api_key' => $apiKey, 'region' => $region];
    } elseif ($provider === 'smtp') {
        $host  = trim((string)($body['host']  ?? ''));
        $port  = (int)($body['port'] ?? 587);
        $user  = trim((string)($body['user']  ?? ''));
        $pass  = (string)($body['pass'] ?? '');
        $secure = in_array($body['secure'] ?? 'tls', ['tls','ssl','none'], true) ? $body['secure'] : 'tls';
        if (!$host || !$user) json_response(false, null, 'SMTP host in user sta obvezna.', 400);
        if (preg_match('/^•+$/u', $pass)) {
            $cur = email_decrypt($pdo->query("SELECT email_settings_enc FROM restaurants WHERE id = " . (int)$restId)->fetchColumn());
            $pass = $cur['pass'] ?? '';
        }
        if (!$pass) json_response(false, null, 'SMTP geslo je obvezno.', 400);
        if (!$fromAddress) json_response(false, null, '"From" email naslov je obvezen za SMTP.', 400);
        $creds = ['host' => $host, 'port' => $port, 'user' => $user, 'pass' => $pass, 'secure' => $secure];
    }

    $enc = $provider === 'default' ? null : email_encrypt($creds);

    // Sprememba provider/credentials/from invalidira prejšnjo verifikacijo
    $stmt = $pdo->prepare("UPDATE restaurants SET email_provider=?, email_settings_enc=?, email_from_name=?, email_from_address=?, email_verified_at=NULL WHERE id=?");
    $stmt->execute([$provider, $enc, $fromName, $fromAddress, $restId]);

    json_response(true, ['provider' => $provider, 'verified' => false]);
}

// ─── POST ?action=test: pošlji test email in označi verified ────────
if ($method === 'POST' && ($_GET['action'] ?? '') === 'test') {
    $cfg = get_restaurant_email_config($pdo, $restId);
    if (!$cfg) json_response(false, null, 'Najprej shrani provider nastavitve.', 400);

    $to = trim((string)(get_body()['to'] ?? ($session['email'] ?? '')));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        json_response(false, null, 'Neveljaven testni email naslov.', 400);
    }

    $stmt = $pdo->prepare("SELECT name FROM restaurants WHERE id = ?");
    $stmt->execute([$restId]);
    $rname = $stmt->fetchColumn() ?: 'Rezble';

    $subject = 'Rezble — Testni email za ' . $rname;
    $html = '<div style="font-family:system-ui,sans-serif;max-width:480px;margin:0 auto;padding:20px">'
        . '<h2 style="color:#1B4332">Test je uspel</h2>'
        . '<p>To je testno sporočilo iz Rezble preko vašega <strong>' . htmlspecialchars($cfg['provider']) . '</strong> providerja.</p>'
        . '<p>Od: <code>' . htmlspecialchars($cfg['from_email'] ?: 'unset') . '</code></p>'
        . '<p style="color:#888;font-size:12px;margin-top:30px">Restavracija: ' . htmlspecialchars($rname) . '</p>'
        . '</div>';
    $text = "Test uspel.\nProvider: {$cfg['provider']}\nFrom: " . ($cfg['from_email'] ?: 'unset') . "\nRestavracija: {$rname}\n";

    $ok = email_send_via_provider($cfg, $to, $subject, $html, $text);
    if (!$ok) json_response(false, null, 'Pošiljanje ni uspelo. Preveri credentials in DNS (SPF/DKIM).', 502);

    $pdo->prepare("UPDATE restaurants SET email_verified_at = NOW() WHERE id = ?")->execute([$restId]);
    json_response(true, ['verified_at' => date('c')]);
}

// ─── DELETE: počisti, preklopi na default ───────────────────────────
if ($method === 'DELETE') {
    $pdo->prepare("UPDATE restaurants SET email_provider='default', email_settings_enc=NULL, email_from_name=NULL, email_from_address=NULL, email_verified_at=NULL WHERE id = ?")->execute([$restId]);
    json_response(true);
}

json_response(false, null, 'Metoda ali akcija ni podprta.', 405);
