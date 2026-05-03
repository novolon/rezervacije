-- ─── Migracija: Blog "Booked" ────────────────────────────────────────────────
-- Marketinški blog z večjezičnimi članki, AI-asistiranim pisanjem in odobritvenim workflowom.
-- Vsa dolgoformalna vsebina živi v DB-ju (ne v lang/*.json).
-- Zaženi enkrat. Varno ponoviti (IF NOT EXISTS).

-- ─────────────────────────────────────────────────────────────────────────────
-- Avtorji (E-E-A-T: pravi avtorji izboljšujejo SEO)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS blog_authors (
  id                INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  slug              VARCHAR(64)   NOT NULL UNIQUE,
  name              VARCHAR(255)  NOT NULL,
  avatar_url        VARCHAR(500)  NULL,
  bio_translations  JSON          NULL,           -- {sl:"...",en:"..."}
  social_links      JSON          NULL,           -- {twitter:"...",linkedin:"..."}
  display_order     INT           NOT NULL DEFAULT 0,
  is_active         TINYINT(1)    NOT NULL DEFAULT 1,
  created_at        DATETIME      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Kategorije + njihovi prevodi
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS blog_categories (
  id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  slug          VARCHAR(120)  NOT NULL UNIQUE,
  display_order INT           NOT NULL DEFAULT 0,
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  created_at    DATETIME      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_category_translations (
  category_id      INT UNSIGNED  NOT NULL,
  lang_code        VARCHAR(5)    NOT NULL,
  name             VARCHAR(255)  NOT NULL,
  description      TEXT          NULL,
  meta_title       VARCHAR(255)  NULL,
  meta_description VARCHAR(500)  NULL,
  PRIMARY KEY (category_id, lang_code),
  FOREIGN KEY (category_id) REFERENCES blog_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Tagi + njihovi prevodi
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS blog_tags (
  id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(120)  NOT NULL UNIQUE,
  created_at  DATETIME      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_tag_translations (
  tag_id     INT UNSIGNED  NOT NULL,
  lang_code  VARCHAR(5)    NOT NULL,
  name       VARCHAR(120)  NOT NULL,
  PRIMARY KEY (tag_id, lang_code),
  FOREIGN KEY (tag_id) REFERENCES blog_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Media library (slike za blog)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS blog_media (
  id                    INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  filename              VARCHAR(255)  NOT NULL,        -- na disku, npr. abc123.jpg
  original_name         VARCHAR(255)  NULL,
  mime_type             VARCHAR(100)  NOT NULL,
  byte_size             INT UNSIGNED  NOT NULL,
  width                 INT UNSIGNED  NULL,
  height                INT UNSIGNED  NULL,
  variants              JSON          NULL,            -- {480:"path",800:"...",1200:"...",1920:"..."}
  dominant_color        VARCHAR(7)    NULL,            -- "#aabbcc"
  alt_translations      JSON          NULL,            -- {sl:"...",en:"..."}
  caption_translations  JSON          NULL,
  uploaded_by           INT UNSIGNED  NULL,
  created_at            DATETIME      DEFAULT CURRENT_TIMESTAMP,
  KEY idx_uploaded_by (uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Glavna entiteta članka (master)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS blog_posts (
  id                INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  master_lang       VARCHAR(5)    NOT NULL DEFAULT 'sl',
  category_id       INT UNSIGNED  NULL,
  author_id         INT UNSIGNED  NULL,
  hero_media_id     INT UNSIGNED  NULL,
  status            ENUM('draft','pending_review','scheduled','published','archived') NOT NULL DEFAULT 'draft',
  published_at      DATETIME      NULL,
  scheduled_at      DATETIME      NULL,
  ai_generated      TINYINT(1)    NOT NULL DEFAULT 0,
  ai_model          VARCHAR(50)   NULL,
  ai_prompt         TEXT          NULL,
  ai_topic          VARCHAR(255)  NULL,
  view_count        INT UNSIGNED  NOT NULL DEFAULT 0,
  created_by        INT UNSIGNED  NULL,
  approved_by       INT UNSIGNED  NULL,
  approved_at       DATETIME      NULL,
  created_at        DATETIME      DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status_published (status, published_at),
  KEY idx_category (category_id),
  KEY idx_author   (author_id),
  FOREIGN KEY (category_id)   REFERENCES blog_categories(id) ON DELETE SET NULL,
  FOREIGN KEY (author_id)     REFERENCES blog_authors(id)    ON DELETE SET NULL,
  FOREIGN KEY (hero_media_id) REFERENCES blog_media(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Per-language vsebina (slug, naslov, content, meta tags)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS blog_post_translations (
  id                    INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  post_id               INT UNSIGNED  NOT NULL,
  lang_code             VARCHAR(5)    NOT NULL,
  slug                  VARCHAR(200)  NOT NULL,
  title                 VARCHAR(500)  NOT NULL,
  excerpt               VARCHAR(500)  NULL,
  content_md            MEDIUMTEXT    NOT NULL,
  content_html          MEDIUMTEXT    NOT NULL,
  meta_title            VARCHAR(255)  NULL,
  meta_description      VARCHAR(500)  NULL,
  og_image_media_id     INT UNSIGNED  NULL,
  table_of_contents     JSON          NULL,           -- [{level:2,text:"...",anchor:"..."}]
  reading_time_minutes  INT UNSIGNED  NOT NULL DEFAULT 0,
  word_count            INT UNSIGNED  NOT NULL DEFAULT 0,
  status                ENUM('draft','pending_review','approved','rejected') NOT NULL DEFAULT 'draft',
  ai_translated         TINYINT(1)    NOT NULL DEFAULT 0,
  approved_by           INT UNSIGNED  NULL,
  approved_at           DATETIME      NULL,
  view_count            INT UNSIGNED  NOT NULL DEFAULT 0,
  created_at            DATETIME      DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_post_lang  (post_id, lang_code),
  UNIQUE KEY uq_lang_slug  (lang_code, slug),
  KEY idx_status_lang (status, lang_code),
  FULLTEXT KEY ft_search (title, excerpt, content_md),
  FOREIGN KEY (post_id)            REFERENCES blog_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (og_image_media_id)  REFERENCES blog_media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Many-to-many tagov
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS blog_post_tags (
  post_id  INT UNSIGNED  NOT NULL,
  tag_id   INT UNSIGNED  NOT NULL,
  PRIMARY KEY (post_id, tag_id),
  FOREIGN KEY (post_id) REFERENCES blog_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id)  REFERENCES blog_tags(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Topic queue (kandidati za AI drafter cron)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS blog_topic_queue (
  id                  INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  topic               VARCHAR(500)  NOT NULL,
  brief               TEXT          NULL,
  target_keyword      VARCHAR(255)  NULL,
  desired_lang        VARCHAR(5)    NOT NULL DEFAULT 'sl',
  desired_word_count  INT UNSIGNED  NOT NULL DEFAULT 1200,
  scheduled_for       DATE          NULL,
  status              ENUM('queued','generated','rejected','skipped') NOT NULL DEFAULT 'queued',
  generated_post_id   INT UNSIGNED  NULL,
  notes               TEXT          NULL,
  created_by          INT UNSIGNED  NULL,
  created_at          DATETIME      DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status_scheduled (status, scheduled_for),
  FOREIGN KEY (generated_post_id) REFERENCES blog_posts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Newsletter prijave (double opt-in)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS blog_subscribers (
  id               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  email            VARCHAR(255)  NOT NULL UNIQUE,
  lang_code        VARCHAR(5)    NOT NULL DEFAULT 'sl',
  confirm_token    VARCHAR(64)   NULL UNIQUE,
  confirmed_at     DATETIME      NULL,
  unsubscribed_at  DATETIME      NULL,
  source           VARCHAR(50)   NULL,                -- "inline_post","footer","exit_intent"
  ip_address       VARCHAR(45)   NULL,
  user_agent       VARCHAR(500)  NULL,
  created_at       DATETIME      DEFAULT CURRENT_TIMESTAMP,
  KEY idx_confirmed (confirmed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Editor revizije (audit log za content_md)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS blog_revisions (
  id                   INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  translation_id       INT UNSIGNED  NOT NULL,
  content_md_snapshot  MEDIUMTEXT    NOT NULL,
  edited_by            INT UNSIGNED  NULL,
  created_at           DATETIME      DEFAULT CURRENT_TIMESTAMP,
  KEY idx_translation_created (translation_id, created_at),
  FOREIGN KEY (translation_id) REFERENCES blog_post_translations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Začetni podatki: privzete kategorije + Rezble Team avtor
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO blog_categories (slug, display_order) VALUES
  ('operativa',            10),
  ('marketing-gostincev',  20),
  ('tehnologija',          30),
  ('goste',                40),
  ('kuhinja',              50);

INSERT IGNORE INTO blog_category_translations (category_id, lang_code, name, description) VALUES
  ((SELECT id FROM blog_categories WHERE slug='operativa'),           'sl', 'Operativa',          'Vsakodnevno vodenje, no-show preprečevanje, optimizacija miz, urniki.'),
  ((SELECT id FROM blog_categories WHERE slug='operativa'),           'en', 'Operations',         'Daily ops, no-show prevention, table optimization, scheduling.'),
  ((SELECT id FROM blog_categories WHERE slug='marketing-gostincev'), 'sl', 'Marketing',          'Pridobivanje in zadrževanje gostov, družbena omrežja, lojalnost.'),
  ((SELECT id FROM blog_categories WHERE slug='marketing-gostincev'), 'en', 'Marketing',          'Getting and keeping guests, social media, loyalty.'),
  ((SELECT id FROM blog_categories WHERE slug='tehnologija'),         'sl', 'Tehnologija',        'Digitalna orodja, sistemi, integracije za gostinstvo.'),
  ((SELECT id FROM blog_categories WHERE slug='tehnologija'),         'en', 'Technology',         'Digital tools, systems, integrations for hospitality.'),
  ((SELECT id FROM blog_categories WHERE slug='goste'),               'sl', 'Gostje',             'Izkušnja gosta, ankete, lojalnost, povratne informacije.'),
  ((SELECT id FROM blog_categories WHERE slug='goste'),               'en', 'Guests',             'Guest experience, surveys, loyalty, feedback.'),
  ((SELECT id FROM blog_categories WHERE slug='kuhinja'),             'sl', 'Kuhinja',            'Trendi, meniji, sezonsko, kuharji.'),
  ((SELECT id FROM blog_categories WHERE slug='kuhinja'),             'en', 'Kitchen',            'Trends, menus, seasonal, chefs.');

INSERT IGNORE INTO blog_authors (slug, name, bio_translations, social_links) VALUES
  ('rezble-team',
   'Rezble Team',
   JSON_OBJECT(
     'sl', 'Ekipa, ki gradi Rezble — sistem za rezervacije za sodobne restavracije.',
     'en', 'The team building Rezble — the reservation system for modern restaurants.'
   ),
   JSON_OBJECT('website', 'https://app.rezervacije.si'));
