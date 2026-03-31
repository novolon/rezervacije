-- =============================================
-- Rezervacijski sistem SaaS – baza podatkov
-- =============================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS restaurant_admins;
DROP TABLE IF EXISTS reservations;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS restaurants;
SET FOREIGN_KEY_CHECKS = 1;

-- Uporabniki
CREATE TABLE users (
    id                  INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
    email               VARCHAR(180)      NOT NULL UNIQUE,
    email_verified_at   DATETIME          NULL DEFAULT NULL,
    password_hash       VARCHAR(255)      NOT NULL,
    full_name           VARCHAR(120)      NOT NULL,
    role                ENUM('superadmin','admin','user') NOT NULL DEFAULT 'user',
    restaurant_id       INT UNSIGNED      NULL DEFAULT NULL,   -- samo za 'user' role
    trial_ends_at       DATETIME          NULL DEFAULT NULL,   -- billing hook
    subscription_status ENUM('trial','active','inactive') NOT NULL DEFAULT 'trial',
    is_active           TINYINT(1)        NOT NULL DEFAULT 1,
    remember_token      VARCHAR(255)      NULL DEFAULT NULL,
    remember_expires    DATETIME          NULL DEFAULT NULL,
    verification_token  VARCHAR(64)       NULL DEFAULT NULL,
    reset_token         VARCHAR(64)       NULL DEFAULT NULL,
    reset_token_expires DATETIME          NULL DEFAULT NULL,
    created_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Restavracije
CREATE TABLE restaurants (
    id                    INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
    name                  VARCHAR(120)      NOT NULL,
    owner_id              INT UNSIGNED      NOT NULL,               -- admin ki je ustvaril restavracijo
    reservation_duration  INT UNSIGNED      NOT NULL DEFAULT 60,    -- dolžina rezervacije v minutah
    allow_custom_duration TINYINT(1)        NOT NULL DEFAULT 0,     -- ali smejo userji spremeniti trajanje
    schedule_start        SMALLINT UNSIGNED NOT NULL DEFAULT 480,   -- prva možna minuta (480 = 08:00)
    schedule_end          SMALLINT UNSIGNED NOT NULL DEFAULT 1380,  -- zadnja možna minuta (1380 = 23:00)
    color                 VARCHAR(7)        NOT NULL DEFAULT '#F59E0B',
    is_active             TINYINT(1)        NOT NULL DEFAULT 1,
    created_at            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FK: users.restaurant_id → restaurants (dodan po kreaciji restaurants)
ALTER TABLE users
    ADD FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE SET NULL;

-- Admin–Restavracija junction (več adminov na restavracijo)
CREATE TABLE restaurant_admins (
    restaurant_id INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    PRIMARY KEY (restaurant_id, user_id),
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rezervacije
CREATE TABLE reservations (
    id               INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
    restaurant_id    INT UNSIGNED      NOT NULL,
    reservation_date DATE              NOT NULL,
    reservation_time TIME              NOT NULL,
    duration         INT UNSIGNED      NULL DEFAULT NULL,  -- NULL = uporabi default restavracije
    guest_name       VARCHAR(120)      NOT NULL,
    guest_count      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    email            VARCHAR(180)      NULL,
    phone            VARCHAR(30)       NULL,
    notes            TEXT              NULL,
    created_by       INT UNSIGNED      NOT NULL,
    created_at       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by)    REFERENCES users(id)       ON DELETE RESTRICT,
    INDEX idx_date_rest (reservation_date, restaurant_id),
    INDEX idx_date      (reservation_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- Po zagonu tega SQL-a obiščite /setup.php
-- da ustvarite prvega superadmin uporabnika.
-- =============================================
