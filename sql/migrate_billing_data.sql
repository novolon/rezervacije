-- =============================================
-- Migracija: podatki za račun (podjetje, davčna)
-- Dodaj stolpce v users tabelo
-- =============================================

ALTER TABLE users
    ADD COLUMN company_name         VARCHAR(200) NULL DEFAULT NULL AFTER full_name,
    ADD COLUMN company_address      VARCHAR(300) NULL DEFAULT NULL AFTER company_name,
    ADD COLUMN tax_number           VARCHAR(20)  NULL DEFAULT NULL AFTER company_address,
    ADD COLUMN is_vat_registered    TINYINT(1)   NOT NULL DEFAULT 0 AFTER tax_number,
    ADD COLUMN vat_id               VARCHAR(30)  NULL DEFAULT NULL AFTER is_vat_registered,
    ADD COLUMN email_change_pending VARCHAR(180) NULL DEFAULT NULL AFTER email,
    ADD COLUMN email_change_token   VARCHAR(64)  NULL DEFAULT NULL AFTER email_change_pending,
    ADD COLUMN email_change_expires DATETIME     NULL DEFAULT NULL AFTER email_change_token;
