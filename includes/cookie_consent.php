<?php
/**
 * Rezble cookie consent (GDPR + ePrivacy).
 *
 * En sam consent za vse površine: rezble.com (landing, booked) in
 * app.rezble.com (admin app, affiliate, public booking) – cookie postavimo
 * na apex domeno ".rezble.com", da je deljen med subdomenami.
 *
 * Uporaba (kjerkoli – v <head> ALI tik pred </body>; output je samo CSS+JS+config,
 *         banner in modal lazy ustvari JS po potrebi):
 *   require_once __DIR__ . '/cookie_consent.php';
 *   rez_consent_render(['surface' => 'landing']);
 *
 * Server-side gating:
 *   if (rez_consent_allows('analytics')) { ... }
 *
 * Cookie format (rez_consent):
 *   { "v":1, "ts": <unix>, "cats": { "necessary":1, "functional":0, "analytics":0, "marketing":0 } }
 */

const REZ_CONSENT_COOKIE   = 'rez_consent';
const REZ_CONSENT_VERSION  = 1;
const REZ_CONSENT_TTL_DAYS = 180; // 6 mesecev → re-prompt (EDPB priporočilo)

/**
 * Domena za consent cookie:
 *  - prod (gostitelj se konča z rezble.com) → ".rezble.com" (apex, deljeno)
 *  - dev / drugo                            → '' (current host)
 */
function rez_consent_cookie_domain(): string {
    $host = parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_HOST) ?? '';
    if ($host && preg_match('/(?:^|\.)rezble\.com$/i', $host)) {
        return '.rezble.com';
    }
    return '';
}

/**
 * Trenutne consent vrednosti (server-side branje cookieja).
 * Vrne: ['necessary'=>true, 'functional'=>bool, 'analytics'=>bool, 'marketing'=>bool, '_set'=>bool]
 *  '_set' je true samo če je uporabnik dejansko izrazil odločitev.
 */
function rez_consent_state(): array {
    $defaults = ['necessary' => true, 'functional' => false, 'analytics' => false, 'marketing' => false, '_set' => false];
    if (empty($_COOKIE[REZ_CONSENT_COOKIE])) return $defaults;
    $raw = json_decode($_COOKIE[REZ_CONSENT_COOKIE], true);
    if (!is_array($raw) || ($raw['v'] ?? null) !== REZ_CONSENT_VERSION) return $defaults;
    $cats = is_array($raw['cats'] ?? null) ? $raw['cats'] : [];
    return [
        'necessary'  => true,
        'functional' => !empty($cats['functional']),
        'analytics'  => !empty($cats['analytics']),
        'marketing'  => !empty($cats['marketing']),
        '_set'       => true,
    ];
}

/**
 * Server-side gating helper.
 * if (rez_consent_allows('marketing')) setcookie('rez_aff', ...);
 */
function rez_consent_allows(string $category): bool {
    $s = rez_consent_state();
    if ($category === 'necessary') return true;
    if (!$s['_set']) return false; // pred sprejemom: samo necessary
    return !empty($s[$category]);
}

/**
 * Ali je banner sploh treba prikazati (= consent ni bil še nikoli izrazen).
 */
function rez_consent_needs_banner(): bool {
    return !rez_consent_state()['_set'];
}

/**
 * Vrne pot do public Cookie politike.
 */
function rez_consent_policy_url(): string {
    $base = defined('APP_URL') ? APP_URL : '';
    $bp   = defined('BASE_PATH') ? BASE_PATH : '';
    return rtrim($base, '/') . $bp . '/cookie-policy.php';
}

/**
 * Izriše <link>+<script>+config – head-safe (lahko v <head> ali pred </body>).
 * Banner + modal markup lazy ustvari JS prvič, ko ju je treba prikazati.
 *
 * @param array $opts
 *   - surface: 'landing'|'booked'|'app'|'affiliate'|'book_public'  (telemetry hint)
 *   - force:   true → vedno upodobi konfiguracijo (privzeto true; output je idempotenten)
 */
function rez_consent_render(array $opts = []): void {
    static $rendered = false;
    if ($rendered) return;
    $rendered = true;

    $surface = $opts['surface'] ?? 'app';

    $bp      = defined('BASE_PATH') ? BASE_PATH : '';
    $cssHref = $bp . '/assets/css/cookie-consent.css?v=' . @filemtime(__DIR__ . '/../assets/css/cookie-consent.css');
    $jsSrc   = $bp . '/assets/js/cookie-consent.js?v=' . @filemtime(__DIR__ . '/../assets/js/cookie-consent.js');

    // Strings za UI – tako lahko PHP nadzira jezik (Slovenščina default).
    $strings = [
        'banner' => [
            'title' => 'Cenimo vašo zasebnost',
            'text'  => 'Uporabljamo piškotke za delovanje storitve, izboljšavo vsebine in (z vašo privolitvijo) merjenje uporabe. Sami izberete, kaj sprejmete – odločitev lahko kadarkoli spremenite.',
            'more'  => 'Več v Politiki piškotkov',
            'reject'=> 'Zavrni vse',
            'prefs' => 'Nastavitve',
            'accept'=> 'Sprejmi vse',
        ],
        'modal' => [
            'title'     => 'Nastavitve piškotkov',
            'close'     => 'Zapri',
            'intro'     => 'Sami izberete, kateri piškotki se naložijo. Nujni piškotki so vedno aktivni, ker omogočajo osnovno delovanje. Več podrobnosti najdete v',
            'introLink' => 'Politiki piškotkov',
            'always'    => 'Vedno aktivno',
            'reject'    => 'Zavrni vse',
            'accept'    => 'Sprejmi vse',
            'save'      => 'Shrani izbiro',
        ],
        'cats' => [
            [
                'key'    => 'necessary',
                'always' => true,
                'title'  => 'Nujni',
                'desc'   => 'Potrebni za delovanje storitve – prijava, varnost, ohranitev seje, izbira jezika ob prijavi. Ne morete jih zavrniti, ker brez njih sistem ne deluje.',
            ],
            [
                'key'    => 'functional',
                'always' => false,
                'title'  => 'Funkcionalni',
                'desc'   => 'Shranjujejo nastavitve (npr. jezik na javnih straneh, izbira pogleda, ostanek prijavljen) za boljšo uporabniško izkušnjo.',
            ],
            [
                'key'    => 'analytics',
                'always' => false,
                'title'  => 'Analitika',
                'desc'   => 'Anonimno merjenje uporabe (PostHog, EU regija) – število obiskov, najpogosteje uporabljene funkcionalnosti, agregirane statistike. Brez teh ne moremo izboljševati produkta na podlagi resnične uporabe.',
            ],
            [
                'key'    => 'marketing',
                'always' => false,
                'title'  => 'Trženje',
                'desc'   => 'Sledenje priporočilom (affiliate program) – beleženje, kateri partner vas je usmeril, da partner prejme pripadajoče provizije. Ne uporabljamo oglaševalskih pikslov tretjih oseb.',
            ],
        ],
    ];

    $config = [
        'cookieName'   => REZ_CONSENT_COOKIE,
        'version'      => REZ_CONSENT_VERSION,
        'ttlDays'      => REZ_CONSENT_TTL_DAYS,
        'cookieDomain' => rez_consent_cookie_domain(),
        'policyUrl'    => rez_consent_policy_url(),
        'surface'      => $surface,
        'autoShow'     => rez_consent_needs_banner(),
        'strings'      => $strings,
    ];
    $configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    ?>
<link rel="stylesheet" href="<?= htmlspecialchars($cssHref, ENT_QUOTES) ?>">
<script>window.__REZ_CC_CONFIG__ = <?= $configJson ?>;</script>
<script src="<?= htmlspecialchars($jsSrc, ENT_QUOTES) ?>" defer></script>
    <?php
}
