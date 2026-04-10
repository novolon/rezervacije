-- =============================================
-- Dnevni urniki po dnevih + blokirani datumi
-- =============================================

-- Per-day schedule (zamenja globalni schedule_start/end + booking_open_days)
CREATE TABLE IF NOT EXISTS restaurant_day_schedules (
    restaurant_id INT NOT NULL,
    day_of_week   TINYINT UNSIGNED NOT NULL, -- 0=Pon, 1=Tor, ..., 6=Ned
    is_open       TINYINT(1) NOT NULL DEFAULT 1,
    start_time    SMALLINT UNSIGNED NOT NULL DEFAULT 480,  -- minute od polnoči
    end_time      SMALLINT UNSIGNED NOT NULL DEFAULT 1380,
    PRIMARY KEY (restaurant_id, day_of_week),
    CONSTRAINT fk_rds_rest FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
);

-- Blokirani datumi (izjeme – ne glede na dan v tednu)
CREATE TABLE IF NOT EXISTS restaurant_blackouts (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    blackout_date DATE NOT NULL,
    reason        VARCHAR(255) NULL DEFAULT NULL,
    UNIQUE KEY uq_rest_date (restaurant_id, blackout_date),
    CONSTRAINT fk_rb_rest FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
);

-- Inicializiraj dnevne urnike iz obstoječih nastavitev restavracij
INSERT IGNORE INTO restaurant_day_schedules (restaurant_id, day_of_week, is_open, start_time, end_time)
SELECT r.id,
       t.dow,
       CASE WHEN (r.booking_open_days >> t.dow) & 1 = 1 THEN 1 ELSE 0 END,
       r.schedule_start,
       r.schedule_end
FROM restaurants r
CROSS JOIN (
    SELECT 0 AS dow UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL
    SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
) t;
