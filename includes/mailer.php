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
 * Privzeto fiksne širine 240px da deluje konsistentno v vseh klientih.
 */
function email_button(string $label, string $href, string $color = '#c8542b', string $marginBottom = '28px'): string {
    $hrefEsc  = htmlspecialchars($href, ENT_QUOTES);
    $labelEsc = htmlspecialchars($label, ENT_QUOTES);
    return "
    <table role='presentation' cellpadding='0' cellspacing='0' border='0' style='margin:8px 0 {$marginBottom}'>
        <tr><td>
            <!--[if mso]>
            <v:roundrect xmlns:v='urn:schemas-microsoft-com:vml' xmlns:w='urn:schemas-microsoft-com:office:word'
                href='{$hrefEsc}' style='height:48px;v-text-anchor:middle;width:240px;' arcsize='17%' stroke='f' fillcolor='{$color}'>
                <w:anchorlock/>
                <center style='color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:700'>{$labelEsc}</center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-- -->
            <a href='{$hrefEsc}' target='_blank' style='background:{$color};border-radius:8px;color:#ffffff;display:inline-block;font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Arial,sans-serif;font-size:15px;font-weight:600;line-height:48px;height:48px;min-width:240px;padding:0 28px;text-align:center;text-decoration:none;letter-spacing:0.01em;-webkit-text-size-adjust:none'>{$labelEsc}</a>
            <!--<![endif]-->
        </td></tr>
    </table>";
}

/**
 * Plain text link s puščico — alternativa gumbu za sekundarne akcije.
 */
function email_link(string $label, string $href, string $color = '#c8542b'): string {
    $hrefEsc  = htmlspecialchars($href, ENT_QUOTES);
    $labelEsc = htmlspecialchars($label, ENT_QUOTES);
    return "<a href='{$hrefEsc}' target='_blank' style='color:{$color};text-decoration:underline;font-weight:600;font-size:14.5px'>{$labelEsc}</a>";
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
    $size  = $muted ? '14px' : '15.5px';
    return "<p style='margin:0 0 14px;font-size:{$size};line-height:1.6;color:{$color}'>{$html}</p>";
}

/**
 * Tabela detajlov (paket, znesek, datum ipd.) — kompaktno, brez prevelikega spacinga.
 *
 * @param array $rows  [['Label', 'Value', $isAccent=false], ...]
 */
function email_details_table(array $rows, string $title = ''): string {
    $titleHtml = '';
    if ($title !== '') {
        $titleEsc = htmlspecialchars($title, ENT_QUOTES);
        $titleHtml = "<tr><td colspan='2' style='padding:10px 16px 4px;font-size:11px;color:#5a655e;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;border-bottom:1px solid #e8dcc9'>{$titleEsc}</td></tr>";
    }
    $rowsHtml = '';
    $first = true;
    foreach ($rows as $r) {
        $label = htmlspecialchars((string)($r[0] ?? ''), ENT_QUOTES);
        $value = (string)($r[1] ?? '');
        $accent= !empty($r[2]);
        $border = ($first && $title === '') ? '' : 'border-top:1px solid #e8dcc9;';
        $first = false;
        $valStyle = $accent
            ? 'font-weight:700;font-size:15.5px;color:#c8542b'
            : 'font-weight:600;font-size:14.5px;color:#1c2620';
        $rowsHtml .= "<tr><td style='padding:8px 16px;color:#5a655e;font-size:13.5px;width:130px;{$border}'>{$label}</td>"
                   . "<td style='padding:8px 16px;{$valStyle};{$border}'>{$value}</td></tr>";
    }
    return "<table role='presentation' style='width:100%;border-collapse:collapse;background:#fbf8f1;border-radius:10px;border:1px solid #e8dcc9;margin:0 0 22px'>{$titleHtml}{$rowsHtml}</table>";
}

/**
 * Booking details kartica — naslov restavracije + datum/čas + št. gostov.
 */
function _booking_details_html(string $restName, string $date, string $time, int $guests): string {
    $dateF = date('d. m. Y', strtotime($date));
    $dayF  = ['Ponedeljek','Torek','Sreda','Četrtek','Petek','Sobota','Nedelja'][date('N', strtotime($date)) - 1];
    $rest  = htmlspecialchars($restName, ENT_QUOTES);
    $timeE = htmlspecialchars($time, ENT_QUOTES);
    $guestsTxt = $guests . ' ' . ($guests === 1 ? 'gost' : ($guests < 5 ? 'gostje' : 'gostov'));
    return "<table role='presentation' style='width:100%;border-collapse:collapse;background:#fbf8f1;border:1px solid #e8dcc9;border-radius:10px;margin:18px 0 22px'>
        <tr><td style='padding:10px 18px 6px;font-size:11px;color:#5a655e;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;border-bottom:1px solid #e8dcc9'>Podrobnosti rezervacije</td></tr>
        <tr><td style='padding:14px 18px 4px'>
            <div style='font-family:Georgia,\"Times New Roman\",serif;font-size:18px;font-weight:700;color:#1c2620;margin-bottom:6px;line-height:1.25'>{$rest}</div>
            <div style='font-size:15px;color:#2a3530;line-height:1.45'>{$dayF}, {$dateF} ob {$timeE}</div>
            <div style='font-size:14px;color:#5a655e;margin-top:4px'>{$guestsTxt}</div>
        </td></tr>
    </table>";
}

/**
 * Pošlje email za potrditev naslova ob registraciji.
 */
function send_verification_email(string $email, string $fullName, string $token): bool {
    $link    = APP_URL . BASE_PATH . '/verify-email.php?token=' . urlencode($token);
    $appName = APP_NAME;
    $name    = htmlspecialchars($fullName, ENT_QUOTES);
    $linkEsc = htmlspecialchars($link, ENT_QUOTES);

    $body = email_h('Potrdi email naslov')
        . email_p("Pozdravljeni, <strong>{$name}</strong>!")
        . email_p('Hvala za registracijo. Za aktivacijo računa potrdite vaš email naslov s klikom na spodnji gumb.')
        . email_button('Potrdi email naslov', $link)
        . email_p("Če gumb ne deluje, kopirajte ta naslov v brskalnik:<br><a href='{$linkEsc}' style='color:#c8542b;word-break:break-all'>{$linkEsc}</a>", true)
        . email_p('Link je veljaven 24 ur. Če niste vi ustvarili računa, ignorirajte to sporočilo.', true);

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
    $name    = htmlspecialchars($fullName, ENT_QUOTES);
    $linkEsc = htmlspecialchars($link, ENT_QUOTES);

    $body = email_h('Ponastavi geslo')
        . email_p("Pozdravljeni, <strong>{$name}</strong>!")
        . email_p('Prejeli smo zahtevo za ponastavitev gesla vašega računa. Kliknite spodnji gumb za nadaljevanje.')
        . email_button('Ponastavi geslo', $link)
        . email_p("Če gumb ne deluje, kopirajte ta naslov v brskalnik:<br><a href='{$linkEsc}' style='color:#c8542b;word-break:break-all'>{$linkEsc}</a>", true)
        . email_p('Link je veljaven 1 uro. Če niste vi zahtevali ponastavitve, ignorirajte to sporočilo — vaše geslo ostane nespremenjeno.', true);

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

    $body = email_h('Nov zahtevek za predračun')
        . email_p('Prejeli ste zahtevek za letno plačilo po predračunu.', true)
        . email_details_table([
            ['Admin',  $aName],
            ['Email',  $aEmail],
            ['Paket',  $planName . ' (letno)'],
            ['Znesek', $price . '/leto', true],
        ])
        . email_button('Odpri superadmin panel', $panelUrl)
        . email_p('Po plačilu predračuna adminu ročno dodelite paket v superadmin panelu (Dodeli paket).', true);

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
        ? email_p('Naslednji avtomatski poskus: <strong>' . date('j. n. Y, H:i', $nextAttemptAt) . '</strong>')
        : email_p('To je bil zadnji samodejni poskus. Posodobite plačilni način ročno.');

    $name = htmlspecialchars($fullName, ENT_QUOTES);

    $body = email_h('Plačilo ni uspelo')
        . email_p("Poskus {$attempt} od 4", true)
        . email_p("Pozdravljeni, <strong>{$name}</strong>!")
        . email_p("Stripe ni uspel zaračunati <strong>{$amount}</strong> za paket <strong>{$planName}</strong>. Posodobite plačilni način, da preprečite prekinitev dostopa.")
        . $nextTry
        . email_button('Posodobi plačilni način', $billingUrl)
        . email_p('Če imate vprašanja, nas kontaktirajte.', true);

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

    $body = email_h('Prihajajoče plačilo')
        . email_p('Opomnik teden dni pred zaračunanjem.', true)
        . email_p("Pozdravljeni, <strong>{$name}</strong>!")
        . email_p('Obveščamo vas, da bo čez 7 dni zaračunano:')
        . email_details_table([
            ['Paket',  $planName],
            ['Znesek', $amount, true],
            ['Datum',  $billingDate],
        ])
        . email_button('Upravljaj naročnino', $billingUrl)
        . email_p('Plačilo bo izvedeno samodejno. Če želite preklicati, to storite pred datumom zaračunanja.', true);

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

    $body = email_h('Potrdite nov email naslov')
        . email_p("Pozdravljeni, <strong>{$name}</strong>!")
        . email_p('Prejeli smo zahtevo za spremembo email naslova vašega računa na ta naslov. Kliknite spodnji gumb za potrditev.')
        . email_button('Potrdi nov email', $link)
        . email_p('Link je veljaven 1 uro. Če niste zahtevali spremembe, ignorirajte to sporočilo.', true);

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
    return "<p style='margin:0 0 14px;font-size:14.5px;line-height:1.6;color:#2a3530'>"
        . "<a href='{$googleUrl}' target='_blank' style='color:#c8542b;text-decoration:underline;font-weight:600'>Dodaj v koledar</a>"
        . " &nbsp;·&nbsp; "
        . "<a href='{$icsUrl}' style='color:#c8542b;text-decoration:underline;font-weight:600'>Prenesi .ics</a>"
        . "</p>";
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
        $editUrl = htmlspecialchars(APP_URL . BASE_PATH . '/pages/reservation_edit.php?t=' . urlencode($editToken), ENT_QUOTES);
        $editLinks = email_p("<a href='{$editUrl}' style='color:#c8542b;text-decoration:underline;font-weight:600'>Uredi prošnjo →</a>");
    }

    $name = htmlspecialchars($guestName, ENT_QUOTES);
    $body = email_h('Vaša prošnja je sprejeta')
        . email_p("Pozdravljeni, <strong>{$name}</strong>!")
        . email_p('Vaša prošnja za rezervacijo je bila sprejeta. Ko jo potrdimo, vam pošljemo potrditev po e-pošti.')
        . $details
        . $editLinks
        . email_p('Če imate vprašanja, nas kontaktirajte neposredno v restavraciji.', true)
        . $contact;
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
        $editUrl = htmlspecialchars(APP_URL . BASE_PATH . '/pages/reservation_edit.php?t=' . urlencode($editToken), ENT_QUOTES);
        $editLinks = email_p("<a href='{$editUrl}' style='color:#c8542b;text-decoration:underline;font-weight:600'>Uredi rezervacijo →</a>");
    }

    $name = htmlspecialchars($guestName, ENT_QUOTES);
    $body = email_h('Rezervacija potrjena')
        . email_p("Pozdravljeni, <strong>{$name}</strong>!")
        . email_p('Vaša rezervacija je potrjena. Veselimo se vašega obiska!')
        . $details
        . $calLinks
        . $editLinks
        . email_p('Če ne morete priti, nas prosimo obvestite čim prej. Hvala!', true)
        . $contact;
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
    $name = htmlspecialchars($guestName, ENT_QUOTES);
    $body = email_h('Rezervacija ni mogoča')
        . email_p("Pozdravljeni, <strong>{$name}</strong>!")
        . email_p('Žal vaše rezervacije ne moremo potrditi za izbrani termin. Prosimo, poskusite z drugim terminom ali nas kontaktirajte.')
        . $details
        . email_p('Opravičujemo se za nevšečnosti.', true)
        . $contact;
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
    $name = htmlspecialchars($guestName, ENT_QUOTES);
    $body = email_h('Opomnik: jutri imate rezervacijo')
        . email_p("Pozdravljeni, <strong>{$name}</strong>!")
        . email_p('Opominjamo vas, da imate jutri rezervacijo.')
        . $details
        . $calLinks
        . email_p('Veselimo se vašega obiska. Če ne morete priti, nas prosimo obvestite čim prej.', true)
        . $contact;
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
        $urlApprove = htmlspecialchars(APP_URL . BASE_PATH . '/api/reservation_action.php?id=' . $reservationId . '&action=approve&sig=' . $sigApprove, ENT_QUOTES);
        $urlReject  = htmlspecialchars(APP_URL . BASE_PATH . '/api/reservation_action.php?id=' . $reservationId . '&action=reject&sig='  . $sigReject, ENT_QUOTES);
        $actionButtons = "
        <table role='presentation' cellpadding='0' cellspacing='0' border='0' style='margin:8px 0 14px'>
            <tr>
                <td style='padding-right:10px'>
                    <!--[if mso]>
                    <v:roundrect xmlns:v='urn:schemas-microsoft-com:vml' xmlns:w='urn:schemas-microsoft-com:office:word'
                        href='{$urlApprove}' style='height:48px;v-text-anchor:middle;width:220px;' arcsize='17%' stroke='f' fillcolor='#1B4332'>
                        <w:anchorlock/><center style='color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:700'>Potrdi rezervacijo</center>
                    </v:roundrect>
                    <![endif]-->
                    <!--[if !mso]><!-- -->
                    <a href='{$urlApprove}' target='_blank' style='background:#1B4332;border-radius:8px;color:#ffffff;display:inline-block;font-family:-apple-system,Arial,sans-serif;font-size:15px;font-weight:600;line-height:48px;height:48px;min-width:200px;padding:0 28px;text-align:center;text-decoration:none'>Potrdi rezervacijo</a>
                    <!--<![endif]-->
                </td>
                <td>
                    <a href='{$urlReject}' target='_blank' style='border:1.5px solid #b34822;border-radius:8px;color:#b34822;display:inline-block;font-family:-apple-system,Arial,sans-serif;font-size:15px;font-weight:600;line-height:45px;height:48px;min-width:160px;padding:0 24px;text-align:center;text-decoration:none;box-sizing:border-box'>Zavrni</a>
                </td>
            </tr>
        </table>";
    }

    $name = htmlspecialchars($guestName, ENT_QUOTES);
    $email = htmlspecialchars($guestEmail, ENT_QUOTES);
    $statusColor = $status === 'confirmed' ? '#065F46' : '#b34822';
    $body = email_h('Nova rezervacija')
        . email_p("Gost: <strong>{$name}</strong> ({$email})")
        . "<p style='margin:0 0 18px;font-size:13.5px;color:{$statusColor};font-weight:700;text-transform:uppercase;letter-spacing:0.05em'>{$statusTxt}</p>"
        . $details
        . $actionButtons
        . email_link('Odpri razpored →', $appLink);
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
    // Humanize request type
    $typeLabel = [
        'data_export'      => 'izvoz vaših osebnih podatkov',
        'export'           => 'izvoz vaših osebnih podatkov',
        'data_deletion'    => 'izbris vaših osebnih podatkov',
        'deletion'         => 'izbris vaših osebnih podatkov',
        'delete'           => 'izbris vaših osebnih podatkov',
        'data_correction'  => 'popravek vaših osebnih podatkov',
        'correction'       => 'popravek vaših osebnih podatkov',
        'access'           => 'dostop do vaših osebnih podatkov',
    ][$requestType] ?? ('zahtevek po GDPR (' . htmlspecialchars($requestType, ENT_QUOTES, 'UTF-8') . ')');

    $body = email_h('Potrditev GDPR zahtevka')
        . email_p("Prejeli smo vaš zahtevek za <strong>{$typeLabel}</strong>.")
        . email_p('Na vaš zahtevek bomo odgovorili v <strong>30 dneh</strong> skladno z Uredbo GDPR.')
        . email_p("Če niste oddali tega zahtevka, nas obvestite na <a href='mailto:zasebnost@rezervacije.si' style='color:#c8542b'>zasebnost@rezervacije.si</a>.")
        . email_button('Politika zasebnosti', $appLink . '/pages/privacy.php');
    $html = email_wrap($appName, $body, 'Potrditev GDPR zahtevka');
    $text = "Prejeli smo vaš zahtevek za {$typeLabel}. Odgovorili bomo v 30 dneh.";
    return send_email($toEmail, 'Potrditev GDPR zahtevka – ' . $appName, $html, $text);
}

// ─── Affiliate emaili ─────────────────────────────────────────────

function send_affiliate_verify_email(string $toEmail, string $name, string $token): bool {
    $appName = APP_NAME;
    $link    = APP_URL . BASE_PATH . '/affiliate/verify-email.php?token=' . urlencode($token);
    $nameEsc = htmlspecialchars($name, ENT_QUOTES);
    $body = email_h('Potrdi email naslov')
        . email_p("Pozdravljeni, <strong>{$nameEsc}</strong>!")
        . email_p('Hvala za prijavo v affiliate program. Klikni spodnji gumb za potrditev emaila.')
        . email_button('Potrdi email', $link)
        . email_p('Če niste oddali prijave, ignorirajte to sporočilo.', true);
    $html = email_wrap($appName, $body, 'Po potrditvi emaila bomo preverili vašo prijavo.');
    return send_email($toEmail, 'Potrdi email – Affiliate program', $html);
}

function send_affiliate_approved_email(string $toEmail, string $name, string $refCode): bool {
    $appName  = APP_NAME;
    $dashLink = APP_URL . BASE_PATH . '/affiliate/dashboard.php';
    $refLink  = APP_URL . BASE_PATH . '/?ref=' . urlencode($refCode);
    $nameEsc  = htmlspecialchars($name, ENT_QUOTES);
    $codeEsc  = htmlspecialchars($refCode, ENT_QUOTES);
    $linkEsc  = htmlspecialchars($refLink, ENT_QUOTES);

    $body = email_h('Vaša prijava je odobrena')
        . email_p("Pozdravljeni, <strong>{$nameEsc}</strong>!")
        . email_p('Vaša affiliate prijava je bila odobrena. Vaša unikatna referenčna koda:')
        . "<div style='background:#fae8df;border:1px solid #f4d4c4;border-radius:10px;padding:22px 28px;margin:0 0 22px;font-size:24px;font-weight:700;text-align:center;letter-spacing:.12em;color:#b34822;font-family:Georgia,\"Times New Roman\",serif'>{$codeEsc}</div>"
        . email_p('Vaša referenčna povezava:')
        . email_p("<a href='{$linkEsc}' style='color:#c8542b;word-break:break-all;text-decoration:underline'>{$linkEsc}</a>", true)
        . email_button('Odpri affiliate dashboard', $dashLink);
    $html = email_wrap($appName, $body, 'Dobrodošli v affiliate programu!');
    return send_email($toEmail, 'Vaša affiliate prijava je odobrena', $html);
}

function send_affiliate_rejected_email(string $toEmail, string $name, string $reason): bool {
    $appName = APP_NAME;
    $nameEsc = htmlspecialchars($name, ENT_QUOTES);
    $body = email_h('Affiliate prijava ni bila odobrena')
        . email_p("Pozdravljeni, <strong>{$nameEsc}</strong>.")
        . email_p('Po pregledu vaše prijave vam sporočamo, da je ne moremo odobriti.')
        . ($reason ? email_p("<strong>Razlog:</strong> " . htmlspecialchars($reason, ENT_QUOTES)) : '')
        . email_p("Kontaktirajte nas na <a href='mailto:info@rezervacije.si' style='color:#c8542b'>info@rezervacije.si</a> za več informacij.");
    $html = email_wrap($appName, $body, '');
    return send_email($toEmail, 'Affiliate prijava – obvestilo', $html);
}

function send_affiliate_payout_email(string $toEmail, string $name, float $amountEur, string $reference): bool {
    $appName = APP_NAME;
    $nameEsc = htmlspecialchars($name, ENT_QUOTES);
    $amount  = number_format($amountEur, 2, ',', '.') . ' €';

    $body = email_h('Izplačilo affiliate provizije')
        . email_p("Pozdravljeni, <strong>{$nameEsc}</strong>!")
        . email_p('Vaše nakazilo je bilo izvedeno:')
        . email_details_table([
            ['Referenca', htmlspecialchars($reference, ENT_QUOTES)],
            ['Znesek',    $amount, true],
        ])
        . email_p('Nakazilo bo vidno na vašem računu v 1–3 bančnih dneh.', true);
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

    $rows = [
        ['Paket',   $planName],
        ['Plačilo', $cycleLabel],
    ];
    if ($price > 0) $rows[] = ['Cena', $priceStr, true];

    $body = email_h('Naročnina posodobljena')
        . email_p('Vaš paket je bil uspešno spremenjen.', true)
        . email_p("Pozdravljeni, <strong>{$escapedName}</strong>!")
        . email_details_table($rows)
        . email_p('Račun za naročnino je na voljo v vašem računu pod <em>Naročnine &amp; Računi</em>.')
        . email_button('Odpri naročnine in račune', $billingUrl)
        . email_p('Hvala za zaupanje!', true);

    $html = email_wrap($appName, $body, $appName . ' · Potrditev spremembe paketa');
    $text  = "Naročnina posodobljena\n\nPaket: {$planName}\nPlačilo: {$cycleLabel}"
           . ($price > 0 ? "\nCena: {$priceStr}" : '')
           . "\n\nRačun si oglejte v vašem računu:\n{$billingUrl}";

    return send_email($to, 'Naročnina posodobljena – ' . $appName, $html, $text);
}

function send_affiliate_discount_granted_email(string $toEmail, string $name, string $code, float $percent): bool {
    $appName  = APP_NAME;
    $dashLink = APP_URL . BASE_PATH . '/affiliate/dashboard.php';
    $nameEsc  = htmlspecialchars($name, ENT_QUOTES);
    $codeEsc  = htmlspecialchars($code, ENT_QUOTES);
    $body = email_h('Vaša popustna koda je aktivna')
        . email_p("Pozdravljeni, <strong>{$nameEsc}</strong>!")
        . email_p('Pridobili ste popustno kodo za vaše stranke (' . (int)$percent . '% popust):')
        . "<div style='background:#fae8df;border:1px solid #f4d4c4;border-radius:10px;padding:22px 28px;margin:0 0 22px;font-size:24px;font-weight:700;text-align:center;letter-spacing:.12em;color:#b34822;font-family:Georgia,\"Times New Roman\",serif'>{$codeEsc}</div>"
        . email_p('Stranke vpišejo kodo pri plačilu ali kliknejo vaš kombinirani link v dashboardu.', true)
        . email_button('Odpri dashboard', $dashLink);
    $html = email_wrap($appName, $body, 'Vsako unovčenje kode se beleži v vašem dashboardu.');
    return send_email($toEmail, 'Vaša affiliate popustna koda: ' . $code, $html);
}
