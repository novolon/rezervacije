-- Zaposleni po restavraciji
CREATE TABLE IF NOT EXISTS restaurant_staff (
  id            INT          AUTO_INCREMENT PRIMARY KEY,
  restaurant_id INT          NOT NULL,
  name          VARCHAR(100) NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
);

-- Rezervacije: kateri zaposleni je sprejel rezervacijo
ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS staff_id INT NULL AFTER created_by;

ALTER TABLE reservations
  ADD CONSTRAINT IF NOT EXISTS fk_reservation_staff
    FOREIGN KEY (staff_id) REFERENCES restaurant_staff(id) ON DELETE SET NULL;

-- Polja po meri za restavracijo
CREATE TABLE IF NOT EXISTS restaurant_custom_fields (
  id            INT          AUTO_INCREMENT PRIMARY KEY,
  restaurant_id INT          NOT NULL,
  label         VARCHAR(100) NOT NULL,
  field_type    ENUM('text','select','checkbox') NOT NULL DEFAULT 'text',
  options       TEXT         NULL,      -- JSON array za select type
  applies_to    ENUM('internal','public','both') NOT NULL DEFAULT 'both',
  is_required   TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order    INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
);

-- Vrednosti polj po meri za vsako rezervacijo
CREATE TABLE IF NOT EXISTS reservation_field_values (
  id             INT  AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT  NOT NULL,
  field_id       INT  NOT NULL,
  value          TEXT,
  UNIQUE KEY uq_res_field (reservation_id, field_id),
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
  FOREIGN KEY (field_id) REFERENCES restaurant_custom_fields(id) ON DELETE CASCADE
);
