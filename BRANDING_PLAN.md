# Custom Branding & Email Plan

White-label izkušnja za Premium uporabnike: logotip, barve, skritje Rezble brandinga, in custom email pošiljanje. Vsi nižji paketi vidijo "by Rezble" povezavo.

---

## Database (1 nova migracija)

`sql/migrate_branding.sql`:
```sql
ALTER TABLE restaurants
  ADD COLUMN logo_path           VARCHAR(255) NULL,
  ADD COLUMN brand_primary       VARCHAR(7)   NULL,    -- #RRGGBB
  ADD COLUMN brand_secondary     VARCHAR(7)   NULL,
  ADD COLUMN hide_branding       TINYINT(1)   NOT NULL DEFAULT 0,
  -- email
  ADD COLUMN email_provider      ENUM('default','mailgun','smtp') NOT NULL DEFAULT 'default',
  ADD COLUMN email_settings_enc  TEXT         NULL,    -- encrypted JSON (creds)
  ADD COLUMN email_from_name     VARCHAR(100) NULL,
  ADD COLUMN email_from_address  VARCHAR(255) NULL,
  ADD COLUMN email_verified_at   TIMESTAMP    NULL;
```

---

## Feature gating (`includes/plans.php`)

Vse spodnje funkcije **samo Premium**:
- `custom_logo`
- `custom_colors`
- `hide_branding`
- `custom_from_email`

---

## Phase 1 — White-label brand (logo + colors + hide Rezble)

### 1.1 "by Rezble" link (vsi paketi razen Premium z `hide_branding=1`)

- **Lokacije**: `widget.js` (vgrajeni widget), `book.php` (javni booking flow), email footer
- **Markup**:
  ```html
  <div class="rz-attribution">
      Powered by <a href="https://rezble.com" target="_blank" rel="noopener">Rezble</a>
  </div>
  ```
- **Logika**: prikaži, če NE (premium AND hide_branding=1)
- **CSS**: subtle, 11px, var(--ink-mute)

### 1.2 Logo upload (Premium only)

- **API endpoint**: `POST /api/upload_logo.php` (multipart/form-data)
- **Validacija**:
  - MIME: `image/png`, `image/jpeg`, `image/svg+xml`, `image/webp`
  - Max velikost: **500 KB**
  - Max dimenzije: 800x400 px (resize če večja, samo za raster)
  - SVG: sanitize (regex remove `<script>`, `on*` attrs)
- **Storage**: `/uploads/logos/{restaurant_id}_{sha1(content+timestamp)}.{ext}`
  - Hash v imenu prepreči cache stampede ob menjavi
- **Priporočila prikazana v UI**:
  - "Najbolje: SVG ali PNG z prozornim ozadjem"
  - "Priporočena velikost: 240x80 px (širši) ali 200x200 px (kvadratni)"
  - "Max 500 KB"
- **Uporaba**: `<img src="...">` v glavi widget-a in book.php (namesto privzete ikone restavracije)
- **Brisanje**: gumb "Odstrani logotip" → unlink + clear column

### 1.3 Brand colors (Premium only)

- **DB**: `brand_primary`, `brand_secondary` (#RRGGBB)
- **Aplikacija**: injekcija CSS variables v `<style>` blok ali inline na `<html>`:
  ```css
  :root {
      --brand-primary: <?= $rest['brand_primary'] ?: '#2563eb' ?>;
      --brand-secondary: <?= $rest['brand_secondary'] ?: '#64748b' ?>;
  }
  ```
- **CSS v widget/book.php**: zamenjaj hardcodirane `#2563eb` z `var(--brand-primary, #2563eb)` (z fallback)
- **Uporaba**: gumbi, accent linije, focus rings, hover state

### 1.4 Live preview

- **Postavitev**: nov tab "Branding" v `restaurant-edit.php` (ali sekcija znotraj "Spletne rezervacije")
- **Layout**: levo form (50%), desno iframe preview (50%) s `book.php?token=X&preview=1`
- **Mehanizem**: PostMessage med form <-> iframe za live update brez reload:
  - Form spreminja CSS vars + img src
  - iframe sluša `message` event in posodobi CSS vars
- **Fallback**: če postMessage uspe ne, iframe se reloada na blur/save
- **Mobile**: preview kot collapse-able panel pod formom

### Phase 1 effort: ~2-3 dni

| Task | Effort |
|------|--------|
| Migration + plans.php gate | 0.5 d |
| API upload + validacija | 0.5 d |
| widget.js + book.php branding injection | 0.5 d |
| restaurant-edit.php "Branding" tab + preview iframe | 1 d |
| Testiranje (vsi paketi, multi-tenant) | 0.5 d |

---

## Phase 2 — Custom from-email

Ker projekt nima Composer-ja (`CLAUDE.md` prepoved), brez PHPMailer/SwiftMailer/Symfony Mailer. Tri možnosti:

### Možnost A: Mailgun (per-restaurant) — **PRIPOROČAM**

Restavracija doda **svojo** Mailgun domeno + API ključ.

- **Pro**: že obstoječ `mailer.php` (HTTP API), ena dodatna par parametrov
- **Pro**: ni dependency, ni socket koda
- **Pro**: Mailgun pošlje DNS verifikacijo (SPF/DKIM)
- **Con**: restavracija mora imeti Mailgun račun (~$5/mes za 1k emailov)
- **Effort**: ~0.5 d

### Možnost B: Raw SMTP — **PODPORA POPULARNIH PROVIDER-jev**

Mini-SMTP klient s PHP sockets (~200 vrstic vendored v `includes/smtp_client.php`).

- **Podprta**: Gmail SMTP, Outlook 365, Zoho, ProtonMail Bridge, kakršenkoli SMTP
- **Pro**: univerzalno, vsi imajo SMTP
- **Pro**: brez dodatnih plačil za restavracijo
- **Con**: ~200 vrstic kode za vzdrževanje
- **Con**: TLS handshake debugging je včasih nadležen
- **Con**: počasnejši od HTTP API (300-800ms per email)
- **Effort**: ~1.5 d (incl. testing z 3+ providerji)

### Možnost C: SendGrid / Postmark / Resend API

HTTP API integracije — isti vzorec kot Mailgun.

- **Pro**: nizka latenca (HTTP)
- **Con**: še 3 adapterji za vzdrževanje
- **Effort**: ~0.5 d per provider

### Predlog: implementiramo A + B

- **A (Mailgun custom)** — za technically savvy tenante, najbolj zanesljivo
- **B (SMTP)** — fallback za vse ostale (Gmail je najpogostejši)
- **C** lahko dodamo kasneje če kdo prosi

### Arhitektura

`includes/mailer.php` postane provider-aware:
```php
function send_email($pdo, $restaurantId, $to, $subject, $html) {
    $config = get_email_config($pdo, $restaurantId);
    if ($config['provider'] === 'mailgun') return send_via_mailgun_custom($config, ...);
    if ($config['provider'] === 'smtp')    return send_via_smtp($config, ...);
    return send_via_default_mailgun($to, $subject, $html);
}
```

### Verifikacija

- Pred aktivacijo provider-ja: pošlje **test email** uporabniku, samo če uspe se `email_verified_at` postavi
- DKIM/SPF preverjanje za Mailgun: API klic `GET /domains/{domain}` → `state: active`

### Šifriranje credentialov

SMTP geslo + Mailgun API ključ se shranita šifrirano:
- `email_settings_enc` = `openssl_encrypt(json_encode($config), 'aes-256-gcm', APP_SECRET, ...)`
- `APP_SECRET` v `config.php`

### Phase 2 effort: ~3-4 dni

| Task | Effort |
|------|--------|
| Migration `email_settings_enc` + encryption helpers | 0.5 d |
| mailer.php provider routing | 0.5 d |
| SMTP klient (vendored, brez Composer) | 1 d |
| Mailgun custom domain integration | 0.5 d |
| UI: pages/restaurant-edit.php "Email" tab + test email | 0.5 d |
| DNS verification helper (SPF/DKIM check) | 0.5 d |

---

## Skupna ocena: ~5-7 dni dela

## Vrstni red implementacije

1. **Branding migration + plans gate** (1h, blokira vse ostalo)
2. **"by Rezble" link + hide branding gate** (2h, najmanjši, največja zaznavnost)
3. **Brand colors + live preview** (1 dan, hitro vredno)
4. **Logo upload + integration** (1 dan)
5. **Custom email — Mailgun custom** (1 dan)
6. **Custom email — SMTP klient** (1.5 dni)

---

## Status

- [x] Phase 1.1 — "by Rezble" link (widget + book.php, hide via premium toggle)
- [x] Phase 1.2 — Logo upload (api/upload_logo.php, 500KB max, MIME + SVG sanitizer)
- [x] Phase 1.3 — Brand colors (primary + secondary, CSS variable injection)
- [x] Phase 1.4 — Live preview (postMessage iframe, ?preview=1)
- [x] Phase 2 — Custom email (Mailgun custom + SMTP), with verified-test workflow

## TODO (manual deployment steps)

1. Apply migration on prod: `sql/migrate_branding.sql`
2. Set `APP_SECRET` constant in `config.php` (random 32+ char string) for proper AES key
3. Create `uploads/logos/` directory writable by PHP user (apache/www-data)
4. Translate `re.brand_*` and `re.email_*` keys to en/de/es/fr/hr/it/pt
   (sl.json fallback works but native translations preferred)
5. Test booking flow with: default Mailgun, Mailgun custom, SMTP (Gmail w/ App Pass)
