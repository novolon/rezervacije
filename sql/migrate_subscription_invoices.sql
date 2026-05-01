-- Tabela za zgodovino Hub računov (eden na Stripe invoice, ne eden na subscription)
CREATE TABLE IF NOT EXISTS subscription_invoices (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT          NOT NULL,
    subscription_id  INT          NULL,
    hub_invoice_id   VARCHAR(64)  NOT NULL,
    stripe_invoice_id VARCHAR(64) NULL,
    plan_slug        VARCHAR(32)  NOT NULL DEFAULT 'basic',
    billing_cycle    VARCHAR(16)  NOT NULL DEFAULT 'monthly',
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hub_invoice (hub_invoice_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migriraj obstoječe hub_invoice_id iz subscriptions
INSERT IGNORE INTO subscription_invoices
    (user_id, subscription_id, hub_invoice_id, plan_slug, billing_cycle, created_at)
SELECT user_id, id, hub_invoice_id, plan_slug, billing_cycle, created_at
FROM subscriptions
WHERE hub_invoice_id IS NOT NULL;
