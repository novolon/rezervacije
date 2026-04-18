# CLAUDE.md – Rezervacije SaaS

This file gives Claude Code everything it needs to work efficiently in this project.

---

## Project Overview

**Rezervacije** is a multi-tenant SaaS reservation management system for restaurants.
- Slovenian UI (all user-facing strings, emails, and labels are in Slovenian)
- No frameworks on the backend – pure PHP 7.4+ with PDO
- No JS frameworks in admin – vanilla JS only
- React + Vite only for the landing page (`/home/`)
- Hosted on Synology NAS at `dev.novolon.com/rezervacije-saas` (dev) and `app.rezervacije.si` (prod)

---

## Directory Layout

```
/api/               RESTful JSON API endpoints (one file per domain)
/pages/             Authenticated admin/staff pages (PHP, output HTML)
/includes/          Shared PHP: db.php, auth_check.php, functions.php, mailer.php, plans.php, helpers
/cron/              Background jobs: reminders.php, survey_send.php, waitlist_expire.php, gdpr_cleanup.php
/assets/css/        All stylesheets (plain CSS, no preprocessor)
/assets/js/         All admin JavaScript (vanilla, no bundler)
/home/              React 18 + Vite + Tailwind landing page (separate build)
/sql/               Ordered migration files (schema.sql first, then migrate_*.sql)
/config.php         Secrets – NOT in git (copy config.example.php)
```

---

## Architecture Rules

### PHP
- All API files read `$_GET['action']` or HTTP method to branch logic.
- Always include `includes/auth_check.php` at the top of authenticated pages/APIs.
- Always include `includes/db.php` for PDO (`$pdo`).
- Feature gating via `user_has_feature($user, 'feature_name')` defined in `includes/plans.php`.
- Multi-tenancy: always filter queries by `restaurant_id`. Never expose cross-tenant data.
- Table allocation uses MySQL transactions (`BEGIN` / `COMMIT`) with `SELECT ... FOR UPDATE` to prevent race conditions.
- Soft deletes are used for some entities (check for `deleted_at` column before adding hard deletes).
- Token-based access for public flows (booking, survey, guest self-edit) – tokens stored per-restaurant or per-reservation.

### JavaScript (admin)
- All API calls go through `assets/js/api.js` (wrapper around `fetch`).
- All modal logic is in `assets/js/modal.js`.
- Do not introduce jQuery, lodash, or any npm dependency in the admin JS.
- State is managed through direct DOM manipulation and API refetches.

### CSS
- Plain CSS, no preprocessor. Use existing variables in `main.css` (`:root` block).
- Follow existing naming conventions (BEM-lite: `.block__element--modifier`).

### React (landing page only)
- Source: `home/src/`
- Build: `npm run build` inside `/home/` (output to `home/dist/`)
- Uses Tailwind 3, Emotion, Framer Motion, Lucide React icons.

---

## Database

- MySQL utf8mb4, PDO.
- Apply migrations in order: `schema.sql` → `migrate_to_saas.sql` → each `migrate_*.sql`.
- Always add new columns with `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` when writing migrations.
- Key tables: `users`, `restaurants`, `restaurant_admins`, `reservations`, `subscriptions`, `guests`, `waitlist`, `restaurant_tables`, `restaurant_areas`, `restaurant_table_merge_groups`, `reservation_table_assignments`, `survey_forms`, `survey_questions`, `survey_responses`, `survey_answers`, `gdpr_requests`, `realtime_events`.

---

## Subscription Plans & Feature Gating

| Plan     | Slug        | Monthly | Annual  |
|----------|-------------|---------|---------|
| Trial    | `trial`     | free    | –       |
| Basic    | `basic`     | 4.99€   | 49.99€  |
| Advanced | `advanced`  | 6.99€   | 69.99€  |
| Premium  | `premium`   | 9.99€   | 99.99€  |

Feature availability is checked via `user_has_feature()` in `includes/plans.php`. Always gate new Advanced/Premium features there.

---

## Roles

| Role         | Scope                                               |
|--------------|-----------------------------------------------------|
| `superadmin` | Full platform: all tenants, billing, GDPR, discounts |
| `admin`      | Own restaurants (1+), staff management, all features within plan |
| `user`       | Single restaurant, view/manage reservations, mark arrived |
| Guest        | Public pages only, token-based (no login)           |

---

## Configuration (`config.php`)

Keys required (see `config.example.php` for template):
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`
- `APP_NAME`, `APP_URL`, `TIMEZONE`, `SESSION_LIFETIME`
- `BASE_PATH` (e.g. `/rezervacije-saas` on dev, empty on prod)
- `MAILGUN_API_KEY`, `MAILGUN_DOMAIN`, `MAIL_FROM`
- `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICES` (array)
- `SCHEDULE_START`, `SCHEDULE_END` (minutes from midnight)

---

## Email

All email is sent via Mailgun HTTP API (EU region) using `includes/mailer.php`. No SMTP, no Composer. Do not add any email library. To add a new email type, add a function to `mailer.php` and call it directly.

---

## Cron Jobs

| File                       | Purpose                            | Frequency         |
|----------------------------|------------------------------------|-------------------|
| `cron/reminders.php`       | 24h reminder to guests             | Daily at 09:00    |
| `cron/survey_send.php`     | Delayed survey email delivery      | Every 15–30 min   |
| `cron/waitlist_expire.php` | Expire 2h windows, cascade queue   | Every 15 min      |
| `cron/gdpr_cleanup.php`    | Anonymize data older than 3 years  | Weekly            |

Configure in Synology Task Scheduler. No Laravel scheduler, no queue worker – plain PHP CLI.

---

## Deployment

1. Copy `config.example.php` → `config.php`, fill all values.
2. Run SQL migrations in order from `/sql/`.
3. Visit `/setup.php` to create the superadmin account.
4. **Delete `setup.php`** immediately after.
5. Set up cron jobs in Synology Task Scheduler.
6. For the landing page: `cd home && npm install && npm run build`.

Dev URL: `https://dev.novolon.com/rezervacije-saas`
Prod URL: `https://app.rezervacije.si`

---

## Key Files to Know

| File                              | What it does                                          |
|-----------------------------------|-------------------------------------------------------|
| `includes/db.php`                 | PDO connection (`$pdo`)                               |
| `includes/auth_check.php`         | Validates session, redirects if unauthenticated       |
| `includes/plans.php`              | Plan definitions + `user_has_feature()` gating        |
| `includes/mailer.php`             | All email send functions                              |
| `includes/table_helper.php`       | Auto table allocation algorithm                       |
| `includes/waitlist_notifier.php`  | Cascade notifications when a slot opens               |
| `api/reservations.php`            | Core reservation CRUD                                 |
| `api/book.php`                    | Public booking flow (slot availability + submission)  |
| `api/stripe-webhook.php`          | Stripe event handler                                  |
| `api/billing.php`                 | Stripe Checkout session creation                      |
| `pages/main.php`                  | Main dashboard (calendar + schedule)                  |
| `pages/admin.php`                 | Admin panel (restaurants, users, settings)            |
| `pages/superadmin.php`            | Superadmin panel (tenants, discounts, GDPR)           |
| `assets/js/api.js`                | Client-side API wrapper                               |
| `assets/js/modal.js`              | Modal dialog system                                   |
| `widget.js`                       | Embeddable booking widget (Premium plan)              |

---

## Coding Conventions

- PHP: no framework, procedural style. Group SQL, business logic, then output/JSON.
- All API responses: `header('Content-Type: application/json')` + `json_encode(['success' => true, ...])` or `['error' => '...']`.
- Error handling: return `['error' => 'message']` with appropriate HTTP status code.
- JavaScript: async/await with `try/catch`. Show errors inline (no `alert()`).
- CSS: mobile-first, follow existing breakpoints in `main.css`.
- Do not add Composer. No PSR autoloading. Includes are manual `require_once`.
- Do not add npm packages to admin JS. Keep it vanilla.

---

## Design System

See `MAGIC_PATTERNS_PROMPT.md` for the full UI design system (colors, spacing, component patterns). Key points:
- Primary color: `#2563eb` (blue)
- Font: System stack (no custom web fonts in admin)
- Modals: use existing `.modal-overlay` + `.modal-content` pattern from `modal.js`
- Status colors: confirmed = green, pending = amber, cancelled = red/grey

---

## Existing Planning Docs

| File                      | Topic                                      |
|---------------------------|--------------------------------------------|
| `PRODUCT_BRIEF.md`        | Full product specification                 |
| `FEATURES_PLAN.md`        | 7-module implementation roadmap            |
| `SELFBOOKING_PLAN.md`     | Public booking flow spec                   |
| `SURVEY_PLAN.md`          | Survey builder & response system spec      |
| `TABLE_MANAGEMENT_PLAN.md`| Table allocation & merge groups spec       |
| `BILLING_TODO.md`         | Stripe integration checklist               |
| `MAGIC_PATTERNS_PROMPT.md`| UI design patterns & conventions           |

---

## Things to Avoid

- Do not add Composer or any PHP dependency manager.
- Do not add jQuery or any JS framework to the admin.
- Do not hardcode restaurant IDs or user IDs.
- Do not skip `restaurant_id` filtering in any database query.
- Do not commit `config.php` or `setup.php`.
- Do not use raw `$_POST`/`$_GET` without sanitizing (use PDO prepared statements always).
- Do not add new subscription plans without updating `includes/plans.php` gating logic.
