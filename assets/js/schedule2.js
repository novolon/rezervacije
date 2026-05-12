/**
 * Dnevni razpored – horizontalni Gantt timeline.
 * Mize so vrstice, čas gre od leve proti desni (procentualno).
 */
const Schedule = (() => {
  // SL fallbacks (genitive months are SL-only grammar; other langs use nominative).
  const MONTHS_SL = [
    'januarja','februarja','marca','aprila','maja','junija',
    'julija','avgusta','septembra','oktobra','novembra','decembra',
  ];
  const DAYS_SL = ['Nedelja','Ponedeljek','Torek','Sreda','Četrtek','Petek','Sobota'];

  function dayName(jsDayIdx) {
    const monIdx = (jsDayIdx + 6) % 7;
    const v = window.__T__ && window.__T__['days.' + monIdx];
    return v || DAYS_SL[jsDayIdx];
  }
  function monthName(i) {
    if ((window.__LANG__ || 'sl') === 'sl') return MONTHS_SL[i];
    const v = window.__T__ && window.__T__['months.' + (i + 1)];
    return v || MONTHS_SL[i];
  }

  const ROW_HEIGHT = 48; // px
  let nowTimer = null;

  // ── Pomožne funkcije ─────────────────────────────────────────
  function minToTime(m) {
    return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
  }

  function timeToMin(t) {
    const p = t.split(':');
    return parseInt(p[0]) * 60 + parseInt(p[1]);
  }

  function formatDateLabel(date) {
    return `${dayName(date.getDay())}, ${date.getDate()}. ${monthName(date.getMonth())} ${date.getFullYear()}`;
  }

  function dateToStr(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  // ── Grupiranje pending rezervacij ────────────────────────────
  function groupPending(reservations) {
    const pendingGroups = {};
    const nonPending = [];
    reservations.forEach(r => {
      if (r.status === 'pending') {
        const key = `${r.restaurant_id}|${r.reservation_time}`;
        if (!pendingGroups[key]) pendingGroups[key] = [];
        pendingGroups[key].push(r);
      } else {
        nonPending.push(r);
      }
    });
    const pendingReps = Object.values(pendingGroups).map(group => {
      if (group.length === 1) return { ...group[0], _pendingGroup: group };
      return { ...group[0], _pendingGroup: group, _groupCount: group.length };
    });
    return [...nonPending, ...pendingReps];
  }

  // ── Izračun prekrivajočih se rezervacij ─────────────────────
  function computeOverlapLayout(reservations) {
    if (!reservations.length) return [];
    const sorted = [...reservations].sort(
      (a, b) => timeToMin(a.reservation_time) - timeToMin(b.reservation_time)
    );
    const result = [];
    let cluster = [], clusterEnd = 0;

    function processCluster(cl) {
      const cols = [];
      cl.forEach(r => {
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

    sorted.forEach(r => {
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

  // ── Off-hours segment helper ─────────────────────────────────
  function addOffHoursOv(content, fromMin, toMin, SCHEDULE_START, totalMin) {
    if (toMin <= fromMin) return;
    const lPct = a => Math.max(0, Math.min((a - SCHEDULE_START) / totalMin * 100, 100));
    const wPct = d => Math.max(0, Math.min(d / totalMin * 100, 100));
    const ov = document.createElement('div');
    ov.className = 'tl-block-overlay tl-off-hours';
    ov.style.left  = lPct(fromMin).toFixed(3) + '%';
    ov.style.width = wPct(toMin - fromMin).toFixed(3) + '%';
    content.appendChild(ov);
  }

  // ── Overlays (off-hours + blocked) ──────────────────────────
  function addOverlays(content, dayPeriods, overlays, SCHEDULE_START, SCHEDULE_END, totalMin) {
    const lPct = a => Math.max(0, Math.min((a - SCHEDULE_START) / totalMin * 100, 100));
    const wPct = d => Math.max(0, Math.min(d / totalMin * 100, 100));

    // Čas izven terminov – indigo (podpora za multi-period)
    if (dayPeriods && dayPeriods.length > 0) {
      let cursor = SCHEDULE_START;
      dayPeriods.forEach(p => {
        if (p.start_time > cursor) addOffHoursOv(content, cursor, p.start_time, SCHEDULE_START, totalMin);
        cursor = Math.max(cursor, p.end_time);
      });
      if (cursor < SCHEDULE_END) addOffHoursOv(content, cursor, SCHEDULE_END, SCHEDULE_START, totalMin);
    }

    if (!overlays) return;

    if (overlays.isClosed) {
      const ov = document.createElement('div');
      ov.className = 'tl-block-overlay closed';
      ov.appendChild(mkLabel('Zaprt', 'rgba(107,114,128,.55)'));
      content.appendChild(ov);
      return;
    }
    if (overlays.fullBlackout) {
      const ov = document.createElement('div');
      ov.className = 'tl-block-overlay full';
      const txt = overlays.fullBlackoutReason ? `Blokirano – ${overlays.fullBlackoutReason}` : 'Blokirano';
      ov.appendChild(mkLabel(txt, 'rgba(220,38,38,.6)'));
      content.appendChild(ov);
      return;
    }
    (overlays.partialBlackouts || []).forEach(pb => {
      const w = wPct(pb.end - pb.start);
      if (w <= 0) return;
      const ov = document.createElement('div');
      ov.className = 'tl-block-overlay partial';
      ov.style.left  = lPct(pb.start).toFixed(3) + '%';
      ov.style.width = w.toFixed(3) + '%';
      if (pb.reason) ov.appendChild(mkLabel(`Blokirano – ${pb.reason}`, 'rgba(180,90,0,.6)'));
      content.appendChild(ov);
    });
  }

  function mkLabel(text, color) {
    const lbl = document.createElement('span');
    lbl.className = 'tl-block-label';
    lbl.textContent = text;
    if (color) lbl.style.color = color;
    return lbl;
  }

  // ── Content area (skupna logika za oba načina) ───────────────
  function buildRowContent(
    rowResv, SCHEDULE_START, SCHEDULE_END, totalMin,
    overlays, dayPeriods, isPast, isToday, ds, duration, table
  ) {
    const lPct = absMin => Math.max(0, Math.min((absMin - SCHEDULE_START) / totalMin * 100, 100));
    const wPct = dur    => Math.max(0, Math.min(dur / totalMin * 100, 100));

    const content = document.createElement('div');
    content.className = 'tl-row-content';

    // Vertikalne mrežne črte za vsako uro
    const fh = Math.ceil(SCHEDULE_START / 60);
    const lh = Math.floor(SCHEDULE_END / 60);
    for (let h = fh; h <= lh; h++) {
      const gl = document.createElement('div');
      gl.className = 'tl-grid-line';
      gl.style.left = lPct(h * 60).toFixed(3) + '%';
      content.appendChild(gl);
    }

    addOverlays(content, dayPeriods, overlays, SCHEDULE_START, SCHEDULE_END, totalMin);

    // Izračun prekrivanj (za stacking znotraj ene vrstice)
    const layout = computeOverlapLayout(rowResv);
    const maxRows = Math.max(1, layout.reduce((m, l) => Math.max(m, l.subCols), 1));
    if (maxRows > 1) content.style.height = (ROW_HEIGHT * maxRows) + 'px';

    layout.forEach(({ reservation, subCol }) => {
      if (table) {
        const assignments = reservation.table_assignments || [];
        const thisA = assignments.find(a => a.table_id === table.id);
        if (thisA && thisA.merge_group_id) {
          const mergeGroup = assignments.filter(a => a.merge_group_id === thisA.merge_group_id);
          mergeGroup.sort((a, b) => a.table_id - b.table_id);
          if (mergeGroup[0].table_id !== table.id) {
            content.appendChild(buildMergedPlaceholder(
              reservation, mergeGroup[0].table_name,
              SCHEDULE_START, totalMin, lPct, wPct, isPast, subCol
            ));
            return;
          }
        }
      }
      content.appendChild(buildCard(reservation, SCHEDULE_START, totalMin, lPct, wPct, isPast, isToday, subCol));
    });

    // Klik za novo rezervacijo
    if (!isPast) {
      content.addEventListener('click', e => {
        if (e.target !== content) return;
        const rect = content.getBoundingClientRect();
        const rawMin = ((e.clientX - rect.left) / rect.width) * totalMin;
        const snapped = Math.floor(rawMin / duration) * duration;
        const absMin = SCHEDULE_START + Math.max(0, Math.min(snapped, totalMin - duration));
        ReservationModal.open('create', {
          date: ds,
          time: minToTime(absMin),
          restaurantId: window.App ? App.getState().restaurantId : null,
        });
      });
    }

    // Now line
    if (isToday) {
      const nl = document.createElement('div');
      nl.className = 'tl-now-line';
      content.appendChild(nl);
    }

    return content;
  }

  // ── Vrstica za mizo ──────────────────────────────────────────
  function buildTableRow(
    table, tableResv, SCHEDULE_START, SCHEDULE_END, totalMin,
    overlays, dayPeriods, isPast, isToday, ds, duration
  ) {
    const row = document.createElement('div');
    row.className = 'tl-row';

    const label = document.createElement('div');
    label.className = 'tl-row-label';
    label.innerHTML = `<span class="tl-row-label-name">${table.name}</span>${
      table.area_name ? `<span class="tl-row-label-area">${table.area_name}</span>` : ''
    }`;
    row.appendChild(label);

    row.appendChild(buildRowContent(
      tableResv, SCHEDULE_START, SCHEDULE_END, totalMin,
      overlays, dayPeriods, isPast, isToday, ds, duration, table
    ));
    return row;
  }

  // ── Enojni row (brez miz) ────────────────────────────────────
  function buildSingleRow(
    allForLayout, SCHEDULE_START, SCHEDULE_END, totalMin,
    overlays, dayPeriods, isPast, isToday, ds, duration
  ) {
    const row = document.createElement('div');
    row.className = 'tl-row tl-row-single';
    row.appendChild(buildRowContent(
      allForLayout, SCHEDULE_START, SCHEDULE_END, totalMin,
      overlays, dayPeriods, isPast, isToday, ds, duration, null
    ));
    return row;
  }

  // ── Barva kartice glede na status/čas ───────────────────────
  function cardColor(reservation, isPast, isToday) {
    if (reservation.status === 'pending' || reservation._pendingGroup) return '#EF4444';
    if (isPast) return '#9CA3AF';
    if (isToday) {
      const now    = new Date();
      const nowMin = now.getHours() * 60 + now.getMinutes();
      const start  = timeToMin(reservation.reservation_time);
      const end    = start + (parseInt(reservation.reservation_duration) || 60);
      if (nowMin >= end)    return '#9CA3AF'; // pretekla (danes)
      if (nowMin >= start)  return '#F59E0B'; // trenutna
    }
    return '#2563eb'; // prihodnja
  }

  // ── Rezervacijska kartica (horizontalna) ─────────────────────
  function buildCard(reservation, SCHEDULE_START, totalMin, lPct, wPct, isPast, isToday, subRow = 0) {
    const startMin = timeToMin(reservation.reservation_time);
    const dur      = parseInt(reservation.reservation_duration) || 60;
    const topPx    = subRow * ROW_HEIGHT + 3;
    const heightPx = ROW_HEIGHT - 8;

    const isPending  = reservation.status === 'pending' || !!reservation._pendingGroup;
    const groupCount = reservation._groupCount || 0;
    const color = cardColor(reservation, isPast, isToday);

    const card = document.createElement('div');
    card.className = 'reservation-card' + (isPending ? ' res-pending' : '');
    card.style.cssText = `
      position:absolute;
      top:${topPx}px;
      height:${heightPx}px;
      left:calc(${lPct(startMin).toFixed(3)}% + 2px);
      width:calc(${Math.max(0.3, wPct(dur)).toFixed(3)}% - 4px);
      border-left-color:${color};
      overflow:hidden;
      z-index:2;
      ${isPending ? 'background:#FEF2F2;border-left-style:dashed;' : ''}
      ${isPast ? 'opacity:.7;' : ''}
    `;

    const nameEl = document.createElement('div');
    nameEl.className = 'card-name';
    nameEl.textContent = (isPending && groupCount > 1)
      ? `⏳ ${groupCount} čakajočih`
      : ((isPending ? '⏳ ' : '') + reservation.guest_name);
    card.appendChild(nameEl);

    const meta = document.createElement('div');
    meta.className = 'card-meta';

    const timeEl = document.createElement('span');
    timeEl.className = 'card-time';
    timeEl.textContent = minToTime(startMin);
    meta.appendChild(timeEl);

    if (!isPending || groupCount <= 1) {
      const guestEl = document.createElement('span');
      guestEl.className = 'card-guests';
      guestEl.innerHTML = `<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>${reservation.guest_count}`;
      meta.appendChild(guestEl);
    }

    card.appendChild(meta);

    card.addEventListener('click', e => {
      e.stopPropagation();
      if (isPending && reservation._pendingGroup) PendingModal.open(reservation._pendingGroup);
      else ReservationModal.open('view', reservation);
    });

    return card;
  }

  // ── Merged miza placeholder ──────────────────────────────────
  function buildMergedPlaceholder(reservation, firstTableName, SCHEDULE_START, totalMin, lPct, wPct, isPast, subRow = 0) {
    const startMin = timeToMin(reservation.reservation_time);
    const dur      = parseInt(reservation.reservation_duration) || 60;

    const ph = document.createElement('div');
    ph.className = 'tl-merged-placeholder';
    ph.style.cssText = `
      top:${subRow * ROW_HEIGHT + 3}px;
      height:${ROW_HEIGHT - 8}px;
      left:calc(${lPct(startMin).toFixed(3)}% + 2px);
      width:calc(${Math.max(0.3, wPct(dur)).toFixed(3)}% - 4px);
      ${isPast ? 'opacity:.75;' : ''}
    `;
    ph.textContent = `Združena miza: ${firstTableName}`;

    ph.addEventListener('click', e => {
      e.stopPropagation();
      ReservationModal.open('view', reservation);
    });

    return ph;
  }

  // ── Now line posodabljanje ───────────────────────────────────
  function updateNowLine(wrap, SCHEDULE_START, totalMin) {
    const now    = new Date();
    const nowMin = now.getHours() * 60 + now.getMinutes();
    const SCHEDULE_END = SCHEDULE_START + totalMin;
    const visible = nowMin >= SCHEDULE_START && nowMin <= SCHEDULE_END;
    const pct = visible ? ((nowMin - SCHEDULE_START) / totalMin * 100).toFixed(3) + '%' : '0%';

    wrap.querySelectorAll('.tl-now-line').forEach(el => {
      el.style.left    = pct;
      el.style.display = visible ? '' : 'none';
    });

    const badge = wrap.querySelector('#tl-now-badge');
    if (badge) {
      badge.style.left    = pct;
      badge.textContent   = minToTime(nowMin);
      badge.style.display = visible ? '' : 'none';
    }
  }

  function scrollToNow(wrap, SCHEDULE_START, totalMin) {
    const now    = new Date();
    const nowMin = now.getHours() * 60 + now.getMinutes();
    const SCHEDULE_END = SCHEDULE_START + totalMin;
    if (nowMin < SCHEDULE_START || nowMin > SCHEDULE_END) return;
    const pct = (nowMin - SCHEDULE_START) / totalMin;
    wrap.scrollLeft = Math.max(0, wrap.scrollWidth * pct - wrap.clientWidth / 2);
  }

  // ── Glavni render ────────────────────────────────────────────
  function render(date, reservations, duration, allRestaurants, bounds, overlays) {
    duration = Math.max(15, duration || 60);
    // Vedno prikaži od 08:00 do 23:00 (ali širše če urnik sega izven)
    const SCHEDULE_START = Math.min(bounds ? bounds.start : 480, 480);
    const SCHEDULE_END   = Math.max(bounds ? bounds.end   : 1380, 1380);
    const totalMin = SCHEDULE_END - SCHEDULE_START;

    // Glava panela
    const labelEl = document.getElementById('schedule-date-label');
    if (labelEl) labelEl.textContent = formatDateLabel(date);
    const visibleResv = reservations.filter(r => r.status !== 'cancelled');
    const totalGuests = visibleResv.reduce((s, r) => s + parseInt(r.guest_count || 0), 0);
    const sc = document.getElementById('stat-count');
    const sg = document.getElementById('stat-guests');
    if (sc) sc.textContent = visibleResv.length;
    if (sg) sg.textContent = totalGuests;

    const body = document.getElementById('schedule-body');
    if (!body) return;
    body.innerHTML = '';

    if (nowTimer) { clearInterval(nowTimer); nowTimer = null; }

    const ds      = dateToStr(date);
    const isPast  = ds < APP_STATE.today;
    const isToday = ds === APP_STATE.today;

    // Aktivna restavracija
    const restId = window.App ? App.getState().restaurantId : null;
    const rest = APP_STATE.restaurants.find(r => r.id === restId) || APP_STATE.restaurants[0];
    const hasTables = !!(rest && rest.has_tables
      && Array.isArray(rest.tables) && rest.tables.length > 0);

    // Vsi odprti termini za ta dan (podpora za multi-period)
    const dayIdx = (date.getDay() + 6) % 7; // 0=Pon
    const dayPeriods = rest
      ? (rest.day_schedules || [])
          .filter(d => d.day_of_week === dayIdx && d.is_open)
          .sort((a, b) => a.start_time - b.start_time)
      : [];

    const allForLayout = groupPending(reservations);

    // ── Wrapper ──────────────────────────────────────────────
    const wrap = document.createElement('div');
    wrap.className = hasTables ? 'tl-wrap' : 'tl-wrap tl-wrap--no-label';

    // ── Časovna glava ────────────────────────────────────────
    const timeHeader = document.createElement('div');
    timeHeader.className = 'tl-time-header';

    const corner = document.createElement('div');
    corner.className = 'tl-corner';
    timeHeader.appendChild(corner);

    const timeAxis = document.createElement('div');
    timeAxis.className = 'tl-time-axis';

    const lPct = absMin => Math.max(0, Math.min((absMin - SCHEDULE_START) / totalMin * 100, 100));

    const firstHour = Math.ceil(SCHEDULE_START / 60);
    const lastHour  = Math.floor(SCHEDULE_END / 60);
    for (let h = firstHour; h <= lastHour; h++) {
      const lbl = document.createElement('span');
      lbl.className = 'tl-hour-label';
      lbl.style.left = lPct(h * 60).toFixed(3) + '%';
      lbl.textContent = `${String(h).padStart(2, '0')}:00`;
      timeAxis.appendChild(lbl);
    }

    const nowBadge = document.createElement('span');
    nowBadge.className = 'tl-now-badge';
    nowBadge.id = 'tl-now-badge';
    nowBadge.style.display = 'none';
    timeAxis.appendChild(nowBadge);

    timeHeader.appendChild(timeAxis);
    wrap.appendChild(timeHeader);

    // ── Body ─────────────────────────────────────────────────
    const tlBody = document.createElement('div');
    tlBody.className = 'tl-body';

    if (hasTables) {
      const shownResv = new Set();
      let lastArea = undefined;
      rest.tables.forEach(table => {
        // Ločnica med conami
        if (table.area_name !== lastArea) {
          if (lastArea !== undefined) {
            const sep = document.createElement('div');
            sep.className = 'tl-area-sep';
            tlBody.appendChild(sep);
          }
          lastArea = table.area_name;
        }
        const tableResv = allForLayout.filter(r =>
          Array.isArray(r.table_assignments)
          && r.table_assignments.some(a => a.table_id === table.id)
        );
        tableResv.forEach(r => shownResv.add(r));
        tlBody.appendChild(buildTableRow(
          table, tableResv, SCHEDULE_START, SCHEDULE_END, totalMin,
          overlays, dayPeriods, isPast, isToday, ds, duration
        ));
      });
      // Rezervacije brez dodeljene mize
      const unassigned = allForLayout.filter(r => !shownResv.has(r));
      if (unassigned.length > 0) {
        const urow = document.createElement('div');
        urow.className = 'tl-row';
        const ulabel = document.createElement('div');
        ulabel.className = 'tl-row-label';
        ulabel.innerHTML = '<span class="tl-row-label-name" style="color:var(--color-muted);font-style:italic">Brez mize</span>';
        urow.appendChild(ulabel);
        urow.appendChild(buildRowContent(
          unassigned, SCHEDULE_START, SCHEDULE_END, totalMin,
          overlays, dayPeriods, isPast, isToday, ds, duration, null
        ));
        tlBody.appendChild(urow);
      }
    } else {
      tlBody.appendChild(buildSingleRow(
        allForLayout, SCHEDULE_START, SCHEDULE_END, totalMin,
        overlays, dayPeriods, isPast, isToday, ds, duration
      ));
    }

    wrap.appendChild(tlBody);

    // Legenda
    const legend = document.createElement('div');
    legend.className = 'tl-legend';
    [
      { color: '#9CA3AF', label: 'Pretekle rezervacije' },
      { color: '#F59E0B', label: 'Trenutne rezervacije'  },
      { color: '#2563eb', label: 'Naslednje rezervacije' },
      { color: '#EF4444', label: 'Rezervacije za potrditev' },
    ].forEach(({ color, label }) => {
      const item = document.createElement('span');
      item.className = 'tl-legend-item';
      item.innerHTML = `<span class="tl-legend-dot" style="background:${color}"></span>${label}`;
      legend.appendChild(item);
    });
    wrap.appendChild(legend);

    body.appendChild(wrap);

    // Prazen dan
    if (reservations.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'schedule-empty';
      empty.innerHTML = `
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>
        </svg>
        <p>${window.t('upnext.no_reservations_for_day').replace(/\.$/, '')}</p>
        ${!isPast ? `<small style="color:var(--color-muted);font-size:.75rem">${window.t('schedule.click_to_add_hint')}</small>` : ''}
      `;
      body.appendChild(empty);
    }

    // Now line + timer
    if (isToday) {
      updateNowLine(wrap, SCHEDULE_START, totalMin);
      nowTimer = setInterval(() => updateNowLine(wrap, SCHEDULE_START, totalMin), 60000);
      requestAnimationFrame(() => scrollToNow(wrap, SCHEDULE_START, totalMin));
    }
  }

  return { render };
})();
