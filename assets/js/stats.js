/**
 * stats.js – Statistika rezervacij
 */

// ─── Stanje ──────────────────────────────────────────────────────
const State = {
    from:       null,
    to:         null,
    restaurantId: APP_STATE.restaurantId || null,
    onlyReturning: false,
};

// ─── Chart instance cache ────────────────────────────────────────
const Charts = {};

function destroyChart(id) {
    if (Charts[id]) { Charts[id].destroy(); delete Charts[id]; }
}

// ─── Barve ───────────────────────────────────────────────────────
const AMBER   = '#F59E0B';
const AMBER_L = 'rgba(245,158,11,.15)';
const BLUE    = '#3B82F6';
const GREEN   = '#10B981';
const RED     = '#EF4444';
const PURPLE  = '#8B5CF6';

// ─── API helper ──────────────────────────────────────────────────
async function fetchStats(section, extra = {}) {
    const params = new URLSearchParams({
        section,
        from: State.from,
        to:   State.to,
        ...extra,
    });
    if (State.restaurantId) params.set('restaurant_id', State.restaurantId);
    const res = await fetch(`${APP_STATE.base}/api/stats.php?${params}`);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const json = await res.json();
    if (!json.success) throw new Error(json.error || 'Napaka');
    return json.data;
}

// ─── Datum setup ─────────────────────────────────────────────────
function setDays(days) {
    State.to   = formatDate(new Date());
    const d    = new Date();
    d.setDate(d.getDate() - days + 1);
    State.from = formatDate(d);
    updatePeriodLabel();
}

function formatDate(d) {
    return d.toISOString().slice(0, 10);
}

/** Prikaži natančen "od – do" datum izbranega obdobja. */
function updatePeriodLabel() {
    const el = document.getElementById('period-range-label');
    if (!el || !State.from || !State.to) return;
    el.textContent = fmtDate(State.from) + ' – ' + fmtDate(State.to);
}

// ─── Nalaganje ───────────────────────────────────────────────────
async function loadAll() {
    await Promise.all([
        loadOverview(),
        loadMonthly(),
        loadByDay(),
        loadByHour(),
        loadSources(),
        loadGroupsDist(),
        loadReturning(),
        loadTopCustomers(),
        loadInsights(),
    ]);
}

// ─── KPI kartice ─────────────────────────────────────────────────
function renderDelta(elId, pct, suffix) {
    suffix = suffix || '%';
    const el = document.getElementById(elId);
    if (!el) return;
    if (pct === null || pct === undefined || isNaN(+pct)) { el.innerHTML = ''; return; }
    pct = +pct;
    const abs = Math.abs(pct);
    if (abs < 1) { el.className = 'rz-kpi-delta is-flat'; el.textContent = '→ 0' + suffix; return; }
    const up = pct > 0;
    el.className = 'rz-kpi-delta ' + (up ? 'is-up' : 'is-down');
    const sign = up ? '+' : '';
    const val  = Number.isInteger(pct) ? pct : pct.toFixed(1);
    el.textContent = (up ? '▲' : '▼') + ' ' + sign + val + suffix + ' vs. prej';
}

async function loadOverview() {
    try {
        const d = await fetchStats('overview');
        document.getElementById('kpi-total').textContent   = fmtNum(d.total_reservations);
        document.getElementById('kpi-guests').textContent  = fmtNum(d.total_guests);
        document.getElementById('kpi-avg').textContent     = d.avg_guests ? d.avg_guests.toLocaleString('sl-SI') : '0';
        document.getElementById('kpi-arrival').textContent = d.arrival_rate ? d.arrival_rate + '%' : '—';
        renderDelta('kpi-total-delta',   d.delta_reservations,  '%');
        renderDelta('kpi-guests-delta',  d.delta_guests,        '%');
        renderDelta('kpi-arrival-delta', d.delta_arrival_rate,  ' pp');
    } catch { /* tiho */ }
}

// ─── Trend po izbranem obdobju ──────────────────────────────────
async function loadMonthly() {
    try {
        const data = await fetchStats('by_month');
        const labels = data.map(r => r.label);
        const vals   = data.map(r => r.reservations);
        const guests = data.map(r => r.guests);

        // Subtitle: prikaži natančen datum od–do
        const subtitleEl = document.getElementById('trend-range');
        if (subtitleEl && State.from && State.to) {
            subtitleEl.textContent = fmtDate(State.from) + ' – ' + fmtDate(State.to);
        }

        destroyChart('monthly');
        const ctx = document.getElementById('chart-monthly').getContext('2d');
        Charts.monthly = new Chart(ctx, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Rezervacije',
                    data: vals,
                    backgroundColor: AMBER,
                    borderRadius: 5,
                    borderSkipped: false,
                }, {
                    label: 'Gostje',
                    data: guests,
                    backgroundColor: AMBER_L,
                    borderRadius: 5,
                    borderSkipped: false,
                }],
            },
            options: chartOpts({ stacked: false }),
        });
    } catch { /* tiho */ }
}

// ─── Po dnevu v tednu ────────────────────────────────────────────
async function loadByDay() {
    try {
        const data = await fetchStats('by_day');
        const labels = data.map(r => r.label);
        const vals   = data.map(r => r.reservations);
        const max    = Math.max(...vals) || 1;

        destroyChart('days');
        const ctx = document.getElementById('chart-days').getContext('2d');
        Charts.days = new Chart(ctx, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Rezervacije',
                    data: vals,
                    backgroundColor: vals.map(v => `rgba(245,158,11,${0.25 + 0.75 * v / max})`),
                    borderRadius: 4,
                }],
            },
            options: chartOpts(),
        });
    } catch { /* tiho */ }
}

// ─── Po uri ─────────────────────────────────────────────────────
async function loadByHour() {
    try {
        const data = await fetchStats('by_hour');
        const labels = data.map(r => r.label);
        const vals   = data.map(r => r.reservations);
        const max    = Math.max(...vals) || 1;

        destroyChart('hours');
        const ctx = document.getElementById('chart-hours').getContext('2d');
        Charts.hours = new Chart(ctx, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Rezervacije',
                    data: vals,
                    backgroundColor: vals.map(v => `rgba(59,130,246,${0.2 + 0.8 * v / max})`),
                    borderRadius: 4,
                }],
            },
            options: chartOpts(),
        });
    } catch { /* tiho */ }
}

// ─── Vir rezervacij ──────────────────────────────────────────────
async function loadSources() {
    try {
        const data = await fetchStats('sources');
        const map = {};
        data.forEach(r => { map[r.source] = r; });

        const staff  = parseInt(map.staff?.reservations  || 0);
        const pub    = parseInt(map.public?.reservations || 0);

        destroyChart('sources');
        const ctx = document.getElementById('chart-sources').getContext('2d');
        Charts.sources = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Osebje', 'Javna rezervacija'],
                datasets: [{
                    data: [staff, pub],
                    backgroundColor: [AMBER, BLUE],
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 6,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: { position: 'bottom', labels: { font: { family: 'Inter', size: 12 }, padding: 14 } },
                    tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw}` } },
                },
            },
        });
    } catch { /* tiho */ }
}

// ─── Porazdelitev skupin ─────────────────────────────────────────
async function loadGroupsDist() {
    try {
        const data = await fetchStats('guests_dist');
        const labels = data.map(r => r.label);
        const vals   = data.map(r => r.reservations);
        const pcts   = data.map(r => r.pct);

        destroyChart('groups');
        const ctx = document.getElementById('chart-groups').getContext('2d');
        Charts.groups = new Chart(ctx, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Rezervacije',
                    data: vals,
                    backgroundColor: [AMBER, BLUE, GREEN, PURPLE, RED],
                    borderRadius: 5,
                }],
            },
            options: {
                ...chartOpts(),
                plugins: {
                    ...chartOpts().plugins,
                    tooltip: {
                        callbacks: {
                            label: ctx => ` ${ctx.raw} rez. (${pcts[ctx.dataIndex]}%)`,
                        },
                    },
                },
            },
        });
    } catch { /* tiho */ }
}

// ─── Returning KPI ───────────────────────────────────────────────
async function loadReturning() {
    try {
        const d = await fetchStats('returning');
        document.getElementById('ret-unique').textContent    = fmtNum(d.unique_guests);
        document.getElementById('ret-returning').textContent = fmtNum(d.returning_guests);
        document.getElementById('ret-pct').textContent       = d.returning_pct + '%';
    } catch { /* tiho */ }
}

// ─── Top gostje tabela ───────────────────────────────────────────
async function loadTopCustomers() {
    const tbody = document.getElementById('guests-tbody');
    tbody.innerHTML = '<tr><td colspan="7" class="table-empty">Nalagam...</td></tr>';
    try {
        const data = await fetchStats('top_customers', State.onlyReturning ? { returning: 1 } : {});
        if (!data.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="table-empty">Ni podatkov.</td></tr>';
            return;
        }
        tbody.innerHTML = data.slice(0, 20).map((r, i) => {
            const loyal   = parseInt(r.visits) >= 5 ? '<span class="loyal-badge">' + window.t('stats.loyal_guest') + '</span>' : '';
            const name    = h(r.guest_name || '—');
            const contact = r.email
                ? `<a href="mailto:${h(r.email)}" style="color:var(--color-accent)">${h(r.email)}</a>`
                : (r.phone ? `<a href="tel:${h(r.phone)}" style="color:var(--color-accent)">${h(r.phone)}</a>` : '—');
            return `<tr>
                <td>${i + 1}</td>
                <td>${name}${loyal}</td>
                <td>${contact}</td>
                <td><strong>${r.visits}</strong></td>
                <td>${r.total_guests}</td>
                <td>${fmtDate(r.first_visit)}</td>
                <td>${fmtDate(r.last_visit)}</td>
            </tr>`;
        }).join('');
    } catch {
        tbody.innerHTML = '<tr><td colspan="7" class="table-empty">Napaka pri nalaganju.</td></tr>';
    }
}

// ─── Helpers ─────────────────────────────────────────────────────
function fmtNum(n) {
    return parseInt(n || 0).toLocaleString('sl-SI');
}

function fmtDate(str) {
    if (!str) return '—';
    const d = new Date(str);
    return d.toLocaleDateString('sl-SI', { day: 'numeric', month: 'short', year: 'numeric' });
}

function h(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function chartOpts(extra = {}) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1E2330',
                titleFont: { family: 'Inter', size: 12 },
                bodyFont:  { family: 'Inter', size: 12 },
                padding: 10,
                cornerRadius: 8,
            },
        },
        scales: {
            x: {
                stacked: extra.stacked || false,
                grid: { display: false },
                ticks: { font: { family: 'Inter', size: 11 }, color: '#6B7280' },
                border: { display: false },
            },
            y: {
                stacked: extra.stacked || false,
                beginAtZero: true,
                grid: { color: 'rgba(0,0,0,.05)' },
                ticks: { font: { family: 'Inter', size: 11 }, color: '#6B7280', precision: 0 },
                border: { display: false },
            },
        },
    };
}

// ─── CSV izvoz ───────────────────────────────────────────────────
document.getElementById('btn-export').addEventListener('click', () => {
    const params = new URLSearchParams({ section: 'export', from: State.from, to: State.to });
    if (State.restaurantId) params.set('restaurant_id', State.restaurantId);
    window.location.href = `${APP_STATE.base}/api/stats.php?${params}`;
});

// ─── Period gumbi ────────────────────────────────────────────────
document.querySelectorAll('.period-btn[data-days]').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.period-btn[data-days]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const days = btn.dataset.days;
        if (days === 'custom') {
            document.getElementById('custom-dates').style.display = 'flex';
        } else {
            document.getElementById('custom-dates').style.display = 'none';
            setDays(parseInt(days));
            loadAll();
        }
    });
});

document.getElementById('btn-apply-dates')?.addEventListener('click', () => {
    const from = document.getElementById('filter-from').value;
    const to   = document.getElementById('filter-to').value;
    if (!from || !to || from > to) {
        alert('Izberite veljavno obdobje.');
        return;
    }
    State.from = from;
    State.to   = to;
    updatePeriodLabel();
    loadAll();
});

// ─── Restavracija filter ─────────────────────────────────────────
document.getElementById('filter-restaurant')?.addEventListener('change', function () {
    State.restaurantId = this.value ? parseInt(this.value) : null;
    loadAll();
});

// ─── Returning filter ────────────────────────────────────────────
document.getElementById('btn-all-guests')?.addEventListener('click', function () {
    State.onlyReturning = false;
    this.classList.add('active');
    document.getElementById('btn-returning-only').classList.remove('active');
    loadTopCustomers();
});

document.getElementById('btn-returning-only')?.addEventListener('click', function () {
    State.onlyReturning = true;
    this.classList.add('active');
    document.getElementById('btn-all-guests').classList.remove('active');
    loadTopCustomers();
});

// ─── AI Insights ─────────────────────────────────────────────────
const INSIGHT_ICONS = {
    fire:  '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2c0 6-6 8-6 13a6 6 0 0 0 12 0c0-5-6-7-6-13Z"/><path d="M12 12c0 3-2 4-2 6a2 2 0 0 0 4 0c0-2-2-3-2-6Z"/></svg>',
    clock: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    warn:  '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.3 3.3 2 19h20L13.7 3.3a2 2 0 0 0-3.4 0Z"/><path d="M12 10v4M12 17v1"/></svg>',
    up:    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19V5M5 12l7-7 7 7"/></svg>',
    down:  '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12l7 7 7-7"/></svg>',
    group: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.2"/><path d="M3 19c0-3 3-5 6-5s6 2 6 5M14 18c0-2 2-4 5-4s5 1.5 5 3"/></svg>',
};
const INSIGHT_COLOR = { fire:'var(--accent)', clock:'var(--info)', warn:'var(--warning)', up:'var(--success)', down:'var(--danger)', group:'var(--secondary)' };

async function loadInsights() {
    const el = document.getElementById('insights-list');
    if (!el) return;
    try {
        const d = await fetchStats('insights');
        if (!d.insights || !d.insights.length) {
            el.innerHTML = '<div style="text-align:center;color:var(--ink-mute);padding:24px;font-size:13px">Premalo podatkov za ugotovitve. Preverite pozneje.</div>';
            return;
        }
        el.innerHTML = d.insights.map(i => {
            const color = INSIGHT_COLOR[i.icon] || 'var(--ink-mute)';
            const icon  = INSIGHT_ICONS[i.icon] || '';
            return `<div style="display:flex;align-items:flex-start;gap:10px;background:var(--bg-sunken);border:1px solid var(--line);border-radius:10px;padding:12px 14px">
                <span style="color:${color};flex-shrink:0;margin-top:1px">${icon}</span>
                <span style="font-size:13px;color:var(--ink-soft);line-height:1.5">${i.text}</span>
            </div>`;
        }).join('');
    } catch {
        el.innerHTML = '';
    }
}

// ─── Init ────────────────────────────────────────────────────────
setDays(30);
loadAll();
