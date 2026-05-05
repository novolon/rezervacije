<?php
/**
 * Javni endpoint za potrditev/zavrnitev rezervacije prek emaila.
 * Brez prijave – zavarovan z HMAC podpisom.
 * GET /api/reservation_action.php?id={id}&action={approve|reject}&sig={hmac}
 */
require_once '../config.php';
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';

$id     = isset($_GET['id'])     ? (int)$_GET['id']          : 0;
$action = trim($_GET['action']   ?? '');
$sig    = trim($_GET['sig']      ?? '');

// ── Validacija podpisa ─────────────────────────────────────────
function make_action_sig(int $id, string $action): string {
    return hash_hmac('sha256', "{$id}|{$action}", DB_PASS . 'admin-action-v1');
}

function render_result(string $icon, string $title, string $body): void {
    $appName = defined('APP_NAME') ? APP_NAME : 'Rezervacije';
    $appUrl  = (defined('APP_URL') ? APP_URL : '') . (defined('BASE_PATH') ? BASE_PATH : '');
    echo "<!DOCTYPE html><html lang='sl'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'>
    <title>{$title} – {$appName}</title>
    <link href='https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap' rel='stylesheet'>
    <style>
        body{margin:0;padding:0;background:#F9FAFB;font-family:Inter,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh}
        .card{background:#fff;border-radius:16px;padding:40px 48px;box-shadow:0 1px 4px rgba(0,0,0,.1);max-width:420px;width:90%;text-align:center}
        .icon{font-size:3rem;margin-bottom:16px}
        h1{font-size:1.3rem;font-weight:700;color:#111827;margin:0 0 10px}
        p{font-size:.9rem;color:#6B7280;line-height:1.6;margin:0 0 24px}
        a{display:inline-block;background:#1B4332;color:#fff;text-decoration:none;padding:10px 24px;border-radius:8px;font-size:.875rem;font-weight:600}
    </style></head><body>
    <div class='card'>
        <div class='icon'>{$icon}</div>
        <h1>{$title}</h1>
        <p>{$body}</p>
        <a href='{$appUrl}/pages/main.php'>Odpri razpored →</a>
    </div></body></html>";
    exit;
}

// Preveri parametre
if (!$id || !in_array($action, ['approve', 'reject'], true) || !$sig) {
    render_result('⚠️', 'Neveljavna povezava', 'Parametri manjkajo ali so nepravilni.');
}

// Preveri HMAC
if (!hash_equals(make_action_sig($id, $action), $sig)) {
    render_result('🔒', 'Neveljavna povezava', 'Podpis ni veljaven. Prosimo, uporabite originalni link iz emaila.');
}

$pdo = getDB();

// Naloži rezervacijo
$stmt = $pdo->prepare("
    SELECT r.*, res.name AS restaurant_name, res.reservation_duration AS restaurant_duration,
           res.contact_email, res.contact_phone
    FROM reservations r
    JOIN restaurants res ON r.restaurant_id = res.id
    WHERE r.id = ?
");
$stmt->execute([$id]);
$res = $stmt->fetch();

if (!$res) {
    render_result('🔗', 'Rezervacija ne obstaja', 'Rezervacija ni bila najdena.');
}

// Že obdelana?
if ($res['status'] !== 'pending') {
    $statusMap = ['confirmed' => 'že potrjena', 'rejected' => 'že zavrnjena', 'cancelled' => 'odpovedana'];
    $statusLabel = $statusMap[$res['status']] ?? 'obdelana';
    render_result('ℹ️', 'Rezervacija je ' . $statusLabel,
        'Ta rezervacija je bila ' . $statusLabel . ' in je ni mogoče ponovno obdelati.');
}

// ── Izvedi akcijo ──────────────────────────────────────────────
$date     = $res['reservation_date'];
$time     = substr($res['reservation_time'], 0, 5);
$duration = (int)($res['duration'] ?? $res['restaurant_duration'] ?? 60);
$cEmail   = $res['contact_email'] ?? '';
$cPhone   = $res['contact_phone'] ?? '';

if ($action === 'approve') {
    // Ustvari edit_token
    $editToken   = bin2hex(random_bytes(32));
    $editExpires = date('Y-m-d H:i:s', strtotime("{$date} {$time}") + 3600);
    try {
        $pdo->prepare("UPDATE reservations SET status = 'confirmed', edit_token = ?, edit_token_expires = ? WHERE id = ?")
            ->execute([$editToken, $editExpires, $id]);
    } catch (PDOException $e) {
        $pdo->prepare("UPDATE reservations SET status = 'confirmed' WHERE id = ?")->execute([$id]);
        $editToken = '';
    }

    if ($res['email']) {
        send_booking_confirmed_guest(
            $res['email'], $res['guest_name'], $res['restaurant_name'],
            $date, $time, (int)$res['guest_count'], $duration, $editToken, $cEmail, $cPhone,
            _resolve_email_lang()
        );
    }

    render_result('✅', 'Rezervacija potrjena',
        'Rezervacija za <strong>' . htmlspecialchars($res['guest_name']) . '</strong> (' .
        date('d. m. Y', strtotime($date)) . ' ob ' . $time . ') je bila potrjena.<br>Gost je bil obveščen.');
}

if ($action === 'reject') {
    $pdo->prepare("UPDATE reservations SET status = 'rejected' WHERE id = ?")->execute([$id]);

    if ($res['email']) {
        send_booking_rejected_guest(
            $res['email'], $res['guest_name'], $res['restaurant_name'],
            $date, $time, (int)$res['guest_count'], $cEmail, $cPhone,
            _resolve_email_lang()
        );
    }

    render_result('❌', 'Rezervacija zavrnjena',
        'Rezervacija za <strong>' . htmlspecialchars($res['guest_name']) . '</strong> (' .
        date('d. m. Y', strtotime($date)) . ' ob ' . $time . ') je bila zavrnjena.<br>Gost je bil obveščen.');
}
