-- Dodaj možnost prilagoditve trajanja per-rezervacija
ALTER TABLE restaurants
    ADD COLUMN allow_custom_duration TINYINT(1) NOT NULL DEFAULT 0
    AFTER reservation_duration;

ALTER TABLE reservations
    ADD COLUMN duration INT UNSIGNED NULL DEFAULT NULL
    AFTER reservation_time;
-- NULL = uporabi default restavracije
