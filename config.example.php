<?php
// =============================================
// Konfiguracija aplikacije – TEMPLATE
// Kopiraj to datoteko v config.php in nastavi vrednosti!
// config.php je v .gitignore in se NE sme commitati.
// =============================================

define('DB_HOST',    'localhost');
define('DB_NAME',    'rezervacije_saas');
define('DB_USER',    'db_user');
define('DB_PASS',    'db_password');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME',         'Rezervacije');
define('SESSION_LIFETIME', 8 * 3600); // 8 ur
define('TIMEZONE',         'Europe/Ljubljana');

// Pot do podmape aplikacije (brez trailing slash).
// Nastavi na '' če je app v korenski mapi.
// Primer: '/rezervacije' za dev.example.com/rezervacije/
define('BASE_PATH', '/rezervacije');

// Javni URL aplikacije (brez trailing slash) – za email linke
define('APP_URL', 'https://yourdomain.com');

// Mailgun (EU region)
define('MAILGUN_API_KEY', '');        // npr. key-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
define('MAILGUN_DOMAIN',  '');        // npr. mg.yourdomain.com
define('MAIL_FROM',       'Rezervacije <noreply@yourdomain.com>');

ini_set('display_errors', '0');
ini_set('log_errors',     '1');
date_default_timezone_set(TIMEZONE);
