/**
 * Dnevni razpored – absolutno pozicioniran timeline.
 * Vsaka rezervacija je postavljena na pravo vertikalno pozicijo
 * in ima višino sorazmerno s trajanjem.
 */
const Schedule = (() => {
  const MONTHS_SL = [
    "januarja",
    "februarja",
    "marca",
    "aprila",
    "maja",
    "junija",
    "julija",
    "avgusta",
    "septembra",
    "oktobra",
    "novembra",
    "decembra",
  ];
  const DAYS_SL = [
    "Nedelja",
    "Ponedeljek",
    "Torek",
    "Sreda",
    "Četrtek",
    "Petek",
    "Sobota",
  ];

  const PX_PER_MIN = 0.6; // 60 min = 54px, 90 min = 81px

  function minToTime(m) {
    return `${String(Math.floor(m / 60)).padStart(2, "0")}:${String(m % 60).padStart(2, "0")}`;
  }

  function timeToMin(t) {
    const p = t.split(":");
    return parseInt(p[0]) * 60 + parseInt(p[1]);
  }

  function formatDateLabel(date) {
    return `${DAYS_SL[date.getDay()]}, ${date.getDate()}. ${MONTHS_SL[date.getMonth()]} ${date.getFullYear()}`;
  }

  function dateToStr(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  }

  // ── Render ────────────────────────────────────────────────────
  function render(date, reservations, duration, allRestaurants, bounds) {
    duration = Math.max(15, duration || 60);
    const SCHEDULE_START = bounds ? bounds.start : 480;  // minute (8*60)
    const SCHEDULE_END   = bounds ? bounds.end   : 1380; // minute (23*60)

    // Glava panela
    const labelEl = document.getElementById("schedule-date-label");
    if (labelEl) labelEl.textContent = formatDateLabel(date);

    const totalCount = reservations.length;
    const totalGuests = reservations.reduce(
      (s, r) => s + parseInt(r.guest_count),
      0,
    );
    const sc = document.getElementById("stat-count");
    const sg = document.getElementById("stat-guests");
    if (sc) sc.textContent = totalCount;
    if (sg) sg.textContent = totalGuests;

    const body = document.getElementById("schedule-body");
    if (!body) return;
    body.innerHTML = "";

    const totalHeight = (SCHEDULE_END - SCHEDULE_START) * PX_PER_MIN;
    const ds = dateToStr(date);
    const isPast = ds < APP_STATE.today;

    const restIds = [
      ...new Set(reservations.map((r) => parseInt(r.restaurant_id))),
    ];
    const isMulti = restIds.length > 1;

    // Glava stolpcev (multi-restavracija)
    if (isMulti) {
      const rHeader = document.createElement("div");
      rHeader.className = "tl-rest-header";
      restIds.forEach((rid) => {
        const rest = reservations.find(
          (r) => parseInt(r.restaurant_id) === rid,
        );
        const col = document.createElement("div");
        col.className = "tl-rest-col-label";
        col.style.borderLeftColor = rest ? rest.restaurant_color : "#ccc";
        col.textContent = rest ? rest.restaurant_name : `Restavracija ${rid}`;
        rHeader.appendChild(col);
      });
      body.appendChild(rHeader);
    }

    // Zunanji wrapper (os + vsebina)
    const outer = document.createElement("div");
    outer.className = "tl-outer";

    // ── Časovna os (levo) ─────────────────────────────────────
    const axis = document.createElement("div");
    axis.className = "tl-axis";
    axis.style.height = totalHeight + "px";

    const firstHour = Math.ceil(SCHEDULE_START / 60);
    const lastHour  = Math.floor(SCHEDULE_END / 60);
    for (let h = firstHour; h <= lastHour; h++) {
      const hMin = h * 60;
      const lbl = document.createElement("div");
      lbl.className = "tl-hour-label";
      lbl.style.top = (hMin - SCHEDULE_START) * PX_PER_MIN + "px";
      lbl.textContent = `${String(h).padStart(2, "0")}:00`;
      axis.appendChild(lbl);
    }
    outer.appendChild(axis);

    // ── Vsebina timeline ──────────────────────────────────────
    const content = document.createElement("div");
    content.className = "tl-content";
    content.style.height = totalHeight + "px";

    // Mrežne črte (vsako uro)
    for (let h = firstHour; h <= lastHour; h++) {
      const line = document.createElement("div");
      line.className = "tl-grid-line";
      line.style.top = (h * 60 - SCHEDULE_START) * PX_PER_MIN + "px";
      content.appendChild(line);
    }

    // Lažje črte za poddivizije (če trajanje < 60)
    if (duration < 60) {
      for (let m = SCHEDULE_START; m < SCHEDULE_END; m += duration) {
        if (m % 60 !== 0) {
          const line = document.createElement("div");
          line.className = "tl-grid-line tl-grid-minor";
          line.style.top = (m - SCHEDULE_START) * PX_PER_MIN + "px";
          content.appendChild(line);
        }
      }
    }

    // Klikabilno ozadje (za dodajanje rezervacij)
    if (!isPast) {
      content.addEventListener("click", (e) => {
        if (e.target !== content) return;
        const rect = content.getBoundingClientRect();
        const clickY = e.clientY - rect.top;
        const rawMin = clickY / PX_PER_MIN;
        const snapped = Math.floor(rawMin / duration) * duration;
        const absMin =
          SCHEDULE_START +
          Math.max(
            0,
            Math.min(snapped, SCHEDULE_END - SCHEDULE_START - duration),
          );
        let restaurantId = null;
        if (isMulti) {
          const clickX = e.clientX - rect.left;
          const colIdx = Math.floor(
            clickX / (content.offsetWidth / restIds.length),
          );
          restaurantId = restIds[Math.min(colIdx, restIds.length - 1)];
        } else if (window.App) {
          restaurantId = App.getState().restaurantId;
        }
        ReservationModal.open("create", {
          date: ds,
          time: minToTime(absMin),
          restaurantId,
        });
      });
    }

    // Rezervacijske kartice – z detekcijo prekrivanj
    if (isMulti) {
      // Vsaka restavracija dobi svojo cono, znotraj cone izračunaj prekrivanja
      restIds.forEach((rid, restColIdx) => {
        const restReservations = reservations.filter(r => parseInt(r.restaurant_id) === rid);
        const layout = computeOverlapLayout(restReservations);
        layout.forEach(({ reservation, subCol, subCols }) => {
          const card = buildCard(reservation, restColIdx, restIds.length, isPast, subCol, subCols, SCHEDULE_START);
          content.appendChild(card);
        });
      });
    } else {
      const layout = computeOverlapLayout(reservations);
      layout.forEach(({ reservation, subCol, subCols }) => {
        const card = buildCard(reservation, 0, 1, isPast, subCol, subCols, SCHEDULE_START);
        content.appendChild(card);
      });
    }

    outer.appendChild(content);
    body.appendChild(outer);

    // Prazen dan – sporočilo
    if (reservations.length === 0) {
      const empty = document.createElement("div");
      empty.className = "schedule-empty";
      empty.innerHTML = `
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>
                </svg>
                <p>Ni rezervacij za ta dan</p>
                ${!isPast ? '<small style="color:var(--color-muted);font-size:.75rem">Kliknite na čas za novo rezervacijo</small>' : ""}`;
      body.insertBefore(empty, outer);
    }
  }

  // ── Izračun prekrivajočih se rezervacij ──────────────────────
  // Vrne za vsako rezervacijo: { reservation, subCol, subCols }
  function computeOverlapLayout(reservations) {
    if (!reservations.length) return [];

    // Razvrsti po začetnem času
    const sorted = [...reservations].sort((a, b) =>
      timeToMin(a.reservation_time) - timeToMin(b.reservation_time)
    );

    const result = [];
    // Cluster: skupina ki se medsebojno prekrivajo
    let cluster = [];
    let clusterEnd = 0;

    function processCluster(cluster) {
      // Dodeli sub-stolpce znotraj klastra
      const cols = []; // cols[i] = konec zadnje rezervacije v stolpcu i
      cluster.forEach(r => {
        const start = timeToMin(r.reservation_time);
        const dur   = parseInt(r.reservation_duration) || 60;
        const end   = start + dur;
        // Poišči prvi prosti stolpec
        let placed = false;
        for (let i = 0; i < cols.length; i++) {
          if (cols[i] <= start) {
            cols[i] = end;
            result.push({ reservation: r, subCol: i, subCols: 0 }); // subCols se nastavi po
            placed = true;
            break;
          }
        }
        if (!placed) {
          cols.push(end);
          result.push({ reservation: r, subCol: cols.length - 1, subCols: 0 });
        }
      });
      // Nastavi subCols na vse iz tega klastra
      const totalSubCols = cols.length;
      // Poišči zadnje cluster.length elementov v result in jim nastavi subCols
      for (let i = result.length - cluster.length; i < result.length; i++) {
        result[i].subCols = totalSubCols;
      }
    }

    sorted.forEach(r => {
      const start = timeToMin(r.reservation_time);
      const dur   = parseInt(r.reservation_duration) || 60;
      const end   = start + dur;

      if (cluster.length === 0 || start < clusterEnd) {
        cluster.push(r);
        clusterEnd = Math.max(clusterEnd, end);
      } else {
        processCluster(cluster);
        cluster = [r];
        clusterEnd = end;
      }
    });
    if (cluster.length) processCluster(cluster);

    return result;
  }

  // ── Zgradi kartico ────────────────────────────────────────────
  function buildCard(reservation, colIdx, totalCols, isPast, subCol = 0, subCols = 1, schedStart = 480) {
    const startMin =
      timeToMin(reservation.reservation_time) - schedStart;
    const dur = parseInt(reservation.reservation_duration) || 60;
    const top = startMin * PX_PER_MIN;
    const height = Math.max(dur * PX_PER_MIN - 3, 24);
    // Zunanja cona (restavracija v multi načinu)
    const zoneLeftPct  = (colIdx / totalCols) * 100;
    const zoneWidthPct = 100 / totalCols;
    // Znotraj cone: sub-stolpec za prekrivanja
    const subLeftPct   = (subCol / subCols) * zoneWidthPct;
    const subWidthPct  = zoneWidthPct / subCols;

    const card = document.createElement("div");
    card.className = "reservation-card";
    card.style.cssText = `
            position: absolute;
            top: ${top}px;
            height: ${height}px;
            left: calc(${zoneLeftPct + subLeftPct}% + 4px);
            width: calc(${subWidthPct}% - 8px);
            border-left-color: ${reservation.restaurant_color || "#F59E0B"};
            overflow: hidden;
            z-index: 2;
            ${isPast ? "opacity:.75;" : ""}
        `;

    const nameEl = document.createElement("div");
    nameEl.className = "card-name";
    nameEl.textContent = reservation.guest_name;

    // Prikaži meta podatke samo če je kartica dovolj visoka
    if (height >= 36) {
      const meta = document.createElement("div");
      meta.className = "card-meta";

      const absStart = timeToMin(reservation.reservation_time);
      const timeEl = document.createElement("span");
      timeEl.className = "card-time";
      timeEl.textContent = `${minToTime(absStart)} – ${minToTime(absStart + dur)}`;

      const guestEl = document.createElement("span");
      guestEl.className = "card-guests";
      guestEl.innerHTML = `<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>${reservation.guest_count}`;

      meta.appendChild(timeEl);
      meta.appendChild(guestEl);
      card.appendChild(nameEl);
      card.appendChild(meta);
    } else {
      // Kompaktna kartica (kratko trajanje)
      const compact = document.createElement("div");
      compact.style.cssText =
        "display:flex;align-items:center;gap:6px;overflow:hidden;";
      compact.appendChild(nameEl);
      const absStart = timeToMin(reservation.reservation_time);
      const t = document.createElement("span");
      t.className = "card-time";
      t.style.flexShrink = "0";
      t.textContent = minToTime(absStart);
      compact.appendChild(t);
      card.appendChild(compact);
    }

    card.addEventListener("click", (e) => {
      e.stopPropagation();
      ReservationModal.open("view", reservation);
    });

    return card;
  }

  return { render };
})();
