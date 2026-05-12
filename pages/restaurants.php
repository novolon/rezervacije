<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/lang.php';

if (!is_logged_in()) redirect_to_login();
if ($_SESSION['role'] === 'superadmin') {
    header('Location: ' . BASE_PATH . '/pages/superadmin.php'); exit;
}
if ($_SESSION['role'] !== 'admin') {
    header('Location: ' . BASE_PATH . '/pages/main.php'); exit;
}

$pdo = getDB();
refresh_subscription_session($pdo);

if (!empty($_SESSION['payment_failed'])) {
    require_once '../includes/payment_failed_block.php'; exit;
}

$fullName = $_SESSION['full_name'];
$isAdmin  = true;

// Vse restavracije (aktivne + neaktivne)
$stmt = $pdo->prepare("
    SELECT r.id, r.name, r.color, r.is_active, r.booking_enabled, r.booking_token, r.reservation_duration,
           (SELECT COUNT(*) FROM reservations WHERE restaurant_id = r.id) AS total_reservations
    FROM restaurants r
    JOIN restaurant_admins ra ON r.id = ra.restaurant_id
    WHERE ra.user_id = ?
    ORDER BY r.is_active DESC, r.name
");
$stmt->execute([$_SESSION['user_id']]);
$allRestaurants = $stmt->fetchAll();

// Za sidebar – samo aktivne
$restaurants = array_values(array_filter($allRestaurants, fn($r) => (int)$r['is_active'] === 1));

// Pending count za sidebar
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM reservations r
    JOIN restaurant_admins ra ON r.restaurant_id = ra.restaurant_id
    WHERE ra.user_id = ? AND r.status = 'pending'
");
$stmt->execute([$_SESSION['user_id']]);
$pendingCount = (int)$stmt->fetchColumn();

$autoOpen  = !empty($_GET['add']);
$pageTitle = t('restaurants.page_title');
$extraCss  = ['main.css?v=4', 'modal.css?v=3', 'design.css?v=2'];
require_once '../includes/html_head.php';
?>
<body>
<style>
.rz-rest-page { padding: 0; }
.rz-rest-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
    margin-top: 8px;
}
.rz-rest-card {
    background: var(--bg-elev);
    border: 1px solid var(--line);
    border-radius: var(--card-radius);
    padding: 18px 20px;
    box-shadow: var(--shadow-card);
    display: flex; flex-direction: column;
    transition: border-color .15s, box-shadow .15s, transform .15s;
}
.rz-rest-card:hover { border-color: var(--line-strong); transform: translateY(-1px); }
.rz-rest-card.is-inactive { opacity: .7; }

.rz-rest-card-head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.rz-rest-card-dot { width: 12px; height: 12px; border-radius: 50%; flex: none; border: 2px solid rgba(0,0,0,.08); }
.rz-rest-card-name {
    flex: 1; font-size: 15px; font-weight: 700; color: var(--ink);
    letter-spacing: -.01em; margin: 0;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.rz-rest-card-badge {
    font-size: 10px; font-weight: 700; letter-spacing: .06em;
    padding: 3px 8px; border-radius: 12px; text-transform: uppercase; flex: none;
}
.rz-rest-badge-active   { background: color-mix(in oklab, var(--success, #10B981) 15%, transparent); color: var(--success, #059669); }
.rz-rest-badge-inactive { background: var(--bg-sunken); color: var(--ink-mute); }

.rz-rest-card-meta {
    font-size: 12px; color: var(--ink-mute);
    display: flex; gap: 14px; flex-wrap: wrap;
    margin-bottom: 16px; line-height: 1.5;
}
.rz-rest-card-meta strong { color: var(--ink-soft); font-weight: 600; }
.rz-rest-card-meta .pill-on { color: var(--success, #059669); font-weight: 600; }

.rz-rest-card-actions {
    display: flex; gap: 6px; margin-top: auto;
    padding-top: 12px; border-top: 1px solid var(--line);
}
.rz-rest-card-actions .rz-btn { flex: 1; justify-content: center; }

.rz-rest-add-tile {
    background: transparent;
    border: 2px dashed var(--line-strong);
    border-radius: var(--card-radius);
    padding: 18px 20px; min-height: 178px;
    cursor: pointer;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 10px;
    color: var(--ink-mute); font: 600 13px var(--font), system-ui;
    transition: border-color .15s, color .15s, background .15s;
}
.rz-rest-add-tile:hover {
    border-color: var(--accent);
    color: var(--accent);
    background: color-mix(in oklab, var(--accent) 4%, transparent);
}
.rz-rest-add-tile svg { width: 24px; height: 24px; }

.rz-rest-empty {
    text-align: center; padding: 64px 24px;
    border: 2px dashed var(--line-strong);
    border-radius: var(--card-radius);
    color: var(--ink-mute);
    background: var(--bg-elev);
}
.rz-rest-empty h2 { font-size: 18px; color: var(--ink); margin: 0 0 8px; font-weight: 700; }
.rz-rest-empty p  { margin: 0 0 20px; font-size: 14px; }

.rz-modal-foot {
    display: flex; gap: 8px; justify-content: flex-end;
    padding: 14px 20px; border-top: 1px solid var(--line);
}
.field-error {
    display: none;
    background: color-mix(in oklab, var(--danger, #DC2626) 10%, transparent);
    color: var(--danger, #991B1B);
    padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; font-size: 13px;
}

/* Drawer (override iz rezble.css za boljši form layout) */
.rz-drawer.is-form { display: flex; flex-direction: column; padding: 0; }
.rz-drawer.is-form .rz-dr-head { padding: 22px 24px 14px; margin: 0; border-bottom: 1px solid var(--line); }
.rz-drawer.is-form .rz-dr-eyebrow { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--ink-mute); }
.rz-drawer.is-form .rz-dr-title { font-size: 20px; font-weight: 700; margin: 4px 0 0; letter-spacing: -.015em; color: var(--ink); }
.rz-drawer.is-form .rz-dr-sub { font-size: 12px; color: var(--ink-mute); margin: 6px 0 0; line-height: 1.5; }
.rz-drawer-body { flex: 1; padding: 20px 24px; overflow-y: auto; }
.rz-drawer-foot {
    display: flex; gap: 8px; justify-content: flex-end;
    padding: 14px 20px; border-top: 1px solid var(--line);
    background: var(--bg-elev);
    position: sticky; bottom: 0;
}

.dr-section { margin-bottom: 18px; }
.dr-section-title {
    font-size: 10px; font-weight: 700; letter-spacing: .1em;
    text-transform: uppercase; color: var(--ink-mute);
    margin: 0 0 10px;
}
.dr-field { margin-bottom: 12px; }
.dr-field:last-child { margin-bottom: 0; }
.dr-field label {
    display: block; font-size: 12px; font-weight: 600;
    color: var(--ink-soft); margin-bottom: 5px;
}
.dr-field input[type=text],
.dr-field input[type=email],
.dr-field input[type=tel] {
    width: 100%; padding: 9px 12px;
    border: 1px solid var(--line); border-radius: 8px;
    font-size: 13.5px; font-family: var(--font); color: var(--ink);
    background: var(--bg-elev); outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.dr-field input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px color-mix(in oklab, var(--accent) 14%, transparent);
}
.dr-field input[type=color] {
    width: 64px; height: 42px; padding: 4px;
    border: 1px solid var(--line); border-radius: 8px;
    cursor: pointer; background: var(--bg-elev);
}
.dr-row { display: flex; gap: 12px; align-items: flex-end; }
.dr-row .dr-field { flex: 1; margin-bottom: 0; }
.dr-hint {
    font-size: 12px; color: var(--ink-mute);
    line-height: 1.5; margin: 8px 0 0;
}
</style>

<div id="rz-app" class="rz-app">
<?php require_once '../includes/sidebar.php'; ?>
<main class="rz-main">

<?php require_once '../includes/trial_banner.php'; ?>

<?php
    $topbarTitle    = t('restaurants.page_title');
    $topbarSubtitle = t('restaurants.subtitle');
    ob_start(); ?>
    <button type="button" id="btn-add-restaurant" class="rz-btn rz-btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        <span><?= t('restaurants.add_btn') ?></span>
    </button>
<?php $topbarActions = ob_get_clean(); require_once '../includes/topbar.php'; ?>

<div class="rz-rest-page">

<?php if (empty($allRestaurants)): ?>
    <div class="rz-rest-empty">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:14px;opacity:.5"><path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5Z"/></svg>
        <h2><?= t('restaurants.empty_title') ?></h2>
        <p><?= t('restaurants.empty_desc') ?></p>
        <button class="rz-btn rz-btn-primary rz-btn-lg" id="btn-add-empty" style="max-width:280px;margin:0 auto">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            <span><?= t('restaurants.add_first') ?></span>
        </button>
    </div>
<?php else: ?>
    <div class="rz-rest-grid">
    <?php foreach ($allRestaurants as $r): ?>
        <?php
            $isActiveR = (int)$r['is_active'] === 1;
            $editUrl   = BASE_PATH . '/pages/restaurant-edit.php?id=' . (int)$r['id'];
        ?>
        <div class="rz-rest-card<?= $isActiveR ? '' : ' is-inactive' ?>">
            <div class="rz-rest-card-head">
                <span class="rz-rest-card-dot" style="background:<?= h($r['color']) ?>"></span>
                <h3 class="rz-rest-card-name" title="<?= h($r['name']) ?>"><?= h($r['name']) ?></h3>
                <span class="rz-rest-card-badge <?= $isActiveR ? 'rz-rest-badge-active' : 'rz-rest-badge-inactive' ?>">
                    <?= $isActiveR ? t('restaurants.status_active') : t('restaurants.status_inactive') ?>
                </span>
            </div>
            <div class="rz-rest-card-meta">
                <span><strong><?= (int)$r['reservation_duration'] ?></strong> min</span>
                <span><strong><?= (int)$r['total_reservations'] ?></strong> <?= t('restaurants.reservations') ?></span>
                <?php if ((int)$r['booking_enabled'] === 1): ?>
                    <span class="pill-on"><?= t('restaurants.booking_on') ?></span>
                <?php endif; ?>
            </div>
            <div class="rz-rest-card-actions">
                <a href="<?= h($editUrl) ?>" class="rz-btn">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 20h4l11-11-4-4L4 16v4ZM13 6l4 4"/></svg>
                    <span><?= t('restaurants.edit') ?></span>
                </a>
                <button type="button" class="rz-btn rz-btn-danger" data-del-id="<?= (int)$r['id'] ?>" data-del-name="<?= h($r['name']) ?>">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>
                    <span><?= t('restaurants.delete') ?></span>
                </button>
            </div>
        </div>
    <?php endforeach; ?>
        <button type="button" id="btn-add-tile" class="rz-rest-add-tile">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            <span><?= t('restaurants.add_btn') ?></span>
        </button>
    </div>
<?php endif; ?>

</div>

<div id="toast-container" style="position:fixed;top:20px;right:20px;z-index:9999"></div>

<script>
window.APP_STATE = window.APP_STATE || <?= json_encode([
    'base'   => BASE_PATH,
    'role'   => 'admin',
    'userId' => (int)$_SESSION['user_id'],
], JSON_UNESCAPED_UNICODE) ?>;
const BASE = APP_STATE.base;

const TXT = {
    cancel:        <?= json_encode(t('common.cancel')) ?>,
    createBtn:     <?= json_encode(t('restaurants.create_btn')) ?>,
    creating:      <?= json_encode(t('restaurants.creating')) ?>,
    errNameReq:    <?= json_encode(t('restaurants.err_name_required')) ?>,
    drawerEyebrow: <?= json_encode(t('restaurants.add_btn')) ?>,
    drawerTitle:   <?= json_encode(t('restaurants.modal_title')) ?>,
    drawerSub:     <?= json_encode(t('restaurants.modal_hint')) ?>,
    sectionBasic:  <?= json_encode(t('restaurants.section_basic')) ?>,
    sectionContact:<?= json_encode(t('restaurants.section_contact')) ?>,
    sectionContactOpt: <?= json_encode(t('restaurants.section_contact_optional')) ?>,
    fieldName:     <?= json_encode(t('restaurants.modal_name')) ?>,
    fieldNamePh:   <?= json_encode(t('restaurants.modal_name_placeholder')) ?>,
    fieldColor:    <?= json_encode(t('restaurants.modal_color')) ?>,
    fieldAddress:  <?= json_encode(t('re.field_address')) ?>,
    fieldEmail:    <?= json_encode(t('re.field_contact_email')) ?>,
    fieldPhone:    <?= json_encode(t('re.field_contact_phone')) ?>,
    delTitle:      <?= json_encode(t('restaurants.delete_title')) ?>,
    delConfirm:    <?= json_encode(t('restaurants.delete_confirm')) ?>,
    delWarning:    <?= json_encode(t('restaurants.delete_warning')) ?>,
    delBtn:        <?= json_encode(t('restaurants.delete_btn')) ?>,
    deleting:      <?= json_encode(t('restaurants.deleting')) ?>,
};

function escHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function showToast(msg, type) {
    const el = document.createElement('div');
    el.style.cssText = 'background:' + (type === 'error' ? '#FEE2E2' : '#D1FAE5')
        + ';color:' + (type === 'error' ? '#991B1B' : '#065F46')
        + ';padding:10px 16px;border-radius:8px;margin-bottom:8px;font:600 13px system-ui;box-shadow:0 8px 24px rgba(0,0,0,.12)';
    el.textContent = msg;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => el.remove(), 3500);
}

function buildModal(id, contentHtml) {
    document.getElementById(id)?.remove();
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = id;
    overlay.innerHTML = contentHtml;
    document.body.appendChild(overlay);
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    return overlay;
}

let _drawerEscHandler = null;
function closeAddDrawer() {
    document.getElementById('add-rest-drawer')?.remove();
    if (_drawerEscHandler) {
        document.removeEventListener('keydown', _drawerEscHandler);
        _drawerEscHandler = null;
    }
}

function openAddDrawer() {
    closeAddDrawer();
    const wrap = document.createElement('div');
    wrap.className = 'rz-drawer-wrap';
    wrap.id = 'add-rest-drawer';
    wrap.innerHTML = `
        <div class="rz-drawer is-form" role="dialog" aria-modal="true">
            <div class="rz-dr-head">
                <div>
                    <div class="rz-dr-eyebrow">${escHtml(TXT.drawerEyebrow)}</div>
                    <h2 class="rz-dr-title">${escHtml(TXT.drawerTitle)}</h2>
                    <p class="rz-dr-sub">${escHtml(TXT.drawerSub)}</p>
                </div>
                <button type="button" class="rz-dr-close" id="add-rest-close" aria-label="${escHtml(TXT.cancel)}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="rz-drawer-body">
                <div id="add-rest-error" class="field-error"></div>

                <div class="dr-section">
                    <h3 class="dr-section-title">${escHtml(TXT.sectionBasic)}</h3>
                    <div class="dr-row">
                        <div class="dr-field" style="flex:1">
                            <label>${escHtml(TXT.fieldName)} *</label>
                            <input id="add-rest-name" type="text" placeholder="${escHtml(TXT.fieldNamePh)}" autocomplete="off">
                        </div>
                        <div class="dr-field" style="flex:none">
                            <label>${escHtml(TXT.fieldColor)}</label>
                            <input id="add-rest-color" type="color" value="#2563eb">
                        </div>
                    </div>
                </div>

                <div class="dr-section">
                    <h3 class="dr-section-title">${escHtml(TXT.sectionContact)} <span style="font-weight:500;color:var(--ink-mute);text-transform:none;letter-spacing:0">· ${escHtml(TXT.sectionContactOpt)}</span></h3>
                    <div class="dr-field">
                        <label>${escHtml(TXT.fieldAddress)}</label>
                        <input id="add-rest-address" type="text" placeholder="Tržaška cesta 25, 1000 Ljubljana" maxlength="255" autocomplete="off">
                    </div>
                    <div class="dr-field">
                        <label>${escHtml(TXT.fieldEmail)}</label>
                        <input id="add-rest-email" type="email" placeholder="info@restavracija.si" autocomplete="off">
                    </div>
                    <div class="dr-field">
                        <label>${escHtml(TXT.fieldPhone)}</label>
                        <input id="add-rest-phone" type="tel" placeholder="+386 1 234 56 78" autocomplete="off">
                    </div>
                </div>
            </div>
            <div class="rz-drawer-foot">
                <button type="button" class="rz-btn" id="add-rest-cancel">${escHtml(TXT.cancel)}</button>
                <button type="button" class="rz-btn rz-btn-primary" id="add-rest-submit">${escHtml(TXT.createBtn)}</button>
            </div>
        </div>
    `;
    document.body.appendChild(wrap);

    wrap.addEventListener('click', e => { if (e.target === wrap) closeAddDrawer(); });
    document.getElementById('add-rest-close').addEventListener('click', closeAddDrawer);
    document.getElementById('add-rest-cancel').addEventListener('click', closeAddDrawer);
    document.getElementById('add-rest-submit').addEventListener('click', submitAdd);
    document.getElementById('add-rest-name').addEventListener('keydown', e => { if (e.key === 'Enter') submitAdd(); });

    _drawerEscHandler = e => { if (e.key === 'Escape') closeAddDrawer(); };
    document.addEventListener('keydown', _drawerEscHandler);

    setTimeout(() => document.getElementById('add-rest-name')?.focus(), 50);
}

async function submitAdd() {
    const name    = document.getElementById('add-rest-name').value.trim();
    const color   = document.getElementById('add-rest-color').value;
    const address = document.getElementById('add-rest-address').value.trim();
    const email   = document.getElementById('add-rest-email').value.trim();
    const phone   = document.getElementById('add-rest-phone').value.trim();
    const errBox  = document.getElementById('add-rest-error');
    errBox.style.display = 'none';
    if (!name) {
        errBox.textContent = TXT.errNameReq;
        errBox.style.display = 'block';
        document.getElementById('add-rest-name')?.focus();
        return;
    }
    const btn = document.getElementById('add-rest-submit');
    btn.disabled = true;
    btn.textContent = TXT.creating;
    try {
        const res = await fetch(BASE + '/api/restaurants.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name, color,
                address:       address || null,
                contact_email: email   || null,
                contact_phone: phone   || null,
            }),
        });
        const j = await res.json();
        if (!j.success) throw new Error(j.error || 'Error');
        location.href = BASE + '/pages/restaurant-edit.php?id=' + j.data.id;
    } catch (e) {
        errBox.textContent = e.message;
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.textContent = TXT.createBtn;
    }
}

function openDeleteModal(id, name) {
    buildModal('del-rest-modal', `
        <div class="modal-box" style="max-width:440px">
            <div class="modal-header">
                <div class="modal-title">${escHtml(TXT.delTitle)}</div>
                <button type="button" class="modal-close" onclick="document.getElementById('del-rest-modal').remove()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <p style="margin:0 0 12px;font-size:14px;color:var(--ink);line-height:1.5">${escHtml(TXT.delConfirm)} <strong>${escHtml(name)}</strong>?</p>
                <p style="margin:0;font-size:12px;color:var(--danger,#991B1B);background:color-mix(in oklab,var(--danger,#DC2626) 8%,transparent);padding:10px 12px;border-radius:8px;line-height:1.5">${escHtml(TXT.delWarning)}</p>
            </div>
            <div class="rz-modal-foot">
                <button type="button" class="rz-btn" onclick="document.getElementById('del-rest-modal').remove()">${escHtml(TXT.cancel)}</button>
                <button type="button" class="rz-btn rz-btn-danger" id="del-rest-submit">${escHtml(TXT.delBtn)}</button>
            </div>
        </div>
    `);
    document.getElementById('del-rest-submit').addEventListener('click', () => doDelete(id));
}

async function doDelete(id) {
    const btn = document.getElementById('del-rest-submit');
    btn.disabled = true;
    btn.textContent = TXT.deleting;
    try {
        const res = await fetch(BASE + '/api/restaurants.php?id=' + id + '&force=1', {
            method: 'DELETE', credentials: 'same-origin',
        });
        const j = await res.json();
        if (!j.success) throw new Error(j.error || 'Error');
        location.reload();
    } catch (e) {
        showToast(e.message, 'error');
        btn.disabled = false;
        btn.textContent = TXT.delBtn;
    }
}

document.getElementById('btn-add-restaurant')?.addEventListener('click', openAddDrawer);
document.getElementById('btn-add-tile')?.addEventListener('click', openAddDrawer);
document.getElementById('btn-add-empty')?.addEventListener('click', openAddDrawer);
document.querySelectorAll('[data-del-id]').forEach(btn => {
    btn.addEventListener('click', () => openDeleteModal(parseInt(btn.dataset.delId), btn.dataset.delName));
});

<?php if ($autoOpen): ?>
openAddDrawer();
<?php endif; ?>
</script>

<script src="<?= BASE_PATH ?>/assets/js/rezble-shell.js?v=2"></script>

</main>
</div>
</body>
</html>
