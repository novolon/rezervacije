<?php
/**
 * blog_image_saver.php – ponovno uporabljiv pipeline za shranjevanje slik v blog_media.
 *
 * Sprejme lokalno (že obstoječo) datoteko, jo predela skozi GD (resize, dominant color),
 * shrani v /uploads/blog/{Y}/{m}/, INSERT v blog_media in vrne metapodatke.
 *
 * Klicalec mora poskrbeti da datoteka obstaja na disku. Originalna datoteka se prestavi
 * ali kopira v ciljni direktorij (parameter $moveOriginal).
 *
 * Vrnitveni format = isti kot api/blog_media.php upload odgovor.
 */

if (!function_exists('blog_image_save_from_path')) {

/**
 * @param string  $srcPath        Absolutna pot do izvorne datoteke.
 * @param string  $originalName   Izvorno ime (za zapis v blog_media.original_name).
 * @param int     $uploadedBy     User ID (blog_media.uploaded_by).
 * @param array   $options        Dodatne opcije:
 *                                  - 'move' (bool, default false): če true, izvorno premakne (rename), drugače kopira.
 *                                  - 'alt_translations' (array|null): {lang_code: alt_text}
 *                                  - 'caption_translations' (array|null): {lang_code: caption}
 *                                  - 'declared_mime' (string|null): če zunaj veš mime (npr. PNG iz DALL-E), sicer auto-detect.
 * @return array|null  Polni metapodatki ali null pri napaki (in opcijsko throw).
 * @throws RuntimeException Pri kritičnih napakah.
 */
function blog_image_save_from_path($srcPath, $originalName, $uploadedBy, array $options = []) {
    if (!is_file($srcPath)) {
        throw new RuntimeException('Izvorna datoteka ne obstaja: ' . $srcPath);
    }
    if (!extension_loaded('gd')) {
        throw new RuntimeException('GD ekstenzija ni naložena.');
    }

    $size = (int)filesize($srcPath);
    if ($size <= 0)            throw new RuntimeException('Prazna datoteka.');
    if ($size > 12 * 1024 * 1024) throw new RuntimeException('Datoteka je prevelika (max 12 MB).');

    // Mime detect
    $mime = $options['declared_mime'] ?? null;
    if (!$mime) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $srcPath);
        finfo_close($finfo);
    }
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Nepodprt format: ' . $mime);
    }
    $ext = $allowed[$mime];

    $src = false;
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($srcPath); break;
        case 'image/png':  $src = @imagecreatefrompng($srcPath); break;
        case 'image/webp': $src = @imagecreatefromwebp($srcPath); break;
        case 'image/gif':  $src = @imagecreatefromgif($srcPath); break;
    }
    if (!$src) throw new RuntimeException('Slike ni mogoče prebrati skozi GD.');

    $width  = imagesx($src);
    $height = imagesy($src);
    if ($width > 6000 || $height > 6000) {
        imagedestroy($src);
        throw new RuntimeException('Maksimalne dimenzije: 6000x6000 px.');
    }

    // Dominant color (5x5 sample → average)
    $sw = 5; $sh = 5;
    $thumb = imagecreatetruecolor($sw, $sh);
    imagecopyresampled($thumb, $src, 0,0,0,0, $sw, $sh, $width, $height);
    $rSum=$gSum=$bSum=0;
    for ($x=0; $x<$sw; $x++) for ($y=0; $y<$sh; $y++) {
        $rgb = imagecolorat($thumb, $x, $y);
        $rSum += ($rgb >> 16) & 0xFF;
        $gSum += ($rgb >> 8) & 0xFF;
        $bSum += $rgb & 0xFF;
    }
    $count = $sw * $sh;
    $dominantColor = sprintf('#%02x%02x%02x',
        (int)round($rSum / $count),
        (int)round($gSum / $count),
        (int)round($bSum / $count)
    );
    imagedestroy($thumb);

    // Pripravi mapo: /uploads/blog/{Y}/{m}/
    $rootDir = realpath(__DIR__ . '/..');
    $year  = date('Y');
    $month = date('m');
    $relDir = 'uploads/blog/' . $year . '/' . $month;
    $absDir = $rootDir . '/' . $relDir;
    if (!is_dir($absDir)) {
        if (!@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            imagedestroy($src);
            throw new RuntimeException('Ne morem ustvariti mape: ' . $relDir);
        }
    }

    // Unique filename (sha1 + nonce, ker se lahko isti DALL-E prompt ponovi)
    $hash = sha1_file($srcPath) . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
    $baseName = $hash;

    // Shrani original
    $origRel = $relDir . '/' . $baseName . '.' . $ext;
    $origAbs = $rootDir . '/' . $origRel;
    $moved   = false;
    if (!empty($options['move'])) {
        $moved = @rename($srcPath, $origAbs);
    }
    if (!$moved) {
        $moved = @copy($srcPath, $origAbs);
    }
    if (!$moved) {
        // Zadnji fallback – persist iz GD resource-a
        switch ($mime) {
            case 'image/jpeg': $moved = @imagejpeg($src, $origAbs, 90); break;
            case 'image/png':  $moved = @imagepng($src, $origAbs, 6); break;
            case 'image/webp': $moved = @imagewebp($src, $origAbs, 88); break;
            case 'image/gif':  $moved = @imagegif($src, $origAbs); break;
        }
    }
    if (!$moved) {
        imagedestroy($src);
        throw new RuntimeException('Ne morem shraniti originalne datoteke.');
    }

    // Generiraj variants — 480/800/1200/1920 px wide
    $variants     = [];
    $variantSizes = [480, 800, 1200, 1920];
    $useWebp      = function_exists('imagewebp');

    foreach ($variantSizes as $targetW) {
        if ($targetW >= $width) {
            $variants[$targetW] = $origRel;
            continue;
        }
        $newH = (int)round($height * ($targetW / $width));
        $resized = imagecreatetruecolor($targetW, $newH);
        if (in_array($mime, ['image/png','image/webp','image/gif'], true)) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $tr = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefilledrectangle($resized, 0, 0, $targetW, $newH, $tr);
        }
        imagecopyresampled($resized, $src, 0,0,0,0, $targetW, $newH, $width, $height);

        $varExt = $useWebp && $mime !== 'image/gif' ? 'webp' : $ext;
        $varRel = $relDir . '/' . $baseName . '-' . $targetW . '.' . $varExt;
        $varAbs = $rootDir . '/' . $varRel;
        $ok = false;
        if ($varExt === 'webp')                    $ok = @imagewebp($resized, $varAbs, 86);
        elseif ($varExt === 'jpg' || $varExt === 'jpeg') $ok = @imagejpeg($resized, $varAbs, 88);
        elseif ($varExt === 'png')                 $ok = @imagepng($resized, $varAbs, 6);
        elseif ($varExt === 'gif')                 $ok = @imagegif($resized, $varAbs);
        imagedestroy($resized);
        if ($ok) $variants[$targetW] = $varRel;
    }
    imagedestroy($src);

    if (empty($variants)) $variants[$width] = $origRel;

    $alts = $options['alt_translations'] ?? null;
    $caps = $options['caption_translations'] ?? null;

    $pdo = function_exists('getDB') ? getDB() : $GLOBALS['pdo'];
    $insStmt = $pdo->prepare(
        "INSERT INTO blog_media
         (filename, original_name, mime_type, byte_size, width, height, variants, dominant_color,
          alt_translations, caption_translations, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $insStmt->execute([
        $baseName . '.' . $ext,
        $originalName,
        $mime,
        $size,
        $width,
        $height,
        json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $dominantColor,
        $alts ? json_encode($alts, JSON_UNESCAPED_UNICODE) : null,
        $caps ? json_encode($caps, JSON_UNESCAPED_UNICODE) : null,
        (int)$uploadedBy,
    ]);
    $id = (int)$pdo->lastInsertId();

    return [
        'id'             => $id,
        'filename'       => $baseName . '.' . $ext,
        'mime_type'      => $mime,
        'width'          => $width,
        'height'         => $height,
        'byte_size'      => $size,
        'variants'       => $variants,
        'preview_url'    => BASE_PATH . '/' . ltrim($variants[800] ?? reset($variants), '/'),
        'dominant_color' => $dominantColor,
    ];
}

}
