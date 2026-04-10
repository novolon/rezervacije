# Magic Patterns Design Prompt — Restaurant Reservation SaaS

## Design Tokens

- **Primary color:** `rgb(196, 112, 75)` — terracotta/warm orange (used for CTAs, accents, active states)
- **Secondary color:** `rgb(27, 67, 50)` — deep forest green (used for headers, sidebar, badges, key UI chrome)
- **Font:** DM Sans (all weights)
- **Language:** Slovenian (all UI labels are in Slovenian)

---

## Product Overview

A multi-tenant SaaS web application for restaurant reservation management. Restaurants sign up, configure their venues, and manage reservations through a staff dashboard. Guests book online via a public link. No mobile app — responsive web only.

---

## User Roles

- **Superadmin** — product owner, sees all tenants, manages billing and discounts
- **Admin** — restaurant owner/manager, manages their restaurants, staff, and reservations
- **User (staff)** — assigned to one restaurant, manages reservations and schedule
- **Guest** — end customer, accesses only public tokenized pages (no account)

---

## Subscription Tiers

- **Trial** (14 days) — basic access
- **Basic** (4.99€/mo) — core reservation management
- **Advanced** (6.99€/mo) — online booking, email notifications, guest database, surveys, analytics
- **Premium** (9.99€/mo) — embeddable widget, custom branding, SMS, CSV exports

Plan gating UX: locked features show a lock icon or upgrade prompt inline — never a blank space or hard error.

---

## Screens to Design

### 1. Login

- Email + password fields
- "Zapomni si me" checkbox (remember me, 30-day session)
- Links: "Pozabljeno geslo?" and "Ustvari račun"
- Error state for invalid credentials

### 2. Register

- Fields: first name, last name, email, password, confirm password
- Mandatory GDPR checkbox: agree to Terms + Privacy Policy
- Optional checkbox: marketing consent
- DPA checkbox: data processing agreement
- Submit triggers email verification flow

### 3. Main Dashboard

Primary working screen for all logged-in staff.

**Top navigation bar:**
- Logo / product name
- Plan badge (Trial X dni / Basic / Advanced / Premium)
- Restaurant selector dropdown (for admins with multiple restaurants)
- Pending reservations badge with count (red, updates in real-time) — click opens pending modal
- Nav links: Statistika, Gostje, Anketa, Naročnina, Odjava
- Trial expiry warning banner when < 7 days remain (dismissible per session)

**Calendar panel:**
- Monthly calendar
- Days with reservations show a dot or count indicator
- Click on a day loads that day's reservations
- Previous/next month navigation
- Today highlighted

**Daily Schedule panel:**
- Selected date as header
- Time slots from opening to closing (e.g. 16:00–23:00)
- Each reservation is a block within its time slot
  - Block height = reservation duration (90 min spans 90 min visually)
  - Block color = restaurant's assigned color
  - Shows: guest name, number of guests, time
- Statuses:
  - `confirmed` — normal block color
  - `pending` — gray with dashed border + hourglass icon; grouped pending shown as "⏳ 3 čakajočih"
  - `rejected` / `cancelled` — not shown
- Click confirmed → Edit/details modal
- Click pending → Approve/reject modal
- Click empty slot → New reservation modal
- "Prispel" (arrived) button on each reservation block

**New/Edit Reservation Modal:**
- Fields: first name, last name, email (optional), phone (optional), number of guests, date picker, time selector, duration, notes, restaurant selector (if multi-restaurant)
- Validation: cannot book in the past
- Mini guest profile shown inline when email matches an existing guest (Advanced+):
  > "3. obisk · Zadnji: 12. 3. 2026 · ⭐ 4.2 povprečna ocena · Opomba: alergija na gluten"
- Save / Cancel / Delete buttons

**Pending Reservations Modal:**
- Lists all pending reservations for a time slot
- Per entry: name, email, phone, guests, notes
- [✓ Potrdi] [✗ Zavrni] buttons per entry

**Trial expired modal / paywall:** full-screen modal blocking app use when trial ends with no active plan

---

### 4. Admin Panel

Tabbed interface.

**Tab: Restavracije**
- Table: name, color, schedule, status, booking link, actions (edit, delete)
- [+ Dodaj restavracijo] button

**Restaurant Modal (create/edit):**
- Name, color picker, opening/closing time, default reservation duration, max guests
- Toggle: allow staff to override duration per reservation
- Online bookings section (Advanced+): enable toggle, open days (Mon–Sun checkboxes), min/max guests, auto-confirm vs. manual approval, booking link with copy button
- Guest self-management (Advanced+): allow edit/cancel toggles, cutoff hours

**Tab: Uporabniki**
- Table: name, email, role, assigned restaurant, status
- [+ Dodaj uporabnika] button

**User Modal:**
- First name, last name, email, role (admin/user), assigned restaurant, password

---

### 5. Statistics

**Filter bar:** restaurant selector, period picker (Last 30 days / 3 months / 6 months / 1 year / Custom), [Izvozi CSV] button

**KPI cards (4):** Total reservations, Total guests, Avg guests/reservation, Arrival rate %

**Monthly trend:** bar chart, last 12 months, X = month, Y = reservation count, tooltip shows reservations + guests

**Patterns (2 charts side by side):** reservations by day of week (horizontal bar, Mon–Sun), reservations by hour (horizontal bar, peak hours)

**Booking source:** donut chart (staff-added vs. public booking), status breakdown table (Confirmed / Rejected / Pending %)

**Returning Guests (Advanced+):**
- KPI row: Unique guests | Returning guests | Return rate %
- Donut chart: New vs. Returning
- Stat cards: avg days between visits, % returning within 90 days
- Retention curve: % returning at 30 / 60 / 90 / 180 days
- Top 20 guests table: rank, name, email, visits, total guests, first/last visit, "Zvesti gost" badge for 5+ visits

**Group size distribution:** bar chart — 1 / 2 / 3 / 4 / 5+ persons (% of total)

---

### 6. Guest Database (Advanced+)

- Search bar (name / email / phone)
- Table: name, email, phone, total visits, last visit, tags, actions

**Guest Profile Modal:**
- Contact info (inline editable)
- Tags: VIP, Alergija, Posebne zahteve, Redni gost, No-show, Vegetarijanec/Vegan + custom
- Admin private note (textarea)
- Full reservation history: date, time, guests, status
- Survey results: date, average rating
- Stats: total visits, no-shows, avg days between visits

---

### 7. Survey Builder (Advanced+)

- Restaurant selector
- Survey settings: title, description, thank-you message, auto-send toggle, delay (X hours after arrival), include thank-you/survey link toggles
- Question builder:
  - Add question button
  - Question types: Star rating (1–5), Radio buttons, Checkboxes, Short text, Long text
  - Per question: text, required toggle, options (for radio/checkbox), delete, reorder (up/down or drag)
- Save button

---

### 8. Survey Responses (Advanced+)

- Filters: restaurant, date range, consent type
- Table: submission date, guest name, consent type, status (submitted / email sent / pending)
- Click row → response detail modal with per-question answers rendered by type
- [Izvozi CSV] button (Premium only)

---

### 9. Billing

**Trial/unsubscribed state:**
- Current plan status + days remaining or expiry notice
- Pricing cards: Basic / Advanced / Premium with monthly + annual pricing (annual shows crossed-out price when promotion active), feature list, [Izberi paket] button
- Annual plan: also [Zahtevaj račun] option

**Active subscriber state:**
- Current plan name, status (active / cancelled / paused), billing cycle, next billing date
- [Upravljaj naročnino] button

---

### 10. Superadmin Panel

**Tab: Administratorji**
- Table: name, email, plan, trial expiry, joined date, status
- Per row: [Dodeli paket] button → modal (select plan, end date)

**Tab: Popusti**
- Table: plan, label, discounted monthly, discounted yearly, valid from–to, status
- [Dodaj popust] form

**Tab: GDPR zahteve**
- Table: type (access/rectification/erasure/portability), email, restaurant, status, requested/resolved at
- Status update + notes per request
- Erasure action: anonymizes user data

---

### 11. Public Booking Page (guest-facing, no login)

Clean, minimal, restaurant-branded. Progress indicator showing steps 1–4.

**Step 1 — Number of guests:**
- Buttons from min to max guests
- "Več →" for groups above max (shows number input)
- [Naprej →] button

**Step 2 — Date:**
- Monthly calendar
- Gray = closed/past, green/active = bookable
- Click date → goes to Step 3

**Step 3 — Time:**
- Header: "Za [datum], [N] gostov"
- Grid of available time buttons
- [← Nazaj] link

**Step 4 — Guest details:**
- First name + last name (required), email (required), phone (optional), notes (optional)
- GDPR consent checkbox (required)
- Marketing opt-in checkbox (optional)
- [← Nazaj] [Oddaj rezervacijo]

**Step 5 — Confirmation:**
- Auto-confirm: "✓ Rezervacija potrjena! Poslali smo vam potrditveno e-pošto."
- Manual approval: "⏳ Zahteva prejeta. Obvestili vas bomo po e-pošti."
- [Nova rezervacija] button

---

### 12. Guest Survey Page (guest-facing, no login)

- Restaurant name and logo
- Survey title and description
- Questions rendered by type: star rating (5 stars with hover), radio, checkbox, short text, long text
- Consent section (required radio): publish with name / anonymous / do not publish
- [Oddaj] button + required field validation
- Edge case screens: invalid token, already submitted, success thank-you

---

### 13. Reservation Edit / Cancel (guest-facing, no login)

- Shows current reservation details
- Guest can change: date, time, number of guests, notes
- [Shrani spremembe] → validates availability, updates
- [Prekliči rezervacijo] → confirmation dialog with optional reason (Sprememba načrtov / Bolezni / Drugo)
- Error screens: link expired, already cancelled, too close to reservation time

---

## Global UX Notes

- **Toast notifications** for non-blocking feedback (saved, error, link copied, etc.)
- **Modals** for: add/edit reservation, restaurant config, user management, pending approvals, guest profile
- **No loading spinners for simple state** — instant local updates
- **Pending badge:** red badge with count in top nav
- **Date/time format:** Slovenian locale — `d. M. yyyy`, 24h time
- **Plan gating:** locked features show lock icon or upgrade prompt inline
- **Trial banner:** persistent top banner when < 7 days remain, dismissible per session
