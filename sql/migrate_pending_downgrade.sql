-- Stolpci za načrtovano znižanje paketa / preklop cikla ob koncu période
ALTER TABLE subscriptions
    ADD COLUMN IF NOT EXISTS pending_plan_slug     VARCHAR(32) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS pending_billing_cycle VARCHAR(16) NULL DEFAULT NULL;
