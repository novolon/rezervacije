-- ─── Migracija: AI Help Chat (assistant za uporabnike v Rezble appu) ──────────
-- Zaženi enkrat. Varno ponoviti (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS help_chat_conversations (
  id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id                  INT UNSIGNED NULL,
  restaurant_id            INT UNSIGNED NULL,
  user_name                VARCHAR(120) NULL,
  user_email               VARCHAR(255) NULL,
  user_role                VARCHAR(20)  NULL COMMENT 'admin, user, superadmin',
  user_lang                VARCHAR(5)   NULL COMMENT 'sl, en, de, ...',
  title                    VARCHAR(255) NULL COMMENT 'Avto-derived iz prvega vprašanja',
  message_count            INT UNSIGNED NOT NULL DEFAULT 0,
  total_input_tokens       INT UNSIGNED NOT NULL DEFAULT 0,
  total_output_tokens      INT UNSIGNED NOT NULL DEFAULT 0,
  total_cache_create_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  total_cache_read_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
  total_cost_usd           DECIMAL(10,6) NOT NULL DEFAULT 0,
  model                    VARCHAR(60)  NULL COMMENT 'claude-haiku-4-5-20251001',
  created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_message_at          DATETIME     NULL,
  INDEX idx_hcc_user (user_id),
  INDEX idx_hcc_created (created_at),
  FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE SET NULL,
  FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS help_chat_messages (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id     INT UNSIGNED NOT NULL,
  role                ENUM('user','assistant') NOT NULL,
  content             MEDIUMTEXT  NOT NULL,
  input_tokens        INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens       INT UNSIGNED NOT NULL DEFAULT 0,
  cache_create_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  cache_read_tokens   INT UNSIGNED NOT NULL DEFAULT 0,
  cost_usd            DECIMAL(10,6) NOT NULL DEFAULT 0,
  latency_ms          INT UNSIGNED NULL COMMENT 'Server-side čas API klica',
  refused             TINYINT(1)  NOT NULL DEFAULT 0 COMMENT 'AI je zavrnil (off-topic)',
  created_at          DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hcm_conv (conversation_id),
  INDEX idx_hcm_created (created_at),
  FOREIGN KEY (conversation_id) REFERENCES help_chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
