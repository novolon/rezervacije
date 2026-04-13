-- Modul 3: Samourejanje rezervacije (gost)
-- Poganja se enkrat. Preveri najprej ali stolpci že obstajajo.

-- 1. Rezervacije: edit_token, cancel_reason
ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS edit_token         VARCHAR(64)  NULL UNIQUE,
  ADD COLUMN IF NOT EXISTS edit_token_expires DATETIME     NULL,
  ADD COLUMN IF NOT EXISTS cancel_reason      VARCHAR(255) NULL;

-- 2. Restavracije: nastavitve za gostovo urejanje/odpoved
ALTER TABLE restaurants
  ADD COLUMN IF NOT EXISTS allow_guest_edit          TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS guest_edit_cutoff_hours   INT        NOT NULL DEFAULT 24,
  ADD COLUMN IF NOT EXISTS allow_guest_cancel        TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS guest_cancel_cutoff_hours INT        NOT NULL DEFAULT 4;
