<?php
/**
 * Javna stran ankete – dostopna brez prijave prek unikatnega tokena.
 * URL: /pages/survey.php?t={token}
 */
require_once '../config.php';
require_once '../includes/lang.php';
require_once '../includes/db.php';

$token = trim($_GET['t'] ?? '');

// Naloži UI v jeziku, v katerem je bila gostu poslana anketa.
if ($token) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT survey_language FROM survey_responses WHERE token = ?");
        $stmt->execute([$token]);
        $sLang = $stmt->fetchColumn();
        if ($sLang && in_array($sLang, ['sl','en','de','it','fr','hr','es','pt'], true)) {
            _rz_load_lang($sLang);
        }
    } catch (PDOException $e) { /* ignore — fallback na privzet jezik */ }
}
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
    <title><?= t('survey.page_title') ?> – <?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box}
        body{margin:0;padding:0;background:#F3F4F6;font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#111827;min-height:100vh}
        .survey-page{max-width:640px;margin:0 auto;padding:40px 16px 60px}
        .survey-header{text-align:center;margin-bottom:28px}
        .survey-logo{display:inline-flex;align-items:center;gap:10px;margin-bottom:18px}
        .survey-logo-icon{background:#F59E0B;border-radius:10px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .survey-logo-icon svg{display:block}
        .survey-logo-name{font-size:1.05rem;font-weight:700;color:#111827}
        .survey-card{background:#fff;border-radius:14px;padding:28px 28px 32px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:16px}
        .survey-title{font-size:1.3rem;font-weight:700;color:#111827;margin:0 0 8px}
        .survey-desc{font-size:.9rem;color:#6B7280;line-height:1.6;margin:0}
        .question-block{margin-bottom:26px}
        .question-block:last-child{margin-bottom:0}
        .question-label{font-size:.92rem;font-weight:600;color:#111827;margin-bottom:10px;display:block;line-height:1.45}
        .question-label .required{color:#EF4444;margin-left:3px}
        /* Rating */
        .stars{display:flex;gap:6px;flex-wrap:wrap}
        .star-btn{background:none;border:none;cursor:pointer;padding:2px;line-height:1;font-size:0}
        .star-btn svg{transition:fill .1s,stroke .1s;display:block}
        .star-btn.active svg{fill:#F59E0B;stroke:#F59E0B}
        .star-btn:hover svg,.star-btn.hover svg{fill:#FDE68A;stroke:#F59E0B}
        /* Radio / Checkbox */
        .option-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border:1px solid #E5E7EB;border-radius:8px;margin-bottom:6px;cursor:pointer;transition:border-color .15s,background .15s}
        .option-item:hover{border-color:#F59E0B;background:#FFFBEB}
        .option-item.selected{border-color:#F59E0B;background:#FFFBEB}
        .option-item input{cursor:pointer;accent-color:#F59E0B;width:16px;height:16px;flex-shrink:0}
        .option-item label{font-size:.9rem;color:#374151;cursor:pointer;flex:1}
        /* Text / Textarea */
        .question-input{width:100%;border:1px solid #D1D5DB;border-radius:8px;padding:10px 13px;font-size:.9rem;font-family:inherit;color:#111827;transition:border-color .15s}
        .question-input:focus{outline:none;border-color:#F59E0B;box-shadow:0 0 0 3px rgba(245,158,11,.12)}
        textarea.question-input{resize:vertical;min-height:90px}
        /* Soglasje */
        .consent-section{background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:18px 20px;margin-top:8px}
        .consent-title{font-size:.92rem;font-weight:600;color:#111827;margin:0 0 12px}
        .consent-note{font-size:.8rem;color:#9CA3AF;margin-top:10px}
        /* Gumb */
        .btn-submit{background:#F59E0B;color:#fff;border:none;border-radius:9px;padding:13px 36px;font-size:1rem;font-weight:600;cursor:pointer;font-family:inherit;transition:.15s;width:100%;margin-top:24px}
        .btn-submit:hover{background:#D97706}
        .btn-submit:disabled{opacity:.6;cursor:default}
        /* Status strani */
        .state-wrap{text-align:center;padding:60px 20px}
        .state-icon{font-size:3rem;margin-bottom:16px}
        .state-title{font-size:1.3rem;font-weight:700;color:#111827;margin-bottom:8px}
        .state-sub{font-size:.9rem;color:#6B7280}
        .err-text{color:#EF4444;font-size:.83rem;margin-top:6px}
        @media(max-width:480px){.survey-card{padding:20px 16px 24px}}
    </style>
    <script>
    window.__T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
    window.t = function(k, p) { var s = window.__T__[k] || k; if (p) { for (var x in p) s = s.split('{'+x+'}').join(p[x]); } return s; };
    </script>
</head>
<body>
<div class="survey-page">
    <div class="survey-header">
        <div class="survey-logo">
            <div class="survey-logo-icon">
                <svg width="20" height="20" viewBox="0 0 28 28" fill="none">
                    <path d="M7 10h14M7 14h14M7 18h9" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>
                </svg>
            </div>
            <span class="survey-logo-name"><?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?></span>
        </div>
    </div>

    <div id="app">
        <div class="state-wrap"><div class="state-icon">⏳</div><div class="state-sub"><?= t('survey.loading') ?></div></div>
    </div>
</div>

<script>
const BASE   = '<?= htmlspecialchars(BASE_PATH, ENT_QUOTES) ?>';
const TOKEN  = '<?= htmlspecialchars($token, ENT_QUOTES) ?>';

function h(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

const app = document.getElementById('app');

function showState(icon, title, sub) {
    app.innerHTML = `
        <div class="state-wrap">
            <div class="state-icon">${icon}</div>
            <div class="state-title">${h(title)}</div>
            <div class="state-sub">${h(sub)}</div>
        </div>`;
}

if (!TOKEN) {
    showState('❌', window.t('survey.invalid_link'), window.t('survey.invalid_link_sub'));
} else {
    fetch(`${BASE}/api/survey.php?action=get_by_token&token=${encodeURIComponent(TOKEN)}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                showState('❌', window.t('survey.invalid_link'), res.error || '');
                return;
            }
            if (res.data && res.data.already_submitted) {
                showState('✅', window.t('survey.already_submitted_title'), window.t('survey.already_submitted_sub'));
                return;
            }
            renderSurvey(res.data);
        })
        .catch(() => showState('❌', window.t('survey.error_title'), window.t('survey.error_sub')));
}

function renderSurvey(data) {
    const { restaurant_name, title, description, questions } = data;

    let qHtml = '';
    (questions || []).forEach((q, i) => {
        qHtml += `<div class="question-block" data-qid="${q.id}" data-type="${h(q.type)}" data-required="${q.is_required}">`;
        qHtml += `<span class="question-label">${h(q.question_text)}${q.is_required ? '<span class="required">*</span>' : ''}</span>`;

        if (q.type === 'rating') {
            qHtml += renderRating(q.id);
        } else if (q.type === 'radio') {
            (q.options || []).forEach(o => {
                qHtml += `
                <div class="option-item" onclick="selectOption(${q.id},'radio',${o.id},this)">
                    <input type="radio" name="q${q.id}" value="${o.id}" id="o${o.id}">
                    <label for="o${o.id}">${h(o.label)}</label>
                </div>`;
            });
        } else if (q.type === 'checkbox') {
            (q.options || []).forEach(o => {
                qHtml += `
                <div class="option-item" onclick="selectOption(${q.id},'checkbox',${o.id},this)">
                    <input type="checkbox" name="q${q.id}" value="${o.id}" id="o${o.id}">
                    <label for="o${o.id}">${h(o.label)}</label>
                </div>`;
            });
        } else if (q.type === 'text') {
            qHtml += `<input type="text" class="question-input" id="ans-${q.id}" maxlength="500">`;
        } else if (q.type === 'textarea') {
            qHtml += `<textarea class="question-input" id="ans-${q.id}" maxlength="2000"></textarea>`;
        }

        qHtml += `<div class="err-text" id="err-${q.id}" style="display:none"></div>`;
        qHtml += '</div>';
    });

    // Soglasje
    const consentHtml = `
    <div class="question-block" id="block-consent">
        <span class="question-label">${window.t('survey.consent_label')}<span class="required">*</span></span>
        <div class="consent-section">
            <div class="consent-title">${window.t('survey.consent_title')}</div>
            <div class="option-item" onclick="selectConsent('public', this)">
                <input type="radio" name="consent" value="public" id="c-public">
                <label for="c-public">${window.t('survey.consent_public')}</label>
            </div>
            <div class="option-item" onclick="selectConsent('anonymous', this)">
                <input type="radio" name="consent" value="anonymous" id="c-anon">
                <label for="c-anon">${window.t('survey.consent_anonymous')}</label>
            </div>
            <div class="option-item" onclick="selectConsent('private', this)">
                <input type="radio" name="consent" value="private" id="c-private">
                <label for="c-private">${window.t('survey.consent_private')}</label>
            </div>
            <p class="consent-note">${window.t('survey.consent_note')}</p>
        </div>
        <div class="err-text" id="err-consent" style="display:none">${window.t('survey.consent_error')}</div>
    </div>`;

    app.innerHTML = `
        <div class="survey-card">
            <div class="survey-title">${h(title)}</div>
            ${description ? `<p class="survey-desc">${h(description)}</p>` : ''}
        </div>
        <form class="survey-card" id="survey-form" onsubmit="return false">
            ${qHtml}
            ${consentHtml}
            <button type="button" class="btn-submit" id="btn-submit" onclick="submitSurvey()">${window.t('survey.submit_btn')}</button>
        </form>`;
}

function renderRating(qid) {
    let html = `<div class="stars" id="stars-${qid}" data-value="0">`;
    for (let i = 1; i <= 5; i++) {
        html += `
        <button type="button" class="star-btn" data-val="${i}"
            onclick="setRating(${qid},${i})"
            onmouseenter="hoverRating(${qid},${i})"
            onmouseleave="leaveRating(${qid})">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="1.5">
                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
        </button>`;
    }
    html += '</div>';
    return html;
}

function setRating(qid, val) {
    const container = document.getElementById(`stars-${qid}`);
    container.dataset.value = val;
    updateStars(container, val, val);
}
function hoverRating(qid, val) {
    const container = document.getElementById(`stars-${qid}`);
    const current = parseInt(container.dataset.value) || 0;
    updateStars(container, current, val);
}
function leaveRating(qid) {
    const container = document.getElementById(`stars-${qid}`);
    const current = parseInt(container.dataset.value) || 0;
    updateStars(container, current, current);
}
function updateStars(container, selected, hover) {
    container.querySelectorAll('.star-btn').forEach(btn => {
        const v = parseInt(btn.dataset.val);
        btn.classList.toggle('active', v <= selected);
        btn.classList.toggle('hover', v <= hover && v > selected);
    });
}

// Radio
function selectOption(qid, type, optId, el) {
    const block = document.querySelector(`[data-qid="${qid}"]`);
    if (type === 'radio') {
        block.querySelectorAll('.option-item').forEach(i => {
            i.classList.remove('selected');
            i.querySelector('input').checked = false;
        });
        el.classList.add('selected');
        el.querySelector('input').checked = true;
    } else {
        el.classList.toggle('selected');
        el.querySelector('input').checked = !el.querySelector('input').checked;
        // Klik je bil na div, toggle je morda obrnil input
        el.querySelector('input').checked = el.classList.contains('selected');
    }
}

let selectedConsent = null;
function selectConsent(val, el) {
    selectedConsent = val;
    document.querySelectorAll('#block-consent .option-item').forEach(i => {
        i.classList.remove('selected');
        i.querySelector('input').checked = false;
    });
    el.classList.add('selected');
    el.querySelector('input').checked = true;
    document.getElementById('err-consent').style.display = 'none';
}

function getAnswers() {
    const answers = {};
    document.querySelectorAll('.question-block[data-qid]').forEach(block => {
        const qid  = parseInt(block.dataset.qid);
        const type = block.dataset.type;

        if (type === 'rating') {
            const val = parseInt(document.getElementById(`stars-${qid}`).dataset.value) || 0;
            if (val > 0) answers[qid] = val;
        } else if (type === 'radio') {
            const checked = block.querySelector('input[type=radio]:checked');
            if (checked) answers[qid] = parseInt(checked.value);
        } else if (type === 'checkbox') {
            const checked = [...block.querySelectorAll('input[type=checkbox]:checked')].map(i => parseInt(i.value));
            if (checked.length) answers[qid] = checked;
        } else {
            const inp = document.getElementById(`ans-${qid}`);
            if (inp && inp.value.trim()) answers[qid] = inp.value.trim();
        }
    });
    return answers;
}

function validate(answers) {
    let ok = true;
    document.querySelectorAll('.question-block[data-qid]').forEach(block => {
        const qid = parseInt(block.dataset.qid);
        const errEl = document.getElementById(`err-${qid}`);
        if (!block.dataset.required || block.dataset.required === '0') { errEl.style.display='none'; return; }
        const ans = answers[qid];
        const missing = ans === undefined || ans === null || ans === '' || (Array.isArray(ans) && ans.length === 0);
        errEl.style.display = missing ? '' : 'none';
        errEl.textContent = window.t('survey.field_required');
        if (missing) ok = false;
    });
    if (!selectedConsent) {
        document.getElementById('err-consent').style.display = '';
        ok = false;
    }
    return ok;
}

function submitSurvey() {
    const answers = getAnswers();
    if (!validate(answers)) return;

    const btn = document.getElementById('btn-submit');
    btn.disabled = true;
    btn.textContent = window.t('survey.submitting');

    fetch(`${BASE}/api/survey.php?action=submit_response`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: TOKEN, consent: selectedConsent, answers }),
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            showState('🙏', window.t('survey.thank_you_title'), window.t('survey.thank_you_sub'));
        } else {
            btn.disabled = false;
            btn.textContent = window.t('survey.submit_btn');
            alert(res.error || window.t('survey.err_submit'));
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.textContent = window.t('survey.submit_btn');
        alert(window.t('survey.err_network'));
    });
}
</script>
</body>
</html>
