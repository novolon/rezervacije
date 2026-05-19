<?php
/**
 * Modul 7: SSE endpoint za real-time sync.
 *
 * Klient: `new EventSource('/api/events.php?restaurant_id=X&last_id=Y')`
 * Strežnik: 30s lifecycle, 2s poll, polling tabele `realtime_events`.
 *           Po 30s klijent dobi `event: bye` in se sam reconnecta z `Last-Event-ID`.
 *
 * Synology Nginx specifika:
 *  - `X-Accel-Buffering: no` izklopi reverse-proxy buffer
 *  - `ob_end_flush()` + `ob_implicit_flush(true)` izklopita PHP-FPM buffer
 *  - `session_write_close()` sprosti session lock, da paralelni request-i ne blokirajo
 *  - Krajši lifecycle (30s) prepreči zapolnitev PHP-FPM worker poola.
 */

require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/realtime.php';

// Auth (uporabi navadni JSON 401, ker pred header-ji še lahko)
$session = require_auth();

$pdo = getDB();

$reqRestId = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
if ($reqRestId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'restaurant_id required']);
    exit;
}

// ─── Avtorizacija na restavracijo ─────────────────────────────
if ($session['role'] === 'user') {
    if ((int)$session['restaurant_id'] !== $reqRestId) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
} elseif ($session['role'] === 'admin') {
    if (!admin_owns_restaurant($pdo, $session, $reqRestId)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
} elseif ($session['role'] !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

// ─── Plan gate: realtime_sync je Advanced+ ────────────────────
// Za `user` role piggyback na admin lastnika restavracije.
if ($session['role'] !== 'superadmin') {
    $ownerId = 0;
    if ($session['role'] === 'admin') {
        $ownerId = (int)$session['user_id'];
    } else {
        try {
            $oStmt = $pdo->prepare("SELECT owner_id FROM restaurants WHERE id = ?");
            $oStmt->execute([$reqRestId]);
            $ownerId = (int)$oStmt->fetchColumn();
        } catch (Throwable $e) { $ownerId = 0; }
    }
    if (!$ownerId || !user_has_feature($pdo, $ownerId, 'realtime_sync')) {
        http_response_code(403);
        echo json_encode(['error' => 'realtime_sync not in plan']);
        exit;
    }
}

// ─── Last-Event-ID (reconnect cursor) ─────────────────────────
$lastId = 0;
if (!empty($_SERVER['HTTP_LAST_EVENT_ID'])) {
    $lastId = (int)$_SERVER['HTTP_LAST_EVENT_ID'];
} elseif (isset($_GET['last_id'])) {
    $lastId = (int)$_GET['last_id'];
}
if ($lastId < 0) $lastId = 0;

// ─── SSE headers ──────────────────────────────────────────────
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no');           // Nginx (Synology)
header('Connection: keep-alive');

// ─── Sproščanje session lock-a (omogoči paralelne request-e) ──
session_write_close();

// ─── Disable output buffering ─────────────────────────────────
while (ob_get_level() > 0) { @ob_end_flush(); }
ob_implicit_flush(true);
@set_time_limit(0);
ignore_user_abort(false);

// ─── Pošlji opening retry hint (klient retry po 2s ob napaki) ─
echo "retry: 2000\n\n";
@flush();

// ─── Inicialni "hello" event z trenutnim cursor-jem ───────────
// Če klient prvič pride (last_id = 0), nastavi cursor na trenutni MAX(id),
// da ne dobi starih 10min event-ov ob mountu.
if ($lastId === 0) {
    try {
        $maxId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM realtime_events WHERE restaurant_id = " . (int)$reqRestId)->fetchColumn();
        $lastId = $maxId;
    } catch (Throwable $e) {
        // tabela morda ne obstaja – spi do prvega event-a
        $lastId = 0;
    }
}
echo "event: hello\n";
echo "data: " . json_encode(['cursor' => $lastId, 'restaurant_id' => $reqRestId]) . "\n\n";
@flush();

// ─── Poll loop: 30s lifecycle, 2s tick ────────────────────────
$started   = time();
$maxLife   = 30;           // seconds
$tick      = 2;            // seconds
$cleanedAt = 0;

$stmt = $pdo->prepare(
    "SELECT id, event_type, payload, UNIX_TIMESTAMP(created_at) AS ts
     FROM realtime_events
     WHERE restaurant_id = ? AND id > ?
     ORDER BY id ASC
     LIMIT 100"
);

while (true) {
    if (connection_aborted()) break;
    if ((time() - $started) >= $maxLife) break;

    try {
        $stmt->execute([$reqRestId, $lastId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // tabela morda ne obstaja ali je DB padla — počakaj in zaključi
        echo ": db-error " . $e->getMessage() . "\n\n";
        @flush();
        sleep($tick);
        continue;
    }

    if ($rows) {
        foreach ($rows as $row) {
            $payload = json_decode($row['payload'], true) ?: [];
            $payload['_type'] = $row['event_type'];
            $payload['_ts']   = (int)$row['ts'];
            echo "id: " . (int)$row['id'] . "\n";
            echo "event: " . $row['event_type'] . "\n";
            echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
            $lastId = (int)$row['id'];
        }
        @flush();
    } else {
        // keepalive comment – Nginx in browser-ji ohranijo conn ob ":"
        echo ": ping " . time() . "\n\n";
        @flush();
    }

    // Cleanup enkrat na conn (~10s po startu, ne ob vsakem ticku)
    if ($cleanedAt === 0 && (time() - $started) >= 10) {
        cleanup_realtime_events($pdo, 10);
        $cleanedAt = time();
    }

    sleep($tick);
}

// ─── Graceful bye → klient reconnecta z Last-Event-ID ─────────
echo "event: bye\n";
echo "data: " . json_encode(['cursor' => $lastId]) . "\n\n";
@flush();
