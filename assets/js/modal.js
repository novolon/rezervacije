/**
 * Modalni dialog za rezervacije.
 * Načini: 'create', 'view', 'edit'
 */
const ReservationModal = (() => {
    let currentMode = null;
    let currentData = null;
    let submitted   = false;

    // ── Odpri ─────────────────────────────────────────────────────
    function open(mode, data = {}) {
        // Prepreči ustvarjanje/urejanje rezervacij v preteklosti
        if (mode === 'create') {
            const d = data.date || data.reservation_date || '';
            if (d && d < APP_STATE.today) {
                if (window.App) App.showToast('Rezervacij v preteklosti ni mogoče dodajati.', 'error');
                return;
            }
        }
        currentMode = mode;
        currentData = data;
        submitted   = false;
        buildModal(mode, data);
    }

    // ── Zapri ─────────────────────────────────────────────────────
    function close() {
        const overlay = document.getElementById('modal-overlay');
        if (overlay) overlay.remove();
    }

    // ── Zgradi modal ──────────────────────────────────────────────
    function buildModal(mode, data) {
        // Odstrani obstoječe
        const existing = document.getElementById('modal-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'modal-overlay';

        const box = document.createElement('div');
        box.className = 'modal-box';

        // Glava
        const header = document.createElement('div');
        header.className = 'modal-header';
        const title = document.createElement('div');
        title.className = 'modal-title';
        title.textContent = mode === 'create' ? '+ Nova rezervacija'
                          : mode === 'edit'   ? 'Uredi rezervacijo'
                                              : 'Rezervacija';
        const closeBtn = document.createElement('button');
        closeBtn.className = 'modal-close';
        closeBtn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>`;
        closeBtn.addEventListener('click', close);
        header.appendChild(title);
        header.appendChild(closeBtn);

        // Telo
        const body = document.createElement('div');
        body.className = 'modal-body';

        if (mode === 'view') {
            body.appendChild(buildViewContent(data));
        } else {
            body.appendChild(buildFormContent(data));
        }

        // Footer
        const footer = document.createElement('div');
        footer.className = 'modal-footer';

        if (mode === 'view') {
            const isPast = data.reservation_date && data.reservation_date < APP_STATE.today;

            const delBtn = document.createElement('button');
            delBtn.className = 'btn-danger-outline';
            delBtn.textContent = 'Izbriši';
            delBtn.addEventListener('click', () => handleDelete(data.id));

            const closeFooter = document.createElement('button');
            closeFooter.className = 'btn btn-ghost';
            closeFooter.textContent = 'Zapri';
            closeFooter.addEventListener('click', close);

            footer.appendChild(delBtn);
            footer.appendChild(closeFooter);

            if (!isPast) {
                const editBtn = document.createElement('button');
                editBtn.className = 'btn btn-primary';
                editBtn.textContent = 'Uredi';
                editBtn.addEventListener('click', () => open('edit', data));
                footer.appendChild(editBtn);
            }

        } else {
            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'btn btn-ghost';
            cancelBtn.textContent = mode === 'edit' ? 'Prekliči urejanje' : 'Prekliči';
            cancelBtn.addEventListener('click', () => {
                if (mode === 'edit') open('view', currentData);
                else close();
            });

            const saveBtn = document.createElement('button');
            saveBtn.className = 'btn btn-primary';
            saveBtn.id = 'modal-save-btn';
            saveBtn.textContent = mode === 'edit' ? 'Shrani spremembe' : 'Shrani rezervacijo';
            saveBtn.addEventListener('click', () => handleSubmit(mode, data));

            footer.appendChild(cancelBtn);
            footer.appendChild(saveBtn);
        }

        box.appendChild(header);
        box.appendChild(body);
        box.appendChild(footer);
        overlay.appendChild(box);

        // Zapri ob kliku na overlay
        overlay.addEventListener('click', e => { if (e.target === overlay) close(); });

        document.body.appendChild(overlay);

        // Fokus na prvo polje
        if (mode !== 'view') {
            setTimeout(() => {
                const first = box.querySelector('input, select, textarea');
                if (first) first.focus();
            }, 100);
        }
    }

    // ── Prikaz podatkov (view mode) ───────────────────────────────
    function buildViewContent(r) {
        const wrap = document.createElement('div');

        // Restavracija badge
        if (r.restaurant_name) {
            const badge = document.createElement('div');
            badge.style.marginBottom = '16px';
            badge.innerHTML = `
                <div class="rest-badge" style="background:${hexToRgba(r.restaurant_color || '#F59E0B', .12)}; color:${r.restaurant_color || '#F59E0B'}">
                    <div class="rest-badge-dot" style="background:${r.restaurant_color || '#F59E0B'}"></div>
                    ${escText(r.restaurant_name)}
                </div>`;
            wrap.appendChild(badge);
        }

        const grid = document.createElement('div');
        grid.className = 'view-grid';

        const fields = [
            { label: 'Ime gosta',         value: r.guest_name,         class: 'big' },
            { label: 'Število oseb',       value: r.guest_count + ' oseb' },
            { label: 'Datum',             value: formatDate(r.reservation_date) },
            { label: 'Čas rezervacije',   value: r.reservation_time ? r.reservation_time.substring(0,5) : '' },
        ];

        if (r.duration) fields.push({ label: 'Trajanje', value: r.duration + ' min' });
        if (r.email) fields.push({ label: 'E-pošta', value: r.email });
        if (r.phone) fields.push({ label: 'Telefon', value: r.phone });
        if (r.notes) fields.push({ label: 'Opomba', value: r.notes, full: true });

        fields.forEach(f => {
            const item = document.createElement('div');
            item.className = 'view-item' + (f.full ? ' full' : '');
            item.innerHTML = `<div class="view-label">${escText(f.label)}</div>
                              <div class="view-value ${f.class || ''}">${escText(String(f.value || '–'))}</div>`;
            grid.appendChild(item);
        });

        wrap.appendChild(grid);
        return wrap;
    }

    // ── Forma (create / edit) ─────────────────────────────────────
    function buildFormContent(data) {
        const wrap = document.createElement('div');

        // Restaurant selector (admin in create mode)
        const st = window.App ? App.getState() : null;
        const isAdmin = APP_STATE.role === 'admin';

        if (isAdmin && currentMode === 'create') {
            const restField = makeField('restaurant_id', 'Restavracija', true);
            const sel = document.createElement('select');
            sel.name = 'restaurant_id';
            sel.required = true;
            const defOpt = document.createElement('option');
            defOpt.value = ''; defOpt.textContent = '— Izberi restavracijo —';
            sel.appendChild(defOpt);
            APP_STATE.restaurants.forEach(r => {
                const opt = document.createElement('option');
                opt.value = r.id;
                opt.textContent = r.name;
                if (data.restaurantId && parseInt(data.restaurantId) === r.id) opt.selected = true;
                sel.appendChild(opt);
            });
            restField.appendChild(sel);
            restField.appendChild(makeError('Izberite restavracijo.'));
            wrap.appendChild(restField);
        }

        // Datum
        const dateField = makeField('reservation_date', 'Datum', true);
        const dateInput = document.createElement('input');
        dateInput.type = 'date'; dateInput.name = 'reservation_date'; dateInput.required = true;
        dateInput.value = data.reservation_date || data.date || '';
        if (currentMode === 'create') dateInput.min = APP_STATE.today;
        dateField.appendChild(dateInput);
        dateField.appendChild(makeError('Datum je obvezen.'));
        wrap.appendChild(dateField);

        // Čas + število oseb (v vrstici)
        const row1 = document.createElement('div');
        row1.className = 'form-row';

        const timeField = makeField('reservation_time', 'Čas', true);
        const timeInput = document.createElement('input');
        timeInput.type = 'time'; timeInput.name = 'reservation_time'; timeInput.required = true;
        timeInput.value = data.reservation_time
            ? data.reservation_time.substring(0,5)
            : (data.time || '');
        timeField.appendChild(timeInput);
        timeField.appendChild(makeError('Čas je obvezen.'));

        const guestField = makeField('guest_count', 'Število oseb', true);
        const guestInput = document.createElement('input');
        guestInput.type = 'number'; guestInput.name = 'guest_count'; guestInput.min = 1;
        guestInput.required = true; guestInput.value = data.guest_count || '';
        guestField.appendChild(guestInput);
        guestField.appendChild(makeError('Vsaj 1 oseba.'));

        row1.appendChild(timeField);
        row1.appendChild(guestField);
        wrap.appendChild(row1);

        // Trajanje (prikaži samo če restavracija dovoli)
        const restId = (currentMode === 'create')
            ? (data.restaurantId || (APP_STATE.role !== 'admin' ? APP_STATE.restaurantId : null))
            : data.restaurant_id;
        const restInfo = restId ? APP_STATE.restaurants.find(r => r.id == restId) : null;
        const allowCustom = restInfo ? restInfo.allow_custom_duration : false;
        const defaultDur  = restInfo ? restInfo.reservation_duration  : 60;

        if (allowCustom) {
            const durField = makeField('duration', 'Trajanje');
            const durSel = document.createElement('select');
            durSel.name = 'duration';
            const currentDur = parseInt(data.duration) || defaultDur;
            for (let m = 15; m <= 480; m += 15) {
                const h   = Math.floor(m / 60);
                const min = m % 60;
                let label = '';
                if (h > 0 && min > 0) label = `${h}h ${min}min`;
                else if (h > 0)       label = `${h}h`;
                else                  label = `${min}min`;
                const opt = document.createElement('option');
                opt.value = m;
                opt.textContent = label;
                if (m === currentDur) opt.selected = true;
                durSel.appendChild(opt);
            }
            durField.appendChild(durSel);
            durField.appendChild(makeError(''));
            wrap.appendChild(durField);
        }

        // Ime
        const nameField = makeField('guest_name', 'Ime gosta', true);
        const nameInput = document.createElement('input');
        nameInput.type = 'text'; nameInput.name = 'guest_name'; nameInput.required = true;
        nameInput.value = data.guest_name || '';
        nameField.appendChild(nameInput);
        nameField.appendChild(makeError('Ime je obvezno.'));
        wrap.appendChild(nameField);

        // Email + Telefon
        const row2 = document.createElement('div');
        row2.className = 'form-row';

        const emailField = makeField('email', 'E-pošta');
        const emailInput = document.createElement('input');
        emailInput.type = 'email'; emailInput.name = 'email';
        emailInput.value = data.email || '';
        emailField.appendChild(emailInput);
        emailField.appendChild(makeError('Neveljaven e-naslov.'));

        const phoneField = makeField('phone', 'Telefon');
        const phoneInput = document.createElement('input');
        phoneInput.type = 'tel'; phoneInput.name = 'phone';
        phoneInput.value = data.phone || '';
        phoneField.appendChild(phoneInput);
        phoneField.appendChild(makeError(''));

        row2.appendChild(emailField);
        row2.appendChild(phoneField);
        wrap.appendChild(row2);

        // Opomba
        const notesField = makeField('notes', 'Opomba');
        const notesTA = document.createElement('textarea');
        notesTA.name = 'notes';
        notesTA.value = data.notes || '';
        notesField.appendChild(notesTA);
        notesField.appendChild(makeError(''));
        wrap.appendChild(notesField);

        return wrap;
    }

    function makeField(name, label, required = false) {
        const div = document.createElement('div');
        div.className = 'field';
        div.dataset.field = name;
        const lbl = document.createElement('label');
        lbl.textContent = label;
        if (required) lbl.innerHTML += '<span class="req"> *</span>';
        div.appendChild(lbl);
        return div;
    }

    function makeError(msg) {
        const span = document.createElement('span');
        span.className = 'field-error';
        span.textContent = msg;
        return span;
    }

    // ── Validacija ────────────────────────────────────────────────
    function validateForm() {
        let valid = true;
        const overlay = document.getElementById('modal-overlay');
        if (!overlay) return false;

        overlay.querySelectorAll('.field').forEach(f => {
            f.classList.remove('invalid');
        });

        const required = ['reservation_date', 'reservation_time', 'guest_name', 'guest_count'];
        if (APP_STATE.role === 'admin' && currentMode === 'create') {
            required.unshift('restaurant_id');
        }

        required.forEach(name => {
            const input = overlay.querySelector(`[name="${name}"]`);
            if (!input) return;
            const val = input.value.trim();
            if (!val || (name === 'guest_count' && parseInt(val) < 1)) {
                input.closest('.field').classList.add('invalid');
                valid = false;
            }
        });

        // Email validacija (opcijska)
        const emailEl = overlay.querySelector('[name="email"]');
        if (emailEl && emailEl.value.trim()) {
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailEl.value)) {
                emailEl.closest('.field').classList.add('invalid');
                valid = false;
            }
        }

        // Validacija časa znotraj urnika restavracije
        const timeEl = overlay.querySelector('[name="reservation_time"]');
        if (timeEl && timeEl.value) {
            let schedRestId;
            if (APP_STATE.role === 'admin' && currentMode === 'create') {
                schedRestId = overlay.querySelector('[name="restaurant_id"]')?.value;
            } else if (currentMode === 'edit') {
                schedRestId = currentData?.restaurant_id;
            } else {
                schedRestId = APP_STATE.restaurantId;
            }
            const rest = schedRestId ? APP_STATE.restaurants.find(r => r.id == schedRestId) : null;
            if (rest && rest.schedule_start != null && rest.schedule_end != null) {
                const [hh, mm] = timeEl.value.split(':').map(Number);
                const timeMins = hh * 60 + mm;
                function fmt(m) { return String(Math.floor(m/60)).padStart(2,'0') + ':' + String(m%60).padStart(2,'0'); }
                if (timeMins < rest.schedule_start || timeMins >= rest.schedule_end) {
                    const errSpan = timeEl.closest('.field').querySelector('.field-error');
                    if (errSpan) errSpan.textContent = `Čas mora biti med ${fmt(rest.schedule_start)} in ${fmt(rest.schedule_end)}.`;
                    timeEl.closest('.field').classList.add('invalid');
                    valid = false;
                }
            }
        }

        return valid;
    }

    // ── Submit ────────────────────────────────────────────────────
    async function handleSubmit(mode, originalData) {
        if (!validateForm()) return;

        const overlay  = document.getElementById('modal-overlay');
        const formData = {};

        overlay.querySelectorAll('input, select, textarea').forEach(el => {
            formData[el.name] = el.value.trim();
        });

        const saveBtn = document.getElementById('modal-save-btn');
        if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = '...'; }

        try {
            let result;
            if (mode === 'create') {
                // Določi restaurant_id
                if (APP_STATE.role !== 'admin') {
                    formData.restaurant_id = APP_STATE.restaurantId;
                }
                result = await API.post('/api/reservations.php', formData);
                close();
                if (window.App) App.afterReservationChange('Rezervacija dodana!');
            } else if (mode === 'edit') {
                result = await API.put(`/api/reservations.php?id=${originalData.id}`, formData);
                // Posodobi currentData za morebitni ponovni view
                currentData = { ...originalData, ...result };
                close();
                if (window.App) App.afterReservationChange('Rezervacija posodobljena!');
            }
        } catch (e) {
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = mode === 'edit' ? 'Shrani spremembe' : 'Shrani rezervacijo'; }
            if (window.App) App.showToast(e.message, 'error');
        }
    }

    // ── Delete ────────────────────────────────────────────────────
    async function handleDelete(id) {
        if (!confirm('Ali res želite izbrisati to rezervacijo?')) return;
        try {
            await API.delete(`/api/reservations.php?id=${id}`);
            close();
            if (window.App) App.afterReservationChange('Rezervacija izbrisana.');
        } catch (e) {
            if (window.App) App.showToast(e.message, 'error');
        }
    }

    // ── Pomožne ───────────────────────────────────────────────────
    function escText(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function hexToRgba(hex, alpha) {
        const r = parseInt(hex.slice(1,3),16);
        const g = parseInt(hex.slice(3,5),16);
        const b = parseInt(hex.slice(5,7),16);
        return `rgba(${r},${g},${b},${alpha})`;
    }

    function formatDate(ds) {
        if (!ds) return '';
        const [y, m, d] = ds.split('-');
        const months = ['jan','feb','mar','apr','maj','jun','jul','avg','sep','okt','nov','dec'];
        return `${parseInt(d)}. ${months[parseInt(m)-1]} ${y}`;
    }

    return { open, close };
})();
