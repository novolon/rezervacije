# Cookie consent – Rezble

End-to-end consent sistem za rezble.com (landing, booked) in app.rezble.com (admin app, affiliate, javne rezervacijske strani). Skladno z ePrivacy Directive 5(3) in GDPR (informirana, prostovoljna, granularna, lahko umakljiva privolitev).

---

## TL;DR

- En `rez_consent` cookie na apex domeni `.rezble.com` → deljen med vsemi subdomenami.
- 4 kategorije: **necessary** (vedno) / **functional** / **analytics** / **marketing**.
- Banner ob prvem obisku ima **3 enakovredne gumbe**: Sprejmi vse / Zavrni vse / Nastavitve. Re-prompt po **180 dneh**.
- Pred privolitvijo se postavijo **samo nujni piškotki** (PHP seja, jezik ob izrecni izbiri, »remember me« ob izrecni izbiri, sam consent cookie).
- PostHog SDK in vsi tracking piškotki (`_ph_anon`, `rez_aff`) se naložijo **šele po privolitvi za ustrezno kategorijo**.

---

## Datoteke

| Pot | Vloga |
|---|---|
| `includes/cookie_consent.php` | Server helper: `rez_consent_render()`, `rez_consent_allows($cat)`, `rez_consent_state()`. |
| `assets/js/cookie-consent.js` | Client: cookie I/O, banner+modal lazy build, public API `window.rezConsent`, event `rezconsent:change`. |
| `assets/css/cookie-consent.css` | Samostojne stilske definicije (deluje brez Tailwinda). |
| `cookie-policy.php` | Javna stran s seznamom vseh piškotkov (link iz vseh footerjev). |

## Vključevanje na površinah

- **Landing** (`home/index.php`): direkten `rez_consent_render(['surface' => 'landing'])` pred `</body>`.
- **Booked blog** (`blog/_header.php`): direkten render takoj po `<body>`.
- **Affiliate landing** (`affiliate/index.php`): direkten render pred `</body>`.
- **Admin app + affiliate auth** (vsi pages, ki uporabljajo `includes/html_head.php`): render je vključen v `html_head.php` – avtomatsko za vse nove strani; surface se zazna iz `SCRIPT_NAME` (`'app'` ali `'affiliate'`).
- **Public booking** (`book.php`): direkten render pred `</body>`.
- **Cookie politika** (`cookie-policy.php`): vključi se sama, ima tudi gumb »Spremeni nastavitve«.

> Render je idempotenten – varno klicati večkrat, output bo natisnjen samo enkrat.

---

## Cookie format

Cookie `rez_consent` je `application/json` ovit v URL-encoding. Atributi:

- **Path**: `/`
- **SameSite**: `Lax`
- **Secure**: da (na HTTPS)
- **HttpOnly**: ne (JS mora brati za gating)
- **Domain**: `.rezble.com` v produkciji (auto-detect: če host konča z `rezble.com`); na dev brez domene (per-host).
- **Expires**: 180 dni od zadnje privolitve.

Vsebina:
```json
{
  "v": 1,
  "ts": 1715000000,
  "cats": { "necessary": 1, "functional": 0, "analytics": 1, "marketing": 0 },
  "surface": "landing"
}
```

Ko verzija (`v`) ne ujema več, se cookie ignorira → re-prompt. Spremenite `REZ_CONSENT_VERSION` v `cookie_consent.php`, ko spremenite kategorije ali politiko, da pridobite svežo privolitev za vse uporabnike.

---

## Kategorije in trenutni inventar

| Cookie | Vir | Trajanje | Kategorija | Opomba |
|---|---|---|---|---|
| `PHPSESSID` | PHP runtime | seja | **necessary** | Prijava, košarica, varnostni žeton. |
| `rem_tok` | `api/auth.php`, `includes/auth_check.php` | 30 dni | **necessary** | Opt-in »ostani prijavljen«; brez izrecne izbire ni postavljen. |
| `rzlang` | `includes/lang.php`, `api/auth.php` | 365 dni | **necessary** | Postavljen samo ob izrecni izbiri jezika; argumentirano nujno (»specifically requested service«). |
| `rez_consent` | `cookie_consent.php` (JS) | 180 dni | **necessary** | Ta consent sistem. |
| `_ph_anon` | `includes/analytics.php` (server) | 365 dni | **analytics** | Postavljen le, če je `rez_consent_allows('analytics')`. |
| `ph_*` | PostHog SDK (client) | do 365 dni | **analytics** | SDK init se zgodi le po privolitvi (`rezPosthogStart()`). |
| `rez_aff` | `includes/affiliate_helper.php` | 60 dni | **marketing** | Postavljen le, če je `rez_consent_allows('marketing')`. |

> Klikovni log (`affiliate_clicks` tabela) ne potrebuje piškotka – posname se brez klientske identifikacije ob prepoznavi `?ref=` parametra (legitimni interes affiliate atribucije, brez storage v brskalniku).

---

## Kako uporabljati v kodi

### Server-side gating (PHP)

```php
require_once __DIR__ . '/cookie_consent.php';

if (rez_consent_allows('marketing')) {
    setcookie('moja_marketing_kuka', $val, ...);
}
```

`rez_consent_state()` vrne polni state array (vključno s `'_set'`), `rez_consent_needs_banner()` vrne true, če uporabnik še ni izrazil odločitve.

### Client-side gating (JS)

```js
// Sinhrono branje
if (window.rezConsent && window.rezConsent.has('analytics')) {
    initThirdPartyAnalytics();
}

// Reaktivno (po vsaki spremembi)
window.addEventListener('rezconsent:change', function (ev) {
    var c = ev.detail.categories;
    if (c.analytics) initSomeAnalytics();
    else            killSomeAnalytics();
});
```

### Programatsko upravljanje

```js
window.rezConsent.acceptAll();              // npr. iz "Accept" CTA
window.rezConsent.rejectAll();
window.rezConsent.show();                   // odpre preferences modal
window.rezConsent.set({ analytics: true }); // delna sprememba
```

### Trigger povezava »Cookie nastavitve«

Vsak link/gumb z atributom `data-rez-consent="open"` avtomatsko odpre modal. Že vključeno v footerjih landing/affiliate strani in v `cookie-policy.php`.

---

## Postopek dodajanja novega piškotka

1. **Ugotovite kategorijo** (necessary / functional / analytics / marketing). Če je tracking, marketing ali analitika tretje osebe → vedno consent.
2. **PHP cookie**: pred `setcookie()` dodajte gating:
   ```php
   require_once __DIR__ . '/cookie_consent.php';
   if (!rez_consent_allows('analytics')) return;
   setcookie('moj_cookie', $val, [...]);
   ```
3. **Client cookie / SDK**: SDK obvijte v stub + start funkcijo:
   ```js
   var started = false;
   function startMyAnalytics() { if (started) return; started = true; /* init... */ }
   if (window.rezConsent && window.rezConsent.has('analytics')) startMyAnalytics();
   window.addEventListener('rezconsent:change', function (e) {
       if (e.detail.categories.analytics) startMyAnalytics();
   });
   ```
4. **Posodobite seznam** v `cookie-policy.php` (tabela `Seznam piškotkov`) **in** v tabeli zgoraj v tem dokumentu.
5. **Če nova kategorija ali pomembna sprememba**: povečajte `REZ_CONSENT_VERSION` v `includes/cookie_consent.php`, da se vsi obstoječi consenti razveljavijo in zahtevamo svežo privolitev.

## Postopek odstranjevanja piškotka

1. Odstranite `setcookie()` klice in odpošljite expiration v vseh mestih (`time() - 3600`).
2. Posodobite seznam v `cookie-policy.php` in tukaj.
3. Verzije ni treba spremeniti, če odstranjujete brez dodajanja.

---

## Compliance checklist (EU)

- [x] **Informirana privolitev**: vsaka kategorija ima jasen opis (banner + modal).
- [x] **Granularnost**: 4 kategorije, ne vse-ali-nič.
- [x] **Enakovredni gumbi**: »Sprejmi vse« in »Zavrni vse« sta vidno enako vidna v banneru in modalu.
- [x] **Brez pred-označenih polj**: vsi opcijski toggli so privzeto **off**, dokler uporabnik aktivno ne izbere.
- [x] **Brez piškotkov pred privolitvijo**: PostHog SDK, `_ph_anon`, `rez_aff` se naložijo šele po consentu.
- [x] **Nujni piškotki brez consenta**: PHP seja, prijava (»remember me« je opt-in), izbira jezika ob izrecni akciji, sam consent cookie.
- [x] **Možnost umika kadarkoli**: `data-rez-consent="open"` v footerjih, »Cookie nastavitve« povsod.
- [x] **Trajanje privolitve**: 180 dni → re-prompt.
- [x] **Politika piškotkov**: javna stran `cookie-policy.php` s celotnim seznamom in trajanji.
- [x] **EU obdelava**: PostHog EU regija (`eu.i.posthog.com`); brez prenosov v tretje države.
- [ ] **Audit log privolitev**: trenutno ne shranjujemo zgodovine na strežniku. Če je to zahteva (npr. nemški SaaS B2C), dodajte tabelo `consent_log(ts, ip_hash, categories_json, version)` in ob `rezconsent:change` pošljite `POST /api/consent_log.php`.

---

## Ne pozabi pri novih integracijah

| Primer integracije | Kategorija | Kako gateati |
|---|---|---|
| Google Analytics, Plausible, Matomo | analytics | Init za `rezConsent.has('analytics')`. |
| Meta Pixel, Google Ads pixel | marketing | Init za `rezConsent.has('marketing')`. |
| Intercom / Crisp / chat live | functional ali analytics (odvisno od purpose) | Glede na vlogo (support = functional, analytics-heavy = analytics). |
| Hotjar, FullStory session recording | analytics | Posebna pozornost: snemanje seje pogosto zahteva ločeno kategorijo »session_replay« – razmislite o dodajanju 5. kategorije. |
| Stripe Elements | necessary | Plačilo je »explicitly requested service« po ePrivacy 5(3). Brez gatinga. |
| YouTube/Vimeo embed | marketing/functional | Embed nastavi piškotke tretje osebe – kar zamenjajte za »click-to-load« placeholder, dokler uporabnik ne potrdi. |

## Edge cases

- **Embed widget** (`widget.js` na partner straneh tretje osebe): partnerska stran je odgovorna za svoj consent. Naš widget bi moral spoštovati `Sec-Fetch-*` ali zahtevati partnerjev consent prek postMessage; trenutno ne shranjuje piškotkov sam (vse gre na strežnik). Če bo kdaj uvedeno tracking-ovo storage znotraj iframe-a, bo treba dodatna pravila.
- **Iframe rezervacijska stran**: `book.php` ima svoj banner; če je vgrajen v partnerski strani, banner pojavi le ob prvi interakciji znotraj iframe-a.
- **Cross-subdomain consistency**: cookie domena `.rezble.com` zagotavlja, da uporabnik, ki sprejme/zavrne na rezble.com, ne dobi ponovnega bannerja na app.rezble.com (in obratno). Dev (single host) – per-host consent.

## Ko spremenite politiko ali kategorije

1. Posodobite `REZ_CONSENT_VERSION` v `includes/cookie_consent.php` (`1` → `2` itd.).
2. Posodobite `cookie-policy.php` (`$lastUpdate` + spremembe v vsebini).
3. Posodobite ta dokument.
4. Opcijsko: pošljite email obvestilo registriranim uporabnikom 30 dni vnaprej (GDPR transparency).
