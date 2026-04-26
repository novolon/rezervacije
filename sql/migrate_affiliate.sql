-- =============================================
-- Affiliate program + Discount codes migracija
-- =============================================

-- ─── Affiliate računi ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS affiliates (
  id                       INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  ref_code                 VARCHAR(12)   NOT NULL UNIQUE,
  email                    VARCHAR(180)  NOT NULL UNIQUE,
  email_verified_at        DATETIME      NULL,
  password_hash            VARCHAR(255)  NOT NULL,
  full_name                VARCHAR(160)  NOT NULL,
  legal_form               ENUM('individual','sole_trader','company','foreign') NOT NULL DEFAULT 'individual',
  company_name             VARCHAR(180)  NULL,
  tax_number               VARCHAR(40)   NULL,
  vat_id                   VARCHAR(40)   NULL,
  iban                     VARCHAR(34)   NULL,
  bic                      VARCHAR(11)   NULL,
  address                  VARCHAR(255)  NULL,
  city                     VARCHAR(80)   NULL,
  postal_code              VARCHAR(15)   NULL,
  country                  CHAR(2)       NOT NULL DEFAULT 'SI',
  -- provizijska konfiguracija (NULL = global default)
  commission_percent       DECIMAL(5,2)  NULL,
  commission_flat_eur      DECIMAL(8,2)  NULL,
  commission_window_months SMALLINT UNSIGNED NULL,
  hold_days                SMALLINT UNSIGNED NULL,
  min_payout_eur           DECIMAL(8,2)  NULL,
  -- popustna koda (grant od superadmina)
  discount_enabled         TINYINT(1)    NOT NULL DEFAULT 0,
  discount_percent         DECIMAL(5,2)  NULL,
  discount_duration        ENUM('once','repeating','forever') NULL,
  discount_duration_months SMALLINT UNSIGNED NULL,
  discount_code_id         INT UNSIGNED  NULL,
  -- status
  status                   ENUM('pending','active','suspended','rejected') NOT NULL DEFAULT 'pending',
  rejected_reason          VARCHAR(255)  NULL,
  -- consent / GDPR
  terms_accepted_at        DATETIME      NOT NULL,
  terms_version            VARCHAR(20)   NOT NULL DEFAULT '1.0',
  marketing_consent        TINYINT(1)    NOT NULL DEFAULT 0,
  -- auth tokens
  remember_token           VARCHAR(255)  NULL,
  remember_expires         DATETIME      NULL,
  reset_token              VARCHAR(64)   NULL,
  reset_token_expires      DATETIME      NULL,
  verification_token       VARCHAR(64)   NULL,
  -- meta
  approved_by              INT UNSIGNED  NULL,
  approved_at              DATETIME      NULL,
  created_at               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_status   (status),
  INDEX idx_ref_code (ref_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Klikovni log ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS affiliate_clicks (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  affiliate_id INT UNSIGNED  NOT NULL,
  ref_code     VARCHAR(12)   NOT NULL,
  ip_hash      CHAR(64)      NOT NULL,
  user_agent   VARCHAR(255)  NULL,
  referer      VARCHAR(512)  NULL,
  landing_url  VARCHAR(512)  NULL,
  utm_source   VARCHAR(80)   NULL,
  utm_medium   VARCHAR(80)   NULL,
  utm_campaign VARCHAR(120)  NULL,
  is_bot       TINYINT(1)    NOT NULL DEFAULT 0,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
  INDEX idx_aff_date (affiliate_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Referrali (affiliate ↔ registriran user) ────────────────────
CREATE TABLE IF NOT EXISTS affiliate_referrals (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  affiliate_id     INT UNSIGNED  NOT NULL,
  user_id          INT UNSIGNED  NOT NULL UNIQUE,
  ref_code         VARCHAR(12)   NOT NULL,
  cookie_set_at    DATETIME      NULL,
  landing_url      VARCHAR(512)  NULL,
  utm_source       VARCHAR(80)   NULL,
  utm_medium       VARCHAR(80)   NULL,
  utm_campaign     VARCHAR(120)  NULL,
  first_paid_at    DATETIME      NULL,
  commission_until DATETIME      NULL,
  attribution      ENUM('cookie','code','url') NOT NULL DEFAULT 'cookie',
  status           ENUM('signed_up','converted','churned','rejected') NOT NULL DEFAULT 'signed_up',
  rejected_reason  VARCHAR(255)  NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE CASCADE,
  INDEX idx_affiliate (affiliate_id),
  INDEX idx_status    (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Provizije ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS affiliate_commissions (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  affiliate_id      INT UNSIGNED  NOT NULL,
  referral_id       BIGINT UNSIGNED NOT NULL,
  user_id           INT UNSIGNED  NOT NULL,
  subscription_id   INT UNSIGNED  NULL,
  stripe_invoice_id VARCHAR(100)  NULL,
  base_amount_eur   DECIMAL(10,2) NOT NULL,
  percent           DECIMAL(5,2)  NULL,
  flat_amount_eur   DECIMAL(10,2) NULL,
  amount_eur        DECIMAL(10,2) NOT NULL,
  status            ENUM('pending','payable','paid','void','clawback') NOT NULL DEFAULT 'pending',
  available_at      DATETIME      NOT NULL,
  payout_id         BIGINT UNSIGNED NULL,
  void_reason       VARCHAR(255)  NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (affiliate_id)    REFERENCES affiliates(id)          ON DELETE CASCADE,
  FOREIGN KEY (referral_id)     REFERENCES affiliate_referrals(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)         REFERENCES users(id)               ON DELETE CASCADE,
  FOREIGN KEY (subscription_id) REFERENCES subscriptions(id)       ON DELETE SET NULL,
  INDEX idx_aff_status     (affiliate_id, status),
  INDEX idx_available_at   (available_at),
  INDEX idx_stripe_invoice (stripe_invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Izplačila (batch) ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS affiliate_payouts (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  affiliate_id  INT UNSIGNED  NOT NULL,
  amount_eur    DECIMAL(10,2) NOT NULL,
  currency      CHAR(3)       NOT NULL DEFAULT 'EUR',
  status        ENUM('processing','paid','failed') NOT NULL DEFAULT 'processing',
  iban_snapshot VARCHAR(34)   NOT NULL,
  reference     VARCHAR(40)   NOT NULL UNIQUE,
  notes         TEXT          NULL,
  created_by    INT UNSIGNED  NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at       DATETIME      NULL,
  FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE RESTRICT,
  FOREIGN KEY (created_by)   REFERENCES users(id)      ON DELETE SET NULL,
  INDEX idx_aff_status (affiliate_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Popustne kode ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS discount_codes (
  id                  INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(40)   NOT NULL UNIQUE,
  description         VARCHAR(180)  NULL,
  percent_off         DECIMAL(5,2)  NULL,
  amount_off_eur      DECIMAL(8,2)  NULL,
  applies_to_plans    VARCHAR(60)   NULL,
  applies_to_cycles   VARCHAR(20)   NULL,
  duration            ENUM('once','repeating','forever') NOT NULL DEFAULT 'once',
  duration_months     SMALLINT UNSIGNED NULL,
  max_redemptions     INT UNSIGNED  NULL,
  redemption_count    INT UNSIGNED  NOT NULL DEFAULT 0,
  one_per_user        TINYINT(1)    NOT NULL DEFAULT 1,
  valid_from          DATETIME      NULL,
  valid_until         DATETIME      NULL,
  owner_affiliate_id  INT UNSIGNED  NULL,
  stripe_coupon_id    VARCHAR(100)  NULL,
  stripe_promo_id     VARCHAR(100)  NULL,
  is_active           TINYINT(1)    NOT NULL DEFAULT 1,
  created_by          INT UNSIGNED  NULL,
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_affiliate_id) REFERENCES affiliates(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by)         REFERENCES users(id)      ON DELETE SET NULL,
  INDEX idx_active (is_active),
  INDEX idx_owner  (owner_affiliate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FK: affiliates.discount_code_id → discount_codes
ALTER TABLE affiliates
  ADD CONSTRAINT fk_aff_discount_code
  FOREIGN KEY (discount_code_id) REFERENCES discount_codes(id) ON DELETE SET NULL;

-- ─── Unovčenja popustnih kod ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS discount_code_redemptions (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code_id           INT UNSIGNED  NOT NULL,
  user_id           INT UNSIGNED  NOT NULL,
  subscription_id   INT UNSIGNED  NULL,
  stripe_invoice_id VARCHAR(100)  NULL,
  amount_off_eur    DECIMAL(10,2) NOT NULL,
  redeemed_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (code_id)         REFERENCES discount_codes(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)         REFERENCES users(id)          ON DELETE CASCADE,
  FOREIGN KEY (subscription_id) REFERENCES subscriptions(id)  ON DELETE SET NULL,
  INDEX idx_code_user (code_id, user_id),
  INDEX idx_invoice   (stripe_invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Globalna konfiguracija ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS affiliate_settings (
  setting_key   VARCHAR(60)  NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO affiliate_settings (setting_key, setting_value) VALUES
  ('default_commission_percent',    '20.00'),
  ('default_commission_window_m',   '12'),
  ('default_hold_days',             '45'),
  ('default_min_payout_eur',        '30.00'),
  ('cookie_ttl_days',               '60'),
  ('terms_version',                 '1.0'),
  ('default_discount_percent',      '10.00'),
  ('default_discount_duration',     'once'),
  ('max_discount_percent',          '30.00');
