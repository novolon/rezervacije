# Billing & Paketi – implementacijski plan

> Branch: `feature/billing-packages`
> Valuta: EUR | Plačilni sistem: Stripe

---

## Paketi

| Paket    | Mesečno | Letno   | Slug       |
|----------|---------|---------|------------|
| Basic    | 4,99 €  | 49,99 € | `basic`    |
| Advanced | 6,99 €  | 69,99 € | `advanced` |
| Premium  | 9,99 €  | 99,99 € | `premium`  |

### Funkcionalnosti po paketu

| Funkcionalnost                             | Trial | Basic | Advanced | Premium |
|--------------------------------------------|:-----:|:-----:|:--------:|:-------:|
| Upravljanje rezervacij                     | ✓     | ✓     | ✓        | ✓       |
| Dodajanje restavracij                      | ✓     | ✓     | ✓        | ✓       |
| Dodajanje osebja                           | ✓     | ✓     | ✓        | ✓       |
| Email gostom ob rezervaciji                |       |       | ✓        | ✓       |
| Opomnik gostom 24h pred rezervacijo        |       |       | ✓        | ✓       |
| Javna rezervacijska povezava               |       |       | ✓        | ✓       |
| Potrjevanje/zavračanje rezervacij          |       |       | ✓        | ✓       |
| Embedni widget (JS na spletno stran)       |       |       |           | ✓       |
| Branding (barva, logo)                     |       |       |           | ✓       |
| Samodejno potrjevanje z omejit. gostov     |       |       |           | ✓       |
| SMS obvestila                              |       |       |           | ✓       |

---

## FAZA 1 – Baza + Feature gating + Trial warning (brez Stripe)

### 1.1 SQL migracija
- [x] Ustvari `sql/migrate_billing.sql`
  - tabela `subscriptions` (user_id, plan_slug, status, billing_cycle, payment_method, started_at, ends_at, stripe_subscription_id, stripe_customer_id)
  - tabela `plan_discounts` (plan_slug, label, discounted_monthly, discounted_yearly, valid_from, valid_until, is_active)
  - migracija obstoječih trialnih računov → vnos v `subscriptions`

### 1.2 Plan engine
- [x] Ustvari `includes/plans.php`
  - konstante/config za vse pakete in funkcionalnosti
  - `get_active_subscription(PDO, user_id)` → vrne trenutno naročnino
  - `user_has_feature(PDO, user_id, feature_slug)` → bool
  - `get_trial_days_left(subscription)` → int
  - `require_feature(PDO, session, feature_slug)` → json 403 če ni dostopa

### 1.3 Session refresh
- [x] V `includes/auth_check.php` ob vsakem requestu shrani `plan_slug` in `trial_days_left` v session
  - da front ne rabi vsakič klicat API za to

### 1.4 Trial warning banner
- [x] V `pages/main.php` – banner nad headerjem ko < 7 dni do konca triala
- [x] V `pages/admin.php` – isti banner
- [x] Banner: "Vaš trial poteče čez X dni. [Izberi paket →]"
- [x] Ko trial poteče: modal/blokada z gumbom na pricing

### 1.5 Billing stran (admin)
- [x] Nova stran `pages/billing.php`
  - prikaže trenutni paket in status
  - prikaže pricing tabelo vseh paketov (Basic, Advanced, Premium)
  - gumb "Izberi paket" → placeholder (Stripe pride v F2)
  - za letno plačilo: opcija "po predračunu" → placeholder (F3)
- [x] Link v navigaciji (admin header)

### 1.6 Superadmin – ročno dodeljevanje paketov
- [x] V `api/superadmin.php` – POST action `assign_plan`
  - parametri: user_id, plan_slug, ends_at (datum poteka ali null = trajno)
  - vpiše v `subscriptions`
- [x] V `pages/superadmin.php` – v tabeli adminov dodaj gumb "Dodeli paket"
  - modal: izberi paket (trial/basic/advanced/premium), datum poteka
- [x] V `api/superadmin.php` – GET action `subscription` za prikaz trenutnega paketa v tabeli

### 1.7 Testiranje faze 1
- [ ] Ročno dodeli paket adminu via superadmin
- [ ] Preveri feature gating (trial admin ne sme videti advanced funkcij)
- [ ] Preveri trial warning (nastavi trial_ends_at na jutri in preveri banner)

---

## FAZA 2 – Stripe integracija

> Potrebno: Stripe secret key, publishable key, webhook endpoint URL

### 2.1 Setup
- [ ] `composer require stripe/stripe-php`
- [ ] Dodaj v `config.php`: `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`

### 2.2 Checkout
- [ ] `api/billing.php` – POST `create_checkout_session`
  - parametri: plan_slug, billing_cycle (monthly/yearly)
  - ustvari Stripe Checkout Session, vrne URL
- [ ] `pages/billing.php` – gumb "Izberi paket" pokliče API in preusmeri na Stripe

### 2.3 Uspešno plačilo
- [ ] `pages/billing-success.php` – stran po uspešnem plačilu (Stripe redirect)
- [ ] Prikaže potrditev, posodobi session

### 2.4 Webhook handler
- [ ] `api/stripe-webhook.php`
  - `checkout.session.completed` → aktiviraj naročnino v `subscriptions`
  - `invoice.payment_succeeded` → podaljšaj `ends_at`
  - `invoice.payment_failed` → status = 'payment_failed', pošlji email
  - `customer.subscription.deleted` → status = 'canceled'
  - `customer.subscription.trial_will_end` → pošlji opozorilo (backup za in-app)

### 2.5 Customer portal
- [ ] `api/billing.php` – POST `customer_portal` → vrne URL Stripe portala
- [ ] Gumb "Upravljaj naročnino" v `pages/billing.php`

### 2.6 Konfiguracija Stripe
- [ ] V Stripe dashboardu: ustvari Products + Prices za vse 6 kombinacij (3 paketi × 2 cikla)
- [ ] Shrani Price ID-je v `config.php` ali `includes/plans.php`
- [ ] Webhook endpoint registracija v Stripe dashboardu

---

## FAZA 3 – Letno plačilo po predračunu

> Potrebno: email naslov za prejem zahtevkov

### 3.1 Flow
- [ ] V `pages/billing.php` – za letni plan dodaj opcijo "Plačilo po predračunu"
- [ ] `api/billing.php` – POST `request_invoice`
  - shrani zahtevek v `subscriptions` (status = 'pending_invoice')
  - pošlji email na superadmin email z podatki (admin ime, paket, znesek)
- [ ] Superadmin v panelu vidi pending_invoice račune in jih ročno aktivira (assign_plan)

---

## FAZA 4 – Začasni popusti (superadmin)

### 4.1 UI
- [ ] V `pages/superadmin.php` – nov tab "Popusti"
- [ ] Forma: paket, opis akcije, znižana cena mesečno, znižana cena letno, datum od–do

### 4.2 API
- [ ] `api/superadmin.php` – POST `create_discount`, GET `discounts`, DELETE `discount`

### 4.3 Prikaz
- [ ] `pages/billing.php` – prikaže znižano ceno kadar je aktiven popust (prečrtaj originalno)

---

## FAZA 5 – Napredni paket: self-booking (ločen branch)

> Opomba: kompleksna funkionalnost, planirati ločeno

- [ ] Javna rezervacijska stran (`/book/{token}`)
- [ ] Admin nastavi delovne dni + rezervacijske čase po dnevih
- [ ] Korak 1: izbira datuma
- [ ] Korak 2: izbira ure
- [ ] Korak 3: podatki gosta
- [ ] Email potrditvi (gostu + restavraciji)
- [ ] Admin odobri/zavrne rezervacijo
- [ ] Opomnik 24h pred rezervacijo (cron job)

---

## FAZA 6 – Premium paket: embed widget (ločen branch)

- [ ] `GET /widget/{token}` vrne JS snippet
- [ ] Widget se renderira znotraj div-a na zunanji strani
- [ ] Isti booking flow kot Faza 5, v iframu ali shadow DOM

---

## Odprta vprašanja (za dogovor)

| # | Vprašanje | Status |
|---|-----------|--------|
| 1 | Stripe API ključi (test + live) | ⏳ čaka |
| 2 | Webhook URL (produkcijski strežnik) | ⏳ čaka |
| 3 | Email za predračunske zahtevke | ⏳ čaka |
| 4 | Stripe Price ID-ji (po setupu) | ⏳ čaka |
| 5 | SMS provider za Premium (Twilio?) | ⏳ čaka |

---

## Napredek

- [x] Faza 1.1 – SQL migracija
- [x] Faza 1.2 – Plan engine (`includes/plans.php`)
- [x] Faza 1.3 – Session refresh
- [x] Faza 1.4 – Trial warning banner
- [x] Faza 1.5 – Billing stran
- [x] Faza 1.6 – Superadmin: ročno dodeljevanje
- [ ] Faza 1.7 – Testiranje
- [ ] Faza 2 – Stripe
- [ ] Faza 3 – Predračun
- [ ] Faza 4 – Popusti
- [ ] Faza 5 – Self-booking
- [ ] Faza 6 – Embed widget
