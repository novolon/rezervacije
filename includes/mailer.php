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
 * Skupni email wrapper (card dizajn)
 */
function email_wrap(string $appName, string $body, string $footerNote): string {
    return "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
    <body style='margin:0;padding:0;background:#F3F4F6;font-family:Inter,-apple-system,BlinkMacSystemFont,\"Segoe UI\",sans-serif'>
        <table width='100%' cellpadding='0' cellspacing='0' style='background:#F3F4F6;padding:40px 16px'>
            <tr><td align='center'>
                <table width='100%' cellpadding='0' cellspacing='0' style='max-width:520px'>

                    <!-- Logo -->
                    <tr><td style='padding-bottom:20px;text-align:center'>
                        <table cellpadding='0' cellspacing='0' style='display:inline-table'>
                            <tr>
                                <td style='background:#F59E0B;border-radius:10px;width:36px;height:36px;text-align:center;vertical-align:middle'>
                                    <span style='color:#fff;font-size:18px;font-weight:700;line-height:36px;display:block'>R</span>
                                </td>
                                <td style='padding-left:10px;vertical-align:middle'>
                                    <span style='font-size:18px;font-weight:700;color:#111827'>{$appName}</span>
                                </td>
                            </tr>
                        </table>
                    </td></tr>

                    <!-- Card -->
                    <tr><td style='background:#fff;border-radius:12px;padding:36px 40px;box-shadow:0 1px 3px rgba(0,0,0,.08)'>
                        {$body}
                    </td></tr>

                    <!-- Footer -->
                    <tr><td style='text-align:center;padding-top:20px'>
                        <p style='margin:0;font-size:12px;color:#9CA3AF'>{$footerNote}</p>
                    </td></tr>

                </table>
            </td></tr>
        </table>
    </body>
    </html>";
}

/**
 * Pošlje email za potrditev naslova ob registraciji.
 */
function send_verification_email(string $email, string $fullName, string $token): bool {
    $link    = APP_URL . BASE_PATH . '/verify-email.php?token=' . urlencode($token);
    $appName = APP_NAME;

    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#111827'>Pozdravljeni, " . htmlspecialchars($fullName) . "!</p>
        <p style='margin:0 0 24px;font-size:14px;color:#6B7280;line-height:1.6'>Hvala za registracijo. Za aktivacijo računa potrdite vaš email naslov s klikom na spodnji gumb.</p>
        <p style='margin:28px 0'><a href='{$link}' target='_blank' style='background:#F59E0B;color:#ffffff;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:600;font-size:14px;font-family:Inter,-apple-system,sans-serif;display:inline-block;line-height:1.4;border:none'>Potrdi email naslov</a></p>
        <p style='margin:0;font-size:13px;color:#9CA3AF;line-height:1.5'>Če gumb ne deluje, kopirajte ta naslov v brskalnik:<br>
        <a href='{$link}' style='color:#F59E0B;word-break:break-all'>{$link}</a></p>
        <hr style='border:none;border-top:1px solid #F3F4F6;margin:24px 0'>
        <p style='margin:0;font-size:12px;color:#9CA3AF'>Link je veljaven 24 ur. Če niste vi ustvarili računa, ignorirajte to sporočilo.</p>";

    $html = email_wrap($appName, $body, $appName . ' · Avtomatsko sporočilo');
    $text = "Pozdravljeni, {$fullName}!\n\nPotrdi email naslov:\n{$link}\n\nLink je veljaven 24 ur.";

    return send_email($email, 'Potrdite vaš email – ' . APP_NAME, $html, $text);
}

/**
 * Pošlje email za ponastavitev gesla.
 */
function send_password_reset_email(string $email, string $fullName, string $token): bool {
    $link    = APP_URL . BASE_PATH . '/reset-password.php?token=' . urlencode($token);
    $appName = APP_NAME;

    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#111827'>Pozdravljeni, " . htmlspecialchars($fullName) . "!</p>
        <p style='margin:0 0 24px;font-size:14px;color:#6B7280;line-height:1.6'>Prejeli smo zahtevo za ponastavitev gesla vašega računa. Kliknite spodnji gumb za nadaljevanje.</p>
        <table role='presentation' border='0' cellpadding='0' cellspacing='0'>
  <tr>
    <td align='left'>
      <!--[if mso]>
      <v:roundrect xmlns:v='urn:schemas-microsoft-com:vml'
        xmlns:w='urn:schemas-microsoft-com:office:word'
        href='{$link}'
        style='height:48px;v-text-anchor:middle;width:220px;'
        arcsize='12%'
        stroke='f'
        fillcolor='#F59E0B'>
        <w:anchorlock/>
        <center style='color:#ffffff;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;'>
          Potrdi email naslov
        </center>
      </v:roundrect>
      <![endif]-->

      <!--[if !mso]><!-- -->
      <a href='{$link}' target='_blank'
         style='background:#F59E0B;
                border-radius:8px;
                color:#ffffff;
                display:inline-block;
                font-family:Inter,Arial,sans-serif;
                font-size:14px;
                font-weight:600;
                line-height:48px;
                text-align:center;
                text-decoration:none;
                width:220px;
                -webkit-text-size-adjust:none;'>
        Potrdi email naslov
      </a>
      <!--<![endif]-->
    </td>
  </tr>
</table>
        <div style='margin-top:24px'></div>
        <p style='margin:0;font-size:13px;color:#9CA3AF;line-height:1.5'>Če gumb ne deluje, kopirajte ta naslov v brskalnik:<br>
        <a href='{$link}' style='color:#F59E0B;word-break:break-all;'>{$link}</a></p>
        <hr style='border:none;border-top:1px solid #F3F4F6;margin:24px 0'>
        <p style='margin:0;font-size:12px;color:#9CA3AF'>Link je veljaven 1 uro. Če niste vi zahtevali ponastavitve, ignorirajte to sporočilo – vaše geslo ostane nespremenjeno.</p>";

    $html = email_wrap($appName, $body, $appName . ' · Avtomatsko sporočilo');
    $text = "Pozdravljeni, {$fullName}!\n\nPonastavi geslo:\n{$link}\n\nLink je veljaven 1 uro.";

    return send_email($email, 'Ponastavitev gesla – ' . APP_NAME, $html, $text);
}
