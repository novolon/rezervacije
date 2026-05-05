-- ─────────────────────────────────────────────────────────────────────────────
-- migrate_restaurant_address.sql
-- Doda naslov restavracije, ki se prikazuje v footerju email sporočil gostom.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE restaurants
    ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Naslov restavracije (ulica, hišna št., kraj). Prikaže se v emailih gostom.'
        AFTER name;
