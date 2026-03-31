<?php
/**
 * Vrne JSON odgovor in zaustavi izvajanje
 */
function json_response(bool $success, $data = null, string $error = '', int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $response = ['success' => $success];
    if ($data !== null)  $response['data']  = $data;
    if ($error !== '')   $response['error'] = $error;
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Preveri, da je uporabnik prijavljen; sicer vrne 401
 */
function require_auth(): array {
    if (empty($_SESSION['user_id'])) {
        json_response(false, null, 'Unauthorized', 401);
    }
    return $_SESSION;
}

/**
 * Preveri, da je uporabnik admin ali superadmin; sicer vrne 403
 */
function require_admin(): array {
    $session = require_auth();
    if (!in_array($session['role'], ['admin', 'superadmin'])) {
        json_response(false, null, 'Forbidden', 403);
    }
    return $session;
}

/**
 * Preveri, da je uporabnik superadmin; sicer vrne 403
 */
function require_superadmin(): array {
    $session = require_auth();
    if ($session['role'] !== 'superadmin') {
        json_response(false, null, 'Forbidden', 403);
    }
    return $session;
}

/**
 * Vrne seznam restaurant_id, do katerih ima admin dostop (via restaurant_admins junction).
 * Superadmin vrne null (= vse).
 */
function get_admin_restaurant_ids(PDO $pdo, array $session): ?array {
    if ($session['role'] === 'superadmin') {
        return null; // null = dostop do vseh
    }
    if ($session['role'] === 'admin') {
        $stmt = $pdo->prepare("SELECT restaurant_id FROM restaurant_admins WHERE user_id = ?");
        $stmt->execute([$session['user_id']]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
    // user: dostop samo do lastne restavracije
    return $session['restaurant_id'] ? [(int)$session['restaurant_id']] : [];
}

/**
 * Preveri, da admin ima dostop do določene restavracije.
 * Superadmin ima vedno dostop.
 */
function admin_owns_restaurant(PDO $pdo, array $session, int $restaurantId): bool {
    if ($session['role'] === 'superadmin') return true;
    $stmt = $pdo->prepare("SELECT 1 FROM restaurant_admins WHERE restaurant_id = ? AND user_id = ?");
    $stmt->execute([$restaurantId, $session['user_id']]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Prebere JSON telo zahteve
 */
function get_body(): array {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * HTML escape
 */
function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Vrne restavracijo po ID-ju ali 404
 */
function get_restaurant_or_404(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = ? AND is_active = 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_response(false, null, 'Restavracija ne obstaja', 404);
    return $row;
}
