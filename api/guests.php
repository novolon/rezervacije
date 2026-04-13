<?php
/**
 * Guests API – baza gostov (Advanced/Premium)
 *
 * GET    ?restaurant_id=X             → seznam gostov
 * GET    ?restaurant_id=X&email=...   → profil gosta + zgodovina
 * PUT    ?id=X                        → uredi opombo / tagge gosta
 * DELETE ?id=X                        → zbriši gosta iz baze
 */
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/guest_helper.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_auth();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

// Feature gate: samo Advanced/Premium (in superadmin)
if ($session['role'] !== 'superadmin' && !user_has_feature($pdo, (int)$session['user_id'], 'guest_database')) {
    json_response(false, null, 'Ta funkcionalnost ni na voljo v vašem paketu.', 403);
}

/**
 * Preveri da ima admin dostop do restavracije.
 */
function check_rest_access(PDO $pdo, array $session, int $rest_id): void {
    if ($session['role'] === 'superadmin') return;
    if ($session['role'] === 'admin') {
        $stmt = $pdo->prepare("SELECT 1 FROM restaurant_admins WHERE restaurant_id = ? AND user_id = ?");
        $stmt->execute([$rest_id, $session['user_id']]);
        if (!$stmt->fetchColumn()) json_response(false, null, 'Dostop zavrnjen.', 403);
        return;
    }
    if ((int)$session['restaurant_id'] !== $rest_id) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }
}

// ─── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $rest_id = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
    if (!$rest_id) json_response(false, null, 'restaurant_id je obvezen.', 400);
    check_rest_access($pdo, $session, $rest_id);

    // Profil posameznega gosta (po emailu)
    if (!empty($_GET['email'])) {
        $email = strtolower(trim($_GET['email']));
        $profile = get_guest_profile($pdo, $rest_id, $email);
        if (!$profile) json_response(false, null, 'Gost ne obstaja.', 404);
        $profile['tags'] = $profile['tags'] ? json_decode($profile['tags'], true) : [];
        $profile['history'] = get_guest_history($pdo, $rest_id, $email, 20);

        // Ankete – povprečna ocena
        try {
            $stmt = $pdo->prepare("
                SELECT AVG(sq.rating_value) AS avg_rating, COUNT(DISTINCT sr.id) AS survey_count
                FROM survey_responses sr
                JOIN survey_answers sa ON sa.response_id = sr.id
                JOIN survey_questions sq ON sa.question_id = sq.id AND sq.type = 'rating'
                WHERE sr.email = ? AND sr.submitted_at IS NOT NULL
                  AND EXISTS (SELECT 1 FROM survey_forms sf WHERE sf.id = sr.survey_id AND sf.restaurant_id = ?)
            ");
            $stmt->execute([$email, $rest_id]);
            $row = $stmt->fetch();
            $profile['avg_rating']   = $row['avg_rating']   ? round((float)$row['avg_rating'], 1) : null;
            $profile['survey_count'] = (int)($row['survey_count'] ?? 0);
        } catch (PDOException $e) {
            $profile['avg_rating']   = null;
            $profile['survey_count'] = 0;
        }

        json_response(true, $profile);
    }

    // Seznam gostov (z iskanjem)
    $search = trim($_GET['search'] ?? '');
    $limit  = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $where  = 'restaurant_id = ?';
    $params = [$rest_id];

    if ($search) {
        $like = '%' . $search . '%';
        $where  .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $params  = array_merge($params, [$like, $like, $like, $like]);
    }

    $stmt = $pdo->prepare("
        SELECT id, email, first_name, last_name, phone, tags,
               first_visit, last_visit, total_visits, total_covers, no_shows, is_blacklisted
        FROM guests
        WHERE {$where}
        ORDER BY last_visit DESC, created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $guests = $stmt->fetchAll();

    foreach ($guests as &$g) {
        $g['tags'] = $g['tags'] ? json_decode($g['tags'], true) : [];
    }

    // Skupno število za pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM guests WHERE {$where}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    json_response(true, ['guests' => $guests, 'total' => $total]);
}

// ─── PUT (uredi gosta) ─────────────────────────────────────────
if ($method === 'PUT') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    $stmt = $pdo->prepare("SELECT * FROM guests WHERE id = ?");
    $stmt->execute([$id]);
    $guest = $stmt->fetch();
    if (!$guest) json_response(false, null, 'Gost ne obstaja.', 404);
    check_rest_access($pdo, $session, (int)$guest['restaurant_id']);

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $fields = [];
    $params = [];

    if (array_key_exists('notes', $body)) {
        $fields[] = 'notes = ?';
        $params[] = trim($body['notes']);
    }
    if (array_key_exists('tags', $body)) {
        $tags = is_array($body['tags']) ? $body['tags'] : [];
        // Sanitiziraj tagge
        $tags = array_map('strval', array_slice($tags, 0, 20));
        $fields[] = 'tags = ?';
        $params[] = json_encode($tags, JSON_UNESCAPED_UNICODE);
    }
    if (array_key_exists('is_blacklisted', $body)) {
        $fields[] = 'is_blacklisted = ?';
        $params[] = (int)(bool)$body['is_blacklisted'];
    }
    if (array_key_exists('first_name', $body)) {
        $fields[] = 'first_name = ?';
        $params[] = trim($body['first_name']);
    }
    if (array_key_exists('last_name', $body)) {
        $fields[] = 'last_name = ?';
        $params[] = trim($body['last_name']);
    }
    if (array_key_exists('phone', $body)) {
        $fields[] = 'phone = ?';
        $params[] = trim($body['phone']) ?: null;
    }

    if (empty($fields)) json_response(false, null, 'Ni polj za posodobitev.', 400);

    $params[] = $id;
    $pdo->prepare("UPDATE guests SET " . implode(', ', $fields) . " WHERE id = ?")
        ->execute($params);

    json_response(true, null, '', 200);
}

// ─── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    $stmt = $pdo->prepare("SELECT * FROM guests WHERE id = ?");
    $stmt->execute([$id]);
    $guest = $stmt->fetch();
    if (!$guest) json_response(false, null, 'Gost ne obstaja.', 404);
    check_rest_access($pdo, $session, (int)$guest['restaurant_id']);

    $pdo->prepare("DELETE FROM guests WHERE id = ?")->execute([$id]);
    json_response(true, null);
}

json_response(false, null, 'Metoda ni podprta.', 405);
