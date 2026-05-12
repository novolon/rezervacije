<?php
require_once 'config.php';
require_once 'includes/db.php';

$token = trim($_GET['t'] ?? '');

// Naloži restaurant lang nastavitve PREJ kot lang.php auto-init,
// da lahko nastavimo cookie na primary_language preden se lang.php zažene.
$_bkLangSwitcher  = true;
$_bkAvailLangs    = ['sl','en','de','it','fr','hr','es','pt'];
$_bkPrimaryLang   = null;
$_bkBranding      = null; // premium branding (logo + barve)
$_bkRest          = null; // celota za inline preview
if ($token) {
    try {
        $pdo = getDB();
        // Najprej preverimo lang stolpce, nato pa še brand stolpce (oboje ločeno za varnost migracij).
        $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE booking_token = ? LIMIT 1");
        $stmt->execute([$token]);
        if ($r = $stmt->fetch()) {
            $_bkRest = $r;
            $_bkLangSwitcher = !isset($r['booking_lang_switcher_enabled']) || $r['booking_lang_switcher_enabled'] === null ? true : (bool)$r['booking_lang_switcher_enabled'];
            if (!empty($r['booking_available_languages'])) {
                $decoded = json_decode($r['booking_available_languages'], true);
                if (is_array($decoded) && !empty($decoded)) $_bkAvailLangs = $decoded;
            }
            if (!empty($r['booking_primary_language'])) $_bkPrimaryLang = $r['booking_primary_language'];

            // Premium branding
            require_once 'includes/plans.php';
            require_once 'includes/branding_helper.php';
            $_bkBranding = get_restaurant_branding($pdo, $r);
        }
    } catch (Throwable $e) { /* stolpci morda še ne obstajajo */ }
}
// Če ?lang= ni eksplicitno poslan in nimamo cookie-ja, in switcher onemogočen ali primary nastavljen,
// nastavi cookie na primary lang da lang.php ne zažene auto-detekcije.
if (empty($_GET['lang']) && empty($_COOKIE['rzlang']) && $_bkPrimaryLang) {
    if (!$_bkLangSwitcher) {
        // Switcher onemogočen — vsi vidijo primary
        $_COOKIE['rzlang'] = $_bkPrimaryLang;
    } else {
        // Switcher omogočen — pusti auto-detect, ampak omeji na available_languages
        // (handled later v $_GET filter)
    }
}
// Če ?lang= je poslan ampak ni v available_languages, ga zavrni (fall back na primary)
if (!empty($_GET['lang']) && !in_array($_GET['lang'], $_bkAvailLangs, true)) {
    unset($_GET['lang']);
    if ($_bkPrimaryLang) $_COOKIE['rzlang'] = $_bkPrimaryLang;
}
// Če cookie ni v available, prav tako reset
if (!empty($_COOKIE['rzlang']) && !in_array($_COOKIE['rzlang'], $_bkAvailLangs, true) && $_bkPrimaryLang) {
    $_COOKIE['rzlang'] = $_bkPrimaryLang;
}

require_once 'includes/lang.php';

$apiBase = BASE_PATH . '/api/book.php';
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
    <title><?= t('book.page_title') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <?php
    $_bkPrimary   = htmlspecialchars($_bkBranding['primary']   ?? '#1B4332', ENT_QUOTES);
    $_bkSecondary = htmlspecialchars($_bkBranding['secondary'] ?? '#C4704B', ENT_QUOTES);
    ?>
    <script>
    tailwind.config = {
        theme: { extend: {
            colors: {
                forest:    { DEFAULT: '<?= $_bkPrimary ?>', light: '#2D6A4F', dark: '#081C15' },
                cream:     { DEFAULT: '#FAFAF5', dark: '#F0F0E6' },
                terracotta:{ DEFAULT: '<?= $_bkSecondary ?>', hover: '#A85D3B' },
                sage:      { DEFAULT: '#A3B18A', light: '#DAD7CD' },
            },
            fontFamily: { sans: ['"DM Sans"', 'sans-serif'] },
        }}
    }
    </script>
    <style>
        :root {
            --brand-primary: <?= $_bkPrimary ?>;
            --brand-secondary: <?= $_bkSecondary ?>;
        }
        html { scroll-behavior: smooth; }
        .step { display: none; }
        .step.active { display: block; }
        .guest-btn { transition: all .15s; }
        .guest-btn.selected { background: var(--brand-primary); color: #fff; border-color: var(--brand-primary); }
        .slot-btn { transition: all .15s; }
        .slot-btn.selected { background: var(--brand-primary); color: #fff; border-color: var(--brand-primary); }
        .slot-btn:disabled { opacity: .35; cursor: not-allowed; }
        .slot-btn.waitlist { border-color: #F59E0B; color: #92400E; background: #FFFBEB; }
        .slot-btn.waitlist:hover { background: #FEF3C7; border-color: #D97706; }
        .slot-btn.waitlist.selected { background: #F59E0B; color: #fff; border-color: #F59E0B; }
        .slot-btn.full { opacity: .4; cursor: not-allowed; }
        .cal-day { aspect-ratio: 1; display: flex; align-items: center; justify-content: center;
                   border-radius: 9999px; font-size: .875rem; font-weight: 500; cursor: pointer;
                   transition: all .15s; }
        .cal-day.available:hover { background: #F0F0E6; }
        .cal-day.selected { background: var(--brand-primary); color: #fff; }
        .cal-day.disabled { color: #DAD7CD; cursor: default; pointer-events: none; }
        .cal-day.today { font-weight: 700; color: var(--brand-secondary); }
        .cal-day.today.selected { color: #fff; }
        .rz-attribution { text-align:center; font-size:11px; color:rgba(0,0,0,.4); padding:14px 14px 18px; line-height:1.4; }
        .rz-attribution a { color:rgba(0,0,0,.6); font-weight:600; text-decoration:none; border-bottom:1px solid rgba(0,0,0,.2); }
        .rz-attribution a:hover { color:rgba(0,0,0,.85); }
        .brand-logo-img { max-width: 200px; max-height: 60px; object-fit: contain; }
    </style>
<?php
require_once __DIR__ . '/includes/posthog_init.php';
posthog_render_init([
    'context'  => 'book_public',
    'identify' => false,
    'extra'    => [
        'booking_token'   => $token,
        'restaurant_name' => $rest['name'] ?? null,
    ],
]);
?>
</head>
<body class="min-h-screen bg-cream font-sans">

<?php if (!$token): ?>
<!-- Ni tokena -->
<div class="min-h-screen flex items-center justify-center p-4">
    <div class="text-center">
        <div class="text-6xl mb-4">🔗</div>
        <h1 class="text-2xl font-bold text-forest mb-2"><?= t('book.invalid_link_title') ?></h1>
        <p class="text-forest/60"><?= t('book.invalid_link_msg') ?></p>
    </div>
</div>
<?php else: ?>

<!-- Vsebina (JS naloži restavracijo) -->
<div class="min-h-screen flex flex-col">

    <!-- Header -->
    <header class="bg-white border-b border-sage-light px-4 py-4">
        <div class="max-w-lg mx-auto flex items-center gap-3">
            <?php if (!empty($_bkBranding['logo_url'])): ?>
                <img src="<?= htmlspecialchars($_bkBranding['logo_url'], ENT_QUOTES) ?>" alt="" class="brand-logo-img flex-shrink-0" style="max-height:48px">
            <?php else: ?>
                <div class="w-8 h-8 bg-forest rounded-lg flex items-center justify-center text-white font-bold text-sm flex-shrink-0" id="rest-initial">R</div>
            <?php endif; ?>
            <div class="flex-1 min-w-0">
                <div id="rest-name" class="font-bold text-forest text-sm truncate"><?= t('common.loading') ?></div>
                <div class="text-xs text-forest/50"><?= t('book.subtitle') ?></div>
            </div>
            <!-- Lang switcher (samo če ima restavracija switcher omogočen IN ima vsaj 2 podprta jezika) -->
            <?php if ($_bkLangSwitcher && count($_bkAvailLangs) >= 2): ?>
            <details class="relative" id="lang-switcher">
                <summary class="cursor-pointer list-none px-2 py-1.5 rounded-md hover:bg-cream-dark text-xs font-semibold text-forest flex items-center gap-1" style="user-select:none">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
                    <?= strtoupper(get_lang()) ?>
                </summary>
                <div class="absolute right-0 top-full mt-1 bg-white border border-sage-light rounded-lg shadow-lg overflow-hidden z-30" style="min-width:140px">
                    <?php
                    $_curUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
                    $_curQs  = $_GET; unset($_curQs['lang']);
                    $_langNames = ['sl'=>'Slovenščina','en'=>'English','de'=>'Deutsch','it'=>'Italiano','fr'=>'Français','hr'=>'Hrvatski','es'=>'Español','pt'=>'Português'];
                    foreach ($_bkAvailLangs as $_lc):
                        if (!isset($_langNames[$_lc])) continue;
                        $_qs = array_merge($_curQs, ['lang' => $_lc]);
                        $_href = $_curUrl . '?' . http_build_query($_qs);
                        $_isCurrent = $_lc === get_lang();
                    ?>
                        <a href="<?= htmlspecialchars($_href, ENT_QUOTES) ?>"
                           class="block px-3 py-2 text-sm hover:bg-cream-dark <?= $_isCurrent ? 'font-semibold text-forest bg-cream' : 'text-forest/70' ?>">
                            <span class="inline-block w-7 text-xs text-forest/50 font-bold tracking-wider"><?= strtoupper($_lc) ?></span>
                            <?= $_langNames[$_lc] ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
        </div>
    </header>

    <!-- Napaka pri nalaganju -->
    <div id="load-error" class="hidden flex-1 flex items-center justify-center p-8">
        <div class="text-center max-w-sm">
            <div class="text-5xl mb-4">😔</div>
            <h2 class="text-xl font-bold text-forest mb-2"><?= t('book.unavailable_title') ?></h2>
            <p id="load-error-msg" class="text-forest/60 text-sm"><?= t('book.unavailable_msg') ?></p>
        </div>
    </div>

    <!-- Glavni vsebnik -->
    <main id="booking-main" class="hidden flex-1 flex flex-col">

        <!-- Progress -->
        <div class="bg-white border-b border-sage-light px-4 py-3">
            <div class="max-w-lg mx-auto">
                <div class="flex items-center gap-2">
                    <?php foreach ([['1',t('book.step1_label')],['2',t('book.step2_label')],['3',t('book.step3_label')],['4',t('book.step4_label')]] as [$n, $lbl]): ?>
                    <div class="flex items-center gap-1 flex-1 last:flex-none">
                        <div class="progress-step-<?= $n ?> flex items-center gap-1.5">
                            <div class="step-num-<?= $n ?> w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold
                                <?= $n === '1' ? 'bg-forest text-white' : 'bg-sage-light text-forest/40' ?>">
                                <?= $n ?>
                            </div>
                            <span class="step-lbl-<?= $n ?> text-xs font-medium hidden sm:block
                                <?= $n === '1' ? 'text-forest' : 'text-forest/40' ?>">
                                <?= $lbl ?>
                            </span>
                        </div>
                        <?php if ($n !== '4'): ?>
                        <div class="flex-1 h-px bg-sage-light mx-1"></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Steps -->
        <div class="flex-1 px-4 py-8">
        <div class="max-w-lg mx-auto">

            <!-- ── Korak 1: Število gostov ── -->
            <div id="step-1" class="step active">
                <h2 class="text-2xl font-bold text-forest mb-2"><?= t('book.step1_title') ?></h2>
                <p class="text-forest/60 text-sm mb-8"><?= t('book.step1_subtitle') ?></p>

                <div id="guest-btns" class="flex flex-wrap gap-3 mb-6"></div>

                <!-- "Več" input -->
                <div id="guest-more-wrap" class="hidden mb-6">
                    <label class="block text-sm font-medium text-forest mb-2" id="guest-more-label"><?= t('book.enter_guests_label') ?></label>
                    <input id="guest-more-input" type="number" min="11" step="1"
                        class="w-32 border border-sage-light rounded-xl px-4 py-2.5 text-forest font-medium text-lg focus:outline-none focus:border-forest">
                </div>

                <button id="btn-guests-next" disabled
                    class="w-full bg-terracotta text-white py-4 rounded-2xl font-semibold text-lg transition-colors
                           disabled:opacity-40 disabled:cursor-not-allowed hover:enabled:bg-terracotta-hover">
                    <?= t('book.next') ?>
                </button>
            </div>

            <!-- ── Korak 2: Datum ── -->
            <div id="step-2" class="step">
                <div class="flex items-center gap-3 mb-6">
                    <button onclick="goStep(1)" class="text-forest/50 hover:text-forest transition-colors">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <h2 class="text-2xl font-bold text-forest"><?= t('book.step2_title') ?></h2>
                </div>

                <!-- Koledar -->
                <div class="bg-white rounded-2xl border border-sage-light overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-4 border-b border-sage-light">
                        <button id="cal-prev" onclick="calMove(-1)"
                            class="text-forest/50 hover:text-forest transition-colors p-1">
                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                        </button>
                        <div id="cal-title" class="font-bold text-forest"></div>
                        <button id="cal-next" onclick="calMove(1)"
                            class="text-forest/50 hover:text-forest transition-colors p-1">
                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                        </button>
                    </div>
                    <div class="px-4 py-2">
                        <div class="grid grid-cols-7 mb-1">
                            <?php foreach ([t('days_short.0'),t('days_short.1'),t('days_short.2'),t('days_short.3'),t('days_short.4'),t('days_short.5'),t('days_short.6')] as $d): ?>
                            <div class="text-center text-xs font-medium text-forest/40 py-2"><?= $d ?></div>
                            <?php endforeach; ?>
                        </div>
                        <div id="cal-grid" class="grid grid-cols-7 gap-y-1"></div>
                    </div>
                    <div class="px-5 py-3 border-t border-sage-light bg-cream/50">
                        <p class="text-xs text-forest/50"><?= t('book.calendar_note') ?></p>
                    </div>
                </div>
            </div>

            <!-- ── Korak 3: Termin ── -->
            <div id="step-3" class="step">
                <div class="flex items-center gap-3 mb-2">
                    <button onclick="goStep(2)" class="text-forest/50 hover:text-forest transition-colors">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <h2 class="text-2xl font-bold text-forest"><?= t('book.step3_title') ?></h2>
                </div>
                <p id="step3-subtitle" class="text-sm text-forest/60 mb-6 ml-9"></p>

                <div id="slots-loading" class="text-center py-10 text-forest/40">
                    <svg class="animate-spin mx-auto mb-3" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                    </svg>
                    <?= t('book.slots_loading') ?>
                </div>
                <div id="slots-empty" class="hidden text-center py-10">
                    <div class="text-4xl mb-3">😕</div>
                    <p class="text-forest/60 font-medium"><?= t('book.no_slots') ?></p>
                    <button onclick="goStep(2)" class="mt-4 text-sm text-terracotta font-medium hover:underline"><?= t('book.pick_other_date') ?></button>

                    <div id="waitlist-offer" class="hidden mt-6 bg-amber-50 border border-amber-200 rounded-2xl p-5 text-left">
                        <p class="text-sm font-semibold text-amber-900 mb-1"><?= t('book.waitlist_offer_title') ?></p>
                        <p class="text-xs text-amber-700 mb-4"><?= t('book.waitlist_offer_desc') ?></p>
                        <div class="space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-forest mb-1"><?= t('book.first_name') ?> <span class="text-terracotta">*</span></label>
                                    <input id="wl-first" type="text" placeholder="Janez"
                                        class="w-full border border-sage-light rounded-xl px-3 py-2.5 text-forest text-sm focus:outline-none focus:border-forest">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-forest mb-1"><?= t('book.last_name') ?> <span class="text-terracotta">*</span></label>
                                    <input id="wl-last" type="text" placeholder="Novak"
                                        class="w-full border border-sage-light rounded-xl px-3 py-2.5 text-forest text-sm focus:outline-none focus:border-forest">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-forest mb-1"><?= t('book.email') ?> <span class="text-terracotta">*</span></label>
                                <input id="wl-email" type="email" placeholder="janez@email.com"
                                    class="w-full border border-sage-light rounded-xl px-3 py-2.5 text-forest text-sm focus:outline-none focus:border-forest">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-forest mb-1"><?= t('book.phone') ?> <span class="text-forest/40 font-normal"><?= t('common.optional') ?></span></label>
                                <input id="wl-phone" type="tel" placeholder="041 123 456"
                                    class="w-full border border-sage-light rounded-xl px-3 py-2.5 text-forest text-sm focus:outline-none focus:border-forest">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-forest mb-1"><?= t('book.preferred_time') ?> <span class="text-forest/40 font-normal"><?= t('common.optional') ?></span></label>
                                <input id="wl-time" type="time"
                                    class="w-full border border-sage-light rounded-xl px-3 py-2.5 text-forest text-sm focus:outline-none focus:border-forest">
                            </div>
                            <label class="flex gap-2 items-start cursor-pointer">
                                <input id="wl-gdpr" type="checkbox" class="mt-0.5 accent-forest">
                                <span class="text-xs text-forest/70"><?= t('book.gdpr_waitlist') ?></span>
                            </label>
                            <div id="wl-error" class="hidden text-xs text-red-600 bg-red-50 rounded-lg px-3 py-2"></div>
                            <button id="wl-submit" onclick="submitWaitlist()"
                                class="w-full bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-xl py-3 text-sm transition-colors">
                                <?= t('book.waitlist_submit') ?>
                            </button>
                        </div>
                    </div>
                    <div id="waitlist-done" class="hidden mt-6 bg-green-50 border border-green-200 rounded-2xl p-5 text-center">
                        <div class="text-2xl mb-2">✓</div>
                        <p class="text-sm font-semibold text-green-800"><?= t('book.waitlist_done_title') ?></p>
                        <p class="text-xs text-green-700 mt-1"><?= t('book.waitlist_done_msg') ?></p>
                    </div>
                </div>
                <div id="slots-grid" class="hidden grid grid-cols-3 sm:grid-cols-4 gap-3"></div>

                <!-- Legenda -->
                <div id="slots-legend" class="hidden mt-3 text-xs text-forest/50" style="display:none">
                    <span id="legend-waitlist" style="display:none;align-items:center;gap:6px">
                        <span style="display:inline-block;width:12px;height:12px;border-radius:3px;border:2px solid #F59E0B;background:#FFFBEB"></span> <?= t('book.slots_legend_waitlist') ?>
                    </span>
                </div>

                <!-- Obvestilo ob kliku na waitlist termin (samo notice + Nadaljuj) -->
                <div id="slot-waitlist-panel" class="hidden mt-4 bg-amber-50 border border-amber-200 rounded-2xl p-5">
                    <p class="text-sm font-semibold text-amber-900 mb-1"><?= t('book.waitlist_slot_title') ?></p>
                    <p class="text-xs text-amber-700 mb-4" id="swl-desc-text"></p>
                    <button onclick="continueToWaitlist()"
                        class="w-full bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-xl py-3 text-sm transition-colors">
                        <?= t('book.continue') ?>
                    </button>
                </div>
            </div>

            <!-- ── Korak 3b: Izbira cone (opcijsko) ── -->
            <div id="step-3b" class="step">
                <div class="flex items-center gap-3 mb-2">
                    <button onclick="goStep(3)" class="text-forest/50 hover:text-forest transition-colors">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <h2 class="text-2xl font-bold text-forest"><?= t('book.step3b_title') ?></h2>
                </div>
                <p id="step3b-subtitle" class="text-sm text-forest/60 mb-6 ml-9"></p>

                <div id="area-loading" class="text-center py-10 text-forest/40">
                    <svg class="animate-spin mx-auto mb-3" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                    </svg>
                    <?= t('book.areas_loading') ?>
                </div>
                <div id="area-btns" class="hidden space-y-3"></div>
            </div>

            <!-- ── Korak 4: Podatki ── -->
            <div id="step-4" class="step">
                <div class="flex items-center gap-3 mb-2">
                    <button onclick="state.restaurant?.allow_area_choice ? goStep('3b') : goStep(3)" class="text-forest/50 hover:text-forest transition-colors">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <h2 class="text-2xl font-bold text-forest"><?= t('book.step4_title') ?></h2>
                </div>
                <p id="step4-subtitle" class="text-sm text-forest/60 mb-6 ml-9"></p>

                <!-- Obvestilo za čakalno listo (prikazano samo v waitlist načinu) -->
                <div id="step4-waitlist-notice" class="hidden bg-amber-50 border border-amber-200 rounded-2xl px-4 py-3 text-sm text-amber-800 mb-4">
                    <strong><?= t('book.waitlist_notice_prefix') ?></strong> <span id="step4-waitlist-time" class="font-semibold"></span> – <?= t('book.waitlist_notice_desc') ?>
                </div>

                <div id="form-error" class="hidden bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm mb-4"></div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-forest mb-1.5"><?= t('book.full_name') ?> <span class="text-terracotta">*</span></label>
                        <input id="f-name" type="text" autocomplete="name"
                            class="w-full border border-sage-light rounded-xl px-4 py-3 text-forest focus:outline-none focus:border-forest transition-colors"
                            placeholder="<?= t('book.full_name_placeholder') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-forest mb-1.5"><?= t('book.email') ?> <span class="text-terracotta">*</span></label>
                        <input id="f-email" type="email" autocomplete="email"
                            class="w-full border border-sage-light rounded-xl px-4 py-3 text-forest focus:outline-none focus:border-forest transition-colors"
                            placeholder="<?= t('book.email_placeholder') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-forest mb-1.5"><?= t('book.phone') ?> <span class="text-forest/40 font-normal"><?= t('common.optional') ?></span></label>
                        <input id="f-phone" type="tel" autocomplete="tel"
                            class="w-full border border-sage-light rounded-xl px-4 py-3 text-forest focus:outline-none focus:border-forest transition-colors"
                            placeholder="<?= t('book.phone_placeholder') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-forest mb-1.5"><?= t('book.notes') ?> <span class="text-forest/40 font-normal"><?= t('common.optional') ?></span></label>
                        <textarea id="f-notes" rows="3"
                            class="w-full border border-sage-light rounded-xl px-4 py-3 text-forest focus:outline-none focus:border-forest transition-colors resize-none"
                            placeholder="<?= t('book.notes_placeholder') ?>"></textarea>
                    </div>

                    <!-- Polja po meri (dinamično vstavljeno) -->
                    <div id="custom-fields-wrap"></div>

                    <!-- GDPR soglasje -->
                    <div class="pt-2 space-y-2">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" id="gdpr-consent"
                                class="mt-1 flex-shrink-0 w-4 h-4 accent-forest"
                                onchange="updateSubmitBtn()">
                            <span class="text-xs text-forest/70 leading-relaxed">
                                <?= t('book.gdpr_consent') ?>
                                <a href="<?= BASE_PATH ?>/pages/privacy.php" target="_blank"
                                   class="text-forest underline underline-offset-2 hover:text-forest/70"><?= t('common.privacy_policy') ?></a>. <span class="text-terracotta">*</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" id="marketing-consent"
                                class="mt-1 flex-shrink-0 w-4 h-4 accent-forest">
                            <span class="text-xs text-forest/70 leading-relaxed">
                                <?= t('book.marketing_consent') ?>
                            </span>
                        </label>
                    </div>
                </div>

                <button id="btn-submit" disabled
                    class="w-full mt-6 bg-terracotta hover:bg-terracotta-hover text-white py-4 rounded-2xl font-semibold text-lg transition-colors
                           disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                    <span id="btn-submit-text"><?= t('book.submit') ?></span>
                    <svg id="btn-submit-spin" class="hidden animate-spin" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                    </svg>
                </button>
            </div>

            <!-- ── Korak 5: Potrditev ── -->
            <div id="step-5" class="step">
                <div class="text-center py-8">
                    <div id="confirm-icon" class="text-6xl mb-6"></div>
                    <h2 id="confirm-title" class="text-2xl font-bold text-forest mb-3"></h2>
                    <p id="confirm-msg" class="text-forest/60 leading-relaxed mb-4"></p>

                    <!-- Povzetek rezervacije -->
                    <div id="confirm-summary" class="bg-white border border-sage-light rounded-2xl p-5 mb-8 text-left">
                        <div class="text-xs font-semibold text-forest/40 uppercase tracking-wider mb-3"><?= t('book.summary_title') ?></div>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-forest/60"><?= t('book.summary_restaurant') ?></span>
                                <span id="cs-rest" class="font-medium text-forest"></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-forest/60"><?= t('book.summary_date') ?></span>
                                <span id="cs-date" class="font-medium text-forest"></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-forest/60"><?= t('book.summary_time') ?></span>
                                <span id="cs-time" class="font-medium text-forest"></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-forest/60"><?= t('book.summary_guests') ?></span>
                                <span id="cs-guests" class="font-medium text-forest"></span>
                            </div>
                        </div>
                    </div>

                    <button onclick="resetBooking()"
                        class="w-full border-2 border-forest text-forest py-3.5 rounded-2xl font-semibold transition-colors hover:bg-forest hover:text-white">
                        <?= t('book.new_booking') ?>
                    </button>
                </div>
            </div>

        </div>
        </div>
    </main>

    <?php if (empty($_bkBranding['hide_branding'])): ?>
    <?php
        $_attribLabels = [
            'sl' => 'Brez skrbi z', 'en' => 'Powered by', 'de' => 'Bereitgestellt von',
            'es' => 'Funciona con', 'fr' => 'Propulsé par', 'hr' => 'Pokreće',
            'it' => 'Powered by',  'pt' => 'Com tecnologia',
        ];
        $_attribLabel = $_attribLabels[get_lang()] ?? $_attribLabels['en'];
    ?>
    <div class="rz-attribution"><?= htmlspecialchars($_attribLabel) ?> <a href="https://rezble.com" target="_blank" rel="noopener">Rezble</a></div>
    <?php endif; ?>
</div>

<script>
const TOKEN   = <?= json_encode($token) ?>;
const API_URL = <?= json_encode(APP_URL . BASE_PATH . '/api/book.php') ?>;
const USER_LANG = <?= json_encode(get_lang()) ?>;

// ── i18n ──────────────────────────────────────────────────────
const __T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
function t(key, p) {
    var s = (__T__[key] != null) ? __T__[key] : key;
    if (p) { for (var k in p) { s = s.replace(new RegExp('\\{'+k+'\\}','g'), p[k]); } }
    return s;
}

// ── State ─────────────────────────────────────────────────────
const state = {
    restaurant:    null,
    guests:        null,
    date:          null,
    time:          null,
    areaId:        null,   // izbrana cona (null = vseeno mi je)
    calYear:       new Date().getFullYear(),
    calMonth:      new Date().getMonth(), // 0-based
    _isWaitlist:   false,
    _waitlistTime: null,
};

const MONTHS  = <?= lang_months_js() ?>;
const DAYS_SL = <?= lang_days_js() ?>;

// ── Init ──────────────────────────────────────────────────────
(async () => {
    try {
        const res  = await fetch(`${API_URL}?t=${encodeURIComponent(TOKEN)}&lang=${encodeURIComponent(USER_LANG)}`);
        const json = await res.json();
        if (!json.success) throw new Error(json.error || t('common.error'));
        state.restaurant = json.data;
        document.getElementById('rest-name').textContent = json.data.name;
        // Če nimamo logo_url-ja (non-premium) postavi inicialko restavracije v kvadratek.
        const initEl = document.getElementById('rest-initial');
        if (initEl) initEl.textContent = (json.data.name || 'R')[0].toUpperCase();
        document.getElementById('booking-main').classList.remove('hidden');
        buildGuestButtons();
        renderCalendar();
        renderCustomFields(json.data.custom_fields || []);
    } catch (e) {
        document.getElementById('load-error-msg').textContent = e.message;
        document.getElementById('load-error').classList.remove('hidden');
        document.getElementById('load-error').classList.add('flex');
    }
})();

// ── Gostje ────────────────────────────────────────────────────
function buildGuestButtons() {
    const { min_guests, max_guests } = state.restaurant;
    const wrap = document.getElementById('guest-btns');
    wrap.innerHTML = '';

    // Vedno prikaži največ 10 gumbov
    const showUpTo = Math.min(10, max_guests);
    const hasMore  = max_guests > 10;

    for (let n = min_guests; n <= showUpTo; n++) {
        const btn = document.createElement('button');
        btn.className = 'guest-btn w-14 h-14 rounded-2xl border-2 border-sage-light text-forest font-bold text-lg';
        btn.textContent = n;
        btn.onclick = () => {
            selectGuests(n, btn);
            goStep(2); // takoj naprej
        };
        wrap.appendChild(btn);
    }

    if (hasMore) {
        // Gumb "Več"
        const more = document.createElement('button');
        more.id = 'btn-guests-more';
        more.className = 'guest-btn px-4 h-14 rounded-2xl border-2 border-sage-light text-forest font-medium text-base';
        more.textContent = t('book.more_guests_btn');
        more.onclick = () => toggleMoreGuests();
        wrap.appendChild(more);

        const inp = document.getElementById('guest-more-input');
        inp.min = 11;
        inp.max = max_guests;
        const lbl = document.getElementById('guest-more-label');
        if (lbl) lbl.textContent = t('book.enter_guests_range', { max: max_guests });
        inp.placeholder = '11';
        inp.addEventListener('input', () => {
            const v = parseInt(inp.value);
            if (v >= 11 && v <= max_guests) {
                selectGuests(v, document.getElementById('btn-guests-more'));
            } else {
                clearGuestSelection();
                if (v > max_guests) inp.value = max_guests;
            }
        });

        // Naprej gumb samo za "Več" primer
        document.getElementById('btn-guests-next').style.display = '';
    } else {
        // Brez "Več" — skrij Naprej gumb (klik na gumb takoj naprej)
        document.getElementById('guest-more-wrap').classList.add('hidden');
        document.getElementById('btn-guests-next').style.display = 'none';
    }
}

let moreOpen = false;
function toggleMoreGuests() {
    moreOpen = !moreOpen;
    document.getElementById('guest-more-wrap').classList.toggle('hidden', !moreOpen);
    const btn = document.getElementById('btn-guests-more');
    if (moreOpen) {
        clearGuestSelection();
        btn.classList.add('selected');
        document.getElementById('guest-more-input').focus();
    } else {
        btn.classList.remove('selected');
        clearGuestSelection();
    }
}

function clearGuestSelection() {
    state.guests = null;
    document.querySelectorAll('.guest-btn').forEach(b => b.classList.remove('selected'));
    document.getElementById('btn-guests-next').disabled = true;
}

function selectGuests(n, btn) {
    state.guests = n;
    document.querySelectorAll('.guest-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    if (btn.id !== 'btn-guests-more') {
        moreOpen = false;
        document.getElementById('guest-more-wrap').classList.add('hidden');
    }
    document.getElementById('btn-guests-next').disabled = false;
}

document.getElementById('btn-guests-next').onclick = () => {
    if (state.guests) goStep(2);
};

// ── Kalendar ──────────────────────────────────────────────────
function calMove(dir) {
    state.calMonth += dir;
    if (state.calMonth > 11) { state.calMonth = 0; state.calYear++; }
    if (state.calMonth < 0)  { state.calMonth = 11; state.calYear--; }
    renderCalendar();
}

function renderCalendar() {
    const year  = state.calYear;
    const month = state.calMonth;
    const today = new Date(); today.setHours(0,0,0,0);

    document.getElementById('cal-title').textContent = `${MONTHS[month]} ${year}`;

    // Gumb "prejšnji" – onemogoči za pretekli mesec
    const isCurrentMonth = year === today.getFullYear() && month === today.getMonth();
    document.getElementById('cal-prev').style.opacity  = isCurrentMonth ? '.25' : '1';
    document.getElementById('cal-prev').style.pointerEvents = isCurrentMonth ? 'none' : '';

    const firstDay = new Date(year, month, 1);
    const lastDay  = new Date(year, month + 1, 0);

    // Ponedeljek = 0
    let startDow = firstDay.getDay() - 1;
    if (startDow < 0) startDow = 6;

    const grid = document.getElementById('cal-grid');
    grid.innerHTML = '';

    // Prazne celice pred 1.
    for (let i = 0; i < startDow; i++) {
        grid.appendChild(Object.assign(document.createElement('div'), { className: 'cal-day' }));
    }

    const openDays      = state.restaurant.open_days;
    const blackoutDates = state.restaurant.blackout_dates || [];

    for (let d = 1; d <= lastDay.getDate(); d++) {
        const date       = new Date(year, month, d);
        const dow        = (date.getDay() + 6) % 7; // 0=Pon
        const dateStr    = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        const isPast     = date < today;
        const isOpen     = (openDays >> dow) & 1;
        const isBlackout = blackoutDates.includes(dateStr);
        const isToday    = date.toDateString() === today.toDateString();
        const isSelected = dateStr === state.date;

        const cell = document.createElement('div');
        cell.textContent = d;

        if (isPast) {
            cell.className = `cal-day disabled${isToday ? ' today' : ''}`;
        } else if (isBlackout) {
            // Popolnoma blokiran datum – ni klika
            cell.className = `cal-day disabled${isToday ? ' today' : ''}`;
        } else if (!isOpen) {
            // Izklopljen dan v tednu – ni klika (brez čakalne liste)
            cell.className = `cal-day disabled${isToday ? ' today' : ''}`;
        } else {
            cell.className = `cal-day available${isToday ? ' today' : ''}${isSelected ? ' selected' : ''}`;
            cell.onclick   = () => selectDate(dateStr);
        }
        grid.appendChild(cell);
    }
}

function selectDate(dateStr) {
    state.date = dateStr;
    renderCalendar();
    goStep(3);
    loadSlots(dateStr);
}

function selectDateWaitlist(dateStr) {
    state.date = dateStr;
    renderCalendar();

    // Pokaži step 3 z direktno waitlist ponudbo (brez nalaganja terminov)
    document.getElementById('slots-loading').classList.add('hidden');
    document.getElementById('slots-grid').classList.add('hidden');

    const slotsEmpty   = document.getElementById('slots-empty');
    const waitlistOffer = document.getElementById('waitlist-offer');
    const waitlistDone  = document.getElementById('waitlist-done');

    slotsEmpty.classList.remove('hidden');
    if (waitlistOffer) {
        waitlistOffer.classList.remove('hidden');
        if (waitlistDone) waitlistDone.classList.add('hidden');
        ['wl-first','wl-last','wl-email','wl-phone','wl-time'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        const gdpr = document.getElementById('wl-gdpr');
        if (gdpr) gdpr.checked = false;
        const err = document.getElementById('wl-error');
        if (err) err.classList.add('hidden');
    }

    // Posodobi podnaslov
    const d = new Date(dateStr + 'T12:00:00');
    const dow = DAYS_SL[(d.getDay() + 6) % 7];
    document.getElementById('step3-subtitle').textContent =
        `${dow}, ${d.getDate()}. ${MONTHS[d.getMonth()]} ${d.getFullYear()} · ${t('book.no_slots_date_suffix')}`;

    goStep(3);
}

// ── Termini ───────────────────────────────────────────────────
async function loadSlots(date) {
    document.getElementById('slots-loading').classList.remove('hidden');
    document.getElementById('slots-empty').classList.add('hidden');
    document.getElementById('slots-grid').classList.add('hidden');

    try {
        const res  = await fetch(`${API_URL}?t=${encodeURIComponent(TOKEN)}&date=${date}&guest_count=${state.guests || 1}&lang=${encodeURIComponent(USER_LANG)}`);
        const json = await res.json();
        if (!json.success) throw new Error(json.error);

        const slots = json.data.slots || [];
        document.getElementById('slots-loading').classList.add('hidden');

        // Skrij inline waitlist panel ob novem nalaganju
        document.getElementById('slot-waitlist-panel')?.classList.add('hidden');
        state._isWaitlist = false;

        if (slots.length === 0) {
            document.getElementById('slots-empty').classList.remove('hidden');
            // Ponudi čakalno listo če je na voljo (splošna – brez termina)
            const waitlistOffer = document.getElementById('waitlist-offer');
            const waitlistDone  = document.getElementById('waitlist-done');
            if (waitlistOffer && state.restaurant?.waitlist_enabled) {
                waitlistOffer.classList.remove('hidden');
                if (waitlistDone) waitlistDone.classList.add('hidden');
                ['wl-first','wl-last','wl-email','wl-phone','wl-time'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.value = '';
                });
                const gdpr = document.getElementById('wl-gdpr');
                if (gdpr) gdpr.checked = false;
                const err = document.getElementById('wl-error');
                if (err) err.classList.add('hidden');
            }
            return;
        }

        // Ko so termini na voljo, skrij splošno waitlist ponudbo
        const wo = document.getElementById('waitlist-offer');
        if (wo) wo.classList.add('hidden');

        const grid = document.getElementById('slots-grid');
        grid.innerHTML = '';
        let hasWaitlist = false;

        slots.forEach(slot => {
            const time   = typeof slot === 'string' ? slot : slot.time;
            const status = typeof slot === 'string' ? 'available' : (slot.status || 'available');

            const btn = document.createElement('button');

            if (status === 'full') {
                btn.className = 'slot-btn full border-2 border-sage-light rounded-xl py-3 text-forest font-semibold text-sm';
                btn.textContent = time;
                btn.disabled = true;
                btn.title = t('book.slot_full_title');
            } else if (status === 'waitlist') {
                hasWaitlist = true;
                btn.className = 'slot-btn waitlist border-2 rounded-xl py-3 font-semibold text-sm';
                btn.title = t('book.slot_waitlist_title');
                btn.innerHTML = time + ' <span style="font-size:.65rem;display:block;font-weight:500">' + t('book.slot_waitlist_badge') + '</span>';
                btn.onclick = () => selectWaitlistSlot(time, btn);
            } else {
                btn.className = 'slot-btn border-2 border-sage-light rounded-xl py-3 text-forest font-semibold text-sm hover:border-forest';
                btn.textContent = time;
                btn.onclick = () => selectSlot(time, btn);
            }

            grid.appendChild(btn);
        });

        grid.classList.remove('hidden');

        // Legenda
        const legend = document.getElementById('slots-legend');
        const legendWl = document.getElementById('legend-waitlist');
        if (legend)   legend.style.display   = hasWaitlist ? 'flex'        : 'none';
        if (legendWl) legendWl.style.display = hasWaitlist ? 'inline-flex' : 'none';

        // Podnaslov
        const d = new Date(date + 'T12:00:00');
        const dow = DAYS_SL[(d.getDay() + 6) % 7];
        document.getElementById('step3-subtitle').textContent =
            `${dow}, ${d.getDate()}. ${MONTHS[d.getMonth()]} ${d.getFullYear()} · ${state.guests} ${guestLabel(state.guests)}`;

    } catch (e) {
        document.getElementById('slots-loading').classList.add('hidden');
        document.getElementById('slots-empty').classList.remove('hidden');
    }
}

function selectSlot(time, btn) {
    state.time = time;
    state.areaId = null;
    document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    state._isWaitlist = false;
    document.getElementById('slot-waitlist-panel')?.classList.add('hidden');

    if (state.restaurant?.allow_area_choice) {
        setTimeout(() => loadAreas(state.date, time), 200);
    } else {
        setTimeout(() => goStep(4), 200);
    }
}

async function loadAreas(date, time) {
    const areaLoading = document.getElementById('area-loading');
    const areaBtns    = document.getElementById('area-btns');
    areaLoading.classList.remove('hidden');
    areaBtns.classList.add('hidden');
    areaBtns.innerHTML = '';

    goStep('3b');

    // Podnaslov
    const d = new Date(date + 'T12:00:00');
    const dow = DAYS_SL[(d.getDay() + 6) % 7];
    document.getElementById('step3b-subtitle').textContent =
        `${dow}, ${d.getDate()}. ${MONTHS[d.getMonth()]} ${d.getFullYear()} · ${time} · ${state.guests} ${guestLabel(state.guests)}`;

    try {
        const res  = await fetch(`${API_URL}?t=${encodeURIComponent(TOKEN)}&date=${date}&time=${time}&guest_count=${state.guests || 1}&lang=${encodeURIComponent(USER_LANG)}`);
        const json = await res.json();
        if (!json.success) throw new Error(json.error);

        const areas = json.data?.areas || [];
        areaLoading.classList.add('hidden');

        // Gumb "Vseeno mi je" (vedno na vrhu)
        const anyBtn = document.createElement('button');
        anyBtn.className = 'w-full text-left border-2 border-sage-light rounded-2xl px-5 py-4 hover:border-forest transition-colors';
        anyBtn.innerHTML = `<div class="font-semibold text-forest">${t('book.area_any')}</div>
            <div class="text-sm text-forest/50 mt-0.5">${t('book.area_any_desc')}</div>`;
        anyBtn.onclick = () => selectArea(null, anyBtn);
        areaBtns.appendChild(anyBtn);

        areas.forEach(area => {
            const btn = document.createElement('button');
            const disabled = !area.available;
            btn.className = `w-full text-left border-2 rounded-2xl px-5 py-4 transition-colors ${
                disabled ? 'border-sage-light opacity-40 cursor-not-allowed' : 'border-sage-light hover:border-forest cursor-pointer'
            }`;
            btn.disabled = disabled;
            btn.innerHTML = `<div class="font-semibold text-forest">${escHtml(area.name)}</div>
                ${disabled ? '<div class="text-sm text-forest/40 mt-0.5">' + t('book.area_unavailable') + '</div>' : ''}`;
            if (!disabled) btn.onclick = () => selectArea(area.id, btn);
            areaBtns.appendChild(btn);
        });

        areaBtns.classList.remove('hidden');

        // Če ni nobene cone definirane, preskočimo ta korak
        if (!areas.length) {
            goStep(4);
            return;
        }
    } catch (e) {
        // Napaka – preskočimo izbiro cone in nadaljujemo
        goStep(4);
    }
}

function selectArea(areaId, btn) {
    state.areaId = areaId;
    document.querySelectorAll('#area-btns button').forEach(b => b.classList.remove('border-forest', 'bg-cream'));
    btn.classList.add('border-forest', 'bg-cream');
    setTimeout(() => goStep(4), 200);
}

function selectWaitlistSlot(time, btn) {
    // Označi gumb
    document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');

    state._waitlistTime = time;
    state._isWaitlist   = false; // še ni potrjeno, samo notice

    const descEl = document.getElementById('swl-desc-text');
    if (descEl) descEl.textContent = t('book.waitlist_slot_desc', { time });
    const label = document.getElementById('swl-time-label');
    if (label) label.textContent = time;

    const panel = document.getElementById('slot-waitlist-panel');
    if (panel) {
        panel.classList.remove('hidden');
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

function continueToWaitlist() {
    state._isWaitlist = true;
    document.getElementById('slot-waitlist-panel')?.classList.add('hidden');
    goStep(4);
}

// ── Čakalna lista ─────────────────────────────────────────────
const WAITLIST_API = <?= json_encode(APP_URL . BASE_PATH . '/api/waitlist.php') ?>;

async function submitWaitlist() {
    const firstName = document.getElementById('wl-first')?.value.trim();
    const lastName  = document.getElementById('wl-last')?.value.trim();
    const email     = document.getElementById('wl-email')?.value.trim();
    const phone     = document.getElementById('wl-phone')?.value.trim();
    const timePref  = document.getElementById('wl-time')?.value.trim();
    const gdpr      = document.getElementById('wl-gdpr')?.checked;
    const errEl     = document.getElementById('wl-error');

    const showErr = (msg) => {
        errEl.textContent = msg;
        errEl.classList.remove('hidden');
    };

    if (!firstName) return showErr(t('book.err_first_name'));
    if (!lastName)  return showErr(t('book.err_last_name'));
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return showErr(t('book.err_email_short'));
    if (!gdpr)      return showErr(t('book.err_gdpr_short'));

    errEl.classList.add('hidden');
    const btn = document.getElementById('wl-submit');
    btn.disabled = true;
    btn.textContent = t('common.sending');

    try {
        const res  = await fetch(`${WAITLIST_API}?t=${encodeURIComponent(TOKEN)}`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                date:        state.date,
                time_pref:   timePref || '',
                guests:      state.guests || 1,
                first_name:  firstName,
                last_name:   lastName,
                email,
                phone:       phone || '',
                gdpr_consent: true,
                lang:        USER_LANG,
            }),
        });
        const json = await res.json();
        if (!json.success) throw new Error(json.error || t('book.err_server'));

        document.getElementById('waitlist-offer').classList.add('hidden');
        document.getElementById('waitlist-done').classList.remove('hidden');
    } catch (e) {
        showErr(e.message);
        btn.disabled = false;
        btn.textContent = t('book.waitlist_submit');
    }
}

// ── Korak 4 – podnaslov ───────────────────────────────────────
function updateStep4Subtitle() {
    const displayTime = state._isWaitlist ? state._waitlistTime : state.time;
    if (!state.date) return;
    const d   = new Date(state.date + 'T12:00:00');
    const dow = DAYS_SL[(d.getDay() + 6) % 7];
    const timeStr = displayTime ? ` · ${displayTime}` : '';
    document.getElementById('step4-subtitle').textContent =
        `${dow}, ${d.getDate()}. ${MONTHS[d.getMonth()]}${timeStr} · ${state.guests} ${guestLabel(state.guests)}`;
}

// ── Custom fields rendering ───────────────────────────────────
function renderCustomFields(fields) {
    const wrap = document.getElementById('custom-fields-wrap');
    if (!wrap || !fields || !fields.length) return;
    wrap.innerHTML = '';
    fields.forEach(f => {
        const div = document.createElement('div');
        const reqLabel = f.is_required ? '<span class="text-terracotta"> *</span>' : '<span class="text-forest/40 font-normal"> (neobvezno)</span>';
        if (f.field_type === 'checkbox') {
            div.innerHTML = `
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" id="cf-${f.id}" data-cfid="${f.id}"
                        class="w-5 h-5 rounded border-sage-light text-forest cursor-pointer"
                        style="accent-color:#1B4332">
                    <span class="text-sm font-medium text-forest">${escHtml(f.label)}</span>
                </label>`;
        } else if (f.field_type === 'select' && f.options && f.options.length) {
            const opts = f.options.map(o => `<option value="${escHtml(o)}">${escHtml(o)}</option>`).join('');
            div.innerHTML = `
                <label class="block text-sm font-medium text-forest mb-1.5">${escHtml(f.label)}${reqLabel}</label>
                <select id="cf-${f.id}" data-cfid="${f.id}" ${f.is_required ? 'required' : ''}
                    class="w-full border border-sage-light rounded-xl px-4 py-3 text-forest focus:outline-none focus:border-forest transition-colors">
                    <option value="">— Izberite —</option>
                    ${opts}
                </select>`;
        } else {
            div.innerHTML = `
                <label class="block text-sm font-medium text-forest mb-1.5">${escHtml(f.label)}${reqLabel}</label>
                <input type="text" id="cf-${f.id}" data-cfid="${f.id}" ${f.is_required ? 'required' : ''}
                    class="w-full border border-sage-light rounded-xl px-4 py-3 text-forest focus:outline-none focus:border-forest transition-colors">`;
        }
        wrap.appendChild(div);
    });
}

function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function collectCustomFields() {
    const cf = {};
    document.querySelectorAll('#custom-fields-wrap [data-cfid]').forEach(el => {
        const id = el.dataset.cfid;
        if (el.type === 'checkbox') {
            cf[id] = el.checked ? '1' : '0';
        } else {
            cf[id] = el.value.trim();
        }
    });
    return cf;
}

function validateCustomFields() {
    const fields = state.restaurant?.custom_fields || [];
    for (const f of fields) {
        if (!f.is_required) continue;
        const el = document.getElementById('cf-' + f.id);
        if (!el) continue;
        if (el.type === 'checkbox') continue;
        if (!el.value.trim()) {
            showFormErr(t('book.err_field_required', { label: f.label }));
            el.focus();
            return false;
        }
    }
    return true;
}

// ── GDPR consent gating ───────────────────────────────────────
function updateSubmitBtn() {
    const consented = document.getElementById('gdpr-consent')?.checked;
    document.getElementById('btn-submit').disabled = !consented;
}

// ── Submit ────────────────────────────────────────────────────
document.getElementById('btn-submit').onclick = async () => {
    const name      = document.getElementById('f-name').value.trim();
    const email     = document.getElementById('f-email').value.trim();
    const phone     = document.getElementById('f-phone').value.trim();
    const notes     = document.getElementById('f-notes').value.trim();
    const gdprOk    = document.getElementById('gdpr-consent')?.checked;
    const marketing = document.getElementById('marketing-consent')?.checked;

    if (!name)   { showFormErr(t('book.err_name')); return; }
    if (!email)  { showFormErr(t('book.err_email_required')); return; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showFormErr(t('book.err_email_invalid')); return; }
    if (!gdprOk) { showFormErr(t('book.err_gdpr')); return; }

    document.getElementById('form-error').classList.add('hidden');

    // ── Čakalna lista ──
    if (state._isWaitlist) {
        setSubmitting(true);
        const spaceIdx  = name.indexOf(' ');
        const firstName = spaceIdx >= 0 ? name.substring(0, spaceIdx).trim()  : name;
        const lastName  = spaceIdx >= 0 ? name.substring(spaceIdx + 1).trim() : '';
        try {
            const res  = await fetch(`${WAITLIST_API}?t=${encodeURIComponent(TOKEN)}`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({
                    date:         state.date,
                    time_pref:    state._waitlistTime || '',
                    guests:       state.guests,
                    first_name:   firstName,
                    last_name:    lastName,
                    email, phone,
                    gdpr_consent: true,
                    lang:         USER_LANG,
                }),
            });
            const json = await res.json();
            if (!json.success) throw new Error(json.error || t('book.err_server'));
            showWaitlistConfirmation();
        } catch (e) {
            showFormErr(e.message);
            setSubmitting(false);
        }
        return;
    }

    // ── Navadna rezervacija ──
    if (!validateCustomFields()) return;

    const customFields = collectCustomFields();

    setSubmitting(true);
    try {
        const res  = await fetch(`${API_URL}?t=${encodeURIComponent(TOKEN)}`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                date: state.date, time: state.time,
                guest_name: name, email, phone, notes,
                guest_count: state.guests,
                area_id: state.areaId,
                custom_fields: customFields,
                gdpr_consent: true,
                marketing_consent: marketing ? true : false,
                lang: USER_LANG,
            }),
        });
        const json = await res.json();
        if (!json.success) throw new Error(json.error || t('book.err_server'));
        showConfirmation(json.data.auto_confirm);
    } catch (e) {
        showFormErr(e.message);
        setSubmitting(false);
    }
};

function showFormErr(msg) {
    const el = document.getElementById('form-error');
    el.textContent = msg;
    el.classList.remove('hidden');
}

function setSubmitting(loading) {
    const btn  = document.getElementById('btn-submit');
    const text = document.getElementById('btn-submit-text');
    btn.disabled = loading;
    if (loading) {
        text.textContent = t('common.sending');
    } else {
        text.textContent = state._isWaitlist ? t('book.submit_waitlist') : t('book.submit');
    }
    document.getElementById('btn-submit-spin').classList.toggle('hidden', !loading);
}

function showWaitlistConfirmation() {
    const d   = new Date(state.date + 'T12:00:00');
    const dow = DAYS_SL[(d.getDay() + 6) % 7];
    const dateStr = `${dow}, ${d.getDate()}. ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;

    document.getElementById('confirm-icon').textContent  = t('book.waitlist_confirm_icon');
    document.getElementById('confirm-title').textContent = t('book.waitlist_confirm_title');
    document.getElementById('confirm-msg').textContent   = t('book.waitlist_confirm_msg');

    document.getElementById('cs-rest').textContent   = state.restaurant.name;
    document.getElementById('cs-date').textContent   = dateStr;
    document.getElementById('cs-time').textContent   = state._waitlistTime || '—';
    document.getElementById('cs-guests').textContent = `${state.guests} ${guestLabel(state.guests)}`;

    goStep(5);
}

// ── Potrditev ─────────────────────────────────────────────────
function showConfirmation(autoConfirm) {
    if (window.posthog) {
        window.posthog.capture('booking_completed', {
            auto_confirmed: !!autoConfirm,
            guests:         state.guests,
            date:           state.date,
            has_area:       !!state.areaId,
        });
    }
    const d   = new Date(state.date + 'T12:00:00');
    const dow = DAYS_SL[(d.getDay() + 6) % 7];
    const dateStr = `${dow}, ${d.getDate()}. ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;

    document.getElementById('confirm-icon').textContent  = autoConfirm ? t('book.confirm_auto_icon') : t('book.confirm_pending_icon');
    document.getElementById('confirm-title').textContent = autoConfirm ? t('book.confirm_auto_title') : t('book.confirm_pending_title');
    document.getElementById('confirm-msg').textContent   = autoConfirm ? t('book.confirm_auto_msg') : t('book.confirm_pending_msg');

    document.getElementById('cs-rest').textContent   = state.restaurant.name;
    document.getElementById('cs-date').textContent   = dateStr;
    document.getElementById('cs-time').textContent   = state.time;
    document.getElementById('cs-guests').textContent = `${state.guests} ${guestLabel(state.guests)}`;

    goStep(5);
}

// ── Navigacija med koraki ─────────────────────────────────────
function goStep(n) {
    document.querySelectorAll('.step').forEach(el => el.classList.remove('active'));
    document.getElementById(`step-${n}`).classList.add('active');
    if (window.posthog) {
        window.posthog.capture('booking_step_viewed', { step: String(n) });
    }

    // Step '3b' se mapira na vizualni korak 3 v progress indikatorju
    const progressStep = (n === '3b') ? 3 : n;

    // Progress indikator
    for (let i = 1; i <= 4; i++) {
        const num = document.querySelector(`.step-num-${i}`);
        const lbl = document.querySelector(`.step-lbl-${i}`);
        const done = i < progressStep;
        const active = i === progressStep;
        num.className = `step-num-${i} w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold ` +
            (done ? 'bg-sage text-white' : active ? 'bg-forest text-white' : 'bg-sage-light text-forest/40');
        if (lbl) lbl.className = `step-lbl-${i} text-xs font-medium hidden sm:block ` +
            (done || active ? 'text-forest' : 'text-forest/40');
        if (done) num.textContent = '✓';
        else      num.textContent = i;
    }

    if (n === 4) {
        updateStep4Subtitle();
        // Prikaži/skrij waitlist obvestilo in prilagodi gumb
        const notice  = document.getElementById('step4-waitlist-notice');
        const wlTime  = document.getElementById('step4-waitlist-time');
        const submitText = document.getElementById('btn-submit-text');
        if (state._isWaitlist) {
            if (notice)   notice.classList.remove('hidden');
            if (wlTime)   wlTime.textContent = state._waitlistTime || '';
            if (submitText) submitText.textContent = t('book.submit_waitlist');
        } else {
            if (notice)   notice.classList.add('hidden');
            if (submitText) submitText.textContent = t('book.submit');
        }
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetBooking() {
    state.guests      = null;
    state.date        = null;
    state.time        = null;
    state.areaId      = null;
    state._isWaitlist = false;
    document.querySelectorAll('.guest-btn').forEach(b => b.classList.remove('selected'));
    document.getElementById('btn-guests-next').disabled = true;
    document.getElementById('guest-more-wrap').classList.add('hidden');
    document.getElementById('guest-more-input').value = '';
    document.getElementById('f-name').value  = '';
    document.getElementById('f-email').value = '';
    document.getElementById('f-phone').value = '';
    document.getElementById('f-notes').value = '';
    moreOpen = false;
    goStep(1);
}

// ── Helpers ───────────────────────────────────────────────────
function guestLabel(n) {
    if (n === 1) return t('book.guest_label_1');
    if (n < 5)   return t('book.guest_label_few');
    return t('book.guest_label_many');
}
</script>

<?php endif; ?>
<script>window.BASE_PATH = '<?= BASE_PATH ?>';</script>
<?php
require_once __DIR__ . '/includes/cookie_consent.php';
rez_consent_render(['surface' => 'book_public']);
?>
</body>
</html>
