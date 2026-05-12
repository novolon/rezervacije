<?php
/**
 * PostHog client-side init.
 *
 * Vključi v <head> ali tik pred </body> v vseh straneh, kjer želiš tracking.
 * Avtomatsko prepozna kontekst (admin app, public booking, register, ...) in
 * postavi person properties iz PHP session (če obstaja).
 *
 * Uporaba (v PHP):
 *   require_once __DIR__ . '/posthog_init.php';
 *   posthog_render_init([
 *       'context'   => 'admin',           // ali 'register', 'login', 'book_public', 'widget', 'blog'
 *       'identify'  => true,              // identificiraj user_id če je v session-ju (default: true)
 *       'extra'     => ['foo' => 'bar'],  // dodatne global properties (opcijsko)
 *   ]);
 */

if (!function_exists('posthog_render_init')) {

function posthog_render_init(array $opts = []): void {
    if (!defined('POSTHOG_KEY') || POSTHOG_KEY === '') return; // ni konfigurirano

    $context  = $opts['context']  ?? 'app';
    $identify = $opts['identify'] ?? true;
    $extra    = $opts['extra']    ?? [];

    // Cookie consent gating: PostHog je analytics → potrebuje 'analytics' privolitev.
    // Snippet vedno pripravi window.posthog stub (capture-i se pufrajo do init-a),
    // pravi init pa sproži šele consent listener.
    require_once __DIR__ . '/cookie_consent.php';
    $analyticsAllowed = rez_consent_allows('analytics');

    // Person/session properties iz session-ja (admin app), če so na voljo
    $personProps = [];
    $distinctId  = null;
    if ($identify && session_status() === PHP_SESSION_ACTIVE) {
        if (!empty($_SESSION['user_id'])) {
            $distinctId = (string)$_SESSION['user_id'];
            $personProps = [
                'email'         => $_SESSION['email']        ?? null,
                'name'          => $_SESSION['full_name']    ?? null,
                'role'          => $_SESSION['role']         ?? null,
                'plan'          => $_SESSION['plan_slug']    ?? null,
                'restaurant_id' => $_SESSION['restaurant_id'] ?? null,
            ];
            // Odstrani prazne vrednosti
            $personProps = array_filter($personProps, fn($v) => $v !== null && $v !== '');
        }
    }

    $globalProps = array_merge([
        'app_context' => $context,
        'app_lang'    => $_COOKIE['rzlang'] ?? 'sl',
    ], $extra);

    $key  = POSTHOG_KEY;
    $host = defined('POSTHOG_HOST') && POSTHOG_HOST !== '' ? POSTHOG_HOST : 'https://eu.i.posthog.com';
    $keyJson         = json_encode($key);
    $hostJson        = json_encode($host);
    $globalPropsJson = json_encode($globalProps, JSON_UNESCAPED_UNICODE);
    $distinctIdJson  = json_encode($distinctId);
    $personPropsJson = json_encode($personProps, JSON_UNESCAPED_UNICODE);

    $autoStartJson = json_encode((bool)$analyticsAllowed);

    // PostHog snippet (uradni — minimalno spremenjen). API zagotavlja, da je window.posthog
    // dostopen takoj (proxy pred async loadom).
    // Pravi init je obvit v rezPosthogStart(): pokliče se le, če uporabnik privoli v 'analytics'.
    // Stub posthog.* klici, ki se zgodijo prej, se pufrajo in izvedejo, ko se SDK naloži.
    echo <<<HTML
<script>
!function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.crossOrigin="anonymous",p.async=!0,p.src=s.api_host.replace(".i.posthog.com","-assets.i.posthog.com")+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="init capture register register_once register_for_session unregister unregister_for_session getFeatureFlag getFeatureFlagPayload isFeatureEnabled reloadFeatureFlags updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures on onFeatureFlags onSessionId getSurveys getActiveMatchingSurveys renderSurvey canRenderSurvey identify setPersonProperties group resetGroups setPersonPropertiesForFlags resetPersonPropertiesForFlags setGroupPropertiesForFlags resetGroupPropertiesForFlags reset opt_in_capturing opt_out_capturing has_opted_in_capturing has_opted_out_capturing clear_opt_in_out_capturing debug".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);
window.__rezPosthogStarted = false;
window.rezPosthogStart = function () {
    if (window.__rezPosthogStarted) return;
    window.__rezPosthogStarted = true;
    posthog.init({$keyJson}, {
        api_host: {$hostJson},
        person_profiles: 'identified_only',
        capture_pageview: true,
        capture_pageleave: true,
        autocapture: true,
        disable_session_recording: false,
        loaded: function(ph) {
            var globalProps = {$globalPropsJson};
            if (Object.keys(globalProps).length) ph.register(globalProps);
            var distinctId = {$distinctIdJson};
            var personProps = {$personPropsJson};
            if (distinctId) ph.identify(distinctId, personProps);
        }
    });
};
// Ob nalaganju zaženi, samo če je analytics consent že podan.
if ({$autoStartJson}) {
    window.rezPosthogStart();
}
// Sicer počakaj na rezconsent:change (ko uporabnik klikne "Sprejmi" / "Shrani").
window.addEventListener('rezconsent:change', function (ev) {
    var allowed = ev && ev.detail && ev.detail.categories && ev.detail.categories.analytics;
    if (allowed) {
        window.rezPosthogStart();
    } else if (window.__rezPosthogStarted && window.posthog && typeof posthog.opt_out_capturing === 'function') {
        try { posthog.opt_out_capturing(); } catch (e) {}
    }
});
</script>
HTML;
}

}
