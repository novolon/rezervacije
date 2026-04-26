# AFFILIATE_PLAN.md – Affiliate program za Rezervacije SaaS

> Namen: omogočiti, da kdorkoli (tudi nekdo, ki **ni uporabnik aplikacije**)
> postane affiliate, dobi unikatno povezavo in prejme provizijo, ko se restavracija
> preko njegove povezave registrira in plača naročnino. Plan pokriva tako
> **funkcionalnost** (kaj sistem dela, za koga, in kako), kot **implementacijo**
> (DB shema, fajli, API endpointi, integracija s Stripe).

---

## 1. Cilji in pravila igre

- **Ciljna publika affiliatov:** gostinski svetovalci, agencije, blogerji, freelancerji,
  obstoječi uporabniki, vplivneži v HoReCa segmentu.
- **Nima zahteve po Rezervacije računu.** Affiliate je **ločena entiteta**, ima
  svoj login, svoj dashboard in svoja “sub-pravila” (ne vidi nobenega podatka
  iz tenantov, samo svoje statistike).
- **Brezplačno za prijavo**, vendar mora superadmin **odobriti** (proti zlorabam,
  kontrola identitete, davčni status).
- **Provizija:** model **recurring** – % od neto plačila restavracije za **12 mesecev**
  od prvega plačila (klasičen SaaS partner model).
  Privzeto **20 %** za prvo leto, konfigurabilno per-affiliate (možnost VIP tarif).
- **Hold period:** provizija je rezervirana takoj ob plačilu, postane **payable**
  šele po **45 dneh** (ščitimo se proti chargebackom in refundacijam v garanciji).
- **Self-referral je prepovedan** (enak email ali enak `tax_number`/`vat_id`
  med affiliatom in registrirano restavracijo).
- **Atribucija:** *last-click wins* z dolžino piškotka **60 dni**. Če uporabnik
  pride preko več affiliatov, šteje zadnji klik pred registracijo.
- **Valuta:** EUR. Brez konverzij.
- **Davčna obravnava:** affiliate sam izda račun (fizične osebe → avtorska
  pogodba ali popoldanski s.p.; pravne osebe → račun z DDV po potrebi). Aplikacija
  samo vodi seznam zaslužkov in plačil; obdavčitev je odgovornost affiliata.
  Pripravimo PDF povzetek (statement) za vsako izplačilo.

---

## 2. Uporabniške zgodbe

### Kot **bodoči affiliate**
1. Pridem na `app.rezervacije.si/affiliate` – javna landing stran z opisom programa,
   provizijo, FAQ, registracijskim gumbom.
2. Registriram se: ime, email, geslo, naslov, davčni status (fizična oseba /
   s.p. / d.o.o. / tujina), TRR (IBAN), opcijsko davčna št. / DDV ID.
3. Potrdim email, pristanem na **Affiliate Pogoje** (ločen TOS dokument).
4. Status računa = `pending`. Superadmin me odobri, dobim email z linkom na dashboard.
5. V dashboardu vidim svojo **ref povezavo** (`https://app.rezervacije.si/?ref=AB12CD34`),
   QR kodo, marketing material (logoti, primere objav), **klike**, **prijave**,
   **plačnike**, **proviziji v hold-u**, **payable znesek**, **zgodovino izplačil**.

### Kot **restavracija (potencialna stranka)**
1. Pridem preko affiliate povezave: `/?ref=AB12CD34` ali `/register.php?ref=AB12CD34`.
2. Sistem nastavi piškotek `rez_aff` z affiliate ID + 60-dnevnim TTL + UTM podatki.
3. Lahko brskam po landing strani, se vrnem kasneje – piškotek še vedno velja.
4. Ko se registriram, se kreira povezava `affiliate_referrals` (user_id ↔ affiliate_id).
5. Začnem 30-dnevni trial. Povezava obstaja, ampak provizije še **ni**.
6. Ko opravim prvo plačilo (Stripe `invoice.payment_succeeded`), se ustvari prva
   provizija v statusu `pending` (hold).
7. Dobim **5 % popust ob prvem plačilu** (opcijsko – kupon ki ga affiliate dvigne
   konverzijo). Odločitev: privzeto **izklopljeno**, vklopljivo iz superadmin
   nastavitev kot per-affiliate kupon.

### Kot **superadmin**
1. Vidim seznam affiliatov, status (`pending`, `active`, `suspended`).
2. Odobrim novega affiliata (preverim TRR, identiteto).
3. Konfiguriram global default % komisije in privzeti hold period.
4. Per-affiliate lahko nastavim drugačno % (VIP partner npr. 30 %), drugačen
   trajanje recurringa (12 → 24 mesecev), ali pavšalno provizijo (one-off, npr. 30 €).
5. Vidim agregirano sliko: kliki, konverzija, pending zneski, dolg do affiliatov.
6. **Sprožim batch izplačilo** (mesečno): superadmin gre na “Payouts”, sistem
   izračuna `payable` zneske vseh affiliatov nad pragom (privzeto 30 €).
7. Sistem generira **CSV za nakazila preko banke** (IBAN, znesek, namen plačila)
   in **PDF statement** za vsakega affiliata. Status `payout` postane `processing`.
8. Po opravljenem nakazilu superadmin v aplikaciji označi izplačilo kot `paid`.
   Affiliate dobi email s PDF priponko.

---

## 3. Funkcionalne komponente

### 3.1 Public landing
- `/affiliate/` (landing) – statična, marketinška: kako deluje, koliko zaslužim,
  primer izračuna, FAQ, “Postani affiliate” CTA.
- `/affiliate/register.php` – javna registracija (brez auth_check).
- `/affiliate/login.php`, `/affiliate/forgot-password.php`, `/affiliate/reset-password.php`,
  `/affiliate/verify-email.php` – ločen auth tokovod od osnovne aplikacije.
- `/affiliate/terms.php` – pogoji programa (provizija, hold, izplačila, prepoved
  klikobotov, končanje sodelovanja).

### 3.2 Affiliate dashboard (auth)
- `/affiliate/dashboard.php` – overview kartice (kliki/registracije/aktivni plačniki/zaslužek).
- `/affiliate/links.php` – ref povezava (kopiraj), QR koda, generator landing-page-deep-linkov
  (npr. `?ref=XXX&utm_campaign=letak`), marketing material.
- `/affiliate/referrals.php` – tabela registracij (datum, status: trial / active / canceled,
  paket). **Brez prikaza imena restavracije** (zasebnost) – pokažemo samo
  inicialke ali maskirano: “Resta***ija A.B.”
- `/affiliate/earnings.php` – tabela provizij: datum, znesek, status (`pending`,
  `payable`, `paid`, `void`), referenca na batch plačilo.
- `/affiliate/payouts.php` – zgodovina izplačil + PDF prenos.
- `/affiliate/profile.php` – urejanje TRR, naslova, davčnih podatkov, gesla.

### 3.3 Tracking
- Vsak request s parametrom `?ref=CODE` na **katerikoli** javni strani
  (`/`, `/home/`, `/register.php`, `/affiliate/`):
  - validira `ref_code` (ali obstaja in ali je affiliate `active`),
  - shrani v cookie `rez_aff` (HttpOnly=false zaradi JS landinga, SameSite=Lax,
    Path=/, max-age 60 dni),
  - logira klik v `affiliate_clicks` (asinhrono, brez blokiranja UX-a).
- Boti: filtriraj `User-Agent` z osnovno blacklisto + rate-limit po IP (npr. > 30
  klikov/min iz istega IP-ja → ne logiramo, samo cookie).

### 3.4 Conversion tracking
- V `register.php` (admin signup) ob ustvarjanju userja:
  - preveri `rez_aff` cookie,
  - preveri **anti-self-referral** (email / tax_number),
  - vstavi vrstico v `affiliate_referrals` z `user_id`, `affiliate_id`, `ref_code`,
    `cookie_set_at`, `landing_url`, `utm_*`,
  - počisti cookie.

### 3.5 Commission generation
- V `api/stripe-webhook.php` v `handle_invoice_paid()` po posodobitvi `subscriptions`:
  - poišči `affiliate_referral` za uporabnika,
  - če obstaja in **ni preteklo `commission_window_months`** od prvega plačila,
  - izračunaj % od `invoice.amount_paid` (po odbitku DDV in chargebackov),
  - vstavi `affiliate_commissions` (status `pending`, `available_at = NOW() + hold_days`).
- Ob `invoice.payment_failed` ali `charge.refunded` → posodobimo provizijo na `void`,
  če je še v hold-u; če je že `paid`, beležimo **negativni znesek** (clawback) za
  naslednji payout cycle.

### 3.6 Payout flow
- Cron `cron/affiliate_payable.php` (dnevno): premakne `pending` → `payable`,
  ko pretečejo hold dnevi.
- Superadmin akcija (ročna): `Generate Payout Batch` →
  - izračuna `SUM(amount)` per affiliate iz vseh `payable` provizij,
  - filtra po `min_payout_amount`,
  - kreira `affiliate_payouts` (status `processing`),
  - poveže pripadajoče `affiliate_commissions` na payout (status → `paid`),
  - generira **SEPA-friendly CSV** (IBAN, znesek, namen plačila s payout #),
  - generira **PDF statement** per affiliate (storno samo iz hold zgornjih layerjev).
- Superadmin po izvedenem nakazilu klikne `Mark as Paid` → status `paid`,
  affiliate dobi email s priponko PDF.

---

## 4. Database shema

> Vse tabele v utf8mb4, InnoDB, FK po obstoječi konvenciji.
> Migracija: `sql/migrate_affiliate.sql`.

```sql
-- ─── Affiliate račun ────────────────────────────────────────────
CREATE TABLE affiliates (
  id                    INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  ref_code              VARCHAR(12)   NOT NULL UNIQUE,                  -- npr. 'AB12CD34'
  email                 VARCHAR(180)  NOT NULL UNIQUE,
  email_verified_at     DATETIME      NULL,
  password_hash         VARCHAR(255)  NOT NULL,
  full_name             VARCHAR(160)  NOT NULL,
  legal_form            ENUM('individual','sole_trader','company','foreign') NOT NULL DEFAULT 'individual',
  company_name          VARCHAR(180)  NULL,
  tax_number            VARCHAR(40)   NULL,
  vat_id                VARCHAR(40)   NULL,
  iban                  VARCHAR(34)   NULL,
  bic                   VARCHAR(11)   NULL,
  address               VARCHAR(255)  NULL,
  city                  VARCHAR(80)   NULL,
  postal_code           VARCHAR(15)   NULL,
  country               CHAR(2)       NOT NULL DEFAULT 'SI',
  -- per-affiliate konfiguracija
  commission_percent    DECIMAL(5,2)  NULL,                             -- NULL = uporabi global default
  commission_flat_eur   DECIMAL(8,2)  NULL,                             -- alternativa % (one-off)
  commission_window_months SMALLINT UNSIGNED NULL,                      -- NULL = global default (12)
  hold_days             SMALLINT UNSIGNED NULL,                         -- NULL = global (45)
  min_payout_eur        DECIMAL(8,2)  NULL,                             -- NULL = global (30)
  -- status
  status                ENUM('pending','active','suspended','rejected') NOT NULL DEFAULT 'pending',
  rejected_reason       VARCHAR(255)  NULL,
  -- consent / GDPR
  terms_accepted_at     DATETIME      NOT NULL,
  terms_version         VARCHAR(20)   NOT NULL,
  marketing_consent     TINYINT(1)    NOT NULL DEFAULT 0,
  -- meta
  remember_token        VARCHAR(255)  NULL,
  remember_expires      DATETIME      NULL,
  reset_token           VARCHAR(64)   NULL,
  reset_token_expires   DATETIME      NULL,
  verification_token    VARCHAR(64)   NULL,
  approved_by           INT UNSIGNED  NULL,                             -- superadmin user.id
  approved_at           DATETIME      NULL,
  created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Klikovni log ───────────────────────────────────────────────
CREATE TABLE affiliate_clicks (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  affiliate_id    INT UNSIGNED  NOT NULL,
  ref_code        VARCHAR(12)   NOT NULL,
  ip_hash         CHAR(64)      NOT NULL,                               -- SHA-256 IP+salt (GDPR pseudonim)
  user_agent      VARCHAR(255)  NULL,
  referer         VARCHAR(512)  NULL,
  landing_url     VARCHAR(512)  NULL,
  utm_source      VARCHAR(80)   NULL,
  utm_medium      VARCHAR(80)   NULL,
  utm_campaign    VARCHAR(120)  NULL,
  is_bot          TINYINT(1)    NOT NULL DEFAULT 0,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
  INDEX idx_aff_date (affiliate_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Referrals (affiliate ↔ registriran admin) ─────────────────
CREATE TABLE affiliate_referrals (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  affiliate_id      INT UNSIGNED  NOT NULL,
  user_id           INT UNSIGNED  NOT NULL UNIQUE,                       -- 1 user = 1 affiliate (last click wins ob registraciji)
  ref_code          VARCHAR(12)   NOT NULL,
  cookie_set_at     DATETIME      NULL,
  landing_url       VARCHAR(512)  NULL,
  utm_source        VARCHAR(80)   NULL,
  utm_medium        VARCHAR(80)   NULL,
  utm_campaign      VARCHAR(120)  NULL,
  first_paid_at     DATETIME      NULL,                                  -- ko se zgodi 1. invoice.payment_succeeded
  commission_until  DATETIME      NULL,                                  -- = first_paid_at + commission_window_months
  status            ENUM('signed_up','converted','churned','rejected') NOT NULL DEFAULT 'signed_up',
  rejected_reason   VARCHAR(255)  NULL,                                  -- npr. self-referral
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE CASCADE,
  INDEX idx_affiliate (affiliate_id),
  INDEX idx_status    (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Provizije ──────────────────────────────────────────────────
CREATE TABLE affiliate_commissions (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  affiliate_id        INT UNSIGNED  NOT NULL,
  referral_id         BIGINT UNSIGNED NOT NULL,
  user_id             INT UNSIGNED  NOT NULL,
  subscription_id     INT UNSIGNED  NULL,                                -- subscriptions.id
  stripe_invoice_id   VARCHAR(100)  NULL,
  base_amount_eur     DECIMAL(10,2) NOT NULL,                            -- znesek invoice (brez DDV)
  percent             DECIMAL(5,2)  NULL,
  flat_amount_eur     DECIMAL(10,2) NULL,
  amount_eur          DECIMAL(10,2) NOT NULL,                            -- končni znesek provizije (lahko negativen pri clawbacku)
  status              ENUM('pending','payable','paid','void','clawback') NOT NULL DEFAULT 'pending',
  available_at        DATETIME      NOT NULL,                            -- = invoice_paid_at + hold_days
  payout_id           BIGINT UNSIGNED NULL,
  void_reason         VARCHAR(255)  NULL,
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (affiliate_id)    REFERENCES affiliates(id)             ON DELETE CASCADE,
  FOREIGN KEY (referral_id)     REFERENCES affiliate_referrals(id)    ON DELETE CASCADE,
  FOREIGN KEY (user_id)         REFERENCES users(id)                  ON DELETE CASCADE,
  FOREIGN KEY (subscription_id) REFERENCES subscriptions(id)          ON DELETE SET NULL,
  INDEX idx_aff_status      (affiliate_id, status),
  INDEX idx_available_at    (available_at),
  INDEX idx_stripe_invoice  (stripe_invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Izplačila (batch) ─────────────────────────────────────────
CREATE TABLE affiliate_payouts (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  affiliate_id    INT UNSIGNED  NOT NULL,
  amount_eur      DECIMAL(10,2) NOT NULL,
  currency        CHAR(3)       NOT NULL DEFAULT 'EUR',
  status          ENUM('processing','paid','failed') NOT NULL DEFAULT 'processing',
  iban_snapshot   VARCHAR(34)   NOT NULL,                                -- IBAN ob času payouta
  reference       VARCHAR(40)   NOT NULL UNIQUE,                         -- npr. 'AFP-2026-04-001'
  statement_pdf   VARCHAR(255)  NULL,                                    -- relativna pot do generiranega PDF-a
  notes           TEXT          NULL,
  created_by      INT UNSIGNED  NULL,                                    -- superadmin
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at         DATETIME      NULL,
  FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE RESTRICT,
  FOREIGN KEY (created_by)   REFERENCES users(id)     ON DELETE SET NULL,
  INDEX idx_aff_status (affiliate_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Globalna konfiguracija ─────────────────────────────────────
CREATE TABLE affiliate_settings (
  setting_key     VARCHAR(60)   NOT NULL PRIMARY KEY,
  setting_value   VARCHAR(255)  NOT NULL,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Privzeti zapisi
INSERT INTO affiliate_settings (setting_key, setting_value) VALUES
  ('default_commission_percent',    '20.00'),
  ('default_commission_window_m',   '12'),
  ('default_hold_days',             '45'),
  ('default_min_payout_eur',        '30.00'),
  ('cookie_ttl_days',               '60'),
  ('terms_version',                 '1.0');
```

> FK na `subscriptions.id` zahteva `subscriptions.id` indeks; ta že obstaja (PK).

---

## 5. Mapa fajlov in API endpointi

### 5.1 Direktoriji
```
/affiliate/
  index.php                landing (javna)
  register.php             javna registracija
  login.php                login affiliata
  forgot-password.php
  reset-password.php
  verify-email.php
  logout.php
  dashboard.php
  links.php
  referrals.php
  earnings.php
  payouts.php
  profile.php
  terms.php

/api/
  affiliate.php            affiliate self-service API (login, profile update, link gen)
  affiliate_admin.php      superadmin API (approve, configure, payouts)
  affiliate_track.php      pikselski endpoint za click logging (POST iz JS, async)

/includes/
  affiliate_auth.php       require_affiliate(), is_affiliate_logged_in()
  affiliate_helper.php     ref_code generator, atribucija, anti-fraud, commission calc
  affiliate_session.php    ločen session namespace ($_SESSION['affiliate_*'])

/cron/
  affiliate_payable.php    pending → payable po hold periodi (dnevno)

/sql/
  migrate_affiliate.sql

/assets/css/
  affiliate.css

/assets/js/
  affiliate.js             dashboard interakcije, copy-link, charts

/lang/sl/affiliate.json    Slovenske stringe (preko obstoječega lang sistema)
/lang/en/affiliate.json
```

### 5.2 API endpointi

**Public (brez auth):**
- `GET  /api/affiliate_track.php?ref=XXX&u=...&utm_source=...` → vrne 1×1 piksel ali 204
- `POST /affiliate/register.php` → kreira affiliate (`status=pending`), pošlje verify email
- `POST /affiliate/login.php` → login form
- `POST /affiliate/forgot-password.php`, `reset-password.php`, `verify-email.php`

**Affiliate self-service** (`/api/affiliate.php`, zahteva affiliate session):
- `GET  ?action=stats&range=30d` → kliki, registracije, plačniki, zaslužek
- `GET  ?action=referrals` → tabela referralov (maskirana)
- `GET  ?action=earnings` → tabela provizij
- `GET  ?action=payouts` → seznam izplačil
- `GET  ?action=payout_pdf&id=N` → prenos PDF statementa
- `POST {action: 'update_profile', ...}` → urejanje TRR, naslova
- `POST {action: 'change_password', old, new}`
- `POST {action: 'generate_link', utm_source, utm_medium, utm_campaign}` → vrne deep-link

**Superadmin** (`/api/affiliate_admin.php`, `require_superadmin`):
- `GET  ?action=list&status=pending` → seznam affiliatov
- `GET  ?action=detail&id=N` → vse o affiliatu (klike, referrale, provizije, payouti)
- `POST {action: 'approve', id}` → odobri (pošlje email)
- `POST {action: 'reject', id, reason}`
- `POST {action: 'suspend', id, reason}`
- `POST {action: 'configure', id, commission_percent, commission_window, ...}`
- `POST {action: 'set_global', key, value}` → spremeni `affiliate_settings`
- `POST {action: 'create_payout_batch'}` → kreira `affiliate_payouts` za vse upravičene
- `GET  ?action=payout_csv&batch=YYYY-MM` → SEPA CSV za banko
- `POST {action: 'mark_paid', payout_ids: [...]}` → potrdi izvršena nakazila
- `POST {action: 'manual_adjustment', affiliate_id, amount, note}` → ročni clawback / bonus

### 5.3 Modificirani obstoječi fajli

| Fajl | Sprememba |
|------|-----------|
| `register.php` | Beri `rez_aff` cookie, anti-self-referral check, vstavi `affiliate_referrals` |
| `api/stripe-webhook.php` | V `handle_invoice_paid()` kliči `affiliate_record_commission()`. V `handle_invoice_failed()` in v dodanem `charge.refunded` evente sproži clawback. |
| `includes/mailer.php` | Dodaj: `send_affiliate_verify_email`, `send_affiliate_approved_email`, `send_affiliate_rejected_email`, `send_affiliate_payout_email` |
| `includes/lang.php` / `lang/*` | Stringi za affiliate UI |
| `pages/superadmin.php` | Dodaj nov tab “Affiliate” |
| `home/` (React landing) | Sprejmi `?ref=` v URL, postavi cookie pred preusmeritvijo na register |
| `widget.js` | (samo če ga affiliate uporablja v marketingu — ni nujno za MVP) |

---

## 6. Ključni algoritmi

### 6.1 Generacija `ref_code`
```php
// 8 znakov, brez ambivalentnih (0/O, 1/I/L). Loop dokler ni unique.
function generate_ref_code(PDO $pdo): string {
    $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 8; $i++) $code .= $alpha[random_int(0, strlen($alpha)-1)];
        $stmt = $pdo->prepare("SELECT 1 FROM affiliates WHERE ref_code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn());
    return $code;
}
```

### 6.2 Atribucija ob registraciji
```php
function attach_affiliate_on_signup(PDO $pdo, int $newUserId, string $newEmail, ?string $taxNumber): void {
    if (empty($_COOKIE['rez_aff'])) return;
    $payload = json_decode($_COOKIE['rez_aff'], true) ?: [];
    $code    = $payload['code'] ?? null;
    if (!$code) return;

    $stmt = $pdo->prepare("SELECT id, email, tax_number FROM affiliates WHERE ref_code = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$code]);
    $aff = $stmt->fetch();
    if (!$aff) return;

    // Anti-self-referral
    if (strcasecmp($aff['email'], $newEmail) === 0) return;
    if ($taxNumber && $aff['tax_number'] && $aff['tax_number'] === $taxNumber) return;

    $pdo->prepare("
        INSERT IGNORE INTO affiliate_referrals
        (affiliate_id, user_id, ref_code, cookie_set_at, landing_url, utm_source, utm_medium, utm_campaign)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $aff['id'], $newUserId, $code,
        $payload['ts']      ?? null,
        $payload['landing'] ?? null,
        $payload['utm_source']   ?? null,
        $payload['utm_medium']   ?? null,
        $payload['utm_campaign'] ?? null,
    ]);

    setcookie('rez_aff', '', time() - 3600, '/');
}
```

### 6.3 Računanje provizije (Stripe webhook)
```php
function affiliate_record_commission(PDO $pdo, int $userId, array $invoice, int $subscriptionId): void {
    $stmt = $pdo->prepare("SELECT * FROM affiliate_referrals WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $ref = $stmt->fetch();
    if (!$ref) return;

    // Pretekel commission window?
    if ($ref['commission_until'] && strtotime($ref['commission_until']) < time()) return;

    $aff = affiliate_get($pdo, (int)$ref['affiliate_id']);
    if (!$aff || $aff['status'] !== 'active') return;

    // Konfiguracija (per-affiliate ali global default)
    $percent  = $aff['commission_percent']    ?? affiliate_setting($pdo, 'default_commission_percent');
    $flat     = $aff['commission_flat_eur'];
    $holdDays = $aff['hold_days']             ?? affiliate_setting($pdo, 'default_hold_days');

    $base   = ((int)$invoice['amount_paid']) / 100.0;  // EUR brez DDV (Stripe amount_paid je net)
    $amount = $flat ? (float)$flat : round($base * (float)$percent / 100, 2);

    if ($amount <= 0) return;

    $availableAt = (new DateTime('@' . ($invoice['paid_at'] ?? time())))
                     ->modify("+{$holdDays} days")->format('Y-m-d H:i:s');

    $pdo->prepare("
        INSERT INTO affiliate_commissions
        (affiliate_id, referral_id, user_id, subscription_id, stripe_invoice_id,
         base_amount_eur, percent, flat_amount_eur, amount_eur, status, available_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ")->execute([
        $aff['id'], $ref['id'], $userId, $subscriptionId, $invoice['id'],
        $base, $flat ? null : $percent, $flat, $amount, $availableAt
    ]);

    // Prvi plačani invoice → nastavi referral commission_until
    if (!$ref['first_paid_at']) {
        $window = (int)($aff['commission_window_months'] ?? affiliate_setting($pdo, 'default_commission_window_m'));
        $until  = (new DateTime('@' . ($invoice['paid_at'] ?? time())))
                    ->modify("+{$window} months")->format('Y-m-d H:i:s');
        $pdo->prepare("UPDATE affiliate_referrals SET first_paid_at = NOW(), commission_until = ?, status = 'converted' WHERE id = ?")
            ->execute([$until, $ref['id']]);
    }
}
```

### 6.4 Clawback (refund / chargeback)
- Nov webhook handler: `charge.refunded` in `charge.dispute.created`.
- Najdi povezano `affiliate_commissions.stripe_invoice_id`:
  - Če `status = pending` → `status = void`, `void_reason = 'refund'`.
  - Če `status = payable` (še ni izplačano) → enako: `void`.
  - Če `status = paid` → vstavi novo provizijo z **negativnim** zneskom in
    `status = clawback`, da se odšteje v naslednjem batchu.

### 6.5 Cron `affiliate_payable.php`
```php
$pdo->exec("
  UPDATE affiliate_commissions
  SET status = 'payable'
  WHERE status = 'pending' AND available_at <= NOW()
");
```
Frequenca: **dnevno ob 03:00**, dodano v Synology Task Scheduler.

### 6.6 Payout batch
```php
function create_payout_batch(PDO $pdo, int $superadminId): array {
    $pdo->beginTransaction();
    try {
        // Per affiliate: vsota payable provizij, če čez prag
        $rows = $pdo->query("
            SELECT a.id, a.iban, a.min_payout_eur,
                   COALESCE(SUM(c.amount_eur), 0) AS total
            FROM affiliates a
            JOIN affiliate_commissions c ON c.affiliate_id = a.id
            WHERE a.status = 'active' AND c.status = 'payable'
            GROUP BY a.id
            HAVING total >= COALESCE(a.min_payout_eur, (SELECT setting_value FROM affiliate_settings WHERE setting_key='default_min_payout_eur'))
        ")->fetchAll();

        $batch = [];
        foreach ($rows as $r) {
            if (empty($r['iban'])) continue; // brez IBANa preskoči
            $ref = sprintf('AFP-%s-%03d', date('Y-m'), count($batch)+1);
            $stmt = $pdo->prepare("
                INSERT INTO affiliate_payouts (affiliate_id, amount_eur, iban_snapshot, reference, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$r['id'], $r['total'], $r['iban'], $ref, $superadminId]);
            $payoutId = $pdo->lastInsertId();

            // Poveži provizije s payoutom in jih označi kot 'paid'
            $pdo->prepare("
                UPDATE affiliate_commissions
                SET payout_id = ?, status = 'paid'
                WHERE affiliate_id = ? AND status = 'payable'
            ")->execute([$payoutId, $r['id']]);

            $batch[] = ['payout_id' => $payoutId, 'affiliate_id' => $r['id'], 'amount' => $r['total']];
        }
        $pdo->commit();
        return $batch;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
```

---

## 7. Varnost in skladnost

- **Ločen session namespace**: `$_SESSION['affiliate_id']`, ne miksati z admin sejo.
  Cookie path `/affiliate/` (po možnosti tudi separate `session_name()`).
- **Rate limiting** na `/affiliate/login.php`, `/affiliate/register.php`, `forgot-password`
  (5 napačnih poskusov / 15 min na IP, kot pri obstoječi auth).
- **CSRF**: vsak `POST` v affiliate dashboardu zahteva token (kot v ostalih API-jih).
- **Click log GDPR**: shranjujemo **samo SHA-256 IP+salt** (kot v obstoječem GDPR
  helperju). Salt v `config.php`. Po **180 dneh** klike anonimiziramo (cron).
- **Ref povezava ne razkrije ime restavracije**: v affiliate dashboardu so
  imena maskirana.
- **Anti-cookie-stuffing**: če se isti `?ref` zazna z 30+ različnih IP-jev v < 1
  minuti → flag affiliata (status `suspended` zahteva ročno verifikacijo).
- **Audit log**: vsako approve / reject / suspend / payout akcijo logiramo v
  obstoječi audit mehanizem (`audit_log` tabela, če obstaja, drugače dodamo).

---

## 8. Vplivi na obstoječi code (kontrolne točke)

| Točka | Sprememba | Tveganje |
|-------|-----------|----------|
| `register.php` | Klic `attach_affiliate_on_signup()` po `lastInsertId()`. Dodatni `?ref=` parameter v URL preusmeritvi (preskočimo, samo cookie). | Nizko – tek v try/catch, neuspeh ne sme blokirati registracije. |
| `home/` (React) | V router/wrapper komponenti: ob mountu preberi `?ref=`, validacija (regex `[A-Z2-9]{8}`), POST na `/api/affiliate_track.php` ki nastavi cookie (server-side, da je SameSite enak). | Nizko – samo če `?ref` prisoten. |
| `api/stripe-webhook.php` | Hook v `handle_invoice_paid()` ter dodan `charge.refunded`. | Srednje – ne sme blokirati glavnega toka. Try/catch okoli affiliate koda + log. |
| `pages/superadmin.php` | Nov tab + JS modul. | Nizko. |
| `includes/lang.php` | Brez sprememb logike – samo nove jsonline. | Brez. |

---

## 9. Roadmap (faze)

### Faza 1 – MVP (2 sprinta)
- [ ] `migrate_affiliate.sql`
- [ ] Affiliate auth (register, login, verify, password reset)
- [ ] Public landing `/affiliate/index.php`
- [ ] Affiliate dashboard: stats, links, referrals, earnings (read-only)
- [ ] Tracking cookie + click log (asinhron)
- [ ] Atribucija v `register.php`
- [ ] Hook v Stripe webhooku → `affiliate_commissions`
- [ ] Cron `affiliate_payable.php`
- [ ] Superadmin: approve/reject/suspend, list affiliatov
- [ ] Email predloge (verify, approved, rejected)

### Faza 2 – Payouts (1 sprint)
- [ ] Superadmin payout batch UI
- [ ] SEPA CSV export
- [ ] PDF statement generator (FPDF / TCPDF brez Composer? **brez Composer pravila** → uporabimo
      `tFPDF` single-file knjižnico, vendar **commitamo direktno v `lib/`**, ker
      Composer ni dovoljen)
- [ ] `mark_paid` + email z PDF priponko
- [ ] Clawback handler za `charge.refunded`

### Faza 3 – Optimizacije (po potrebi)
- [ ] Multi-tier komisija (več kot 1 nivo affiliatov)
- [ ] Per-campaign tracking links (utm groupings)
- [ ] Public stats widget za affiliata
- [ ] 2FA za affiliate račun
- [ ] Stripe Connect integracija (avtomatska izplačila preko Stripe-a) – izven MVP

---

## 10. Odprta vprašanja za usklajevanje

1. **Pravna osnova izplačil:** ali bomo uporabljali pavšalni odstotek tudi za
   tujino (drugačno DDV zakonodajo)? Predlog: privzeto **samo SI** v MVP-ju,
   tujce dodamo po validaciji (vat_id check preko VIES-a).
2. **Marketing material:** kdo pripravi banner / FB šablone / besedila? Predlog:
   za MVP samo besedilo + logo + 2 social postova v slovenščini.
3. **Affiliate cookie & GDPR consent:** je piškotek `rez_aff` strogo nujen
   (analitika oz. funkcionalnost)? Pravno gledano je marketinški, zato **mora**
   biti v cookie banner-ju in zahtevati consent. Implementacija: če consent ni
   dan, cookie ne nastavimo, ampak takoj redirectamo na `/register.php?ref=XXX`
   in atribucija teče preko URL parametra (kratkoročno – v isti seji).
4. **Minimalni payout prag:** 30 € v MVP-ju zveni razumno. Drugače?
5. **VIP affiliati / tier:** ali že v MVP-ju, ali šele po 6 mesecih, ko vidimo,
   kdo dejansko dela?

---

## 11. Povezave z obstoječimi dokumenti

- `PRODUCT_BRIEF.md` – overall produkt
- `BILLING_TODO.md` – Stripe integracija (povzemamo isti pattern)
- `MAGIC_PATTERNS_PROMPT.md` – UI konvencije (uporabi za affiliate dashboard)

---

## 12. TL;DR

> Dodamo ločeno entiteto `affiliate` (ni user / admin / staff), z lastnim auth
> tokovodom, cookie-based atribucijo (60 dni, last-click), Stripe-driven
> izračunom provizij (20 % × 12 mesecev z 45-dnevnim hold-om), in batch izplačili
> preko SEPA CSV-ja, ki ga superadmin sproži ročno enkrat mesečno.
> 6 novih tabel, 1 nova migracija, ~25 novih PHP fajlov, 2 novi CSS/JS file,
> 2 hook-a v obstoječi `register.php` in `stripe-webhook.php`.
