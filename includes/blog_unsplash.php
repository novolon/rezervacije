<?php
/**
 * blog_unsplash.php – Unsplash API wrapper.
 *
 * - blog_unsplash_search($query, $perPage, $orientation)
 *      → vrne array iskalnih zadetkov za picker.
 * - blog_unsplash_download_and_save($photoId, $altInLang, $captionInLang, $masterLang, $userId)
 *      → prenese izbrano sliko, kliče trackDownload (Unsplash ToS!) in shrani prek
 *        blog_image_save_from_path. Vrne metadata + appendta photographer credit
 *        v caption.
 *
 * Unsplash zahteva:
 *   1. trackDownload klic ob vsakem prenosu (rate na "official downloads")
 *   2. attribution: "Photo by {Name} on Unsplash" + linki na avtorja in unsplash.com
 *
 * Authentication: Authorization: Client-ID {UNSPLASH_ACCESS_KEY}
 */

require_once __DIR__ . '/blog_image_saver.php';

if (!defined('BLOG_UNSPLASH_API'))   define('BLOG_UNSPLASH_API',   'https://api.unsplash.com');

/**
 * Iskanje fotografij. Vrne minimalen array za UI picker.
 */
function blog_unsplash_search($query, $perPage = 6, $orientation = 'landscape') {
    if (!defined('UNSPLASH_ACCESS_KEY') || !UNSPLASH_ACCESS_KEY) {
        throw new RuntimeException('UNSPLASH_ACCESS_KEY ni nastavljen v config.php');
    }
    $perPage = max(1, min(20, (int)$perPage));
    $query   = trim((string)$query);
    if ($query === '') throw new RuntimeException('Iskalna poizvedba je prazna.');

    $url = BLOG_UNSPLASH_API . '/search/photos?'
         . http_build_query([
             'query'       => $query,
             'per_page'    => $perPage,
             'orientation' => in_array($orientation, ['landscape','portrait','squarish'], true) ? $orientation : 'landscape',
             'content_filter' => 'high', // izloči PG-13+
         ]);

    $resp = _bk_unsplash_get($url);
    $json = json_decode($resp, true);
    if (!is_array($json) || !isset($json['results'])) {
        throw new RuntimeException('Unsplash vrnil nepričakovan odgovor.');
    }

    $out = [];
    foreach ($json['results'] as $p) {
        $out[] = [
            'id'             => $p['id'],
            'thumb'          => $p['urls']['thumb']   ?? '',
            'small'          => $p['urls']['small']   ?? '',
            'regular'        => $p['urls']['regular'] ?? '',
            'width'          => (int)($p['width']  ?? 0),
            'height'         => (int)($p['height'] ?? 0),
            'color'          => $p['color'] ?? '',
            'description'    => $p['description'] ?? ($p['alt_description'] ?? ''),
            'photographer'   => [
                'name'   => $p['user']['name']         ?? 'Unknown',
                'profile'=> $p['user']['links']['html']?? '',
            ],
            'unsplash_url'   => $p['links']['html']             ?? '',
            'download_loc'   => $p['links']['download_location']?? '',
        ];
    }
    return $out;
}

/**
 * Pridobi en photo objekt za prenos (ko user iz pickerja izbere ID).
 */
function blog_unsplash_get_photo($photoId) {
    if (!defined('UNSPLASH_ACCESS_KEY') || !UNSPLASH_ACCESS_KEY) {
        throw new RuntimeException('UNSPLASH_ACCESS_KEY ni nastavljen.');
    }
    $photoId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$photoId);
    if ($photoId === '') throw new RuntimeException('Neveljaven photo_id.');

    $resp = _bk_unsplash_get(BLOG_UNSPLASH_API . '/photos/' . $photoId);
    $json = json_decode($resp, true);
    if (!is_array($json) || empty($json['urls']['raw'])) {
        throw new RuntimeException('Unsplash photo ne obstaja.');
    }
    return $json;
}

/**
 * Prenese sliko, jo shrani prek blog_image_save_from_path, in baka attribution
 * v caption_translations. Klicalec naj nato content_md zapiše s `![alt](media:ID "caption")`.
 *
 * @return array  Metadata kot blog_image_save_from_path() + 'attribution' field.
 */
function blog_unsplash_download_and_save($photoId, $altInLang, $captionInLang, $masterLang, $userId) {
    $photo = blog_unsplash_get_photo($photoId);

    // Najboljša velikost: ne razini "raw" (lahko je 4000+px), uporabimo "regular" (~1080) ali "full"
    // Za blog hero (1792x1024) je full bolj ustrezen.
    $imgUrl = $photo['urls']['full']    ?? $photo['urls']['regular'] ?? $photo['urls']['raw'];
    $photographer = [
        'name'    => $photo['user']['name']         ?? 'Unknown',
        'profile' => $photo['user']['links']['html']?? 'https://unsplash.com',
    ];
    $unsplashUrl  = $photo['links']['html']               ?? 'https://unsplash.com';
    $downloadLoc  = $photo['links']['download_location']  ?? '';

    // 1. Prenos
    $tmpFile = tempnam(sys_get_temp_dir(), 'unsplash_') . '.jpg';
    $fh = @fopen($tmpFile, 'w');
    if (!$fh) throw new RuntimeException('Ne morem ustvariti tmp datoteke.');

    $ch = curl_init($imgUrl);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if (!$ok || $code >= 400 || filesize($tmpFile) < 1024) {
        @unlink($tmpFile);
        throw new RuntimeException('Prenos slike z Unsplash CDN spodletel.');
    }

    // 2. Track download (Unsplash ToS — moramo poklicati po prenosu)
    if ($downloadLoc !== '') {
        try {
            _bk_unsplash_get($downloadLoc);
        } catch (Throwable $_) { /* ne smemo blokirati zaradi tracking-a */ }
    }

    // 3. Sestavi caption z attribution-om (zahtevano po Unsplash pogojih)
    $attribution = sprintf('Photo by %s on Unsplash', $photographer['name']);
    $finalCaption = $captionInLang;
    if ($finalCaption !== '' && stripos($finalCaption, 'Unsplash') === false) {
        $finalCaption .= ' · ' . $attribution;
    } elseif ($finalCaption === '') {
        $finalCaption = $attribution;
    }

    // 4. Predaj v image saver
    try {
        $alts = [$masterLang => $altInLang !== '' ? $altInLang : ($photo['alt_description'] ?? $attribution)];
        $caps = [$masterLang => $finalCaption];
        $meta = blog_image_save_from_path($tmpFile, 'unsplash-' . $photoId . '.jpg', $userId, [
            'move'                 => true,
            'declared_mime'        => 'image/jpeg',
            'alt_translations'     => $alts,
            'caption_translations' => $caps,
        ]);
    } catch (Exception $e) {
        @unlink($tmpFile);
        throw $e;
    }

    $meta['attribution'] = [
        'photographer'  => $photographer['name'],
        'profile_url'   => $photographer['profile'],
        'source'        => 'unsplash',
        'unsplash_url'  => $unsplashUrl,
    ];
    $meta['caption_with_attribution'] = $finalCaption;
    return $meta;
}

/**
 * Helper: Unsplash GET z Client-ID auth.
 */
function _bk_unsplash_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Client-ID ' . UNSPLASH_ACCESS_KEY,
            'Accept-Version: v1',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('Unsplash cURL napaka: ' . $err);
    if ($code >= 400) {
        $j = json_decode($resp, true);
        $msg = $j['errors'][0] ?? ('HTTP ' . $code);
        throw new RuntimeException('Unsplash API: ' . $msg);
    }
    return $resp;
}
