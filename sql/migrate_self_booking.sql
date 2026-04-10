-- =============================================
-- Faza 5 – Self-booking (javna rezervacijska stran)
-- =============================================

-- Booking polja v restaurants
ALTER TABLE restaurants
    ADD COLUMN booking_token        VARCHAR(64)      NULL DEFAULT NULL UNIQUE AFTER color,
    ADD COLUMN booking_enabled      TINYINT(1)       NOT NULL DEFAULT 0 AFTER booking_token,
    ADD COLUMN booking_open_days    TINYINT UNSIGNED NOT NULL DEFAULT 127 AFTER booking_enabled,
    ADD COLUMN booking_auto_confirm TINYINT(1)       NOT NULL DEFAULT 1 AFTER booking_open_days,
    ADD COLUMN booking_min_guests   TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER booking_auto_confirm,
    ADD COLUMN booking_max_guests   TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER booking_min_guests;

-- Approval flow in reservations
ALTER TABLE reservations
    ADD COLUMN status ENUM('confirmed','pending','rejected','cancelled') NOT NULL DEFAULT 'confirmed' AFTER notes,
    ADD COLUMN source ENUM('admin','public') NOT NULL DEFAULT 'admin' AFTER status;

-- Generiraj unikatne booking tokene za obstoječe restavracije
UPDATE restaurants
SET booking_token = LOWER(HEX(RANDOM_BYTES(32)))
WHERE booking_token IS NULL;
