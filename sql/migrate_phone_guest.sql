-- Migracija: telefonska identifikacija gostov
-- Zaženi enkrat na produkciji

-- ── 1. Normaliziraj obstoječe telefonske številke v guests ───────
UPDATE guests
SET phone = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '.', '')
WHERE phone IS NOT NULL AND phone != '';

-- ── 2. Naredi email opcijsko (NULL = gost brez emaila) ───────────
ALTER TABLE guests MODIFY COLUMN email VARCHAR(255) NULL DEFAULT NULL;

-- ── 3. Backfill gostov iz obstoječih rezervacij (po emailu) ──────
-- Doda vse unikatne email-gostje iz rezervacij, ki še niso v tabeli.
-- ON DUPLICATE KEY posodobi statistiko obstoječih vrstic.
INSERT INTO guests
    (restaurant_id, email, first_name, last_name, phone,
     first_visit, last_visit, total_visits, total_covers, no_shows)
SELECT
    r.restaurant_id,
    LOWER(r.email)                                                      AS email,
    TRIM(SUBSTRING_INDEX(COALESCE(r.guest_name, ''), ' ', 1))          AS first_name,
    TRIM(CASE
        WHEN INSTR(COALESCE(r.guest_name,''), ' ') > 0
        THEN SUBSTRING(r.guest_name, INSTR(r.guest_name,' ') + 1)
        ELSE ''
    END)                                                                AS last_name,
    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        COALESCE(r.phone,''), ' ',''),'-',''),'(',''),')',''),'.',''
    )                                                                   AS phone,
    MIN(r.reservation_date)                                             AS first_visit,
    MAX(r.reservation_date)                                             AS last_visit,
    COUNT(*)                                                            AS total_visits,
    COALESCE(SUM(r.guest_count), 0)                                     AS total_covers,
    0                                                                   AS no_shows
FROM reservations r
WHERE r.email IS NOT NULL AND r.email != '' AND r.email LIKE '%@%'
GROUP BY r.restaurant_id, LOWER(r.email)
ON DUPLICATE KEY UPDATE
    first_name   = IF(first_name = '' OR first_name IS NULL, VALUES(first_name), first_name),
    last_name    = IF(last_name  = '' OR last_name  IS NULL, VALUES(last_name),  last_name),
    phone        = IF(phone      = '' OR phone      IS NULL, VALUES(phone),      phone),
    first_visit  = IF(first_visit IS NULL OR VALUES(first_visit) < first_visit,  VALUES(first_visit), first_visit),
    last_visit   = IF(last_visit  IS NULL OR VALUES(last_visit)  > last_visit,   VALUES(last_visit),  last_visit),
    total_visits = VALUES(total_visits),
    total_covers = VALUES(total_covers);

-- ── 4. Backfill gostov samo s telefonom (brez emaila) ────────────
INSERT INTO guests
    (restaurant_id, email, first_name, last_name, phone,
     first_visit, last_visit, total_visits, total_covers, no_shows)
SELECT
    sub.restaurant_id,
    NULL,
    sub.first_name,
    sub.last_name,
    sub.phone_norm,
    sub.first_visit,
    sub.last_visit,
    sub.total_visits,
    sub.total_covers,
    0
FROM (
    SELECT
        r.restaurant_id,
        TRIM(SUBSTRING_INDEX(COALESCE(r.guest_name, ''), ' ', 1))     AS first_name,
        TRIM(CASE
            WHEN INSTR(COALESCE(r.guest_name,''), ' ') > 0
            THEN SUBSTRING(r.guest_name, INSTR(r.guest_name,' ') + 1)
            ELSE ''
        END)                                                           AS last_name,
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
            r.phone, ' ',''),'-',''),'(',''),')',''),'.',''
        )                                                              AS phone_norm,
        MIN(r.reservation_date)                                        AS first_visit,
        MAX(r.reservation_date)                                        AS last_visit,
        COUNT(*)                                                       AS total_visits,
        COALESCE(SUM(r.guest_count), 0)                                AS total_covers
    FROM reservations r
    WHERE (r.email IS NULL OR r.email = '')
      AND r.phone IS NOT NULL AND r.phone != ''
    GROUP BY r.restaurant_id, phone_norm
) sub
-- Ne vstavi če ta telefon že obstaja v guests
LEFT JOIN guests g ON g.restaurant_id = sub.restaurant_id AND g.phone = sub.phone_norm
WHERE g.id IS NULL;

-- ── 5. Unikaten indeks za telefon ─────────────────────────────────
-- Pred zagonom preveri: SELECT restaurant_id, phone, COUNT(*) FROM guests
--   WHERE phone IS NOT NULL GROUP BY restaurant_id, phone HAVING COUNT(*) > 1;
-- Če so duplikati, jih ročno spoji, nato zaženi:
ALTER TABLE guests ADD UNIQUE KEY uq_restaurant_phone (restaurant_id, phone);
