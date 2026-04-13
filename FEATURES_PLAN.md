# Dodatne funkcionalnosti – implementacijski načrt

> 7 funkcionalnosti: GDPR, čakalna lista, samourejanje, kalendar, baza gostov, analitika vračajočih, real-time sync

---

## Paket gating

| Funkcionalnost                          | Basic | Advanced | Premium |
|-----------------------------------------|:-----:|:--------:|:-------:|
| GDPR (zakonska obveza)                  | ✓     | ✓        | ✓       |
| Dodaj v Google/Apple Calendar           |       | ✓        | ✓       |
| Sam uredi/odpove rezervacijo            |       | ✓        | ✓       |
| Čakalna lista                           |       | ✓        | ✓       |
| Baza gostov z zgodovino                 |       | ✓        | ✓       |
| Analitika: vračajoči gosti              |       | ✓        | ✓       |
| Real-time sync (SSE)                    |       | ✓        | ✓       |

---

---

# MODUL 1 – GDPR

> **Prioriteta: NAJVIŠJA – obvezno pred javno objavo**
> Zakonska podlaga: Uredba EU 2016/679 (GDPR), Zakon o varstvu osebnih podatkov (ZVOP-2)

---

## Kaj pokriva

1. Politika zasebnosti (PP) – javna stran
2. Pogoji uporabe (ToS) – javna stran
3. Soglasje ob registraciji admina
4. Soglasje gosta ob rezervaciji (self-booking)
5. Pravice posameznikov: dostop, popravek, izbris, prenosljivost
6. Sledenje soglasjem (audit log)
7. Upravljanje piškotkov (cookie consent)
8. Pogodba o obdelavi podatkov (DPA) med SaaS in restavracijo

---

## Baza podatkov

### Sprememba tabele `users` (admini)
```sql
ALTER TABLE users
  ADD COLUMN gdpr_consent_at     DATETIME NULL,
  ADD COLUMN gdpr_consent_ip     VARCHAR(45) NULL,
  ADD COLUMN marketing_consent   TINYINT(1) DEFAULT 0,
  ADD COLUMN marketing_consent_at DATETIME NULL,
  ADD COLUMN deleted_at          DATETIME NULL;  -- soft delete
```

### Sprememba tabele `reservations` (gostje self-booking)
```sql
ALTER TABLE reservations
  ADD COLUMN gdpr_consent        TINYINT(1) DEFAULT 0,
  ADD COLUMN gdpr_consent_at     DATETIME NULL,
  ADD COLUMN gdpr_consent_ip     VARCHAR(45) NULL,
  ADD COLUMN marketing_consent   TINYINT(1) DEFAULT 0;
```

### Nova tabela `gdpr_requests`
```sql
CREATE TABLE gdpr_requests (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  type         ENUM('access','rectification','erasure','portability') NOT NULL,
  requester_email VARCHAR(255) NOT NULL,
  restaurant_id   INT NULL,          -- na katero restavracijo se nanaša
  status       ENUM('pending','processing','completed','rejected') DEFAULT 'pending',
  notes        TEXT,
  requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  resolved_at  DATETIME NULL
);
```

---

## Implementacija

### 1.1 Pravni dokumenti
- Nova stran `pages/privacy.php` – Politika zasebnosti
- Nova stran `pages/terms.php` – Pogoji uporabe
- Oba dokumenta na posebni nezaščiteni URL (dostopna brez prijave)
- Vsebina: generičen template, customiziran za SaaS + restavracije
  - Kdo je upravljavec (SaaS), kdo je obdelovalec (restavracija)
  - Katere podatke zbiramo (ime, email, tel, IP, piškotki)
  - Namen obdelave, pravna podlaga (pogodba, zakoniti interes)
  - Rok hrambe (rezervacije: X let, gosti: X let po zadnjem obisku)
  - Pravice posameznika, kontakt DPO

### 1.2 Soglasje ob registraciji admina
- V registracijskem obrazcu: obvezni checkbox
  - "Strinjam se s [Pogoji uporabe] in [Politiko zasebnosti]" *(obvezno)*
  - "Strinjam se s prejemanjem novic in ponudb" *(neobvezno)*
- Shrani `gdpr_consent_at`, `gdpr_consent_ip`, `marketing_consent`
- Brez soglasja → registracija ni možna

### 1.3 Soglasje gosta (self-booking)
- Na zadnjem koraku rezervacije: obvezni checkbox
  - "Strinjam se z obdelavo osebnih podatkov za namen rezervacije" + link na PP
- Neobvezno: "Strinjam se s prejemanjem novic restavracije"
- Shrani v `reservations` (gdpr_consent, gdpr_consent_at, gdpr_consent_ip)

### 1.4 Cookie consent banner
- Prikaže se ob prvem obisku javnih strani (booking, survey, widget)
- Minimalni pristop: samo nujni piškotki (session)
  - Ni analitičnih/trženjskih piškotkov → consent banner je enostaven
- Shrani odločitev v `localStorage` (ne v cookie, da ni paradoksa)
- Komponenta: `js/cookie-consent.js` + `css/cookie-consent.css`

### 1.5 Pravice posameznika – Admin UI
- Nova stran `pages/gdpr.php` (superadmin)
  - Tabela zahtevkov iz `gdpr_requests`
  - Ročna obdelava + sprememba statusa

- Nova javna stran `pages/gdpr_request.php`
  - Gost ali admin vnese email in izbere tip zahtevka
  - Vpiše se v `gdpr_requests`
  - Potrditveni email na vneseni naslov

#### Pravica do izbrisa (Right to be Forgotten)
- Superadmin akcija `api/gdpr.php` → `erase_user`
  - `users`: nastavi `deleted_at`, anonimizira ime/email/tel
  - `reservations` (kot gost self-booking): anonimizira ime/email/tel, ohrani datum/uro/število gostov za statistiko
  - Briše: survey odgovore kjer `consent != public`

#### Pravica do prenosljivosti (Data Portability)
- Superadmin ali gost zahteva izvoz
- `api/gdpr.php` → `export_user_data` → JSON datoteka z vsemi podatki

### 1.6 Hramba podatkov – samodejno čiščenje
- `cron/gdpr_cleanup.php` (enkrat tedensko):
  - Mehko briše rezervacije starejše od 3 let (anonimizacija, ne brisanje)
  - Briše neizpolnjene ankete (samo survey_responses brez submitted_at) starejše od 6 mesecev

### 1.7 DPA (Pogodba o obdelavi) za restavracije
- Med registracijo prikaži skrajšano DPA
  - SaaS je obdelovalec, restavracija je upravljavec gostovih podatkov
  - Restavracija je odgovorna za obvestitev svojih gostov
- Checkbox + shrani `gdpr_consent_at` v `users`

---

---

# MODUL 2 – Čakalna lista

> Paket: Advanced+

---

## Baza podatkov

### Nova tabela `waitlist`
```sql
CREATE TABLE waitlist (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  restaurant_id   INT NOT NULL,
  date            DATE NOT NULL,
  time_preference VARCHAR(10) NULL,   -- npr. "19:00" ali NULL (kdorkoli)
  guests          INT NOT NULL,
  first_name      VARCHAR(100) NOT NULL,
  last_name       VARCHAR(100) NOT NULL,
  email           VARCHAR(255) NOT NULL,
  phone           VARCHAR(30) NULL,
  gdpr_consent    TINYINT(1) DEFAULT 0,
  notify_via      ENUM('email','sms','both') DEFAULT 'email',
  token           VARCHAR(64) NOT NULL UNIQUE,  -- za odjavo/potrditev
  status          ENUM('waiting','notified','confirmed','expired','removed') DEFAULT 'waiting',
  notified_at     DATETIME NULL,
  expires_at      DATETIME NULL,                -- čas do potrditve (npr. +2h)
  confirmed_at    DATETIME NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
);
```

---

## Implementacija

### 2.1 Vpis na čakalno listo (self-booking flow)
- Ko ni prostih terminov na izbrani datum, prikaži: "Za ta datum ni prostih mest. Vpišite se na čakalno listo."
- Obrazec: ime, priimek, email, tel, preferenca ure (opcija), GDPR soglasje
- Shrani v `waitlist`, pošlji potrditveni email s token linkom za odjavo

### 2.2 Sprožilec ob sprosti termina
- Kdaj se sproži: odpoved rezervacije, ureditev rezervacije (manj gostov), admin ročno
- `includes/waitlist_notifier.php` → `notify_waitlist($restaurant_id, $date)`
  1. Poišči čakajoče za ta datum (status = 'waiting'), urejeno po `created_at`
  2. Za prvega (ali vse, odvisno od nastavitve): pošlji email/SMS
  3. Nastavi `status = 'notified'`, `notified_at = NOW()`, `expires_at = NOW() + 2h`

### 2.3 Potrditev s strani gosta
- Link v emailu: `/waitlist.php?t={token}&action=confirm`
  - Preveri `expires_at` – če je potekel: "Ponudba je potekla"
  - Ustvari rezervacijo, nastavi `status = confirmed`
  - Pošlji potrditveni email rezervacije (standard)
- Link za odjavo: `/waitlist.php?t={token}&action=remove`

### 2.4 Samodejno ponastavitev po poteku
- `cron/waitlist_expire.php` (vsake 15 min):
  - Poišči notified vnose kjer `expires_at <= NOW()` in `status = 'notified'`
  - Nastavi `status = 'expired'`
  - Pokliči `notify_waitlist()` za naslednjega v vrsti

### 2.5 Admin UI
- V pogledu restavracije: tab "Čakalna lista"
- Tabela: datum, ime, email, tel, preferenca, status, čas vpisa
- Admin lahko ročno sproži obvestilo ali odstrani iz liste

---

---

# MODUL 3 – Samourejanje rezervacije (gost)

> Paket: Advanced+

---

## Baza podatkov

### Sprememba tabele `reservations`
```sql
ALTER TABLE reservations
  ADD COLUMN edit_token         VARCHAR(64) NULL UNIQUE,
  ADD COLUMN edit_token_expires DATETIME NULL,
  ADD COLUMN cancel_reason      VARCHAR(255) NULL;
```

---

## Implementacija

### 3.1 Token v potrditvenem emailu
- Ko se pošlje potrditveni email (obstoječ flow):
  - Ustvari `edit_token = bin2hex(random_bytes(32))`
  - `edit_token_expires = datum_rezervacije + 1h` (po rezervaciji ni več smisla urejati)
  - Vključi v email: "[Uredi rezervacijo] [Odpoved rezervacije]"

### 3.2 Javna stran `pages/reservation_edit.php`
- URL: `/reservation_edit.php?t={token}`
- Brez prijave
- Prikaže obstoječe podatke rezervacije
- Gost lahko spremeni:
  - Datum (glede na razpoložljivost)
  - Uro (glede na razpoložljivost)
  - Število gostov
  - Opomba
- Ne more spremeniti: restavracije, kontaktnih podatkov
- Omejitve (nastavljivo v admin nastavitvah):
  - Urejanje možno samo do X ur pred rezervacijo (npr. 24h)
  - Odpoved možna do X ur pred (npr. 4h)

### 3.3 Odpoved s strani gosta
- Gumb "Odpovem rezervacijo" → potrditveni dialog
- Opcionalno: razlog odpovedi (radio: "Sprememba načrtov / Bolezen / Drugo")
- Po odpovedi: shrani `cancel_reason`, nastavi status `cancelled`
- Obvesti restavracijo (email)
- Sproži preverjanje čakalne liste (`notify_waitlist`)

### 3.4 Omejitve (Admin nastavitve restavracije)
```sql
ALTER TABLE restaurants
  ADD COLUMN allow_guest_edit        TINYINT(1) DEFAULT 1,
  ADD COLUMN guest_edit_cutoff_hours INT DEFAULT 24,
  ADD COLUMN allow_guest_cancel      TINYINT(1) DEFAULT 1,
  ADD COLUMN guest_cancel_cutoff_hours INT DEFAULT 4;
```

---

---

# MODUL 4 – Dodaj v Google/Apple Calendar

> Paket: Advanced+
> Implementacija: lahka, brez zunanjih API-jev

---

## Implementacija

### 4.1 .ics generator `includes/calendar_export.php`
```php
function generate_ics($reservation, $restaurant): string
// Vrne string z .ics vsebino:
// BEGIN:VCALENDAR, VEVENT z DTSTART, DTEND, SUMMARY, DESCRIPTION, LOCATION
```

### 4.2 Endpoint `api/calendar.php?token={edit_token}`
- Vrne `.ics` datoteko z ustreznimi headeri:
  - `Content-Type: text/calendar`
  - `Content-Disposition: attachment; filename=rezervacija.ics`
- Apple Calendar, Outlook, ostali klienti: direkten download

### 4.3 Google Calendar link (URL-based, brez API)
Format:
```
https://calendar.google.com/calendar/render?action=TEMPLATE
  &text=Rezervacija+pri+{ime_restavracije}
  &dates={YYYYMMDDTHHMMSS}/{YYYYMMDDTHHMMSS}
  &details=Rezervacija+za+{N}+oseb
  &location={naslov_restavracije}
```

### 4.4 Vključitev v emaile
V potrditveni email (in reminder email) dodaj sekcijo:
```
📅 Dodaj v:
[Google Calendar]  [Apple/Outlook Calendar (.ics)]
```

---

---

# MODUL 5 – Baza gostov z zgodovino

> Paket: Advanced+

---

## Pristop

Gosti **nimajo svojih računov** – prepoznani so **po emailu** v okviru vsake restavracije.  
En email = en profil gosta, per restavracija.

---

## Baza podatkov

### Nova tabela `guests`
```sql
CREATE TABLE guests (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  restaurant_id INT NOT NULL,
  email         VARCHAR(255) NOT NULL,
  first_name    VARCHAR(100),
  last_name     VARCHAR(100),
  phone         VARCHAR(30),
  notes         TEXT,                    -- admin opombe
  tags          VARCHAR(500),            -- JSON array: ['VIP','alergija:gluten']
  first_visit   DATE NULL,
  last_visit    DATE NULL,
  total_visits  INT DEFAULT 0,
  total_covers  INT DEFAULT 0,           -- skupno število gostov
  no_shows      INT DEFAULT 0,
  is_blacklisted TINYINT(1) DEFAULT 0,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (restaurant_id, email),
  FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
);
```

---

## Implementacija

### 5.1 Ustvarjanje/posodobitev gostovega profila
- Funkcija `includes/guest_helper.php` → `upsert_guest($restaurant_id, $email, $data)`
- Pokliče se ob:
  - Admin doda rezervacijo z emailom gosta
  - Gost sam naredi rezervacijo (self-booking)
  - Gost izpolni anketo
- Posodobi: `first_name`, `last_name`, `phone`, `last_visit`, `total_visits`, `total_covers`

### 5.2 Nova stran `pages/guests.php`
- Dostopna za Advanced/Premium
- Tabela gostov: ime, email, tel, skupaj obiskov, zadnji obisk, oznake
- Iskanje po imenu/emailu/telefonu
- Klik na gosta → modal s profilom

### 5.3 Modal profila gosta
- Kontaktni podatki (urejanje inline)
- Taggi/oznake (dodajanje, brisanje)
- Admin opomba (textarea)
- Celotna zgodovina rezervacij: datum, ura, gostje, status
- Ankete: datum, povprečna ocena (če so izpolnjene)
- Statistika: skupaj obiskov, no-shows, povprečni razmik med obiski

### 5.4 Integracija z rezervacijskim pogledom
- Ko admin odpre rezervacijo z emailom: pokaži mini-profil gosta (sidebar/tooltip)
  - "3. obisk · Zadnjič: 12.3.2026 · ⭐ 4.2 povprečna ocena"
  - Admin opomba, če obstaja

### 5.5 Oznake (tags)
Prednastavjene oznake + možnost custom:
- VIP, Alergija, Posebne zahteve, Redna stranka, No-show, Vegetarijanec/vegan

---

---

# MODUL 6 – Analitika: Vračajoči gosti

> Paket: Advanced+
> Doda se obstoječi stats strani (`pages/stats.php`)

---

## Nove metrike

### 6.1 Delež vračajočih gostov
```sql
-- Novi gosti = email se pojavi prvič v danem obdobju
-- Vračajoči = email se je pojavil že prej
SELECT
  COUNT(DISTINCT CASE WHEN prev.email IS NULL THEN r.email END) AS new_guests,
  COUNT(DISTINCT CASE WHEN prev.email IS NOT NULL THEN r.email END) AS returning_guests
FROM reservations r
LEFT JOIN reservations prev
  ON prev.email = r.email
  AND prev.restaurant_id = r.restaurant_id
  AND prev.date < r.date
WHERE r.restaurant_id = ?
  AND r.date BETWEEN ? AND ?
  AND r.status NOT IN ('cancelled')
```

### 6.2 Povprečni čas med obiski (per gost)
```sql
SELECT AVG(days_between) FROM (
  SELECT DATEDIFF(r2.date, r1.date) AS days_between
  FROM reservations r1
  JOIN reservations r2
    ON r1.email = r2.email
    AND r1.restaurant_id = r2.restaurant_id
    AND r2.date > r1.date
  WHERE r1.restaurant_id = ?
    AND r2.status NOT IN ('cancelled')
) sub
```

### 6.3 Retention curve
- Koliko % gostov se vrne v 30 / 60 / 90 / 180 dneh po prvem obisku

### 6.4 UI
- Nov zavihek "Gostje" na stats strani (poleg obstoječih grafov)
- Donut chart: Novi vs. vračajoči (Chart.js, obstoječa knjižnica)
- Stat kartice: povprečni razmik med obiski, % ki se vrne v 90 dneh
- Brez novih zunanjih knjižnic

---

---

# MODUL 7 – Real-time sync (SSE)

> Paket: Advanced+
> Tehnologija: **Server-Sent Events (SSE)** – ne WebSocket

---

## Zakaj SSE in ne WebSocket

| | SSE | WebSocket |
|---|---|---|
| PHP podpora | Nativna | Zahteva Ratchet/Swoole |
| Synology kompatibilnost | ✓ | Problematično |
| Enosmerni (server→client) | ✓ dovolj | Overkill |
| HTTP/2 multiplexing | ✓ | Ne |
| Fallback (polling) | Enostavno | Kompleksno |

Za rezervacijski sistem je dovolj enosmerni push (server obvesti vse odprte seje).

---

## Implementacija

### 7.1 SSE endpoint `api/events.php`
```php
// Drži HTTP zvezo odprto, pošilja spremembe
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');   // za Nginx (Synology)

// Vsake 2s preveri tabelo `realtime_events` za novosti
// Pošlje event: data: {"type":"reservation_added","id":123,...}
```

### 7.2 Nova tabela `realtime_events`
```sql
CREATE TABLE realtime_events (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  restaurant_id INT NOT NULL,
  event_type    ENUM('reservation_added','reservation_updated','reservation_deleted','arrived') NOT NULL,
  payload       JSON NOT NULL,
  created_at    DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_restaurant_created (restaurant_id, created_at)
);
```

### 7.3 Sprožilec
- V obstoječi `api/reservations.php` (in `mark_arrived`) po vsaki spremembi:
  ```php
  insert_realtime_event($restaurant_id, 'reservation_updated', $payload);
  ```
- Cron ali trigger za čiščenje starih eventov (> 5 min) da tabela ne raste

### 7.4 Frontend `js/realtime.js`
```javascript
const source = new EventSource('/api/events.php?restaurant_id=X&last_id=Y');
source.onmessage = (e) => {
  const event = JSON.parse(e.data);
  // dispatch na obstoječe UI funkcije: addReservation(), updateReservation(), ...
};
// Fallback: če SSE ni podprt ali se prekine → polling vsake 10s
source.onerror = () => { startPollingFallback(); };
```

### 7.5 Čiščenje
- `cron/cleanup_events.php` ali inline ob vsakem SSE requestu:
  ```sql
  DELETE FROM realtime_events WHERE created_at < NOW() - INTERVAL 10 MINUTE
  ```

---

---

# Migracija baze – skupna datoteka

`sql/migrate_features.sql`

```sql
-- GDPR
ALTER TABLE users ADD COLUMN gdpr_consent_at DATETIME NULL, ...;
ALTER TABLE reservations ADD COLUMN gdpr_consent TINYINT(1) DEFAULT 0, ...;
CREATE TABLE gdpr_requests (...);

-- Čakalna lista
CREATE TABLE waitlist (...);

-- Samourejanje
ALTER TABLE reservations ADD COLUMN edit_token VARCHAR(64) NULL UNIQUE, ...;
ALTER TABLE restaurants ADD COLUMN allow_guest_edit TINYINT(1) DEFAULT 1, ...;

-- Gostje
CREATE TABLE guests (...);

-- Real-time
CREATE TABLE realtime_events (...);
```

---

# Vrstni red implementacije

> GDPR mora biti narejeno **pred vsem ostalim** (pred javno objavo).

### Prioriteta 1 – pred objavo
- [x] **G1** – GDPR: Privacy policy + Terms of service strani
- [x] **G2** – GDPR: Soglasje ob registraciji admina
- [x] **G3** – GDPR: Soglasje gosta (self-booking)
- [x] **G4** – GDPR: Cookie consent banner
- [x] **G5** – GDPR: GDPR request stran (javna) + superadmin pregled
- [x] **G6** – GDPR: Pravica do izbrisa + prenosljivost
- [x] **G7** – GDPR: DPA checkbox pri registraciji
- [x] **G8** – `sql/migrate_features.sql` – vse tabele in ALTER
- [] GDPR NE DELA V EMBEDU!

### Prioriteta 2 – kmalu po objavi
- [x] **C1** – Modul 4: Calendar links (.ics + Google URL) v emailih
- [x] **C2** – Modul 3: `edit_token` generacija v potrditvenem emailu
- [x] **C3** – Modul 3: `pages/reservation_edit.php` (uredi/odpovej)
- [x] **C4** – Modul 3: Admin nastavitve (cutoff ure, toggle)
- [x] **C5** – Modul 5: `includes/guest_helper.php` + upsert ob rezervaciji
- [x] **C6** – Modul 5: `pages/guests.php` (tabela + modal)
- [x] **C7** – Modul 5: Mini-profil v rezervacijskem pogledu

### Prioriteta 3
- [x] **W1** – Modul 2: Čakalna lista (tabela, vpis, notifier, cron)
- [x] **W2** – Modul 2: Admin UI za čakalno listo
- [ ] **A1** – Modul 6: Analitika vračajočih gostov (SQL + Chart.js)
- [ ] **R1** – Modul 7: SSE endpoint + `realtime_events` tabela
- [ ] **R2** – Modul 7: Frontend `js/realtime.js` + fallback polling