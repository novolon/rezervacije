<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/plans.php';
require_once '../includes/lang.php';
require_once '../includes/affiliate_helper.php';

$appUrl = APP_URL . BASE_PATH;

// Pridobi cene + popuste iz baze
$pdo         = getDB();

// Affiliate tracking: nastavi cookie ob ?ref= (before any output)
$refParam = strtoupper(trim($_GET['ref'] ?? ''));
if ($refParam && preg_match('/^[A-Z2-9]{8}$/', $refParam)) {
    $trackAff = affiliate_get_by_code($pdo, $refParam);
    if ($trackAff) {
        affiliate_set_cookie($refParam, [
            'utm_source'   => $_GET['utm_source']   ?? '',
            'utm_medium'   => $_GET['utm_medium']   ?? '',
            'utm_campaign' => $_GET['utm_campaign'] ?? '',
        ]);
        affiliate_log_click($pdo, (int)$trackAff['id'], $refParam);
    }
}
$pricingData = [];
foreach (['basic', 'advanced', 'premium'] as $slug) {
    $plan = PLANS[$slug];
    $disc = get_active_discount($pdo, $slug);
    $pricingData[$slug] = [
        'monthly'   => $plan['monthly_price'],
        'yearly'    => $plan['yearly_price'],
        'discM'     => ($disc && $disc['discounted_monthly'] !== null) ? (float)$disc['discounted_monthly'] : null,
        'discY'     => ($disc && $disc['discounted_yearly']  !== null) ? (float)$disc['discounted_yearly']  : null,
        'discLabel' => $disc ? $disc['label'] : null,
        'discUntil' => $disc ? substr($disc['valid_until'], 0, 7) : null,
    ];
}

function fmtPrice(float $p): string {
    return '€' . number_format($p, 2, ',', '');
}
function initialMonthly(array $d): string {
    return fmtPrice($d['discM'] ?? $d['monthly']);
}
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
    <title><?= t('landing.meta_title') ?></title>
    <meta name="description" content="<?= t('landing.meta_description') ?>">
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
<?php
require_once __DIR__ . '/../includes/posthog_init.php';
posthog_render_init(['context' => 'landing', 'identify' => false]);
?>
</head>
<body class="min-h-screen flex flex-col font-sans bg-cream text-forest">

<!-- ═══════════════════════════════════════════════════════════
     NAVBAR
════════════════════════════════════════════════════════════ -->
<nav class="fixed top-0 left-0 right-0 z-50 bg-cream/90 backdrop-blur-md border-b border-sage-light">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-20">
            <a href="#" class="flex items-center" aria-label="Rezble">
                <img src="<?= $appUrl ?>/assets/images/Rezble.svg" alt="Rezble" style="height:32px;width:auto;display:block">
            </a>
            <div class="hidden md:flex items-center space-x-6">
                <a href="#features" class="text-forest/80 hover:text-forest font-medium transition-colors"><?= t('landing.nav_features') ?></a>
                <a href="#pricing"  class="text-forest/80 hover:text-forest font-medium transition-colors"><?= t('landing.nav_pricing') ?></a>
                <a href="#faq"      class="text-forest/80 hover:text-forest font-medium transition-colors"><?= t('landing.nav_faq') ?></a>
                <a href="<?= $appUrl ?>/login.php"    class="text-forest/80 hover:text-forest font-medium transition-colors"><?= t('landing.nav_login') ?></a>
                <a href="<?= $appUrl ?>/register.php" class="bg-terracotta hover:bg-terracotta-hover text-white px-6 py-2.5 rounded-full font-medium transition-colors shadow-sm"><?= t('landing.nav_cta') ?></a>
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
            <a href="#features" class="text-forest font-medium py-2" onclick="closeMobileNav()"><?= t('landing.nav_features') ?></a>
            <a href="#pricing"  class="text-forest font-medium py-2" onclick="closeMobileNav()"><?= t('landing.nav_pricing') ?></a>
            <a href="#faq"      class="text-forest font-medium py-2" onclick="closeMobileNav()"><?= t('landing.nav_faq') ?></a>
            <a href="<?= $appUrl ?>/login.php"    class="text-forest font-medium py-2"><?= t('landing.nav_login_full') ?></a>
            <div class="py-2"><?php $langSwitcherTheme = 'light'; require __DIR__ . '/../includes/lang_switcher.php'; ?></div>
            <a href="<?= $appUrl ?>/register.php" class="bg-terracotta text-white px-6 py-3 rounded-full font-medium text-center mt-4" onclick="closeMobileNav()"><?= t('landing.nav_cta') ?></a>
        </div>
    </div>
</nav>

<!-- ═══════════════════════════════════════════════════════════
     HERO
════════════════════════════════════════════════════════════ -->
<section class="pt-32 pb-20 md:pt-40 md:pb-32 overflow-hidden">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center max-w-3xl mx-auto mb-16">
            <h1 class="text-4xl md:text-6xl font-bold text-forest leading-tight mb-6">
                <?= t('landing.hero_title') ?>
            </h1>
            <p class="text-lg md:text-xl text-forest/70 mb-10 leading-relaxed">
                <?= t('landing.hero_subtitle') ?>
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="<?= $appUrl ?>/register.php"
                   class="w-full sm:w-auto bg-terracotta hover:bg-terracotta-hover text-white px-8 py-4 rounded-full font-semibold text-lg transition-colors shadow-lg flex items-center justify-center gap-2">
                    <?= t('landing.hero_cta') ?>
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                </a>
            </div>

            <!-- Trust badges: no card / no fees / no commission / self-serve -->
            <div class="mt-8 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm font-medium text-forest/70">
                <?php
                $_trust = [
                    t('common.trust.no_card'),
                    t('common.trust.no_fees'),
                    t('common.trust.no_commission'),
                ];
                foreach ($_trust as $_label): ?>
                <span class="inline-flex items-center gap-1.5">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#2F7D52" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    <?= htmlspecialchars($_label) ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Product Mockup — verna kopija app.rezble.com (sidebar + topbar + timeline) -->
        <div class="relative mx-auto max-w-6xl">
            <div class="bg-cream rounded-2xl shadow-2xl border border-sage-light overflow-hidden flex flex-col">
                <!-- Browser chrome -->
                <div class="bg-cream-dark/50 border-b border-sage-light px-4 py-3 flex items-center gap-2">
                    <div class="flex gap-1.5">
                        <div class="w-3 h-3 rounded-full bg-red-400"></div>
                        <div class="w-3 h-3 rounded-full bg-amber-400"></div>
                        <div class="w-3 h-3 rounded-full bg-green-400"></div>
                    </div>
                    <div class="mx-auto bg-white rounded-md px-16 md:px-32 py-1 text-xs text-forest/40 border border-sage-light">app.rezble.com</div>
                </div>

                <?php
                // Inline Rezble logo (white variant for dark sidebar)
                $rezbleLogoSvg = '<svg viewBox="0 0 104 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="h-[18px] w-auto">'
                    . '<path fill="#fff" d="M31.04,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.87-.21,1.56-.64,2.08-1.07,1.3-2.16,2.3-3.25,3.02-1.1.69-2.39,1.04-3.89,1.04s-2.69-.39-3.72-1.17c-1.01-.78-1.97-2.12-2.88-4.02-.74-1.52-1.32-2.64-1.74-3.35-.42-.74-.85-1.26-1.27-1.57-.4-.31-.91-.5-1.51-.57-.09.47-.35,1.94-.77,4.42-.18,1.12-.29,1.8-.34,2.04-.22,1.36-.67,2.41-1.34,3.15-.67.71-1.69,1.07-3.05,1.07-1.5,0-2.83-.44-3.99-1.31-1.14-.89-2.02-2.12-2.65-3.69-.63-1.59-.94-3.38-.94-5.39,0-3.75.65-6.99,1.94-9.72,1.32-2.73,3.15-4.8,5.5-6.23,2.37-1.45,5.1-2.18,8.18-2.18,2.15,0,3.94.32,5.4.97,1.45.65,2.53,1.54,3.22,2.68.72,1.14,1.07,2.42,1.07,3.85,0,1.25-.3,2.48-.91,3.69-.58,1.18-1.46,2.23-2.65,3.15-1.18.92-2.63,1.6-4.32,2.04,1.07.29,1.9.76,2.48,1.41s1.16,1.6,1.74,2.85c.63,1.34,1.24,2.32,1.84,2.95.63.63,1.34.94,2.15.94.72,0,1.4-.23,2.04-.7.65-.49,1.46-1.32,2.45-2.48.27-.31.57-.47.91-.47ZM8.45,20.94c-.76,0-1.29-.18-1.58-.54-.27-.36-.4-.76-.4-1.21,0-.54.17-.96.5-1.27.36-.31.76-.47,1.21-.47h.84c.36-2.19.69-4.08,1.01-5.66.29-1.45,1.23-2.18,2.82-2.18,1.27,0,1.91.57,1.91,1.71,0,.25-.01.44-.03.57l-1.01,5.56c1.21-.07,2.3-.36,3.29-.87,1.01-.51,1.8-1.23,2.38-2.14.6-.92.91-1.95.91-3.12,0-1.41-.48-2.51-1.44-3.32-.96-.8-2.37-1.21-4.22-1.21-2.19,0-4.11.55-5.77,1.64-1.63,1.07-2.91,2.68-3.82,4.83-.92,2.12-1.37,4.71-1.37,7.77,0,1.43.15,2.66.44,3.69.29,1.03.65,1.8,1.07,2.31.42.51.83.77,1.21.77.29,0,.53-.15.7-.44.2-.29.36-.76.47-1.41l.91-5.03Z"/>'
                    . '<path fill="#fff" d="M43.56,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.89-.21,1.59-.64,2.08-.83,1.01-2,1.93-3.52,2.78-1.5.85-3.11,1.27-4.83,1.27-2.35,0-4.17-.64-5.46-1.91-1.3-1.27-1.94-3.02-1.94-5.23,0-1.54.32-2.97.97-4.29.65-1.34,1.54-2.4,2.68-3.18,1.16-.78,2.47-1.17,3.92-1.17,1.3,0,2.34.39,3.12,1.17.78.76,1.17,1.8,1.17,3.12,0,1.54-.56,2.87-1.68,3.99-1.1,1.09-2.96,1.97-5.6,2.61.56,1.03,1.62,1.54,3.18,1.54,1.01,0,2.15-.35,3.42-1.04,1.3-.71,2.41-1.64,3.35-2.78.27-.31.57-.47.91-.47ZM35.11,19.17c-.83,0-1.53.48-2.11,1.44-.56.96-.84,2.12-.84,3.48v.07c1.32-.31,2.36-.78,3.12-1.41.76-.63,1.14-1.35,1.14-2.18,0-.42-.12-.76-.37-1.01-.22-.27-.54-.4-.94-.4Z"/>'
                    . '<path fill="#fff" d="M58.17,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.83-.21,1.52-.64,2.08-1.05,1.36-2.36,2.43-3.92,3.22-1.54.78-3.29,1.17-5.23,1.17-1.52,0-2.84-.22-3.96-.67-1.12-.47-1.98-1.09-2.58-1.88-.58-.8-.87-1.7-.87-2.68,0-1.47.59-2.78,1.78-3.92,1.18-1.14,2.88-2.23,5.1-3.28l-5.5.13c-.49.02-.87-.15-1.14-.5-.25-.38-.37-.83-.37-1.34s.12-1.01.37-1.41c.27-.42.63-.64,1.07-.64,1.03,0,2.4.08,4.12.23.36.02,1.01.07,1.94.13.96.07,1.77.1,2.41.1.22,0,.65-.09,1.27-.27.11-.02.32-.08.64-.17.34-.09.61-.13.84-.13.36,0,.65.16.87.47.25.31.37.79.37,1.44,0,.71-.17,1.27-.5,1.68-.34.4-.86.76-1.58,1.07-2.03.87-3.72,1.81-5.06,2.81-1.34.98-2.01,1.97-2.01,2.95,0,.63.29,1.14.87,1.54.58.4,1.44.6,2.58.6,1.25,0,2.51-.31,3.79-.94,1.3-.63,2.46-1.57,3.49-2.85.27-.31.57-.47.91-.47Z"/>'
                    . '<path fill="#fff" d="M74.2,21.21c.29,0,.51.15.67.44.16.29.23.66.23,1.11,0,.56-.08.99-.23,1.31-.16.29-.4.49-.74.6-1.34.47-2.82.74-4.43.8-.45,1.85-1.3,3.35-2.55,4.49-1.23,1.14-2.59,1.71-4.09,1.71-2.26,0-3.9-.86-4.93-2.58-1.03-1.72-1.54-4.21-1.54-7.47,0-2.88.36-6.01,1.07-9.38.72-3.4,1.75-6.28,3.12-8.65,1.39-2.39,3.03-3.59,4.93-3.59,1.03,0,1.85.45,2.48,1.34.63.87.94,2.01.94,3.42,0,1.83-.35,3.65-1.04,5.46-.69,1.81-1.84,3.71-3.45,5.7,1.5.11,2.72.74,3.65,1.88.94,1.12,1.5,2.5,1.68,4.15,1.05-.07,2.3-.29,3.75-.67.13-.04.29-.07.47-.07ZM64.95,3.32c-.45,0-.94.67-1.47,2.01-.51,1.32-.99,3.12-1.44,5.39-.45,2.28-.78,4.77-1.01,7.47,1.48-2.7,2.65-5.08,3.52-7.14.89-2.08,1.34-3.92,1.34-5.53,0-.71-.09-1.26-.27-1.64-.16-.38-.38-.57-.67-.57ZM63.21,28.11c.69,0,1.31-.29,1.84-.87.54-.58.89-1.42,1.07-2.51-.69-.47-1.23-1.08-1.61-1.84-.36-.76-.54-1.56-.54-2.41,0-.31.04-.74.13-1.27h-.1c-.92,0-1.69.46-2.31,1.37-.6.89-.91,2.1-.91,3.62,0,1.27.23,2.25.7,2.92.49.67,1.06,1.01,1.71,1.01Z"/>'
                    . '<path fill="#fff" d="M84.92,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.89-.21,1.59-.64,2.08-.96,1.18-2.01,2.16-3.15,2.92-1.12.76-2.39,1.14-3.82,1.14-1.97,0-3.43-.89-4.39-2.68-.94-1.79-1.41-4.1-1.41-6.94s.35-5.83,1.04-9.32c.72-3.48,1.75-6.48,3.12-8.98,1.39-2.5,3.03-3.75,4.93-3.75,1.07,0,1.91.5,2.51,1.51.63.98.94,2.4.94,4.26,0,2.66-.74,5.74-2.21,9.25-1.47,3.51-3.48,6.98-6,10.42.16.92.41,1.57.77,1.98.36.38.83.57,1.41.57.92,0,1.72-.26,2.41-.77.69-.54,1.58-1.44,2.65-2.71.27-.31.57-.47.91-.47ZM80.79,3.32c-.51,0-1.1.93-1.74,2.78-.65,1.85-1.22,4.15-1.71,6.9-.49,2.75-.76,5.38-.8,7.91,1.59-2.61,2.85-5.23,3.79-7.84.94-2.64,1.41-5.04,1.41-7.2,0-1.7-.31-2.55-.94-2.55Z"/>'
                    . '<path fill="#fff" d="M95.15,25.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.8-.19,1.5-.57,2.08-.63.96-1.45,1.71-2.48,2.25-1.01.54-2.21.8-3.62.8-2.15,0-3.81-.64-4.99-1.91-1.18-1.3-1.78-3.04-1.78-5.23,0-1.54.32-2.97.97-4.29.65-1.34,1.54-2.4,2.68-3.18,1.16-.78,2.47-1.17,3.92-1.17,1.3,0,2.34.39,3.12,1.17.78.76,1.17,1.8,1.17,3.12,0,1.54-.56,2.87-1.68,3.99-1.1,1.09-2.97,1.97-5.63,2.61.54,1.03,1.44,1.54,2.72,1.54.92,0,1.66-.21,2.25-.64.6-.42,1.3-1.14,2.08-2.14.27-.34.57-.5.91-.5ZM89.65,19.17c-.83,0-1.53.48-2.11,1.44-.56.96-.84,2.12-.84,3.48v.07c1.32-.31,2.36-.78,3.12-1.41.76-.63,1.14-1.35,1.14-2.18,0-.42-.12-.76-.37-1.01-.22-.27-.54-.4-.94-.4Z"/>'
                    . '<path fill="#c8542b" d="M100.75,31.66c-.98,0-1.73-.27-2.25-.8-.49-.54-.74-1.24-.74-2.11,0-1.01.28-1.81.84-2.41.58-.6,1.39-.9,2.41-.9s1.72.25,2.21.74c.51.47.77,1.17.77,2.11,0,1.03-.29,1.85-.87,2.48-.58.6-1.37.9-2.38.9Z"/>'
                    . '</svg>';
                ?>
                <div class="flex h-[480px] md:h-[580px]">
                    <!-- Sidebar (forest dark) -->
                    <div class="w-[230px] bg-forest text-cream/90 hidden md:flex flex-col py-3 px-2 flex-none">
                        <!-- Logo + plan -->
                        <div class="flex items-center justify-between px-2 pb-3">
                            <div class="flex items-center gap-2">
                                <?= $rezbleLogoSvg ?>
                                <span class="text-[8px] font-bold tracking-widest px-1.5 py-0.5 rounded bg-terracotta text-white"><?= t('landing.mockup.plan_badge') ?></span>
                            </div>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="opacity-50"><path d="m14 6-6 6 6 6"/></svg>
                        </div>
                        <!-- Restaurant switcher -->
                        <button class="flex items-center gap-2 px-3 py-2 mx-1 mb-2 rounded-lg border border-cream/15 text-[13px] font-semibold text-cream">
                            <span class="w-2 h-2 rounded-full bg-terracotta flex-none"></span>
                            <span class="flex-1 text-left truncate"><?= t('landing.mockup.restaurant') ?></span>
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="opacity-60"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <!-- Pending pill -->
                        <a class="flex items-center justify-between mx-1 mb-3 px-3 py-2 rounded-lg border border-dashed border-cream/15 text-[12px] font-medium text-cream/80">
                            <span class="flex items-center gap-2 text-cream/55">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                                <span class="text-cream/95"><?= t('landing.mockup.pending') ?></span>
                            </span>
                            <span class="px-1.5 min-w-[18px] text-center text-[10px] font-bold rounded-full bg-red-500 text-white">3</span>
                        </a>
                        <!-- Nav -->
                        <div class="px-3 pt-1 pb-1 text-[9px] font-bold tracking-widest text-cream/40 uppercase"><?= t('landing.mockup.nav_section_overview') ?></div>
                        <a class="flex items-center gap-3 mx-1 px-3 py-2 rounded-lg bg-cream/10 text-cream font-semibold text-[13px]">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
                            <span><?= t('landing.mockup.nav_today') ?></span>
                            <span class="ml-auto w-1 h-4 rounded bg-cream/60"></span>
                        </a>
                        <a class="flex items-center gap-3 mx-1 px-3 py-2 rounded-lg text-cream/65 text-[13px]">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
                            <span><?= t('landing.mockup.nav_stats') ?></span>
                        </a>
                        <a class="flex items-center gap-3 mx-1 px-3 py-2 rounded-lg text-cream/65 text-[13px]">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.2"/><path d="M3 19c0-3 3-5 6-5s6 2 6 5"/></svg>
                            <span><?= t('landing.mockup.nav_guests') ?></span>
                        </a>
                        <a class="flex items-center gap-3 mx-1 px-3 py-2 rounded-lg text-cream/65 text-[13px]">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            <span><?= t('landing.mockup.nav_waitlist') ?></span>
                        </a>
                        <div class="px-3 pt-3 pb-1 text-[9px] font-bold tracking-widest text-cream/40 uppercase"><?= t('landing.mockup.nav_section_settings') ?></div>
                        <a class="flex items-center gap-3 mx-1 px-3 py-2 rounded-lg text-cream/65 text-[13px]">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2"/></svg>
                            <span><?= t('landing.mockup.nav_restaurant') ?></span>
                        </a>
                        <a class="flex items-center gap-3 mx-1 px-3 py-2 rounded-lg text-cream/65 text-[13px]">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                            <span><?= t('landing.mockup.nav_billing') ?></span>
                        </a>
                        <div class="flex-1"></div>
                        <!-- User -->
                        <div class="flex items-center gap-2 px-2 py-2 mt-2 border-t border-cream/10 pt-3">
                            <div class="w-8 h-8 rounded-full bg-blue-500 text-white text-[11px] font-bold grid place-items-center font-mono"><?= t('landing.mockup.user_initials') ?></div>
                            <div class="flex-1 min-w-0">
                                <div class="text-[12px] font-semibold text-cream truncate"><?= t('landing.mockup.user_name') ?></div>
                                <div class="text-[10px] text-cream/50"><?= t('landing.mockup.user_role') ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Main content -->
                    <div class="flex-1 bg-cream p-5 md:p-6 flex flex-col gap-4 overflow-hidden min-w-0">
                        <!-- Topbar -->
                        <div class="flex items-end justify-between gap-4 pb-3 border-b border-sage-light/60">
                            <div>
                                <h2 class="text-[20px] md:text-[26px] font-bold text-forest leading-tight tracking-tight" style="font-family:'Source Serif 4',serif"><?= t('landing.mockup.restaurant') ?></h2>
                                <div class="text-[12px] text-forest/55 mt-0.5"><?= t('landing.mockup.subtitle') ?></div>
                            </div>
                            <div class="hidden md:flex items-center gap-2 flex-none">
                                <div class="flex items-center gap-2 px-2.5 py-1.5 rounded-lg border border-sage-light bg-white text-[12px] text-forest/60">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                                    <span><?= t('landing.mockup.search') ?></span>
                                    <span class="text-[9px] font-mono px-1 rounded border border-sage-light bg-cream-dark/40 text-forest/50">⌘K</span>
                                </div>
                                <button class="px-3 py-1.5 bg-terracotta text-white rounded-lg text-[12px] font-semibold flex items-center gap-1.5 shadow-sm">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                    <?= t('landing.mockup.new_reservation') ?>
                                </button>
                            </div>
                        </div>

                        <!-- KPI strip -->
                        <div class="grid grid-cols-4 gap-2 md:gap-3">
                            <div class="bg-white border border-sage-light/70 rounded-xl px-3 py-2.5">
                                <div class="text-[9px] font-bold tracking-wider uppercase text-forest/50"><?= t('landing.mockup.kpi_confirmed') ?></div>
                                <div class="text-[18px] md:text-[22px] font-bold text-forest leading-tight">10</div>
                            </div>
                            <div class="bg-terracotta/10 border border-terracotta/30 rounded-xl px-3 py-2.5">
                                <div class="text-[9px] font-bold tracking-wider uppercase text-terracotta-hover"><?= t('landing.mockup.kpi_waiting') ?></div>
                                <div class="text-[18px] md:text-[22px] font-bold text-terracotta-hover leading-tight">3</div>
                            </div>
                            <div class="bg-white border border-sage-light/70 rounded-xl px-3 py-2.5">
                                <div class="text-[9px] font-bold tracking-wider uppercase text-forest/50"><?= t('landing.mockup.kpi_guests') ?></div>
                                <div class="text-[18px] md:text-[22px] font-bold text-forest leading-tight">38</div>
                            </div>
                            <div class="bg-white border border-sage-light/70 rounded-xl px-3 py-2.5">
                                <div class="text-[9px] font-bold tracking-wider uppercase text-forest/50"><?= t('landing.mockup.kpi_occupancy') ?></div>
                                <div class="text-[18px] md:text-[22px] font-bold text-forest leading-tight">76%</div>
                            </div>
                        </div>

                        <!-- Timeline card -->
                        <div class="flex-1 bg-white border border-sage-light/70 rounded-xl overflow-hidden flex flex-col min-h-0">
                            <!-- Card head -->
                            <div class="px-4 pt-3 pb-2 flex items-center justify-between gap-3 border-b border-sage-light/60">
                                <div>
                                    <div class="text-[9px] font-bold tracking-widest uppercase text-forest/45 font-mono"><?= t('landing.mockup.tl_eyebrow') ?></div>
                                    <h3 class="text-[15px] font-bold text-forest tracking-tight" style="font-family:'Source Serif 4',serif"><?= t('landing.mockup.tl_title') ?></h3>
                                </div>
                                <div class="flex items-center gap-1.5 flex-none">
                                    <button class="w-6 h-6 grid place-items-center rounded border border-sage-light text-forest/60">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
                                    </button>
                                    <button class="w-6 h-6 grid place-items-center rounded border border-sage-light text-forest/60">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
                                    </button>
                                    <div class="flex rounded-md border border-sage-light overflow-hidden ml-1 text-[11px] font-semibold">
                                        <span class="px-2.5 py-1 bg-forest text-cream"><?= t('landing.mockup.tl_view_timeline') ?></span>
                                        <span class="px-2.5 py-1 text-forest/60 bg-white"><?= t('landing.mockup.tl_view_list') ?></span>
                                    </div>
                                </div>
                            </div>
                            <!-- Hours header -->
                            <div class="grid bg-cream/40 border-b border-sage-light/60 text-[10px] font-medium text-forest/50" style="grid-template-columns:90px 1fr">
                                <div class="border-r border-sage-light/60 px-2 py-1.5 font-bold text-[9px] tracking-widest uppercase text-forest/40"><?= t('landing.mockup.tables_header') ?></div>
                                <div class="grid grid-cols-5 relative">
                                    <div class="px-2 py-1.5 border-r border-dashed border-sage-light">17:00</div>
                                    <div class="px-2 py-1.5 border-r border-dashed border-sage-light">18:00</div>
                                    <div class="px-2 py-1.5 border-r border-dashed border-sage-light">19:00</div>
                                    <div class="px-2 py-1.5 border-r border-dashed border-sage-light">20:00</div>
                                    <div class="px-2 py-1.5">21:00</div>
                                </div>
                            </div>
                            <!-- Body -->
                            <div class="flex-1 grid relative" style="grid-template-columns:90px 1fr">
                                <!-- Left: zones + tables -->
                                <div class="border-r border-sage-light/60 bg-white text-[11px]">
                                    <!-- Zone PRITLICJE -->
                                    <div class="flex items-center justify-between px-2 py-1.5 bg-cream/40 border-b border-sage-light/60 text-[8.5px] font-bold tracking-widest uppercase text-forest/45">
                                        <span><?= t('landing.mockup.zone_ground') ?></span>
                                        <span class="px-1 text-[9px] font-bold rounded bg-cream-dark/40 text-forest/55">2</span>
                                    </div>
                                    <div class="px-2 h-12 flex items-center justify-between font-medium text-forest/85"><span><?= t('landing.mockup.table_3') ?></span><span class="text-[9px] font-bold text-forest/40">2</span></div>
                                    <div class="px-2 h-12 flex items-center justify-between font-medium text-forest/85 border-t border-sage-light/40"><span><?= t('landing.mockup.table_4') ?></span><span class="text-[9px] font-bold text-forest/40">6</span></div>
                                    <!-- Zone TERASA -->
                                    <div class="flex items-center justify-between px-2 py-1.5 bg-cream/40 border-y border-sage-light/60 text-[8.5px] font-bold tracking-widest uppercase text-forest/45">
                                        <span><?= t('landing.mockup.zone_terrace') ?></span>
                                        <span class="px-1 text-[9px] font-bold rounded bg-cream-dark/40 text-forest/55">2</span>
                                    </div>
                                    <div class="px-2 h-12 flex items-center justify-between font-medium text-forest/85"><span><?= t('landing.mockup.terrace_1') ?></span><span class="text-[9px] font-bold text-forest/40">4</span></div>
                                    <div class="px-2 h-12 flex items-center justify-between font-medium text-forest/85 border-t border-sage-light/40"><span><?= t('landing.mockup.terrace_2') ?></span><span class="text-[9px] font-bold text-forest/40">6</span></div>
                                </div>
                                <!-- Right: grid + reservations -->
                                <div class="relative">
                                    <!-- Vertical guides -->
                                    <div class="absolute inset-0 grid grid-cols-5 pointer-events-none">
                                        <div class="border-r border-dashed border-sage-light/50"></div>
                                        <div class="border-r border-dashed border-sage-light/50"></div>
                                        <div class="border-r border-dashed border-sage-light/50"></div>
                                        <div class="border-r border-dashed border-sage-light/50"></div>
                                        <div></div>
                                    </div>
                                    <!-- Zone PRITLICJE rows -->
                                    <div class="h-[26px] bg-cream/30 border-b border-sage-light/60"></div>
                                    <div class="h-12 relative border-b border-sage-light/40">
                                        <!-- Pending -->
                                        <div class="absolute top-1.5 bottom-1.5 left-[63%] right-[18%] rounded-md border border-dashed border-amber-500 bg-white px-2 py-0.5 text-[10px] font-bold text-forest leading-tight overflow-hidden">
                                            19:30 · 1g <span class="ml-1 px-1 rounded bg-amber-500/80 text-white text-[8px] font-bold"><?= t('landing.mockup.pending_chip') ?></span>
                                            <div class="text-[9px] font-medium text-forest/70 mt-0.5"><?= t('landing.mockup.res_pending_name') ?></div>
                                        </div>
                                    </div>
                                    <div class="h-12 relative border-b border-sage-light/40">
                                        <!-- Confirmed (forest) -->
                                        <div class="absolute top-1.5 bottom-1.5 left-[78%] right-[2%] rounded-md bg-forest border-l-[3px] border-forest-light text-cream px-1.5 py-0.5 text-[10px] font-bold leading-tight overflow-hidden">
                                            20:00 · 6g
                                            <div class="text-[9px] font-medium text-cream/85 mt-0.5"><?= t('landing.mockup.res_confirmed1_name') ?></div>
                                        </div>
                                    </div>
                                    <!-- Zone TERASA spacer -->
                                    <div class="h-[26px] bg-cream/30 border-y border-sage-light/60"></div>
                                    <div class="h-12 relative border-b border-sage-light/40">
                                        <!-- Now / arrived (terracotta) -->
                                        <div class="absolute top-1.5 bottom-1.5 left-[28%] right-[44%] rounded-md bg-terracotta border-l-[3px] border-terracotta-hover text-white px-1.5 py-0.5 text-[10px] font-bold leading-tight overflow-hidden">
                                            18:30 · 4g
                                            <div class="text-[9px] font-medium text-white/95 mt-0.5"><?= t('landing.mockup.res_arrived_name') ?></div>
                                        </div>
                                    </div>
                                    <div class="h-12 relative">
                                        <!-- Past (grey) -->
                                        <div class="absolute top-1.5 bottom-1.5 left-[8%] right-[68%] rounded-md bg-forest/40 border-l-[3px] border-forest/60 text-white px-1.5 py-0.5 text-[10px] font-bold leading-tight overflow-hidden">
                                            17:00 · 2g
                                            <div class="text-[9px] font-medium text-white/90 mt-0.5"><?= t('landing.mockup.res_past_name') ?></div>
                                        </div>
                                        <!-- Confirmed (forest) -->
                                        <div class="absolute top-1.5 bottom-1.5 left-[44%] right-[16%] rounded-md bg-forest border-l-[3px] border-forest-light text-cream px-1.5 py-0.5 text-[10px] font-bold leading-tight overflow-hidden">
                                            19:00 · 3g
                                            <div class="text-[9px] font-medium text-cream/85 mt-0.5"><?= t('landing.mockup.res_confirmed2_name') ?></div>
                                        </div>
                                    </div>
                                    <!-- ZDAJ vertical line + pill -->
                                    <div class="absolute top-0 bottom-0 left-[36%] w-px bg-terracotta pointer-events-none"></div>
                                    <div class="absolute top-1 left-[36%] -translate-x-1/2 px-1.5 py-0.5 rounded bg-terracotta text-white text-[9px] font-bold tracking-wider shadow-sm font-mono"><?= t('landing.mockup.now_pill') ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="absolute -z-10 top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[120%] h-[120%] bg-gradient-to-b from-sage-light/40 to-transparent rounded-full blur-3xl opacity-50"></div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     SOCIAL PROOF
════════════════════════════════════════════════════════════ -->
<section class="py-12 border-y border-sage-light bg-cream-dark/30">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <p class="text-sm font-medium text-forest/50 uppercase tracking-wider mb-8"><?= t('landing.social_proof') ?></p>
        <div class="flex flex-wrap justify-center items-center gap-8 md:gap-16 opacity-60 hover:opacity-100 transition-opacity duration-500">
            <?php foreach (['Bistro Milano','Café Central','The Green Table','Sakura Kitchen','La Piazza'] as $name): ?>
            <span class="text-xl md:text-2xl font-bold font-serif text-forest"><?= htmlspecialchars($name) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     PROBLEM / SOLUTION
════════════════════════════════════════════════════════════ -->
<section class="py-24 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
            <div class="bg-cream p-8 md:p-12 rounded-3xl border border-sage-light">
                <h2 class="text-3xl font-bold text-forest mb-8"><?= t('landing.problem_title') ?></h2>
                <ul class="space-y-6">
                    <?php foreach ([t('landing.problem_1'), t('landing.problem_2'), t('landing.problem_3'), t('landing.problem_4')] as $item): ?>
                    <li class="flex items-start gap-4">
                        <svg class="shrink-0 mt-1 text-terracotta" width="24" height="24" fill="none" stroke="#C4704B" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>
                        <span class="text-lg text-forest/80"><?= htmlspecialchars($item) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="bg-forest p-8 md:p-12 rounded-3xl shadow-xl">
                <h2 class="text-3xl font-bold text-cream mb-8"><?= t('landing.solution_title') ?></h2>
                <ul class="space-y-6">
                    <?php foreach ([t('landing.solution_1'), t('landing.solution_2'), t('landing.solution_3'), t('landing.solution_4')] as $item): ?>
                    <li class="flex items-start gap-4">
                        <svg class="shrink-0 mt-1" width="24" height="24" fill="none" stroke="#A3B18A" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <span class="text-lg text-cream/90"><?= htmlspecialchars($item) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     CORE FEATURES
════════════════════════════════════════════════════════════ -->
<section id="features" class="py-24 bg-cream">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center max-w-3xl mx-auto mb-16">
            <h2 class="text-3xl md:text-4xl font-bold text-forest mb-4"><?= t('landing.features_title') ?></h2>
            <p class="text-lg text-forest/70"><?= t('landing.features_subtitle') ?></p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php
            $coreFeatures = [
                ['icon' => '<path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/>',
                 'title' => t('landing.feat_calendar_title'), 'desc' => t('landing.feat_calendar_desc')],
                ['icon' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
                 'title' => t('landing.feat_multi_title'), 'desc' => t('landing.feat_multi_desc')],
                ['icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                 'title' => t('landing.feat_staff_title'), 'desc' => t('landing.feat_staff_desc')],
                ['icon' => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
                 'title' => t('landing.feat_realtime_title'), 'desc' => t('landing.feat_realtime_desc')],
                ['icon' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
                 'title' => t('landing.feat_stats_title'), 'desc' => t('landing.feat_stats_desc')],
                ['icon' => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/><rect x="14" y="9" width="6" height="11" rx="1.5" fill="currentColor" stroke="none" opacity=".15"/><rect x="14" y="9" width="6" height="11" rx="1.5"/>',
                 'title' => t('landing.feat_devices_title'), 'desc' => t('landing.feat_devices_desc')],
            ];
            foreach ($coreFeatures as $f): ?>
            <div class="bg-white p-8 rounded-2xl border border-sage-light shadow-sm hover:shadow-md transition-shadow">
                <div class="text-terracotta mb-6 bg-terracotta/10 w-16 h-16 rounded-xl flex items-center justify-center">
                    <svg width="32" height="32" fill="none" stroke="#C4704B" stroke-width="2"><?= $f['icon'] ?></svg>
                </div>
                <h3 class="text-xl font-bold text-forest mb-3"><?= htmlspecialchars($f['title']) ?></h3>
                <p class="text-forest/70 leading-relaxed"><?= htmlspecialchars($f['desc']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     ADVANCED FEATURES
════════════════════════════════════════════════════════════ -->
<section class="py-24 bg-white border-t border-sage-light/50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="mb-16">
            <span class="inline-block px-4 py-1.5 bg-forest/10 text-forest font-semibold rounded-full text-sm mb-4"><?= t('landing.adv_badge') ?></span>
            <h2 class="text-3xl md:text-4xl font-bold text-forest mb-4"><?= t('landing.adv_title') ?></h2>
            <p class="text-lg text-forest/70 max-w-2xl"><?= t('landing.adv_subtitle') ?></p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <?php
            $advFeatures = [
                ['icon' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
                 'title' => t('landing.adv_email_title'), 'desc' => t('landing.adv_email_desc'), 'soon' => false],
                ['icon' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
                 'title' => t('landing.adv_link_title'), 'desc' => t('landing.adv_link_desc'), 'soon' => false],
                ['icon' => '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
                 'title' => t('landing.adv_confirm_title'), 'desc' => t('landing.adv_confirm_desc'), 'soon' => false],
                ['icon' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
                 'title' => t('landing.adv_tables_title'), 'desc' => t('landing.adv_tables_desc'), 'soon' => false],
                ['icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>',
                 'title' => t('landing.adv_guests_title'), 'desc' => t('landing.adv_guests_desc'), 'soon' => false],
                // Polja po meri — 6. Advanced kartica
                ['icon' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9M14 13h3M14 17h3"/>',
                 'title' => t('landing.adv_fields_title'), 'desc' => t('landing.adv_fields_desc'), 'soon' => false],
            ];
            foreach ($advFeatures as $f): ?>
            <div class="bg-cream p-8 rounded-2xl border border-sage-light relative overflow-hidden">
                <div class="text-forest mb-6">
                    <svg width="28" height="28" fill="none" stroke="#1B4332" stroke-width="2"><?= $f['icon'] ?></svg>
                </div>
                <h3 class="text-xl font-bold text-forest mb-3"><?= htmlspecialchars($f['title']) ?></h3>
                <p class="text-forest/70 leading-relaxed mb-4"><?= htmlspecialchars($f['desc']) ?></p>
                <?php if ($f['soon']): ?>
                <span class="inline-block px-3 py-1 bg-sage/30 text-forest/80 text-xs font-bold rounded-md uppercase tracking-wider"><?= t('landing.soon') ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     PREMIUM FEATURES
════════════════════════════════════════════════════════════ -->
<section class="py-24 bg-forest text-cream">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="mb-16">
            <span class="inline-block px-4 py-1.5 bg-terracotta text-white font-semibold rounded-full text-sm mb-4"><?= t('landing.prem_badge') ?></span>
            <h2 class="text-3xl md:text-4xl font-bold mb-4"><?= t('landing.prem_title') ?></h2>
            <p class="text-lg text-cream/70 max-w-2xl"><?= t('landing.prem_subtitle') ?></p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php
            $premFeatures = [
                // 1. Vgradljivi widget
                ['icon' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
                 'title' => t('landing.prem_widget_title'), 'desc' => t('landing.prem_widget_desc'), 'soon' => false],
                // 2. Samodejno združevanje miz
                ['icon' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><path d="M10 6h4M12 14v6M9 17h6"/>',
                 'title' => t('landing.prem_merge_title'), 'desc' => t('landing.prem_merge_desc'), 'soon' => false],
                // 3. Auto-confirm
                ['icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                 'title' => t('landing.prem_auto_title'), 'desc' => t('landing.prem_auto_desc'), 'soon' => false],
                // 4. Ankete za goste
                ['icon' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
                 'title' => t('landing.prem_survey_title'), 'desc' => t('landing.prem_survey_desc'), 'soon' => false],
                // 5. Čakalna lista
                ['icon' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
                 'title' => t('landing.adv_waitlist_title'), 'desc' => t('landing.adv_waitlist_desc'), 'soon' => false],
                // 6. Izvoz podatkov
                ['icon' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
                 'title' => t('landing.prem_export_title'), 'desc' => t('landing.prem_export_desc'), 'soon' => false],
            ];
            foreach ($premFeatures as $f): ?>
            <div class="bg-forest-light/30 p-8 rounded-2xl border border-forest-light relative">
                <div class="text-terracotta mb-6">
                    <svg width="28" height="28" fill="none" stroke="#C4704B" stroke-width="2"><?= $f['icon'] ?></svg>
                </div>
                <h3 class="text-xl font-bold mb-3"><?= htmlspecialchars($f['title']) ?></h3>
                <p class="text-cream/70 leading-relaxed mb-4"><?= htmlspecialchars($f['desc']) ?></p>
                <?php if ($f['soon']): ?>
                <span class="inline-block px-3 py-1 bg-forest text-cream/60 text-xs font-bold rounded-md uppercase tracking-wider border border-forest-light"><?= t('landing.soon') ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     HOW IT WORKS
════════════════════════════════════════════════════════════ -->
<section class="py-24 bg-cream">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-20">
            <h2 class="text-3xl md:text-4xl font-bold text-forest mb-4"><?= t('landing.howitworks_title') ?></h2>
            <p class="text-lg text-forest/70"><?= t('landing.howitworks_subtitle') ?></p>
        </div>
        <div class="relative">
            <div class="hidden md:block absolute top-8 left-[10%] right-[10%] h-0.5 bg-sage-light z-0"></div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-12 relative z-10">
                <?php foreach ([
                    ['1', t('landing.step1_title'), t('landing.step1_desc')],
                    ['2', t('landing.step2_title'), t('landing.step2_desc')],
                    ['3', t('landing.step3_title'), t('landing.step3_desc')],
                ] as [$num, $title, $desc]): ?>
                <div class="text-center flex flex-col items-center">
                    <div class="w-16 h-16 bg-forest text-cream rounded-full flex items-center justify-center text-2xl font-bold mb-6 shadow-lg border-4 border-cream"><?= $num ?></div>
                    <h3 class="text-xl font-bold text-forest mb-3"><?= htmlspecialchars($title) ?></h3>
                    <p class="text-forest/70 leading-relaxed max-w-xs"><?= htmlspecialchars($desc) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     PRICING
════════════════════════════════════════════════════════════ -->
<section id="pricing" class="py-24 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-16">
            <h2 class="text-3xl md:text-4xl font-bold text-forest mb-6"><?= t('landing.pricing_title') ?></h2>
            <div class="flex items-center justify-center gap-4">
                <span id="lbl-monthly" class="text-sm font-medium text-forest"><?= t('landing.pricing_monthly') ?></span>
                <button id="billing-toggle" onclick="toggleBilling()"
                    class="relative w-14 h-8 bg-forest rounded-full p-1 transition-colors">
                    <div id="toggle-knob" class="w-6 h-6 bg-white rounded-full shadow-sm transition-transform duration-200" style="transform:translateX(0)"></div>
                </button>
                <span id="lbl-yearly" class="text-sm font-medium text-forest/50 flex items-center gap-2">
                    <?= t('landing.pricing_yearly') ?>
                    <span class="bg-terracotta/10 text-terracotta text-xs px-2 py-0.5 rounded-full font-bold"><?= t('landing.pricing_yearly_discount') ?></span>
                </span>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-12">

            <?php
            $planCards = [
                ['basic',    false, t('landing.plan_basic_desc'),
                    [t('landing.feat_basic_1'), t('landing.feat_basic_2'), t('landing.feat_basic_3'), t('landing.feat_basic_4'), t('landing.feat_basic_5')]],
                ['advanced', true,  t('landing.plan_advanced_desc'),
                    [t('landing.feat_adv_1'), t('landing.feat_adv_2'), t('landing.feat_adv_3'), t('landing.feat_adv_4'), t('landing.feat_adv_5'), t('landing.feat_adv_6'), t('landing.feat_adv_8')]],
                ['premium',  false, t('landing.plan_premium_desc'),
                    [t('landing.feat_prem_1'), t('landing.feat_prem_2'), t('landing.feat_prem_3'), t('landing.feat_prem_4'), t('landing.feat_prem_5'), t('landing.feat_prem_6'), t('landing.feat_prem_7')]],
            ];
            $planNames = ['basic' => t('landing.plan_basic'), 'advanced' => t('landing.plan_advanced'), 'premium' => t('landing.plan_premium')];
            foreach ($planCards as [$slug, $highlighted, $desc, $features]):
                $d = $pricingData[$slug];
                $showDisc = $d['discM'] !== null;
                $mainPrice = initialMonthly($d);
                $origPrice = $showDisc ? fmtPrice($d['monthly']) : null;
            ?>
            <div class="relative flex flex-col p-8 rounded-3xl border <?= $highlighted ? 'border-terracotta shadow-xl bg-cream' : 'border-sage-light bg-white' ?>">
                <?php if ($highlighted): ?>
                <div class="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-terracotta text-white px-4 py-1 rounded-full text-sm font-bold shadow-sm"><?= t('landing.pricing_popular') ?></div>
                <?php endif; ?>

                <!-- 30-day trial badge -->
                <div class="inline-flex items-center gap-1.5 bg-sage/15 text-forest text-xs font-semibold px-3 py-1 rounded-full mb-4 self-start border border-sage/30">
                    <svg width="11" height="11" fill="none" stroke="#A3B18A" stroke-width="2.5"><polyline points="10 3 4.5 8.5 2 6"/></svg>
                    <?= t('landing.pricing_trial_badge') ?>
                </div>

                <div class="mb-6">
                    <h3 class="text-xl font-bold text-forest mb-2"><?= $planNames[$slug] ?></h3>
                    <p class="text-forest/60 text-sm"><?= htmlspecialchars($desc) ?></p>
                </div>

                <div class="mb-1 flex items-baseline gap-2 flex-wrap">
                    <span class="price-orig-<?= $slug ?> text-lg text-forest/40 line-through"
                          style="<?= $showDisc ? '' : 'display:none' ?>"><?= $origPrice ?></span>
                    <span class="price-amount-<?= $slug ?> text-4xl font-bold text-forest"><?= $mainPrice ?></span>
                    <span class="price-period-<?= $slug ?> text-forest/60 font-medium"><?= t('common.per_month') ?></span>
                </div>
                <div class="disc-label-<?= $slug ?> mb-4 text-xs text-terracotta font-semibold"
                     style="<?= ($showDisc) ? '' : 'display:none' ?>">
                    <?php if ($d['discLabel']): ?>
                    <?= htmlspecialchars($d['discLabel']) ?> – do <?= str_replace('-', '/', $d['discUntil'] ?? '') ?>
                    <?php endif; ?>
                </div>
                <?php if (!$showDisc): ?><div class="mb-4 h-4"></div><?php endif; ?>

                <ul class="space-y-3 mb-8 flex-1">
                    <?php foreach ($features as $f): ?>
                    <li class="flex items-start gap-3">
                        <svg class="shrink-0 mt-0.5" width="20" height="20" fill="none" stroke="#A3B18A" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        <span class="text-forest/80 text-sm"><?= htmlspecialchars($f) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <a href="<?= $appUrl ?>/register.php?plan=<?= $slug ?>"
                   class="w-full py-3 rounded-xl font-bold text-center transition-colors <?= $highlighted ? 'bg-terracotta hover:bg-terracotta-hover text-white' : 'bg-forest/10 hover:bg-forest/20 text-forest' ?>">
                    <?= t('landing.pricing_cta') ?>
                </a>
            </div>
            <?php endforeach; ?>

        </div>

        <div class="text-center text-forest/60 text-sm space-y-2">
            <p class="font-medium text-forest/80"><?= t('landing.pricing_footer_1') ?></p>
            <p><?= t('landing.pricing_footer_2') ?></p>
            <p><?= t_raw('landing.pricing_footer_3', ['contact_link' => '<a href="' . htmlspecialchars($appUrl . '/register.php') . '" class="underline hover:text-terracotta">' . t('landing.pricing_contact') . '</a>']) ?></p>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     FAQ
════════════════════════════════════════════════════════════ -->
<section id="faq" class="py-24 bg-cream">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-16">
            <h2 class="text-3xl md:text-4xl font-bold text-forest mb-4"><?= t('landing.faq_title') ?></h2>
        </div>
        <div class="space-y-4">
            <?php
            $faqs = [];
            for ($fi = 0; $fi <= 10; $fi++) {
                $faqs[] = [t('landing.faq_' . $fi . '_q'), t('landing.faq_' . $fi . '_a')];
            }
            foreach ($faqs as $i => [$q, $a]):
            ?>
            <div class="bg-white border border-sage-light rounded-2xl overflow-hidden">
                <button onclick="toggleFaq(<?= $i ?>)" class="w-full px-6 py-5 text-left flex justify-between items-center focus:outline-none">
                    <span class="font-bold text-forest pr-8"><?= htmlspecialchars($q) ?></span>
                    <svg id="faq-chevron-<?= $i ?>" class="faq-chevron shrink-0 text-terracotta" width="20" height="20" fill="none" stroke="#C4704B" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div id="faq-body-<?= $i ?>" class="faq-body px-6 pb-5 text-forest/70 leading-relaxed border-t border-sage-light/30 pt-4">
                    <?= htmlspecialchars($a) ?>
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
        <h2 class="text-4xl md:text-5xl font-bold text-cream mb-6"><?= t('landing.cta_title') ?></h2>
        <p class="text-xl text-cream/80 mb-10"><?= t('landing.cta_subtitle') ?></p>
        <a href="<?= $appUrl ?>/register.php"
           class="inline-block bg-terracotta hover:bg-terracotta-hover text-white px-10 py-4 rounded-full font-bold text-lg transition-colors shadow-xl">
            <?= t('landing.cta_btn') ?>
        </a>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     FOOTER
════════════════════════════════════════════════════════════ -->
<footer class="bg-forest-dark text-cream/80 py-12 border-t border-forest-light/30">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
            <div class="col-span-1 md:col-span-2">
                <a href="#" class="inline-block mb-4" aria-label="Rezble">
                    <img src="<?= $appUrl ?>/assets/images/rezble-white.svg" alt="Rezble" style="height:32px;width:auto;display:block">
                </a>
                <p class="text-sm max-w-sm text-cream/60">Preprost, sodoben sistem za upravljanje rezervacij, zasnovan za restavracije – od posameznih lokacij do verig.</p>
            </div>
            <div>
                <h4 class="text-cream font-semibold mb-4"><?= t('landing.footer_product') ?></h4>
                <ul class="space-y-2 text-sm">
                    <li><a href="#features" class="hover:text-terracotta transition-colors"><?= t('landing.footer_features') ?></a></li>
                    <li><a href="#pricing"  class="hover:text-terracotta transition-colors"><?= t('landing.footer_pricing') ?></a></li>
                    <li><a href="#faq"      class="hover:text-terracotta transition-colors"><?= t('landing.footer_faq') ?></a></li>
                </ul>
            </div>
            <div>
                <h4 class="text-cream font-semibold mb-4"><?= t('landing.footer_legal') ?></h4>
                <ul class="space-y-2 text-sm">
                    <li><a href="#" class="hover:text-terracotta transition-colors"><?= t('landing.footer_contact') ?></a></li>
                    <li><a href="#" class="hover:text-terracotta transition-colors"><?= t('landing.footer_privacy') ?></a></li>
                    <li><a href="#" class="hover:text-terracotta transition-colors"><?= t('landing.footer_terms') ?></a></li>
                    <li><a href="<?= $appUrl ?>/cookie-policy.php" class="hover:text-terracotta transition-colors">Politika piškotkov</a></li>
                    <li><a href="#" data-rez-consent="open" class="hover:text-terracotta transition-colors">Cookie nastavitve</a></li>
                </ul>
            </div>
        </div>
        <div class="mt-12 pt-8 border-t border-forest-light/30 text-sm text-cream/50 text-center md:text-left">
            <?= t('landing.footer_copyright', ['year' => date('Y')]) ?>
        </div>
    </div>
</footer>

<?php
require_once __DIR__ . '/../includes/cookie_consent.php';
rez_consent_render(['surface' => 'landing']);
?>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════ -->
<script>
// ── Pricing data (iz PHP) ─────────────────────────────────────
const PRICING = <?= json_encode($pricingData, JSON_UNESCAPED_UNICODE) ?>;
let isYearly = false;

function fmtEur(n) {
    return '€' + n.toFixed(2).replace('.', ',');
}

function toggleBilling() {
    isYearly = !isYearly;

    document.getElementById('toggle-knob').style.transform = isYearly ? 'translateX(24px)' : 'translateX(0)';
    document.getElementById('lbl-monthly').classList.toggle('text-forest/50', isYearly);
    document.getElementById('lbl-monthly').classList.toggle('text-forest',    !isYearly);
    document.getElementById('lbl-yearly').classList.toggle('text-forest/50',  !isYearly);
    document.getElementById('lbl-yearly').classList.toggle('text-forest',     isYearly);

    ['basic', 'advanced', 'premium'].forEach(slug => {
        const d = PRICING[slug];
        const mainPrice = isYearly
            ? (d.discY  ?? d.yearly)
            : (d.discM  ?? d.monthly);
        const origPrice = isYearly
            ? (d.discY  != null ? d.yearly  : null)
            : (d.discM  != null ? d.monthly : null);

        document.querySelector(`.price-amount-${slug}`).textContent = fmtEur(mainPrice);
        document.querySelector(`.price-period-${slug}`).textContent = isYearly ? '<?= t('common.per_year') ?>' : '<?= t('common.per_month') ?>';

        const origEl  = document.querySelector(`.price-orig-${slug}`);
        const discEl  = document.querySelector(`.disc-label-${slug}`);
        const invoBtn = document.querySelector(`.invoice-btn-${slug}`);

        if (origPrice != null) {
            origEl.textContent   = fmtEur(origPrice);
            origEl.style.display = '';
        } else {
            origEl.style.display = 'none';
        }

        const hasDisc = isYearly ? d.discY != null : d.discM != null;
        discEl.style.display = hasDisc ? '' : 'none';

        if (invoBtn) invoBtn.style.display = isYearly ? '' : 'none';
    });
}

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

// Odpri prvo vprašanje ob nalaganju
toggleFaq(0);
</script>

</body>
</html>
