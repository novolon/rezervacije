# Račun Hub API — dokumentacija za zunanje aplikacije

API za izdajanje in upravljanje računov preko Račun Hub. Hub interno komunicira s Čebelca.biz (fiscalization, PDF), tvoja aplikacija pa se pogovarja samo z Hub-om.

**Verzija API:** `v1`  
**Base URL (dev):** `http://localhost:3001/v1`  
**Base URL (prod):** `https://api.racuni.novolon.com/v1`

---

## Za Claude Code: kontekst integracije

Ta API je Matic Kaltenekar s.p.-ov interni Hub za izdajanje računov. Kadar gradiš aplikacijo ki mora izdati račun (rezervacijski sistem, naročniški sistem, e-commerce), klic narediš na ta API — **ne na Čebelca API direktno.**

### Ključne lastnosti

- **Asinhroni workflow:** `POST /v1/invoices` vrne takoj (`pending_sync`), račun dobi številko po ~2-10s ko se sinhronizira s Čebelco. Polli `GET /v1/invoices/{id}` dokler `status !== "synced"`.
- **Idempotency je obvezna:** Vedno pošlji `Idempotency-Key` header pri POST-u. Format: `<resource>-<tvoj_id>` npr. `order-12345-invoice`. Brez tega tvegaš duplikate pri retry-jih.
- **Denar kot string:** Vsi denarni zneski v API odgovorih so `string` (decimalno), ne `number`. Pri pisanju kode ne parsiraj v float — uporabi `decimal.js` ali `Decimal` knjižnico.
- **API ključ:** Pridobi od Matica (matic@kaltenekar.com). Format: dolg hex string (64 znakov).
- **source_app:** Vedno nastavi na kratek identifikator tvoje aplikacije (npr. `"rezervacije"`). Matic vidi v dashboardu od kod prihajajo računi.

### Minimalni primer (PHP)

> Rezervacijski sistem je pisan v PHP. Spodaj je pripravan razred ki ga daš v projekt.

```php
<?php

class RacunHubClient
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $sourceApp,
    ) {}

    /**
     * Ustvari račun. Vrne array z id, status, number.
     * @throws RuntimeException ob HTTP napaki
     */
    public function createInvoice(string $idempotencyKey, array $data): array
    {
        return $this->request('POST', '/v1/invoices', $data, $idempotencyKey);
    }

    /**
     * Polling dokler status != synced (max $maxWaitSec sekund).
     * @throws RuntimeException če timeout ali sync_error
     */
    public function waitForSync(string $invoiceId, int $maxWaitSec = 30): array
    {
        $deadline = time() + $maxWaitSec;
        while (time() < $deadline) {
            $invoice = $this->request('GET', "/v1/invoices/{$invoiceId}");
            if ($invoice['status'] === 'synced') {
                return $invoice;
            }
            if ($invoice['status'] === 'sync_error') {
                throw new RuntimeException("Hub sync failed za {$invoiceId}, Hub bo poskusil znova.");
            }
            sleep(2);
        }
        throw new RuntimeException("Timeout: račun {$invoiceId} se ni sinhroniziral v {$maxWaitSec}s.");
    }

    /** Prenesi PDF kot binarni string. Vrne false če PDF še ni pripravljen (202). */
    public function getPdf(string $invoiceId): string|false
    {
        $ch = curl_init("{$this->apiUrl}/v1/invoices/{$invoiceId}/pdf");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$this->apiKey}"],
        ]);
        $body       = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode === 202) return false; // še ni pripravljen
        if ($statusCode !== 200) throw new RuntimeException("PDF prenos neuspešen: HTTP {$statusCode}");
        return $body;
    }

    private function request(string $method, string $path, array $body = [], string $idempotencyKey = ''): array
    {
        $ch = curl_init("{$this->apiUrl}{$path}");

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($idempotencyKey !== '') {
            $headers[] = "Idempotency-Key: {$idempotencyKey}";
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $method !== 'GET' ? json_encode($body) : null,
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response   = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($response, true);

        if ($statusCode >= 400) {
            $code    = $json['error']['code'] ?? 'UNKNOWN';
            $message = $json['error']['message'] ?? $response;
            throw new RuntimeException("Hub API error [{$statusCode}] {$code}: {$message}");
        }

        return $json;
    }
}
```

**Uporaba v rezervacijskem sistemu:**

```php
<?php

// init (enkrat, npr. v DI containerju)
$hub = new RacunHubClient(
    apiUrl:    $_ENV['HUB_API_URL'],   // https://api.racuni.novolon.com
    apiKey:    $_ENV['HUB_API_KEY'],   // dolg hex string, dobi od Matica
    sourceApp: 'rezervacije',
);

// Po uspešnem plačilu rezervacije
function onReservationPaid(int $reservationId, array $reservation): void
{
    global $hub;

    $today = date('Y-m-d');

    $invoice = $hub->createInvoice(
        idempotencyKey: "rezervacija-{$reservationId}-invoice",
        data: [
            'client' => [
                'name'       => $reservation['guest_name'],
                'email'      => $reservation['guest_email'],
                'tax_number' => $reservation['guest_tax_number'] ?? null,
                'country'    => $reservation['guest_country'] ?? 'SI',
            ],
            'issue_date'     => $today,
            'due_date'       => $today,
            'items'          => [[
                'description' => "Rezervacija #{$reservationId}: {$reservation['room_name']}",
                'quantity'    => $reservation['nights'],
                'unit'        => 'noč',
                'unit_price'  => $reservation['price_per_night'],
            ]],
            'source_app'     => 'rezervacije',
            'reference'      => (string) $reservationId,
            'payment_method' => 'stripe',
            'mark_paid'      => true,
            'paid_date'      => $today,
        ],
    );

    // Shrani Hub invoice ID v svojo bazo
    DB::update('reservations', ['hub_invoice_id' => $invoice['id']], ['id' => $reservationId]);
}
```

**`.env` za PHP projekt:**

```bash
HUB_API_URL=https://api.racuni.novolon.com
HUB_API_KEY=<hex_kljuc_od_matica>
```

---

## Avtentikacija

Vsi `/v1/*` requesti zahtevajo **`Authorization: Bearer <api_key>`** header.

```bash
curl -X GET https://api.racuni.novolon.com/v1/invoices \
  -H "Authorization: Bearer <tvoj_api_kljuc>"
```

API ključi so dolgi hex stringi (64 znakov). Pridobi od Matica.

---

## Endpoints

### Računi

#### `POST /v1/invoices`

Ustvari nov račun.

**Headers:**

- `Authorization: Bearer <key>` (obvezno)
- `Idempotency-Key: <unique_key>` (**močno priporočeno**)
- `Content-Type: application/json`

**Body:**

```typescript
{
  // STRANKA
  client: {
    id?: string;          // ID obstoječe stranke v Hubu (če ga že imaš)
    name: string;         // ali celotni podatki za assure:
    street?: string;
    postal_code?: string;
    city?: string;
    country?: string;     // ISO 3166-1 alpha-2 (npr. "SI", "DE"), default "SI"
    tax_number?: string;  // z ali brez prefiksa ("SI10545379")
    email?: string;
    is_taxable?: boolean;
  };

  // DATUMI (YYYY-MM-DD)
  issue_date: string;     // datum izdaje
  service_date?: string;  // datum opr. storitve (default: issue_date)
  due_date: string;       // rok plačila

  // VALUTA IN JEZIK
  currency?: string;  // ISO 4217 (default: "EUR")
  language?: string;  // "sl" | "en" (default: "sl")

  // POSTAVKE (vsaj 1)
  items: Array<{
    description: string;
    quantity: number;
    unit: string;        // "kos", "ur", "noč", "kg", ...
    unit_price: number;  // decimalno, brez DDV
    vat_rate?: number;   // % DDV, default 0 (Matic je normiranec, vedno 0)
    discount?: number;   // % popust, default 0
  }>;

  // DODATNO
  note?: string;       // opomba na računu (pod tabelo postavk)
  reference?: string;  // tvoj interni ID (npr. ID rezervacije)
  source_app: string;  // identifikator tvoje aplikacije, npr. "rezervacije"
  category?: string;   // ime kategorije (npr. "Rezble") — tiho ignorira če ne obstaja
  tags?: string[];     // seznam oznak (npr. ["mesecno", "basic"])
  custom_fields?: Record<string, unknown>;  // poljubni JSON metadata

  // PLAČILO
  payment_method?: "trr" | "stripe" | "cash" | "card" | "paypal" | "other";
  mark_paid?: boolean;  // true = takoj označi kot plačano
  paid_date?: string;   // YYYY-MM-DD, obvezno če mark_paid=true

  // E-POŠTA (opcijsko — samodejno pošlje PDF ko je pripravljen)
  send_email?: {
    to: string;       // e-mail prejemnika
    from: string;     // pošiljatelj (mora biti v MAILGUN_AUTHORIZED_SENDERS)
    subject?: string; // Hub ustvari privzeto če ni
    message?: string; // Hub ustvari privzeto če ni
  };
}
```

**Response 202:**

```typescript
{
  id: string; // Hub invoice ID, shrani v svojo bazo
  status: 'pending_sync' | 'synced';
  number: string | null; // null dokler ni sinhroniziran s Čebelco
  total: string; // skupaj z DDV, decimalno kot string
  currency: string;
  pdf_url: string | null;
  created_at: string; // ISO 8601
}
```

**Napake:**

- `400` — validacijska napaka (manjkajoča polja, napačen format)
- `401` — manjka ali neveljaven API ključ
- `409` — duplikat (isti Idempotency-Key z drugačnim bodyjem)

#### `GET /v1/invoices/{id}`

Podrobnosti računa. Pokliči po kreaciji dokler `status === "synced"`.

**Response:**

```typescript
{
  id: string;
  status: 'draft' | 'pending_sync' | 'synced' | 'sync_error' | 'cancelled';
  number: string | null;

  client: {
    id: string;
    name: string;
    street: string | null;
    postal_code: string | null;
    city: string | null;
    country: string;
    tax_number: string | null;
    email: string | null;
  }

  issue_date: string; // YYYY-MM-DD
  service_date: string;
  due_date: string;
  paid_date: string | null;

  currency: string;
  exchange_rate_eur: string; // 1 enota valute = X EUR
  subtotal: string;
  vat_total: string;
  total: string;
  total_eur: string;

  items: Array<{
    id: string;
    description: string;
    quantity: string;
    unit: string;
    unit_price: string;
    vat_rate: string;
    discount: string;
    line_total: string;
  }>;

  note: string | null;
  reference: string | null;
  source_app: string;
  payment_method: string | null;
  language: string;

  category: { id: string; name: string; color: string | null } | null;
  tags: string[];

  pdf_url: string | null;
  cebelca_id: number | null;
  synced_at: string | null;
  created_at: string;
  updated_at: string;
}
```

#### `GET /v1/invoices`

Seznam računov.

**Query parametri:**

- `limit` — število rezultatov (default 50, max 500)
- `offset` — paginacija
- `status` — filter po statusu (`synced`, `pending_sync`, ...)
- `client_id` — filter po stranki
- `source_app` — filter po aplikaciji (npr. `rezervacije`)
- `date_from` / `date_to` — YYYY-MM-DD, filter po datumu izdaje
- `reference` — iskanje po tvojem internem IDju

**Response:**

```typescript
{
  data: Invoice[];
  pagination: {
    total: number;
    limit: number;
    offset: number;
    has_more: boolean;
  };
}
```

#### `GET /v1/invoices/{id}/pdf`

Prenese PDF računa.

- **200:** `Content-Type: application/pdf` — binary PDF
- **202:** PDF še ni pripravljen, poskusi čez 5s

#### `POST /v1/invoices/{id}/mark-paid`

Označi račun kot plačan.

```typescript
{
  paid_date: string;  // YYYY-MM-DD
  payment_method?: "trr" | "stripe" | "cash" | "card" | "paypal" | "other";
}
```

#### `POST /v1/invoices/{id}/cancel`

Prekliči račun.

#### `DELETE /v1/invoices/{id}`

Izbriši račun iz Hub baze (ne iz Čebelce — pravni dokument tam ostane).

**Response:** 204 No Content

#### `GET /v1/invoices/{id}/email-draft`

Vrne predlogo e-pošte.

```typescript
{
  to: string;
  from: string;
  subject: string;
  message: string;
  pdf_ready: boolean;
}
```

#### `POST /v1/invoices/{id}/send-email`

Pošlji PDF po e-pošti.

```typescript
// Request
{
  to: string;
  from: string;
  subject: string;
  message: string;
}

// Response
{
  ok: true;
  queued: boolean; // true = takoj, false = čaka na PDF
  pdf_ready: boolean;
}
```

---

### Stranke

#### `POST /v1/clients`

Ustvari ali posodobi stranko (assure — če obstaja po `tax_number`, samo posodobi podatke).

```typescript
{
  name: string;
  street?: string;
  postal_code?: string;
  city?: string;
  country?: string;    // default "SI"
  tax_number?: string;
  email?: string;
  phone?: string;
  is_taxable?: boolean;
  note?: string;
}
```

#### `GET /v1/clients/{id}` / `GET /v1/clients`

Podrobnosti oz. seznam strank. Query: `search`, `country`, `limit`, `offset`.

#### `DELETE /v1/clients/{id}`

Izbriši stranko. Napaka 409 če stranka ima račune (najprej izbriši račune).

---

### Kategorije

#### `GET /v1/categories`

Seznam kategorij za filtriranje računov (read-only iz API-ja, upravljanje samo preko UI).

---

## Idempotency

Vedno pošlji `Idempotency-Key` header pri POST-u:

- Format: `<resource>-<tvoj_id>` npr. `rezervacija-12345-invoice`
- Hub hrani rezultat 24 ur
- Isti ključ → vrne prejšnji odgovor, ne ustvari novega

---

## Error format

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Opisno sporočilo.",
    "request_id": "req_abc123"
  }
}
```

**Kode:** `VALIDATION_ERROR`, `AUTHENTICATION_ERROR`, `NOT_FOUND`, `CONFLICT`, `RATE_LIMITED`, `INTERNAL_ERROR`

---

## Status računov — lifecycle

```
draft → pending_sync → synced
                    ↘ sync_error  (Hub retry-a v ozadju, max 5x)
```

Poll dokler `synced`:

```php
$invoice = $hub->waitForSync($invoice['id'], maxWaitSec: 30);
echo $invoice['number']; // npr. "26-0007"
echo $invoice['pdf_url'];
```

---

## Hitri test (curl)

```bash
# Zamenjaj <KEY> s svojim API ključem
curl -X POST https://api.racuni.novolon.com/v1/invoices \
  -H "Authorization: Bearer <KEY>" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-001" \
  -d '{
    "client": {"name": "Test d.o.o.", "country": "SI"},
    "issue_date": "2026-04-29",
    "due_date": "2026-05-13",
    "items": [{"description": "Testna storitev", "quantity": 1, "unit": "kos", "unit_price": 100}],
    "source_app": "rezervacije"
  }'
```

---

## Support

Email: matic@kaltenekar.com — pošlji `request_id` iz error response za hitro diagnozo.

---

**Verzija:** 1.1  
**Posodobljeno:** 2026-04-29
