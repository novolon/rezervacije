/**
 * Mesečni koledar – render in podatkovni load.
 */
const Calendar = (() => {
    const DAYS_SL = ['Pon', 'Tor', 'Sre', 'Čet', 'Pet', 'Sob', 'Ned'];
    const MONTHS_SL = [
        'Januar', 'Februar', 'Marec', 'April', 'Maj', 'Junij',
        'Julij', 'Avgust', 'September', 'Oktober', 'November', 'December'
    ];

    let calendarData  = {}; // { 'YYYY-MM-DD': { count, guests } }
    let selectedDate  = APP_STATE.today;
    let currentYear   = 0;
    let currentMonth  = 0;

    function getEl(id) { return document.getElementById(id); }

    // ── Render mreže ─────────────────────────────────────────────
    function render(year, month) {
        currentYear  = year;
        currentMonth = month;

        const title = getEl('cal-title');
        if (title) title.textContent = `${MONTHS_SL[month]} ${year}`;

        const grid = getEl('cal-grid');
        if (!grid) return;
        grid.innerHTML = '';

        // Glave dni
        DAYS_SL.forEach(d => {
            const el = document.createElement('div');
            el.className = 'calendar-day-header';
            el.textContent = d;
            grid.appendChild(el);
        });

        // Začetni dan meseca (1 = ponedeljek, ..., 7 = nedelja v SL formatu)
        const firstDay = new Date(year, month, 1);
        // getDay(): 0=ned, 1=pon... prilagodimo na pon=0
        let startOffset = (firstDay.getDay() + 6) % 7;

        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const today = APP_STATE.today;

        // Prazne celice pred 1.
        for (let i = 0; i < startOffset; i++) {
            const empty = document.createElement('div');
            empty.className = 'calendar-cell empty';
            grid.appendChild(empty);
        }

        // Dnevi
        for (let d = 1; d <= daysInMonth; d++) {
            const dateObj = new Date(year, month, d);
            const ds = dateStr(dateObj);
            const cell = buildCell(d, ds, today);
            grid.appendChild(cell);
        }
    }

    function buildCell(dayNum, ds, today) {
        const cell = document.createElement('div');
        const classes = ['calendar-cell'];

        if (ds < today)      classes.push('past');
        else if (ds === today) classes.push('today');
        if (ds === selectedDate) classes.push('selected');

        cell.className = classes.join(' ');
        cell.dataset.date = ds;

        // Vsebina celice
        const top = document.createElement('div');
        top.className = 'cell-top';

        const num = document.createElement('div');
        num.className = 'cell-day-num';
        num.textContent = dayNum;
        top.appendChild(num);

        const data = calendarData[ds];
        if (data && data.count > 0) {
            const badge = document.createElement('div');
            badge.className = 'cell-badge';
            badge.textContent = data.count;
            top.appendChild(badge);
        }

        cell.appendChild(top);

        if (data && data.count > 0) {
            const cnt = document.createElement('div');
            cnt.className = 'cell-count';
            cnt.textContent = `${data.count} rezervacij`;

            const guests = document.createElement('div');
            guests.className = 'cell-guests';
            guests.textContent = `👥 ${data.guests} oseb`;

            cell.appendChild(cnt);
            cell.appendChild(guests);
        }

        cell.addEventListener('click', () => {
            selectedDate = ds;
            // Posodobi selected razred
            document.querySelectorAll('.calendar-cell.selected').forEach(c => c.classList.remove('selected'));
            cell.classList.add('selected');
            // Obvesti app
            if (window.App) App.onDayClick(new Date(ds + 'T00:00:00'));
        });

        return cell;
    }

    // ── Naloži mesečne agregate ───────────────────────────────────
    async function loadMonth(year, month) {
        const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;
        const state    = window.App ? App.getState() : null;
        const restId   = state ? state.restaurantId : null;

        const url = `/api/dashboard.php?month=${monthStr}` +
                    (restId ? `&restaurant_id=${restId}` : '');
        try {
            calendarData = await API.get(url) || {};
            // Re-render s novimi podatki
            render(year, month);
            // Povrni selected
            setSelected(selectedDate);
        } catch (e) {
            if (window.App) App.showToast(e.message, 'error');
        }
    }

    // ── Označi izbran dan ─────────────────────────────────────────
    function setSelected(ds) {
        selectedDate = ds;
        document.querySelectorAll('.calendar-cell').forEach(c => {
            if (c.dataset.date === ds) {
                c.classList.add('selected');
            } else {
                c.classList.remove('selected');
            }
        });
    }

    // ── Pomožna ───────────────────────────────────────────────────
    function dateStr(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    return { render, loadMonth, setSelected };
})();
