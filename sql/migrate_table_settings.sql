-- Upravljanje miz – Faza 2 nastavitve
-- Zaženi enkrat na produkcijski bazi

-- Vse mize so združljive: sistem lahko združi katerekoli proste mize za večje skupine
ALTER TABLE restaurants
    ADD COLUMN IF NOT EXISTS all_tables_mergeable TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS allow_area_choice    TINYINT(1) NOT NULL DEFAULT 0;
