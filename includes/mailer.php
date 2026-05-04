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
 * Skupni email wrapper (Rezble design system).
 * Tokens (sinhronizirano s public CSS):
 *   bg:        #f0e8dd  (cream)
 *   paper:     #ffffff
 *   ink:       #1c2620  (headings)
 *   text:      #2a3530
 *   text-2:    #5a655e  (muted)
 *   accent:    #c8542b  (terracotta)
 *   accent-2:  #b34822  (terracotta-2 / hover)
 *   accent-soft:#fae8df (highlight bg)
 *   divider:   #e8dcc9  (cream-2)
 */
function email_wrap(string $appName, string $body, string $footerNote): string {
    $year = date('Y');
    return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width,initial-scale=1'>
<title>{$appName}</title>
</head>
<body style='margin:0;padding:0;background:#f0e8dd;font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,\"Helvetica Neue\",Arial,sans-serif;color:#2a3530;-webkit-font-smoothing:antialiased'>
<table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='background:#f0e8dd;padding:48px 16px'>
    <tr><td align='center'>
        <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='max-width:560px'>

            <!-- Brand header -->
            <tr><td style='padding:0 0 24px;text-align:center'>
                <table role='presentation' cellpadding='0' cellspacing='0' border='0' style='display:inline-table'>
                    <tr>
                        <td style='background:#c8542b;border-radius:10px;width:38px;height:38px;text-align:center;vertical-align:middle'>
                            <span style='color:#ffffff;font-size:20px;font-weight:800;line-height:38px;display:block;font-family:Georgia,\"Times New Roman\",serif'>R</span>
                        </td>
                        <td style='padding-left:12px;vertical-align:middle'>
                            <span style='font-size:20px;font-weight:700;color:#1c2620;letter-spacing:-0.01em;font-family:Georgia,\"Times New Roman\",serif'>{$appName}</span>
                        </td>
                    </tr>
                </table>
            </td></tr>

            <!-- Card -->
            <tr><td style='background:#ffffff;border-radius:14px;padding:40px 44px;box-shadow:0 1px 2px rgba(28,38,32,0.06),0 8px 24px rgba(28,38,32,0.04)'>
                {$body}
            </td></tr>

            <!-- Footer -->
            <tr><td style='text-align:center;padding:24px 16px 8px'>
                <p style='margin:0 0 4px;font-size:12.5px;color:#5a655e;line-height:1.5'>{$footerNote}</p>
                <p style='margin:0;font-size:11px;color:#8a948e'>&copy; {$year} {$appName}</p>
            </td></tr>

        </table>
    </td></tr>
</table>
</body>
</html>";
}

/**
 * Bulletproof CTA button (Outlook VML + standardni HTML fallback).
 */
function email_button(string $label, string $href, string $color = '#c8542b'): string {
    $hrefEsc  = htmlspecialchars($href, ENT_QUOTES);
    $labelEsc = htmlspecialchars($label, ENT_QUOTES);
    return "
    <table role='presentation' cellpadding='0' cellspacing='0' border='0' style='margin:8px 0'>
        <tr><td>
            <!--[if mso]>
            <v:roundrect xmlns:v='urn:schemas-microsoft-com:vml' xmlns:w='urn:schemas-microsoft-com:office:word'
                href='{$hrefEsc}' style='height:48px;v-text-anchor:middle;width:240px;' arcsize='17%' stroke='f' fillcolor='{$color}'>
                <w:anchorlock/>
                <center style='color:#ffffff;font-family:Arial,sans-serif;font-size:14.5px;font-weight:700'>{$labelEsc}</center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-- -->
            <a href='{$hrefEsc}' target='_blank' style='background:{$color};border-radius:8px;color:#ffffff;display:inline-block;font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Arial,sans-serif;font-size:14.5px;font-weight:600;line-height:1;padding:15px 28px;text-decoration:none;letter-spacing:0.01em'>{$labelEsc}</a>
            <!--<![endif]-->
        </td></tr>
    </table>";
}

/**
 * Pomembna škatla (poudarjena vrednost — koda, znesek ipd.).
 */
function email_inset_box(string $html): string {
    return "<div style='background:#fae8df;border:1px solid #f4d4c4;border-radius:10px;padding:14px 18px;margin:18px 0;font-size:15px;line-height:1.5;color:#1c2620'>{$html}</div>";
}

/**
 * Tanka ločnica.
 */
function email_divider(): string {
    return "<hr style='border:none;border-top:1px solid #e8dcc9;margin:24px 0'>";
}

/**
 * Servisni heading (h2) v serif fontu.
 */
function email_h(string $text): string {
    $esc = htmlspecialchars($text, ENT_QUOTES);
    return "<h2 style='margin:0 0 14px;font-size:22px;line-height:1.25;font-weight:700;color:#1c2620;font-family:Georgia,\"Times New Roman\",serif;letter-spacing:-0.01em'>{$esc}</h2>";
}

/**
 * Standardni odstavek.
 */
function email_p(string $html, bool $muted = false): string {
    $color = $muted ? '#5a655e' : '#2a3530';
    $size  = $muted ? '13px' : '14.5px';
    return "<p style='margin:0 0 14px;font-size:{$size};line-height:1.6;color:{$color}'>{$html}</p>";
}

/**
 * Pošlje email za potrditev naslova ob registraciji.
 */
function send_verification_email(string $email, string $fullName, string $token): bool {
    $link    = APP_URL . BASE_PATH . '/verify-email.php?token=' . urlencode($token);
    $appName = APP_NAME;

    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#1c2620'>Pozdravljeni, " . htmlspecialchars($fullName) . "!</p>
        <p style='margin:0 0 24px;font-size:14px;color:#5a655e;line-height:1.6'>Hvala za registracijo. Za aktivacijo računa potrdite vaš email naslov s klikom na spodnji gumb.</p>
        <p style='margin:28px 0'><a href='{$link}' target='_blank' style='background:#c8542b;color:#ffffff;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:600;font-size:14px;font-family:Inter,-apple-system,sans-serif;display:inline-block;line-height:1.4;border:none'>Potrdi email naslov</a></p>
        <p style='margin:0;font-size:13px;color:#8a948e;line-height:1.5'>Če gumb ne deluje, kopirajte ta naslov v brskalnik:<br>
        <a href='{$link}' style='color:#c8542b;word-break:break-all'>{$link}</a></p>
        <hr style='border:none;border-top:1px solid #f7f4ee;margin:24px 0'>
        <p style='margin:0;font-size:12px;color:#8a948e'>Link je veljaven 24 ur. Če niste vi ustvarili računa, ignorirajte to sporočilo.</p>";

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
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#1c2620'>Pozdravljeni, " . htmlspecialchars($fullName) . "!</p>
        <p style='margin:0 0 24px;font-size:14px;color:#5a655e;line-height:1.6'>Prejeli smo zahtevo za ponastavitev gesla vašega računa. Kliknite spodnji gumb za nadaljevanje.</p>
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
        fillcolor='#c8542b'>
        <w:anchorlock/>
        <center style='color:#ffffff;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;'>
          Potrdi email naslov
        </center>
      </v:roundrect>
      <![endif]-->

      <!--[if !mso]><!-- -->
      <a href='{$link}' target='_blank'
         style='background:#c8542b;
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
        <p style='margin:0;font-size:13px;color:#8a948e;line-height:1.5'>Če gumb ne deluje, kopirajte ta naslov v brskalnik:<br>
        <a href='{$link}' style='color:#c8542b;word-break:break-all;'>{$link}</a></p>
        <hr style='border:none;border-top:1px solid #f7f4ee;margin:24px 0'>
        <p style='margin:0;font-size:12px;color:#8a948e'>Link je veljaven 1 uro. Če niste vi zahtevali ponastavitve, ignorirajte to sporočilo – vaše geslo ostane nespremenjeno.</p>";

    $html = email_wrap($appName, $body, $appName . ' · Avtomatsko sporočilo');
    $text = "Pozdravljeni, {$fullName}!\n\nPonastavi geslo:\n{$link}\n\nLink je veljaven 1 uro.";

    return send_email($email, 'Ponastavitev gesla – ' . APP_NAME, $html, $text);
}

/**
 * Pošlje email superadminu ob zahtevku za predračun (letno plačilo).
 */
function send_invoice_request_email(
    string $superadminEmail,
    string $adminName,
    string $adminEmail,
    string $planSlug,
    float  $yearlyPrice
): bool {
    $appName  = APP_NAME;
    $planName = ucfirst($planSlug);
    $price    = number_format($yearlyPrice, 2, ',', '.') . ' €';
    $panelUrl = APP_URL . BASE_PATH . '/pages/superadmin.php';

    $aName  = htmlspecialchars($adminName,  ENT_QUOTES);
    $aEmail = htmlspecialchars($adminEmail, ENT_QUOTES);

    $body = "
        <h2 style='margin:0 0 6px;font-size:1.15rem;color:#1c2620'>Nov zahtevek za predračun</h2>
        <p style='margin:0 0 20px;font-size:.875rem;color:#5a655e'>Prejeli ste zahtevek za letno plačilo po predračunu.</p>
        <table style='width:100%;border-collapse:collapse;margin-bottom:24px'>
            <tr><td style='padding:8px 0;color:#5a655e;font-size:.875rem;width:110px'>Admin:</td>
                <td style='padding:8px 0;font-weight:600;color:#1c2620'>{$aName}</td></tr>
            <tr><td style='padding:8px 0;color:#5a655e;font-size:.875rem'>Email:</td>
                <td style='padding:8px 0;color:#1c2620'>{$aEmail}</td></tr>
            <tr><td style='padding:8px 0;color:#5a655e;font-size:.875rem'>Paket:</td>
                <td style='padding:8px 0;font-weight:600;color:#1c2620'>{$planName} (letno)</td></tr>
            <tr><td style='padding:8px 0;color:#5a655e;font-size:.875rem'>Znesek:</td>
                <td style='padding:8px 0;font-weight:700;font-size:1.05rem;color:#c8542b'>{$price}/leto</td></tr>
        </table>
        <a href='{$panelUrl}' style='display:inline-block;background:#c8542b;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem'>Odpri superadmin panel →</a>
        <p style='margin:20px 0 0;font-size:.8rem;color:#8a948e'>Po plačilu predračuna adminu ročno dodelite paket v superadmin panelu (Dodeli paket).</p>
    ";

    $html = email_wrap($appName, $body, $appName . ' · Zahtevek za predračun');
    $text = "Nov zahtevek za predračun\n\nAdmin: {$adminName}\nEmail: {$adminEmail}\nPaket: {$planName} (letno)\nZnesek: {$price}/leto\n\nPanel: {$panelUrl}";

    return send_email($superadminEmail, "Zahtevek za predračun – {$adminName} ({$planName})", $html, $text);
}

/**
 * Pošlje email adminu ob neuspelem Stripe plačilu.
 */
function send_payment_failed_email(
    string $email,
    string $fullName,
    string $planName,
    float  $amountEur,
    int    $attemptCount,
    ?int   $nextAttemptAt
): bool {
    $appName = APP_NAME;
    $amount  = number_format($amountEur, 2, ',', '.') . ' €';
    $attempt = $attemptCount;
    $billingUrl = APP_URL . BASE_PATH . '/pages/billing.php';

    $nextTry = $nextAttemptAt
        ? '<p style="margin:0 0 20px;font-size:.875rem;color:#2a3530">Naslednji avtomatski poskus: <strong>' . date('j. n. Y, H:i', $nextAttemptAt) . '</strong></p>'
        : '<p style="margin:0 0 20px;font-size:.875rem;color:#2a3530">To je bil zadnji samodejni poskus. Posodobite plačilni način ročno.</p>';

    $name = htmlspecialchars($fullName, ENT_QUOTES);

    $body = "
        <h2 style='margin:0 0 6px;font-size:1.15rem;color:#1c2620'>Plačilo ni uspelo</h2>
        <p style='margin:0 0 20px;font-size:.875rem;color:#5a655e'>Poskus {$attempt}</p>
        <p style='margin:0 0 16px;font-size:.875rem;color:#2a3530'>Pozdravljeni, <strong>{$name}</strong>!</p>
        <p style='margin:0 0 20px;font-size:.875rem;color:#2a3530'>
            Stripe ni uspel zaračunati <strong>{$amount}</strong> za paket <strong>{$planName}</strong>.<br>
            Posodobite plačilni način, da preprečite prekinitev dostopa.
        </p>
        {$nextTry}
        <a href='{$billingUrl}' style='display:inline-block;background:#EF4444;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;margin-bottom:20px'>Posodobi plačilni način →</a>
        <p style='margin:0;font-size:.8rem;color:#8a948e'>Če imate vprašanja, nas kontaktirajte.</p>
    ";

    $html = email_wrap($appName, $body, $appName . ' · Obvestilo o plačilu');
    $text = "Plačilo ni uspelo (poskus {$attempt})\n\nZnesek: {$amount}\nPaket: {$planName}\n\nPosodobite plačilni način:\n{$billingUrl}";

    return send_email($email, 'Plačilo ni uspelo – ' . $appName, $html, $text);
}

/**
 * Pošlje opomnik adminu teden dni pred naslednjim plačilom.
 */
function send_upcoming_invoice_email(
    string $email,
    string $fullName,
    string $planName,
    float  $amountEur,
    int    $billingAt
): bool {
    $appName    = APP_NAME;
    $amount     = number_format($amountEur, 2, ',', '.') . ' €';
    $billingDate = date('j. n. Y', $billingAt);
    $billingUrl  = APP_URL . BASE_PATH . '/pages/billing.php';
    $name = htmlspecialchars($fullName, ENT_QUOTES);

    $body = "
        <h2 style='margin:0 0 6px;font-size:1.15rem;color:#1c2620'>Prihajajočo plačilo</h2>
        <p style='margin:0 0 20px;font-size:.875rem;color:#5a655e'>Opomnik teden dni pred zaračunanjem</p>
        <p style='margin:0 0 16px;font-size:.875rem;color:#2a3530'>Pozdravljeni, <strong>{$name}</strong>!</p>
        <p style='margin:0 0 12px;font-size:.875rem;color:#2a3530'>
            Obveščamo vas, da bo čez 7 dni zaračunano:
        </p>
        <table style='width:100%;border-collapse:collapse;margin-bottom:24px;background:#fbf8f1;border-radius:8px'>
            <tr><td style='padding:12px 16px;color:#5a655e;font-size:.875rem;width:130px'>Paket:</td>
                <td style='padding:12px 16px;font-weight:600;color:#1c2620'>{$planName}</td></tr>
            <tr style='border-top:1px solid #e8dcc9'><td style='padding:12px 16px;color:#5a655e;font-size:.875rem'>Znesek:</td>
                <td style='padding:12px 16px;font-weight:700;font-size:1.05rem;color:#1c2620'>{$amount}</td></tr>
            <tr style='border-top:1px solid #e8dcc9'><td style='padding:12px 16px;color:#5a655e;font-size:.875rem'>Datum:</td>
                <td style='padding:12px 16px;font-weight:600;color:#1c2620'>{$billingDate}</td></tr>
        </table>
        <a href='{$billingUrl}' style='display:inline-block;background:#c8542b;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;margin-bottom:20px'>Upravljaj naročnino →</a>
        <p style='margin:0;font-size:.8rem;color:#8a948e'>Plačilo bo izvedeno samodejno. Če želite preklicati, to storite pred datumom zaračunanja.</p>
    ";

    $html = email_wrap($appName, $body, $appName . ' · Opomnik pred zaračunanjem');
    $text = "Prihajajočo plačilo\n\nPaket: {$planName}\nZnesek: {$amount}\nDatum: {$billingDate}\n\nUpravljaj naročnino:\n{$billingUrl}";

    return send_email($email, 'Opomnik: plačilo ' . $amount . ' dne ' . $billingDate . ' – ' . $appName, $html, $text);
}

/**
 * Pošlje link za potrditev spremembe emaila na NOV email naslov.
 */
function send_email_change_email(string $newEmail, string $fullName, string $token): bool {
    $link    = APP_URL . BASE_PATH . '/pages/confirm-email-change.php?token=' . urlencode($token);
    $appName = APP_NAME;
    $name    = htmlspecialchars($fullName, ENT_QUOTES);

    $body = "
        <h2 style='margin:0 0 6px;font-size:1.15rem;color:#1c2620'>Potrdite nov email naslov</h2>
        <p style='margin:0 0 20px;font-size:.875rem;color:#5a655e'>Zahteva za spremembo email naslova</p>
        <p style='margin:0 0 20px;font-size:.875rem;color:#2a3530'>
            Pozdravljeni, <strong>{$name}</strong>!<br><br>
            Prejeli smo zahtevo za spremembo email naslova vašega računa na ta naslov.<br>
            Kliknite spodnji gumb za potrditev.
        </p>
        <a href='{$link}' style='display:inline-block;background:#c8542b;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;margin-bottom:20px'>Potrdi nov email →</a>
        <p style='margin:0;font-size:.8rem;color:#8a948e'>Link je veljaven 1 uro. Če niste zahtevali spremembe, ignorirajte to sporočilo.</p>
    ";

    $html = email_wrap($appName, $body, $appName . ' · Potrditev emaila');
    $text = "Potrdite nov email naslov\n\nKliknite:\n{$link}\n\nLink je veljaven 1 uro.";

    return send_email($newEmail, 'Potrdite nov email naslov – ' . $appName, $html, $text);
}

// ─── Booking emaili ───────────────────────────────────────────

/**
 * Vrne HTML blok s kontaktnimi podatki restavracije (ali prazen niz).
 */
function _contact_html(string $email, string $phone): string {
    if (!$email && !$phone) return '';
    $parts = [];
    if ($email) $parts[] = '<a href="mailto:' . htmlspecialchars($email) . '" style="color:#c8542b">' . htmlspecialchars($email) . '</a>';
    if ($phone) $parts[] = '<a href="tel:' . htmlspecialchars(preg_replace('/\s+/', '', $phone)) . '" style="color:#c8542b">' . htmlspecialchars($phone) . '</a>';
    return '<p style="margin:12px 0 0;font-size:13px;color:#8a948e">Kontakt: ' . implode(' · ', $parts) . '</p>';
}

function _calendar_google_url(string $date, string $time, int $duration, string $restName, string $guestName): string {
    $startTs = strtotime("{$date} {$time}");
    $endTs   = $startTs + $duration * 60;
    $dtStart = date('Ymd\THis', $startTs);
    $dtEnd   = date('Ymd\THis', $endTs);
    $title   = rawurlencode("Rezervacija – {$restName}");
    $details = rawurlencode("Rezervacija za {$guestName}");
    return "https://calendar.google.com/calendar/render?action=TEMPLATE&text={$title}&dates={$dtStart}/{$dtEnd}&details={$details}";
}

function _calendar_ics_url(string $date, string $time, int $duration, string $restName, string $guestName): string {
    $key = hash('sha256', DB_PASS . 'ics-v1');
    $sig = substr(hash_hmac('sha256', "{$date}|{$time}|{$duration}|{$restName}|{$guestName}", $key), 0, 16);
    return APP_URL . BASE_PATH . '/api/ics.php'
        . '?d='   . rawurlencode($date)
        . '&t='   . rawurlencode($time)
        . '&dur=' . $duration
        . '&r='   . rawurlencode($restName)
        . '&n='   . rawurlencode($guestName)
        . '&sig=' . $sig;
}

function _calendar_links_html(string $date, string $time, int $duration, string $restName, string $guestName): string {
    $googleUrl = htmlspecialchars(_calendar_google_url($date, $time, $duration, $restName, $guestName));
    $icsUrl    = htmlspecialchars(_calendar_ics_url($date, $time, $duration, $restName, $guestName));
    return "
        <div style='margin:20px 0'>
            <a href='{$googleUrl}' target='_blank'
               style='display:inline-block;background:#4285F4;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;margin-right:8px;margin-bottom:8px'>
                Dodaj v Google Koledar
            </a>
            <a href='{$icsUrl}'
               style='display:inline-block;background:#fbf8f1;color:#2a3530;text-decoration:none;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;border:1px solid #e8dcc9;margin-bottom:8px'>
                Prenesi .ics
            </a>
        </div>";
}

function _booking_details_html(string $restName, string $date, string $time, int $guests): string {
    $dateF = date('d. m. Y', strtotime($date));
    $dayF  = ['Ponedeljek','Torek','Sreda','Četrtek','Petek','Sobota','Nedelja'][date('N', strtotime($date)) - 1];
    return "
        <div style='background:#fbf8f1;border-radius:8px;padding:16px 20px;margin:20px 0;border-left:3px solid #1B4332'>
            <div style='font-size:13px;color:#5a655e;margin-bottom:6px'>Podrobnosti rezervacije</div>
            <div style='font-size:15px;font-weight:600;color:#1c2620;margin-bottom:4px'>" . htmlspecialchars($restName) . "</div>
            <div style='font-size:14px;color:#2a3530'>{$dayF}, {$dateF} ob " . htmlspecialchars($time) . "</div>
            <div style='font-size:13px;color:#5a655e;margin-top:4px'>" . htmlspecialchars((string)$guests) . " " . ($guests === 1 ? 'gost' : ($guests < 5 ? 'gostje' : 'gostov')) . "</div>
        </div>";
}

/**
 * Gost – rezervacija čaka potrditev (manual approve).
 */
function send_booking_pending_guest(string $toEmail, string $guestName, string $restName, string $date, string $time, int $guests, string $editToken = '', string $contactEmail = '', string $contactPhone = ''): bool {
    $appName = APP_NAME;
    $details = _booking_details_html($restName, $date, $time, $guests);
    $contact = _contact_html($contactEmail, $contactPhone);

    $editLinks = '';
    if ($editToken) {
        $cancelUrl = htmlspecialchars(APP_URL . BASE_PATH . '/pages/reservation_edit.php?t=' . urlencode($editToken) . '&action=cancel');
        $editUrl   = htmlspecialchars(APP_URL . BASE_PATH . '/pages/reservation_edit.php?t=' . urlencode($editToken));
        $editLinks = "
        <div style='margin:20px 0;padding:16px 20px;background:#fbf8f1;border-radius:8px;border:1px solid #e8dcc9'>
            <div style='font-size:13px;color:#5a655e;margin-bottom:10px'>Upravljanje prošnje:</div>
            <a href='{$editUrl}'
               style='display:inline-block;background:#1B4332;color:#fff;text-decoration:none;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;margin-right:8px;margin-bottom:6px'>
                Uredi prošnjo
            </a>
            <a href='{$cancelUrl}'
               style='display:inline-block;background:#fff;color:#DC2626;text-decoration:none;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;border:1.5px solid #FCA5A5;margin-bottom:6px'>
                Prekliči prošnjo
            </a>
        </div>";
    }

    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#1c2620'>Pozdravljeni, " . htmlspecialchars($guestName) . "!</p>
        <p style='margin:0 0 20px;font-size:14px;color:#5a655e;line-height:1.6'>Vaša prošnja za rezervacijo je bila sprejeta. Ko jo potrdimo, vam pošljemo potrditev po e-pošti.</p>
        {$details}
        {$editLinks}
        <p style='margin:0;font-size:13px;color:#8a948e'>Če imate vprašanja, nas kontaktirajte neposredno v restavraciji.</p>
        {$contact}";
    $html = email_wrap($appName, $body, $appName . ' · Rezervacijska prošnja');
    $text = "Pozdravljeni {$guestName},\nVaša prošnja za rezervacijo v {$restName} ({$date} ob {$time}, {$guests} gostov) je bila sprejeta. Ko jo potrdimo, vas obvestimo.";
    return send_email($toEmail, 'Vaša prošnja za rezervacijo je bila sprejeta – ' . $restName, $html, $text);
}

/**
 * Gost – rezervacija potrjena (auto ali manual approve).
 */
function send_booking_confirmed_guest(string $toEmail, string $guestName, string $restName, string $date, string $time, int $guests, int $duration = 60, string $editToken = '', string $contactEmail = '', string $contactPhone = ''): bool {
    $appName  = APP_NAME;
    $details  = _booking_details_html($restName, $date, $time, $guests);
    $calLinks = _calendar_links_html($date, $time, $duration, $restName, $guestName);
    $contact  = _contact_html($contactEmail, $contactPhone);

    $editLinks = '';
    if ($editToken) {
        $editUrl   = htmlspecialchars(APP_URL . BASE_PATH . '/pages/reservation_edit.php?t=' . urlencode($editToken));
        $cancelUrl = htmlspecialchars(APP_URL . BASE_PATH . '/pages/reservation_edit.php?t=' . urlencode($editToken) . '&action=cancel');
        $editLinks = "
        <div style='margin:20px 0;padding:16px 20px;background:#fbf8f1;border-radius:8px;border:1px solid #e8dcc9'>
            <div style='font-size:13px;color:#5a655e;margin-bottom:10px'>Upravljanje rezervacije:</div>
            <a href='{$editUrl}'
               style='display:inline-block;background:#1B4332;color:#fff;text-decoration:none;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;margin-right:8px;margin-bottom:6px'>
                Uredi rezervacijo
            </a>
            <a href='{$cancelUrl}'
               style='display:inline-block;background:#fff;color:#DC2626;text-decoration:none;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;border:1.5px solid #FCA5A5;margin-bottom:6px'>
                Odpovem rezervacijo
            </a>
        </div>";
    }

    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#1c2620'>Pozdravljeni, " . htmlspecialchars($guestName) . "!</p>
        <p style='margin:0 0 20px;font-size:14px;color:#5a655e;line-height:1.6'>Vaša rezervacija je potrjena. Veselimo se vašega obiska!</p>
        {$details}
        {$calLinks}
        {$editLinks}
        <p style='margin:0;font-size:13px;color:#8a948e'>Če ne morete priti, nas prosimo obvestite čim prej. Hvala!</p>
        {$contact}";
    $html = email_wrap($appName, $body, $appName . ' · Potrjena rezervacija');
    $text = "Pozdravljeni {$guestName},\nVaša rezervacija v {$restName} je potrjena: {$date} ob {$time}, {$guests} gostov.";
    return send_email($toEmail, 'Rezervacija potrjena – ' . $restName, $html, $text);
}

/**
 * Gost – rezervacija zavrnjena.
 */
function send_booking_rejected_guest(string $toEmail, string $guestName, string $restName, string $date, string $time, int $guests, string $contactEmail = '', string $contactPhone = ''): bool {
    $appName = APP_NAME;
    $details = _booking_details_html($restName, $date, $time, $guests);
    $contact = _contact_html($contactEmail, $contactPhone);
    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#1c2620'>Pozdravljeni, " . htmlspecialchars($guestName) . "!</p>
        <p style='margin:0 0 20px;font-size:14px;color:#5a655e;line-height:1.6'>Žal vaše rezervacije ne moremo potrditi za izbrani termin. Prosimo, poskusite z drugim terminom ali nas kontaktirajte.</p>
        {$details}
        <p style='margin:0;font-size:13px;color:#8a948e'>Opravičujemo se za nevšečnosti.</p>
        {$contact}";
    $html = email_wrap($appName, $body, $appName . ' · Rezervacija ni mogoča');
    $text = "Pozdravljeni {$guestName},\nVaše rezervacije v {$restName} ({$date} ob {$time}) žal ne moremo potrditi. Prosimo, poskusite z drugim terminom.";
    return send_email($toEmail, 'Rezervacija ni mogoča – ' . $restName, $html, $text);
}

/**
 * Gost – opomnik 24h pred rezervacijo.
 */
function send_booking_reminder_guest(string $toEmail, string $guestName, string $restName, string $date, string $time, int $guests, int $duration = 60, string $contactEmail = '', string $contactPhone = ''): bool {
    $appName  = APP_NAME;
    $details  = _booking_details_html($restName, $date, $time, $guests);
    $calLinks = _calendar_links_html($date, $time, $duration, $restName, $guestName);
    $contact  = _contact_html($contactEmail, $contactPhone);
    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#1c2620'>Pozdravljeni, " . htmlspecialchars($guestName) . "!</p>
        <p style='margin:0 0 20px;font-size:14px;color:#5a655e;line-height:1.6'>Opominjamo vas, da imate jutri rezervacijo.</p>
        {$details}
        {$calLinks}
        <p style='margin:0;font-size:13px;color:#8a948e'>Veselimo se vašega obiska. Če ne morete priti, nas prosimo obvestite čim prej.</p>
        {$contact}";
    $html = email_wrap($appName, $body, $appName . ' · Opomnik rezervacije');
    $text = "Opomnik: jutri ({$date} ob {$time}) imate rezervacijo v {$restName} za {$guests} gostov.";
    return send_email($toEmail, 'Opomnik: jutri imate rezervacijo v ' . $restName, $html, $text);
}

/**
 * Admin – nova rezervacija prispela.
 */
function send_booking_notify_admin(string $toEmail, string $adminName, string $restName, string $guestName, string $guestEmail, string $date, string $time, int $guests, string $status, int $reservationId = 0): bool {
    $appName   = APP_NAME;
    $details   = _booking_details_html($restName, $date, $time, $guests);
    $statusTxt = $status === 'confirmed' ? 'Samodejno potrjena' : 'Čaka na vašo potrditev';
    $appLink   = APP_URL . BASE_PATH . '/pages/main.php';

    // Gumba Potrdi/Zavrni – samo za pending rezervacije
    $actionButtons = '';
    if ($status === 'pending' && $reservationId > 0) {
        $sigApprove = hash_hmac('sha256', "{$reservationId}|approve", DB_PASS . 'admin-action-v1');
        $sigReject  = hash_hmac('sha256', "{$reservationId}|reject",  DB_PASS . 'admin-action-v1');
        $urlApprove = htmlspecialchars(APP_URL . BASE_PATH . '/api/reservation_action.php?id=' . $reservationId . '&action=approve&sig=' . $sigApprove);
        $urlReject  = htmlspecialchars(APP_URL . BASE_PATH . '/api/reservation_action.php?id=' . $reservationId . '&action=reject&sig='  . $sigReject);
        $actionButtons = "
        <div style='margin:20px 0 4px;display:flex;gap:10px;flex-wrap:wrap'>
            <a href='{$urlApprove}'
               style='display:inline-block;background:#1B4332;color:#fff;text-decoration:none;padding:11px 24px;border-radius:8px;font-weight:600;font-size:.9rem'>
                ✓ Potrdi rezervacijo
            </a>
            <a href='{$urlReject}'
               style='display:inline-block;background:#fff;color:#DC2626;text-decoration:none;padding:11px 24px;border-radius:8px;font-weight:600;font-size:.9rem;border:1.5px solid #FCA5A5'>
                ✕ Zavrni rezervacijo
            </a>
        </div>
        <p style='margin:6px 0 0;font-size:.75rem;color:#8a948e'>Ali odpri razpored za več možnosti.</p>";
    }

    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#1c2620'>Nova rezervacija!</p>
        <p style='margin:0 0 8px;font-size:14px;color:#5a655e'>Gost: <strong>" . htmlspecialchars($guestName) . "</strong> (" . htmlspecialchars($guestEmail) . ")</p>
        <p style='margin:0 0 20px;font-size:13px;color:" . ($status === 'confirmed' ? '#065F46' : '#b34822') . ";font-weight:600'>{$statusTxt}</p>
        {$details}
        {$actionButtons}
        <p style='margin:20px 0 0'><a href='{$appLink}' style='background:#c8542b;color:#fff;text-decoration:none;padding:9px 20px;border-radius:8px;font-weight:600;font-size:.85rem;display:inline-block'>Odpri razpored →</a></p>";
    $html = email_wrap($appName, $body, $appName . ' · Obvestilo o rezervaciji');
    $text = "Nova rezervacija v {$restName}!\nGost: {$guestName} ({$guestEmail})\n{$date} ob {$time}, {$guests} gostov.\nStatus: {$statusTxt}";
    return send_email($toEmail, 'Nova rezervacija – ' . $restName, $html, $text);
}

/**
 * Potrditveni email za GDPR zahtevek
 */
function send_gdpr_confirmation(string $toEmail, string $requestType): bool {
    $appName  = defined('APP_NAME') ? APP_NAME : 'Rezervacije';
    $appLink  = (defined('APP_URL') ? APP_URL : '') . (defined('BASE_PATH') ? BASE_PATH : '');
    $body     = "
        <p style='margin:0 0 12px'>Prejeli smo vaš zahtevek za <strong>" . htmlspecialchars($requestType, ENT_QUOTES, 'UTF-8') . "</strong>.</p>
        <p style='margin:0 0 12px'>Na vaš zahtevek bomo odgovorili v <strong>30 dneh</strong> skladno z Uredbo GDPR.</p>
        <p style='margin:0 0 20px'>Če niste oddali tega zahtevka, nas obvestite na
            <a href='mailto:zasebnost@rezervacije.si' style='color:#c8542b'>zasebnost@rezervacije.si</a>.
        </p>
        <p style='margin:0'><a href='{$appLink}/pages/privacy.php'
            style='background:#1B4332;color:#fff;text-decoration:none;padding:11px 24px;border-radius:8px;font-weight:600;font-size:.9rem;display:inline-block'>
            Politika zasebnosti →</a></p>";
    $html = email_wrap($appName, $body, 'Potrditev GDPR zahtevka');
    $text = "Prejeli smo vaš zahtevek za {$requestType}. Odgovorili bomo v 30 dneh.";
    return send_email($toEmail, 'Potrditev GDPR zahtevka – ' . $appName, $html, $text);
}

// ─── Affiliate emaili ─────────────────────────────────────────────

function send_affiliate_verify_email(string $toEmail, string $name, string $token): bool {
    $appName = APP_NAME;
    $link    = APP_URL . BASE_PATH . '/affiliate/verify-email.php?token=' . urlencode($token);
    $body    = "<h2 style='margin:0 0 16px;font-size:1.3rem;color:#1c2620'>Potrdi email naslov</h2>
        <p style='margin:0 0 12px'>Pozdravljeni, <strong>" . htmlspecialchars($name, ENT_QUOTES) . "</strong>!</p>
        <p style='margin:0 0 20px'>Hvala za prijavo v affiliate program. Klikni spodnji gumb za potrditev emaila.</p>
        <p style='margin:0'><a href='{$link}' style='background:#c8542b;color:#fff;text-decoration:none;padding:11px 24px;border-radius:8px;font-weight:600;font-size:.9rem;display:inline-block'>Potrdi email →</a></p>
        <p style='margin:16px 0 0;font-size:.82rem;color:#8a948e'>Če niste oddali prijave, ignorirajte to sporočilo.</p>";
    $html = email_wrap($appName, $body, 'Po potrditvi emaila bomo preverili vašo prijavo.');
    return send_email($toEmail, 'Potrdi email – Affiliate program', $html);
}

function send_affiliate_approved_email(string $toEmail, string $name, string $refCode): bool {
    $appName  = APP_NAME;
    $dashLink = APP_URL . BASE_PATH . '/affiliate/dashboard.php';
    $refLink  = APP_URL . BASE_PATH . '/?ref=' . urlencode($refCode);
    $body     = "<h2 style='margin:0 0 16px;font-size:1.3rem;color:#1c2620'>Vaša prijava je odobrena! 🎉</h2>
        <p style='margin:0 0 12px'>Pozdravljeni, <strong>" . htmlspecialchars($name, ENT_QUOTES) . "</strong>!</p>
        <p style='margin:0 0 12px'>Vaša affiliate prijava je bila odobrena. Vaša unikatna referenčna koda je:</p>
        <div style='background:#fae8df;border:1px solid #f4d4c4;border-radius:8px;padding:12px 16px;margin:0 0 20px;font-size:1.4rem;font-weight:700;text-align:center;letter-spacing:.1em;color:#b34822'>{$refCode}</div>
        <p style='margin:0 0 8px'>Vaša referenčna povezava:</p>
        <p style='margin:0 0 20px;font-size:.85rem;color:#5a655e;word-break:break-all'>{$refLink}</p>
        <p style='margin:0'><a href='{$dashLink}' style='background:#c8542b;color:#fff;text-decoration:none;padding:11px 24px;border-radius:8px;font-weight:600;font-size:.9rem;display:inline-block'>Odpri affiliate dashboard →</a></p>";
    $html = email_wrap($appName, $body, 'Dobrodošli v affiliate programu!');
    return send_email($toEmail, 'Vaša affiliate prijava je odobrena!', $html);
}

function send_affiliate_rejected_email(string $toEmail, string $name, string $reason): bool {
    $appName = APP_NAME;
    $body    = "<h2 style='margin:0 0 16px;font-size:1.3rem;color:#1c2620'>Affiliate prijava ni bila odobrena</h2>
        <p style='margin:0 0 12px'>Pozdravljeni, <strong>" . htmlspecialchars($name, ENT_QUOTES) . "</strong>.</p>
        <p style='margin:0 0 12px'>Po pregledu vaše prijave vam sporočamo, da je ne moremo odobriti.</p>"
        . ($reason ? "<p style='margin:0 0 12px'><strong>Razlog:</strong> " . htmlspecialchars($reason, ENT_QUOTES) . "</p>" : '')
        . "<p style='margin:0'>Kontaktirajte nas na <a href='mailto:info@rezervacije.si' style='color:#c8542b'>info@rezervacije.si</a> za več informacij.</p>";
    $html = email_wrap($appName, $body, '');
    return send_email($toEmail, 'Affiliate prijava – obvestilo', $html);
}

function send_affiliate_payout_email(string $toEmail, string $name, float $amountEur, string $reference): bool {
    $appName = APP_NAME;
    $body    = "<h2 style='margin:0 0 16px;font-size:1.3rem;color:#1c2620'>Izplačilo affiliate provizije</h2>
        <p style='margin:0 0 12px'>Pozdravljeni, <strong>" . htmlspecialchars($name, ENT_QUOTES) . "</strong>!</p>
        <p style='margin:0 0 12px'>Vaše nakazilo je bilo izvedeno:</p>
        <table style='width:100%;border-collapse:collapse;margin:0 0 20px'>
            <tr><td style='padding:8px 0;color:#5a655e;border-bottom:1px solid #f7f4ee'>Referenca</td><td style='padding:8px 0;font-weight:600;border-bottom:1px solid #f7f4ee;text-align:right'>" . htmlspecialchars($reference, ENT_QUOTES) . "</td></tr>
            <tr><td style='padding:8px 0;color:#5a655e'>Znesek</td><td style='padding:8px 0;font-weight:700;font-size:1.1rem;color:#059669;text-align:right'>" . number_format($amountEur, 2, ',', '.') . " €</td></tr>
        </table>
        <p style='margin:0;font-size:.85rem;color:#8a948e'>Nakazilo bo vidno na vašem računu v 1–3 bančnih dneh.</p>";
    $html = email_wrap($appName, $body, 'Hvala za vaše partnerstvo!');
    return send_email($toEmail, 'Affiliate izplačilo – ' . $reference, $html);
}

/**
 * Potrditveni email ob spremembi/aktivaciji paketa.
 */
function send_plan_changed_email(
    string $to,
    string $name,
    string $planName,
    string $billingCycle,
    float  $price
): bool {
    $appName     = APP_NAME;
    $billingUrl  = APP_URL . BASE_PATH . '/pages/billing.php';
    $cycleLabel  = $billingCycle === 'yearly' ? 'letno' : 'mesečno';
    $priceStr    = $price > 0
        ? number_format($price, 2, ',', '.') . ' €/' . ($billingCycle === 'yearly' ? 'leto' : 'mesec')
        : 'brezplačno';
    $escapedName = htmlspecialchars($name, ENT_QUOTES);

    $priceRow = $price > 0
        ? "<tr style='border-top:1px solid #e8dcc9'><td style='padding:12px 16px;color:#5a655e;font-size:.875rem'>Cena:</td>
               <td style='padding:12px 16px;font-weight:700;font-size:1.05rem;color:#1c2620'>{$priceStr}</td></tr>"
        : '';

    $body = "
        <h2 style='margin:0 0 6px;font-size:1.15rem;color:#1c2620'>Naročnina posodobljena</h2>
        <p style='margin:0 0 20px;font-size:.875rem;color:#5a655e'>Vaš paket je bil uspešno spremenjen.</p>
        <p style='margin:0 0 16px;font-size:.875rem;color:#2a3530'>Pozdravljeni, <strong>{$escapedName}</strong>!</p>
        <table style='width:100%;border-collapse:collapse;margin-bottom:24px;background:#fbf8f1;border-radius:8px'>
            <tr><td style='padding:12px 16px;color:#5a655e;font-size:.875rem;width:130px'>Paket:</td>
                <td style='padding:12px 16px;font-weight:600;color:#1c2620'>{$planName}</td></tr>
            <tr style='border-top:1px solid #e8dcc9'><td style='padding:12px 16px;color:#5a655e;font-size:.875rem'>Plačilo:</td>
                <td style='padding:12px 16px;font-weight:600;color:#1c2620'>{$cycleLabel}</td></tr>
            {$priceRow}
        </table>
        <p style='margin:0 0 20px;font-size:.875rem;color:#2a3530'>
            Račun za naročnino je na voljo v vašem računu pod <em>Naročnine &amp; Računi</em>.
        </p>
        <a href='{$billingUrl}' style='display:inline-block;background:#c8542b;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;margin-bottom:20px'>Odpri naročnine in račune →</a>
        <p style='margin:0;font-size:.8rem;color:#8a948e'>Hvala za zaupanje!</p>
    ";

    $html = email_wrap($appName, $body, $appName . ' · Potrditev spremembe paketa');
    $text  = "Naročnina posodobljena\n\nPaket: {$planName}\nPlačilo: {$cycleLabel}"
           . ($price > 0 ? "\nCena: {$priceStr}" : '')
           . "\n\nRačun si oglejte v vašem računu:\n{$billingUrl}";

    return send_email($to, 'Naročnina posodobljena – ' . $appName, $html, $text);
}

function send_affiliate_discount_granted_email(string $toEmail, string $name, string $code, float $percent): bool {
    $appName  = APP_NAME;
    $dashLink = APP_URL . BASE_PATH . '/affiliate/dashboard.php';
    $body     = "<h2 style='margin:0 0 16px;font-size:1.3rem;color:#1c2620'>Vaša popustna koda je aktivna!</h2>
        <p style='margin:0 0 12px'>Pozdravljeni, <strong>" . htmlspecialchars($name, ENT_QUOTES) . "</strong>!</p>
        <p style='margin:0 0 12px'>Pridobili ste popustno kodo za vaše stranke (" . (int)$percent . "% popust):</p>
        <div style='background:#ECFDF5;border:1px solid #6EE7B7;border-radius:8px;padding:12px 16px;margin:0 0 20px;font-size:1.4rem;font-weight:700;text-align:center;letter-spacing:.1em;color:#065F46'>" . htmlspecialchars($code, ENT_QUOTES) . "</div>
        <p style='margin:0 0 20px;font-size:.85rem;color:#5a655e'>Stranke vpišejo kodo pri plačilu ali kliknejo vaš kombinirani link v dashboardu.</p>
        <p style='margin:0'><a href='{$dashLink}' style='background:#c8542b;color:#fff;text-decoration:none;padding:11px 24px;border-radius:8px;font-weight:600;font-size:.9rem;display:inline-block'>Odpri dashboard →</a></p>";
    $html = email_wrap($appName, $body, 'Vsako unovčenje kode se beleži v vašem dashboardu.');
    return send_email($toEmail, 'Vaša affiliate popustna koda: ' . $code, $html);
}
