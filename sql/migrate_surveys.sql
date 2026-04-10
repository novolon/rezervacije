-- ─── Migracija: ankete o zadovoljstvu ────────────────────────────────────────
-- Zaženi enkrat. Varno ponoviti (IF NOT EXISTS).

ALTER TABLE reservations ADD COLUMN IF NOT EXISTS arrived_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS survey_forms (
  id                   INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
  restaurant_id        INT UNSIGNED     NOT NULL,
  title                VARCHAR(255)     NOT NULL DEFAULT 'Anketa o zadovoljstvu',
  description          TEXT,
  thank_you_message    TEXT,
  send_enabled         TINYINT(1)       NOT NULL DEFAULT 0,
  send_delay_hours     INT              NOT NULL DEFAULT 2,
  include_thankyou     TINYINT(1)       NOT NULL DEFAULT 1,
  include_survey       TINYINT(1)       NOT NULL DEFAULT 1,
  is_active            TINYINT(1)       NOT NULL DEFAULT 1,
  created_at           DATETIME         DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS survey_questions (
  id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
  survey_id     INT UNSIGNED     NOT NULL,
  sort_order    INT              NOT NULL DEFAULT 0,
  question_text TEXT             NOT NULL,
  type          ENUM('checkbox','rating','radio','text','textarea') NOT NULL,
  is_required   TINYINT(1)       NOT NULL DEFAULT 0,
  created_at    DATETIME         DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (survey_id) REFERENCES survey_forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS survey_question_options (
  id          INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
  question_id INT UNSIGNED     NOT NULL,
  sort_order  INT              NOT NULL DEFAULT 0,
  label       VARCHAR(255)     NOT NULL,
  FOREIGN KEY (question_id) REFERENCES survey_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS survey_responses (
  id                INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
  survey_id         INT UNSIGNED     NOT NULL,
  reservation_id    INT UNSIGNED     NOT NULL,
  email             VARCHAR(255)     NOT NULL,
  token             VARCHAR(64)      NOT NULL UNIQUE,
  scheduled_send_at DATETIME         NOT NULL,
  email_sent_at     DATETIME         NULL,
  consent           ENUM('public','anonymous','private') NULL,
  submitted_at      DATETIME         NULL,
  created_at        DATETIME         DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (survey_id)      REFERENCES survey_forms(id)  ON DELETE CASCADE,
  FOREIGN KEY (reservation_id) REFERENCES reservations(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS survey_answers (
  id          INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
  response_id INT UNSIGNED     NOT NULL,
  question_id INT UNSIGNED     NOT NULL,
  answer_text TEXT,
  option_ids  TEXT,
  FOREIGN KEY (response_id) REFERENCES survey_responses(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES survey_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
