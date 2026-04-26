<?php
/**
 * Affiliate auth guard – vključi na vrhu zaščitenih affiliate strani/API-jev.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/affiliate_session.php';

aff_session_start();

function require_affiliate(): array {
    if (!aff_is_logged_in()) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        header('Location: ' . BASE_PATH . '/affiliate/login.php');
        exit;
    }
    $sess = aff_session_get();
    if ($sess['status'] === 'suspended' || $sess['status'] === 'rejected') {
        aff_session_destroy();
        header('Location: ' . BASE_PATH . '/affiliate/login.php?err=suspended');
        exit;
    }
    if ($sess['status'] === 'pending') {
        // Pending affiliati vidijo samo "čakamo na odobritev" stran
        $allowed = ['/affiliate/dashboard.php', '/affiliate/logout.php'];
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $ok = false;
        foreach ($allowed as $a) {
            if (str_ends_with($uri, $a)) { $ok = true; break; }
        }
        if (!$ok) {
            header('Location: ' . BASE_PATH . '/affiliate/dashboard.php');
            exit;
        }
    }
    return $sess;
}
