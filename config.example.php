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

// Stripe
define('STRIPE_SECRET_KEY',      '');   // sk_live_... ali sk_test_...
define('STRIPE_PUBLISHABLE_KEY', '');   // pk_live_... ali pk_test_...
define('STRIPE_WEBHOOK_SECRET',  '');   // whsec_... (iz Stripe dashboard → Webhooks)

// Cene paketov (EUR). Single source of truth — uporablja jih landing, register, billing,
// blog AI prompt. Spremeni tukaj → posodobljeno povsod.
define('PLAN_PRICES', [
    'basic'    => ['monthly' => 9.99,  'yearly' => 99.00],
    'advanced' => ['monthly' => 29.99, 'yearly' => 299.00],
    'premium'  => ['monthly' => 69.99, 'yearly' => 699.00],
]);

// PostHog analytics (cloud EU region). Brezplačno do 1M dogodkov/mesec.
// Pridobi public API key na https://eu.posthog.com → Project settings → Project API key.
// Pusti prazno, da onemogočiš analitiko.
define('POSTHOG_KEY',  '');                        // phc_xxxxxxxxxxxxxxxxxxxxxxxx
define('POSTHOG_HOST', 'https://eu.i.posthog.com'); // EU region (GDPR friendly). Za US: https://us.i.posthog.com

ini_set('display_errors', '0');
ini_set('log_errors',     '1');
date_default_timezone_set(TIMEZONE);
