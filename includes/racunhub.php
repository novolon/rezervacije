<?php
/**
 * Račun Hub API klient.
 * Konfiguracija: HUB_API_URL, HUB_API_KEY v config.php
 */

class RacunHubClient
{
    private $apiUrl;
    private $apiKey;
    private $sourceApp;

    public function __construct(string $apiUrl, string $apiKey, string $sourceApp = 'rezervacije')
    {
        $this->apiUrl    = $apiUrl;
        $this->apiKey    = $apiKey;
        $this->sourceApp = $sourceApp;
    }

    /**
     * Ustvari račun. Vrne array z id, status, number.
     * @throws RuntimeException ob HTTP napaki
     */
    public function createInvoice(string $idempotencyKey, array $data): array
    {
        return $this->request('POST', '/v1/invoices', $data, $idempotencyKey);
    }

    /**
     * Pridobi račun po ID.
     */
    public function getInvoice(string $invoiceId): array
    {
        return $this->request('GET', "/v1/invoices/{$invoiceId}");
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
                throw new RuntimeException("Hub sync failed za {$invoiceId}");
            }
            sleep(2);
        }
        throw new RuntimeException("Timeout: račun {$invoiceId} se ni sinhroniziral v {$maxWaitSec}s.");
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

        if ($response === false) {
            throw new RuntimeException("Hub API cURL napaka: " . curl_error($ch));
        }

        $json = json_decode($response, true);

        if ($statusCode >= 400) {
            $code    = $json['error']['code']    ?? 'UNKNOWN';
            $message = $json['error']['message'] ?? $response;
            throw new RuntimeException("Hub API [{$statusCode}] {$code}: {$message}");
        }

        return $json ?? [];
    }
}

/**
 * Vrne singleton instanco klienta (lazy init).
 */
function get_racunhub(): RacunHubClient
{
    static $instance = null;
    if ($instance === null) {
        $instance = new RacunHubClient(HUB_API_URL, HUB_API_KEY, 'rezervacije');
    }
    return $instance;
}

/**
 * Sestavi `client` blok za Hub API iz users vrstice.
 * Pričakuje: full_name, email, company_name, company_address,
 *            tax_number, is_vat_registered, vat_id
 */
function hub_build_client(array $user): array
{
    $isVat  = !empty($user['is_vat_registered']);
    $taxNum = $isVat
        ? ($user['vat_id']      ?? '')
        : ($user['tax_number']  ?? '');

    $client = [
        'name'        => ($user['company_name'] ?: $user['full_name']),
        'email'       => $user['email'],
        'country'     => 'SI',
        'is_taxable'  => $isVat,
    ];

    if (!empty($user['company_address'])) {
        $client['street'] = $user['company_address'];
    }
    if ($taxNum !== '') {
        $client['tax_number'] = $taxNum;
    }

    return $client;
}
