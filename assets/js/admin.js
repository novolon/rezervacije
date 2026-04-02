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
        const container = document.getElementById('toast-container');
        const t = document.createElement('div');
        t.className = `toast toast-${type}`;
        t.textContent = msg;
        container.appendChild(t);
        setTimeout(() => {
            t.style.opacity = '0';
            t.style.transition = 'opacity .3s';
            setTimeout(() => t.remove(), 300);
        }, 3200);
    }

    // ── Escape HTML ───────────────────────────────────────────────
    function h(str) {
        const d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    // ── Pomožni funkciji za čas ───────────────────────────────────
    function minsToTime(m) {
        return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
    }
    function timeToMins(t) {
        const [hh, mm] = (t || '').split(':').map(Number);
        return (hh || 0) * 60 + (mm || 0);
    }

    // ─────────────────────────────────────────────────────────────
    // RESTAVRACIJE
    // ─────────────────────────────────────────────────────────────
    let restaurants = [];

    async function loadRestaurants() {
        try {
            restaurants = await API.get('/api/restaurants.php') || [];
            renderRestaurantsTable();
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    function renderRestaurantsTable() {
        const tbody = document.getElementById('rest-tbody');
        if (!tbody) return;

        if (restaurants.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="table-empty">Ni restavracij. Dodajte prvo.</td></tr>`;
            return;
        }

        tbody.innerHTML = restaurants.map(r => `
            <tr>
                <td><strong>${h(r.name)}</strong></td>
                <td>${r.reservation_duration} min</td>
                <td><span class="color-swatch" style="background:${h(r.color)}"></span> ${h(r.color)}</td>
                <td><span class="badge ${r.is_active == 1 ? 'badge-active' : 'badge-inactive'}">${r.is_active == 1 ? 'Aktivna' : 'Neaktivna'}</span></td>
                <td>
                    <div class="table-actions">
                        <button class="btn-icon" title="Uredi" onclick="AdminRestaurants.edit(${r.id})">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button class="btn-icon danger" title="Deaktiviraj" onclick="AdminRestaurants.deactivate(${r.id}, '${h(r.name)}')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                        </button>
                        <button class="btn-icon danger" title="Trajno izbriši" onclick="AdminRestaurants.delete(${r.id}, '${h(r.name)}')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
    }

    // Modal za restavracijo
    function openRestModal(mode, restId = null) {
        const rest = restId ? restaurants.find(r => r.id === restId) : null;
        const title = mode === 'edit' ? 'Uredi restavracijo' : 'Nova restavracija';

        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'admin-modal';

        overlay.innerHTML = `
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title">${title}</div>
                <button class="modal-close" onclick="document.getElementById('admin-modal').remove()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div id="admin-rest-error" class="admin-error"></div>
                <div class="admin-form">
                    <div class="admin-field">
                        <label>Ime restavracije *</label>
                        <input id="r-name" type="text" value="${h(rest?.name || '')}" required>
                    </div>
                    <div class="admin-field-row">
                        <div class="admin-field">
                            <label>Trajanje rezervacije (min) *</label>
                            <input id="r-duration" type="number" min="15" step="15" value="${rest?.reservation_duration || 60}">
                        </div>
                        <div class="admin-field">
                            <label>Barva</label>
                            <input id="r-color" type="color" value="${rest?.color || '#F59E0B'}" style="height:42px;padding:4px">
                        </div>
                    </div>
                    <div class="admin-field-row">
                        <div class="admin-field">
                            <label>Prva rezervacija</label>
                            <input id="r-sched-start" type="time" value="${minsToTime(rest?.schedule_start ?? 480)}">
                        </div>
                        <div class="admin-field">
                            <label>Zadnja rezervacija</label>
                            <input id="r-sched-end" type="time" value="${minsToTime(rest?.schedule_end ?? 1380)}">
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;margin-top:4px;">
                        <input type="checkbox" id="r-allow-custom" style="width:18px;height:18px;accent-color:#F59E0B;cursor:pointer;flex-shrink:0" ${rest?.allow_custom_duration ? 'checked' : ''}>
                        <label for="r-allow-custom" style="font-size:.875rem;font-weight:500;cursor:pointer;color:#374151">Dovoli spremembo trajanja pri rezervaciji</label>
                    </div>
                    ${rest ? `<div class="admin-field">
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

        document.getElementById('admin-rest-save').addEventListener('click', async () => {
            const name        = document.getElementById('r-name').value.trim();
            const duration    = parseInt(document.getElementById('r-duration').value);
            const color       = document.getElementById('r-color').value;
            const allowCustom = document.getElementById('r-allow-custom').checked ? 1 : 0;
            const schedStart  = timeToMins(document.getElementById('r-sched-start').value);
            const schedEnd    = timeToMins(document.getElementById('r-sched-end').value);
            const active      = rest ? parseInt(document.getElementById('r-active').value) : undefined;
            const errEl       = document.getElementById('admin-rest-error');

            if (!name) { errEl.textContent = 'Ime je obvezno.'; errEl.style.display = 'block'; return; }
            if (schedEnd <= schedStart) { errEl.textContent = 'Zadnji čas mora biti večji od prvega.'; errEl.style.display = 'block'; return; }
            errEl.style.display = 'none';

            const payload = { name, reservation_duration: duration, allow_custom_duration: allowCustom, color, schedule_start: schedStart, schedule_end: schedEnd };
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
            }
        });
    }

    const AdminRestaurants = {
        edit:       (id) => openRestModal('edit', id),
        deactivate: async (id, name) => {
            if (!confirm(`Deaktivirajte restavracijo "${name}"?`)) return;
            try {
                await API.delete(`/api/restaurants.php?id=${id}`);
                toast(`Restavracija "${name}" deaktivirana.`);
                loadRestaurants();
            } catch(e) { toast(e.message, 'error'); }
        },
        delete: async (id, name) => {
            if (!confirm(`Trajno izbrišete restavracijo "${name}" in vse njene rezervacije?\n\nTe akcije ni mogoče razveljaviti.`)) return;
            try {
                await API.delete(`/api/restaurants.php?id=${id}&force=1`);
                toast(`Restavracija "${name}" trajno izbrisana.`);
                loadRestaurants();
            } catch(e) { toast(e.message, 'error'); }
        },
    };
    window.AdminRestaurants = AdminRestaurants;

    // ─────────────────────────────────────────────────────────────
    // UPORABNIKI
    // ─────────────────────────────────────────────────────────────
    let users = [];

    async function loadUsers() {
        try {
            users = await API.get('/api/users.php') || [];
            renderUsersTable();
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    function renderUsersTable() {
        const tbody = document.getElementById('users-tbody');
        if (!tbody) return;

        if (users.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="table-empty">Ni uporabnikov.</td></tr>`;
            return;
        }

        tbody.innerHTML = users.map(u => `
            <tr>
                <td><strong>${h(u.full_name)}</strong></td>
                <td>${u.email ? h(u.email) : `<span style="color:var(--color-muted);font-size:.8rem">👤 ${h(u.username)}</span>`}</td>
                <td>${u.restaurant_name ? h(u.restaurant_name) : '<span style="color:var(--color-muted)">—</span>'}</td>
                <td><span class="badge ${u.is_active == 1 ? 'badge-active' : 'badge-inactive'}">${u.is_active == 1 ? 'Aktiven' : 'Neaktiven'}</span></td>
                <td>
                    <div class="table-actions">
                        <button class="btn-icon" title="Uredi" onclick="AdminUsers.edit(${u.id})">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button class="btn-icon danger" title="Deaktiviraj" onclick="AdminUsers.deactivate(${u.id}, '${h(u.full_name)}')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                        </button>
                        <button class="btn-icon danger" title="Trajno izbriši" onclick="AdminUsers.delete(${u.id}, '${h(u.full_name)}')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
    }

    function buildRestOptions(selectedId) {
        return restaurants.map(r =>
            `<option value="${r.id}" ${r.id == selectedId ? 'selected' : ''}>${h(r.name)}</option>`
        ).join('');
    }

    function openUserModal(mode, userId = null) {
        const user  = userId ? users.find(u => u.id === userId) : null;
        const title = mode === 'edit' ? 'Uredi uporabnika' : 'Nov uporabnik';

        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'admin-modal';

        // Določi začetni tip prijave (email ali username)
        const initLoginType = user ? (user.email ? 'email' : 'username') : 'email';

        overlay.innerHTML = `
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title">${title}</div>
                <button class="modal-close" onclick="document.getElementById('admin-modal').remove()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div id="admin-user-error" class="admin-error"></div>
                <div class="admin-form">
                    <div class="admin-field">
                        <label>Polno ime *</label>
                        <input id="u-fullname" type="text" value="${h(user?.full_name || '')}">
                    </div>

                    <!-- Tip prijave: email ali username -->
                    <div class="admin-field">
                        <label>Tip prijave *</label>
                        <div style="display:flex;gap:16px;margin-top:4px">
                            <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer">
                                <input type="radio" name="u-login-type" value="email" ${initLoginType === 'email' ? 'checked' : ''} onchange="AdminUsers.toggleLoginType()">
                                Email
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer">
                                <input type="radio" name="u-login-type" value="username" ${initLoginType === 'username' ? 'checked' : ''} onchange="AdminUsers.toggleLoginType()">
                                Uporabniško ime
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
                            <input id="u-username" type="text" value="${h(user?.username || '')}" autocomplete="off" placeholder="npr. janez.novak">
                        </div>
                        <div class="admin-field">
                            <label>${mode === 'edit' ? 'Novo geslo (prazno = brez menjave)' : 'Geslo *'}</label>
                            <input id="u-password" type="password" autocomplete="new-password">
                        </div>
                    </div>

                    <div class="admin-field">
                        <label>Restavracija *</label>
                        <select id="u-restaurant">
                            <option value="">— Izberi —</option>
                            ${buildRestOptions(user?.restaurant_id)}
                        </select>
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

        document.getElementById('admin-user-save').addEventListener('click', async () => {
            const fullname   = document.getElementById('u-fullname').value.trim();
            const loginType  = document.querySelector('input[name="u-login-type"]:checked')?.value || 'email';
            const email      = loginType === 'email'    ? (document.getElementById('u-email')?.value.trim()    || '') : '';
            const username   = loginType === 'username' ? (document.getElementById('u-username')?.value.trim() || '') : '';
            const password   = document.getElementById('u-password').value;
            const restId     = document.getElementById('u-restaurant')?.value || null;
            const active     = user ? parseInt(document.getElementById('u-active').value) : undefined;
            const errEl      = document.getElementById('admin-user-error');

            if (!fullname) {
                errEl.textContent = 'Ime je obvezno.'; errEl.style.display = 'block'; return;
            }
            if (loginType === 'email' && !email) {
                errEl.textContent = 'Email je obvezen.'; errEl.style.display = 'block'; return;
            }
            if (loginType === 'username' && !username) {
                errEl.textContent = 'Uporabniško ime je obvezno.'; errEl.style.display = 'block'; return;
            }
            if (mode === 'create' && !password) {
                errEl.textContent = 'Geslo je obvezno.';
                errEl.style.display = 'block'; return;
            }
            if (!restId) {
                errEl.textContent = 'Izberite restavracijo za tega uporabnika.';
                errEl.style.display = 'block'; return;
            }
            errEl.style.display = 'none';

            const body = { full_name: fullname, role: 'user', restaurant_id: restId };
            if (loginType === 'email')    { body.email = email;       body.username = null; }
            if (loginType === 'username') { body.username = username;  body.email = null; }
            if (password) body.password = password;
            if (active !== undefined) body.is_active = active;

            try {
                if (mode === 'edit' && user) {
                    await API.put(`/api/users.php?id=${user.id}`, body);
                    toast('Uporabnik posodobljen!');
                } else {
                    await API.post('/api/users.php', body);
                    toast('Uporabnik dodan!');
                }
                overlay.remove();
                loadUsers();
            } catch (e) {
                errEl.textContent = e.message;
                errEl.style.display = 'block';
            }
        });
    }

    const AdminUsers = {
        edit:       (id) => openUserModal('edit', id),
        deactivate: async (id, name) => {
            if (!confirm(`Deaktivirajte uporabnika "${name}"?`)) return;
            try {
                await API.delete(`/api/users.php?id=${id}`);
                toast(`Uporabnik "${name}" deaktiviran.`);
                loadUsers();
            } catch(e) { toast(e.message, 'error'); }
        },
        delete: async (id, name) => {
            if (!confirm(`Trajno izbrišete uporabnika "${name}"?\n\nTe akcije ni mogoče razveljaviti.`)) return;
            try {
                await API.delete(`/api/users.php?id=${id}&force=1`);
                toast(`Uporabnik "${name}" trajno izbrisan.`);
                loadUsers();
            } catch(e) { toast(e.message, 'error'); }
        },
        toggleLoginType: () => {
            const type = document.querySelector('input[name="u-login-type"]:checked')?.value;
            const emailField    = document.getElementById('u-email-field');
            const usernameField = document.getElementById('u-username-field');
            if (emailField)    emailField.style.display    = type === 'email'    ? '' : 'none';
            if (usernameField) usernameField.style.display = type === 'username' ? '' : 'none';
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
