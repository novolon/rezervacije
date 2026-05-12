/**
 * Translations UI – jezikovni "chips" + modal za urejanje prevodov.
 *
 * Uporaba:
 *   window.TranslationsUI.attach(container, {
 *     kind:           'survey_question' | 'survey_form' | 'survey_option' | 'area' | 'custom_field',
 *     targetId:       N,                   // ID resourca (question, area, …)
 *     primaryLang:    'sl',                // Master jezik (chip se ne prikaže za to)
 *     availableLangs: ['sl','de','en'],    // Vsi jeziki, za katere prikažemo chip
 *     masterText:     'Original v primary lang',
 *     translations:   { de: 'Wie...', en: '...' }, // initial; lahko prazen objekt
 *     fieldKey:       'question_text',     // Ključ v fields payload-u (privzeto glede na kind)
 *     onSaved:        function(lang, value) {}, // optional callback
 *   });
 *
 *   Vrne kontroler { refresh(translations), destroy() }.
 */
(function () {
  'use strict';

  const ALL_LABELS = {
    sl: 'SL', en: 'EN', de: 'DE', it: 'IT', fr: 'FR', hr: 'HR', es: 'ES', pt: 'PT',
  };
  const FULL_NAMES = {
    sl: 'Slovenščina', en: 'English', de: 'Deutsch', it: 'Italiano',
    fr: 'Français', hr: 'Hrvatski', es: 'Español', pt: 'Português',
  };

  // Mapping kind → endpoint config
  function endpointFor(kind, targetId) {
    if (kind === 'survey_form' || kind === 'survey_question' || kind === 'survey_option') {
      return {
        url: (window.APP_BASE || '') + '/api/survey.php?action=save_translation',
        bodyKind: kind === 'survey_form' ? 'form' : (kind === 'survey_question' ? 'question' : 'option'),
        fieldKey: kind === 'survey_question' ? 'question_text' : (kind === 'survey_option' ? 'label' : 'title'),
      };
    }
    if (kind === 'area') {
      return {
        url: (window.APP_BASE || '') + '/api/translations.php?action=save',
        bodyKind: 'area',
        fieldKey: 'name',
      };
    }
    if (kind === 'custom_field') {
      return {
        url: (window.APP_BASE || '') + '/api/translations.php?action=save',
        bodyKind: 'custom_field',
        fieldKey: 'label',
      };
    }
    return null;
  }

  function chipNode(lang, isFilled, onClick) {
    const c = document.createElement('button');
    c.type = 'button';
    c.className = 'rz-tr-chip' + (isFilled ? ' is-filled' : '');
    c.title = FULL_NAMES[lang] || lang;
    c.dataset.lang = lang;
    c.innerHTML = '<span class="rz-tr-dot" aria-hidden="true"></span>' +
                  '<span class="rz-tr-label">' + (ALL_LABELS[lang] || lang.toUpperCase()) + '</span>';
    c.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); onClick(lang); });
    return c;
  }

  function attach(container, opts) {
    if (!container) return null;
    const langs = (opts.availableLangs || ['sl']).filter(function (l) { return l !== opts.primaryLang; });
    let translations = Object.assign({}, opts.translations || {});

    container.classList.add('rz-tr-chips');
    container.innerHTML = '';

    function isFilled(lang) {
      const v = translations[lang];
      return typeof v === 'string' ? v.trim() !== '' : false;
    }

    function render() {
      container.innerHTML = '';
      langs.forEach(function (lang) {
        container.appendChild(chipNode(lang, isFilled(lang), openModal));
      });
    }

    function openModal(lang) {
      const overlay = document.createElement('div');
      overlay.className = 'rz-tr-modal-overlay';

      const box = document.createElement('div');
      box.className = 'rz-tr-modal';

      const T = window.t || function (k) { return k; };
      const titleTpl = T('translations.modal_title') || 'Translation: {lang}';
      const titleStr = titleTpl.replace('{lang}', FULL_NAMES[lang] || lang.toUpperCase());

      const masterLbl  = T('translations.master_label') || 'Original';
      const masterText = (opts.masterText || '').toString();
      const placeHolder= T('translations.input_placeholder') || 'Enter translation…';
      const saveBtn    = T('translations.save_btn')   || 'Save';
      const cancelBtn  = T('translations.cancel_btn') || 'Cancel';
      const clearBtn   = T('translations.clear_btn')  || 'Clear';

      const isMultiline = (opts.multiline === true) || (masterText && masterText.length > 80);
      const inputHtml = isMultiline
        ? '<textarea id="rz-tr-input" rows="4" maxlength="2000" placeholder="' + esc(placeHolder) + '"></textarea>'
        : '<input id="rz-tr-input" type="text" maxlength="500" placeholder="' + esc(placeHolder) + '">';

      box.innerHTML =
        '<div class="rz-tr-modal-head"><h3>' + esc(titleStr) + '</h3>' +
        '<button class="rz-tr-modal-close" type="button" aria-label="' + esc(cancelBtn) + '">×</button></div>' +
        '<div class="rz-tr-modal-body">' +
        inputHtml +
        '<div class="rz-tr-master"><span class="rz-tr-master-label">' + esc(masterLbl) +
        ' (' + esc((ALL_LABELS[opts.primaryLang] || opts.primaryLang.toUpperCase())) + '):</span>' +
        '<div class="rz-tr-master-text">' + esc(masterText) + '</div></div>' +
        '</div>' +
        '<div class="rz-tr-modal-foot">' +
        '<button type="button" class="rz-tr-btn rz-tr-btn-ghost" data-act="clear">' + esc(clearBtn) + '</button>' +
        '<div style="flex:1"></div>' +
        '<button type="button" class="rz-tr-btn rz-tr-btn-secondary" data-act="cancel">' + esc(cancelBtn) + '</button>' +
        '<button type="button" class="rz-tr-btn rz-tr-btn-primary" data-act="save">' + esc(saveBtn) + '</button>' +
        '</div>';

      overlay.appendChild(box);
      document.body.appendChild(overlay);

      const input = box.querySelector('#rz-tr-input');
      input.value = translations[lang] || '';
      setTimeout(function () { input.focus(); }, 50);

      function closeModal() { overlay.remove(); document.removeEventListener('keydown', onKey); }
      function onKey(e) {
        if (e.key === 'Escape') closeModal();
        else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey || !isMultiline)) {
          if (!isMultiline) { e.preventDefault(); doSave(); }
        }
      }
      document.addEventListener('keydown', onKey);

      box.querySelector('.rz-tr-modal-close').addEventListener('click', closeModal);
      overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
      box.querySelector('[data-act="cancel"]').addEventListener('click', closeModal);
      box.querySelector('[data-act="clear"]').addEventListener('click', function () {
        input.value = ''; doSave();
      });
      box.querySelector('[data-act="save"]').addEventListener('click', doSave);

      function doSave() {
        const value = input.value.trim();
        const ep    = endpointFor(opts.kind, opts.targetId);
        if (!ep) { closeModal(); return; }

        const fields = {};
        const fk     = opts.fieldKey || ep.fieldKey;
        fields[fk]   = value;

        // Survey kind uporablja drugačno body shemo (kind, target_id, lang_code, fields)
        // Translations.php uporablja (kind, id, lang_code, fields)
        let body;
        if (opts.kind === 'survey_form' || opts.kind === 'survey_question' || opts.kind === 'survey_option') {
          body = { kind: ep.bodyKind, target_id: opts.targetId, lang_code: lang, fields: fields };
        } else {
          body = { kind: ep.bodyKind, id: opts.targetId, lang_code: lang, fields: fields };
        }

        const btn = box.querySelector('[data-act="save"]');
        btn.disabled = true;

        fetch(ep.url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        })
          .then(function (r) { return r.json().catch(function () { return { success: false, error: 'Bad response' }; }); })
          .then(function (j) {
            if (!j.success) throw new Error(j.error || 'Save failed');
            translations[lang] = value;
            render();
            if (typeof opts.onSaved === 'function') opts.onSaved(lang, value);
            closeModal();
          })
          .catch(function (e) {
            btn.disabled = false;
            if (window.App && App.showToast) App.showToast(e.message || 'Save failed', 'error');
            else alert(e.message || 'Save failed');
          });
      }
    }

    function esc(s) {
      return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    render();
    return {
      refresh: function (newTrans) { translations = Object.assign({}, newTrans || {}); render(); },
      setTargetId: function (id) { opts.targetId = id; },
      setMasterText: function (txt) { opts.masterText = txt; },
      destroy: function () { container.innerHTML = ''; },
    };
  }

  window.TranslationsUI = { attach: attach };
})();
