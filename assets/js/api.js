/**
 * Centraliziran fetch ovoj za vse API klice.
 * Samodejno doda BASE_PATH pred vsako pot.
 * Samodejno preusmeri na login ob 401.
 */
const API = (() => {
    const base = (window.APP_STATE && APP_STATE.base) ? APP_STATE.base : '';

    async function request(url, options = {}) {
        const fullUrl = base + url;
        const defaults = {
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
        };
        const cfg = { ...defaults, ...options };
        if (cfg.body && typeof cfg.body !== 'string') {
            cfg.body = JSON.stringify(cfg.body);
        }

        let res;
        try {
            res = await fetch(fullUrl, cfg);
        } catch (err) {
            throw new Error('Napaka pri povezavi s strežnikom.');
        }

        if (res.status === 401) {
            window.location.href = base + '/login.php';
            return;
        }

        const json = await res.json().catch(() => ({ success: false, error: 'Neveljaven odgovor strežnika.' }));

        if (!json.success) {
            const err = new Error(json.error || 'Neznana napaka.');
            err.data = json.data || null;
            throw err;
        }

        return json.data;
    }

    return {
        get:    (url)       => request(url, { method: 'GET' }),
        post:   (url, body) => request(url, { method: 'POST',   body }),
        put:    (url, body) => request(url, { method: 'PUT',    body }),
        delete: (url)       => request(url, { method: 'DELETE' }),
    };
})();
