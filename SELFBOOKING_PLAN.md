# Faza 5 – Self-booking: Načrt implementacije

## Pregled

Gostom restavracije omogoči spletno rezervacijo prek unikatne javne povezave, brez prijave.

**URL:** `https://app.domena.com/book.php?t={token}`
**Feature flag:** `public_booking` (Advanced + Premium paket)

---

## Arhitektura

```
Landing/Google/Instagram
     ↓
book.php?t={token}          ← javna stran (brez prijave)
     ↓ fetch
api/book.php                ← javni API (brez auth)
     ↓
reservations tabela         ← status: pending / confirmed
     ↓ email
gost + admin
     ↓
pages/main.php (razpored)   ← pending = siva/pikčasta barva
     ↓ klik na pending
modal approve/reject        ← če več pending na istem terminu → vse skupaj
     ↓
api/reservations.php        ← action=approve / action=reject
     ↓ email
gost (potrjeno / zavrnjeno)
     ↓ dan pred
cron/reminders.php          ← opomnik gostu
```

---

## User flow (gost)

```
Korak 1 – Število gostov
  → Številski vnosnik (+ / - gumbi ali input)
  → Min: 1, brez zgornje omejitve
  → "Naprej →"

Korak 2 – Datum
  → Mesečni koledar
  → Sivi dnevi: zaprti (open_days bitmask) ali preteklost
  → Klik na odprt dan → naprej

Korak 3 – Termin
  → Fetch GET api/book.php?t=&date=
  → Grid gumbov z urami (npr. 18:00 / 18:30 / 19:00...)
  → Klik na termin → naprej

Korak 4 – Podatki gosta
  → Ime* | Email* | Telefon (neobvezno) | Opombe (neobvezno)
  → [← Nazaj]  [Pošlji rezervacijo]
  → POST api/book.php

Korak 5 – Potrditev
  [auto-confirm] → "✓ Rezervacija potrjena! Poslali smo vam e-mail."
  [manual]       → "⏳ Prošnja sprejeta. Ko jo potrdimo, vas obvestimo."
  → [Nova rezervacija] gumb (ponastavi na korak 1)
```

**Napredek:** vizualni progress bar/indikator korakov (1–4, korak 5 je end state).

---

## Admin flow (setup)

```
admin.php → uredi restavracijo
  → sekcija "Spletne rezervacije"
  → vklopi toggle
  → nastavi odpiralne dni (checkboxes Pon–Ned)
  → izberi: ☑ Samodejno potrdi / ☐ Ročno odobravanje
  → shrani → sistem samodejno generiraj booking_token
  → kopiraj link: book.php?t={token}
  → postavi na spletno stran / Google / Instagram
```

---

## Admin flow (pending rezervacije)

```
Admin odpre pages/main.php
  → pending rezervacije vidne v razporedu kot sivi/pikčasti bloki
  → če je na istem terminu več pending → prikaže "3 čakajočih" (grouped)
  → klik na pending blok → modal

[Modal – en pending]
  → Ime, email, tel, št. gostov, opombe, datum, čas
  → [✓ Potrdi]  [✗ Zavrni]

[Modal – več pending na istem terminu]
  → Seznam vseh pending za ta termin
  → Vsak ima [✓ Potrdi] [✗ Zavrni] gumba
  → Po akciji: blok v razporedu se posodobi, toast

Po potrditvi:
  → status = 'confirmed'
  → email gostu: "Rezervacija potrjena"
  → blok v razporedu postane normalna barva

Po zavrnitvi:
  → status = 'rejected'
  → email gostu: "Rezervacije ne moremo potrditi"
  → blok izgine iz razporeda
```

**Pending badge v headerju:** rdeč badge z številom vseh pending rezervacij za vse adminove restavracije.

---

## Prikaz v razporedu (main.php schedule)

| Status | Prikaz |
|--------|--------|
| `confirmed` (admin) | Normalna barva restavracije |
| `confirmed` (public) | Normalna barva restavracije |
| `pending` | Siva barva + pikčast rob + "⏳" ikona |
| `pending` (več na istem terminu) | Sivi blok + "⏳ 3 čakajočih" |
| `rejected` / `cancelled` | Ni prikazano |

---

## Baza podatkov

### Nova polja v `restaurants`

| Stolpec | Tip | Default | Opis |
|---------|-----|---------|------|
| `booking_token` | VARCHAR(64) UNIQUE | NULL | Unikatni token za javno URL |
| `booking_enabled` | TINYINT(1) | 0 | Ali so spletne rezervacije vklopljene |
| `booking_open_days` | TINYINT UNSIGNED | 127 | Bitmask odprtih dni (bit0=Pon…bit6=Ned), 127=vsi |
| `booking_auto_confirm` | TINYINT(1) | 1 | 1=samodejno potrdi, 0=ročno odobravanje |
| `booking_min_guests` | TINYINT UNSIGNED | 2 | Min. število gostov (najnižji prikazani gumb) |
| `booking_max_guests` | TINYINT UNSIGNED | 10 | Max. število gostov za gumbe; nad tem → "Več" + input |

**Bitmask open_days:**
```
bit 0 = Ponedeljek  (vrednost 1)
bit 1 = Torek       (vrednost 2)
bit 2 = Sreda       (vrednost 4)
bit 3 = Četrtek     (vrednost 8)
bit 4 = Petek       (vrednost 16)
bit 5 = Sobota      (vrednost 32)
bit 6 = Nedelja     (vrednost 64)
127 = vsi dnevi odprti
```

### Nova polja v `reservations`

| Stolpec | Tip | Default | Opis |
|---------|-----|---------|------|
| `status` | ENUM | 'confirmed' | confirmed / pending / rejected / cancelled |
| `source` | ENUM | 'admin' | admin (ročno vneseno) / public (spletni obrazec) |

> Obstoječe rezervacije in vse adminske rezervacije dobijo `status='confirmed'` in `source='admin'` (default vrednosti).

### Migracija
```
sql/migrate_self_booking.sql  ✅ ustvarjena
```

---

## Datoteke

### Nove datoteke

| Datoteka | Status | Opis |
|----------|--------|------|
| `sql/migrate_self_booking.sql` | ✅ done | DB migracija |
| `api/book.php` | ✅ done | Javni booking API |
| `book.php` | ✅ done | Javna 4-koračna booking stran |
| `cron/reminders.php` | ✅ done | 24h opomniki za goste |

### Modificirane datoteke

| Datoteka | Status | Opis |
|----------|--------|------|
| `includes/mailer.php` | ✅ done | +6 booking email funkcij |
| `api/restaurants.php` | ✅ done | booking_token ob ustvaritvi, booking nastavitve v PUT |
| `api/reservations.php` | ✅ done | +approve/reject action, status v GET odgovorih |
| `assets/js/admin.js` | ✅ done | Booking sekcija v restaurant modalu |
| `assets/js/schedule.js` + `modal.js` | ✅ done | Pending bloki v razporedu, pending modal |
| `pages/main.php` | ✅ done | Pending badge v headerju, status podatki za JS |

---

## Podroben opis komponent

### `api/book.php` ✅

```
GET  ?t={token}            → ime, open_days, auto_confirm, urnik, duration
GET  ?t={token}&date=...   → prosti termini za datum (array časov)
POST ?t={token}            → ustvari rezervacijo, vrne {auto_confirm: bool}
```

**Slot logika:** generiraj termine od `schedule_start` do `schedule_end` po `reservation_duration` minutah. Za danes filtriraj pretekle.

**Varnost:** booking_enabled + is_active + user_has_feature + dan odprt + validacija vhodov.

---

### `book.php` ⬜

**Design:** DM Sans, forest (#1B4332) / terracotta (#C4704B) / cream (#FAFAF5).
**Tailwind CDN** z razširjenimi barvami (enako kot landing page).

**Struktura strani:**
```
[Logo + ime restavracije]
[Progress bar: ① Gostje → ② Datum → ③ Termin → ④ Podatki]

[Step 1] Število gostov
  [2] [3] [4] [5] [6] [7] [8] [9] [10] [Več →]
  (gumbi od booking_min_guests do booking_max_guests, potem "Več")

  [ko klikne "Več"]:
  [2] [3] ... [10] [Več ✓]
  Vnesite število gostov: [____]  (min: max_guests+1)

  [Naprej →]  (aktiven ko je število izbrano)

[Step 2] Datum
  [← Maj 2026 →]
  [Pon Tor Sre Čet Pet Sob Ned]
  [ 1   2   3   4   5   6   7 ]
  ...
  (sivi = zaprti/preteklost, zeleni = dostopni, klik → step 3)

[Step 3] Termin (za [datum], [N] gostov)
  [18:00] [18:30] [19:00] [19:30] ...
  [← Nazaj]

[Step 4] Vaši podatki
  Ime in priimek *
  Email *
  Telefon
  Opombe
  [← Nazaj]  [Pošlji rezervacijo]

[Step 5] Potrditev
  ✓ ali ⏳ + sporočilo
  [Naredi novo rezervacijo]
```

---

### `api/restaurants.php` ⬜

**POST:** ob ustvaritvi doda `booking_token = bin2hex(random_bytes(32))`.

**PUT:** obdela: `booking_enabled`, `booking_open_days`, `booking_auto_confirm`, `booking_min_guests`, `booking_max_guests`.

---

### `api/reservations.php` ⬜

**GET:** vrne `status` in `source` polji za vsako rezervacijo (za pending prikaz v razporedu).

**PUT action=approve:**
- Preveri lastništvo restavracije
- `status = 'confirmed'`
- Email gostu: potrjeno

**PUT action=reject:**
- Preveri lastništvo restavracije
- `status = 'rejected'`
- Email gostu: zavrnjeno

---

### `assets/js/admin.js` ⬜

**Restaurant modal** — nova sekcija "Spletne rezervacije":
```
─── Spletne rezervacije ──────────────────────
☐ Omogoči spletne rezervacije

[ko vklopljeno prikaži]:
  Odprti dnevi:
  [☑ Pon] [☑ Tor] [☑ Sre] [☑ Čet] [☑ Pet] [☐ Sob] [☐ Ned]

  Število gostov:  Min [2]  Max [10]
  (gumbi od min do max, nato "Več" za večje skupine)

  ☑ Samodejno potrdi rezervacije
  (izklopljeno = rezervacije čakajo na ročno potrditev)

  [pri editiranju]:
  Rezervacijska povezava:
  [https://.../book.php?t=abc123...]  [Kopiraj]
```

**Restaurant tabela** — nov stolpec:
- 🔗 (klikabilno, kopira link) če `booking_enabled = 1`
- `—` če onemogočeno

---

### `assets/js/main.js` ⬜

**Pending bloki v razporedu:**
- Rezervacije s `status='pending'` dobijo CSS razred `res-pending`
- Stil: siva barva ozadja, pikčast rob, ⏳ ikona
- Če je na istem terminu/mizi več pending → prikaz kot en skupinski blok "⏳ N čakajočih"
- Klik na pending (enega ali skupino) → odpre pending modal

**Pending modal:**
```
[Za termin 19:00 – Sreda, 7. maj]

Ime: Janez Novak          Gostov: 4
Email: janez@gmail.com    Tel: 041 123 456
Opombe: alergija na gluten
[✓ Potrdi]  [✗ Zavrni]

──────────────────────────────
Ime: Ana Kovač             Gostov: 2
Email: ana@gmail.com       Tel: —
Opombe: —
[✓ Potrdi]  [✗ Zavrni]
```
- Po vsaki akciji: API klic, toast, refresh tega termina v razporedu
- Badge v headerju se zmanjša

---

### `pages/main.php` ⬜

**PHP:** query za pending count (za badge).

**JS APP_STATE:** doda `restaurants[].booking_token` in `restaurants[].booking_enabled`.

**Pending badge** v headerju (admin):
```html
<button onclick="openPendingModal()">
  ⏳ Čakajoče
  <span class="pending-badge">3</span>
</button>
```

---

### `cron/reminders.php` ⬜

```php
// Vse confirmed rezervacije za jutri z emailom
SELECT r.*, rest.name
FROM reservations r
JOIN restaurants rest ON r.restaurant_id = rest.id
WHERE r.reservation_date = CURDATE() + INTERVAL 1 DAY
  AND r.status = 'confirmed'
  AND r.email IS NOT NULL
// Pošlji send_booking_reminder_guest() za vsako
// Logira: X opomnikov poslanih
```

**Opomniki za vse rezervacije** (admin + public) — vsak gost z emailom dobi opomnik.

**Cron (Synology Task Scheduler):**
```
0 9 * * * php /volume1/web/rezervacije-saas/cron/reminders.php
```

---

## Email flow

| Dogodek | Prejemnik | Funkcija |
|---------|-----------|----------|
| Nova javna rez. (auto-confirm) | Gost | `send_booking_confirmed_guest()` |
| Nova javna rez. (manual) | Gost | `send_booking_pending_guest()` |
| Nova javna rez. (vedno) | Admin | `send_booking_notify_admin()` |
| Admin potrdi | Gost | `send_booking_confirmed_guest()` |
| Admin zavrne | Gost | `send_booking_rejected_guest()` |
| 24h pred rezervacijo | Gost | `send_booking_reminder_guest()` |

---

## Vrstni red implementacije

1. ✅ `sql/migrate_self_booking.sql`
2. ✅ `includes/mailer.php` — email funkcije
3. ✅ `api/book.php` — javni API
4. ✅ `book.php` — javna booking stran (5 korakov)
5. ✅ `api/restaurants.php` — booking_token + nastavitve
6. ✅ `api/reservations.php` — approve/reject + status v GET
7. ✅ `assets/js/admin.js` — booking sekcija v modalu
8. ✅ `assets/js/schedule.js` + `modal.js` — pending bloki + modal v razporedu
9. ✅ `pages/main.php` — pending badge + APP_STATE razširitev
10. ✅ `cron/reminders.php` — opomniki
