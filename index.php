<?php
require_once 'includes/auth_check.php';

if (is_logged_in()) {
    redirect_to_main();
} else {
    redirect_to_login();
}
