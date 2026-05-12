/**
 * Glavna aplikacijska logika – stanje, inicializacija, koordinacija.
 */
(function () {
  "use strict";

  const POLL_INTERVAL = 30000; // 30 sekund

  // ── Stanje aplikacije ────────────────────────────────────────
  const state = {
    currentDate: new Date(APP_STATE.today + "T00:00:00"), // 'T00:00:00' = lokalni čas, ne UTC
    calendarYear: new Date().getFullYear(),
    calendarMonth: new Date().getMonth(), // 0-based
    restaurantId:
      APP_STATE.restaurantId ||
      (APP_STATE.restaurants[0] ? APP_STATE.restaurants[0].id : null),
    lastDayUpdated: null, // zadnji updated_at za trenutni dan
    lastMonthUpdated: null, // zadnji updated_at za trenutni mesec
    pollTimer: null,
    reservations: [],
  };

  // ── Pomožne funkcije ─────────────────────────────────────────
  function dateStr(d) {
    // Uporabi lokalne metode (ne toISOString, ki vrne UTC in povzroči -1 dan v UTC+2)
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
  }

  function isToday(d) {
    return dateStr(d) === APP_STATE.today;
  }

  // ── Toast obvestila ──────────────────────────────────────────
  function showToast(msg, type = "success") {
    const container = document.getElementById("toast-container");
    const t = document.createElement("div");
    t.className = `toast toast-${type}`;
    t.textContent = msg;
    container.appendChild(t);
    setTimeout(() => {
      t.style.opacity = "0";
      t.style.transition = "opacity .3s";
      setTimeout(() => t.remove(), 300);
    }, 3200);
  }

  // ── Gumb "Vrni se na danes" ───────────────────────────────────
  function updateTodayBtn() {
    const btn = document.getElementById("btn-today");
    btn.style.display = isToday(state.currentDate) ? "none" : "inline-flex";
  }

  function goToToday() {
    state.currentDate = new Date(APP_STATE.today + "T00:00:00");
    state.calendarYear = new Date().getFullYear();
    state.calendarMonth = new Date().getMonth();
    Calendar.render(state.calendarYear, state.calendarMonth);
    Calendar.loadMonth(state.calendarYear, state.calendarMonth);
    Calendar.setSelected(APP_STATE.today);
    loadSchedule();
    updateTodayBtn();
  }

  // ── Pokaži/skrij gumb za dodajanje glede na dan ──────────────
  function updateAddBtn() {
    const btn = document.getElementById("btn-add-reservation");
    if (!btn) return;
    const isPast = dateStr(state.currentDate) < APP_STATE.today;
    btn.style.display = isPast ? "none" : "";
  }

  // ── Izračunaj overlay info za blokirane/zaprte intervale ──────
  function getDayOverlays() {
    const ds = dateStr(state.currentDate);
    const dayIdx = (state.currentDate.getDay() + 6) % 7; // 0=Pon

    const rests = APP_STATE.restaurants.filter(
      (r) => r.id === state.restaurantId,
    );

    let isClosed = false;
    let fullBlackout = false;
    const partialBlackouts = [];

    rests.forEach((r) => {
      const daySchedule = (r.day_schedules || []).find(
        (d) => d.day_of_week === dayIdx,
      );
      if (daySchedule && !daySchedule.is_open) isClosed = true;

      const blk = (r.blackout_dates || []).find((b) => b.date === ds);
      if (blk) {
        if (blk.block_start === null) {
          fullBlackout = true;
        } else {
          partialBlackouts.push({ start: blk.block_start, end: blk.block_end });
        }
      }
    });

    return { isClosed, fullBlackout, partialBlackouts };
  }

  // ── Naloži razpored za currentDate ───────────────────────────
  async function loadSchedule() {
    const ds = dateStr(state.currentDate);
    const url =
      `/api/reservations.php?date=${ds}` +
      (state.restaurantId ? `&restaurant_id=${state.restaurantId}` : "");
    let reservations = [];
    try {
      const data = (await API.get(url)) || {};
      reservations = data.reservations || [];
      const overlays = data.overlay
        ? {
            isClosed: data.overlay.is_closed,
            fullBlackout: data.overlay.full_blackout,
            fullBlackoutReason: data.overlay.full_blackout_reason || null,
            partialBlackouts: (data.overlay.partial_blackouts || []).map(
              (p) => ({ start: p.start, end: p.end, reason: p.reason || null }),
            ),
          }
        : getDayOverlays();
      state.lastDayUpdated = reservations.reduce(
        (max, r) => (r.updated_at > max ? r.updated_at : max),
        "",
      );
      const duration = getActiveDuration();
      const bounds = getActiveScheduleBounds();
      Schedule.render(
        state.currentDate,
        reservations,
        duration,
        APP_STATE.restaurants,
        bounds,
        overlays,
      );
      state.reservations = reservations;
    } catch (e) {
      showToast(e.message, "error");
    }
    UpNext.render(reservations, state.currentDate);
    updateTodayBtn();
    updateAddBtn();
  }

  // ── Polling: tiho preveri spremembe ──────────────────────────
  async function pollForChanges() {
    const ds = dateStr(state.currentDate);
    const url =
      `/api/reservations.php?date=${ds}&poll=1` +
      (state.restaurantId ? `&restaurant_id=${state.restaurantId}` : "");
    try {
      const data = await API.get(url);
      if (!data) return;
      const dayChanged = data.day_updated !== state.lastDayUpdated;
      const monthChanged = data.month_updated !== state.lastMonthUpdated;
      if (dayChanged) await loadSchedule();
      if (monthChanged) {
        state.lastMonthUpdated = data.month_updated;
        Calendar.loadMonth(state.calendarYear, state.calendarMonth);
      }
    } catch (e) {
      // Tiha napaka – ne moti uporabnika
    }
  }

  async function initMonthBaseline() {
    const ds = dateStr(state.currentDate);
    const url =
      `/api/reservations.php?date=${ds}&poll=1` +
      (state.restaurantId ? `&restaurant_id=${state.restaurantId}` : "");
    try {
      const data = await API.get(url);
      if (data) state.lastMonthUpdated = data.month_updated;
    } catch (e) {}
  }

  function startPolling() {
    stopPolling();
    state.pollTimer = setInterval(() => {
      if (!document.hidden) pollForChanges();
    }, POLL_INTERVAL);
  }

  function stopPolling() {
    if (state.pollTimer) {
      clearInterval(state.pollTimer);
      state.pollTimer = null;
    }
  }

  // ── Dolžina rezervacije aktivne restavracije ─────────────────
  function getActiveDuration() {
    if (state.restaurantId) {
      const r = APP_STATE.restaurants.find((x) => x.id === state.restaurantId);
      return r ? parseInt(r.reservation_duration) : 60;
    }
    return 60;
  }

  // ── Začetek/konec razporeda za določen dan ────────────────────
  function getDayBounds(r, dayIdx) {
    if (r.day_schedules && r.day_schedules.length) {
      const ds = r.day_schedules.find((d) => d.day_of_week === dayIdx);
      if (ds)
        return ds.is_open ? { start: ds.start_time, end: ds.end_time } : null;
    }
    return { start: r.schedule_start, end: r.schedule_end };
  }

  function getActiveScheduleBounds() {
    const dayIdx = (state.currentDate.getDay() + 6) % 7;
    const r =
      APP_STATE.restaurants.find((x) => x.id === state.restaurantId) ||
      APP_STATE.restaurants[0];
    if (!r) return { start: 480, end: 1380 };
    // Najprej day_periods (multi-period), fallback na day_schedules
    const periods =
      (r.day_periods || []).length > 0
        ? (r.day_periods || []).filter((d) => d.day_of_week === dayIdx)
        : (r.day_schedules || []).filter(
            (d) => d.day_of_week === dayIdx && d.is_open,
          );
    if (periods.length > 0) {
      return {
        start: Math.min(...periods.map((p) => p.start_time)),
        end: Math.max(...periods.map((p) => p.end_time)),
      };
    }
    return { start: 480, end: 1380 };
  }

  // ── Klik na dan v koledarju ──────────────────────────────────
  function onDayClick(date) {
    state.currentDate = date;
    Calendar.setSelected(dateStr(date));
    loadSchedule();
    updateTodayBtn();
    updateAddBtn();
  }

  // ── Sprememba restavracije (admin dropdown) ───────────────────
  function onRestaurantChange(restId) {
    state.restaurantId = restId
      ? parseInt(restId)
      : APP_STATE.restaurants[0]?.id || null;
    Calendar.loadMonth(state.calendarYear, state.calendarMonth);
    loadSchedule();
  }

  // ── Navigacija po mesecih ─────────────────────────────────────
  function prevMonth() {
    state.calendarMonth--;
    if (state.calendarMonth < 0) {
      state.calendarMonth = 11;
      state.calendarYear--;
    }
    Calendar.render(state.calendarYear, state.calendarMonth);
    Calendar.loadMonth(state.calendarYear, state.calendarMonth);
  }

  function nextMonth() {
    state.calendarMonth++;
    if (state.calendarMonth > 11) {
      state.calendarMonth = 0;
      state.calendarYear++;
    }
    Calendar.render(state.calendarYear, state.calendarMonth);
    Calendar.loadMonth(state.calendarYear, state.calendarMonth);
  }

  // ── Po uspešni akciji (create/edit/delete/approve/reject) ───────────────────
  function afterReservationChange(msg) {
    if (msg) showToast(msg);
    Calendar.loadMonth(state.calendarYear, state.calendarMonth);
    loadSchedule();
  }

  // ── Inicializacija ───────────────────────────────────────────
  async function init() {
    // Dropdown restavracije (admin)
    const sel = document.getElementById("restaurant-select");
    if (sel) {
      // Nastavi začetno vrednost
      sel.value = state.restaurantId || "";
      sel.addEventListener("change", () => onRestaurantChange(sel.value));
    }

    // Gumb danes
    document.getElementById("btn-today").addEventListener("click", goToToday);

    // Prev/next dan navigacija (poleg naslova razporeda)
    function shiftDay(deltaDays) {
      const d = new Date(state.currentDate);
      d.setDate(d.getDate() + deltaDays);
      state.currentDate = d;
      // Posodobi calendar prikaz, če smo prešli na drug mesec
      const newY = d.getFullYear();
      const newM = d.getMonth();
      if (newY !== state.calendarYear || newM !== state.calendarMonth) {
        state.calendarYear = newY;
        state.calendarMonth = newM;
        Calendar.render(newY, newM);
        Calendar.loadMonth(newY, newM);
      }
      // Označi nov dan v koledarju
      Calendar.setSelected(dateStr(d));
      loadSchedule();
      updateTodayBtn();
      updateAddBtn();
    }
    const prevDayBtn = document.getElementById("btn-prev-day");
    const nextDayBtn = document.getElementById("btn-next-day");
    if (prevDayBtn) prevDayBtn.addEventListener("click", () => shiftDay(-1));
    if (nextDayBtn) nextDayBtn.addEventListener("click", () => shiftDay(1));

    // Gumb + Nova rezervacija
    const addBtn = document.getElementById("btn-add-reservation");
    if (addBtn) {
      addBtn.addEventListener("click", () => {
        ReservationModal.open("create", {
          date: dateStr(state.currentDate),
          restaurantId: state.restaurantId,
        });
      });
    }

    // Gumb dnevno poročilo
    const reportBtn = document.getElementById("btn-daily-report");
    if (reportBtn) {
      reportBtn.addEventListener("click", () => {
        const rest =
          APP_STATE.restaurants.find((r) => r.id === state.restaurantId) ||
          APP_STATE.restaurants[0];
        DailyReport.open(
          state.reservations || [],
          state.currentDate,
          rest ? rest.name : "",
        );
      });
    }

    // Navigacija
    document.getElementById("cal-prev").addEventListener("click", prevMonth);
    document.getElementById("cal-next").addEventListener("click", nextMonth);

    // Callbacks za koordinacijo
    window.App = {
      onDayClick,
      afterReservationChange,
      showToast,
      getState: () => state,
      loadSchedule,
    };

    // Začetni load
    Calendar.render(state.calendarYear, state.calendarMonth);
    Calendar.loadMonth(state.calendarYear, state.calendarMonth);
    await loadSchedule();
    // Inicializiraj month baseline po prvem loadu
    await initMonthBaseline();
    updateTodayBtn();

    // Polling – ustavi ko je tab skrit, nadaljuj ko se vrne
    startPolling();
    document.addEventListener("visibilitychange", () => {
      if (document.hidden) stopPolling();
      else {
        pollForChanges();
        startPolling();
      }
    });
  }

  document.addEventListener("DOMContentLoaded", init);
})();
