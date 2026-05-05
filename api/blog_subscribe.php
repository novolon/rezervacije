<?php
/**
 * Blog newsletter subscribe API – javni endpoint.
 *
 * Akcije:
 *   POST  /api/blog_subscribe.php?action=subscribe
 *         body (multipart ali JSON): email, lang, source
 *   GET   /api/blog_subscribe.php?action=confirm&t={token}  →  potrdi naslov
 *   GET   /api/blog_subscribe.php?action=unsubscribe&t={token}  →  odjava
 *
 * Rate-limit: 5 prijav/IP/uro (preprost, brez Redis-a).
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/blog_helpers.php';

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($method === 'POST' ? 'subscribe' : '');

// ─── Subscribe ───────────────────────────────────────────────────
if ($action === 'subscribe' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // Body lahko pride kot form ali JSON
    $email  = $_POST['email']        ?? '';
    $lang   = $_POST['lang']         ?? get_lang();
    $source = $_POST['source']       ?? 'unknown';
    $gdpr   = $_POST['gdpr_consent'] ?? '';
    if (empty($email)) {
        $body   = get_body();
        $email  = $body['email']        ?? '';
        $lang   = $body['lang']         ?? $lang;
        $source = $body['source']       ?? $source;
        $gdpr   = $body['gdpr_consent'] ?? $gdpr;
    }
    $email = trim(strtolower($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, null, 'Neveljaven email.', 400);
    }
    // GDPR consent obvezen
    if (empty($gdpr) || $gdpr === '0' || strtolower((string)$gdpr) === 'false') {
        json_response(false, null, t_raw('booked.subscribe.gdpr.required_error'), 400);
    }
    if (!in_array($lang, BLOG_LANGS, true)) $lang = 'sl';

    // Rate limit (preprost: zadnja ura po IP)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rcStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM blog_subscribers
         WHERE ip_address = ? AND created_at >= NOW() - INTERVAL 1 HOUR"
    );
    $rcStmt->execute([$ip]);
    if ((int)$rcStmt->fetchColumn() >= 5) {
        json_response(false, null, 'Preveč poskusov. Poskusi znova čez uro.', 429);
    }

    // Preveri obstoječo prijavo
    $exStmt = $pdo->prepare("SELECT id, confirmed_at, unsubscribed_at, confirm_token FROM blog_subscribers WHERE email = ?");
    $exStmt->execute([$email]);
    $existing = $exStmt->fetch();

    if ($existing && !empty($existing['confirmed_at']) && empty($existing['unsubscribed_at'])) {
        // Že naročen — izjavi success (ne razkrij obstoja)
        json_response(true, null, 'Hvala, naročnina je aktivna.');
    }

    $token = bin2hex(random_bytes(32));
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    if ($existing) {
        // Obnovi (lahko ponovna potrditev po unsubscribe)
        $pdo->prepare(
            "UPDATE blog_subscribers
             SET confirm_token = ?, lang_code = ?, source = ?, ip_address = ?, user_agent = ?, unsubscribed_at = NULL
             WHERE id = ?"
        )->execute([$token, $lang, $source, $ip, $userAgent, (int)$existing['id']]);
    } else {
        $pdo->prepare(
            "INSERT INTO blog_subscribers (email, lang_code, confirm_token, source, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$email, $lang, $token, $source, $ip, $userAgent]);
    }

    // Pošlji confirm email
    $confirmUrl = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . BASE_PATH . '/api/blog_subscribe.php?action=confirm&t=' . $token;

    $appName = defined('APP_NAME') ? APP_NAME : 'Rezble';
    $bodyHtml = email_h(_email_t('email.blog_subscribe.heading', $lang))
        . email_p(_email_t('email.blog_subscribe.intro', $lang))
        . email_button(_email_t('email.blog_subscribe.button', $lang), $confirmUrl)
        . email_p(_email_t('email.blog_subscribe.note', $lang), true);
    $html = function_exists('email_wrap')
        ? email_wrap($appName, $bodyHtml, '', ['type' => 'booked'])
        : ('<html><body>' . $bodyHtml . '</body></html>');
    @send_email($email, _email_t('email.blog_subscribe.subject', $lang), $html);

    json_response(true, null, 'Preveri email za potrditev.');
}

// ─── Confirm ─────────────────────────────────────────────────────
if ($action === 'confirm') {
    $token = $_GET['t'] ?? '';
    if (!$token || !preg_match('/^[a-f0-9]{32,128}$/', $token)) {
        http_response_code(400);
        echo '<!DOCTYPE html><meta charset="UTF-8"><body style="font-family:Inter,sans-serif;padding:40px;text-align:center"><h1>Neveljaven token</h1></body>';
        exit;
    }
    $stmt = $pdo->prepare("SELECT id, lang_code FROM blog_subscribers WHERE confirm_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo '<!DOCTYPE html><meta charset="UTF-8"><body style="font-family:Inter,sans-serif;padding:40px;text-align:center"><h1>Povezava ni veljavna</h1></body>';
        exit;
    }
    $pdo->prepare("UPDATE blog_subscribers SET confirmed_at = NOW(), confirm_token = NULL WHERE id = ?")->execute([(int)$row['id']]);
    $listingUrl = blog_listing_url($row['lang_code'] ?: 'sl');
    header('Location: ' . $listingUrl . '?subscribed=1');
    exit;
}

// ─── Unsubscribe ─────────────────────────────────────────────────
if ($action === 'unsubscribe') {
    $token = $_GET['t'] ?? '';
    if (!$token || !preg_match('/^[a-f0-9]{32,128}$/', $token)) {
        http_response_code(400);
        echo 'Neveljaven token.';
        exit;
    }
    $stmt = $pdo->prepare("SELECT id, lang_code FROM blog_subscribers WHERE confirm_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if ($row) {
        $pdo->prepare("UPDATE blog_subscribers SET unsubscribed_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
    }
    $listingUrl = blog_listing_url($row['lang_code'] ?? 'sl');
    header('Location: ' . $listingUrl . '?unsubscribed=1');
    exit;
    exit;
}

header('Content-Type: application/json; charset=utf-8');
json_response(false, null, 'Neznana akcija.', 400);
