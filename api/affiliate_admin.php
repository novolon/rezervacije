<?php
/**
 * Affiliate Admin API – samo za superadmin.
 */
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth_check.php';
require_once '../includes/affiliate_helper.php';
require_once '../includes/discount_helper.php';
require_once '../includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_superadmin();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];
$body    = $method === 'POST' ? get_body() : [];
$action  = $body['action'] ?? ($_GET['action'] ?? '');

// ─── GET: seznam affiliatov ──────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $status = $_GET['status'] ?? '';
    $where  = in_array($status, ['pending','active','suspended','rejected'])
        ? "WHERE a.status = " . $pdo->quote($status)
        : '';

    $stmt = $pdo->query("
        SELECT a.id, a.ref_code, a.email, a.full_name, a.status,
               a.commission_percent, a.hold_days, a.commission_window_months,
               a.discount_enabled, a.discount_percent, a.discount_duration,
               a.discount_duration_months, a.created_at, a.approved_at,
               (SELECT COALESCE(SUM(c.amount_eur),0) FROM affiliate_commissions c WHERE c.affiliate_id = a.id AND c.status = 'payable') AS payable_eur,
               (SELECT COUNT(*) FROM affiliate_referrals r WHERE r.affiliate_id = a.id) AS referral_count,
               dc.code AS discount_code
        FROM affiliates a
        LEFT JOIN discount_codes dc ON dc.id = a.discount_code_id
        {$where}
        ORDER BY a.created_at DESC
        LIMIT 500
    ");
    $rows = $stmt->fetchAll();

    $resp = ['items' => $rows];
    if (!empty($_GET['settings'])) {
        $settings = $pdo->query("SELECT setting_key, setting_value FROM affiliate_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $resp['settings'] = $settings;
    }
    json_response(true, $resp);
}

// ─── GET: global nastavitve ──────────────────────────────────────
if ($method === 'GET' && $action === 'settings') {
    $settings = $pdo->query("SELECT setting_key, setting_value FROM affiliate_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    json_response(true, $settings);
}

// ─── GET: detail affiliata ───────────────────────────────────────
if ($method === 'GET' && $action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_response(false, null, 'Manjka id.', 400);

    $aff = affiliate_get($pdo, $id);
    if (!$aff) json_response(false, null, 'Affiliate ne obstaja.', 404);

    $referrals = $pdo->prepare("
        SELECT r.*, u.full_name AS user_name, u.email AS user_email, u.created_at AS user_registered
        FROM affiliate_referrals r JOIN users u ON u.id = r.user_id
        WHERE r.affiliate_id = ?
        ORDER BY r.created_at DESC
    ");
    $referrals->execute([$id]);

    $commissions = $pdo->prepare("
        SELECT * FROM affiliate_commissions WHERE affiliate_id = ? ORDER BY created_at DESC LIMIT 100
    ");
    $commissions->execute([$id]);

    $payouts = $pdo->prepare("
        SELECT * FROM affiliate_payouts WHERE affiliate_id = ? ORDER BY created_at DESC LIMIT 50
    ");
    $payouts->execute([$id]);

    $discCode = null;
    if ($aff['discount_code_id']) {
        $dc = $pdo->prepare("SELECT * FROM discount_codes WHERE id = ?");
        $dc->execute([$aff['discount_code_id']]);
        $discCode = $dc->fetch() ?: null;
    }

    // Merge discount_code fields into flat response so JS can access aff.discount_code, aff.discount_percent etc.
    $data = $aff;
    if ($discCode) {
        $data['discount_code']           = $discCode['code'];
        $data['discount_percent']        = $discCode['percent_off'];
        $data['discount_duration']       = $discCode['duration'];
        $data['discount_duration_months']= $discCode['duration_months'];
    }
    $data['referrals']   = $referrals->fetchAll();
    $data['commissions'] = $commissions->fetchAll();
    $data['payouts']     = $payouts->fetchAll();
    json_response(true, $data);
}

// ─── POST: approve ────────────────────────────────────────────────
if ($method === 'POST' && $action === 'approve') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_response(false, null, 'Manjka id.', 400);

    $aff = affiliate_get($pdo, $id);
    if (!$aff) json_response(false, null, 'Affiliate ne obstaja.', 404);
    if (!in_array($aff['status'], ['pending','suspended','rejected'])) {
        json_response(false, null, 'Affiliate je že aktiven.', 400);
    }

    $pdo->prepare("UPDATE affiliates SET status = 'active', approved_by = ?, approved_at = NOW() WHERE id = ?")
        ->execute([(int)$session['user_id'], $id]);

    // Email samo ob prvem odobravanju (ne ob reaktivaciji)
    if ($aff['status'] === 'pending') {
        send_affiliate_approved_email($aff['email'], $aff['full_name'], $aff['ref_code']);
    }
    json_response(true, null, 'Affiliate aktiviran.');
}

// ─── POST: reject ─────────────────────────────────────────────────
if ($method === 'POST' && $action === 'reject') {
    $id     = (int)($body['id'] ?? 0);
    $reason = substr($body['reason'] ?? '', 0, 255);
    if (!$id) json_response(false, null, 'Manjka id.', 400);

    $aff = affiliate_get($pdo, $id);
    if (!$aff) json_response(false, null, 'Affiliate ne obstaja.', 404);

    $pdo->prepare("UPDATE affiliates SET status = 'rejected', rejected_reason = ? WHERE id = ?")
        ->execute([$reason, $id]);

    send_affiliate_rejected_email($aff['email'], $aff['full_name'], $reason);
    json_response(true, null, 'Affiliate zavrnjen.');
}

// ─── POST: suspend ────────────────────────────────────────────────
if ($method === 'POST' && $action === 'suspend') {
    $id     = (int)($body['id'] ?? 0);
    $reason = substr($body['reason'] ?? '', 0, 255);
    if (!$id) json_response(false, null, 'Manjka id.', 400);

    $pdo->prepare("UPDATE affiliates SET status = 'suspended', rejected_reason = ? WHERE id = ?")
        ->execute([$reason, $id]);
    json_response(true, null, 'Affiliate suspendiran.');
}

// ─── POST: configure (provizija, window) ─────────────────────────
if ($method === 'POST' && $action === 'configure') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_response(false, null, 'Manjka id.', 400);

    $fields = [];
    $params = [];
    $allowed = ['commission_percent','commission_flat_eur','commission_window_months','hold_days','min_payout_eur'];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) {
            $fields[] = "{$f} = ?";
            $params[]  = $body[$f] === null ? null : (float)$body[$f];
        }
    }
    if (!$fields) json_response(false, null, 'Ni polj za posodobitev.', 400);
    $params[] = $id;

    $pdo->prepare("UPDATE affiliates SET " . implode(', ', $fields) . " WHERE id = ?")
        ->execute($params);
    json_response(true, null, 'Konfiguracija posodobljena.');
}

// ─── POST: grant_discount ─────────────────────────────────────────
if ($method === 'POST' && $action === 'grant_discount') {
    $id       = (int)($body['id'] ?? 0);
    $percent  = (float)($body['percent_off'] ?? $body['percent'] ?? 0);
    $duration = in_array($body['duration'] ?? '', ['once','repeating','forever']) ? $body['duration'] : 'once';
    $months   = ($duration === 'repeating' && isset($body['duration_months'])) ? (int)$body['duration_months']
              : ($duration === 'repeating' && isset($body['months']) ? (int)$body['months'] : null);

    if (!$id || $percent <= 0 || $percent > 100) json_response(false, null, 'Neveljavni parametri.', 400);

    try {
        $result = grant_affiliate_discount($pdo, $id, $percent, $duration, $months, (int)$session['user_id']);
        json_response(true, $result, 'Popustna koda ustvarjena.');
    } catch (RuntimeException $e) {
        json_response(false, null, $e->getMessage(), 500);
    }
}

// ─── POST: revoke_discount ────────────────────────────────────────
if ($method === 'POST' && $action === 'revoke_discount') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_response(false, null, 'Manjka id.', 400);

    revoke_affiliate_discount($pdo, $id);
    json_response(true, null, 'Popustna koda onemogočena.');
}

// ─── POST: create_payout_batch ───────────────────────────────────
if ($method === 'POST' && $action === 'create_payout_batch') {
    $pdo->beginTransaction();
    try {
        $rows = $pdo->query("
            SELECT a.id, a.full_name, a.iban, a.min_payout_eur,
                   COALESCE(SUM(c.amount_eur), 0) AS total
            FROM affiliates a
            JOIN affiliate_commissions c ON c.affiliate_id = a.id
            WHERE a.status = 'active' AND c.status = 'payable'
            GROUP BY a.id
            HAVING total >= COALESCE(
                a.min_payout_eur,
                (SELECT setting_value FROM affiliate_settings WHERE setting_key = 'default_min_payout_eur')
            )
        ")->fetchAll();

        $batch = [];
        $seq   = 1;
        foreach ($rows as $r) {
            if (empty($r['iban'])) continue;
            $ref = sprintf('AFP-%s-%03d', date('Y-m'), $seq++);

            $ins = $pdo->prepare("
                INSERT INTO affiliate_payouts (affiliate_id, amount_eur, iban_snapshot, reference, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $ins->execute([$r['id'], $r['total'], $r['iban'], $ref, (int)$session['user_id']]);
            $payoutId = (int)$pdo->lastInsertId();

            $pdo->prepare("
                UPDATE affiliate_commissions
                SET payout_id = ?, status = 'paid'
                WHERE affiliate_id = ? AND status = 'payable'
            ")->execute([$payoutId, $r['id']]);

            $batch[] = [
                'payout_id'    => $payoutId,
                'affiliate_id' => $r['id'],
                'full_name'    => $r['full_name'],
                'iban'         => $r['iban'],
                'amount'       => $r['total'],
                'reference'    => $ref,
            ];
        }
        $pdo->commit();
        json_response(true, ['batch' => $batch, 'count' => count($batch)]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(false, null, 'Napaka pri batch: ' . $e->getMessage(), 500);
    }
}

// ─── GET: payout CSV ─────────────────────────────────────────────
if ($method === 'GET' && $action === 'payout_csv') {
    $batch = $_GET['batch'] ?? date('Y-m');
    $rows  = $pdo->prepare("
        SELECT p.reference, p.amount_eur, p.iban_snapshot, a.full_name, a.bic
        FROM affiliate_payouts p
        JOIN affiliates a ON a.id = p.affiliate_id
        WHERE p.reference LIKE ?
        ORDER BY p.reference ASC
    ");
    $rows->execute(["AFP-{$batch}%"]);
    $data = $rows->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"payouts-{$batch}.csv\"");
    echo "\xEF\xBB\xBF"; // UTF-8 BOM za Excel
    echo "Referenca,Ime,IBAN,BIC,Znesek EUR\n";
    foreach ($data as $row) {
        echo implode(',', [
            $row['reference'],
            '"' . str_replace('"', '""', $row['full_name']) . '"',
            $row['iban_snapshot'],
            $row['bic'] ?? '',
            number_format((float)$row['amount_eur'], 2, '.', ''),
        ]) . "\n";
    }
    exit;
}

// ─── POST: mark_paid ─────────────────────────────────────────────
if ($method === 'POST' && $action === 'mark_paid') {
    $ids = array_map('intval', $body['payout_ids'] ?? []);
    if (!$ids) json_response(false, null, 'Manjka payout_ids.', 400);

    $in  = implode(',', $ids);
    $pdo->exec("UPDATE affiliate_payouts SET status = 'paid', paid_at = NOW() WHERE id IN ({$in})");

    // Pošlji email affiliatom
    $rows = $pdo->query("
        SELECT p.*, a.email, a.full_name
        FROM affiliate_payouts p
        JOIN affiliates a ON a.id = p.affiliate_id
        WHERE p.id IN ({$in})
    ")->fetchAll();

    foreach ($rows as $row) {
        send_affiliate_payout_email($row['email'], $row['full_name'], (float)$row['amount_eur'], $row['reference']);
    }

    json_response(true, null, count($ids) . ' izplačil označenih kot plačano.');
}

// ─── POST: set_global ────────────────────────────────────────────
if ($method === 'POST' && $action === 'set_global') {
    $key = $body['key'] ?? '';
    $val = $body['value'] ?? '';
    if (!$key) json_response(false, null, 'Manjka key.', 400);

    $pdo->prepare("INSERT INTO affiliate_settings (setting_key, setting_value)
                   VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
        ->execute([$key, $val, $val]);
    json_response(true, null, 'Nastavitev posodobljena.');
}

json_response(false, null, 'Neznan action.', 400);
