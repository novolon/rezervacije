/**
 * Rezble shell — command palette (⌘K / Ctrl+K).
 *
 * Uporaba:
 *   <button id="rz-cmd-open">…</button>  (iz includes/topbar.php)
 *   window.RZ_CMD_ITEMS = [...]          (opcijsko, stran lahko doda ukaze)
 *
 * Privzeti ukazi so navigacijski (dashboard, statistika, nastavitve ...).
 * Strani lahko razširijo `window.RZ_CMD_ITEMS` pred nalaganjem tega skripta.
 */
(function() {
    'use strict';

    const BASE = (window.APP_STATE && window.APP_STATE.base) ? window.APP_STATE.base : '';
    const restId = (window.APP_STATE && window.APP_STATE.restaurantId) ? window.APP_STATE.restaurantId : null;

    // Nastavitve URL: če je en restavracija izbrana, gremo direktno na edit; sicer seznam restavracij.
    const settingsUrl = restId
        ? BASE + '/pages/restaurant-edit.php?id=' + restId
        : BASE + '/pages/restaurants.php';
    // restaurant-edit.php JS bere `location.hash` (`#urnik`) za aktivni tab.
    // Uporabljamo hash, ne `?tab=`, da preklop deluje brez polnega reload-a, če smo že na strani.
    const settingsTab = (tab) => settingsUrl + '#' + tab;

    // Helper: t() z fallback na privzeti string.
    function _t(key, fallback) {
        if (typeof window.t === 'function') {
            const out = window.t(key);
            if (out && out !== key) return out;
        }
        return fallback;
    }

    // Resolve seznam lang ključev v polje prevedenih nizov (skipa prazne / iste-kot-ključ).
    function resolveKeys(keys) {
        if (!keys || !keys.length) return [];
        return keys.map(function(k) { return _t(k, ''); }).filter(function(s) { return s && s.length > 1; });
    }

    // Build commands at runtime (after lang is ready).
    // `searchTerms` so dodatne ključne besede (SL+EN+...) ki povečajo verjetnost
    // da uporabnik najde ukaz, ne glede na trenutni jezik UI.
    // `searchKeys` so lang ključi naslovov sekcij/kartic/toggle-ov za ta tab —
    // iz njih dobimo dodatne search izraze v trenutnem jeziku.
    // `searchOnly: true` pomeni: skrij dokler uporabnik ne začne pisati.
    function buildNavCommands() {
        const G_NAV  = _t('main.cmd_group_navigation', 'Navigacija');
        const G_SET  = _t('main.cmd_group_settings',   'Nastavitve');
        const G_ACT  = _t('main.cmd_group_actions',    'Dejanja');
        return [
            // Navigacija
            { group: G_NAV, label: _t('nav.today', 'Danes'), hint: _t('main.cmd_today_hint', 'Pojdi na dashboard'),
              icon: 'calendar', href: BASE + '/pages/main.php',
              searchTerms: ['danes', 'today', 'dashboard', 'main', 'home'] },
            { group: G_NAV, label: _t('nav.stats', 'Statistika'), hint: _t('main.cmd_stats_hint', 'Poglej statistiko'),
              icon: 'chart', href: BASE + '/pages/stats.php',
              searchTerms: ['statistika', 'stats', 'statistics', 'reports', 'poročila', 'analytics'] },
            { group: G_NAV, label: _t('nav.guests', 'Gostje'), hint: _t('main.cmd_guests_hint', 'Baza gostov'),
              icon: 'users', href: BASE + '/pages/guests.php',
              searchTerms: ['gostje', 'gosti', 'guests', 'clients', 'gäste', 'ospiti', 'huéspedes', 'hospedes', 'crm'] },
            { group: G_NAV, label: _t('nav.waitlist', 'Čakalna lista'), hint: _t('main.cmd_waitlist_hint', 'Čakajoče zahteve'),
              icon: 'wait', href: BASE + '/pages/waitlist.php',
              searchTerms: ['čakalna', 'cakalna', 'waitlist', 'warteliste', 'attesa', 'attente', 'espera'] },
            { group: G_NAV, label: _t('nav.survey', 'Anketa'), hint: _t('main.cmd_survey_hint', 'Pregled odgovorov + izvoz'),
              icon: 'survey', href: BASE + '/pages/survey_results.php',
              searchTerms: ['anketa', 'survey', 'umfrage', 'sondaggio', 'enquête', 'encuesta', 'inquérito', 'feedback'] },
            { group: G_NAV, label: _t('nav.settings', 'Nastavitve'), hint: _t('main.cmd_settings_hint', 'Restavracija, mize, urnik'),
              icon: 'cog', href: settingsUrl,
              searchTerms: ['nastavitve', 'settings', 'einstellungen', 'impostazioni', 'paramètres', 'ajustes', 'definições', 'config', 'preferences'] },
            { group: G_NAV, label: _t('nav.billing', 'Naročnina'), hint: _t('main.cmd_billing_hint', 'Plan in plačila'),
              icon: 'card', href: BASE + '/pages/billing.php',
              searchTerms: ['naročnina', 'narocnina', 'billing', 'subscription', 'abrechnung', 'fatturazione', 'facturation', 'facturación', 'plan', 'invoice', 'paket'] },

            // Nastavitve – sub-tabi (skriti dokler ni iskalnega niza).
            // searchKeys: lang ključi za naslove kartic/toggle-ov v tem tabu —
            //            resolved kot search izrazi v trenutnem jeziku.
            // searchTerms: dodatne keyword-e v 8 jezikih (jezik-neodvisni alias-i).
            { group: G_SET, searchOnly: true,
              label: _t('re.tab_general', 'Splošno'), hint: _t('main.cmd_set_general_hint', 'Ime, kontakt, lokacija, barva'),
              icon: 'cog', href: settingsTab('splosno'),
              searchKeys: ['re.card_basic', 're.card_rest_settings', 're.field_name', 're.field_color', 're.field_status',
                           're.card_contact', 're.field_address', 're.field_contact_email', 're.field_contact_phone',
                           're.card_users_title', 're.toggle_custom_duration', 're.toggle_employee_override', 're.toggle_notify_guest'],
              searchTerms: ['splošno', 'splosno', 'general', 'allgemein', 'generale', 'général', 'geral',
                            'kontakt', 'contact', 'lokacija', 'address', 'naslov', 'logo', 'barva', 'color', 'phone', 'email', 'ime', 'name'] },
            { group: G_SET, searchOnly: true,
              label: _t('re.tab_schedule', 'Urnik'), hint: _t('main.cmd_set_schedule_hint', 'Odpiralni čas, blokirani datumi'),
              icon: 'cog', href: settingsTab('urnik'),
              searchKeys: ['re.card_opening_hours', 're.card_weekly_schedule', 're.card_rules', 're.card_res_settings',
                           're.field_duration', 're.card_blackouts_title', 're.card_blackouts_eyebrow'],
              searchTerms: ['urnik', 'schedule', 'öffnungszeiten', 'orari', 'horaires', 'horario', 'horário',
                            'opening hours', 'hours', 'closed', 'zaprt', 'odpiralni', 'blackout', 'dopust', 'holiday',
                            'tedenski', 'weekly', 'pravila', 'rules'] },
            { group: G_SET, searchOnly: true,
              label: _t('re.tab_booking', 'Spletne rezervacije'), hint: _t('main.cmd_set_booking_hint', 'Javna rezervacijska povezava, widget'),
              icon: 'cog', href: settingsTab('booking'),
              searchKeys: ['re.card_settings', 're.toggle_booking_enabled', 're.field_min_guests', 're.field_max_guests',
                           're.field_slot_interval', 're.toggle_auto_confirm',
                           're.card_guest', 're.card_guest_edit', 're.toggle_allow_edit', 're.toggle_allow_cancel',
                           're.card_waitlist', 're.toggle_waitlist_enabled', 're.field_waitlist_max',
                           're.card_space_settings', 're.card_area_choice', 're.toggle_area_choice',
                           're.card_link', 're.card_booking_url', 're.card_embed'],
              searchTerms: ['spletne rezervacije', 'spletna rezervacija', 'online booking', 'public link', 'public booking',
                            'javna povezava', 'javna rezervacija', 'rezervacijska povezava', 'booking link', 'booking url',
                            'widget', 'embed', 'embed koda', 'embed code', 'vgradni', 'iframe',
                            'auto confirm', 'auto-confirm', 'samodejno', 'samopotrjevanje', 'samodejno potrjevanje', 'samodejno potrdi',
                            'potrdi', 'potrditev', 'potrjevanje', 'confirm', 'approve', 'odobri',
                            'cancel', 'odpoved', 'odpovej', 'edit', 'urejanje', 'sprememba',
                            'min gostov', 'max gostov', 'min guests', 'max guests',
                            'waitlist', 'čakalna lista'] },
            { group: G_SET, searchOnly: true,
              label: _t('re.tab_staff', 'Zaposleni'), hint: _t('main.cmd_set_staff_hint', 'Uporabniki, vloge, dostopi'),
              icon: 'users', href: settingsTab('zaposleni'),
              searchKeys: ['re.card_staff_list', 're.card_extra'],
              searchTerms: ['zaposleni', 'staff', 'employees', 'mitarbeiter', 'dipendenti', 'personnel', 'empleados', 'funcionários',
                            'users', 'uporabniki', 'roles', 'access', 'pravice', 'permissions'] },
            { group: G_SET, searchOnly: true,
              label: _t('re.tab_fields', 'Polja po meri'), hint: _t('main.cmd_set_fields_hint', 'Custom polja v rezervaciji'),
              icon: 'cog', href: settingsTab('polja'),
              searchTerms: ['polja po meri', 'polja', 'fields', 'custom fields', 'felder', 'campi', 'champs', 'campos',
                            'allergije', 'allergies', 'opombe', 'notes', 'custom field', 'extra polja'] },
            { group: G_SET, searchOnly: true,
              label: _t('re.tab_survey', 'Anketa') + ' (' + _t('common.edit', 'Uredi') + ')', hint: _t('main.cmd_set_survey_hint', 'Vprašanja, pošiljanje ankete'),
              icon: 'survey', href: settingsTab('anketa'),
              searchKeys: ['re.card_survey_general', 're.card_survey_send', 're.card_survey_questions', 're.card_survey_responses'],
              searchTerms: ['anketa', 'ankete', 'survey', 'questions', 'vprašanja', 'feedback', 'rating', 'ocena', 'zadovoljstvo'] },
            { group: G_SET, searchOnly: true,
              label: _t('re.tab_tables', 'Mize'), hint: _t('main.cmd_set_tables_hint', 'Razporeditev miz, cone, kapacitete'),
              icon: 'cog', href: settingsTab('mize'),
              searchKeys: ['re.card_areas_title', 're.card_areas_eyebrow', 're.card_merge_groups'],
              searchTerms: ['mize', 'tables', 'tische', 'tavoli', 'mesas', 'cone', 'areas', 'sectors', 'sektorji',
                            'capacity', 'kapaciteta', 'merge', 'združevanje', 'razporeditev'] },

            // Dejanja
            { group: G_ACT, label: _t('main.cmd_new_reservation', 'Nova rezervacija'), hint: _t('main.cmd_new_reservation_hint', 'Odpri obrazec'),
              icon: 'plus', action: 'newReservation',
              searchTerms: ['nova rezervacija', 'new reservation', 'add reservation', 'create', 'reservation', 'booking', 'rezervacija', 'buchung', 'prenotazione', 'réservation', 'reserva'] },
            { group: G_ACT, label: _t('nav.logout', 'Odjava'), hint: _t('main.cmd_logout_hint', 'Odjava iz aplikacije'),
              icon: 'logout', href: BASE + '/logout.php',
              searchTerms: ['odjava', 'logout', 'sign out', 'abmelden', 'esci', 'déconnexion', 'cerrar sesión', 'sair'] },
        ];
    }
    let NAV_COMMANDS = buildNavCommands();

    // --- Async search (gostje + rezervacije) ---
    let asyncTimer = null;
    let lastAsyncQuery = '';
    let asyncResults = [];

    function parseTags(t) {
        if (!t) return [];
        if (Array.isArray(t)) return t.filter(Boolean);
        if (typeof t === 'string') {
            const trim = t.trim();
            if (trim.startsWith('[')) { try { const p = JSON.parse(trim); return Array.isArray(p) ? p : []; } catch(e) {} }
            return trim.split(',').map(s => s.trim()).filter(Boolean);
        }
        return [];
    }

    function fmtDate(d) {
        if (!d) return '';
        try {
            const dt = new Date(d);
            return dt.toLocaleDateString(window.__LANG__ || 'sl', { day: 'numeric', month: 'short' });
        } catch (e) { return d; }
    }

    function searchAsync(q) {
        return new Promise(function(resolve) {
            clearTimeout(asyncTimer);
            if (!q || q.length < 2) { resolve([]); return; }
            const restParam = restId ? ('&restaurant_id=' + restId) : '';
            asyncTimer = setTimeout(function() {
                // Limit za rezervacije višji — guest ima lahko mnogo prihajajočih.
                // SQL razvrstitev: upcoming ASC najprej, past DESC za njim. Cmd-list popup je scrollable.
                const guestUrl = BASE + '/api/guests.php?search=' + encodeURIComponent(q) + restParam + '&limit=10';
                const resvUrl  = BASE + '/api/reservations.php?action=search&q=' + encodeURIComponent(q) + restParam + '&limit=50';
                Promise.all([
                    fetch(guestUrl).then(r => r.json()).catch(() => ({ success: false })),
                    fetch(resvUrl).then(r => r.json()).catch(() => ({ success: false })),
                ]).then(function([guestRes, resvRes]) {
                    const out = [];

                    // Gostje
                    if (guestRes && guestRes.success && Array.isArray(guestRes.data)) {
                        const gLbl = _t('nav.guests', 'Gostje');
                        const visits = _t('main.cmd_guest_visits', 'obiskov');
                        guestRes.data.forEach(function(g) {
                            const name = ((g.first_name || '') + ' ' + (g.last_name || '')).trim() || g.email || g.phone || '?';
                            const tagsArr = parseTags(g.tags);
                            const tagStr = tagsArr.length ? '#' + tagsArr.slice(0, 3).join(' #') : '';
                            const subtitle = [g.email, g.phone, tagStr].filter(Boolean).join(' · ');
                            out.push({
                                group: gLbl,
                                label: name,
                                hint: subtitle + (g.total_visits ? ' · ' + g.total_visits + ' ' + visits : ''),
                                icon: 'users',
                                href: BASE + '/pages/guests.php?id=' + g.id,
                            });
                        });
                    } else if (guestRes && !guestRes.success) {
                        // Tihi diagnostični log za consolo (samo ko se izrecno odpre dev consolla).
                        console.warn('[rz-cmd] guest search:', guestRes.error || 'failed');
                    }

                    // Rezervacije
                    if (resvRes && resvRes.success && Array.isArray(resvRes.data)) {
                        const rLbl = _t('main.cmd_group_reservations', 'Rezervacije');
                        resvRes.data.forEach(function(r) {
                            const dateStr = fmtDate(r.reservation_date);
                            const timeStr = (r.reservation_time || '').substr(0, 5);
                            const ppl = r.guest_count ? ' · ' + r.guest_count + 'g' : '';
                            const sub = [dateStr + ' ' + timeStr + ppl, r.email || r.phone || '', r.restaurant_name || ''].filter(Boolean).join(' · ');
                            out.push({
                                group: rLbl,
                                label: r.guest_name || '?',
                                hint: sub,
                                icon: 'calendar',
                                href: BASE + '/pages/main.php?date=' + (r.reservation_date || '') + '&res=' + r.id,
                            });
                        });
                    } else if (resvRes && !resvRes.success) {
                        console.warn('[rz-cmd] reservation search:', resvRes.error || 'failed');
                    }

                    resolve(out);
                }).catch(function(e) {
                    console.warn('[rz-cmd] search error:', e);
                    resolve([]);
                });
            }, 220);
        });
    }

    // --- Default fallback handlers (delujejo iz katerekoli strani) ---
    // Stran lahko override-a (npr. main.php nastavi window.rz_newReservation),
    // ampak default poskrbi da se akcija izvede tudi iz drugih strani prek redirect-a.
    if (typeof window.rz_newReservation !== 'function') {
        window.rz_newReservation = function() {
            window.location.href = BASE + '/pages/main.php?new=1';
        };
    }
    if (typeof window.rz_goToday !== 'function') {
        window.rz_goToday = function() {
            window.location.href = BASE + '/pages/main.php';
        };
    }

    const ICONS = {
        calendar:  '<rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
        chart:     '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        users:     '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.2"/><path d="M3 19c0-3 3-5 6-5s6 2 6 5M14 18c0-2 2-4 5-4s5 1.5 5 3"/>',
        wait:      '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        survey:    '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        cog:       '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2"/>',
        card:      '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h3"/>',
        plus:      '<path d="M12 5v14M5 12h14"/>',
        search:    '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        logout:    '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        user:      '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-7 8-7s8 3 8 7"/>',
        phone:     '<path d="M22 16.9v3a2 2 0 0 1-2.2 2A19.8 19.8 0 0 1 2 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7 12.8 12.8 0 0 0 .7 2.8 2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.1-.5 12.8 12.8 0 0 0 2.8.7 2 2 0 0 1 1.7 2Z"/>',
    };

    function iconSvg(name, size) {
        const path = ICONS[name] || '';
        const s = size || 16;
        return '<svg width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
    }

    // --- State ---
    let wrap = null;
    let inputEl = null;
    let listEl = null;
    let focusIdx = 0;
    let flatItems = [];

    function buildPalette() {
        if (wrap) return;
        wrap = document.createElement('div');
        wrap.className = 'rz-cmd-wrap';
        wrap.hidden = true;
        wrap.innerHTML =
            '<div class="rz-cmd" role="dialog" aria-label="Ukazi">' +
              '<div class="rz-cmd-input">' +
                iconSvg('search', 18) +
                '<input type="text" autocomplete="off" spellcheck="false" placeholder="' + window.t('shell.search_placeholder') + '">' +
                '<span class="rz-kbd">ESC</span>' +
              '</div>' +
              '<div class="rz-cmd-list" id="rz-cmd-list"></div>' +
            '</div>';
        document.body.appendChild(wrap);

        inputEl = wrap.querySelector('input');
        listEl  = wrap.querySelector('#rz-cmd-list');

        wrap.addEventListener('click', function(e) {
            if (e.target === wrap) close();
        });
        inputEl.addEventListener('input', render);
        inputEl.addEventListener('keydown', onInputKey);
    }

    function allCommands() {
        const extra = Array.isArray(window.RZ_CMD_ITEMS) ? window.RZ_CMD_ITEMS : [];
        return NAV_COMMANDS.concat(extra);
    }

    // Normalize string for diacritic-insensitive search:
    //   "Čakalna lista" → "cakalna lista"
    //   "Gäste"          → "gaste"
    function normalize(s) {
        if (!s) return '';
        try {
            return s.toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        } catch (e) {
            return s.toString().toLowerCase();
        }
    }

    function matchesQuery(cmd, tokens) {
        if (!tokens.length) return true;
        const resolvedKeys = resolveKeys(cmd.searchKeys);
        const haystack = normalize([
            cmd.label || '',
            cmd.hint  || '',
            (cmd.searchTerms || []).join(' '),
            resolvedKeys.join(' '),
        ].join(' '));
        // Vsi tokeni morajo biti v haystacku (vrstni red ni pomemben).
        return tokens.every(function(t) { return haystack.includes(t); });
    }

    function render() {
        const q = (inputEl.value || '').trim();
        const qNorm = normalize(q);
        const tokens = qNorm.split(/\s+/).filter(Boolean);

        const cmds = allCommands().filter(function(c) {
            // searchOnly: skrij dokler uporabnik ne začne pisati
            if (c.searchOnly && !q) return false;
            return matchesQuery(c, tokens);
        });

        // Trigger async search (>= 2 chars) — gostje + rezervacije v parallel.
        if (q && q.length >= 2 && q !== lastAsyncQuery) {
            lastAsyncQuery = q;
            searchAsync(q).then(function(results) {
                if (q !== lastAsyncQuery) return; // stale
                asyncResults = results;
                renderList(cmds, q);
            });
        } else if (!q) {
            asyncResults = [];
        }

        renderList(cmds, q);
    }

    function renderList(cmds, q) {
        const all = cmds.concat(asyncResults);
        flatItems = all;
        focusIdx = 0;

        if (all.length === 0) {
            listEl.innerHTML = '<div class="rz-cmd-empty">Ni ujemanja za “' + escapeHtml(q) + '”.</div>';
            return;
        }

        const groups = {};
        all.forEach(function(c) {
            const g = c.group || 'Drugo';
            (groups[g] = groups[g] || []).push(c);
        });

        let idx = 0;
        let html = '';
        Object.keys(groups).forEach(function(name) {
            html += '<div class="rz-cmd-group">';
            html += '<div class="rz-cmd-group-h">' + escapeHtml(name) + '</div>';
            groups[name].forEach(function(c) {
                html += '<button type="button" class="rz-cmd-row' + (idx === focusIdx ? ' is-focus' : '') + '" data-idx="' + idx + '">';
                html += iconSvg(c.icon || 'search', 16);
                html += '<span>' + escapeHtml(c.label) + '</span>';
                if (c.hint) html += '<span class="rz-cmd-hint">' + escapeHtml(c.hint) + '</span>';
                html += '</button>';
                idx++;
            });
            html += '</div>';
        });
        listEl.innerHTML = html;

        listEl.querySelectorAll('.rz-cmd-row').forEach(function(el) {
            el.addEventListener('click', function() {
                runItem(flatItems[parseInt(el.dataset.idx)]);
            });
        });
    }

    function updateFocus() {
        const rows = listEl.querySelectorAll('.rz-cmd-row');
        rows.forEach(function(el, i) {
            el.classList.toggle('is-focus', i === focusIdx);
            if (i === focusIdx) el.scrollIntoView({ block: 'nearest' });
        });
    }

    function onInputKey(e) {
        if (e.key === 'Escape') { close(); return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); focusIdx = Math.min(focusIdx + 1, flatItems.length - 1); updateFocus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); focusIdx = Math.max(focusIdx - 1, 0); updateFocus(); }
        else if (e.key === 'Enter') { e.preventDefault(); if (flatItems[focusIdx]) runItem(flatItems[focusIdx]); }
    }

    function runItem(item) {
        if (!item) return;
        if (item.href) { window.location.href = item.href; return; }
        if (item.action) {
            close();
            const fn = window['rz_' + item.action] || window[item.action];
            if (typeof fn === 'function') fn();
            return;
        }
    }

    function open() {
        buildPalette();
        wrap.hidden = false;
        inputEl.value = '';
        render();
        setTimeout(function() { inputEl.focus(); }, 10);
    }

    function close() {
        if (wrap) wrap.hidden = true;
    }

    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, function(c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    // --- Keyboard shortcut ---
    document.addEventListener('keydown', function(e) {
        const mod = e.metaKey || e.ctrlKey;
        if (mod && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            if (wrap && !wrap.hidden) close(); else open();
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        const btn = document.getElementById('rz-cmd-open');
        if (btn) btn.addEventListener('click', open);

        // Mac vs Win/Linux — prikazi pravo bližnjico v topbarju.
        const isMac = /Mac|iPhone|iPad|iPod/i.test(navigator.platform || navigator.userAgent || '');
        if (!isMac) {
            document.querySelectorAll('[data-rz-shortcut="cmd-k"]').forEach(function(el) {
                el.textContent = 'Ctrl K';
            });
        }
    });

    window.RZ = window.RZ || {};
    window.RZ.cmd = { open: open, close: close };
})();
