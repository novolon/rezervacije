<?php
// survey_builder.php je preseljen v restaurant-edit.php (tab Anketa)
require_once '../includes/auth_check.php';
if (is_logged_in()) {
    header('Location: ' . BASE_PATH . '/pages/admin.php');
    exit;
}
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/lang.php';

if (!is_logged_in()) redirect_to_login();
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
$hasSurvey = user_has_feature($pdo, (int)$_SESSION['user_id'], 'survey');

// Naloži restavracije
$restaurants = [];
if ($isAdmin) {
    $stmt = $pdo->prepare("
        SELECT r.id, r.name FROM restaurants r
        JOIN restaurant_admins ra ON r.id = ra.restaurant_id
        WHERE ra.user_id = ? AND r.is_active = 1 ORDER BY r.name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $restaurants = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('survey_builder.page_title') ?> – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css?v=4">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin.css?v=3">
    <style>
        .survey-wrap{max-width:780px;margin:0 auto;padding:28px 16px 60px}
        .survey-section{background:#fff;border-radius:12px;padding:24px;margin-bottom:20px;border:1px solid #E5E7EB}
        .survey-section h3{margin:0 0 18px;font-size:1rem;font-weight:600;color:#111827;display:flex;align-items:center;gap:8px}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
        .form-row.full{grid-template-columns:1fr}
        .form-group{display:flex;flex-direction:column;gap:5px}
        .form-group label{font-size:.82rem;font-weight:500;color:#374151}
        .form-group input,.form-group textarea,.form-group select{border:1px solid #D1D5DB;border-radius:8px;padding:9px 12px;font-size:.88rem;font-family:inherit;color:#111827;background:#fff;transition:border-color .15s}
        .form-group input:focus,.form-group textarea:focus,.form-group select:focus{outline:none;border-color:#F59E0B;box-shadow:0 0 0 3px rgba(245,158,11,.12)}
        .form-group textarea{resize:vertical;min-height:80px}
        .toggle-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #F3F4F6}
        .toggle-row:last-child{border-bottom:none}
        .toggle-label{font-size:.88rem;color:#374151}
        .toggle-label small{display:block;font-size:.78rem;color:#9CA3AF;margin-top:2px}
        .toggle-switch{position:relative;width:40px;height:22px;flex-shrink:0}
        .toggle-switch input{opacity:0;width:0;height:0}
        .toggle-slider{position:absolute;inset:0;background:#D1D5DB;border-radius:22px;cursor:pointer;transition:.2s}
        .toggle-slider:before{content:'';position:absolute;width:16px;height:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
        .toggle-switch input:checked+.toggle-slider{background:#F59E0B}
        .toggle-switch input:checked+.toggle-slider:before{transform:translateX(18px)}
        .delay-field{display:none;margin-top:12px}
        .delay-field.visible{display:flex;align-items:center;gap:8px}
        .delay-field input{width:70px}
        .delay-field span{font-size:.88rem;color:#6B7280}

        /* Vprašanja */
        .question-list{display:flex;flex-direction:column;gap:10px;margin-bottom:16px}
        .question-card{background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:14px 16px}
        .question-card-top{display:flex;align-items:flex-start;gap:10px}
        .question-card-order{display:flex;flex-direction:column;gap:2px;flex-shrink:0;padding-top:2px}
        .question-card-order button{background:none;border:1px solid #D1D5DB;border-radius:4px;width:22px;height:22px;cursor:pointer;font-size:.75rem;color:#6B7280;display:flex;align-items:center;justify-content:center;padding:0}
        .question-card-order button:hover{background:#F3F4F6}
        .question-card-body{flex:1;display:flex;flex-direction:column;gap:8px}
        .question-card-body input[type=text],.question-card-body select{border:1px solid #D1D5DB;border-radius:7px;padding:8px 10px;font-size:.85rem;font-family:inherit;color:#111827;background:#fff;width:100%}
        .question-card-body input:focus,.question-card-body select:focus{outline:none;border-color:#F59E0B}
        .question-meta{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
        .question-meta label{font-size:.8rem;color:#6B7280;display:flex;align-items:center;gap:5px;cursor:pointer}
        .question-meta input[type=checkbox]{cursor:pointer}
        .question-actions{display:flex;justify-content:flex-end}
        .btn-delete-q{background:none;border:none;cursor:pointer;color:#EF4444;font-size:.8rem;padding:4px 8px;border-radius:5px;display:flex;align-items:center;gap:4px}
        .btn-delete-q:hover{background:#FEF2F2}
        .options-list{display:flex;flex-direction:column;gap:5px;margin-top:4px}
        .option-row{display:flex;align-items:center;gap:6px}
        .option-row input{flex:1;border:1px solid #D1D5DB;border-radius:6px;padding:6px 9px;font-size:.83rem;font-family:inherit}
        .option-row input:focus{outline:none;border-color:#F59E0B}
        .btn-remove-opt{background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:1rem;line-height:1;padding:2px 5px}
        .btn-remove-opt:hover{color:#EF4444}
        .btn-add-opt{background:none;border:1px dashed #D1D5DB;border-radius:6px;padding:5px 10px;font-size:.8rem;color:#6B7280;cursor:pointer;margin-top:4px;width:100%}
        .btn-add-opt:hover{border-color:#F59E0B;color:#F59E0B}
        .btn-add-q{border:2px dashed #D1D5DB;border-radius:10px;padding:12px;font-size:.88rem;color:#6B7280;background:none;cursor:pointer;width:100%;font-family:inherit;transition:.15s}
        .btn-add-q:hover{border-color:#F59E0B;color:#F59E0B}
        .btn-save-survey{background:#F59E0B;color:#fff;border:none;border-radius:9px;padding:12px 28px;font-size:.95rem;font-weight:600;cursor:pointer;font-family:inherit;transition:.15s}
        .btn-save-survey:hover{background:#D97706}
        .btn-save-survey:disabled{opacity:.6;cursor:default}
        .save-status{font-size:.85rem;color:#6B7280;margin-left:12px}
        .save-status.ok{color:#059669}
        .save-status.err{color:#EF4444}
        .gate-notice{background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px;padding:18px 20px;margin-bottom:20px;font-size:.9rem;color:#92400E}
        .gate-notice a{color:#B45309;font-weight:600}
        @media(max-width:600px){.form-row{grid-template-columns:1fr}}
    </style>
    <script>
    window.__T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
    window.t = function(k, p) { var s = window.__T__[k] || k; if (p) { for (var x in p) s = s.split('{'+x+'}').join(p[x]); } return s; };
    </script>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────── -->
<header class="app-header">
    <a href="<?= BASE_PATH ?>/pages/main.php" class="header-logo" style="flex-shrink:0">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
            <rect width="28" height="28" rx="7" fill="#F59E0B"/>
            <path d="M7 10h14M7 14h14M7 18h9" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <?= h(APP_NAME) ?>
        <?php if ($isAdmin): ?><?= plan_badge($_SESSION['plan_slug'] ?? 'trial') ?><?php endif; ?>
    </a>
    <div class="header-restaurant">
        <span style="color:rgba(255,255,255,.5);font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600"><?= t('survey_builder.header_label') ?></span>
    </div>
    <div class="header-actions">
        <span class="header-user">👤 <?= h($fullName) ?></span>
        <a href="<?= BASE_PATH ?>/pages/main.php" class="btn-header btn-header-admin">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            <?= t('survey_builder.schedule_link') ?>
        </a>
        <?php if ($isAdmin): ?>
        <a href="<?= BASE_PATH ?>/pages/admin.php" class="btn-header btn-header-admin">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
            <?= t('survey_builder.admin_link') ?>
        </a>
        <a href="<?= BASE_PATH ?>/pages/survey_results.php" class="btn-header">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            <?= t('survey_builder.responses_link') ?>
        </a>
        <?php endif; ?>
        <a href="<?= BASE_PATH ?>/pages/profile.php" class="btn-header" title="<?= t('survey_builder.profile_link') ?>"><?= t('survey_builder.profile_link') ?></a>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout"><?= t('survey_builder.logout') ?></a>
    </div>
    <button class="hamburger-btn" id="hamburger-btn" onclick="document.getElementById('mobile-nav').classList.toggle('open')">
        <span></span><span></span><span></span>
    </button>
</header>

<div class="mobile-nav" id="mobile-nav">
    <div class="mobile-nav-user">👤 <?= h($fullName) ?></div>
    <a href="<?= BASE_PATH ?>/pages/main.php" class="btn-header btn-header-admin"><?= t('survey_builder.schedule_link') ?></a>
    <?php if ($isAdmin): ?>
    <a href="<?= BASE_PATH ?>/pages/admin.php" class="btn-header btn-header-admin"><?= t('survey_builder.admin_link') ?></a>
    <a href="<?= BASE_PATH ?>/pages/survey_results.php" class="btn-header"><?= t('survey_builder.mobile_responses') ?></a>
    <?php endif; ?>
    <a href="<?= BASE_PATH ?>/pages/profile.php" class="btn-header"><?= t('survey_builder.profile_link') ?></a>
    <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout"><?= t('survey_builder.logout') ?></a>
</div>

<?php require_once '../includes/trial_banner.php'; ?>

<div class="survey-wrap">

    <?php if (!$hasSurvey): ?>
    <div class="gate-notice">
        <?= t_raw('survey_builder.gate_notice') ?>
        <a href="<?= BASE_PATH ?>/pages/billing.php"><?= t('survey_builder.gate_upgrade') ?></a>
    </div>
    <?php else: ?>

    <!-- Dropdown za restavracijo -->
    <?php if ($isAdmin && count($restaurants) > 1): ?>
    <div style="margin-bottom:20px">
        <label style="font-size:.85rem;font-weight:500;color:#374151;display:block;margin-bottom:6px"><?= t('survey_builder.restaurant_label') ?></label>
        <select id="restaurant-select" style="border:1px solid #D1D5DB;border-radius:8px;padding:9px 12px;font-size:.9rem;font-family:inherit;min-width:220px;background:#fff">
            <option value=""><?= t('survey_builder.restaurant_placeholder') ?></option>
            <?php foreach ($restaurants as $r): ?>
            <option value="<?= $r['id'] ?>"><?= h($r['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php elseif (count($restaurants) === 1): ?>
    <input type="hidden" id="restaurant-select" value="<?= $restaurants[0]['id'] ?>">
    <?php endif; ?>

    <div id="survey-editor" style="display:none">

        <!-- Nastavitve -->
        <div class="survey-section">
            <h3>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
                <?= t('survey_builder.settings_title') ?>
            </h3>
            <div class="form-row full">
                <div class="form-group">
                    <label><?= t('survey_builder.field_title') ?></label>
                    <input type="text" id="sf-title" maxlength="255">
                </div>
            </div>
            <div class="form-row full">
                <div class="form-group">
                    <label><?= t('survey_builder.field_description') ?></label>
                    <textarea id="sf-description" rows="2"></textarea>
                </div>
            </div>
            <div class="form-row full">
                <div class="form-group">
                    <label><?= t('survey_builder.field_thankyou') ?></label>
                    <textarea id="sf-thankyou" rows="3"></textarea>
                </div>
            </div>
        </div>

        <!-- Pošiljanje -->
        <div class="survey-section">
            <h3>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                <?= t('survey_builder.send_title') ?>
            </h3>
            <div class="toggle-row">
                <div class="toggle-label">
                    <?= t('survey_builder.send_enabled') ?>
                    <small><?= t('survey_builder.send_enabled_hint') ?></small>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" id="sf-send-enabled" onchange="toggleDelay()">
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="delay-field" id="delay-field">
                <label style="font-size:.85rem;color:#374151"><?= t('survey_builder.send_after') ?></label>
                <input type="number" id="sf-delay" min="0" max="168" value="2" style="width:70px;border:1px solid #D1D5DB;border-radius:7px;padding:7px 10px;font-size:.9rem;font-family:inherit">
                <span><?= t('survey_builder.send_hours') ?></span>
            </div>
            <div class="toggle-row" style="margin-top:10px">
                <div class="toggle-label"><?= t('survey_builder.incl_thankyou') ?></div>
                <label class="toggle-switch">
                    <input type="checkbox" id="sf-incl-thankyou" checked>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="toggle-row">
                <div class="toggle-label"><?= t('survey_builder.incl_survey') ?></div>
                <label class="toggle-switch">
                    <input type="checkbox" id="sf-incl-survey" checked>
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>

        <!-- Vprašanja -->
        <div class="survey-section">
            <h3>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3M12 17h.01"/></svg>
                <?= t('survey_builder.questions_title') ?>
            </h3>
            <div class="question-list" id="question-list"></div>
            <button class="btn-add-q" onclick="addQuestion()"><?= t('survey_builder.add_question') ?></button>
        </div>

        <div style="display:flex;align-items:center;gap:0">
            <button class="btn-save-survey" id="btn-save" onclick="saveForm()"><?= t('survey_builder.save_btn') ?></button>
            <span class="save-status" id="save-status"></span>
        </div>

    </div>

    <div id="no-restaurant-msg" style="color:#9CA3AF;font-size:.9rem;padding:20px 0">
        <?php if (empty($restaurants)): ?>
        <?= t('survey_builder.no_restaurant') ?> <a href="<?= BASE_PATH ?>/pages/admin.php" style="color:#F59E0B">Admin</a>.
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>

<script>
const BASE = '<?= BASE_PATH ?>';
let currentRestaurantId = null;
let questionCounter = 0;

// Tip labels
const TYPE_LABELS = {
    rating:   window.t('survey_builder.type_rating'),
    radio:    window.t('survey_builder.type_radio'),
    checkbox: window.t('survey_builder.type_checkbox'),
    text:     window.t('survey_builder.type_text'),
    textarea: window.t('survey_builder.type_textarea'),
};

function h(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function toggleDelay() {
    const d = document.getElementById('delay-field');
    d.classList.toggle('visible', document.getElementById('sf-send-enabled').checked);
}

// ── Selector ──
const sel = document.getElementById('restaurant-select');
if (sel && sel.tagName === 'SELECT') {
    sel.addEventListener('change', () => {
        if (sel.value) loadForm(parseInt(sel.value));
        else { currentRestaurantId = null; document.getElementById('survey-editor').style.display='none'; }
    });
} else if (sel && sel.tagName === 'INPUT') {
    // Samo ena restavracija
    loadForm(parseInt(sel.value));
}

function loadForm(restId) {
    currentRestaurantId = restId;
    fetch(`${BASE}/api/survey.php?action=get_form&restaurant_id=${restId}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) { alert(res.error || window.t('survey_builder.err_load')); return; }
            document.getElementById('survey-editor').style.display = 'block';
            document.getElementById('no-restaurant-msg').style.display = 'none';
            if (res.data) {
                fillForm(res.data);
            } else {
                resetForm();
            }
        });
}

function fillForm(d) {
    document.getElementById('sf-title').value       = d.title || '';
    document.getElementById('sf-description').value = d.description || '';
    document.getElementById('sf-thankyou').value    = d.thank_you_message || '';
    document.getElementById('sf-send-enabled').checked  = !!parseInt(d.send_enabled);
    document.getElementById('sf-delay').value            = d.send_delay_hours || 2;
    document.getElementById('sf-incl-thankyou').checked = !!parseInt(d.include_thankyou);
    document.getElementById('sf-incl-survey').checked   = !!parseInt(d.include_survey);
    toggleDelay();

    const list = document.getElementById('question-list');
    list.innerHTML = '';
    questionCounter = 0;
    (d.questions || []).forEach(q => addQuestion(q));
}

function resetForm() {
    document.getElementById('sf-title').value        = window.t('survey_builder.default_title');
    document.getElementById('sf-description').value  = '';
    document.getElementById('sf-thankyou').value     = '';
    document.getElementById('sf-send-enabled').checked  = false;
    document.getElementById('sf-delay').value            = 2;
    document.getElementById('sf-incl-thankyou').checked = true;
    document.getElementById('sf-incl-survey').checked   = true;
    toggleDelay();
    document.getElementById('question-list').innerHTML = '';
    questionCounter = 0;
}

function addQuestion(data = null) {
    questionCounter++;
    const id  = `q${questionCounter}`;
    const type = data ? data.type : 'rating';
    const text = data ? (data.question_text || '') : '';
    const req  = data ? !!parseInt(data.is_required) : false;
    const opts = (data && data.options) ? data.options : [];

    const card = document.createElement('div');
    card.className = 'question-card';
    card.id = `card-${id}`;
    card.dataset.qid = data ? data.id : '';

    card.innerHTML = `
        <div class="question-card-top">
            <div class="question-card-order">
                <button title="${window.t('survey_builder.btn_up')}" onclick="moveQuestion('${id}', -1)">▲</button>
                <button title="${window.t('survey_builder.btn_down')}" onclick="moveQuestion('${id}', 1)">▼</button>
            </div>
            <div class="question-card-body">
                <input type="text" placeholder="${window.t('survey_builder.q_placeholder')}" value="${h(text)}" id="qt-${id}">
                <div class="question-meta">
                    <select id="qtype-${id}" onchange="onTypeChange('${id}')">
                        ${Object.entries(TYPE_LABELS).map(([v,l]) => `<option value="${v}"${v===type?' selected':''}>${l}</option>`).join('')}
                    </select>
                    <label>
                        <input type="checkbox" id="qreq-${id}"${req?' checked':''}> ${window.t('survey_builder.q_required')}
                    </label>
                </div>
                <div class="options-list" id="opts-${id}"></div>
                <button class="btn-add-opt" id="btn-addopt-${id}" onclick="addOption('${id}')" style="display:none">${window.t('survey_builder.btn_add_option')}</button>
            </div>
            <div class="question-actions" style="flex-shrink:0">
                <button class="btn-delete-q" onclick="removeQuestion('${id}')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                    ${window.t('survey_builder.btn_delete')}
                </button>
            </div>
        </div>
    `;

    document.getElementById('question-list').appendChild(card);

    // Dodaj obstoječe možnosti
    if (opts.length) {
        opts.forEach(o => addOption(id, o.label));
    }
    onTypeChange(id);
}

function onTypeChange(id) {
    const type = document.getElementById(`qtype-${id}`).value;
    const needsOpts = type === 'radio' || type === 'checkbox';
    const optsList = document.getElementById(`opts-${id}`);
    const btnAdd   = document.getElementById(`btn-addopt-${id}`);
    btnAdd.style.display = needsOpts ? '' : 'none';
    if (!needsOpts) {
        optsList.innerHTML = '';
    } else if (needsOpts && optsList.children.length === 0) {
        addOption(id, '');
    }
}

let optCounter = 0;
function addOption(qid, value = '') {
    optCounter++;
    const oid = `opt${optCounter}`;
    const row = document.createElement('div');
    row.className = 'option-row';
    row.id = `optrow-${oid}`;
    row.innerHTML = `
        <input type="text" placeholder="${window.t('survey_builder.opt_placeholder')}" value="${h(value)}" id="${oid}">
        <button class="btn-remove-opt" onclick="document.getElementById('optrow-${oid}').remove()" title="Odstrani">×</button>
    `;
    document.getElementById(`opts-${qid}`).appendChild(row);
}

function removeQuestion(id) {
    document.getElementById(`card-${id}`).remove();
}

function moveQuestion(id, dir) {
    const card = document.getElementById(`card-${id}`);
    const list = document.getElementById('question-list');
    if (dir === -1 && card.previousElementSibling) {
        list.insertBefore(card, card.previousElementSibling);
    } else if (dir === 1 && card.nextElementSibling) {
        list.insertBefore(card.nextElementSibling, card);
    }
}

function collectQuestions() {
    const cards = document.querySelectorAll('#question-list .question-card');
    return Array.from(cards).map(card => {
        const id  = card.id.replace('card-', '');
        const type = document.getElementById(`qtype-${id}`).value;
        const opts = [];
        card.querySelectorAll(`#opts-${id} .option-row input[type=text]`).forEach(inp => {
            const v = inp.value.trim();
            if (v) opts.push({ label: v });
        });
        return {
            question_text: document.getElementById(`qt-${id}`).value.trim(),
            type,
            is_required: document.getElementById(`qreq-${id}`).checked ? 1 : 0,
            options: opts,
        };
    });
}

function saveForm() {
    if (!currentRestaurantId) { alert(window.t('survey_builder.err_no_restaurant')); return; }
    const btn = document.getElementById('btn-save');
    const status = document.getElementById('save-status');
    btn.disabled = true;
    status.className = 'save-status';
    status.textContent = window.t('survey_builder.saving');

    const payload = {
        restaurant_id:    currentRestaurantId,
        title:            document.getElementById('sf-title').value.trim(),
        description:      document.getElementById('sf-description').value.trim(),
        thank_you_message:document.getElementById('sf-thankyou').value.trim(),
        send_enabled:     document.getElementById('sf-send-enabled').checked ? 1 : 0,
        send_delay_hours: parseInt(document.getElementById('sf-delay').value) || 2,
        include_thankyou: document.getElementById('sf-incl-thankyou').checked ? 1 : 0,
        include_survey:   document.getElementById('sf-incl-survey').checked ? 1 : 0,
        questions:        collectQuestions(),
    };

    fetch(`${BASE}/api/survey.php?action=save_form`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        if (res.success) {
            status.className = 'save-status ok';
            status.textContent = window.t('survey_builder.saved');
            setTimeout(() => status.textContent = '', 3000);
        } else {
            status.className = 'save-status err';
            status.textContent = res.error || window.t('survey_builder.err_save');
        }
    })
    .catch(() => {
        btn.disabled = false;
        status.className = 'save-status err';
        status.textContent = window.t('survey_builder.err_connection');
    });
}
</script>
</body>
</html>
