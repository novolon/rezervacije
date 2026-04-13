<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';

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
    header('Location: ' . BASE_PATH . '/pages/admin.php'); exit;
}

$stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = ?");
$stmt->execute([$restId]);
$rest = $stmt->fetch();
if (!$rest) { header('Location: ' . BASE_PATH . '/pages/admin.php'); exit; }

$fullName  = $_SESSION['full_name'];
$activeTab = $_GET['tab'] ?? 'splosno';
$hasSurvey      = user_has_feature($pdo, (int)$_SESSION['user_id'], 'survey');
$hasSurveyEdit  = user_has_feature($pdo, (int)$_SESSION['user_id'], 'survey_edit');
$hasTableMgmt   = user_has_feature($pdo, (int)$_SESSION['user_id'], 'table_management');
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Uredi restavracijo – <?= h($rest['name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css?v=4">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin.css?v=2">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/modal.css?v=2">
    <style>
        .rest-edit-wrap { max-width: 780px; margin: 0 auto; padding: calc(var(--header-h) + 24px) 20px 60px; }
        body.has-trial-banner .rest-edit-wrap { padding-top: calc(var(--header-h) + var(--banner-h) + 24px); }

        .rest-edit-header { display:flex; align-items:center; gap:14px; margin-bottom:28px; flex-wrap:wrap; }
        .rest-edit-back { display:flex; align-items:center; gap:6px; color:var(--color-muted); font-size:.85rem; font-weight:500; text-decoration:none; transition:color var(--transition); }
        .rest-edit-back:hover { color:var(--color-text); }
        .rest-edit-title { font-size:1.4rem; font-weight:700; color:var(--color-text); letter-spacing:-.02em; margin:0; flex:1; }
        .rest-color-dot { width:14px; height:14px; border-radius:50%; display:inline-block; vertical-align:middle; margin-right:6px; border:2px solid rgba(0,0,0,.1); }

        /* Tabs */
        .re-tabs { display:flex; gap:2px; border-bottom:2px solid var(--color-border); margin-bottom:28px; overflow-x:auto; }
        .re-tab { padding:10px 16px; font-size:.85rem; font-weight:600; font-family:var(--font); color:var(--color-muted); background:transparent; border:none; cursor:pointer; border-bottom:2px solid transparent; transition:color var(--transition),border-color var(--transition); white-space:nowrap; }
        .re-tab:hover { color:var(--color-text); }
        .re-tab.active { color:var(--color-accent); border-bottom-color:var(--color-accent); }
        .re-panel { display:none; } .re-panel.active { display:block; }

        /* Sekcija znotraj taba */
        .re-section { background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:22px 24px; margin-bottom:20px; }
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

        /* Day schedule */
        .day-row { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid var(--color-border); }
        .day-row:last-child { border-bottom:none; }
        .day-label-wrap { display:flex; align-items:center; gap:8px; width:140px; flex-shrink:0; cursor:pointer; }
        .day-times { display:flex; align-items:center; gap:6px; }
        .day-time-input { border:1.5px solid var(--color-border); border-radius:8px; padding:7px 10px; font-size:.85rem; font-family:var(--font); color:var(--color-text); outline:none; width:90px; }
        .day-time-input:focus { border-color:var(--color-accent); }

        /* CF type/applies badges */
        .cf-badge { font-size:.7rem; padding:2px 7px; border-radius:4px; font-weight:600; }
        .cf-badge-type { background:#E5E7EB; color:#374151; }
        .cf-badge-pub  { background:#DBEAFE; color:#1D4ED8; }
        .cf-badge-int  { background:#D1FAE5; color:#065F46; }
        .cf-badge-both { background:#EDE9FE; color:#5B21B6; }
        .cf-badge-req  { background:#FEE2E2; color:#DC2626; }

        @media (max-width:600px) {
            .rest-edit-wrap { padding: calc(var(--header-h) + 16px) 12px 40px; }
            .re-section { padding:16px; }
            .admin-field-row { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>

<header class="app-header">
    <a href="<?= BASE_PATH ?>/pages/main.php" class="header-logo" style="flex-shrink:0">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
            <rect width="28" height="28" rx="7" fill="#F59E0B"/>
            <path d="M7 10h14M7 14h14M7 18h9" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <?= h(APP_NAME) ?>
        <?= plan_badge($_SESSION['plan_slug'] ?? 'trial') ?>
    </a>
    <div class="header-restaurant">
        <span style="color:rgba(255,255,255,.5);font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600"><?= h($rest['name']) ?></span>
    </div>
    <div class="header-actions">
        <span class="header-user">👤 <?= h($fullName) ?></span>
        <a href="<?= BASE_PATH ?>/pages/main.php" class="btn-header btn-header-admin">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            Razpored
        </a>
        <a href="<?= BASE_PATH ?>/pages/admin.php" class="btn-header btn-header-admin">Admin</a>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
    </div>
    <button class="hamburger-btn" id="hamburger-btn" onclick="document.getElementById('mobile-nav').classList.toggle('open')">
        <span></span><span></span><span></span>
    </button>
</header>
<div class="mobile-nav" id="mobile-nav">
    <div class="mobile-nav-user">👤 <?= h($fullName) ?></div>
    <a href="<?= BASE_PATH ?>/pages/main.php" class="btn-header btn-header-admin">Razpored</a>
    <a href="<?= BASE_PATH ?>/pages/admin.php" class="btn-header btn-header-admin">Admin</a>
    <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
</div>

<?php require_once '../includes/trial_banner.php'; ?>

<div class="rest-edit-wrap">

    <div class="rest-edit-header">
        <a href="<?= BASE_PATH ?>/pages/admin.php" class="rest-edit-back">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
            Admin
        </a>
        <h1 class="rest-edit-title">
            <span class="rest-color-dot" id="hdr-color-dot" style="background:<?= h($rest['color']) ?>"></span>
            <?= h($rest['name']) ?>
        </h1>
        <span class="badge <?= $rest['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $rest['is_active'] ? 'Aktivna' : 'Neaktivna' ?></span>
    </div>

    <div id="page-error" style="display:none;background:#FEE2E2;color:#991B1B;padding:12px 16px;border-radius:var(--radius);margin-bottom:20px;font-size:.875rem"></div>
    <div id="page-success" style="display:none;background:#D1FAE5;color:#065F46;padding:12px 16px;border-radius:var(--radius);margin-bottom:20px;font-size:.875rem"></div>

    <!-- Tabs -->
    <div class="re-tabs">
        <button class="re-tab active" data-tab="splosno">Splošno</button>
        <button class="re-tab" data-tab="urnik">Urnik</button>
        <button class="re-tab" data-tab="booking">Spletne rezervacije</button>
        <button class="re-tab" data-tab="zaposleni">Zaposleni</button>
        <button class="re-tab" data-tab="polja">Polja po meri</button>
        <button class="re-tab" data-tab="anketa">Anketa</button>
        <button class="re-tab" data-tab="mize">Mize</button>
    </div>

    <!-- ── Tab: Splošno ────────────────────────────────────── -->
    <div id="panel-splosno" class="re-panel active">
        <div class="re-section">
            <div class="re-section-title">Osnovno</div>
            <div class="admin-form">
                <div class="admin-field-row">
                    <div class="admin-field">
                        <label>Ime restavracije *</label>
                        <input id="r-name" type="text" value="<?= h($rest['name']) ?>">
                    </div>
                    <div class="admin-field" style="max-width:140px">
                        <label>Barva</label>
                        <input id="r-color" type="color" value="<?= h($rest['color']) ?>"
                            style="height:42px;padding:4px;width:100%"
                            oninput="document.getElementById('hdr-color-dot').style.background=this.value">
                    </div>
                </div>
                <div class="admin-field-row">
                    <div class="admin-field">
                        <label>Trajanje rezervacije (min)</label>
                        <input id="r-duration" type="number" min="15" step="15" value="<?= (int)$rest['reservation_duration'] ?>">
                    </div>
                    <div class="admin-field" style="justify-content:flex-end;padding-top:18px">
                        <label class="toggle-wrap" style="cursor:pointer">
                            <span class="toggle">
                                <input type="checkbox" id="r-allow-custom" <?= $rest['allow_custom_duration'] ? 'checked' : '' ?>>
                                <span class="toggle-track"></span>
                            </span>
                            <span class="toggle-label">Sprememba trajanja per-rezervacija</span>
                        </label>
                    </div>
                </div>
                <div class="admin-field">
                    <label>Status</label>
                    <select id="r-active" style="max-width:200px">
                        <option value="1" <?= $rest['is_active'] ? 'selected' : '' ?>>Aktivna</option>
                        <option value="0" <?= !$rest['is_active'] ? 'selected' : '' ?>>Neaktivna</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="re-section">
            <div class="re-section-title">Kontaktni podatki</div>
            <p style="font-size:.825rem;color:var(--color-muted);margin:0 0 14px;line-height:1.5">Prikazani gostom v potrditvenih emailih in na strani za urejanje rezervacije.</p>
            <div class="admin-form">
                <div class="admin-field-row">
                    <div class="admin-field">
                        <label>Kontaktni email</label>
                        <input id="r-contact-email" type="email" placeholder="info@restavracija.si" value="<?= h($rest['contact_email'] ?? '') ?>">
                    </div>
                    <div class="admin-field">
                        <label>Kontaktna telefonska</label>
                        <input id="r-contact-phone" type="tel" placeholder="+386 1 234 56 78" value="<?= h($rest['contact_phone'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>
        <div class="re-save-bar">
            <button class="btn btn-primary" id="btn-save-splosno">Shrani</button>
        </div>
    </div>

    <!-- ── Tab: Urnik ──────────────────────────────────────── -->
    <div id="panel-urnik" class="re-panel">
        <div class="re-section">
            <div class="re-section-title">Urnik po dnevih</div>
            <div id="day-schedule-wrap">Nalagam...</div>
        </div>
        <div class="re-section">
            <div class="re-section-title">Blokirani datumi (izjeme)</div>
            <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
                <input type="date" id="blackout-date" min="<?= date('Y-m-d') ?>"
                    style="border:1.5px solid var(--color-border);border-radius:8px;padding:8px 12px;font-size:.875rem;font-family:var(--font);outline:none">
                <input type="text" id="blackout-reason" placeholder="Razlog (neobvezno)"
                    style="flex:1;min-width:160px;border:1.5px solid var(--color-border);border-radius:8px;padding:8px 12px;font-size:.875rem;font-family:var(--font);outline:none">
                <button class="btn btn-primary btn-sm" id="btn-add-blackout">+ Dodaj</button>
            </div>
            <div id="blackout-list">Nalagam...</div>
        </div>
        <div class="re-save-bar">
            <button class="btn btn-primary" id="btn-save-urnik">Shrani urnik</button>
        </div>
    </div>

    <!-- ── Tab: Spletne rezervacije ────────────────────────── -->
    <div id="panel-booking" class="re-panel">
        <div class="re-section">
            <div class="re-section-title">Nastavitve</div>
            <div class="admin-form">
                <label class="toggle-wrap" style="cursor:pointer">
                    <span class="toggle">
                        <input type="checkbox" id="r-booking-enabled" <?= $rest['booking_enabled'] ? 'checked' : '' ?>
                            onchange="document.getElementById('booking-settings').style.display=this.checked?'':'none'">
                        <span class="toggle-track"></span>
                    </span>
                    <span class="toggle-label">Omogoči spletne rezervacije</span>
                </label>

                <div id="booking-settings" style="display:<?= $rest['booking_enabled'] ? '' : 'none' ?>">
                    <div class="admin-field-row" style="margin-top:8px">
                        <div class="admin-field">
                            <label>Min. gostov</label>
                            <input id="r-min-guests" type="number" min="1" max="99" value="<?= (int)($rest['booking_min_guests'] ?? 2) ?>">
                        </div>
                        <div class="admin-field">
                            <label>Max. gostov</label>
                            <input id="r-max-guests" type="number" min="1" max="500" value="<?= (int)($rest['booking_max_guests'] ?? 10) ?>">
                        </div>
                    </div>
                    <div class="admin-field" style="margin-top:10px">
                        <label>Razmak med termini</label>
                        <select id="r-slot-interval" style="max-width:200px">
                            <?php foreach ([15,20,30,45,60,90,120] as $m):
                                $lbl = $m < 60 ? "{$m} min" : ($m === 60 ? '1 ura' : ($m === 90 ? '1,5 ure' : ($m/60).' uri'));
                                $cur = $rest['booking_slot_interval'] ?? $rest['reservation_duration'];
                            ?>
                            <option value="<?= $m ?>" <?= $cur == $m ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="margin-top:12px">
                        <label class="toggle-wrap" style="cursor:pointer">
                            <span class="toggle">
                                <input type="checkbox" id="r-auto-confirm" <?= ($rest['booking_auto_confirm'] ?? 1) ? 'checked' : '' ?>>
                                <span class="toggle-track"></span>
                            </span>
                            <span class="toggle-label">Samodejno potrdi rezervacije</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Samourejanje rezervacij (gost) ──────────────── -->
        <div class="re-section">
            <div class="re-section-title">Samourejanje (gost) <span style="background:#DBEAFE;color:#1D4ED8;font-size:.68rem;font-weight:700;padding:1px 7px;border-radius:20px;margin-left:4px">Advanced+</span></div>
            <p style="font-size:.825rem;color:var(--color-muted);margin:0 0 14px;line-height:1.5">
                Gost dobi link za urejanje/odpoved v potrditvenem emailu. Nastavite rok, do kdaj je to mogoče.
            </p>
            <div class="admin-form">
                <div class="admin-field-row" style="align-items:flex-start;gap:16px">
                    <div class="admin-field" style="flex:1">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                            <label style="margin:0">Gost lahko uredi</label>
                            <label class="toggle-wrap" style="cursor:pointer;margin:0">
                                <span class="toggle">
                                    <input type="checkbox" id="r-allow-edit" <?= ($rest['allow_guest_edit'] ?? 1) ? 'checked' : '' ?>>
                                    <span class="toggle-track"></span>
                                </span>
                            </label>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px" id="edit-cutoff-wrap">
                            <input type="number" id="r-edit-cutoff" min="1" max="168" style="width:70px;border:1.5px solid var(--color-border);border-radius:8px;padding:7px 10px;font-size:.875rem;font-family:var(--font);outline:none" value="<?= (int)($rest['guest_edit_cutoff_hours'] ?? 24) ?>">
                            <span style="font-size:.825rem;color:var(--color-muted)">ur pred terminom</span>
                        </div>
                    </div>
                    <div class="admin-field" style="flex:1">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                            <label style="margin:0">Gost lahko odpove</label>
                            <label class="toggle-wrap" style="cursor:pointer;margin:0">
                                <span class="toggle">
                                    <input type="checkbox" id="r-allow-cancel" <?= ($rest['allow_guest_cancel'] ?? 1) ? 'checked' : '' ?>>
                                    <span class="toggle-track"></span>
                                </span>
                            </label>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px" id="cancel-cutoff-wrap">
                            <input type="number" id="r-cancel-cutoff" min="1" max="168" style="width:70px;border:1.5px solid var(--color-border);border-radius:8px;padding:7px 10px;font-size:.875rem;font-family:var(--font);outline:none" value="<?= (int)($rest['guest_cancel_cutoff_hours'] ?? 4) ?>">
                            <span style="font-size:.825rem;color:var(--color-muted)">ur pred terminom</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Čakalna lista ────────────────────────────────────── -->
        <div class="re-section">
            <div class="re-section-title">Čakalna lista <span style="background:#FEF3C7;color:#92400E;font-size:.68rem;font-weight:700;padding:1px 7px;border-radius:20px;margin-left:4px">Advanced+</span></div>
            <p style="font-size:.825rem;color:var(--color-muted);margin:0 0 14px;line-height:1.5">
                Ko za izbrani datum ni prostih terminov, se gostom ponudi vpis na čakalno listo. Ko se sprosti termin, jih sistem samodejno obvesti.
            </p>
            <?php if (!user_has_feature($pdo, (int)$_SESSION['user_id'], 'waitlist')): ?>
            <p style="font-size:.825rem;color:#92400E;background:#FEF3C7;border-radius:8px;padding:10px 14px;margin:0">
                Čakalna lista je na voljo v paketu <strong>Advanced</strong> ali višjem.
                <a href="<?= BASE_PATH ?>/pages/billing.php" style="color:#92400E;font-weight:600">Nadgradi →</a>
            </p>
            <?php else: ?>
            <label class="toggle-wrap" style="cursor:pointer">
                <span class="toggle">
                    <input type="checkbox" id="r-waitlist-enabled" <?= ($rest['waitlist_enabled'] ?? 1) ? 'checked' : '' ?>>
                    <span class="toggle-track"></span>
                </span>
                <span class="toggle-label">Omogoči čakalno listo za javno rezervacijo</span>
            </label>
            <?php endif; ?>
        </div>

        <?php if ($rest['booking_token']): ?>
        <div class="re-section">
            <div class="re-section-title">Rezervacijska povezava</div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="text" id="booking-url-input" readonly
                    style="flex:1;min-width:200px;background:#F9FAFB;font-size:.8rem;color:#374151;border:1.5px solid var(--color-border);border-radius:8px;padding:9px 12px;font-family:monospace"
                    onclick="this.select()">
                <button class="btn btn-ghost" onclick="copyBookingUrl()">Kopiraj</button>
            </div>
        </div>
        <div class="re-section">
            <div class="re-section-title">Embed koda <span style="background:#EDE9FE;color:#5B21B6;font-size:.68rem;font-weight:700;padding:1px 7px;border-radius:20px;margin-left:4px">Premium</span></div>
            <p style="font-size:.825rem;color:var(--color-muted);margin-bottom:8px">Prilepite to kodo na katerokoli spletno stran.</p>
            <textarea id="embed-code-input" readonly rows="3" onclick="this.select()"
                style="width:100%;background:#F9FAFB;font-size:.75rem;color:#374151;font-family:monospace;line-height:1.6;resize:none;border:1.5px solid var(--color-border);border-radius:8px;padding:10px 12px"></textarea>
            <button class="btn btn-ghost btn-sm" style="margin-top:8px" onclick="copyEmbed()">Kopiraj embed kodo</button>
        </div>
        <?php endif; ?>

        <div class="re-save-bar">
            <button class="btn btn-primary" id="btn-save-booking">Shrani</button>
        </div>
    </div>

    <!-- ── Tab: Zaposleni ──────────────────────────────────── -->
    <div id="panel-zaposleni" class="re-panel">
        <div class="re-section">
            <div class="re-section-title">Seznam zaposlenih</div>
            <div id="staff-list" style="margin-bottom:14px">Nalagam...</div>
            <div style="display:flex;gap:8px">
                <input type="text" id="staff-name-input" placeholder="Ime zaposlenega"
                    style="flex:1;border:1.5px solid var(--color-border);border-radius:8px;padding:9px 12px;font-size:.875rem;font-family:var(--font);outline:none"
                    onkeydown="if(event.key==='Enter')addStaff()">
                <button class="btn btn-primary" onclick="addStaff()">+ Dodaj</button>
            </div>
        </div>
        <div class="re-note">
            Ko ima restavracija vsaj enega zaposlenega, se pri dodajanju rezervacije pojavi izbira "Sprejel".
        </div>
    </div>

    <!-- ── Tab: Polja po meri ──────────────────────────────── -->
    <div id="panel-polja" class="re-panel">
        <div class="re-section">
            <div class="re-section-title">Aktivna polja</div>
            <div id="cf-list" style="margin-bottom:16px">Nalagam...</div>
        </div>

        <div class="re-section">
            <div class="re-section-title">Dodaj novo polje</div>
            <div class="admin-form">
                <div class="admin-field-row">
                    <div class="admin-field">
                        <label>Oznaka *</label>
                        <input type="text" id="cf-label" placeholder="npr. Alergije">
                    </div>
                    <div class="admin-field" style="max-width:140px">
                        <label>Tip</label>
                        <select id="cf-type" onchange="toggleCfOptions(this.value)">
                            <option value="text">Besedilo</option>
                            <option value="select">Izbira</option>
                            <option value="checkbox">Da/Ne</option>
                        </select>
                    </div>
                </div>
                <div id="cf-options-wrap" style="display:none">
                    <div class="admin-field">
                        <label>Možnosti (ena na vrstico) *</label>
                        <textarea id="cf-options" rows="3" placeholder="Opcija 1&#10;Opcija 2&#10;Opcija 3"
                            style="width:100%;border:1.5px solid var(--color-border);border-radius:8px;padding:9px 12px;font-size:.875rem;font-family:var(--font);resize:vertical;outline:none;min-height:80px"></textarea>
                    </div>
                </div>
                <div class="admin-field-row">
                    <div class="admin-field">
                        <label>Velja za</label>
                        <select id="cf-applies">
                            <option value="both">Interno + Splet</option>
                            <option value="internal">Samo interno</option>
                            <option value="public">Samo splet</option>
                        </select>
                    </div>
                    <div class="admin-field" style="justify-content:flex-end;padding-top:18px">
                        <label class="toggle-wrap" style="cursor:pointer">
                            <span class="toggle">
                                <input type="checkbox" id="cf-required">
                                <span class="toggle-track"></span>
                            </span>
                            <span class="toggle-label">Obvezno polje</span>
                        </label>
                    </div>
                </div>
                <div style="text-align:right">
                    <button class="btn btn-primary" onclick="addCustomField()">Dodaj polje</button>
                </div>
            </div>
        </div>

        <div class="re-note re-note-blue">
            <strong>Interno</strong> = polje vidijo samo zaposleni pri dodajanju rezervacije.<br>
            <strong>Splet</strong> = polje se prikaže gostom pri spletni rezervaciji.<br>
            <strong>Interno + Splet</strong> = oboje.
        </div>
    </div>

    <!-- ── Tab: Anketa ───────────────────────────────────────── -->
    <div id="panel-anketa" class="re-panel">
    <?php if (!$hasSurvey): ?>
        <div class="re-section" style="background:#FEF3C7;border-color:#FDE68A">
            <p style="margin:0;font-size:.9rem;color:#92400E">
                Anketa o zadovoljstvu je na voljo v paketu <strong>Advanced</strong> ali višjem.
                <a href="<?= BASE_PATH ?>/pages/billing.php" style="color:#B45309;font-weight:600">Nadgradi paket →</a>
            </p>
        </div>
    <?php else: ?>

    <?php if (!$hasSurveyEdit): ?>
        <div class="re-section" style="background:#EFF6FF;border-color:#BFDBFE;margin-bottom:20px">
            <p style="margin:0;font-size:.875rem;color:#1E40AF">
                <strong>Advanced paket:</strong> Anketa je prikazana samo za branje. Za urejanje vprašanj in nastavitev nadgradite na
                <a href="<?= BASE_PATH ?>/pages/billing.php" style="color:#1D4ED8;font-weight:600">Premium →</a>
            </p>
        </div>
    <?php endif; ?>

        <!-- Nastavitve -->
        <div class="re-section">
            <div class="re-section-title">Nastavitve ankete</div>
            <div class="admin-form">
                <div class="admin-field-row">
                    <div class="admin-field" style="flex:2">
                        <label>Naslov ankete</label>
                        <input type="text" id="sf-title" maxlength="255">
                    </div>
                </div>
                <div class="admin-field-row">
                    <div class="admin-field">
                        <label>Opis (opcionalno)</label>
                        <textarea id="sf-description" rows="2" style="width:100%;border:1.5px solid var(--color-border);border-radius:8px;padding:9px 12px;font-size:.875rem;font-family:var(--font);resize:vertical;outline:none"></textarea>
                    </div>
                </div>
                <div class="admin-field-row">
                    <div class="admin-field">
                        <label>Besedilo zahvalnega emaila</label>
                        <textarea id="sf-thankyou" rows="3" style="width:100%;border:1.5px solid var(--color-border);border-radius:8px;padding:9px 12px;font-size:.875rem;font-family:var(--font);resize:vertical;outline:none"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pošiljanje -->
        <div class="re-section">
            <div class="re-section-title">Samodejno pošiljanje</div>
            <div style="display:flex;flex-direction:column;gap:0">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--color-border)">
                    <div>
                        <span style="font-size:.875rem;color:var(--color-text);font-weight:500">Vklopljeno</span>
                        <div style="font-size:.78rem;color:var(--color-muted);margin-top:2px">Po obisku sistem samodejno pošlje email gostu</div>
                    </div>
                    <label class="toggle-wrap" style="cursor:pointer;margin:0">
                        <span class="toggle">
                            <input type="checkbox" id="sf-send-enabled" onchange="surveyToggleDelay()">
                            <span class="toggle-track"></span>
                        </span>
                    </label>
                </div>
                <div id="sf-delay-row" style="display:none;align-items:center;gap:8px;padding:10px 0;border-bottom:1px solid var(--color-border)">
                    <span style="font-size:.875rem;color:var(--color-text)">Pošlji po</span>
                    <input type="number" id="sf-delay" min="0" max="168" value="2"
                        style="width:60px;border:1.5px solid var(--color-border);border-radius:8px;padding:7px 10px;font-size:.875rem;font-family:var(--font)">
                    <span style="font-size:.875rem;color:var(--color-muted)">urah po prihodu gosta</span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--color-border)">
                    <span style="font-size:.875rem;color:var(--color-text)">Vključi zahvalo v email</span>
                    <label class="toggle-wrap" style="cursor:pointer;margin:0">
                        <span class="toggle">
                            <input type="checkbox" id="sf-incl-thankyou" checked>
                            <span class="toggle-track"></span>
                        </span>
                    </label>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0">
                    <span style="font-size:.875rem;color:var(--color-text)">Vključi povezavo do ankete</span>
                    <label class="toggle-wrap" style="cursor:pointer;margin:0">
                        <span class="toggle">
                            <input type="checkbox" id="sf-incl-survey" checked>
                            <span class="toggle-track"></span>
                        </span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Vprašanja -->
        <div class="re-section">
            <div class="re-section-title">Vprašanja</div>
            <div id="sf-question-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px"></div>
            <?php if ($hasSurveyEdit): ?>
            <button onclick="surveyAddQuestion()" style="width:100%;border:2px dashed var(--color-border);border-radius:8px;padding:10px;font-size:.875rem;color:var(--color-muted);background:none;cursor:pointer;font-family:var(--font);transition:.15s"
                onmouseenter="this.style.borderColor='var(--color-accent)';this.style.color='var(--color-accent)'"
                onmouseleave="this.style.borderColor='';this.style.color=''">
                + Dodaj vprašanje
            </button>
            <?php endif; ?>
        </div>

        <?php if ($hasSurveyEdit): ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:32px">
            <button class="btn-success" id="btn-save-survey" onclick="saveSurveyForm()">Shrani anketo</button>
            <span id="sf-save-status" style="font-size:.85rem;color:var(--color-muted)"></span>
        </div>
        <?php else: ?>
        <div style="margin-bottom:32px"></div>
        <?php endif; ?>

        <!-- Odgovori -->
        <div class="re-section">
            <div class="re-section-title" style="display:flex;justify-content:space-between;align-items:center">
                <span>Prejeti odgovori</span>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="date" id="sr-from" style="border:1px solid var(--color-border);border-radius:6px;padding:5px 9px;font-size:.8rem;font-family:var(--font)">
                    <input type="date" id="sr-to"   style="border:1px solid var(--color-border);border-radius:6px;padding:5px 9px;font-size:.8rem;font-family:var(--font)">
                    <button onclick="loadSurveyResults()" style="background:var(--color-accent);color:#fff;border:none;border-radius:6px;padding:6px 14px;font-size:.8rem;font-weight:600;cursor:pointer;font-family:var(--font)">Prikaži</button>
                    <?php if (user_has_feature($pdo, (int)$_SESSION['user_id'], 'survey_export')): ?>
                    <button onclick="exportSurveyCsv()" style="background:#fff;border:1px solid var(--color-border);border-radius:6px;padding:6px 12px;font-size:.8rem;color:var(--color-text);cursor:pointer;font-family:var(--font)">↓ CSV</button>
                    <?php endif; ?>
                </div>
            </div>
            <div id="sr-list" style="margin-top:14px"><p style="font-size:.85rem;color:var(--color-muted)">Kliknite Prikaži za nalaganje odgovorov.</p></div>
        </div>

    <?php endif; ?>
    </div>

    <!-- ── Tab: Mize ─────────────────────────────────────────── -->
    <div id="panel-mize" class="re-panel">
    <?php if (!$hasTableMgmt): ?>
        <div class="re-section" style="background:#FEF3C7;border-color:#FDE68A">
            <p style="margin:0;font-size:.9rem;color:#92400E">
                Upravljanje miz je na voljo v paketu <strong>Advanced</strong> ali višjem.
                <a href="<?= BASE_PATH ?>/pages/billing.php" style="color:#B45309;font-weight:600">Nadgradi paket →</a>
            </p>
        </div>
    <?php else: ?>

        <!-- Cone -->
        <div class="re-section">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                <div class="re-section-title" style="margin:0">Cone</div>
                <button onclick="showAreaForm()" class="btn-success" style="font-size:.8rem;padding:.4rem .9rem">+ Cona</button>
            </div>
            <div id="areas-list"></div>
            <div id="area-form" style="display:none;margin-top:12px;display:none">
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="text" id="area-name-input" placeholder="Ime cone (npr. Zunaj, 1. nadstropje)" style="flex:1;border:1.5px solid var(--color-border);border-radius:8px;padding:8px 12px;font-size:.875rem;font-family:var(--font);outline:none">
                    <button onclick="saveArea()" class="btn-success" style="font-size:.8rem;padding:.4rem .9rem">Shrani</button>
                    <button onclick="cancelAreaForm()" style="font-size:.8rem;padding:.4rem .9rem;background:transparent;border:1.5px solid var(--color-border);border-radius:8px;cursor:pointer;font-family:var(--font)">Prekliči</button>
                </div>
                <input type="hidden" id="area-edit-id" value="">
            </div>
        </div>

        <!-- Mize -->
        <div class="re-section">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                <div class="re-section-title" style="margin:0">Mize</div>
                <button onclick="showTableForm()" class="btn-success" style="font-size:.8rem;padding:.4rem .9rem">+ Miza</button>
            </div>
            <div id="tables-list"></div>
            <div id="table-form" style="display:none;margin-top:12px;background:var(--color-bg);border-radius:8px;padding:14px;border:1px solid var(--color-border)">
                <div style="display:grid;grid-template-columns:1fr auto auto;gap:8px;align-items:end">
                    <div>
                        <label style="font-size:.78rem;font-weight:600;color:var(--color-muted);display:block;margin-bottom:4px">Ime mize *</label>
                        <input type="text" id="table-name-input" placeholder="npr. Miza 1, Bar 3"
                            style="width:100%;border:1.5px solid var(--color-border);border-radius:8px;padding:8px 12px;font-size:.875rem;font-family:var(--font);outline:none;box-sizing:border-box">
                    </div>
                    <div>
                        <label style="font-size:.78rem;font-weight:600;color:var(--color-muted);display:block;margin-bottom:4px">Zmogljivost *</label>
                        <input type="number" id="table-cap-input" min="1" max="50" value="2"
                            style="width:80px;border:1.5px solid var(--color-border);border-radius:8px;padding:8px 10px;font-size:.875rem;font-family:var(--font);outline:none">
                    </div>
                    <div>
                        <label style="font-size:.78rem;font-weight:600;color:var(--color-muted);display:block;margin-bottom:4px">Cona</label>
                        <select id="table-area-select"
                            style="border:1.5px solid var(--color-border);border-radius:8px;padding:8px 10px;font-size:.875rem;font-family:var(--font);outline:none;background:var(--color-surface)">
                            <option value="">— brez cone —</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:8px;margin-top:10px">
                    <button onclick="saveTable()" class="btn-success" style="font-size:.8rem;padding:.4rem .9rem">Shrani</button>
                    <button onclick="cancelTableForm()" style="font-size:.8rem;padding:.4rem .9rem;background:transparent;border:1.5px solid var(--color-border);border-radius:8px;cursor:pointer;font-family:var(--font)">Prekliči</button>
                </div>
                <input type="hidden" id="table-edit-id" value="">
            </div>
        </div>

        <!-- Združene mize -->
        <div class="re-section">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                <div class="re-section-title" style="margin:0">Združene mize</div>
                <button onclick="showMergeForm()" class="btn-success" style="font-size:.8rem;padding:.4rem .9rem">+ Skupina</button>
            </div>
            <div style="font-size:.8rem;color:var(--color-muted);margin-bottom:12px">
                Definirajte, katere mize se lahko združijo (npr. sosednje mize za večje gruče gostov).
            </div>
            <div id="merge-groups-list"></div>
            <div id="merge-form" style="display:none;margin-top:12px;background:var(--color-bg);border-radius:8px;padding:14px;border:1px solid var(--color-border)">
                <label style="font-size:.78rem;font-weight:600;color:var(--color-muted);display:block;margin-bottom:4px">Ime skupine (opcionalno)</label>
                <input type="text" id="mg-name-input" placeholder="npr. Terasa 1+2"
                    style="width:100%;border:1.5px solid var(--color-border);border-radius:8px;padding:8px 12px;font-size:.875rem;font-family:var(--font);outline:none;box-sizing:border-box;margin-bottom:10px">
                <label style="font-size:.78rem;font-weight:600;color:var(--color-muted);display:block;margin-bottom:6px">Izberite mize (vsaj 2) *</label>
                <div id="mg-tables-checkboxes" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px"></div>
                <div style="display:flex;gap:8px">
                    <button onclick="saveMergeGroup()" class="btn-success" style="font-size:.8rem;padding:.4rem .9rem">Shrani</button>
                    <button onclick="cancelMergeForm()" style="font-size:.8rem;padding:.4rem .9rem;background:transparent;border:1.5px solid var(--color-border);border-radius:8px;cursor:pointer;font-family:var(--font)">Prekliči</button>
                </div>
                <input type="hidden" id="mg-edit-id" value="">
            </div>
        </div>

    <?php endif; ?>
    </div>

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

// ── Tabs ──────────────────────────────────────────────────────
document.querySelectorAll('.re-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.re-tab').forEach(t=>t.classList.remove('active'));
        document.querySelectorAll('.re-panel').forEach(p=>p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-'+tab.dataset.tab).classList.add('active');
        // Lazy load
        if (tab.dataset.tab === 'urnik'   && !urnikLoaded)  loadUrnik();
        if (tab.dataset.tab === 'zaposleni' && !staffLoaded) loadStaff();
        if (tab.dataset.tab === 'polja'   && !cfLoaded)    loadCustomFields();
        if (tab.dataset.tab === 'anketa'  && !surveyLoaded) loadSurveyForm();
        if (tab.dataset.tab === 'mize'    && !tablesLoaded) loadTables();
    });
});

// ── Splošno – shrani ──────────────────────────────────────────
document.getElementById('btn-save-splosno').addEventListener('click', async () => {
    const name         = document.getElementById('r-name').value.trim();
    const color        = document.getElementById('r-color').value;
    const duration     = parseInt(document.getElementById('r-duration').value)||60;
    const allowCustom  = document.getElementById('r-allow-custom').checked ? 1 : 0;
    const active       = parseInt(document.getElementById('r-active').value);
    const contactEmail = document.getElementById('r-contact-email').value.trim();
    const contactPhone = document.getElementById('r-contact-phone').value.trim();
    if (!name) { showPageErr('Ime je obvezno.'); return; }
    const btn = document.getElementById('btn-save-splosno');
    btn.disabled=true; btn.textContent='...';
    try {
        await apiCall('PUT', `/api/restaurants.php?id=${REST_ID}`, {
            name, color, reservation_duration: duration,
            allow_custom_duration: allowCustom, is_active: active,
            contact_email: contactEmail, contact_phone: contactPhone,
        });
        document.querySelector('.rest-edit-title').innerHTML =
            `<span class="rest-color-dot" id="hdr-color-dot" style="background:${color}"></span>${h(name)}`;
        showPageOk('Shranjeno!');
    } catch(e) { showPageErr(e.message); }
    btn.disabled=false; btn.textContent='Shrani';
});


// ── Urnik ─────────────────────────────────────────────────────
const DAY_NAMES = ['Ponedeljek','Torek','Sreda','Četrtek','Petek','Sobota','Nedelja'];
let urnikLoaded = false;
let blackouts = [];

async function loadUrnik() {
    urnikLoaded = true;
    try {
        const rest = await apiCall('GET', `/api/restaurants.php?id=${REST_ID}`);
        renderDaySchedule(rest.day_schedules || []);
        blackouts = rest.blackouts || [];
        renderBlackouts();
    } catch(e) { toast(e.message,'error'); }
}

function renderDaySchedule(ds) {
    const wrap = document.getElementById('day-schedule-wrap');
    wrap.innerHTML = DAY_NAMES.map((name, i) => {
        const d    = ds.find(x=>x.day_of_week==i) || null;
        const open = d ? !!d.is_open : (i<5);
        const st   = d ? minsToTime(d.start_time) : '08:00';
        const en   = d ? minsToTime(d.end_time)   : '23:00';
        return `<div class="day-row">
            <label class="day-label-wrap">
                <input type="checkbox" class="day-cb" data-day="${i}" ${open?'checked':''}
                    style="width:16px;height:16px;accent-color:var(--color-accent);cursor:pointer;flex-shrink:0"
                    onchange="toggleDayRow(${i},this.checked)">
                <span style="font-size:.875rem;font-weight:500;color:var(--color-text)">${name}</span>
            </label>
            <div class="day-times" id="day-times-${i}" style="${!open?'opacity:.35;pointer-events:none':''}">
                <input type="time" class="day-time-input day-start" data-day="${i}" value="${st}">
                <span style="color:var(--color-muted);font-size:.8rem">–</span>
                <input type="time" class="day-time-input day-end" data-day="${i}" value="${en}">
            </div>
        </div>`;
    }).join('');
}

window.toggleDayRow = (day, open) => {
    const el = document.getElementById('day-times-'+day);
    if (el) { el.style.opacity=open?'1':'.35'; el.style.pointerEvents=open?'':'none'; }
};

function getDaySchedules() {
    return Array.from({length:7},(_,i)=>({
        day_of_week: i,
        is_open:    document.querySelector(`.day-cb[data-day="${i}"]`)?.checked?1:0,
        start_time: timeToMins(document.querySelector(`.day-start[data-day="${i}"]`)?.value||'08:00'),
        end_time:   timeToMins(document.querySelector(`.day-end[data-day="${i}"]`)?.value||'23:00'),
    }));
}

function renderBlackouts() {
    const el = document.getElementById('blackout-list');
    if (!blackouts.length) { el.innerHTML='<p style="font-size:.825rem;color:var(--color-muted)">Ni blokiranih datumov.</p>'; return; }
    el.innerHTML = blackouts.map(b=>`
        <div class="item-row" data-date="${b.blackout_date}">
            <span class="item-row-name">${h(b.blackout_date)}${b.reason?` <span style="color:var(--color-muted);font-weight:400">– ${h(b.reason)}</span>`:''}</span>
            <button class="item-row-del" onclick="removeBlackout('${b.blackout_date}',this)" title="Odstrani">×</button>
        </div>`).join('');
}

window.removeBlackout = async (date, btn) => {
    try {
        await apiCall('DELETE', `/api/restaurants.php?id=${REST_ID}&action=remove_blackout&date=${date}`);
        btn.closest('.item-row').remove();
        blackouts = blackouts.filter(b=>b.blackout_date!==date);
        if (!blackouts.length) renderBlackouts();
        toast('Datum odstranjen.');
    } catch(e) { toast(e.message,'error'); }
};

document.getElementById('btn-add-blackout').addEventListener('click', async () => {
    const date   = document.getElementById('blackout-date').value;
    const reason = document.getElementById('blackout-reason').value.trim();
    if (!date) { toast('Izberite datum.','error'); return; }
    try {
        await apiCall('PUT', `/api/restaurants.php?id=${REST_ID}&action=add_blackout`, {date,reason});
        blackouts.push({blackout_date:date, reason:reason||null});
        renderBlackouts();
        document.getElementById('blackout-date').value='';
        document.getElementById('blackout-reason').value='';
        toast('Datum dodan!');
    } catch(e) { toast(e.message,'error'); }
});

document.getElementById('btn-save-urnik').addEventListener('click', async () => {
    const ds = getDaySchedules();
    const invalid = ds.find(d=>d.is_open && d.end_time<=d.start_time);
    if (invalid) { showPageErr(`${DAY_NAMES[invalid.day_of_week]}: končni čas mora biti večji od začetnega.`); return; }
    const btn = document.getElementById('btn-save-urnik');
    btn.disabled=true; btn.textContent='...';
    try {
        await apiCall('PUT', `/api/restaurants.php?id=${REST_ID}`, {day_schedules:ds});
        showPageOk('Urnik shranjen!');
    } catch(e) { showPageErr(e.message); }
    btn.disabled=false; btn.textContent='Shrani urnik';
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
    if (v) navigator.clipboard.writeText(v).then(()=>toast('Povezava kopirana!'),()=>prompt('Kopiraj:',v));
};
window.copyEmbed = () => {
    const v = document.getElementById('embed-code-input')?.value;
    if (v) navigator.clipboard.writeText(v).then(()=>toast('Embed koda kopirana!'),()=>prompt('Kopiraj:',v));
};

document.getElementById('btn-save-booking').addEventListener('click', async () => {
    const enabled     = document.getElementById('r-booking-enabled').checked ? 1 : 0;
    const interval    = parseInt(document.getElementById('r-slot-interval')?.value||'60');
    const autoConf    = document.getElementById('r-auto-confirm')?.checked ? 1 : 0;
    const minG        = parseInt(document.getElementById('r-min-guests')?.value||'2');
    const maxG        = parseInt(document.getElementById('r-max-guests')?.value||'10');
    const allowEdit   = document.getElementById('r-allow-edit')?.checked ? 1 : 0;
    const editCutoff  = parseInt(document.getElementById('r-edit-cutoff')?.value||'24');
    const allowCancel = document.getElementById('r-allow-cancel')?.checked ? 1 : 0;
    const cancelCutoff = parseInt(document.getElementById('r-cancel-cutoff')?.value||'4');
    if (enabled && minG > maxG) { showPageErr('Min. gostov ne more biti večje od max.'); return; }
    const btn = document.getElementById('btn-save-booking');
    btn.disabled=true; btn.textContent='...';
    try {
        await apiCall('PUT', `/api/restaurants.php?id=${REST_ID}`, {
            booking_enabled: enabled, booking_slot_interval: interval,
            booking_auto_confirm: autoConf, booking_min_guests: minG, booking_max_guests: maxG,
            allow_guest_edit: allowEdit, guest_edit_cutoff_hours: editCutoff,
            allow_guest_cancel: allowCancel, guest_cancel_cutoff_hours: cancelCutoff,
            waitlist_enabled: document.getElementById('r-waitlist-enabled')?.checked ? 1 : 0,
        });
        showPageOk('Shranjeno!');
    } catch(e) { showPageErr(e.message); }
    btn.disabled=false; btn.textContent='Shrani';
});

// ── Zaposleni ─────────────────────────────────────────────────
let staffLoaded = false;

async function loadStaff() {
    staffLoaded = true;
    try {
        const staff = await apiCall('GET', `/api/staff.php?restaurant_id=${REST_ID}`);
        renderStaff(staff || []);
    } catch(e) { document.getElementById('staff-list').innerHTML='<p style="color:var(--color-danger);font-size:.875rem">Napaka pri nalaganju.</p>'; }
}

function renderStaff(staff) {
    const el = document.getElementById('staff-list');
    if (!staff.length) { el.innerHTML='<p style="font-size:.875rem;color:var(--color-muted)">Ni zaposlenih.</p>'; return; }
    el.innerHTML = staff.map(s=>`
        <div class="item-row" id="staff-row-${s.id}">
            <span class="item-row-name">${h(s.name)}</span>
            ${s.is_active==0?'<span class="item-row-badge" style="background:#F3F4F6;color:var(--color-muted)">neaktiven</span>':''}
            <button class="item-row-del" onclick="removeStaff(${s.id})" title="Odstrani">×</button>
        </div>`).join('');
}

window.addStaff = async () => {
    const inp  = document.getElementById('staff-name-input');
    const name = inp.value.trim();
    if (!name) { toast('Vnesite ime.','error'); return; }
    try {
        await apiCall('POST', '/api/staff.php', {restaurant_id:REST_ID, name});
        inp.value = '';
        await loadStaff();
        toast('Zaposleni dodan!');
    } catch(e) { toast(e.message,'error'); }
};

window.removeStaff = async (id) => {
    try {
        await apiCall('DELETE', `/api/staff.php?id=${id}`);
        await loadStaff();
        toast('Zaposleni odstranjen.');
    } catch(e) { toast(e.message,'error'); }
};

// ── Polja po meri ──────────────────────────────────────────────
let cfLoaded = false;
const APPLIES_LABELS = {internal:'Samo interno', public:'Samo splet', both:'Interno + Splet'};
const APPLIES_CLASS  = {internal:'cf-badge-int',  public:'cf-badge-pub',  both:'cf-badge-both'};
const TYPE_LABELS    = {text:'Besedilo', select:'Izbira', checkbox:'Da/Ne'};

async function loadCustomFields() {
    cfLoaded = true;
    try {
        const fields = await apiCall('GET', `/api/customfields.php?restaurant_id=${REST_ID}`);
        renderCustomFields(fields || []);
    } catch(e) { document.getElementById('cf-list').innerHTML='<p style="color:var(--color-danger);font-size:.875rem">Napaka pri nalaganju.</p>'; }
}

function renderCustomFields(fields) {
    const el = document.getElementById('cf-list');
    if (!fields.length) { el.innerHTML='<p style="font-size:.875rem;color:var(--color-muted)">Ni polj po meri.</p>'; return; }
    el.innerHTML = fields.map(f=>`
        <div class="item-row" id="cf-row-${f.id}">
            <span class="item-row-name">${h(f.label)}</span>
            <span class="cf-badge cf-badge-type">${TYPE_LABELS[f.field_type]||f.field_type}</span>
            <span class="cf-badge ${APPLIES_CLASS[f.applies_to]||''}">${APPLIES_LABELS[f.applies_to]||f.applies_to}</span>
            ${f.is_required?'<span class="cf-badge cf-badge-req">Obvezno</span>':''}
            <button class="item-row-del" onclick="removeCustomField(${f.id})" title="Odstrani">×</button>
        </div>`).join('');
}

window.toggleCfOptions = (type) => {
    document.getElementById('cf-options-wrap').style.display = type==='select' ? '' : 'none';
};

window.addCustomField = async () => {
    const label   = document.getElementById('cf-label').value.trim();
    const type    = document.getElementById('cf-type').value;
    const applies = document.getElementById('cf-applies').value;
    const req     = document.getElementById('cf-required').checked ? 1 : 0;
    const optsTxt = document.getElementById('cf-options')?.value||'';
    if (!label) { toast('Oznaka je obvezna.','error'); return; }
    const options = type==='select' ? optsTxt.split('\n').map(s=>s.trim()).filter(Boolean) : [];
    if (type==='select' && !options.length) { toast('Vnesite vsaj eno možnost.','error'); return; }
    try {
        await apiCall('POST', '/api/customfields.php', {
            restaurant_id:REST_ID, label, field_type:type,
            applies_to:applies, is_required:req, options,
        });
        document.getElementById('cf-label').value='';
        document.getElementById('cf-options').value='';
        document.getElementById('cf-required').checked=false;
        await loadCustomFields();
        toast('Polje dodano!');
    } catch(e) { toast(e.message,'error'); }
};

window.removeCustomField = async (id) => {
    if (!confirm('Izbrišete polje? Obstoječe vrednosti se ohranijo.')) return;
    try {
        await apiCall('DELETE', `/api/customfields.php?id=${id}`);
        document.getElementById(`cf-row-${id}`)?.remove();
        const el = document.getElementById('cf-list');
        if (!el.querySelector('.item-row')) el.innerHTML='<p style="font-size:.875rem;color:var(--color-muted)">Ni polj po meri.</p>';
        toast('Polje odstranjeno.');
    } catch(e) { toast(e.message,'error'); }
};

// ── Anketa ────────────────────────────────────────────────────
let surveyLoaded = false;
let sfQCounter   = 0;
let sfOptCounter = 0;

const SF_TYPE_LABELS = {
    rating:'Zvezdičasta ocena (1–5)', radio:'Izbirni gumb (radio)',
    checkbox:'Potrditvena polja', text:'Kratko besedilno polje', textarea:'Dolgo besedilno polje',
};

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
}

function fillSurveyForm(d) {
    const canEdit = APP_STATE.surveyEdit;
    document.getElementById('sf-title').value       = d.title || '';
    document.getElementById('sf-description').value = d.description || '';
    document.getElementById('sf-thankyou').value    = d.thank_you_message || '';
    document.getElementById('sf-send-enabled').checked  = !!parseInt(d.send_enabled);
    document.getElementById('sf-delay').value            = d.send_delay_hours || 2;
    document.getElementById('sf-incl-thankyou').checked = !!parseInt(d.include_thankyou);
    document.getElementById('sf-incl-survey').checked   = !!parseInt(d.include_survey);
    surveyToggleDelay();

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
    document.getElementById('sf-title').value        = 'Anketa o zadovoljstvu';
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
    const text    = data ? (data.question_text || '') : '';
    const req     = data ? !!parseInt(data.is_required) : false;
    const opts    = data && data.options ? data.options : [];

    const card = document.createElement('div');
    card.id = `sfcard-${qk}`;
    card.style.cssText = 'background:var(--color-bg);border:1px solid var(--color-border);border-radius:8px;padding:12px 14px';

    const typeOpts = Object.entries(SF_TYPE_LABELS).map(([v,l]) =>
        `<option value="${v}"${v===type?' selected':''}>${l}</option>`).join('');

    const TYPE_READABLE = { rating:'Zvezdičasta ocena (1–5)', radio:'Izbirni gumb', checkbox:'Potrditvena polja', text:'Kratko besedilo', textarea:'Dolgo besedilo' };

    if (canEdit) {
        card.innerHTML = `
        <div style="display:flex;align-items:flex-start;gap:8px">
            <div style="display:flex;flex-direction:column;gap:2px;flex-shrink:0;padding-top:2px">
                <button onclick="sfMoveQ('${qk}',-1)" style="background:none;border:1px solid var(--color-border);border-radius:4px;width:22px;height:22px;cursor:pointer;font-size:.7rem;color:var(--color-muted);display:flex;align-items:center;justify-content:center;padding:0" title="Gor">▲</button>
                <button onclick="sfMoveQ('${qk}',1)"  style="background:none;border:1px solid var(--color-border);border-radius:4px;width:22px;height:22px;cursor:pointer;font-size:.7rem;color:var(--color-muted);display:flex;align-items:center;justify-content:center;padding:0" title="Dol">▼</button>
            </div>
            <div style="flex:1;display:flex;flex-direction:column;gap:6px">
                <input type="text" id="sfqt-${qk}" placeholder="Besedilo vprašanja..." value="${h(text)}"
                    style="border:1.5px solid var(--color-border);border-radius:7px;padding:8px 10px;font-size:.875rem;font-family:var(--font);color:var(--color-text);width:100%;outline:none">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <select id="sfqtype-${qk}" onchange="sfTypeChange('${qk}')"
                        style="border:1.5px solid var(--color-border);border-radius:7px;padding:7px 10px;font-size:.825rem;font-family:var(--font);background:#fff">${typeOpts}</select>
                    <label style="font-size:.82rem;color:var(--color-muted);display:flex;align-items:center;gap:5px;cursor:pointer">
                        <input type="checkbox" id="sfqreq-${qk}"${req?' checked':''} style="cursor:pointer;accent-color:var(--color-accent)"> Obvezno
                    </label>
                </div>
                <div id="sfqopts-${qk}" style="display:flex;flex-direction:column;gap:4px"></div>
                <button id="sfqaddopt-${qk}" onclick="sfAddOpt('${qk}')" style="display:none;border:1px dashed var(--color-border);border-radius:6px;padding:5px 10px;font-size:.8rem;color:var(--color-muted);background:none;cursor:pointer;text-align:left">+ Dodaj možnost</button>
            </div>
            <button onclick="document.getElementById('sfcard-${qk}').remove()"
                style="background:none;border:none;cursor:pointer;color:#EF4444;font-size:.8rem;padding:4px 6px;border-radius:5px;flex-shrink:0;display:flex;align-items:center;gap:3px;white-space:nowrap"
                onmouseenter="this.style.background='#FEF2F2'" onmouseleave="this.style.background='none'">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg> Briši
            </button>
        </div>`;
        document.getElementById('sf-question-list').appendChild(card);
        opts.forEach(o => sfAddOpt(qk, o.label));
        sfTypeChange(qk);
    } else {
        // Readonly prikaz vprašanja
        const reqBadge = req ? '<span style="font-size:.72rem;background:#FEE2E2;color:#DC2626;padding:1px 6px;border-radius:10px;font-weight:600;margin-left:6px">Obvezno</span>' : '';
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

function sfAddOpt(qk, value = '') {
    sfOptCounter++;
    const ok = `sfo${sfOptCounter}`;
    const row = document.createElement('div');
    row.id = `sfoptrow-${ok}`;
    row.style.cssText = 'display:flex;align-items:center;gap:6px';
    row.innerHTML = `
        <input type="text" id="${ok}" placeholder="Možnost..." value="${h(value)}"
            style="flex:1;border:1.5px solid var(--color-border);border-radius:6px;padding:6px 9px;font-size:.83rem;font-family:var(--font);outline:none">
        <button onclick="document.getElementById('sfoptrow-${ok}').remove()"
            style="background:none;border:none;cursor:pointer;color:var(--color-muted);font-size:1.1rem;line-height:1;padding:2px 5px"
            onmouseenter="this.style.color='#EF4444'" onmouseleave="this.style.color=''">×</button>`;
    document.getElementById(`sfqopts-${qk}`).appendChild(row);
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
        const opts = [];
        card.querySelectorAll(`#sfqopts-${qk} input[type=text]`).forEach(inp => {
            const v = inp.value.trim(); if (v) opts.push({ label: v });
        });
        return {
            question_text: document.getElementById(`sfqt-${qk}`).value.trim(),
            type, is_required: document.getElementById(`sfqreq-${qk}`).checked ? 1 : 0, options: opts,
        };
    });
}

async function saveSurveyForm() {
    const btn = document.getElementById('btn-save-survey');
    const st  = document.getElementById('sf-save-status');
    btn.disabled = true; st.style.color=''; st.textContent = 'Shranjujem...';
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
        st.style.color = '#059669'; st.textContent = 'Shranjeno!';
        setTimeout(() => st.textContent = '', 3000);
    } catch(e) {
        st.style.color = '#EF4444'; st.textContent = e.message || 'Napaka.';
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
    list.innerHTML = '<p style="font-size:.85rem;color:var(--color-muted)">Nalagam...</p>';
    try {
        const rows = await apiCall('GET', `/api/survey.php?${params}`);
        renderSurveyResults(rows || []);
    } catch(e) { list.innerHTML = `<p style="color:#EF4444;font-size:.85rem">${h(e.message)}</p>`; }
}

function renderSurveyResults(rows) {
    const list = document.getElementById('sr-list');
    if (!rows.length) { list.innerHTML='<p style="font-size:.85rem;color:var(--color-muted)">Ni odgovorov.</p>'; return; }
    const CONSENT = { public:'Javno', anonymous:'Anonimno', private:'Zasebno' };
    list.innerHTML = `
    <table style="width:100%;border-collapse:collapse;font-size:.85rem">
        <thead><tr style="border-bottom:2px solid var(--color-border)">
            <th style="padding:7px 10px;text-align:left;font-size:.75rem;font-weight:600;color:var(--color-muted)">Datum</th>
            <th style="padding:7px 10px;text-align:left;font-size:.75rem;font-weight:600;color:var(--color-muted)">Gost</th>
            <th style="padding:7px 10px;text-align:left;font-size:.75rem;font-weight:600;color:var(--color-muted)">Soglasje</th>
            <th style="padding:7px 10px;text-align:left;font-size:.75rem;font-weight:600;color:var(--color-muted)">Status</th>
        </tr></thead>
        <tbody>${rows.map(r => {
            const submitted = r.submitted_at ? new Date(r.submitted_at.replace(' ','T')).toLocaleDateString('sl-SI') : '–';
            const status = r.submitted_at
                ? `<span style="color:#059669;font-size:.78rem;font-weight:600">Izpolnjena</span>`
                : r.email_sent_at
                    ? `<span style="color:#92400E;font-size:.78rem">Email poslan</span>`
                    : `<span style="color:var(--color-muted);font-size:.78rem">Čaka</span>`;
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
    content.innerHTML = '<p style="text-align:center;padding:30px;color:var(--color-muted)">Nalagam...</p>';
    try {
        const { response: sr, answers } = await apiCall('GET', `/api/survey.php?action=get_response_detail&id=${id}`);
        const CONSENT_MAP = { public:'Javno z imenom', anonymous:'Anonimno', private:'Ne strinja se z objavo' };
        const submitted = sr.submitted_at ? new Date(sr.submitted_at.replace(' ','T')).toLocaleString('sl-SI') : '–';
        let html = `<div style="font-size:1rem;font-weight:700;color:var(--color-text);margin-bottom:4px">Odgovor ankete</div>
            <div style="font-size:.8rem;color:var(--color-muted);margin-bottom:18px">Oddano: ${submitted} · Soglasje: ${CONSENT_MAP[sr.consent]||'–'}</div>`;
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

async function loadTables() {
    tablesLoaded = true;
    try {
        tablesData = await apiCall('GET', `/api/tables.php?restaurant_id=${REST_ID}`);
        renderAreas();
        renderTables();
        renderMergeGroups();
    } catch(e) {
        document.getElementById('areas-list').innerHTML = `<p style="color:#EF4444;font-size:.875rem">${h(e.message)}</p>`;
    }
}

function renderAreas() {
    const el = document.getElementById('areas-list');
    if (!tablesData.areas.length) { el.innerHTML = '<p style="font-size:.85rem;color:var(--color-muted)">Ni definiranih con. Cone so opcijsko.</p>'; return; }
    el.innerHTML = tablesData.areas.map(a => `
        <div class="item-row" style="opacity:${a.is_active?1:.5}">
            <span class="item-row-name">${h(a.name)}</span>
            <span class="item-row-badge" style="background:${a.is_active?'#D1FAE5':'#F3F4F6'};color:${a.is_active?'#065F46':'#6B7280'}">${a.is_active?'Aktivna':'Neaktivna'}</span>
            <button onclick="editArea(${a.id})" style="font-size:.8rem;padding:3px 8px;border:1.5px solid var(--color-border);border-radius:6px;background:transparent;cursor:pointer;font-family:var(--font)">Uredi</button>
            <button class="item-row-del" onclick="deleteArea(${a.id})" title="Briši">✕</button>
        </div>
    `).join('');
}

function renderTables() {
    const el = document.getElementById('tables-list');
    if (!tablesData.tables.length) { el.innerHTML = '<p style="font-size:.85rem;color:var(--color-muted)">Ni definiranih miz.</p>'; return; }
    el.innerHTML = tablesData.tables.map(t => `
        <div class="item-row" style="opacity:${t.is_active?1:.5}">
            <span class="item-row-name">${h(t.name)}</span>
            ${t.area_name ? `<span class="item-row-badge" style="background:#EDE9FE;color:#5B21B6">${h(t.area_name)}</span>` : ''}
            <span class="item-row-badge" style="background:#DBEAFE;color:#1D4ED8">${t.capacity} oseb</span>
            <span class="item-row-badge" style="background:${t.is_active?'#D1FAE5':'#F3F4F6'};color:${t.is_active?'#065F46':'#6B7280'}">${t.is_active?'Aktivna':'Neaktivna'}</span>
            <button onclick="editTable(${t.id})" style="font-size:.8rem;padding:3px 8px;border:1.5px solid var(--color-border);border-radius:6px;background:transparent;cursor:pointer;font-family:var(--font)">Uredi</button>
            <button class="item-row-del" onclick="deleteTable(${t.id})" title="Briši">✕</button>
        </div>
    `).join('');
}

function renderMergeGroups() {
    const el = document.getElementById('merge-groups-list');
    if (!tablesData.merge_groups.length) { el.innerHTML = '<p style="font-size:.85rem;color:var(--color-muted)">Ni definiranih skupin za združevanje.</p>'; return; }
    el.innerHTML = tablesData.merge_groups.map(g => `
        <div class="item-row">
            <span class="item-row-name">${g.name ? h(g.name) : '<em style="color:var(--color-muted)">Brez imena</em>'}</span>
            <span class="item-row-badge" style="background:#FEF3C7;color:#92400E">${g.member_names.join(' + ')}</span>
            <span class="item-row-badge" style="background:#DBEAFE;color:#1D4ED8">${g.total_capacity} oseb skupaj</span>
            <button onclick="editMergeGroup(${g.id})" style="font-size:.8rem;padding:3px 8px;border:1.5px solid var(--color-border);border-radius:6px;background:transparent;cursor:pointer;font-family:var(--font)">Uredi</button>
            <button class="item-row-del" onclick="deleteMergeGroup(${g.id})" title="Briši">✕</button>
        </div>
    `).join('');
}

// ─ Area form ─
function showAreaForm(editId=null) {
    const f = document.getElementById('area-form');
    document.getElementById('area-name-input').value = editId
        ? (tablesData.areas.find(a=>a.id===editId)?.name || '') : '';
    document.getElementById('area-edit-id').value = editId || '';
    f.style.display = 'block';
    document.getElementById('area-name-input').focus();
}
function cancelAreaForm() { document.getElementById('area-form').style.display='none'; }
function editArea(id) { showAreaForm(id); }

async function saveArea() {
    const name   = document.getElementById('area-name-input').value.trim();
    const editId = document.getElementById('area-edit-id').value;
    if (!name) { toast('Vnesite ime cone.','error'); return; }
    try {
        if (editId) {
            await apiCall('PUT', `/api/tables.php?area_id=${editId}`, { name });
        } else {
            await apiCall('POST', '/api/tables.php', { action:'create_area', restaurant_id:REST_ID, name });
        }
        cancelAreaForm();
        tablesLoaded = false; await loadTables();
        toast(editId ? 'Cona posodobljena.' : 'Cona dodana.');
    } catch(e) { toast(e.message,'error'); }
}

async function deleteArea(id) {
    if (!confirm('Izbriši cono? Mize v tej coni bodo ostale brez dodelitve.')) return;
    try {
        await apiCall('DELETE', `/api/tables.php?area_id=${id}`);
        tablesLoaded = false; await loadTables();
        toast('Cona izbrisana.');
    } catch(e) { toast(e.message,'error'); }
}

// ─ Table form ─
function populateAreaSelect(selectedId=null) {
    const sel = document.getElementById('table-area-select');
    sel.innerHTML = '<option value="">— brez cone —</option>' +
        tablesData.areas.map(a => `<option value="${a.id}" ${selectedId==a.id?'selected':''}>${h(a.name)}</option>`).join('');
}

function showTableForm(editId=null) {
    const f = document.getElementById('table-form');
    const t = editId ? tablesData.tables.find(x=>x.id===editId) : null;
    document.getElementById('table-name-input').value = t?.name || '';
    document.getElementById('table-cap-input').value  = t?.capacity || 2;
    document.getElementById('table-edit-id').value    = editId || '';
    populateAreaSelect(t?.area_id || null);
    f.style.display = 'block';
    document.getElementById('table-name-input').focus();
}
function cancelTableForm() { document.getElementById('table-form').style.display='none'; }
function editTable(id) { showTableForm(id); }

async function saveTable() {
    const name     = document.getElementById('table-name-input').value.trim();
    const capacity = parseInt(document.getElementById('table-cap-input').value) || 2;
    const areaId   = document.getElementById('table-area-select').value || null;
    const editId   = document.getElementById('table-edit-id').value;
    if (!name) { toast('Vnesite ime mize.','error'); return; }
    try {
        if (editId) {
            await apiCall('PUT', `/api/tables.php?table_id=${editId}`, { name, capacity, area_id: areaId });
        } else {
            await apiCall('POST', '/api/tables.php', { action:'create_table', restaurant_id:REST_ID, name, capacity, area_id: areaId });
        }
        cancelTableForm();
        tablesLoaded = false; await loadTables();
        toast(editId ? 'Miza posodobljena.' : 'Miza dodana.');
    } catch(e) { toast(e.message,'error'); }
}

async function deleteTable(id) {
    if (!confirm('Izbriši mizo?')) return;
    try {
        await apiCall('DELETE', `/api/tables.php?table_id=${id}`);
        tablesLoaded = false; await loadTables();
        toast('Miza izbrisana.');
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
        <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;cursor:pointer;background:var(--color-surface);border:1.5px solid var(--color-border);border-radius:8px;padding:6px 10px">
            <input type="checkbox" value="${t.id}" ${g?.member_ids?.includes(t.id)?'checked':''}>
            ${h(t.name)} <span style="font-size:.75rem;color:var(--color-muted)">(${t.capacity} os.)</span>
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
    if (checked.length < 2) { toast('Izberite vsaj 2 mizi.','error'); return; }
    try {
        if (editId) {
            await apiCall('PUT', `/api/tables.php?merge_group_id=${editId}`, { name, member_table_ids: checked });
        } else {
            await apiCall('POST', '/api/tables.php', { action:'create_merge_group', restaurant_id:REST_ID, name, member_table_ids: checked });
        }
        cancelMergeForm();
        tablesLoaded = false; await loadTables();
        toast(editId ? 'Skupina posodobljena.' : 'Skupina dodana.');
    } catch(e) { toast(e.message,'error'); }
}

async function deleteMergeGroup(id) {
    if (!confirm('Izbriši skupino za združevanje?')) return;
    try {
        await apiCall('DELETE', `/api/tables.php?merge_group_id=${id}`);
        tablesLoaded = false; await loadTables();
        toast('Skupina izbrisana.');
    } catch(e) { toast(e.message,'error'); }
}
<?php else: ?>
let tablesLoaded = false;
function loadTables() { tablesLoaded = true; }
<?php endif; ?>
</script>

</body>
</html>
