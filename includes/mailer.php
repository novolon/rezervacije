<?php
/**
 * Mailgun HTTP API wrapper – pošilja email brez zunanjih knjižnic.
 * Zahteva konstante: MAILGUN_API_KEY, MAILGUN_DOMAIN, MAIL_FROM
 *
 * Per-restaurant override: mailer_use_restaurant($pdo, $restId) pred klici
 * (custom Mailgun domena ali SMTP). mailer_use_default() po koncu sklopa.
 */

require_once __DIR__ . '/email_provider.php';
require_once __DIR__ . '/branding_helper.php';

// Override config (thread-local).
$GLOBALS['_mailer_override'] = null;
$GLOBALS['_mailer_branding'] = null;

function mailer_use_restaurant(PDO $pdo, int $restId): void {
    $GLOBALS['_mailer_override'] = get_restaurant_email_config($pdo, $restId);
    // Naloži tudi branding (za skritje "Powered by Rezble" v footer-u, če je premium toggle).
    $stmt = $pdo->prepare("SELECT id, owner_id, hide_branding FROM restaurants WHERE id = ?");
    $stmt->execute([$restId]);
    $row = $stmt->fetch();
    $GLOBALS['_mailer_branding'] = $row ? get_restaurant_branding($pdo, $row) : null;
}
function mailer_use_default(): void {
    $GLOBALS['_mailer_override'] = null;
    $GLOBALS['_mailer_branding'] = null;
}
function mailer_branding_hidden(): bool {
    $b = $GLOBALS['_mailer_branding'] ?? null;
    return $b !== null && !empty($b['hide_branding']);
}

function send_email(string $to, string $subject, string $html, string $text = ''): bool {
    // Per-restaurant override (samo Premium z verificiranim email-om).
    $override = $GLOBALS['_mailer_override'] ?? null;
    if ($override && !empty($override['verified_at'])) {
        return email_send_via_provider($override, $to, $subject, $html, $text);
    }

    // Privzeti Rezble Mailgun.
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
 * Email-specific translation helper. Bere lang/{lang}.json direktno (ni odvisen
 * od lang.php auto-init), kešira per-lang. Za nedefinirane ključe pade nazaj
 * na sl.json. Ne escapa HTML — klicalec mora user-vnose pred-escapirati.
 */
function _email_t(string $key, string $lang = 'sl', array $params = []): string {
    static $cache = [];
    static $allowed = ['sl','en','de','it','fr','hr','es','pt'];
    if (!in_array($lang, $allowed, true)) $lang = 'sl';

    if (!isset($cache[$lang])) {
        $file = __DIR__ . '/../lang/' . $lang . '.json';
        $cache[$lang] = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    }
    $str = $cache[$lang][$key] ?? null;
    if ($str === null && $lang !== 'sl') {
        if (!isset($cache['sl'])) {
            $file = __DIR__ . '/../lang/sl.json';
            $cache['sl'] = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        }
        $str = $cache['sl'][$key] ?? $key;
    } elseif ($str === null) {
        $str = $key;
    }
    foreach ($params as $k => $v) {
        $str = str_replace('{' . $k . '}', (string)$v, $str);
    }
    return $str;
}

/**
 * Resolves email lang iz request konteksta. Klicalci lahko namesto $lang
 * parametra pošljejo to, kadar nimajo direktnega lang inputa.
 *
 * Prioriteta: POST['lang'] > GET['lang'] > rzlang cookie > session lang > 'sl'.
 */
function _resolve_email_lang(): string {
    static $allowed = ['sl','en','de','it','fr','hr','es','pt'];
    $lang = $_POST['lang'] ?? $_GET['lang'] ?? null;
    if (!$lang && !empty($_COOKIE['rzlang']))   $lang = $_COOKIE['rzlang'];
    if (!$lang && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['lang'])) $lang = $_SESSION['lang'];
    return is_string($lang) && in_array($lang, $allowed, true) ? $lang : 'sl';
}

/** Lokaliziran labelček za število gostov. */
function _email_guests_label(int $n, string $lang): string {
    if ($n === 1)               $key = 'email.booking.guests_one';
    elseif ($n >= 2 && $n <= 4) $key = 'email.booking.guests_few';
    else                        $key = 'email.booking.guests_many';
    return _email_t($key, $lang, ['n' => $n]);
}

/** Lokaliziran datum + dan v tednu. */
function _email_format_date(string $date, string $lang): string {
    $ts = strtotime($date);
    $day = _email_t('days.' . (date('N', $ts) - 1), $lang);
    return $day . ', ' . date('d. m. Y', $ts);
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
/**
 * @param string $appName
 * @param string $body
 * @param string $footerNote     Legacy: kratko sporočilo nad copyright vrstico (npr. "appName · auto").
 *                               Pri novih klicih je lahko prazen — footer-Extra pove vse.
 * @param array  $footerExtra    Strukturiran footer:
 *  - empty                                                                 → minimalen "© YEAR Rezble" footer (default).
 *  - ['type' => 'guest', 'rest_name'=>, 'rest_address'=>?, 'rest_email'=>?,
 *     'rest_phone'=>?, 'lang'=>?]                                          → polni guest footer (rezervacija pri X).
 *  - ['type' => 'booked']                                                  → "Booked by Rezble" subscriber footer.
 */
function email_wrap(string $appName, string $body, string $footerNote = '', array $footerExtra = []): string {
    $year = date('Y');
    $logoUrl = (defined('APP_URL') ? rtrim(APP_URL, '/') : '')
             . (defined('BASE_PATH') ? BASE_PATH : '')
             . '/assets/images/rezble@2x.png';
    $footerHtml = _email_render_footer($footerNote, $footerExtra, $year, $appName);
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
                <img src='{$logoUrl}' alt='{$appName}' width='120' style='display:inline-block;height:auto;max-width:140px;border:0;outline:none;text-decoration:none'>
            </td></tr>

            <!-- Card -->
            <tr><td style='background:#ffffff;border-radius:14px;padding:40px 44px;box-shadow:0 1px 2px rgba(28,38,32,0.06),0 8px 24px rgba(28,38,32,0.04)'>
                {$body}
            </td></tr>

            <!-- Footer -->
            <tr><td style='text-align:center;padding:24px 16px 8px'>
                {$footerHtml}
            </td></tr>

        </table>
    </td></tr>
</table>
</body>
</html>";
}

/** Footer rendering — različen za guest / booked / default tipe. */
function _email_render_footer(string $footerNote, array $footerExtra, string $year, string $appName): string {
    $type     = $footerExtra['type'] ?? 'default';
    $rezbleUrl = 'https://www.rezble.com';
    $bookedUrl = 'https://www.rezble.com/booked';
    $hideRezble = function_exists('mailer_branding_hidden') && mailer_branding_hidden();
    $lang       = $footerExtra['lang'] ?? 'sl';

    // "Powered by Rezble" line — pridem v vse footer-je razen če je hide_branding=1 (Premium).
    $poweredByLabels = [
        'sl'=>'Brez skrbi z','en'=>'Powered by','de'=>'Bereitgestellt von',
        'es'=>'Funciona con','fr'=>'Propulsé par','hr'=>'Pokreće',
        'it'=>'Powered by','pt'=>'Com tecnologia',
    ];
    $poweredBy = $poweredByLabels[$lang] ?? $poweredByLabels['en'];
    $rezbleLine = $hideRezble ? '' :
        "<p style='margin:8px 0 0;font-size:11px;color:#8a948e;line-height:1.5'>{$poweredBy} "
      . "<a href='{$rezbleUrl}' style='color:#8a948e;text-decoration:none;font-weight:600;border-bottom:1px solid #d4d4d4' target='_blank'>Rezble</a></p>";

    if ($type === 'guest') {
        $restName    = htmlspecialchars((string)($footerExtra['rest_name']    ?? ''), ENT_QUOTES);
        $restAddress = htmlspecialchars((string)($footerExtra['rest_address'] ?? ''), ENT_QUOTES);
        $restEmail   = htmlspecialchars((string)($footerExtra['rest_email']   ?? ''), ENT_QUOTES);
        $restPhone   = htmlspecialchars((string)($footerExtra['rest_phone']   ?? ''), ENT_QUOTES);
        $privacyUrl  = (defined('APP_URL') ? rtrim(APP_URL, '/') : '')
                     . (defined('BASE_PATH') ? BASE_PATH : '') . '/pages/privacy.php';

        $line1 = $restAddress !== '' ? ($restName . ', ' . $restAddress) : $restName;
        $reasonText = _email_t('email.footer.guest_reason', $lang, ['rest' => $restName]);
        $privacyLabel = _email_t('email.footer.privacy_link', $lang);
        $contactLabel = _email_t('email.footer.contact_label', $lang);

        $contactParts = [];
        if ($restEmail !== '') $contactParts[] = "<a href='mailto:{$restEmail}' style='color:#5a655e;text-decoration:underline'>{$restEmail}</a>";
        if ($restPhone !== '') $contactParts[] = "<a href='tel:" . preg_replace('/\s+/', '', $restPhone) . "' style='color:#5a655e;text-decoration:underline'>{$restPhone}</a>";
        $contactBlock = !empty($contactParts) ? (' &middot; ' . $contactLabel . ' ' . implode(' ', $contactParts)) : '';

        return "<p style='margin:0 0 6px;font-size:12.5px;color:#5a655e;line-height:1.5;font-weight:600'>{$line1}</p>"
             . "<p style='margin:0 0 4px;font-size:11.5px;color:#8a948e;line-height:1.55'>{$reasonText}</p>"
             . "<p style='margin:0 0 4px;font-size:11.5px;color:#8a948e;line-height:1.55'>"
             . "<a href='{$privacyUrl}' style='color:#8a948e;text-decoration:underline'>{$privacyLabel}</a>"
             . $contactBlock
             . "</p>"
             . "<p style='margin:0;font-size:11px;color:#8a948e'>&copy; {$year} {$restName}</p>"
             . $rezbleLine;
    }

    if ($type === 'booked') {
        return "<p style='margin:0;font-size:11.5px;color:#8a948e;line-height:1.5'>"
             . "&copy; {$year} <a href='{$bookedUrl}' style='color:#8a948e;text-decoration:none' target='_blank'>Booked</a> by "
             . "<a href='{$rezbleUrl}' style='color:#8a948e;text-decoration:none' target='_blank'>Rezble</a>"
             . "</p>";
    }

    // Default: minimalen footer (za auth, billing, affiliate, gdpr emaile)
    // Default tip se uporablja za platform-level emaile (registracije, billing, GDPR) —
    // pri teh hide_branding NE velja, ker so to Rezble emaili, ne restaurant-jevi.
    $extraNote = $footerNote !== '' ? "<p style='margin:0 0 4px;font-size:12.5px;color:#5a655e;line-height:1.5'>{$footerNote}</p>" : '';
    return $extraNote
         . "<p style='margin:0;font-size:11.5px;color:#8a948e'>&copy; {$year} "
         . "<a href='{$rezbleUrl}' style='color:#8a948e;text-decoration:none' target='_blank'>Rezble</a></p>";
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
    return "<h2 style='margin:0 0 14px;font-size:22px;line-height:1.25;font-weight:700;color:#1c2620;letter-spacing:-0.01em'>{$esc}</h2>";
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
function _booking_details_html(string $restName, string $date, string $time, int $guests, string $lang = 'sl'): string {
    $rest  = htmlspecialchars($restName, ENT_QUOTES);
    $timeE = htmlspecialchars($time, ENT_QUOTES);
    $datePart  = _email_format_date($date, $lang);
    $atTime    = _email_t('email.booking.at_time', $lang, ['time' => $timeE]);
    $guestsTxt = _email_guests_label($guests, $lang);
    $title     = _email_t('email.booking.details_title', $lang);
    return "<table role='presentation' style='width:100%;border-collapse:collapse;background:#fbf8f1;border:1px solid #e8dcc9;border-radius:10px;margin:18px 0 22px'>
        <tr><td style='padding:10px 18px 6px;font-size:11px;line-height:13px;color:#5a655e;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;border-bottom:1px solid #e8dcc9'>{$title}</td></tr>
        <tr><td style='padding:14px 18px 14px'>
            <div style='font-family:Georgia,\"Times New Roman\",serif;font-size:18px;font-weight:700;color:#1c2620;margin-bottom:6px;line-height:1.25'>{$rest}</div>
            <div style='font-size:15px;color:#2a3530;line-height:1.45'>{$datePart} {$atTime}</div>
            <div style='font-size:14px;color:#5a655e;margin-top:4px'>{$guestsTxt}</div>
        </td></tr>
    </table>";
}

/**
 * Pošlje email za potrditev naslova ob registraciji.
 */
function send_verification_email(string $email, string $fullName, string $token, string $lang = 'sl'): bool {
    $link    = APP_URL . BASE_PATH . '/verify-email.php?token=' . urlencode($token);
    $appName = APP_NAME;
    $name    = htmlspecialchars($fullName, ENT_QUOTES);
    $linkEsc = htmlspecialchars($link, ENT_QUOTES);

    $body = email_h(_email_t('email.verification.heading', $lang))
        . email_p(_email_t('email.common.greeting_name', $lang, ['name' => $name]))
        . email_p(_email_t('email.verification.intro', $lang))
        . email_button(_email_t('email.verification.button', $lang), $link)
        . email_p(_email_t('email.common.fallback_link', $lang) . "<br><a href='{$linkEsc}' style='color:#c8542b;word-break:break-all'>{$linkEsc}</a>", true)
        . email_p(_email_t('email.verification.note', $lang), true);

    $html = email_wrap($appName, $body, _email_t('email.common.footer_auto', $lang, ['appName' => $appName]));
    $text = _email_t('email.verification.text_summary', $lang, ['name' => $fullName, 'link' => $link]);
    return send_email($email, _email_t('email.verification.subject', $lang, ['appName' => $appName]), $html, $text);
}

/**
 * Pošlje email za ponastavitev gesla.
 */
function send_password_reset_email(string $email, string $fullName, string $token, string $lang = 'sl'): bool {
    $link    = APP_URL . BASE_PATH . '/reset-password.php?token=' . urlencode($token);
    $appName = APP_NAME;
    $name    = htmlspecialchars($fullName, ENT_QUOTES);
    $linkEsc = htmlspecialchars($link, ENT_QUOTES);

    $body = email_h(_email_t('email.reset.heading', $lang))
        . email_p(_email_t('email.common.greeting_name', $lang, ['name' => $name]))
        . email_p(_email_t('email.reset.intro', $lang))
        . email_button(_email_t('email.reset.button', $lang), $link)
        . email_p(_email_t('email.common.fallback_link', $lang) . "<br><a href='{$linkEsc}' style='color:#c8542b;word-break:break-all'>{$linkEsc}</a>", true)
        . email_p(_email_t('email.reset.note', $lang), true);

    $html = email_wrap($appName, $body, _email_t('email.common.footer_auto', $lang, ['appName' => $appName]));
    $text = _email_t('email.reset.text_summary', $lang, ['name' => $fullName, 'link' => $link]);
    return send_email($email, _email_t('email.reset.subject', $lang, ['appName' => $appName]), $html, $text);
}

/**
 * Pošlje email superadminu ob zahtevku za predračun (letno plačilo).
 */
function send_invoice_request_email(
    string $superadminEmail,
    string $adminName,
    string $adminEmail,
    string $planSlug,
    float  $yearlyPrice,
    string $lang = 'sl'
): bool {
    $appName  = APP_NAME;
    $planName = ucfirst($planSlug);
    $priceStr = number_format($yearlyPrice, 2, ',', '.');
    $panelUrl = APP_URL . BASE_PATH . '/pages/superadmin.php';

    $aName  = htmlspecialchars($adminName,  ENT_QUOTES);
    $aEmail = htmlspecialchars($adminEmail, ENT_QUOTES);

    $body = email_h(_email_t('email.invoice_request.heading', $lang))
        . email_p(_email_t('email.invoice_request.subtitle', $lang), true)
        . email_details_table([
            [_email_t('email.invoice_request.label_admin',  $lang), $aName],
            [_email_t('email.invoice_request.label_email',  $lang), $aEmail],
            [_email_t('email.invoice_request.label_plan',   $lang), _email_t('email.invoice_request.plan_yearly', $lang, ['plan' => $planName])],
            [_email_t('email.invoice_request.label_amount', $lang), _email_t('email.invoice_request.amount_per_year', $lang, ['amount' => $priceStr]), true],
        ])
        . email_button(_email_t('email.invoice_request.button', $lang), $panelUrl)
        . email_p(_email_t('email.invoice_request.note', $lang), true);

    $html = email_wrap($appName, $body, _email_t('email.invoice_request.footer', $lang, ['appName' => $appName]));
    $text = "Nov zahtevek za predračun\n\nAdmin: {$adminName}\nEmail: {$adminEmail}\nPaket: {$planName} (letno)\nZnesek: {$priceStr} €/leto\n\nPanel: {$panelUrl}";
    return send_email($superadminEmail, _email_t('email.invoice_request.subject', $lang, ['adminName' => $adminName, 'plan' => $planName]), $html, $text);
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
    ?int   $nextAttemptAt,
    string $lang = 'sl'
): bool {
    $appName = APP_NAME;
    $amount  = number_format($amountEur, 2, ',', '.') . ' €';
    $billingUrl = APP_URL . BASE_PATH . '/pages/billing.php';
    $name = htmlspecialchars($fullName, ENT_QUOTES);

    $nextTry = $nextAttemptAt
        ? email_p(_email_t('email.payment_failed.next_attempt', $lang, ['when' => date('j. n. Y, H:i', $nextAttemptAt)]))
        : email_p(_email_t('email.payment_failed.last_attempt', $lang));

    $body = email_h(_email_t('email.payment_failed.heading', $lang))
        . email_p(_email_t('email.payment_failed.attempt', $lang, ['n' => $attemptCount]), true)
        . email_p(_email_t('email.common.greeting_name', $lang, ['name' => $name]))
        . email_p(_email_t('email.payment_failed.intro', $lang, ['amount' => $amount, 'plan' => $planName]))
        . $nextTry
        . email_button(_email_t('email.payment_failed.button', $lang), $billingUrl)
        . email_p(_email_t('email.payment_failed.note', $lang), true);

    $html = email_wrap($appName, $body, _email_t('email.payment_failed.footer', $lang, ['appName' => $appName]));
    $text = _email_t('email.payment_failed.text_summary', $lang, ['n' => $attemptCount, 'amount' => $amount, 'plan' => $planName, 'link' => $billingUrl]);
    return send_email($email, _email_t('email.payment_failed.subject', $lang, ['appName' => $appName]), $html, $text);
}

/**
 * Pošlje opomnik adminu teden dni pred naslednjim plačilom.
 */
function send_upcoming_invoice_email(
    string $email,
    string $fullName,
    string $planName,
    float  $amountEur,
    int    $billingAt,
    string $lang = 'sl'
): bool {
    $appName     = APP_NAME;
    $amount      = number_format($amountEur, 2, ',', '.') . ' €';
    $billingDate = date('j. n. Y', $billingAt);
    $billingUrl  = APP_URL . BASE_PATH . '/pages/billing.php';
    $name = htmlspecialchars($fullName, ENT_QUOTES);

    $body = email_h(_email_t('email.upcoming_invoice.heading', $lang))
        . email_p(_email_t('email.upcoming_invoice.subtitle', $lang), true)
        . email_p(_email_t('email.common.greeting_name', $lang, ['name' => $name]))
        . email_p(_email_t('email.upcoming_invoice.intro', $lang))
        . email_details_table([
            [_email_t('email.upcoming_invoice.label_plan',   $lang), $planName],
            [_email_t('email.upcoming_invoice.label_amount', $lang), $amount, true],
            [_email_t('email.upcoming_invoice.label_date',   $lang), $billingDate],
        ])
        . email_button(_email_t('email.upcoming_invoice.button', $lang), $billingUrl)
        . email_p(_email_t('email.upcoming_invoice.note', $lang), true);

    $html = email_wrap($appName, $body, _email_t('email.upcoming_invoice.footer', $lang, ['appName' => $appName]));
    $text = _email_t('email.upcoming_invoice.text_summary', $lang, ['plan' => $planName, 'amount' => $amount, 'date' => $billingDate, 'link' => $billingUrl]);
    return send_email($email, _email_t('email.upcoming_invoice.subject', $lang, ['amount' => $amount, 'date' => $billingDate, 'appName' => $appName]), $html, $text);
}

/**
 * Pošlje link za potrditev spremembe emaila na NOV email naslov.
 */
function send_email_change_email(string $newEmail, string $fullName, string $token, string $lang = 'sl'): bool {
    $link    = APP_URL . BASE_PATH . '/pages/confirm-email-change.php?token=' . urlencode($token);
    $appName = APP_NAME;
    $name    = htmlspecialchars($fullName, ENT_QUOTES);

    $body = email_h(_email_t('email.email_change.heading', $lang))
        . email_p(_email_t('email.common.greeting_name', $lang, ['name' => $name]))
        . email_p(_email_t('email.email_change.intro', $lang))
        . email_button(_email_t('email.email_change.button', $lang), $link)
        . email_p(_email_t('email.email_change.note', $lang), true);

    $html = email_wrap($appName, $body, _email_t('email.email_change.footer', $lang, ['appName' => $appName]));
    $text = _email_t('email.email_change.text_summary', $lang, ['link' => $link]);
    return send_email($newEmail, _email_t('email.email_change.subject', $lang, ['appName' => $appName]), $html, $text);
}

// ─── Booking emaili ───────────────────────────────────────────

/**
 * Vrne HTML blok s kontaktnimi podatki restavracije (ali prazen niz).
 */
function _contact_html(string $email, string $phone, string $lang = 'sl'): string {
    if (!$email && !$phone) return '';
    $parts = [];
    if ($email) $parts[] = '<a href="mailto:' . htmlspecialchars($email) . '" style="color:#c8542b">' . htmlspecialchars($email) . '</a>';
    if ($phone) $parts[] = '<a href="tel:' . htmlspecialchars(preg_replace('/\s+/', '', $phone)) . '" style="color:#c8542b">' . htmlspecialchars($phone) . '</a>';
    return '<p style="margin:12px 0 0;font-size:13px;color:#8a948e">' . _email_t('email.booking.contact_label', $lang) . ' ' . implode(' · ', $parts) . '</p>';
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

function _calendar_links_html(string $date, string $time, int $duration, string $restName, string $guestName, string $lang = 'sl'): string {
    $googleUrl = htmlspecialchars(_calendar_google_url($date, $time, $duration, $restName, $guestName));
    $icsUrl    = htmlspecialchars(_calendar_ics_url($date, $time, $duration, $restName, $guestName));
    return "<p style='margin:0 0 14px;font-size:14.5px;line-height:1.6;color:#2a3530'>"
        . "<a href='{$googleUrl}' target='_blank' style='color:#c8542b;text-decoration:underline;font-weight:600'>" . _email_t('email.booking.calendar_link', $lang) . "</a>"
        . " &nbsp;·&nbsp; "
        . "<a href='{$icsUrl}' style='color:#c8542b;text-decoration:underline;font-weight:600'>" . _email_t('email.booking.ics_link', $lang) . "</a>"
        . "</p>";
}

/**
 * Gost – rezervacija čaka potrditev (manual approve).
 */
function send_booking_pending_guest(string $toEmail, string $guestName, string $restName, string $date, string $time, int $guests, string $editToken = '', string $contactEmail = '', string $contactPhone = '', string $lang = 'sl', string $restAddress = ''): bool {
    $appName = APP_NAME;
    $details = _booking_details_html($restName, $date, $time, $guests, $lang);
    $contact = _contact_html($contactEmail, $contactPhone, $lang);

    $editLinks = '';
    if ($editToken) {
        $editUrl = htmlspecialchars(APP_URL . BASE_PATH . '/pages/reservation_edit.php?t=' . urlencode($editToken), ENT_QUOTES);
        $editLinks = email_p("<a href='{$editUrl}' style='color:#c8542b;text-decoration:underline;font-weight:600'>" . _email_t('email.booking_pending.edit_link', $lang) . "</a>");
    }

    $name = htmlspecialchars($guestName, ENT_QUOTES);
    $body = email_h(_email_t('email.booking_pending.heading', $lang))
        . email_p(_email_t('email.common.greeting_name', $lang, ['name' => $name]))
        . email_p(_email_t('email.booking_pending.intro', $lang))
        . $details
        . $editLinks
        . email_p(_email_t('email.booking_pending.note', $lang), true)
        . $contact;
    $html = email_wrap($appName, $body, '', [
        'type'         => 'guest',
        'rest_name'    => $restName,
        'rest_address' => $restAddress,
        'rest_email'   => $contactEmail,
        'rest_phone'   => $contactPhone,
        'lang'         => $lang,
    ]);
    $text = _email_t('email.booking_pending.text_summary', $lang, [
        'name' => $guestName, 'rest' => $restName, 'date' => $date, 'time' => $time, 'guests' => $guests,
    ]);
    return send_email($toEmail, _email_t('email.booking_pending.subject', $lang, ['restName' => $restName]), $html, $text);
}

/**
 * Gost – rezervacija potrjena (auto ali manual approve).
 */
function send_booking_confirmed_guest(string $toEmail, string $guestName, string $restName, string $date, string $time, int $guests, int $duration = 60, string $editToken = '', string $contactEmail = '', string $contactPhone = '', string $lang = 'sl', string $restAddress = ''): bool {
    $appName  = APP_NAME;
    $details  = _booking_details_html($restName, $date, $time, $guests, $lang);
    $calLinks = _calendar_links_html($date, $time, $duration, $restName, $guestName, $lang);
    $contact  = _contact_html($contactEmail, $contactPhone, $lang);

    $editLinks = '';
    if ($editToken) {
        $editUrl = htmlspecialchars(APP_URL . BASE_PATH . '/pages/reservation_edit.php?t=' . urlencode($editToken), ENT_QUOTES);
        $editLinks = email_p("<a href='{$editUrl}' style='color:#c8542b;text-decoration:underline;font-weight:600'>" . _email_t('email.booking_confirmed.edit_link', $lang) . "</a>");
    }

    $name = htmlspecialchars($guestName, ENT_QUOTES);
    $body = email_h(_email_t('email.booking_confirmed.heading', $lang))
        . email_p(_email_t('email.common.greeting_name', $lang, ['name' => $name]))
        . email_p(_email_t('email.booking_confirmed.intro', $lang))
        . $details
        . $calLinks
        . $editLinks
        . email_p(_email_t('email.booking_confirmed.note', $lang), true)
        . $contact;
    $html = email_wrap($appName, $body, '', [
        'type' => 'guest', 'rest_name' => $restName, 'rest_address' => $restAddress,
        'rest_email' => $contactEmail, 'rest_phone' => $contactPhone, 'lang' => $lang,
    ]);
    $text = _email_t('email.booking_confirmed.text_summary', $lang, [
        'name' => $guestName, 'rest' => $restName, 'date' => $date, 'time' => $time, 'guests' => $guests,
    ]);
    return send_email($toEmail, _email_t('email.booking_confirmed.subject', $lang, ['restName' => $restName]), $html, $text);
}

/**
 * Gost – rezervacija zavrnjena.
 */
function send_booking_rejected_guest(string $toEmail, string $guestName, string $restName, string $date, string $time, int $guests, string $contactEmail = '', string $contactPhone = '', string $lang = 'sl', string $restAddress = ''): bool {
    $appName = APP_NAME;
    $details = _booking_details_html($restName, $date, $time, $guests, $lang);
    $contact = _contact_html($contactEmail, $contactPhone, $lang);
    $name = htmlspecialchars($guestName, ENT_QUOTES);
    $body = email_h(_email_t('email.booking_rejected.heading', $lang))
        . email_p(_email_t('email.common.greeting_name', $lang, ['name' => $name]))
        . email_p(_email_t('email.booking_rejected.intro', $lang))
        . $details
        . email_p(_email_t('email.booking_rejected.note', $lang), true)
        . $contact;
    $html = email_wrap($appName, $body, '', [
        'type' => 'guest', 'rest_name' => $restName, 'rest_address' => $restAddress,
        'rest_email' => $contactEmail, 'rest_phone' => $contactPhone, 'lang' => $lang,
    ]);
    $text = _email_t('email.booking_rejected.text_summary', $lang, [
        'name' => $guestName, 'rest' => $restName, 'date' => $date, 'time' => $time,
    ]);
    return send_email($toEmail, _email_t('email.booking_rejected.subject', $lang, ['restName' => $restName]), $html, $text);
}

/**
 * Gost – opomnik 24h pred rezervacijo.
 */
function send_booking_reminder_guest(string $toEmail, string $guestName, string $restName, string $date, string $time, int $guests, int $duration = 60, string $contactEmail = '', string $contactPhone = '', string $lang = 'sl', string $restAddress = ''): bool {
    $appName  = APP_NAME;
    $details  = _booking_details_html($restName, $date, $time, $guests, $lang);
    $calLinks = _calendar_links_html($date, $time, $duration, $restName, $guestName, $lang);
    $contact  = _contact_html($contactEmail, $contactPhone, $lang);
    $name = htmlspecialchars($guestName, ENT_QUOTES);
    $body = email_h(_email_t('email.booking_reminder.heading', $lang))
        . email_p(_email_t('email.common.greeting_name', $lang, ['name' => $name]))
        . email_p(_email_t('email.booking_reminder.intro', $lang))
        . $details
        . $calLinks
        . email_p(_email_t('email.booking_reminder.note', $lang), true)
        . $contact;
    $html = email_wrap($appName, $body, '', [
        'type' => 'guest', 'rest_name' => $restName, 'rest_address' => $restAddress,
        'rest_email' => $contactEmail, 'rest_phone' => $contactPhone, 'lang' => $lang,
    ]);
    $text = _email_t('email.booking_reminder.text_summary', $lang, [
        'rest' => $restName, 'date' => $date, 'time' => $time, 'guests' => $guests,
    ]);
    return send_email($toEmail, _email_t('email.booking_reminder.subject', $lang, ['restName' => $restName]), $html, $text);
}

/**
 * Admin – nova rezervacija prispela.
 */
function send_booking_notify_admin(string $toEmail, string $adminName, string $restName, string $guestName, string $guestEmail, string $date, string $time, int $guests, string $status, int $reservationId = 0, string $lang = 'sl'): bool {
    $appName   = APP_NAME;
    $details   = _booking_details_html($restName, $date, $time, $guests, $lang);
    $statusTxt = $status === 'confirmed'
        ? _email_t('email.notify_admin.status_confirmed', $lang)
        : _email_t('email.notify_admin.status_pending',   $lang);
    $appLink   = APP_URL . BASE_PATH . '/pages/main.php';

    // Gumba Potrdi/Zavrni – samo za pending rezervacije
    $actionButtons = '';
    if ($status === 'pending' && $reservationId > 0) {
        $sigApprove = hash_hmac('sha256', "{$reservationId}|approve", DB_PASS . 'admin-action-v1');
        $sigReject  = hash_hmac('sha256', "{$reservationId}|reject",  DB_PASS . 'admin-action-v1');
        $urlApprove = htmlspecialchars(APP_URL . BASE_PATH . '/api/reservation_action.php?id=' . $reservationId . '&action=approve&sig=' . $sigApprove, ENT_QUOTES);
        $urlReject  = htmlspecialchars(APP_URL . BASE_PATH . '/api/reservation_action.php?id=' . $reservationId . '&action=reject&sig='  . $sigReject, ENT_QUOTES);
        $approveLabel = _email_t('email.notify_admin.btn_approve', $lang);
        $rejectLabel  = _email_t('email.notify_admin.btn_reject',  $lang);
        $actionButtons = "
        <table role='presentation' cellpadding='0' cellspacing='0' border='0' style='margin:8px 0 14px'>
            <tr>
                <td style='padding-right:10px'>
                    <!--[if mso]>
                    <v:roundrect xmlns:v='urn:schemas-microsoft-com:vml' xmlns:w='urn:schemas-microsoft-com:office:word'
                        href='{$urlApprove}' style='height:48px;v-text-anchor:middle;width:220px;' arcsize='17%' stroke='f' fillcolor='#1B4332'>
                        <w:anchorlock/><center style='color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:700'>{$approveLabel}</center>
                    </v:roundrect>
                    <![endif]-->
                    <!--[if !mso]><!-- -->
                    <a href='{$urlApprove}' target='_blank' style='background:#1B4332;border-radius:8px;color:#ffffff;display:inline-block;font-family:-apple-system,Arial,sans-serif;font-size:15px;font-weight:600;line-height:48px;height:48px;min-width:200px;padding:0 28px;text-align:center;text-decoration:none'>{$approveLabel}</a>
                    <!--<![endif]-->
                </td>
                <td>
                    <a href='{$urlReject}' target='_blank' style='border:1.5px solid #b34822;border-radius:8px;color:#b34822;display:inline-block;font-family:-apple-system,Arial,sans-serif;font-size:15px;font-weight:600;line-height:45px;height:48px;min-width:160px;padding:0 24px;text-align:center;text-decoration:none;box-sizing:border-box'>{$rejectLabel}</a>
                </td>
            </tr>
        </table>";
    }

    $name = htmlspecialchars($guestName, ENT_QUOTES);
    $email = htmlspecialchars($guestEmail, ENT_QUOTES);
    $statusColor = $status === 'confirmed' ? '#065F46' : '#b34822';
    $body = email_h(_email_t('email.notify_admin.heading', $lang))
        . email_p(_email_t('email.notify_admin.guest_line', $lang, ['name' => $name, 'email' => $email]))
        . "<p style='margin:0 0 18px;font-size:13.5px;color:{$statusColor};font-weight:700;text-transform:uppercase;letter-spacing:0.05em'>{$statusTxt}</p>"
        . $details
        . $actionButtons
        . email_link(_email_t('email.notify_admin.open_schedule', $lang), $appLink);
    $html = email_wrap($appName, $body, _email_t('email.notify_admin.footer', $lang, ['appName' => $appName]));
    $text = _email_t('email.notify_admin.text_summary', $lang, [
        'rest' => $restName, 'name' => $guestName, 'email' => $guestEmail,
        'date' => $date, 'time' => $time, 'guests' => $guests, 'status' => $statusTxt,
    ]);
    return send_email($toEmail, _email_t('email.notify_admin.subject', $lang, ['restName' => $restName]), $html, $text);
}

/**
 * Potrditveni email za GDPR zahtevek
 */
function send_gdpr_confirmation(string $toEmail, string $requestType, string $lang = 'sl'): bool {
    $appName  = defined('APP_NAME') ? APP_NAME : 'Rezervacije';
    $appLink  = (defined('APP_URL') ? APP_URL : '') . (defined('BASE_PATH') ? BASE_PATH : '');
    static $typeKeyMap = [
        'data_export'     => 'email.gdpr.type_export',
        'export'          => 'email.gdpr.type_export',
        'data_deletion'   => 'email.gdpr.type_deletion',
        'deletion'        => 'email.gdpr.type_deletion',
        'delete'          => 'email.gdpr.type_deletion',
        'data_correction' => 'email.gdpr.type_correction',
        'correction'      => 'email.gdpr.type_correction',
        'access'          => 'email.gdpr.type_access',
    ];
    $typeLabel = isset($typeKeyMap[$requestType])
        ? _email_t($typeKeyMap[$requestType], $lang)
        : _email_t('email.gdpr.type_other', $lang, ['type' => htmlspecialchars($requestType, ENT_QUOTES, 'UTF-8')]);

    $body = email_h(_email_t('email.gdpr.heading', $lang))
        . email_p(_email_t('email.gdpr.intro',    $lang, ['type' => $typeLabel]))
        . email_p(_email_t('email.gdpr.timeline', $lang))
        . email_p(_email_t('email.gdpr.contact',  $lang))
        . email_button(_email_t('email.gdpr.button', $lang), $appLink . '/pages/privacy.php');
    $html = email_wrap($appName, $body, _email_t('email.gdpr.footer', $lang));
    $text = _email_t('email.gdpr.text_summary', $lang, ['type' => $typeLabel]);
    return send_email($toEmail, _email_t('email.gdpr.subject', $lang, ['appName' => $appName]), $html, $text);
}

// ─── Affiliate emaili ─────────────────────────────────────────────

function send_affiliate_verify_email(string $toEmail, string $name, string $token, string $lang = 'sl'): bool {
    $appName = APP_NAME;
    $link    = APP_URL . BASE_PATH . '/affiliate/verify-email.php?token=' . urlencode($token);
    $nameEsc = htmlspecialchars($name, ENT_QUOTES);
    $body = email_h(_email_t('email.affiliate_verify.heading', $lang))
        . email_p(_email_t('email.common.greeting_name', $lang, ['name' => $nameEsc]))
        . email_p(_email_t('email.affiliate_verify.intro', $lang))
        . email_button(_email_t('email.affiliate_verify.button', $lang), $link)
        . email_p(_email_t('email.affiliate_verify.note', $lang), true);
    $html = email_wrap($appName, $body, _email_t('email.affiliate_verify.footer', $lang));
    return send_email($toEmail, _email_t('email.affiliate_verify.subject', $lang), $html);
}

function send_affiliate_approved_email(string $toEmail, string $name, string $refCode, string $lang = 'sl'): bool {
    $appName  = APP_NAME;
    $dashLink = APP_URL . BASE_PATH . '/affiliate/dashboard.php';
    $refLink  = APP_URL . BASE_PATH . '/?ref=' . urlencode($refCode);
    $nameEsc  = htmlspecialchars($name, ENT_QUOTES);
    $codeEsc  = htmlspecialchars($refCode, ENT_QUOTES);
    $linkEsc  = htmlspecialchars($refLink, ENT_QUOTES);

    $body = email_h(_email_t('email.affiliate_approved.heading', $lang))
        . email_p(_email_t('email.common.greeting_name', $lang, ['name' => $nameEsc]))
        . email_p(_email_t('email.affiliate_approved.intro', $lang))
        . "<div style='background:#fae8df;border:1px solid #f4d4c4;border-radius:10px;padding:22px 28px;margin:0 0 22px;font-size:24px;font-weight:700;text-align:center;letter-spacing:.12em;color:#b34822;font-family:Georgia,\"Times New Roman\",serif'>{$codeEsc}</div>"
        . email_p(_email_t('email.affiliate_approved.ref_link_label', $lang))
        . email_p("<a href='{$linkEsc}' style='color:#c8542b;word-break:break-all;text-decoration:underline'>{$linkEsc}</a>", true)
        . email_button(_email_t('email.affiliate_approved.button', $lang), $dashLink);
    $html = email_wrap($appName, $body, _email_t('email.affiliate_approved.footer', $lang));
    return send_email($toEmail, _email_t('email.affiliate_approved.subject', $lang), $html);
}

function send_affiliate_rejected_email(string $toEmail, string $name, string $reason, string $lang = 'sl'): bool {
    $appName = APP_NAME;
    $nameEsc = htmlspecialchars($name, ENT_QUOTES);
    $reasonRow = $reason
        ? email_p(_email_t('email.affiliate_rejected.reason_label', $lang) . ' ' . htmlspecialchars($reason, ENT_QUOTES))
        : '';
    $body = email_h(_email_t('email.affiliate_rejected.heading', $lang))
        . email_p(_email_t('email.common.greeting_dot', $lang, ['name' => $nameEsc]))
        . email_p(_email_t('email.affiliate_rejected.intro', $lang))
        . $reasonRow
        . email_p(_email_t('email.affiliate_rejected.contact', $lang));
    $html = email_wrap($appName, $body, _email_t('email.affiliate_rejected.footer', $lang));
    return send_email($toEmail, _email_t('email.affiliate_rejected.subject', $lang), $html);
}

function send_affiliate_payout_email(string $toEmail, string $name, float $amountEur, string $reference, string $lang = 'sl'): bool {
    $appName = APP_NAME;
    $nameEsc = htmlspecialchars($name, ENT_QUOTES);
    $amount  = number_format($amountEur, 2, ',', '.') . ' €';

    $body = email_h(_email_t('email.affiliate_payout.heading', $lang))
        . email_p(_email_t('email.common.greeting_name', $lang, ['name' => $nameEsc]))
        . email_p(_email_t('email.affiliate_payout.intro', $lang))
        . email_details_table([
            [_email_t('email.affiliate_payout.label_ref',    $lang), htmlspecialchars($reference, ENT_QUOTES)],
            [_email_t('email.affiliate_payout.label_amount', $lang), $amount, true],
        ])
        . email_p(_email_t('email.affiliate_payout.note', $lang), true);
    $html = email_wrap($appName, $body, _email_t('email.affiliate_payout.footer', $lang));
    return send_email($toEmail, _email_t('email.affiliate_payout.subject', $lang, ['ref' => $reference]), $html);
}

/**
 * Potrditveni email ob spremembi/aktivaciji paketa.
 */
function send_plan_changed_email(
    string $to,
    string $name,
    string $planName,
    string $billingCycle,
    float  $price,
    string $lang = 'sl'
): bool {
    $appName     = APP_NAME;
    $billingUrl  = APP_URL . BASE_PATH . '/pages/billing.php';
    $cycleLabel  = $billingCycle === 'yearly'
        ? _email_t('email.plan_changed.cycle_yearly',  $lang)
        : _email_t('email.plan_changed.cycle_monthly', $lang);
    if ($price > 0) {
        $amountStr = number_format($price, 2, ',', '.');
        $priceStr  = $billingCycle === 'yearly'
            ? _email_t('email.plan_changed.price_per_year',  $lang, ['amount' => $amountStr])
            : _email_t('email.plan_changed.price_per_month', $lang, ['amount' => $amountStr]);
    } else {
        $priceStr = _email_t('email.plan_changed.price_free', $lang);
    }
    $escapedName = htmlspecialchars($name, ENT_QUOTES);

    $rows = [
        [_email_t('email.plan_changed.label_plan',    $lang), $planName],
        [_email_t('email.plan_changed.label_billing', $lang), $cycleLabel],
    ];
    if ($price > 0) $rows[] = [_email_t('email.plan_changed.label_price', $lang), $priceStr, true];

    $body = email_h(_email_t('email.plan_changed.heading', $lang))
        . email_p(_email_t('email.plan_changed.subtitle', $lang), true)
        . email_p(_email_t('email.common.greeting_name', $lang, ['name' => $escapedName]))
        . email_details_table($rows)
        . email_p(_email_t('email.plan_changed.invoice_note', $lang))
        . email_button(_email_t('email.plan_changed.button', $lang), $billingUrl)
        . email_p(_email_t('email.plan_changed.note', $lang), true);

    $html = email_wrap($appName, $body, _email_t('email.plan_changed.footer', $lang, ['appName' => $appName]));
    $text = "Naročnina posodobljena\n\nPaket: {$planName}\nPlačilo: {$cycleLabel}"
          . ($price > 0 ? "\nCena: {$priceStr}" : '')
          . "\n\nRačun si oglejte:\n{$billingUrl}";
    return send_email($to, _email_t('email.plan_changed.subject', $lang, ['appName' => $appName]), $html, $text);
}

function send_affiliate_discount_granted_email(string $toEmail, string $name, string $code, float $percent, string $lang = 'sl'): bool {
    $appName  = APP_NAME;
    $dashLink = APP_URL . BASE_PATH . '/affiliate/dashboard.php';
    $nameEsc  = htmlspecialchars($name, ENT_QUOTES);
    $codeEsc  = htmlspecialchars($code, ENT_QUOTES);
    $body = email_h(_email_t('email.affiliate_discount.heading', $lang))
        . email_p(_email_t('email.common.greeting_name', $lang, ['name' => $nameEsc]))
        . email_p(_email_t('email.affiliate_discount.intro', $lang, ['percent' => (int)$percent]))
        . "<div style='background:#fae8df;border:1px solid #f4d4c4;border-radius:10px;padding:22px 28px;margin:0 0 22px;font-size:24px;font-weight:700;text-align:center;letter-spacing:.12em;color:#b34822;font-family:Georgia,\"Times New Roman\",serif'>{$codeEsc}</div>"
        . email_p(_email_t('email.affiliate_discount.note', $lang), true)
        . email_button(_email_t('email.affiliate_discount.button', $lang), $dashLink);
    $html = email_wrap($appName, $body, _email_t('email.affiliate_discount.footer', $lang));
    return send_email($toEmail, _email_t('email.affiliate_discount.subject', $lang, ['code' => $code]), $html);
}
