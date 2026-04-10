<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_admin();
$pdo     = getDB();
$body    = get_body();
$action  = $body['action'] ?? '';
$userId  = (int)$session['user_id'];

// ─── Posodobi podatke o podjetju ──────────────────────────────
if ($action === 'update_company') {
    $companyName    = trim($body['company_name']    ?? '');
    $companyAddress = trim($body['company_address'] ?? '');
    $isVat          = !empty($body['is_vat_registered']);
    $taxNumber      = trim($body['tax_number'] ?? '');
    $vatId          = strtoupper(trim($body['vat_id'] ?? ''));

    if (!$companyName)    json_response(false, null, 'Naziv podjetja je obvezen.', 400);
    if (!$companyAddress) json_response(false, null, 'Naslov podjetja je obvezen.', 400);

    if ($isVat) {
        if (!$vatId) json_response(false, null, 'ID za DDV je obvezen.', 400);
        if (!preg_match('/^[A-Z]{2}[A-Z0-9]{2,15}$/', $vatId))
            json_response(false, null, 'ID za DDV ni veljavne oblike (npr. SI12345678).', 400);
        $taxNumber = null;
    } else {
        if (!$taxNumber) json_response(false, null, 'Davčna številka je obvezna.', 400);
        if (!preg_match('/^[A-Z0-9]{4,20}$/i', $taxNumber))
            json_response(false, null, 'Davčna številka ni veljavna.', 400);
        $vatId = null;
    }

    $pdo->prepare("
        UPDATE users SET company_name=?, company_address=?, is_vat_registered=?, tax_number=?, vat_id=?
        WHERE id=?
    ")->execute([$companyName, $companyAddress, $isVat ? 1 : 0, $taxNumber, $vatId, $userId]);

    json_response(true, null, 'Podatki o podjetju so posodobljeni.');
}

// ─── Sprememba gesla ──────────────────────────────────────────
if ($action === 'change_password') {
    $currentPw = $body['current_password'] ?? '';
    $newPw     = $body['new_password']     ?? '';
    $confirmPw = $body['confirm_password'] ?? '';

    if (!$currentPw || !$newPw || !$confirmPw)
        json_response(false, null, 'Vsa polja so obvezna.', 400);
    if (strlen($newPw) < 8)
        json_response(false, null, 'Novo geslo mora imeti vsaj 8 znakov.', 400);
    if ($newPw !== $confirmPw)
        json_response(false, null, 'Novi gesli se ne ujemata.', 400);

    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($currentPw, $user['password_hash']))
        json_response(false, null, 'Trenutno geslo ni pravilno.', 400);

    $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")
        ->execute([password_hash($newPw, PASSWORD_BCRYPT), $userId]);

    json_response(true, null, 'Geslo je bilo uspešno spremenjeno.');
}

// ─── Zahteva za spremembo emaila ─────────────────────────────
if ($action === 'request_email_change') {
    require_once '../includes/mailer.php';

    $password = $body['password'] ?? '';
    $newEmail = trim(strtolower($body['new_email'] ?? ''));

    if (!$password || !$newEmail)
        json_response(false, null, 'Vsa polja so obvezna.', 400);
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL))
        json_response(false, null, 'Vnesite veljaven email naslov.', 400);

    $stmt = $pdo->prepare("SELECT password_hash, full_name, email FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash']))
        json_response(false, null, 'Geslo ni pravilno.', 400);
    if ($newEmail === $user['email'])
        json_response(false, null, 'Nov email je enak trenutnemu.', 400);

    // Preveri da email ni že v uporabi
    $exists = $pdo->prepare("SELECT id FROM users WHERE email=? AND id != ?");
    $exists->execute([$newEmail, $userId]);
    if ($exists->fetchColumn()) json_response(false, null, 'Ta email naslov je že v uporabi.', 400);

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $pdo->prepare("
        UPDATE users SET email_change_pending=?, email_change_token=?, email_change_expires=? WHERE id=?
    ")->execute([$newEmail, $token, $expires, $userId]);

    send_email_change_email($newEmail, $user['full_name'], $token);

    json_response(true, null, 'Poslali smo potrditveni link na ' . $newEmail . '. Preverite email.');
}

json_response(false, null, 'Neznan action.', 400);
