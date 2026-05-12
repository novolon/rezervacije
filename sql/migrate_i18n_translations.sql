-- ─── Migracija: i18n translations za survey, areas, custom fields, guest language ──
-- Zaženi enkrat. Varno ponoviti (IF NOT EXISTS / preverja stolpce).

-- ── Survey forms translations ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS survey_form_translations (
  form_id           INT UNSIGNED  NOT NULL,
  lang_code         VARCHAR(5)    NOT NULL,
  title             VARCHAR(255)  NOT NULL,
  description       TEXT          NULL,
  thank_you_message TEXT          NULL,
  PRIMARY KEY (form_id, lang_code),
  FOREIGN KEY (form_id) REFERENCES survey_forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Survey question translations ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS survey_question_translations (
  question_id   INT UNSIGNED  NOT NULL,
  lang_code     VARCHAR(5)    NOT NULL,
  question_text TEXT          NOT NULL,
  PRIMARY KEY (question_id, lang_code),
  FOREIGN KEY (question_id) REFERENCES survey_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Survey question option translations ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS survey_question_option_translations (
  option_id INT UNSIGNED  NOT NULL,
  lang_code VARCHAR(5)    NOT NULL,
  label     VARCHAR(255)  NOT NULL,
  PRIMARY KEY (option_id, lang_code),
  FOREIGN KEY (option_id) REFERENCES survey_question_options(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Restaurant area translations ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS restaurant_area_translations (
  area_id   INT UNSIGNED  NOT NULL,
  lang_code VARCHAR(5)    NOT NULL,
  name      VARCHAR(100)  NOT NULL,
  PRIMARY KEY (area_id, lang_code),
  FOREIGN KEY (area_id) REFERENCES restaurant_areas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Custom field translations ──────────────────────────────────────────────────
-- options_json je JSON array prevedenih labelov za select type, isti vrstni red kot v
-- restaurant_custom_fields.options.
CREATE TABLE IF NOT EXISTS restaurant_custom_field_translations (
  field_id     INT          NOT NULL,
  lang_code    VARCHAR(5)   NOT NULL,
  label        VARCHAR(100) NOT NULL,
  options_json TEXT         NULL,
  PRIMARY KEY (field_id, lang_code),
  FOREIGN KEY (field_id) REFERENCES restaurant_custom_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Reservations: zapisani jezik gosta ─────────────────────────────────────────
ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS guest_language VARCHAR(5) NULL DEFAULT NULL
    COMMENT 'Jezik, v katerem je gost opravil online rezervacijo (za emails / admin info)';

-- ── Survey responses: jezik, v katerem je bila anketa poslana ──────────────────
ALTER TABLE survey_responses
  ADD COLUMN IF NOT EXISTS survey_language VARCHAR(5) NOT NULL DEFAULT 'sl'
    COMMENT 'Jezik anketnega emaila in survey strani za tega gosta';
