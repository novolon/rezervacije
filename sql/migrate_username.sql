-- =============================================
-- Migracija: dodaj username, email postane neobvezen
-- ZAŽENITE ENKRAT na obstoječi bazi!
-- =============================================

-- Dodaj username kolono
ALTER TABLE users
    ADD COLUMN username VARCHAR(60) NULL DEFAULT NULL AFTER email,
    ADD UNIQUE INDEX idx_users_username (username);

-- Email ni več obvezen (admin-dodani userji ga ne rabijo)
ALTER TABLE users
    MODIFY COLUMN email VARCHAR(180) NULL DEFAULT NULL;
