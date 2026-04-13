-- Modul: Upravljanje miz (Table Management) — Faza 1
-- Zaženi enkrat na produkcijski bazi

-- 1. Cone/območja znotraj restavracije
CREATE TABLE IF NOT EXISTS restaurant_areas (
    id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT UNSIGNED     NOT NULL,
    name          VARCHAR(100)     NOT NULL,
    sort_order    SMALLINT         NOT NULL DEFAULT 0,
    is_active     TINYINT(1)       NOT NULL DEFAULT 1,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    INDEX idx_area_rest (restaurant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Mize
CREATE TABLE IF NOT EXISTS restaurant_tables (
    id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT UNSIGNED     NOT NULL,
    area_id       INT UNSIGNED     NULL DEFAULT NULL,
    name          VARCHAR(60)      NOT NULL,
    capacity      TINYINT UNSIGNED NOT NULL DEFAULT 2,
    sort_order    SMALLINT         NOT NULL DEFAULT 0,
    is_active     TINYINT(1)       NOT NULL DEFAULT 1,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    FOREIGN KEY (area_id)       REFERENCES restaurant_areas(id) ON DELETE SET NULL,
    INDEX idx_table_rest (restaurant_id),
    INDEX idx_table_area (area_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Merge grupe (header zapisa)
CREATE TABLE IF NOT EXISTS restaurant_table_merge_groups (
    id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT UNSIGNED     NOT NULL,
    name          VARCHAR(100)     NULL,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    INDEX idx_mg_rest (restaurant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Člani merge grup (M:N)
CREATE TABLE IF NOT EXISTS restaurant_table_merge_members (
    merge_group_id INT UNSIGNED NOT NULL,
    table_id       INT UNSIGNED NOT NULL,
    PRIMARY KEY (merge_group_id, table_id),
    FOREIGN KEY (merge_group_id) REFERENCES restaurant_table_merge_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (table_id)       REFERENCES restaurant_tables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Dodelitev miz rezervacijam
CREATE TABLE IF NOT EXISTS reservation_table_assignments (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT UNSIGNED NOT NULL,
    table_id       INT UNSIGNED NOT NULL,
    merge_group_id INT UNSIGNED NULL DEFAULT NULL,
    assigned_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by    INT UNSIGNED NULL DEFAULT NULL,
    UNIQUE KEY uq_res_table (reservation_id, table_id),
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
    FOREIGN KEY (table_id)       REFERENCES restaurant_tables(id) ON DELETE CASCADE,
    FOREIGN KEY (merge_group_id) REFERENCES restaurant_table_merge_groups(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_by)    REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_rta_reservation (reservation_id),
    INDEX idx_rta_table (table_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
