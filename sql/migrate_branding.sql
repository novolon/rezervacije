-- migrate_branding.sql
-- Premium branding (logo, barve, hide "by Rezble") in custom email pošiljanje (Mailgun custom / SMTP).
-- Vsa polja so opcijska in nullable; default = privzeto vedenje (Mailgun, brez brandinga).

ALTER TABLE restaurants
    ADD COLUMN IF NOT EXISTS logo_path           VARCHAR(255) NULL
        COMMENT 'Relative path do uploadanega logotipa (premium).',
    ADD COLUMN IF NOT EXISTS brand_primary       VARCHAR(7)   NULL
        COMMENT 'Primarna barva za widget/book (#RRGGBB, premium).',
    ADD COLUMN IF NOT EXISTS brand_secondary     VARCHAR(7)   NULL
        COMMENT 'Sekundarna barva za widget/book (#RRGGBB, premium).',
    ADD COLUMN IF NOT EXISTS hide_branding       TINYINT(1)   NOT NULL DEFAULT 0
        COMMENT 'Če je 1, skrij "by Rezble" link (premium only).',
    ADD COLUMN IF NOT EXISTS email_provider      ENUM('default','mailgun','smtp') NOT NULL DEFAULT 'default'
        COMMENT 'Kateri provider naj pošlje email iz te restavracije.',
    ADD COLUMN IF NOT EXISTS email_settings_enc  TEXT         NULL
        COMMENT 'Šifrirani credentials (AES-256-GCM), JSON: domain/api_key (mailgun) ali host/port/user/pass (smtp).',
    ADD COLUMN IF NOT EXISTS email_from_name     VARCHAR(100) NULL
        COMMENT 'From ime na izhodnih emailih (npr. "Restavracija Lipa").',
    ADD COLUMN IF NOT EXISTS email_from_address  VARCHAR(255) NULL
        COMMENT 'From email naslov (mora pripadati lastni domeni / SMTP-ju).',
    ADD COLUMN IF NOT EXISTS email_verified_at   TIMESTAMP    NULL
        COMMENT 'Čas zadnje uspešne verifikacije custom emaila (test send OK).';
