<?php
/**
 * Stripe HTTP helpers – skupna koda za billing.php in discount_helper.php.
 * Varno za večkratno vključitev (function_exists guard).
 */

if (!function_exists('stripe_request')) {
    function stripe_request(string $method, string $endpoint, array $data = []): array {
        $url = 'https://api.stripe.com/v1' . $endpoint;
        $payload = stripe_encode($data);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?? [];
    }

    function stripe_encode(array $data, string $prefix = ''): string {
        $parts = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix ? "{$prefix}[{$key}]" : $key;
            if (is_array($value)) {
                foreach ($value as $i => $v) {
                    if (is_array($v)) {
                        $parts[] = stripe_encode($v, "{$fullKey}[{$i}]");
                    } else {
                        $parts[] = urlencode("{$fullKey}[{$i}]") . '=' . urlencode((string)$v);
                    }
                }
            } elseif ($value !== null) {
                $parts[] = urlencode($fullKey) . '=' . urlencode((string)$value);
            }
        }
        return implode('&', $parts);
    }
}
