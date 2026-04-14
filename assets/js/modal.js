/**
 * Modalni dialog za rezervacije.
 * Načini: 'create', 'view', 'edit'
 */
const ReservationModal = (() => {
    let currentMode = null;
    let currentData = null;
    let submitted   = false;

    // ── Cache za zaposlene in custom fields po restaurant_id ─────
    const _extrasCache = {};

    async function fetchExtras(restId) {
        if (!restId) return { staff: [], fields: [] };
        const rid = parseInt(restId);
        if (_extrasCache[rid]) return _extrasCache[rid];
        const [staff, fields] = await Promise.all([
            API.get(`/api/staff.php?restaurant_id=${rid}`).catch(() => []),
            API.get(`/api/customfields.php?restaurant_id=${rid}`).catch(() => []),
        ]);
        _extrasCache[rid] = { staff: staff || [], fields: fields || [] };
        return _extrasCache[rid];
    }

    function clearExtrasCache(restId) {
        if (restId) delete _extrasCache[parseInt(restId)];
    }

    // ── Odpri ─────────────────────────────────────────────────────
    async function open(mode, data = {}) {
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

        // Pridobi restaurant_id za fetchExtras
        let restIdForExtras = null;
        if (mode === 'create') {
            restIdForExtras = data.restaurantId || (APP_STATE.role !== 'admin' ? APP_STATE.restaurantId : null);
        } else {
            restIdForExtras = data.restaurant_id;
        }

        // Za view/edit: naloži polne podatke (field_values, staff_name)
        if (mode !== 'create' && data.id) {
            try {
                const full = await API.get(`/api/reservations.php?id=${data.id}`);
                if (full) data = { ...data, ...full };
            } catch (e) { /* fallback na obstoječe podatke */ }
        }

        // Naloži zaposlene in custom fields (async, pred gradnjo modala)
        let extras = { staff: [], fields: [] };
        if (restIdForExtras) {
            extras = await fetchExtras(restIdForExtras).catch(() => ({ staff: [], fields: [] }));
        }

        buildModal(mode, data, extras);
    }

    // ── Zapri ─────────────────────────────────────────────────────
    function close() {
        const overlay = document.getElementById('modal-overlay');
        if (overlay) overlay.remove();
    }

    // ── Zgradi modal ──────────────────────────────────────────────
    function buildModal(mode, data, extras = { staff: [], fields: [] }) {
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
            body.appendChild(buildViewContent(data, extras));
        } else {
            body.appendChild(buildFormContent(data, extras));
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

            // ── Gost je prišel + Pošlji anketo (samo za potrjene z emailom) ──
            if (APP_STATE.hasSurvey && data.email && data.status === 'confirmed') {
                const arrivedBtn = document.createElement('button');
                const isArrived = !!data.arrived_at;
                arrivedBtn.className = 'btn btn-ghost';
                arrivedBtn.id = 'btn-arrived';
                arrivedBtn.style.cssText = isArrived
                    ? 'color:#065F46;border-color:#6EE7B7;background:#ECFDF5'
                    : 'color:#374151';
                arrivedBtn.innerHTML = isArrived
                    ? `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg> Prišel`
                    : `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg> Gost je prišel`;
                arrivedBtn.addEventListener('click', () => handleMarkArrived(data, arrivedBtn, box));
                footer.appendChild(arrivedBtn);

                if (isArrived) {
                    const sendBtn = document.createElement('button');
                    sendBtn.className = 'btn btn-ghost';
                    sendBtn.id = 'btn-send-survey';
                    sendBtn.style.cssText = 'color:#92400E;border-color:#FDE68A;background:#FFFBEB';
                    sendBtn.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2 11 13M22 2 15 22 11 13 2 9l20-7z"/></svg> Pošlji anketo`;
                    sendBtn.addEventListener('click', () => handleSendSurvey(data, sendBtn));
                    footer.appendChild(sendBtn);
                }
            }

            footer.appendChild(closeFooter);

            if (!isPast) {
                // Gumb "Prestavi mizo" (samo če je table management aktiven IN restavracija ima mize)
                if (APP_STATE.hasTableMgmt && data.id && data.restaurant_has_tables) {
                    const moveBtn = document.createElement('button');
                    moveBtn.className = 'btn btn-ghost';
                    moveBtn.style.cssText = 'color:#7C3AED;border-color:#C4B5FD';
                    moveBtn.textContent = 'Prestavi mizo';
                    moveBtn.addEventListener('click', () => openTableMoveDialog(data, box));
                    footer.appendChild(moveBtn);
                }

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
    function buildViewContent(r, extras = { staff: [], fields: [] }) {
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
        if (r.staff_name) fields.push({ label: 'Sprejel', value: r.staff_name });
        if (Array.isArray(r.table_assignments) && r.table_assignments.length) {
            const tableLabel = r.table_assignments.map(a => a.table_name).join(' + ');
            fields.push({ label: 'Miza', value: tableLabel });
        }
        if (r.notes) fields.push({ label: 'Opomba', value: r.notes, full: true });
        if (r.arrived_at) {
            const d = new Date(r.arrived_at.replace(' ','T'));
            fields.push({ label: 'Prišel ob', value: d.toLocaleTimeString('sl-SI', { hour:'2-digit', minute:'2-digit' }) });
        }

        // Custom field vrednosti
        const fieldValues = Array.isArray(r.field_values) ? r.field_values : [];
        fieldValues.forEach(fv => {
            if (fv.value !== null && fv.value !== '') {
                const displayVal = fv.value === '1' ? 'Da' : (fv.value === '0' ? 'Ne' : fv.value);
                fields.push({ label: fv.label, value: displayVal, full: true });
            }
        });

        fields.forEach(f => {
            const item = document.createElement('div');
            item.className = 'view-item' + (f.full ? ' full' : '');
            item.innerHTML = `<div class="view-label">${escText(f.label)}</div>
                              <div class="view-value ${f.class || ''}">${escText(String(f.value || '–'))}</div>`;
            grid.appendChild(item);
        });

        wrap.appendChild(grid);

        // ── Mini-profil gosta (Advanced/Premium) ─────────────────
        if (APP_STATE.hasGuestDatabase && r.email && r.restaurant_id) {
            const profileDiv = document.createElement('div');
            profileDiv.id = 'guest-mini-profile';
            profileDiv.style.cssText = 'margin-top:16px;border-top:1px solid #E5E7EB;padding-top:14px';
            profileDiv.innerHTML = '<div style="color:#9CA3AF;font-size:.8rem">Nalagam profil gosta…</div>';
            wrap.appendChild(profileDiv);

            const base = APP_STATE.base || '';
            fetch(`${base}/api/guests.php?restaurant_id=${r.restaurant_id}&email=${encodeURIComponent(r.email)}`)
                .then(res => res.json())
                .then(json => {
                    if (!json.success || !json.data) {
                        profileDiv.innerHTML = '';
                        return;
                    }
                    const g = json.data;
                    const tags = (g.tags || []).slice(0, 4).map(t =>
                        `<span style="display:inline-block;padding:1px 8px;border-radius:999px;font-size:.7rem;font-weight:600;background:${t==='VIP'?'#FEE2E2':'#FEF3C7'};color:${t==='VIP'?'#991B1B':'#92400E'};margin:1px 2px">${escText(t)}</span>`
                    ).join('');
                    const avg = g.avg_rating ? `⭐ ${g.avg_rating}` : '';
                    const guestPageUrl = `${base}/pages/guests.php?rest_id=${r.restaurant_id}`;
                    profileDiv.innerHTML = `
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                            <span style="font-size:.75rem;font-weight:600;color:#9CA3AF;text-transform:uppercase;letter-spacing:.05em">Profil gosta</span>
                            <a href="${guestPageUrl}" style="font-size:.75rem;color:#F59E0B;text-decoration:none" target="_blank">Baza gostov →</a>
                        </div>
                        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">
                            <div style="font-size:.85rem">
                                <span style="color:#6B7280">Obiski:</span>
                                <strong style="color:#111827;margin-left:4px">${g.total_visits}</strong>
                                ${g.no_shows ? `<span style="color:#EF4444;font-size:.75rem;margin-left:4px">(${g.no_shows} ns)</span>` : ''}
                            </div>
                            ${g.last_visit && g.last_visit !== r.reservation_date ? `<div style="font-size:.85rem"><span style="color:#6B7280">Zadnjič:</span> <strong style="color:#111827;margin-left:4px">${fmtMiniDate(g.last_visit)}</strong></div>` : ''}
                            ${avg ? `<div style="font-size:.85rem">${avg}</div>` : ''}
                            ${g.is_blacklisted ? '<span style="color:#EF4444;font-size:.8rem;font-weight:600">⛔ Blokiran</span>' : ''}
                        </div>
                        ${tags ? `<div style="margin-top:6px">${tags}</div>` : ''}
                        ${g.notes ? `<div style="margin-top:8px;font-size:.82rem;color:#374151;background:#F9FAFB;border-radius:6px;padding:7px 10px">${escText(g.notes)}</div>` : ''}
                    `;
                })
                .catch(() => { profileDiv.innerHTML = ''; });
        }

        return wrap;
    }

    function fmtMiniDate(d) {
        if (!d) return '';
        const [y,m,day] = d.slice(0,10).split('-');
        return `${parseInt(day)}. ${parseInt(m)}. ${y}`;
    }

    // ── Forma (create / edit) ─────────────────────────────────────
    function buildFormContent(data, extras = { staff: [], fields: [] }) {
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

        // ── Zaposleni (samo če restaurant ima vsaj enega) ──────────
        const activeStaff = (extras.staff || []).filter(s => s.is_active != 0);
        if (activeStaff.length > 0) {
            const staffField = makeField('staff_id', 'Sprejel');
            const staffSel = document.createElement('select');
            staffSel.name = 'staff_id';
            const noOpt = document.createElement('option');
            noOpt.value = ''; noOpt.textContent = '— Nihče / neznano —';
            staffSel.appendChild(noOpt);
            activeStaff.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = s.name;
                if (data.staff_id && parseInt(data.staff_id) === parseInt(s.id)) opt.selected = true;
                staffSel.appendChild(opt);
            });
            staffField.appendChild(staffSel);
            staffField.appendChild(makeError(''));
            wrap.appendChild(staffField);
        }

        // ── Polja po meri (internal ali both) ─────────────────────
        const internalFields = (extras.fields || []).filter(
            f => f.applies_to === 'internal' || f.applies_to === 'both'
        );
        internalFields.forEach(f => {
            const fieldEl = makeField('cf_' + f.id, f.label, !!f.is_required);

            // Najdi obstoječo vrednost
            const existingFV = Array.isArray(data.field_values)
                ? data.field_values.find(fv => fv.label === f.label)
                : null;
            const existingVal = existingFV ? existingFV.value : '';

            let inputEl;
            if (f.field_type === 'checkbox') {
                const label = document.createElement('label');
                label.style.display = 'flex';
                label.style.alignItems = 'center';
                label.style.gap = '8px';
                label.style.cursor = 'pointer';
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.name = 'cf_' + f.id;
                cb.value = '1';
                cb.checked = existingVal === '1';
                cb.style.width = '16px';
                cb.style.height = '16px';
                cb.style.accentColor = '#F59E0B';
                cb.style.cursor = 'pointer';
                cb.style.flexShrink = '0';
                const span = document.createElement('span');
                span.textContent = f.label;
                span.style.fontSize = '.875rem';
                label.appendChild(cb);
                label.appendChild(span);
                // Za checkbox ne prikažemo ponovne oznake – preskočimo label
                const cbWrap = makeField('cf_' + f.id, '', false);
                cbWrap.style.marginTop = '4px';
                cbWrap.appendChild(label);
                cbWrap.appendChild(makeError(''));
                wrap.appendChild(cbWrap);
                return;
            } else if (f.field_type === 'select' && f.options && f.options.length) {
                inputEl = document.createElement('select');
                inputEl.name = 'cf_' + f.id;
                const empty = document.createElement('option');
                empty.value = ''; empty.textContent = '— Izberi —';
                inputEl.appendChild(empty);
                f.options.forEach(opt => {
                    const o = document.createElement('option');
                    o.value = opt; o.textContent = opt;
                    if (existingVal === opt) o.selected = true;
                    inputEl.appendChild(o);
                });
            } else {
                inputEl = document.createElement('input');
                inputEl.type = 'text';
                inputEl.name = 'cf_' + f.id;
                inputEl.value = existingVal;
                if (f.is_required) inputEl.required = true;
            }
            fieldEl.appendChild(inputEl);
            fieldEl.appendChild(makeError(f.is_required ? `${f.label} je obvezno.` : ''));
            wrap.appendChild(fieldEl);
        });

        // ── Izbira mize (samo create + hasTableMgmt + restavracija ima mize) ──
        if (currentMode === 'create' && APP_STATE.hasTableMgmt) {
            const tableSection = document.createElement('div');
            tableSection.id = 'table-selection-section';
            tableSection.style.cssText = 'display:none;margin-top:4px';

            const tableHdr = document.createElement('label');
            tableHdr.textContent = 'Miza';
            tableHdr.style.cssText = 'display:block;font-size:.8125rem;font-weight:600;color:#374151;margin-bottom:6px';
            tableSection.appendChild(tableHdr);

            const tableStatus = document.createElement('div');
            tableStatus.id = 'table-status';
            tableStatus.style.cssText = 'font-size:.8rem;color:#6B7280;min-height:20px';
            tableStatus.textContent = 'Izpolnite datum, čas in število gostov za prikaz miz.';
            tableSection.appendChild(tableStatus);

            const tableRadios = document.createElement('div');
            tableRadios.id = 'table-radios';
            tableRadios.style.cssText = 'display:none;flex-direction:column;gap:6px;margin-top:8px';
            tableSection.appendChild(tableRadios);

            const tableError = document.createElement('div');
            tableError.id = 'table-no-availability';
            tableError.style.cssText = 'display:none;font-size:.8rem;color:#DC2626;background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:8px 12px;margin-top:6px';
            tableError.textContent = 'Ni razpoložljivih miz za ta termin. Spremenite čas ali zmanjšajte število gostov.';
            tableSection.appendChild(tableError);

            // hidden input za prenos izbrane mize
            const hiddenTableId    = document.createElement('input');
            hiddenTableId.type = 'hidden'; hiddenTableId.name = 'selected_table_id'; hiddenTableId.id = 'selected_table_id';
            const hiddenMergeId    = document.createElement('input');
            hiddenMergeId.type = 'hidden'; hiddenMergeId.name = 'selected_merge_group_id'; hiddenMergeId.id = 'selected_merge_group_id';
            tableSection.appendChild(hiddenTableId);
            tableSection.appendChild(hiddenMergeId);

            wrap.appendChild(tableSection);

            // Debounced fetch ko se spremenijo polja
            let _tFetchTimer = null;
            function scheduleTableFetch() {
                if (_tFetchTimer) clearTimeout(_tFetchTimer);
                _tFetchTimer = setTimeout(fetchAvailableTables, 600);
            }

            async function fetchAvailableTables() {
                const ov = document.getElementById('modal-overlay');
                if (!ov) return;
                const restIdEl   = ov.querySelector('[name="restaurant_id"]');
                const dateEl     = ov.querySelector('[name="reservation_date"]');
                const timeEl     = ov.querySelector('[name="reservation_time"]');
                const guestEl    = ov.querySelector('[name="guest_count"]');
                const durationEl = ov.querySelector('[name="duration"]');

                const rId  = restIdEl  ? parseInt(restIdEl.value)  : (APP_STATE.restaurantId || 0);
                const date = dateEl    ? dateEl.value               : '';
                const time = timeEl    ? timeEl.value               : '';
                const g    = guestEl   ? parseInt(guestEl.value)    : 0;
                const dur  = durationEl? parseInt(durationEl.value) : 60;

                if (!rId || !date || !time || !g) {
                    tableSection.style.display = 'none';
                    return;
                }

                // Preveri ali ima restavracija mize
                const rest = APP_STATE.restaurants.find(r => r.id === rId);
                if (!rest || !rest.has_tables) {
                    tableSection.style.display = 'none';
                    return;
                }

                tableSection.style.display = '';
                tableStatus.textContent = 'Nalagam mize...';
                tableRadios.style.display = 'none';
                tableError.style.display = 'none';
                hiddenTableId.value = '';
                hiddenMergeId.value = '';

                try {
                    const params = new URLSearchParams({ restaurant_id: rId, action: 'available', date, time, guests: g, duration: dur });
                    const data = await API.get(`/api/tables.php?${params}`);
                    const tables      = data.tables      || [];
                    const mergeGroups = data.merge_groups || [];

                    if (!tables.length && !mergeGroups.length) {
                        tableStatus.textContent = '';
                        tableError.style.display = 'block';
                        tableRadios.style.display = 'none';
                        // Onemogoči submit
                        const saveBtn = document.getElementById('modal-save-btn');
                        if (saveBtn) saveBtn.dataset.tableBlocked = '1';
                        return;
                    }

                    // Omogoči submit
                    const saveBtn = document.getElementById('modal-save-btn');
                    if (saveBtn) delete saveBtn.dataset.tableBlocked;
                    tableError.style.display = 'none';
                    tableStatus.textContent = '';
                    tableRadios.innerHTML = '';
                    tableRadios.style.display = 'flex';

                    function addRadio(value, type, label, capacity) {
                        const lbl = document.createElement('label');
                        lbl.style.cssText = 'display:flex;align-items:center;gap:8px;padding:8px 12px;border:1.5px solid #E5E7EB;border-radius:8px;cursor:pointer;font-size:.875rem;transition:border-color .15s';
                        const rb = document.createElement('input');
                        rb.type = 'radio'; rb.name = '_table_radio'; rb.value = value;
                        rb.style.accentColor = '#1B4332';
                        rb.addEventListener('change', () => {
                            tableRadios.querySelectorAll('label').forEach(l => l.style.borderColor = '#E5E7EB');
                            lbl.style.borderColor = '#1B4332';
                            if (type === 'table') {
                                hiddenTableId.value = value;
                                hiddenMergeId.value = '';
                            } else {
                                hiddenMergeId.value = value;
                                hiddenTableId.value = '';
                            }
                        });
                        const txt = document.createElement('span');
                        txt.innerHTML = `<strong>${label}</strong><span style="color:#9CA3AF;font-size:.75rem;margin-left:6px">${capacity} os.</span>`;
                        lbl.appendChild(rb);
                        lbl.appendChild(txt);
                        tableRadios.appendChild(lbl);
                    }

                    tables.forEach(t => addRadio(t.id, 'table', (t.area_name ? t.area_name + ' – ' : '') + t.name, t.capacity));
                    mergeGroups.forEach(mg => addRadio(mg.id, 'merge', mg.name, mg.total_capacity));

                    // Samodejno izberi prvo
                    const firstRb = tableRadios.querySelector('input[type="radio"]');
                    if (firstRb) {
                        firstRb.checked = true;
                        firstRb.dispatchEvent(new Event('change'));
                        firstRb.closest('label').style.borderColor = '#1B4332';
                    }
                } catch (e) {
                    tableStatus.textContent = 'Napaka pri nalaganju miz.';
                }
            }

            // Sproži fetch ob spremembi relevantnih polj (s setTimeout za zagotovitev da je wrap dodan v DOM)
            setTimeout(() => {
                const ov = document.getElementById('modal-overlay');
                if (!ov) return;
                ['reservation_date','reservation_time','guest_count','duration','restaurant_id'].forEach(n => {
                    ov.querySelector(`[name="${n}"]`)?.addEventListener('change', scheduleTableFetch);
                    ov.querySelector(`[name="${n}"]`)?.addEventListener('input',  scheduleTableFetch);
                });
            }, 0);
        }

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

        // Validacija obveznih custom fields
        overlay.querySelectorAll('[name^="cf_"]').forEach(input => {
            const field = input.closest('.field');
            if (!field) return;
            const lbl = field.querySelector('label');
            if (!lbl || !lbl.querySelector('.req')) return; // ni obvezno
            if (input.type === 'checkbox') return; // checkboxes so vedno OK
            if (!input.value.trim()) {
                field.classList.add('invalid');
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

        // Blokira submit, če ni razpoložljivih miz za ta termin
        if (currentMode === 'create' && APP_STATE.hasTableMgmt) {
            const saveBtn = document.getElementById('modal-save-btn');
            if (saveBtn && saveBtn.dataset.tableBlocked) {
                const errDiv = document.getElementById('table-no-availability');
                if (errDiv) {
                    errDiv.style.display = 'block';
                    errDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
                valid = false;
            }
        }

        return valid;
    }

    // ── Submit ────────────────────────────────────────────────────
    async function handleSubmit(mode, originalData) {
        if (!validateForm()) return;

        const overlay  = document.getElementById('modal-overlay');
        const formData = {};
        const customFields = {};

        overlay.querySelectorAll('input, select, textarea').forEach(el => {
            if (!el.name) return;
            if (el.name.startsWith('cf_')) {
                const fieldId = el.name.replace('cf_', '');
                if (el.type === 'checkbox') {
                    customFields[fieldId] = el.checked ? '1' : '0';
                } else {
                    customFields[fieldId] = el.value.trim();
                }
            } else {
                if (el.type !== 'checkbox') {
                    formData[el.name] = el.value.trim();
                }
            }
        });

        if (Object.keys(customFields).length > 0) {
            formData.custom_fields = customFields;
        }

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

    // ── Mark arrived ──────────────────────────────────────────────
    async function handleMarkArrived(data, btn, box) {
        const isArrived = !!data.arrived_at;
        if (isArrived) {
            if (!confirm('Odznačiti gosta kot prišlega? S tem bo izbrisano tudi morebitno načrtovano pošiljanje ankete.')) return;
        }
        btn.disabled = true;
        try {
            const res = await API.post(
                `/api/reservations.php?action=mark_arrived&id=${data.id}`,
                { undo: isArrived }
            );
            data.arrived_at = res.arrived_at || null;
            if (window.App) App.showToast(isArrived ? 'Prihod odznačen.' : 'Gost označen kot prišel!', 'success');
            // Osveži footer – znova odpri view modal
            open('view', data);
        } catch (e) {
            btn.disabled = false;
            if (window.App) App.showToast(e.message || 'Napaka.', 'error');
        }
    }

    // ── Send survey now ───────────────────────────────────────────
    async function handleSendSurvey(data, btn) {
        btn.disabled = true;
        btn.textContent = 'Pošiljam...';
        try {
            const res = await API.post(`/api/survey.php?action=send_now`, { reservation_id: data.id, force: false });
            if (window.App) App.showToast('Anketa poslana!', 'success');
            btn.textContent = 'Poslano ✓';
            btn.style.cssText = 'color:#065F46;border-color:#6EE7B7;background:#ECFDF5;cursor:default';
        } catch (e) {
            // Preverimo ali je bila že poslana
            if (e.data && e.data.already_sent) {
                const sentAt = e.data.sent_at ? new Date(e.data.sent_at.replace(' ','T')).toLocaleString('sl-SI') : '';
                if (confirm(`Anketa je bila že poslana (${sentAt}). Poslati znova?`)) {
                    try {
                        await API.post(`/api/survey.php?action=send_now`, { reservation_id: data.id, force: true });
                        if (window.App) App.showToast('Anketa znova poslana!', 'success');
                        btn.textContent = 'Poslano ✓';
                        btn.style.cssText = 'color:#065F46;border-color:#6EE7B7;background:#ECFDF5;cursor:default';
                        return;
                    } catch (e2) { /* pade spodaj */ }
                }
            }
            btn.disabled = false;
            btn.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2 11 13M22 2 15 22 11 13 2 9l20-7z"/></svg> Pošlji anketo`;
            if (window.App) App.showToast(e.message || 'Pošiljanje ni uspelo.', 'error');
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

    // ── Prestavi mizo (dialog) ────────────────────────────────────
    async function openTableMoveDialog(data, box) {
        const base = APP_STATE.base || '';
        const restId = data.restaurant_id;
        if (!restId) return;

        // Naloži razpoložljive mize za restavracijo
        let tablesResp;
        try {
            const r = await fetch(`${base}/api/tables.php?restaurant_id=${restId}`, { credentials:'same-origin' });
            tablesResp = await r.json();
        } catch(e) { if (window.App) App.showToast('Napaka pri nalaganju miz.','error'); return; }
        if (!tablesResp.success || !tablesResp.data?.tables?.length) {
            if (window.App) App.showToast('Za to restavracijo ni definiranih miz.','error');
            return;
        }

        const allTables = tablesResp.data.tables.filter(t => t.is_active);
        const currentAssigned = (data.table_assignments || []).map(a => a.table_id);

        // Mini-dialog overlay
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;display:flex;align-items:center;justify-content:center;padding:16px';

        const dlg = document.createElement('div');
        dlg.style.cssText = 'background:#fff;border-radius:14px;max-width:420px;width:100%;padding:24px;box-shadow:0 8px 30px rgba(0,0,0,.18)';
        dlg.innerHTML = `
            <h3 style="margin:0 0 16px;font-size:1rem;font-weight:700">Prestavi mizo</h3>
            <p style="font-size:.8rem;color:#6B7280;margin:0 0 12px">Izberite mizo za rezervacijo <strong>${escText(data.guest_name)}</strong> (${data.guest_count} os.):</p>
            <div id="move-table-list" style="display:flex;flex-direction:column;gap:6px;max-height:260px;overflow-y:auto;margin-bottom:16px">
                ${allTables.map(t => `
                    <label style="display:flex;align-items:center;gap:8px;padding:8px 12px;border:1.5px solid ${currentAssigned.includes(t.id)?'#7C3AED':'#E5E7EB'};border-radius:8px;cursor:pointer;background:${currentAssigned.includes(t.id)?'#F5F3FF':'#FAFAFA'}">
                        <input type="radio" name="move-table" value="${t.id}" ${currentAssigned.includes(t.id)?'checked':''} style="accent-color:#7C3AED">
                        <span style="flex:1;font-size:.875rem;font-weight:500">${escText(t.name)}</span>
                        <span style="font-size:.75rem;color:#6B7280">${t.capacity} os.</span>
                        ${t.area_name ? `<span style="font-size:.7rem;padding:2px 6px;border-radius:4px;background:#EDE9FE;color:#5B21B6">${escText(t.area_name)}</span>` : ''}
                    </label>
                `).join('')}
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end">
                <button id="move-cancel" class="btn btn-ghost">Prekliči</button>
                <button id="move-confirm" class="btn btn-primary" style="background:#7C3AED;border-color:#7C3AED">Prestavi</button>
            </div>
        `;

        overlay.appendChild(dlg);
        document.body.appendChild(overlay);
        overlay.addEventListener('click', e => { if(e.target===overlay) overlay.remove(); });
        document.getElementById('move-cancel').addEventListener('click', () => overlay.remove());

        document.getElementById('move-confirm').addEventListener('click', async () => {
            const sel = dlg.querySelector('input[name="move-table"]:checked');
            if (!sel) { if(window.App) App.showToast('Izberite mizo.','error'); return; }
            const tableId = parseInt(sel.value);
            try {
                const res = await fetch(`${base}/api/table_assignment.php`, {
                    method: 'PUT',
                    headers: { 'Content-Type':'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ reservation_id: data.id, table_ids: [tableId], merge_group_id: null }),
                });
                const json = await res.json();
                if (!json.success) throw new Error(json.error || 'Napaka');
                overlay.remove();
                if (window.App) App.showToast('Miza prestavljena.','success');
                // Osveži modal z novimi podatki
                data.table_assignments = json.data?.table_assignments || [{ table_id: tableId, table_name: sel.closest('label').querySelector('span').textContent }];
                open('view', data);
            } catch(e) { if(window.App) App.showToast(e.message,'error'); }
        });
    }

    return { open, close };
})();

/**
 * Modal za odobritev/zavrnitev pending rezervacij.
 */
const PendingModal = (() => {
    function escText(str) {
        const d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    function formatDate(ds) {
        if (!ds) return '';
        const [y, m, d] = ds.split('-');
        const months = ['jan','feb','mar','apr','maj','jun','jul','avg','sep','okt','nov','dec'];
        return `${parseInt(d)}. ${months[parseInt(m)-1]} ${y}`;
    }

    function open(pendingList) {
        const existing = document.getElementById('pending-modal-overlay');
        if (existing) existing.remove();

        const first = pendingList[0];
        const time  = first.reservation_time ? first.reservation_time.substring(0,5) : '';
        const label = `${time} – ${formatDate(first.reservation_date)}`;

        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'pending-modal-overlay';

        const box = document.createElement('div');
        box.className = 'modal-box';
        box.style.maxWidth = '520px';

        // Glava
        box.innerHTML = `
            <div class="modal-header">
                <div class="modal-title">Čakajoče rezervacije – ${escText(label)}</div>
                <button class="modal-close" id="pending-modal-close">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body" id="pending-modal-body"></div>
            <div class="modal-footer">
                <button class="btn btn-ghost" id="pending-modal-close-btn">Zapri</button>
            </div>`;

        overlay.appendChild(box);
        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
        document.body.appendChild(overlay);

        box.querySelector('#pending-modal-close').addEventListener('click', () => overlay.remove());
        box.querySelector('#pending-modal-close-btn').addEventListener('click', () => overlay.remove());

        renderList(pendingList, box.querySelector('#pending-modal-body'));
    }

    function renderList(pendingList, container) {
        container.innerHTML = '';
        if (pendingList.length === 0) {
            container.innerHTML = '<p style="text-align:center;color:var(--color-muted);padding:16px 0">Ni čakajočih rezervacij.</p>';
            return;
        }
        pendingList.forEach(r => {
            const row = document.createElement('div');
            row.id = `pending-row-${r.id}`;
            row.style.cssText = 'border:1px solid #E5E7EB;border-radius:10px;padding:14px 16px;margin-bottom:12px;background:#FAFAFA';

            const cfRows = (r.field_values || []).map(fv =>
                `<div style="grid-column:1/-1"><span style="color:#6B7280;font-size:.75rem;display:block">${escText(fv.label)}</span>${escText(fv.value === '1' ? 'Da' : fv.value === '0' ? 'Ne' : fv.value)}</div>`
            ).join('');

            row.innerHTML = `
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;margin-bottom:10px;font-size:.875rem">
                    <div><span style="color:#6B7280;font-size:.75rem;display:block">Ime</span><strong>${escText(r.guest_name)}</strong></div>
                    <div><span style="color:#6B7280;font-size:.75rem;display:block">Gostov</span><strong>${escText(String(r.guest_count))}</strong></div>
                    <div><span style="color:#6B7280;font-size:.75rem;display:block">Email</span>${r.email ? escText(r.email) : '<span style="color:#9CA3AF">—</span>'}</div>
                    <div><span style="color:#6B7280;font-size:.75rem;display:block">Telefon</span>${r.phone ? escText(r.phone) : '<span style="color:#9CA3AF">—</span>'}</div>
                    ${r.staff_name ? `<div style="grid-column:1/-1"><span style="color:#6B7280;font-size:.75rem;display:block">Sprejel/a</span>${escText(r.staff_name)}</div>` : ''}
                    ${r.notes ? `<div style="grid-column:1/-1"><span style="color:#6B7280;font-size:.75rem;display:block">Opomba</span>${escText(r.notes)}</div>` : ''}
                    ${cfRows}
                </div>
                <div style="display:flex;gap:8px">
                    <button class="btn btn-primary" style="background:#16a34a;flex:1" data-action="approve" data-id="${r.id}">✓ Potrdi</button>
                    <button class="btn btn-ghost" style="color:#dc2626;border-color:#dc2626;flex:1" data-action="reject" data-id="${r.id}">✗ Zavrni</button>
                </div>`;

            row.querySelectorAll('[data-action]').forEach(btn => {
                btn.addEventListener('click', () => handleAction(btn.dataset.action, parseInt(btn.dataset.id), row));
            });

            container.appendChild(row);
        });
    }

    async function handleAction(action, id, rowEl) {
        const btns = rowEl.querySelectorAll('button');
        btns.forEach(b => { b.disabled = true; });

        try {
            await API.put(`/api/reservations.php?id=${id}&action=${action}`, {});
            const msg = action === 'approve' ? 'Rezervacija potrjena!' : 'Rezervacija zavrnjena.';
            if (window.App) App.showToast(msg, action === 'approve' ? 'success' : 'error');

            // Animiraj izginotje vrstice
            rowEl.style.transition = 'opacity .3s';
            rowEl.style.opacity = '0';
            setTimeout(() => {
                rowEl.remove();
                // Osveži razpored in badge
                if (window.App) App.afterReservationChange(null);
                updatePendingBadge();
                // Zapri modal če ni več vrstic
                const body = document.getElementById('pending-modal-body');
                if (body && body.children.length === 0) {
                    const overlay = document.getElementById('pending-modal-overlay');
                    if (overlay) overlay.remove();
                }
            }, 300);
        } catch (e) {
            btns.forEach(b => { b.disabled = false; });
            if (window.App) App.showToast(e.message, 'error');
        }
    }

    function updatePendingBadge() {
        const badge = document.getElementById('pending-badge');
        if (!badge) return;
        const current = parseInt(badge.textContent) || 0;
        const next = Math.max(0, current - 1);
        if (window.PendingSection) {
            PendingSection.updateGlobalBadge(next);
        } else {
            badge.textContent = next;
            badge.style.display = next > 0 ? 'inline-flex' : 'none';
            const btn = document.getElementById('btn-pending');
            if (btn) btn.style.display = next > 0 ? '' : 'none';
        }
    }

    return { open };
})();

/**
 * Inline sekcija čakajočih rezervacij (ne modal).
 */
const PendingSection = (() => {
    function escText(str) {
        const d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    function formatDate(ds) {
        if (!ds) return '';
        const days   = ['ned', 'pon', 'tor', 'sre', 'čet', 'pet', 'sob'];
        const months = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'avg', 'sep', 'okt', 'nov', 'dec'];
        const dt = new Date(ds + 'T00:00:00');
        return `${days[dt.getDay()]}, ${dt.getDate()}. ${months[dt.getMonth()]}`;
    }

    async function load() {
        try {
            const list = await API.get('/api/reservations.php?pending=1');
            render(list || []);
        } catch (e) {
            // tiha napaka – ne moti uporabnika
        }
    }

    function render(list) {
        const section   = document.getElementById('pending-section');
        const container = document.getElementById('pending-list');
        const badge     = document.getElementById('pending-section-badge');
        if (!section || !container) return;

        if (badge) badge.textContent = list.length;
        updateGlobalBadge(list.length);

        if (list.length === 0) {
            section.style.display = 'none';
            return;
        }

        section.style.display = '';
        container.innerHTML = '';

        const showRest = APP_STATE.restaurants && APP_STATE.restaurants.length > 1;

        list.forEach(r => {
            const item = document.createElement('div');
            item.className = 'pending-item';
            item.id = `pending-item-${r.id}`;

            const time  = r.reservation_time ? r.reservation_time.substring(0, 5) : '';
            const cnt   = parseInt(r.guest_count);
            const cntTxt = cnt === 1 ? '1 oseba' : `${cnt} oseb`;

            let infoHtml = `
                <div class="pending-item-when">${escText(formatDate(r.reservation_date))} · ${escText(time)}</div>
                <div class="pending-item-guest">${escText(r.guest_name)} · ${escText(cntTxt)}</div>`;

            if (showRest && r.restaurant_name) {
                infoHtml += `<div class="pending-item-rest">🍽 ${escText(r.restaurant_name)}</div>`;
            }

            const contacts = [];
            if (r.email) contacts.push(`📧 ${escText(r.email)}`);
            if (r.phone) contacts.push(`📞 ${escText(r.phone)}`);
            if (contacts.length) {
                infoHtml += `<div class="pending-item-contact">${contacts.join(' · ')}</div>`;
            }

            if (r.notes) {
                infoHtml += `<div class="pending-item-notes">"${escText(r.notes)}"</div>`;
            }

            if (r.staff_name) {
                infoHtml += `<div class="pending-item-extra"><span class="pending-item-label">Sprejel/a:</span> ${escText(r.staff_name)}</div>`;
            }

            (r.field_values || []).forEach(fv => {
                const val = fv.value === '1' ? 'Da' : fv.value === '0' ? 'Ne' : fv.value;
                infoHtml += `<div class="pending-item-extra"><span class="pending-item-label">${escText(fv.label)}:</span> ${escText(val)}</div>`;
            });

            item.innerHTML = `
                <div class="pending-item-info">${infoHtml}</div>
                <div class="pending-item-actions">
                    <button class="btn btn-sm" style="background:#16a34a;color:#fff;border:none" data-action="approve" data-id="${r.id}">✓ Potrdi</button>
                    <button class="btn btn-sm btn-ghost" style="color:#dc2626;border-color:#fca5a5" data-action="reject" data-id="${r.id}">✗ Zavrni</button>
                </div>`;

            item.querySelectorAll('[data-action]').forEach(btn => {
                btn.addEventListener('click', () => handleAction(btn.dataset.action, parseInt(btn.dataset.id), item));
            });

            container.appendChild(item);
        });
    }

    async function handleAction(action, id, itemEl) {
        const btns = itemEl.querySelectorAll('button');
        btns.forEach(b => { b.disabled = true; });

        try {
            await API.put(`/api/reservations.php?id=${id}&action=${action}`, {});

            itemEl.style.transition = 'opacity .25s';
            itemEl.style.opacity = '0';
            setTimeout(() => {
                itemEl.remove();
                const remaining = document.querySelectorAll('#pending-list .pending-item').length;
                const badge = document.getElementById('pending-section-badge');
                if (badge) badge.textContent = remaining;
                if (remaining === 0) {
                    const section = document.getElementById('pending-section');
                    if (section) section.style.display = 'none';
                }
                updateGlobalBadge(remaining);
                const msg = action === 'approve' ? 'Rezervacija potrjena!' : 'Rezervacija zavrnjena.';
                if (window.App) App.afterReservationChange(msg);
            }, 250);
        } catch (e) {
            btns.forEach(b => { b.disabled = false; });
            if (window.App) App.showToast(e.message, 'error');
        }
    }

    function updateGlobalBadge(count) {
        const badge = document.getElementById('pending-badge');
        if (badge) {
            badge.textContent = count;
            badge.style.display = count > 0 ? 'inline-flex' : 'none';
        }
        const btn = document.getElementById('btn-pending');
        if (btn) btn.style.display = count > 0 ? '' : 'none';
    }

    async function loadAndScroll() {
        await load();
        const section = document.getElementById('pending-section');
        if (section && section.style.display !== 'none') {
            section.scrollIntoView({ behavior: 'smooth' });
        } else if (window.App) {
            App.showToast('Ni čakajočih rezervacij.', 'info');
        }
    }

    return { load, render, updateGlobalBadge, loadAndScroll };
})();
