/**
 * Rezble cookie consent (GDPR + ePrivacy).
 *
 * En consent za rezble.com + app.rezble.com (cookie na .rezble.com apex).
 * 4 kategorije: necessary / functional / analytics / marketing.
 *
 * Skripta lahko teče iz <head> ali z body-loaded — banner in modal markup
 * lazy ustvari ob prvem prikazu (dokler PHP ne odda DIV-ov).
 *
 * Public API: window.rezConsent
 *   .has(category)             → bool                   – ali je kategorija sprejeta
 *   .all()                     → { necessary, functional, analytics, marketing, isSet }
 *   .show()                    → void                   – odpre preferences modal
 *   .acceptAll() / rejectAll() → void                   – programmatic
 *   .set(prefs)                → void                   – { functional:bool, analytics:bool, marketing:bool }
 *   addEventListener('rezconsent:change', cb)           – ko se odločitev spremeni
 *
 * V HTML: <a data-rez-consent="open">Cookie nastavitve</a> avtomatsko odpre modal.
 */
(function () {
    'use strict';

    var CFG = window.__REZ_CC_CONFIG__ || {};
    var COOKIE_NAME   = CFG.cookieName   || 'rez_consent';
    var VERSION       = CFG.version      || 1;
    var TTL_DAYS      = CFG.ttlDays      || 180;
    var COOKIE_DOMAIN = CFG.cookieDomain || '';
    var SURFACE       = CFG.surface      || 'app';
    var AUTOSHOW      = !!CFG.autoShow;
    var POLICY_URL    = CFG.policyUrl    || '/cookie-policy.php';
    var S             = CFG.strings      || {};
    var CATS_DEF      = (S.cats) || [];

    // ── Cookie helpers ──────────────────────────────────────────
    function readCookie(name) {
        var pat = new RegExp('(?:^|; )' + name.replace(/[$()*+./?[\\\]^{|}-]/g, '\\$&') + '=([^;]*)');
        var m = document.cookie.match(pat);
        return m ? decodeURIComponent(m[1]) : null;
    }
    function writeCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + days * 24 * 3600 * 1000);
        var parts = [
            name + '=' + encodeURIComponent(value),
            'Expires=' + d.toUTCString(),
            'Path=/',
            'SameSite=Lax'
        ];
        if (COOKIE_DOMAIN) parts.push('Domain=' + COOKIE_DOMAIN);
        if (location.protocol === 'https:') parts.push('Secure');
        document.cookie = parts.join('; ');
    }

    function readState() {
        var raw = readCookie(COOKIE_NAME);
        if (!raw) return null;
        try {
            var p = JSON.parse(raw);
            if (!p || p.v !== VERSION) return null;
            if (p.ts && (Date.now() / 1000 - p.ts) > TTL_DAYS * 86400) return null;
            return {
                necessary:  true,
                functional: !!(p.cats && p.cats.functional),
                analytics:  !!(p.cats && p.cats.analytics),
                marketing:  !!(p.cats && p.cats.marketing),
                _set: true
            };
        } catch (e) { return null; }
    }
    function writeState(prefs) {
        var payload = {
            v: VERSION,
            ts: Math.floor(Date.now() / 1000),
            cats: {
                necessary:  1,
                functional: prefs.functional ? 1 : 0,
                analytics:  prefs.analytics  ? 1 : 0,
                marketing:  prefs.marketing  ? 1 : 0
            },
            surface: SURFACE
        };
        writeCookie(COOKIE_NAME, JSON.stringify(payload), TTL_DAYS);
        return {
            necessary:  true,
            functional: !!prefs.functional,
            analytics:  !!prefs.analytics,
            marketing:  !!prefs.marketing,
            _set: true
        };
    }

    // ── State + emit ────────────────────────────────────────────
    var state = readState() || { necessary: true, functional: false, analytics: false, marketing: false, _set: false };

    function emit(detail) {
        try {
            window.dispatchEvent(new CustomEvent('rezconsent:change', { detail: detail }));
        } catch (e) { /* IE11 ignored */ }
    }

    // ── Lazy DOM build ──────────────────────────────────────────
    var root = null, banner = null, modal = null, inputs = null;

    function el(tag, attrs, html) {
        var n = document.createElement(tag);
        if (attrs) {
            for (var k in attrs) {
                if (k === 'class') n.className = attrs[k];
                else if (k === 'html') n.innerHTML = attrs[k];
                else n.setAttribute(k, attrs[k]);
            }
        }
        if (html != null) n.innerHTML = html;
        return n;
    }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    function buildMarkup() {
        if (root) return;
        if (!document.body) return; // body še ni naložen → poskusi kasneje

        root = el('div', { id: 'rez-cc', class: 'rez-cc', 'data-surface': SURFACE });
        root.hidden = true;

        // Banner
        var bannerS = S.banner || {};
        var bHtml =
            '<div class="rez-cc-banner-inner">' +
                '<div class="rez-cc-banner-text">' +
                    '<h2 id="rez-cc-banner-title">' + esc(bannerS.title || '') + '</h2>' +
                    '<p id="rez-cc-banner-text">' + esc(bannerS.text || '') + ' ' +
                        '<a href="' + esc(POLICY_URL) + '" target="_blank" rel="noopener">' + esc(bannerS.more || '') + '</a>.' +
                    '</p>' +
                '</div>' +
                '<div class="rez-cc-banner-actions">' +
                    '<button type="button" class="rez-cc-btn rez-cc-btn-ghost" data-rez-cc="reject-all">' + esc(bannerS.reject || '') + '</button>' +
                    '<button type="button" class="rez-cc-btn rez-cc-btn-ghost" data-rez-cc="open-prefs">' + esc(bannerS.prefs || '') + '</button>' +
                    '<button type="button" class="rez-cc-btn rez-cc-btn-primary" data-rez-cc="accept-all">' + esc(bannerS.accept || '') + '</button>' +
                '</div>' +
            '</div>';
        banner = el('div', {
            class: 'rez-cc-banner',
            role: 'dialog',
            'aria-labelledby': 'rez-cc-banner-title',
            'aria-describedby': 'rez-cc-banner-text'
        }, bHtml);
        banner.hidden = true;
        root.appendChild(banner);

        // Modal
        var modalS = S.modal || {};
        var catsHtml = '';
        for (var i = 0; i < CATS_DEF.length; i++) {
            var c = CATS_DEF[i] || {};
            var ctrl = c.always
                ? '<span class="rez-cc-cat-always">' + esc(modalS.always || '') + '</span>'
                : '<label class="rez-cc-toggle">' +
                    '<input type="checkbox" data-rez-cc-cat="' + esc(c.key || '') + '">' +
                    '<span class="rez-cc-toggle-track"><span class="rez-cc-toggle-knob"></span></span>' +
                  '</label>';
            catsHtml +=
                '<div class="rez-cc-cat">' +
                    '<div class="rez-cc-cat-head">' +
                        '<h3 class="rez-cc-cat-title">' + esc(c.title || '') + '</h3>' +
                        ctrl +
                    '</div>' +
                    '<p class="rez-cc-cat-desc">' + esc(c.desc || '') + '</p>' +
                '</div>';
        }

        var mHtml =
            '<div class="rez-cc-modal-backdrop" data-rez-cc="close-prefs"></div>' +
            '<div class="rez-cc-modal-card">' +
                '<div class="rez-cc-modal-head">' +
                    '<h2 id="rez-cc-modal-title">' + esc(modalS.title || '') + '</h2>' +
                    '<button type="button" class="rez-cc-modal-close" data-rez-cc="close-prefs" aria-label="' + esc(modalS.close || '') + '">&times;</button>' +
                '</div>' +
                '<div class="rez-cc-modal-body">' +
                    '<p class="rez-cc-modal-intro">' + esc(modalS.intro || '') + ' <a href="' + esc(POLICY_URL) + '" target="_blank" rel="noopener">' + esc(modalS.introLink || '') + '</a>.</p>' +
                    catsHtml +
                '</div>' +
                '<div class="rez-cc-modal-foot">' +
                    '<button type="button" class="rez-cc-btn rez-cc-btn-ghost" data-rez-cc="reject-all">' + esc(modalS.reject || '') + '</button>' +
                    '<button type="button" class="rez-cc-btn rez-cc-btn-ghost" data-rez-cc="accept-all">' + esc(modalS.accept || '') + '</button>' +
                    '<button type="button" class="rez-cc-btn rez-cc-btn-primary" data-rez-cc="save-prefs">' + esc(modalS.save || '') + '</button>' +
                '</div>' +
            '</div>';
        modal = el('div', {
            class: 'rez-cc-modal',
            role: 'dialog',
            'aria-modal': 'true',
            'aria-labelledby': 'rez-cc-modal-title'
        }, mHtml);
        modal.hidden = true;
        root.appendChild(modal);

        document.body.appendChild(root);
        inputs = root.querySelectorAll('[data-rez-cc-cat]');

        // Wire up clicks (delegacija)
        root.addEventListener('click', function (e) {
            var t = e.target.closest && e.target.closest('[data-rez-cc]');
            if (!t || !root.contains(t)) return;
            var act = t.getAttribute('data-rez-cc');
            if      (act === 'accept-all')  { e.preventDefault(); acceptAll(); }
            else if (act === 'reject-all')  { e.preventDefault(); rejectAll(); }
            else if (act === 'open-prefs')  { e.preventDefault(); showModal(); }
            else if (act === 'close-prefs') { e.preventDefault(); hideModal(); }
            else if (act === 'save-prefs')  { e.preventDefault(); savePrefs(); }
        });
    }

    function showBanner() {
        buildMarkup();
        if (!root || !banner) return;
        root.hidden = false;
        banner.hidden = false;
    }
    function hideBanner() {
        if (banner) banner.hidden = true;
        if (root && (!modal || modal.hidden)) root.hidden = true;
    }
    function syncTogglesFromState() {
        if (!inputs) return;
        for (var i = 0; i < inputs.length; i++) {
            var k = inputs[i].getAttribute('data-rez-cc-cat');
            inputs[i].checked = !!state[k];
        }
    }
    function showModal() {
        buildMarkup();
        if (!root || !modal) return;
        root.hidden = false;
        modal.hidden = false;
        syncTogglesFromState();
        var first = modal.querySelector('button, [tabindex], input');
        if (first) try { first.focus(); } catch (e) {}
        document.addEventListener('keydown', onEsc);
    }
    function hideModal() {
        if (modal) modal.hidden = true;
        if (root && (!banner || banner.hidden)) root.hidden = true;
        document.removeEventListener('keydown', onEsc);
    }
    function onEsc(e) { if (e.key === 'Escape') hideModal(); }

    function applyAndPersist(prefs) {
        state = writeState(prefs);
        hideBanner();
        hideModal();
        emit({
            categories: { necessary: true, functional: state.functional, analytics: state.analytics, marketing: state.marketing },
            source: 'user'
        });
    }
    function acceptAll() { applyAndPersist({ functional: true,  analytics: true,  marketing: true  }); }
    function rejectAll() { applyAndPersist({ functional: false, analytics: false, marketing: false }); }
    function savePrefs() {
        var prefs = { functional: false, analytics: false, marketing: false };
        if (inputs) {
            for (var i = 0; i < inputs.length; i++) {
                var k = inputs[i].getAttribute('data-rez-cc-cat');
                if (k in prefs) prefs[k] = !!inputs[i].checked;
            }
        }
        applyAndPersist(prefs);
    }

    // Globalna trigger povezava: <a data-rez-consent="open">Cookie nastavitve</a>
    document.addEventListener('click', function (e) {
        var t = e.target.closest && e.target.closest('[data-rez-consent="open"]');
        if (!t) return;
        e.preventDefault();
        showModal();
    });

    // ── Auto-show banner ob prvem obisku ────────────────────────
    function tryAutoShow() {
        if (!AUTOSHOW || state._set) return;
        if (document.body) showBanner();
        else document.addEventListener('DOMContentLoaded', showBanner);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tryAutoShow);
    } else {
        tryAutoShow();
    }

    // ── Public API ──────────────────────────────────────────────
    window.rezConsent = {
        has: function (cat) { return cat === 'necessary' ? true : !!state[cat]; },
        all: function () {
            return {
                necessary: true,
                functional: !!state.functional,
                analytics:  !!state.analytics,
                marketing:  !!state.marketing,
                isSet:      !!state._set
            };
        },
        show:      showModal,
        acceptAll: acceptAll,
        rejectAll: rejectAll,
        set: function (prefs) { applyAndPersist(prefs || {}); }
    };

    // Initial event (za listener-je, ki se vežejo PO load-u)
    setTimeout(function () {
        emit({
            categories: { necessary: true, functional: !!state.functional, analytics: !!state.analytics, marketing: !!state.marketing },
            source: state._set ? 'restored' : 'default'
        });
    }, 0);
})();
