# Anketa o zadovoljstvu – implementacijski načrt

> Funkcionalnost: po obisku gosta sistem samodejno pošlje zahvalo in/ali anketo.
> Paket: **Advanced** (prednastavljena anketa, urejanje, lastna anketa) | **Premium** (+ CSV izvoz)

---

## Pregled funkcionalnosti

1. Ko admin označi gosta kot prišlega, sistem čaka X ur (nastavljivo) in nato samodejno pošlje email
2. Email vsebuje zahvalo in/ali povezavo do ankete (glede na nastavitve)
3. Advanced dobi prednastavljeno anketo – jo lahko uredi ali naredi novo
4. Basic nima ankete
5. Gost izpolni anketo prek unikatne povezave (brez prijave), poda soglasje za objavo
6. Admin pregleda vse odgovore; Premium jih tudi izvozi v CSV

---

## Prednastavljena anketa

Ob ustvaritvi nove restavracije (Advanced/Premium) se samodejno ustvari anketa s temi vprašanji:

| # | Tip | Vprašanje | Obvezno |
|---|-----|-----------|:-------:|
| 1 | rating | Kako bi ocenili vaš celoten obisk? | ✓ |
| 2 | rating | Kako bi ocenili kakovost hrane in pijače? | |
| 3 | rating | Kako bi ocenili prijaznost osebja? | |
| 4 | radio | Ali bi nas priporočili prijateljem ali družini? *(Da / Verjetno da / Verjetno ne / Ne)* | |
| 5 | textarea | Kaj vam je bilo med obiskom najbolj všeč? | |
| 6 | textarea | Kaj bi radi izboljšali? | |

Admin jo lahko uredi ali ustvari svojo (Advanced+).

---

## Baza podatkov

### Tabela `survey_forms`
```sql
CREATE TABLE survey_forms (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  restaurant_id        INT NOT NULL,
  title                VARCHAR(255) NOT NULL DEFAULT 'Anketa o zadovoljstvu',
  description          TEXT,
  thank_you_message    TEXT,                     -- besedilo zahvalnega emaila
  send_enabled         TINYINT(1) DEFAULT 0,     -- samodejno pošiljanje vklopljeno
  send_delay_hours     INT NOT NULL DEFAULT 2,   -- čakanje po prihodu (h)
  include_thankyou     TINYINT(1) DEFAULT 1,     -- vključi zahvalo v email
  include_survey       TINYINT(1) DEFAULT 1,     -- vključi povezavo do ankete
  is_active            TINYINT(1) DEFAULT 1,
  created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
);
```

### Tabela `survey_questions`
```sql
CREATE TABLE survey_questions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  survey_id     INT NOT NULL,
  sort_order    INT NOT NULL DEFAULT 0,
  question_text TEXT NOT NULL,
  type          ENUM('checkbox','rating','radio','text','textarea') NOT NULL,
  is_required   TINYINT(1) DEFAULT 0,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (survey_id) REFERENCES survey_forms(id) ON DELETE CASCADE
);
```

### Tabela `survey_question_options`
Samo za tipe `checkbox` in `radio`.
```sql
CREATE TABLE survey_question_options (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  question_id INT NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  label       VARCHAR(255) NOT NULL,
  FOREIGN KEY (question_id) REFERENCES survey_questions(id) ON DELETE CASCADE
);
```

### Tabela `survey_responses`
En vnos = en gost, ena anketa.
```sql
CREATE TABLE survey_responses (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  survey_id       INT NOT NULL,
  reservation_id  INT NOT NULL,
  email           VARCHAR(255) NOT NULL,         -- vedno shrani
  token           VARCHAR(64) NOT NULL UNIQUE,   -- unikaten link
  scheduled_send_at DATETIME NOT NULL,           -- arrived_at + delay_hours
  email_sent_at   DATETIME NULL,                 -- NULL = čaka na pošiljanje
  consent         ENUM('public','anonymous','private') NULL,  -- NULL = ni še izpolnila
  submitted_at    DATETIME NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (survey_id) REFERENCES survey_forms(id),
  FOREIGN KEY (reservation_id) REFERENCES reservations(id)
);
```

### Tabela `survey_answers`
```sql
CREATE TABLE survey_answers (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  response_id INT NOT NULL,
  question_id INT NOT NULL,
  answer_text TEXT,     -- za 'text', 'textarea', 'rating' (vrednost 1–5)
  option_ids  TEXT,     -- JSON array option ID-jev za 'checkbox' in 'radio'
  FOREIGN KEY (response_id) REFERENCES survey_responses(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES survey_questions(id)
);
```

### Sprememba tabele `reservations`
Dodati polje (migracija):
```sql
ALTER TABLE reservations ADD COLUMN arrived_at DATETIME NULL;
```

---

## Migracija

Datoteka: `sql/migrate_surveys.sql`
- Ustvari 4 nove tabele zgoraj
- `ALTER TABLE reservations ADD COLUMN arrived_at DATETIME NULL`

---

## Faze implementacije

---

### FAZA 1 – Admin: gradnja ankete

**Cilj:** Admin vidi prednastavljeno anketo in jo lahko uredi.

#### 1.1 Prednastavljena anketa ob ustvaritvi restavracije
- V obstoječi logiki ustvarjanja restavracije pokliči `seed_default_survey($restaurant_id)`
- Funkcija v `includes/survey_helper.php` vstavi 6 prednastavjenih vprašanj (tabela zgoraj)
- Samo za Advanced/Premium račune

#### 1.2 Nova stran `pages/survey_builder.php`
- Dostopna samo za Advanced/Premium
- Admin izbere restavracijo (dropdown)
- Prikaže obstoječo anketo ali gumb "Ustvari anketo"
- Nastavitve (zgornj del):
  - Naslov ankete
  - Opis (opcionalno)
  - Besedilo zahvalnega emaila
  - Toggle: "Samodejno pošlji anketo po obisku"
  - Polje: "Pošlji po X urah po prihodu" (število, privzeto 2)
  - Toggle: "Vključi zahvalo v email"
  - Toggle: "Vključi povezavo do ankete"

#### 1.3 Gradnik vprašanj
- Tipi: **Zvezdičasta ocena (1–5)**, **Radio**, **Checkboxi**, **Kratko besedilno polje**, **Dolgo besedilno polje**
- Za vsako vprašanje: besedilo, ali je obvezno
- Za `radio` in `checkbox`: dodajanje možnosti dinamično v modalu
- Preurejanje z gumbi ↑↓ (ali drag-and-drop)
- Brisanje vprašanj

#### 1.4 API `api/survey.php`
- `GET get_form?restaurant_id=X` – anketa + vprašanja + možnosti
- `POST save_form` – ustvari/posodobi anketo in vsa vprašanja
- `DELETE delete_question` – briše posamezno vprašanje

---

### FAZA 2 – Sprožilec in zakasnelno pošiljanje

**Cilj:** Ko admin označi gosta kot prišlega, se ustvari zakasnjen zapis za pošiljanje emaila. Cron job pošlje email ob pravem času.

#### 2.1 Označitev prihoda v Admin UI
- V pogledu rezervacije dodaj gumb/checkbox "Gost je prišel"
- API akcija (obstoječ `api/reservations.php` ali nov): `POST mark_arrived`
  - Zapiše `arrived_at = NOW()` v `reservations`
  - Preveri, ali ima restavracija aktivno anketo z `send_enabled = 1`
  - Preveri, ali gost ima email
  - Ustvari vrstico v `survey_responses`:
    - `scheduled_send_at = NOW() + INTERVAL send_delay_hours HOUR`
    - `email_sent_at = NULL`
    - token = `bin2hex(random_bytes(32))`

#### 2.2 Cron job `cron/survey_send.php`
- Zaganjati vsakih 15–30 min (PHP cron ali Synology task scheduler)
- Poizvedba:
  ```sql
  SELECT sr.* FROM survey_responses sr
  WHERE sr.email_sent_at IS NULL
    AND sr.scheduled_send_at <= NOW()
  ```
- Za vsako vrstico: pokliči `send_survey_email($response)` iz `includes/survey_mailer.php`
- Zapiše `email_sent_at = NOW()`

#### 2.3 Funkcija `send_survey_email()` v `includes/survey_mailer.php`
- Zgradi email z zahvalo in/ali linkom glede na nastavitve ankete
- Link: `https://{domena}/survey.php?t={token}`
- Pošlji prek obstoječega mailerja

#### 2.4 Ročno pošiljanje (Admin UI)
- V pogledu rezervacije: gumb "Pošlji anketo zdaj" (vidno kadar je gost označen kot prišel + anketa obstaja)
- Ignorira zakasnitev, pošlje takoj
- Zapiše `email_sent_at`; prepreči ponavljanje (opozorilo z možnostjo vseeno poslati)

---

### FAZA 3 – Javna stran ankete

**Cilj:** Gost izpolni anketo prek unikatne povezave brez prijave.

#### 3.1 Nova stran `pages/survey.php`
- URL: `/survey.php?t={token}`
- Brez prijave
- Prikaže: ime restavracije, naslov/opis ankete, vsa vprašanja, sekcija soglasja
- Soglasje (obvezno, radio):
  - Strinjam se z objavo z imenom
  - Strinjam se z anonimno objavo
  - Ne strinjam se z objavo
- Gumb "Pošlji" + validacija obveznih polj

#### 3.2 Tipi vprašanj – render
| Tip | HTML |
|-----|------|
| `rating` | 5 SVG zvezd, klik/hover efekt, vrednost 1–5 |
| `radio` | `<input type="radio">` za vsako možnost |
| `checkbox` | `<input type="checkbox">` za vsako možnost |
| `text` | `<input type="text">` |
| `textarea` | `<textarea>` |

#### 3.3 Oddaja `api/survey.php` → `submit_response`
- Preveri token → najdi `survey_responses`
- Preveri `submitted_at IS NULL`
- Shrani `consent`, nastavi `submitted_at = NOW()`
- Shrani odgovore v `survey_answers`

#### 3.4 Robni primeri
- Token ne obstaja → "Neveljavna povezava"
- Že izpolnjena → "Anketa je bila že izpolnjena. Hvala!"
- Uspešno → zahvalno sporočilo

---

### FAZA 4 – Admin: pregled odgovorov

**Cilj:** Admin vidi vse odgovore; Premium jih izvozi.

#### 4.1 Nova stran `pages/survey_results.php`
- Dostopna za Advanced/Premium
- Filter: restavracija, datum oddaje (od–do), soglasje
- Tabela: datum, ime gosta, soglasje, status (izpolnjena / email poslan / čaka)
- Klik → modal s podrobnostmi

#### 4.2 Modal odgovora
- Za vsako vprašanje prikaže odgovor (zvezdice za rating, seznam za radio/checkbox, besedilo)
- Oznaka soglasja

#### 4.3 CSV izvoz (samo Premium) `api/survey.php` → `export_csv`
- Parametri: `restaurant_id`, `date_from`, `date_to`
- Glava: Datum, Email (skrij za anonimne), Soglasje, [vprašanje1], [vprašanje2], ...
- `header('Content-Type: text/csv')` + `Content-Disposition: attachment`

---

## Paket gating

| Funkcionalnost | Basic | Advanced | Premium |
|----------------|:-----:|:--------:|:-------:|
| Prednastavljena anketa | | ✓ | ✓ |
| Urejanje ankete / lastna anketa | | ✓ | ✓ |
| Samodejno pošiljanje emaila | | ✓ | ✓ |
| Pregled odgovorov | | ✓ | ✓ |
| CSV izvoz | | | ✓ |

Dodati v `includes/plans.php`:
- `survey` → Advanced+
- `survey_export` → Premium

---

## Navigacija (Admin UI)

- V admin meniju nov link "Anketa"
- Dve podstrani:
  - "Uredi anketo" → `survey_builder.php`
  - "Odgovori" → `survey_results.php`

---

## Varnost

- Token: 32 kriptografsko naključnih bytov (64 hex znakov)
- Javna stran: token je edini dostop, brez prijave
- Email nikoli prikazan na javni strani
- CSV: emaili skriti za anonimne odgovore
- XSS: `htmlspecialchars` na vse izpise

---

## Datotečna struktura

```
pages/
  survey_builder.php     ← admin: uredi anketo
  survey_results.php     ← admin: pregled odgovorov
  survey.php             ← javna stran za gosta

api/
  survey.php             ← vse API akcije

includes/
  survey_mailer.php      ← pošiljanje emaila
  survey_helper.php      ← seed prednastavjene ankete

cron/
  survey_send.php        ← zakasnelo pošiljanje (vsake 15–30 min)

sql/
  migrate_surveys.sql    ← 4 tabele + ALTER reservations
```

---

## Vrstni red implementacije

- [x] **F1** – `sql/migrate_surveys.sql` (4 tabele + arrived_at)
- [x] **F2** – `includes/survey_helper.php`: seed prednastavjene ankete
- [x] **F3** – `api/survey.php`: `save_form`, `get_form`
- [x] **F4** – `pages/survey_builder.php` (gradnja ankete)
- [x] **F5** – `api/reservations.php`: `mark_arrived` + ustvari `survey_responses` zapis
- [x] **F6** – `includes/survey_mailer.php` + `cron/survey_send.php`
- [x] **F7** – Ročno pošiljanje v Admin UI
- [x] **F8** – `pages/survey.php` (javna stran, render vprašanj)
- [x] **F9** – `api/survey.php`: `submit_response`
- [x] **F10** – `pages/survey_results.php` (pregled, modal)
- [x] **F11** – CSV izvoz (`export_csv`, samo Premium)
- [x] **F12** – Paket gating (plans.php) + navigacija + seed ob ustvaritvi restavracije
- [x] **F13** – Testiranje end-to-end
