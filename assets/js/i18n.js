/**
 * Rezble i18n helper.
 *
 * Pričakuje window.__T__ (objekt s prevodi), window.__LANG__ (koda jezika),
 * window.__MONTHS__ (array 12 mesečnih imen) in window.__DAYS__ (array 7 imen dni).
 * Vse injektira includes/html_head.php.
 *
 * Uporaba:
 *   t('nav.today')                   → prevedeni niz
 *   t('book.err_field_required', { label: 'Ime' }) → zamenjava {label}
 */
(function () {
    'use strict';

    /**
     * Vrne prevedeni niz. Če ključ ne obstaja, vrne ključ sam.
     * @param {string} key
     * @param {Object} [params]  npr. { count: 3 }
     * @returns {string}
     */
    window.t = function (key, params) {
        var s = (window.__T__ && window.__T__[key] != null) ? window.__T__[key] : key;
        if (params) {
            for (var k in params) {
                if (Object.prototype.hasOwnProperty.call(params, k)) {
                    s = s.replace(new RegExp('\\{' + k + '\\}', 'g'), params[k]);
                }
            }
        }
        return s;
    };

    /** Nastavi/zamenja jezik brez ponovnega nalaganja (za dinamične kontekste). */
    window.setLangStrings = function (strings, lang) {
        window.__T__ = strings || {};
        if (lang) window.__LANG__ = lang;
    };
})();
