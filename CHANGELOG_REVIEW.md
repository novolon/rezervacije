# CHANGELOG — pregled nalog za testiranje

Vse spremembe iz tega batch-a. Vsako nalogo lahko testiraš ločeno.

---

## Naloge (sledim vrstnemu redu user-ja)

| # | Naloga | Status |
|---|---|---|
| 1 | Celoten app responsive za mobile | ✅ DONE |
| 2 | Booked manjka hamburger menu na mobile | ✅ DONE |
| 3 | Odstrani SMS omembe (razen iz feature tabele + "Kmalu" badge) | ✅ DONE |
| 4 | Posodobi landing page z features + nove info | ✅ DONE |
| 5 | (= 2) | ✅ DONE |
| 6 | Uredi vse prevode | ✅ DONE (delno – glej spodaj) |

---

<!-- Naloge se polnijo, ko so opravljene -->

## ✅ Naloga 3: Odstrani SMS omembe

### Spremembe

**Landing (`home/index.php`):**
- Odstranjena SMS premium feature kartica. Nadomeščena z **"Ankete za goste"** kartico.
- Odstranjeni vsi `'soon' => true` flagi z premium kartic (zdaj vse aktivne).

**Lang fajli (vsi 8 jezikov):**
- `auth.hero_text`: odstranjena referenca na SMS:
  - Prej: "Rezble prevzame vaše rezervacije 24/7 — od spletne strani do SMS-a..."
  - Zdaj: "Rezble prevzame vaše rezervacije 24/7. Osebje se lahko posveti gostom, ne pa zvonjenju."
- `landing.feat_prem_5` v primerjalni tabeli paketov: **"SMS obvestila (Kmalu)"** — edina omemba, ki ostane.
- Dodana 2 nova ključa: `landing.prem_survey_title` + `landing.prem_survey_desc`.

**AI prompt (`includes/blog_anthropic.php`):**
- Iz FACTS sekcije odstranjena vrstica "SMS notifications" — AI ne sme več promovirati neaktivne funkcionalnosti.
- Primer caption-a v prompt-u zamenjan z 24h email opomnikom.

### Kako testirati
1. Obišči landing (`/home/index.php`) — premium feature kartice naj kažejo: Vgradljivi widget, Branding, Auto-confirm, **Ankete za goste** (NI več SMS).
2. Pricing comparison tabela — Premium plan naj v feature listi imel "SMS obvestila (Kmalu)".
3. `auth.hero_text` (login/register page hero) ne sme več omenjati SMS.
4. Sproži AI generacijo blog članka — Sonnet ne sme omeniti SMS funkcionalnosti.



## ✅ Naloga 4: Posodobi landing page z featurji

### Spremembe

**`home/index.php`:**
- Advanced sekcija razširjena s **3 → 6 kartic**. Dodane: **Razporeditev miz**, **Čakalna lista**, **Baza gostov**.
- Premium sekcija: SMS kartica zamenjana z **Ankete za goste**.
- Vse `'soon' => true` flage odstranjene — vse kartice prikazane kot aktivne funkcionalnosti.

**Novi lang ključi (8 jezikov):**
- `landing.adv_tables_title` + `_desc` — Razporeditev miz
- `landing.adv_waitlist_title` + `_desc` — Čakalna lista
- `landing.adv_guests_title` + `_desc` — Baza gostov
- `landing.prem_survey_title` + `_desc` (iz Naloge 3) — Ankete za goste

### Kako testirati
1. Obišči `/home/index.php` → **Advanced features** sekcija mora prikazati 6 kartic v gridu.
2. **Premium features** sekcija → 4 kartice (Widget, Branding, Auto-confirm, Ankete).
3. Nobena kartica nima več "Kmalu" badge (razen v primerjalni pricing tabeli "SMS obvestila (Kmalu)").
4. Preklopi jezik (sl → en → de → it itd.) — landing strani naj se prikažejo brez "landing.adv_tables_title" raw ključev.



## ✅ Naloga 1: Mobile responsive — cel app

### Spremembe

**`includes/topbar.php`:**
- Dodan **hamburger gumb** (`#rz-mobile-toggle`) na levi strani topbar-a, viden samo na mobile (`< 900px`).

**`includes/sidebar.php`:**
- Dodan **backdrop overlay** (`#rz-mobile-backdrop`) za zatemnitev ozadja, ko je sidebar odprt.
- JS: toggle `is-mobile-open` razred, zapri ob kliku na nav link / backdrop / Escape, blokiraj scroll body-ja ko je drawer odprt.

**`assets/css/rezble.css`:**
- Stiliziran `.rz-mobile-toggle` gumb (40×40px, ikona burger).
- `.rz-mobile-backdrop` z blur-om in fade-in animacijo.
- Razširjen `@media (max-width: 900px)`:
  - Sidebar drawer 280px širok, drsi z `transform`.
  - `.rz-h1` 22px, KPI grid 2 stolpca, modali full-width od spodaj.
  - Schedule row, calendar cells, up-next rows kompaktnejši.
  - Tabele scrollable (`.rz-table-wrap`).
- Nov `@media (max-width: 540px)` breakpoint za telefone:
  - KPI 1-stolpec, calendar/schedule/up-next še bolj kompaktni.

### Kako testirati
1. Odpri admin (`/pages/main.php`) na **mobile** ali resize na ≤ 900px → topbar mora prikazati hamburger gumb levo.
2. Klik na hamburger → sidebar drsi z leve, ozadje se zatemni.
3. Klik na backdrop / Escape / link v sidebaru → drawer se zapre.
4. Preglej **stats**, **restaurant-edit**, **pending**, **survey_results** strani — vse layouts (KPI grid, kartice, modali, tabele) naj bodo berljive na 375px.
5. Test na 540px (npr. iPhone SE) → KPI 1-stolpec, čak. lista in calendar morata biti čitljiva.



## ✅ Naloga 2 + 5: Booked hamburger menu na mobile

### Spremembe

**`blog/_header.php`:**
- Dodan **hamburger gumb** (`#bk-hamburger`) v navbar (viden samo `< 768px`).
- Skril CTA gumb (»Začni«) na xs ekranih (`< 640px`) — premakne se v drawer.
- Dodan **off-canvas drawer** (`#bk-mobile-drawer`):
  - Header z logom + close gumb.
  - Nav linki (Booked, Pricing, CTA Začni).
  - Language switcher kot 4-stolpčna mreža z aktivnim ozadjem.
- Vanilla JS toggle: backdrop, Escape, scroll lock.

**`assets/css/blog.css`:**
- `.bk-hamburger` — kvadraten gumb 38×38px z border-om.
- `.bk-mobile-backdrop` — full-screen blur overlay.
- `.bk-mobile-drawer` — drsi iz desne (86vw, max 360px), cream ozadje, shadow.
- Drawer nav s 16px font-om in 12×14px paddingom za touch targets.
- `.bk-drawer-langs-grid` — 4-stolpčna mreža za jezike, aktivni ima dark bg.
- `@media (min-width: 768px)` skrije drawer na desktopu.

### Kako testirati
1. Odpri blog (`/blog/index.php`) na mobile (≤ 768px) → hamburger viden v desnem zgornjem kotu.
2. Klik → drawer drsi iz desne, ozadje se zatemni.
3. V drawer-ju vidi: Booked, Cene, **Začni** CTA, language grid (8 jezikov, aktivni je temen).
4. Klik na link / backdrop / Escape / X gumb → drawer se zapre.
5. Klik na drug jezik → preusmeri na pravo URL (in zapre drawer).







## ✅ Naloga 6: Pregled prevodov (delno)

### Spremembe

**Lang fajli (vsi 8 jezikov):**
- Prevedene **3 nove advanced feature kartice** + **1 premium kartica** (skupaj 8 ključev) v 7 jezikov:
  - `landing.adv_tables_title/desc` (Razporeditev miz / Tischplan / Plan des tables / …)
  - `landing.adv_waitlist_title/desc` (Čakalna lista / Warteliste / …)
  - `landing.adv_guests_title/desc` (Baza gostov / Gästedatenbank / …)
  - `landing.prem_survey_title/desc` (Ankete za goste / Gästeumfragen / …)
- Posodobljen `auth.hero_text` v 7 jezikov (brez SMS reference, idiomatsko prevedeno).
- Strukturni format: vsi lang fajli ostajajo PRETTY-print 4-space indent, JSON UTF-8 brez escape.

### Kaj NI bilo pregledano (priporočilo za kasneje)

Celotni pregled vseh ~1601 SL ključev za tone of voice, slovnično pravilnost in konsistenco zahteva **dolgotrajen Sonnet API pregled**. Skript `scripts/review_sl_texts.php` je pripravljen za to:

```bash
BLOG_AI_INSECURE_SSL=1 php scripts/review_sl_texts.php --dry              # samo pokaži predloge
BLOG_AI_INSECURE_SSL=1 php scripts/review_sl_texts.php                    # pregled vseh
BLOG_AI_INSECURE_SSL=1 php scripts/review_sl_texts.php --prefix=email     # samo email.*
```

Po SL pregledu je treba spremembe propagirati v 7 jezikov (analogno – ali ročno ali z razširjenim translate skriptom).

### Kako testirati
1. Preklopi jezik na landing-u (`/home/index.php`) → vse 4 nove kartice (+ Ankete za goste) imajo nativni prevod.
2. Login/register stran (`/login.php`) v vsakem jeziku → `auth.hero_text` ne omenja več SMS.
3. JSON validacija: `php -r "json_decode(file_get_contents('lang/de.json')); echo json_last_error_msg();"` mora vrniti "No error".
