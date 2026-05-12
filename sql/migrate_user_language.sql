-- migrate_user_language.sql
-- Doda language preference za uporabnika (set ob registraciji, used za login redirect).

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS language VARCHAR(5) NOT NULL DEFAULT 'sl'
        COMMENT 'Uporabnikov jezik vmesnika (sl/en/de/it/fr/hr/es/pt)';
