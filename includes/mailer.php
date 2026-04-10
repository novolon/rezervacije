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
        <h2 style='margin:0 0 6px;font-size:1.15rem;color:#111827'>Nov zahtevek za predračun</h2>
        <p style='margin:0 0 20px;font-size:.875rem;color:#6B7280'>Prejeli ste zahtevek za letno plačilo po predračunu.</p>
        <table style='width:100%;border-collapse:collapse;margin-bottom:24px'>
            <tr><td style='padding:8px 0;color:#6B7280;font-size:.875rem;width:110px'>Admin:</td>
                <td style='padding:8px 0;font-weight:600;color:#111827'>{$aName}</td></tr>
            <tr><td style='padding:8px 0;color:#6B7280;font-size:.875rem'>Email:</td>
                <td style='padding:8px 0;color:#111827'>{$aEmail}</td></tr>
            <tr><td style='padding:8px 0;color:#6B7280;font-size:.875rem'>Paket:</td>
                <td style='padding:8px 0;font-weight:600;color:#111827'>{$planName} (letno)</td></tr>
            <tr><td style='padding:8px 0;color:#6B7280;font-size:.875rem'>Znesek:</td>
                <td style='padding:8px 0;font-weight:700;font-size:1.05rem;color:#F59E0B'>{$price}/leto</td></tr>
        </table>
        <a href='{$panelUrl}' style='display:inline-block;background:#F59E0B;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem'>Odpri superadmin panel →</a>
        <p style='margin:20px 0 0;font-size:.8rem;color:#9CA3AF'>Po plačilu predračuna adminu ročno dodelite paket v superadmin panelu (Dodeli paket).</p>
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
        ? '<p style="margin:0 0 20px;font-size:.875rem;color:#374151">Naslednji avtomatski poskus: <strong>' . date('j. n. Y, H:i', $nextAttemptAt) . '</strong></p>'
        : '<p style="margin:0 0 20px;font-size:.875rem;color:#374151">To je bil zadnji samodejni poskus. Posodobite plačilni način ročno.</p>';

    $name = htmlspecialchars($fullName, ENT_QUOTES);

    $body = "
        <h2 style='margin:0 0 6px;font-size:1.15rem;color:#111827'>Plačilo ni uspelo</h2>
        <p style='margin:0 0 20px;font-size:.875rem;color:#6B7280'>Poskus {$attempt}</p>
        <p style='margin:0 0 16px;font-size:.875rem;color:#374151'>Pozdravljeni, <strong>{$name}</strong>!</p>
        <p style='margin:0 0 20px;font-size:.875rem;color:#374151'>
            Stripe ni uspel zaračunati <strong>{$amount}</strong> za paket <strong>{$planName}</strong>.<br>
            Posodobite plačilni način, da preprečite prekinitev dostopa.
        </p>
        {$nextTry}
        <a href='{$billingUrl}' style='display:inline-block;background:#EF4444;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;margin-bottom:20px'>Posodobi plačilni način →</a>
        <p style='margin:0;font-size:.8rem;color:#9CA3AF'>Če imate vprašanja, nas kontaktirajte.</p>
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
        <h2 style='margin:0 0 6px;font-size:1.15rem;color:#111827'>Prihajajočo plačilo</h2>
        <p style='margin:0 0 20px;font-size:.875rem;color:#6B7280'>Opomnik teden dni pred zaračunanjem</p>
        <p style='margin:0 0 16px;font-size:.875rem;color:#374151'>Pozdravljeni, <strong>{$name}</strong>!</p>
        <p style='margin:0 0 12px;font-size:.875rem;color:#374151'>
            Obveščamo vas, da bo čez 7 dni zaračunano:
        </p>
        <table style='width:100%;border-collapse:collapse;margin-bottom:24px;background:#F9FAFB;border-radius:8px'>
            <tr><td style='padding:12px 16px;color:#6B7280;font-size:.875rem;width:130px'>Paket:</td>
                <td style='padding:12px 16px;font-weight:600;color:#111827'>{$planName}</td></tr>
            <tr style='border-top:1px solid #E5E7EB'><td style='padding:12px 16px;color:#6B7280;font-size:.875rem'>Znesek:</td>
                <td style='padding:12px 16px;font-weight:700;font-size:1.05rem;color:#111827'>{$amount}</td></tr>
            <tr style='border-top:1px solid #E5E7EB'><td style='padding:12px 16px;color:#6B7280;font-size:.875rem'>Datum:</td>
                <td style='padding:12px 16px;font-weight:600;color:#111827'>{$billingDate}</td></tr>
        </table>
        <a href='{$billingUrl}' style='display:inline-block;background:#F59E0B;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;margin-bottom:20px'>Upravljaj naročnino →</a>
        <p style='margin:0;font-size:.8rem;color:#9CA3AF'>Plačilo bo izvedeno samodejno. Če želite preklicati, to storite pred datumom zaračunanja.</p>
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
        <h2 style='margin:0 0 6px;font-size:1.15rem;color:#111827'>Potrdite nov email naslov</h2>
        <p style='margin:0 0 20px;font-size:.875rem;color:#6B7280'>Zahteva za spremembo email naslova</p>
        <p style='margin:0 0 20px;font-size:.875rem;color:#374151'>
            Pozdravljeni, <strong>{$name}</strong>!<br><br>
            Prejeli smo zahtevo za spremembo email naslova vašega računa na ta naslov.<br>
            Kliknite spodnji gumb za potrditev.
        </p>
        <a href='{$link}' style='display:inline-block;background:#F59E0B;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;margin-bottom:20px'>Potrdi nov email →</a>
        <p style='margin:0;font-size:.8rem;color:#9CA3AF'>Link je veljaven 1 uro. Če niste zahtevali spremembe, ignorirajte to sporočilo.</p>
    ";

    $html = email_wrap($appName, $body, $appName . ' · Potrditev emaila');
    $text = "Potrdite nov email naslov\n\nKliknite:\n{$link}\n\nLink je veljaven 1 uro.";

    return send_email($newEmail, 'Potrdite nov email naslov – ' . $appName, $html, $text);
}

// ─── Booking emaili ───────────────────────────────────────────

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
               style='display:inline-block;background:#F9FAFB;color:#374151;text-decoration:none;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;border:1px solid #E5E7EB;margin-bottom:8px'>
                Prenesi .ics
            </a>
        </div>";
}

function _booking_details_html(string $restName, string $date, string $time, int $guests): string {
    $dateF = date('d. m. Y', strtotime($date));
    $dayF  = ['Ponedeljek','Torek','Sreda','Četrtek','Petek','Sobota','Nedelja'][date('N', strtotime($date)) - 1];
    return "
        <div style='background:#F9FAFB;border-radius:8px;padding:16px 20px;margin:20px 0;border-left:3px solid #1B4332'>
            <div style='font-size:13px;color:#6B7280;margin-bottom:6px'>Podrobnosti rezervacije</div>
            <div style='font-size:15px;font-weight:600;color:#111827;margin-bottom:4px'>" . htmlspecialchars($restName) . "</div>
            <div style='font-size:14px;color:#374151'>{$dayF}, {$dateF} ob " . htmlspecialchars($time) . "</div>
            <div style='font-size:13px;color:#6B7280;margin-top:4px'>" . htmlspecialchars((string)$guests) . " " . ($guests === 1 ? 'gost' : ($guests < 5 ? 'gostje' : 'gostov')) . "</div>
        </div>";
}

/**
 * Gost – rezervacija čaka potrditev (manual approve).
 */
function send_booking_pending_guest(string $toEmail, string $guestName, string $restName, string $date, string $time, int $guests): bool {
    $appName = APP_NAME;
    $details = _booking_details_html($restName, $date, $time, $guests);
    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#111827'>Pozdravljeni, " . htmlspecialchars($guestName) . "!</p>
        <p style='margin:0 0 20px;font-size:14px;color:#6B7280;line-height:1.6'>Vaša prošnja za rezervacijo je bila sprejeta. Ko jo potrdimo, vam pošljemo potrditev po e-pošti.</p>
        {$details}
        <p style='margin:0;font-size:13px;color:#9CA3AF'>Če imate vprašanja, nas kontaktirajte neposredno v restavraciji.</p>";
    $html = email_wrap($appName, $body, $appName . ' · Rezervacijska prošnja');
    $text = "Pozdravljeni {$guestName},\nVaša prošnja za rezervacijo v {$restName} ({$date} ob {$time}, {$guests} gostov) je bila sprejeta. Ko jo potrdimo, vas obvestimo.";
    return send_email($toEmail, 'Vaša prošnja za rezervacijo je bila sprejeta – ' . $restName, $html, $text);
}

/**
 * Gost – rezervacija potrjena (auto ali manual approve).
 */
function send_booking_confirmed_guest(string $toEmail, string $guestName, string $restName, string $date, string $time, int $guests, int $duration = 60): bool {
    $appName  = APP_NAME;
    $details  = _booking_details_html($restName, $date, $time, $guests);
    $calLinks = _calendar_links_html($date, $time, $duration, $restName, $guestName);
    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#111827'>Pozdravljeni, " . htmlspecialchars($guestName) . "!</p>
        <p style='margin:0 0 20px;font-size:14px;color:#6B7280;line-height:1.6'>Vaša rezervacija je potrjena. Veselimo se vašega obiska!</p>
        {$details}
        {$calLinks}
        <p style='margin:0;font-size:13px;color:#9CA3AF'>Če ne morete priti, nas prosimo obvestite čim prej. Hvala!</p>";
    $html = email_wrap($appName, $body, $appName . ' · Potrjena rezervacija');
    $text = "Pozdravljeni {$guestName},\nVaša rezervacija v {$restName} je potrjena: {$date} ob {$time}, {$guests} gostov.";
    return send_email($toEmail, 'Rezervacija potrjena – ' . $restName, $html, $text);
}

/**
 * Gost – rezervacija zavrnjena.
 */
function send_booking_rejected_guest(string $toEmail, string $guestName, string $restName, string $date, string $time, int $guests): bool {
    $appName = APP_NAME;
    $details = _booking_details_html($restName, $date, $time, $guests);
    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#111827'>Pozdravljeni, " . htmlspecialchars($guestName) . "!</p>
        <p style='margin:0 0 20px;font-size:14px;color:#6B7280;line-height:1.6'>Žal vaše rezervacije ne moremo potrditi za izbrani termin. Prosimo, poskusite z drugim terminom ali nas kontaktirajte.</p>
        {$details}
        <p style='margin:0;font-size:13px;color:#9CA3AF'>Opravičujemo se za nevšečnosti.</p>";
    $html = email_wrap($appName, $body, $appName . ' · Rezervacija ni mogoča');
    $text = "Pozdravljeni {$guestName},\nVaše rezervacije v {$restName} ({$date} ob {$time}) žal ne moremo potrditi. Prosimo, poskusite z drugim terminom.";
    return send_email($toEmail, 'Rezervacija ni mogoča – ' . $restName, $html, $text);
}

/**
 * Gost – opomnik 24h pred rezervacijo.
 */
function send_booking_reminder_guest(string $toEmail, string $guestName, string $restName, string $date, string $time, int $guests, int $duration = 60): bool {
    $appName  = APP_NAME;
    $details  = _booking_details_html($restName, $date, $time, $guests);
    $calLinks = _calendar_links_html($date, $time, $duration, $restName, $guestName);
    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#111827'>Pozdravljeni, " . htmlspecialchars($guestName) . "!</p>
        <p style='margin:0 0 20px;font-size:14px;color:#6B7280;line-height:1.6'>Opominjamo vas, da imate jutri rezervacijo.</p>
        {$details}
        {$calLinks}
        <p style='margin:0;font-size:13px;color:#9CA3AF'>Veselimo se vašega obiska. Če ne morete priti, nas prosimo obvestite čim prej.</p>";
    $html = email_wrap($appName, $body, $appName . ' · Opomnik rezervacije');
    $text = "Opomnik: jutri ({$date} ob {$time}) imate rezervacijo v {$restName} za {$guests} gostov.";
    return send_email($toEmail, 'Opomnik: jutri imate rezervacijo v ' . $restName, $html, $text);
}

/**
 * Admin – nova rezervacija prispela.
 */
function send_booking_notify_admin(string $toEmail, string $adminName, string $restName, string $guestName, string $guestEmail, string $date, string $time, int $guests, string $status): bool {
    $appName   = APP_NAME;
    $details   = _booking_details_html($restName, $date, $time, $guests);
    $statusTxt = $status === 'confirmed' ? 'Samodejno potrjena' : 'Čaka na vašo potrditev';
    $appLink   = APP_URL . BASE_PATH . '/pages/main.php';
    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#111827'>Nova rezervacija!</p>
        <p style='margin:0 0 8px;font-size:14px;color:#6B7280'>Gost: <strong>" . htmlspecialchars($guestName) . "</strong> (" . htmlspecialchars($guestEmail) . ")</p>
        <p style='margin:0 0 20px;font-size:13px;color:" . ($status === 'confirmed' ? '#065F46' : '#92400E') . ";font-weight:600'>{$statusTxt}</p>
        {$details}
        <p style='margin:20px 0 0'><a href='{$appLink}' style='background:#1B4332;color:#fff;text-decoration:none;padding:11px 24px;border-radius:8px;font-weight:600;font-size:.9rem;display:inline-block'>Odpri razpored →</a></p>";
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
            <a href='mailto:zasebnost@rezervacije.si' style='color:#F59E0B'>zasebnost@rezervacije.si</a>.
        </p>
        <p style='margin:0'><a href='{$appLink}/pages/privacy.php'
            style='background:#1B4332;color:#fff;text-decoration:none;padding:11px 24px;border-radius:8px;font-weight:600;font-size:.9rem;display:inline-block'>
            Politika zasebnosti →</a></p>";
    $html = email_wrap($appName, $body, 'Potrditev GDPR zahtevka');
    $text = "Prejeli smo vaš zahtevek za {$requestType}. Odgovorili bomo v 30 dneh.";
    return send_email($toEmail, 'Potrditev GDPR zahtevka – ' . $appName, $html, $text);
}
