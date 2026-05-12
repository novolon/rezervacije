/**
 * UpNext – "Kdo prihaja": rezervacije za izbrani dan.
 */
const UpNext = (() => {
  function dateStr(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  }

  function timeToMin(t) {
    const p = (t || "00:00").split(":");
    return parseInt(p[0]) * 60 + parseInt(p[1]);
  }

  function formatMinsUntil(mins) {
    if (mins < 60) return window.t("upnext.in_minutes", { min: mins });
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return m > 0
      ? window.t("upnext.in_hours_minutes", { h: h, m: m })
      : window.t("upnext.in_hours", { h: h });
  }

  function render(reservations, currentDate) {
    const list = document.getElementById("upnext-list");
    const eyebrow = document.getElementById("upnext-eyebrow");
    const countEl = document.getElementById("upnext-count");
    if (!list) return;

    const ds = dateStr(currentDate);
    const isToday = ds === APP_STATE.today;

    const now = new Date();
    const nowMin = now.getHours() * 60 + now.getMinutes();

    // Mon-first 0..6 day index (Date.getDay() returns 0=Sun..6=Sat)
    const dayIdxMon = (currentDate.getDay() + 6) % 7;
    const dayShort = window.t("days_short3." + dayIdxMon).toUpperCase();
    const monthShort = window.t("months_short." + currentDate.getMonth());
    const dayLabel = `${dayShort} · ${currentDate.getDate()}. ${monthShort}`;

    if (eyebrow)
      eyebrow.textContent = isToday ? window.t("upnext.eyebrow_today") : dayLabel;

    // Danes: skrij pretekle (končane, ki niso arrived); ostali dnevi: vse
    const upcoming = (reservations || [])
      .filter((r) => {
        if (!isToday) return true;
        return timeToMin(r.reservation_time) >= nowMin;
      })
      .slice()
      .sort((a, b) =>
        (a.reservation_time || "").localeCompare(b.reservation_time || ""),
      );

    if (countEl) {
      if (upcoming.length > 0) {
        const totalGuests = upcoming.reduce(
          (s, r) => s + (parseInt(r.guest_count) || 0),
          0,
        );
        countEl.textContent = window.t("upnext.count_summary", {
          count: upcoming.length,
          guests: totalGuests,
        });
      } else {
        countEl.textContent = "";
      }
    }

    list.innerHTML = "";

    if (!upcoming.length) {
      const empty = document.createElement("div");
      empty.className = "rz-upnext-empty";
      empty.textContent = isToday
        ? window.t("upnext.no_more_today")
        : window.t("upnext.no_reservations_for_day");
      list.appendChild(empty);
      return;
    }

    upcoming.forEach((r) => {
      const resMin = timeToMin(r.reservation_time);
      const minsUntil = resMin - nowMin;
      const isPending = r.status === "pending";
      const isArrived = r.status === "arrived" || !!r.arrived_at;

      const row = document.createElement("button");
      let rowCls = "rz-upn-row";
      if (isPending) rowCls += " rz-upn-pending";
      if (isArrived) rowCls += " rz-upn-arrived";
      row.className = rowCls;

      // Čas
      const timeDiv = document.createElement("div");
      timeDiv.className = "rz-upn-time";

      const hhmm = document.createElement("span");
      hhmm.className = "rz-upn-hhmm";
      hhmm.textContent = (r.reservation_time || "").slice(0, 5);
      timeDiv.appendChild(hhmm);

      if (isToday) {
        const inSpan = document.createElement("span");
        inSpan.className = "rz-upn-in";
        if (minsUntil > 0) inSpan.textContent = formatMinsUntil(minsUntil);
        else inSpan.textContent = window.t("upnext.now");
        timeDiv.appendChild(inSpan);
      }
      row.appendChild(timeDiv);

      // Info
      const infoDiv = document.createElement("div");

      const nameEl = document.createElement("div");
      nameEl.className = "rz-upn-name";
      nameEl.textContent = r.guest_name || "—";
      infoDiv.appendChild(nameEl);

      const metaEl = document.createElement("div");
      metaEl.className = "rz-upn-meta";
      const tables =
        (r.table_assignments || [])
          .map((t) => t.table_name)
          .filter(Boolean)
          .join(", ") || "—";
      const notePart = r.notes ? " · " + r.notes : "";
      metaEl.textContent = `${r.guest_count || 0} gostov · ${tables}${notePart}`;
      infoDiv.appendChild(metaEl);

      row.appendChild(infoDiv);

      // Tag
      const tag = document.createElement("span");
      tag.className = "rz-upn-tag";
      tag.textContent = isPending ? "ČAKA" : isArrived ? "PRIŠEL" : "POTRJENO";
      row.appendChild(tag);

      row.addEventListener("click", () => {
        if (isPending) {
          PendingModal.open([r]);
        } else {
          ReservationModal.open("view", r);
        }
      });

      list.appendChild(row);
    });
  }

  return { render };
})();
