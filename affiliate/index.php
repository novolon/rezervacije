<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/lang.php';
require_once '../includes/affiliate_session.php';

aff_session_start();
$loggedIn = aff_is_logged_in();

$appUrl  = APP_URL . BASE_PATH;
$affBase = $appUrl . '/affiliate';

// Privzete vrednosti programa (sinhronizirane z migrate_affiliate.sql)
$pdo = getDB();
$settings = [
    'default_commission_percent'  => '20.00',
    'default_commission_window_m' => '12',
    'default_hold_days'           => '45',
    'default_min_payout_eur'      => '30.00',
];
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM affiliate_settings WHERE setting_key IN ('default_commission_percent','default_commission_window_m','default_hold_days','default_min_payout_eur')")->fetchAll();
    foreach ($rows as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
} catch (\Throwable $e) { /* fallback na privzete */ }

$pct       = (int)round((float)$settings['default_commission_percent']);
$windowM   = (int)$settings['default_commission_window_m'];
$holdDays  = (int)$settings['default_hold_days'];
$minPayout = number_format((float)$settings['default_min_payout_eur'], 0, ',', '.');

// Cene paketov iz config.php (PLAN_PRICES) – single source of truth
$priceBasicM    = (float)PLAN_PRICES['basic']['monthly'];
$priceBasicY    = (float)PLAN_PRICES['basic']['yearly'];
$priceAdvM      = (float)PLAN_PRICES['advanced']['monthly'];
$priceAdvY      = (float)PLAN_PRICES['advanced']['yearly'];
$pricePremM     = (float)PLAN_PRICES['premium']['monthly'];
$pricePremY     = (float)PLAN_PRICES['premium']['yearly'];
function fmtEurSl(float $n): string { return number_format($n, 2, ',', '.'); }
// Provizije za mockup tabelo (20 % od mesečne cene)
$commBasic = round($priceBasicM * 0.20, 2);
$commAdv   = round($priceAdvM   * 0.20, 2);
$commPrem  = round($pricePremM  * 0.20, 2);

// Skupni placeholder-ji za prevode
$tParams = ['pct' => $pct, 'months' => $windowM, 'hold' => $holdDays, 'min' => $minPayout];
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
    <title><?= t('landing_aff.meta_title') ?></title>
    <meta name="description" content="<?= t('landing_aff.meta_description', $tParams) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    forest:    { DEFAULT: '#1B4332', light: '#2D6A4F', dark: '#081C15' },
                    cream:     { DEFAULT: '#FAFAF5', dark:  '#F0F0E6' },
                    terracotta:{ DEFAULT: '#C4704B', hover: '#A85D3B' },
                    sage:      { DEFAULT: '#A3B18A', light: '#DAD7CD' },
                },
                fontFamily: { sans: ['"DM Sans"', 'sans-serif'] },
            }
        }
    }
    </script>
    <style>
        html { scroll-behavior: smooth; }
        .faq-body { display: none; }
        .faq-body.open { display: block; }
        .faq-chevron { transition: transform .3s; }
        .faq-chevron.open { transform: rotate(180deg); }
    </style>
</head>
<body class="min-h-screen flex flex-col font-sans bg-cream text-forest">

<!-- ═══════════════════════════════════════════════════════════
     NAVBAR
════════════════════════════════════════════════════════════ -->
<nav class="fixed top-0 left-0 right-0 z-50 bg-cream/90 backdrop-blur-md border-b border-sage-light">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-20">
            <a href="<?= $affBase ?>/" class="flex items-center gap-2" aria-label="Rezble Affiliate">
                <img src="<?= $appUrl ?>/assets/images/Rezble.svg" alt="Rezble" style="height:30px;width:auto;display:block">
                <span class="text-forest/60 font-medium"><?= t('landing_aff.nav_logo_suffix') ?></span>
            </a>
            <div class="hidden md:flex items-center space-x-6">
                <a href="#how"     class="text-forest/80 hover:text-forest font-medium transition-colors"><?= t('landing_aff.nav_how') ?></a>
                <a href="#earnings" class="text-forest/80 hover:text-forest font-medium transition-colors"><?= t('landing_aff.nav_earnings') ?></a>
                <a href="#faq"     class="text-forest/80 hover:text-forest font-medium transition-colors"><?= t('landing_aff.nav_faq') ?></a>
                <?php if ($loggedIn): ?>
                <a href="<?= $affBase ?>/dashboard.php" class="bg-terracotta hover:bg-terracotta-hover text-white px-6 py-2.5 rounded-full font-medium transition-colors shadow-sm"><?= t('landing_aff.nav_dashboard') ?></a>
                <?php else: ?>
                <a href="<?= $affBase ?>/login.php"    class="text-forest/80 hover:text-forest font-medium transition-colors"><?= t('landing_aff.nav_login') ?></a>
                <a href="<?= $affBase ?>/register.php" class="bg-terracotta hover:bg-terracotta-hover text-white px-6 py-2.5 rounded-full font-medium transition-colors shadow-sm"><?= t('landing_aff.nav_become') ?></a>
                <?php endif; ?>
                <?php $langSwitcherTheme = 'light'; require __DIR__ . '/../includes/lang_switcher.php'; ?>
            </div>
            <button id="nav-toggle" class="md:hidden text-forest p-2">
                <svg id="nav-icon-menu" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                <svg id="nav-icon-close" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
    <div id="nav-mobile" class="md:hidden bg-cream border-b border-sage-light" style="display:none">
        <div class="px-4 pt-2 pb-6 flex flex-col space-y-4">
            <a href="#how"      class="text-forest font-medium py-2" onclick="closeMobileNav()"><?= t('landing_aff.nav_how') ?></a>
            <a href="#earnings" class="text-forest font-medium py-2" onclick="closeMobileNav()"><?= t('landing_aff.nav_earnings') ?></a>
            <a href="#faq"      class="text-forest font-medium py-2" onclick="closeMobileNav()"><?= t('landing_aff.nav_faq') ?></a>
            <div class="py-2"><?php $langSwitcherTheme = 'light'; require __DIR__ . '/../includes/lang_switcher.php'; ?></div>
            <?php if ($loggedIn): ?>
            <a href="<?= $affBase ?>/dashboard.php" class="bg-terracotta text-white px-6 py-3 rounded-full font-medium text-center mt-4" onclick="closeMobileNav()"><?= t('landing_aff.nav_dashboard') ?></a>
            <?php else: ?>
            <a href="<?= $affBase ?>/login.php"    class="text-forest font-medium py-2"><?= t('landing_aff.nav_login') ?></a>
            <a href="<?= $affBase ?>/register.php" class="bg-terracotta text-white px-6 py-3 rounded-full font-medium text-center mt-4" onclick="closeMobileNav()"><?= t('landing_aff.nav_become') ?></a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- ═══════════════════════════════════════════════════════════
     HERO
════════════════════════════════════════════════════════════ -->
<section class="pt-32 pb-20 md:pt-40 md:pb-24 overflow-hidden">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center max-w-3xl mx-auto mb-16">
            <span class="inline-flex items-center gap-2 px-4 py-1.5 bg-terracotta/10 text-terracotta font-semibold rounded-full text-sm mb-6">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <?= t('landing_aff.hero_badge') ?>
            </span>
            <h1 class="text-4xl md:text-6xl font-bold text-forest leading-tight mb-6">
                <?= t('landing_aff.hero_title_pre') ?> <span class="text-terracotta"><?= t('landing_aff.hero_title_pct', $tParams) ?></span> <?= t('landing_aff.hero_title_post') ?>
            </h1>
            <p class="text-lg md:text-xl text-forest/70 mb-10 leading-relaxed">
                <?= t('landing_aff.hero_subtitle', $tParams) ?>
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="<?= $affBase ?>/<?= $loggedIn ? 'dashboard.php' : 'register.php' ?>"
                   class="w-full sm:w-auto bg-terracotta hover:bg-terracotta-hover text-white px-8 py-4 rounded-full font-semibold text-lg transition-colors shadow-lg flex items-center justify-center gap-2">
                    <?= $loggedIn ? t('landing_aff.hero_cta_logged') : t('landing_aff.hero_cta_guest') ?>
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                </a>
                <a href="#how" class="w-full sm:w-auto px-8 py-4 rounded-full font-semibold text-lg text-forest/80 hover:text-forest transition-colors">
                    <?= t('landing_aff.hero_cta_how') ?>
                </a>
            </div>

            <!-- Trust badges -->
            <div class="mt-8 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm font-medium text-forest/70">
                <?php foreach (['landing_aff.hero_trust_1','landing_aff.hero_trust_2','landing_aff.hero_trust_3'] as $_key): ?>
                <span class="inline-flex items-center gap-1.5">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#2F7D52" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    <?= t($_key) ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Mockup affiliate dashboarda -->
        <div class="relative mx-auto max-w-5xl">
            <div class="bg-cream rounded-2xl shadow-2xl border border-sage-light overflow-hidden flex flex-col">
                <!-- Browser chrome -->
                <div class="bg-cream-dark/50 border-b border-sage-light px-4 py-3 flex items-center gap-2">
                    <div class="flex gap-1.5">
                        <div class="w-3 h-3 rounded-full bg-red-400"></div>
                        <div class="w-3 h-3 rounded-full bg-amber-400"></div>
                        <div class="w-3 h-3 rounded-full bg-green-400"></div>
                    </div>
                    <div class="mx-auto bg-white rounded-md px-12 md:px-32 py-1 text-xs text-forest/40 border border-sage-light">app.rezble.com/affiliate</div>
                </div>

                <div class="bg-cream p-5 md:p-8">
                    <!-- Hero stat row -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
                        <div class="bg-white border border-sage-light/70 rounded-xl px-4 py-3">
                            <div class="text-[10px] font-bold tracking-wider uppercase text-forest/50"><?= t('landing_aff.mockup.kpi_clicks') ?></div>
                            <div class="text-2xl font-bold text-forest">1.247</div>
                        </div>
                        <div class="bg-white border border-sage-light/70 rounded-xl px-4 py-3">
                            <div class="text-[10px] font-bold tracking-wider uppercase text-forest/50"><?= t('landing_aff.mockup.kpi_registrations') ?></div>
                            <div class="text-2xl font-bold text-forest">38</div>
                        </div>
                        <div class="bg-terracotta/10 border border-terracotta/30 rounded-xl px-4 py-3">
                            <div class="text-[10px] font-bold tracking-wider uppercase text-terracotta-hover"><?= t('landing_aff.mockup.kpi_paid') ?></div>
                            <div class="text-2xl font-bold text-terracotta-hover">21</div>
                        </div>
                        <div class="bg-forest text-cream border border-forest rounded-xl px-4 py-3">
                            <div class="text-[10px] font-bold tracking-wider uppercase text-cream/70"><?= t('landing_aff.mockup.kpi_earned') ?></div>
                            <div class="text-2xl font-bold">€<?= fmtEurSl(21 * $commAdv) ?></div>
                        </div>
                    </div>

                    <!-- Tabela priporočenih -->
                    <div class="bg-white border border-sage-light/70 rounded-xl overflow-hidden">
                        <div class="px-4 py-3 border-b border-sage-light/60 flex items-center justify-between">
                            <div>
                                <div class="text-[9px] font-bold tracking-widest uppercase text-forest/45 font-mono"><?= t('landing_aff.mockup.table_eyebrow') ?></div>
                                <h3 class="text-[15px] font-bold text-forest tracking-tight"><?= t('landing_aff.mockup.table_title') ?></h3>
                            </div>
                            <div class="hidden md:flex items-center gap-2 px-2.5 py-1.5 rounded-lg border border-sage-light bg-cream/40 text-[11px] text-forest/60 font-mono">
                                rezble.com/?ref=AB7K9X2P
                            </div>
                        </div>
                        <div class="divide-y divide-sage-light/40 text-[12px]">
                            <?php
                            $statusPaid  = t('landing_aff.mockup.status_paid');
                            $statusHold  = t('landing_aff.mockup.status_hold');
                            $statusTrial = t('landing_aff.mockup.status_trial');
                            $rows = [
                                ['Bistro Milano',     'Premium',  '€' . fmtEurSl($commPrem),  $statusPaid,  'forest'],
                                ['Café Central',      'Advanced', '€' . fmtEurSl($commAdv),   $statusPaid,  'forest'],
                                ['The Green Table',   'Basic',    '€' . fmtEurSl($commBasic), $statusPaid,  'forest'],
                                ['Sakura Kitchen',    'Premium',  '€' . fmtEurSl($commPrem),  $statusHold,  'amber'],
                                ['La Piazza',         'Advanced', '—',                        $statusTrial, 'sage'],
                            ];
                            $badgeMap = [
                                'forest' => 'bg-forest/10 text-forest',
                                'amber'  => 'bg-amber-500/15 text-amber-700',
                                'sage'   => 'bg-sage/25 text-forest/70',
                            ];
                            foreach ($rows as [$name, $plan, $earn, $stat, $color]):
                                $badge = $badgeMap[$color] ?? $badgeMap['sage'];
                            ?>
                            <div class="px-4 py-2.5 grid grid-cols-12 gap-2 items-center">
                                <div class="col-span-5 md:col-span-5 font-semibold text-forest truncate"><?= htmlspecialchars($name) ?></div>
                                <div class="col-span-3 md:col-span-3 text-forest/65"><?= htmlspecialchars($plan) ?></div>
                                <div class="col-span-2 md:col-span-2 font-mono font-bold text-forest"><?= htmlspecialchars($earn) ?></div>
                                <div class="col-span-2 md:col-span-2 text-right">
                                    <span class="inline-block px-2 py-0.5 text-[10px] font-bold rounded <?= $badge ?>"><?= htmlspecialchars($stat) ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="absolute -z-10 top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[120%] h-[120%] bg-gradient-to-b from-sage-light/40 to-transparent rounded-full blur-3xl opacity-50"></div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     ZA KOGA JE PROGRAM
════════════════════════════════════════════════════════════ -->
<section class="py-20 bg-white border-y border-sage-light/50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center max-w-3xl mx-auto mb-12">
            <h2 class="text-3xl md:text-4xl font-bold text-forest mb-4"><?= t('landing_aff.audience_title') ?></h2>
            <p class="text-lg text-forest/70"><?= t('landing_aff.audience_subtitle') ?></p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <?php
            $audience = [
                ['<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 9h20M7 4v16"/>',
                 t('landing_aff.audience_1_title'), t('landing_aff.audience_1_desc')],
                ['<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
                 t('landing_aff.audience_2_title'), t('landing_aff.audience_2_desc')],
                ['<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                 t('landing_aff.audience_3_title'), t('landing_aff.audience_3_desc')],
            ];
            foreach ($audience as [$icon, $title, $desc]): ?>
            <div class="bg-cream p-8 rounded-2xl border border-sage-light">
                <div class="text-terracotta mb-6 bg-terracotta/10 w-14 h-14 rounded-xl flex items-center justify-center">
                    <svg width="26" height="26" fill="none" stroke="#C4704B" stroke-width="2"><?= $icon ?></svg>
                </div>
                <h3 class="text-xl font-bold text-forest mb-3"><?= $title ?></h3>
                <p class="text-forest/70 leading-relaxed"><?= $desc ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     KAKO DELUJE
════════════════════════════════════════════════════════════ -->
<section id="how" class="py-24 bg-cream">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-20">
            <h2 class="text-3xl md:text-4xl font-bold text-forest mb-4"><?= t('landing_aff.how_title') ?></h2>
            <p class="text-lg text-forest/70"><?= t('landing_aff.how_subtitle') ?></p>
        </div>
        <div class="relative">
            <div class="hidden md:block absolute top-8 left-[10%] right-[10%] h-0.5 bg-sage-light z-0"></div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-12 relative z-10">
                <?php
                // step1/2 → escapani t(); step3/4 → t_raw() ker vsebujeta <strong>/€ s placeholder-ji
                $steps = [
                    ['1', t('landing_aff.how_step1_title'), t('landing_aff.how_step1_desc')],
                    ['2', t('landing_aff.how_step2_title'), t('landing_aff.how_step2_desc')],
                    ['3', t('landing_aff.how_step3_title'), t_raw('landing_aff.how_step3_desc', $tParams)],
                    ['4', t('landing_aff.how_step4_title'), t_raw('landing_aff.how_step4_desc', $tParams)],
                ];
                foreach ($steps as [$num, $title, $desc]): ?>
                <div class="text-center flex flex-col items-center">
                    <div class="w-16 h-16 bg-forest text-cream rounded-full flex items-center justify-center text-2xl font-bold mb-6 shadow-lg border-4 border-cream"><?= $num ?></div>
                    <h3 class="text-xl font-bold text-forest mb-3"><?= $title ?></h3>
                    <p class="text-forest/70 leading-relaxed max-w-xs"><?= $desc ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     PROVIZIJE / IZPLAČILA
════════════════════════════════════════════════════════════ -->
<section id="earnings" class="py-24 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-stretch">

            <!-- Levi card: številke programa -->
            <div class="bg-forest text-cream p-8 md:p-12 rounded-3xl shadow-xl">
                <span class="inline-block px-4 py-1.5 bg-terracotta text-white font-semibold rounded-full text-sm mb-6"><?= t('landing_aff.earnings_badge') ?></span>
                <h2 class="text-3xl font-bold mb-8"><?= t('landing_aff.earnings_title') ?></h2>
                <div class="space-y-6">
                    <div class="flex items-baseline gap-6">
                        <div class="text-5xl font-bold text-terracotta leading-none w-32 flex-none"><?= t('landing_aff.earnings_pct_value', $tParams) ?></div>
                        <div>
                            <div class="text-cream font-bold text-lg"><?= t('landing_aff.earnings_pct_title') ?></div>
                            <div class="text-cream/70 text-sm"><?= t('landing_aff.earnings_pct_desc') ?></div>
                        </div>
                    </div>
                    <div class="border-t border-forest-light/40"></div>
                    <div class="flex items-baseline gap-6">
                        <div class="text-5xl font-bold text-terracotta leading-none w-32 flex-none"><?= t('landing_aff.earnings_window_value', $tParams) ?></div>
                        <div>
                            <div class="text-cream font-bold text-lg"><?= t('landing_aff.earnings_window_title') ?></div>
                            <div class="text-cream/70 text-sm"><?= t('landing_aff.earnings_window_desc', $tParams) ?></div>
                        </div>
                    </div>
                    <div class="border-t border-forest-light/40"></div>
                    <div class="flex items-baseline gap-6">
                        <div class="text-5xl font-bold text-terracotta leading-none w-32 flex-none">60d</div>
                        <div>
                            <div class="text-cream font-bold text-lg"><?= t('landing_aff.earnings_cookie_title') ?></div>
                            <div class="text-cream/70 text-sm"><?= t('landing_aff.earnings_cookie_desc') ?></div>
                        </div>
                    </div>
                    <div class="border-t border-forest-light/40"></div>
                    <div class="flex items-baseline gap-6">
                        <div class="text-5xl font-bold text-terracotta leading-none w-32 flex-none"><?= t('landing_aff.earnings_payout_value', $tParams) ?></div>
                        <div>
                            <div class="text-cream font-bold text-lg"><?= t('landing_aff.earnings_payout_title') ?></div>
                            <div class="text-cream/70 text-sm"><?= t('landing_aff.earnings_payout_desc', $tParams) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Desni card: kalkulator -->
            <div class="bg-cream p-8 md:p-12 rounded-3xl border border-sage-light">
                <h2 class="text-3xl font-bold text-forest mb-2"><?= t('landing_aff.calc_title') ?></h2>
                <p class="text-forest/60 mb-8"><?= t('landing_aff.calc_subtitle') ?></p>

                <div class="mb-6">
                    <label class="flex items-center justify-between text-sm font-semibold text-forest mb-3">
                        <span><?= t('landing_aff.calc_slider_label') ?></span>
                        <span id="calc-rest" class="text-terracotta text-lg font-bold">10</span>
                    </label>
                    <input id="calc-rest-input" type="range" min="1" max="100" value="10"
                        class="w-full accent-terracotta">
                    <div class="flex justify-between text-[11px] text-forest/50 font-mono mt-1">
                        <span>1</span><span>25</span><span>50</span><span>75</span><span>100</span>
                    </div>
                </div>

                <!-- Billing cycle toggle (Mesečno / Letno) -->
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-forest mb-3"><?= t('landing_aff.calc_cycle_label') ?></label>
                    <div class="inline-flex bg-white border border-sage-light rounded-full p-1 text-sm font-bold">
                        <button type="button" id="calc-cycle-monthly"
                            class="calc-cycle-btn px-4 py-1.5 rounded-full bg-terracotta text-white transition-all"
                            data-cycle="monthly">
                            <?= t('landing_aff.calc_cycle_monthly') ?>
                        </button>
                        <button type="button" id="calc-cycle-yearly"
                            class="calc-cycle-btn px-4 py-1.5 rounded-full text-forest/70 hover:text-forest transition-all"
                            data-cycle="yearly">
                            <?= t('landing_aff.calc_cycle_yearly') ?>
                        </button>
                    </div>
                </div>

                <div class="mb-8">
                    <label class="block text-sm font-semibold text-forest mb-3"><?= t('landing_aff.calc_plan_label') ?></label>
                    <div class="grid grid-cols-3 gap-2">
                        <?php
                        // Cene iz config.php → PLAN_PRICES (single source of truth)
                        // Imena paketov ('Basic','Advanced','Premium') so skupna blagovna imena – ne prevajamo.
                        $plans = [
                            ['basic',    'Basic',    $priceBasicM, $priceBasicY],
                            ['advanced', 'Advanced', $priceAdvM,   $priceAdvY],
                            ['premium',  'Premium',  $pricePremM,  $pricePremY],
                        ];
                        foreach ($plans as [$slug, $label, $priceM, $priceY]): ?>
                        <button type="button"
                            data-slug="<?= $slug ?>"
                            data-price-monthly="<?= $priceM ?>"
                            data-price-yearly="<?= $priceY ?>"
                            class="calc-plan rz-calc-btn px-3 py-3 rounded-xl border-2 text-sm font-bold transition-all
                            <?= $slug === 'advanced' ? 'border-terracotta bg-terracotta text-white' : 'border-sage-light bg-white text-forest hover:border-forest/30' ?>">
                            <div><?= $label ?></div>
                            <div class="calc-plan-price text-[11px] font-medium opacity-80">€<?= fmtEurSl($priceM) ?><?= t('landing_aff.calc_per_month') ?></div>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php
                // Privzete vrednosti (mesečno × Advanced × 10 restavracij)
                $initMonthly = 10 * $priceAdvM * ($pct / 100);
                $initYearly  = $initMonthly * $windowM;
                ?>
                <div class="bg-white rounded-2xl border border-sage-light p-6 space-y-3">
                    <div class="flex justify-between text-sm text-forest/65">
                        <span id="calc-row-label-1"><?= t('landing_aff.calc_monthly') ?></span>
                        <span id="calc-monthly" class="font-mono font-bold text-forest">€<?= fmtEurSl($initMonthly) ?></span>
                    </div>
                    <div class="flex justify-between text-base text-forest font-semibold border-t border-sage-light/60 pt-3">
                        <span id="calc-row-label-2"><?= t('landing_aff.calc_yearly', $tParams) ?></span>
                        <span id="calc-yearly" class="font-mono text-2xl font-bold text-terracotta">€<?= fmtEurSl($initYearly) ?></span>
                    </div>
                </div>
                <p class="text-[11px] text-forest/50 mt-4" id="calc-disclaimer"><?= t('landing_aff.calc_disclaimer', $tParams) ?></p>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     KAJ DOBITE
════════════════════════════════════════════════════════════ -->
<section class="py-24 bg-cream">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center max-w-3xl mx-auto mb-16">
            <h2 class="text-3xl md:text-4xl font-bold text-forest mb-4"><?= t('landing_aff.perks_title') ?></h2>
            <p class="text-lg text-forest/70"><?= t('landing_aff.perks_subtitle') ?></p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php
            $perks = [
                ['<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
                 t('landing_aff.perks_links_title'), t('landing_aff.perks_links_desc')],
                ['<path d="M3 3v18h18"/><path d="M7 14l4-4 4 4 5-5"/>',
                 t('landing_aff.perks_dashboard_title'), t('landing_aff.perks_dashboard_desc')],
                ['<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
                 t('landing_aff.perks_tracking_title'), t('landing_aff.perks_tracking_desc')],
                ['<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
                 t('landing_aff.perks_material_title'), t('landing_aff.perks_material_desc')],
                ['<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
                 t('landing_aff.perks_payout_title'), t('landing_aff.perks_payout_desc', $tParams)],
                ['<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
                 t('landing_aff.perks_support_title'), t('landing_aff.perks_support_desc')],
            ];
            foreach ($perks as [$icon, $title, $desc]): ?>
            <div class="bg-white p-8 rounded-2xl border border-sage-light shadow-sm hover:shadow-md transition-shadow">
                <div class="text-terracotta mb-6 bg-terracotta/10 w-16 h-16 rounded-xl flex items-center justify-center">
                    <svg width="32" height="32" fill="none" stroke="#C4704B" stroke-width="2"><?= $icon ?></svg>
                </div>
                <h3 class="text-xl font-bold text-forest mb-3"><?= $title ?></h3>
                <p class="text-forest/70 leading-relaxed"><?= $desc ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     FAQ
════════════════════════════════════════════════════════════ -->
<section id="faq" class="py-24 bg-white">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-16">
            <h2 class="text-3xl md:text-4xl font-bold text-forest mb-4"><?= t('landing_aff.faq_title') ?></h2>
        </div>
        <div class="space-y-4">
            <?php
            $faqs = [];
            for ($i = 0; $i < 8; $i++) {
                $faqs[] = [t('landing_aff.faq_' . $i . '_q'), t('landing_aff.faq_' . $i . '_a', $tParams)];
            }
            foreach ($faqs as $i => [$q, $a]):
            ?>
            <div class="bg-cream border border-sage-light rounded-2xl overflow-hidden">
                <button onclick="toggleFaq(<?= $i ?>)" class="w-full px-6 py-5 text-left flex justify-between items-center focus:outline-none">
                    <span class="font-bold text-forest pr-8"><?= $q ?></span>
                    <svg id="faq-chevron-<?= $i ?>" class="faq-chevron shrink-0 text-terracotta" width="20" height="20" fill="none" stroke="#C4704B" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div id="faq-body-<?= $i ?>" class="faq-body px-6 pb-5 text-forest/70 leading-relaxed border-t border-sage-light/30 pt-4">
                    <?= $a ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     FINAL CTA
════════════════════════════════════════════════════════════ -->
<section class="py-24 bg-forest relative overflow-hidden">
    <div class="absolute inset-0 opacity-10">
        <div class="absolute -top-24 -right-24 w-96 h-96 bg-sage rounded-full blur-3xl"></div>
        <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-terracotta rounded-full blur-3xl"></div>
    </div>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10">
        <h2 class="text-4xl md:text-5xl font-bold text-cream mb-6"><?= t('landing_aff.cta_title') ?></h2>
        <p class="text-xl text-cream/80 mb-10"><?= t('landing_aff.cta_subtitle') ?></p>
        <a href="<?= $affBase ?>/<?= $loggedIn ? 'dashboard.php' : 'register.php' ?>"
           class="inline-block bg-terracotta hover:bg-terracotta-hover text-white px-10 py-4 rounded-full font-bold text-lg transition-colors shadow-xl">
            <?= $loggedIn ? t('landing_aff.hero_cta_logged') : t('landing_aff.hero_cta_guest') ?>
        </a>
        <?php if (!$loggedIn): ?>
        <div class="mt-6 text-cream/60 text-sm">
            <?= t('landing_aff.cta_login_prompt') ?> <a href="<?= $affBase ?>/login.php" class="underline hover:text-terracotta"><?= t('landing_aff.nav_login') ?></a>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     FOOTER
════════════════════════════════════════════════════════════ -->
<footer class="bg-forest-dark text-cream/80 py-12 border-t border-forest-light/30">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
            <div class="col-span-1 md:col-span-2">
                <a href="<?= $appUrl ?>/" class="inline-block mb-4" aria-label="Rezble">
                    <img src="<?= $appUrl ?>/assets/images/rezble-white.svg" alt="Rezble" style="height:32px;width:auto;display:block">
                </a>
                <p class="text-sm max-w-sm text-cream/60"><?= t('landing_aff.footer_tagline', $tParams) ?></p>
            </div>
            <div>
                <h4 class="text-cream font-semibold mb-4"><?= t('landing_aff.footer_h_program') ?></h4>
                <ul class="space-y-2 text-sm">
                    <li><a href="#how"     class="hover:text-terracotta transition-colors"><?= t('landing_aff.nav_how') ?></a></li>
                    <li><a href="#earnings" class="hover:text-terracotta transition-colors"><?= t('landing_aff.nav_earnings') ?></a></li>
                    <li><a href="#faq"     class="hover:text-terracotta transition-colors"><?= t('landing_aff.nav_faq') ?></a></li>
                    <li><a href="<?= $affBase ?>/terms.php" class="hover:text-terracotta transition-colors"><?= t('landing_aff.footer_terms') ?></a></li>
                    <li><a href="<?= $appUrl ?>/cookie-policy.php" class="hover:text-terracotta transition-colors"><?= t('landing_aff.footer_cookies_policy') ?></a></li>
                    <li><a href="#" data-rez-consent="open" class="hover:text-terracotta transition-colors"><?= t('landing_aff.footer_cookies_settings') ?></a></li>
                </ul>
            </div>
            <div>
                <h4 class="text-cream font-semibold mb-4"><?= t('landing_aff.footer_h_account') ?></h4>
                <ul class="space-y-2 text-sm">
                    <?php if ($loggedIn): ?>
                    <li><a href="<?= $affBase ?>/dashboard.php" class="hover:text-terracotta transition-colors"><?= t('landing_aff.footer_dashboard') ?></a></li>
                    <li><a href="<?= $affBase ?>/links.php"     class="hover:text-terracotta transition-colors"><?= t('landing_aff.footer_links') ?></a></li>
                    <li><a href="<?= $affBase ?>/earnings.php"  class="hover:text-terracotta transition-colors"><?= t('landing_aff.footer_earnings') ?></a></li>
                    <?php else: ?>
                    <li><a href="<?= $affBase ?>/register.php" class="hover:text-terracotta transition-colors"><?= t('landing_aff.footer_register') ?></a></li>
                    <li><a href="<?= $affBase ?>/login.php"    class="hover:text-terracotta transition-colors"><?= t('landing_aff.footer_login') ?></a></li>
                    <?php endif; ?>
                    <li><a href="<?= $appUrl ?>/" class="hover:text-terracotta transition-colors"><?= t('landing_aff.footer_main_site') ?></a></li>
                </ul>
            </div>
        </div>
        <div class="mt-12 pt-8 border-t border-forest-light/30 text-sm text-cream/50 text-center md:text-left">
            <?= t('landing_aff.footer_copyright', ['year' => date('Y')]) ?>
        </div>
    </div>
</footer>

<?php
require_once __DIR__ . '/../includes/cookie_consent.php';
rez_consent_render(['surface' => 'affiliate']);
?>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════ -->
<script>
// ── Mobilni meni ──────────────────────────────────────────────
document.getElementById('nav-toggle').addEventListener('click', function() {
    const menu = document.getElementById('nav-mobile');
    const open = menu.style.display !== 'none';
    menu.style.display = open ? 'none' : 'block';
    document.getElementById('nav-icon-menu').style.display  = open ? '' : 'none';
    document.getElementById('nav-icon-close').style.display = open ? 'none' : '';
});
function closeMobileNav() {
    document.getElementById('nav-mobile').style.display = 'none';
    document.getElementById('nav-icon-menu').style.display  = '';
    document.getElementById('nav-icon-close').style.display = 'none';
}

// ── FAQ accordion ─────────────────────────────────────────────
function toggleFaq(i) {
    const body    = document.getElementById('faq-body-' + i);
    const chevron = document.getElementById('faq-chevron-' + i);
    const isOpen  = body.classList.contains('open');
    document.querySelectorAll('.faq-body').forEach(el => el.classList.remove('open'));
    document.querySelectorAll('.faq-chevron').forEach(el => el.classList.remove('open'));
    if (!isOpen) {
        body.classList.add('open');
        chevron.classList.add('open');
    }
}
toggleFaq(0);

// ── Kalkulator provizij ───────────────────────────────────────
const COMMISSION_PCT = <?= $pct ?>;
const MONTHS         = <?= $windowM ?>;
const CALC_T = {
    perMonth:           <?= json_encode(t('landing_aff.calc_per_month'),       JSON_UNESCAPED_UNICODE) ?>,
    perYear:            <?= json_encode(t('landing_aff.calc_per_year'),        JSON_UNESCAPED_UNICODE) ?>,
    rowMonthly:         <?= json_encode(t('landing_aff.calc_monthly'),         JSON_UNESCAPED_UNICODE) ?>,
    rowYearly:          <?= json_encode(t('landing_aff.calc_yearly',  $tParams), JSON_UNESCAPED_UNICODE) ?>,
    rowPerRestYear:     <?= json_encode(t('landing_aff.calc_per_rest_year'),   JSON_UNESCAPED_UNICODE) ?>,
    rowYearlyTotal:     <?= json_encode(t('landing_aff.calc_yearly_total', $tParams), JSON_UNESCAPED_UNICODE) ?>,
    disclaimerMonthly:  <?= json_encode(t('landing_aff.calc_disclaimer',         $tParams), JSON_UNESCAPED_UNICODE) ?>,
    disclaimerYearly:   <?= json_encode(t('landing_aff.calc_disclaimer_yearly',  $tParams), JSON_UNESCAPED_UNICODE) ?>,
};

let calcRest      = 10;
let calcCycle     = 'monthly';
let calcPlanSlug  = 'advanced';
let calcPriceM    = <?= $priceAdvM ?>;
let calcPriceY    = <?= $priceAdvY ?>;

function fmtEur(n) { return '€' + n.toFixed(2).replace('.', ','); }

function recalcEarnings() {
    const labelTop = document.getElementById('calc-row-label-1');
    const labelBot = document.getElementById('calc-row-label-2');
    const elTop    = document.getElementById('calc-monthly');
    const elBot    = document.getElementById('calc-yearly');
    const elDisc   = document.getElementById('calc-disclaimer');

    if (calcCycle === 'monthly') {
        // Mesečno plačilo: provizijo prejemamo vsak mesec znotraj 12-mes okna.
        const monthly = calcRest * calcPriceM * (COMMISSION_PCT / 100);
        const yearly  = monthly * MONTHS;
        labelTop.textContent = CALC_T.rowMonthly;
        labelBot.textContent = CALC_T.rowYearly;
        elTop.textContent    = fmtEur(monthly);
        elBot.textContent    = fmtEur(yearly);
        elDisc.textContent   = CALC_T.disclaimerMonthly;
    } else {
        // Letno plačilo: en račun = ena provizija znotraj 12-mes okna.
        const perRest = calcPriceY * (COMMISSION_PCT / 100);
        const total   = calcRest * perRest;
        labelTop.textContent = CALC_T.rowPerRestYear;
        labelBot.textContent = CALC_T.rowYearlyTotal;
        elTop.textContent    = fmtEur(perRest);
        elBot.textContent    = fmtEur(total);
        elDisc.textContent   = CALC_T.disclaimerYearly;
    }
}

function updatePlanPriceLabels() {
    document.querySelectorAll('.calc-plan').forEach(btn => {
        const priceLabel = btn.querySelector('.calc-plan-price');
        if (!priceLabel) return;
        const price = calcCycle === 'monthly'
            ? parseFloat(btn.dataset.priceMonthly)
            : parseFloat(btn.dataset.priceYearly);
        const suffix = calcCycle === 'monthly' ? CALC_T.perMonth : CALC_T.perYear;
        priceLabel.textContent = fmtEur(price) + suffix;
    });
}

document.getElementById('calc-rest-input').addEventListener('input', function(e) {
    calcRest = parseInt(e.target.value, 10);
    document.getElementById('calc-rest').textContent = calcRest;
    recalcEarnings();
});

document.querySelectorAll('.calc-plan').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.calc-plan').forEach(b => {
            b.classList.remove('border-terracotta','bg-terracotta','text-white');
            b.classList.add('border-sage-light','bg-white','text-forest','hover:border-forest/30');
        });
        this.classList.remove('border-sage-light','bg-white','text-forest','hover:border-forest/30');
        this.classList.add('border-terracotta','bg-terracotta','text-white');
        calcPlanSlug = this.dataset.slug;
        calcPriceM   = parseFloat(this.dataset.priceMonthly);
        calcPriceY   = parseFloat(this.dataset.priceYearly);
        recalcEarnings();
    });
});

document.querySelectorAll('.calc-cycle-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.calc-cycle-btn').forEach(b => {
            b.classList.remove('bg-terracotta','text-white');
            b.classList.add('text-forest/70','hover:text-forest');
        });
        this.classList.remove('text-forest/70','hover:text-forest');
        this.classList.add('bg-terracotta','text-white');
        calcCycle = this.dataset.cycle;
        updatePlanPriceLabels();
        recalcEarnings();
    });
});

recalcEarnings();
</script>

</body>
</html>
