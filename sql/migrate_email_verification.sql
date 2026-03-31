-- =============================================
-- Migracija: email verifikacija + password reset
-- ZAŽENITE ENKRAT na obstoječi bazi!
-- =============================================

ALTER TABLE users
    ADD COLUMN email_verified_at  DATETIME     NULL DEFAULT NULL AFTER email,
    ADD COLUMN verification_token VARCHAR(64)  NULL DEFAULT NULL,
    ADD COLUMN reset_token        VARCHAR(64)  NULL DEFAULT NULL,
    ADD COLUMN reset_token_expires DATETIME    NULL DEFAULT NULL;

-- Obstoječi superadmin in userji so že preverjeni
UPDATE users SET email_verified_at = created_at WHERE role IN ('superadmin', 'user');

-- Obstoječi admini (migrirani) so tudi preverjeni
UPDATE users SET email_verified_at = created_at WHERE role = 'admin';
