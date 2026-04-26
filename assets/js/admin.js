/**
 * Admin panel – tabs, CRUD za restavracije in userje.
 */
(function () {
    'use strict';

    // ── Tab switching ─────────────────────────────────────────────
    function initTabs() {
        document.querySelectorAll('.admin-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.admin-panel').forEach(p => p.classList.remove('active'));
                tab.classList.add('active');
                const panel = document.getElementById('panel-' + tab.dataset.tab);
                if (panel) panel.classList.add('active');
            });
        });
    }

    // ── Toast ─────────────────────────────────────────────────────
    function toast(msg, type = 'success') {
        if (!msg) return;
        const container = document.getElementById('toast-container');
        const t = document.createElement('div');
        t.className = `toast toast-${type}`;
        t.textContent = msg;
        container.appendChild(t);
        setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(() => t.remove(), 300); }, 3200);
    }

    function h(str) {
        const d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    function minsToTime(m) {
        return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
    }
    function timeToMins(t) {
        const [hh, mm] = (t || '').split(':').map(Number);
        return (hh || 0) * 60 + (mm || 0);
    }

    // ── Per-day schedule helpers ──────────────────────────────────
    const DAY_NAMES_FULL = ['Ponedeljek', 'Torek', 'Sreda', 'Četrtek', 'Petek', 'Sobota', 'Nedelja'];

    window.toggleDayRow = function(day, open) {
        const wrap = document.querySelector(`.day-times-wrap[data-day="${day}"]`);
        if (wrap) { wrap.style.opacity = open ? '1' : '.35'; wrap.style.pointerEvents = open ? '' : 'none'; }
    };

    function buildDayScheduleHtml(daySchedules) {
        return DAY_NAMES_FULL.map((name, i) => {
            const ds     = daySchedules ? daySchedules.find(d => d.day_of_week == i) : null;
            const isOpen = ds ? !!ds.is_open : (i < 5); // default: Pon–Pet odprti
            const start  = ds ? minsToTime(ds.start_time) : '08:00';
            const end    = ds ? minsToTime(ds.end_time)   : '23:00';
            return `
            <div style="display:flex;align-items:center;gap:10px;padding:7px 0;${i < 6 ? 'border-bottom:1px solid #F3F4F6;' : ''}">
                <label style="display:flex;align-items:center;gap:7px;width:130px;cursor:pointer;flex-shrink:0">
                    <input type="checkbox" class="day-open-cb" data-day="${i}"
                        ${isOpen ? 'checked' : ''}
                        style="width:15px;height:15px;accent-color:#1B4332;cursor:pointer;flex-shrink:0"
                        onchange="toggleDayRow(${i},this.checked)">
                    <span style="font-size:.85rem;font-weight:500;color:#374151">${name}</span>
                </label>
                <div class="day-times-wrap" data-day="${i}"
                    style="display:flex;align-items:center;gap:6px;${!isOpen ? 'opacity:.35;pointer-events:none;' : ''}">
                    <input type="time" class="day-start" data-day="${i}" value="${start}"
                        style="border:1px solid #E5E7EB;border-radius:6px;padding:5px 8px;font-size:.8rem;font-family:inherit;color:#374151;width:90px">
                    <span style="color:#9CA3AF;font-size:.75rem">–</span>
                    <input type="time" class="day-end" data-day="${i}" value="${end}"
                        style="border:1px solid #E5E7EB;border-radius:6px;padding:5px 8px;font-size:.8rem;font-family:inherit;color:#374151;width:90px">
                </div>
            </div>`;
        }).join('');
    }

    function getDaySchedules() {
        return Array.from({length: 7}, (_, i) => ({
            day_of_week: i,
            is_open:     document.querySelector(`.day-open-cb[data-day="${i}"]`)?.checked ? 1 : 0,
            start_time:  timeToMins(document.querySelector(`.day-start[data-day="${i}"]`)?.value || '08:00'),
            end_time:    timeToMins(document.querySelector(`.day-end[data-day="${i}"]`)?.value   || '23:00'),
        }));
    }

    // ── Blackout helpers ──────────────────────────────────────────
    function buildBlackoutListHtml(blackouts, restId) {
        if (!blackouts || blackouts.length === 0) {
            return '<p style="font-size:.825rem;color:#9CA3AF;padding:4px 0">Ni blokiranih datumov.</p>';
        }
        return blackouts.map(b => `
            <div class="blackout-item" data-date="${b.blackout_date}"
                style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:#FEF2F2;border-radius:6px;margin-bottom:6px;font-size:.825rem">
                <span style="font-weight:600;color:#374151">${h(b.blackout_date)}</span>
                ${b.reason ? `<span style="color:#6B7280">– ${h(b.reason)}</span>` : ''}
                <button type="button"
                    style="margin-left:auto;color:#EF4444;background:none;border:none;cursor:pointer;font-size:1.1rem;line-height:1;padding:0 2px"
                    onclick="AdminRestaurants.removeBlackout(${restId},'${b.blackout_date}',this)">×</button>
            </div>`).join('');
    }

    // ── Booking URL ───────────────────────────────────────────────
    function bookingUrl(token) {
        const base = window.location.href.replace(/\/pages\/[^/]*(\?.*)?$/, '');
        return base + '/book.php?t=' + token;
    }

    function embedCode(token) {
        const base = window.location.href.replace(/\/pages\/[^/]*(\?.*)?$/, '');
        return `<div id="rez-widget"></div>\n<script src="${base}/widget.js" data-token="${token}" data-container="#rez-widget"><\/script>`;
    }

    // ─────────────────────────────────────────────────────────────
    // RESTAVRACIJE
    // ─────────────────────────────────────────────────────────────
    let restaurants = [];

    async function loadRestaurants() {
        try {
            restaurants = await API.get('/api/restaurants.php') || [];
            renderRestaurantsTable();
        } catch (e) { toast(e.message, 'error'); }
    }

    function renderRestaurantsTable() {
        const tbody = document.getElementById('rest-tbody');
        if (!tbody) return;

        if (restaurants.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" class="table-empty">Ni restavracij. Dodajte prvo.</td></tr>`;
            return;
        }

        tbody.innerHTML = restaurants.map(r => {
            const bookingCell = r.booking_enabled == 1 && r.booking_token
                ? `<button class="btn-icon" title="Kopiraj rezervacijsko povezavo" onclick="AdminRestaurants.copyLink('${h(r.booking_token)}')">
                       <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                   </button>`
                : `<span style="color:var(--color-muted)">—</span>`;
            return `
            <tr>
                <td><strong>${h(r.name)}</strong></td>
                <td>${r.reservation_duration} min</td>
                <td><span class="color-swatch" style="background:${h(r.color)}"></span> ${h(r.color)}</td>
                <td><span class="badge ${r.is_active == 1 ? 'badge-active' : 'badge-inactive'}">${r.is_active == 1 ? 'Aktivna' : 'Neaktivna'}</span></td>
                <td>${bookingCell}</td>
                <td>
                    <div class="table-actions">
                        <button class="btn-icon" title="Uredi" onclick="AdminRestaurants.edit(${r.id})">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button class="btn-icon danger" title="Deaktiviraj" onclick="AdminRestaurants.deactivate(${r.id},'${h(r.name)}')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                        </button>
                        <button class="btn-icon danger" title="Trajno izbriši" onclick="AdminRestaurants.delete(${r.id},'${h(r.name)}')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        </button>
                    </div>
                </td>
            </tr>`;
        }).join('');
    }

    // ── Modal za restavracijo ─────────────────────────────────────
    async function openRestModal(mode, restId = null) {
        let rest = null;
        if (mode === 'edit' && restId) {
            // Najprej vzemi iz lokalnega seznama (osnovni podatki)
            const basic = restaurants.find(r => r.id === restId) || null;
            try {
                rest = await API.get(`/api/restaurants.php?id=${restId}`);
            } catch (e) {
                // Fallback na osnovne podatke brez day_schedules/blackouts
                rest = basic;
                toast('Opozorilo: Nekateri podatki niso bili naloženi.', 'error');
            }
            if (!rest) { toast('Restavracija ni najdena.', 'error'); return; }
        }

        const title = mode === 'edit' ? 'Uredi restavracijo' : 'Nova restavracija';
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'admin-modal';

        overlay.innerHTML = `
        <div class="modal-box" style="max-width:560px">
            <div class="modal-header">
                <div class="modal-title">${title}</div>
                <button class="modal-close" onclick="document.getElementById('admin-modal').remove()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div id="admin-rest-error" class="admin-error"></div>
                <div class="admin-form">

                    <div class="admin-field-row">
                        <div class="admin-field">
                            <label>Ime restavracije *</label>
                            <input id="r-name" type="text" value="${h(rest?.name || '')}" required>
                        </div>
                        <div class="admin-field" style="max-width:120px">
                            <label>Barva</label>
                            <input id="r-color" type="color" value="${rest?.color || '#F59E0B'}" style="height:42px;padding:4px;width:100%">
                        </div>
                    </div>

                    <div class="admin-field-row">
                        <div class="admin-field">
                            <label>Trajanje rezervacije (min)</label>
                            <input id="r-duration" type="number" min="15" step="15" value="${rest?.reservation_duration || 60}">
                        </div>
                        <div class="admin-field" style="justify-content:flex-end;padding-top:20px">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500">
                                <input type="checkbox" id="r-allow-custom"
                                    style="width:16px;height:16px;accent-color:#F59E0B;cursor:pointer;flex-shrink:0"
                                    ${rest?.allow_custom_duration ? 'checked' : ''}>
                                Sprememba trajanja
                            </label>
                        </div>
                    </div>

                    <!-- Urnik po dnevih -->
                    <div style="border-top:1px solid #E5E7EB;margin:14px 0 10px;padding-top:12px">
                        <div style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6B7280;margin-bottom:8px">Urnik</div>
                        ${buildDayScheduleHtml(rest?.day_schedules || null)}
                    </div>

                    <!-- Blokirani datumi -->
                    <div style="border-top:1px solid #E5E7EB;margin:14px 0 10px;padding-top:12px">
                        <div style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6B7280;margin-bottom:8px">Blokirani datumi (izjeme)</div>
                        <div style="display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap">
                            <input type="date" id="blackout-date-input" min="${new Date().toISOString().slice(0,10)}"
                                style="flex:1;min-width:130px;border:1px solid #E5E7EB;border-radius:6px;padding:7px 10px;font-size:.825rem;font-family:inherit">
                            <input type="text" id="blackout-reason-input" placeholder="Razlog (neob.)"
                                style="flex:2;min-width:120px;border:1px solid #E5E7EB;border-radius:6px;padding:7px 10px;font-size:.825rem;font-family:inherit">
                            <button type="button" class="btn btn-primary" style="padding:7px 12px;white-space:nowrap;font-size:.8rem"
                                onclick="AdminRestaurants.addBlackout(${rest?.id || 0})">+ Dodaj</button>
                        </div>
                        <div id="blackout-list">${buildBlackoutListHtml(rest?.blackouts || [], rest?.id || 0)}</div>
                    </div>

                    <!-- Spletne rezervacije -->
                    <div style="border-top:1px solid #E5E7EB;margin:14px 0 10px;padding-top:12px">
                        <div style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6B7280;margin-bottom:8px">Spletne rezervacije</div>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:10px">
                            <input type="checkbox" id="r-booking-enabled"
                                style="width:17px;height:17px;accent-color:#1B4332;cursor:pointer;flex-shrink:0"
                                ${rest?.booking_enabled == 1 ? 'checked' : ''}
                                onchange="document.getElementById('r-booking-settings').style.display=this.checked?'':'none'">
                            <span style="font-size:.875rem;font-weight:500;color:#374151">Omogoči spletne rezervacije</span>
                        </label>
                        <div id="r-booking-settings" style="display:${rest?.booking_enabled == 1 ? '' : 'none'}">
                            <div class="admin-field-row" style="margin-bottom:8px">
                                <div class="admin-field">
                                    <label>Min. gostov</label>
                                    <input id="r-min-guests" type="number" min="1" max="99" value="${rest?.booking_min_guests ?? 2}">
                                </div>
                                <div class="admin-field">
                                    <label>Max. gostov</label>
                                    <input id="r-max-guests" type="number" min="1" max="500" value="${rest?.booking_max_guests ?? 10}">
                                </div>
                            </div>
                            <div class="admin-field" style="margin-bottom:10px">
                                <label>Razmak med termini (spletna rezervacija)</label>
                                <select id="r-slot-interval">
                                    ${[15,20,30,45,60,90,120].map(m => {
                                        const lbl = m < 60 ? `${m} min` : m === 60 ? '1 ura' : m === 90 ? '1,5 ure' : `${m/60} uri`;
                                        const cur = rest?.booking_slot_interval ?? rest?.reservation_duration ?? 60;
                                        return `<option value="${m}" ${cur == m ? 'selected' : ''}>${lbl}</option>`;
                                    }).join('')}
                                </select>
                            </div>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:${rest?.booking_token ? '12px' : '0'}">
                                <input type="checkbox" id="r-auto-confirm"
                                    style="width:16px;height:16px;accent-color:#1B4332;cursor:pointer;flex-shrink:0"
                                    ${(rest?.booking_auto_confirm ?? 1) == 1 ? 'checked' : ''}>
                                <span style="font-size:.875rem;font-weight:500;color:#374151">Samodejno potrdi rezervacije</span>
                            </label>
                            ${rest?.booking_token ? `
                            <div class="admin-field">
                                <label>Rezervacijska povezava</label>
                                <div style="display:flex;gap:6px;align-items:center;margin-top:4px">
                                    <input type="text" value="${bookingUrl(rest.booking_token)}" readonly
                                        style="flex:1;background:#F9FAFB;font-size:.775rem;color:#374151" onclick="this.select()">
                                    <button type="button" class="btn btn-ghost" style="white-space:nowrap;padding:7px 12px;font-size:.8rem"
                                        onclick="AdminRestaurants.copyLink('${rest.booking_token}')">Kopiraj</button>
                                </div>
                            </div>
                            <div class="admin-field">
                                <label>Embed koda <span style="background:#EDE9FE;color:#5B21B6;font-size:.68rem;font-weight:700;padding:1px 7px;border-radius:20px;margin-left:4px">Premium</span></label>
                                <div style="font-size:.775rem;color:#6B7280;margin-bottom:6px">Prilepite to kodo na katerokoli spletno stran.</div>
                                <textarea readonly onclick="this.select()" rows="3"
                                    style="width:100%;background:#F9FAFB;font-size:.72rem;color:#374151;font-family:monospace;line-height:1.5;resize:none;border:1px solid var(--color-border);border-radius:8px;padding:8px 10px"
                                >${embedCode(rest.booking_token)}</textarea>
                                <button type="button" class="btn btn-ghost" style="margin-top:6px;font-size:.8rem;padding:6px 12px"
                                    onclick="AdminRestaurants.copyEmbed('${rest.booking_token}')">Kopiraj embed kodo</button>
                            </div>` : ''}
                        </div>
                    </div>

                    ${rest ? `
                    <!-- Zaposleni -->
                    <div style="border-top:1px solid #E5E7EB;margin:14px 0 10px;padding-top:12px">
                        <div style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6B7280;margin-bottom:8px">Zaposleni</div>
                        <div id="staff-list" style="margin-bottom:8px"><span style="font-size:.825rem;color:#9CA3AF">Nalagam...</span></div>
                        <div style="display:flex;gap:6px">
                            <input type="text" id="staff-name-input" placeholder="Ime zaposlenega"
                                style="flex:1;border:1px solid #E5E7EB;border-radius:6px;padding:7px 10px;font-size:.825rem;font-family:inherit">
                            <button type="button" class="btn btn-primary" style="padding:7px 12px;white-space:nowrap;font-size:.8rem"
                                onclick="AdminStaff.add(${rest.id})">+ Dodaj</button>
                        </div>
                    </div>

                    <!-- Polja po meri -->
                    <div style="border-top:1px solid #E5E7EB;margin:14px 0 10px;padding-top:12px">
                        <div style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6B7280;margin-bottom:8px">Polja po meri</div>
                        <div id="cf-list" style="margin-bottom:8px"><span style="font-size:.825rem;color:#9CA3AF">Nalagam...</span></div>
                        <details style="margin-top:6px">
                            <summary style="font-size:.8rem;color:var(--color-accent);cursor:pointer;font-weight:600;list-style:none;display:flex;align-items:center;gap:5px">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                Dodaj polje
                            </summary>
                            <div style="margin-top:10px;padding:12px;background:#F9FAFB;border-radius:8px;border:1px solid #E5E7EB;display:flex;flex-direction:column;gap:8px">
                                <div class="admin-field-row" style="margin:0">
                                    <div class="admin-field" style="margin:0">
                                        <label>Oznaka *</label>
                                        <input type="text" id="cf-label" placeholder="npr. Alergije">
                                    </div>
                                    <div class="admin-field" style="margin:0;max-width:120px">
                                        <label>Tip</label>
                                        <select id="cf-type" onchange="AdminCustomFields.toggleOptions(this.value)">
                                            <option value="text">Besedilo</option>
                                            <option value="select">Izbira</option>
                                            <option value="checkbox">Da/Ne</option>
                                        </select>
                                    </div>
                                </div>
                                <div id="cf-options-wrap" style="display:none">
                                    <label style="font-size:.775rem;font-weight:500;color:#374151;margin-bottom:3px;display:block">Možnosti (ena na vrstico) *</label>
                                    <textarea id="cf-options" rows="3" placeholder="Opcija 1&#10;Opcija 2&#10;Opcija 3"
                                        style="width:100%;border:1px solid #E5E7EB;border-radius:6px;padding:7px 10px;font-size:.8rem;font-family:inherit;resize:vertical"></textarea>
                                </div>
                                <div class="admin-field-row" style="margin:0">
                                    <div class="admin-field" style="margin:0">
                                        <label>Velja za</label>
                                        <select id="cf-applies">
                                            <option value="both">Interno + Splet</option>
                                            <option value="internal">Samo interno</option>
                                            <option value="public">Samo splet</option>
                                        </select>
                                    </div>
                                    <div class="admin-field" style="margin:0;justify-content:flex-end;padding-top:18px">
                                        <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-weight:500;font-size:.875rem">
                                            <input type="checkbox" id="cf-required" style="width:15px;height:15px;accent-color:#F59E0B;cursor:pointer">
                                            Obvezno
                                        </label>
                                    </div>
                                </div>
                                <div style="text-align:right">
                                    <button type="button" class="btn btn-primary" style="font-size:.8rem;padding:7px 16px"
                                        onclick="AdminCustomFields.add(${rest.id})">Shrani polje</button>
                                </div>
                            </div>
                        </details>
                    </div>

                    <div class="admin-field">
                        <label>Status</label>
                        <select id="r-active">
                            <option value="1" ${rest.is_active == 1 ? 'selected' : ''}>Aktivna</option>
                            <option value="0" ${rest.is_active == 0 ? 'selected' : ''}>Neaktivna</option>
                        </select>
                    </div>` : ''}

                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" onclick="document.getElementById('admin-modal').remove()">Prekliči</button>
                <button class="btn btn-primary" id="admin-rest-save">Shrani</button>
            </div>
        </div>`;

        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
        document.body.appendChild(overlay);

        // Naloži zaposlene in polja po meri (samo edit mode)
        if (mode === 'edit' && rest) {
            AdminStaff.load(rest.id);
            AdminCustomFields.load(rest.id);
        }

        document.getElementById('admin-rest-save').addEventListener('click', async () => {
            const name           = document.getElementById('r-name').value.trim();
            const duration       = parseInt(document.getElementById('r-duration').value);
            const color          = document.getElementById('r-color').value;
            const allowCustom    = document.getElementById('r-allow-custom').checked ? 1 : 0;
            const active         = rest ? parseInt(document.getElementById('r-active').value) : undefined;
            const bookingEnabled      = document.getElementById('r-booking-enabled').checked ? 1 : 0;
            const bookingSlotInterval = parseInt(document.getElementById('r-slot-interval')?.value || '60');
            const bookingAutoConfirm  = document.getElementById('r-auto-confirm')?.checked ? 1 : 0;
            const bookingMinGuests    = parseInt(document.getElementById('r-min-guests')?.value || '2');
            const bookingMaxGuests    = parseInt(document.getElementById('r-max-guests')?.value || '10');
            const daySchedules   = getDaySchedules();
            const errEl          = document.getElementById('admin-rest-error');

            if (!name) { errEl.textContent = 'Ime je obvezno.'; errEl.style.display = 'block'; return; }
            if (bookingMinGuests > bookingMaxGuests) { errEl.textContent = 'Min. gostov ne more biti večje od max.'; errEl.style.display = 'block'; return; }

            // Preveri da vsaj en odprt dan ima veljaven čas
            const openDays = daySchedules.filter(d => d.is_open);
            const invalidDay = openDays.find(d => d.end_time <= d.start_time);
            if (invalidDay) { errEl.textContent = `${DAY_NAMES_FULL[invalidDay.day_of_week]}: končni čas mora biti večji od začetnega.`; errEl.style.display = 'block'; return; }

            errEl.style.display = 'none';

            const payload = {
                name,
                reservation_duration: duration,
                allow_custom_duration: allowCustom,
                color,
                day_schedules: daySchedules,
                booking_enabled: bookingEnabled,
                booking_slot_interval: bookingSlotInterval,
                booking_auto_confirm: bookingAutoConfirm,
                booking_min_guests: bookingMinGuests,
                booking_max_guests: bookingMaxGuests,
            };

            const saveBtn = document.getElementById('admin-rest-save');
            saveBtn.disabled = true; saveBtn.textContent = '...';

            try {
                if (mode === 'edit' && rest) {
                    await API.put(`/api/restaurants.php?id=${rest.id}`, { ...payload, is_active: active });
                    toast('Restavracija posodobljena!');
                } else {
                    await API.post('/api/restaurants.php', payload);
                    toast('Restavracija dodana!');
                }
                overlay.remove();
                loadRestaurants();
            } catch (e) {
                errEl.textContent = e.message;
                errEl.style.display = 'block';
                saveBtn.disabled = false; saveBtn.textContent = 'Shrani';
            }
        });
    }

    // ─────────────────────────────────────────────────────────────
    // ZAPOSLENI
    // ─────────────────────────────────────────────────────────────
    const AdminStaff = {
        load: async (restId) => {
            const el = document.getElementById('staff-list');
            if (!el) return;
            try {
                const staff = await API.get(`/api/staff.php?restaurant_id=${restId}`) || [];
                el.innerHTML = staff.length ? staff.map(s => AdminStaff._row(s, restId)).join('') : '<p style="font-size:.825rem;color:#9CA3AF;padding:2px 0">Ni zaposlenih.</p>';
            } catch (e) { if (el) el.innerHTML = '<p style="font-size:.825rem;color:#EF4444">Napaka pri nalaganju.</p>'; }
        },
        _row: (s, restId) => `
            <div class="blackout-item" id="staff-row-${s.id}" style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:#F0FDF4;border-radius:6px;margin-bottom:5px;font-size:.825rem">
                <span style="font-weight:600;color:#374151;flex:1">${h(s.name)}</span>
                ${s.is_active == 0 ? '<span style="color:#9CA3AF;font-size:.75rem">(neaktiven)</span>' : ''}
                <button type="button" title="Odstrani" style="color:#EF4444;background:none;border:none;cursor:pointer;font-size:1.1rem;line-height:1;padding:0 2px"
                    onclick="AdminStaff.remove(${s.id}, ${restId})">×</button>
            </div>`,
        add: async (restId) => {
            const input = document.getElementById('staff-name-input');
            const name = input?.value.trim();
            if (!name) { toast('Vnesite ime zaposlenega.', 'error'); return; }
            try {
                await API.post('/api/staff.php', { restaurant_id: restId, name });
                input.value = '';
                await AdminStaff.load(restId);
                toast('Zaposleni dodan!');
            } catch (e) { toast(e.message, 'error'); }
        },
        remove: async (id, restId) => {
            try {
                await API.delete(`/api/staff.php?id=${id}`);
                document.getElementById(`staff-row-${id}`)?.remove();
                toast('Zaposleni odstranjen.');
                // Osveži seznam (soft delete)
                await AdminStaff.load(restId);
            } catch (e) { toast(e.message, 'error'); }
        },
    };
    window.AdminStaff = AdminStaff;

    // ─────────────────────────────────────────────────────────────
    // POLJA PO MERI
    // ─────────────────────────────────────────────────────────────
    const APPLIES_LABELS = { internal: 'Interno', public: 'Splet', both: 'Interno + Splet' };
    const TYPE_LABELS    = { text: 'Besedilo', select: 'Izbira', checkbox: 'Da/Ne' };

    const AdminCustomFields = {
        toggleOptions: (type) => {
            const wrap = document.getElementById('cf-options-wrap');
            if (wrap) wrap.style.display = type === 'select' ? '' : 'none';
        },
        load: async (restId) => {
            const el = document.getElementById('cf-list');
            if (!el) return;
            try {
                const fields = await API.get(`/api/customfields.php?restaurant_id=${restId}`) || [];
                el.innerHTML = fields.length
                    ? fields.map(f => AdminCustomFields._row(f)).join('')
                    : '<p style="font-size:.825rem;color:#9CA3AF;padding:2px 0">Ni polj po meri.</p>';
            } catch (e) { if (el) el.innerHTML = '<p style="font-size:.825rem;color:#EF4444">Napaka pri nalaganju.</p>'; }
        },
        _row: (f) => `
            <div id="cf-row-${f.id}" style="display:flex;align-items:center;gap:8px;padding:7px 10px;background:#FFF7ED;border-radius:6px;margin-bottom:5px;font-size:.825rem;flex-wrap:wrap">
                <span style="font-weight:600;color:#374151;flex:1">${h(f.label)}</span>
                <span style="background:#E5E7EB;color:#374151;border-radius:4px;padding:1px 7px;font-size:.72rem">${h(TYPE_LABELS[f.field_type] || f.field_type)}</span>
                <span style="background:#DBEAFE;color:#1D4ED8;border-radius:4px;padding:1px 7px;font-size:.72rem">${h(APPLIES_LABELS[f.applies_to] || f.applies_to)}</span>
                ${f.is_required ? '<span style="background:#FEE2E2;color:#DC2626;border-radius:4px;padding:1px 7px;font-size:.72rem">Obvezno</span>' : ''}
                <button type="button" title="Izbriši" style="color:#EF4444;background:none;border:none;cursor:pointer;font-size:1.1rem;line-height:1;padding:0 2px"
                    onclick="AdminCustomFields.remove(${f.id})">×</button>
            </div>`,
        add: async (restId) => {
            const label    = document.getElementById('cf-label')?.value.trim();
            const type     = document.getElementById('cf-type')?.value;
            const applies  = document.getElementById('cf-applies')?.value;
            const required = document.getElementById('cf-required')?.checked ? 1 : 0;
            const optsTxt  = document.getElementById('cf-options')?.value || '';

            if (!label) { toast('Oznaka polja je obvezna.', 'error'); return; }

            const options = type === 'select'
                ? optsTxt.split('\n').map(s => s.trim()).filter(Boolean)
                : [];
            if (type === 'select' && options.length < 1) {
                toast('Vnesite vsaj eno možnost za tip Izbira.', 'error'); return;
            }

            try {
                await API.post('/api/customfields.php', {
                    restaurant_id: restId,
                    label, field_type: type, applies_to: applies,
                    is_required: required, options,
                });
                // Počisti formo
                ['cf-label','cf-options'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
                const cfRequired = document.getElementById('cf-required');
                if (cfRequired) cfRequired.checked = false;
                await AdminCustomFields.load(restId);
                toast('Polje dodano!');
            } catch (e) { toast(e.message, 'error'); }
        },
        remove: async (id) => {
            if (!confirm('Izbrišete polje po meri? Obstoječe vrednosti v rezervacijah se ohranijo.')) return;
            try {
                await API.delete(`/api/customfields.php?id=${id}`);
                document.getElementById(`cf-row-${id}`)?.remove();
                toast('Polje odstranjeno.');
            } catch (e) { toast(e.message, 'error'); }
        },
    };
    window.AdminCustomFields = AdminCustomFields;

    const AdminRestaurants = {
        edit: (id) => { window.location.href = (window.APP_STATE?.base || '') + `/pages/restaurant-edit.php?id=${id}`; },
        copyLink: (token) => {
            const url = bookingUrl(token);
            navigator.clipboard.writeText(url).then(() => toast('Povezava kopirana!'), () => { prompt('Kopiraj:', url); });
        },
        copyEmbed: (token) => {
            const code = embedCode(token);
            navigator.clipboard.writeText(code).then(() => toast('Embed koda kopirana!'), () => { prompt('Kopiraj:', code); });
        },
        addBlackout: async (restId) => {
            if (!restId) { toast('Najprej shranite restavracijo.', 'error'); return; }
            const date   = document.getElementById('blackout-date-input')?.value;
            const reason = document.getElementById('blackout-reason-input')?.value.trim() || '';
            if (!date) { toast('Izberite datum.', 'error'); return; }
            try {
                await API.put(`/api/restaurants.php?id=${restId}&action=add_blackout`, { date, reason });
                const list = document.getElementById('blackout-list');
                if (list) {
                    const item = document.createElement('div');
                    item.className = 'blackout-item';
                    item.dataset.date = date;
                    item.style.cssText = 'display:flex;align-items:center;gap:8px;padding:6px 10px;background:#FEF2F2;border-radius:6px;margin-bottom:6px;font-size:.825rem';
                    item.innerHTML = `<span style="font-weight:600;color:#374151">${h(date)}</span>${reason ? `<span style="color:#6B7280">– ${h(reason)}</span>` : ''}<button type="button" style="margin-left:auto;color:#EF4444;background:none;border:none;cursor:pointer;font-size:1.1rem;line-height:1;padding:0 2px" onclick="AdminRestaurants.removeBlackout(${restId},'${date}',this)">×</button>`;
                    // Zamenjaj "ni datumov" sporočilo če obstaja
                    const empty = list.querySelector('p');
                    if (empty) empty.remove();
                    list.appendChild(item);
                }
                document.getElementById('blackout-date-input').value = '';
                document.getElementById('blackout-reason-input').value = '';
                toast('Datum dodan!');
            } catch(e) { toast(e.message, 'error'); }
        },
        removeBlackout: async (restId, date, btn) => {
            try {
                await API.delete(`/api/restaurants.php?id=${restId}&action=remove_blackout&date=${date}`);
                btn?.closest('.blackout-item')?.remove();
                toast('Datum odstranjen.');
            } catch(e) { toast(e.message, 'error'); }
        },
        deactivate: async (id, name) => {
            if (!confirm(`Deaktivirajte restavracijo "${name}"?`)) return;
            try { await API.delete(`/api/restaurants.php?id=${id}`); toast(`"${name}" deaktivirana.`); loadRestaurants(); }
            catch(e) { toast(e.message, 'error'); }
        },
        delete: async (id, name) => {
            if (!confirm(`Trajno izbrišete restavracijo "${name}" in vse njene rezervacije?\n\nTe akcije ni mogoče razveljaviti.`)) return;
            try { await API.delete(`/api/restaurants.php?id=${id}&force=1`); toast(`"${name}" trajno izbrisana.`); loadRestaurants(); }
            catch(e) { toast(e.message, 'error'); }
        },
    };
    window.AdminRestaurants = AdminRestaurants;

    // ─────────────────────────────────────────────────────────────
    // UPORABNIKI
    // ─────────────────────────────────────────────────────────────
    let users = [];

    async function loadUsers() {
        try { users = await API.get('/api/users.php') || []; renderUsersTable(); }
        catch (e) { toast(e.message, 'error'); }
    }

    function renderUsersTable() {
        const tbody = document.getElementById('users-tbody');
        if (tbody && users.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" class="table-empty">Ni uporabnikov.</td></tr>`;
        } else if (tbody) {
            tbody.innerHTML = users.map(u => `<tr><td>${h(u.full_name)}</td></tr>`).join('');
        }
        renderUsersByRestaurant();
    }

    function renderUsersByRestaurant() {
        const wrap = document.getElementById('users-by-rest');
        if (!wrap) return;

        if (users.length === 0) {
            wrap.innerHTML = `<div style="padding:16px 20px;color:var(--color-muted);font-size:.875rem">Ni zaposlenih. Dodajte prvega.</div>`;
            return;
        }

        // Grupiraj po restavraciji
        const grouped = {};
        users.forEach(u => {
            const key  = u.restaurant_id || '__none__';
            const name = u.restaurant_name || 'Brez restavracije';
            if (!grouped[key]) grouped[key] = { name, users: [] };
            grouped[key].users.push(u);
        });

        wrap.innerHTML = Object.entries(grouped).map(([restId, group]) => `
            <div style="border-bottom:1px solid var(--color-border);last-child:border-bottom:none">
                <div style="padding:10px 20px 8px;font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--color-muted);background:var(--color-bg)">
                    ${h(group.name)}
                </div>
                <table class="admin-table" style="margin:0">
                    <thead>
                        <tr>
                            <th>Ime</th>
                            <th>Email / Uporabniško ime</th>
                            <th>Vloga</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        ${group.users.map(u => {
                            const roleBadge = u.role === 'admin'
                                ? `<span style="background:color-mix(in oklab,var(--color-accent) 12%,transparent);color:var(--color-accent);border-radius:4px;padding:2px 8px;font-size:.72rem;font-weight:700">Admin</span>`
                                : `<span style="background:var(--color-bg);color:var(--color-muted);border-radius:4px;padding:2px 8px;font-size:.72rem;font-weight:600;border:1px solid var(--color-border)">Osebje</span>`;
                            return `
                            <tr>
                                <td><strong>${h(u.full_name)}</strong></td>
                                <td style="color:var(--color-text-2)">${u.email ? h(u.email) : `<span style="color:var(--color-muted);font-size:.8rem">👤 ${h(u.username)}</span>`}</td>
                                <td>${roleBadge}</td>
                                <td><span class="badge ${u.is_active == 1 ? 'badge-active' : 'badge-inactive'}">${u.is_active == 1 ? 'Aktiven' : 'Neaktiven'}</span></td>
                                <td>
                                    <div class="table-actions">
                                        <button class="btn-icon" title="Uredi" onclick="AdminUsers.edit(${u.id})">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </button>
                                        <button class="btn-icon danger" title="Deaktiviraj" onclick="AdminUsers.deactivate(${u.id},'${h(u.full_name)}')">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                                        </button>
                                        <button class="btn-icon danger" title="Trajno izbriši" onclick="AdminUsers.delete(${u.id},'${h(u.full_name)}')">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>`;
                        }).join('')}
                    </tbody>
                </table>
            </div>`).join('');
    }

    function buildRestOptions(selectedId) {
        return restaurants.map(r => `<option value="${r.id}" ${r.id == selectedId ? 'selected' : ''}>${h(r.name)}</option>`).join('');
    }

    function openUserModal(mode, userId = null) {
        const user  = userId ? users.find(u => u.id === userId) : null;
        const title = mode === 'edit' ? 'Uredi uporabnika' : 'Nov uporabnik';
        const initLoginType = user ? (user.email ? 'email' : 'username') : 'email';
        const initRole = user?.role === 'admin' ? 'admin' : 'user';
        // Za sub-admine uporabi linked_restaurant_id če restaurant_id ni nastavljen
        const initRestId = user?.restaurant_id || user?.linked_restaurant_id || null;

        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'admin-modal';

        // Obstoječi uporabnik, ki ga povežemo (za create mode)
        let linkedExistingUser = null;

        overlay.innerHTML = `
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title">${title}</div>
                <button class="modal-close" onclick="document.getElementById('admin-modal').remove()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div id="admin-user-info" style="display:none;padding:10px 12px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;font-size:.825rem;color:#1D4ED8;margin-bottom:12px"></div>
                <div id="admin-user-error" class="admin-error"></div>
                <div class="admin-form">
                    <div class="admin-field">
                        <label>Polno ime *</label>
                        <input id="u-fullname" type="text" value="${h(user?.full_name || '')}">
                    </div>
                    <div class="admin-field">
                        <label>Tip prijave *</label>
                        <div style="display:flex;gap:16px;margin-top:4px">
                            <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer">
                                <input type="radio" name="u-login-type" value="email" ${initLoginType === 'email' ? 'checked' : ''} onchange="AdminUsers.toggleLoginType()"> Email
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer">
                                <input type="radio" name="u-login-type" value="username" ${initLoginType === 'username' ? 'checked' : ''} onchange="AdminUsers.toggleLoginType()"> Uporabniško ime
                            </label>
                        </div>
                    </div>
                    <div class="admin-field-row">
                        <div class="admin-field" id="u-email-field" style="${initLoginType !== 'email' ? 'display:none' : ''}">
                            <label>Email</label>
                            <input id="u-email" type="email" value="${h(user?.email || '')}" autocomplete="off">
                        </div>
                        <div class="admin-field" id="u-username-field" style="${initLoginType !== 'username' ? 'display:none' : ''}">
                            <label>Uporabniško ime</label>
                            <input id="u-username" type="text" value="${h(user?.username || '')}" autocomplete="off">
                        </div>
                        <div class="admin-field" id="u-password-field">
                            <label>${mode === 'edit' ? 'Novo geslo (prazno = brez menjave)' : 'Geslo *'}</label>
                            <input id="u-password" type="password" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="admin-field">
                        <label>Restavracija *</label>
                        <select id="u-restaurant">
                            <option value="">— Izberi —</option>
                            ${buildRestOptions(initRestId)}
                        </select>
                    </div>
                    <div class="admin-field">
                        <label>Vloga *</label>
                        <div style="display:flex;gap:16px;margin-top:4px">
                            <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer">
                                <input type="radio" name="u-role" value="user" ${initRole === 'user' ? 'checked' : ''}> Uporabnik
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer">
                                <input type="radio" name="u-role" value="admin" ${initRole === 'admin' ? 'checked' : ''}> Admin
                            </label>
                        </div>
                        <div style="margin-top:6px;font-size:.775rem;color:#6B7280">
                            <strong>Uporabnik</strong> – vidi in ureja rezervacije za svojo restavracijo.<br>
                            <strong>Admin</strong> – upravlja restavracijo (urnik, nastavitve, zaposleni).
                        </div>
                    </div>
                    ${user ? `<div class="admin-field">
                        <label>Status</label>
                        <select id="u-active">
                            <option value="1" ${user.is_active == 1 ? 'selected' : ''}>Aktiven</option>
                            <option value="0" ${user.is_active == 0 ? 'selected' : ''}>Neaktiven</option>
                        </select>
                    </div>` : ''}
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" onclick="document.getElementById('admin-modal').remove()">Prekliči</button>
                <button class="btn btn-primary" id="admin-user-save">Shrani</button>
            </div>
        </div>`;

        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
        document.body.appendChild(overlay);

        // ── Preverjanje obstoječega emaila/username (samo create) ──
        if (mode === 'create') {
            async function checkExistingUser() {
                const loginType = document.querySelector('input[name="u-login-type"]:checked')?.value || 'email';
                const val = loginType === 'email'
                    ? document.getElementById('u-email')?.value.trim()
                    : document.getElementById('u-username')?.value.trim();
                if (!val) { linkedExistingUser = null; _resetLinkedState(); return; }

                try {
                    const param = loginType === 'email' ? `email=${encodeURIComponent(val)}` : `username=${encodeURIComponent(val)}`;
                    const res = await API.get(`/api/users.php?action=check_email&${param}`);
                    if (res?.found) {
                        linkedExistingUser = res.user;
                        _setLinkedState(res.user);
                    } else {
                        linkedExistingUser = null;
                        _resetLinkedState();
                    }
                } catch (e) { /* tiho */ }
            }

            function _setLinkedState(found) {
                const infoEl = document.getElementById('admin-user-info');
                if (infoEl) {
                    infoEl.style.display = '';
                    const roleNote = found.role === 'admin'
                        ? ' Ker je obstoječi admin, bo dodan z admin dostopom.'
                        : '';
                    infoEl.innerHTML = `<strong>Obstoječi račun:</strong> ${h(found.full_name)} – bo dodan k izbrani restavraciji.${roleNote}`;
                }
                const fn = document.getElementById('u-fullname');
                if (fn) { fn.value = found.full_name; fn.disabled = true; }
                const pw = document.getElementById('u-password');
                const pwField = document.getElementById('u-password-field');
                if (pw) pw.disabled = true;
                if (pwField) pwField.style.opacity = '.4';
                // Za obstoječe admine zakleni vlogo na admin (ne more biti user)
                if (found.role === 'admin') {
                    const roleAdmin = document.querySelector('input[name="u-role"][value="admin"]');
                    const roleUser  = document.querySelector('input[name="u-role"][value="user"]');
                    if (roleAdmin) { roleAdmin.checked = true; roleAdmin.disabled = true; }
                    if (roleUser)  { roleUser.disabled  = true; }
                } else {
                    // Obstoječi user – vloga se can still be set
                    const roleR = document.querySelector('input[name="u-role"][value="user"]');
                    if (roleR) roleR.checked = true;
                }
            }

            function _resetLinkedState() {
                const infoEl = document.getElementById('admin-user-info');
                if (infoEl) infoEl.style.display = 'none';
                const fn = document.getElementById('u-fullname');
                if (fn) { fn.disabled = false; fn.value = ''; }
                const pw = document.getElementById('u-password');
                const pwField = document.getElementById('u-password-field');
                if (pw) pw.disabled = false;
                if (pwField) pwField.style.opacity = '';
                // Odkleni role radios
                document.querySelectorAll('input[name="u-role"]').forEach(r => r.disabled = false);
                const roleUser = document.querySelector('input[name="u-role"][value="user"]');
                if (roleUser) roleUser.checked = true;
            }

            document.getElementById('u-email')?.addEventListener('blur', checkExistingUser);
            document.getElementById('u-username')?.addEventListener('blur', checkExistingUser);
            // Ko se zamenja tip prijave, počisti stanje
            document.querySelectorAll('input[name="u-login-type"]').forEach(r => r.addEventListener('change', () => {
                linkedExistingUser = null; _resetLinkedState();
            }));
        }

        document.getElementById('admin-user-save').addEventListener('click', async () => {
            const loginType = document.querySelector('input[name="u-login-type"]:checked')?.value || 'email';
            const email     = loginType === 'email'    ? (document.getElementById('u-email')?.value.trim()    || '') : '';
            const username  = loginType === 'username' ? (document.getElementById('u-username')?.value.trim() || '') : '';
            const restId    = document.getElementById('u-restaurant')?.value || null;
            const active    = user ? parseInt(document.getElementById('u-active').value) : undefined;
            const roleVal   = document.querySelector('input[name="u-role"]:checked')?.value || 'user';
            const errEl     = document.getElementById('admin-user-error');

            // Preveri restavracijo takoj
            if (!restId) { errEl.textContent = 'Izberite restavracijo.'; errEl.style.display = 'block'; return; }

            // Async race fix: če blur ni utegnil preveriti, preveri zdaj
            if (mode === 'create' && !linkedExistingUser) {
                const val = loginType === 'email' ? email : username;
                if (val) {
                    try {
                        const param = loginType === 'email' ? `email=${encodeURIComponent(val)}` : `username=${encodeURIComponent(val)}`;
                        const res = await API.get(`/api/users.php?action=check_email&${param}`);
                        if (res?.found) { linkedExistingUser = res.user; _setLinkedState(res.user); }
                    } catch (e) { /* tiho */ }
                }
            }

            errEl.style.display = 'none';

            let body;
            if (mode === 'create' && linkedExistingUser) {
                // Poveži obstoječega – vloga se pošlje, API jo upošteva
                body = { link_existing_id: linkedExistingUser.id, restaurant_id: restId, role: roleVal };
            } else {
                const fullname = document.getElementById('u-fullname').value.trim();
                const password = document.getElementById('u-password').value;
                if (!fullname) { errEl.textContent = 'Ime je obvezno.'; errEl.style.display = 'block'; return; }
                if (loginType === 'email' && !email) { errEl.textContent = 'Email je obvezen.'; errEl.style.display = 'block'; return; }
                if (loginType === 'username' && !username) { errEl.textContent = 'Uporabniško ime je obvezno.'; errEl.style.display = 'block'; return; }
                if (mode === 'create' && !password) { errEl.textContent = 'Geslo je obvezno.'; errEl.style.display = 'block'; return; }
                body = { full_name: fullname, restaurant_id: restId, role: roleVal };
                if (loginType === 'email')    { body.email = email; body.username = null; }
                if (loginType === 'username') { body.username = username; body.email = null; }
                if (password) body.password = password;
            }
            if (active !== undefined) body.is_active = active;

            try {
                if (mode === 'edit' && user) { await API.put(`/api/users.php?id=${user.id}`, body); toast('Uporabnik posodobljen!'); }
                else { await API.post('/api/users.php', body); toast('Uporabnik dodan!'); }
                overlay.remove();
                loadUsers();
            } catch (e) { errEl.textContent = e.message; errEl.style.display = 'block'; }
        });
    }

    const AdminUsers = {
        edit:       (id) => openUserModal('edit', id),
        deactivate: async (id, name) => {
            if (!confirm(`Deaktivirajte uporabnika "${name}"?`)) return;
            try { await API.delete(`/api/users.php?id=${id}`); toast(`"${name}" deaktiviran.`); loadUsers(); }
            catch(e) { toast(e.message, 'error'); }
        },
        delete: async (id, name) => {
            if (!confirm(`Trajno izbrišete uporabnika "${name}"?\n\nTe akcije ni mogoče razveljaviti.`)) return;
            try { await API.delete(`/api/users.php?id=${id}&force=1`); toast(`"${name}" trajno izbrisan.`); loadUsers(); }
            catch(e) { toast(e.message, 'error'); }
        },
        toggleLoginType: () => {
            const type = document.querySelector('input[name="u-login-type"]:checked')?.value;
            const ef = document.getElementById('u-email-field');
            const uf = document.getElementById('u-username-field');
            if (ef) ef.style.display = type === 'email'    ? '' : 'none';
            if (uf) uf.style.display = type === 'username' ? '' : 'none';
        },
    };
    window.AdminUsers = AdminUsers;

    // ── Inicializacija ─────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
        initTabs();
        loadRestaurants();
        loadUsers();
        document.getElementById('btn-add-restaurant')?.addEventListener('click', () => openRestModal('create'));
        document.getElementById('btn-add-user')?.addEventListener('click', () => openUserModal('create'));
    });
})();
