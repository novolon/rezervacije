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

    // --- Ukazi ---
    const NAV_COMMANDS = [
        { group: 'Navigacija', label: 'Danes',       hint: 'Pojdi na dashboard',        icon: 'calendar', href: BASE + '/pages/main.php' },
        { group: 'Navigacija', label: 'Statistika',  hint: 'Poglej statistiko',          icon: 'chart',    href: BASE + '/pages/stats.php' },
        { group: 'Navigacija', label: 'Gostje',      hint: 'Baza gostov',                icon: 'users',    href: BASE + '/pages/guests.php' },
        { group: 'Navigacija', label: 'Čakalna lista', hint: 'Čakajoče zahteve',         icon: 'wait',     href: BASE + '/pages/waitlist.php' },
        { group: 'Navigacija', label: 'Anketa',      hint: 'Nastavi in preglej odgovore', icon: 'survey',  href: BASE + '/pages/survey.php' },
        { group: 'Navigacija', label: 'Nastavitve',  hint: 'Restavracija, mize, urnik',  icon: 'cog',      href: BASE + '/pages/admin.php' },
        { group: 'Navigacija', label: 'Naročnina',   hint: 'Plan in plačila',            icon: 'card',     href: BASE + '/pages/billing.php' },
        { group: 'Dejanja',    label: 'Nova rezervacija', hint: 'Odpri obrazec',         icon: 'plus',     action: 'newReservation' },
        { group: 'Dejanja',    label: 'Odjava',       hint: 'Odjava iz aplikacije',      icon: 'logout',   href: BASE + '/logout.php' },
    ];

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
                '<input type="text" autocomplete="off" spellcheck="false" placeholder="Išči strani, rezervacije, goste …">' +
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

    function render() {
        const q = (inputEl.value || '').trim().toLowerCase();
        const cmds = allCommands().filter(function(c) {
            if (!q) return true;
            return (c.label + ' ' + (c.hint || '')).toLowerCase().includes(q);
        });

        flatItems = cmds;
        focusIdx = 0;

        if (cmds.length === 0) {
            listEl.innerHTML = '<div class="rz-cmd-empty">Ni ujemanja za “' + escapeHtml(q) + '”.</div>';
            return;
        }

        const groups = {};
        cmds.forEach(function(c) {
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
    });

    window.RZ = window.RZ || {};
    window.RZ.cmd = { open: open, close: close };
})();
