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
    $__rz_app_lang = $code;
}

/**
 * Vrne preveden in HTML-escapedan niz.
 * Za nize z HTML vsebino uporabi t_raw().
 *
 * @param array<string,scalar> $params  Zamenjave, npr. ['count' => 3] → '{count}' → '3'
 */
function t(string $key, array $params = []): string {
    global $__rz_lang_strings;
    $str = $__rz_lang_strings[$key] ?? $key;
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
    global $__rz_lang_strings;
    $str = $__rz_lang_strings[$key] ?? $key;
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
 * Detektira preferiran jezik uporabnika iz HTTP Accept-Language headerja.
 * Format vhoda: "it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7"
 * Vrne enega od podprtih jezikov ali 'en' (mednarodni default), nikoli 'sl'
 * — slovenščina je default samo za uporabnike z znanim sl Accept-Language.
 *
 * Opcijsko (zakomentirano): IP geolokacija prek MaxMind/Cloudflare CF-IPCountry
 * headerja. Browser language je zanesljivejša, ker spoštuje user preferenco
 * (turist iz Italije v ZDA hoče italijansko).
 */
function detect_user_lang(): string {
    static $allowed = ['sl', 'en', 'de', 'it', 'fr', 'hr', 'es', 'pt'];
    $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if ($accept === '') return 'en';

    $tags = [];
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
    if (empty($tags)) return 'en';
    arsort($tags);
    return array_key_first($tags);
}

// ── Auto-init ──────────────────────────────────────────────────────────────────
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
