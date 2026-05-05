<?php
/**
 * Čakalna lista API (javni endpoint – brez prijave).
 *
 * POST ?t={booking_token}                    → vpis na čakalno listo
 * GET  ?token={waitlist_token}&action=confirm → potrditev mesta
 * GET  ?token={waitlist_token}&action=remove  → odjava s čakalne liste
 */
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/waitlist_notifier.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: potrditev ali odjava ──────────────────────────────────
if ($method === 'GET') {
    $token  = trim($_GET['token'] ?? '');
    $action = trim($_GET['action'] ?? '');

    if (!$token || !in_array($action, ['confirm', 'remove'])) {
        // Prikaz HTML napake (ker bo gost sledil linku v emailu)
        _html_error('Neveljaven zahtevek.');
    }

    $stmt = $pdo->prepare("
        SELECT w.*, r.name AS rest_name, r.owner_id
        FROM waitlist w
        JOIN restaurants r ON r.id = w.restaurant_id
        WHERE w.token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $entry = $stmt->fetch();

    if (!$entry) _html_error('Čakalni vnos ne obstaja ali je potekel.');

    if ($action === 'remove') {
        if (in_array($entry['status'], ['confirmed', 'removed'])) {
            _html_success('Že obdelano', 'Ta čakalni vnos je bil že obdelan.');
        }
        $pdo->prepare("UPDATE waitlist SET status = 'removed' WHERE id = ?")
            ->execute([$entry['id']]);
        _html_success('Odjavljeni ste', 'Uspešno ste se odjavili s čakalne liste.');
    }

    // action = confirm
    if ($entry['status'] === 'confirmed') {
        _html_success('Že potrjeno', 'Vaša rezervacija je bila že potrjena.');
    }
    if ($entry['status'] === 'removed') {
        _html_error('Ta vnos je bil odstranjen s čakalne liste.');
    }
    if ($entry['status'] === 'expired') {
        _html_error('Ponudba je potekla. Čas za potrditev je bil 2 uri od prejema obvestila.');
    }
    if ($entry['status'] !== 'notified') {
        _html_error('Rezervacija trenutno ni na voljo.');
    }
    if (strtotime($entry['expires_at']) < time()) {
        $pdo->prepare("UPDATE waitlist SET status = 'expired' WHERE id = ?")
            ->execute([$entry['id']]);
        // Obvesti naslednjega
        notify_waitlist($pdo, (int)$entry['restaurant_id'], $entry['date']);
        _html_error('Ponudba je potekla. Čas za potrditev je bil 2 uri od prejema obvestila.');
    }

    // Ustvari rezervacijo iz čakalnega vnosa
    try {
        $pdo->beginTransaction();

        // Pridobi restavracijo za trajanje in nastavitve
        $rest = $pdo->prepare("SELECT * FROM restaurants WHERE id = ?");
        $rest->execute([$entry['restaurant_id']]);
        $rest = $rest->fetch();

        $time = $entry['time_preference'] ?: '00:00';

        $editToken        = bin2hex(random_bytes(32));
        $editTokenExpires = date('Y-m-d H:i:s', strtotime($entry['date'] . ' ' . $time) + 3600);

        $pdo->prepare("
            INSERT INTO reservations
                (restaurant_id, reservation_date, reservation_time, duration,
                 guest_name, guest_count, email, phone,
                 status, source, created_by,
                 gdpr_consent, gdpr_consent_at, gdpr_consent_ip,
                 edit_token, edit_token_expires)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', 'public', ?,
                    ?, ?, ?, ?, ?)
        ")->execute([
            $entry['restaurant_id'],
            $entry['date'],
            $time . (strlen($time) === 5 ? ':00' : ''),
            $rest['reservation_duration'] ?? 60,
            $entry['first_name'] . ' ' . $entry['last_name'],
            $entry['guests'],
            $entry['email'],
            $entry['phone'],
            $rest['owner_id'],  // created_by
            $entry['gdpr_consent'],
            $entry['gdpr_consent'] ? date('Y-m-d H:i:s') : null,
            null,
            $editToken,
            $editTokenExpires,
        ]);

        $newResId = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE waitlist SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?")
            ->execute([$entry['id']]);

        $pdo->commit();

        // Posodobi gostovo bazo
        try {
            require_once __DIR__ . '/../includes/guest_helper.php';
            upsert_guest($pdo, (int)$entry['restaurant_id'], $entry['email'], [
                'guest_name'       => $entry['first_name'] . ' ' . $entry['last_name'],
                'phone'            => $entry['phone'],
                'reservation_date' => $entry['date'],
                'guest_count'      => $entry['guests'],
            ]);
        } catch (Throwable $e) { /* tiho */ }

        // Pošlji potrditveni email
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            $cEmail = $rest['contact_email'] ?? '';
            $cPhone = $rest['contact_phone'] ?? '';
            send_booking_confirmed_guest(
                $entry['email'],
                $entry['first_name'] . ' ' . $entry['last_name'],
                $entry['rest_name'],
                $entry['date'],
                $time,
                $entry['guests'],
                $rest['reservation_duration'] ?? 60,
                $editToken,
                $cEmail,
                $cPhone,
                _resolve_email_lang()
            );
        } catch (Throwable $e) { error_log('Waitlist confirm email error: ' . $e->getMessage()); }

        _html_success(
            'Rezervacija potrjena!',
            'Vaša rezervacija pri ' . htmlspecialchars($entry['rest_name'], ENT_QUOTES) .
            ' za ' . date('j. n. Y', strtotime($entry['date'])) . ' je potrjena. Poslali smo vam potrditveni email.'
        );

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Waitlist confirm error: ' . $e->getMessage());
        _html_error('Napaka strežnika. Poskusite znova ali kontaktirajte restavracijo.');
    }
}

// ── POST: vpis na čakalno listo ────────────────────────────────
if ($method === 'POST') {
    $bookingToken = trim($_GET['t'] ?? '');
    if (!$bookingToken) {
        json_response(false, null, 'Manjka booking token.', 400);
    }

    // Naloži restavracijo
    $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE booking_token = ? AND is_active = 1");
    $stmt->execute([$bookingToken]);
    $rest = $stmt->fetch();
    if (!$rest) {
        json_response(false, null, 'Restavracija ni najdena.', 404);
    }

    // Preverba paketa
    if (!user_has_feature($pdo, (int)$rest['owner_id'], 'waitlist')) {
        json_response(false, null, 'Čakalna lista ni na voljo.', 403);
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $date      = trim($body['date']        ?? '');
    $timePref  = trim($body['time_pref']   ?? '');
    $guests    = max(1, (int)($body['guests'] ?? 1));
    $firstName = trim($body['first_name']  ?? '');
    $lastName  = trim($body['last_name']   ?? '');
    $email     = trim($body['email']       ?? '');
    $phone     = trim($body['phone']       ?? '');
    $gdpr      = !empty($body['gdpr_consent']) ? 1 : 0;

    // Validacija
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(false, null, 'Neveljaven datum.', 400);
    }
    if ($date < date('Y-m-d')) {
        json_response(false, null, 'Datum je v preteklosti.', 400);
    }
    if (is_blackout($pdo, (int)$rest['id'], $date)) {
        json_response(false, null, 'Za ta datum rezervacije niso na voljo.', 400);
    }
    if (!$firstName) {
        json_response(false, null, 'Ime je obvezno.', 400);
    }
    if (!$lastName) {
        json_response(false, null, 'Priimek je obvezno.', 400);
    }
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, null, 'Vnesite veljaven email naslov.', 400);
    }
    if (!$gdpr) {
        json_response(false, null, 'Soglasje za obdelavo podatkov je obvezno.', 400);
    }

    // Preverba ali je že vpisan
    $existing = $pdo->prepare("
        SELECT id FROM waitlist
        WHERE restaurant_id = ? AND date = ? AND email = ?
          AND status IN ('waiting','notified')
        LIMIT 1
    ");
    $existing->execute([$rest['id'], $date, $email]);
    if ($existing->fetchColumn()) {
        json_response(false, null, 'Na ta datum ste že vpisani na čakalno listo.', 409);
    }

    $token = bin2hex(random_bytes(32));

    try {
        $pdo->prepare("
            INSERT INTO waitlist
                (restaurant_id, date, time_preference, guests,
                 first_name, last_name, email, phone,
                 gdpr_consent, token, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'waiting', NOW())
        ")->execute([
            $rest['id'], $date,
            $timePref ?: null, $guests,
            $firstName, $lastName, $email, $phone ?: null,
            $gdpr, $token,
        ]);

        // Pošlji potrditveni email vpisa
        $entryForMail = [
            'first_name'      => $firstName,
            'last_name'       => $lastName,
            'email'           => $email,
            'date'            => $date,
            'time_preference' => $timePref ?: null,
            'token'           => $token,
        ];
        try {
            send_waitlist_signup_email($entryForMail, $rest['name']);
        } catch (Throwable $e) { error_log('Waitlist signup email error: ' . $e->getMessage()); }

        json_response(true, ['message' => 'Uspešno ste se vpisali na čakalno listo.']);

    } catch (PDOException $e) {
        error_log('Waitlist POST error: ' . $e->getMessage());
        json_response(false, null, 'Napaka strežnika. Poskusite znova.', 500);
    }
}

json_response(false, null, 'Metoda ni podprta.', 405);

// ── HTML pomočniki ─────────────────────────────────────────────
function _html_success(string $title, string $msg): never {
    _html_page($title, $msg, '#1B4332', '✓');
}

function _html_error(string $msg): never {
    _html_page('Napaka', $msg, '#DC2626', '✕');
}

function _html_page(string $title, string $msg, string $color, string $icon): never {
    $appName = defined('APP_NAME') ? APP_NAME : 'Rezervacije';
    $basePath = defined('BASE_PATH') ? BASE_PATH : '';
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html>
<html lang='sl'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width,initial-scale=1'>
<title>{$title} – {$appName}</title>
<style>
  body { margin:0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
         background:#F3F4F6; display:flex; align-items:center; justify-content:center; min-height:100vh; }
  .card { background:#fff; border-radius:16px; padding:48px 40px; max-width:420px; width:90%; text-align:center;
          box-shadow:0 4px 20px rgba(0,0,0,.08); }
  .icon { width:64px; height:64px; border-radius:50%; background:{$color}; color:#fff;
          font-size:28px; display:flex; align-items:center; justify-content:center; margin:0 auto 20px; }
  h1 { margin:0 0 12px; font-size:22px; color:#111827; }
  p  { margin:0 0 28px; color:#6B7280; font-size:15px; line-height:1.5; }
  a  { display:inline-block; background:{$color}; color:#fff; text-decoration:none;
       padding:11px 28px; border-radius:10px; font-size:14px; font-weight:600; }
</style>
</head>
<body>
<div class='card'>
  <div class='icon'>{$icon}</div>
  <h1>{$title}</h1>
  <p>" . htmlspecialchars($msg, ENT_QUOTES) . "</p>
</div>
</body>
</html>";
    exit;
}
