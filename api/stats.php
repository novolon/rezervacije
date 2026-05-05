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
    $overviewSql = "
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
    ";
    $stmt = $pdo->prepare($overviewSql);
    $stmt->execute(array_merge($rf['params'], [$from, $to]));
    $current = $stmt->fetch();

    // Prejšnje enako obdobje za delta primerjavo
    $periodDays = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $prevTo     = date('Y-m-d', strtotime($from) - 86400);
    $prevFrom   = date('Y-m-d', strtotime($prevTo) - ($periodDays - 1) * 86400);
    $stmt2 = $pdo->prepare($overviewSql);
    $stmt2->execute(array_merge($rf['params'], [$prevFrom, $prevTo]));
    $prev = $stmt2->fetch();

    // Izračunaj delta %
    $deltaFn = fn($cur, $pre) => $pre > 0 ? round(($cur - $pre) / $pre * 100) : ($cur > 0 ? 100 : 0);
    $current['prev_total_reservations'] = (int)$prev['total_reservations'];
    $current['prev_total_guests']       = (int)$prev['total_guests'];
    $current['prev_arrival_rate']       = (float)($prev['arrival_rate'] ?? 0);
    $current['delta_reservations']      = $deltaFn((int)$current['total_reservations'], (int)$prev['total_reservations']);
    $current['delta_guests']            = $deltaFn((int)$current['total_guests'], (int)$prev['total_guests']);
    $current['delta_arrival_rate']      = round((float)($current['arrival_rate'] ?? 0) - (float)($prev['arrival_rate'] ?? 0), 1);

    json_response(true, $current);
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
// Trend po izbranem obdobju z adaptivno granularnostjo:
//   ≤ 31 dni  → po dnevih
//   ≤ 90 dni  → po tednih
//   ≤ 730 dni → po mesecih
//   > 730 dni → po četrtletjih
if ($section === 'by_month') {
    $fromDt = strtotime($from);
    $toDt   = strtotime($to);
    $days   = max(1, ($toDt - $fromDt) / 86400 + 1);

    $months_sl = ['Jan','Feb','Mar','Apr','Maj','Jun','Jul','Avg','Sep','Okt','Nov','Dec'];

    if ($days <= 31) {
        // Po dnevih
        $sqlFmt = "%Y-%m-%d";
        $bucketKey = 'day';
        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(r.reservation_date, '{$sqlFmt}') AS bucket,
                   COUNT(*) AS reservations,
                   COALESCE(SUM(r.guest_count), 0) AS guests
            FROM reservations r
            WHERE {$rf['where']} AND r.reservation_date BETWEEN ? AND ?
              AND r.status NOT IN ('rejected','cancelled')
            GROUP BY bucket ORDER BY bucket
        ");
        $stmt->execute(array_merge($rf['params'], [$from, $to]));
        $map = [];
        foreach ($stmt->fetchAll() as $row) $map[$row['bucket']] = $row;
        $result = [];
        for ($d = $fromDt; $d <= $toDt; $d += 86400) {
            $key = date('Y-m-d', $d);
            $result[] = [
                'label'        => date('j. n.', $d),
                'period'       => $key,
                'reservations' => (int)($map[$key]['reservations'] ?? 0),
                'guests'       => (int)($map[$key]['guests']       ?? 0),
            ];
        }
    } elseif ($days <= 90) {
        // Po ISO tednih
        $stmt = $pdo->prepare("
            SELECT YEARWEEK(r.reservation_date, 3) AS bucket,
                   MIN(r.reservation_date) AS week_start,
                   COUNT(*) AS reservations,
                   COALESCE(SUM(r.guest_count), 0) AS guests
            FROM reservations r
            WHERE {$rf['where']} AND r.reservation_date BETWEEN ? AND ?
              AND r.status NOT IN ('rejected','cancelled')
            GROUP BY bucket ORDER BY bucket
        ");
        $stmt->execute(array_merge($rf['params'], [$from, $to]));
        $map = [];
        foreach ($stmt->fetchAll() as $row) $map[$row['bucket']] = $row;
        // Generate buckets za vsak teden
        $result = [];
        $w = strtotime('monday this week', $fromDt);
        if ($w > $fromDt) $w = strtotime('-1 week', $w);
        while ($w <= $toDt) {
            $yw = (int)date('o', $w) * 100 + (int)date('W', $w);
            $row = $map[$yw] ?? null;
            $result[] = [
                'label'        => 'T' . (int)date('W', $w) . ' · ' . date('j. n.', $w),
                'period'       => date('Y-\WW', $w),
                'reservations' => (int)($row['reservations'] ?? 0),
                'guests'       => (int)($row['guests']       ?? 0),
            ];
            $w = strtotime('+7 days', $w);
        }
    } elseif ($days <= 730) {
        // Po mesecih
        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(r.reservation_date, '%Y-%m') AS bucket,
                   COUNT(*) AS reservations,
                   COALESCE(SUM(r.guest_count), 0) AS guests
            FROM reservations r
            WHERE {$rf['where']} AND r.reservation_date BETWEEN ? AND ?
              AND r.status NOT IN ('rejected','cancelled')
            GROUP BY bucket ORDER BY bucket
        ");
        $stmt->execute(array_merge($rf['params'], [$from, $to]));
        $map = [];
        foreach ($stmt->fetchAll() as $row) $map[$row['bucket']] = $row;
        $result = [];
        $cursor = strtotime(date('Y-m-01', $fromDt));
        while ($cursor <= $toDt) {
            $key = date('Y-m', $cursor);
            $m = (int)date('m', $cursor) - 1;
            $result[] = [
                'label'        => $months_sl[$m] . ' ' . date('Y', $cursor),
                'period'       => $key,
                'reservations' => (int)($map[$key]['reservations'] ?? 0),
                'guests'       => (int)($map[$key]['guests']       ?? 0),
            ];
            $cursor = strtotime('+1 month', $cursor);
        }
    } else {
        // Po četrtletjih
        $stmt = $pdo->prepare("
            SELECT CONCAT(YEAR(r.reservation_date), '-Q', QUARTER(r.reservation_date)) AS bucket,
                   COUNT(*) AS reservations,
                   COALESCE(SUM(r.guest_count), 0) AS guests
            FROM reservations r
            WHERE {$rf['where']} AND r.reservation_date BETWEEN ? AND ?
              AND r.status NOT IN ('rejected','cancelled')
            GROUP BY bucket ORDER BY bucket
        ");
        $stmt->execute(array_merge($rf['params'], [$from, $to]));
        $map = [];
        foreach ($stmt->fetchAll() as $row) $map[$row['bucket']] = $row;
        $result = [];
        $cursor = strtotime(date('Y-m-01', $fromDt));
        // Snap na začetek četrtletja
        $startMonth = (int)date('m', $cursor);
        $qStartMonth = (int)floor(($startMonth - 1) / 3) * 3 + 1;
        $cursor = strtotime(date('Y', $cursor) . '-' . sprintf('%02d', $qStartMonth) . '-01');
        while ($cursor <= $toDt) {
            $q = (int)ceil((int)date('n', $cursor) / 3);
            $key = date('Y', $cursor) . '-Q' . $q;
            $result[] = [
                'label'        => 'Q' . $q . ' ' . date('Y', $cursor),
                'period'       => $key,
                'reservations' => (int)($map[$key]['reservations'] ?? 0),
                'guests'       => (int)($map[$key]['guests']       ?? 0),
            ];
            $cursor = strtotime('+3 months', $cursor);
        }
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
    // Gosta identificiramo po emailu ali (če emaila ni) po telefonu
    $identExpr = "CASE
        WHEN email IS NOT NULL AND email != '' THEN CONCAT('e:', email)
        WHEN phone IS NOT NULL AND phone != '' THEN CONCAT('p:', phone)
        ELSE NULL
    END";
    $identFilter = "(email IS NOT NULL AND email != '' OR phone IS NOT NULL AND phone != '')";

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT {$identExpr}) AS unique_guests
        FROM reservations r
        WHERE {$rf['where']} AND r.reservation_date BETWEEN ? AND ?
          AND r.status = 'confirmed' AND {$identFilter}
    ");
    $stmt->execute(array_merge($rf['params'], [$from, $to]));
    $unique = (int)$stmt->fetchColumn();

    // Vrnili se vsaj 2x (v celotni zgodovini)
    $stmt2 = $pdo->prepare("
        SELECT COUNT(*) AS returning_guests FROM (
            SELECT {$identExpr} AS ident
            FROM reservations r
            WHERE {$rf['where']} AND r.status = 'confirmed' AND {$identFilter}
              AND ({$identExpr}) IN (
                  SELECT DISTINCT {$identExpr}
                  FROM reservations r2
                  WHERE {$rf['where']} AND r2.reservation_date BETWEEN ? AND ?
                    AND r2.status = 'confirmed' AND {$identFilter}
              )
            GROUP BY ident
            HAVING COUNT(*) >= 2
        ) sub
    ");
    $stmt2->execute(array_merge($rf['params'], $rf['params'], [$from, $to]));
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

    // Identifikator: email ima prednost, nato telefon
    $identExpr   = "CASE
        WHEN email IS NOT NULL AND email != '' THEN CONCAT('e:', email)
        WHEN phone IS NOT NULL AND phone != '' THEN CONCAT('p:', phone)
        ELSE NULL
    END";
    $identFilter = "(email IS NOT NULL AND email != '' OR phone IS NOT NULL AND phone != '')";

    $stmt = $pdo->prepare("
        SELECT
            MAX(email)            AS email,
            MAX(phone)            AS phone,
            MAX(guest_name)       AS guest_name,
            COUNT(*)              AS visits,
            SUM(guest_count)      AS total_guests,
            MIN(reservation_date) AS first_visit,
            MAX(reservation_date) AS last_visit
        FROM reservations r
        WHERE {$rf['where']}
          AND r.status = 'confirmed'
          AND {$identFilter}
        GROUP BY {$identExpr}
        {$having}
        ORDER BY visits DESC
        LIMIT 50
    ");
    $stmt->execute($rf['params']);
    $rows = $stmt->fetchAll();

    // Počisti: gostje brez emaila imajo phone kot kontakt
    foreach ($rows as &$row) {
        if (empty($row['email'])) $row['email'] = null;
        if (empty($row['phone'])) $row['phone'] = null;
    }
    json_response(true, $rows);
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

// ─── Sekcija: insights ────────────────────────────────────────
if ($section === 'insights') {
    $insights = [];
    $days7Labels = ['Nedelja','Ponedeljek','Torek','Sreda','Četrtek','Petek','Sobota'];

    // 1) Najprometnjeji dan v tednu (zadnjih 90 dni)
    $d90from = date('Y-m-d', strtotime('-90 days'));
    $stmt = $pdo->prepare("
        SELECT DAYOFWEEK(reservation_date) AS dow, COUNT(*) AS cnt
        FROM reservations r
        WHERE {$rf['where']} AND reservation_date >= ? AND status NOT IN ('rejected','cancelled')
        GROUP BY dow ORDER BY cnt DESC LIMIT 1
    ");
    $stmt->execute(array_merge($rf['params'], [$d90from]));
    $busiest = $stmt->fetch();
    if ($busiest && $busiest['cnt'] >= 3) {
        $dayName = $days7Labels[$busiest['dow'] - 1] ?? '';
        $insights[] = ['icon' => 'fire', 'text' => "{$dayName} je v zadnjih 90 dneh najprometnješi dan ({$busiest['cnt']} rezervacij)."];
    }

    // 2) Najpogostejša ura rezervacij
    $stmt = $pdo->prepare("
        SELECT HOUR(reservation_time) AS h, COUNT(*) AS cnt
        FROM reservations r
        WHERE {$rf['where']} AND reservation_date BETWEEN ? AND ? AND status NOT IN ('rejected','cancelled')
        GROUP BY h ORDER BY cnt DESC LIMIT 1
    ");
    $stmt->execute(array_merge($rf['params'], [$from, $to]));
    $peakHour = $stmt->fetch();
    if ($peakHour && $peakHour['cnt'] >= 3) {
        $insights[] = ['icon' => 'clock', 'text' => "Koničasta ura v izbranem obdobju: {$peakHour['h']}:00 ({$peakHour['cnt']} rezervacij)."];
    }

    // 3) No-show stopnja
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(status = 'no_show') AS no_shows
        FROM reservations r
        WHERE {$rf['where']} AND reservation_date BETWEEN ? AND ?
    ");
    $stmt->execute(array_merge($rf['params'], [$from, $to]));
    $nsData = $stmt->fetch();
    if ($nsData && $nsData['total'] >= 5 && $nsData['no_shows'] > 0) {
        $pct = round($nsData['no_shows'] / $nsData['total'] * 100);
        if ($pct >= 5) {
            $insights[] = ['icon' => 'warn', 'text' => "Stopnja no-show je {$pct}% ({$nsData['no_shows']} od {$nsData['total']} rezervacij). Razmislite o SMS opomniku."];
        }
    }

    // 4) Rezervacije se povečujejo / zmanjšujejo
    $midpoint = date('Y-m-d', (strtotime($from) + strtotime($to)) / 2);
    $stmt = $pdo->prepare("
        SELECT
            SUM(reservation_date < ?) AS first_half,
            SUM(reservation_date >= ?) AS second_half
        FROM reservations r
        WHERE {$rf['where']} AND reservation_date BETWEEN ? AND ? AND status NOT IN ('rejected','cancelled')
    ");
    $stmt->execute(array_merge($rf['params'], [$midpoint, $midpoint, $from, $to]));
    $halves = $stmt->fetch();
    if ($halves && $halves['first_half'] > 0 && $halves['second_half'] > 0) {
        $trend = $halves['second_half'] - $halves['first_half'];
        if (abs($trend) >= 2) {
            $pct  = round(abs($trend) / max($halves['first_half'], 1) * 100);
            $dir  = $trend > 0 ? 'naraščajo' : 'padajo';
            $insights[] = ['icon' => $trend > 0 ? 'up' : 'down', 'text' => "Rezervacije {$dir} – {$pct}% razlika med prvo in drugo polovico obdobja."];
        }
    }

    // 5) Najpogostejša velikost skupin
    $stmt = $pdo->prepare("
        SELECT guest_count, COUNT(*) AS cnt
        FROM reservations r
        WHERE {$rf['where']} AND reservation_date BETWEEN ? AND ? AND status NOT IN ('rejected','cancelled')
        GROUP BY guest_count ORDER BY cnt DESC LIMIT 1
    ");
    $stmt->execute(array_merge($rf['params'], [$from, $to]));
    $grp = $stmt->fetch();
    if ($grp && $grp['cnt'] >= 3) {
        $insights[] = ['icon' => 'group', 'text' => "Najpogostejša skupina ima {$grp['guest_count']} gost(a/ov) ({$grp['cnt']}× v obdobju)."];
    }

    json_response(true, ['insights' => $insights]);
}

json_response(false, null, 'Neznana sekcija.', 400);
