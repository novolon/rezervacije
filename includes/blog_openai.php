<?php
/**
 * blog_openai.php – DALL-E 3 image generator → blog_media.
 *
 * Tok:
 *   1. POST /v1/images/generations z modelom "dall-e-3"
 *   2. Prenos začasne URL slike v tmp datoteko
 *   3. Predaja v blog_image_save_from_path() (ista pipeline kot upload)
 *
 * Slike se shranijo kot PNG (DALL-E vrača PNG). GD nato naredi 4 WebP variante.
 *
 * Strošek: $0.08/slika za 1792x1024 standard quality (zadnji preverjeni cenik).
 */

require_once __DIR__ . '/blog_image_saver.php';

if (!defined('BLOG_OPENAI_API_URL'))   define('BLOG_OPENAI_API_URL',   'https://api.openai.com/v1/images/generations');
if (!defined('BLOG_OPENAI_DALLE_MODEL')) define('BLOG_OPENAI_DALLE_MODEL', 'dall-e-3');

/**
 * Generira eno sliko z DALL-E 3 in jo persista v blog_media.
 *
 * @param string  $prompt          ENGLISH prompt za DALL-E.
 * @param string  $altInLang       Alt text v jeziku članka (master_lang).
 * @param string  $captionInLang   Caption v jeziku članka (lahko prazen).
 * @param string  $masterLang      Jezik kode (sl/en/de/...).
 * @param int     $uploadedBy      User ID.
 * @param array   $opts            'size' (default 1792x1024), 'quality' (standard|hd), 'style' (vivid|natural).
 * @return array  Metadata iz blog_image_save_from_path() + 'media_id'.
 */
function blog_openai_generate_image($prompt, $altInLang, $captionInLang, $masterLang, $uploadedBy, array $opts = []) {
    if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) {
        throw new RuntimeException('OPENAI_API_KEY ni nastavljen v config.php');
    }
    $size    = $opts['size']    ?? '1792x1024';
    $quality = $opts['quality'] ?? 'standard';
    $style   = $opts['style']   ?? 'natural';

    $fullPrompt = trim($prompt);
    // Sufiks za bolj editorial vibe — DALL-E pogosto rad doda tekst, prepovejmo eksplicitno
    $fullPrompt .= ' Editorial photography style. Clean composition. No text, no watermarks, no logos, no signage with words.';

    $payload = [
        'model'   => BLOG_OPENAI_DALLE_MODEL,
        'prompt'  => $fullPrompt,
        'n'       => 1,
        'size'    => $size,
        'quality' => $quality,
        'style'   => $style,
        'response_format' => 'url',
    ];

    $ch = curl_init(BLOG_OPENAI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . OPENAI_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) throw new RuntimeException('OpenAI cURL napaka: ' . $err);
    $json = json_decode($resp, true);
    if ($code >= 400 || !is_array($json)) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $code);
        throw new RuntimeException('OpenAI API: ' . $msg);
    }
    if (empty($json['data'][0]['url'])) {
        throw new RuntimeException('OpenAI: manjka URL v odgovoru.');
    }
    $imgUrl = $json['data'][0]['url'];

    // Prenos v tmp datoteko
    $tmpFile = tempnam(sys_get_temp_dir(), 'dalle_') . '.png';
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
    $dlCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if (!$ok || $dlCode >= 400 || filesize($tmpFile) < 1024) {
        @unlink($tmpFile);
        throw new RuntimeException('Prenos slike z DALL-E URL-ja ni uspel.');
    }

    // Predaj v image saver pipeline (move=true zaradi tmp lokacije)
    try {
        $alts = [$masterLang => $altInLang];
        $caps = $captionInLang !== '' ? [$masterLang => $captionInLang] : null;
        $meta = blog_image_save_from_path($tmpFile, 'dalle3-' . date('Ymd-His') . '.png', $uploadedBy, [
            'move'                 => true,
            'declared_mime'        => 'image/png',
            'alt_translations'     => $alts,
            'caption_translations' => $caps,
        ]);
    } catch (Exception $e) {
        @unlink($tmpFile);
        throw $e;
    }

    return $meta;
}
