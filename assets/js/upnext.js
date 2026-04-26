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
    if (mins < 60) return `čez ${mins} min`;
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return m > 0 ? `čez ${h}h ${m}m` : `čez ${h}h`;
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

    const DAYS_SHORT = ["ned", "pon", "tor", "sre", "čet", "pet", "sob"];
    const MONTHS_GEN = [
      "jan",
      "feb",
      "mar",
      "apr",
      "maj",
      "jun",
      "jul",
      "avg",
      "sep",
      "okt",
      "nov",
      "dec",
    ];
    const dayLabel = `${DAYS_SHORT[currentDate.getDay()].toUpperCase()} · ${currentDate.getDate()}. ${MONTHS_GEN[currentDate.getMonth()]}`;

    if (eyebrow)
      eyebrow.textContent = isToday ? "DANES · DO KONCA DNE" : dayLabel;

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
        countEl.textContent = `${upcoming.length} rezervacij · ${totalGuests} gostov`;
      } else {
        countEl.textContent = "";
      }
    }

    list.innerHTML = "";

    if (!upcoming.length) {
      const empty = document.createElement("div");
      empty.className = "rz-upnext-empty";
      empty.textContent = isToday
        ? "Za danes ni več prihajajočih rezervacij."
        : ds < APP_STATE.today
          ? "Ni rezervacij za ta dan."
          : "Za ta dan ni rezervacij.";
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
        else inSpan.textContent = "zdaj";
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
