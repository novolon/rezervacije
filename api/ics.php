<?php
/**
 * Generira .ics datoteko za rezervacijo.
 * Parametri: d (datum YYYY-MM-DD), t (čas HH:MM), dur (trajanje v min), r (restavracija), n (ime gosta), sig (HMAC)
 */
require_once __DIR__ . '/../config.php';

$date = trim($_GET['d'] ?? '');
$time = trim($_GET['t'] ?? '');
$dur  = (int)($_GET['dur'] ?? 60);
$rest = trim($_GET['r'] ?? '');
$name = trim($_GET['n'] ?? '');
$sig  = trim($_GET['sig'] ?? '');

// Preveri podpis
$key      = hash('sha256', DB_PASS . 'ics-v1');
$expected = substr(hash_hmac('sha256', "{$date}|{$time}|{$dur}|{$rest}|{$name}", $key), 0, 16);
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    exit('Neveljaven podpis.');
}

// Preveri format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
    http_response_code(400);
    exit('Napačni parametri.');
}
if ($dur < 1 || $dur > 1440) {
    $dur = 60;
}

// ICS escaping (RFC 5545)
function ics_escape(string $str): string {
    return str_replace(['\\', ',', ';', "\n"], ['\\\\', '\\,', '\\;', '\\n'], $str);
}

// Časi – pretvorba iz Ljubljana v UTC
$startTs = strtotime("{$date} {$time}");
$endTs   = $startTs + $dur * 60;
$dtStart = gmdate('Ymd\THis\Z', $startTs);
$dtEnd   = gmdate('Ymd\THis\Z', $endTs);
$dtStamp = gmdate('Ymd\THis\Z');

$uid     = md5("{$date}|{$time}|{$rest}") . '@rezervacije';
$summary = ics_escape("Rezervacija – {$rest}");
$desc    = ics_escape("Rezervacija za {$name}");

$ics = implode("\r\n", [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Rezervacije//SI',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    "UID:{$uid}",
    "DTSTAMP:{$dtStamp}",
    "DTSTART:{$dtStart}",
    "DTEND:{$dtEnd}",
    "SUMMARY:{$summary}",
    "DESCRIPTION:{$desc}",
    'STATUS:CONFIRMED',
    'END:VEVENT',
    'END:VCALENDAR',
]) . "\r\n";

$filename = 'rezervacija-' . $date . '.ics';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo $ics;
