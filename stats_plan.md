# Plan: Statistika rezervacij

## Cilj
Stran `/pages/stats.php` dostopna adminu in userju, ki prikazuje
koristne metrike za restavracijo — vključno z identifikacijo stalnih gostov.

---

## Faze

### Faza 1 – API (`api/stats.php`)
Enoten endpoint z `?section=X&restaurant_id=Y&from=&to=` parametri.

Sekcije:
- `overview`      — KPI kartice
- `by_day`        — rezervacije po dnevu v tednu
- `by_hour`       — rezervacije po uri
- `by_month`      — trend po mesecih (zadnjih 12)
- `sources`       — vir: staff vs public booking
- `guests_dist`   — porazdelitev velikosti skupin
- `returning`     — statistika stalnih gostov
- `top_customers` — seznam najpogostejših gostov

Dostop: admin vidi samo svoje restavracije, user samo svojo.

---

### Faza 2 – Baza (brez migracije)
Vse temelji na obstoječi tabeli `reservations`. Polje `email` je ključ
za identifikacijo gostov. Ni treba dodajati novih tabel.

Returning customer = email z 2+ potrjenimi rezervacijami.

---

### Faza 3 – Stran (`pages/stats.php`)

#### Glava
- Filter: restavracija (dropdown, samo admin z več rest.)
- Filter: obdobje — Zadnjih 30 dni / 3 mesece / 6 mesecev / 1 leto / Po meri (date picker)
- Gumb "Izvozi CSV"

#### Sekcija 1: KPI kartice (vrstica 4 kart)
| Kartica | Vrednost |
|---|---|
| Skupaj rezervacij | N (v obdobju) |
| Skupaj gostov | N |
| Povp. gostov / rezervacija | X,X |
| Stopnja prihoda | % (confirmed / total) |

#### Sekcija 2: Trend po mesecih
- Stolpčni grafikon (zadnjih 12 mesecev)
- X os: mesec, Y os: število rezervacij
- Tooltip: rezervacije + gostje
- Knjižnica: Chart.js (CDN)

#### Sekcija 3: Vzorci
Dve manjši kartici vzporedno:

**Rezervacije po dnevu v tednu**
- Horizontalni bar chart (Pon–Ned)
- Barva: intenzivnost glede na vrednost

**Rezervacije po uri**
- Horizontalni bar chart (8:00–23:00)
- Pokaže peak hours

#### Sekcija 4: Vir rezervacij
- Pie/doughnut chart: Staff dodane vs Public booking
- + tabela: Potrjene / Zavrnjene / V čakanju (%)

#### Sekcija 5: Stalni gostje ⭐
- KPI: Edinstveni gostje (z emailom) | Vrnjeni gostje | % vračanja
- Tabela "Top 20 gostov":

| # | Ime | Email | Obiski | Skupaj gostov | 1. obisk | Zadnji obisk |
|---|---|---|---|---|---|---|
| 1 | Janez N. | j@... | 8 | 24 | 12. jan 24 | 3. apr 26 |

- Filtriranje tabele: vsi / samo vrnjeni
- Badge "Zvesti gost" za 5+ obiskov

#### Sekcija 6: Porazdelitev skupin
- Bar chart: 1 oseba / 2 osebi / 3 / 4 / 5+ (%)
- Pokaže katere velikosti skupin so najpogostejše

---

### Faza 4 – CSV izvoz
`GET /api/stats.php?section=export&...` vrne CSV z vsemi rezervacijami
v izbranem obdobju: datum, čas, ime, email, telefon, gostje, status, vir.

---

### Faza 5 – Navigacija
- Dodaj "Statistika" gumb v header main.php in admin.php
- Dostop: admin + user (omejen na lastno restavracijo)

---

## Tehnični detajli

### Returning customer query (primer)
```sql
SELECT
    email,
    MAX(guest_name)        AS guest_name,
    COUNT(*)               AS visits,
    SUM(guest_count)       AS total_guests,
    MIN(reservation_date)  AS first_visit,
    MAX(reservation_date)  AS last_visit
FROM reservations
WHERE restaurant_id = ?
  AND status = 'confirmed'
  AND email IS NOT NULL AND email != ''
GROUP BY email
HAVING COUNT(*) >= 1
ORDER BY visits DESC
LIMIT 50
```

### Chart.js
Enkrat naložen iz CDN, brez lokalnih datotek.
Vsi grafi so responsive (maintainAspectRatio: false).

---

## Prioriteta nalog

- [ ] Faza 1: `api/stats.php` — vsi endpointi
- [ ] Faza 3: `pages/stats.php` — HTML + CSS
- [ ] Faza 3: KPI kartice (JS fetch + render)
- [ ] Faza 3: Trend grafikon (Chart.js)
- [ ] Faza 3: Dnevi/ure grafi
- [ ] Faza 5: Stalni gostje tabela
- [ ] Faza 4: CSV izvoz
- [ ] Faza 5: Navigacija (header gumbi)
