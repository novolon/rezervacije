<?php
/**
 * Blog media API – upload + list + delete.
 *
 * Upload: multipart/form-data POST z `file` field.
 *   1. Validacija mime (finfo_file), velikosti, dimenzij.
 *   2. SHA1 hash → unique filename pod /uploads/blog/{yyyy}/{mm}/{hash}.{ext}
 *   3. GD resize: 480/800/1200/1920px wide variants (WebP če podprt, drugače JPEG).
 *   4. Dominant color iz povprečja 5x5 sample-a.
 *   5. Insert v blog_media + vrne metadata.
 *
 * List: GET ?action=list  →  vrne zadnjih 100 slik.
 * Delete: POST/DELETE ?action=delete&id=N — hkrati pobriše datoteke z diska.
 * Save alt: POST ?action=save_alt — alt_translations + caption_translations po jezikih.
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lang.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_superadmin();
$pdo     = getDB();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($method === 'POST' ? 'upload' : 'list');

// ─── List ────────────────────────────────────────────────────────
if ($action === 'list') {
    $stmt = $pdo->query(
        "SELECT id, filename, original_name, mime_type, byte_size, width, height,
                variants, dominant_color, alt_translations, caption_translations, created_at
         FROM blog_media ORDER BY created_at DESC LIMIT 200"
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['variants']             = $r['variants']             ? json_decode($r['variants'], true)             : [];
        $r['alt_translations']     = $r['alt_translations']     ? json_decode($r['alt_translations'], true)     : [];
        $r['caption_translations'] = $r['caption_translations'] ? json_decode($r['caption_translations'], true) : [];
        // Pripravi pot za UI
        if (!empty($r['variants'][800])) {
            $r['preview_url'] = BASE_PATH . '/' . ltrim($r['variants'][800], '/');
        } elseif (!empty($r['variants'])) {
            $r['preview_url'] = BASE_PATH . '/' . ltrim(reset($r['variants']), '/');
        } else {
            $r['preview_url'] = '';
        }
    }
    json_response(true, $rows);
}

// ─── Save alt/caption ────────────────────────────────────────────
if ($action === 'save_alt' && in_array($method, ['POST','PUT'], true)) {
    $body = get_body();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) json_response(false, null, 'id obvezen.', 400);
    $alts = $body['alt_translations'] ?? null;
    $caps = $body['caption_translations'] ?? null;
    $pdo->prepare("UPDATE blog_media SET alt_translations = ?, caption_translations = ? WHERE id = ?")
        ->execute([
            $alts ? json_encode($alts, JSON_UNESCAPED_UNICODE) : null,
            $caps ? json_encode($caps, JSON_UNESCAPED_UNICODE) : null,
            $id,
        ]);
    json_response(true, null, 'Shranjeno.');
}

// ─── Delete ──────────────────────────────────────────────────────
if ($action === 'delete' && in_array($method, ['POST','DELETE'], true)) {
    $body = get_body();
    $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
    if (!$id) json_response(false, null, 'id obvezen.', 400);
    $stmt = $pdo->prepare("SELECT filename, variants FROM blog_media WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) {
        $rootDir = realpath(__DIR__ . '/..');
        $variants = $row['variants'] ? json_decode($row['variants'], true) : [];
        foreach ($variants as $rel) {
            $f = $rootDir . '/' . ltrim($rel, '/');
            if (is_file($f)) @unlink($f);
        }
        if (!empty($row['filename'])) {
            // Original v isti folder kot variants
            $origPath = $rootDir . '/uploads/blog/' . date('Y') . '/' . date('m') . '/' . $row['filename'];
            if (is_file($origPath)) @unlink($origPath);
        }
    }
    $pdo->prepare("DELETE FROM blog_media WHERE id = ?")->execute([$id]);
    json_response(true, null, 'Izbrisano.');
}

// ─── Upload ──────────────────────────────────────────────────────
if ($action === 'upload' && $method === 'POST') {
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $msgs = [
            UPLOAD_ERR_NO_FILE => 'Datoteka ni izbrana.',
            UPLOAD_ERR_INI_SIZE => 'Datoteka je prevelika (php.ini).',
            UPLOAD_ERR_FORM_SIZE => 'Datoteka je prevelika.',
            UPLOAD_ERR_PARTIAL => 'Naložen samo del datoteke.',
            UPLOAD_ERR_NO_TMP_DIR => 'Manjka tmp mapa.',
            UPLOAD_ERR_CANT_WRITE => 'Ne morem pisati na disk.',
        ];
        json_response(false, null, $msgs[$err] ?? 'Napaka pri uploadu.', 400);
    }

    $tmp  = $_FILES['file']['tmp_name'];
    $orig = $_FILES['file']['name'];
    $size = (int)$_FILES['file']['size'];

    if ($size > 8 * 1024 * 1024) json_response(false, null, 'Datoteka je prevelika (max 8 MB).', 400);

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmp);
    finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        json_response(false, null, 'Nepodprt format. Dovoljeni: JPG, PNG, WebP, GIF.', 400);
    }
    $ext = $allowed[$mime];

    // Naloži v GD
    if (!extension_loaded('gd')) {
        json_response(false, null, 'GD ekstenzija ni naložena.', 500);
    }
    $src = false;
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($tmp); break;
        case 'image/png':  $src = @imagecreatefrompng($tmp); break;
        case 'image/webp': $src = @imagecreatefromwebp($tmp); break;
        case 'image/gif':  $src = @imagecreatefromgif($tmp); break;
    }
    if (!$src) json_response(false, null, 'Slike ni mogoče prebrati.', 400);

    $width  = imagesx($src);
    $height = imagesy($src);
    if ($width > 6000 || $height > 6000) {
        imagedestroy($src);
        json_response(false, null, 'Maksimalne dimenzije: 6000×6000 px.', 400);
    }

    // Dominant color (5×5 sample → average)
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

    // Pripravi mapo: /uploads/blog/{yyyy}/{mm}/
    $rootDir = realpath(__DIR__ . '/..');
    $year  = date('Y');
    $month = date('m');
    $relDir = 'uploads/blog/' . $year . '/' . $month;
    $absDir = $rootDir . '/' . $relDir;
    if (!is_dir($absDir)) {
        if (!@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            imagedestroy($src);
            json_response(false, null, 'Ne morem ustvariti mape: ' . $relDir, 500);
        }
    }

    // Unique filename
    $hash = sha1_file($tmp) . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
    $baseName = $hash;

    // Shrani original
    $origRel = $relDir . '/' . $baseName . '.' . $ext;
    $origAbs = $rootDir . '/' . $origRel;
    if (!@move_uploaded_file($tmp, $origAbs)) {
        // Fallback: že prebrali v GD, lahko save iz $src
        switch ($mime) {
            case 'image/jpeg': @imagejpeg($src, $origAbs, 90); break;
            case 'image/png':  @imagepng($src, $origAbs, 6); break;
            case 'image/webp': @imagewebp($src, $origAbs, 88); break;
            case 'image/gif':  @imagegif($src, $origAbs); break;
        }
    }

    // Generiraj variants — 480/800/1200/1920 px wide (samo za rasterske, ne za GIF)
    $variants = [];
    $variantSizes = [480, 800, 1200, 1920];
    $useWebp = function_exists('imagewebp');

    foreach ($variantSizes as $targetW) {
        if ($targetW >= $width) {
            // Slika je že manjša/enaka — uporabimo original
            $variants[$targetW] = $origRel;
            continue;
        }
        $newH = (int)round($height * ($targetW / $width));
        $resized = imagecreatetruecolor($targetW, $newH);
        // Ohrani transparentnost za PNG/WebP
        if (in_array($mime, ['image/png','image/webp','image/gif'], true)) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $tr = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefilledrectangle($resized, 0, 0, $targetW, $newH, $tr);
        }
        imagecopyresampled($resized, $src, 0,0,0,0, $targetW, $newH, $width, $height);

        // Shrani: WebP če podprt + ni gif animation
        $varExt = $useWebp && $mime !== 'image/gif' ? 'webp' : $ext;
        $varRel = $relDir . '/' . $baseName . '-' . $targetW . '.' . $varExt;
        $varAbs = $rootDir . '/' . $varRel;
        $ok = false;
        if ($varExt === 'webp') {
            $ok = @imagewebp($resized, $varAbs, 86);
        } elseif ($varExt === 'jpg' || $varExt === 'jpeg') {
            $ok = @imagejpeg($resized, $varAbs, 88);
        } elseif ($varExt === 'png') {
            $ok = @imagepng($resized, $varAbs, 6);
        } elseif ($varExt === 'gif') {
            $ok = @imagegif($resized, $varAbs);
        }
        imagedestroy($resized);
        if ($ok) $variants[$targetW] = $varRel;
    }
    imagedestroy($src);

    if (empty($variants)) {
        // Fallback: vsaj original
        $variants[$width] = $origRel;
    }

    $insStmt = $pdo->prepare(
        "INSERT INTO blog_media
         (filename, original_name, mime_type, byte_size, width, height, variants, dominant_color, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $insStmt->execute([
        $baseName . '.' . $ext,
        $orig,
        $mime,
        $size,
        $width,
        $height,
        json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $dominantColor,
        (int)$session['user_id'],
    ]);
    $id = (int)$pdo->lastInsertId();

    json_response(true, [
        'id'              => $id,
        'filename'        => $baseName . '.' . $ext,
        'mime_type'       => $mime,
        'width'           => $width,
        'height'          => $height,
        'variants'        => $variants,
        'preview_url'     => BASE_PATH . '/' . ltrim($variants[800] ?? reset($variants), '/'),
        'dominant_color'  => $dominantColor,
    ]);
}

json_response(false, null, 'Neznana akcija: ' . $action, 400);
