<?php
/**
 * Affiliate self-service API – zahteva affiliate session.
 */
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/affiliate_auth.php';
require_once '../includes/affiliate_helper.php';
require_once '../includes/discount_helper.php';

header('Content-Type: application/json; charset=utf-8');

$sess   = require_affiliate();
$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$body   = $method === 'POST' ? get_body() : [];
$action = $body['action'] ?? ($_GET['action'] ?? '');
$affId  = (int)$sess['id'];

// ─── GET: statistike ────────────────────────────────────────────
if ($method === 'GET' && $action === 'stats') {
    $days  = min(365, max(7, (int)($_GET['days'] ?? 30)));
    $since = date('Y-m-d', strtotime("-{$days} days"));

    $clicks = $pdo->prepare("
        SELECT COUNT(*) FROM affiliate_clicks
        WHERE affiliate_id = ? AND created_at >= ? AND is_bot = 0
    ");
    $clicks->execute([$affId, $since]);

    $referrals = $pdo->prepare("
        SELECT COUNT(*) FROM affiliate_referrals WHERE affiliate_id = ?
    ");
    $referrals->execute([$affId]);

    $converted = $pdo->prepare("
        SELECT COUNT(*) FROM affiliate_referrals WHERE affiliate_id = ? AND status = 'converted'
    ");
    $converted->execute([$affId]);

    $earnings = $pdo->prepare("
        SELECT COALESCE(SUM(amount_eur), 0) FROM affiliate_commissions
        WHERE affiliate_id = ? AND status IN ('pending','payable','paid')
    ");
    $earnings->execute([$affId]);

    $payable = $pdo->prepare("
        SELECT COALESCE(SUM(amount_eur), 0) FROM affiliate_commissions
        WHERE affiliate_id = ? AND status = 'payable'
    ");
    $payable->execute([$affId]);

    json_response(true, [
        'clicks'           => (int)$clicks->fetchColumn(),
        'referrals'        => (int)$referrals->fetchColumn(),
        'converted'        => (int)$converted->fetchColumn(),
        'total_earned_eur' => round((float)$earnings->fetchColumn(), 2),
        'payable_eur'      => round((float)$payable->fetchColumn(), 2),
        'days'             => $days,
    ]);
}

// ─── GET: referrali (maskirani, paginirani) ──────────────────────
if ($method === 'GET' && $action === 'referrals') {
    $page    = max(1, (int)($_GET['page']    ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));
    $status  = $_GET['status'] ?? '';
    $offset  = ($page - 1) * $perPage;

    $where   = $status ? "AND r.status = " . $pdo->quote($status) : '';

    $total = $pdo->prepare("SELECT COUNT(*) FROM affiliate_referrals r WHERE r.affiliate_id = ? {$where}");
    $total->execute([$affId]);

    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(u.created_at, '%Y-%m') AS registered_ym,
               r.status,
               r.attribution,
               (SELECT COUNT(*) FROM affiliate_commissions c WHERE c.affiliate_id = r.affiliate_id AND c.user_id = r.user_id AND c.status != 'voided') AS paid_invoices,
               (SELECT COALESCE(SUM(c.amount_eur),0) FROM affiliate_commissions c WHERE c.affiliate_id = r.affiliate_id AND c.user_id = r.user_id AND c.status != 'voided') AS earned_eur
        FROM affiliate_referrals r
        JOIN users u ON u.id = r.user_id
        WHERE r.affiliate_id = ? {$where}
        ORDER BY r.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute([$affId]);
    json_response(true, [
        'items'    => $stmt->fetchAll(),
        'total'    => (int)$total->fetchColumn(),
        'per_page' => $perPage,
        'page'     => $page,
    ]);
}

// ─── GET: provizije (summary + paginirane) ───────────────────────
if ($method === 'GET' && $action === 'earnings') {
    // Summary mode
    if (!empty($_GET['summary'])) {
        $s = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN status IN ('pending','payable','paid') THEN amount_eur END), 0) AS total_earned,
                COALESCE(SUM(CASE WHEN status = 'pending'  THEN amount_eur END), 0) AS pending,
                COALESCE(SUM(CASE WHEN status = 'payable'  THEN amount_eur END), 0) AS payable,
                COALESCE(SUM(CASE WHEN status = 'paid'     THEN amount_eur END), 0) AS paid
            FROM affiliate_commissions WHERE affiliate_id = ?
        ");
        $s->execute([$affId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        json_response(true, array_map('floatval', $row));
    }

    $page    = max(1, (int)($_GET['page']    ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));
    $status  = $_GET['status'] ?? '';
    $offset  = ($page - 1) * $perPage;
    $where   = $status ? "AND status = " . $pdo->quote($status) : '';

    $total = $pdo->prepare("SELECT COUNT(*) FROM affiliate_commissions WHERE affiliate_id = ? {$where}");
    $total->execute([$affId]);

    $stmt = $pdo->prepare("
        SELECT id,
               DATE_FORMAT(created_at, '%Y-%m-%d') AS date,
               invoice_amount_eur,
               amount_eur AS commission_eur,
               percent,
               status,
               available_at,
               payout_id
        FROM affiliate_commissions
        WHERE affiliate_id = ? {$where}
        ORDER BY created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute([$affId]);
    json_response(true, [
        'items'    => $stmt->fetchAll(),
        'total'    => (int)$total->fetchColumn(),
        'per_page' => $perPage,
        'page'     => $page,
    ]);
}

// ─── GET: izplačila ──────────────────────────────────────────────
if ($method === 'GET' && $action === 'payouts') {
    $stmt = $pdo->prepare("
        SELECT id, amount_eur, status,
               reference AS batch_reference,
               iban_snapshot,
               created_at AS requested_at,
               paid_at
        FROM affiliate_payouts
        WHERE affiliate_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$affId]);
    json_response(true, $stmt->fetchAll());
}

// ─── GET: discount statistike ────────────────────────────────────
if ($method === 'GET' && $action === 'discount_stats') {
    $aff = affiliate_get($pdo, $affId);
    if (!$aff || !$aff['discount_code_id']) {
        json_response(true, ['enabled' => false]);
    }
    $codeId = (int)$aff['discount_code_id'];

    $stmt = $pdo->prepare("SELECT code, percent_off, duration, duration_months, is_active FROM discount_codes WHERE id = ?");
    $stmt->execute([$codeId]);
    $dc = $stmt->fetch();

    $reds = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount_off_eur),0) FROM discount_code_redemptions WHERE code_id = ?");
    $reds->execute([$codeId]);
    [$count, $totalOff] = $reds->fetch(PDO::FETCH_NUM);

    json_response(true, [
        'enabled'         => (bool)$aff['discount_enabled'],
        'code'            => $dc['code']             ?? null,
        'percent_off'     => $dc['percent_off']      ?? null,
        'duration'        => $dc['duration']         ?? null,
        'duration_months' => $dc['duration_months']  ? (int)$dc['duration_months'] : null,
        'is_active'       => (bool)($dc['is_active'] ?? 0),
        'redemptions'     => (int)$count,
        'total_off_eur'   => round((float)$totalOff, 2),
    ]);
}

// ─── POST: update_profile ────────────────────────────────────────
if ($method === 'POST' && $action === 'update_profile') {
    $allowed = ['full_name','iban','bic','address','city','postal_code','country','company_name','tax_number','vat_id'];
    $fields = []; $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) {
            $fields[] = "{$f} = ?";
            $params[]  = $body[$f] === '' ? null : substr($body[$f], 0, 255);
        }
    }
    if (!$fields) json_response(false, null, 'Ni polj za posodobitev.', 400);
    $params[] = $affId;
    $pdo->prepare("UPDATE affiliates SET " . implode(', ', $fields) . " WHERE id = ?")
        ->execute($params);

    // Posodobi session ime, če je bil full_name spremenjen
    if (isset($body['full_name'])) {
        $_SESSION['aff_full_name'] = $body['full_name'];
    }
    json_response(true, null, 'Profil posodobljen.');
}

// ─── POST: change_password ───────────────────────────────────────
if ($method === 'POST' && $action === 'change_password') {
    $old = $body['current_password'] ?? $body['old'] ?? '';
    $new = $body['new_password']     ?? $body['new'] ?? '';
    if (strlen($new) < 8) json_response(false, null, 'Novo geslo mora imeti vsaj 8 znakov.', 400);

    $stmt = $pdo->prepare("SELECT password_hash FROM affiliates WHERE id = ?");
    $stmt->execute([$affId]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($old, $row['password_hash'])) {
        json_response(false, null, 'Napačno trenutno geslo.', 401);
    }
    $pdo->prepare("UPDATE affiliates SET password_hash = ? WHERE id = ?")
        ->execute([password_hash($new, PASSWORD_BCRYPT), $affId]);
    json_response(true, null, 'Geslo posodobljeno.');
}

json_response(false, null, 'Neznan action.', 400);
