-- migrate_notify_guest_email.sql
-- Doda toggle: ali se ob ustvarjeni rezervaciji (interno) gostu pošlje email s potrditvijo.
-- Default = 1 (ohrani staro vedenje).

ALTER TABLE restaurants
    ADD COLUMN IF NOT EXISTS notify_guest_email TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Pošlji potrditveni email gostu ob ustvarjeni rezervaciji.';
