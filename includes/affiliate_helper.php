<?php
/**
 * Affiliate core helpers: ref_code generacija, atribucija, provizije, click log.
 */

function affiliate_setting(PDO $pdo, string $key): string {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $pdo->prepare("SELECT setting_value FROM affiliate_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    $cache[$key] = $val !== false ? (string)$val : '';
    return $cache[$key];
}

function affiliate_get(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM affiliates WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function affiliate_get_by_code(PDO $pdo, string $code): ?array {
    $stmt = $pdo->prepare("SELECT * FROM affiliates WHERE ref_code = ? AND status = 'active'");
    $stmt->execute([strtoupper($code)]);
    return $stmt->fetch() ?: null;
}

/**
 * Generira unikaten 8-znakovni ref_code (brez ambivalentnih znakov).
 */
function affiliate_generate_ref_code(PDO $pdo): string {
    $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alpha[random_int(0, strlen($alpha) - 1)];
        }
        $stmt = $pdo->prepare("SELECT 1 FROM affiliates WHERE ref_code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn());
    return $code;
}

/**
 * Nastavi affiliate tracking cookie.
 * Kličemo vedno, ko zaznamo ?ref= parameter na javni strani.
 *
 * Cookie consent: rez_aff je marketing/tracking cookie (ePrivacy 5(3)),
 * zato ga postavimo SAMO če je uporabnik privolil v 'marketing' kategorijo.
 * Klik vedno logiramo ločeno (affiliate_log_click) – to je server-side analitika
 * brez piškotka in jo opravičuje legitimni interes affiliate priporočila.
 */
function affiliate_set_cookie(string $refCode, array $params = []): void {
    require_once __DIR__ . '/cookie_consent.php';
    if (!rez_consent_allows('marketing')) return; // brez privolitve ne nastavimo

    $ttl  = 60 * 86400; // 60 dni
    $data = json_encode([
        'code'         => strtoupper($refCode),
        'ts'           => date('Y-m-d H:i:s'),
        'landing'      => substr($_SERVER['REQUEST_URI'] ?? '', 0, 512),
        'utm_source'   => substr($params['utm_source']   ?? '', 0, 80),
        'utm_medium'   => substr($params['utm_medium']   ?? '', 0, 80),
        'utm_campaign' => substr($params['utm_campaign'] ?? '', 0, 120),
    ]);
    setcookie('rez_aff', $data, [
        'expires'  => time() + $ttl,
        'path'     => '/',
        'httponly' => false,
        'samesite' => 'Lax',
        'secure'   => isset($_SERVER['HTTPS']),
    ]);
}

/**
 * Logira klik (asinhrono – ne blokira UX-a).
 * Kliči samo po validaciji ref_code-a.
 */
function affiliate_log_click(PDO $pdo, int $affId, string $refCode): void {
    try {
        $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
        $salt   = defined('APP_URL') ? APP_URL : 'rez_salt_2026';
        $ipHash = hash('sha256', $ip . $salt);
        $ua     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $ref    = substr($_SERVER['HTTP_REFERER']    ?? '', 0, 512);
        $uri    = substr($_SERVER['REQUEST_URI']     ?? '', 0, 512);
        $isBot  = preg_match('/bot|crawl|spider|slurp|curl|wget/i', $ua) ? 1 : 0;

        $pdo->prepare("
            INSERT INTO affiliate_clicks
            (affiliate_id, ref_code, ip_hash, user_agent, referer, landing_url, is_bot)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$affId, $refCode, $ipHash, $ua, $ref, $uri, $isBot]);
    } catch (Throwable $e) {
        error_log('affiliate_log_click error: ' . $e->getMessage());
    }
}

/**
 * Ob registraciji: poveži novega userja z affiliatom (iz cookie ali ?code= fallback).
 * Kliči po uspešnem INSERT v users tabelo.
 */
function attach_affiliate_on_signup(
    PDO    $pdo,
    int    $newUserId,
    string $newEmail,
    ?string $taxNumber    = null,
    ?string $codeFromUrl  = null
): void {
    try {
        $aff     = null;
        $payload = [];

        $attribution = 'url';

        // 1) Primarno: cookie
        if (!empty($_COOKIE['rez_aff'])) {
            $payload = json_decode($_COOKIE['rez_aff'], true) ?: [];
            if (!empty($payload['code'])) {
                $aff = affiliate_get_by_code($pdo, $payload['code']);
                if ($aff) $attribution = 'cookie';
            }
        }

        // 2) Fallback: ?code= URL param → iščemo affiliata prek discount_codes
        if (!$aff && $codeFromUrl) {
            $stmt = $pdo->prepare("
                SELECT a.* FROM affiliates a
                JOIN discount_codes dc ON dc.owner_affiliate_id = a.id
                WHERE dc.code = ? AND dc.is_active = 1 AND a.status = 'active'
                LIMIT 1
            ");
            $stmt->execute([strtoupper($codeFromUrl)]);
            $aff = $stmt->fetch() ?: null;
            if ($aff) $attribution = 'code';
        }

        // 3) Fallback: ?ref= URL param → direktno po ref_code
        if (!$aff) {
            $refFromUrl = strtoupper(trim($_GET['ref'] ?? ''));
            if ($refFromUrl && preg_match('/^[A-Z2-9]{8}$/', $refFromUrl)) {
                $aff = affiliate_get_by_code($pdo, $refFromUrl);
                if ($aff) {
                    $attribution = 'url';
                    $payload     = ['code' => $refFromUrl, 'landing' => $_SERVER['REQUEST_URI'] ?? ''];
                }
            }
        }

        if (!$aff) return;

        // Anti-self-referral
        if (strcasecmp($aff['email'], $newEmail) === 0) return;
        if ($taxNumber && $aff['tax_number'] && $aff['tax_number'] === $taxNumber) return;

        // Preveri, ali referral že obstaja (ON DUPLICATE KEY IGNORE)
        $pdo->prepare("
            INSERT IGNORE INTO affiliate_referrals
            (affiliate_id, user_id, ref_code, attribution, cookie_set_at, landing_url, utm_source, utm_medium, utm_campaign)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $aff['id'],
            $newUserId,
            $aff['ref_code'],
            $attribution,
            $payload['ts']           ?? null,
            substr($payload['landing']      ?? '', 0, 512),
            substr($payload['utm_source']   ?? '', 0, 80),
            substr($payload['utm_medium']   ?? '', 0, 80),
            substr($payload['utm_campaign'] ?? '', 0, 120),
        ]);

        // Počisti cookie
        setcookie('rez_aff', '', ['expires' => time() - 3600, 'path' => '/']);

    } catch (Throwable $e) {
        error_log('attach_affiliate_on_signup error: ' . $e->getMessage());
        // Ne blokira registracije
    }
}

/**
 * Ob plačilu (invoice.payment_succeeded): ustvari provizijo za affiliate.
 * Kliči iz stripe-webhook.php v handle_invoice_paid().
 */
function affiliate_record_commission(
    PDO    $pdo,
    int    $userId,
    array  $invoice,
    int    $subscriptionId
): void {
    try {
        $stmt = $pdo->prepare("SELECT * FROM affiliate_referrals WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $ref = $stmt->fetch();
        if (!$ref) return;

        // Preveri commission window
        if ($ref['commission_until'] && strtotime($ref['commission_until']) < time()) return;

        $aff = affiliate_get($pdo, (int)$ref['affiliate_id']);
        if (!$aff || $aff['status'] !== 'active') return;

        // Konfiguracija (per-affiliate ali global default)
        $percent  = $aff['commission_percent']  ?? (float)affiliate_setting($pdo, 'default_commission_percent');
        $flat     = $aff['commission_flat_eur']  ? (float)$aff['commission_flat_eur'] : null;
        $holdDays = (int)($aff['hold_days']      ?? affiliate_setting($pdo, 'default_hold_days'));
        $window   = (int)($aff['commission_window_months'] ?? affiliate_setting($pdo, 'default_commission_window_m'));

        $base = ((int)($invoice['amount_paid'] ?? 0)) / 100.0;
        if ($base <= 0) return;

        $amount = $flat ? $flat : round($base * (float)$percent / 100, 2);
        if ($amount <= 0) return;

        $paidAt      = (int)($invoice['created'] ?? time());
        $availableAt = (new DateTime('@' . $paidAt))
            ->modify("+{$holdDays} days")
            ->format('Y-m-d H:i:s');

        // Idempotenca: preveri duplicate stripe_invoice_id
        $dup = $pdo->prepare("SELECT 1 FROM affiliate_commissions WHERE stripe_invoice_id = ? AND affiliate_id = ?");
        $dup->execute([$invoice['id'] ?? '', $aff['id']]);
        if ($dup->fetchColumn()) return;

        $pdo->prepare("
            INSERT INTO affiliate_commissions
            (affiliate_id, referral_id, user_id, subscription_id, stripe_invoice_id,
             base_amount_eur, percent, flat_amount_eur, amount_eur, status, available_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ")->execute([
            $aff['id'], $ref['id'], $userId, $subscriptionId,
            $invoice['id'] ?? null,
            $base,
            $flat ? null : (float)$percent,
            $flat,
            $amount,
            $availableAt,
        ]);

        // Prvič plačal → nastavi referral commission_until
        if (!$ref['first_paid_at']) {
            $until = (new DateTime('@' . $paidAt))
                ->modify("+{$window} months")
                ->format('Y-m-d H:i:s');
            $pdo->prepare("
                UPDATE affiliate_referrals
                SET first_paid_at = NOW(), commission_until = ?, status = 'converted'
                WHERE id = ?
            ")->execute([$until, $ref['id']]);
        }

    } catch (Throwable $e) {
        error_log('affiliate_record_commission error: ' . $e->getMessage());
    }
}

/**
 * Ob refundu/chargebacku: razveljavi provizijo (void) ali ustvari clawback.
 */
function affiliate_handle_refund(PDO $pdo, string $stripeInvoiceId): void {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM affiliate_commissions
            WHERE stripe_invoice_id = ? AND status IN ('pending','payable','paid')
            LIMIT 1
        ");
        $stmt->execute([$stripeInvoiceId]);
        $comm = $stmt->fetch();
        if (!$comm) return;

        if (in_array($comm['status'], ['pending', 'payable'])) {
            $pdo->prepare("UPDATE affiliate_commissions SET status = 'void', void_reason = 'refund' WHERE id = ?")
                ->execute([$comm['id']]);
        } else {
            // Že izplačano → clawback
            $pdo->prepare("
                INSERT INTO affiliate_commissions
                (affiliate_id, referral_id, user_id, subscription_id, stripe_invoice_id,
                 base_amount_eur, percent, flat_amount_eur, amount_eur, status, available_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'clawback', NOW())
            ")->execute([
                $comm['affiliate_id'], $comm['referral_id'], $comm['user_id'],
                $comm['subscription_id'], $comm['stripe_invoice_id'],
                -abs((float)$comm['base_amount_eur']),
                $comm['percent'],
                $comm['flat_amount_eur'] ? -abs((float)$comm['flat_amount_eur']) : null,
                -abs((float)$comm['amount_eur']),
            ]);
        }
    } catch (Throwable $e) {
        error_log('affiliate_handle_refund error: ' . $e->getMessage());
    }
}
