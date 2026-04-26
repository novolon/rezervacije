<?php
/**
 * Affiliate session management – ločen namespace od user seje.
 * Uporablja isti PHP session, a ključe s prefixom 'aff_'.
 */

function aff_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    // Preveri potek affiliate seje (8 ur neaktivnosti)
    if (!empty($_SESSION['aff_id']) && !empty($_SESSION['aff_last_activity'])) {
        if ((time() - $_SESSION['aff_last_activity']) > 8 * 3600) {
            aff_session_destroy();
        }
    }
    if (!empty($_SESSION['aff_id'])) {
        $_SESSION['aff_last_activity'] = time();
    }
}

function aff_is_logged_in(): bool {
    return !empty($_SESSION['aff_id']);
}

function aff_session_set(array $affiliate): void {
    $_SESSION['aff_id']            = (int) $affiliate['id'];
    $_SESSION['aff_email']         = $affiliate['email'];
    $_SESSION['aff_full_name']     = $affiliate['full_name'];
    $_SESSION['aff_ref_code']      = $affiliate['ref_code'];
    $_SESSION['aff_status']        = $affiliate['status'];
    $_SESSION['aff_last_activity'] = time();
}

function aff_session_get(): array {
    return [
        'id'        => $_SESSION['aff_id']        ?? 0,
        'email'     => $_SESSION['aff_email']      ?? '',
        'full_name' => $_SESSION['aff_full_name']  ?? '',
        'ref_code'  => $_SESSION['aff_ref_code']   ?? '',
        'status'    => $_SESSION['aff_status']     ?? '',
    ];
}

function aff_session_destroy(): void {
    foreach (['aff_id','aff_email','aff_full_name','aff_ref_code','aff_status','aff_last_activity'] as $k) {
        unset($_SESSION[$k]);
    }
}
