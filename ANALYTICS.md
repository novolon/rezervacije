# Analytics — Rezble (PostHog)

Vsa produktna analitika gre prek PostHog (EU regija, `eu.i.posthog.com`). Skladno z GDPR/ePrivacy: PostHog se NE inicializira pred consentom za »analytics« kategorijo (glej `COOKIES.md`).

---

## Setup

V `config.php`:
```php
define('POSTHOG_KEY',  'phc_…');                    // Project API key iz eu.posthog.com → Settings
define('POSTHOG_HOST', 'https://eu.i.posthog.com'); // EU regija
```

Pusti prazno (`''`) → analitika tiho preskoči (ničesar ne razbije).

Privzeto **brezplačno do 1 M dogodkov/mesec** in 5 K session replays/mesec.

---

## Kje je client SDK naložen

Vsa mesta, ki kličejo `posthog_render_init()` v `<head>`:

| Površina | Datoteka | `context` property |
|---|---|---|
| Landing | `home/index.php` | `landing` |
| Booked blog | `blog/_header.php` | `blog` |
| Javna booking stran | `book.php` | `book_public` |
| Admin app + affiliate auth/portal | `includes/html_head.php` | `admin` (avtomatsko `affiliate` za `/affiliate/*`) |

**Cookie consent gating**: SDK init se zgodi šele po `rezconsent:change` z `analytics: true`. Brez privolitve → ničesar.

---

## Avtomatsko (vklopljen autocapture)

Brez kakršnegakoli kodiranja se beležijo:

| Dogodek | Kaj |
|---|---|
| **Pageview** (`$pageview`) | Vsak ogled strani (URL, referrer, timestamp, naprava, brskalnik) |
| **Pageleave** (`$pageleave`) | Kdaj uporabnik zapusti stran (čas na strani) |
| **Autocapture klikov** (`$autocapture`) | Vsak klik na `<a>`, `<button>`, `<input type="submit">` z label tekstom in CSS selektorjem |
| **Form submit** | Vsak `<form>` submit |
| **Session recording** | **Vklopljen** — sname premikanje miške, klike, scroll, vnosne polja (z masking-om gesel) |

→ Klike po admin app-u in javnih booking flow-ih PostHog avtomatsko vidi **brez** custom kode.

---

## Custom dogodki (eksplicitno belezeni)

### Server-side (`includes/analytics.php` → `analytics_capture()`)

| Event | Datoteka | Properties | Pomen |
|---|---|---|---|
| `login_success` | `api/auth.php` | `role` | Uspešna prijava admin/staff |
| `register_success` | `register.php` | `email`, `plan`, `lang` | Nov račun ustvarjen |
| `register_failed` | `register.php` | `reason` (email_taken / server_error), `email` | Neuspešna registracija |
| `reservation_created` | `api/book.php` | `restaurant_id`, `guest_count`, `lang` | Javna rezervacija oddana |
| `plan_activated` | `api/stripe-webhook.php` | `plan_slug`, `cycle`, `amount`, `currency` | Stripe plačilo uspešno |

### Client-side (`window.posthog.capture()`)

| Event | Datoteka | Properties | Pomen |
|---|---|---|---|
| `login_success` | `login.php` | `role` | Vzporedno s server-side; client-side ima distinct_id že vezan |
| `login_failed` | `login.php` | `reason` (unknown / network_error) | Neuspešna prijava |
| `booking_step_viewed` | `book.php` | `step` (string: `"1"`, `"2"`, `"3"`, `"3b"`, `"4"`, `"5"`) | Vsak korak v booking flow |
| `booking_completed` | `book.php` | `auto_confirmed`, `guests`, `date`, `has_area` | Booking uspešno zaključen |

**Booking step legenda**:
- `1` — izbira gostov + datuma
- `2` — izbira termina
- `3` — kontaktni podatki + opomba
- `3b` — alternativna pot: čakalna lista (kadar termin ni prost)
- `4` — pregled / potrdi
- `5` — potrditev (ekran »uspešno«)

---

## Person properties (prijavljeni uporabniki)

Pri prijavljenih userjih (`identify` v `posthog_init.php`) se v PostHog veže:

```js
{
  email:         "...",
  full_name:     "...",
  role:          "superadmin" | "admin" | "user",
  plan_slug:     "trial" | "basic" | "advanced" | "premium",
  restaurant_id: 42
}
```

→ V PostHog lahko filtriraš »vsi premium plačniki« ali »vsi superadmini, ki so v zadnjem tednu naredili X dejanj«.

## Global properties (vsak event)

Avtomatsko dodano vsakemu dogodku:
- `app_context` — `landing` / `blog` / `book_public` / `admin` / `affiliate`
- `app_lang` — `sl` / `en` / `de` / `it` / `fr` / `hr` / `es` / `pt`

---

## Recipi za pogosta vprašanja

### 1. Kateri uporabniki so začeli rezervacijo a je niso zaključili?

**Funnel** (Insights → New insight → Funnel):
```
Step 1: booking_step_viewed     where step = "1"
Step 2: booking_step_viewed     where step = "2"
Step 3: booking_step_viewed     where step = "3"     # ali "3b" za waitlist
Step 4: booking_step_viewed     where step = "4"
Step 5: booking_completed
```
Conversion window: **30 min** (booking je hiter; privzetih 14 dni je preveč).

PostHog pokaže drop-off na vsakem koraku v % + absolutnih številkah. Klikneš »dropoff« stolpec → seznam sej, ki so padle ven na tisti točki → odpreš v **Session Replay** in pogledaš zakaj.

**Trends — sami abandoned bookings na dan**:
```
A: booking_step_viewed   where step = "1"
B: booking_completed
Formula: A - B
```

**Cohort »abandoned bookers«**:
```
Performed event: booking_step_viewed (last 7 days)
AND did NOT perform: booking_completed (last 7 days)
```

### 2. Kateri korak ima največjo izpadno stopnjo?

Funnel zgoraj — PostHog izračuna conversion rate per step. Najnižji odstotek = bottleneck.

### 3. Ali ljudje, ki vidijo waitlist (step `3b`), kdaj zaključijo?

Funnel:
```
Step 1: booking_step_viewed   where step = "3b"
Step 2: booking_completed
```

### 4. Koliko gostov v povprečju rezervirajo prek javne strani?

Insights → Trends → `reservation_created` → Group by event property `guest_count` → Average / Median.

### 5. Kateri jeziki na booking strani konvertirajo najbolje?

Funnel z breakdown by `app_lang`:
- Step 1: `booking_step_viewed` (step=1)
- Step 2: `booking_completed`
- Breakdown: `app_lang`

### 6. Kateri admini se ne prijavljajo več?

Cohort:
```
Performed event: login_success in last 90 days
AND did NOT perform: login_success in last 14 days
```

### 7. Premium plačniki, ki niso aktivni v app-u

Cohort:
```
person property: plan_slug = "premium"
AND did NOT perform: $autocapture in last 7 days
```

### 8. Kateri Stripe plani se najbolje prodajajo

Trends → `plan_activated` → Group by `plan_slug`.

---

## Predlogi za dodatno granularnost (trenutno NE belezi)

Če želiš razumeti **zakaj** booking flow propade, ne samo kje:

| Dodaten event | Kdaj | Kaj pove |
|---|---|---|
| `booking_widget_opened` | Ko se javna stran sploh odpre | Top-of-funnel baseline (vsi obiskovalci, ne samo tisti, ki so začeli izbirati) |
| `booking_no_slots` | Ko ni prostih terminov za izbran datum | »Restavracija je preveč zasedena za ta datum« |
| `booking_validation_error` | Obrazec javi napako | Katere napake (telefon, email, gosti …) najpogosteje blokirajo |
| `booking_back_clicked` | Klik »Nazaj« | Iz katerega koraka se najpogosteje vračajo |
| `booking_changed_date` | Menjava datuma | »Iskali so več datumov« — signal o nedostopnosti |
| `booking_changed_guests` | Menjava števila gostov | Signal o omejitvah pri zmogljivosti |
| `booking_widget_closed` | Pred zaključkom (beforeunload, klik X) | Točka odhoda (z `last_step` property-jem) |

Dodatno za admin app:
| Event | Kje | Pomen |
|---|---|---|
| `reservation_created_admin` | api/reservations.php (POST) | Admin/staff je ročno ustvaril rezervacijo |
| `reservation_status_changed` | api/reservation_action.php | Spremembe statusa: confirmed/cancelled/no_show/arrived |
| `reservation_canceled_by_guest` | iz tokena ob preklicu | Gostov self-cancel |
| `waitlist_joined` | api/waitlist.php | Gost se je prijavil na čakalno listo |
| `waitlist_promoted` | cron/waitlist_expire.php | Sistem ga je promoviral v rezervacijo |
| `survey_submitted` | api/survey.php | Gost oddal anketo (z `rating`) |
| `affiliate_signup` | affiliate/register.php | Nov affiliate registriran |
| `affiliate_referral_paid` | api/stripe-webhook.php | Affiliate provizija za prvo plačilo (uporabno za attribution funnel) |

Za session recording: priporočljivo **onemogočiti** na plačilnih obrazcih (Stripe Elements ima svoje masking, ampak za varnost) in profilu (osebne info). To delaš v PostHog UI → Settings → Session Replay → URL exclusions.

---

## Booking step kot številka (priporočeno)

Trenutno je `step` poslano kot **string** (`"1"`, `"2"`, `"3b"`). PostHog ga sortira leksikografsko, kar pomeni `"10" < "2"` (če bi kdaj imeli več kot 9 korakov).

Predlog: dodati še `step_num` (int) property:
```js
window.posthog.capture('booking_step_viewed', {
    step: String(n),
    step_num: typeof n === 'number' ? n : 0  // 3b → 0 (waitlist) ali 3.5
});
```

Tako lahko v PostHog filtriraš `step_num > 3` numerično.

---

## Privacy / GDPR

- **Vse skozi consent**: PostHog SDK, `_ph_anon` cookie in `ph_*` cookieji se naložijo **samo po consent.analytics = true** (glej `COOKIES.md`).
- **EU regija**: vsi podatki na `eu.i.posthog.com`, brez prenosov v ZDA.
- **Personal data**: PostHog hrani email, ime, role kot person properties — opcijsko lahko pošlješ k PostHog `posthog.opt_out_capturing()` za uporabnike, ki to zahtevajo (GDPR pravica do izbrisa).
- **Session replay** masks `<input type="password">`, `<input type="email">`, `[data-attr="ph-no-capture"]`, ipd. po default-u. Občutljive podatke (IBAN, telefonske številke) lahko ročno označiš z `data-ph-mask-text` atributom.

---

## Reference

- Server helper: `includes/analytics.php` → `analytics_capture($event, $distinctId, $properties)`
- Client init: `includes/posthog_init.php` → `posthog_render_init(['context' => '...', 'identify' => bool, 'extra' => [...]])`
- Anon ID: `_ph_anon` cookie (365 dni, samo s consent.analytics)
- Cookie consent integracija: `COOKIES.md`
