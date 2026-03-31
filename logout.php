<?php
require_once 'includes/auth_check.php';

// Pobriši remember me token
if (!empty($_COOKIE['rem_tok'])) {
    $parts = explode(':', $_COOKIE['rem_tok'], 2);
    if (count($parts) === 2) {
        require_once 'includes/db.php';
        try {
            getDB()->prepare("UPDATE users SET remember_token = NULL, remember_expires = NULL WHERE id = ?")
                   ->execute([(int)$parts[0]]);
        } catch (Exception $e) {}
    }
    setcookie('rem_tok', '', ['expires' => time() - 1, 'path' => BASE_PATH . '/']);
}

session_unset();
session_destroy();

header('Location: ' . BASE_PATH . '/login.php');
exit;
