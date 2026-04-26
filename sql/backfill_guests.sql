-- Backfill gostov iz obstoječih rezervacij
-- Zaženi ENKRAT, potem zbrišite.
-- Varno za večkratni zagon (INSERT IGNORE preskoci duplikate).

-- ── 1. Gostje z emailom ──────────────────────────────────────────
INSERT IGNORE INTO guests
    (restaurant_id, email, first_name, last_name, phone,
     first_visit, last_visit, total_visits, total_covers, no_shows)
SELECT
    r.restaurant_id,
    LOWER(r.email),
    TRIM(SUBSTRING_INDEX(COALESCE(r.guest_name, ''), ' ', 1)),
    TRIM(CASE WHEN INSTR(COALESCE(r.guest_name,''), ' ') > 0
         THEN SUBSTRING(r.guest_name, INSTR(r.guest_name,' ') + 1)
         ELSE '' END),
    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        COALESCE(r.phone,''), ' ',''),'-',''),'(',''),')',''),'.',''),
    MIN(r.reservation_date),
    MAX(r.reservation_date),
    COUNT(*),
    COALESCE(SUM(r.guest_count), 0),
    0
FROM reservations r
WHERE r.email IS NOT NULL AND r.email != '' AND r.email LIKE '%@%'
GROUP BY r.restaurant_id, LOWER(r.email);

-- ── 2. Gostje samo s telefonom (brez emaila) ────────────────────
INSERT IGNORE INTO guests
    (restaurant_id, email, first_name, last_name, phone,
     first_visit, last_visit, total_visits, total_covers, no_shows)
SELECT
    r.restaurant_id,
    NULL,
    TRIM(SUBSTRING_INDEX(COALESCE(r.guest_name, ''), ' ', 1)),
    TRIM(CASE WHEN INSTR(COALESCE(r.guest_name,''), ' ') > 0
         THEN SUBSTRING(r.guest_name, INSTR(r.guest_name,' ') + 1)
         ELSE '' END),
    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        r.phone, ' ',''),'-',''),'(',''),')',''),'.',''),
    MIN(r.reservation_date),
    MAX(r.reservation_date),
    COUNT(*),
    COALESCE(SUM(r.guest_count), 0),
    0
FROM reservations r
WHERE (r.email IS NULL OR r.email = '')
  AND r.phone IS NOT NULL AND r.phone != ''
GROUP BY
    r.restaurant_id,
    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        r.phone, ' ',''),'-',''),'(',''),')',''),'.',''  );
