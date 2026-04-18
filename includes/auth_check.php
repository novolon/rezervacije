<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    // SameSite=Lax je potreben za Stripe redirect (Strict zlomi session po plačilu).
    // session_set_cookie_params() je zanesljivejše od ini_set na Synology/shared hostingu.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Preverimo potek seje (8 ur neaktivnosti)
if (!empty($_SESSION['user_id']) && !empty($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
        session_start();
    }
}

// Posodobimo zadnjo aktivnost
if (!empty($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
}

// Remember me – auto-login iz cookie-ja
if (empty($_SESSION['user_id']) && !empty($_COOKIE['rem_tok'])) {
    $parts = explode(':', $_COOKIE['rem_tok'], 2);
    if (count($parts) === 2) {
        [$cookieUserId, $rawToken] = $parts;
        require_once __DIR__ . '/db.php';
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1 AND remember_expires > NOW()");
            $stmt->execute([(int)$cookieUserId]);
            $user = $stmt->fetch();
            if ($user && !empty($user['remember_token']) && hash_equals($user['remember_token'], hash('sha256', $rawToken))) {
                session_regenerate_id(true);
                $_SESSION['user_id']       = (int) $user['id'];
                $_SESSION['role']          = $user['role'];
                $_SESSION['email']         = $user['email'];
                $_SESSION['restaurant_id'] = $user['restaurant_id'] ? (int) $user['restaurant_id'] : null;
                $_SESSION['full_name']     = $user['full_name'];
                $_SESSION['last_activity'] = time();
                // Rolling token – generiraj novega ob vsakem auto-loginu
                $newRaw  = bin2hex(random_bytes(32));
                $newHash = hash('sha256', $newRaw);
                $pdo->prepare("UPDATE users SET remember_token = ?, remember_expires = ? WHERE id = ?")
                    ->execute([$newHash, date('Y-m-d H:i:s', strtotime('+30 days')), $user['id']]);
                setcookie('rem_tok', $user['id'] . ':' . $newRaw, [
                    'expires'  => time() + 30 * 86400,
                    'path'     => BASE_PATH . '/',
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]);
            } else {
                setcookie('rem_tok', '', ['expires' => time() - 1, 'path' => BASE_PATH . '/']);
            }
        } catch (Exception $e) {
            // Tiha napaka – ne blokiraj strani
        }
    }
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

/**
 * Osveži podatke o naročnini v session (cache 5 min).
 * Kliči po vsakem page loadu za admin role.
 */
function refresh_subscription_session(PDO $pdo): void {
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') return;
    $cacheKey = '_sub_cached_at';
    if (!empty($_SESSION[$cacheKey]) && (time() - $_SESSION[$cacheKey]) < 300) return;

    try {
        require_once __DIR__ . '/plans.php';
        $sub = get_active_subscription($pdo, (int)$_SESSION['user_id']);
        // plan_slug je zdaj vedno 'basic'/'advanced'/'premium' (ne več 'trial')
        $planSlug = $sub['plan_slug'] ?? 'basic';
        if ($planSlug === 'trial') $planSlug = 'basic'; // legacy fallback
        $_SESSION['plan_slug']       = $planSlug;
        $_SESSION['is_on_trial']     = is_on_trial($sub);
        $_SESSION['trial_days_left'] = get_trial_days_left($sub);
        $_SESSION['trial_expired']   = is_trial_expired($sub);
        $_SESSION['payment_failed']  = ($sub['status'] ?? '') === 'payment_failed';
        $_SESSION[$cacheKey]         = time();
    } catch (Throwable $e) {
        // Tabela subscriptions verjetno še ne obstaja – ignoriraj
        error_log('refresh_subscription_session error: ' . $e->getMessage());
    }
}

function redirect_to_login(): void {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

function redirect_to_main(): void {
    // Superadmin gre na superadmin dashboard
    if (!empty($_SESSION['role']) && $_SESSION['role'] === 'superadmin') {
        header('Location: ' . BASE_PATH . '/pages/superadmin.php');
    } else {
        header('Location: ' . BASE_PATH . '/pages/main.php');
    }
    exit;
}
