-- =============================================
-- Razširitev urnika: več terminov na dan,
-- delno blokiranje datumov, override za zaposlene
-- =============================================

-- 1. Tabela za več terminov na dan
CREATE TABLE IF NOT EXISTS restaurant_day_periods (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT UNSIGNED NOT NULL,
    day_of_week   TINYINT UNSIGNED NOT NULL, -- 0=Pon..6=Ned
    start_time    SMALLINT UNSIGNED NOT NULL,
    end_time      SMALLINT UNSIGNED NOT NULL,
    KEY idx_rdp_rest_dow (restaurant_id, day_of_week),
    CONSTRAINT fk_rdp_rest FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
);

-- 2. Migriraj obstoječe urnike v novo tabelo (samo enkrat)
INSERT IGNORE INTO restaurant_day_periods (restaurant_id, day_of_week, start_time, end_time)
SELECT restaurant_id, day_of_week, start_time, end_time
FROM restaurant_day_schedules
ON DUPLICATE KEY UPDATE start_time = VALUES(start_time);

-- 3. Dodaj delno blokiranje datumov (NULL = cel dan)
ALTER TABLE restaurant_blackouts
    ADD COLUMN IF NOT EXISTS block_start SMALLINT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS block_end   SMALLINT UNSIGNED NULL DEFAULT NULL;

-- 4. Dovoli zaposlenim rezervacije na zaprte/blokirane dni
ALTER TABLE restaurants
    ADD COLUMN IF NOT EXISTS employees_can_override_schedule TINYINT(1) NOT NULL DEFAULT 0;
