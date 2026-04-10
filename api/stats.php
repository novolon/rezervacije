<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_auth();
$pdo     = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, null, 'Metoda ni podprta.', 405);
}

$section = trim($_GET['section'] ?? '');
if (!$section) json_response(false, null, 'Parameter section je obvezen.', 400);

// ─── Resolviraj restaurant_id ──────────────────────────────────
$rest_id_param = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : null;

if ($session['role'] === 'user') {
    $rest_id = (int)$session['restaurant_id'];
} elseif ($session['role'] === 'admin') {
    if ($rest_id_param) {
        $chk = $pdo->prepare("SELECT 1 FROM restaurant_admins WHERE restaurant_id = ? AND user_id = ?");
        $chk->execute([$rest_id_param, $session['user_id']]);
        $rest_id = $chk->fetchColumn() ? $rest_id_param : null;
    } else {
        $rest_id = null; // vse lastne
    }
} else {
    $rest_id = $rest_id_param; // superadmin
}

// ─── Datum filter ──────────────────────────────────────────────
$from = $_GET['from'] ?? null;
$to   = $_GET['to']   ?? null;

if (!$from || !$to) {
    // Default: zadnjih 30 dni
    $to   = date('Y-m-d');
    $from = date('Y-m-d', strtotime('-30 days'));
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

// ─── Zgradi WHERE za restavracijo ──────────────────────────────
function build_rest_filter(PDO $pdo, array $session, ?int $rest_id, string $alias = 'r'): array {
    if ($session['role'] === 'user') {
        return [
            'where'  => "{$alias}.restaurant_id = ?",
            'params' => [(int)$session['restaurant_id']],
        ];
    }
    if ($session['role'] === 'superadmin') {
        if ($rest_id !== null) {
            return ['where' => "{$alias}.restaurant_id = ?", 'params' => [$rest_id]];
        }
        return ['where' => '1=1', 'params' => []];
    }
    // admin
    if ($rest_id !== null) {
        return ['where' => "{$alias}.restaurant_id = ?", 'params' => [$rest_id]];
    }
    return [
        'where'  => "EXISTS (SELECT 1 FROM restaurant_admins ra WHERE ra.restaurant_id = {$alias}.restaurant_id AND ra.user_id = ?)",
        'params' => [(int)$session['user_id']],
    ];
}

$rf = build_rest_filter($pdo, $session, $rest_id);

// ─── Sekcija: overview ─────────────────────────────────────────
if ($section === 'overview') {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_reservations,
            COALESCE(SUM(guest_count), 0) AS total_guests,
            ROUND(AVG(guest_count), 1) AS avg_guests,
            ROUND(SUM(status = 'confirmed') * 100.0 / NULLIF(COUNT(*), 0), 1) AS arrival_rate,
            SUM(status = 'confirmed') AS confirmed_count,
            SUM(status = 'pending') AS pending_count,
            SUM(status IN ('rejected','cancelled')) AS rejected_count
        FROM reservations r
        WHERE {$rf['where']} AND r.reservation_date BETWEEN ? AND ?
    ");
    $stmt->execute(array_merge($rf['params'], [$from, $to]));
    json_response(true, $stmt->fetch());
}

// ─── Sekcija: by_day ──────────────────────────────────────────
if ($section === 'by_day') {
    // DAYOFWEEK: 1=Ned, 2=Pon, ..., 7=Sob → prerazporedimo v Pon=1..Ned=7
    $stmt = $pdo->prepare("
        SELECT
            DAYOFWEEK(r.reservation_date) AS dow_mysql,
            COUNT(*) AS reservations,
            COALESCE(SUM(r.guest_count), 0) AS guests
        FROM reservations r
        WHERE {$rf['where']} AND r.reservation_date BETWEEN ? AND ?
          AND r.status NOT IN ('rejected','cancelled')
        GROUP BY dow_mysql
        ORDER BY dow_mysql
    ");
    $stmt->execute(array_merge($rf['params'], [$from, $to]));
    $rows = $stmt->fetchAll();

    $days = ['Pon','Tor','Sre','Čet','Pet','Sob','Ned'];
    $result = [];
    foreach ($days as $i => $label) {
        $result[$i] = ['label' => $label, 'reservations' => 0, 'guests' => 0];
    }
    foreach ($rows as $row) {
        // MySQL dow: 1=Sun→index 6, 2=Mon→0, ..., 7=Sat→5
        $idx = ($row['dow_mysql'] == 1) ? 6 : ($row['dow_mysql'] - 2);
        $result[$idx]['reservations'] = (int)$row['reservations'];
        $result[$idx]['guests']       = (int)$row['guests'];
    }
    json_response(true, array_values($result));
}

// ─── Sekcija: by_hour ─────────────────────────────────────────
if ($section === 'by_hour') {
    $stmt = $pdo->prepare("
        SELECT
            HOUR(r.reservation_time) AS hour,
            COUNT(*) AS reservations,
            COALESCE(SUM(r.guest_count), 0) AS guests
        FROM reservations r
        WHERE {$rf['where']} AND r.reservation_date BETWEEN ? AND ?
          AND r.status NOT IN ('rejected','cancelled')
        GROUP BY hour
        ORDER BY hour
    ");
    $stmt->execute(array_merge($rf['params'], [$from, $to]));
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[(int)$row['hour']] = ['reservations' => (int)$row['reservations'], 'guests' => (int)$row['guests']];
    }
    $result = [];
    for ($h = 8; $h <= 23; $h++) {
        $result[] = [
            'label'        => sprintf('%02d:00', $h),
            'hour'         => $h,
            'reservations' => $map[$h]['reservations'] ?? 0,
            'guests'       => $map[$h]['guests']       ?? 0,
        ];
    }
    json_response(true, $result);
}

// ─── Sekcija: by_month ────────────────────────────────────────
if ($section === 'by_month') {
    // Zadnjih 12 mesecev (neodvisno od from/to filtra)
    $monthFrom = date('Y-m-d', strtotime('-11 months', strtotime(date('Y-m-01'))));
    $monthTo   = date('Y-m-d', strtotime('last day of this month'));

    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(r.reservation_date, '%Y-%m') AS month,
            COUNT(*) AS reservations,
            COALESCE(SUM(r.guest_count), 0) AS guests
        FROM reservations r
        WHERE {$rf['where']} AND r.reservation_date BETWEEN ? AND ?
          AND r.status NOT IN ('rejected','cancelled')
        GROUP BY month
        ORDER BY month
    ");
    $stmt->execute(array_merge($rf['params'], [$monthFrom, $monthTo]));
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) $map[$row['month']] = $row;

    $months_sl = ['Jan','Feb','Mar','Apr','Maj','Jun','Jul','Avg','Sep','Okt','Nov','Dec'];
    $result = [];
    for ($i = 11; $i >= 0; $i--) {
        $dt    = date('Y-m', strtotime("-{$i} months"));
        $m     = (int)date('m', strtotime($dt . '-01')) - 1;
        $result[] = [
            'label'        => $months_sl[$m] . ' ' . date('Y', strtotime($dt . '-01')),
            'month'        => $dt,
            'reservations' => (int)($map[$dt]['reservations'] ?? 0),
            'guests'       => (int)($map[$dt]['guests']       ?? 0),
        ];
    }
    json_response(true, $result);
}

// ─── Sekcija: sources ─────────────────────────────────────────
if ($section === 'sources') {
    $stmt = $pdo->prepare("
        SELECT
            CASE WHEN r.created_by IS NULL THEN 'public' ELSE 'staff' END AS source,
            COUNT(*) AS reservations,
            SUM(r.status = 'confirmed') AS confirmed,
            SUM(r.status = 'pending')   AS pending,
            SUM(r.status IN ('rejected','cancelled')) AS rejected
        FROM reservations r
        WHERE {$rf['where']} AND r.reservation_date BETWEEN ? AND ?
        GROUP BY source
    ");
    $stmt->execute(array_merge($rf['params'], [$from, $to]));
    json_response(true, $stmt->fetchAll());
}

// ─── Sekcija: guests_dist ─────────────────────────────────────
if ($section === 'guests_dist') {
    $stmt = $pdo->prepare("
        SELECT
            CASE
                WHEN guest_count = 1 THEN '1'
                WHEN guest_count = 2 THEN '2'
                WHEN guest_count = 3 THEN '3'
                WHEN guest_count = 4 THEN '4'
                ELSE '5+'
            END AS size_group,
            COUNT(*) AS reservations
        FROM reservations r
        WHERE {$rf['where']} AND r.reservation_date BETWEEN ? AND ?
          AND r.status NOT IN ('rejected','cancelled')
        GROUP BY size_group
        ORDER BY size_group
    ");
    $stmt->execute(array_merge($rf['params'], [$from, $to]));
    $rows = $stmt->fetchAll();
    $total = array_sum(array_column($rows, 'reservations'));
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'label'        => $row['size_group'] . ' ' . ($row['size_group'] === '1' ? 'oseba' : 'oseb'),
            'reservations' => (int)$row['reservations'],
            'pct'          => $total ? round($row['reservations'] * 100 / $total, 1) : 0,
        ];
    }
    json_response(true, $result);
}

// ─── Sekcija: returning ───────────────────────────────────────
if ($section === 'returning') {
    // Vsi unikatni gosti z emailom v obdobju
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT email) AS unique_guests
        FROM reservations r
        WHERE {$rf['where']} AND r.reservation_date BETWEEN ? AND ?
          AND r.status = 'confirmed' AND email IS NOT NULL AND email != ''
    ");
    $stmt->execute(array_merge($rf['params'], [$from, $to]));
    $unique = (int)$stmt->fetchColumn();

    // Vrnili se vsaj 2x (v celotni zgodovini, ne samo v obdobju)
    $stmt2 = $pdo->prepare("
        SELECT COUNT(*) AS returning_guests FROM (
            SELECT email
            FROM reservations r
            WHERE {$rf['where']} AND r.status = 'confirmed'
              AND email IS NOT NULL AND email != ''
              AND email IN (
                  SELECT DISTINCT email FROM reservations r2
                  WHERE {$rf['where']} AND r2.reservation_date BETWEEN ? AND ?
                    AND r2.status = 'confirmed' AND r2.email IS NOT NULL AND r2.email != ''
              )
            GROUP BY email
            HAVING COUNT(*) >= 2
        ) sub
    ");
    $params2 = array_merge($rf['params'], $rf['params'], [$from, $to]);
    $stmt2->execute($params2);
    $returning = (int)$stmt2->fetchColumn();

    json_response(true, [
        'unique_guests'    => $unique,
        'returning_guests' => $returning,
        'returning_pct'    => $unique ? round($returning * 100 / $unique, 1) : 0,
    ]);
}

// ─── Sekcija: top_customers ───────────────────────────────────
if ($section === 'top_customers') {
    $only_returning = isset($_GET['returning']) && $_GET['returning'] == '1';
    $having = $only_returning ? 'HAVING COUNT(*) >= 2' : '';

    $stmt = $pdo->prepare("
        SELECT
            email,
            MAX(guest_name)       AS guest_name,
            COUNT(*)              AS visits,
            SUM(guest_count)      AS total_guests,
            MIN(reservation_date) AS first_visit,
            MAX(reservation_date) AS last_visit
        FROM reservations r
        WHERE {$rf['where']}
          AND r.status = 'confirmed'
          AND email IS NOT NULL AND email != ''
        GROUP BY email
        {$having}
        ORDER BY visits DESC
        LIMIT 50
    ");
    $stmt->execute($rf['params']);
    json_response(true, $stmt->fetchAll());
}

// ─── Sekcija: export (CSV) ────────────────────────────────────
if ($section === 'export') {
    $stmt = $pdo->prepare("
        SELECT
            r.reservation_date, r.reservation_time, r.guest_name, r.email, r.phone,
            r.guest_count, r.status, r.notes,
            CASE WHEN r.created_by IS NULL THEN 'Javna' ELSE 'Osebje' END AS source,
            res.name AS restaurant_name
        FROM reservations r
        JOIN restaurants res ON r.restaurant_id = res.id
        WHERE {$rf['where']} AND r.reservation_date BETWEEN ? AND ?
        ORDER BY r.reservation_date, r.reservation_time
    ");
    $stmt->execute(array_merge($rf['params'], [$from, $to]));
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rezervacije_' . $from . '_' . $to . '.csv"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    fputcsv($out, ['Datum','Čas','Ime','Email','Telefon','Gostje','Status','Vir','Opomba','Restavracija'], ';');
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['reservation_date'],
            substr($row['reservation_time'], 0, 5),
            $row['guest_name'],
            $row['email'] ?? '',
            $row['phone'] ?? '',
            $row['guest_count'],
            $row['status'],
            $row['source'],
            $row['notes'] ?? '',
            $row['restaurant_name'],
        ], ';');
    }
    fclose($out);
    exit;
}

json_response(false, null, 'Neznana sekcija.', 400);
