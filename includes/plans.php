<?php
/**
 * Plan engine – definicije paketov, feature gating, subscription helper.
 */

// ─── Definicije paketov ────────────────────────────────────────
const PLANS = [
    'trial' => [
        'name'            => 'Trial',
        'monthly_price'   => 0,
        'yearly_price'    => 0,
        'features'        => ['reservations', 'restaurants', 'staff'],
    ],
    'basic' => [
        'name'            => 'Basic',
        'monthly_price'   => 4.99,
        'yearly_price'    => 49.99,
        'features'        => ['reservations', 'restaurants', 'staff'],
    ],
    'advanced' => [
        'name'            => 'Advanced',
        'monthly_price'   => 6.99,
        'yearly_price'    => 69.99,
        'features'        => ['reservations', 'restaurants', 'staff',
                              'guest_emails', 'guest_reminders',
                              'public_booking', 'booking_approval'],
    ],
    'premium' => [
        'name'            => 'Premium',
        'monthly_price'   => 9.99,
        'yearly_price'    => 99.99,
        'features'        => ['reservations', 'restaurants', 'staff',
                              'guest_emails', 'guest_reminders',
                              'public_booking', 'booking_approval',
                              'embed_widget', 'branding',
                              'auto_confirm', 'sms_notifications'],
    ],
];

// ─── Opisi funkcionalnosti (za UI) ────────────────────────────
const FEATURE_LABELS = [
    'reservations'    => 'Upravljanje rezervacij',
    'restaurants'     => 'Dodajanje restavracij',
    'staff'           => 'Dodajanje osebja',
    'guest_emails'    => 'Email gostom ob rezervaciji',
    'guest_reminders' => 'Opomnik gostom 24h pred rezervacijo',
    'public_booking'  => 'Javna rezervacijska povezava',
    'booking_approval'=> 'Potrjevanje/zavračanje rezervacij',
    'embed_widget'    => 'Embedni widget za spletno stran',
    'branding'        => 'Branding restavracije',
    'auto_confirm'    => 'Samodejno potrjevanje z omejitvijo gostov',
    'sms_notifications'=> 'SMS obvestila',
];

// ─── Pridobi aktivno naročnino admina ─────────────────────────
function get_active_subscription(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("
        SELECT * FROM subscriptions
        WHERE user_id = ?
          AND status IN ('trial', 'active', 'pending_invoice')
          AND (ends_at IS NULL OR ends_at > NOW())
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

// ─── Preverja ali ima admin dostop do določene funkcionalnosti ─
function user_has_feature(PDO $pdo, int $userId, string $feature): bool {
    $sub = get_active_subscription($pdo, $userId);
    if (!$sub) return false;
    $plan = PLANS[$sub['plan_slug']] ?? null;
    if (!$plan) return false;
    return in_array($feature, $plan['features']);
}

// ─── API guard – vrne 403 če admin nima dostopa ───────────────
function require_feature(PDO $pdo, array $session, string $feature): void {
    if ($session['role'] === 'superadmin') return; // superadmin ni omejen
    if ($session['role'] !== 'admin') return;       // userji nimajo paketov
    if (!user_has_feature($pdo, (int)$session['user_id'], $feature)) {
        json_response(false, null, 'Ta funkcionalnost ni na voljo v vašem paketu.', 403);
    }
}

// ─── Koliko dni do konca triala ───────────────────────────────
function get_trial_days_left(?array $sub): int {
    if (!$sub || $sub['plan_slug'] !== 'trial' || !$sub['ends_at']) return 0;
    $diff = (new DateTime($sub['ends_at']))->diff(new DateTime());
    // invert=1 pomeni ends_at je v prihodnosti (še ni potekel)
    return $diff->invert === 0 ? 0 : max(0, (int)$diff->days);
}

// ─── Je trial potekel? ────────────────────────────────────────
function is_trial_expired(?array $sub): bool {
    if (!$sub) return true;
    if ($sub['plan_slug'] !== 'trial') return false;
    if (!$sub['ends_at']) return false;
    return strtotime($sub['ends_at']) < time();
}

// ─── HTML badge za paket ──────────────────────────────────────
function plan_badge(string $planSlug): string {
    $label = PLANS[$planSlug]['name'] ?? ucfirst($planSlug);
    return '<span class="plan-badge plan-badge-' . htmlspecialchars($planSlug, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</span>';
}

// ─── Aktivni popust za paket (če obstaja) ─────────────────────
function get_active_discount(PDO $pdo, string $planSlug): ?array {
    $stmt = $pdo->prepare("
        SELECT * FROM plan_discounts
        WHERE plan_slug = ? AND is_active = 1
          AND valid_from <= NOW() AND valid_until >= NOW()
        ORDER BY valid_until ASC
        LIMIT 1
    ");
    $stmt->execute([$planSlug]);
    return $stmt->fetch() ?: null;
}
