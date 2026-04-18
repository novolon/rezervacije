<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/plans.php';

$appUrl = APP_URL . BASE_PATH;

// Pridobi cene + popuste iz baze
$pdo         = getDB();
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
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rezervacije – Sistem za upravljanje rezervacij v restavraciji</title>
    <meta name="description" content="Nadomestite papirne beležke s preglednim, sprotno posodabljajočim sistemom za rezervacije. Brez namestititve, brez skrite cene.">
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
            <a href="#" class="text-2xl font-bold text-forest tracking-tight">
                Rezervacije<span class="text-terracotta">.</span>
            </a>
            <div class="hidden md:flex items-center space-x-8">
                <a href="#features" class="text-forest/80 hover:text-forest font-medium transition-colors">Funkcionalnosti</a>
                <a href="#pricing"  class="text-forest/80 hover:text-forest font-medium transition-colors">Cenik</a>
                <a href="#faq"      class="text-forest/80 hover:text-forest font-medium transition-colors">FAQ</a>
                <a href="<?= $appUrl ?>/login.php"    class="text-forest/80 hover:text-forest font-medium transition-colors">Prijava</a>
                <a href="<?= $appUrl ?>/register.php" class="bg-terracotta hover:bg-terracotta-hover text-white px-6 py-2.5 rounded-full font-medium transition-colors shadow-sm">Začni brezplačno</a>
            </div>
            <button id="nav-toggle" class="md:hidden text-forest p-2">
                <svg id="nav-icon-menu" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                <svg id="nav-icon-close" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
    <div id="nav-mobile" class="md:hidden bg-cream border-b border-sage-light" style="display:none">
        <div class="px-4 pt-2 pb-6 flex flex-col space-y-4">
            <a href="#features" class="text-forest font-medium py-2" onclick="closeMobileNav()">Funkcionalnosti</a>
            <a href="#pricing"  class="text-forest font-medium py-2" onclick="closeMobileNav()">Cenik</a>
            <a href="#faq"      class="text-forest font-medium py-2" onclick="closeMobileNav()">FAQ</a>
            <a href="<?= $appUrl ?>/login.php"    class="text-forest font-medium py-2">Prijava za uporabnike</a>
            <a href="<?= $appUrl ?>/register.php" class="bg-terracotta text-white px-6 py-3 rounded-full font-medium text-center mt-4" onclick="closeMobileNav()">Začni brezplačno</a>
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
                Upravljanje rezervacij, ki ga vaša restavracija dejansko potrebuje
            </h1>
            <p class="text-lg md:text-xl text-forest/70 mb-10 leading-relaxed">
                Nadomestite papirne beležke in zmedo s preglednim, sprotnim sistemom za rezervacije,
                ki ga celotna ekipa uporablja – iz katerekoli naprave, brez namestititve.
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="<?= $appUrl ?>/register.php"
                   class="w-full sm:w-auto bg-terracotta hover:bg-terracotta-hover text-white px-8 py-4 rounded-full font-semibold text-lg transition-colors shadow-lg flex items-center justify-center gap-2">
                    Začnite 30-dnevni brezplačni preizkus
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                </a>
                <span class="text-sm text-forest/60 flex items-center gap-1.5">
                    <svg width="16" height="16" fill="none" stroke="#A3B18A" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    Brez kreditne kartice
                </span>
            </div>
        </div>

        <!-- Product Mockup -->
        <div class="relative mx-auto max-w-5xl">
            <div class="bg-white rounded-2xl shadow-2xl border border-sage-light overflow-hidden flex flex-col">
                <div class="bg-cream-dark/50 border-b border-sage-light px-4 py-3 flex items-center gap-2">
                    <div class="flex gap-1.5">
                        <div class="w-3 h-3 rounded-full bg-red-400"></div>
                        <div class="w-3 h-3 rounded-full bg-amber-400"></div>
                        <div class="w-3 h-3 rounded-full bg-green-400"></div>
                    </div>
                    <div class="mx-auto bg-white rounded-md px-32 py-1 text-xs text-forest/40 border border-sage-light">app.rezervacije.com</div>
                </div>
                <div class="flex h-[400px] md:h-[500px]">
                    <div class="w-56 border-r border-sage-light bg-cream/30 p-4 hidden md:flex flex-col gap-6">
                        <div class="flex items-center gap-2 text-forest font-bold text-lg mb-4">
                            <div class="w-8 h-8 bg-forest rounded-md flex items-center justify-center text-white text-sm">B</div>
                            Bistro Milano
                        </div>
                        <div class="space-y-1">
                            <div class="flex items-center gap-3 px-3 py-2 bg-white rounded-lg text-forest shadow-sm border border-sage-light font-medium text-sm">
                                <svg width="18" height="18" fill="none" stroke="#C4704B" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                Rezervacije
                            </div>
                            <div class="flex items-center gap-3 px-3 py-2 text-forest/60 text-sm rounded-lg">
                                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                Gostje
                            </div>
                        </div>
                    </div>
                    <div class="flex-1 bg-white p-6 flex flex-col gap-4 overflow-hidden">
                        <div class="flex justify-between items-center">
                            <h2 class="text-xl font-bold text-forest">Danes, 24. okt.</h2>
                            <div class="flex gap-2">
                                <button class="px-4 py-1.5 border border-sage-light rounded-lg text-sm font-medium text-forest">Mesečni pogled</button>
                                <button class="px-4 py-1.5 bg-forest text-white rounded-lg text-sm font-medium">+ Nova rezervacija</button>
                            </div>
                        </div>
                        <div class="flex-1 border border-sage-light rounded-xl overflow-hidden flex flex-col">
                            <div class="flex border-b border-sage-light bg-cream/30 text-xs font-medium text-forest/60">
                                <div class="w-20 border-r border-sage-light p-2 text-center">Miza</div>
                                <div class="flex-1 grid grid-cols-4">
                                    <div class="p-2 border-r border-sage-light">18:00</div>
                                    <div class="p-2 border-r border-sage-light">19:00</div>
                                    <div class="p-2 border-r border-sage-light">20:00</div>
                                    <div class="p-2">21:00</div>
                                </div>
                            </div>
                            <div class="flex-1 flex flex-col">
                                <?php for ($row = 1; $row <= 5; $row++): ?>
                                <div class="flex border-b border-sage-light flex-1 min-h-[60px]">
                                    <div class="w-20 border-r border-sage-light flex items-center justify-center text-sm font-medium text-forest/70 bg-cream/10">M<?= $row ?></div>
                                    <div class="flex-1 grid grid-cols-4 relative">
                                        <div class="border-r border-sage-light border-dashed"></div>
                                        <div class="border-r border-sage-light border-dashed"></div>
                                        <div class="border-r border-sage-light border-dashed"></div>
                                        <div></div>
                                        <?php if ($row === 1): ?>
                                        <div class="absolute top-2 bottom-2 left-[5%] right-[55%] bg-terracotta/10 border border-terracotta/30 rounded-md p-2 overflow-hidden">
                                            <div class="text-xs font-bold text-terracotta-hover">Novak (4)</div>
                                            <div class="text-[10px] text-terracotta">18:15 – 19:45</div>
                                        </div>
                                        <?php elseif ($row === 2): ?>
                                        <div class="absolute top-2 bottom-2 left-[30%] right-[20%] bg-forest/10 border border-forest/30 rounded-md p-2 overflow-hidden">
                                            <div class="text-xs font-bold text-forest">Horvat (2)</div>
                                            <div class="text-[10px] text-forest/70">19:00 – 20:30</div>
                                        </div>
                                        <?php elseif ($row === 4): ?>
                                        <div class="absolute top-2 bottom-2 left-[60%] right-[5%] bg-sage/20 border border-sage rounded-md p-2 overflow-hidden">
                                            <div class="text-xs font-bold text-forest">Kovač (6)</div>
                                            <div class="text-[10px] text-forest/70">20:00 – 22:00</div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endfor; ?>
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
        <p class="text-sm font-medium text-forest/50 uppercase tracking-wider mb-8">Zaupajo nam restavracije po vsej Evropi</p>
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
                <h2 class="text-3xl font-bold text-forest mb-8">Še vedno vodite rezervacije na papirju?</h2>
                <ul class="space-y-6">
                    <?php foreach ([
                        'Napačni telefonski zapisi in neberljiva pisava',
                        'Ekipa nima vpogleda v rezervacije v realnem času',
                        'Nemogoče upravljati več lokacij hkrati',
                        'Zmeda med osebjem v konicah',
                    ] as $item): ?>
                    <li class="flex items-start gap-4">
                        <svg class="shrink-0 mt-1 text-terracotta" width="24" height="24" fill="none" stroke="#C4704B" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>
                        <span class="text-lg text-forest/80"><?= htmlspecialchars($item) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="bg-forest p-8 md:p-12 rounded-3xl shadow-xl">
                <h2 class="text-3xl font-bold text-cream mb-8">Spoznajte Rezervacije</h2>
                <ul class="space-y-6">
                    <?php foreach ([
                        'Pregleden vizualni koledar, ki ga vsi razumejo',
                        'Sočasna sinhronizacija na vseh napravah',
                        'Upravljajte vse restavracije z enega računa',
                        'Vgrajene vloge osebja in preprost vmesnik',
                    ] as $item): ?>
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
            <h2 class="text-3xl md:text-4xl font-bold text-forest mb-4">Vse, kar potrebujete za upravljanje rezervacij</h2>
            <p class="text-lg text-forest/70">Vključeno v vseh paketih. Brez skritih stroškov, brez zapletene nastavitve.</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
            <?php
            $coreFeatures = [
                ['icon' => '<path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/>',
                 'title' => 'Vizualni koledar', 'desc' => 'Mesečni pregled z dnevnimi štetji in podrobna urna časovnica za vsak dan.'],
                ['icon' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
                 'title' => 'Več restavracij', 'desc' => 'Upravljajte vse lokacije z enega administratorskega računa in preklapljajte med njimi.'],
                ['icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                 'title' => 'Upravljanje osebja', 'desc' => 'Dodajte neomejeno računov osebja z dostopom glede na vlogo. Nastavitve ostanejo zaščitene.'],
                ['icon' => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
                 'title' => 'Posodobitve v realnem času', 'desc' => 'Spremembe se samodejno sinhronizirajo na vseh napravah. Brez osvežitve strani.'],
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
            <span class="inline-block px-4 py-1.5 bg-forest/10 text-forest font-semibold rounded-full text-sm mb-4">Napredni paket</span>
            <h2 class="text-3xl md:text-4xl font-bold text-forest mb-4">Obveščajte svoje goste</h2>
            <p class="text-lg text-forest/70 max-w-2xl">Avtomatizirajte komunikacijo in gostom omogočite spletno rezervacijo.</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <?php
            $advFeatures = [
                ['icon' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
                 'title' => 'E-poštna obvestila gostom', 'desc' => 'Samodejni potrditveni e-maili in opomniki 24 ur pred rezervacijo, poslani neposredno gostom.', 'soon' => false],
                ['icon' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
                 'title' => 'Javna rezervacijska povezava', 'desc' => 'Gostom dajte edinstveno URL za direktno rezervacijo brez klicanja. Brez zamujenih rezervacij.', 'soon' => true],
                ['icon' => '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
                 'title' => 'Potrjevanje rezervacij', 'desc' => 'Preglejte prispele zahteve in jih ročno potrdite ali zavrnite, preden gost dobi potrdilo.', 'soon' => true],
            ];
            foreach ($advFeatures as $f): ?>
            <div class="bg-cream p-8 rounded-2xl border border-sage-light relative overflow-hidden">
                <div class="text-forest mb-6">
                    <svg width="28" height="28" fill="none" stroke="#1B4332" stroke-width="2"><?= $f['icon'] ?></svg>
                </div>
                <h3 class="text-xl font-bold text-forest mb-3"><?= htmlspecialchars($f['title']) ?></h3>
                <p class="text-forest/70 leading-relaxed mb-4"><?= htmlspecialchars($f['desc']) ?></p>
                <?php if ($f['soon']): ?>
                <span class="inline-block px-3 py-1 bg-sage/30 text-forest/80 text-xs font-bold rounded-md uppercase tracking-wider">Kmalu na voljo</span>
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
            <span class="inline-block px-4 py-1.5 bg-terracotta text-white font-semibold rounded-full text-sm mb-4">Premium paket</span>
            <h2 class="text-3xl md:text-4xl font-bold mb-4">Profesionalna orodja za resne gostince</h2>
            <p class="text-lg text-cream/70 max-w-2xl">Popolnoma personalizirane rezervacijske izkušnje in napredna avtomatizacija.</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
            <?php
            $premFeatures = [
                ['icon' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
                 'title' => 'Vgradljivi widget', 'desc' => 'Ena vrstica JavaScripta za vgradnjo rezervacijskega obrazca neposredno na vašo spletno stran.', 'soon' => true],
                ['icon' => '<circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
                 'title' => 'Branding restavracije', 'desc' => 'Naložite logotip in nastavite barve blagovne znamke za strani in e-maile gostom.', 'soon' => false],
                ['icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                 'title' => 'Samodejno potrjevanje', 'desc' => 'Samodejno potrdite manjše skupinice, večje pa preglejte ročno.', 'soon' => true],
                ['icon' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
                 'title' => 'SMS obvestila', 'desc' => 'SMS potrdila in opomniki gostom za zmanjšanje izostankov.', 'soon' => true],
            ];
            foreach ($premFeatures as $f): ?>
            <div class="bg-forest-light/30 p-8 rounded-2xl border border-forest-light relative">
                <div class="text-terracotta mb-6">
                    <svg width="28" height="28" fill="none" stroke="#C4704B" stroke-width="2"><?= $f['icon'] ?></svg>
                </div>
                <h3 class="text-xl font-bold mb-3"><?= htmlspecialchars($f['title']) ?></h3>
                <p class="text-cream/70 leading-relaxed mb-4"><?= htmlspecialchars($f['desc']) ?></p>
                <?php if ($f['soon']): ?>
                <span class="inline-block px-3 py-1 bg-forest text-cream/60 text-xs font-bold rounded-md uppercase tracking-wider border border-forest-light">Kmalu na voljo</span>
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
            <h2 class="text-3xl md:text-4xl font-bold text-forest mb-4">Pripravljeni v minutah</h2>
            <p class="text-lg text-forest/70">Brez tehnične nastavitve, brez namestitve aplikacije.</p>
        </div>
        <div class="relative">
            <div class="hidden md:block absolute top-8 left-[10%] right-[10%] h-0.5 bg-sage-light z-0"></div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-12 relative z-10">
                <?php foreach ([
                    ['1', 'Registracija',             'Ustvarite račun v sekundi. Za 30-dnevni preizkus ni potrebna kreditna kartica.'],
                    ['2', 'Nastavite restavracijo',   'Dodajte lokacije, nastavite delovni čas in povabite člane osebja.'],
                    ['3', 'Začnite sprejemati rezervacije', 'Upravljajte rezervacije z vsake naprave, v realnem času. Poslovite se od papirja.'],
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
            <h2 class="text-3xl md:text-4xl font-bold text-forest mb-6">Preprosta, pregledna cena</h2>
            <div class="flex items-center justify-center gap-4">
                <span id="lbl-monthly" class="text-sm font-medium text-forest">Mesečno</span>
                <button id="billing-toggle" onclick="toggleBilling()"
                    class="relative w-14 h-8 bg-forest rounded-full p-1 transition-colors">
                    <div id="toggle-knob" class="w-6 h-6 bg-white rounded-full shadow-sm transition-transform duration-200" style="transform:translateX(0)"></div>
                </button>
                <span id="lbl-yearly" class="text-sm font-medium text-forest/50 flex items-center gap-2">
                    Letno
                    <span class="bg-terracotta/10 text-terracotta text-xs px-2 py-0.5 rounded-full font-bold">~17% ugodneje</span>
                </span>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-12">

            <?php
            $planCards = [
                ['basic',    false, 'Idealno za posamezne lokacije, ki prehajajo s papirja.',
                    ['Upravljanje rezervacij', 'Več restavracij', 'Neomejeno računov osebja', 'Koledar v realnem času', 'E-poštna podpora']],
                ['advanced', true,  'Za restavracije, ki želijo spletne rezervacije in orodja za goste.',
                    ['Vse iz Osnovnega', 'E-poštna obvestila gostom', 'Opomniki 24h pred rezervacijo', 'Javna rezervacijska povezava', 'Potrjevanje/zavračanje rezervacij', 'Baza gostov', 'Čakalna lista', 'Upravljanje miz']],
                ['premium',  false, 'Profesionalna orodja in polni branding.',
                    ['Vse iz Naprednega', 'Branding restavracije', 'Vgradljivi widget', 'Samodejno potrjevanje', 'SMS obvestila', 'Ankete + CSV izvoz']],
            ];
            $planNames = ['basic' => 'Osnovni', 'advanced' => 'Napredni', 'premium' => 'Premium'];
            foreach ($planCards as [$slug, $highlighted, $desc, $features]):
                $d = $pricingData[$slug];
                $showDisc = $d['discM'] !== null;
                $mainPrice = initialMonthly($d);
                $origPrice = $showDisc ? fmtPrice($d['monthly']) : null;
            ?>
            <div class="relative flex flex-col p-8 rounded-3xl border <?= $highlighted ? 'border-terracotta shadow-xl bg-cream' : 'border-sage-light bg-white' ?>">
                <?php if ($highlighted): ?>
                <div class="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-terracotta text-white px-4 py-1 rounded-full text-sm font-bold shadow-sm">Priljubljen</div>
                <?php endif; ?>

                <!-- 30-day trial badge -->
                <div class="inline-flex items-center gap-1.5 bg-sage/15 text-forest text-xs font-semibold px-3 py-1 rounded-full mb-4 self-start border border-sage/30">
                    <svg width="11" height="11" fill="none" stroke="#A3B18A" stroke-width="2.5"><polyline points="10 3 4.5 8.5 2 6"/></svg>
                    30 dni brezplačno
                </div>

                <div class="mb-6">
                    <h3 class="text-xl font-bold text-forest mb-2"><?= $planNames[$slug] ?></h3>
                    <p class="text-forest/60 text-sm"><?= htmlspecialchars($desc) ?></p>
                </div>

                <div class="mb-1 flex items-baseline gap-2 flex-wrap">
                    <span class="price-orig-<?= $slug ?> text-lg text-forest/40 line-through"
                          style="<?= $showDisc ? '' : 'display:none' ?>"><?= $origPrice ?></span>
                    <span class="price-amount-<?= $slug ?> text-4xl font-bold text-forest"><?= $mainPrice ?></span>
                    <span class="price-period-<?= $slug ?> text-forest/60 font-medium">/mesec</span>
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
                    Začni brezplačni preizkus
                </a>
                <a href="<?= $appUrl ?>/register.php?plan=<?= $slug ?>"
                   class="invoice-btn-<?= $slug ?> block w-full mt-3 py-2 border border-dashed border-sage text-center text-forest/60 hover:text-forest text-sm rounded-xl transition-colors"
                   style="display:none">
                    ali po predračunu →
                </a>
            </div>
            <?php endforeach; ?>

        </div>

        <div class="text-center text-forest/60 text-sm space-y-2">
            <p class="font-medium text-forest/80">Vsi paketi vključujejo neomejeno računov osebja in podporo za več restavracij.</p>
            <p>Po preteku 30-dnevnega triala izberite paket, ki vam ustreza. Nadgradnja kadarkoli – plačate samo sorazmerno razliko.</p>
            <p>Letno plačilo je možno tudi po predračunu — <a href="<?= $appUrl ?>/register.php" class="underline hover:text-terracotta">kontaktirajte nas</a>.</p>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     FAQ
════════════════════════════════════════════════════════════ -->
<section id="faq" class="py-24 bg-cream">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-16">
            <h2 class="text-3xl md:text-4xl font-bold text-forest mb-4">Pogosta vprašanja</h2>
        </div>
        <div class="space-y-4">
            <?php
            $faqs = [
                ['Ali moram kaj namestiti?',
                 'Ne. Sistem je popolnoma spletni – odprite brskalnik in se prijavite iz katerekoli naprave.'],
                ['Ali lahko upravljam več lokacij restavracije?',
                 'Da. Vsi paketi podpirajo več restavracij pod enim administratorskim računom.'],
                ['Koliko računov osebja lahko dodam?',
                 'Število računov osebja ni omejeno.'],
                ['Ali obstaja brezplačni preizkus?',
                 'Da – katerikoli paket (Osnovni, Napredni, Premium) lahko preizkušate 30 dni brezplačno. Kreditna kartica ni potrebna. Med trialom lahko prosto preklapljate med paketi.'],
                ['Ali lahko med trialom zamenjam paket?',
                 'Da, brez omejitev in brez plačila. V nastavitvah zaračunavanja izberite drug paket in sprememba začne veljati takoj.'],
                ['Ali lahko kadarkoli prekličem naročnino?',
                 'Da. Prekličete kadarkoli v nastavitvah zaračunavanja.'],
                ['Ali ponujate letno plačilo po predračunu?',
                 'Da. Kontaktirajte nas za dogovor o letnem plačilu z bančnim nakazilom / predračunom.'],
                ['Katere načine plačila sprejemate?',
                 'Kreditne in debetne kartice prek Stripe. Letni paketi so plačljivi tudi po predračunu.'],
                ['Ali so moji podatki varni?',
                 'Podatki so ločeni po računih. Gesla so zgoščena (bcrypt). Seje potečejo po neaktivnosti. HTTPS je obvezen.'],
                ['Kaj je javna rezervacijska povezava?',
                 'Edinstvena URL, ki jo gosti obiščejo za direktno rezervacijo mize – brez klicanja. Na voljo v Naprednem in Premium paketu. (Kmalu na voljo)'],
                ['Ali lahko vgradim obrazec za rezervacije na svojo spletno stran?',
                 'Da, v Premium paketu. Kratka JavaScript koda omogoči vgradnjo rezervacijskega obrazca neposredno na katerokoli spletno stran. (Kmalu na voljo)'],
            ];
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
        <h2 class="text-4xl md:text-5xl font-bold text-cream mb-6">Pripravljeni na modernizacijo rezervacij?</h2>
        <p class="text-xl text-cream/80 mb-10">Začnite 30-dnevni brezplačni preizkus – brez kreditne kartice.</p>
        <a href="<?= $appUrl ?>/register.php"
           class="inline-block bg-terracotta hover:bg-terracotta-hover text-white px-10 py-4 rounded-full font-bold text-lg transition-colors shadow-xl">
            Začni brezplačno
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
                <a href="#" class="text-2xl font-bold text-cream tracking-tight mb-4 block">
                    Rezervacije<span class="text-terracotta">.</span>
                </a>
                <p class="text-sm max-w-sm text-cream/60">Preprost, sodoben sistem za upravljanje rezervacij, zasnovan za restavracije – od posameznih lokacij do verig.</p>
            </div>
            <div>
                <h4 class="text-cream font-semibold mb-4">Produkt</h4>
                <ul class="space-y-2 text-sm">
                    <li><a href="#features" class="hover:text-terracotta transition-colors">Funkcionalnosti</a></li>
                    <li><a href="#pricing"  class="hover:text-terracotta transition-colors">Cenik</a></li>
                    <li><a href="#faq"      class="hover:text-terracotta transition-colors">FAQ</a></li>
                </ul>
            </div>
            <div>
                <h4 class="text-cream font-semibold mb-4">Pravno</h4>
                <ul class="space-y-2 text-sm">
                    <li><a href="#" class="hover:text-terracotta transition-colors">Kontakt</a></li>
                    <li><a href="#" class="hover:text-terracotta transition-colors">Politika zasebnosti</a></li>
                    <li><a href="#" class="hover:text-terracotta transition-colors">Pogoji uporabe</a></li>
                </ul>
            </div>
        </div>
        <div class="mt-12 pt-8 border-t border-forest-light/30 text-sm text-cream/50 text-center md:text-left">
            &copy; <?= date('Y') ?> Rezervacije. Vse pravice pridržane.
        </div>
    </div>
</footer>

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
        document.querySelector(`.price-period-${slug}`).textContent = isYearly ? '/leto' : '/mesec';

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
