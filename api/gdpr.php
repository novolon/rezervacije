<?php
/**
 * GDPR API
 * POST ?action=submit_request   – javni GDPR zahtevek (brez prijave)
 * POST ?action=update_status    – superadmin: posodobi status zahtevka
 * POST ?action=erase_user       – superadmin: izbriši/anonimiziraj admina
 * POST ?action=erase_guest      – superadmin: anonimiziraj gostove rezervacije
 * POST ?action=export_user      – superadmin: izvozi podatke admina (JSON)
 * POST ?action=export_guest     – superadmin: izvozi podatke gosta (JSON)
 */
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');

$pdo    = getDB();
$action = trim($_GET['action'] ?? '');
$body   = get_body();

// ── Javna akcija: submit_request ──────────────────────────────
if ($action === 'submit_request') {
    $email        = trim($body['email']         ?? '');
    $type         = trim($body['type']          ?? '');
    $restaurantId = (int)($body['restaurant_id'] ?? 0) ?: null;

    $validTypes = ['access', 'rectification', 'erasure', 'portability'];
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, null, 'Vnesite veljaven email naslov.', 400);
    }
    if (!in_array($type, $validTypes)) {
        json_response(false, null, 'Neveljaven tip zahtevka.', 400);
    }

    // Prepreči spam: max 3 zahtevki z istim emailom v 24h
    $spamCheck = $pdo->prepare("
        SELECT COUNT(*) FROM gdpr_requests
        WHERE requester_email = ? AND requested_at > NOW() - INTERVAL 24 HOUR
    ");
    $spamCheck->execute([$email]);
    if ($spamCheck->fetchColumn() >= 3) {
        json_response(false, null, 'Preveč zahtevkov. Počakajte 24 ur.', 429);
    }

    $pdo->prepare("
        INSERT INTO gdpr_requests (type, requester_email, restaurant_id)
        VALUES (?, ?, ?)
    ")->execute([$type, $email, $restaurantId]);

    // Potrditveni email prosilcu
    try {
        $typeLabels = [
            'access'        => 'dostop do podatkov',
            'rectification' => 'popravek podatkov',
            'erasure'       => 'izbris podatkov',
            'portability'   => 'prenosljivost podatkov',
        ];
        send_gdpr_confirmation($email, $typeLabels[$type], _resolve_email_lang());
    } catch (Exception $e) {
        error_log('GDPR confirmation email failed: ' . $e->getMessage());
    }

    json_response(true, null);
}

// ── Zaščitene akcije – samo superadmin ───────────────────────
$session = require_superadmin();

if ($action === 'update_status') {
    $id     = (int)($body['id']     ?? 0);
    $status = trim($body['status']  ?? '');
    $notes  = trim($body['notes']   ?? '');

    $valid = ['pending', 'processing', 'completed', 'rejected'];
    if (!$id || !in_array($status, $valid)) {
        json_response(false, null, 'Neveljavni podatki.', 400);
    }

    $resolved = in_array($status, ['completed', 'rejected']) ? date('Y-m-d H:i:s') : null;

    $pdo->prepare("
        UPDATE gdpr_requests
        SET status = ?, notes = ?, resolved_at = ?
        WHERE id = ?
    ")->execute([$status, $notes ?: null, $resolved, $id]);

    json_response(true);
}

if ($action === 'erase_user') {
    $userId = (int)($body['user_id'] ?? 0);
    if (!$userId) json_response(false, null, 'Manjka user_id.', 400);

    $user = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
    $user->execute([$userId]);
    $userRow = $user->fetch();
    if (!$userRow) json_response(false, null, 'Uporabnik ne obstaja.', 404);
    if ($userRow['role'] === 'superadmin') json_response(false, null, 'Superadmina ni mogoče izbrisati.', 403);

    $anon     = 'izbrisano_' . $userId;
    $anonMail = 'deleted_' . $userId . '@anon.invalid';
    $now      = date('Y-m-d H:i:s');

    $pdo->prepare("
        UPDATE users
        SET email = ?, full_name = ?, password_hash = '',
            phone = NULL, company_name = ?, company_address = NULL,
            tax_number = NULL, vat_id = NULL,
            remember_token = NULL, verification_token = NULL, reset_token = NULL,
            deleted_at = ?
        WHERE id = ?
    ")->execute([$anonMail, $anon, $anon, $now, $userId]);

    // Anonimiziraj rezervacije tega admina (ohrani datum/uro/število gostov za statistiko)
    $pdo->prepare("
        UPDATE reservations
        SET guest_name = 'Anonimizirano', email = NULL, phone = NULL, notes = NULL
        WHERE created_by = ?
    ")->execute([$userId]);

    json_response(true, ['anonymized' => true]);
}

if ($action === 'erase_guest') {
    $email        = trim($body['email']         ?? '');
    $restaurantId = (int)($body['restaurant_id'] ?? 0);

    if (!$email || !$restaurantId) {
        json_response(false, null, 'Manjkata email in restaurant_id.', 400);
    }

    // Anonimiziraj rezervacije gosta v tej restavraciji
    $pdo->prepare("
        UPDATE reservations
        SET guest_name = 'Anonimizirano', email = NULL, phone = NULL, notes = NULL
        WHERE restaurant_id = ? AND email = ?
    ")->execute([$restaurantId, $email]);

    $affected = $pdo->rowCount();

    json_response(true, ['anonymized_reservations' => $affected]);
}

if ($action === 'export_user') {
    $userId = (int)($body['user_id'] ?? 0);
    if (!$userId) json_response(false, null, 'Manjka user_id.', 400);

    $u = $pdo->prepare("SELECT id, email, full_name, company_name, company_address, tax_number, vat_id, created_at, gdpr_consent_at, subscription_status FROM users WHERE id = ?");
    $u->execute([$userId]);
    $userRow = $u->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) json_response(false, null, 'Uporabnik ne obstaja.', 404);

    $r = $pdo->prepare("SELECT reservation_date, reservation_time, guest_count, status, created_at FROM reservations WHERE created_by = ? ORDER BY reservation_date DESC");
    $r->execute([$userId]);
    $reservations = $r->fetchAll(PDO::FETCH_ASSOC);

    $export = [
        'exported_at' => date('c'),
        'user'        => $userRow,
        'reservations'=> $reservations,
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="gdpr_export_user_' . $userId . '.json"');
    echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'export_guest') {
    $email        = trim($body['email']         ?? '');
    $restaurantId = (int)($body['restaurant_id'] ?? 0);

    if (!$email || !$restaurantId) {
        json_response(false, null, 'Manjkata email in restaurant_id.', 400);
    }

    $r = $pdo->prepare("
        SELECT reservation_date, reservation_time, guest_count, status, notes, created_at
        FROM reservations
        WHERE restaurant_id = ? AND email = ?
        ORDER BY reservation_date DESC
    ");
    $r->execute([$restaurantId, $email]);
    $reservations = $r->fetchAll(PDO::FETCH_ASSOC);

    $export = [
        'exported_at'  => date('c'),
        'email'        => $email,
        'restaurant_id'=> $restaurantId,
        'reservations' => $reservations,
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="gdpr_export_guest_' . time() . '.json"');
    echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

json_response(false, null, 'Neznana akcija.', 400);
