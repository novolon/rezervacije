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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/tokens.css">
<?php foreach ($_extraCss as $_cssFile): ?>
  <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/<?= htmlspecialchars($_cssFile) ?>">
<?php endforeach; ?>
  <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/rezble.css">
  <script>
  window.__LANG__ = '<?= get_lang() ?>';
  window.__T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
  window.__MONTHS__ = <?= lang_months_js() ?>;
  window.__DAYS__ = <?= lang_days_js() ?>;
  </script>
  <script src="<?= BASE_PATH ?>/assets/js/i18n.js"></script>
</head>
