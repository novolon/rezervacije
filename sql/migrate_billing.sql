-- =============================================
-- Billing migracija – paketi in naročnine
-- =============================================

-- Naročnine (ena aktivna na admin)
CREATE TABLE IF NOT EXISTS subscriptions (
    id                      INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    user_id                 INT UNSIGNED  NOT NULL,
    plan_slug               ENUM('trial','basic','advanced','premium') NOT NULL DEFAULT 'trial',
    status                  ENUM('trial','active','canceled','expired','payment_failed','pending_invoice') NOT NULL DEFAULT 'trial',
    billing_cycle           ENUM('monthly','yearly') NULL DEFAULT NULL,    -- NULL za trial
    payment_method          ENUM('stripe','invoice') NULL DEFAULT NULL,    -- NULL za trial
    started_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ends_at                 DATETIME      NULL DEFAULT NULL,               -- NULL = trajno (manual grant)
    stripe_subscription_id  VARCHAR(100)  NULL DEFAULT NULL,
    stripe_customer_id      VARCHAR(100)  NULL DEFAULT NULL,
    assigned_by             INT UNSIGNED  NULL DEFAULT NULL,               -- superadmin ki je ročno dodelil
    created_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Začasni popusti (superadmin)
CREATE TABLE IF NOT EXISTS plan_discounts (
    id                      INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    plan_slug               ENUM('basic','advanced','premium') NOT NULL,
    label                   VARCHAR(120)  NOT NULL,                        -- npr. "Pomladna akcija"
    discounted_monthly      DECIMAL(8,2)  NULL DEFAULT NULL,
    discounted_yearly       DECIMAL(8,2)  NULL DEFAULT NULL,
    valid_from              DATETIME      NOT NULL,
    valid_until             DATETIME      NOT NULL,
    is_active               TINYINT(1)    NOT NULL DEFAULT 1,
    created_by              INT UNSIGNED  NULL DEFAULT NULL,
    created_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migracija: obstoječi admin računi → trial vnos v subscriptions
INSERT INTO subscriptions (user_id, plan_slug, status, started_at, ends_at)
SELECT
    id,
    'trial',
    CASE
        WHEN subscription_status = 'trial'    AND (trial_ends_at IS NULL OR trial_ends_at > NOW()) THEN 'trial'
        WHEN subscription_status = 'active'   THEN 'active'
        ELSE 'expired'
    END,
    created_at,
    trial_ends_at
FROM users
WHERE role = 'admin'
  AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = users.id);
