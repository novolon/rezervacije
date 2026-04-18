# Upravljanje miz (Table Management) — Faza 1

## Pregled

Sistem za rezervacije trenutno nima upravljanja zmogljivosti — vsi termini so vedno prikazani kot prosti. Ta feature doda cone, mize in merge grupe. Sistem samodejno dodeli mizo vsaki rezervaciji in preverja zasednost. Gostje ne izbirajo mize; to naredi sistem. Zaposleni pa jo lahko ročno prestavijo.

**Faza 2 (vizualizacija tlorisa) je izven obsega tega plana.**

---

## Baza podatkov — `sql/migrate_tables.sql`

### 1. Cone znotraj restavracije

```sql
CREATE TABLE IF NOT EXISTS restaurant_areas (
    id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT UNSIGNED     NOT NULL,
    name          VARCHAR(100)     NOT NULL,
    sort_order    SMALLINT         NOT NULL DEFAULT 0,
    is_active     TINYINT(1)       NOT NULL DEFAULT 1,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    INDEX idx_area_rest (restaurant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2. Mize

```sql
CREATE TABLE IF NOT EXISTS restaurant_tables (
    id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT UNSIGNED     NOT NULL,
    area_id       INT UNSIGNED     NULL DEFAULT NULL,
    name          VARCHAR(60)      NOT NULL,
    capacity      TINYINT UNSIGNED NOT NULL DEFAULT 2,
    sort_order    SMALLINT         NOT NULL DEFAULT 0,
    is_active     TINYINT(1)       NOT NULL DEFAULT 1,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    FOREIGN KEY (area_id)       REFERENCES restaurant_areas(id) ON DELETE SET NULL,
    INDEX idx_table_rest (restaurant_id),
    INDEX idx_table_area (area_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3. Merge grupe (header)

```sql
CREATE TABLE IF NOT EXISTS restaurant_table_merge_groups (
    id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT UNSIGNED     NOT NULL,
    name          VARCHAR(100)     NULL,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    INDEX idx_mg_rest (restaurant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4. Člani merge grup (M:N)

```sql
CREATE TABLE IF NOT EXISTS restaurant_table_merge_members (
    merge_group_id INT UNSIGNED NOT NULL,
    table_id       INT UNSIGNED NOT NULL,
    PRIMARY KEY (merge_group_id, table_id),
    FOREIGN KEY (merge_group_id) REFERENCES restaurant_table_merge_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (table_id)       REFERENCES restaurant_tables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5. Dodelitev miz rezervacijam

```sql
CREATE TABLE IF NOT EXISTS reservation_table_assignments (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT UNSIGNED NOT NULL,
    table_id       INT UNSIGNED NOT NULL,
    merge_group_id INT UNSIGNED NULL DEFAULT NULL,
    assigned_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by    INT UNSIGNED NULL DEFAULT NULL,
    UNIQUE KEY uq_res_table (reservation_id, table_id),
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
    FOREIGN KEY (table_id)       REFERENCES restaurant_tables(id) ON DELETE CASCADE,
    FOREIGN KEY (merge_group_id) REFERENCES restaurant_table_merge_groups(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_by)    REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_rta_reservation (reservation_id),
    INDEX idx_rta_table (table_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Subscription Gating

Dodati `'table_management'` v `includes/plans.php` za plana **advanced** in **premium**:

```php
'advanced' => [..., 'table_management'],
'premium'  => [..., 'table_management'],
'FEATURE_LABELS' => [..., 'table_management' => 'Upravljanje miz in zmogljivosti'],
```

Basic plan tega featurea nima.

---

## Nova datoteka: `includes/table_helper.php`

Vsebuje skupne funkcije za vse API-je:

| Funkcija | Namen |
|----------|-------|
| `restaurant_has_tables($pdo, $restId)` | Preveri, ali ima restavracija sploh mize (zgodnji izhod) |
| `find_available_table($pdo, $restId, $date, $time, $durationMins, $guestCount, $excludeResId)` | Vrne `array\|false` |
| `assign_tables_to_reservation($pdo, $reservationId, $assignment, $assignedBy)` | Shrani dodelitev |
| `get_table_assignments($pdo, $reservationId)` | Vrne dodeljene mize |
| `clear_table_assignments($pdo, $reservationId)` | Izbriše dodelitve (pred re-assign) |

### Algoritem `find_available_table`

```
VHOD: restId, date, time, durationMins, guestCount, excludeResId

1. Naloži vse aktivne mize restavracije (ORDER BY capacity ASC)
   → Če ni miz: vrni ['mode' => 'no_tables']  ← backward-compatible!

2. Izračunaj časovno okno:
   newStart = TIME_TO_SEC(time) / 60
   newEnd   = newStart + durationMins

3. Poišči zasedene table_id-je (overlap query):
   SELECT DISTINCT rta.table_id
   FROM reservation_table_assignments rta
   JOIN reservations r  ON rta.reservation_id = r.id
   JOIN restaurants res ON r.restaurant_id = res.id
   WHERE r.restaurant_id = :restId
     AND r.reservation_date = :date
     AND r.status IN ('confirmed', 'pending')
     AND r.id != :excludeResId          -- NULL varno: "!= NULL" je vedno false
     AND TIME_TO_SEC(r.reservation_time)/60 < :newEnd
     AND TIME_TO_SEC(r.reservation_time)/60
         + COALESCE(r.duration, res.reservation_duration) > :newStart

4. freeTables = vse mize WHERE id NOT IN (zasedeni)

5. Enotna miza:
   candidates = freeTables WHERE capacity >= guestCount
   IF candidates: vrni ['mode' => 'single', 'table_id' => candidates[0].id]

6. Merge grupe (sortirane po skupni zmogljivosti ASC):
   FOR EACH group:
     IF vse_članice_so_proste AND SUM(capacity) >= guestCount:
       vrni ['mode' => 'merge', 'table_ids' => [...], 'merge_group_id' => group.id]

7. vrni false  ← ni prostega mesta
```

**Ključno:** Vedno `COALESCE(r.duration, res.reservation_duration)` — trajanje je lahko NULL.

---

## Nova datoteka: `api/tables.php`

Admin CRUD za cone, mize in merge grupe. Vse zahteva `require_feature('table_management')`.

| Metoda | Parametri | Akcija |
|--------|-----------|--------|
| GET | `?restaurant_id=X` | Vrni `{ areas, tables, merge_groups }` |
| POST | `action=create_area` | Ustvari cono |
| POST | `action=create_table` | Ustvari mizo |
| POST | `action=create_merge_group` | Ustvari merge grupo (z `member_table_ids[]`) |
| PUT | `?area_id=X` | Posodobi cono |
| PUT | `?table_id=X` | Posodobi mizo (ime, zmogljivost, cona, aktiven) |
| PUT | `?merge_group_id=X` | Posodobi merge grupo |
| DELETE | `?area_id=X` | Briši cono (mize dobijo `area_id = NULL`) |
| DELETE | `?table_id=X` | Briši mizo — **blokira, če ima prihodnje rezervacije** |
| DELETE | `?merge_group_id=X` | Briši merge grupo |

DELETE mize vrne **409 Conflict**, če obstajajo prihodnje `reservation_table_assignments` za to mizo. Admin mora rezervacije najprej ročno prestaviti.

---

## Nova datoteka: `api/table_assignment.php`

Ročna prestavitev mize s strani osebja.

```
PUT
Body: { reservation_id: X, table_ids: [1,2], merge_group_id: null|N }
```

Logika:
1. Preveri lastništvo restavracije in feature `table_management`
2. Pokliči `find_available_table(..., excludeResId=$reservationId)` — validacija
3. Če ni prosto: vrni 409
4. `clear_table_assignments()` → `assign_tables_to_reservation(..., assigned_by=$userId)`

---

## Spremembe obstoječih datotek

### `api/book.php`

**GET ?date= — filtriranje terminov:**
```php
require_once __DIR__ . '/../includes/table_helper.php';

if (user_has_feature($pdo, $rest['owner_id'], 'table_management')) {
    $guestCount = max(1, (int)($_GET['guest_count'] ?? $rest['booking_min_guests']));
    $duration   = (int)$rest['reservation_duration'];
    $slots = array_values(array_filter($slots, function($slot) use ($pdo, $rest, $date, $guestCount, $duration) {
        $r = find_available_table($pdo, (int)$rest['id'], $date, $slot, $duration, $guestCount, null);
        return $r !== false;
    }));
}
```

**POST — ustvari rezervacijo (transakcija):**
```php
$pdo->beginTransaction();
// INSERT INTO reservations → $newId
$assignment = find_available_table($pdo, $restId, $date, $time, $duration, $guestCount, null);
if ($assignment === false) {
    $pdo->rollBack();
    // → vrni error 'no_availability' ali preusmeri na waitlist
}
assign_tables_to_reservation($pdo, $newId, $assignment, null);
$pdo->commit();
```

### `api/reservations.php`

- **POST (admin ustvari):** Enaka transakcija, a brez hard-fail — rezervacija se shrani tudi brez mize, odgovor vsebuje `"table_warning": "Ni proste mize."`.
- **PUT (uredi):** Ko se spremenijo `reservation_date / reservation_time / guest_count / duration`:
  `clear_table_assignments()` → `find_available_table()` → nova dodelitev.
- **GET (posamezna rezervacija):** Dodaj `table_assignments` array v odgovor.

### `includes/plans.php`

Dodaj `'table_management'` v feature liste za **advanced** in **premium**.

### `pages/restaurant-edit.php`

Novi zavihek **"Mize"**:
1. **Cone** — seznam + add/edit/delete form
2. **Mize** — seznam + form (ime, zmogljivost, dropdown za cono)
3. **Združene mize** — seznam merge grup + multi-select za člane

PHP gating na vrhu: `$hasTableMgmt = user_has_feature($pdo, $_SESSION['user_id'], 'table_management');`
Če false → prikaži "upgrade" poziv namesto zavihka.

### `assets/js/modal.js`

V `buildViewContent()` dodaj prikaz dodeljenih miz:
```js
if (Array.isArray(r.table_assignments) && r.table_assignments.length) {
    fields.push({ label: 'Miza', value: r.table_assignments.map(a => a.table_name).join(' + ') });
}
```
Dodaj gumb **"Prestavi mizo"** za prihodnje rezervacije (za admin/user vlogi).

### `assets/js/schedule.js`

Na karticah rezervacij prikaži majhen badge z imenom mize (npr. `M3` ali `M3+M4`).

### `pages/main.php`

```php
'hasTableMgmt' => user_has_feature($pdo, (int)$_SESSION['user_id'], 'table_management'),
```

---

## Robni primeri

| Primer | Rešitev |
|--------|---------|
| Restavracija brez miz | `find_available_table` vrne `no_tables` → staro obnašanje |
| Race condition (hkratni rezervaciji) | Transakcija + mize se lockirajo s `SELECT ... FOR UPDATE` |
| `r.duration = NULL` | Vedno `COALESCE(r.duration, res.reservation_duration)` |
| Merge skupina delno zasedena | Skupina preskočena (VSE članice morajo biti proste) |
| Admin ustvari brez proste mize | Rezervacija shranjena, odgovor vsebuje `table_warning` |
| Brisanje mize z rezervacijami | 409 Conflict — admin mora najprej prestaviti |
| `rejected` / `cancelled` status | Ne blokirata miz (filter samo `confirmed` + `pending`) |

---

## Zaporedje implementacije

1. Zaženi `sql/migrate_tables.sql`
2. Posodobi `includes/plans.php` (feature gating)
3. Ustvari `includes/table_helper.php` (algoritem)
4. Ustvari `api/tables.php` (admin CRUD)
5. Modificiraj `api/book.php` (slot filter + transakcija)
6. Modificiraj `api/reservations.php` (auto-dodelitev + GET info)
7. Ustvari `api/table_assignment.php` (ročna prestavitev)
8. Zavihek "Mize" v `pages/restaurant-edit.php`
9. Posodobi `assets/js/modal.js` (prikaz + prestavitev)
10. Posodobi `pages/main.php` (APP_STATE)
11. Badge v `assets/js/schedule.js`

---

## Verifikacija

1. **Brez miz:** ustvari rezervacijo → deluje kot prej, brez blokade
2. **Z mizami:** ustvari rezervacijo → preveri vrstico v `reservation_table_assignments`
3. **Zasedene mize:** zapolni vse mize za termin → naslednja javna rezervacija dobi napako
4. **Merge:** zapolni vse enotne mize, pusti merge grupo prosto → sistem jo samodejno dodeli
5. **Staff prestavi:** PUT na `api/table_assignment.php` → `assigned_by` ni NULL
6. **Basic plan:** `api/tables.php` vrne 403 Forbidden


---

## Faza 2 — Nadgradnje

### 1. Čakalna lista: podrobnosti rezervacije

**Datoteka:** `pages/waitlist.php`

Vsaka vrstica v tabeli čakalne liste postane klikljiva. Klik odpre modal s polnimi podrobnostmi vnosa:
ime, email, telefon, datum, čas, gostje, stanje, kdaj vpisan, kdaj obveščen, kdaj potrjen.

Obstoječe akcije (Obvesti / Odstrani) ostanejo, modal jih vsebuje poleg podatkov.

---

### 2. Blokirani datumi → brez čakalnega seznama

**Datoteke:** `includes/functions.php`, `api/waitlist.php`

`is_blackout()` se premakne iz `api/book.php` v `includes/functions.php`, ker jo sedaj potrebujeta dve datoteki.

V `api/waitlist.php` POST (javni vpis na čakalno listo) se doda preverba takoj po nalaganju restavracije:
```php
if (is_blackout($pdo, (int)$rest['id'], $date)) {
    json_response(false, null, 'Za ta datum rezervacije niso na voljo.', 400);
}
```

---

### 3. Admin UI: cone-first

**Datoteka:** `pages/restaurant-edit.php`

Mize se v admin panelu prikažejo grupirane po conah. Vsaka cona ima lasten razdelek z gumbom **"+ Miza"** znotraj nje. Mize brez cone so v razdelku "Brez cone".

Enako za združene mize: forma za novo skupino se prikaže šele po izbiri vsaj ene cone ali pa je privzeto pripeta na obstoječe mize.

Sprememba je le vizualna/UX — podatkovni model ostane nespremenjen.

---

### 4. "Vse mize so združljive"

**Datoteke:** `sql/migrate_tables.sql`, `restaurants` tabela, `includes/table_helper.php`, `pages/restaurant-edit.php` (splošne nastavitve ali zavihek Mize)

Nova nastavitev restavracije: `all_tables_mergeable TINYINT(1) NOT NULL DEFAULT 0`.

Ko je vklopljena, algoritem `find_available_table` po koraku 5 (enotna miza) in koraku 6 (definirane merge grupe) doda korak 6b:
```
6b. all_tables_mergeable preverba:
    freeTables sortiramo DESC po kapaciteti
    greedily kopičimo, dokler sum(capacity) >= guestCount
    → vrni ['mode' => 'merge', 'table_ids' => [...], 'merge_group_id' => null]
    Če ni dovolj prostih miz → vrni false
```

Admin toggle: checkbox v razdelku "Združene mize" v zavihku Mize.

---

### 5. Izbira cone v booking flowu

**Datoteke:** `api/book.php`, `book.php` (javna stran), `widget.js`

Nova nastavitev restavracije: `allow_area_choice TINYINT(1) NOT NULL DEFAULT 0`.

#### Backend (`api/book.php` GET)

Ko je `allow_area_choice` aktiven, GET ?date=&time=&guests= vrne dodatno polje `areas`:
```json
"areas": [
  { "id": 1, "name": "Terasa", "available": true },
  { "id": 2, "name": "Notranjost", "available": false }
]
```
Cone brez razpoložljive mize so `available: false`. Dodana je možnost `{ "id": null, "name": "Vseeno mi je" }` vedno na vrhu.

#### Backend (`api/book.php` POST)

Sprejme opcionalno `area_id`. Če je podana, `find_available_table` išče samo med mizami v tej coni.

#### `find_available_table` razširitev

Nov opcijski parameter `?int $areaId = null`. Če je podan, poizvedba za mize doda `AND rt.area_id = :areaId`.

#### Frontend (`book.php` + `widget.js`)

Nov korak **3b** med izbiro termina (korak 3) in obrazcem (korak 4):
- Naslov: "Izberite prostor"
- Seznam gumbov / kartic za vsako cono
- Prva možnost: "Vseeno mi je" (vedno aktivna)
- Disabled cone: vizualno zatemnjene z razlogom "ni prostih miz"
- Če admin `allow_area_choice = 0` → korak 3b se preskoči

#### Admin toggle

Checkbox v splošnih nastavitvah restavracije (`pages/restaurant-edit.php`, razdelek Rezervacije).

---

### 6. Vizualizacija miz (Faza 3 — plan po zaključku Faze 2)

Po zaključku vseh točk Faze 2 se naredi ločen plan za interaktivni tloris restavracije.

---

## Zaporedje implementacije Faze 2

1. `is_blackout` → premakni v `includes/functions.php`
2. Čakalna lista: podrobnosti (modal klik)
3. Blokirani datumi: preverba v `api/waitlist.php`
4. Admin UI: cone-first prikaz
5. `all_tables_mergeable`: migracija + algoritem + toggle
6. `allow_area_choice`: migracija + API + book.php + widget.js