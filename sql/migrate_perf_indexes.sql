-- ─────────────────────────────────────────────────────────────────────────────
-- migrate_perf_indexes.sql
-- Performance indeksi za skaliranje na 1M+ rezervacij.
--
-- Kaj rešuje:
--   1. reservations.idx_date_rest ima napačen vrstni red (date, rest) — pri
--      multi-tenant queries je rest_id bolj selektiven, zato mora biti prvi.
--      Brez tega popravka bo MySQL pri 1M rezervacij in ~1000 restavracij
--      bral vse zapise za date range pred filtriranjem po restaurant_id
--      → linearen padec hitrosti.
--   2. Manjkajoč status filter index — admin pogosto filtrira po status
--      (pending/confirmed) znotraj svoje restavracije.
--   3. Email/phone lookup za guest history — popularna funkcija ki je
--      brez indexa naredi full scan.
--   4. Survey answers JOIN — survey_answers.response_id potrebuje index.
--   5. Table assignments — reservation_table_assignments
--      potrebuje (reservation_id) in (table_id) indekse.
--
-- Idempotent: vsi DROP + ADD IF NOT EXISTS za varno ponavljanje.
-- ─────────────────────────────────────────────────────────────────────────────

-- ── 1. reservations ─────────────────────────────────────────────────────────

-- Stari narobe-ordered index (če obstaja). DROP IF EXISTS ne deluje za INDEX
-- na MySQL 5.7, zato uporabljamo procedural fallback.
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservations' AND INDEX_NAME='idx_date_rest') > 0,
    'ALTER TABLE reservations DROP INDEX idx_date_rest',
    'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Pravilen vrstni red: restaurant_id PRVI (multi-tenant filter), nato date+time.
-- Pokrije: WHERE restaurant_id = ? AND reservation_date BETWEEN ? AND ?
--         WHERE restaurant_id = ? AND reservation_date = ? ORDER BY reservation_time
ALTER TABLE reservations ADD INDEX IF NOT EXISTS idx_rest_date_time (restaurant_id, reservation_date, reservation_time);

-- Status filter index — admin filtri po pending/confirmed/cancelled per dan.
-- Pokrije: WHERE restaurant_id = ? AND status = ? AND reservation_date BETWEEN ...
ALTER TABLE reservations ADD INDEX IF NOT EXISTS idx_rest_status_date (restaurant_id, status, reservation_date);

-- Cross-tenant date scan (superadmin stats, sitemaps, ...). idx_date already
-- exists v schema.sql; ohranimo ga kot je.

-- Guest history lookup (vrne vse rezervacije gosta po emailu/telefonu)
ALTER TABLE reservations ADD INDEX IF NOT EXISTS idx_email (email);
ALTER TABLE reservations ADD INDEX IF NOT EXISTS idx_phone (phone);

-- guest_id lookup (per-restaurant guest profile → all bookings)
-- Stolpec guest_id je bil dodan v migrate_guests. Index ni vedno prisoten.
SET @hasGuestIdCol = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservations' AND COLUMN_NAME='guest_id');
SET @s = IF(@hasGuestIdCol > 0,
    'ALTER TABLE reservations ADD INDEX IF NOT EXISTS idx_guest (guest_id)',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 2. guests ───────────────────────────────────────────────────────────────
-- Phone unique je že iz migrate_phone_guest. Email pa potrebuje per-restaurant
-- compound index (lookup pri public booking — če gost obstaja, prepoznaj).
-- Ni unique ker isti email lahko obstaja brez verifikacije.
SET @hasGuestsTable = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guests');
SET @s = IF(@hasGuestsTable > 0,
    'ALTER TABLE guests ADD INDEX IF NOT EXISTS idx_rest_email (restaurant_id, email)',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 3. survey_answers ──────────────────────────────────────────────────────
-- response_id JOIN se zgodi pri vsakem detail klicu in pri export_csv batchu.
SET @hasSurveyAnswers = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='survey_answers');
SET @s = IF(@hasSurveyAnswers > 0,
    'ALTER TABLE survey_answers ADD INDEX IF NOT EXISTS idx_response (response_id)',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 4. survey_responses ────────────────────────────────────────────────────
-- WHERE survey_id = ? AND submitted_at IS NOT NULL ORDER BY submitted_at DESC
SET @hasSurveyResp = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='survey_responses');
SET @s = IF(@hasSurveyResp > 0,
    'ALTER TABLE survey_responses ADD INDEX IF NOT EXISTS idx_survey_submitted (survey_id, submitted_at)',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 5. reservation_table_assignments ───────────────────────────────────────
SET @hasRTA = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservation_table_assignments');
SET @s1 = IF(@hasRTA > 0,
    'ALTER TABLE reservation_table_assignments ADD INDEX IF NOT EXISTS idx_reservation (reservation_id)',
    'SELECT 1');
PREPARE stmt FROM @s1; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @s2 = IF(@hasRTA > 0,
    'ALTER TABLE reservation_table_assignments ADD INDEX IF NOT EXISTS idx_table (table_id)',
    'SELECT 1');
PREPARE stmt FROM @s2; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 6. waitlist ────────────────────────────────────────────────────────────
-- Najpogostejši queryji: po restaurantu + datumu, in po statusu/expires.
SET @hasWaitlist = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waitlist');
SET @s = IF(@hasWaitlist > 0,
    'ALTER TABLE waitlist ADD INDEX IF NOT EXISTS idx_rest_date (restaurant_id, date)',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @s = IF(@hasWaitlist > 0,
    'ALTER TABLE waitlist ADD INDEX IF NOT EXISTS idx_status_expires (status, expires_at)',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 7. subscriptions ───────────────────────────────────────────────────────
-- WHERE user_id = ? AND status IN ('trial','active') ORDER BY created_at DESC
SET @hasSubs = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='subscriptions');
SET @s = IF(@hasSubs > 0,
    'ALTER TABLE subscriptions ADD INDEX IF NOT EXISTS idx_user_status (user_id, status)',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 8. blog_subscribers ────────────────────────────────────────────────────
-- Listing query: WHERE confirmed_at IS NOT NULL AND unsubscribed_at IS NULL
SET @hasBlogSubs = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='blog_subscribers');
SET @s = IF(@hasBlogSubs > 0,
    'ALTER TABLE blog_subscribers ADD INDEX IF NOT EXISTS idx_confirmed (confirmed_at, unsubscribed_at)',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ─────────────────────────────────────────────────────────────────────────────
-- Po apply-u tega:
--   EXPLAIN SELECT * FROM reservations
--    WHERE restaurant_id = 42 AND reservation_date BETWEEN '2026-01-01' AND '2026-01-31';
--   → mora pokazati: type=range, key=idx_rest_date_time, rows ~30
-- ─────────────────────────────────────────────────────────────────────────────
