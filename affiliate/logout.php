<?php
require_once '../config.php';
require_once '../includes/affiliate_session.php';
aff_session_start();
aff_session_destroy();
header('Location: ' . BASE_PATH . '/affiliate/login.php');
exit;
