<?php
/**
 * Mailgun HTTP API wrapper – pošilja email brez zunanjih knjižnic.
 * Zahteva konstante: MAILGUN_API_KEY, MAILGUN_DOMAIN, MAIL_FROM
 */

function send_email(string $to, string $subject, string $html, string $text = ''): bool {
    if (!defined('MAILGUN_API_KEY') || !MAILGUN_API_KEY) {
        error_log('Mailer: MAILGUN_API_KEY ni nastavljen.');
        return false;
    }

    $url = 'https://api.eu.mailgun.net/v3/' . MAILGUN_DOMAIN . '/messages';

    $data = [
        'from'    => MAIL_FROM,
        'to'      => $to,
        'subject' => $subject,
        'html'    => $html,
    ];
    if ($text) {
        $data['text'] = $text;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => 'api:' . MAILGUN_API_KEY,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log('Mailer curl error: ' . $error);
        return false;
    }
    if ($httpCode !== 200) {
        error_log('Mailer HTTP ' . $httpCode . ': ' . $response);
        return false;
    }

    return true;
}

/**
 * Pošlje email za potrditev naslova ob registraciji.
 */
function send_verification_email(string $email, string $fullName, string $token): bool {
    $link    = APP_URL . BASE_PATH . '/verify-email.php?token=' . urlencode($token);
    $appName = APP_NAME;

    $html = "
    <div style='font-family:Inter,sans-serif;max-width:520px;margin:0 auto;padding:32px 24px;'>
        <div style='text-align:center;margin-bottom:24px'>
            <div style='display:inline-block;background:#F59E0B;border-radius:10px;padding:10px 14px'>
                <span style='color:#fff;font-size:22px;font-weight:700'>{$appName}</span>
            </div>
        </div>
        <h2 style='color:#111827;margin:0 0 12px'>Potrdite vaš email naslov</h2>
        <p style='color:#374151;line-height:1.6'>Pozdravljeni, {$fullName}!</p>
        <p style='color:#374151;line-height:1.6'>Za dokončanje registracije kliknite spodnji gumb:</p>
        <div style='text-align:center;margin:28px 0'>
            <a href='{$link}'
               style='background:#F59E0B;color:#fff;text-decoration:none;padding:13px 32px;border-radius:8px;font-weight:600;font-size:15px;display:inline-block'>
                Potrdi email naslov
            </a>
        </div>
        <p style='color:#6B7280;font-size:13px'>Link je veljaven 24 ur. Če niste vi ustvarili računa, ignorirajte to sporočilo.</p>
        <hr style='border:none;border-top:1px solid #E5E7EB;margin:24px 0'>
        <p style='color:#9CA3AF;font-size:12px;text-align:center'>{$appName} · Avtomatsko sporočilo</p>
    </div>";

    $text = "Pozdravljeni, {$fullName}!\n\nPotrdi email naslov:\n{$link}\n\nLink je veljaven 24 ur.";

    return send_email($email, 'Potrdite vaš email – ' . APP_NAME, $html, $text);
}

/**
 * Pošlje email za ponastavitev gesla.
 */
function send_password_reset_email(string $email, string $fullName, string $token): bool {
    $link    = APP_URL . BASE_PATH . '/reset-password.php?token=' . urlencode($token);
    $appName = APP_NAME;

    $html = "
    <div style='font-family:Inter,sans-serif;max-width:520px;margin:0 auto;padding:32px 24px;'>
        <div style='text-align:center;margin-bottom:24px'>
            <div style='display:inline-block;background:#F59E0B;border-radius:10px;padding:10px 14px'>
                <span style='color:#fff;font-size:22px;font-weight:700'>{$appName}</span>
            </div>
        </div>
        <h2 style='color:#111827;margin:0 0 12px'>Ponastavitev gesla</h2>
        <p style='color:#374151;line-height:1.6'>Pozdravljeni, {$fullName}!</p>
        <p style='color:#374151;line-height:1.6'>Prejeli smo zahtevo za ponastavitev gesla vašega računa. Kliknite spodnji gumb:</p>
        <div style='text-align:center;margin:28px 0'>
            <a href='{$link}'
               style='background:#F59E0B;color:#fff;text-decoration:none;padding:13px 32px;border-radius:8px;font-weight:600;font-size:15px;display:inline-block'>
                Ponastavi geslo
            </a>
        </div>
        <p style='color:#6B7280;font-size:13px'>Link je veljaven 1 uro. Če niste vi zahtevali ponastavitve, ignorirajte to sporočilo – vaše geslo ostane nespremenjeno.</p>
        <hr style='border:none;border-top:1px solid #E5E7EB;margin:24px 0'>
        <p style='color:#9CA3AF;font-size:12px;text-align:center'>{$appName} · Avtomatsko sporočilo</p>
    </div>";

    $text = "Pozdravljeni, {$fullName}!\n\nPonastavi geslo:\n{$link}\n\nLink je veljaven 1 uro.";

    return send_email($email, 'Ponastavitev gesla – ' . APP_NAME, $html, $text);
}
