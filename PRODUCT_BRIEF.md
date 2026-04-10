# Product Brief: Restaurant Reservation SaaS

> For: Product Designer  
> Version: April 2026  
> Language: Slovenian (primary UI language)

---

## 1. What Is This Product?

A multi-tenant SaaS web application for restaurant reservation management. Restaurants sign up, configure their venues, and manage reservations through a staff dashboard. Guests can book online via a public link or embeddable widget.

The product replaces pen-and-paper or legacy Excel-based systems with a clean, modern web interface. No mobile app — responsive web only.

**Stack:** PHP 7.4, MySQL, Vanilla JS/CSS. No frameworks. Runs on shared hosting (Synology NAS).

---

## 2. User Roles

| Role | Who | Access |
|------|-----|--------|
| **Superadmin** | Product owner | All tenants, all data, billing overrides, discounts |
| **Admin** | Restaurant owner / manager | Their own restaurants, staff, reservations, settings |
| **User** (staff) | Waiter, receptionist | Assigned restaurant only — reservations + schedule |
| **Guest** | End customer | Public booking page + survey page (no account) |

An admin can manage **multiple restaurants**. A restaurant can have **multiple admins**. Users are assigned to exactly one restaurant.

---

## 3. Subscription Tiers

| Feature | Trial (14 days) | Basic (4.99€/mo) | Advanced (6.99€/mo) | Premium (9.99€/mo) |
|---------|:-:|:-:|:-:|:-:|
| Reservation management | ✓ | ✓ | ✓ | ✓ |
| Multiple restaurants | ✓ | ✓ | ✓ | ✓ |
| Staff management | ✓ | ✓ | ✓ | ✓ |
| Email notifications to guests | | | ✓ | ✓ |
| 24h reminder emails | | | ✓ | ✓ |
| Public booking page | | | ✓ | ✓ |
| Approve / reject reservations | | | ✓ | ✓ |
| Google/Apple Calendar export (.ics) | | | ✓ | ✓ |
| Guest self-edit / cancel | | | ✓ | ✓ |
| Waitlist | | | ✓ | ✓ |
| Guest database with history | | | ✓ | ✓ |
| Returning guest analytics | | | ✓ | ✓ |
| Real-time sync (SSE) | | | ✓ | ✓ |
| Satisfaction survey (build + send + view) | | | ✓ | ✓ |
| Embeddable booking widget | | | | ✓ |
| Custom branding (color, logo) | | | | ✓ |
| SMS notifications | | | | ✓ |
| Survey CSV export | | | | ✓ |
| Auto-confirm with guest limits | | | | ✓ |

Billing: monthly or annual (annual discounted). Annual via invoice (manual activation by superadmin) or via Stripe.

---

## 4. Pages Structure

### Public pages (no login required)

| URL | Page | Description |
|-----|------|-------------|
| `/` | Landing / Home | Marketing page with pricing, features, CTA to register |
| `/login.php` | Login | Email + password. "Remember me" (30-day session). Forgot password link. |
| `/register.php` | Register | New admin signup. GDPR consent (mandatory) + marketing opt-in (optional). Email verification required. |
| `/forgot-password.php` | Forgot password | Send reset link via email |
| `/reset-password.php` | Reset password | Token-based password reset |
| `/verify-email.php` | Email verification | Verify account after registration |
| `/book.php?t={token}` | Public booking | Multi-step reservation form for guests (no login). 4 steps + confirmation state. |
| `/survey.php?t={token}` | Guest survey | Post-visit satisfaction survey (no login). Consent selection. |
| `/waitlist.php?t={token}` | Waitlist confirmation | Guest confirms or removes themselves from waitlist |
| `/reservation_edit.php?t={token}` | Edit/cancel reservation | Guest self-edits or cancels their reservation (no login) |
| `/widget.js` | Booking widget script | JS snippet embeds booking form on external websites (Premium) |
| `/pages/privacy.php` | Privacy Policy | GDPR: data processing explanation |
| `/pages/terms.php` | Terms of Service | User agreement |
| `/pages/gdpr_request.php` | GDPR rights request | Guest submits access/erasure/portability request |

### Authenticated pages (admin + user)

| URL | Page | Access |
|-----|------|--------|
| `/pages/main.php` | Main dashboard | Admin + User |
| `/pages/admin.php` | Admin panel | Admin only |
| `/pages/stats.php` | Statistics | Admin + User |
| `/pages/billing.php` | Billing & subscription | Admin only |
| `/pages/guests.php` | Guest database | Admin (Advanced+) |
| `/pages/survey_builder.php` | Survey editor | Admin (Advanced+) |
| `/pages/survey_results.php` | Survey responses | Admin (Advanced+) |
| `/pages/superadmin.php` | Superadmin panel | Superadmin only |
| `/pages/gdpr.php` | GDPR requests management | Superadmin only |

---

## 5. Page-by-Page Feature Details

---

### Landing Page (`/home/`)

- Hero section: headline, subheadline, CTA buttons (Register free / See demo)
- Feature highlights (3–4 cards)
- Pricing table with all three tiers and feature comparison
- FAQ section
- Footer with links to Privacy Policy, Terms, Contact

---

### Login (`/login.php`)

- Email + password fields
- "Remember me" checkbox (extends session to 30 days)
- Link: "Forgot password?"
- Link: "Create account"
- Redirect to main dashboard on success
- Error state for invalid credentials

---

### Register (`/register.php`)

- Fields: first name, last name, email, password, confirm password
- Mandatory GDPR checkbox: agree to Terms + Privacy Policy
- Optional checkbox: marketing communications consent
- DPA checkbox: data processing agreement (SaaS processes data on behalf of restaurant)
- Submit → email verification sent
- Cannot log in until email is verified

---

### Main Dashboard (`/pages/main.php`)

This is the primary working screen for all logged-in staff. Two-panel layout.

#### Header (top bar)
- Logo / product name
- Plan badge (Trial X days / Basic / Advanced / Premium)
- Restaurant selector dropdown (if admin has multiple restaurants)
- Pending reservations badge with count (admin only, Advanced+) — click opens pending modal
- Navigation links: Statistics, Guests (Advanced+), Survey (Advanced+), Billing, Logout
- Trial expiry warning banner (shows when < 7 days remain)
- Trial expired modal / paywall (blocks use when trial ends without active plan)

#### Left panel — Calendar
- Monthly calendar
- Highlighted days with reservations (dot or count indicator)
- Click on day → loads that day's schedule in right panel
- Navigation: previous/next month
- Today highlighted

#### Right panel — Daily Schedule
- Selected date shown as header
- Time slots from opening to closing (e.g. 16:00–23:00)
- Each reservation shown as a block within its time slot
  - Block height = reservation duration (90 min block spans 90 min visually)
  - Block color = restaurant color
  - Shows: guest name, number of guests, time
- Reservation statuses:
  - `confirmed` (admin-added or approved public) — normal color
  - `pending` (awaiting approval from public booking) — gray with dashed border + ⏳ icon
  - `pending` (multiple at same slot) — grouped block: "⏳ 3 čakajočih"
  - `rejected` / `cancelled` — not shown
- Click on confirmed reservation → Edit/details modal
- Click on pending reservation → Approve/reject modal
- Click on empty slot → New reservation modal
- Arrived button on each reservation (marks guest as arrived → triggers survey flow)

#### New / Edit Reservation Modal
- Fields:
  - Guest name (first + last)
  - Email (optional)
  - Phone (optional)
  - Number of guests (blank by default, no default value)
  - Date picker
  - Time selector (slots per restaurant schedule)
  - Duration (default = restaurant default; admin can override if setting enabled)
  - Notes
  - Restaurant (if admin manages multiple)
- Validation: cannot book in the past (past reservations remain visible, new ones blocked)
- Mini guest profile shown if email matches existing guest (Advanced+):
  > "3rd visit · Last: 12.3.2026 · ⭐ 4.2 avg rating · Note: allergic to gluten"
- Save / Cancel / Delete buttons

#### Pending Reservations Modal
- Lists all pending reservations for a given time slot
- For each: name, email, phone, guests, notes
- [✓ Confirm] [✗ Reject] buttons per entry
- Confirming → sends confirmation email to guest, block turns to confirmed color
- Rejecting → sends rejection email, block disappears

---

### Admin Panel (`/pages/admin.php`)

Tabbed interface. Tabs:

#### Tab 1: Restaurants
- Table of restaurants: name, color, schedule, status, booking link (if enabled), actions
- [+ Add restaurant] button → opens restaurant modal
- Per-restaurant actions: Edit, Delete

**Restaurant Modal (create/edit):**
- Name
- Color picker (for schedule block color)
- Opening time / closing time
- Reservation duration (default minutes)
- Max guests per reservation
- Allow staff to change duration per reservation (toggle)
- **Online bookings section (Advanced+):**
  - Toggle: Enable online booking
  - Open days (checkboxes Mon–Sun)
  - Min guests / Max guests (for guest-facing buttons)
  - Auto-confirm vs. manual approval toggle
  - Booking link display + Copy button (read-only, auto-generated token)
- **Guest self-management (Advanced+):**
  - Allow guest to edit reservation (toggle)
  - Edit cutoff: X hours before (number field)
  - Allow guest to cancel reservation (toggle)
  - Cancel cutoff: X hours before (number field)

#### Tab 2: Users (staff)
- Table of users: name, email, role, assigned restaurant, status
- [+ Add user] button → modal
- Edit / Delete per user

**User Modal:**
- First name, last name
- Email
- Role: admin / user
- Assigned restaurant (dropdown, required for role=user)
- Password (set by admin on create; user can change later)

---

### Statistics (`/pages/stats.php`)

Available to admins and users (limited to their own restaurant).

#### Filters (header bar)
- Restaurant selector (admin with multiple only)
- Period: Last 30 days / 3 months / 6 months / 1 year / Custom (date range picker)
- [Export CSV] button

#### Section 1 — KPI Cards (4 cards in a row)
- Total reservations (in period)
- Total guests (sum of guest_count)
- Avg guests per reservation
- Arrival rate (% of confirmed that were marked as arrived)

#### Section 2 — Monthly Trend
- Bar chart (last 12 months)
- X: month, Y: reservation count
- Tooltip: reservations + guests

#### Section 3 — Patterns (2 side-by-side charts)
- Reservations by day of week (horizontal bar, Mon–Sun)
- Reservations by hour (horizontal bar, shows peak hours)

#### Section 4 — Booking Source
- Donut chart: Staff-added vs. Public booking
- Status breakdown table: Confirmed / Rejected / Pending (%)

#### Section 5 — Returning Guests (Advanced+)
- KPI row: Unique guests (with email) | Returning guests | Return rate %
- Donut chart: New vs. Returning
- Stat cards: avg days between visits, % returning within 90 days
- Retention curve: % returning at 30 / 60 / 90 / 180 days
- "Top 20 guests" table: rank, name, email, visits, total guests, first visit, last visit
  - "Loyal guest" badge for 5+ visits
  - Filter: All / Returning only

#### Section 6 — Group Size Distribution
- Bar chart: 1 / 2 / 3 / 4 / 5+ persons (% of total)

---

### Guest Database (`/pages/guests.php`) — Advanced+

- Search bar (by name / email / phone)
- Table: name, email, phone, total visits, last visit, tags, actions
- Click on row → opens guest profile modal

**Guest Profile Modal:**
- Contact info (name, email, phone) — inline editable
- Tags: VIP, Allergy, Special requests, Regular, No-show, Vegetarian/Vegan + custom
- Admin private note (textarea)
- Full reservation history: date, time, guests, status
- Survey results: date, average rating
- Stats: total visits, no-shows, avg days between visits

---

### Survey Editor (`/pages/survey_builder.php`) — Advanced+

- Restaurant selector
- Survey settings:
  - Title
  - Description (optional)
  - Thank-you email message text
  - Toggle: Auto-send survey after visit
  - Delay: Send X hours after arrival (number field, default 2)
  - Toggle: Include thank-you message in email
  - Toggle: Include survey link in email
- Question builder:
  - Add question button
  - Question types: Star rating (1–5), Radio buttons, Checkboxes, Short text, Long text
  - Per question: question text, required toggle
  - For radio/checkbox: add/remove options
  - Reorder questions (up/down arrows or drag-and-drop)
  - Delete question
- Save button

**Default survey (auto-created for Advanced/Premium on restaurant creation):**
1. Overall visit rating (star rating, required)
2. Food & drink quality (star rating)
3. Staff friendliness (star rating)
4. Would you recommend us? (radio: Yes / Probably yes / Probably no / No)
5. What did you enjoy most? (textarea)
6. What could we improve? (textarea)

---

### Survey Responses (`/pages/survey_results.php`) — Advanced+

- Filters: restaurant, date range, consent type
- Table: submission date, guest name, consent type, status (submitted / email sent / pending)
- Click row → response detail modal

**Response Detail Modal:**
- Per-question answers (stars rendered as stars, radio/checkbox as selected values, text as-is)
- Consent label
- Guest name / anonymous

- [Export CSV] button — Premium only
  - Columns: Date, Email (hidden for anonymous), Consent, [each question]...

---

### Billing (`/pages/billing.php`)

**For trial / expired / unsubscribed users:**
- Current plan status + days remaining (or expiry notice)
- Pricing cards: Basic / Advanced / Premium
  - Monthly and annual price (annual with discount shown, original crossed out if promotion active)
  - Feature list per plan
  - [Choose plan] button → Stripe Checkout (redirects to Stripe)
  - Annual: also [Request invoice] option → manual activation flow

**For active subscribers:**
- Current plan name + status (active / cancelled / paused)
- Billing cycle (monthly / annual)
- Next billing date
- [Manage subscription] → Stripe Customer Portal

**Stripe Checkout:** redirects to Stripe-hosted checkout page (locale: Slovenian). On success → billing-success page.

---

### Superadmin Panel (`/pages/superadmin.php`)

Tabs:

#### Tab 1: Admins
- Table: name, email, plan, trial expiry, joined date, status
- Per-row: [Assign plan] button → modal
  - Select plan: trial / basic / advanced / premium
  - End date (or leave blank for indefinite)
- View current subscription per admin

#### Tab 2: Discounts
- Table of active/scheduled discounts: plan, label, discounted monthly, discounted yearly, valid from–to, status
- [Add discount] → form: plan, description, discounted monthly price, discounted yearly price, start date, end date
- [Delete] per discount

#### Tab 3: GDPR Requests
- Table: type (access/rectification/erasure/portability), email, restaurant, status, requested at, resolved at
- Status: pending / processing / completed / rejected
- Update status + notes per request
- Erasure action: anonymizes user data in DB

---

### Public Booking Page (`/book.php?t={token}`)

Standalone public page. No login. Design: clean, minimal, restaurant-branded.

**Step 1 — Number of guests**
- Buttons from `min_guests` to `max_guests` (e.g. 2, 3, 4, 5, 6, 7, 8, 9, 10)
- "More →" button for groups above max → shows number input
- [Next →] button (active once selection made)

**Step 2 — Date**
- Monthly calendar
- Gray = closed days or past dates
- Green/active = bookable days
- Click on date → moves to Step 3

**Step 3 — Time**
- Header: "For [date], [N] guests"
- Grid of available time buttons (e.g. 18:00 / 18:30 / 19:00 / 19:30...)
- [← Back] link

**Step 4 — Guest details**
- First name + last name (required)
- Email (required)
- Phone (optional)
- Notes (optional)
- GDPR consent checkbox (required): "I agree to the processing of my personal data for the purpose of this reservation" + link to Privacy Policy
- Marketing opt-in checkbox (optional)
- [← Back] [Submit reservation]

**Step 5 — Confirmation**
- Auto-confirm mode: "✓ Reservation confirmed! We sent you a confirmation email."
- Manual approval mode: "⏳ Request received. We will notify you by email once confirmed."
- [Make another reservation] button (resets to Step 1)

**Progress indicator:** visual step bar showing steps 1–4 (Step 5 is end state).

---

### Guest Survey Page (`/survey.php?t={token}`)

Standalone public page. No login.

- Restaurant name and logo (if set)
- Survey title and description
- Questions rendered by type:
  - Star rating: 5 stars, click/hover effect
  - Radio: single-select
  - Checkbox: multi-select
  - Short text: single-line input
  - Long text: textarea
- Consent section (required, radio):
  - Agree to publish with name
  - Agree to anonymous publish
  - Do not agree to publish
- [Submit] button + required field validation
- Edge cases:
  - Invalid token → "Invalid link"
  - Already submitted → "Thank you, this survey has already been submitted."
  - Success → thank-you message

---

### Reservation Edit / Cancel (`/reservation_edit.php?t={token}`)

Guest-facing, no login. Link delivered in confirmation email (Advanced+).

- Shows current reservation details
- Guest can change: date, time, number of guests, notes
- Guest cannot change: restaurant, contact details
- [Save changes] → validates availability, updates reservation, sends notification email to restaurant
- [Cancel reservation] → confirmation dialog
  - Optional: reason for cancellation (radio: Change of plans / Illness / Other)
  - On confirm: marks as cancelled, notifies restaurant, triggers waitlist check
- Error states: link expired, reservation already cancelled, too close to reservation time (cutoff enforced)

---

## 6. Email Notifications

| Trigger | Recipient | Content |
|---------|-----------|---------|
| Registration | Admin | Email verification link |
| Forgot password | Admin | Password reset link |
| New public reservation (auto-confirm) | Guest | Confirmation + calendar links (.ics + Google) |
| New public reservation (manual approval) | Guest | "Pending" notice |
| New public reservation (any) | Admin | Notification with approve/reject links |
| Admin approves reservation | Guest | Confirmation + calendar links |
| Admin rejects reservation | Guest | Rejection notice |
| 24h before reservation | Guest | Reminder (cron job, daily at 9:00) |
| Admin marks guest as arrived (Advanced+) | Guest | Survey email (after configured delay, e.g. 2h) |
| Guest cancels reservation | Restaurant admin | Cancellation notice |
| Guest joins waitlist | Guest | Waitlist confirmation + unsubscribe link |
| Slot opens, guest notified | Guest | "A spot is available, confirm within 2h" |
| Guest confirms from waitlist | Guest | Booking confirmation |
| Trial expiring (< 7 days) | Admin | Warning email |
| Stripe payment success | Admin | Subscription activated email |
| Invoice request | Superadmin | New invoice request notification |

All emails include the restaurant name and branding. Calendar links in confirmation/reminder emails: Google Calendar URL + .ics download (Advanced+).

---

## 7. Feature Modules Summary

### Module: Core Reservation Management
All plans. Staff adds/edits/deletes reservations. Day view with time-block schedule. Monthly calendar navigation. Notes, guest count, duration per reservation.

### Module: Online Booking (Public)
Advanced+. Restaurant gets a unique URL. Guest books in 4 steps without login. Admin can set: open days, guest count range, auto-confirm or manual approval. Pending reservations appear in schedule with dashed border.

### Module: Booking Widget Embed
Premium only. JavaScript snippet embeds the booking form on any external website. Renders inside a div or iframe, same 4-step flow.

### Module: Guest Self-Management
Advanced+. Edit token sent in confirmation email. Guest can edit or cancel without login, up to a configurable cutoff time before the reservation.

### Module: Waitlist
Advanced+. When no slots available, guest can join waitlist. On cancellation, next in line is notified by email with a 2-hour confirmation window. Cron job handles expiry and cascading notifications.

### Module: Calendar Export
Advanced+. .ics file download + Google Calendar deep link, included in confirmation and reminder emails.

### Module: Real-time Sync
Advanced+. Server-Sent Events (SSE) push updates to all open browser sessions for the same restaurant. When any staff member adds/edits/deletes a reservation, all other sessions update without page reload. Fallback: polling every 10s if SSE not supported.

### Module: Guest Database
Advanced+. Guests identified by email per restaurant. Profile auto-created/updated on each reservation. Admin can add tags, private notes, view full history, see survey scores. Mini-profile shown inline when opening a reservation with a known email.

### Module: Statistics & Analytics
All plans (returning guests section: Advanced+). KPIs, monthly trend chart, peak day/hour charts, booking source breakdown, group size distribution, top customers table, returning guest analytics, CSV export.

### Module: Satisfaction Survey
Advanced+. Admin builds custom survey (or uses default template). Sent automatically after guest arrival (configurable delay). Guest completes on a public tokenized page. Admin reviews responses. Premium: CSV export.

### Module: Billing & Subscriptions
Stripe integration (monthly + annual). Trial period with countdown banner. Paywall on expiry. Invoice request for annual plans (manual activation by superadmin). Superadmin can assign plans manually and manage promotional discounts.

### Module: GDPR Compliance
All plans (legally required). Privacy Policy + Terms of Service pages. GDPR consent on registration and on public booking. Cookie consent banner. Public GDPR request form (access, rectification, erasure, portability). Superadmin processes requests, can trigger data anonymization. Automated cleanup cron (reservations older than 3 years anonymized).

---

## 8. Key UX Notes for Designer

- **Primary language:** Slovenian. All UI labels, error messages, and emails in Slovenian.
- **Color system:** Restaurant-specific colors for schedule blocks. UI uses a neutral palette (dark green / terracotta / cream on public pages; professional neutral on dashboard).
- **Layout:** Two-panel main dashboard (calendar left, schedule right). Single-column for public pages. Responsive: on mobile, calendar stacks above schedule.
- **No loading spinners for simple state** — instant local updates, background API sync.
- **Toast notifications** for non-blocking feedback (saved, error, copied link, etc.).
- **Modals** for: add/edit reservation, restaurant config, user management, pending approvals, guest profile.
- **Plan gating UX:** Locked features show a lock icon or upgrade prompt, not a blank space or hard error.
- **Trial banner:** persistent top banner when < 7 days remain. Dismissible per session but re-appears next login.
- **Pending badge:** red badge with count in top nav. Updates in real-time.
- **Date/time format:** Slovenian locale (d. M. yyyy, 24h time).
- **No account for guests** — guests interact only via tokenized URLs (booking, survey, edit, waitlist).

---

## 9. Out of Scope (not in current product)

- Native mobile app
- Table layout / floor plan management
- POS integration
- Multi-language support (Slovenian only)
- Staff scheduling / shift management
- Inventory or menu management
