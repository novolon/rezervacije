-- Dodaj per-restavracija toggle za čakalno listo
ALTER TABLE restaurants
  ADD COLUMN waitlist_enabled TINYINT(1) NOT NULL DEFAULT 1;
