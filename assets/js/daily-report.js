/**
 * Dnevno poročilo – full-screen overlay z razpredelnico rezervacij za izbrani dan.
 */
const DailyReport = (() => {
    'use strict';

    // ── Vbrizgaj CSS ────────────────────────────────────────────────
    const style = document.createElement('style');
    style.textContent = `
.dr-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.22);
    backdrop-filter: blur(2px);
    z-index: 1000;
    display: flex;
    justify-content: flex-end;
    animation: dr-fade-in .2s ease;
}
.dr-modal {
    background: white;
    width: 880px;
    max-width: 96vw;
    height: 100%;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    box-shadow: -12px 0 60px -20px rgba(0,0,0,.25);
    animation: dr-slide-right .28s cubic-bezier(.22,1,.36,1);
}
@keyframes dr-fade-in {
    from { opacity: 0; }
    to   { opacity: 1; }
}
@keyframes dr-slide-right {
    from { transform: translateX(40px); opacity: 0; }
    to   { transform: translateX(0); opacity: 1; }
}
.dr-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}
.dr-eyebrow {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .08em;
    color: #6b7280;
    margin-bottom: 4px;
}
.dr-title {
    font-size: 18px;
    font-weight: 700;
    color: #1a1a2e;
}
.dr-subtitle {
    font-size: 13px;
    color: #6b7280;
    margin-top: 2px;
}
.dr-header-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}
.dr-kpis {
    display: flex;
    gap: 16px;
    padding: 16px 24px;
    border-bottom: 1px solid #e5e7eb;
    background: #f9fafb;
}
.dr-kpi {
    flex: 1;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 12px 16px;
}
.dr-kpi-label {
    font-size: 11px;
    color: #6b7280;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
}
.dr-kpi-value {
    font-size: 24px;
    font-weight: 700;
    color: #1a1a2e;
    margin-top: 4px;
}
.dr-body {
    padding: 0 24px 24px;
    flex: 1;
}
.dr-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 16px;
    font-size: 13px;
}
.dr-table th {
    text-align: left;
    padding: 8px 10px;
    border-bottom: 2px solid #e5e7eb;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .06em;
    color: #6b7280;
    text-transform: uppercase;
}
.dr-table td {
    padding: 10px;
    border-bottom: 1px solid #f3f4f6;
    color: #374151;
    vertical-align: top;
}
.dr-table tr:last-child td {
    border-bottom: none;
}
.dr-table tr:hover td {
    background: #f9fafb;
}
.dr-status {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
}
.dr-status-confirmed {
    background: #D1FAE5;
    color: #065F46;
}
.dr-status-pending {
    background: #FEF3C7;
    color: #92400E;
}
.dr-status-arrived {
    background: #DBEAFE;
    color: #1E40AF;
}
.dr-footer {
    padding: 16px 24px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
}
@media print {
    /* Skrij vse na strani razen dnevnega poročila */
    body > *:not(#dr-overlay) {
        display: none !important;
    }
    /* Razveljavi tudi sticky/fixed elemente in header (so otroci body) */
    html, body {
        background: #fff !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .dr-overlay {
        position: static !important;
        background: none !important;
        padding: 0 !important;
        z-index: auto !important;
    }
    .dr-modal {
        width: auto !important;
        max-width: none !important;
        max-height: none !important;
        height: auto !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        margin: 0 !important;
        animation: none !important;
    }
    .dr-btn-print,
    .dr-btn-close,
    .dr-footer,
    .dr-header-actions {
        display: none !important;
    }
    /* Tabela naj ne preskoči stran med vrstico */
    .dr-table tr { page-break-inside: avoid; }
}
`;
    document.head.appendChild(style);

    // ── Pomožne funkcije za datum ────────────────────────────────────
    const FALLBACK_DAYS   = ['Nedelja','Ponedeljek','Torek','Sreda','Četrtek','Petek','Sobota'];
    const FALLBACK_MONTHS = ['januar','februar','marec','april','maj','junij','julij','avgust','september','oktober','november','december'];

    function dayName(jsDayIdx) {
        const monIdx = (jsDayIdx + 6) % 7;
        const v = window.__T__ && window.__T__['days.' + monIdx];
        return v || FALLBACK_DAYS[jsDayIdx];
    }
    function monthName(i) {
        if ((window.__LANG__ || 'sl') === 'sl') return FALLBACK_MONTHS[i];
        const v = window.__T__ && window.__T__['months.' + (i + 1)];
        return v || FALLBACK_MONTHS[i];
    }

    function formatDateSl(date) {
        const d = (date instanceof Date) ? date : new Date(date + 'T00:00:00');
        return dayName(d.getDay()) + ', ' + d.getDate() + '. ' + monthName(d.getMonth()) + ' ' + d.getFullYear();
    }

    function formatTime(timeStr) {
        if (!timeStr) return '—';
        // timeStr je lahko "HH:MM:SS" ali "HH:MM"
        return timeStr.substring(0, 5);
    }

    function statusLabel(status) {
        switch (status) {
            case 'confirmed': return { text: window.t('daily_report.status_confirmed'), cls: 'dr-status-confirmed' };
            case 'pending':   return { text: window.t('daily_report.status_pending'),   cls: 'dr-status-pending'   };
            case 'arrived':   return { text: window.t('daily_report.status_arrived'),   cls: 'dr-status-arrived'   };
            default:          return { text: status || '—', cls: '' };
        }
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Zgradi HTML vsebino ──────────────────────────────────────────
    function buildHtml(reservations, date, restName) {
        const dateLabel = formatDateSl(date);

        // KPI izračuni
        const total    = reservations.length;
        const guests   = reservations.reduce((s, r) => s + (parseInt(r.guest_count) || 0), 0);
        const pending  = reservations.filter(r => r.status === 'pending').length;

        // Sortiraj po reservation_time
        const sorted = [...reservations].sort((a, b) => {
            const ta = a.reservation_time || '';
            const tb = b.reservation_time || '';
            return ta < tb ? -1 : ta > tb ? 1 : 0;
        });

        // Tabela vrstic
        let rows = '';
        if (sorted.length === 0) {
            rows = `<tr><td colspan="6" style="text-align:center;padding:24px;color:#6b7280;">${window.t('daily_report.no_reservations')}</td></tr>`;
        } else {
            for (const r of sorted) {
                const sl = statusLabel(r.status);
                const tables = (r.tables || []).map(t => escHtml(t.table_name)).join(', ') || '—';
                rows += `<tr>
                    <td>${escHtml(formatTime(r.reservation_time))}</td>
                    <td>${escHtml(r.guest_name || '—')}</td>
                    <td style="text-align:center">${escHtml(String(r.guest_count || '—'))}</td>
                    <td>${tables}</td>
                    <td><span class="dr-status ${escHtml(sl.cls)}">${escHtml(sl.text)}</span></td>
                    <td>${escHtml(r.notes || '')}</td>
                </tr>`;
            }
        }

        return `
<div class="dr-modal" id="dr-modal-inner">
    <div class="dr-header">
        <div>
            <div class="dr-eyebrow">${window.t('daily_report.eyebrow')}</div>
            <div class="dr-title">${escHtml(dateLabel)}</div>
            <div class="dr-subtitle">${escHtml(restName)}</div>
        </div>
        <div class="dr-header-actions">
            <button type="button" class="rz-btn rz-btn-primary dr-btn-print" onclick="window.print()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                ${window.t('common.print')}
            </button>
            <button type="button" class="rz-iconbtn dr-btn-close" id="dr-close-btn" title="${window.t('common.close')}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
    </div>

    <div class="dr-kpis">
        <div class="dr-kpi">
            <div class="dr-kpi-label">${window.t('stats.kpi_total')}</div>
            <div class="dr-kpi-value">${total}</div>
        </div>
        <div class="dr-kpi">
            <div class="dr-kpi-label">${window.t('stats.kpi_guests')}</div>
            <div class="dr-kpi-value">${guests}</div>
        </div>
        <div class="dr-kpi">
            <div class="dr-kpi-label">${window.t('daily_report.kpi_pending')}</div>
            <div class="dr-kpi-value">${pending}</div>
        </div>
    </div>

    <div class="dr-body">
        <table class="dr-table">
            <thead>
                <tr>
                    <th>${window.t('waitlist.col_time')}</th>
                    <th>${window.t('waitlist.col_name')}</th>
                    <th>${window.t('nav.guests')}</th>
                    <th>${window.t('daily_report.col_table')}</th>
                    <th>${window.t('re.field_status')}</th>
                    <th>${window.t('daily_report.col_notes')}</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
    </div>

    <div class="dr-footer">
        <button type="button" class="btn btn-ghost dr-btn-close">${window.t('common.close')}</button>
    </div>
</div>`;
    }

    // ── Javni API ────────────────────────────────────────────────────
    function open(reservations, date, restName) {
        // Odstrani morebitni obstoječi overlay
        const existing = document.getElementById('dr-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'dr-overlay';
        overlay.id = 'dr-overlay';
        overlay.innerHTML = buildHtml(reservations, date, restName);
        document.body.appendChild(overlay);

        function close() {
            overlay.remove();
        }

        // Klik na backdrop (ne na modal) zapre
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) close();
        });

        // Gumbi za zapiranje
        overlay.querySelectorAll('.dr-btn-close').forEach(btn => {
            btn.addEventListener('click', close);
        });

        // ESC zapre
        function onKey(e) {
            if (e.key === 'Escape') { close(); document.removeEventListener('keydown', onKey); }
        }
        document.addEventListener('keydown', onKey);
    }

    return { open };
})();
