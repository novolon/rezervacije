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

// ── Auto-init ──────────────────────────────────────────────────────────────────
// Prioriteta: GET ?lang= (za switcher) > cookie > session > privzeto 'sl'
$_rz_lang_init = 'sl';
if (!empty($_GET['lang']) && in_array($_GET['lang'], ['sl', 'en', 'de', 'it', 'fr', 'hr', 'es', 'pt'], true)) {
    $_rz_lang_init = $_GET['lang'];
    setcookie('rzlang', $_rz_lang_init, time() + 365 * 24 * 3600, '/');
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['lang'] = $_rz_lang_init;
    }
} elseif (!empty($_COOKIE['rzlang'])) {
    $_rz_lang_init = (string)$_COOKIE['rzlang'];
} elseif (!empty($_SESSION['lang'])) {
    $_rz_lang_init = (string)$_SESSION['lang'];
}
_rz_load_lang($_rz_lang_init);
unset($_rz_lang_init);
