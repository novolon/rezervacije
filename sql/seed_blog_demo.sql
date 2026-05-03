-- ─── Seed: Demo blog post za testiranje render-a ─────────────────────────────
-- Zaženi PO migrate_blog.sql.
-- Vstavi en članek v sl + en in ga objavi.
-- content_html je prazen → blog/post.php ga renderira ad-hoc iz content_md
-- (z novimi anchor linki in CTA shortcode-i).
-- Varno ponoviti (INSERT IGNORE).

INSERT IGNORE INTO blog_posts
  (id, master_lang, category_id, author_id, status, published_at, ai_generated, created_by)
VALUES
  (1, 'sl',
   (SELECT id FROM blog_categories WHERE slug='operativa'),
   (SELECT id FROM blog_authors    WHERE slug='rezble-team'),
   'published', NOW() - INTERVAL 1 DAY, 0, 1);

INSERT IGNORE INTO blog_post_translations
  (post_id, lang_code, slug, title, excerpt, content_md, content_html, meta_title, meta_description, status, reading_time_minutes, word_count)
VALUES (
  1, 'sl', 'kako-zmanjsati-no-show',
  'Kako zmanjšati no-show: 7 stvari, ki delujejo',
  'Pregled konkretnih taktik, s katerimi smo pri partnerskih restavracijah zmanjšali no-show za 30–60 %.',
'## Zakaj je no-show drag in dober pokazatelj

**No-show** — gost rezervira mizo in se ne pojavi — ni samo izgubljen prihodek. Je tudi *signal*, kako dobro vaš sistem komunicira z gostom in koliko zaupanja mu vlivate. Pri partnerskih restavracijah opažamo, da lahko z osnovnimi prijemi no-show zmanjšate s 12–15 % na pod 5 %, brez da bi gosta »kaznovali«.

V tem članku gremo skozi sedem konkretnih ukrepov, ki jih lahko uvedete to soboto. Nekateri so brezplačni, drugi zahtevajo orodje, vsi pa temeljijo na enem: **jasna in pravočasna komunikacija**.

[cta:subscribe]

## 1. Pošlji potrditveni mail takoj

Prvi `confirmation_email` mora odleteti **v sekundi**, ko je rezervacija narejena. Ne čez deset minut. Ne »ko bo natakar pogledal«. Takoj. Gost potrebuje konkreten dokaz, da je rezervacija sprejeta — drugače rezervira še pri konkurenci za vsak slučaj.

Dobra potrditvena pošta vsebuje:

- Ime restavracije, datum in uro v **velikem tisku**
- Število oseb in ime rezervacije
- Naslov z linkom na zemljevid
- Eno klik akcijo: *Prekliči* ali *Spremeni*
- Telefonsko številko za neposredni kontakt

### Pogosta napaka

Restavracije pošiljajo *enako pošto* za rezervacijo ob 18:00 in ob 22:00. Gost ob 22:00 že razmišlja o spanju — opomnik mora biti drugačen. Personalizacija po terminu je 5 minut dela in zelo poveča stopnjo prihodov.

[cta:pricing]

## 2. SMS opomnik 24h prej

SMS ima **open rate okoli 98 %**. Mail ima 20 %. Razlika je dramatična. En SMS opomnik 24 ur prej pogosto sam zniža no-show za polovico.

> "Po uvedbi SMS opomnikov je naš no-show padel s 12 % na 4 % v dveh mesecih." — vodja salona, Restavracija X

Sporočilo naj bo kratko, prijazno in mora vsebovati **en klik za potrditev** in en za odpoved. Če gost potrdi, ste sproti čistili seznam. Če odpove, takoj sprostite mizo za waitlist.

## 3. Zahtevaj potrditev za večje skupine

Skupine 6+ ljudi so **nesorazmerno tvegane**. Eno odpoved v skupini in cela rezervacija razpade. Pri rezervacijah za 6+ vedno zahtevajte aktivno potrditev 48 ur prej — če ne pride, samodejno sprostite mize.

1. Sprejmite rezervacijo z opozorilom o potrditvi
2. 48 ur prej pošljite mail in SMS z eno potrditveno povezavo
3. Če v 12 urah ni odgovora, pošljite drugi opomnik
4. Če še vedno tišina, pokličite — ali sprostite

---

[cta:register]

## 4. Uvedi waitlist

Waitlist je **varnostna mreža**. Ko nekdo odpove ob 19:32 za 20:00, imate na čakalni listi že 4 ljudi, ki bi z veseljem prišli. En klik in miza je zasedena.

Brez waitliste vsaka odpoved postane prazna miza. Z waitlisto se odpoved spremeni v *nove zadovoljene goste* — in nove podatke o tem, kateri termini so najbolj iskani.

## 5. Uporabi soft-deposit

**Soft-deposit** = rezerviran znesek na kartici, ki ga zaračunate samo v primeru no-show. Ne pri prihodu, ne pri rezervaciji — samo če gost ne pride. Slovenski gostje so na to vse bolj navajeni, sploh za vikend večerje in praznike.

Tipičen znesek: `10–15 € na osebo`. Dovolj, da gost dvakrat premisli, ne dovolj, da bi se počutil kaznovanega. Ključ je transparentnost — povejte to **pred** rezervacijo, ne v drobnem tisku.

[cta:demo]

## 6. Vodi statistiko po gostu

Če gost trikrat ni prišel, je to *vzorec*, ne nesreča. Vodite enostaven score po gostu. Ne diskriminirajte na prvi rezervaciji. A če imate podatke, jih uporabite — za **prilagojeno strategijo**, ne za zavračanje.

## 7. Komuniciraj jasno

Vse zgornje točke padejo, če komunikacija ni jasna. Politika odpovedi mora biti **napisana v dveh stavkih**, vidna pred rezervacijo in v vsakem mailu. Gostje, ki vedo, kaj se zgodi pri no-show, se obnašajo bolje.

Bodite topli, ne togi: »Razumemo, da se zgodi. Prosimo, sporočite nam vsaj 4 ure prej.« deluje bolje kot *»Pri no-show zaračunamo polno ceno.«*
',
  '',
  'Kako zmanjšati no-show v restavraciji: 7 taktik, ki delujejo',
  '7 konkretnih ukrepov, s katerimi smo pri partnerskih restavracijah zmanjšali no-show za 30–60 %. Praktični vodič za lastnike in vodje salonov.',
  'approved', 6, 380
);

INSERT IGNORE INTO blog_post_translations
  (post_id, lang_code, slug, title, excerpt, content_md, content_html, meta_title, meta_description, status, ai_translated, reading_time_minutes, word_count)
VALUES (
  1, 'en', 'how-to-reduce-no-shows',
  'How to reduce no-shows: 7 things that actually work',
  'A breakdown of concrete tactics that helped our partner restaurants cut no-shows by 30–60 %.',
'## Why no-shows hurt and what they reveal

A **no-show** — a guest books a table and never appears — isn''t just lost revenue. It''s also a *signal* of how well your system communicates with the guest and how much trust you build. Across our partner restaurants, basic moves can drop no-shows from 12–15 % to under 5 %, without "punishing" anyone.

This article walks through seven concrete moves you can roll out this Saturday. Some are free, others need a tool, but they all rely on one thing: **clear, timely communication**.

[cta:subscribe]

## 1. Send the confirmation email instantly

The first `confirmation_email` must fire **within a second** of the booking. Not ten minutes later. Not "when the host checks". Right now. The guest needs concrete proof the booking was accepted — otherwise they''ll book somewhere else just in case.

A good confirmation email contains:

- Restaurant name, date, and time in **large print**
- Party size and reservation name
- Address with a map link
- A one-click action: *Cancel* or *Modify*
- A phone number for direct contact

### Common mistake

Restaurants send the *same email* for an 18:00 booking and a 22:00 booking. At 22:00 the guest is already thinking about sleep — the reminder needs to feel different. Personalization by slot is 5 minutes of work and meaningfully increases show rates.

[cta:pricing]

## 2. SMS reminder 24h before

SMS has an **open rate around 98 %**. Email has 20 %. The gap is dramatic. A single SMS reminder 24 hours before often halves no-shows on its own.

> "After we introduced SMS reminders, no-shows fell from 12 % to 4 % in two months." — floor manager, Restaurant X

The message should be short, friendly, and contain **one click to confirm** plus one to cancel. If they confirm, you''re cleaning your list as you go. If they cancel, you free the table for the waitlist immediately.

## 3. Require confirmation for big parties

Groups of 6+ are **disproportionately risky**. One cancellation in the group and the whole booking falls apart. For 6+ bookings, always require active confirmation 48 hours ahead — if it doesn''t come, the tables release automatically.

1. Accept the booking with a confirmation requirement note
2. 48 hours before, send mail and SMS with a single confirm link
3. No reply in 12 hours, send a second reminder
4. Still silence, call — or release

---

[cta:register]

## 4. Run a waitlist

A waitlist is your **safety net**. When someone cancels at 19:32 for 20:00, you already have 4 people on the list who''d gladly come. One click and the table is occupied.

Without a waitlist, every cancellation becomes an empty table. With a waitlist, a cancellation becomes *new happy guests* — plus new data on which slots are most in demand.

## 5. Use a soft-deposit

A **soft-deposit** = a reserved amount on the card, charged *only* if the guest no-shows. Not on arrival, not on booking — only on no-show. European guests are increasingly used to this, especially for weekend dinners and holidays.

Typical amount: `€10–15 per person`. Enough to make the guest think twice, not enough to feel punitive. The key is transparency — say it **before** the booking, not in the fine print.

[cta:demo]

## 6. Track guest history

If a guest has skipped three times, that''s a *pattern*, not bad luck. Keep a simple per-guest score. Don''t discriminate on a first booking. But if you have data, use it — for a **tailored strategy**, not for refusal.

## 7. Communicate clearly

All of the above falls apart if communication isn''t clear. Your cancellation policy should be **written in two sentences**, visible before booking and in every email. Guests who know what happens on a no-show behave better.

Be warm, not stiff: "We get that things come up. Please let us know at least 4 hours ahead." works better than *"No-shows are charged the full price."*
',
  '',
  'How to reduce no-shows in your restaurant: 7 tactics that work',
  '7 concrete moves that helped our partner restaurants cut no-shows by 30–60 %. A practical guide for owners and floor managers.',
  'approved', 1, 6, 380
);
