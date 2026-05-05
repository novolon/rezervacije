<?php
/**
 * Javna stran za gostovo urejanje / odpoved rezervacije.
 * URL: /pages/reservation_edit.php?t={edit_token}[&action=cancel]
 * Brez prijave.
 */
require_once '../config.php';
require_once '../includes/lang.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';
require_once '../includes/waitlist_notifier.php';

$pdo   = getDB();
$token = trim($_GET['t'] ?? '');

// ── Naloži rezervacijo po tokenu ──────────────────────────────
$reservation = null;
$restaurant  = null;
$error       = '';
$done        = '';
$doneType    = ''; // 'edited' | 'cancelled'

if ($token) {
    $stmt = $pdo->prepare("
        SELECT r.*, res.name AS restaurant_name,
               res.allow_guest_edit,        res.guest_edit_cutoff_hours,
               res.allow_guest_cancel,      res.guest_cancel_cutoff_hours,
               res.reservation_duration,    res.booking_slot_interval,
               res.booking_open_days,       res.owner_id,
               res.booking_auto_confirm
        FROM reservations r
        JOIN restaurants res ON r.restaurant_id = res.id
        WHERE r.edit_token = ? AND r.edit_token_expires > NOW()
          AND r.status NOT IN ('cancelled','rejected')
    ");
    $stmt->execute([$token]);
    $reservation = $stmt->fetch();

    if (!$reservation) {
        $error = t('res_edit.err_invalid_link');
    }
}

// Kontaktni podatki restavracije (za prikaz na strani)
$restContactEmail = $reservation['contact_email'] ?? '';
$restContactPhone = $reservation['contact_phone'] ?? '';

// Če rezervacija ni bila naložena (neveljavna/potekla povezava), poskusi pridobiti
// kontakt restavracije iz tokena brez časovnih/statusnih omejitev
if (!$reservation && $token && !$restContactEmail && !$restContactPhone) {
    try {
        $fbStmt = $pdo->prepare("
            SELECT res.name AS restaurant_name, res.contact_email, res.contact_phone
            FROM reservations r
            JOIN restaurants res ON r.restaurant_id = res.id
            WHERE r.edit_token = ?
            LIMIT 1
        ");
        $fbStmt->execute([$token]);
        $fb = $fbStmt->fetch();
        if ($fb) {
            $restContactEmail = $fb['contact_email'] ?? '';
            $restContactPhone = $fb['contact_phone'] ?? '';
        }
    } catch (PDOException $e) { /* tiho */ }
}

function _contact_inline(string $email, string $phone): string {
    if (!$email && !$phone) return '';
    $parts = [];
    if ($email) $parts[] = '<a href="mailto:' . htmlspecialchars($email) . '" style="color:#1B4332;font-weight:600">' . htmlspecialchars($email) . '</a>';
    if ($phone) $parts[] = '<a href="tel:' . htmlspecialchars(preg_replace('/\s+/', '', $phone)) . '" style="color:#1B4332;font-weight:600">' . htmlspecialchars($phone) . '</a>';
    return '<div style="margin-top:8px;font-size:.875rem;color:#374151">' . t('res_edit.contact_label') . ': ' . implode(' · ', $parts) . '</div>';
}

// ── Naloži custom polja restavracije + obstoječe vrednosti ───
$customFields     = [];  // definicije polj
$customFieldValues = []; // id => vrednost

if ($reservation) {
    try {
        $cfStmt = $pdo->prepare("
            SELECT id, label, field_type, options, is_required
            FROM restaurant_custom_fields
            WHERE restaurant_id = ? AND applies_to IN ('public','both') AND is_active = 1
            ORDER BY sort_order, id
        ");
        $cfStmt->execute([(int)$reservation['restaurant_id']]);
        $customFields = $cfStmt->fetchAll();
        foreach ($customFields as &$cf) {
            $cf['options']     = $cf['options'] ? json_decode($cf['options'], true) : [];
            $cf['is_required'] = (bool)$cf['is_required'];
        }
        unset($cf);

        if ($customFields) {
            $vStmt = $pdo->prepare("
                SELECT field_id, value FROM reservation_field_values WHERE reservation_id = ?
            ");
            $vStmt->execute([(int)$reservation['id']]);
            foreach ($vStmt->fetchAll() as $row) {
                $customFieldValues[(int)$row['field_id']] = $row['value'];
            }
        }
    } catch (PDOException $e) { /* tabela morda še ne obstaja */ }
}

// ── Izračun ali je sprememba še dovoljena ─────────────────────
$canEdit   = false;
$canCancel = false;
$resDatetime = null;

if ($reservation) {
    $resDatetime = strtotime($reservation['reservation_date'] . ' ' . $reservation['reservation_time']);
    $hoursLeft   = ($resDatetime - time()) / 3600;

    $allowEdit   = (int)($reservation['allow_guest_edit']   ?? 1);
    $editCutoff  = (int)($reservation['guest_edit_cutoff_hours']   ?? 24);
    $allowCancel = (int)($reservation['allow_guest_cancel'] ?? 1);
    $cancelCutoff = (int)($reservation['guest_cancel_cutoff_hours'] ?? 4);

    $canEdit   = $allowEdit   && $hoursLeft > $editCutoff;
    $canCancel = $allowCancel && $hoursLeft > $cancelCutoff;
}

// ── POST – uredi ali odpovej ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reservation && !$error) {
    $postAction = trim($_POST['action'] ?? '');

    if ($postAction === 'cancel' && $canCancel) {
        $cancelReason = trim($_POST['cancel_reason'] ?? '');
        try {
            $pdo->prepare("
                UPDATE reservations
                SET status = 'cancelled', cancel_reason = ?,
                    edit_token = NULL, edit_token_expires = NULL
                WHERE id = ?
            ")->execute([$cancelReason ?: null, (int)$reservation['id']]);

            // Obvesti restavracijo
            $adminStmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
            $adminStmt->execute([(int)$reservation['owner_id']]);
            $admin = $adminStmt->fetch();
            if ($admin && $admin['email']) {
                _send_cancel_notify_admin(
                    $admin['email'], $reservation['restaurant_name'],
                    $reservation['guest_name'], $reservation['guest_email'] ?? $reservation['email'] ?? '',
                    $reservation['reservation_date'],
                    substr($reservation['reservation_time'], 0, 5),
                    (int)$reservation['guest_count'],
                    $cancelReason
                );
            }

            // Obvesti čakalno listo
            try {
                notify_waitlist($pdo, (int)$reservation['restaurant_id'], $reservation['reservation_date']);
            } catch (Throwable $e) { error_log('notify_waitlist error: ' . $e->getMessage()); }

            $done     = t('res_edit.done_cancelled');
            $doneType = 'cancelled';
            $reservation = null;
        } catch (PDOException $e) {
            error_log('Reservation cancel error: ' . $e->getMessage());
            $error = t('res_edit.err_generic');
        }

    } elseif ($postAction === 'edit' && $canEdit) {
        $newDate  = trim($_POST['reservation_date'] ?? '');
        $newTime  = trim($_POST['reservation_time'] ?? '');
        $newCount = max(1, (int)($_POST['guest_count'] ?? 1));
        $newNotes = trim($_POST['notes'] ?? '');

        // Validacija
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
            $error = t('res_edit.err_invalid_date');
        } elseif ($newDate < date('Y-m-d')) {
            $error = t('res_edit.err_past_date');
        } elseif (!preg_match('/^\d{2}:\d{2}$/', $newTime)) {
            $error = t('res_edit.err_invalid_time');
        } else {
            // Preveri cutoff za novi termin
            $newTs = strtotime("{$newDate} {$newTime}");
            if (($newTs - time()) / 3600 < $editCutoff) {
                $error = t('res_edit.err_cutoff', ['hours' => $editCutoff]);
            } else {
                // Blokiran datum?
                $blStmt = $pdo->prepare("SELECT 1 FROM restaurant_blackouts WHERE restaurant_id = ? AND blackout_date = ?");
                $blStmt->execute([(int)$reservation['restaurant_id'], $newDate]);
                if ($blStmt->fetchColumn()) {
                    $error = t('res_edit.err_blackout');
                } else {
                    try {
                        // Nov token z veljavnostjo do 1h po novem terminu
                        $newToken   = bin2hex(random_bytes(32));
                        $newExpires = date('Y-m-d H:i:s', $newTs + 3600);

                        // Če je bila rezervacija potrjena in restavracija zahteva ročno potrditev → vrni v pending
                        $revertToPending = ($reservation['status'] === 'confirmed') && !($reservation['booking_auto_confirm'] ?? 1);

                        $pdo->prepare("
                            UPDATE reservations
                            SET reservation_date = ?, reservation_time = ?,
                                guest_count = ?, notes = ?,
                                edit_token = ?, edit_token_expires = ?,
                                status = IF(? = 1, 'pending', status)
                            WHERE id = ?
                        ")->execute([
                            $newDate, $newTime . ':00',
                            $newCount, $newNotes ?: null,
                            $newToken, $newExpires,
                            $revertToPending ? 1 : 0,
                            (int)$reservation['id'],
                        ]);

                        // Shrani custom field vrednosti
                        if (!empty($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
                            try {
                                $cfCheck = $pdo->prepare("SELECT id FROM restaurant_custom_fields WHERE id = ? AND restaurant_id = ? AND applies_to IN ('public','both') AND is_active = 1");
                                $cfIns   = $pdo->prepare("INSERT INTO reservation_field_values (reservation_id, field_id, value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
                                foreach ($_POST['custom_fields'] as $fieldId => $value) {
                                    $fid = (int)$fieldId;
                                    $cfCheck->execute([$fid, (int)$reservation['restaurant_id']]);
                                    if ($cfCheck->fetchColumn()) {
                                        $cfIns->execute([(int)$reservation['id'], $fid, trim((string)$value)]);
                                    }
                                }
                            } catch (PDOException $e) { /* tiho */ }
                        }

                        // Obvesti restavracijo
                        $adminStmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
                        $adminStmt->execute([(int)$reservation['owner_id']]);
                        $admin = $adminStmt->fetch();
                        if ($admin && $admin['email']) {
                            _send_edit_notify_admin(
                                $admin['email'], $reservation['restaurant_name'],
                                $reservation['guest_name'],
                                $reservation['reservation_date'],
                                substr($reservation['reservation_time'], 0, 5),
                                $newDate, $newTime, $newCount
                            );
                        }

                        // Obvestilo gostu
                        $guestEmail = $reservation['email'] ?? '';
                        if ($guestEmail) {
                            _send_edit_confirm_guest(
                                $guestEmail, $reservation['guest_name'],
                                $reservation['restaurant_name'],
                                $reservation['reservation_date'],
                                substr($reservation['reservation_time'], 0, 5),
                                $newDate, $newTime, $newCount,
                                $newToken
                            );
                        }

                        $done     = t('res_edit.done_edited');
                        $doneType = 'edited';
                        $reservation = null;
                    } catch (PDOException $e) {
                        error_log('Reservation edit error: ' . $e->getMessage());
                        $error = t('res_edit.err_generic');
                    }
                }
            }
        }
    }
}

// ── Pomožni emaili (admin obvestilo) ─────────────────────────
function _send_cancel_notify_admin(string $toEmail, string $restName, string $guestName, string $guestEmail, string $date, string $time, int $guests, string $reason): void {
    $appName  = APP_NAME;
    $dateF    = date('d. m. Y', strtotime($date));
    $dayNames = ['Ponedeljek','Torek','Sreda','Četrtek','Petek','Sobota','Nedelja'];
    $dayF     = $dayNames[date('N', strtotime($date)) - 1];
    $reasonHtml = $reason ? '<p style="margin:0 0 8px;font-size:.875rem;color:#374151">Razlog: <em>' . htmlspecialchars($reason) . '</em></p>' : '';
    $appLink  = APP_URL . BASE_PATH . '/pages/main.php';

    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#111827'>Rezervacija odpovedana</p>
        <p style='margin:0 0 16px;font-size:.875rem;color:#6B7280'>Gost je odpovedal rezervacijo.</p>
        <div style='background:#FEF2F2;border-radius:8px;padding:16px 20px;margin-bottom:16px;border-left:3px solid #EF4444'>
            <div style='font-weight:600;color:#111827;margin-bottom:4px'>" . htmlspecialchars($guestName) . " (" . htmlspecialchars($guestEmail) . ")</div>
            <div style='font-size:.875rem;color:#374151'>{$dayF}, {$dateF} ob {$time} · {$guests} " . ($guests === 1 ? 'gost' : ($guests < 5 ? 'gostje' : 'gostov')) . "</div>
        </div>
        {$reasonHtml}
        <p style='margin:20px 0 0'><a href='{$appLink}' style='background:#1B4332;color:#fff;text-decoration:none;padding:11px 24px;border-radius:8px;font-weight:600;font-size:.9rem;display:inline-block'>Odpri razpored →</a></p>";

    $html = email_wrap($appName, $body, $appName . ' · Odpoved rezervacije');
    $text = "Rezervacija odpovedana\n{$guestName} ({$guestEmail})\n{$date} ob {$time}, {$guests} gostov" . ($reason ? "\nRazlog: {$reason}" : '');
    send_email($toEmail, "Odpoved rezervacije – {$restName}", $html, $text);
}

function _send_edit_notify_admin(string $toEmail, string $restName, string $guestName, string $oldDate, string $oldTime, string $newDate, string $newTime, int $newCount): void {
    $appName  = APP_NAME;
    $oldDateF = date('d. m. Y', strtotime($oldDate));
    $newDateF = date('d. m. Y', strtotime($newDate));
    $appLink  = APP_URL . BASE_PATH . '/pages/main.php';

    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#111827'>Rezervacija spremenjena</p>
        <p style='margin:0 0 16px;font-size:.875rem;color:#6B7280'>Gost je spremenil rezervacijo.</p>
        <div style='background:#F9FAFB;border-radius:8px;padding:16px 20px;margin-bottom:12px'>
            <div style='font-size:.8rem;color:#6B7280;margin-bottom:4px'>Gost</div>
            <div style='font-weight:600;color:#111827'>" . htmlspecialchars($guestName) . "</div>
        </div>
        <table style='width:100%;border-collapse:collapse;margin-bottom:16px;font-size:.875rem'>
            <tr>
                <td style='padding:6px 0;color:#6B7280;width:80px'>Prej:</td>
                <td style='padding:6px 0;color:#374151'>{$oldDateF} ob {$oldTime}</td>
            </tr>
            <tr>
                <td style='padding:6px 0;color:#6B7280'>Sedaj:</td>
                <td style='padding:6px 0;font-weight:600;color:#065F46'>{$newDateF} ob {$newTime} · {$newCount} " . ($newCount === 1 ? 'gost' : ($newCount < 5 ? 'gostje' : 'gostov')) . "</td>
            </tr>
        </table>
        <p style='margin:0'><a href='{$appLink}' style='background:#1B4332;color:#fff;text-decoration:none;padding:11px 24px;border-radius:8px;font-weight:600;font-size:.9rem;display:inline-block'>Odpri razpored →</a></p>";

    $html = email_wrap($appName, $body, $appName . ' · Sprememba rezervacije');
    $text = "Rezervacija spremenjena\n{$guestName}\nPrej: {$oldDate} ob {$oldTime}\nSedaj: {$newDate} ob {$newTime}, {$newCount} gostov";
    send_email($toEmail, "Sprememba rezervacije – {$restName}", $html, $text);
}

function _send_edit_confirm_guest(string $toEmail, string $guestName, string $restName, string $oldDate, string $oldTime, string $newDate, string $newTime, int $newCount, string $editToken): void {
    $appName  = APP_NAME;
    $dayNames = ['Ponedeljek','Torek','Sreda','Četrtek','Petek','Sobota','Nedelja'];
    $oldDateF = $dayNames[date('N', strtotime($oldDate)) - 1] . ', ' . date('d. m. Y', strtotime($oldDate));
    $newDateF = $dayNames[date('N', strtotime($newDate)) - 1] . ', ' . date('d. m. Y', strtotime($newDate));
    $guestsLbl = $newCount === 1 ? 'gost' : ($newCount < 5 ? 'gostje' : 'gostov');

    $editLinks = '';
    if ($editToken) {
        $editUrl   = htmlspecialchars(APP_URL . BASE_PATH . '/pages/reservation_edit.php?t=' . urlencode($editToken));
        $cancelUrl = htmlspecialchars(APP_URL . BASE_PATH . '/pages/reservation_edit.php?t=' . urlencode($editToken) . '&action=cancel');
        $editLinks = "
        <div style='margin:20px 0;padding:16px 20px;background:#F9FAFB;border-radius:8px;border:1px solid #E5E7EB'>
            <div style='font-size:13px;color:#6B7280;margin-bottom:10px'>Upravljanje rezervacije:</div>
            <a href='{$editUrl}' style='display:inline-block;background:#1B4332;color:#fff;text-decoration:none;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;margin-right:8px;margin-bottom:6px'>Uredi rezervacijo</a>
            <a href='{$cancelUrl}' style='display:inline-block;background:#fff;color:#DC2626;text-decoration:none;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;border:1.5px solid #FCA5A5;margin-bottom:6px'>Odpovem rezervacijo</a>
        </div>";
    }

    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#111827'>Pozdravljeni, " . htmlspecialchars($guestName) . "!</p>
        <p style='margin:0 0 20px;font-size:14px;color:#6B7280;line-height:1.6'>Vaša rezervacija je bila uspešno spremenjena.</p>
        <table style='width:100%;border-collapse:collapse;margin-bottom:20px;font-size:.875rem;background:#F9FAFB;border-radius:8px'>
            <tr>
                <td style='padding:10px 16px;color:#6B7280;width:90px'>Prej:</td>
                <td style='padding:10px 16px;color:#374151'>{$oldDateF} ob {$oldTime}</td>
            </tr>
            <tr style='border-top:1px solid #E5E7EB'>
                <td style='padding:10px 16px;color:#6B7280'>Sedaj:</td>
                <td style='padding:10px 16px;font-weight:600;color:#065F46'>{$newDateF} ob {$newTime} · {$newCount} {$guestsLbl}</td>
            </tr>
            <tr style='border-top:1px solid #E5E7EB'>
                <td style='padding:10px 16px;color:#6B7280'>Restavracija:</td>
                <td style='padding:10px 16px;color:#111827'>" . htmlspecialchars($restName) . "</td>
            </tr>
        </table>
        {$editLinks}
        <p style='margin:0;font-size:13px;color:#9CA3AF'>Če imate vprašanja, nas kontaktirajte neposredno v restavraciji.</p>";

    $html = email_wrap($appName, $body, $appName . ' · Potrditev spremembe rezervacije');
    $text = "Pozdravljeni {$guestName},\nVaša rezervacija v {$restName} je bila spremenjena.\nPrej: {$oldDate} ob {$oldTime}\nSedaj: {$newDate} ob {$newTime}, {$newCount} {$guestsLbl}";
    send_email($toEmail, "Rezervacija spremenjena – {$restName}", $html, $text);
}

// ── Pridobi proste termine za izbrani datum (AJAX) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['slots_for'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$reservation) { echo json_encode(['slots' => []]); exit; }

    $sDate = trim($_GET['slots_for']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sDate) || $sDate < date('Y-m-d')) {
        echo json_encode(['slots' => []]); exit;
    }

    $restId   = (int)$reservation['restaurant_id'];
    $dow      = (int)date('N', strtotime($sDate)) - 1;

    // Per-day schedule
    $dsStmt = $pdo->prepare("SELECT is_open, start_time, end_time FROM restaurant_day_schedules WHERE restaurant_id = ? AND day_of_week = ?");
    $dsStmt->execute([$restId, $dow]);
    $ds = $dsStmt->fetch();
    if (!$ds) {
        $openDays = (int)$reservation['booking_open_days'];
        $ds = [
            'is_open'    => (($openDays >> $dow) & 1) ? 1 : 0,
            'start_time' => (int)$reservation['schedule_start'],
            'end_time'   => (int)$reservation['schedule_end'],
        ];
    }
    if (!$ds['is_open']) { echo json_encode(['slots' => [], 'reason' => 'closed']); exit; }

    // Blokiran datum?
    $blStmt = $pdo->prepare("SELECT 1 FROM restaurant_blackouts WHERE restaurant_id = ? AND blackout_date = ?");
    $blStmt->execute([$restId, $sDate]);
    if ($blStmt->fetchColumn()) { echo json_encode(['slots' => [], 'reason' => 'blackout']); exit; }

    $interval = max(15, (int)($reservation['booking_slot_interval'] ?? $reservation['reservation_duration']));
    $slots = [];
    for ($m = (int)$ds['start_time']; $m + $interval <= (int)$ds['end_time']; $m += $interval) {
        $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
    echo json_encode(['slots' => $slots]); exit;
}

// ── Formatiranje datuma ───────────────────────────────────────
function fmt_date(string $d): string {
    $names = array_map(fn($i) => t_raw('days.' . $i), range(0, 6));
    return $names[date('N', strtotime($d)) - 1] . ', ' . date('d. m. Y', strtotime($d));
}
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
    <title><?= t('res_edit.page_title') ?> – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/login.css">
    <style>
        body { background: #F9FAFB; font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .wrap { max-width: 560px; margin: 0 auto; padding: 32px 20px 80px; }
        .logo { display:flex; align-items:center; gap:10px; margin-bottom:32px; text-decoration:none; color:#111827; }
        .logo-icon { background:#F59E0B; border-radius:10px; width:36px; height:36px; display:flex; align-items:center; justify-content:center; }
        .logo-icon svg { display:block; }
        .logo span { font-size:1.1rem; font-weight:700; }
        .card { background:#fff; border-radius:16px; padding:36px 40px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
        @media(max-width:600px) { .card { padding:24px 20px; } }
        .card h1 { font-size:1.3rem; font-weight:700; margin:0 0 6px; color:#111827; }
        .card .sub { color:#6B7280; font-size:.875rem; margin:0 0 24px; line-height:1.5; }
        .booking-box { background:#F9FAFB; border-radius:10px; padding:16px 20px; margin-bottom:24px; border-left:3px solid #1B4332; }
        .booking-box .rest { font-size:15px; font-weight:600; color:#111827; margin-bottom:2px; }
        .booking-box .dt { font-size:.875rem; color:#374151; }
        .booking-box .gs { font-size:.8rem; color:#6B7280; margin-top:4px; }
        .section-sep { border:none; border-top:1px solid #F3F4F6; margin:24px 0; }
        .action-tabs { display:flex; gap:8px; margin-bottom:24px; }
        .action-tab { flex:1; padding:10px; border-radius:8px; border:1.5px solid #E5E7EB; background:#fff; font-size:.875rem; font-weight:600; color:#374151; cursor:pointer; font-family:inherit; transition:all .15s; text-align:center; }
        .action-tab.active { border-color:#1B4332; background:#F0FDF4; color:#1B4332; }
        .action-tab.cancel-tab.active { border-color:#EF4444; background:#FEF2F2; color:#DC2626; }
        .panel { display:none; } .panel.active { display:block; }
        .form-group { margin-bottom:16px; }
        .form-group label { display:block; font-size:.8rem; font-weight:600; color:#374151; margin-bottom:6px; letter-spacing:.03em; }
        .form-group input, .form-group select, .form-group textarea {
            width:100%; padding:10px 12px; border:1.5px solid #E5E7EB; border-radius:8px;
            font-size:.875rem; font-family:inherit; color:#111827; box-sizing:border-box;
            transition:border-color .15s; background:#fff;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline:none; border-color:#1B4332; box-shadow:0 0 0 3px rgba(27,67,50,.08);
        }
        .form-group textarea { resize:vertical; min-height:80px; }
        .slots-wrap { display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
        .slot-btn { padding:7px 14px; border-radius:8px; border:1.5px solid #E5E7EB; background:#fff; font-size:.825rem; font-weight:600; font-family:inherit; cursor:pointer; transition:all .15s; color:#374151; }
        .slot-btn:hover { border-color:#1B4332; color:#1B4332; }
        .slot-btn.selected { background:#1B4332; color:#fff; border-color:#1B4332; }
        .radio-group { display:flex; flex-direction:column; gap:8px; margin-top:6px; }
        .radio-row { display:flex; align-items:center; gap:8px; font-size:.875rem; color:#374151; cursor:pointer; }
        .radio-row input[type=radio] { accent-color:#DC2626; width:16px; height:16px; cursor:pointer; }
        .btn-primary { background:#1B4332; color:#fff; border:none; padding:.7rem 1.6rem; border-radius:8px; font-size:.875rem; font-weight:600; font-family:inherit; cursor:pointer; transition:background .15s; }
        .btn-primary:hover { background:#143728; }
        .btn-danger { background:#DC2626; color:#fff; border:none; padding:.7rem 1.6rem; border-radius:8px; font-size:.875rem; font-weight:600; font-family:inherit; cursor:pointer; transition:background .15s; }
        .btn-danger:hover { background:#B91C1C; }
        .err-msg { background:#FEF2F2; color:#DC2626; border-radius:8px; padding:12px 16px; font-size:.875rem; margin-bottom:20px; border:1px solid #FECACA; }
        .done-card { text-align:center; padding:8px 0 16px; }
        .done-icon { font-size:3rem; margin-bottom:12px; }
        .done-title { font-size:1.1rem; font-weight:700; color:#111827; margin:0 0 8px; }
        .done-sub { color:#6B7280; font-size:.875rem; line-height:1.6; }
        .not-allowed-msg { background:#FFF7ED; border:1px solid #FED7AA; border-radius:8px; padding:14px 18px; font-size:.875rem; color:#92400E; }
        .cutoff-info { font-size:.8rem; color:#6B7280; margin-top:4px; }
        #slots-loading { font-size:.8rem; color:#6B7280; }
    </style>
    <script>
    window.__T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
    window.t = function(k, p) { var s = window.__T__[k] || k; if (p) { for (var x in p) s = s.split('{'+x+'}').join(p[x]); } return s; };
    </script>
</head>
<body>
<div class="wrap">
    <!-- Logo -->
    <a class="logo" href="<?= BASE_PATH ?>/">
        <div class="logo-icon">
            <svg width="20" height="20" viewBox="0 0 40 40" fill="none">
                <path d="M10 14h20M10 20h20M10 26h12" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
        </div>
        <span><?= h(APP_NAME) ?></span>
    </a>

    <div class="card">

    <?php if ($done): ?>
        <!-- Uspešno -->
        <div class="done-card">
            <div class="done-icon"><?= $doneType === 'cancelled' ? '❌' : '✅' ?></div>
            <h2 class="done-title"><?= $doneType === 'cancelled' ? t('res_edit.done_title_cancelled') : t('res_edit.done_title_edited') ?></h2>
            <p class="done-sub"><?= h($done) ?><br><br><?= t('res_edit.done_notified') ?></p>
        </div>

    <?php elseif ($error && !$reservation): ?>
        <!-- Napaka / neveljavna povezava -->
        <div style="text-align:center;padding:8px 0 16px">
            <div style="font-size:2.5rem;margin-bottom:12px">🔗</div>
            <h1><?= t('res_edit.err_title') ?></h1>
            <p class="sub" style="margin:0"><?= h($error) ?></p>
            <?php if ($restContactEmail || $restContactPhone): ?>
            <div style="margin-top:16px;font-size:.875rem;color:#374151">
                <?= _contact_inline($restContactEmail, $restContactPhone) ?>
            </div>
            <?php endif; ?>
        </div>

    <?php elseif ($reservation): ?>
        <h1><?= t('res_edit.main_title') ?></h1>
        <p class="sub"><?= t('res_edit.main_sub') ?></p>

        <?php if ($error): ?>
            <div class="err-msg"><?= h($error) ?></div>
        <?php endif; ?>

        <!-- Povzetek rezervacije -->
        <div class="booking-box">
            <div class="rest"><?= h($reservation['restaurant_name']) ?></div>
            <div class="dt"><?= h(fmt_date($reservation['reservation_date'])) ?> ob <?= h(substr($reservation['reservation_time'], 0, 5)) ?></div>
            <?php $gc = (int)$reservation['guest_count']; $gcLbl = $gc === 1 ? t('res_edit.guest_1') : ($gc < 5 ? t('res_edit.guest_few') : t('res_edit.guest_many')); ?>
            <div class="gs"><?= $gc ?> <?= $gcLbl ?> · <?= h($reservation['guest_name']) ?></div>
        </div>

        <?php if (!$canEdit && !$canCancel): ?>
            <div class="not-allowed-msg">
                <?= t('res_edit.no_edit_msg') ?><br>
                <?= t('res_edit.no_edit_sub') ?>
                <?= _contact_inline($restContactEmail, $restContactPhone) ?>
            </div>

        <?php else: ?>
            <!-- Tabs: Uredi / Odpovej -->
            <div class="action-tabs">
                <?php if ($canEdit): ?>
                <button class="action-tab active" id="tab-edit" onclick="switchTab('edit')"><?= t('res_edit.tab_edit') ?></button>
                <?php endif; ?>
                <?php if ($canCancel): ?>
                <button class="action-tab cancel-tab <?= !$canEdit ? 'active' : '' ?>" id="tab-cancel" onclick="switchTab('cancel')"><?= t('res_edit.tab_cancel') ?></button>
                <?php endif; ?>
            </div>

            <?php if ($canEdit): ?>
            <!-- Panel: Uredi -->
            <div id="panel-edit" class="panel active">
                <?php if (($reservation['status'] ?? '') === 'confirmed' && !($reservation['booking_auto_confirm'] ?? 1)): ?>
                <div style="background:#FFF7ED;border:1px solid #FED7AA;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:.875rem;color:#92400E;line-height:1.5">
                    <?= t('res_edit.pending_warning') ?>
                </div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="edit">

                    <div class="form-group">
                        <label><?= t('res_edit.label_date') ?></label>
                        <input type="date" name="reservation_date" id="inp-date"
                               value="<?= h($reservation['reservation_date']) ?>"
                               min="<?= date('Y-m-d', strtotime('+' . (int)$reservation['guest_edit_cutoff_hours'] . ' hours')) ?>"
                               required>
                        <div class="cutoff-info"><?= t('res_edit.cutoff_info', ['hours' => (int)$reservation['guest_edit_cutoff_hours']]) ?></div>
                    </div>

                    <div class="form-group">
                        <label><?= t('res_edit.label_time') ?></label>
                        <div id="slots-loading" style="display:none"><?= t('res_edit.loading_slots') ?></div>
                        <div class="slots-wrap" id="slots-wrap"></div>
                        <input type="hidden" name="reservation_time" id="inp-time" value="<?= h(substr($reservation['reservation_time'], 0, 5)) ?>">
                    </div>

                    <div class="form-group">
                        <label><?= t('res_edit.label_guests') ?></label>
                        <select name="guest_count" required>
                            <?php for ($i = 1; $i <= 20; $i++): ?>
                                <option value="<?= $i ?>" <?= $i === (int)$reservation['guest_count'] ? 'selected' : '' ?>><?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><?= t('res_edit.label_notes') ?></label>
                        <textarea name="notes" placeholder="<?= t('res_edit.notes_placeholder') ?>"><?= h($reservation['notes'] ?? '') ?></textarea>
                    </div>

                    <?php foreach ($customFields as $cf): ?>
                    <div class="form-group">
                        <label><?= h($cf['label']) ?><?= $cf['is_required'] ? ' <span style="color:#EF4444">*</span>' : '' ?></label>
                        <?php
                        $cfVal = $customFieldValues[(int)$cf['id']] ?? '';
                        $cfName = 'custom_fields[' . (int)$cf['id'] . ']';
                        if ($cf['field_type'] === 'select' && $cf['options']): ?>
                            <select name="<?= $cfName ?>" <?= $cf['is_required'] ? 'required' : '' ?>>
                                <option value=""><?= t('res_edit.select_placeholder') ?></option>
                                <?php foreach ($cf['options'] as $opt): ?>
                                    <option value="<?= h($opt) ?>" <?= $cfVal === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($cf['field_type'] === 'checkbox'): ?>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400">
                                <input type="checkbox" name="<?= $cfName ?>" value="1" <?= $cfVal ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:#1B4332">
                                <?= t('res_edit.checkbox_yes') ?>
                            </label>
                        <?php else: ?>
                            <input type="text" name="<?= $cfName ?>" value="<?= h($cfVal) ?>" <?= $cf['is_required'] ? 'required' : '' ?>>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <button type="submit" class="btn-primary"><?= t('res_edit.btn_save') ?></button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($canCancel): ?>
            <!-- Panel: Odpovej -->
            <div id="panel-cancel" class="panel <?= !$canEdit ? 'active' : '' ?>">
                <p style="margin:0 0 16px;font-size:.875rem;color:#374151;line-height:1.6">
                    <?= t('res_edit.cancel_confirm_text') ?><br>
                    <?= t('res_edit.cancel_confirm_sub') ?>
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="cancel">
                    <div class="form-group">
                        <label><?= t('res_edit.label_cancel_reason') ?></label>
                        <div class="radio-group">
                            <label class="radio-row"><input type="radio" name="cancel_reason" value="Sprememba načrtov"> <?= t('res_edit.reason_plans') ?></label>
                            <label class="radio-row"><input type="radio" name="cancel_reason" value="Bolezen"> <?= t('res_edit.reason_illness') ?></label>
                            <label class="radio-row"><input type="radio" name="cancel_reason" value="Napačen datum ali čas"> <?= t('res_edit.reason_wrong_dt') ?></label>
                            <label class="radio-row"><input type="radio" name="cancel_reason" value="Drugo"> <?= t('res_edit.reason_other') ?></label>
                        </div>
                    </div>
                    <button type="submit" class="btn-danger" onclick="return confirm(window.t('res_edit.cancel_confirm_text'))">
                        <?= t('res_edit.btn_cancel_confirm') ?>
                    </button>
                </form>
                <?php if ($restContactEmail || $restContactPhone): ?>
                <p style="margin:16px 0 0;font-size:.825rem;color:#6B7280">
                    <?= t('res_edit.contact_question') ?> <?= _contact_inline($restContactEmail, $restContactPhone) ?>
                </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        <?php endif; ?>

    <?php else: ?>
        <!-- Ni tokena -->
        <div style="text-align:center;padding:8px 0 16px">
            <div style="font-size:2.5rem;margin-bottom:12px">📋</div>
            <h1><?= t('res_edit.no_token_title') ?></h1>
            <p class="sub" style="margin:0"><?= t('res_edit.no_token_sub') ?></p>
        </div>
    <?php endif; ?>

    </div><!-- /.card -->
</div><!-- /.wrap -->

<script>
function switchTab(tab) {
    document.querySelectorAll('.action-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    const tabEl = document.getElementById('tab-' + tab);
    const panelEl = document.getElementById('panel-' + tab);
    if (tabEl) tabEl.classList.add('active');
    if (panelEl) panelEl.classList.add('active');
}

// Dinamično nalaganje terminov ob spremembi datuma
const inpDate = document.getElementById('inp-date');
const slotsWrap = document.getElementById('slots-wrap');
const inpTime = document.getElementById('inp-time');
const slotsLoading = document.getElementById('slots-loading');

if (inpDate) {
    inpDate.addEventListener('change', function() {
        const date = this.value;
        if (!date) return;
        loadSlots(date);
    });
    // Ob nalaganju takoj prikaži vse termine za obstoječi datum
    if (inpDate.value) loadSlots(inpDate.value);
}

function loadSlots(date) {
    slotsWrap.innerHTML = '';
    slotsLoading.style.display = 'block';
    fetch('<?= BASE_PATH ?>/pages/reservation_edit.php?t=<?= urlencode($token) ?>&slots_for=' + encodeURIComponent(date))
        .then(r => r.json())
        .then(data => {
            slotsLoading.style.display = 'none';
            if (!data.slots || !data.slots.length) {
                slotsWrap.innerHTML = '<span style="font-size:.8rem;color:#6B7280">' + window.t('res_edit.no_slots') + '</span>';
                inpTime.value = '';
                return;
            }
            const currentTime = '<?= h(substr($reservation['reservation_time'] ?? '', 0, 5)) ?>';
            data.slots.forEach(slot => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'slot-btn' + (slot === currentTime ? ' selected' : '');
                btn.dataset.time = slot;
                btn.textContent = slot;
                btn.onclick = function() {
                    document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
                    this.classList.add('selected');
                    inpTime.value = this.dataset.time;
                };
                slotsWrap.appendChild(btn);
            });
            // Nastavi čas na prvega če ni bil izbran
            if (!inpTime.value && data.slots.length) {
                inpTime.value = data.slots[0];
                slotsWrap.querySelector('.slot-btn')?.classList.add('selected');
            }
        })
        .catch(() => {
            slotsLoading.style.display = 'none';
            slotsWrap.innerHTML = '<span style="font-size:.8rem;color:#DC2626">' + window.t('res_edit.err_slots') + '</span>';
        });
}

</script>
</body>
</html>
