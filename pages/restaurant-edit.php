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

$restId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$restId || !admin_owns_restaurant($pdo, $_SESSION, $restId)) {
    header('Location: ' . BASE_PATH . '/pages/restaurants.php'); exit;
}

$stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = ?");
$stmt->execute([$restId]);
$rest = $stmt->fetch();
if (!$rest) { header('Location: ' . BASE_PATH . '/pages/restaurants.php'); exit; }

$fullName  = $_SESSION['full_name'];
$activeTab = $_GET['tab'] ?? 'splosno';
$hasSurvey      = user_has_feature($pdo, (int)$_SESSION['user_id'], 'survey');
$hasSurveyEdit  = user_has_feature($pdo, (int)$_SESSION['user_id'], 'survey_edit');
$hasTableMgmt   = user_has_feature($pdo, (int)$_SESSION['user_id'], 'table_management');
$hasCustomLogo  = user_has_feature($pdo, (int)$_SESSION['user_id'], 'custom_logo');
$hasCustomColors= user_has_feature($pdo, (int)$_SESSION['user_id'], 'custom_colors');
$hasHideBranding= user_has_feature($pdo, (int)$_SESSION['user_id'], 'hide_branding');
$hasCustomEmail = user_has_feature($pdo, (int)$_SESSION['user_id'], 'custom_from_email');
$hasBrandingTab = $hasCustomLogo || $hasCustomColors || $hasHideBranding || $hasCustomEmail;

$isAdmin = true;
$stmt2 = $pdo->prepare("
    SELECT r.id, r.name, r.color FROM restaurants r
    JOIN restaurant_admins ra ON r.id = ra.restaurant_id
    WHERE ra.user_id = ? AND r.is_active = 1 ORDER BY r.name
");
$stmt2->execute([$_SESSION['user_id']]);
$restaurants = $stmt2->fetchAll();

$stmt3 = $pdo->prepare("
    SELECT COUNT(*) FROM reservations rv
    JOIN restaurant_admins ra ON rv.restaurant_id = ra.restaurant_id
    WHERE ra.user_id = ? AND rv.status = 'pending'
");
$stmt3->execute([$_SESSION['user_id']]);
$pendingCount = (int) $stmt3->fetchColumn();
?>
<?php
$pageTitle = t('re.page_title_prefix') . ' – ' . $rest['name'];
$extraCss  = ['main.css?v=4', 'admin.css?v=3', 'modal.css?v=3', 'design.css?v=2'];
require_once '../includes/html_head.php';
?>
<body>
    <style>

        .rest-edit-header { display:flex; align-items:center; gap:14px; margin-bottom:28px; flex-wrap:wrap; }
        .rest-edit-back { display:flex; align-items:center; gap:6px; color:var(--color-muted); font-size:.85rem; font-weight:500; text-decoration:none; transition:color var(--transition); }
        .rest-edit-back:hover { color:var(--color-text); }
        .rest-edit-title { font-size:1.4rem; font-weight:700; color:var(--color-text); letter-spacing:-.02em; margin:0; flex:1; }
        .rest-color-dot { width:14px; height:14px; border-radius:50%; display:inline-block; vertical-align:middle; margin-right:6px; border:2px solid rgba(0,0,0,.1); }

        /* Tabs (Rezble-style) */
        .re-tabs { display:flex; gap:4px; border-bottom:1px solid var(--line, var(--color-border)); margin-bottom:24px; overflow-x:auto; -webkit-overflow-scrolling:touch; scrollbar-width:none; }
        .re-tabs::-webkit-scrollbar { display:none; }
        @media (max-width: 900px) {
            .re-tabs {
                position: relative;
                padding-right: 24px;
                mask-image: linear-gradient(to right, #000 0, #000 calc(100% - 24px), transparent 100%);
                -webkit-mask-image: linear-gradient(to right, #000 0, #000 calc(100% - 24px), transparent 100%);
            }
        }
        .re-tab { padding:10px 14px; font-size:13px; font-weight:600; font-family:var(--font); color:var(--ink-mute, var(--color-muted)); background:transparent; border:none; cursor:pointer; border-bottom:2px solid transparent; transition:color .15s, border-color .15s; white-space:nowrap; border-radius:6px 6px 0 0; margin-bottom:-1px; }
        .re-tab:hover { color:var(--ink, var(--color-text)); background:var(--bg-sunken, transparent); }
        .re-tab.active { color:var(--accent, var(--color-accent)); border-bottom-color:var(--accent, var(--color-accent)); background:transparent; }
        .re-panel { display:none; } .re-panel.active { display:block; animation: rz-fadeIn .25s var(--ease, ease-out); }

        /* Sekcija znotraj taba */
        .re-section { background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:22px 24px;}
        .re-section-title { font-size:.75rem; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:var(--color-muted); margin-bottom:16px; }

        /* Gumbi */
        .re-save-bar { display:flex; align-items:center; justify-content:flex-end; gap:10px; margin-top:24px; }
        .btn-success { background:#10B981; color:#fff; border:none; padding:.55rem 1.4rem; border-radius:var(--radius); font-size:.875rem; font-weight:600; font-family:var(--font); cursor:pointer; transition:background var(--transition); }
        .btn-success:hover { background:#059669; }
        .btn-danger-sm { background:transparent; border:1.5px solid #FCA5A5; color:#DC2626; padding:.5rem 1rem; border-radius:var(--radius); font-size:.825rem; font-weight:600; font-family:var(--font); cursor:pointer; transition:all var(--transition); }
        .btn-danger-sm:hover { background:#FEE2E2; }

        /* Staff + custom field lijst */
        .item-row { display:flex; align-items:center; gap:8px; padding:8px 12px; background:var(--color-bg); border-radius:8px; margin-bottom:6px; font-size:.875rem; }
        .item-row-name { flex:1; font-weight:500; color:var(--color-text); }
        .item-row-badge { font-size:.72rem; padding:2px 8px; border-radius:20px; font-weight:600; }
        .item-row-del { background:none; border:none; cursor:pointer; color:var(--color-muted); line-height:1; font-size:1.1rem; padding:2px 4px; border-radius:4px; transition:color var(--transition); }
        .item-row-del:hover { color:var(--color-danger); }

        /* Toggle switch */
        .toggle-wrap { display:flex; align-items:center; gap:10px; }
        .toggle { position:relative; display:inline-block; width:40px; height:22px; flex-shrink:0; }
        .toggle input { opacity:0; width:0; height:0; }
        .toggle-track { position:absolute; inset:0; border-radius:22px; background:#D1D5DB; cursor:pointer; transition:background .2s; }
        .toggle-track::before { content:''; position:absolute; width:16px; height:16px; border-radius:50%; left:3px; top:3px; background:#fff; transition:transform .2s; }
        .toggle input:checked + .toggle-track { background:var(--color-accent); }
        .toggle input:checked + .toggle-track::before { transform:translateX(18px); }
        .toggle-label { font-size:.875rem; font-weight:500; color:var(--color-text); cursor:pointer; }

        /* Opomba */
        .re-note { padding:12px 16px; background:#FFF7ED; border:1px solid #FED7AA; border-radius:var(--radius); font-size:.825rem; color:#92400E; margin-top:16px; }
        .re-note-blue { background:#EFF6FF; border-color:#BFDBFE; color:#1D4ED8; }

        /* Day schedule edit area */
        .day-periods-wrap { display:flex; flex-direction:column; gap:6px; }
        .day-period-row { display:flex; align-items:center; gap:6px; }
        .day-time-input { border:1.5px solid var(--line); border-radius:8px; padding:7px 10px; font-size:.85rem; font-family:var(--font-sans); color:var(--ink); outline:none; width:90px; background:var(--bg-elev); }
        .day-time-input:focus { border-color:var(--accent); box-shadow:0 0 0 3px color-mix(in oklab,var(--accent) 14%,transparent); }
        .btn-period-add { background:none; border:1.5px dashed var(--line-strong); border-radius:8px; padding:5px 12px; font-size:.8rem; color:var(--ink-mute); cursor:pointer; font-family:var(--font-sans); transition:border-color .15s,color .15s; margin-top:2px; }
        .btn-period-add:hover { border-color:var(--accent); color:var(--accent); }
        .btn-period-del { background:none; border:none; color:var(--ink-mute); cursor:pointer; font-size:1.1rem; line-height:1; padding:4px; border-radius:6px; transition:color .15s; }
        .btn-period-del:hover { color:var(--danger); }

        /* CF type/applies badges */
        .cf-badge { font-size:.7rem; padding:2px 7px; border-radius:4px; font-weight:600; }
        .cf-badge-type { background:#E5E7EB; color:#374151; }
        .cf-badge-pub  { background:#DBEAFE; color:#1D4ED8; }
        .cf-badge-int  { background:#D1FAE5; color:#065F46; }
        .cf-badge-both { background:#EDE9FE; color:#5B21B6; }
        .cf-badge-req  { background:#FEE2E2; color:#DC2626; }

        @media (max-width:600px) {
            .re-section { padding:16px; }
            .admin-field-row { grid-template-columns:1fr; }
        }
    </style>

<div id="rz-app" class="rz-app">
<?php require_once '../includes/sidebar.php'; ?>
<main class="rz-main">

<?php require_once '../includes/trial_banner.php'; ?>

<?php
    $topbarTitle    = $rest['name'];
    $topbarSubtitle = t('re.topbar_subtitle') . ' <span class="rest-color-dot" id="hdr-color-dot" style="background:' . h($rest['color']) . '"></span>'
                    . ' <span class="badge ' . ($rest['is_active'] ? 'badge-active' : 'badge-inactive') . '">' . ($rest['is_active'] ? t('re.status_active') : t('re.status_inactive')) . '</span>';
    ob_start(); ?>
    <a href="<?= BASE_PATH ?>/pages/restaurants.php" class="rz-btn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        <span><?= t('common.back') ?></span>
    </a>
<?php $topbarActions = ob_get_clean(); require_once '../includes/topbar.php'; ?>

<div class="rest-edit-wrap">

    <div id="page-error" style="display:none;background:#FEE2E2;color:#991B1B;padding:12px 16px;border-radius:var(--radius);margin-bottom:20px;font-size:.875rem"></div>
    <div id="page-success" style="display:none;background:#D1FAE5;color:#065F46;padding:12px 16px;border-radius:var(--radius);margin-bottom:20px;font-size:.875rem"></div>

    <!-- Tabs -->
    <div class="re-tabs">
        <button class="re-tab active" data-tab="splosno"><?= t('re.tab_general') ?></button>
        <button class="re-tab" data-tab="urnik"><?= t('re.tab_schedule') ?></button>
        <button class="re-tab" data-tab="booking"><?= t('re.tab_booking') ?></button>
        <button class="re-tab" data-tab="zaposleni"><?= t('re.tab_staff') ?></button>
        <button class="re-tab" data-tab="polja"><?= t('re.tab_fields') ?></button>
        <button class="re-tab" data-tab="anketa"><?= t('re.tab_survey') ?></button>
        <button class="re-tab" data-tab="mize"><?= t('re.tab_tables') ?></button>
        <?php if ($hasBrandingTab): ?>
            <button class="re-tab" data-tab="branding"><?= t('re.tab_branding') ?></button>
        <?php endif; ?>
    </div>

    <!-- ── Tab: Splošno ────────────────────────────────────── -->
    <div id="panel-splosno" class="re-panel active">
        <div class="flex flex--wrap flex--equal flex--gap20">
        <div class="re-section">
            <?=  card_head(t('re.card_basic'), t('re.card_rest_settings')); ?>
            <div class="admin-form">
                <div class="admin-field-row">
                    <div class="admin-field">
                        <label><?= t('re.field_name') ?></label>
                        <input id="r-name" type="text" value="<?= h($rest['name']) ?>">
                    </div>
                    <div class="admin-field" style="max-width:140px">
                        <label><?= t('re.field_color') ?></label>
                        <input id="r-color" type="color" value="<?= h($rest['color']) ?>"
                            style="height:42px;padding:4px;width:100%"
                            oninput="document.getElementById('hdr-color-dot').style.background=this.value">
                    </div>
                </div>
                <div class="admin-field">
                    <label><?= t('re.field_status') ?></label>
                    <select id="r-active" style="max-width:200px">
                        <option value="1" <?= $rest['is_active'] ? 'selected' : '' ?>><?= t('re.status_active') ?></option>
                        <option value="0" <?= !$rest['is_active'] ? 'selected' : '' ?>><?= t('re.status_inactive') ?></option>
                    </select>
                </div>
            </div>
        </div>
        <div class="re-section">
            <?=  card_head(t('re.card_basic'), t('re.card_contact')); ?>
            <p style="font-size:.825rem;color:var(--color-muted);margin:0 0 14px;line-height:1.5"><?= t('re.contact_intro') ?></p>
            <div class="admin-form">
                <div class="admin-field">
                    <label><?= t('re.field_address') ?></label>
                    <input id="r-address" type="text" placeholder="Tržaška cesta 25, 1000 Ljubljana" value="<?= h($rest['address'] ?? '') ?>" maxlength="255">
                </div>
                <div class="admin-field-row">
                    <div class="admin-field">
                        <label><?= t('re.field_contact_email') ?></label>
                        <input id="r-contact-email" type="email" placeholder="info@restavracija.si" value="<?= h($rest['contact_email'] ?? '') ?>">
                    </div>
                    <div class="admin-field">
                        <label><?= t('re.field_contact_phone') ?></label>
                        <input id="r-contact-phone" type="tel" placeholder="+386 1 234 56 78" value="<?= h($rest['contact_phone'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Sistemski dostopi (uporabniki) -->
        <div class="re-section flex--100">
            <?php $uporabnikiBtn = '<button onclick="openRestUserModal(null)" class="btn btn-primary btn-sm">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                    ' . t('common.add') . '
                </button>'; ?>
            <?=  card_head(t('re.card_users_title'), t('re.card_users_subtitle'), $uporabnikiBtn); ?>
            <p style="font-size:.8rem;color:var(--color-muted);margin:0 0 12px;line-height:1.5"><?= t_raw('re.users_intro') ?></p>
            <div id="rest-users-list"><span style="color:var(--color-muted);font-size:.875rem"><?= t('common.loading') ?></span></div>
        </div>
        </div>
        <div class="re-save-bar">
            <button class="btn btn-primary" id="btn-save-splosno"><?= t('common.save') ?></button>
        </div>
    </div>

    <!-- ── Tab: Urnik ──────────────────────────────────────── -->
    <div id="panel-urnik" class="re-panel">
        <div class="rz-grid-2" style="margin-top:0">

            <!-- Levo: odpiralni čas -->
            <div class="rz-card">
                <?=  card_head(t('re.card_opening_hours'), t('re.card_weekly_schedule')); ?>
                <div class="rz-schedule" id="day-schedule-wrap">
                    <div style="padding:16px;color:var(--ink-mute);font-size:.875rem"><?= t('common.loading') ?></div>
                </div>
            </div>

            <!-- Desno: nastavitve + blokirani dnevi -->
            <div style="display:flex;flex-direction:column;gap:20px">

                <!-- Nastavitve rezervacij -->
                <div class="rz-card">
                    <?=  card_head(t('re.card_rules'), t('re.card_res_settings')); ?>
                    <div class="rz-form">
                        <div class="rz-field" style="max-width:200px">
                            <label class="rz-field-label"><?= t('re.field_duration') ?></label>
                            <input id="r-duration" type="number" min="15" step="15" class="rz-input"
                                value="<?= (int)$rest['reservation_duration'] ?>">
                        </div>
                        <div>
                        <div class="rz-toggle-row">
                            <div>
                                <div class="rz-toggle-label"><?= t('re.toggle_custom_duration') ?></div>
                                <div class="rz-toggle-hint"><?= t('re.toggle_custom_duration_hint') ?></div>
                            </div>
                            <label class="toggle-wrap" style="cursor:pointer;margin:0;flex-shrink:0">
                                <span class="toggle">
                                    <input type="checkbox" id="r-allow-custom" <?= $rest['allow_custom_duration'] ? 'checked' : '' ?>>
                                    <span class="toggle-track"></span>
                                </span>
                            </label>
                        </div>
                        <div class="rz-toggle-row">
                            <div>
                                <div class="rz-toggle-label"><?= t('re.toggle_employee_override') ?></div>
                                <div class="rz-toggle-hint"><?= t('re.toggle_employee_override_hint') ?></div>
                            </div>
                            <label class="toggle-wrap" style="cursor:pointer;margin:0;flex-shrink:0">
                                <span class="toggle">
                                    <input type="checkbox" id="r-employees-override">
                                    <span class="toggle-track"></span>
                                </span>
                            </label>
                        </div>
                        <div class="rz-toggle-row">
                            <div>
                                <div class="rz-toggle-label"><?= t('re.toggle_notify_guest') ?></div>
                                <div class="rz-toggle-hint"><?= t('re.toggle_notify_guest_hint') ?></div>
                            </div>
                            <label class="toggle-wrap" style="cursor:pointer;margin:0;flex-shrink:0">
                                <span class="toggle">
                                    <input type="checkbox" id="r-notify-guest" <?= ($rest['notify_guest_email'] ?? 1) ? 'checked' : '' ?>>
                                    <span class="toggle-track"></span>
                                </span>
                            </label>
                        </div>
                        </div>
                    </div>
                </div>

                <!-- Dopusti in posebni dnevi -->
                <div class="rz-card">
                    <?php $dopusti_btn = '<button class="rz-btn" onclick="toggleBlackoutForm()">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                            ' . t('common.add') . '
                        </button>'; ?>
                    <?=  card_head(t('re.card_blackouts_eyebrow'), t('re.card_blackouts_title'), $dopusti_btn, false); ?>

                    <!-- Skrita forma za dodajanje -->
                    <div id="blackout-add-form" style="display:none;background:var(--bg-sunken);border-radius:10px;padding:16px;margin-bottom:16px">
                        <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap;align-items:flex-end">
                            <div class="rz-field" style="flex-shrink:0">
                                <label class="rz-field-label"><?= t('re.blackout_date_label') ?></label>
                                <input type="date" id="blackout-date" min="<?= date('Y-m-d') ?>" class="rz-input" style="width:150px">
                            </div>
                            <div class="rz-field" style="flex:1;min-width:140px">
                                <label class="rz-field-label"><?= t('re.blackout_reason_label') ?></label>
                                <input type="text" id="blackout-reason" placeholder="<?= t('re.blackout_reason_placeholder') ?>" class="rz-input">
                            </div>
                        </div>
                        <div style="margin-bottom:12px">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.825rem;color:var(--ink);font-weight:500;margin-bottom:8px">
                                <input type="checkbox" id="blackout-partial" style="width:15px;height:15px;accent-color:var(--accent)">
                                <?= t('re.blackout_partial') ?>
                            </label>
                            <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                                <div id="blackout-time-inner" class="rz-field" style="display:none">
                                    <label class="rz-field-label"><?= t('re.blackout_from') ?></label>
                                    <input type="time" id="blackout-start" class="rz-input" style="width:120px">
                                </div>
                                <div id="blackout-time-inner2" class="rz-field" style="display:none">
                                    <label class="rz-field-label"><?= t('re.blackout_to') ?></label>
                                    <input type="time" id="blackout-end" class="rz-input" style="width:120px">
                                </div>
                            </div>
                        </div>
                        <div style="display:flex;gap:8px">
                            <button class="rz-btn rz-btn-primary" id="btn-add-blackout"><?= t('re.btn_save_date') ?></button>
                            <button class="rz-btn" onclick="toggleBlackoutForm()"><?= t('common.cancel') ?></button>
                        </div>
                    </div>

                    <div id="blackout-list">
                        <p style="font-size:.825rem;color:var(--ink-mute);margin:4px 0"><?= t('common.loading') ?></p>
                    </div>
                </div>

            </div><!-- /desno -->
        </div><!-- /rz-grid-2 -->

        <div class="re-save-bar">
            <button class="btn btn-primary" id="btn-save-urnik"><?= t('re.btn_save_schedule') ?></button>
        </div>
    </div>

    <!-- ── Tab: Spletne rezervacije ────────────────────────── -->
    <div id="panel-booking" class="re-panel">
        <div class="flex flex--equal flex--gap20">
            <div class="flex flex--column flex--gap20">
                <div class="re-section">
                    <?=  card_head(t('re.card_settings'), t('re.tab_booking'), '<button class="btn btn-primary js-save-booking">' . t('common.save') . '</button>'); ?>
                    <div class="admin-form">

                        <div class="rz-toggle-row">
                            <div>
                                <div class="rz-toggle-label"><?= t('re.toggle_booking_enabled') ?></div>
                                <div class="rz-toggle-hint"><?= t('re.toggle_booking_enabled_hint') ?></div>
                            </div>
                            <label class="toggle-wrap" style="cursor:pointer;margin:0;flex-shrink:0">
                                <span class="toggle">
                                    <input type="checkbox" id="r-booking-enabled" <?= $rest['booking_enabled'] ? 'checked' : '' ?>
                                    onchange="document.getElementById('booking-settings').style.display=this.checked?'':'none';document.getElementById('booking-dependent').style.display=this.checked?'contents':'none';document.getElementById('booking-dependent1').style.display=this.checked?'flex':'none'">
                                    <span class="toggle-track"></span>
                                </span>
                            </label>
                        </div>

                        <div id="booking-settings" style="display:<?= $rest['booking_enabled'] ? '' : 'none' ?>">
                            <div class="flex flex--gap20 flex--equal" style="margin-top:8px">
                                <div class="admin-field">
                                    <label><?= t('re.field_min_guests') ?></label>
                                    <input id="r-min-guests" type="number" min="1" max="99" value="<?= (int)($rest['booking_min_guests'] ?? 2) ?>">
                                </div>
                                <div class="admin-field">
                                    <label><?= t('re.field_max_guests') ?></label>
                                    <input id="r-max-guests" type="number" min="1" max="500" value="<?= (int)($rest['booking_max_guests'] ?? 10) ?>">
                                </div>
                                <div class="admin-field">
                                    <label><?= t('re.field_slot_interval') ?></label>
                                    <select id="r-slot-interval">
                                        <?php foreach ([15,20,30,45,60,90,120] as $m):
                                            $lbl = $m < 60 ? "{$m} min" : ($m === 60 ? '1 ura' : ($m === 90 ? '1,5 ure' : ($m/60).' uri'));
                                            $cur = $rest['booking_slot_interval'] ?? $rest['reservation_duration'];
                                        ?>
                                        <option value="<?= $m ?>" <?= $cur == $m ? 'selected' : '' ?>><?= $lbl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            

                            <div class="rz-toggle-row">
                                <div>
                                    <div class="rz-toggle-label"><?= t('re.toggle_auto_confirm') ?></div>
                                    <div class="rz-toggle-hint"><?= t('re.toggle_auto_confirm_hint') ?></div>
                                </div>
                                <label class="toggle-wrap" style="cursor:pointer;margin:0;flex-shrink:0">
                                    <span class="toggle">
                                        <input type="checkbox" id="r-auto-confirm" <?= ($rest['booking_auto_confirm'] ?? 1) ? 'checked' : '' ?>>
                                        <span class="toggle-track"></span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="booking-dependent" style="display:<?= $rest['booking_enabled'] ? 'contents' : 'none' ?>">

                <!-- ── Samourejanje rezervacij (gost) ──────────────── -->
                <div class="re-section">
                    <?=  card_head(t('re.card_guest'), t('re.card_guest_edit'), false, 'advanced'); ?>
                    <p class="nastavitve-intro">
                        <?= t('re.guest_edit_intro') ?>
                    </p>
                    <div class="admin-form">
                        <div class="admin-field-row1">
                            <div class="admin-field1 flex flex--gap20 flex--center">
                                <div class="rz-toggle-row flex--1">
                                    <div>
                                        <div class="rz-toggle-label"><?= t('re.toggle_allow_edit') ?></div>
                                        <div class="rz-toggle-hint"><?= t('re.toggle_allow_edit_hint') ?></div>
                                    </div>
                                    <label class="toggle-wrap" style="cursor:pointer;margin:0;flex-shrink:0">
                                        <span class="toggle">
                                            <input type="checkbox" id="r-allow-edit" <?= ($rest['allow_guest_edit'] ?? 1) ? 'checked' : '' ?>>
                                            <span class="toggle-track"></span>
                                        </span>
                                    </label>
                                </div>
                                <div style="display:flex;align-items:center;gap:8px" id="edit-cutoff-wrap">
                                    <input type="number" id="r-edit-cutoff" min="1" max="168" style="width:70px;border:1.5px solid var(--color-border);border-radius:8px;padding:7px 10px;font-size:.875rem;font-family:var(--font);outline:none" value="<?= (int)($rest['guest_edit_cutoff_hours'] ?? 24) ?>">
                                    <span style="font-size:.825rem;color:var(--color-muted)"><?= t('re.cutoff_hours_suffix') ?></span>
                                </div>
                            </div>
                            <div class="admin-field1 flex flex--gap20 flex--center">
                                <div class="rz-toggle-row flex--1">
                                    <div>
                                        <div class="rz-toggle-label"><?= t('re.toggle_allow_cancel') ?></div>
                                        <div class="rz-toggle-hint"><?= t('re.toggle_allow_cancel_hint') ?></div>
                                    </div>
                                    <label class="toggle-wrap" style="cursor:pointer;margin:0;flex-shrink:0">
                                        <span class="toggle">
                                            <input type="checkbox" id="r-allow-cancel" <?= ($rest['allow_guest_cancel'] ?? 1) ? 'checked' : '' ?>>
                                            <span class="toggle-track"></span>
                                        </span>
                                    </label>
                                </div>
                                <div style="display:flex;align-items:center;gap:8px" id="edit-cancel-wrap">
                                    <input type="number" id="r-cancel-cutoff" min="1" max="168" style="width:70px;border:1.5px solid var(--color-border);border-radius:8px;padding:7px 10px;font-size:.875rem;font-family:var(--font);outline:none" value="<?= (int)($rest['guest_cancel_cutoff_hours'] ?? 24) ?>">
                                    <span style="font-size:.825rem;color:var(--color-muted)"><?= t('re.cutoff_hours_suffix') ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Čakalna lista ────────────────────────────────────── -->
                <div class="re-section">
                    <?=  card_head(t('re.card_settings'), t('re.card_waitlist'), false, 'advanced'); ?>
                    <p class="nastavitve-intro">
                        <?= t('re.waitlist_intro') ?>
                    </p>
                    <?php if (!user_has_feature($pdo, (int)$_SESSION['user_id'], 'waitlist')): ?>
                    <p style="font-size:.825rem;color:#92400E;background:#FEF3C7;border-radius:8px;padding:10px 14px;margin:0">
                        <?= t_raw('re.waitlist_gate', ['url' => BASE_PATH . '/pages/billing.php']) ?>
                    </p>
                    <?php else: ?>
                        <div class="rz-toggle-row">
                            <div>
                                <div class="rz-toggle-label"><?= t('re.toggle_waitlist_enabled') ?></div>
                                <div class="rz-toggle-hint"><?= t('re.toggle_waitlist_enabled_hint') ?></div>
                            </div>
                            <label class="toggle-wrap" style="cursor:pointer;margin:0;flex-shrink:0">
                                <span class="toggle">
                                    <input type="checkbox" id="r-allow-edit" <?= ($rest['allow_guest_edit'] ?? 1) ? 'checked' : '' ?>>
                                    <span class="toggle-track"></span>
                                </span>
                            </label>
                        </div>
                        <div class="admin-field" style="padding: 12px 0;">
                            <label><?= t('re.field_waitlist_max') ?></label>
                            <input type="number" id="r-waitlist-max" min="0" max="100"
                                value="<?= (int)($rest['waitlist_max_per_slot'] ?? 3) ?>">
                            <p style="font-size:.775rem;color:var(--color-muted);margin:6px 0 0"><?= t('re.waitlist_max_note') ?></p>
                        </div>
                        
                    <?php endif; ?>
                </div>

                <?php if ($hasTableMgmt): ?>
                <div class="re-section">
                    <?=  card_head(t('re.card_space_settings'), t('re.card_area_choice'), false, 'advanced'); ?>
                    <div class="rz-toggle-row">
                            <div>
                                <div class="rz-toggle-label"><?= t('re.toggle_area_choice') ?></div>
                                <div class="rz-toggle-hint"><?= t('re.toggle_area_choice_hint') ?></div>
                            </div>
                            <label class="toggle-wrap" style="cursor:pointer;margin:0;flex-shrink:0">
                                <span class="toggle">
                                    <input type="checkbox" id="r-allow-area-choice" <?= !empty($rest['allow_area_choice']) ? 'checked' : '' ?>>
                                    <span class="toggle-track"></span>
                                </span>
                            </label>
                        </div>
                </div>
                <?php endif; ?>

                <!-- ── Jeziki booking strani ────────────────────────── -->
                <?php
                $_curLangSwitcher = ($rest['booking_lang_switcher_enabled'] ?? 1);
                $_curAvailLangs = !empty($rest['booking_available_languages'])
                    ? (json_decode($rest['booking_available_languages'], true) ?: ['sl','en','de','it','fr','hr','es','pt'])
                    : ['sl','en','de','it','fr','hr','es','pt'];
                $_curPrimaryLang = $rest['booking_primary_language'] ?? get_lang();
                $_langLabels = ['sl'=>'Slovenščina','en'=>'English','de'=>'Deutsch','it'=>'Italiano','fr'=>'Français','hr'=>'Hrvatski','es'=>'Español','pt'=>'Português'];
                ?>
                <div class="re-section">
                    <?= card_head(t('re.card_settings'), t('re.card_booking_languages'), false, 'advanced'); ?>
                    <p class="nastavitve-intro"><?= t('re.booking_languages_intro') ?></p>
                    <div class="rz-toggle-row">
                        <div>
                            <div class="rz-toggle-label"><?= t('re.toggle_lang_switcher') ?></div>
                            <div class="rz-toggle-hint"><?= t('re.toggle_lang_switcher_hint') ?></div>
                        </div>
                        <label class="toggle-wrap" style="cursor:pointer;margin:0;flex-shrink:0">
                            <span class="toggle">
                                <input type="checkbox" id="r-lang-switcher" <?= $_curLangSwitcher ? 'checked' : '' ?>
                                       onchange="document.getElementById('r-avail-langs-block').style.display=this.checked?'flex':'none'">
                                <span class="toggle-track"></span>
                            </span>
                        </label>
                    </div>

                    <!-- Razpoložljivi jeziki: vidno samo če switcher omogočen -->
                    <div id="r-avail-langs-block" style="margin-top:16px;display:<?= $_curLangSwitcher ? 'flex' : 'none' ?>;flex-direction:column;gap:8px">
                        <label style="font-size:.825rem;color:var(--color-muted);font-weight:600"><?= t('re.available_languages_label') ?></label>
                        <div style="display:flex;flex-wrap:wrap;gap:8px">
                            <?php foreach ($_langLabels as $_lc => $_label): ?>
                                <label class="rz-tag-chip" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1.5px solid var(--color-border);border-radius:999px;font-size:.825rem;cursor:pointer;background:#fff">
                                    <input type="checkbox" name="r-avail-lang[]" value="<?= $_lc ?>" <?= in_array($_lc, $_curAvailLangs, true) ? 'checked' : '' ?>>
                                    <span><?= strtoupper($_lc) ?> · <?= $_label ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Primarni jezik: vedno viden -->
                    <div class="admin-field" style="padding:14px 0 0">
                        <label><?= t('re.primary_language_label') ?> <span style="font-weight:400;color:var(--color-muted);font-size:.78rem"><?= t('re.primary_language_hint') ?></span></label>
                        <select id="r-primary-lang" style="border:1.5px solid var(--color-border);border-radius:8px;padding:8px 12px;font-size:.875rem;font-family:var(--font);outline:none;min-width:220px">
                            <?php foreach ($_langLabels as $_lc => $_label): ?>
                                <option value="<?= $_lc ?>" <?= $_curPrimaryLang === $_lc ? 'selected' : '' ?>><?= strtoupper($_lc) ?> · <?= $_label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
  </div>
    </div>
    <div class="flex flex--column flex--gap20" id="booking-dependent1" style="display:<?= $rest['booking_enabled'] ? 'flex' : 'none' ?>">
                <?php if ($rest['booking_token']): ?>
                <div class="re-section">
                    <?=  card_head(t('re.card_link'), t('re.card_booking_url'), false, 'advanced'); ?>
                    <p class="nastavitve-intro"><?= t('re.booking_link_intro') ?></p>
                    <div class="rz-link-box mono">
                        <input type="text" id="booking-url-input" readonly
                            style="flex:1;min-width:200px;background:rgba(255,255,255,0.3);font-size:.8rem;color:#374151;border:1.5px solid var(--color-border);border-radius:8px;padding:9px 12px;font-family:var(--font-mono)"
                            onclick="this.select()">
                        <button class="rz-btn rz-btn-ghost" onclick="copyBookingUrl()"><?= t('re.btn_copy') ?></button>
                    </div>
                </div>
                <div class="re-section">
                    <?=  card_head(t('re.card_link'), t('re.card_embed'), false, 'premium'); ?>
                    <p class="nastavitve-intro"><?= t('re.booking_link_intro') ?></p>
                    <div class="rz-link-box mono">
                        <textarea id="embed-code-input" readonly rows="3" onclick="this.select()"
                        style="flex:1;height:90px;min-width:200px;background:rgba(255,255,255,0.3);font-size:.8rem;color:#374151;border:1.5px solid var(--color-border);border-radius:8px;padding:9px 12px;font-family:var(--font-mono)"></textarea>
                        <button class="rz-btn rz-btn-ghost" onclick="copyEmbed()"><?= t('re.btn_copy') ?></button>
                    </div>
                </div>
                <?php endif; ?>
</div>
</div>
            </div><!-- /booking-dependent -->

          

    <!-- ── Tab: Zaposleni ──────────────────────────────────── -->
    <div id="panel-zaposleni" class="re-panel">
        <div class="re-section">
            <?=  card_head(t('re.tab_staff'), t('re.card_staff_list'), '<button class="btn btn-primary js-save-booking" id="btn-save-booking">' . t('common.save') . '</button>'); ?>
            <div id="staff-list" style="margin-bottom:14px"><?= t('common.loading') ?></div>
            <div style="display:flex;gap:8px">
                <input type="text" id="staff-name-input" placeholder="<?= t('re.staff_placeholder') ?>"
                    style="flex:1;border:1.5px solid var(--color-border);border-radius:8px;padding:9px 12px;font-size:.875rem;font-family:var(--font);outline:none"
                    onkeydown="if(event.key==='Enter')addStaff()">
                <button class="btn btn-primary" onclick="addStaff()">+ <?= t('common.add') ?></button>
            </div>
        </div>
        <div class="re-note">
            <?= t('re.staff_note') ?>
        </div>
    </div>

    <!-- ── Tab: Polja po meri ──────────────────────────────── -->
    <div id="panel-polja" class="re-panel">
        <div class="re-section">
            <?=  card_head(t('re.card_extra'), t('re.tab_fields'), false, 'advanced'); ?>
            <p class="nastavitve-intro"><?= t('re.cf_intro') ?></p>
            <div class="info-box">
            <strong><?= t('re.cf_info_int') ?></strong> = <?= t('re.cf_info_int_desc') ?><br>
            <strong><?= t('re.cf_info_pub') ?></strong> = <?= t('re.cf_info_pub_desc') ?><br>
            <strong><?= t('re.cf_info_both') ?></strong> = <?= t('re.cf_info_both_desc') ?>
        </div>
            <div id="cf-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px"><?= t('common.loading') ?></div>
            <button onclick="cfAddCard()" style="width:100%;border:2px dashed var(--color-border);border-radius:8px;padding:10px;font-size:.875rem;color:var(--color-muted);background:none;cursor:pointer;font-family:var(--font);transition:.15s"
                onmouseenter="this.style.borderColor='var(--color-accent)';this.style.color='var(--color-accent)'"
                onmouseleave="this.style.borderColor='';this.style.color=''">
                <?= t('re.btn_add_field') ?>
            </button>
        </div>
    </div>

    <!-- ── Tab: Anketa ───────────────────────────────────────── -->
    <div id="panel-anketa" class="re-panel">
    <div class="flex flex--column flex--gap20">
    <?php if (!$hasSurvey): ?>
        <div class="re-section" style="background:#FEF3C7;border-color:#FDE68A">
            <p style="margin:0;font-size:.9rem;color:#92400E">
                <?= t_raw('re.survey_gate', ['url' => BASE_PATH . '/pages/billing.php']) ?>
            </p>
        </div>
    <?php else: ?>

    <?php if (!$hasSurveyEdit): ?>
        <div class="re-section" style="background:#EFF6FF;border-color:#BFDBFE;margin-bottom:20px">
            <p style="margin:0;font-size:.875rem;color:#1E40AF">
                <?= t_raw('re.survey_readonly_note', ['url' => BASE_PATH . '/pages/billing.php']) ?>
            </p>
        </div>
    <?php endif; ?>
        <div class="flex flex--gap20 flex--equal">
            <!-- Nastavitve -->
            <div class="re-section">
                <?=  card_head(t('re.tab_survey'), t('re.card_survey_general'), false, 'advanced'); ?>
                <div class="admin-form">
                    <div class="admin-field-row1">
                        <div class="admin-field" style="flex:2">
                            <label><?= t('re.field_survey_title') ?></label>
                            <input type="text" id="sf-title" maxlength="255">
                        </div>
                    </div>
                    <div class="admin-field-row1">
                        <div class="admin-field">
                            <label><?= t('re.field_survey_desc') ?></label>
                            <textarea id="sf-description" rows="2" style="width:100%;border:1.5px solid var(--color-border);border-radius:8px;padding:9px 12px;font-size:.875rem;font-family:var(--font);resize:vertical;outline:none"></textarea>
                        </div>
                    </div>
                    <div class="admin-field-row1">
                        <div class="admin-field">
                            <label><?= t('re.field_survey_thankyou') ?></label>
                            <textarea id="sf-thankyou" rows="3" style="width:100%;border:1.5px solid var(--color-border);border-radius:8px;padding:9px 12px;font-size:.875rem;font-family:var(--font);resize:vertical;outline:none"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pošiljanje -->
            <div class="re-section">
                <?=  card_head(t('re.tab_survey'), t('re.card_survey_send'), false, 'advanced'); ?>
                <div style="display:flex;flex-direction:column;gap:0">
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--color-border)">
                        <div>
                            <span style="font-size:.875rem;color:var(--color-text);font-weight:500"><?= t('re.survey_send_enabled') ?></span>
                            <div style="font-size:.78rem;color:var(--color-muted);margin-top:2px"><?= t('re.survey_send_enabled_hint') ?></div>
                        </div>
                        <label class="toggle-wrap" style="cursor:pointer;margin:0">
                            <span class="toggle">
                                <input type="checkbox" id="sf-send-enabled" onchange="surveyToggleDelay()">
                                <span class="toggle-track"></span>
                            </span>
                        </label>
                    </div>
                    <div id="sf-delay-row" style="display:none;align-items:center;gap:8px;padding:10px 0;border-bottom:1px solid var(--color-border)">
                        <span style="font-size:.875rem;color:var(--color-text)"><?= t('re.survey_delay_prefix') ?></span>
                        <input type="number" id="sf-delay" min="0" max="168" value="2"
                            style="width:60px;border:1.5px solid var(--color-border);border-radius:8px;padding:7px 10px;font-size:.875rem;font-family:var(--font)">
                        <span style="font-size:.875rem;color:var(--color-muted)"><?= t('re.survey_delay_suffix') ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--color-border)">
                        <span style="font-size:.875rem;color:var(--color-text)"><?= t('re.survey_incl_thankyou') ?></span>
                        <label class="toggle-wrap" style="cursor:pointer;margin:0">
                            <span class="toggle">
                                <input type="checkbox" id="sf-incl-thankyou" checked>
                                <span class="toggle-track"></span>
                            </span>
                        </label>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0">
                        <span style="font-size:.875rem;color:var(--color-text)"><?= t('re.survey_incl_survey') ?></span>
                        <label class="toggle-wrap" style="cursor:pointer;margin:0">
                            <span class="toggle">
                                <input type="checkbox" id="sf-incl-survey" checked>
                                <span class="toggle-track"></span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Vprašanja -->
        <div class="re-section">
            <?=  card_head(t('re.tab_survey'), t('re.card_survey_questions'), false, 'Premium'); ?>
            <div id="sf-question-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px"></div>
            <?php if ($hasSurveyEdit): ?>
            <button onclick="surveyAddQuestion()" style="width:100%;border:2px dashed var(--color-border);border-radius:8px;padding:10px;font-size:.875rem;color:var(--color-muted);background:none;cursor:pointer;font-family:var(--font);transition:.15s"
                onmouseenter="this.style.borderColor='var(--color-accent)';this.style.color='var(--color-accent)'"
                onmouseleave="this.style.borderColor='';this.style.color=''">
                <?= t('re.btn_add_question') ?>
            </button>
            <?php endif; ?>
        </div>

        <?php if ($hasSurveyEdit): ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:32px">
            <button class="btn-success" id="btn-save-survey" onclick="saveSurveyForm()"><?= t('re.btn_save_survey') ?></button>
            <span id="sf-save-status" style="font-size:.85rem;color:var(--color-muted)"></span>
        </div>
        <?php else: ?>
        <div style="margin-bottom:32px"></div>
        <?php endif; ?>

        <!-- Odgovori -->
        <div class="re-section">
            <div class="re-section-title" style="display:flex;justify-content:space-between;align-items:center">
                <span><?= t('re.card_survey_responses') ?></span>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="date" id="sr-from" style="border:1px solid var(--color-border);border-radius:6px;padding:5px 9px;font-size:.8rem;font-family:var(--font)">
                    <input type="date" id="sr-to"   style="border:1px solid var(--color-border);border-radius:6px;padding:5px 9px;font-size:.8rem;font-family:var(--font)">
                    <button onclick="loadSurveyResults()" style="background:var(--color-accent);color:#fff;border:none;border-radius:6px;padding:6px 14px;font-size:.8rem;font-weight:600;cursor:pointer;font-family:var(--font)"><?= t('re.btn_show') ?></button>
                    <?php if (user_has_feature($pdo, (int)$_SESSION['user_id'], 'survey_export')): ?>
                    <button onclick="exportSurveyCsv()" style="background:#fff;border:1px solid var(--color-border);border-radius:6px;padding:6px 12px;font-size:.8rem;color:var(--color-text);cursor:pointer;font-family:var(--font)">↓ CSV</button>
                    <?php endif; ?>
                </div>
            </div>
            <div id="sr-list" style="margin-top:14px"><p style="font-size:.85rem;color:var(--color-muted)"><?= t('re.survey_results_click_load') ?></p></div>
        </div>

    <?php endif; ?>
    </div>
    </div>

    <!-- ── Tab: Mize ─────────────────────────────────────────── -->
    <div id="panel-mize" class="re-panel">
    <div style="display:flex;flex-direction:column;gap:20px">
    <?php if (!$hasTableMgmt): ?>
        <div class="rz-card" style="background:color-mix(in oklab,var(--warning) 10%,transparent);border-color:color-mix(in oklab,var(--warning) 30%,var(--line))">
            <p style="margin:0;font-size:.9rem;color:var(--warning)">
                <?= t_raw('re.tables_gate', ['url' => BASE_PATH . '/pages/billing.php']) ?>
            </p>
        </div>
    <?php else: ?>
        <div class="flex flex--equal flex--gap20">
        <!-- Cone in mize -->
        <div class="rz-card">
            <?php $mizeBtn = '<button onclick="showAreaForm()" class="rz-btn rz-btn-primary">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    ' . t('re.card_areas_eyebrow_btn') . '
                </button>'; ?>
            <?=  card_head(t('re.card_areas_eyebrow'), t('re.card_areas_title'), $mizeBtn, 'advanced'); ?>
            <p class="nastavitve-intro">
                <?= t('re.areas_intro') ?>
            </p>
            <!-- Forma za cono -->
            <div id="area-form" style="display:none;margin-bottom:14px;background:var(--bg-sunken);border-radius:10px;padding:14px;border:1px solid var(--line)">
                <div class="rz-card-eyebrow mono" style="margin-bottom:10px" id="area-form-title"><?= t('re.area_form_new') ?></div>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="text" id="area-name-input" placeholder="<?= t('re.area_name_placeholder') ?>" class="rz-input" style="flex:1">
                    <button onclick="saveArea()" class="rz-btn rz-btn-primary"><?= t('common.save') ?></button>
                    <button onclick="cancelAreaForm()" class="rz-btn"><?= t('common.cancel') ?></button>
                </div>
                <input type="hidden" id="area-edit-id" value="">
            </div>
            <!-- Cone z mizami (dinamično) -->
            <div id="areas-list" style="display:flex;flex-direction:column;gap:12px"></div>
            <!-- Forma za mizo (deljeno, skrita) -->
            <div id="table-form" style="display:none;margin-top:12px;background:var(--bg-sunken);border-radius:10px;padding:14px;border:1px solid var(--line)">
                <div class="rz-card-eyebrow mono" style="margin-bottom:10px" id="table-form-title"><?= t('re.table_form_new') ?></div>
                <div style="display:grid;grid-template-columns:1fr 100px auto;gap:10px;align-items:end">
                    <div class="rz-field">
                        <label class="rz-field-label"><?= t('re.table_name_label') ?></label>
                        <input type="text" id="table-name-input" placeholder="<?= t('re.table_name_placeholder') ?>" class="rz-input">
                    </div>
                    <div class="rz-field">
                        <label class="rz-field-label"><?= t('re.table_cap_label') ?></label>
                        <input type="number" id="table-cap-input" min="1" max="50" value="2" class="rz-input">
                    </div>
                    <div class="rz-field">
                        <label class="rz-field-label"><?= t('re.table_area_label') ?></label>
                        <select id="table-area-select" class="rz-input">
                            <option value=""><?= t('re.table_no_area') ?></option>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:8px;margin-top:12px">
                    <button onclick="saveTable()" class="rz-btn rz-btn-primary"><?= t('common.save') ?></button>
                    <button onclick="cancelTableForm()" class="rz-btn"><?= t('common.cancel') ?></button>
                </div>
                <input type="hidden" id="table-edit-id" value="">
                <input type="hidden" id="table-form-anchor" value="">
            </div>
        </div>
        <div class="flex flex--column flex--gap20">
            <!-- Nastavitev: vse mize so združljive -->
        <div class="rz-card">
            <?=  card_head(t('re.card_areas_eyebrow'), t('re.card_areas_title'), $mizeBtn, 'advanced'); ?>
            <p class="nastavitve-intro">
                <?= t('re.all_mergeable_intro') ?>
            </p>
                <div class="rz-toggle-row">
                    <div>
                        <div class="rz-toggle-label"><?= t('re.toggle_all_mergeable') ?></div>
                        <div class="rz-toggle-hint"><?= t('re.toggle_all_mergeable_hint') ?></div>
                    </div>
                    <label class="toggle-wrap" style="cursor:pointer;margin:0;flex-shrink:0">
                        <span class="toggle">
                            <input type="checkbox" id="all-tables-mergeable-toggle" <?= !empty($rest['all_tables_mergeable']) ? 'checked' : '' ?>
                    onchange="saveTableMergeableSetting(this.checked)">
                            <span class="toggle-track"></span>
                        </span>
                    </label>
                </div>

        <!-- Združene mize -->
        <div id="merge-groups-section" style="margin-top: 2em;">
            <div class="rz-card-head">
                <div>
                    <h2 class="rz-card-title display"><?= t('re.card_merge_groups') ?></h2>
                </div>
            </div>
            <p style="font-size:13px;color:var(--ink-soft);margin:0 0 14px">
                <?= t('re.merge_groups_intro') ?>
            </p>
            <div id="merge-groups-list" style="display:flex;flex-direction:column;gap:6px"></div>
            <div id="merge-form" style="display:none;margin-top:12px;background:var(--bg-sunken);border-radius:10px;padding:14px;border:1px solid var(--line)">
                <div class="rz-field" style="margin-bottom:10px">
                    <label class="rz-field-label"><?= t('re.merge_group_name_label') ?></label>
                    <input type="text" id="mg-name-input" placeholder="<?= t('re.merge_group_name_placeholder') ?>" class="rz-input">
                </div>
                <div class="rz-field" style="margin-bottom:12px">
                    <label class="rz-field-label"><?= t('re.merge_select_label') ?></label>
                    <div id="mg-tables-checkboxes" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px"></div>
                </div>
                <div style="display:flex;gap:8px">
                    <button onclick="saveMergeGroup()" class="rz-btn rz-btn-primary"><?= t('common.save') ?></button>
                    <button onclick="cancelMergeForm()" class="rz-btn"><?= t('common.cancel') ?></button>
                </div>
                <input type="hidden" id="mg-edit-id" value="">
            </div>
            <button onclick="showMergeForm()" style="margin-top: 1em;width:100%;border:2px dashed var(--color-border);border-radius:8px;padding:10px;font-size:.875rem;color:var(--color-muted);background:none;cursor:pointer;font-family:var(--font);transition:.15s"
                onmouseenter="this.style.borderColor='var(--color-accent)';this.style.color='var(--color-accent)'"
                onmouseleave="this.style.borderColor='';this.style.color=''">
                <?= t('re.btn_add_merge_group') ?>
            </button>
        </div>
        </div>
        </div>
        </div>

        

    <?php endif; ?>
    </div>
    </div>

    <!-- ── Tab: Branding ─────────────────────────────────────── -->
    <?php if ($hasBrandingTab): ?>
    <div id="panel-branding" class="re-panel">
        <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px;align-items:start">
            <!-- Levo: form -->
            <div style="display:flex;flex-direction:column;gap:18px;min-width:0">

                <?php if ($hasCustomLogo): ?>
                <div class="re-section">
                    <?= card_head(t('re.brand_logo_title'), t('re.brand_logo_desc'), false, 'premium') ?>
                    <div class="admin-form">
                        <div id="brand-logo-preview" style="display:flex;align-items:center;gap:12px;padding:14px;border:1.5px dashed var(--line-strong);border-radius:8px;margin-bottom:10px;background:var(--bg-sunken,#FAFAF7);min-height:80px">
                            <?php if (!empty($rest['logo_path'])): ?>
                                <img id="brand-logo-img" src="<?= BASE_PATH . '/' . htmlspecialchars(ltrim($rest['logo_path'], '/'), ENT_QUOTES) ?>?v=<?= time() ?>" alt="Logo" style="max-width:200px;max-height:60px;object-fit:contain">
                                <button type="button" id="brand-logo-remove" class="btn btn-ghost btn-danger-sm" style="margin-left:auto"><?= t('re.brand_logo_remove') ?></button>
                            <?php else: ?>
                                <span style="color:var(--ink-mute);font-size:13px"><?= t('re.brand_logo_empty') ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <input type="file" id="brand-logo-input" accept="image/png,image/jpeg,image/svg+xml,image/webp" style="display:none">
                            <button type="button" class="btn btn-primary" onclick="document.getElementById('brand-logo-input').click()">
                                <?= t('re.brand_logo_upload') ?>
                            </button>
                            <span style="font-size:11.5px;color:var(--ink-mute);line-height:1.5"><?= t('re.brand_logo_hint') ?></span>
                        </div>
                        <div id="brand-logo-err" style="display:none;margin-top:8px;background:#FEE2E2;color:#991B1B;padding:8px 12px;border-radius:6px;font-size:12.5px"></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($hasCustomColors): ?>
                <div class="re-section">
                    <?= card_head(t('re.brand_colors_title'), t('re.brand_colors_desc'), false, 'premium') ?>
                    <div class="admin-form">
                        <div class="admin-field-row">
                            <div class="admin-field">
                                <label><?= t('re.brand_primary') ?></label>
                                <div style="display:flex;gap:8px;align-items:center">
                                    <input id="brand-primary" type="color" value="<?= htmlspecialchars($rest['brand_primary'] ?: '#1B4332') ?>" style="height:42px;width:60px;padding:4px;border:1px solid var(--line);border-radius:8px;cursor:pointer">
                                    <input id="brand-primary-hex" type="text" value="<?= htmlspecialchars($rest['brand_primary'] ?: '#1B4332') ?>" maxlength="7" style="flex:1;font-family:var(--font-mono)" placeholder="#1B4332">
                                </div>
                            </div>
                            <div class="admin-field">
                                <label><?= t('re.brand_secondary') ?></label>
                                <div style="display:flex;gap:8px;align-items:center">
                                    <input id="brand-secondary" type="color" value="<?= htmlspecialchars($rest['brand_secondary'] ?: '#C4704B') ?>" style="height:42px;width:60px;padding:4px;border:1px solid var(--line);border-radius:8px;cursor:pointer">
                                    <input id="brand-secondary-hex" type="text" value="<?= htmlspecialchars($rest['brand_secondary'] ?: '#C4704B') ?>" maxlength="7" style="flex:1;font-family:var(--font-mono)" placeholder="#C4704B">
                                </div>
                            </div>
                        </div>
                        <button type="button" id="brand-colors-reset" class="btn btn-ghost btn-sm" style="margin-top:8px"><?= t('re.brand_colors_reset') ?></button>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($hasHideBranding): ?>
                <div class="re-section">
                    <?= card_head(t('re.brand_hide_title'), t('re.brand_hide_desc'), false, 'premium') ?>
                    <div class="admin-form">
                        <label class="toggle-wrap" style="cursor:pointer">
                            <span class="toggle">
                                <input type="checkbox" id="brand-hide" <?= (int)($rest['hide_branding'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <span class="toggle-track"></span>
                            </span>
                            <span class="toggle-label"><?= t('re.brand_hide_toggle') ?></span>
                        </label>
                    </div>
                </div>
                <?php endif; ?>

                <div class="re-save-bar" style="position:sticky;bottom:10px;background:var(--bg-elev);padding:12px;border-radius:10px;border:1px solid var(--line);box-shadow:0 6px 14px rgba(0,0,0,.05);z-index:5">
                    <button type="button" id="brand-save" class="btn btn-primary"><?= t('re.brand_save') ?></button>
                </div>
            </div>

            <!-- Desno: preview -->
            <div class="re-section" style="position:sticky;top:14px;min-width:0">
                <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px">
                    <h3 style="font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-mute);margin:0"><?= t('re.brand_preview') ?></h3>
                    <a href="<?= BASE_PATH ?>/book.php?t=<?= htmlspecialchars($rest['booking_token']) ?>" target="_blank" rel="noopener" style="font-size:12px;color:var(--accent);text-decoration:none">
                        <?= t('re.brand_preview_open') ?> →
                    </a>
                </div>
                <iframe id="brand-preview"
                        src="<?= BASE_PATH ?>/book.php?t=<?= htmlspecialchars($rest['booking_token']) ?>&preview=1"
                        style="width:100%;height:640px;border:1px solid var(--line);border-radius:10px;background:#fff"
                        title="Preview"></iframe>
                <p style="font-size:11.5px;color:var(--ink-mute);margin:8px 0 0;line-height:1.5">
                    <?= t('re.brand_preview_note') ?>
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- .rest-edit-wrap -->

<!-- Modal za podrobnosti odgovora -->
<div id="sr-detail-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;padding:16px" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:14px;max-width:540px;width:100%;max-height:85vh;overflow-y:auto;padding:26px;position:relative;box-shadow:0 8px 30px rgba(0,0,0,.15)">
        <button onclick="document.getElementById('sr-detail-overlay').style.display='none'" style="position:absolute;top:14px;right:14px;background:none;border:none;font-size:1.3rem;cursor:pointer;color:#9CA3AF;line-height:1">×</button>
        <div id="sr-detail-content"></div>
    </div>
</div>

<div id="toast-container"></div>

<script>
window.APP_STATE = <?= json_encode([
    'base'         => BASE_PATH,
    'restId'       => (int)$rest['id'],
    'token'        => $rest['booking_token'] ?? '',
    'surveyEdit'   => $hasSurveyEdit,
    'primaryLang'  => !empty($rest['booking_primary_language']) ? $rest['booking_primary_language'] : 'sl',
    'availLangs'   => !empty($rest['booking_available_languages'])
        ? (json_decode($rest['booking_available_languages'], true) ?: ['sl'])
        : ['sl'],
], JSON_UNESCAPED_UNICODE) ?>;

const REST_ID = APP_STATE.restId;
const BASE    = APP_STATE.base;

// ── Pomožne ───────────────────────────────────────────────────
function h(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
function minsToTime(m) { return String(Math.floor(m/60)).padStart(2,'0')+':'+String(m%60).padStart(2,'0'); }
function timeToMins(t) { const [hh,mm]=(t||'').split(':').map(Number); return (hh||0)*60+(mm||0); }

function toast(msg, type='success') {
    const c=document.getElementById('toast-container');
    const t=document.createElement('div'); t.className=`toast toast-${type}`; t.textContent=msg; c.appendChild(t);
    setTimeout(()=>{ t.style.opacity='0'; t.style.transition='opacity .3s'; setTimeout(()=>t.remove(),300); },3200);
}

function showPageErr(msg) {
    const el=document.getElementById('page-error'); el.textContent=msg; el.style.display='';
    el.scrollIntoView({behavior:'smooth',block:'nearest'});
    setTimeout(()=>el.style.display='none', 5000);
}
function showPageOk(msg) {
    const el=document.getElementById('page-success'); el.textContent=msg; el.style.display='';
    setTimeout(()=>el.style.display='none', 3000);
}

async function apiCall(method, url, body=null) {
    const opts = { method, headers:{'Content-Type':'application/json'}, credentials:'same-origin' };
    if (body) opts.body = JSON.stringify(body);
    const res = await fetch(BASE + url, opts);
    const json = await res.json().catch(()=>({success:false,error:'Napaka strežnika'}));
    if (!json.success) throw new Error(json.error||'Napaka');
    return json.data;
}

// ── Sistemski dostopi (uporabniki za to restavracijo) ─────────
let restUsers = [];

async function loadRestUsers() {
    const wrap = document.getElementById('rest-users-list');
    if (!wrap) return;
    try {
        const all = await apiCall('GET', '/api/users.php');
        restUsers = (all || []).filter(u => (u.restaurant_id == REST_ID) || (u.linked_restaurant_id == REST_ID));
        renderRestUsers();
    } catch(e) {
        if (wrap) wrap.innerHTML = `<span style="color:var(--color-danger);font-size:.8rem">${e.message}</span>`;
    }
}

function renderRestUsers() {
    const wrap = document.getElementById('rest-users-list');
    if (!wrap) return;
    if (!restUsers.length) {
        wrap.innerHTML = `<p style="color:var(--color-muted);font-size:.875rem;padding:4px 0">${window.t('re.no_users')}</p>`;
        return;
    }
    wrap.innerHTML = `<table class="admin-table" style="margin:0">
        <thead><tr>
            <th>${window.t('re.table_col_name')}</th>
            <th>${window.t('re.table_col_login')}</th>
            <th>${window.t('re.table_col_role')}</th>
            <th>${window.t('re.table_col_status')}</th>
            <th></th>
        </tr></thead>
        <tbody>${restUsers.map(u => {
            const roleB = u.role === 'admin'
                ? `<span style="background:color-mix(in oklab,var(--color-accent) 12%,transparent);color:var(--color-accent);border-radius:4px;padding:2px 8px;font-size:.72rem;font-weight:700">Admin</span>`
                : `<span style="background:var(--color-bg);color:var(--color-muted);border-radius:4px;padding:2px 8px;font-size:.72rem;font-weight:600;border:1px solid var(--color-border)">${window.t('re.role_staff_short')}</span>`;
            const loginId = u.email ? escHtml(u.email) : `<span style="color:var(--color-muted)">👤 ${escHtml(u.username||'')}</span>`;
            return `<tr>
                <td><strong>${escHtml(u.full_name)}</strong></td>
                <td style="color:var(--color-text-2)">${loginId}</td>
                <td>${roleB}</td>
                <td><span class="badge ${u.is_active==1?'badge-active':'badge-inactive'}">${u.is_active==1?window.t('re.user_active'):window.t('re.user_inactive')}</span></td>
                <td><div class="table-actions">
                    <button class="btn-icon" title="${window.t('common.edit')}" onclick="openRestUserModal(${u.id})">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button class="btn-icon danger" title="${u.is_active==1?window.t('re.btn_deactivate'):window.t('re.btn_activate')}" onclick="toggleRestUser(${u.id},'${escHtml(u.full_name)}',${u.is_active})">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                    </button>
                    <button class="btn-icon danger" title="${window.t('re.btn_perm_delete')}" onclick="deleteRestUser(${u.id},'${escHtml(u.full_name)}')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    </button>
                </div></td>
            </tr>`;
        }).join('')}</tbody>
    </table>`;
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

function openRestUserModal(userId) {
    const u = userId ? restUsers.find(x => x.id === userId) : null;
    const isEdit = !!u;
    const loginType = u ? (u.email ? 'email' : 'username') : 'email';

    const existing = document.getElementById('rest-user-modal');
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'rest-user-modal';
    overlay.innerHTML = `
    <div class="modal-box" style="max-width:480px">
        <div class="modal-header">
            <div class="modal-title">${isEdit ? window.t('re.user_modal_edit') : window.t('re.user_modal_new')}</div>
            <button class="modal-close" onclick="document.getElementById('rest-user-modal').remove()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <div id="ru-error" style="display:none;background:#FEE2E2;color:#991B1B;padding:10px 12px;border-radius:8px;font-size:.825rem;margin-bottom:12px"></div>
            <div class="admin-form">
                <div class="admin-field">
                    <label>${window.t('re.field_full_name')}</label>
                    <input id="ru-name" type="text" value="${escHtml(u?.full_name||'')}">
                </div>
                <div class="admin-field">
                    <label>${window.t('re.field_login_type')}</label>
                    <div style="display:flex;gap:16px;margin-top:4px">
                        <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer">
                            <input type="radio" name="ru-login" value="email" ${loginType==='email'?'checked':''} onchange="ruToggleLogin()"> Email
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer">
                            <input type="radio" name="ru-login" value="username" ${loginType==='username'?'checked':''} onchange="ruToggleLogin()"> ${window.t('re.login_type_username')}
                        </label>
                    </div>
                </div>
                <div class="admin-field-row">
                    <div class="admin-field" id="ru-email-wrap" style="${loginType!=='email'?'display:none':''}">
                        <label>${window.t('re.field_email')}</label>
                        <input id="ru-email" type="email" value="${escHtml(u?.email||'')}">
                    </div>
                    <div class="admin-field" id="ru-uname-wrap" style="${loginType!=='username'?'display:none':''}">
                        <label>${window.t('re.field_username')}</label>
                        <input id="ru-uname" type="text" value="${escHtml(u?.username||'')}">
                    </div>
                    <div class="admin-field">
                        <label>${isEdit ? window.t('re.field_password_edit') : window.t('re.field_password_new')}</label>
                        <input id="ru-pass" type="password" autocomplete="new-password">
                    </div>
                </div>
                <div class="admin-field">
                    <label>${window.t('re.field_role')}</label>
                    <select id="ru-role">
                        <option value="user" ${u?.role!=='admin'?'selected':''}>${window.t('re.role_user')}</option>
                        <option value="admin" ${u?.role==='admin'?'selected':''}>${window.t('re.role_admin')}</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="document.getElementById('rest-user-modal').remove()">${window.t('common.cancel')}</button>
            <button class="btn btn-primary" id="ru-save-btn" onclick="saveRestUser(${userId||0})">
                ${isEdit ? window.t('re.btn_save_changes') : window.t('re.btn_create_access')}
            </button>
        </div>
    </div>`;
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);
    setTimeout(() => document.getElementById('ru-name')?.focus(), 50);
}

window.ruToggleLogin = function() {
    const t = document.querySelector('input[name="ru-login"]:checked')?.value;
    document.getElementById('ru-email-wrap').style.display = t === 'email' ? '' : 'none';
    document.getElementById('ru-uname-wrap').style.display = t === 'username' ? '' : 'none';
};

async function saveRestUser(userId) {
    const btn = document.getElementById('ru-save-btn');
    const errEl = document.getElementById('ru-error');
    const name = document.getElementById('ru-name').value.trim();
    const loginType = document.querySelector('input[name="ru-login"]:checked')?.value || 'email';
    const email = document.getElementById('ru-email').value.trim();
    const uname = document.getElementById('ru-uname').value.trim();
    const pass = document.getElementById('ru-pass').value;
    const role = document.getElementById('ru-role').value;

    errEl.style.display = 'none';
    if (!name) { errEl.textContent = window.t('re.err_name_required'); errEl.style.display = 'block'; return; }
    if (loginType === 'email' && !email) { errEl.textContent = window.t('re.err_email_required'); errEl.style.display = 'block'; return; }
    if (loginType === 'username' && !uname) { errEl.textContent = window.t('re.err_username_required'); errEl.style.display = 'block'; return; }
    if (!userId && !pass) { errEl.textContent = window.t('re.err_password_required'); errEl.style.display = 'block'; return; }

    btn.disabled = true; btn.textContent = '...';
    const payload = { full_name: name, role, restaurant_id: REST_ID };
    if (loginType === 'email') payload.email = email; else payload.username = uname;
    if (pass) payload.password = pass;

    try {
        if (userId) {
            await apiCall('PUT', `/api/users.php?id=${userId}`, payload);
        } else {
            await apiCall('POST', '/api/users.php', payload);
        }
        document.getElementById('rest-user-modal').remove();
        toast(userId ? window.t('re.toast_access_updated') : window.t('re.toast_access_created'));
        await loadRestUsers();
    } catch(e) {
        errEl.textContent = e.message || window.t('common.error');
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = userId ? window.t('re.btn_save_changes') : window.t('re.btn_create_access');
    }
}

async function toggleRestUser(id, name, isActive) {
    if (!confirm(window.t(isActive ? 're.confirm_deactivate' : 're.confirm_activate', {name}))) return;
    try {
        await apiCall('PUT', `/api/users.php?id=${id}`, { is_active: isActive ? 0 : 1 });
        toast(window.t(isActive ? 're.toast_user_deactivated' : 're.toast_user_activated', {name}));
        await loadRestUsers();
    } catch(e) { toast(e.message, 'error'); }
}

async function deleteRestUser(id, name) {
    if (!confirm(window.t('re.confirm_delete_user', {name}))) return;
    try {
        await apiCall('DELETE', `/api/users.php?id=${id}&force=1`);
        toast(window.t('re.toast_user_deleted', {name}));
        await loadRestUsers();
    } catch(e) { toast(e.message, 'error'); }
}

loadRestUsers();

// ── Tabs ──────────────────────────────────────────────────────
function activateTab(name) {
    const tab = document.querySelector(`.re-tab[data-tab="${name}"]`);
    if (!tab) return;
    document.querySelectorAll('.re-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.re-panel').forEach(p=>p.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById('panel-'+name).classList.add('active');
    if (name === 'urnik'     && !urnikLoaded)  loadUrnik();
    if (name === 'zaposleni' && !staffLoaded)  loadStaff();
    if (name === 'polja'     && !cfLoaded)     loadCustomFields();
    if (name === 'anketa'    && !surveyLoaded) loadSurveyForm();
    if (name === 'mize'      && !tablesLoaded) loadTables();
}

document.querySelectorAll('.re-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        history.replaceState(null, '', '#' + tab.dataset.tab);
        activateTab(tab.dataset.tab);
    });
});


// ── Splošno – shrani ──────────────────────────────────────────
document.getElementById('btn-save-splosno').addEventListener('click', async () => {
    const name         = document.getElementById('r-name').value.trim();
    const color        = document.getElementById('r-color').value;
    const active       = parseInt(document.getElementById('r-active').value);
    const contactEmail = document.getElementById('r-contact-email').value.trim();
    const contactPhone = document.getElementById('r-contact-phone').value.trim();
    const address      = document.getElementById('r-address')?.value.trim() || '';
    const notifyGuest  = document.getElementById('r-notify-guest')?.checked ? 1 : 0;
    if (!name) { showPageErr(window.t('re.err_name_required')); return; }
    const btn = document.getElementById('btn-save-splosno');
    btn.disabled=true; btn.textContent='...';
    try {
        await apiCall('PUT', `/api/restaurants.php?id=${REST_ID}`, {
            name, color, is_active: active,
            contact_email: contactEmail, contact_phone: contactPhone, address: address,
            notify_guest_email: notifyGuest,
        });
        document.querySelector('.rest-edit-title').innerHTML =
            `<span class="rest-color-dot" id="hdr-color-dot" style="background:${color}"></span>${h(name)}`;
        showPageOk(window.t('re.toast_saved'));
    } catch(e) { showPageErr(e.message); }
    btn.disabled=false; btn.textContent=window.t('common.save');
});


// ── Urnik ─────────────────────────────────────────────────────
const DAY_NAMES = [0,1,2,3,4,5,6].map(i => window.t('days.'+i));
let urnikLoaded = false;
let blackouts = [];

async function loadUrnik() {
    urnikLoaded = true;
    try {
        const rest = await apiCall('GET', `/api/restaurants.php?id=${REST_ID}`);
        renderDaySchedule(rest.day_schedules || []);
        blackouts = rest.blackouts || [];
        renderBlackouts();
        const ovr = document.getElementById('r-employees-override');
        if (ovr) ovr.checked = !!rest.employees_can_override_schedule;
    } catch(e) { toast(e.message,'error'); }
}

function daySummary(day, periods) {
    const times = periods.map(p => minsToTime(p.start_time) + ' – ' + minsToTime(p.end_time));
    const timeStr = times.join(', ');
    const slots   = periods.length > 1 ? `<div class="rz-sched-slots">${periods.length} termina</div>` : '';
    return `<div class="rz-sched-time">${timeStr}</div>${slots}`;
}

function renderDaySchedule(ds) {
    const wrap = document.getElementById('day-schedule-wrap');
    wrap.innerHTML = DAY_NAMES.map((name, i) => {
        const d       = ds.find(x => x.day_of_week == i) || null;
        const open    = d ? !!d.is_open : (i < 5);
        const periods = (d && d.periods && d.periods.length)
            ? d.periods
            : [{start_time: d ? d.start_time : 480, end_time: d ? d.end_time : 1380}];

        const periodsHtml = periods.map((p, pi) => `
            <div class="day-period-row" data-period="${pi}">
                <input type="time" class="day-time-input day-start" data-day="${i}" data-period="${pi}"
                    value="${minsToTime(p.start_time)}" oninput="updateDaySummary(${i})">
                <span style="color:var(--ink-mute);font-size:.8rem">–</span>
                <input type="time" class="day-time-input day-end" data-day="${i}" data-period="${pi}"
                    value="${minsToTime(p.end_time)}" oninput="updateDaySummary(${i})">
                <button class="btn-period-del" onclick="removePeriod(${i},${pi},this)" ${periods.length<=1?'style="visibility:hidden"':''}>×</button>
            </div>`).join('');

        return `
            <div class="rz-sched-row${open ? '' : ' is-closed'}" id="day-row-${i}">
                <label class="rz-sched-day" style="cursor:pointer">
                    <input type="checkbox" class="day-cb" data-day="${i}" ${open ? 'checked' : ''}
                        style="width:15px;height:15px;accent-color:var(--accent);cursor:pointer;flex-shrink:0"
                        onchange="toggleDayRow(${i},this.checked)">
                    ${name}
                </label>
                <div id="day-summary-${i}">
                    ${open ? daySummary(i, periods) : `<div class="rz-sched-time" style="color:var(--ink-mute)">${window.t('re.day_closed')}</div>`}
                </div>
                <span class="rz-chip ${open ? 'rz-chip-ok' : 'rz-chip-mute'}" id="day-chip-${i}">${open ? window.t('re.chip_open') : window.t('re.chip_closed')}</span>
                <button class="rz-iconbtn" onclick="toggleDayEdit(${i})" title="Uredi urnik">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
            </div>
            <div id="day-edit-${i}" class="rz-sched-edit" style="display:none">
                <div class="day-periods-wrap" id="day-periods-${i}">
                    ${periodsHtml}
                    <button class="btn-period-add" onclick="addPeriod(${i})">${window.t('re.btn_add_period')}</button>
                </div>
            </div>`;
    }).join('');
}

window.toggleDayRow = (day, open) => {
    const row  = document.getElementById('day-row-' + day);
    const chip = document.getElementById('day-chip-' + day);
    if (row)  { open ? row.classList.remove('is-closed') : row.classList.add('is-closed'); }
    if (chip) { chip.className = `rz-chip ${open ? 'rz-chip-ok' : 'rz-chip-mute'}`; chip.textContent = open ? window.t('re.chip_open') : window.t('re.chip_closed'); }
    updateDaySummary(day);
    if (!open) { const ed = document.getElementById('day-edit-' + day); if (ed) ed.style.display = 'none'; }
};

window.toggleDayEdit = (day) => {
    const ed = document.getElementById('day-edit-' + day);
    if (ed) ed.style.display = ed.style.display === 'none' ? '' : 'none';
};

window.updateDaySummary = (day) => {
    const cb   = document.querySelector(`.day-cb[data-day="${day}"]`);
    const open = cb ? cb.checked : false;
    const sum  = document.getElementById('day-summary-' + day);
    if (!sum) return;
    if (!open) { sum.innerHTML = `<div class="rz-sched-time" style="color:var(--ink-mute)">${window.t('re.day_closed')}</div>`; return; }
    const wrap = document.getElementById('day-periods-' + day);
    if (!wrap) return;
    const periods = [];
    wrap.querySelectorAll('.day-period-row').forEach(row => {
        const st = timeToMins(row.querySelector('.day-start')?.value || '08:00');
        const en = timeToMins(row.querySelector('.day-end')?.value   || '23:00');
        periods.push({start_time: st, end_time: en});
    });
    sum.innerHTML = daySummary(day, periods);
};

window.addPeriod = (day) => {
    const wrap = document.getElementById('day-periods-'+day);
    if (!wrap) return;
    const rows = wrap.querySelectorAll('.day-period-row');
    const pi = rows.length;
    const lastEnd = wrap.querySelector(`.day-end[data-day="${day}"][data-period="${pi-1}"]`);
    const newStart = lastEnd ? lastEnd.value : '08:00';
    const div = document.createElement('div');
    div.className = 'day-period-row';
    div.dataset.period = pi;
    div.innerHTML = `
        <input type="time" class="day-time-input day-start" data-day="${day}" data-period="${pi}" value="${newStart}" oninput="updateDaySummary(${day})">
        <span style="color:var(--ink-mute);font-size:.8rem">–</span>
        <input type="time" class="day-time-input day-end" data-day="${day}" data-period="${pi}" value="${newStart}" oninput="updateDaySummary(${day})">
        <button class="btn-period-del" onclick="removePeriod(${day},${pi},this)">×</button>`;
    wrap.insertBefore(div, wrap.querySelector('.btn-period-add'));
    if (pi === 1) {
        const firstDel = wrap.querySelector(`.day-period-row[data-period="0"] .btn-period-del`);
        if (firstDel) firstDel.style.visibility = '';
    }
    updateDaySummary(day);
};

window.removePeriod = (day, pi, btn) => {
    const wrap = document.getElementById('day-periods-'+day);
    if (!wrap) return;
    btn.closest('.day-period-row').remove();
    wrap.querySelectorAll('.day-period-row').forEach((row,idx)=>{
        row.dataset.period = idx;
        row.querySelectorAll('[data-period]').forEach(el => el.dataset.period = idx);
        const del = row.querySelector('.btn-period-del');
        if (del) del.setAttribute('onclick', `removePeriod(${day},${idx},this)`);
    });
    const rows = wrap.querySelectorAll('.day-period-row');
    if (rows.length === 1) {
        const del = rows[0].querySelector('.btn-period-del');
        if (del) del.style.visibility = 'hidden';
    }
    updateDaySummary(day);
};

function getDaySchedules() {
    return Array.from({length:7}, (_,i) => {
        const isOpen = document.querySelector(`.day-cb[data-day="${i}"]`)?.checked ? 1 : 0;
        const wrap   = document.getElementById('day-periods-'+i);
        const periods = [];
        if (wrap) {
            wrap.querySelectorAll('.day-period-row').forEach(row => {
                const st = row.querySelector('.day-start')?.value || '08:00';
                const en = row.querySelector('.day-end')?.value   || '23:00';
                periods.push({start_time: timeToMins(st), end_time: timeToMins(en)});
            });
        }
        if (!periods.length) periods.push({start_time:480, end_time:1380});
        return {
            day_of_week: i,
            is_open: isOpen,
            start_time: periods[0].start_time,
            end_time:   periods[periods.length-1].end_time,
            periods,
        };
    });
}

function fmtMins(m) { return String(Math.floor(m/60)).padStart(2,'0')+':'+String(m%60).padStart(2,'0'); }

function fmtBlackoutDate(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    const loc = (window.__T__ && window.__T__['common.locale']) || 'sl-SI';
    return d.toLocaleDateString(loc, {day:'numeric', month:'short'});
}

function renderBlackouts() {
    const el = document.getElementById('blackout-list');
    if (!blackouts.length) {
        el.innerHTML = '<p style="font-size:.825rem;color:var(--ink-mute);margin:4px 0">Ni blokiranih datumov.</p>';
        return;
    }
    const todayStr = new Date().toISOString().slice(0, 10);
    const upcoming = blackouts.filter(b => b.blackout_date >= todayStr);
    const past     = blackouts.filter(b => b.blackout_date <  todayStr);

    function row(b, isPast) {
        const sub = (b.block_start != null && b.block_end != null)
            ? `${window.t('re.blackout_partial_schedule')} ${fmtMins(b.block_start)}–${fmtMins(b.block_end)}`
            : window.t('re.day_closed');
        return `
        <div style="display:grid;grid-template-columns:90px 1fr auto;align-items:center;gap:14px;padding:12px 4px;border-top:1px solid var(--line);${isPast ? 'opacity:.6' : ''}" data-date="${h(b.blackout_date)}">
            <span style="font-size:13px;font-weight:700;color:${isPast ? 'var(--ink-mute)' : 'var(--accent)'};font-family:var(--font-mono)">${fmtBlackoutDate(b.blackout_date)}</span>
            <div>
                <div style="font-size:13px;font-weight:600;color:var(--ink)">${b.reason ? h(b.reason) : window.t('re.blackout_blocked')}</div>
                <div style="font-size:11px;color:var(--ink-mute);margin-top:2px">${sub}</div>
            </div>
            <button class="rz-iconbtn" onclick="removeBlackout('${h(b.blackout_date)}',this)" title="Odstrani">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
            </button>
        </div>`;
    }

    let html = '';
    if (upcoming.length) {
        html += upcoming.map(b => row(b, false)).join('');
    } else {
        html += '<p style="font-size:.825rem;color:var(--ink-mute);margin:4px 0">' + window.t('re.no_upcoming_blackouts') + '</p>';
    }
    if (past.length) {
        html += `
            <details style="margin-top:14px">
                <summary style="cursor:pointer;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-mute);padding:6px 0">${window.t('re.archive_label')} (${past.length})</summary>
                <div style="margin-top:6px">${past.map(b => row(b, true)).join('')}</div>
            </details>`;
    }
    el.innerHTML = html;
}

window.removeBlackout = async (date, btn) => {
    try {
        await apiCall('DELETE', `/api/restaurants.php?id=${REST_ID}&action=remove_blackout&date=${date}`);
        btn.closest('[data-date]').remove();
        blackouts = blackouts.filter(b => b.blackout_date !== date);
        if (!blackouts.length) renderBlackouts();
        toast(window.t('re.toast_date_removed'));
    } catch(e) { toast(e.message,'error'); }
};

window.toggleBlackoutForm = () => {
    const form = document.getElementById('blackout-add-form');
    if (form) form.style.display = form.style.display === 'none' ? '' : 'none';
};

document.getElementById('blackout-partial').addEventListener('change', function() {
    document.getElementById('blackout-time-inner').style.display  = this.checked ? '' : 'none';
    document.getElementById('blackout-time-inner2').style.display = this.checked ? '' : 'none';
});

document.getElementById('btn-add-blackout').addEventListener('click', async () => {
    const date    = document.getElementById('blackout-date').value;
    const reason  = document.getElementById('blackout-reason').value.trim();
    const partial = document.getElementById('blackout-partial').checked;
    if (!date) { toast(window.t('re.err_date_required'),'error'); return; }
    const payload = {date, reason};
    if (partial) {
        const bsVal = document.getElementById('blackout-start').value;
        const beVal = document.getElementById('blackout-end').value;
        if (!bsVal || !beVal) { toast(window.t('re.err_time_required'),'error'); return; }
        const bs = timeToMins(bsVal), be = timeToMins(beVal);
        if (be <= bs) { toast(window.t('re.err_end_after_start'),'error'); return; }
        payload.block_start = bs;
        payload.block_end   = be;
    }
    try {
        await apiCall('PUT', `/api/restaurants.php?id=${REST_ID}&action=add_blackout`, payload);
        blackouts.push({blackout_date:date, reason:reason||null, block_start:payload.block_start??null, block_end:payload.block_end??null});
        renderBlackouts();
        document.getElementById('blackout-date').value = '';
        document.getElementById('blackout-reason').value = '';
        document.getElementById('blackout-partial').checked = false;
        document.getElementById('blackout-start').value = '';
        document.getElementById('blackout-end').value = '';
        document.getElementById('blackout-time-inner').style.display  = 'none';
        document.getElementById('blackout-time-inner2').style.display = 'none';
        toggleBlackoutForm();
        toast(window.t('re.toast_blackout_added'));
    } catch(e) { toast(e.message,'error'); }
});

document.getElementById('btn-save-urnik').addEventListener('click', async () => {
    const ds = getDaySchedules();
    // Preveri veljavnost vseh period
    for (const d of ds) {
        if (!d.is_open) continue;
        const name = DAY_NAMES[d.day_of_week];
        for (const p of d.periods) {
            if (p.end_time <= p.start_time) {
                showPageErr(window.t('re.err_time_end_before_start', {name})); return;
            }
        }
        // Preveri prekrivanja med periodami
        const sorted = [...d.periods].sort((a, b) => a.start_time - b.start_time);
        for (let i = 1; i < sorted.length; i++) {
            if (sorted[i].start_time < sorted[i-1].end_time) {
                const fmt = m => String(Math.floor(m/60)).padStart(2,'0') + ':' + String(m%60).padStart(2,'0');
                const t1 = fmt(sorted[i-1].start_time)+'–'+fmt(sorted[i-1].end_time);
                const t2 = fmt(sorted[i].start_time)+'–'+fmt(sorted[i].end_time);
                showPageErr(window.t('re.err_periods_overlap', {name, t1, t2}));
                return;
            }
        }
    }
    const overrideVal  = document.getElementById('r-employees-override')?.checked ? 1 : 0;
    const duration     = parseInt(document.getElementById('r-duration')?.value) || 60;
    const allowCustom  = document.getElementById('r-allow-custom')?.checked ? 1 : 0;
    const btn = document.getElementById('btn-save-urnik');
    btn.disabled=true; btn.textContent='...';
    try {
        await apiCall('PUT', `/api/restaurants.php?id=${REST_ID}`, {
            day_schedules: ds,
            employees_can_override_schedule: overrideVal,
            reservation_duration: duration,
            allow_custom_duration: allowCustom,
        });
        showPageOk(window.t('re.toast_schedule_saved'));
    } catch(e) { showPageErr(e.message); }
    btn.disabled=false; btn.textContent=window.t('re.btn_save_schedule');
});

// ── Booking ───────────────────────────────────────────────────
(function(){
    const token = APP_STATE.token;
    if (!token) return;
    const base = window.location.href.replace(/\/pages\/[^/]*(\?.*)?$/,'');
    const url  = base + '/book.php?t=' + token;
    const code = `<div id="rez-widget"></div>\n<script src="${base}/widget.js" data-token="${token}" data-container="#rez-widget"><\/script>`;
    const inp = document.getElementById('booking-url-input');
    const ta  = document.getElementById('embed-code-input');
    if (inp) inp.value = url;
    if (ta)  ta.value  = code;
})();

window.copyBookingUrl = () => {
    const v = document.getElementById('booking-url-input')?.value;
    if (v) navigator.clipboard.writeText(v).then(()=>toast(window.t('re.toast_url_copied')),()=>prompt(window.t('re.btn_copy')+':',v));
};
window.copyEmbed = () => {
    const v = document.getElementById('embed-code-input')?.value;
    if (v) navigator.clipboard.writeText(v).then(()=>toast(window.t('re.toast_embed_copied')),()=>prompt(window.t('re.btn_copy')+':',v));
};

async function saveBookingSettings(triggerBtn) {
    const enabled     = document.getElementById('r-booking-enabled').checked ? 1 : 0;
    const interval    = parseInt(document.getElementById('r-slot-interval')?.value||'60');
    const autoConf    = document.getElementById('r-auto-confirm')?.checked ? 1 : 0;
    const minG        = parseInt(document.getElementById('r-min-guests')?.value||'2');
    const maxG        = parseInt(document.getElementById('r-max-guests')?.value||'10');
    const allowEdit   = document.getElementById('r-allow-edit')?.checked ? 1 : 0;
    const editCutoff  = parseInt(document.getElementById('r-edit-cutoff')?.value||'24');
    const allowCancel = document.getElementById('r-allow-cancel')?.checked ? 1 : 0;
    const cancelCutoff = parseInt(document.getElementById('r-cancel-cutoff')?.value||'4');
    if (enabled && minG > maxG) { showPageErr(window.t('re.err_min_max_guests')); return; }
    const allBtns = Array.from(document.querySelectorAll('.js-save-booking'));
    allBtns.forEach(b => { b.disabled = true; b.textContent = '...'; });
    // Lang nastavitve
    const langSwitcher = document.getElementById('r-lang-switcher')?.checked ? 1 : 0;
    const primaryLang  = document.getElementById('r-primary-lang')?.value || 'sl';
    let availLangs;
    if (langSwitcher) {
        // Switcher omogočen — uporabi izbrane checkbox-e iz UI
        availLangs = Array.from(document.querySelectorAll('input[name="r-avail-lang[]"]:checked')).map(cb => cb.value);
        if (availLangs.length === 0) { showPageErr('Izberi vsaj en jezik za switcher.'); _reenableBookingBtns(); return; }
        // Primarni mora biti med razpoložljivimi — če ni, ga avtomatsko dodaj
        if (availLangs.indexOf(primaryLang) === -1) availLangs.unshift(primaryLang);
    } else {
        // Switcher onemogočen — available = samo primary (gostje ne morejo izbrati drugih)
        availLangs = [primaryLang];
    }
    try {
        await apiCall('PUT', `/api/restaurants.php?id=${REST_ID}`, {
            booking_enabled: enabled, booking_slot_interval: interval,
            booking_auto_confirm: autoConf, booking_min_guests: minG, booking_max_guests: maxG,
            allow_guest_edit: allowEdit, guest_edit_cutoff_hours: editCutoff,
            allow_guest_cancel: allowCancel, guest_cancel_cutoff_hours: cancelCutoff,
            waitlist_enabled: document.getElementById('r-waitlist-enabled')?.checked ? 1 : 0,
            waitlist_max_per_slot: parseInt(document.getElementById('r-waitlist-max')?.value ?? 3) || 0,
            allow_area_choice: document.getElementById('r-allow-area-choice')?.checked ? 1 : 0,
            booking_lang_switcher_enabled: langSwitcher,
            booking_available_languages:   JSON.stringify(availLangs),
            booking_primary_language:      primaryLang,
        });
        showPageOk(window.t('re.toast_saved'));
    } catch(e) { showPageErr(e.message); }
    allBtns.forEach(b => { b.disabled = false; b.textContent = window.t('common.save'); });
}

// Bind handler na vse "Shrani" gumbe na booking nastavitvah
// (na Spletne rezervacije + Zaposleni tab — oba shranita iste nastavitve).
document.querySelectorAll('.js-save-booking').forEach(btn => {
    btn.addEventListener('click', () => saveBookingSettings(btn));
});

// Validation guard for early-return cases — re-enable buttons after error
function _reenableBookingBtns() {
    document.querySelectorAll('.js-save-booking').forEach(b => { b.disabled=false; b.textContent=window.t('common.save'); });
}

// ── Zaposleni ─────────────────────────────────────────────────
let staffLoaded = false;

async function loadStaff() {
    staffLoaded = true;
    try {
        const staff = await apiCall('GET', `/api/staff.php?restaurant_id=${REST_ID}`);
        renderStaff(staff || []);
    } catch(e) { document.getElementById('staff-list').innerHTML=`<p style="color:var(--color-danger);font-size:.875rem">${window.t('re.err_load')}</p>`; }
}

function renderStaff(staff) {
    const el = document.getElementById('staff-list');
    if (!staff.length) { el.innerHTML=`<p style="font-size:.875rem;color:var(--color-muted)">${window.t('re.no_staff')}</p>`; return; }
    el.innerHTML = staff.map(s=>`
        <div class="item-row" id="staff-row-${s.id}">
            <span class="item-row-name">${h(s.name)}</span>
            ${s.is_active==0?`<span class="item-row-badge" style="background:#F3F4F6;color:var(--color-muted)">${window.t('re.staff_inactive_badge')}</span>`:''}
            <button class="item-row-del" onclick="removeStaff(${s.id})" title="${window.t('re.btn_remove')}">×</button>
        </div>`).join('');
}

window.addStaff = async () => {
    const inp  = document.getElementById('staff-name-input');
    const name = inp.value.trim();
    if (!name) { toast(window.t('re.err_staff_name'),'error'); return; }
    try {
        await apiCall('POST', '/api/staff.php', {restaurant_id:REST_ID, name});
        inp.value = '';
        await loadStaff();
        toast(window.t('re.toast_staff_added'));
    } catch(e) { toast(e.message,'error'); }
};

window.removeStaff = async (id) => {
    try {
        await apiCall('DELETE', `/api/staff.php?id=${id}`);
        await loadStaff();
        toast(window.t('re.toast_staff_removed'));
    } catch(e) { toast(e.message,'error'); }
};

// ── Polja po meri ──────────────────────────────────────────────
let cfLoaded = false;
const APPLIES_LABELS = () => ({internal:window.t('re.cf_applies_internal'), public:window.t('re.cf_applies_public'), both:window.t('re.cf_applies_both')});
const APPLIES_CLASS  = {internal:'cf-badge-int',  public:'cf-badge-pub',  both:'cf-badge-both'};
const TYPE_LABELS    = () => ({text:window.t('re.cf_type_text'), select:window.t('re.cf_type_select'), checkbox:window.t('re.cf_type_checkbox')});

async function loadCustomFields() {
    cfLoaded = true;
    let fields;
    try {
        fields = await apiCall('GET', `/api/customfields.php?restaurant_id=${REST_ID}`);
    } catch(e) {
        console.error('CF load error:', e);
        document.getElementById('cf-list').innerHTML = `<p style="color:var(--color-danger);font-size:.875rem">${window.t('re.err_load')}: ${e.message}</p>`;
        return;
    }
    try {
        renderCustomFields(fields || []);
    } catch(e) {
        console.error('CF render error:', e);
        document.getElementById('cf-list').innerHTML = `<p style="color:var(--color-danger);font-size:.875rem">${window.t('re.err_load')}: ${e.message}</p>`;
    }
}

let cfCardCounter = 0;
let cfOptCounter  = 0;

function renderCustomFields(fields) {
    const list = document.getElementById('cf-list');
    list.innerHTML = '';
    (fields || []).forEach(f => cfAddCard(f));
    initCfDragDrop();
}

function cfAddCard(data = null) {
    cfCardCounter++;
    const ck      = `cfc${cfCardCounter}`;
    const id      = data ? data.id : null;
    const label   = data ? (data.label || '') : '';
    const type    = data ? (data.field_type || 'text') : 'text';
    const applies = data ? (data.applies_to || 'both') : 'both';
    const req     = data ? !!data.is_required : false;
    const opts    = data && data.options ? data.options : [];

    const typeOpts = Object.entries(TYPE_LABELS()).map(([v,l]) =>
        `<option value="${v}"${v===type?' selected':''}>${l}</option>`).join('');
    const appliesOpts = Object.entries(APPLIES_LABELS()).map(([v,l]) =>
        `<option value="${v}"${v===applies?' selected':''}>${l}</option>`).join('');

    const card = document.createElement('div');
    card.className = 'cf-card';
    card.id = `cfcard-${ck}`;
    card.dataset.cfId = id || '';
    card.draggable = true;
    card.style.cssText = 'background:var(--color-bg);border:1px solid var(--color-border);border-radius:8px;padding:12px 14px';

    card.innerHTML = `
        <div style="display:flex;align-items:flex-start;gap:8px">
            <div style="flex-shrink:0;padding:3px 2px;cursor:grab;color:var(--color-muted)" title="Povleci za premik">
                <svg width="10" height="16" viewBox="0 0 10 16" fill="currentColor"><circle cx="2" cy="2" r="1.5"/><circle cx="8" cy="2" r="1.5"/><circle cx="2" cy="6" r="1.5"/><circle cx="8" cy="6" r="1.5"/><circle cx="2" cy="10" r="1.5"/><circle cx="8" cy="10" r="1.5"/><circle cx="2" cy="14" r="1.5"/><circle cx="8" cy="14" r="1.5"/></svg>
            </div>
            <div style="flex:1;display:flex;flex-direction:column;gap:6px">
                <input type="text" id="cflabel-${ck}" placeholder="${window.t('re.cf_label_placeholder')}" value="${h(label)}"
                    style="border:1.5px solid var(--color-border);border-radius:7px;padding:8px 10px;font-size:.875rem;font-family:var(--font);color:var(--color-text);width:100%;outline:none;box-sizing:border-box">
                <div id="cftr-${ck}"></div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <select id="cftype-${ck}"
                        style="border:1.5px solid var(--color-border);border-radius:7px;padding:7px 10px;font-size:.825rem;font-family:var(--font);background:var(--color-surface)">${typeOpts}</select>
                    <select id="cfapplies-${ck}"
                        style="border:1.5px solid var(--color-border);border-radius:7px;padding:7px 10px;font-size:.825rem;font-family:var(--font);background:var(--color-surface)">${appliesOpts}</select>
                    <label style="font-size:.82rem;color:var(--color-muted);display:flex;align-items:center;gap:5px;cursor:pointer">
                        <input type="checkbox" id="cfreq-${ck}"${req?' checked':''} style="cursor:pointer;accent-color:var(--color-accent)"> ${window.t('re.cf_required')}
                    </label>
                </div>
                <div id="cfopts-${ck}" style="display:none;flex-direction:column;gap:4px"></div>
                <button id="cfaddopt-${ck}" style="display:none;border:1px dashed var(--color-border);border-radius:6px;padding:5px 10px;font-size:.8rem;color:var(--color-muted);background:none;cursor:pointer;text-align:left;font-family:var(--font)"
                    onmouseenter="this.style.borderColor='var(--color-accent)';this.style.color='var(--color-accent)'"
                    onmouseleave="this.style.borderColor='';this.style.color=''">+ ${window.t('re.cf_add_option')}</button>
            </div>
            <div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0;align-items:flex-end">
                <button id="cfsave-${ck}"
                    style="background:var(--color-accent);color:#fff;border:none;border-radius:7px;padding:6px 14px;font-size:.8rem;font-weight:600;cursor:pointer;font-family:var(--font);white-space:nowrap">${window.t('common.save')}</button>
                <button id="cfdel-${ck}"
                    style="background:none;border:none;cursor:pointer;color:#EF4444;font-size:.8rem;padding:4px 6px;border-radius:5px;display:flex;align-items:center;gap:3px;white-space:nowrap"
                    onmouseenter="this.style.background='#FEF2F2'" onmouseleave="this.style.background='none'">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg> ${window.t('common.delete')}
                </button>
            </div>
        </div>`;

    document.getElementById('cf-list').appendChild(card);

    // Event listenerje dodamo PO tem ko je kartica v DOM-u, da se izognemo change event med innerHTML parsiranjem
    document.getElementById(`cftype-${ck}`).addEventListener('change', () => cfTypeChange(ck));
    document.getElementById(`cfaddopt-${ck}`).addEventListener('click', () => cfAddCardOpt(ck));
    document.getElementById(`cfsave-${ck}`).addEventListener('click', () => cfSaveCard(ck));
    document.getElementById(`cfdel-${ck}`).addEventListener('click', () => cfDeleteCard(ck));

    // Jezikovni chips za polje (samo za shranjene)
    if (id && window.TranslationsUI && APP_STATE.availLangs) {
        const host = document.getElementById(`cftr-${ck}`);
        fetch(`${APP_STATE.base}/api/translations.php?action=get&kind=custom_field&id=${id}`, { credentials: 'same-origin' })
            .then(r => r.json()).then(j => {
                const trans = {};
                if (j && j.success && j.data) {
                    Object.keys(j.data).forEach(lc => { if (j.data[lc] && j.data[lc].label) trans[lc] = j.data[lc].label; });
                }
                window.TranslationsUI.attach(host, {
                    kind: 'custom_field',
                    targetId: id,
                    primaryLang: APP_STATE.primaryLang || 'sl',
                    availableLangs: APP_STATE.availLangs,
                    masterText: label,
                    translations: trans,
                    fieldKey: 'label',
                });
            }).catch(() => {});
    }

    opts.forEach(o => cfAddCardOpt(ck, typeof o === 'string' ? o : o.label));
    cfTypeChange(ck);
}

function cfTypeChange(ck) {
    const typeEl = document.getElementById(`cftype-${ck}`);
    const wrap   = document.getElementById(`cfopts-${ck}`);
    const btn    = document.getElementById(`cfaddopt-${ck}`);
    if (!typeEl || !wrap || !btn) return;
    const type = typeEl.value;
    const needsOpts = type === 'select';
    wrap.style.display = needsOpts ? 'flex' : 'none';
    btn.style.display  = needsOpts ? '' : 'none';
    if (!needsOpts) { wrap.innerHTML = ''; }
    else if (needsOpts && wrap.children.length === 0) cfAddCardOpt(ck);
}

function cfAddCardOpt(ck, value = '') {
    cfOptCounter++;
    const ok = `cfo${cfOptCounter}`;
    const row = document.createElement('div');
    row.id = `cfoptrow-${ok}`;
    row.style.cssText = 'display:flex;align-items:center;gap:6px';
    row.innerHTML = `
        <input type="text" id="${ok}" placeholder="${window.t('re.cf_opt_placeholder')}" value="${h(value)}"
            style="flex:1;border:1.5px solid var(--color-border);border-radius:6px;padding:6px 9px;font-size:.83rem;font-family:var(--font);outline:none">
        <button onclick="document.getElementById('cfoptrow-${ok}').remove()"
            style="background:none;border:none;cursor:pointer;color:var(--color-muted);font-size:1.1rem;line-height:1;padding:2px 5px"
            onmouseenter="this.style.color='#EF4444'" onmouseleave="this.style.color=''">×</button>`;
    document.getElementById(`cfopts-${ck}`).appendChild(row);
}

async function cfSaveCard(ck) {
    const card    = document.getElementById(`cfcard-${ck}`);
    const id      = card.dataset.cfId ? parseInt(card.dataset.cfId) : null;
    const label   = document.getElementById(`cflabel-${ck}`).value.trim();
    const type    = document.getElementById(`cftype-${ck}`).value;
    const applies = document.getElementById(`cfapplies-${ck}`).value;
    const req     = document.getElementById(`cfreq-${ck}`).checked ? 1 : 0;
    if (!label) { toast(window.t('re.err_cf_label'), 'error'); return; }
    const options = type === 'select'
        ? Array.from(card.querySelectorAll(`#cfopts-${ck} input[type=text]`))
            .map(i => i.value.trim()).filter(Boolean)
        : [];
    if (type === 'select' && !options.length) { toast(window.t('re.err_cf_options'), 'error'); return; }
    const sortOrder = Array.from(document.querySelectorAll('#cf-list .cf-card')).indexOf(card);
    try {
        if (id) {
            await apiCall('PUT', `/api/customfields.php?id=${id}`, {
                label, field_type:type, applies_to:applies, is_required:req, options, sort_order:sortOrder,
            });
        } else {
            const result = await apiCall('POST', '/api/customfields.php', {
                restaurant_id:REST_ID, label, field_type:type,
                applies_to:applies, is_required:req, options, sort_order:sortOrder,
            });
            card.dataset.cfId = result.id;
        }
        toast(window.t('re.toast_saved'));
    } catch(e) { toast(e.message, 'error'); }
}

async function cfDeleteCard(ck) {
    const card = document.getElementById(`cfcard-${ck}`);
    const id   = card.dataset.cfId ? parseInt(card.dataset.cfId) : null;
    if (id && !confirm(window.t('re.confirm_cf_delete'))) return;
    if (id) {
        try {
            await apiCall('DELETE', `/api/customfields.php?id=${id}`);
            toast(window.t('re.toast_cf_deleted'));
        } catch(e) { toast(e.message, 'error'); return; }
    }
    card.remove();
}



async function saveCfOrder() {
    const cards = Array.from(document.querySelectorAll('#cf-list .cf-card'));
    const updates = cards.filter(c => c.dataset.cfId).map((c, i) => ({ id: parseInt(c.dataset.cfId), sort_order: i }));
    await Promise.all(updates.map(u =>
        apiCall('PUT', `/api/customfields.php?id=${u.id}`, { sort_order: u.sort_order }).catch(() => {})
    ));
}

function initCfDragDrop() {
    const list = document.getElementById('cf-list');
    if (list.dataset.dndInit) return;
    list.dataset.dndInit = '1';
    let dragSrc = null;

    list.addEventListener('dragstart', e => {
        const card = e.target.closest('.cf-card');
        if (!card) return;
        dragSrc = card;
        setTimeout(() => { card.style.opacity = '0.4'; }, 0);
        e.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragend', e => {
        const card = e.target.closest('.cf-card');
        if (card) card.style.opacity = '';
        list.querySelectorAll('.cf-card').forEach(c => c.style.outline = '');
        saveCfOrder();
    });
    list.addEventListener('dragover', e => {
        e.preventDefault();
        const card = e.target.closest('.cf-card');
        if (!card || card === dragSrc) return;
        list.querySelectorAll('.cf-card').forEach(c => c.style.outline = '');
        card.style.outline = '2px solid var(--color-accent)';
    });
    list.addEventListener('dragleave', e => {
        const card = e.target.closest('.cf-card');
        if (card) card.style.outline = '';
    });
    list.addEventListener('drop', e => {
        e.preventDefault();
        const card = e.target.closest('.cf-card');
        if (!card || !dragSrc || card === dragSrc) return;
        card.style.outline = '';
        const rect = card.getBoundingClientRect();
        if (e.clientY < rect.top + rect.height / 2) {
            list.insertBefore(dragSrc, card);
        } else {
            list.insertBefore(dragSrc, card.nextSibling);
        }
    });
}

// ── Anketa ────────────────────────────────────────────────────
let surveyLoaded = false;
let sfQCounter   = 0;
let sfOptCounter = 0;

const SF_TYPE_LABELS = () => ({
    rating:window.t('re.sf_type_rating'), radio:window.t('re.sf_type_radio'),
    checkbox:window.t('re.sf_type_checkbox'), text:window.t('re.sf_type_text'), textarea:window.t('re.sf_type_textarea'),
});

function surveyToggleDelay() {
    const row = document.getElementById('sf-delay-row');
    if (row) row.style.display = document.getElementById('sf-send-enabled').checked ? 'flex' : 'none';
}

async function loadSurveyForm() {
    surveyLoaded = true;
    try {
        const data = await apiCall('GET', `/api/survey.php?action=get_form&restaurant_id=${REST_ID}`);
        if (data) fillSurveyForm(data);
        else resetSurveyForm();
    } catch(e) { toast(e.message,'error'); }
    initSurveyDragDrop();
}

function initSurveyDragDrop() {
    const list = document.getElementById('sf-question-list');
    if (!list || list.dataset.dndInit) return;
    list.dataset.dndInit = '1';
    let dragSrc = null;

    list.addEventListener('dragstart', e => {
        const card = e.target.closest('[id^="sfcard-"]');
        if (!card) return;
        dragSrc = card;
        setTimeout(() => { card.style.opacity = '0.4'; }, 0);
        e.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragend', e => {
        const card = e.target.closest('[id^="sfcard-"]');
        if (card) card.style.opacity = '';
        list.querySelectorAll('[id^="sfcard-"]').forEach(c => c.style.outline = '');
    });
    list.addEventListener('dragover', e => {
        e.preventDefault();
        const card = e.target.closest('[id^="sfcard-"]');
        if (!card || card === dragSrc) return;
        list.querySelectorAll('[id^="sfcard-"]').forEach(c => c.style.outline = '');
        card.style.outline = '2px solid var(--color-accent)';
    });
    list.addEventListener('dragleave', e => {
        const card = e.target.closest('[id^="sfcard-"]');
        if (card) card.style.outline = '';
    });
    list.addEventListener('drop', e => {
        e.preventDefault();
        const card = e.target.closest('[id^="sfcard-"]');
        if (!card || !dragSrc || card === dragSrc) return;
        card.style.outline = '';
        const rect = card.getBoundingClientRect();
        if (e.clientY < rect.top + rect.height / 2) {
            list.insertBefore(dragSrc, card);
        } else {
            list.insertBefore(dragSrc, card.nextSibling);
        }
    });
}

function fillSurveyForm(d) {
    const canEdit = APP_STATE.surveyEdit;
    // d.title je že lokaliziran v primary lang (ali master če prevod manjka)
    document.getElementById('sf-title').value       = d.title || '';
    document.getElementById('sf-description').value = d.description || '';
    document.getElementById('sf-thankyou').value    = d.thank_you_message || '';
    document.getElementById('sf-send-enabled').checked  = !!parseInt(d.send_enabled);
    document.getElementById('sf-delay').value            = d.send_delay_hours || 2;
    document.getElementById('sf-incl-thankyou').checked = !!parseInt(d.include_thankyou);
    document.getElementById('sf-incl-survey').checked   = !!parseInt(d.include_survey);
    surveyToggleDelay();
    window.__SURVEY_FORM_ID__ = d.id ? parseInt(d.id) : 0;
    window.__SURVEY_FORM_TRANSLATIONS__ = d.translations || {};
    surveyAttachFormChips();

    // Readonly za Advanced
    if (!canEdit) {
        ['sf-title','sf-description','sf-thankyou','sf-delay'].forEach(id => {
            const el = document.getElementById(id);
            if (el) { el.readOnly = true; el.style.background = 'var(--color-bg)'; el.style.cursor = 'default'; }
        });
        ['sf-send-enabled','sf-incl-thankyou','sf-incl-survey'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.disabled = true;
        });
    }

    document.getElementById('sf-question-list').innerHTML = '';
    sfQCounter = 0;
    (d.questions || []).forEach(q => surveyAddQuestion(q));
}

function resetSurveyForm() {
    document.getElementById('sf-title').value        = window.t('re.survey_default_title');
    document.getElementById('sf-description').value  = '';
    document.getElementById('sf-thankyou').value     = '';
    document.getElementById('sf-send-enabled').checked  = false;
    document.getElementById('sf-delay').value            = 2;
    document.getElementById('sf-incl-thankyou').checked = true;
    document.getElementById('sf-incl-survey').checked   = true;
    surveyToggleDelay();
    document.getElementById('sf-question-list').innerHTML = '';
    sfQCounter = 0;
}

function surveyAddQuestion(data = null) {
    sfQCounter++;
    const qk      = `sfq${sfQCounter}`;
    const canEdit = APP_STATE.surveyEdit;
    const type    = data ? data.type : 'rating';
    // data.question_text je že prevod za primary lang (ali master če prevod manjka)
    const text    = data ? (data.question_text || '') : '';
    const req     = data ? !!parseInt(data.is_required) : false;
    const opts    = data && data.options ? data.options : [];
    const qid     = data && data.id ? parseInt(data.id) : 0;
    const qTrans  = data && data.translations ? data.translations : {};

    const card = document.createElement('div');
    card.id = `sfcard-${qk}`;
    if (qid) card.dataset.qid = qid;
    card.style.cssText = 'background:var(--color-bg);border:1px solid var(--color-border);border-radius:8px;padding:12px 14px';

    const typeOpts = Object.entries(SF_TYPE_LABELS()).map(([v,l]) =>
        `<option value="${v}"${v===type?' selected':''}>${l}</option>`).join('');

    const TYPE_READABLE = {
        rating:window.t('re.sf_type_rating'), radio:window.t('re.sf_type_radio_short'),
        checkbox:window.t('re.sf_type_checkbox'), text:window.t('re.sf_type_text_short'), textarea:window.t('re.sf_type_textarea_short')
    };

    if (canEdit) {
        card.draggable = true;
        card.innerHTML = `
        <div style="display:flex;align-items:flex-start;gap:8px">
            <div style="flex-shrink:0;padding:3px 2px;cursor:grab;color:var(--color-muted)" title="Povleci za premik">
                <svg width="10" height="16" viewBox="0 0 10 16" fill="currentColor"><circle cx="2" cy="2" r="1.5"/><circle cx="8" cy="2" r="1.5"/><circle cx="2" cy="6" r="1.5"/><circle cx="8" cy="6" r="1.5"/><circle cx="2" cy="10" r="1.5"/><circle cx="8" cy="10" r="1.5"/><circle cx="2" cy="14" r="1.5"/><circle cx="8" cy="14" r="1.5"/></svg>
            </div>
            <div style="flex:1;display:flex;flex-direction:column;gap:6px">
                <input type="text" id="sfqt-${qk}" placeholder="${window.t('re.sf_q_placeholder')}" value="${h(text)}"
                    style="border:1.5px solid var(--color-border);border-radius:7px;padding:8px 10px;font-size:.875rem;font-family:var(--font);color:var(--color-text);width:100%;outline:none">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <select id="sfqtype-${qk}" onchange="sfTypeChange('${qk}')"
                        style="border:1.5px solid var(--color-border);border-radius:7px;padding:7px 10px;font-size:.825rem;font-family:var(--font);background:#fff">${typeOpts}</select>
                    <label style="font-size:.82rem;color:var(--color-muted);display:flex;align-items:center;gap:5px;cursor:pointer">
                        <input type="checkbox" id="sfqreq-${qk}"${req?' checked':''} style="cursor:pointer;accent-color:var(--color-accent)"> ${window.t('re.cf_required')}
                    </label>
                </div>
                <div id="sfqtr-${qk}"></div>
                <div id="sfqopts-${qk}" style="display:flex;flex-direction:column;gap:4px"></div>
                <button id="sfqaddopt-${qk}" onclick="sfAddOpt('${qk}')" style="display:none;border:1px dashed var(--color-border);border-radius:6px;padding:5px 10px;font-size:.8rem;color:var(--color-muted);background:none;cursor:pointer;text-align:left">+ ${window.t('re.cf_add_option')}</button>
            </div>
            <button onclick="document.getElementById('sfcard-${qk}').remove()"
                style="background:none;border:none;cursor:pointer;color:#EF4444;font-size:.8rem;padding:4px 6px;border-radius:5px;flex-shrink:0;display:flex;align-items:center;gap:3px;white-space:nowrap"
                onmouseenter="this.style.background='#FEF2F2'" onmouseleave="this.style.background='none'">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg> ${window.t('common.delete')}
            </button>
        </div>`;
        document.getElementById('sf-question-list').appendChild(card);
        opts.forEach(o => sfAddOpt(qk, o));
        sfTypeChange(qk);

        // Jezikovni chips za vprašanje (samo če ima id — torej shranjeno)
        if (qid && window.TranslationsUI && APP_STATE.availLangs) {
            window.TranslationsUI.attach(document.getElementById(`sfqtr-${qk}`), {
                kind: 'survey_question',
                targetId: qid,
                primaryLang: APP_STATE.primaryLang || 'sl',
                availableLangs: APP_STATE.availLangs,
                masterText: text,
                translations: qTrans,
                multiline: text && text.length > 80,
            });
        }
    } else {
        // Readonly prikaz vprašanja
        const reqBadge = req ? `<span style="font-size:.72rem;background:#FEE2E2;color:#DC2626;padding:1px 6px;border-radius:10px;font-weight:600;margin-left:6px">${window.t('re.cf_required')}</span>` : '';
        const typeBadge = `<span style="font-size:.72rem;background:var(--color-border);color:var(--color-muted);padding:1px 6px;border-radius:10px">${TYPE_READABLE[type]||type}</span>`;
        let optsHtml = '';
        if (opts.length) {
            optsHtml = `<div style="margin-top:5px;display:flex;flex-wrap:wrap;gap:4px">${opts.map(o=>`<span style="font-size:.78rem;background:#fff;border:1px solid var(--color-border);border-radius:5px;padding:2px 8px;color:var(--color-muted)">${h(o.label)}</span>`).join('')}</div>`;
        }
        card.innerHTML = `
        <div style="display:flex;align-items:flex-start;gap:8px">
            <div style="flex:1">
                <div style="font-size:.875rem;font-weight:500;color:var(--color-text)">${h(text)}${reqBadge}</div>
                <div style="margin-top:3px">${typeBadge}${optsHtml}</div>
            </div>
        </div>`;
        document.getElementById('sf-question-list').appendChild(card);
    }
}

function sfTypeChange(qk) {
    const type = document.getElementById(`sfqtype-${qk}`).value;
    const needsOpts = type === 'radio' || type === 'checkbox';
    const btnAdd = document.getElementById(`sfqaddopt-${qk}`);
    const optsList = document.getElementById(`sfqopts-${qk}`);
    btnAdd.style.display = needsOpts ? '' : 'none';
    if (!needsOpts) { optsList.innerHTML = ''; }
    else if (needsOpts && optsList.children.length === 0) sfAddOpt(qk);
}

function sfAddOpt(qk, optOrValue = '') {
    sfOptCounter++;
    const ok = `sfo${sfOptCounter}`;
    const isObj = optOrValue && typeof optOrValue === 'object';
    const value = isObj ? (optOrValue.label || '') : (optOrValue || '');
    const oid   = isObj && optOrValue.id ? parseInt(optOrValue.id) : 0;
    const oTrans = isObj && optOrValue.translations ? optOrValue.translations : {};

    const row = document.createElement('div');
    row.id = `sfoptrow-${ok}`;
    if (oid) row.dataset.oid = oid;
    row.style.cssText = 'display:flex;align-items:center;gap:6px;flex-wrap:wrap';
    row.innerHTML = `
        <input type="text" id="${ok}" placeholder="${window.t('re.cf_opt_placeholder')}" value="${h(value)}"
            style="flex:1;min-width:180px;border:1.5px solid var(--color-border);border-radius:6px;padding:6px 9px;font-size:.83rem;font-family:var(--font);outline:none">
        <div id="sfopttr-${ok}"></div>
        <button onclick="document.getElementById('sfoptrow-${ok}').remove()"
            style="background:none;border:none;cursor:pointer;color:var(--color-muted);font-size:1.1rem;line-height:1;padding:2px 5px"
            onmouseenter="this.style.color='#EF4444'" onmouseleave="this.style.color=''">×</button>`;
    document.getElementById(`sfqopts-${qk}`).appendChild(row);

    if (oid && window.TranslationsUI && APP_STATE.availLangs) {
        window.TranslationsUI.attach(document.getElementById(`sfopttr-${ok}`), {
            kind: 'survey_option',
            targetId: oid,
            primaryLang: APP_STATE.primaryLang || 'sl',
            availableLangs: APP_STATE.availLangs,
            masterText: value,
            translations: oTrans,
        });
    }
}

function surveyAttachFormChips() {
    if (!window.TranslationsUI || !APP_STATE.availLangs) return;
    const formId = window.__SURVEY_FORM_ID__ || 0;
    if (!formId) return;
    const trans = window.__SURVEY_FORM_TRANSLATIONS__ || {};
    const primary = APP_STATE.primaryLang || 'sl';

    // Po-polje "translation chips" (title, description, thank_you)
    function ensureChipsAfter(elId, fieldKey, masterText) {
        const el = document.getElementById(elId);
        if (!el) return;
        let chips = el.parentNode.querySelector('.rz-tr-chips-' + elId);
        if (!chips) {
            chips = document.createElement('div');
            chips.className = 'rz-tr-chips-' + elId;
            chips.style.marginTop = '4px';
            el.parentNode.insertBefore(chips, el.nextSibling);
        }
        // Build per-lang map for this field only.
        const perLang = {};
        Object.keys(trans).forEach(function (lc) {
            if (trans[lc] && trans[lc][fieldKey]) perLang[lc] = trans[lc][fieldKey];
        });
        window.TranslationsUI.attach(chips, {
            kind: 'survey_form',
            targetId: formId,
            primaryLang: primary,
            availableLangs: APP_STATE.availLangs,
            masterText: masterText || el.value || '',
            translations: perLang,
            fieldKey: fieldKey,
            multiline: fieldKey !== 'title',
        });
    }
    ensureChipsAfter('sf-title',       'title');
    ensureChipsAfter('sf-description', 'description');
    ensureChipsAfter('sf-thankyou',    'thank_you_message');
}

function sfMoveQ(qk, dir) {
    const card = document.getElementById(`sfcard-${qk}`);
    const list = document.getElementById('sf-question-list');
    if (dir === -1 && card.previousElementSibling) list.insertBefore(card, card.previousElementSibling);
    else if (dir === 1 && card.nextElementSibling)  list.insertBefore(card.nextElementSibling, card);
}

function collectSurveyQuestions() {
    return Array.from(document.querySelectorAll('#sf-question-list > div')).map(card => {
        const qk   = card.id.replace('sfcard-','');
        const type = document.getElementById(`sfqtype-${qk}`).value;
        const qid  = card.dataset.qid ? parseInt(card.dataset.qid) : 0;
        const opts = [];
        card.querySelectorAll(`#sfqopts-${qk} > div[id^="sfoptrow-"]`).forEach(row => {
            const inp = row.querySelector('input[type=text]');
            const v = inp ? inp.value.trim() : '';
            if (!v) return;
            const o = { label: v };
            if (row.dataset.oid) o.id = parseInt(row.dataset.oid);
            opts.push(o);
        });
        const obj = {
            question_text: document.getElementById(`sfqt-${qk}`).value.trim(),
            type, is_required: document.getElementById(`sfqreq-${qk}`).checked ? 1 : 0, options: opts,
        };
        if (qid) obj.id = qid;
        return obj;
    });
}

async function saveSurveyForm() {
    const btn = document.getElementById('btn-save-survey');
    const st  = document.getElementById('sf-save-status');
    btn.disabled = true; st.style.color=''; st.textContent = window.t('re.survey_status_saving');
    try {
        await apiCall('POST', '/api/survey.php?action=save_form', {
            restaurant_id:    REST_ID,
            title:            document.getElementById('sf-title').value.trim(),
            description:      document.getElementById('sf-description').value.trim(),
            thank_you_message:document.getElementById('sf-thankyou').value.trim(),
            send_enabled:     document.getElementById('sf-send-enabled').checked ? 1 : 0,
            send_delay_hours: parseInt(document.getElementById('sf-delay').value) || 2,
            include_thankyou: document.getElementById('sf-incl-thankyou').checked ? 1 : 0,
            include_survey:   document.getElementById('sf-incl-survey').checked ? 1 : 0,
            questions:        collectSurveyQuestions(),
        });
        st.style.color = '#059669'; st.textContent = window.t('re.toast_saved');
        setTimeout(() => st.textContent = '', 3000);
        // Po shranitvi ponovno naloži (potrebno za nova vprašanja, ki dobijo ID-je za prevode)
        if (typeof surveyLoadForm === 'function') surveyLoadForm();
    } catch(e) {
        st.style.color = '#EF4444'; st.textContent = e.message || window.t('common.error');
    }
    btn.disabled = false;
}

// ── Odgovori ──────────────────────────────────────────────────
async function loadSurveyResults() {
    const from = document.getElementById('sr-from')?.value || '';
    const to   = document.getElementById('sr-to')?.value   || '';
    const params = new URLSearchParams({ action:'get_results', restaurant_id: REST_ID });
    if (from) params.set('date_from', from);
    if (to)   params.set('date_to',   to);
    const list = document.getElementById('sr-list');
    list.innerHTML = `<p style="font-size:.85rem;color:var(--color-muted)">${window.t('common.loading')}</p>`;
    try {
        const rows = await apiCall('GET', `/api/survey.php?${params}`);
        renderSurveyResults(rows || []);
    } catch(e) { list.innerHTML = `<p style="color:#EF4444;font-size:.85rem">${h(e.message)}</p>`; }
}

function renderSurveyResults(rows) {
    const list = document.getElementById('sr-list');
    if (!rows.length) { list.innerHTML=`<p style="font-size:.85rem;color:var(--color-muted)">${window.t('re.survey_no_responses')}</p>`; return; }
    const CONSENT = { public:window.t('re.survey_consent_public'), anonymous:window.t('re.survey_consent_anon'), private:window.t('re.survey_consent_private') };
    const loc = (window.__T__ && window.__T__['common.locale']) || 'sl-SI';
    list.innerHTML = `
    <table style="width:100%;border-collapse:collapse;font-size:.85rem">
        <thead><tr style="border-bottom:2px solid var(--color-border)">
            <th style="padding:7px 10px;text-align:left;font-size:.75rem;font-weight:600;color:var(--color-muted)">${window.t('re.survey_col_date')}</th>
            <th style="padding:7px 10px;text-align:left;font-size:.75rem;font-weight:600;color:var(--color-muted)">${window.t('re.survey_col_guest')}</th>
            <th style="padding:7px 10px;text-align:left;font-size:.75rem;font-weight:600;color:var(--color-muted)">${window.t('re.survey_col_consent')}</th>
            <th style="padding:7px 10px;text-align:left;font-size:.75rem;font-weight:600;color:var(--color-muted)">${window.t('re.survey_col_status')}</th>
        </tr></thead>
        <tbody>${rows.map(r => {
            const submitted = r.submitted_at ? new Date(r.submitted_at.replace(' ','T')).toLocaleDateString(loc) : '–';
            const status = r.submitted_at
                ? `<span style="color:#059669;font-size:.78rem;font-weight:600">${window.t('re.survey_status_submitted')}</span>`
                : r.email_sent_at
                    ? `<span style="color:#92400E;font-size:.78rem">${window.t('re.survey_status_email_sent')}</span>`
                    : `<span style="color:var(--color-muted);font-size:.78rem">${window.t('re.survey_status_pending')}</span>`;
            const consent = r.consent ? CONSENT[r.consent] || r.consent : '–';
            return `<tr style="border-bottom:1px solid var(--color-bg);cursor:${r.submitted_at?'pointer':'default'}"
                onclick="${r.submitted_at ? `openSrDetail(${r.id})` : ''}">
                <td style="padding:8px 10px">${submitted}</td>
                <td style="padding:8px 10px;font-weight:500">${h(r.guest_name||'–')}</td>
                <td style="padding:8px 10px">${consent}</td>
                <td style="padding:8px 10px">${status}</td>
            </tr>`;
        }).join('')}</tbody>
    </table>`;
}

async function openSrDetail(id) {
    const overlay = document.getElementById('sr-detail-overlay');
    const content = document.getElementById('sr-detail-content');
    overlay.style.display = 'flex';
    content.innerHTML = `<p style="text-align:center;padding:30px;color:var(--color-muted)">${window.t('common.loading')}</p>`;
    try {
        const { response: sr, answers } = await apiCall('GET', `/api/survey.php?action=get_response_detail&id=${id}`);
        const CONSENT_MAP = { public:window.t('re.survey_consent_public_full'), anonymous:window.t('re.survey_consent_anon'), private:window.t('re.survey_consent_private_full') };
        const loc = (window.__T__ && window.__T__['common.locale']) || 'sl-SI';
        const submitted = sr.submitted_at ? new Date(sr.submitted_at.replace(' ','T')).toLocaleString(loc) : '–';
        let html = `<div style="font-size:1rem;font-weight:700;color:var(--color-text);margin-bottom:4px">${window.t('re.survey_detail_title')}</div>
            <div style="font-size:.8rem;color:var(--color-muted);margin-bottom:18px">${window.t('re.survey_detail_submitted')} ${submitted} · ${window.t('re.survey_detail_consent')} ${CONSENT_MAP[sr.consent]||'–'}</div>`;
        (answers||[]).forEach(a => {
            html += `<div style="margin-bottom:14px">
                <div style="font-size:.8rem;font-weight:600;color:var(--color-muted);margin-bottom:4px">${h(a.question_text)}</div>`;
            if (a.type === 'rating' && a.answer_text) {
                const val = parseInt(a.answer_text);
                let stars = '';
                for (let i=1;i<=5;i++) stars += `<svg width="16" height="16" viewBox="0 0 24 24" fill="${i<=val?'#F59E0B':'none'}" stroke="${i<=val?'#F59E0B':'#D1D5DB'}" stroke-width="1.5" style="display:inline-block"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`;
                html += `<div>${stars} <span style="font-size:.8rem;color:var(--color-muted)">${val}/5</span></div>`;
            } else if (a.option_labels && a.option_labels.length) {
                html += `<div style="font-size:.9rem;color:var(--color-text)">${a.option_labels.map(l=>h(l)).join(', ')}</div>`;
            } else {
                html += `<div style="font-size:.9rem;color:${a.answer_text?'var(--color-text)':'var(--color-muted)'};white-space:pre-wrap">${a.answer_text ? h(a.answer_text) : '–'}</div>`;
            }
            html += '</div>';
        });
        content.innerHTML = html;
    } catch(e) { content.innerHTML = `<p style="color:#EF4444">${h(e.message)}</p>`; }
}

function exportSurveyCsv() {
    const from = document.getElementById('sr-from')?.value || '';
    const to   = document.getElementById('sr-to')?.value   || '';
    const params = new URLSearchParams({ action:'export_csv', restaurant_id: REST_ID });
    if (from) params.set('date_from', from);
    if (to)   params.set('date_to',   to);
    window.location.href = `${BASE}/api/survey.php?${params}`;
}

// ── Mize ─────────────────────────────────────────────────────
<?php if ($hasTableMgmt): ?>
let tablesLoaded = false;
let tablesData = { areas: [], tables: [], merge_groups: [] };

function applyMergeableSectionVisibility(enabled) {
    const sec = document.getElementById('merge-groups-section');
    if (sec) sec.style.display = enabled ? 'none' : '';
}

async function saveTableMergeableSetting(enabled) {
    try {
        await apiCall('PUT', `/api/restaurants.php?id=${REST_ID}`, { all_tables_mergeable: enabled ? 1 : 0 });
        applyMergeableSectionVisibility(enabled);
        toast(enabled ? window.t('re.toast_merge_enabled') : window.t('re.toast_merge_disabled'));
    } catch(e) {
        toast(e.message, 'error');
        document.getElementById('all-tables-mergeable-toggle').checked = !enabled;
    }
}

// Inicializacija: skrij/prikaži ob nalaganju strani
applyMergeableSectionVisibility(document.getElementById('all-tables-mergeable-toggle')?.checked);

async function loadTables() {
    tablesLoaded = true;
    try {
        tablesData = await apiCall('GET', `/api/tables.php?restaurant_id=${REST_ID}`);
        renderAreasWithTables();
        renderMergeGroups();
    } catch(e) {
        document.getElementById('areas-list').innerHTML = `<p style="color:#EF4444;font-size:.875rem">${window.t('re.err_load')}: ${h(e.message)}</p>`;
    }
}

function renderTableRow(t) {
    const activeChip = t.is_active
        ? `<span class="rz-chip rz-chip-ok">${window.t('re.table_chip_active')}</span>`
        : `<span class="rz-chip rz-chip-mute">${window.t('re.table_chip_inactive')}</span>`;
    return `<div style="display:grid;grid-template-columns:20px 1fr auto auto auto 26px;gap:10px;align-items:center;padding:8px 12px;border:1px solid var(--line);border-radius:8px;background:var(--bg-elev);opacity:${t.is_active?1:.5}">
        <span style="color:var(--ink-mute)">└</span>
        <span style="font-size:13px;font-weight:500">${h(t.name)}</span>
        <span class="rz-chip" style="background:color-mix(in oklab,var(--info) 12%,transparent);color:var(--info);border-color:transparent">${t.capacity} oseb</span>
        ${activeChip}
        <button onclick="editTable(${t.id})" class="rz-btn" style="padding:4px 10px;font-size:11px">${window.t('common.edit')}</button>
        <button onclick="deleteTable(${t.id})" class="rz-iconbtn" style="width:26px;height:26px" title="${window.t('common.delete')}"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>`;
}

function renderAreasWithTables() {
    const el = document.getElementById('areas-list');

    // Rescue table-form before innerHTML wipes it (it may have been moved inside areas-list)
    const tf = document.getElementById('table-form');
    if (tf && el.contains(tf)) {
        el.insertAdjacentElement('afterend', tf);
        tf.style.display = 'none';
    }

    const tablesByArea = {};
    const noAreaTables = [];
    tablesData.tables.forEach(t => {
        if (t.area_id) { (tablesByArea[t.area_id] = tablesByArea[t.area_id] || []).push(t); }
        else { noAreaTables.push(t); }
    });

    let html = '';

    if (!tablesData.areas.length && !tablesData.tables.length) {
        el.innerHTML = `<p style="font-size:13px;color:var(--ink-mute)">${window.t('re.no_areas_hint')}</p>
            <div style="margin-top:10px"><button onclick="showTableForm(null,null)" class="rz-btn rz-btn-primary" style="font-size:12px;padding:5px 12px">+ ${window.t('re.btn_table_no_area')}</button></div>`;
        return;
    }

    // Cone s svojimi mizami
    tablesData.areas.forEach(a => {
        const tables = tablesByArea[a.id] || [];
        const activeChip = a.is_active
            ? `<span class="rz-chip rz-chip-ok">AKTIVNA</span>`
            : `<span class="rz-chip rz-chip-mute">NEAKTIVNA</span>`;
        html += `<div id="area-block-${a.id}" data-area-id="${a.id}" data-area-name="${h(a.name)}" style="border:1px solid var(--line);border-radius:10px;overflow:hidden">
            <div style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--bg-sunken);border-bottom:1px solid var(--line);flex-wrap:wrap">
                <strong style="font-size:14px;color:var(--ink)">${h(a.name)}</strong>
                ${activeChip}
                <div class="rz-area-tr" data-area-tr-id="${a.id}"></div>
                <span style="font-size:12px;color:var(--ink-mute)">${window.t('re.area_tables_count', {count: tables.length})}</span>
                <div style="margin-left:auto;display:flex;gap:6px">
                    <button onclick="showTableForm(null,${a.id})" class="rz-btn rz-btn-primary" style="padding:5px 10px;font-size:12px"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> ${window.t('re.btn_add_table')}</button>
                    <button onclick="editArea(${a.id})" class="rz-btn" style="padding:5px 10px;font-size:12px">${window.t('re.btn_edit_area')}</button>
                    <button onclick="deleteArea(${a.id})" class="rz-iconbtn" style="width:26px;height:26px" title="${window.t('common.delete')}"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
                </div>
            </div>
            <div style="padding:10px;display:flex;flex-direction:column;gap:6px">`;
        if (tables.length) {
            html += tables.map(renderTableRow).join('');
        } else {
            html += `<p style="font-size:13px;color:var(--ink-mute);margin:0">${window.t('re.no_tables_in_area')}</p>`;
        }
        html += `</div></div>`;
    });

    // Mize brez cone
    if (noAreaTables.length || !tablesData.areas.length) {
        const headerLabel = tablesData.areas.length ? window.t('re.no_area_label') : window.t('re.tab_tables');
        html += `<div id="area-block-no-area" style="border:1px dashed var(--line);border-radius:10px;overflow:hidden">
            <div style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--bg-sunken)">
                <strong style="font-size:14px;color:var(--ink-mute)">${headerLabel}</strong>
                <span style="font-size:12px;color:var(--ink-mute)">${window.t('re.area_tables_count', {count: noAreaTables.length})}</span>
                <div style="margin-left:auto">
                    <button onclick="showTableForm(null,null)" class="rz-btn rz-btn-primary" style="padding:5px 10px;font-size:12px"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> ${window.t('re.btn_add_table')}</button>
                </div>
            </div>`;
        if (noAreaTables.length) {
            html += `<div style="padding:10px;display:flex;flex-direction:column;gap:6px">${noAreaTables.map(renderTableRow).join('')}</div>`;
        }
        html += `</div>`;
    }

    el.innerHTML = html;

    // Attach prevodne chips za vsako cono
    if (window.TranslationsUI && APP_STATE.availLangs) {
        document.querySelectorAll('.rz-area-tr[data-area-tr-id]').forEach(function (host) {
            const aid = parseInt(host.dataset.areaTrId);
            const block = document.getElementById(`area-block-${aid}`);
            const masterName = block ? (block.dataset.areaName || '') : '';
            // Lazy-load prevodov ob attach (iz translations.php)
            fetch(`${APP_STATE.base}/api/translations.php?action=get&kind=area&id=${aid}`, { credentials: 'same-origin' })
                .then(r => r.json()).then(j => {
                    const trans = {};
                    if (j && j.success && j.data) {
                        Object.keys(j.data).forEach(lc => { if (j.data[lc] && j.data[lc].name) trans[lc] = j.data[lc].name; });
                    }
                    window.TranslationsUI.attach(host, {
                        kind: 'area',
                        targetId: aid,
                        primaryLang: APP_STATE.primaryLang || 'sl',
                        availableLangs: APP_STATE.availLangs,
                        masterText: masterName,
                        translations: trans,
                        fieldKey: 'name',
                    });
                }).catch(() => {});
        });
    }
}

function renderMergeGroups() {
    const el = document.getElementById('merge-groups-list');
    if (!tablesData.merge_groups.length) { el.innerHTML = `<p style="font-size:13px;color:var(--ink-mute)">${window.t('re.no_merge_groups')}</p>`; return; }
    el.innerHTML = tablesData.merge_groups.map(g => `
        <div style="display:grid;grid-template-columns:1fr auto auto auto 26px;gap:10px;align-items:center;padding:12px 14px;border:1px solid var(--line);border-radius:8px">
            <strong style="font-size:13px">${g.name ? h(g.name) : `<em style="color:var(--ink-mute)">${window.t('re.no_name')}</em>`}</strong>
            <span class="rz-chip" style="background:color-mix(in oklab,var(--warning) 18%,transparent);color:var(--warning);border-color:transparent">${g.member_names.join(' + ')}</span>
            <span class="rz-chip" style="background:color-mix(in oklab,var(--success) 12%,transparent);color:var(--success);border-color:transparent">${window.t('re.merge_capacity', {n: g.total_capacity})}</span>
            <button onclick="editMergeGroup(${g.id})" class="rz-btn" style="padding:4px 10px;font-size:11px">${window.t('common.edit')}</button>
            <button onclick="deleteMergeGroup(${g.id})" class="rz-iconbtn" style="width:26px;height:26px" title="${window.t('common.delete')}"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
    `).join('');
}

// ─ Area form ─
function showAreaForm(editId=null) {
    const f = document.getElementById('area-form');
    const existing = editId ? tablesData.areas.find(a=>a.id===editId) : null;
    document.getElementById('area-name-input').value = existing?.name || '';
    document.getElementById('area-edit-id').value = editId || '';
    document.getElementById('area-form-title').textContent = editId ? window.t('re.area_form_edit') : window.t('re.area_form_new');
    f.style.display = 'block';
    document.getElementById('area-name-input').focus();
}
function cancelAreaForm() { document.getElementById('area-form').style.display='none'; }
function editArea(id) { showAreaForm(id); }

async function saveArea() {
    const name   = document.getElementById('area-name-input').value.trim();
    const editId = document.getElementById('area-edit-id').value;
    if (!name) { toast(window.t('re.err_area_name'),'error'); return; }
    try {
        if (editId) {
            await apiCall('PUT', `/api/tables.php?area_id=${editId}`, { name });
        } else {
            await apiCall('POST', '/api/tables.php', { action:'create_area', restaurant_id:REST_ID, name });
        }
        cancelAreaForm();
        await loadTables();
        toast(editId ? window.t('re.toast_area_updated') : window.t('re.toast_area_added'));
    } catch(e) { toast(e.message,'error'); }
}

async function deleteArea(id) {
    if (!confirm(window.t('re.confirm_delete_area'))) return;
    try {
        await apiCall('DELETE', `/api/tables.php?area_id=${id}`);
        tablesLoaded = false; await loadTables();
        toast(window.t('re.toast_area_deleted'));
    } catch(e) { toast(e.message,'error'); }
}

// ─ Table form ─
function populateAreaSelect(selectedId=null) {
    const sel = document.getElementById('table-area-select');
    sel.innerHTML = `<option value="">${window.t('re.table_no_area')}</option>` +
        tablesData.areas.map(a => `<option value="${a.id}" ${selectedId==a.id?'selected':''}>${h(a.name)}</option>`).join('');
}

// presetAreaId: cona, v katero se doda nova miza (ko kliknemo "+ Miza" znotraj cone)
function showTableForm(editId=null, presetAreaId=null) {
    const f = document.getElementById('table-form');
    const t = editId ? tablesData.tables.find(x=>x.id===editId) : null;
    document.getElementById('table-name-input').value = t?.name || '';
    document.getElementById('table-cap-input').value  = t?.capacity || 2;
    document.getElementById('table-edit-id').value    = editId || '';
    document.getElementById('table-form-anchor').value = presetAreaId || '';
    document.getElementById('table-form-title').textContent = editId ? window.t('re.table_form_edit') : window.t('re.table_form_new');
    populateAreaSelect(t?.area_id ?? presetAreaId);

    // Forma se prikaže pod pravilno cono (ali na koncu, če brez cone)
    const anchorId = presetAreaId || 'no-area';
    const anchor = document.getElementById(`area-block-${anchorId}`) || document.getElementById('areas-list');
    anchor.after ? anchor.after(f) : anchor.parentNode.appendChild(f);

    f.style.display = 'block';
    document.getElementById('table-name-input').focus();
}
function cancelTableForm() { document.getElementById('table-form').style.display='none'; }
function editTable(id) {
    const t = tablesData.tables.find(x=>x.id===id);
    showTableForm(id, t?.area_id || null);
}

async function saveTable() {
    const name     = document.getElementById('table-name-input').value.trim();
    const capacity = parseInt(document.getElementById('table-cap-input').value) || 2;
    const areaId   = document.getElementById('table-area-select').value || null;
    const editId   = document.getElementById('table-edit-id').value;
    if (!name) { toast(window.t('re.err_table_name'),'error'); return; }
    try {
        if (editId) {
            await apiCall('PUT', `/api/tables.php?table_id=${editId}`, { name, capacity, area_id: areaId });
        } else {
            await apiCall('POST', '/api/tables.php', { action:'create_table', restaurant_id:REST_ID, name, capacity, area_id: areaId });
        }
        cancelTableForm();
        tablesLoaded = false; await loadTables();
        toast(editId ? window.t('re.toast_table_updated') : window.t('re.toast_table_added'));
    } catch(e) { toast(e.message,'error'); }
}

async function deleteTable(id) {
    if (!confirm(window.t('re.confirm_delete_table'))) return;
    try {
        await apiCall('DELETE', `/api/tables.php?table_id=${id}`);
        tablesLoaded = false; await loadTables();
        toast(window.t('re.toast_table_deleted'));
    } catch(e) { toast(e.message,'error'); }
}

// ─ Merge group form ─
function showMergeForm(editId=null) {
    const f  = document.getElementById('merge-form');
    const g  = editId ? tablesData.merge_groups.find(x=>x.id===editId) : null;
    document.getElementById('mg-name-input').value = g?.name || '';
    document.getElementById('mg-edit-id').value    = editId || '';
    const cbWrap = document.getElementById('mg-tables-checkboxes');
    cbWrap.innerHTML = tablesData.tables.filter(t=>t.is_active).map(t => `
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;background:var(--bg-elev);border:1px solid var(--line);border-radius:8px;padding:6px 10px">
            <input type="checkbox" value="${t.id}" ${g?.member_ids?.includes(t.id)?'checked':''} style="accent-color:var(--accent)">
            ${h(t.name)} <span style="font-size:11px;color:var(--ink-mute)">(${t.capacity} os.)</span>
        </label>
    `).join('');
    f.style.display = 'block';
}
function cancelMergeForm() { document.getElementById('merge-form').style.display='none'; }
function editMergeGroup(id) { showMergeForm(id); }

async function saveMergeGroup() {
    const name    = document.getElementById('mg-name-input').value.trim() || null;
    const editId  = document.getElementById('mg-edit-id').value;
    const checked = [...document.querySelectorAll('#mg-tables-checkboxes input:checked')].map(i=>parseInt(i.value));
    if (checked.length < 2) { toast(window.t('re.err_merge_min_tables'),'error'); return; }
    try {
        if (editId) {
            await apiCall('PUT', `/api/tables.php?merge_group_id=${editId}`, { name, member_table_ids: checked });
        } else {
            await apiCall('POST', '/api/tables.php', { action:'create_merge_group', restaurant_id:REST_ID, name, member_table_ids: checked });
        }
        cancelMergeForm();
        tablesLoaded = false; await loadTables();
        toast(editId ? window.t('re.toast_merge_group_updated') : window.t('re.toast_merge_group_added'));
    } catch(e) { toast(e.message,'error'); }
}

async function deleteMergeGroup(id) {
    if (!confirm(window.t('re.confirm_delete_merge_group'))) return;
    try {
        await apiCall('DELETE', `/api/tables.php?merge_group_id=${id}`);
        tablesLoaded = false; await loadTables();
        toast(window.t('re.toast_merge_group_deleted'));
    } catch(e) { toast(e.message,'error'); }
}
<?php else: ?>
let tablesLoaded = false;
function loadTables() { tablesLoaded = true; }
<?php endif; ?>
// Ob zagonu aktiviraj tab iz hash-a
(function() {
    const hash = location.hash.replace('#', '');
    const valid = ['splosno','urnik','booking','zaposleni','polja','anketa','mize','branding'];
    if (hash && valid.includes(hash)) activateTab(hash);
})();

<?php if ($hasBrandingTab): ?>
// ── BRANDING tab ─────────────────────────────────────────────
(function() {
    const REST_ID = <?= (int)$rest['id'] ?>;
    const BASE = '<?= BASE_PATH ?>';
    const preview = document.getElementById('brand-preview');

    function postPreview(msg) {
        if (!preview || !preview.contentWindow) return;
        try { preview.contentWindow.postMessage({ source: 'rz-branding', ...msg }, '*'); } catch (e) {}
    }

    // Sync color picker <-> hex text input
    function bindColor(picker, hex) {
        const p = document.getElementById(picker);
        const h = document.getElementById(hex);
        if (!p || !h) return;
        p.addEventListener('input', () => { h.value = p.value.toUpperCase(); livePreview(); });
        h.addEventListener('input', () => {
            const v = h.value.trim();
            if (/^#[0-9A-Fa-f]{6}$/.test(v)) { p.value = v; livePreview(); }
        });
    }
    bindColor('brand-primary', 'brand-primary-hex');
    bindColor('brand-secondary', 'brand-secondary-hex');

    function getValues() {
        const primary   = document.getElementById('brand-primary-hex')?.value.trim() || null;
        const secondary = document.getElementById('brand-secondary-hex')?.value.trim() || null;
        const hide      = document.getElementById('brand-hide')?.checked ? 1 : 0;
        return { primary, secondary, hide };
    }

    function livePreview() {
        const v = getValues();
        postPreview({ type: 'colors', primary: v.primary, secondary: v.secondary });
        postPreview({ type: 'hide', hide: v.hide });
    }

    document.getElementById('brand-colors-reset')?.addEventListener('click', () => {
        const p = document.getElementById('brand-primary'); const ph = document.getElementById('brand-primary-hex');
        const s = document.getElementById('brand-secondary'); const sh = document.getElementById('brand-secondary-hex');
        if (p) p.value = '#1B4332'; if (ph) ph.value = '#1B4332';
        if (s) s.value = '#C4704B'; if (sh) sh.value = '#C4704B';
        livePreview();
    });

    document.getElementById('brand-hide')?.addEventListener('change', livePreview);

    document.getElementById('brand-save')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true;
        const oldText = btn.textContent;
        btn.textContent = window.t('common.saving') || 'Shranjujem...';
        try {
            const v = getValues();
            await apiCall('PUT', `/api/restaurants.php?id=${REST_ID}`, {
                brand_primary:   v.primary || null,
                brand_secondary: v.secondary || null,
                hide_branding:   v.hide,
            });
            showSuccess(window.t('common.saved') || 'Shranjeno.');
            // Reload preview za pravo backend rendering
            if (preview) preview.src = preview.src;
        } catch (err) {
            showError(err.message || 'Napaka pri shranjevanju.');
        } finally {
            btn.disabled = false;
            btn.textContent = oldText;
        }
    });

    // Logo upload
    const logoInput = document.getElementById('brand-logo-input');
    if (logoInput) {
        logoInput.addEventListener('change', async () => {
            const file = logoInput.files?.[0];
            if (!file) return;
            const errBox = document.getElementById('brand-logo-err');
            errBox.style.display = 'none';

            if (file.size > 500 * 1024) {
                errBox.textContent = window.t('re.brand_logo_err_size') || 'Datoteka je prevelika (max 500 KB).';
                errBox.style.display = 'block';
                logoInput.value = '';
                return;
            }
            const fd = new FormData();
            fd.append('logo', file);
            fd.append('restaurant_id', REST_ID);
            try {
                const res = await fetch(BASE + '/api/upload_logo.php', {
                    method: 'POST', credentials: 'same-origin', body: fd,
                });
                const j = await res.json();
                if (!j.success) throw new Error(j.error || 'Upload failed');
                // Posodobi preview slike
                const preview = document.getElementById('brand-logo-preview');
                preview.innerHTML = `<img id="brand-logo-img" src="${BASE}/${j.data.path}?v=${Date.now()}" alt="Logo" style="max-width:200px;max-height:60px;object-fit:contain"><button type="button" id="brand-logo-remove" class="btn btn-ghost btn-danger-sm" style="margin-left:auto">${window.t('re.brand_logo_remove') || 'Odstrani'}</button>`;
                bindRemove();
                // Reload preview iframe
                const f = document.getElementById('brand-preview'); if (f) f.src = f.src;
                showSuccess(window.t('common.saved') || 'Naloženo.');
            } catch (err) {
                errBox.textContent = err.message;
                errBox.style.display = 'block';
            } finally {
                logoInput.value = '';
            }
        });
    }

    function bindRemove() {
        document.getElementById('brand-logo-remove')?.addEventListener('click', async () => {
            if (!confirm(window.t('re.brand_logo_confirm_remove') || 'Odstrani logotip?')) return;
            try {
                await apiCall('DELETE', `/api/upload_logo.php?id=${REST_ID}`, null);
                const preview = document.getElementById('brand-logo-preview');
                preview.innerHTML = `<span style="color:var(--ink-mute);font-size:13px">${window.t('re.brand_logo_empty') || 'Še ni naloženega logotipa.'}</span>`;
                const f = document.getElementById('brand-preview'); if (f) f.src = f.src;
                showSuccess(window.t('common.saved') || 'Odstranjeno.');
            } catch (err) {
                showError(err.message || 'Napaka.');
            }
        });
    }
    bindRemove();
})();
<?php endif; ?>
</script>
<script src="<?= BASE_PATH ?>/assets/js/translations_ui.js?v=1"></script>
<script>window.APP_BASE = '<?= BASE_PATH ?>';</script>
<script src="<?= BASE_PATH ?>/assets/js/rezble-shell.js?v=1"></script>

</main>
</div><!-- /rz-app -->
</body>
</html>
