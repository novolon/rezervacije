# Rezervacije SaaS

Multi-tenant SaaS reservation management system for restaurants. Staff manage reservations through an admin dashboard; guests can book online or through an embeddable widget.

**Dev:** `https://dev.novolon.com/rezervacije-saas`
**Prod:** `https://app.rezervacije.si`

---

## Tech Stack

| Layer     | Technology                                          |
|-----------|-----------------------------------------------------|
| Backend   | PHP 7.4+, procedural, no framework                  |
| Database  | MySQL 5.7+ (utf8mb4), PDO                           |
| Admin UI  | Vanilla JavaScript + plain CSS                      |
| Landing   | React 18 + Vite + Tailwind CSS (in `/home/`)        |
| Email     | Mailgun HTTP API (EU region)                        |
| Payments  | Stripe (Checkout + Webhooks + Customer Portal)      |
| Hosting   | Synology NAS (no exotic PHP extensions required)    |

---

## Quick Setup

1. **Database** – run migrations in order:
   ```
   sql/schema.sql
   sql/migrate_to_saas.sql
   sql/migrate_billing.sql
   sql/migrate_self_booking.sql
   sql/migrate_surveys.sql
   sql/migrate_tables.sql
   sql/migrate_waitlist.sql
   sql/migrate_guests.sql
   sql/migrate_gdpr.sql
   sql/migrate_guests_edit.sql
   (any remaining migrate_*.sql)
   ```

2. **Configuration** – copy and fill secrets:
   ```bash
   cp config.example.php config.php
   ```
   Required values: DB credentials, `APP_URL`, `BASE_PATH`, Mailgun keys, Stripe keys.

3. **Superadmin** – visit `/setup.php`, create account, then **delete setup.php**.

4. **Cron jobs** – configure in Synology Task Scheduler:
   | Script                       | Schedule         |
   |------------------------------|------------------|
   | `cron/reminders.php`         | Daily at 09:00   |
   | `cron/survey_send.php`       | Every 15 min     |
   | `cron/waitlist_expire.php`   | Every 15 min     |
   | `cron/gdpr_cleanup.php`      | Weekly           |

5. **Landing page** (optional):
   ```bash
   cd home
   npm install
   npm run build
   ```

---

## User Roles

| Role         | What they can do                                                        |
|--------------|-------------------------------------------------------------------------|
| `superadmin` | Full platform access: all tenants, billing overrides, GDPR, discounts  |
| `admin`      | Manage own restaurants, staff, all features within subscription plan    |
| `user`       | Single restaurant: view/add/edit reservations, mark guests as arrived   |
| Guest        | Public pages only (booking, survey, self-edit) via token links          |

---

## Subscription Plans

| Plan     | Monthly | Annual  | Key Features                                       |
|----------|---------|---------|----------------------------------------------------|
| Trial    | Free    | 14 days | Full Advanced access for 14 days                   |
| Basic    | 4.99€   | 49.99€  | Core reservations, calendar, reminders, stats      |
| Advanced | 6.99€   | 69.99€  | + Online booking, guest DB, waitlist, tables, SSE  |
| Premium  | 9.99€   | 99.99€  | + Booking widget, SMS (planned), CSV export, branding |

Feature gating is enforced in `includes/plans.php` via `user_has_feature()`.

---

## Features

### Core Reservation Management (all plans)
- Daily schedule view with time blocks (height = duration)
- Monthly calendar navigation with reservation indicators
- Create / edit / delete reservations (guest name, count, time, duration, notes)
- Mark guest as "arrived" (triggers survey if configured)
- Restaurant color coding and custom schedule hours

### Online Booking – Public Flow (Advanced+)
- Unique URL per restaurant: `/book.php?t={token}`
- 4-step flow: guest count → date → time → details + GDPR consent
- Auto-confirm or manual approval mode
- Pending reservations shown with visual badge in schedule
- Admin can approve/reject directly from schedule view
- Confirmation email with .ics attachment and Google Calendar link
- Falls back to waitlist when no slots available

### Booking Widget (Premium)
- Embeddable JavaScript snippet (`widget.js`)
- Renders the full booking flow on any external website
- Restaurant-branded

### Guest Self-Management (Advanced+)
- Edit token included in confirmation email
- Guest can modify date / time / guest count / notes up to configurable cutoff (default 24h)
- Guest can cancel up to configurable cutoff (default 4h)
- Cancellation triggers waitlist check and notifies admin

### Waitlist (Advanced+)
- Automatic queue when no slots are available
- Email notification with 2-hour confirmation window
- Cascading: when slot opens, next person in queue is notified
- Admin can manually manage entries

### Guest Database (Advanced+)
- Guest profiles auto-created/updated per reservation (keyed by email per restaurant)
- Tags, private notes, full reservation history, visit stats
- Inline mini-profile shown when editing a reservation with known email
- Mark guests as blacklisted

### Table Management (Advanced+)
- Define areas (zones) and tables with capacity per restaurant
- Merge groups: combine adjacent tables for large parties
- Auto-allocation algorithm picks best-fit table per reservation
- Staff can manually reassign tables
- Public booking validates table availability before presenting time slots
- Prevents overbooking via MySQL transactions + `SELECT FOR UPDATE`
- Backward compatible: works without table setup (no breakage for existing data)

### Real-Time Sync (Advanced+)
- Server-Sent Events (SSE) push updates to all open sessions
- When any staff member changes a reservation, all screens update instantly
- Fallback: polling every 10 seconds

### Calendar Export (Advanced+)
- .ics file download (Apple Calendar, Outlook)
- Google Calendar deep link
- Included in confirmation and reminder emails

### Statistics & Analytics
- **All plans**: KPI cards (total reservations, guests, avg per reservation, arrival rate)
- **All plans**: Monthly trend chart, peak hours/days, source breakdown (staff vs. public)
- **Advanced+**: Returning guest analytics — new vs. returning, avg days between visits, 30/60/90/180-day retention curve, top 20 loyal guests
- **Premium**: CSV export of all stats data

### Satisfaction Survey (Advanced+)
- Admin builds custom surveys (or applies the built-in 6-question template)
- Question types: rating (1–5 stars), radio, checkbox, short text, long text
- Auto-sent X hours after guest is marked as arrived (configurable delay)
- Guest completes via token link — no login required
- Consent options: publish with name / anonymous / private
- Admin reviews all responses with modal detail view
- **Premium**: CSV export (anonymous entries hide email)

### Billing & Subscriptions
- 14-day free trial (full Advanced access)
- Stripe Checkout for card payments (monthly and annual)
- Annual invoices: admin requests → superadmin activates manually
- Stripe Customer Portal for self-service subscription management
- Superadmin can manually assign/override plans
- Time-limited promotional discounts (superadmin managed)
- Trial warning banner at < 7 days remaining
- Paywall modal blocks access after trial expiry

### Email Notifications

| Trigger                        | Recipient | Notes                              |
|-------------------------------|-----------|-------------------------------------|
| Registration                   | Admin     | Email verification link             |
| Public booking (auto-confirm)  | Guest     | Confirmation + .ics + Google Cal    |
| Public booking (manual)        | Guest     | Pending notice                      |
| Public booking (any)           | Admin     | Notification with approve/reject    |
| Admin approves booking         | Guest     | Confirmation + calendar links       |
| Admin rejects booking          | Guest     | Rejection notice                    |
| 24h before reservation         | Guest     | Reminder (cron, daily 09:00)        |
| Guest marked as arrived        | Guest     | Survey link (delayed, cron)         |
| Guest cancels                  | Admin     | Cancellation notice                 |
| Waitlist slot available        | Guest     | "Confirm within 2h" email           |
| Trial expiring (< 7 days)      | Admin     | Warning banner + email              |

### GDPR Compliance (all plans)
- Mandatory consent at registration and at public booking
- Public GDPR rights request form (`/pages/gdpr_request.php`): access, rectification, erasure, portability
- Superadmin processes requests and can trigger data anonymization
- Cron auto-anonymizes reservations older than 3 years
- Cookie consent banner on all public pages
- Privacy Policy (`/pages/privacy.php`) and Terms of Service (`/pages/terms.php`)

---

## API Overview

All API endpoints return JSON: `{"success": true, ...}` or `{"error": "message"}`.

**Public (no auth)**
| Method | Endpoint                          | Action                          |
|--------|-----------------------------------|---------------------------------|
| GET    | `api/pricing.php`                 | Pricing tiers                   |
| GET    | `api/book.php?t=TOKEN`            | Restaurant info for booking     |
| GET    | `api/book.php?t=TOKEN&date=DATE`  | Available time slots            |
| POST   | `api/book.php?t=TOKEN`            | Submit reservation              |
| POST   | `api/auth.php`                    | Login / register                |

**Authenticated (session required)**
| Method | Endpoint                         | Action                          |
|--------|----------------------------------|---------------------------------|
| GET    | `api/reservations.php`           | List reservations               |
| POST   | `api/reservations.php`           | Create reservation              |
| PUT    | `api/reservations.php?id=X`      | Update reservation              |
| DELETE | `api/reservations.php?id=X`      | Delete reservation              |
| PUT    | `api/reservation_action.php`     | Mark arrived / approve / reject |
| GET    | `api/restaurants.php`            | List restaurants                |
| POST   | `api/restaurants.php`            | Create restaurant               |
| PUT    | `api/restaurants.php?id=X`       | Update restaurant settings      |
| GET    | `api/guests.php`                 | List guest profiles             |
| GET    | `api/tables.php`                 | List areas / tables             |
| POST   | `api/tables.php`                 | Create area / table / group     |
| PUT    | `api/table_assignment.php`       | Reassign table to reservation   |
| GET    | `api/waitlist.php`               | List waitlist entries           |
| GET    | `api/stats.php`                  | Analytics data                  |
| GET    | `api/survey.php?action=get_form` | Load survey form                |
| POST   | `api/survey.php?action=save_form`| Save survey form                |
| GET    | `api/ics.php?id=X`               | Download .ics for reservation   |
| GET    | `api/dashboard.php`              | Dashboard KPI data              |
| POST   | `api/billing.php`                | Create Stripe Checkout session  |
| POST   | `api/stripe-webhook.php`         | Stripe webhook handler          |
| GET    | `api/profile.php`                | Get user profile                |
| PUT    | `api/profile.php`                | Update profile / password       |
| GET    | `api/superadmin.php`             | Superadmin data                 |
| POST   | `api/superadmin.php`             | Superadmin actions              |

---

## Key Files Reference

| File                              | Purpose                                        |
|-----------------------------------|------------------------------------------------|
| `includes/db.php`                 | PDO connection (`$pdo`)                        |
| `includes/auth_check.php`         | Session validation + redirect                  |
| `includes/plans.php`              | Plan definitions + `user_has_feature()` gating |
| `includes/mailer.php`             | All Mailgun email functions                    |
| `includes/table_helper.php`       | Auto table allocation algorithm                |
| `includes/waitlist_notifier.php`  | Cascade waitlist notifications                 |
| `includes/guest_helper.php`       | Guest profile create/update logic              |
| `includes/survey_helper.php`      | Survey template seeding                        |
| `includes/survey_mailer.php`      | Survey email sending                           |
| `pages/main.php`                  | Main dashboard (calendar + schedule)           |
| `pages/admin.php`                 | Admin panel (restaurants, users, settings)     |
| `pages/superadmin.php`            | Superadmin panel                               |
| `pages/billing.php`               | Billing & plan management UI                   |
| `pages/stats.php`                 | Statistics & analytics                         |
| `pages/survey_builder.php`        | Survey editor                                  |
| `pages/survey_results.php`        | Survey response viewer                         |
| `pages/guests.php`                | Guest database browser                         |
| `pages/waitlist.php`              | Waitlist management                            |
| `book.php`                        | Public booking form                            |
| `widget.js`                       | Embeddable booking widget                      |
| `assets/js/api.js`                | Client-side API wrapper                        |
| `assets/js/modal.js`              | Modal dialog system                            |
| `config.example.php`              | Config template (copy to config.php)           |

---

## Database Schema Summary

| Table                              | Purpose                                  |
|------------------------------------|------------------------------------------|
| `users`                            | Authentication, roles, trial status      |
| `restaurants`                      | Venue config, booking settings           |
| `restaurant_admins`                | M:N admin–restaurant assignments         |
| `reservations`                     | Core reservation records                 |
| `subscriptions`                    | Active subscriptions per user            |
| `plan_discounts`                   | Time-limited promotional pricing         |
| `guests`                           | Guest profiles per restaurant            |
| `waitlist`                         | Waitlist queue per restaurant/date       |
| `restaurant_areas`                 | Seating zones                            |
| `restaurant_tables`                | Individual tables with capacity          |
| `restaurant_table_merge_groups`    | Named merge groups                       |
| `restaurant_table_merge_members`   | M:N table–group membership               |
| `reservation_table_assignments`    | Which table(s) are assigned per booking  |
| `survey_forms`                     | Survey templates per restaurant          |
| `survey_questions`                 | Questions within surveys                 |
| `survey_question_options`          | Options for radio/checkbox questions     |
| `survey_responses`                 | Guest survey submissions                 |
| `survey_answers`                   | Per-question answers                     |
| `gdpr_requests`                    | GDPR subject rights requests             |
| `realtime_events`                  | SSE push event log                       |

---

## Project Documentation

| File                      | Topic                                         |
|---------------------------|-----------------------------------------------|
| `PRODUCT_BRIEF.md`        | Full product specification                    |
| `FEATURES_PLAN.md`        | 7-module implementation roadmap               |
| `SELFBOOKING_PLAN.md`     | Public booking flow detailed spec             |
| `SURVEY_PLAN.md`          | Survey builder & response system spec         |
| `TABLE_MANAGEMENT_PLAN.md`| Table allocation & merge groups spec          |
| `BILLING_TODO.md`         | Stripe integration checklist                  |
| `MAGIC_PATTERNS_PROMPT.md`| UI design system, colors, component patterns  |

---

## Config Reference (`config.php`)

```php
define('DB_HOST', '');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'Rezervacije');
define('APP_URL', 'https://app.rezervacije.si');  // no trailing slash
define('BASE_PATH', '');                           // e.g. '/rezervacije-saas' on dev
define('TIMEZONE', 'Europe/Ljubljana');
define('SESSION_LIFETIME', 28800);                 // 8 hours

define('MAILGUN_API_KEY', '');
define('MAILGUN_DOMAIN', '');
define('MAIL_FROM', 'noreply@rezervacije.si');

define('STRIPE_SECRET_KEY', '');
define('STRIPE_PUBLISHABLE_KEY', '');
define('STRIPE_WEBHOOK_SECRET', '');
define('STRIPE_PRICES', [
    'basic_monthly'    => 'price_...',
    'basic_yearly'     => 'price_...',
    'advanced_monthly' => 'price_...',
    'advanced_yearly'  => 'price_...',
    'premium_monthly'  => 'price_...',
    'premium_yearly'   => 'price_...',
]);

define('SCHEDULE_START', 600);   // 10:00 in minutes
define('SCHEDULE_END', 1320);    // 22:00 in minutes
```
