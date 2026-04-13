-- Modul 2: Čakalna lista
-- Zaženi enkrat na produkcijski bazi

CREATE TABLE IF NOT EXISTS waitlist (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  restaurant_id   INT UNSIGNED NOT NULL,
  date            DATE NOT NULL,
  time_preference VARCHAR(10) NULL,          -- npr. "19:00" ali NULL (kdorkoli)
  guests          INT NOT NULL DEFAULT 1,
  first_name      VARCHAR(100) NOT NULL,
  last_name       VARCHAR(100) NOT NULL,
  email           VARCHAR(255) NOT NULL,
  phone           VARCHAR(30) NULL,
  gdpr_consent    TINYINT(1) DEFAULT 0,
  notify_via      ENUM('email','sms','both') DEFAULT 'email',
  token           VARCHAR(64) NOT NULL UNIQUE,  -- za potrditev/odjavo
  status          ENUM('waiting','notified','confirmed','expired','removed') DEFAULT 'waiting',
  notified_at     DATETIME NULL,
  expires_at      DATETIME NULL,                -- rok za potrditev (npr. +2h)
  confirmed_at    DATETIME NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rest_date_status (restaurant_id, date, status),
  FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
);
