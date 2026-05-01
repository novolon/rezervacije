-- No-show tracking: nastavitve na restavraciji + razlog blokade na gostem

ALTER TABLE restaurants
    ADD COLUMN IF NOT EXISTS track_no_shows    TINYINT(1)   NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS no_show_threshold INT UNSIGNED NOT NULL DEFAULT 3;

ALTER TABLE guests
    ADD COLUMN IF NOT EXISTS block_reason VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS blocked_at   DATETIME     NULL;

ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS no_show_at DATETIME NULL;

-- Razširi status enum z 'no_show'
ALTER TABLE reservations
    MODIFY COLUMN status ENUM('confirmed','pending','rejected','cancelled','no_show') NOT NULL DEFAULT 'confirmed';
