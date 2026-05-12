<?php
/**
 * Admin stran: Čakajoče rezervacije – masovno potrjevanje / zavrnitev.
 */
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/lang.php';

if (!is_logged_in()) {
    redirect_to_login();
}
if ($_SESSION['role'] === 'superadmin') {
    header('Location: ' . BASE_PATH . '/pages/superadmin.php');
    exit;
}

$pdo = getDB();
refresh_subscription_session($pdo);

if (!empty($_SESSION['payment_failed'])) {
    require_once '../includes/payment_failed_block.php';
    exit;
}

$isAdmin  = $_SESSION['role'] === 'admin';
$fullName = $_SESSION['full_name'];

$restaurants = [];
if ($isAdmin) {
    $stmt = $pdo->prepare("
        SELECT r.id, r.name, r.color FROM restaurants r
        JOIN restaurant_admins ra ON r.id = ra.restaurant_id
        WHERE ra.user_id = ? AND r.is_active = 1 ORDER BY r.name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $restaurants = $stmt->fetchAll();
} elseif (!empty($_SESSION['restaurant_id'])) {
    $stmt = $pdo->prepare("SELECT id, name, color FROM restaurants WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['restaurant_id']]);
    $restaurants = $stmt->fetchAll();
}

$pendingCount = 0;
if ($isAdmin) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM reservations r
        JOIN restaurant_admins ra ON r.restaurant_id = ra.restaurant_id
        WHERE ra.user_id = ? AND r.status = 'pending'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $pendingCount = (int) $stmt->fetchColumn();
} elseif (!empty($_SESSION['restaurant_id'])) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE restaurant_id = ? AND status = 'pending'");
    $stmt->execute([$_SESSION['restaurant_id']]);
    $pendingCount = (int) $stmt->fetchColumn();
}

$activeRestId = 0;
if (!empty($_SESSION['restaurant_id'])) {
    foreach ($restaurants as $r) {
        if ((int)$r['id'] === (int)$_SESSION['restaurant_id']) {
            $activeRestId = (int)$r['id'];
            break;
        }
    }
}
if (!$activeRestId && !empty($restaurants)) {
    $activeRestId = (int)$restaurants[0]['id'];
}
?>
<?php
$pageTitle = t('pending.title');
$extraCss  = ['main.css?v=4', 'modal.css?v=3', 'design.css?v=2'];
require_once '../includes/html_head.php';
?>
<body>

<div id="rz-app" class="rz-app">
<?php require_once '../includes/sidebar.php'; ?>
<main class="rz-main">

<?php require_once '../includes/trial_banner.php'; ?>

<?php
    $topbarTitle    = t('pending.title');
    $topbarSubtitle = t('pending.subtitle');
    $topbarActions  = null;
    require_once '../includes/topbar.php';
?>

<div style="max-width:860px;margin:0 auto;padding:0 0 48px">

    <!-- Akcijska vrstica – zgoraj -->
    <div id="bulk-bar-top" class="rz-card" style="display:none;margin-bottom:12px;padding:12px 16px;flex-direction:row;align-items:center;gap:12px;flex-wrap:wrap">
        <label class="pnd-check-all-label" style="flex:1;min-width:0">
            <input type="checkbox" id="check-all-top" class="pnd-chk-all">
            <span id="selected-lbl-top" style="font-size:13px;font-weight:500;color:var(--ink-mute)"><?= t('pending.select_all') ?></span>
        </label>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="rz-btn rz-btn-sm" id="btn-approve-sel-top"  style="background:var(--success);color:#fff;border-color:var(--success)"><?= t('pending.approve_selected') ?></button>
            <button class="rz-btn rz-btn-sm rz-btn-danger" id="btn-reject-sel-top"><?= t('pending.reject_selected') ?></button>
            <button class="rz-btn rz-btn-sm rz-btn-ghost" id="btn-approve-all-top"  style="color:var(--success)"><?= t('pending.approve_all') ?></button>
            <button class="rz-btn rz-btn-sm rz-btn-ghost rz-btn-danger" id="btn-reject-all-top"><?= t('pending.reject_all') ?></button>
        </div>
    </div>

    <!-- Seznam -->
    <div id="pnd-list">
        <div id="pnd-loading" style="text-align:center;padding:64px 0;color:var(--ink-mute);font-size:14px">
            <?= t('pending.loading') ?>
        </div>
    </div>

    <!-- Akcijska vrstica – spodaj -->
    <div id="bulk-bar-bottom" class="rz-card" style="display:none;margin-top:12px;padding:12px 16px;flex-direction:row;align-items:center;gap:12px;flex-wrap:wrap">
        <label class="pnd-check-all-label" style="flex:1;min-width:0">
            <input type="checkbox" id="check-all-bottom" class="pnd-chk-all">
            <span id="selected-lbl-bottom" style="font-size:13px;font-weight:500;color:var(--ink-mute)"><?= t('pending.select_all') ?></span>
        </label>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="rz-btn rz-btn-sm" id="btn-approve-sel-bottom"  style="background:var(--success);color:#fff;border-color:var(--success)"><?= t('pending.approve_selected') ?></button>
            <button class="rz-btn rz-btn-sm rz-btn-danger" id="btn-reject-sel-bottom"><?= t('pending.reject_selected') ?></button>
            <button class="rz-btn rz-btn-sm rz-btn-ghost" id="btn-approve-all-bottom"  style="color:var(--success)"><?= t('pending.approve_all') ?></button>
            <button class="rz-btn rz-btn-sm rz-btn-ghost rz-btn-danger" id="btn-reject-all-bottom"><?= t('pending.reject_all') ?></button>
        </div>
    </div>

</div>

<!-- ── Detail modal ──────────────────────────────────────────── -->
<div id="pnd-modal" class="modal-overlay" style="display:none" role="dialog" aria-modal="true">
    <div class="modal-box" style="max-width:460px">
        <div class="modal-header">
            <h2 class="modal-title" id="pndm-title"><?= t('pending.modal_title') ?></h2>
            <button type="button" class="modal-close" id="pndm-close" aria-label="<?= t('pending.modal_close') ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M6 18 18 6"/></svg>
            </button>
        </div>
        <div class="modal-body" id="pndm-body"></div>
        <div class="modal-footer" style="display:flex;gap:10px;padding:16px 24px;border-top:1px solid var(--line)">
            <button type="button" class="rz-btn" id="pndm-approve" style="flex:1;justify-content:center;background:var(--success);color:#fff;border-color:var(--success)"><?= t('pending.modal_approve') ?></button>
            <button type="button" class="rz-btn rz-btn-danger" id="pndm-reject" style="flex:1;justify-content:center"><?= t('pending.modal_reject') ?></button>
            <button type="button" class="rz-btn rz-btn-ghost" id="pndm-cancel"><?= t('pending.modal_close') ?></button>
        </div>
    </div>
</div>

<div id="toast-container"></div>

<style>
.rz-card { display: flex; }
.rz-btn-sm { padding: 5px 11px; font-size: 12px; }
.pnd-check-all-label { display: flex; align-items: center; gap: 8px; cursor: pointer; user-select: none; }
.pnd-check-all-label input { width: 15px; height: 15px; accent-color: var(--accent); cursor: pointer; }

.pnd-row {
    display: flex;
    align-items: flex-start;
    gap: 13px;
    background: var(--bg-elev);
    border: 1px solid var(--line);
    border-radius: var(--card-radius);
    padding: 14px 16px;
    margin-bottom: 8px;
    transition: box-shadow .15s, border-color .15s;
    cursor: pointer;
}
.pnd-row:hover {
    border-color: var(--line-strong);
    box-shadow: var(--shadow-card);
}
.pnd-row.is-sel {
    border-color: var(--accent);
    background: var(--accent-soft);
}
.pnd-chk {
    flex: none;
    margin-top: 3px;
    width: 16px;
    height: 16px;
    accent-color: var(--accent);
    cursor: pointer;
}
.pnd-info { flex: 1; min-width: 0; }
.pnd-when {
    font-size: 12px;
    color: var(--ink-mute);
    margin-bottom: 2px;
    font-weight: 500;
    letter-spacing: .01em;
}
.pnd-guest {
    font-size: 15px;
    font-weight: 700;
    color: var(--ink);
    margin-bottom: 4px;
    letter-spacing: -.01em;
}
.pnd-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    font-size: 12px;
    color: var(--ink-mute);
}
.pnd-rest {
    font-size: 11px;
    color: var(--ink-mute);
    margin-top: 3px;
}
.pnd-notes {
    font-size: 12px;
    color: var(--ink-mute);
    font-style: italic;
    margin-top: 5px;
}
.pnd-row-actions {
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex: none;
}

.pnd-empty {
    text-align: center;
    padding: 72px 20px;
    color: var(--ink-mute);
}
.pnd-empty-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: color-mix(in oklab, var(--success) 12%, transparent);
    color: var(--success);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
}
.pnd-empty-title {
    font-weight: 700;
    font-size: 16px;
    color: var(--ink);
    margin-bottom: 6px;
}

/* Modal detail fields */
.pndm-field {
    display: flex;
    gap: 10px;
    padding: 6px 0;
    border-bottom: 1px solid var(--line);
    font-size: 14px;
}
.pndm-field:last-child { border-bottom: 0; }
.pndm-lbl { color: var(--ink-mute); min-width: 96px; flex: none; font-size: 12px; font-weight: 600; letter-spacing: .02em; text-transform: uppercase; padding-top: 1px; }
.pndm-val { color: var(--ink); font-weight: 500; flex: 1; }
</style>

<script>
window.APP_STATE = <?= json_encode([
    'base'         => BASE_PATH,
    'role'         => $_SESSION['role'],
    'restaurantId' => $activeRestId,
    'restaurants'  => $restaurants,
], JSON_UNESCAPED_UNICODE) ?>;
</script>

<script src="<?= BASE_PATH ?>/assets/js/api.js?v=2"></script>
<script src="<?= BASE_PATH ?>/assets/js/rezble-shell.js?v=1"></script>

<script>
(function () {
    "use strict";

    const activeRestId  = APP_STATE.restaurantId || 0;
    let allReservations = [];
    let currentModalId  = null;

    // ── Helpers ──────────────────────────────────────────────

    function esc(str) {
        const d = document.createElement("div");
        d.textContent = str || "";
        return d.innerHTML;
    }

    function fmtDate(ds) {
        if (!ds) return "";
        const days   = [0,1,2,3,4,5,6].map(i => window.t('pending.days.' + i));
        const months = [0,1,2,3,4,5,6,7,8,9,10,11].map(i => window.t('pending.months.' + i));
        const dt = new Date(ds + "T00:00:00");
        return `${days[dt.getDay()]}, ${dt.getDate()}. ${months[dt.getMonth()]}`;
    }

    function fmtTime(ts) { return ts ? ts.substring(0, 5) : ""; }

    function guestLabel(n) {
        n = parseInt(n);
        return n === 1 ? window.t('pending.guest_1') : window.t('pending.guest_n', {n: n});
    }

    function showToast(msg, type) {
        const c = document.getElementById("toast-container");
        if (!c) return;
        const t = document.createElement("div");
        const cls = type === "success" ? "toast-success" : type === "error" ? "toast-error" : "toast-info";
        t.className = `toast ${cls}`;
        t.textContent = msg;
        c.appendChild(t);
        setTimeout(() => { t.style.transition = "opacity .3s"; t.style.opacity = "0"; setTimeout(() => t.remove(), 310); }, 3200);
    }

    function updateSidebarBadge(count) {
        const link = document.querySelector(".rz-pending");
        const cnt  = document.querySelector(".rz-pending-count");
        if (link) link.style.display = count > 0 ? "" : "none";
        if (cnt)  cnt.textContent = count;
    }

    // ── Selection ────────────────────────────────────────────

    function getSelected() {
        return [...document.querySelectorAll(".pnd-chk:checked")].map(cb => parseInt(cb.dataset.id));
    }

    function syncLabels() {
        const sel   = getSelected().length;
        const total = allReservations.length;
        const txt   = sel > 0 ? window.t('pending.selected_count', {sel: sel, total: total}) : window.t('pending.select_all');
        ["selected-lbl-top","selected-lbl-bottom"].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = txt;
        });
        ["check-all-top","check-all-bottom"].forEach(id => {
            const cb = document.getElementById(id);
            if (!cb) return;
            cb.checked       = sel > 0 && sel === total;
            cb.indeterminate = sel > 0 && sel < total;
        });
    }

    // ── Render ───────────────────────────────────────────────

    function render(list) {
        allReservations = list;
        const container = document.getElementById("pnd-list");
        const barTop    = document.getElementById("bulk-bar-top");
        const barBot    = document.getElementById("bulk-bar-bottom");
        const loading   = document.getElementById("pnd-loading");
        if (loading) loading.remove();

        if (list.length === 0) {
            container.innerHTML = `
                <div class="pnd-empty">
                    <div class="pnd-empty-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m5 12 5 5 9-11"/></svg>
                    </div>
                    <div class="pnd-empty-title">${window.t('pending.empty_title')}</div>
                    <div style="font-size:14px">${window.t('pending.empty_sub')}</div>
                </div>`;
            if (barTop) barTop.style.display = "none";
            if (barBot) barBot.style.display = "none";
            updateSidebarBadge(0);
            return;
        }

        if (barTop) barTop.style.display = "flex";
        if (barBot) barBot.style.display = "flex";
        updateSidebarBadge(list.length);

        container.innerHTML = "";

        list.forEach(r => {
            const row = document.createElement("div");
            row.className = "pnd-row";
            row.id = `prow-${r.id}`;

            const metaParts = [];
            if (r.email) metaParts.push(`<span>📧 ${esc(r.email)}</span>`);
            if (r.phone) metaParts.push(`<span>📞 ${esc(r.phone)}</span>`);

            row.innerHTML = `
                <input type="checkbox" class="pnd-chk" data-id="${r.id}" title="Izberi">
                <div class="pnd-info">
                    <div class="pnd-when">${esc(fmtDate(r.reservation_date))} · ${esc(fmtTime(r.reservation_time))}</div>
                    <div class="pnd-guest">${esc(r.guest_name)} <span style="font-weight:500;color:var(--ink-mute);font-size:13px">· ${esc(guestLabel(r.guest_count))}</span></div>
                    ${metaParts.length ? `<div class="pnd-meta">${metaParts.join("")}</div>` : ""}
                    ${r.notes ? `<div class="pnd-notes">"${esc(r.notes)}"</div>` : ""}
                </div>
                <div class="pnd-row-actions" onclick="event.stopPropagation()">
                    <button class="rz-btn rz-btn-sm" data-action="approve" data-id="${r.id}" style="background:var(--success);color:#fff;border-color:var(--success)">${window.t('pending.modal_approve')}</button>
                    <button class="rz-btn rz-btn-sm rz-btn-danger" data-action="reject" data-id="${r.id}">${window.t('pending.modal_reject')}</button>
                </div>`;

            const cb = row.querySelector(".pnd-chk");
            cb.addEventListener("change", () => {
                row.classList.toggle("is-sel", cb.checked);
                syncLabels();
            });
            cb.addEventListener("click", e => e.stopPropagation());

            row.querySelectorAll("[data-action]").forEach(btn => {
                btn.addEventListener("click", e => {
                    e.stopPropagation();
                    handleSingle(btn.dataset.action, parseInt(btn.dataset.id), row);
                });
            });

            row.addEventListener("click", e => {
                if (e.target.tagName === "INPUT" || e.target.tagName === "BUTTON") return;
                openModal(parseInt(r.id));
            });

            container.appendChild(row);
        });

        syncLabels();
    }

    // ── API calls ────────────────────────────────────────────

    async function doAction(action, ids) {
        const results = await Promise.allSettled(
            ids.map(id => API.put(`/api/reservations.php?id=${id}&action=${action}`, {}))
        );
        return { failed: results.filter(r => r.status === "rejected").length };
    }

    function removeRow(id) {
        const row = document.getElementById(`prow-${id}`);
        if (!row) return;
        row.style.transition = "opacity .2s, transform .2s";
        row.style.opacity = "0";
        row.style.transform = "translateX(6px)";
        setTimeout(() => {
            row.remove();
            allReservations = allReservations.filter(r => r.id !== id);
            updateSidebarBadge(allReservations.length);
            syncLabels();
            if (allReservations.length === 0) load();
        }, 210);
    }

    // ── Single action ─────────────────────────────────────────

    async function handleSingle(action, id, rowEl) {
        const ckey = action === "approve" ? "pending.confirm_single_approve" : "pending.confirm_single_reject";
        if (!confirm(window.t(ckey))) return;

        const btns = rowEl.querySelectorAll("button");
        btns.forEach(b => b.disabled = true);

        try {
            await API.put(`/api/reservations.php?id=${id}&action=${action}`, {});
            showToast(window.t(action === "approve" ? "pending.toast_approved_one" : "pending.toast_rejected_one"), action === "approve" ? "success" : "info");
            removeRow(id);
        } catch (e) {
            btns.forEach(b => b.disabled = false);
            showToast(e.message || window.t("pending.err_generic"), "error");
        }
    }

    // ── Bulk action ──────────────────────────────────────────

    async function handleBulk(action, ids) {
        if (ids.length === 0) { showToast(window.t("pending.toast_none_selected"), "info"); return; }
        const noun = ids.length === 1 ? window.t('pending.noun_one') : window.t('pending.noun_many', {n: ids.length});
        const ckey = action === "approve" ? "pending.confirm_bulk_approve" : "pending.confirm_bulk_reject";
        if (!confirm(window.t(ckey, {noun: noun}))) return;

        setBtnsDisabled(true);
        const { failed } = await doAction(action, ids);
        setBtnsDisabled(false);

        if (failed > 0) showToast(window.t('pending.toast_failed', {failed: failed, total: ids.length}), "error");
        else {
            const tkey = action === "approve"
                ? (ids.length === 1 ? "pending.toast_approved_one" : "pending.toast_bulk_approved")
                : (ids.length === 1 ? "pending.toast_rejected_one" : "pending.toast_bulk_rejected");
            showToast(window.t(tkey, {n: ids.length}), action === "approve" ? "success" : "info");
        }

        await load();
    }

    async function handleAll(action) {
        const ids = allReservations.map(r => r.id);
        if (!ids.length) return;
        const ckey = action === "approve" ? "pending.confirm_all_approve" : "pending.confirm_all_reject";
        if (!confirm(window.t(ckey, {count: ids.length}))) return;

        setBtnsDisabled(true);
        const { failed } = await doAction(action, ids);
        setBtnsDisabled(false);

        if (failed > 0) showToast(window.t('pending.toast_failed', {failed: failed, total: ids.length}), "error");
        else showToast(window.t(action === "approve" ? "pending.toast_all_approved" : "pending.toast_all_rejected"), action === "approve" ? "success" : "info");

        await load();
    }

    function setBtnsDisabled(on) {
        document.querySelectorAll("#bulk-bar-top button,#bulk-bar-bottom button,.pnd-row-actions button").forEach(b => b.disabled = on);
    }

    // ── Modal ─────────────────────────────────────────────────

    function openModal(id) {
        const r = allReservations.find(x => x.id === id);
        if (!r) return;
        currentModalId = id;

        function field(lbl, val, italic) {
            if (!val) return "";
            return `<div class="pndm-field">
                <span class="pndm-lbl">${lbl}</span>
                <span class="pndm-val"${italic ? ' style="font-style:italic"' : ""}>${val}</span>
            </div>`;
        }

        let body = "";
        body += field(window.t("pending.field_date"), esc(fmtDate(r.reservation_date)) + " · " + esc(fmtTime(r.reservation_time)));
        body += field(window.t("pending.field_guest"), esc(r.guest_name));
        body += field(window.t("pending.field_count"), esc(guestLabel(r.guest_count)));
        body += field(window.t("pending.field_email"), esc(r.email));
        body += field(window.t("pending.field_phone"), esc(r.phone));
        body += field(window.t("pending.field_notes"), esc(r.notes), true);
        body += field(window.t("pending.field_staff"), esc(r.staff_name));
        (r.field_values || []).forEach(fv => {
            const val = fv.value === "1" ? window.t("pending.bool_yes") : fv.value === "0" ? window.t("pending.bool_no") : fv.value;
            body += field(esc(fv.label), esc(val));
        });

        document.getElementById("pndm-title").textContent = r.guest_name || window.t("pending.modal_title");
        document.getElementById("pndm-body").innerHTML = body;

        const modal = document.getElementById("pnd-modal");
        modal.style.display = "flex";
        document.getElementById("pndm-approve").disabled = false;
        document.getElementById("pndm-reject").disabled  = false;
        modal.addEventListener("click", bgClose);
    }

    function closeModal() {
        const modal = document.getElementById("pnd-modal");
        modal.style.display = "none";
        modal.removeEventListener("click", bgClose);
        currentModalId = null;
    }

    function bgClose(e) {
        if (e.target === document.getElementById("pnd-modal")) closeModal();
    }

    async function modalAction(action) {
        if (!currentModalId) return;
        const ckey = action === "approve" ? "pending.confirm_single_approve" : "pending.confirm_single_reject";
        if (!confirm(window.t(ckey))) return;

        const id = currentModalId;
        document.getElementById("pndm-approve").disabled = true;
        document.getElementById("pndm-reject").disabled  = true;

        try {
            await API.put(`/api/reservations.php?id=${id}&action=${action}`, {});
            closeModal();
            showToast(window.t(action === "approve" ? "pending.toast_approved_one" : "pending.toast_rejected_one"), action === "approve" ? "success" : "info");
            removeRow(id);
        } catch (e) {
            document.getElementById("pndm-approve").disabled = false;
            document.getElementById("pndm-reject").disabled  = false;
            showToast(e.message || window.t("pending.err_generic"), "error");
        }
    }

    // ── Event binding ────────────────────────────────────────

    function bindBar(suffix) {
        document.getElementById(`btn-approve-sel-${suffix}`).addEventListener("click", () => handleBulk("approve", getSelected()));
        document.getElementById(`btn-reject-sel-${suffix}`).addEventListener("click",  () => handleBulk("reject",  getSelected()));
        document.getElementById(`btn-approve-all-${suffix}`).addEventListener("click", () => handleAll("approve"));
        document.getElementById(`btn-reject-all-${suffix}`).addEventListener("click",  () => handleAll("reject"));

        document.getElementById(`check-all-${suffix}`).addEventListener("change", function () {
            const on = this.checked;
            document.querySelectorAll(".pnd-chk").forEach(cb => {
                cb.checked = on;
                const row = document.getElementById(`prow-${cb.dataset.id}`);
                if (row) row.classList.toggle("is-sel", on);
            });
            const other = suffix === "top" ? "check-all-bottom" : "check-all-top";
            const otherCb = document.getElementById(other);
            if (otherCb) { otherCb.checked = on; otherCb.indeterminate = false; }
            syncLabels();
        });
    }

    bindBar("top");
    bindBar("bottom");

    document.getElementById("pndm-close").addEventListener("click",   closeModal);
    document.getElementById("pndm-cancel").addEventListener("click",  closeModal);
    document.getElementById("pndm-approve").addEventListener("click", () => modalAction("approve"));
    document.getElementById("pndm-reject").addEventListener("click",  () => modalAction("reject"));
    document.addEventListener("keydown", e => { if (e.key === "Escape") closeModal(); });

    // ── Load ─────────────────────────────────────────────────

    async function load() {
        try {
            const url  = `/api/reservations.php?pending=1${activeRestId ? '&restaurant_id=' + activeRestId : ''}`;
            const list = await API.get(url);
            render(list || []);
        } catch (e) {
            const el = document.getElementById("pnd-loading");
            if (el) el.textContent = window.t("pending.err_load");
        }
    }

    load();
})();
</script>

</main>
</div>
</body>
</html>
