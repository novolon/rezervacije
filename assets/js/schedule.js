/**
 * Dnevni razpored – horizontalni Gantt timeline.
 * Mize so vrstice, čas gre od leve proti desni (procentualno).
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

  let nowTimer = null;
  let currentView = "timeline";
  let lastRenderArgs = null;

  // ── Pomožne funkcije ─────────────────────────────────────────
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

  const MONTHS_SL_NOM = [
    "januar",
    "februar",
    "marec",
    "april",
    "maj",
    "junij",
    "julij",
    "avgust",
    "september",
    "oktober",
    "november",
    "december",
  ];
  function formatTopbarTitle(date) {
    return `${DAYS_SL[date.getDay()]}, ${date.getDate()}. ${MONTHS_SL_NOM[date.getMonth()]}`;
  }

  function dateToStr(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  }

  // ── Grupiranje pending rezervacij ────────────────────────────
  function groupPending(reservations) {
    const pendingGroups = {};
    const nonPending = [];
    reservations.forEach((r) => {
      if (r.status === "pending") {
        const key = `${r.restaurant_id}|${r.reservation_time}`;
        if (!pendingGroups[key]) pendingGroups[key] = [];
        pendingGroups[key].push(r);
      } else {
        nonPending.push(r);
      }
    });
    const pendingReps = Object.values(pendingGroups).map((group) => {
      if (group.length === 1) return { ...group[0], _pendingGroup: group };
      return { ...group[0], _pendingGroup: group, _groupCount: group.length };
    });
    return [...nonPending, ...pendingReps];
  }

  // ── Izračun prekrivajočih se rezervacij ─────────────────────
  function computeOverlapLayout(reservations) {
    if (!reservations.length) return [];
    const sorted = [...reservations].sort(
      (a, b) => timeToMin(a.reservation_time) - timeToMin(b.reservation_time),
    );
    const result = [];
    let cluster = [],
      clusterEnd = 0;

    function processCluster(cl) {
      const cols = [];
      cl.forEach((r) => {
        const start = timeToMin(r.reservation_time);
        const dur = parseInt(r.reservation_duration) || 60;
        const end = start + dur;
        let placed = false;
        for (let i = 0; i < cols.length; i++) {
          if (cols[i] <= start) {
            cols[i] = end;
            result.push({ reservation: r, subCol: i, subCols: 0 });
            placed = true;
            break;
          }
        }
        if (!placed) {
          cols.push(end);
          result.push({ reservation: r, subCol: cols.length - 1, subCols: 0 });
        }
      });
      const totalSubCols = cols.length;
      for (let i = result.length - cl.length; i < result.length; i++) {
        result[i].subCols = totalSubCols;
      }
    }

    sorted.forEach((r) => {
      const start = timeToMin(r.reservation_time);
      const dur = parseInt(r.reservation_duration) || 60;
      const end = start + dur;
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

  const PX_PER_HOUR = 110;

  function mk(tag, cls) {
    const el = document.createElement(tag);
    if (cls) el.className = cls;
    return el;
  }

  // ── Overlay v rz-tl-row (px-based) ──────────────────────────
  function addOverlaysPx(
    row,
    dayPeriods,
    overlays,
    SCHEDULE_START,
    SCHEDULE_END,
  ) {
    const toPx = (m) => Math.max(0, ((m - SCHEDULE_START) / 60) * PX_PER_HOUR);
    const durPx = (d) => Math.max(0, (d / 60) * PX_PER_HOUR);

    function addOv(cls, leftPx, widthPx, label) {
      if (widthPx <= 0) return;
      const ov = mk("div", `tl-block-overlay ${cls}`);
      ov.style.cssText = `position:absolute;top:0;bottom:0;left:${leftPx}px;width:${widthPx}px;z-index:1;pointer-events:none;border-radius:0;`;
      if (label) {
        const lbl = mk("span", "tl-block-label");
        lbl.textContent = label;
        ov.appendChild(lbl);
      }
      row.appendChild(ov);
    }

    if (dayPeriods && dayPeriods.length > 0) {
      let cursor = SCHEDULE_START;
      dayPeriods.forEach((p) => {
        if (p.start_time > cursor)
          addOv("tl-off-hours", toPx(cursor), durPx(p.start_time - cursor));
        cursor = Math.max(cursor, p.end_time);
      });
      if (cursor < SCHEDULE_END)
        addOv("tl-off-hours", toPx(cursor), durPx(SCHEDULE_END - cursor));
    }

    if (!overlays) return;
    if (overlays.isClosed) {
      addOv("closed", 0, durPx(SCHEDULE_END - SCHEDULE_START), "Zaprt");
      return;
    }
    if (overlays.fullBlackout) {
      addOv(
        "full",
        0,
        durPx(SCHEDULE_END - SCHEDULE_START),
        overlays.fullBlackoutReason
          ? `Blokirano – ${overlays.fullBlackoutReason}`
          : "Blokirano",
      );
      return;
    }
    (overlays.partialBlackouts || []).forEach((pb) => {
      addOv(
        "partial",
        toPx(pb.start),
        durPx(pb.end - pb.start),
        pb.reason ? `Blokirano – ${pb.reason}` : null,
      );
    });
  }

  // ── Rezervacijska kartica (rz-res) ───────────────────────────
  function buildResCard(
    reservation,
    SCHEDULE_START,
    isPast,
    isToday,
    duration,
  ) {
    const startMin = timeToMin(reservation.reservation_time);
    const dur = parseInt(reservation.reservation_duration) || duration;
    const isPending =
      reservation.status === "pending" || !!reservation._pendingGroup;
    const groupCount = reservation._groupCount || 0;

    let stateCls = "rz-res-confirmed";
    if (isPending) {
      stateCls = "rz-res-pending";
    } else if (reservation.status === "arrived") {
      stateCls = "rz-res-arrived";
    } else if (isPast) {
      stateCls = "rz-res-past";
    } else if (isToday) {
      const now = new Date();
      const nowMin = now.getHours() * 60 + now.getMinutes();
      const endMin = startMin + dur;
      if (nowMin >= endMin) stateCls = "rz-res-past";
      else if (nowMin >= startMin) stateCls = "rz-res-arrived";
    }

    const left =
      Math.max(0, ((startMin - SCHEDULE_START) / 60) * PX_PER_HOUR) + 2;
    const width = Math.max(20, (dur / 60) * PX_PER_HOUR - 4);

    const btn = mk("button", `rz-res ${stateCls}`);
    btn.style.left = left + "px";
    btn.style.width = width + "px";

    const head = mk("div", "rz-res-head");
    const timeSpan = mk("span", "rz-res-time mono");
    timeSpan.textContent = reservation.reservation_time
      ? reservation.reservation_time.slice(0, 5)
      : "—";
    head.appendChild(timeSpan);
    const guestsSpan = mk("span", "rz-res-guests");
    guestsSpan.textContent = `· ${reservation.guest_count || 1}g`;
    head.appendChild(guestsSpan);
    if (isPending) {
      const pill = mk("span", "rz-res-pill rz-res-pill-warn");
      pill.textContent = "ČAKA";
      head.appendChild(pill);
    } else if (reservation.status === "arrived") {
      const pill = mk("span", "rz-res-pill");
      pill.textContent = "PRIŠLI";
      head.appendChild(pill);
    }
    btn.appendChild(head);

    const nameEl = mk("div", "rz-res-name");
    nameEl.textContent =
      isPending && groupCount > 1
        ? `${groupCount} čakajočih`
        : reservation.guest_name || "—";
    btn.appendChild(nameEl);

    if (reservation.notes && width > 140) {
      const noteEl = mk("div", "rz-res-note");
      noteEl.textContent = reservation.notes;
      btn.appendChild(noteEl);
    }

    btn.addEventListener("click", (e) => {
      e.stopPropagation();
      if (isPending && reservation._pendingGroup)
        PendingModal.open(reservation._pendingGroup);
      else ReservationModal.open("view", reservation);
    });

    return btn;
  }

  // ── Zgradi rz-tl-row ─────────────────────────────────────────
  function buildTlRow(
    rowResv,
    table,
    SCHEDULE_START,
    SCHEDULE_END,
    overlays,
    dayPeriods,
    isPast,
    isToday,
    ds,
    duration,
  ) {
    const row = mk("div", "rz-tl-row");
    row.style.cursor = isPast ? "default" : "cell";

    addOverlaysPx(row, dayPeriods, overlays, SCHEDULE_START, SCHEDULE_END);

    const layout = computeOverlapLayout(rowResv);
    const maxCols = Math.max(
      1,
      layout.reduce((m, l) => Math.max(m, l.subCols), 1),
    );
    if (maxCols > 1) row.style.height = 56 * maxCols + "px";

    layout.forEach(({ reservation, subCol }) => {
      if (table) {
        const assignments = reservation.table_assignments || [];
        const thisA = assignments.find((a) => a.table_id === table.id);
        if (thisA && assignments.length > 1) {
          // Določi skupino: po merge_group_id če obstaja, sicer vse dodelitve (auto-merge)
          const mergeGroup = thisA.merge_group_id
            ? assignments.filter(
                (a) => a.merge_group_id === thisA.merge_group_id,
              )
            : assignments.slice();
          mergeGroup.sort((a, b) => a.table_id - b.table_id);
          if (mergeGroup[0].table_id !== table.id) {
            const startMin = timeToMin(reservation.reservation_time);
            const dur = parseInt(reservation.reservation_duration) || duration;
            const left =
              Math.max(0, ((startMin - SCHEDULE_START) / 60) * PX_PER_HOUR) + 2;
            const width = Math.max(20, (dur / 60) * PX_PER_HOUR - 4);
            const ph = mk("div", "tl-merged-placeholder");
            ph.style.cssText = `position:absolute;top:${subCol * 56 + 3}px;height:${48}px;left:${left}px;width:${width}px;${isPast ? "opacity:.75;" : ""}`;
            ph.innerHTML = `Združena miza:<strong>${mergeGroup[0].table_name || ""}</strong>`;
            ph.addEventListener("click", (e) => {
              e.stopPropagation();
              ReservationModal.open("view", reservation);
            });
            row.appendChild(ph);
            return;
          }
        }
      }
      const card = buildResCard(
        reservation,
        SCHEDULE_START,
        isPast,
        isToday,
        duration,
      );
      if (subCol > 0) {
        card.style.top = subCol * 56 + 6 + "px";
        card.style.bottom = "auto";
        card.style.height = "44px";
      }
      row.appendChild(card);
    });

    if (!isPast) {
      row.addEventListener("click", (e) => {
        if (e.target !== row) return;
        const rect = row.getBoundingClientRect();
        const rawPx = e.clientX - rect.left;
        const rawMin = (rawPx / PX_PER_HOUR) * 60;
        const snapped = Math.floor(rawMin / duration) * duration;
        const absMin =
          SCHEDULE_START +
          Math.max(
            0,
            Math.min(snapped, SCHEDULE_END - SCHEDULE_START - duration),
          );
        ReservationModal.open("create", {
          date: ds,
          time: minToTime(absMin),
          restaurantId: window.App ? App.getState().restaurantId : null,
          tableId: table ? table.id : null,
        });
      });
    }

    return row;
  }

  // ── Now line + pill posodabljanje ────────────────────────────
  function updateNowLine(tlBodyEl, SCHEDULE_START, totalMin) {
    const now = new Date();
    const nowMin = now.getHours() * 60 + now.getMinutes();
    const SCHEDULE_END = SCHEDULE_START + totalMin;
    const visible = nowMin >= SCHEDULE_START && nowMin <= SCHEDULE_END;
    const nowX = visible ? ((nowMin - SCHEDULE_START) / 60) * PX_PER_HOUR : 0;

    const nl = document.getElementById("rz-tl-now-line");
    if (nl) {
      nl.style.left = nowX + "px";
      nl.style.display = visible ? "" : "none";
    }

    const pill = document.getElementById("rz-now-pill");
    if (pill) {
      pill.style.left = nowX + "px";
      pill.textContent = `ZDAJ · ${minToTime(nowMin)}`;
      pill.style.display = visible ? "" : "none";
    }

    const legendNow = document.getElementById("tl-legend-now");
    if (legendNow)
      legendNow.textContent = visible ? `ZDAJ ${minToTime(nowMin)}` : "";
  }

  function scrollToNow(tlBodyEl, SCHEDULE_START, totalMin) {
    const now = new Date();
    const nowMin = now.getHours() * 60 + now.getMinutes();
    const SCHEDULE_END = SCHEDULE_START + totalMin;
    if (nowMin < SCHEDULE_START || nowMin > SCHEDULE_END) return;
    const nowX = ((nowMin - SCHEDULE_START) / 60) * PX_PER_HOUR;
    tlBodyEl.scrollLeft = Math.max(0, nowX - tlBodyEl.clientWidth / 2);
  }

  // ── ListView ─────────────────────────────────────────────────
  function renderList(reservations, body) {
    body.innerHTML = "";
    const sorted = [...reservations]
      .filter((r) => r.status !== "cancelled")
      .sort((a, b) => a.reservation_time.localeCompare(b.reservation_time));

    const wrap = document.createElement("div");
    wrap.style.cssText = "padding:0 24px 12px";
    const table = document.createElement("table");
    table.className = "rz-table";

    table.innerHTML = `<thead><tr>
      <th style="width:70px">URA</th>
      <th>GOST</th>
      <th>MIZA</th>
      <th style="width:60px" class="rz-th-num">OSEB</th>
      <th>OPOMBA</th>
      <th style="width:130px">STATUS</th>
    </tr></thead>`;

    const tbody = document.createElement("tbody");
    if (sorted.length === 0) {
      const tr = document.createElement("tr");
      tr.innerHTML = `<td colspan="6" style="text-align:center;color:var(--ink-mute);padding:32px">Ni rezervacij za ta dan</td>`;
      tbody.appendChild(tr);
    } else {
      sorted.forEach((r) => {
        const stateCls =
          r.status === "arrived"
            ? "rz-chip-arrived"
            : r.status === "no_show"
              ? "rz-chip-danger"
              : r.status === "confirmed"
                ? "rz-chip-confirmed"
                : r.status === "pending"
                  ? "rz-chip-pending"
                  : "rz-chip-past";
        const stateLabel =
          r.status === "arrived"
            ? "PRIŠLI"
            : r.status === "no_show"
              ? "NI PRIŠEL"
              : r.status === "confirmed"
                ? "POTRJENO"
                : r.status === "pending"
                  ? "ČAKA"
                  : "KONČANO";
        const tables =
          Array.isArray(r.table_assignments) && r.table_assignments.length
            ? r.table_assignments
                .map((a) => a.table_name || `Miza ${a.table_id}`)
                .join(", ")
            : "—";
        const tr = document.createElement("tr");
        tr.style.cursor = "pointer";
        tr.innerHTML = `
          <td class="mono" style="font-weight:700">${r.reservation_time ? r.reservation_time.slice(0, 5) : "—"}</td>
          <td style="font-weight:600">${r.guest_name || "—"}</td>
          <td>${tables}</td>
          <td class="rz-td-num mono">${r.guest_count || "—"}</td>
          <td style="color:var(--ink-mute);font-size:12px">${r.notes || "—"}</td>
          <td><span class="rz-chip ${stateCls}">${stateLabel}</span></td>`;
        tr.addEventListener("click", () => {
          if (typeof ReservationModal !== "undefined")
            ReservationModal.open("view", r);
        });
        tbody.appendChild(tr);
      });
    }
    table.appendChild(tbody);
    wrap.appendChild(table);
    body.appendChild(wrap);
  }

  // ── Glavni render ────────────────────────────────────────────
  function render(
    date,
    reservations,
    duration,
    allRestaurants,
    bounds,
    overlays,
  ) {
    lastRenderArgs = {
      date,
      reservations,
      duration,
      allRestaurants,
      bounds,
      overlays,
    };
    duration = Math.max(15, duration || 60);
    const SCHEDULE_START = bounds ? bounds.start : 480;
    const SCHEDULE_END = bounds ? bounds.end : 1380;
    const totalMin = SCHEDULE_END - SCHEDULE_START;

    // Eyebrow datum
    const eyebrowDate = document.getElementById("sched-eyebrow-date");
    if (eyebrowDate) {
      const d = date;
      const short = ["ned", "pon", "tor", "sre", "čet", "pet", "sob"][
        d.getDay()
      ];
      eyebrowDate.textContent = `${short.toUpperCase()} ${d.getDate()}. ${d.getMonth() + 1}.`;
    }

    // Glava panela — topbar naslov (datum) in podnaslov (statistika)
    const titleEl = document.getElementById("rz-topbar-title");
    if (titleEl) titleEl.textContent = formatTopbarTitle(date);
    const labelEl = document.getElementById("schedule-date-label");
    if (labelEl) labelEl.textContent = formatDateLabel(date);

    const visibleResv = reservations.filter((r) => r.status !== "cancelled");
    const confirmedCount = visibleResv.filter(
      (r) => r.status === "confirmed",
    ).length;
    const pendingCount = visibleResv.filter(
      (r) => r.status === "pending",
    ).length;
    const totalGuests = visibleResv
      .filter((r) => r.status !== "cancelled")
      .reduce((s, r) => s + parseInt(r.guest_count || 0), 0);

    const tbC = document.getElementById("topbar-stat-confirmed");
    const tbP = document.getElementById("topbar-stat-pending");
    const tbG = document.getElementById("topbar-stat-guests");
    if (tbC) tbC.textContent = confirmedCount;
    if (tbP) tbP.textContent = pendingCount;
    if (tbG) tbG.textContent = totalGuests;

    const sc = document.getElementById("stat-count");
    const sg = document.getElementById("stat-guests");
    if (sc) sc.textContent = visibleResv.length;
    if (sg) sg.textContent = totalGuests;

    const body = document.getElementById("schedule-body");
    if (!body) return;
    body.innerHTML = "";

    if (currentView === "list") {
      renderList(reservations, body);
      return;
    }

    if (nowTimer) {
      clearInterval(nowTimer);
      nowTimer = null;
    }

    const ds = dateToStr(date);
    const isPast = ds < APP_STATE.today;
    const isToday = ds === APP_STATE.today;

    // Aktivna restavracija
    const restId = window.App ? App.getState().restaurantId : null;
    const rest =
      APP_STATE.restaurants.find((r) => r.id === restId) ||
      APP_STATE.restaurants[0];
    const hasTables = !!(
      rest &&
      rest.has_tables &&
      Array.isArray(rest.tables) &&
      rest.tables.length > 0
    );

    // Vsi odprti termini za ta dan (podpora za multi-period)
    const dayIdx = (date.getDay() + 6) % 7; // 0=Pon
    // Najprej poskusi day_periods (nova tabela z multi-period podporo), fallback na day_schedules
    const rawPeriods =
      rest && (rest.day_periods || []).length > 0
        ? (rest.day_periods || []).filter((d) => d.day_of_week === dayIdx)
        : rest
          ? (rest.day_schedules || []).filter(
              (d) => d.day_of_week === dayIdx && d.is_open,
            )
          : [];
    const dayPeriods = rawPeriods
      .slice()
      .sort((a, b) => a.start_time - b.start_time);

    const allForLayout = groupPending(reservations);

    // Grupiraj mize po conah
    let zones;
    if (hasTables) {
      const zoneMap = new Map();
      rest.tables.forEach((t) => {
        const k = t.area_name || "";
        if (!zoneMap.has(k))
          zoneMap.set(k, { name: t.area_name || "Splošno", tables: [] });
        zoneMap.get(k).tables.push(t);
      });
      zones = [...zoneMap.values()];
    }

    const firstHour = Math.floor(SCHEDULE_START / 60);
    const lastHour = Math.ceil(SCHEDULE_END / 60);
    const totalHours = lastHour - firstHour;
    const totalWidth = totalHours * PX_PER_HOUR;

    const nowCur = new Date();
    const nowMin = isToday
      ? nowCur.getHours() * 60 + nowCur.getMinutes()
      : null;
    const nowVisible =
      nowMin !== null && nowMin >= SCHEDULE_START && nowMin <= SCHEDULE_END;
    const nowX = nowVisible
      ? ((nowMin - SCHEDULE_START) / 60) * PX_PER_HOUR
      : null;

    // ── rz-tl-head-wrap ──────────────────────────────────────
    const headWrap = mk("div", "rz-tl-head-wrap");
    headWrap.appendChild(mk("div", "rz-tl-sticky-head"));

    const hoursDiv = mk("div", "rz-tl-hours");
    const hoursInner = mk("div", "rz-tl-hours-inner");
    hoursInner.style.width = totalWidth + "px";
    for (let i = 0; i <= totalHours; i++) {
      const h = firstHour + i;
      const hourEl = mk("div", "rz-tl-hour");
      hourEl.style.left = i * PX_PER_HOUR + "px";
      const lbl = mk("span", "rz-tl-hour-label");
      lbl.textContent = String(h).padStart(2, "0") + ":00";
      hourEl.appendChild(lbl);
      hoursInner.appendChild(hourEl);
    }
    if (nowX !== null) {
      const pill = mk("div", "rz-tl-now-pill mono");
      pill.id = "rz-now-pill";
      pill.style.left = nowX + "px";
      pill.textContent = `ZDAJ · ${minToTime(nowMin)}`;
      hoursInner.appendChild(pill);
    }
    hoursDiv.appendChild(hoursInner);
    headWrap.appendChild(hoursDiv);
    body.appendChild(headWrap);

    // ── rz-tl-body ───────────────────────────────────────────
    const tlBody = mk("div", "rz-tl-body");

    // Leva plošča
    const leftPanel = mk("div", "rz-tl-left");
    if (!hasTables) {
      const rl = mk("div", "rz-tl-row-label");
      const s = mk("span");
      s.textContent = "Rezervacije";
      rl.appendChild(s);
      leftPanel.appendChild(rl);
    } else {
      zones.forEach((zone) => {
        const zoneEl = mk("div", "rz-tl-zone");
        const zh = mk("div", "rz-tl-zone-head");
        const zn = mk("span");
        zn.textContent = zone.name;
        const zc = mk("span", "rz-tl-zone-count");
        zc.textContent = zone.tables.length;
        zh.appendChild(zn);
        zh.appendChild(zc);
        zoneEl.appendChild(zh);
        zone.tables.forEach((t) => {
          const rl = mk("div", "rz-tl-row-label");
          const n = mk("span");
          n.textContent = t.name;
          rl.appendChild(n);
          if (t.capacity) {
            const s = mk("span", "rz-tl-table-seats");
            s.textContent = t.capacity;
            rl.appendChild(s);
          }
          zoneEl.appendChild(rl);
        });
        leftPanel.appendChild(zoneEl);
      });
    }
    tlBody.appendChild(leftPanel);

    // Grid
    const gridWrap = mk("div", "rz-tl-grid-wrap");
    gridWrap.style.minWidth = totalWidth + "px";
    const grid = mk("div", "rz-tl-grid");
    grid.style.width = totalWidth + "px";

    for (let i = 0; i <= totalHours; i++) {
      const vl = mk("div", "rz-tl-vline");
      vl.style.left = i * PX_PER_HOUR + "px";
      grid.appendChild(vl);
    }
    if (nowX !== null) {
      const nl = mk("div", "rz-tl-now-line");
      nl.id = "rz-tl-now-line";
      nl.style.left = nowX + "px";
      nl.appendChild(mk("div", "rz-tl-now-dot"));
      grid.appendChild(nl);
    }

    if (!hasTables) {
      const zb = mk("div", "rz-tl-zone-body");
      zb.appendChild(
        buildTlRow(
          allForLayout,
          null,
          SCHEDULE_START,
          SCHEDULE_END,
          overlays,
          dayPeriods,
          isPast,
          isToday,
          ds,
          duration,
        ),
      );
      grid.appendChild(zb);
    } else {
      const shownResv = new Set();
      zones.forEach((zone) => {
        const zb = mk("div", "rz-tl-zone-body");
        zb.appendChild(mk("div", "rz-tl-zone-spacer"));
        zone.tables.forEach((table) => {
          const tableResv = allForLayout.filter(
            (r) =>
              Array.isArray(r.table_assignments) &&
              r.table_assignments.some((a) => a.table_id === table.id),
          );
          tableResv.forEach((r) => shownResv.add(r));
          zb.appendChild(
            buildTlRow(
              tableResv,
              table,
              SCHEDULE_START,
              SCHEDULE_END,
              overlays,
              dayPeriods,
              isPast,
              isToday,
              ds,
              duration,
            ),
          );
        });
        grid.appendChild(zb);
      });

      // Rezervacije brez dodeljene mize
      const unassigned = allForLayout.filter((r) => !shownResv.has(r));
      if (unassigned.length > 0) {
        // Leva plošča — dodaj "Brez mize" cono
        const uZoneEl = mk("div", "rz-tl-zone");
        const uzh = mk("div", "rz-tl-zone-head");
        const uzn = mk("span");
        uzn.textContent = "Brez mize";
        uzn.style.color = "var(--ink-mute)";
        uzh.appendChild(uzn);
        uZoneEl.appendChild(uzh);
        const url = mk("div", "rz-tl-row-label");
        const us = mk("span");
        us.textContent = "—";
        us.style.color = "var(--ink-mute)";
        url.appendChild(us);
        uZoneEl.appendChild(url);
        leftPanel.appendChild(uZoneEl);
        // Grid
        const uzb = mk("div", "rz-tl-zone-body");
        uzb.appendChild(mk("div", "rz-tl-zone-spacer"));
        uzb.appendChild(
          buildTlRow(
            unassigned,
            null,
            SCHEDULE_START,
            SCHEDULE_END,
            overlays,
            dayPeriods,
            isPast,
            isToday,
            ds,
            duration,
          ),
        );
        grid.appendChild(uzb);
      }
    }

    gridWrap.appendChild(grid);
    tlBody.appendChild(gridWrap);

    // Sinhronizacija horizontalnega skrolanja glave
    tlBody.addEventListener("scroll", () => {
      hoursInner.style.transform = `translateX(-${tlBody.scrollLeft}px)`;
    });

    body.appendChild(tlBody);

    // Now timer
    if (isToday) {
      updateNowLine(tlBody, SCHEDULE_START, totalMin);
      nowTimer = setInterval(
        () => updateNowLine(tlBody, SCHEDULE_START, totalMin),
        60000,
      );
      requestAnimationFrame(() =>
        scrollToNow(tlBody, SCHEDULE_START, totalMin),
      );
    }
  }

  function setView(view) {
    currentView = view;
    const eyebrowView = document.getElementById("sched-eyebrow-view");
    if (eyebrowView)
      eyebrowView.textContent = view === "list" ? "SEZNAM" : "TIMELINE";
    document.querySelectorAll("#sched-view-seg button").forEach((btn) => {
      btn.classList.toggle("is-sel", btn.dataset.view === view);
    });
    if (lastRenderArgs) {
      const { date, reservations, duration, allRestaurants, bounds, overlays } =
        lastRenderArgs;
      render(date, reservations, duration, allRestaurants, bounds, overlays);
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("#sched-view-seg button").forEach((btn) => {
      btn.addEventListener("click", () => setView(btn.dataset.view));
    });
    const refreshBtn = document.getElementById("sched-refresh-btn");
    if (refreshBtn)
      refreshBtn.addEventListener("click", () => {
        if (typeof App !== "undefined" && App.loadSchedule) App.loadSchedule();
      });
  });

  return { render, setView };
})();
