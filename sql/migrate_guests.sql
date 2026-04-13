-- Migracija: Baza gostov (Modul 5)
-- Zaženi enkrat na produkciji

CREATE TABLE IF NOT EXISTS guests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id   INT UNSIGNED NOT NULL,
    email           VARCHAR(255) NOT NULL,
    first_name      VARCHAR(100),
    last_name       VARCHAR(100),
    phone           VARCHAR(30),
    notes           TEXT,
    tags            VARCHAR(500),           -- JSON array, npr. '["VIP","alergija:gluten"]'
    first_visit     DATE NULL,
    last_visit      DATE NULL,
    total_visits    INT DEFAULT 0,
    total_covers    INT DEFAULT 0,          -- skupno število gostov
    no_shows        INT DEFAULT 0,
    is_blacklisted  TINYINT(1) DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_restaurant_email (restaurant_id, email),
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
