-- =============================================
-- SaaS Migracija – safe ALTER za obstoječo bazo
-- ZAŽENITE ENKRAT pred prvo uporabo SaaS verzije!
-- =============================================

-- 1. ── users tabela ──────────────────────────────────────────

-- Dodaj email kolono (nullable za migrацijo)
ALTER TABLE users
    ADD COLUMN email VARCHAR(180) NULL AFTER id;

-- Nastavi email = username@migrated.local za obstoječe userje
UPDATE users SET email = CONCAT(username, '@migrated.local') WHERE email IS NULL;

-- Naredi email NOT NULL in dodaj UNIQUE index
ALTER TABLE users MODIFY COLUMN email VARCHAR(180) NOT NULL;
CREATE UNIQUE INDEX idx_users_email ON users(email);

-- Razširi role ENUM za 'superadmin'
ALTER TABLE users
    MODIFY COLUMN role ENUM('superadmin','admin','user') NOT NULL DEFAULT 'user';

-- Dodaj billing hook kolone
ALTER TABLE users
    ADD COLUMN trial_ends_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN subscription_status ENUM('trial','active','inactive') NOT NULL DEFAULT 'trial';

-- Nastavi subscription_status za obstoječe admins na 'active' (migrirani, ne trial)
UPDATE users SET subscription_status = 'active' WHERE role = 'admin';

-- 2. ── restaurants tabela ────────────────────────────────────

-- Pretvori schedule_start/end iz ur v minute (stare vrednosti <= 24 = ure)
UPDATE restaurants SET schedule_start = schedule_start * 60 WHERE schedule_start <= 24;
UPDATE restaurants SET schedule_end   = schedule_end   * 60 WHERE schedule_end   <= 24;

-- Zamenjaj tip iz TINYINT na SMALLINT (podpira minute 0-1440)
ALTER TABLE restaurants
    MODIFY COLUMN schedule_start SMALLINT UNSIGNED NOT NULL DEFAULT 480,
    MODIFY COLUMN schedule_end   SMALLINT UNSIGNED NOT NULL DEFAULT 1380;

-- Dodaj owner_id (nullable za zdaj, napolnimo spodaj)
ALTER TABLE restaurants
    ADD COLUMN owner_id INT UNSIGNED NULL AFTER name;

-- Nastavi owner_id na prvega obstoječega admina
SET @first_admin = (SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1);
UPDATE restaurants SET owner_id = @first_admin WHERE owner_id IS NULL;

-- Naredi owner_id NOT NULL in dodaj FK
ALTER TABLE restaurants MODIFY COLUMN owner_id INT UNSIGNED NOT NULL;
ALTER TABLE restaurants
    ADD CONSTRAINT fk_restaurants_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT;

-- 3. ── restaurant_admins junction tabela (nova) ─────────────

CREATE TABLE IF NOT EXISTS restaurant_admins (
    restaurant_id INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    PRIMARY KEY (restaurant_id, user_id),
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dodaj vse obstoječe admin userje k vsem obstoječim restavracijam
INSERT IGNORE INTO restaurant_admins (restaurant_id, user_id)
SELECT r.id, u.id
FROM restaurants r
CROSS JOIN users u
WHERE u.role = 'admin';

-- =============================================
-- Po migraciji:
-- 1. Odprite /setup.php → ustvarite superadmin račun
-- 2. Obstoječi admini imajo email = username@migrated.local
--    → priporočeno: vsak posodobi email pri prvem vstopu
-- 3. Izbrišite setup.php po kreaciji superadmina
-- =============================================
