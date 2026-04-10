-- Dodaj kolono za označevanje prisotnosti gosta
ALTER TABLE reservations
ADD COLUMN IF NOT EXISTS attended TINYINT(1) NULL DEFAULT NULL AFTER notes;
-- NULL = ni označeno, 1 = prišel, 0 = ni prišel
