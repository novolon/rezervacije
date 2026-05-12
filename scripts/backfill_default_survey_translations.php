<?php
/**
 * One-shot backfill: za vse obstoječe ankete, kjer master besedilo ujema default,
 * vstavi prevode v vseh 8 jezikih (samo manjkajoče). Idempotent — varno ponoviti.
 *
 * Usage:  php scripts/backfill_default_survey_translations.php
 */
if (PHP_SAPI !== 'cli') exit('CLI only.');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/survey_helper.php';

$pdo = getDB();
$inserted = backfill_default_survey_translations($pdo, null);
echo "Inserted/updated translations: $inserted\n";
