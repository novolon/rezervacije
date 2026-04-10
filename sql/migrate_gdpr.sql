-- =============================================
-- GDPR migracija – Modul 1
-- Zaženite enkrat na produkcijski bazi.
-- =============================================

-- 1. Tabela users – GDPR & soft delete stolpci
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS gdpr_consent_at      DATETIME  NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS gdpr_consent_ip      VARCHAR(45) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS dpa_consent_at       DATETIME  NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS marketing_consent    TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS marketing_consent_at DATETIME  NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS deleted_at           DATETIME  NULL DEFAULT NULL;

-- 2. Tabela reservations – GDPR soglasje gosta (self-booking)
ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS gdpr_consent      TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS gdpr_consent_at   DATETIME   NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS gdpr_consent_ip   VARCHAR(45) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS marketing_consent TINYINT(1) NOT NULL DEFAULT 0;

-- 3. Nova tabela gdpr_requests
CREATE TABLE IF NOT EXISTS gdpr_requests (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type             ENUM('access','rectification','erasure','portability') NOT NULL,
  requester_email  VARCHAR(255) NOT NULL,
  restaurant_id    INT UNSIGNED NULL DEFAULT NULL,
  status           ENUM('pending','processing','completed','rejected') NOT NULL DEFAULT 'pending',
  notes            TEXT NULL,
  requested_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at      DATETIME NULL DEFAULT NULL,
  INDEX idx_email  (requester_email),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
