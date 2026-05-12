<?php
/**
 * Server-side analytics helper za PostHog + interno DB beleženje ključnih dogodkov.
 *
 * Klici na PostHog gredo prek `/i/v0/e/` HTTP API-ja in so non-blocking
 * (nizek timeout, fail-silent — analitika ne sme nikoli zlomiti business logike).
 *
 * Uporaba:
 *   analytics_capture('reservation_created', $userId, [
 *       'restaurant_id' => 42,
 *       'guest_count'   => 4,
 *       'auto_confirmed'=> true,
 *   ]);
 */

if (!function_exists('analytics_capture')) {

/**
 * Pošlje en dogodek v PostHog. Distinct ID je user_id (admin/staff) ali
 * "anon-{cookie/ip-hash}" če uporabnik ni prijavljen.
 *
 * @param string      $event      ime dogodka, npr. "reservation_created"
 * @param int|string  $distinctId user_id ali anonimni hash
 * @param array       $properties dodatne lastnosti dogodka
 */
function analytics_capture(string $event, $distinctId, array $properties = []): void {
    if (!defined('POSTHOG_KEY') || POSTHOG_KEY === '') return;
    if ($distinctId === null || $distinctId === '') {
        $distinctId = analytics_anon_id();
    }
    $host = defined('POSTHOG_HOST') && POSTHOG_HOST !== '' ? POSTHOG_HOST : 'https://eu.i.posthog.com';

    $payload = [
        'api_key'     => POSTHOG_KEY,
        'event'       => $event,
        'distinct_id' => (string)$distinctId,
        'properties'  => array_merge([
            '$lib'         => 'rezble-php',
            '$lib_version' => '1.0',
            'app_context'  => 'server',
        ], $properties),
        'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    $ch = curl_init($host . '/i/v0/e/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['content-type: application/json'],
        CURLOPT_TIMEOUT_MS     => 1500,
        CURLOPT_CONNECTTIMEOUT_MS => 800,
        CURLOPT_NOSIGNAL       => 1,
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}

/** Anonimni distinct ID (stable across requests v isti seji).
 *  Cookie se postavi samo, če je uporabnik privolil v 'analytics' kategorijo.
 *  Brez privolitve vrnemo per-request anon ID (event ne bo deduplciran med requesti). */
function analytics_anon_id(): string {
    $cookieKey = '_ph_anon';
    if (!empty($_COOKIE[$cookieKey])) return (string)$_COOKIE[$cookieKey];
    $id = 'anon-' . bin2hex(random_bytes(8));

    require_once __DIR__ . '/cookie_consent.php';
    if (rez_consent_allows('analytics')) {
        setcookie($cookieKey, $id, time() + 365 * 86400, '/', '', !empty($_SERVER['HTTPS']), true);
    }
    return $id;
}

/**
 * Posodobi person properties (npr. ob spremembi plan-a).
 * Uporablja "$set" PostHog mehanizem.
 */
function analytics_set_person(int $userId, array $personProps): void {
    if (!defined('POSTHOG_KEY') || POSTHOG_KEY === '') return;
    analytics_capture('$identify', $userId, ['$set' => $personProps]);
}

}
