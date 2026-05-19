/**
 * Modul 7: Real-time sync (SSE klient).
 *
 * Naročnik: `Realtime.connect(restaurantId)` (Advanced+ plan).
 * EventSource pošlje event-e iz `api/events.php`. Ob vsakem dogodku
 * sproži debounced refresh prek `App.afterReservationChange()` ali
 * `App.loadSchedule()` (kar koli je na voljo).
 *
 * Backend lifecycle je 30s → klient ob `event: bye` reconnecta z `last_id`.
 * Native EventSource sicer reconnecta sam, ampak želimo eksplicitno predati
 * cursor, da ne preskočimo event-ov med transitom.
 */
const Realtime = (() => {
    const base = (window.APP_STATE && APP_STATE.base) ? APP_STATE.base : '';

    let es           = null;
    let restaurantId = null;
    let lastId       = 0;
    let refreshTimer = null;
    let backoffMs    = 2000;       // start, raste do 30s ob errorjih
    const MAX_BACKOFF = 30000;
    const REFRESH_DEBOUNCE = 250;  // ms — batchaj rapid event-e

    function log(...args) {
        if (window.APP_STATE && APP_STATE.debug) console.log('[Realtime]', ...args);
    }

    function scheduleRefresh() {
        if (refreshTimer) return;
        refreshTimer = setTimeout(() => {
            refreshTimer = null;
            try {
                if (window.App && typeof App.afterReservationChange === 'function') {
                    App.afterReservationChange();
                } else if (window.App && typeof App.loadSchedule === 'function') {
                    App.loadSchedule();
                }
            } catch (e) {
                log('refresh error', e);
            }
        }, REFRESH_DEBOUNCE);
    }

    function persistCursor() {
        try { sessionStorage.setItem('rt_last_' + restaurantId, String(lastId)); } catch (_) {}
    }

    function loadCursor() {
        try {
            const v = sessionStorage.getItem('rt_last_' + restaurantId);
            lastId = v ? parseInt(v, 10) || 0 : 0;
        } catch (_) { lastId = 0; }
    }

    function close() {
        if (es) {
            try { es.close(); } catch (_) {}
            es = null;
        }
    }

    function open() {
        if (!restaurantId) return;
        close();

        const url = base + `/api/events.php?restaurant_id=${restaurantId}&last_id=${lastId}`;
        log('connecting', url);

        try {
            es = new EventSource(url, { withCredentials: true });
        } catch (e) {
            log('EventSource ctor failed', e);
            scheduleReconnect();
            return;
        }

        es.addEventListener('hello', (e) => {
            try {
                const data = JSON.parse(e.data);
                if (data && typeof data.cursor === 'number') {
                    // Server pove katera je trenutna max id; sinhroniziraj cursor.
                    if (lastId === 0) { lastId = data.cursor; persistCursor(); }
                }
            } catch (_) {}
            backoffMs = 2000; // uspešen connect → reset backoff
            log('hello, cursor=' + lastId);
        });

        es.addEventListener('bye', (e) => {
            try {
                const data = JSON.parse(e.data);
                if (data && typeof data.cursor === 'number') lastId = data.cursor;
            } catch (_) {}
            persistCursor();
            log('bye, reconnect with cursor=' + lastId);
            close();
            // Takoj nazaj — bye je normalen del lifecycle-a, ne error.
            setTimeout(open, 100);
        });

        // Vsak data event z `event: X` namesto generic message
        ['reservation_added', 'reservation_updated', 'reservation_deleted',
         'reservation_arrived', 'reservation_noshow', 'reservation_status_changed']
            .forEach(type => {
                es.addEventListener(type, (e) => {
                    if (e.lastEventId) {
                        const id = parseInt(e.lastEventId, 10);
                        if (id > lastId) { lastId = id; persistCursor(); }
                    }
                    log(type, e.data);
                    scheduleRefresh();
                });
            });

        es.onerror = (e) => {
            log('error', e, 'readyState=' + (es && es.readyState));
            // EventSource sam reconnecta ob 0/CONNECTING, mi pa eksponentni backoff
            // intervenira samo če ne uspe priti nazaj na OPEN.
            if (es && es.readyState === EventSource.CLOSED) {
                close();
                scheduleReconnect();
            }
        };
    }

    let reconnectTimer = null;
    function scheduleReconnect() {
        if (reconnectTimer) return;
        const delay = backoffMs;
        backoffMs = Math.min(backoffMs * 2, MAX_BACKOFF);
        log('reconnect in ' + delay + 'ms');
        reconnectTimer = setTimeout(() => {
            reconnectTimer = null;
            open();
        }, delay);
    }

    function connect(restId) {
        const id = parseInt(restId, 10);
        if (!id || id === restaurantId) return;
        disconnect();
        restaurantId = id;
        loadCursor();
        open();
    }

    function disconnect() {
        close();
        if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
        if (refreshTimer)   { clearTimeout(refreshTimer);   refreshTimer = null; }
        restaurantId = null;
    }

    function isAlive() {
        return !!(es && es.readyState === EventSource.OPEN);
    }

    // Tab vidnost: prekinemo SSE ko je tab skrit (Synology workers!), reconnect ob povratku
    document.addEventListener('visibilitychange', () => {
        if (!restaurantId) return;
        if (document.hidden) {
            log('tab hidden → close');
            close();
        } else {
            log('tab visible → reopen');
            open();
            scheduleRefresh(); // takoj sync, lahko smo zamudili event-e
        }
    });

    return { connect, disconnect, isAlive };
})();

window.Realtime = Realtime;
