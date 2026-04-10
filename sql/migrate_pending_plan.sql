-- Doda pending_plan stolpec v users tabelo
-- Shranjuje zahtevani paket ob registraciji z landing page

ALTER TABLE users
    ADD COLUMN pending_plan VARCHAR(20) NULL DEFAULT NULL
    AFTER subscription_status;

-- Popravi obstoječe admin račune brez subscription zapisa
-- (registrirani preden je register.php začel ustvarjati subscription vrstico)
INSERT INTO subscriptions (user_id, plan_slug, status, started_at, ends_at)
SELECT
    id,
    'trial',
    CASE
        WHEN subscription_status = 'active' THEN 'active'
        WHEN trial_ends_at IS NOT NULL AND trial_ends_at < NOW() THEN 'expired'
        ELSE 'trial'
    END,
    created_at,
    trial_ends_at
FROM users
WHERE role = 'admin'
  AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = users.id);
