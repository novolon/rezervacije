<?php
/**
 * Rezble HTML head partial.
 * Zahteva: $pageTitle (opcijsko), $extraCss (opcijsko – array legacy CSS imen, npr. ['main.css?v=4', 'stats.css?v=1']).
 *
 * Nalaga: Google Fonts → tokens.css → extra CSS → rezble.css.
 * rezble.css se VEDNO naloži kot zadnji, tako da v konfliktu vedno zmaga novi stil.
 * Po vključitvi tega partiala ne dodajaj <link rel="stylesheet"> ročno – uporabi $extraCss.
 */

if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}

require_once __DIR__ . '/lang.php';

$_pageTitle = $pageTitle ?? 'Rezervacije';
$_appName   = defined('APP_NAME') ? APP_NAME : 'Rezervacije';
$_extraCss  = $extraCss ?? [];
?>
<!doctype html>
<html lang="<?= get_lang() ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($_pageTitle) ?> — <?= htmlspecialchars($_appName) ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/tokens.css">
<?php foreach ($_extraCss as $_cssFile): ?>
  <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/<?= htmlspecialchars($_cssFile) ?>">
<?php endforeach; ?>
  <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/rezble.css?v=<?= @filemtime(__DIR__ . '/../assets/css/rezble.css') ?>">
<?php
// PostHog analytics (admin app context) – init je gated za 'analytics' consent
require_once __DIR__ . '/posthog_init.php';
posthog_render_init(['context' => 'admin']);

// Cookie consent banner – head-safe (samo <link> + <script>, banner DOM lazy build).
// Surface 'app' za interne strani, 'affiliate' za /affiliate/* strani (avtomatska detekcija).
$_ccSurface = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/affiliate/') !== false) ? 'affiliate' : 'app';
require_once __DIR__ . '/cookie_consent.php';
rez_consent_render(['surface' => $_ccSurface]);
?>
  <script>
  window.__LANG__ = '<?= get_lang() ?>';
  window.__T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
  window.__T_FALLBACK__ = <?= get_lang() === 'sl' ? '{}' : json_encode(json_decode((string)@file_get_contents(__DIR__ . '/../lang/sl.json'), true) ?: [], JSON_UNESCAPED_UNICODE) ?>;
  window.__MONTHS__ = <?= lang_months_js() ?>;
  window.__DAYS__ = <?= lang_days_js() ?>;
  </script>
  <script src="<?= BASE_PATH ?>/assets/js/i18n.js"></script>
</head>
