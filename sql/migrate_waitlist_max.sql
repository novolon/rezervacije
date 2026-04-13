-- Dodata polje: max čakalna vrsta na termin
-- Zaženi enkrat na produkcijski bazi

ALTER TABLE restaurants
    ADD COLUMN IF NOT EXISTS waitlist_max_per_slot INT UNSIGNED NOT NULL DEFAULT 3
    COMMENT 'Max vpisov na čakalno listo po terminu (0 = brez omejitve)';
