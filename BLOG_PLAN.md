# BLOG_PLAN.md – Booked

> **Status (Faza 1 končana):** baza, javni frontend, sitemap, RSS, lang chrome stringi, demo seed.
> Naslednje (Faza 2): admin UI + EasyMDE editor + media upload.

---

## Kaj je "Booked"

Marketinški blog za Rezble. Cilj: organski SEO promet → registracije.

- **URL**: `/booked` (privzeti jezik) in `/{lang}/booked` (eksplicitni jezik) za 8 jezikov: sl, en, de, it, fr, hr, es, pt
- **Brand**: "Booked — magazine za gostince, by Rezble"
- **AI**: Claude (Anthropic API) generira osnutke iz `blog_topic_queue` + prevaja master članke v 7 ostalih jezikov
- **Approval workflow**: Matic odobri vsak master članek + vsak prevod posebej
- **Vsebina v DB**, ne v lang fajlih (`blog_post_translations`)

## SEO infrastruktura (postavljena v Fazi 1)

- Vsaka stran: `<title>`, meta description, robots, canonical, OG, Twitter Card
- `<link rel="alternate" hreflang>` za vsak approved prevod + `x-default`
- JSON-LD `BlogPosting` + `BreadcrumbList` na single post; `Blog` + `Organization` na listingu
- `/sitemap.xml` z `xhtml:link rel="alternate"` per URL (cache 1h)
- `/robots.txt` z naslovom sitemapa
- `/booked/feed-{lang}.xml` RSS 2.0 per jezik
- Responsive `<picture>` z 4 srcset variantami + dominant-color placeholder
- Lazy-loaded slike, čisti SSR, vanilla JS samo za enhancement

## Modern blog features (na single post)

- Auto Table of Contents (sticky desktop sidebar) iz H2/H3 anchor-ov
- Reading progress bar (scroll)
- Estimated reading time (200 wpm)
- CTA shortcodes: `[cta:register]`, `[cta:pricing]`, `[cta:demo]`, `[cta:subscribe]`
- Author card z bio (per-jezik prevod) + povezava na avtorjev arhiv
- Related posts (top 3 iz iste kategorije)
- Share buttons: X, LinkedIn, Facebook, Email + Copy link
- Newsletter inline form + footer form (double opt-in)
- Search (FULLTEXT, AJAX)
- Language switcher
- Print CSS

---

## Faze

### ✅ Faza 1: Foundation
- `sql/migrate_blog.sql` (11 tabel + seed kategorije + Rezble Team avtor)
- `sql/seed_blog_demo.sql` (1 članek v sl + en za test render)
- `.htaccess` (mod_rewrite za /booked URL-je)
- `robots.txt` + `sitemap.php`
- `includes/Parsedown.php`, `includes/blog_helpers.php`, `includes/blog_renderer.php`
- `blog/index.php`, `blog/post.php`, `blog/category.php`, `blog/tag.php`, `blog/author.php`, `blog/feed.php`
- `blog/_header.php`, `blog/_footer.php` (deljen layout)
- `assets/css/blog.css`, `assets/js/blog_public.js`
- `lang/{sl,en,de,it,fr,hr,es,pt}.json` – 46 booked.* ključev (sl tekst v vse, prevodi kasneje)

### ⏳ Faza 2: Admin & editor (next)
- `api/blog.php` – CRUD endpoints (list/get/save/change_status/approve/reject/schedule/publish, plus topic queue, categories, tags, authors, search)
- `api/blog_media.php` – upload + GD resize → 480/800/1200/1920px
- `pages/superadmin_blog.php` – nov tab v superadmin: Posts / Topic queue / Categories / Tags / Authors / Media / Subscribers
- `pages/blog_editor.php` – EasyMDE markdown editor + zavihki za vse jezike + autosave
- `assets/js/blog_admin.js`, `assets/js/blog_editor.js`, `assets/css/blog_admin.css`
- Gumb "Generate with AI" v editorju

### ⏳ Faza 3: AI integracija
- `includes/blog_anthropic.php` – cURL wrapper za Anthropic API (`claude-opus-4-7` za generate, `claude-sonnet-4-6` za translate; ephemeral prompt cache)
- API actions: `ai_generate`, `ai_translate`, `ai_suggest_tags`
- `cron/blog_ai_drafter.php` – dnevno 06:00, vzame iz topic queue, generira draft, email Maticu
- `cron/blog_ai_translator.php` – uro, prevede approved master poste v ostalih 7 jezikov
- `cron/blog_publisher.php` – 15 min, scheduled → published

### ⏳ Faza 4: Newsletter + finishing touches
- `api/blog_subscribe.php` – subscribe + confirm (double opt-in)
- Mailgun email šablone za confirm
- `cron/blog_sitemap_rebuild.php` – dnevni regen sitemapa
- Lighthouse pass (cilj: SEO 100, Performance ≥90)

### ⏳ Faza 5: Launch
- Prevod booked.* ključev v ostale jezike (Claude prevede)
- Link na blog v `home/index.php` navbar/footer
- 3-5 ročno odobrenih člankov
- DNS/SSL preverba na app.rezervacije.si

---

## Kako preveriti, da Faza 1 deluje

```bash
# 1. Migracije
mysql rezervacije_saas < sql/migrate_blog.sql
mysql rezervacije_saas < sql/seed_blog_demo.sql

# 2. Public render (dev URL pattern; prod = brez /rezervacije-saas)
curl https://dev.novolon.com/rezervacije-saas/booked
curl https://dev.novolon.com/rezervacije-saas/booked/kako-zmanjsati-no-show
curl https://dev.novolon.com/rezervacije-saas/en/booked/how-to-reduce-no-shows

# 3. SEO infra
curl https://dev.novolon.com/rezervacije-saas/sitemap.xml
curl https://dev.novolon.com/rezervacije-saas/robots.txt
curl https://dev.novolon.com/rezervacije-saas/booked/feed-sl.xml

# 4. Pregled v brskalniku — preveri:
#    - <title>, meta description, og:*, twitter:*, link rel=canonical
#    - link rel=alternate hreflang sl + en + x-default
#    - JSON-LD BlogPosting + BreadcrumbList prisotna
#    - Reading progress, TOC, share, related, CTA renderi
```

Validation:
- https://search.google.com/test/rich-results — JSON-LD recognized
- https://validator.w3.org/nu/ — no HTML errors
- https://www.feedvalidator.org/ — RSS valid

---

## Critical files reference (Faza 1)

| Pot | Funkcija |
|-----|----------|
| `sql/migrate_blog.sql` | Vse blog tabele + seed kategorije + author |
| `includes/blog_helpers.php` | `blog_render_md()`, `blog_render_meta_tags()`, `blog_render_jsonld_post()`, `blog_post_url()`, `blog_post_hreflangs()`, `blog_fetch_related()`, `blog_increment_view()`, `blog_render_picture()`, `blog_sanitize_html()` |
| `includes/blog_renderer.php` | `blog_render_cta()`, `blog_render_post_card()`, `blog_render_share_buttons()`, `blog_render_author_card()`, `blog_render_pagination()`, listing URL builders |
| `includes/Parsedown.php` | Mini markdown parser (custom, brez Composerja) z [cta:type] in media:ID podporo |
| `blog/index.php` | Listing + sidebar + search + pagination |
| `blog/post.php` | Single post + TOC + share + related + CTA + author card |
| `blog/{category,tag,author}.php` | Filtrirani listings |
| `blog/feed.php` | RSS 2.0 per jezik |
| `sitemap.php` | Glavni sitemap z hreflang alternates |
| `.htaccess` | URL rewrites za clean URLs |
| `assets/css/blog.css` | Vsi `bk-*` namespacovani public stili |
| `assets/js/blog_public.js` | Progress bar, TOC scrollspy, share copy, search AJAX, subscribe AJAX |

---

## Kaj NE delamo (eksplicitno izpustil)

- Komentarji (spam-magnet, malo dodane vrednosti)
- Per-tenant blog (en blog samo za Rezble brand)
- Pošiljanje newsletterja (samo zbiranje za zdaj)
- Image library: Unsplash/Pexels integracija (ročni upload za zdaj)
- A/B testing CTA-jev (shortcode arhitektura podpira, ampak ne prej kot bo dovolj prometa)

Za polni načrt glej `~/.claude/plans/naredila-bova-blog-naredi-harmonic-wall.md`.
