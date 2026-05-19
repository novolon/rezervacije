<?php
/**
 * Plan engine – definicije paketov, feature gating, subscription helper.
 *
 * Cene se berejo iz `config.php` → `PLAN_PRICES` (single source of truth).
 * Za spremembo cen popravi config; ta fajl jih samo uporablja.
 */

// ─── Definicije paketov ────────────────────────────────────────
// `define()` ker `const` array ne podpira runtime izrazov (PLAN_PRICES je define).
define('PLANS', [
    // 'trial' je ohranjen samo za legacy zapise v bazi – ne prikazuj v UI
    'trial' => [
        'name'            => 'Basic',   // legacy: stari trial zapisi → enako kot Basic
        'monthly_price'   => 0,
        'yearly_price'    => 0,
        'features'        => ['reservations', 'restaurants', 'staff'],
    ],
    'basic' => [
        'name'            => 'Basic',
        'monthly_price'   => PLAN_PRICES['basic']['monthly'],
        'yearly_price'    => PLAN_PRICES['basic']['yearly'],
        'features'        => ['reservations', 'restaurants', 'staff'],
    ],
    'advanced' => [
        'name'            => 'Advanced',
        'monthly_price'   => PLAN_PRICES['advanced']['monthly'],
        'yearly_price'    => PLAN_PRICES['advanced']['yearly'],
        'features'        => ['reservations', 'restaurants', 'staff',
                              'guest_emails', 'guest_reminders',
                              'public_booking', 'booking_approval',
                              'guest_database',
                              'realtime_sync',
                              'table_management'],
    ],
    'premium' => [
        'name'            => 'Premium',
        'monthly_price'   => PLAN_PRICES['premium']['monthly'],
        'yearly_price'    => PLAN_PRICES['premium']['yearly'],
        'features'        => ['reservations', 'restaurants', 'staff',
                              'guest_emails', 'guest_reminders',
                              'public_booking', 'booking_approval',
                              'embed_widget', 'branding',
                              'custom_logo', 'custom_colors', 'hide_branding',
                              'custom_from_email',
                              'auto_confirm', 'sms_notifications',
                              'survey', 'survey_edit', 'survey_export',
                              'guest_database', 'waitlist',
                              'realtime_sync',
                              'table_management'],
    ],
]);

// Vrstni red paketov (za zaznavo nadgradnje)
const PLAN_RANK = ['trial' => 1, 'basic' => 1, 'advanced' => 2, 'premium' => 3];

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
    'survey'          => 'Pregled ankete o zadovoljstvu',
    'survey_edit'     => 'Urejanje ankete in vprašanj',
    'survey_export'   => 'CSV izvoz anket',
    'guest_database'  => 'Baza gostov z zgodovino',
    'waitlist'        => 'Čakalna lista',
    'realtime_sync'   => 'Sinhronizacija v živo (večnaprava)',
    'table_management'=> 'Upravljanje miz in zmogljivosti',
    'custom_logo'     => 'Lasten logotip v widget/booking',
    'custom_colors'   => 'Lastne barve widget/booking',
    'hide_branding'   => 'Skritje "by Rezble" oznake',
    'custom_from_email'=> 'Pošiljanje emailov z lastne domene',
];

// ─── Pridobi aktivno naročnino admina ─────────────────────────
function get_active_subscription(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("
        SELECT * FROM subscriptions
        WHERE user_id = ?
          AND status IN ('trial', 'active', 'pending_invoice', 'payment_failed')
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
    if ($sub['status'] === 'payment_failed') return false;
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

// ─── Je naročnina v trial obdobju? ───────────────────────────
// Trial se določa po STATUS, ne po plan_slug.
function is_on_trial(?array $sub): bool {
    return $sub !== null && $sub['status'] === 'trial';
}

// ─── Koliko dni do konca triala ───────────────────────────────
function get_trial_days_left(?array $sub): int {
    if (!$sub || $sub['status'] !== 'trial' || !$sub['ends_at']) return 0;
    $diff = (new DateTime($sub['ends_at']))->diff(new DateTime());
    return $diff->invert === 0 ? 0 : max(0, (int)$diff->days);
}

// ─── Je trial potekel? ────────────────────────────────────────
function is_trial_expired(?array $sub): bool {
    if (!$sub) return true;
    if ($sub['status'] !== 'trial') return false; // aktivni plačljivi paket ni potekel
    if (!$sub['ends_at']) return false;
    return strtotime($sub['ends_at']) < time();
}

// ─── Rang paketa (za zaznavo nadgradnje) ──────────────────────
function get_plan_rank(string $planSlug): int {
    return PLAN_RANK[$planSlug] ?? 0;
}

// ─── Izračun sorazmerne razlike za nadgradnjo ─────────────────
// Vrne array z informacijami o prorated znesku ali [], če izračun ni možen.
function calculate_upgrade_proration(array $sub, string $newPlanSlug, PDO $pdo): array {
    if ($sub['status'] !== 'active' || !$sub['ends_at'] || !$sub['billing_cycle']) {
        return [];
    }
    $cycle   = $sub['billing_cycle'];
    $oldSlug = $sub['plan_slug'];

    if (get_plan_rank($newPlanSlug) <= get_plan_rank($oldSlug)) {
        return []; // ni nadgradnja
    }

    $now = new DateTime('now', new DateTimeZone('UTC'));
    $end = new DateTime($sub['ends_at'], new DateTimeZone('UTC'));

    if ($now >= $end) return [];

    $remainingDays = (int)$now->diff($end)->days;

    // Dolžina obdobja (mesečno / letno)
    $periodStart = (clone $end);
    if ($cycle === 'monthly') {
        $periodStart->modify('-1 month');
    } else {
        $periodStart->modify('-1 year');
    }
    $periodDays = max(1, (int)$periodStart->diff($end)->days);

    $oldPrice = (float)PLANS[$oldSlug][$cycle . '_price'];

    $discount = get_active_discount($pdo, $newPlanSlug);
    if ($discount) {
        $newPrice = $cycle === 'yearly'
            ? (isset($discount['discounted_yearly'])  ? (float)$discount['discounted_yearly']  : (float)PLANS[$newPlanSlug]['yearly_price'])
            : (isset($discount['discounted_monthly']) ? (float)$discount['discounted_monthly'] : (float)PLANS[$newPlanSlug]['monthly_price']);
    } else {
        $newPrice = (float)PLANS[$newPlanSlug][$cycle . '_price'];
    }

    $ratio      = $remainingDays / $periodDays;
    $credit     = round($ratio * $oldPrice, 2);
    $chargeNow  = max(0.00, round($ratio * $newPrice - $credit, 2));

    return [
        'remaining_days'     => $remainingDays,
        'period_days'        => $periodDays,
        'old_plan'           => $oldSlug,
        'new_plan'           => $newPlanSlug,
        'cycle'              => $cycle,
        'old_price'          => $oldPrice,
        'new_price'          => $newPrice,
        'credit'             => $credit,
        'charge_now'         => $chargeNow,
        'next_period_price'  => $newPrice,
    ];
}

// ─── HTML badge za paket ──────────────────────────────────────
function plan_badge(string $planSlug): string {
    // Za legacy 'trial' zapise prikaži ime Basic
    $displaySlug = $planSlug === 'trial' ? 'basic' : $planSlug;
    $label = PLANS[$displaySlug]['name'] ?? ucfirst($displaySlug);
    return '<span class="plan-badge plan-badge-' . htmlspecialchars($displaySlug, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</span>';
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
