-- ─────────────────────────────────────────────────────────────────────────────
-- migrate_booking_languages.sql
-- Doda nastavitve jezika public booking strani / widgetu, per-restavracija.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE restaurants
    ADD COLUMN IF NOT EXISTS booking_lang_switcher_enabled TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Pokazi language switcher dropdown na public booking strani / widgetu';

ALTER TABLE restaurants
    ADD COLUMN IF NOT EXISTS booking_available_languages JSON NULL
        COMMENT 'Niz lang code-ov, npr. ["sl","en","it"]. NULL/prazno = vsi 8 podprti.';

ALTER TABLE restaurants
    ADD COLUMN IF NOT EXISTS booking_primary_language VARCHAR(5) NOT NULL DEFAULT 'sl'
        COMMENT 'Privzeti jezik booking strani (uporabljen, kadar switcher onemogočen ALI kadar IP/Accept-Language detekcija ne najde ujemanja)';
