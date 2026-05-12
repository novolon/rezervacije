<?php
/**
 * Logo upload (premium only).
 *  POST  multipart: logo=<file>, restaurant_id=<id>
 *  DELETE ?id=<restaurant_id>
 *
 * Shrani v uploads/logos/{rid}_{hash}.{ext} in nastavi restaurants.logo_path.
 */

require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_admin();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

// Premium gating
if (!user_has_feature($pdo, (int)$session['user_id'], 'custom_logo')) {
    json_response(false, null, 'Lasten logotip je na voljo samo v Premium paketu.', 403);
}

function _logo_owns(PDO $pdo, array $session, int $restId): bool {
    if ($session['role'] === 'superadmin') return true;
    $stmt = $pdo->prepare("SELECT 1 FROM restaurant_admins WHERE restaurant_id = ? AND user_id = ?");
    $stmt->execute([$restId, $session['user_id']]);
    return (bool)$stmt->fetchColumn();
}

function _logo_dir(): string {
    $dir = __DIR__ . '/../uploads/logos';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function _logo_clear_existing(PDO $pdo, int $restId): void {
    $stmt = $pdo->prepare("SELECT logo_path FROM restaurants WHERE id = ?");
    $stmt->execute([$restId]);
    $old = $stmt->fetchColumn();
    if ($old) {
        $oldFull = __DIR__ . '/../' . ltrim($old, '/');
        if (is_file($oldFull)) @unlink($oldFull);
    }
}

// ─── POST: upload ──────────────────────────────────────────────
if ($method === 'POST') {
    $restId = (int)($_POST['restaurant_id'] ?? 0);
    if (!$restId || !_logo_owns($pdo, $session, $restId)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }
    if (empty($_FILES['logo']) || ($_FILES['logo']['error'] ?? 4) !== UPLOAD_ERR_OK) {
        json_response(false, null, 'Datoteka manjka ali napaka pri uploadu.', 400);
    }

    $f    = $_FILES['logo'];
    $size = (int)$f['size'];
    if ($size > 500 * 1024) {
        json_response(false, null, 'Datoteka je prevelika (max 500 KB).', 400);
    }

    $allowed = [
        'image/png'     => 'png',
        'image/jpeg'    => 'jpg',
        'image/svg+xml' => 'svg',
        'image/webp'    => 'webp',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($f['tmp_name']);
    if (!isset($allowed[$mime])) {
        json_response(false, null, 'Nepodprta vrsta datoteke. Dovoljeno: PNG, JPG, SVG, WEBP.', 400);
    }
    $ext = $allowed[$mime];

    // Beri vsebino; pri SVG sanitiziraj
    $content = file_get_contents($f['tmp_name']);
    if ($content === false) json_response(false, null, 'Branje datoteke ni uspelo.', 500);

    if ($mime === 'image/svg+xml') {
        // Osnoven sanitizer: zavrni če vsebuje <script>, on*= eventhandlerje ali javascript: linke.
        if (preg_match('/<script\b|on[a-z]+\s*=|javascript:|<foreignObject\b/i', $content)) {
            json_response(false, null, 'SVG vsebuje nedovoljene elemente. Uporabite "očiščen" SVG (brez skript/event handler-jev).', 400);
        }
    }

    // Generiraj ime z hash-om (cache busting)
    $hash = substr(sha1($content . microtime(true)), 0, 12);
    $name = $restId . '_' . $hash . '.' . $ext;
    $dest = _logo_dir() . '/' . $name;

    if (!file_put_contents($dest, $content)) {
        json_response(false, null, 'Shranjevanje ni uspelo.', 500);
    }

    // Briši stari logo (če je)
    _logo_clear_existing($pdo, $restId);

    $relPath = 'uploads/logos/' . $name;
    $pdo->prepare("UPDATE restaurants SET logo_path = ? WHERE id = ?")->execute([$relPath, $restId]);

    json_response(true, ['path' => $relPath]);
}

// ─── DELETE: odstrani logo ──────────────────────────────────────
if ($method === 'DELETE') {
    $restId = (int)($_GET['id'] ?? 0);
    if (!$restId || !_logo_owns($pdo, $session, $restId)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }
    _logo_clear_existing($pdo, $restId);
    $pdo->prepare("UPDATE restaurants SET logo_path = NULL WHERE id = ?")->execute([$restId]);
    json_response(true);
}

json_response(false, null, 'Metoda ni podprta.', 405);
