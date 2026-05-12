<?php
/**
 * Lokalizacijski helper.
 *
 * Naloži lang/{koda}.json in ponudi t() za prevedene nize.
 * Jezik se določi iz: GET ?lang= → cookie rzlang → session lang → 'sl'.
 *
 * Uporaba v PHP:  <?= t('nav.today') ?>
 * Uporaba v JS:   t('nav.today')  (window.__T__ injektiran prek html_head.php)
 */

if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}

global $__rz_lang_strings, $__rz_app_lang;

function _rz_load_lang(string $code): void {
    global $__rz_lang_strings, $__rz_app_lang;
    $code = preg_replace('/[^a-z]/', '', strtolower($code));
    if (!in_array($code, ['sl', 'en', 'de', 'it', 'fr', 'hr', 'es', 'pt'], true)) {
        $code = 'sl';
    }
    $file = __DIR__ . '/../lang/' . $code . '.json';
    if (!file_exists($file)) {
        $file = __DIR__ . '/../lang/sl.json';
        $code = 'sl';
    }
    $json = @file_get_contents($file);
    $__rz_lang_strings = ($json !== false) ? (json_decode($json, true) ?: []) : [];
    // Fallback: če aktivni jezik ni SL, naloži tudi sl.json za manjkajoče ključe.
    // To prepreči, da bi neprevedeni ključi (npr. "billing.period_month_short") padli skozi
    // kot raw key string v UI.
    if ($code !== 'sl') {
        global $__rz_lang_fallback;
        $slJson = @file_get_contents(__DIR__ . '/../lang/sl.json');
        $__rz_lang_fallback = ($slJson !== false) ? (json_decode($slJson, true) ?: []) : [];
    } else {
        global $__rz_lang_fallback;
        $__rz_lang_fallback = [];
    }
    $__rz_app_lang = $code;
}

/**
 * Vrne preveden in HTML-escapedan niz.
 * Za nize z HTML vsebino uporabi t_raw().
 *
 * @param array<string,scalar> $params  Zamenjave, npr. ['count' => 3] → '{count}' → '3'
 */
function t(string $key, array $params = []): string {
    global $__rz_lang_strings, $__rz_lang_fallback;
    $str = $__rz_lang_strings[$key] ?? ($__rz_lang_fallback[$key] ?? $key);
    foreach ($params as $k => $v) {
        $str = str_replace('{' . $k . '}', (string)$v, $str);
    }
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Enako kot t(), ampak brez HTML escapanja.
 * Uporabi kadar je vrednost zaupanja vredna (konstanta iz prevoda, ne uporabniški vnos).
 */
function t_raw(string $key, array $params = []): string {
    global $__rz_lang_strings, $__rz_lang_fallback;
    $str = $__rz_lang_strings[$key] ?? ($__rz_lang_fallback[$key] ?? $key);
    foreach ($params as $k => $v) {
        $str = str_replace('{' . $k . '}', (string)$v, $str);
    }
    return $str;
}

/** Vrne trenutno kodo jezika ('sl', 'en', …). */
function get_lang(): string {
    global $__rz_app_lang;
    return $__rz_app_lang ?? 'sl';
}

/** Vrne celoten array prevodov (za JSON inject v JS). */
function get_lang_strings(): array {
    global $__rz_lang_strings;
    return $__rz_lang_strings ?? [];
}

/** Vrne JS array mesečnih imen za trenutni jezik. */
function lang_months_js(): string {
    global $__rz_lang_strings;
    $out = [];
    for ($i = 0; $i <= 11; $i++) {
        $out[] = $__rz_lang_strings['months.' . $i] ?? '';
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

/** Vrne JS array imen dni za trenutni jezik. */
function lang_days_js(): string {
    global $__rz_lang_strings;
    $out = [];
    for ($i = 0; $i <= 6; $i++) {
        $out[] = $__rz_lang_strings['days.' . $i] ?? '';
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

/**
 * Detektira ISO 3166-1 kodo države iz IP-ja. Vrne npr. "IT", "DE", "US" ali ''.
 * Strategija:
 *  1. Cloudflare `CF-IPCountry` (free če je domena za CF)
 *  2. Drugi reverse-proxy headerji (X-Country-Code, ipd.)
 *  3. Fallback: ip-api.com (45 req/min brezplačno) — cached v $_SESSION
 */
function detect_user_country(): string {
    // 1. CDN / proxy headerji
    foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEOIP_COUNTRY'] as $h) {
        if (!empty($_SERVER[$h]) && preg_match('/^[A-Z]{2}$/', $_SERVER[$h])) {
            return $_SERVER[$h];
        }
    }
    // 2. Public API fallback (cache v session, da ne kličemo na vsak pageload)
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!$ip || preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|::1$|fe80:)/', $ip)) {
        return ''; // localhost / private — preskoči
    }
    $cacheKey = '_geoip_' . $ip;
    if (isset($_SESSION[$cacheKey]) && $_SESSION[$cacheKey]['exp'] > time()) {
        return $_SESSION[$cacheKey]['cc'] ?? '';
    }

    // Poskusi prek cURL (zanesljivejši kot file_get_contents na Synology kjer
    // allow_url_fopen je včasih onemogočen). Najprej HTTPS ipwho.is, fallback na ip-api.com.
    $cc = '';
    $endpoints = [
        'https://ipwho.is/' . urlencode($ip) . '?fields=country_code',
        'http://ip-api.com/json/'  . urlencode($ip) . '?fields=countryCode',
    ];
    foreach ($endpoints as $url) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false, // Synology pogosto nima cacert
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
            $resp = @file_get_contents($url, false, $ctx);
            $err  = '';
        }
        if (!$resp) {
            error_log('detect_user_country: ' . $url . ' failed: ' . ($err ?: 'no response'));
            continue;
        }
        $j = json_decode($resp, true);
        $code = $j['country_code'] ?? $j['countryCode'] ?? '';
        if ($code && preg_match('/^[A-Z]{2}$/', $code)) {
            $cc = $code;
            break;
        }
    }
    $_SESSION[$cacheKey] = ['cc' => $cc, 'exp' => time() + 86400]; // 24h cache
    return $cc;
}

/**
 * Mapira ISO 3166-1 kodo države v naš lang code (8 podprtih).
 */
function country_to_supported_lang(string $cc): string {
    static $map = [
        'SI' => 'sl',
        'IT' => 'it', 'SM' => 'it', 'VA' => 'it', 'CH' => 'it', // CH ima it/de/fr; default it ker po populaciji prevladuje de — uporabljeno samo če Accept-Lang ne pomaga
        'DE' => 'de', 'AT' => 'de', 'LI' => 'de',
        'FR' => 'fr', 'BE' => 'fr', 'LU' => 'fr', 'MC' => 'fr',
        'HR' => 'hr', 'BA' => 'hr', 'RS' => 'hr', 'ME' => 'hr', 'MK' => 'hr',
        'ES' => 'es', 'MX' => 'es', 'AR' => 'es', 'CL' => 'es', 'CO' => 'es', 'PE' => 'es',
        'VE' => 'es', 'UY' => 'es', 'PY' => 'es', 'BO' => 'es', 'EC' => 'es', 'GT' => 'es',
        'CR' => 'es', 'PA' => 'es', 'DO' => 'es', 'CU' => 'es', 'NI' => 'es', 'HN' => 'es',
        'SV' => 'es',
        'PT' => 'pt', 'BR' => 'pt', 'AO' => 'pt', 'MZ' => 'pt', 'CV' => 'pt',
        // GB/US/IE/AU/CA/NZ/IN ostane 'en' (default fallback)
    ];
    return $map[$cc] ?? '';
}

/**
 * Detektira preferiran jezik uporabnika.
 *
 * Hibridna logika:
 *  1. Accept-Language header — če eksplicitno omenja kateri od naših 8 jezikov,
 *     ga uporabi (spoštuje user preferenco).
 *  2. Sicer pogledaj IP državo (Italijan na potovanju z EN browserjem dobi italijansko).
 *  3. Fallback: 'en' za mednarodne goste, 'sl' samo za prazne signale.
 */
function detect_user_lang(): string {
    static $allowed = ['sl', 'en', 'de', 'it', 'fr', 'hr', 'es', 'pt'];
    $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

    $tags = [];
    if ($accept !== '') {
        foreach (explode(',', $accept) as $entry) {
            $parts = explode(';', trim($entry));
            $code  = strtolower(trim($parts[0]));
            $q     = 1.0;
            foreach (array_slice($parts, 1) as $p) {
                if (preg_match('/q\s*=\s*([0-9.]+)/', $p, $m)) $q = (float)$m[1];
            }
            $primary = explode('-', $code)[0]; // it-IT → it
            if (in_array($primary, $allowed, true)) {
                if (!isset($tags[$primary]) || $tags[$primary] < $q) $tags[$primary] = $q;
            }
        }
    }

    // Kadar browser TOPSTI lang ni 'en', mu zaupaj — user je verjetno explicitno nastavil
    if (!empty($tags)) {
        arsort($tags);
        $top = array_key_first($tags);
        if ($top !== 'en') return $top;
    }

    // Browser je English (privzeto za večino brskalnikov) ALI ne pomaga —
    // uporabi IP geo da ujamemo turiste in dejansko lokacijo.
    $cc = detect_user_country();
    if ($cc !== '') {
        $ipLang = country_to_supported_lang($cc);
        if ($ipLang !== '') return $ipLang;
        // Anglofone države (GB/US/AU/...) eksplicitno → en
        if (in_array($cc, ['GB','US','IE','AU','NZ','CA','IN','ZA','SG','PH','MT'], true)) return 'en';
    }

    // Vrni browser top če sploh kaj je, sicer en
    return !empty($tags) ? array_key_first($tags) : 'en';
}

// ── Auto-init ──────────────────────────────────────────────────────────────────
// Debug / testing: ?reset_lang=1 počisti rzlang cookie in session, da forsira
// ponovno detekcijo (uporabno po prepogibu VPN-a ali jezikovnih nastavitev brskalnika).
if (!empty($_GET['reset_lang'])) {
    setcookie('rzlang', '', time() - 3600, '/');
    unset($_COOKIE['rzlang']);
    if (session_status() === PHP_SESSION_ACTIVE) unset($_SESSION['lang']);
    if (session_status() === PHP_SESSION_ACTIVE) unset($_SESSION['_geoip_' . ($_SERVER['REMOTE_ADDR'] ?? '')]);
}

// Prioriteta: GET ?lang= (eksplicitni switcher) > cookie > session > Accept-Language > 'sl' fallback
$_rz_lang_init = null;
if (!empty($_GET['lang']) && in_array($_GET['lang'], ['sl', 'en', 'de', 'it', 'fr', 'hr', 'es', 'pt'], true)) {
    $_rz_lang_init = $_GET['lang'];
    setcookie('rzlang', $_rz_lang_init, time() + 365 * 24 * 3600, '/');
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['lang'] = $_rz_lang_init;
    }
} elseif (!empty($_COOKIE['rzlang']) && in_array($_COOKIE['rzlang'], ['sl', 'en', 'de', 'it', 'fr', 'hr', 'es', 'pt'], true)) {
    $_rz_lang_init = (string)$_COOKIE['rzlang'];
} elseif (!empty($_SESSION['lang']) && in_array($_SESSION['lang'], ['sl', 'en', 'de', 'it', 'fr', 'hr', 'es', 'pt'], true)) {
    $_rz_lang_init = (string)$_SESSION['lang'];
} else {
    // Auto-detect iz brskalnika; persist v cookie da se na naslednjem requestu
    // ne ponovi detekcija (in da je konsistentno s switcherjem).
    $_rz_lang_init = detect_user_lang();
    if ($_rz_lang_init !== 'sl' || !empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        setcookie('rzlang', $_rz_lang_init, time() + 365 * 24 * 3600, '/');
    }
}
_rz_load_lang($_rz_lang_init);
unset($_rz_lang_init);
