-- Račun Hub: shrani Hub invoice ID ob vsakem plačilu naročnine
ALTER TABLE subscriptions
    ADD COLUMN IF NOT EXISTS hub_invoice_id VARCHAR(64) NULL;
