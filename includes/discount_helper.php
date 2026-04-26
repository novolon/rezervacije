<?php
/**
 * Discount code helpers: validacija, generacija, Stripe sync, beleženje unovčenj.
 */
require_once __DIR__ . '/stripe_helper.php';

function discount_code_exists(PDO $pdo, string $code): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM discount_codes WHERE code = ?");
    $stmt->execute([strtoupper($code)]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Validira kodo pred checkoutom.
 * Vrne ['valid' => true, 'code_id', 'percent_off', 'stripe_promo_id', ...]
 * ali ['valid' => false, 'error' => '...']
 */
function validate_discount_code(
    PDO     $pdo,
    string  $code,
    string  $planSlug  = '',
    string  $cycle     = '',
    ?int    $userId    = null
): array {
    $stmt = $pdo->prepare("SELECT * FROM discount_codes WHERE code = ? LIMIT 1");
    $stmt->execute([strtoupper($code)]);
    $dc = $stmt->fetch();

    if (!$dc)         return ['valid' => false, 'error' => 'Popustna koda ne obstaja ali ne velja.'];
    if (!$dc['is_active']) return ['valid' => false, 'error' => 'Popustna koda ni aktivna.'];
    if ($dc['valid_from']  && strtotime($dc['valid_from'])  > time())
        return ['valid' => false, 'error' => 'Popustna koda še ne velja.'];
    if ($dc['valid_until'] && strtotime($dc['valid_until']) < time())
        return ['valid' => false, 'error' => 'Popustna koda je potekla.'];
    if ($dc['max_redemptions'] && (int)$dc['redemption_count'] >= (int)$dc['max_redemptions'])
        return ['valid' => false, 'error' => 'Popustna koda je izčrpana.'];

    if ($planSlug && $dc['applies_to_plans']) {
        $plans = array_map('trim', explode(',', $dc['applies_to_plans']));
        if (!in_array($planSlug, $plans))
            return ['valid' => false, 'error' => 'Popustna koda ne velja za izbrani paket.'];
    }
    if ($cycle && $dc['applies_to_cycles']) {
        $cycles = array_map('trim', explode(',', $dc['applies_to_cycles']));
        if (!in_array($cycle, $cycles))
            return ['valid' => false, 'error' => 'Popustna koda ne velja za izbrani billing cikel.'];
    }
    if ($userId && $dc['one_per_user']) {
        $u = $pdo->prepare("SELECT 1 FROM discount_code_redemptions WHERE code_id = ? AND user_id = ?");
        $u->execute([$dc['id'], $userId]);
        if ($u->fetchColumn())
            return ['valid' => false, 'error' => 'To kodo ste že unovčili.'];
    }

    return [
        'valid'          => true,
        'code_id'        => (int)$dc['id'],
        'percent_off'    => $dc['percent_off']     ? (float)$dc['percent_off']    : null,
        'amount_off_eur' => $dc['amount_off_eur']  ? (float)$dc['amount_off_eur'] : null,
        'duration'       => $dc['duration'],
        'description'    => $dc['description'] ?? '',
        'stripe_promo_id'=> $dc['stripe_promo_id'],
    ];
}

/**
 * Generira berljivo kodo za affiliata: IMEPERCENT (npr. MARKO15).
 * Fallback na random, če pride do kolizije.
 */
function generate_discount_code(PDO $pdo, array $affiliate, float $percent): string {
    // Slugify ime (latin chars only)
    $name = preg_replace('/[^A-Z0-9]/', '', strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT', $affiliate['full_name']) ?: ''));
    $name = substr($name, 0, 10);
    $pct  = (int)$percent;
    $base = $name ? "{$name}{$pct}" : "AFF{$pct}";
    $code = $base;

    for ($i = 1; $i <= 99; $i++) {
        if (!discount_code_exists($pdo, $code)) return $code;
        $code = $base . $i;
    }
    // Fallback: random
    $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    do {
        $rand = 'AFF';
        for ($j = 0; $j < 6; $j++) $rand .= $alpha[random_int(0, strlen($alpha) - 1)];
        $rand .= $pct;
    } while (discount_code_exists($pdo, $rand));
    return $rand;
}

/**
 * Superadmin podeli affiliatu popustno kodo.
 * Ustvari Stripe coupon + promotion_code in lokalni zapis.
 */
function grant_affiliate_discount(
    PDO    $pdo,
    int    $affId,
    float  $percent,
    string $duration,
    ?int   $months,
    int    $superadminId
): array {
    $aff  = affiliate_get_helper($pdo, $affId);
    if (!$aff) throw new RuntimeException('Affiliate ne obstaja.');

    // Revoke obstoječe, če so
    if ($aff['discount_code_id']) {
        _revoke_discount_code_stripe($pdo, (int)$aff['discount_code_id']);
    }

    $code = generate_discount_code($pdo, $aff, $percent);

    // Stripe coupon
    $couponData = [
        'percent_off' => $percent,
        'duration'    => $duration,
        'name'        => 'Affiliate ' . $aff['full_name'] . ' (' . (int)$percent . '%)',
        'metadata'    => ['affiliate_id' => $affId, 'kind' => 'affiliate'],
    ];
    if ($duration === 'repeating' && $months) {
        $couponData['duration_in_months'] = $months;
    }
    $coupon = stripe_request('POST', '/coupons', $couponData);
    if (empty($coupon['id'])) {
        error_log('Stripe coupon creation failed: ' . json_encode($coupon));
        throw new RuntimeException('Stripe napaka pri kreiranju kupona.');
    }

    // Stripe promotion_code
    $promo = stripe_request('POST', '/promotion_codes', [
        'coupon'   => $coupon['id'],
        'code'     => $code,
        'metadata' => ['affiliate_id' => $affId],
    ]);
    if (empty($promo['id'])) {
        error_log('Stripe promotion_code creation failed: ' . json_encode($promo));
        throw new RuntimeException('Stripe napaka pri kreiranju promocijske kode.');
    }

    // Lokalni zapis
    $pdo->prepare("
        INSERT INTO discount_codes
        (code, description, percent_off, duration, duration_months,
         owner_affiliate_id, stripe_coupon_id, stripe_promo_id, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
    ")->execute([
        $code,
        'Affiliate koda – ' . $aff['full_name'],
        $percent,
        $duration,
        $duration === 'repeating' ? $months : null,
        $affId,
        $coupon['id'],
        $promo['id'],
        $superadminId,
    ]);
    $codeId = (int)$pdo->lastInsertId();

    // Posodobi affiliata
    $pdo->prepare("
        UPDATE affiliates
        SET discount_enabled = 1, discount_percent = ?, discount_duration = ?,
            discount_duration_months = ?, discount_code_id = ?
        WHERE id = ?
    ")->execute([$percent, $duration, $months, $codeId, $affId]);

    return ['code' => $code, 'code_id' => $codeId];
}

/**
 * Superadmin onemogoči affiliate popustno kodo.
 */
function revoke_affiliate_discount(PDO $pdo, int $affId): void {
    $aff = affiliate_get_helper($pdo, $affId);
    if (!$aff || !$aff['discount_code_id']) return;

    _revoke_discount_code_stripe($pdo, (int)$aff['discount_code_id']);

    $pdo->prepare("
        UPDATE affiliates
        SET discount_enabled = 0, discount_code_id = NULL
        WHERE id = ?
    ")->execute([$affId]);
}

function _revoke_discount_code_stripe(PDO $pdo, int $codeId): void {
    $stmt = $pdo->prepare("SELECT stripe_promo_id FROM discount_codes WHERE id = ?");
    $stmt->execute([$codeId]);
    $row = $stmt->fetch();
    if ($row && $row['stripe_promo_id']) {
        // Deaktiviraj Stripe promotion_code
        stripe_request('POST', '/promotion_codes/' . $row['stripe_promo_id'], ['active' => 'false']);
    }
    $pdo->prepare("UPDATE discount_codes SET is_active = 0 WHERE id = ?")
        ->execute([$codeId]);
}

/**
 * Ob invoice.payment_succeeded: zabeleži unovčenje kode (če je bila aplicirana).
 */
function discount_record_redemption(PDO $pdo, array $invoice, int $userId, int $subscriptionId): void {
    try {
        $couponId = $invoice['discount']['coupon']['id']     ?? null;
        $promoId  = $invoice['discount']['promotion_code']   ?? null;
        if (!$couponId && !$promoId) return;

        $stmt = $pdo->prepare("
            SELECT id FROM discount_codes
            WHERE stripe_coupon_id = ? OR stripe_promo_id = ?
            LIMIT 1
        ");
        $stmt->execute([$couponId, $promoId]);
        $dc = $stmt->fetch();
        if (!$dc) return;

        // Idempotenca
        $dup = $pdo->prepare("SELECT 1 FROM discount_code_redemptions WHERE code_id = ? AND stripe_invoice_id = ?");
        $dup->execute([$dc['id'], $invoice['id'] ?? '']);
        if ($dup->fetchColumn()) return;

        $amountOff = ((int)($invoice['total_discount_amounts'][0]['amount'] ?? 0)) / 100.0;

        $pdo->prepare("
            INSERT INTO discount_code_redemptions
            (code_id, user_id, subscription_id, stripe_invoice_id, amount_off_eur)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$dc['id'], $userId, $subscriptionId, $invoice['id'] ?? null, $amountOff]);

        $pdo->prepare("UPDATE discount_codes SET redemption_count = redemption_count + 1 WHERE id = ?")
            ->execute([$dc['id']]);

    } catch (Throwable $e) {
        error_log('discount_record_redemption error: ' . $e->getMessage());
    }
}

function affiliate_get_helper(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM affiliates WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}
