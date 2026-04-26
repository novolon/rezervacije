/**
 * Mesečni koledar – render in podatkovni load.
 */
const Calendar = (() => {
  const MONTHS_SL = [
    "Januar", "Februar", "Marec", "April", "Maj", "Junij",
    "Julij", "Avgust", "September", "Oktober", "November", "December",
  ];
  const DAYS_FULL_SL = ["nedelja","ponedeljek","torek","sreda","četrtek","petek","sobota"];
  const MONTHS_GEN_SL = ["jan","feb","mar","apr","maj","jun","jul","avg","sep","okt","nov","dec"];

  let calendarData = {}; // { 'YYYY-MM-DD': { count, guests } }
  let selectedDate = APP_STATE.today;
  let currentYear = 0;
  let currentMonth = 0;

  function getEl(id) { return document.getElementById(id); }

  // ── Render mreže ─────────────────────────────────────────────
  function render(year, month) {
    currentYear = year;
    currentMonth = month;

    const title = getEl("cal-title");
    if (title) title.textContent = `${MONTHS_SL[month].toUpperCase()} ${year}`;

    const grid = getEl("cal-grid");
    if (!grid) return;
    grid.innerHTML = "";

    const firstDay = new Date(year, month, 1);
    let startOffset = (firstDay.getDay() + 6) % 7;
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const today = APP_STATE.today;

    const appState = window.APP_STATE;
    const restId2 = (window.App ? App.getState().restaurantId : null) || (appState.restaurants[0] || {}).id;
    const rest = (appState.restaurants || []).find(r => r.id === restId2) || (appState.restaurants || [])[0];

    // Prazne celice pred 1.
    for (let i = 0; i < startOffset; i++) {
      const empty = document.createElement("div");
      empty.className = "rz-mc-cell rz-mc-empty";
      grid.appendChild(empty);
    }

    // Dnevi
    for (let d = 1; d <= daysInMonth; d++) {
      const dateObj = new Date(year, month, d);
      const ds = dateStr(dateObj);
      grid.appendChild(buildCell(d, ds, today, getDayCapacity(rest, dateObj)));
    }

    updateSummary(selectedDate);
  }

  // Izračuna dnevno kapaciteto: koliko gostov lahko restavracija sprejme v enem dnevu.
  // Formula: floor(odprto_okno_min / trajanje_rezervacije) * skupaj_sedežev
  function getDayCapacity(rest, dateObj) {
    if (!rest) return 0;
    const totalSeats = (rest.tables || []).reduce((s, t) => s + (t.capacity || 0), 0);
    if (!totalSeats) return 0;
    const duration = parseInt(rest.reservation_duration) || 60;

    const dow = (dateObj.getDay() + 6) % 7; // 0=Pon, 6=Ned
    const dsDay = (rest.day_schedules || []).find(d => d.day_of_week === dow);
    if (dsDay && !dsDay.is_open) return 0;

    const start = dsDay ? dsDay.start_time : (rest.schedule_start || 0);
    const end   = dsDay ? dsDay.end_time   : (rest.schedule_end   || 0);
    const windowMin = end - start;
    if (windowMin <= 0) return 0;

    const slots = Math.floor(windowMin / duration);
    return slots * totalSeats;
  }

  function buildCell(dayNum, ds, today, totalCapacity) {
    const cell = document.createElement("button");
    const classes = ["rz-mc-cell"];
    if (ds < today) classes.push("is-past");
    else if (ds === today) classes.push("is-today");
    if (ds === selectedDate) classes.push("is-sel");
    cell.className = classes.join(" ");
    cell.dataset.date = ds;

    const num = document.createElement("span");
    num.className = "rz-mc-num";
    num.textContent = dayNum;
    cell.appendChild(num);

    const data = calendarData[ds];
    const count = data ? data.count : 0;

    const cnt = document.createElement("span");
    cnt.className = "rz-mc-count mono";
    cnt.textContent = count > 0 ? count : "";
    cell.appendChild(cnt);

    const bar = document.createElement("span");
    bar.className = "rz-mc-bar";
    const fill = document.createElement("span");
    fill.className = "rz-mc-bar-fill";
    const cap = totalCapacity || 1;
    fill.style.width = data && data.guests > 0 ? `${Math.min(100, Math.round((data.guests / cap) * 100))}%` : "0%";
    bar.appendChild(fill);
    cell.appendChild(bar);

    cell.addEventListener("click", () => {
      selectedDate = ds;
      document.querySelectorAll(".rz-mc-cell.is-sel").forEach(c => c.classList.remove("is-sel"));
      cell.classList.add("is-sel");
      updateSummary(ds);
      if (window.App) App.onDayClick(new Date(ds + "T00:00:00"));
    });

    return cell;
  }

  // ── Naloži mesečne agregate ───────────────────────────────────
  async function loadMonth(year, month) {
    const monthStr = `${year}-${String(month + 1).padStart(2, "0")}`;
    const state = window.App ? App.getState() : null;
    const restId = state ? state.restaurantId : null;

    const url =
      `/api/reservations.php?month=${monthStr}` +
      (restId ? `&restaurant_id=${restId}` : "");
    try {
      calendarData = (await API.get(url)) || {};
      render(year, month);
      setSelected(selectedDate);
    } catch (e) {
      if (window.App) App.showToast(e.message, "error");
    }
  }

  // ── Označi izbran dan ─────────────────────────────────────────
  function setSelected(ds) {
    selectedDate = ds;
    document.querySelectorAll(".rz-mc-cell").forEach(c => {
      if (c.dataset.date === ds) c.classList.add("is-sel");
      else c.classList.remove("is-sel");
    });
    updateSummary(ds);
  }

  // ── Posodobi vrstico s povzetkom ─────────────────────────────
  function updateSummary(ds) {
    const labelEl = getEl("cal-sel-label");
    const statsEl = getEl("cal-sel-stats");
    if (!labelEl || !statsEl) return;

    if (!ds) { labelEl.textContent = "–"; statsEl.textContent = ""; return; }

    const d = new Date(ds + "T00:00:00");
    labelEl.textContent = `${DAYS_FULL_SL[d.getDay()]}, ${d.getDate()}. ${MONTHS_GEN_SL[d.getMonth()]}`;

    const data = calendarData[ds];
    if (data && data.count > 0) {
      statsEl.textContent = `${data.count} rezervacij · ${data.guests} oseb`;
    } else {
      statsEl.textContent = "Ni rezervacij";
    }
  }

  // ── Pomožna ───────────────────────────────────────────────────
  function dateStr(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
  }

  return { render, loadMonth, setSelected };
})();
