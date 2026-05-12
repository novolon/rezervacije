<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/lang.php';

$appUrl     = APP_URL . BASE_PATH;
$lastUpdate = '2026-05-07';
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
    <title>Politika piškotkov – Rezble</title>
    <meta name="description" content="Pregled vseh piškotkov, ki jih uporablja Rezble (rezervacijski sistem, landing, booked, affiliate program).">
    <meta name="robots" content="noindex,follow">
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
</head>
<body class="font-sans bg-cream text-forest min-h-screen">

<header class="border-b border-sage-light bg-cream/90 backdrop-blur sticky top-0 z-40">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-5 flex items-center justify-between">
        <a href="<?= $appUrl ?>/" class="flex items-center gap-2">
            <img src="<?= $appUrl ?>/assets/images/Rezble.svg" alt="Rezble" style="height:28px;width:auto">
        </a>
        <a href="#" data-rez-consent="open" class="text-sm font-semibold text-terracotta hover:text-terracotta-hover">Cookie nastavitve</a>
    </div>
</header>

<main class="max-w-3xl mx-auto px-4 sm:px-6 py-12 md:py-16">
    <h1 class="text-3xl md:text-4xl font-bold text-forest mb-3">Politika piškotkov</h1>
    <p class="text-forest/60 mb-10 text-sm">Zadnja posodobitev: <?= htmlspecialchars($lastUpdate) ?></p>

    <section class="prose-rez space-y-4 mb-10 text-forest/85 leading-relaxed">
        <p>Ta politika opisuje, katere piškotke in podobne tehnologije uporabljamo na <strong>rezble.com</strong> (predstavitvena stran, blog Booked) in <strong>app.rezble.com</strong> (rezervacijski sistem, javne rezervacijske strani, affiliate portal). Politika se uporablja skupaj z našo Politiko zasebnosti.</p>
        <p>Piškotki so majhne tekstovne datoteke, ki jih spletna stran shrani v vašem brskalniku. Z njimi si zapomni nastavitve, omogoča prijavo in (z vašo privolitvijo) meri uporabo, da lahko izboljšujemo storitev.</p>
    </section>

    <section class="mb-10">
        <h2 class="text-xl font-bold text-forest mb-4">Vaša izbira</h2>
        <p class="mb-4 text-forest/85 leading-relaxed">Ob prvem obisku vas vprašamo, katere piškotke sprejmete. Odločitev shranimo v piškotku <code class="bg-cream-dark/60 px-2 py-0.5 rounded text-[13px]">rez_consent</code> in jo lahko kadarkoli spremenite preko gumba spodaj ali povezave »Cookie nastavitve« v glavi te strani.</p>
        <button type="button" data-rez-consent="open" class="bg-terracotta hover:bg-terracotta-hover text-white px-6 py-2.5 rounded-full font-semibold text-sm transition-colors shadow-sm">Spremeni nastavitve piškotkov</button>
    </section>

    <section class="mb-10">
        <h2 class="text-xl font-bold text-forest mb-4">Kategorije piškotkov</h2>
        <div class="space-y-4">
            <?php
            $cats = [
                ['Nujni',        'Vedno aktivni – brez njih sistem ne deluje (prijava, varnost, ohranitev seje, izbira jezika).'],
                ['Funkcionalni', 'Shranjujejo vaše nastavitve (jezik na javnih straneh, izbira pogleda, »ostani prijavljen«).'],
                ['Analitika',    'Anonimno merjenje uporabe (PostHog v EU regiji) – pomaga nam razumeti, katere funkcionalnosti so uporabne.'],
                ['Trženje',      'Sledenje priporočilom v okviru affiliate programa, da partner prejme provizijo. Ne uporabljamo oglaševalskih pikslov tretjih oseb.'],
            ];
            foreach ($cats as [$t, $d]): ?>
            <div class="bg-white border border-sage-light rounded-xl p-5">
                <h3 class="font-bold text-forest mb-1"><?= htmlspecialchars($t) ?></h3>
                <p class="text-forest/75 text-sm leading-relaxed"><?= htmlspecialchars($d) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="mb-10">
        <h2 class="text-xl font-bold text-forest mb-4">Seznam piškotkov</h2>
        <div class="overflow-x-auto bg-white border border-sage-light rounded-xl">
            <table class="w-full text-sm">
                <thead class="bg-cream-dark/40 text-forest/70 text-[11px] font-bold uppercase tracking-wider">
                    <tr>
                        <th class="text-left px-4 py-3">Ime</th>
                        <th class="text-left px-4 py-3">Domena</th>
                        <th class="text-left px-4 py-3">Trajanje</th>
                        <th class="text-left px-4 py-3">Kategorija</th>
                        <th class="text-left px-4 py-3">Namen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sage-light/60">
                    <?php
                    $rows = [
                        ['PHPSESSID',       'rezble.com / app.rezble.com', 'seja',     'Nujni',        'PHP seja – prijava, košarica, varnostni žeton.'],
                        ['rem_tok',         'rezble.com / app.rezble.com', '30 dni',   'Nujni',        'Žeton »ostani prijavljen« (postavljen samo, če uporabnik to izrecno izbere).'],
                        ['rzlang',          'rezble.com / app.rezble.com', '365 dni',  'Nujni',        'Izbrani jezik vmesnika – nastavljen ob izrecni izbiri jezika.'],
                        ['rez_consent',     '.rezble.com',                  '180 dni',  'Nujni',        'Shranjuje vaše izbire glede piškotkov (ta sistem).'],
                        ['_ph_anon',        'rezble.com / app.rezble.com', '365 dni',  'Analitika',    'Anonimni distinct ID za zlitje server-side dogodkov v PostHog (postavljen samo s privolitvijo).'],
                        ['ph_*',            '.rezble.com',                  '365 dni',  'Analitika',    'PostHog SDK – session, distinct ID, feature flagi, opt-in/out (postavljeni samo s privolitvijo).'],
                        ['rez_aff',         'rezble.com / app.rezble.com', '60 dni',   'Trženje',      'Affiliate referral atribucija – spremlja, kateri partner vas je usmeril (postavljen samo s privolitvijo).'],
                    ];
                    foreach ($rows as [$name, $dom, $life, $cat, $purpose]): ?>
                    <tr>
                        <td class="px-4 py-3 font-mono text-forest font-semibold"><?= htmlspecialchars($name) ?></td>
                        <td class="px-4 py-3 text-forest/70 text-[12.5px]"><?= htmlspecialchars($dom) ?></td>
                        <td class="px-4 py-3 text-forest/70"><?= htmlspecialchars($life) ?></td>
                        <td class="px-4 py-3 text-forest/70"><?= htmlspecialchars($cat) ?></td>
                        <td class="px-4 py-3 text-forest/85 text-[13px]"><?= htmlspecialchars($purpose) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-[12px] text-forest/55 mt-3">Seznam dopolnjujemo, kadar uvedemo nove integracije. Za dodatne piškotke (npr. nove integracije) bomo pred njihovim postavljanjem zahtevali ustrezno privolitev.</p>
    </section>

    <section class="mb-10">
        <h2 class="text-xl font-bold text-forest mb-4">Kako spremeniti ali umakniti privolitev</h2>
        <ul class="list-disc pl-5 space-y-2 text-forest/85 leading-relaxed">
            <li>Kliknite »<a href="#" data-rez-consent="open" class="underline text-terracotta hover:text-terracotta-hover">Cookie nastavitve</a>« kjerkoli na strani – tudi tu zgoraj.</li>
            <li>V brskalniku lahko izbrišete piškotke za to spletno stran (pri naslednjem obisku vas bomo znova vprašali).</li>
            <li>Privolitev bomo ponovno zahtevali tudi po izteku 6-mesečnega obdobja veljavnosti.</li>
        </ul>
    </section>

    <section class="mb-10">
        <h2 class="text-xl font-bold text-forest mb-4">Prenosi v tretje države</h2>
        <p class="text-forest/85 leading-relaxed">Vsi analitični podatki gredo prek PostHog EU regije (<code class="bg-cream-dark/60 px-2 py-0.5 rounded text-[13px]">eu.i.posthog.com</code>) in se obdelujejo znotraj Evropskega gospodarskega prostora. Prenosov v tretje države ne izvajamo.</p>
    </section>

    <section class="mb-10">
        <h2 class="text-xl font-bold text-forest mb-4">Kontakt</h2>
        <p class="text-forest/85 leading-relaxed">Vprašanja o piškotkih ali vaših pravicah po GDPR? Pišite nam: <a href="mailto:privacy@rezble.com" class="underline text-terracotta hover:text-terracotta-hover">privacy@rezble.com</a></p>
    </section>
</main>

<footer class="border-t border-sage-light bg-cream-dark/30 py-8 mt-12">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 flex flex-col sm:flex-row gap-4 justify-between items-center text-sm text-forest/60">
        <span>&copy; <?= date('Y') ?> Rezble</span>
        <div class="flex gap-4">
            <a href="<?= $appUrl ?>/" class="hover:text-terracotta">Domov</a>
            <a href="#" data-rez-consent="open" class="hover:text-terracotta">Cookie nastavitve</a>
        </div>
    </div>
</footer>

<?php
require_once __DIR__ . '/includes/cookie_consent.php';
rez_consent_render(['surface' => 'cookie_policy']);
?>
</body>
</html>
