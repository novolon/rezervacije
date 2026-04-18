-- =============================================
-- Trial v2 migracija – trial ni več ločen paket
-- =============================================
-- Obstoječe naročnine s plan_slug='trial' posodobimo na 'basic',
-- ker je privzeti paket za nov trial zdaj Basic.
-- Status ostane 'trial' – označuje brezplačno obdobje.

UPDATE subscriptions
SET plan_slug = 'basic'
WHERE plan_slug = 'trial';
