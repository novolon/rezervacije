<?php
/**
 * Branding helper — vrne brandinške nastavitve za restavracijo z upoštevanjem plana.
 *
 * Premium uporabniki lahko:
 *   - naložijo lasten logotip (custom_logo)
 *   - urejajo primarno/sekundarno barvo (custom_colors)
 *   - skrijejo "by Rezble" oznako (hide_branding)
 *
 * Vsi drugi paketi vidijo privzete Rezble barve in "by Rezble" link.
 *
 * Defaults se ujemajo z obstoječim widget.js + book.php "Forest/Terracotta" temo.
 */

if (!function_exists('get_restaurant_branding')) {

/**
 * @param array $rest  Vrstica iz `restaurants` tabele (mora vsebovati owner_id).
 * @return array       ['logo_url'|null, 'primary'#RRGGBB, 'secondary'#RRGGBB, 'hide_branding'bool]
 */
function get_restaurant_branding(PDO $pdo, array $rest): array {
    $defaults = [
        'logo_url'      => null,
        'primary'       => '#1B4332', // forest
        'secondary'     => '#C4704B', // terracotta
        'hide_branding' => false,
    ];

    if (empty($rest['owner_id'])) return $defaults;
    if (!function_exists('user_has_feature')) {
        require_once __DIR__ . '/plans.php';
    }

    $ownerId = (int)$rest['owner_id'];
    $hasLogo   = user_has_feature($pdo, $ownerId, 'custom_logo');
    $hasColors = user_has_feature($pdo, $ownerId, 'custom_colors');
    $hasHide   = user_has_feature($pdo, $ownerId, 'hide_branding');

    $out = $defaults;

    if ($hasLogo && !empty($rest['logo_path'])) {
        // logo_path je shranjen kot relativna pot npr. "uploads/logos/12_abc.png".
        $out['logo_url'] = (defined('BASE_PATH') ? BASE_PATH : '') . '/' . ltrim((string)$rest['logo_path'], '/');
    }
    if ($hasColors) {
        if (!empty($rest['brand_primary'])   && preg_match('/^#[0-9A-Fa-f]{6}$/', $rest['brand_primary']))   $out['primary']   = $rest['brand_primary'];
        if (!empty($rest['brand_secondary']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $rest['brand_secondary'])) $out['secondary'] = $rest['brand_secondary'];
    }
    if ($hasHide && !empty($rest['hide_branding'])) {
        $out['hide_branding'] = true;
    }

    return $out;
}

/**
 * Markup za "Powered by Rezble" attribution. Vrne prazno če je branding skrit.
 */
function rezble_attribution_html(bool $hideBranding, string $lang = 'sl'): string {
    if ($hideBranding) return '';
    $labels = [
        'sl' => 'Brez skrbi z',
        'en' => 'Powered by',
        'de' => 'Bereitgestellt von',
        'es' => 'Funciona con',
        'fr' => 'Propulsé par',
        'hr' => 'Pokreće',
        'it' => 'Powered by',
        'pt' => 'Com tecnologia',
    ];
    $label = $labels[$lang] ?? $labels['en'];
    return '<div class="rz-attribution" style="text-align:center;font-size:11px;color:rgba(0,0,0,.4);padding:10px 14px;line-height:1.4">'
        . htmlspecialchars($label) . ' '
        . '<a href="https://rezble.com" target="_blank" rel="noopener" style="color:inherit;font-weight:600;text-decoration:none;border-bottom:1px solid rgba(0,0,0,.2)">Rezble</a>'
        . '</div>';
}

} // function_exists guard
