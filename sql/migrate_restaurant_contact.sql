-- Kontaktni podatki restavracije (email + telefon)
ALTER TABLE restaurants
  ADD COLUMN IF NOT EXISTS contact_email VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(30)  NULL;
