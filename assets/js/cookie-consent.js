/**
 * Cookie consent banner – Rezervacije SaaS
 * Shranjuje odločitev v localStorage (ne piškotek – izognemo se paradoksu).
 * Prikazuje se samo na javnih straneh (book.php, survey, widget).
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'cookie_consent_v1';
    var decision    = localStorage.getItem(STORAGE_KEY);

    // Če je odločitev že shranjena → ne prikazuj bannerja
    if (decision !== null) return;

    // Ustvari banner
    var banner = document.createElement('div');
    banner.id  = 'cookie-banner';
    banner.innerHTML =
        '<div class="cc-inner">' +
            '<p class="cc-text">' +
                'Ta stran uporablja izključno <strong>nujne piškotke seje</strong> za delovanje rezervacijskega sistema. ' +
                'Analitičnih ali trženjskih piškotkov ne postavljamo. ' +
                '<a href="' + (window.BASE_PATH || '') + '/pages/privacy.php" target="_blank" rel="noopener">Več o zasebnosti</a>' +
            '</p>' +
            '<div class="cc-actions">' +
                '<button id="cc-accept" class="cc-btn cc-btn-accept">Razumem</button>' +
            '</div>' +
        '</div>';

    document.body.appendChild(banner);

    // Vstavi CSS (samo enkrat)
    if (!document.getElementById('cc-style')) {
        var style = document.createElement('style');
        style.id  = 'cc-style';
        style.textContent =
            '#cookie-banner{' +
                'position:fixed;bottom:0;left:0;right:0;z-index:9999;' +
                'background:#1B4332;color:#fff;' +
                'padding:14px 20px;' +
                'box-shadow:0 -2px 12px rgba(0,0,0,.15);' +
                'font-family:inherit;font-size:.85rem;' +
            '}' +
            '.cc-inner{' +
                'max-width:860px;margin:0 auto;' +
                'display:flex;align-items:center;gap:20px;flex-wrap:wrap;' +
            '}' +
            '.cc-text{margin:0;flex:1;line-height:1.5;color:rgba(255,255,255,.9)}' +
            '.cc-text a{color:#A3B18A;text-underline-offset:2px}' +
            '.cc-text a:hover{color:#fff}' +
            '.cc-actions{display:flex;gap:8px;flex-shrink:0}' +
            '.cc-btn{' +
                'padding:8px 20px;border:none;border-radius:8px;' +
                'font-size:.85rem;font-weight:600;cursor:pointer;' +
                'transition:opacity .15s;' +
            '}' +
            '.cc-btn:hover{opacity:.85}' +
            '.cc-btn-accept{background:#F59E0B;color:#fff}';
        document.head.appendChild(style);
    }

    document.getElementById('cc-accept').addEventListener('click', function () {
        localStorage.setItem(STORAGE_KEY, 'accepted');
        banner.remove();
    });
})();
