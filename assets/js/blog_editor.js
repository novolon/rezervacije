/* ============================================================================
 * Booked editor – urejanje članka z EasyMDE + lang tabs + autosave.
 * ============================================================================ */
(function () {
    'use strict';

    const BASE = (window.APP_STATE && APP_STATE.base) || '';

    let postId = window.BLOG_POST_ID || 0;
    const _urlLang = new URLSearchParams(location.search).get('lang');
    let activeLang = (_urlLang && BLOG_LANGS.includes(_urlLang)) ? _urlLang : 'sl';
    let mde = null;
    let translationsCache = {};   // lang_code -> translation object
    let postCache = null;         // post object (master_lang, category, etc.)
    let saveTimer = null;
    let isLoading = false;

    const els = {
        title:        document.getElementById('bk-title'),
        slug:         document.getElementById('bk-slug'),
        excerpt:      document.getElementById('bk-excerpt'),
        metaTitle:    document.getElementById('bk-meta-title'),
        metaDesc:     document.getElementById('bk-meta-description'),
        readingTime:  document.getElementById('bk-reading-time'),
        contentTA:    document.getElementById('bk-content'),
        masterLang:   document.getElementById('bk-master-lang'),
        category:     document.getElementById('bk-category'),
        author:       document.getElementById('bk-author'),
        tags:         document.getElementById('bk-tags-picker'),
        heroPreview:  document.getElementById('bk-hero-preview'),
        statusBadge:  document.getElementById('bk-post-status-badge'),
        autosave:     document.getElementById('bk-autosave-status'),
        editorTitle:  document.getElementById('bk-editor-title'),
        metaTitleLen: document.getElementById('bk-meta-title-len'),
        metaDescLen:  document.getElementById('bk-meta-desc-len'),
    };

    let heroMediaId = null;
    let _mediaPickerCallback = null;

    // ── Init EasyMDE (čakaj, da se library naloži) ──────────────────────────
    function initEditor() {
        if (typeof EasyMDE === 'undefined') {
            setTimeout(initEditor, 100);
            return;
        }
        mde = new EasyMDE({
            element: els.contentTA,
            spellChecker: false,
            autofocus: false,
            status: ['lines','words'],
            minHeight: '500px',
            placeholder: '## Začni s podnaslovom\n\nNapiši uvodni odstavek...',
            toolbar: [
                'bold','italic','heading-2','heading-3','|',
                'quote','unordered-list','ordered-list','|',
                'link','image','code','horizontal-rule','|',
                {
                    name: 'cta',
                    action: function () {
                        const type = prompt('Tip CTA-ja: register / pricing / demo / subscribe', 'register');
                        if (!type) return;
                        const cm = mde.codemirror;
                        const pos = cm.getCursor();
                        cm.replaceRange('\n\n[cta:' + type.trim() + ']\n\n', pos);
                    },
                    className: 'fa fa-bullhorn',
                    title: 'Vstavi CTA shortcode',
                },
                {
                    name: 'media',
                    action: function () {
                        openMediaPicker((m) => {
                            const cm = mde.codemirror;
                            const pos = cm.getCursor();
                            const alt = (m.alt_translations && m.alt_translations[activeLang]) || (m.alt_translations && m.alt_translations.sl) || '';
                            cm.replaceRange('![' + alt + '](media:' + m.id + ')', pos);
                        });
                    },
                    className: 'fa fa-picture-o',
                    title: 'Vstavi sliko iz knjižnice',
                },
                '|','preview','side-by-side','fullscreen'
            ],
        });
        mde.codemirror.on('change', onContentChanged);
        loadPostIfNeeded();
    }
    initEditor();

    // ── Load (existing) ─────────────────────────────────────────────────────
    async function loadPostIfNeeded() {
        if (!postId) {
            // Nov članek — prikaži prazen form, ustvarjen bo ob prvem save-u
            updateLangTabsFromCache();
            return;
        }
        try {
            isLoading = true;
            const post = await API.get('/api/blog.php?action=get_post&id=' + postId);
            postCache = post;
            translationsCache = post.translations || {};
            heroMediaId = post.hero_media_id ? parseInt(post.hero_media_id, 10) : null;
            if (post.hero_media) renderHeroPreview(post.hero_media);

            // Sidebar
            els.masterLang.value = post.master_lang || 'sl';
            els.category.value   = post.category_id || '';
            els.author.value     = post.author_id || '';
            const tagIds = (post.tag_ids || []).map(String);
            els.tags.querySelectorAll('input[type=checkbox]').forEach(cb => {
                cb.checked = tagIds.includes(cb.value);
            });
            renderStatusBadge(post.status);
            updateViewLink();
            els.editorTitle.textContent = 'Urejam: ' + (translationsCache[post.master_lang]?.title || '#' + postId);

            // Aktiven jezik = URL param ali master
            activeLang = (_urlLang && BLOG_LANGS.includes(_urlLang)) ? _urlLang : (post.master_lang || 'sl');
            document.querySelectorAll('.bk-lang-tab').forEach(t => {
                t.classList.toggle('active', t.dataset.lang === activeLang);
            });
            document.getElementById('bk-active-lang').value = activeLang;
            populateFormForLang(activeLang);
            updateLangTabsFromCache();
        } catch (e) {
            alert('Napaka pri nalaganju: ' + e.message);
        } finally { isLoading = false; }
    }

    // ── Lang tabs ───────────────────────────────────────────────────────────
    document.getElementById('bk-lang-tabs').addEventListener('click', (e) => {
        const tab = e.target.closest('.bk-lang-tab');
        if (!tab) return;
        const lang = tab.dataset.lang;
        if (lang === activeLang) return;
        // Auto-save trenutni form
        captureCurrentFormToCache();
        // Preklopi
        document.querySelectorAll('.bk-lang-tab').forEach(t => t.classList.toggle('active', t === tab));
        activeLang = lang;
        document.getElementById('bk-active-lang').value = lang;
        populateFormForLang(lang);
        updateViewLink();
    });

    function captureCurrentFormToCache() {
        if (!activeLang) return;
        const tr = translationsCache[activeLang] || { lang_code: activeLang };
        tr.title            = els.title.value;
        tr.slug             = els.slug.value;
        tr.excerpt          = els.excerpt.value;
        tr.meta_title       = els.metaTitle.value;
        tr.meta_description = els.metaDesc.value;
        tr.content_md       = mde ? mde.value() : els.contentTA.value;
        translationsCache[activeLang] = tr;
    }

    function populateFormForLang(lang) {
        const tr = translationsCache[lang] || { lang_code: lang };
        els.title.value        = tr.title || '';
        els.slug.value         = tr.slug || '';
        els.excerpt.value      = tr.excerpt || '';
        els.metaTitle.value    = tr.meta_title || '';
        els.metaDesc.value     = tr.meta_description || '';
        els.readingTime.value  = tr.reading_time_minutes ? (tr.reading_time_minutes + ' min') : '';
        if (mde) mde.value(tr.content_md || '');
        else els.contentTA.value = tr.content_md || '';
        updateMetaCounters();
    }

    function updateLangTabsFromCache() {
        const TR_LABEL = { draft: '—', pending_review: '⏳', approved: '✓', rejected: '✗' };
        BLOG_LANGS.forEach(lc => {
            const span = document.querySelector('[data-status-for="' + lc + '"]');
            if (!span) return;
            const tr = translationsCache[lc];
            if (!tr) {
                span.textContent = '—';
                span.style.color = '#8a948e';
            } else {
                span.textContent = TR_LABEL[tr.status] || '?';
                span.style.color = tr.status === 'approved' ? '#2f7d52'
                                : tr.status === 'pending_review' ? '#c8542b'
                                : tr.status === 'rejected' ? '#b3392a'
                                : '#5a655e';
            }
        });
    }

    // ── Autosave on change ──────────────────────────────────────────────────
    function onContentChanged() {
        if (isLoading) return;
        if (saveTimer) clearTimeout(saveTimer);
        els.autosave.textContent = '~';
        saveTimer = setTimeout(saveTranslation, 1500);
    }
    [els.title, els.slug, els.excerpt, els.metaTitle, els.metaDesc].forEach(el => {
        if (!el) return;
        el.addEventListener('input', onContentChanged);
    });
    els.metaTitle.addEventListener('input', updateMetaCounters);
    els.metaDesc.addEventListener('input',  updateMetaCounters);
    function updateMetaCounters() {
        if (els.metaTitleLen) els.metaTitleLen.textContent = (els.metaTitle.value || '').length;
        if (els.metaDescLen)  els.metaDescLen.textContent  = (els.metaDesc.value  || '').length;
    }

    // Auto slug iz title (samo če slug prazen)
    els.title.addEventListener('blur', () => {
        if (!els.slug.value && els.title.value) {
            els.slug.value = slugify(els.title.value);
        }
    });

    function slugify(s) {
        const map = {'č':'c','ć':'c','š':'s','ž':'z','đ':'d','á':'a','à':'a','â':'a','ä':'a','é':'e','è':'e','í':'i','ó':'o','ö':'o','ú':'u','ü':'u','ñ':'n'};
        return String(s).toLowerCase().replace(/[čćšžđáàâäéèíóöúüñ]/g, c => map[c] || c)
            .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').substring(0, 80);
    }

    // ── Save translation ────────────────────────────────────────────────────
    async function saveTranslation() {
        if (isLoading) return;
        if (!els.title.value.trim()) { els.autosave.textContent = '⚠'; return; }

        // Ustvari post če še ne obstaja
        if (!postId) {
            try {
                const body = {
                    master_lang: els.masterLang.value || activeLang,
                    title: els.title.value.trim(),
                    slug:  els.slug.value.trim(),
                    excerpt: els.excerpt.value,
                    content_md: mde ? mde.value() : els.contentTA.value,
                    meta_title: els.metaTitle.value,
                    meta_description: els.metaDesc.value,
                    category_id: els.category.value || null,
                    author_id: els.author.value || null,
                    hero_media_id: heroMediaId,
                };
                const res = await API.post('/api/blog.php?action=create_post', body);
                postId = res.id;
                window.BLOG_POST_ID = postId;
                document.getElementById('bk-post-id').value = postId;
                history.replaceState(null, '', BASE + '/pages/blog_editor.php?id=' + postId);
                els.autosave.textContent = '✓ shranjeno';
                els.editorTitle.textContent = 'Urejam: ' + body.title;
                // Po create-u, naloži cache
                await loadPostIfNeeded();
                return;
            } catch (e) {
                els.autosave.textContent = '⚠ ' + e.message;
                return;
            }
        }

        // Posodobi obstoječi post
        try {
            els.autosave.textContent = '…';
            const body = {
                post_id: postId,
                lang_code: activeLang,
                title:    els.title.value.trim(),
                slug:     els.slug.value.trim() || slugify(els.title.value),
                excerpt:  els.excerpt.value,
                meta_title: els.metaTitle.value,
                meta_description: els.metaDesc.value,
                content_md: mde ? mde.value() : els.contentTA.value,
            };
            const res = await API.post('/api/blog.php?action=save_translation', body);
            // Posodobi local cache
            captureCurrentFormToCache();
            translationsCache[activeLang].id = res.translation_id;
            translationsCache[activeLang].slug = res.slug;
            translationsCache[activeLang].reading_time_minutes = res.reading_time_minutes;
            translationsCache[activeLang].word_count = res.word_count;
            translationsCache[activeLang].status = translationsCache[activeLang].status || 'draft';
            els.slug.value = res.slug;
            els.readingTime.value = res.reading_time_minutes + ' min · ' + res.word_count + ' besed';
            els.autosave.textContent = '✓ shranjeno · ' + new Date().toLocaleTimeString('sl-SI', { hour: '2-digit', minute: '2-digit' });
            updateLangTabsFromCache();
            updateViewLink();
        } catch (e) {
            els.autosave.textContent = '⚠ ' + e.message;
        }
    }
    document.getElementById('bk-save-btn').addEventListener('click', saveTranslation);

    // Approve / reject translation
    document.getElementById('bk-approve-translation').addEventListener('click', async () => {
        const tr = translationsCache[activeLang];
        if (!tr || !tr.id) { alert('Najprej shrani prevod.'); return; }
        await API.post('/api/blog.php?action=change_translation_status', { translation_id: tr.id, status: 'approved' });
        tr.status = 'approved';
        updateLangTabsFromCache();
        els.autosave.textContent = '✓ odobreno';
    });
    document.getElementById('bk-reject-translation').addEventListener('click', async () => {
        const tr = translationsCache[activeLang];
        if (!tr || !tr.id) { alert('Najprej shrani prevod.'); return; }
        await API.post('/api/blog.php?action=change_translation_status', { translation_id: tr.id, status: 'rejected' });
        tr.status = 'rejected';
        updateLangTabsFromCache();
    });

    // ── Save meta (sidebar) ─────────────────────────────────────────────────
    document.getElementById('bk-save-meta').addEventListener('click', async () => {
        if (!postId) { alert('Najprej shrani članek.'); return; }
        const tagIds = Array.from(els.tags.querySelectorAll('input[type=checkbox]:checked')).map(cb => parseInt(cb.value, 10));
        try {
            await API.post('/api/blog.php?action=save_post_meta', {
                post_id: postId,
                category_id: els.category.value || null,
                author_id:   els.author.value || null,
                hero_media_id: heroMediaId,
                tag_ids: tagIds,
            });
            els.autosave.textContent = '✓ meta shranjena';
        } catch (e) { alert(e.message); }
    });

    // ── Status actions ──────────────────────────────────────────────────────
    document.getElementById('bk-publish-btn').addEventListener('click', async () => {
        if (!postId) { alert('Najprej shrani.'); return; }
        if (!confirm('Objavi takoj? Prevodi v drugih jezikih, ki niso "approved", ne bodo prikazani.')) return;
        try {
            // Shrani meta (hero, kategorija, avtor, tagi) pred objavo
            const tagIds = Array.from(els.tags.querySelectorAll('input[type=checkbox]:checked')).map(cb => parseInt(cb.value, 10));
            await API.post('/api/blog.php?action=save_post_meta', {
                post_id: postId,
                category_id: els.category.value || null,
                author_id:   els.author.value || null,
                hero_media_id: heroMediaId,
                tag_ids: tagIds,
            });
            await API.post('/api/blog.php?action=change_post_status', {
                post_id: postId, status: 'published',
            });
            if (postCache) postCache.status = 'published';
            renderStatusBadge('published');
            updateViewLink();
            els.autosave.textContent = '✓ objavljeno';
        } catch (e) { alert(e.message); }
    });

    document.getElementById('bk-pending-btn').addEventListener('click', async () => {
        if (!postId) { alert('Najprej shrani.'); return; }
        await API.post('/api/blog.php?action=change_post_status', { post_id: postId, status: 'pending_review' });
        renderStatusBadge('pending_review');
    });

    document.getElementById('bk-archive-btn').addEventListener('click', async () => {
        if (!postId) return;
        if (!confirm('Arhiviraj članek?')) return;
        await API.post('/api/blog.php?action=change_post_status', { post_id: postId, status: 'archived' });
        renderStatusBadge('archived');
    });

    document.getElementById('bk-schedule-btn').addEventListener('click', () => {
        if (!postId) { alert('Najprej shrani.'); return; }
        document.getElementById('bk-schedule-modal').hidden = false;
    });
    document.querySelectorAll('[data-close-modal]').forEach(b => b.addEventListener('click', () => {
        b.closest('.bk-modal').hidden = true;
    }));
    document.getElementById('bk-confirm-schedule').addEventListener('click', async () => {
        const dt = document.getElementById('bk-schedule-datetime').value;
        if (!dt) { alert('Izberi datum.'); return; }
        await API.post('/api/blog.php?action=change_post_status', {
            post_id: postId, status: 'scheduled', scheduled_at: dt.replace('T', ' ') + ':00',
        });
        renderStatusBadge('scheduled');
        document.getElementById('bk-schedule-modal').hidden = true;
    });

    function updateViewLink() {
        const link = document.getElementById('bk-view-post-link');
        const preview = document.getElementById('bk-preview-post-link');
        const tr = translationsCache[activeLang];
        const status = postCache && postCache.status;
        const slug = tr && tr.slug;

        if (link) {
            if (slug && status === 'published' && tr.status === 'approved') {
                link.href = BASE + (activeLang === 'sl' ? '/booked/' : '/' + activeLang + '/booked/') + slug;
                link.style.display = '';
            } else {
                link.style.display = 'none';
            }
        }
        if (preview) {
            const langLabel = preview.querySelector('[data-preview-lang]');
            if (langLabel) langLabel.textContent = activeLang.toUpperCase();
            if (slug) {
                preview.href = BASE + (activeLang === 'sl' ? '/booked/' : '/' + activeLang + '/booked/') + slug + '?preview=1';
                preview.style.display = '';
            } else {
                preview.style.display = 'none';
            }
        }
    }

    function renderStatusBadge(status) {
        const map = {
            draft:          { label: 'Osnutek',     bg: '#F4F1EA', fg: '#5A655E' },
            pending_review: { label: 'V pregledu',  bg: '#FAE8DF', fg: '#B34822' },
            scheduled:      { label: 'Zakazano',    bg: '#E6ECE7', fg: '#2C3A30' },
            published:      { label: 'Objavljeno',  bg: '#D1FADF', fg: '#2F7D52' },
            archived:       { label: 'Arhivirano',  bg: '#F4F1EA', fg: '#8A948E' },
        }[status] || { label: status, bg: '#eee', fg: '#000' };
        els.statusBadge.textContent = map.label;
        els.statusBadge.style.background = map.bg;
        els.statusBadge.style.color = map.fg;
        els.statusBadge.dataset.status = status;
    }

    // ── Hero image picker ───────────────────────────────────────────────────
    document.getElementById('bk-pick-hero').addEventListener('click', () => {
        openMediaPicker((m) => {
            heroMediaId = m.id;
            renderHeroPreview(m);
            els.autosave.textContent = '~ (klikni Shrani meta)';
        });
    });
    document.getElementById('bk-clear-hero').addEventListener('click', () => {
        heroMediaId = null;
        renderHeroPreview(null);
    });

    function renderHeroPreview(media) {
        if (media) {
            els.heroPreview.innerHTML = '<img src="' + escapeAttr(media.preview_url) + '" alt="" style="width:100%;height:auto;display:block;border-radius:10px">';
        } else if (!heroMediaId) {
            els.heroPreview.innerHTML = '<div class="bk-hero-empty">Brez slike</div>';
        }
    }

    // ── Media picker modal ──────────────────────────────────────────────────
    function _renderPickerGrid(grid, rows, onPick) {
        const modal = document.getElementById('bk-media-modal');
        if (!rows || rows.length === 0) {
            grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--color-muted)">Knjižnica je prazna. Naloži sliko zgoraj.</div>';
            return;
        }
        grid.innerHTML = rows.map(m => `
            <div class="bk-media-item" data-id="${m.id}" style="background:${m.dominant_color || '#eee'};cursor:pointer">
                <img src="${escapeAttr(m.preview_url)}" alt="" loading="lazy">
            </div>`).join('');
        grid.querySelectorAll('.bk-media-item').forEach(el => {
            el.addEventListener('click', () => {
                const id = parseInt(el.dataset.id, 10);
                const media = rows.find(x => x.id == id);
                if (media) {
                    if (typeof onPick === 'function') onPick(media);
                    modal.hidden = true;
                }
            });
        });
    }

    async function openMediaPicker(onPick) {
        _mediaPickerCallback = onPick;
        const modal = document.getElementById('bk-media-modal');
        const grid  = document.getElementById('bk-modal-media-grid');
        modal.hidden = false;
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--color-muted)">Nalagam...</div>';
        try {
            const rows = await API.get('/api/blog_media.php?action=list');
            _renderPickerGrid(grid, rows, onPick);
        } catch (e) {
            grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--color-danger)">Napaka.</div>';
        }
    }

    const modalUpload    = document.getElementById('bk-modal-upload');
    const modalUploadBtn = document.getElementById('bk-modal-upload-btn');
    if (modalUploadBtn) modalUploadBtn.addEventListener('click', () => modalUpload.click());
    if (modalUpload) modalUpload.addEventListener('change', async () => {
        const file = modalUpload.files && modalUpload.files[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('file', file);
        modalUploadBtn.textContent = 'Nalagam...';
        try {
            const res = await fetch(BASE + '/api/blog_media.php?action=upload', { method: 'POST', body: fd, credentials: 'same-origin' });
            const json = await res.json();
            if (!json.success) throw new Error(json.error || 'Napaka.');
            const grid = document.getElementById('bk-modal-media-grid');
            const rows = await API.get('/api/blog_media.php?action=list');
            _renderPickerGrid(grid, rows, _mediaPickerCallback);
        } catch (e) {
            alert('Napaka: ' + e.message);
        } finally {
            modalUploadBtn.textContent = '+ Naloži novo sliko';
            modalUpload.value = '';
        }
    });

    // ── Inline tag kreacija ─────────────────────────────────────────────────
    const newTagInput = document.getElementById('bk-new-tag-input');
    const addTagBtn   = document.getElementById('bk-add-tag-btn');
    if (addTagBtn && newTagInput) {
        const doAddTag = async () => {
            const name = newTagInput.value.trim();
            if (!name) return;
            const slug = name.toLowerCase()
                .replace(/[čć]/g, 'c').replace(/[šś]/g, 's').replace(/[žź]/g, 'z').replace(/đ/g, 'd')
                .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
            try {
                const res = await API.post('/api/blog.php?action=save_tag', { id: 0, slug, names: { sl: name } });
                const tagId = res && res.id;
                if (!tagId) throw new Error('Ni ID-ja.');
                const picker = document.getElementById('bk-tags-picker');
                const lbl = document.createElement('label');
                lbl.className = 'bk-tag-chip';
                lbl.innerHTML = '<input type="checkbox" value="' + tagId + '" checked> ' + name.replace(/</g, '&lt;');
                picker.appendChild(lbl);
                newTagInput.value = '';
            } catch (e) { alert('Napaka: ' + e.message); }
        };
        addTagBtn.addEventListener('click', doAddTag);
        newTagInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); doAddTag(); } });
    }

    // ── Kopiraj vsebino iz drugega jezika ───────────────────────────────────
    const copyFromBtn  = document.getElementById('bk-copy-lang-btn');
    const copyFromSel  = document.getElementById('bk-copy-from-lang');
    if (copyFromBtn && copyFromSel) {
        copyFromBtn.addEventListener('click', () => {
            const fromLang = copyFromSel.value;
            if (fromLang === activeLang) { alert('Izberi drug jezik kot je aktiven.'); return; }
            captureCurrentFormToCache();
            const src = translationsCache[fromLang];
            if (!src || !src.title) { alert('Jezik ' + fromLang.toUpperCase() + ' nima shranjene vsebine.'); return; }
            if (!confirm('Prepiši vsa polja aktivnega jezika ' + activeLang.toUpperCase() + ' z vsebino iz ' + fromLang.toUpperCase() + '?')) return;
            const current = translationsCache[activeLang] || { lang_code: activeLang };
            translationsCache[activeLang] = {
                ...current,
                title:            src.title,
                excerpt:          src.excerpt,
                meta_title:       src.meta_title,
                meta_description: src.meta_description,
                content_md:       src.content_md,
            };
            populateFormForLang(activeLang);
            onContentChanged();
        });
    }

    function escapeAttr(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    // ── AI: Translate all ───────────────────────────────────────────────────
    const aiStatusEl = document.getElementById('bk-ai-status');
    function aiStatus(text, color) {
        if (!aiStatusEl) return;
        aiStatusEl.textContent = text || '';
        aiStatusEl.style.color = color || 'var(--color-muted)';
    }

    const aiTranslateBtn = document.getElementById('bk-ai-translate-all');
    if (aiTranslateBtn) {
        aiTranslateBtn.addEventListener('click', async () => {
            if (!postId) { alert('Najprej shrani članek.'); return; }
            const masterLang = (postCache && postCache.master_lang) || 'sl';
            const targets = BLOG_LANGS.filter(l => l !== masterLang);
            const overwrite = confirm('Prepišem že obstoječe prevode?\n\nOK = prepiši\nCancel = preskoči obstoječe (priporočeno za prvi prevod)');
            if (!confirm(`Začnem zaporeden prevod v ${targets.length} jezikov? Vsak ~10-15s, skupaj ~${targets.length * 12}s.`)) return;
            aiTranslateBtn.disabled = true;
            let ok = 0, err = 0, skip = 0;
            const errorDetails = [];
            try {
                for (let i = 0; i < targets.length; i++) {
                    const lang = targets[i];
                    aiStatus(`${i+1}/${targets.length} · Prevajam ${lang.toUpperCase()}...`, '');
                    try {
                        const res = await API.post('/api/blog.php?action=ai_translate_post', {
                            post_id: postId,
                            target_langs: [lang],
                            overwrite: overwrite,
                        });
                        ok   += (res.ok_count     || 0);
                        err  += (res.error_count  || 0);
                        skip += (res.skipped      || 0);
                        if (res.results && res.results.length) {
                            for (const r of res.results) {
                                if (r.status === 'error') {
                                    errorDetails.push(r.lang.toUpperCase() + ': ' + (r.error || 'neznana'));
                                }
                            }
                        }
                    } catch (e) {
                        err++;
                        errorDetails.push(lang.toUpperCase() + ': ' + (e.message || 'mrežna napaka'));
                    }
                }
                if (err > 0) {
                    aiStatus(`Končano: ${ok} ok, ${err} napak, ${skip} preskočeni.\n\nNapake:\n` + errorDetails.join('\n'), 'var(--color-danger, #B34822)');
                    if (aiStatusEl) {
                        aiStatusEl.style.whiteSpace = 'pre-line';
                        aiStatusEl.style.minHeight  = 'auto';
                    }
                    // ne reload-aj samodejno, da uporabnik vidi napake
                } else {
                    aiStatus(`Končano: ${ok} ok, ${skip} preskočeni. Osvežujem...`, 'var(--color-success, #2F7D52)');
                    setTimeout(() => location.reload(), 1500);
                }
            } finally {
                aiTranslateBtn.disabled = false;
            }
        });
    }

    // ── AI: Suggest tags ────────────────────────────────────────────────────
    const aiTagsBtn = document.getElementById('bk-ai-suggest-tags');
    if (aiTagsBtn) {
        aiTagsBtn.addEventListener('click', async () => {
            const title = els.title.value.trim();
            const md    = mde ? mde.value() : els.contentTA.value;
            if (!title || !md) { alert('Najprej vpiši naslov + vsebino.'); return; }
            aiTagsBtn.disabled = true;
            aiStatus('Generiram tage...', '');
            try {
                const res = await API.post('/api/blog.php?action=ai_suggest_tags', {
                    title, content_md: md, lang: activeLang,
                });
                if (!res.tags || !res.tags.length) {
                    aiStatus('AI ni vrnil tagov.', 'var(--color-danger, #B34822)');
                    return;
                }
                const accepted = prompt('AI predlaga tage (loči z vejicami, lahko popraviš):\n\n' + res.tags.join(', '), res.tags.join(', '));
                if (!accepted) { aiStatus('Preklicano.', ''); return; }
                const names = accepted.split(',').map(s => s.trim()).filter(Boolean);
                aiStatus('Dodajam tage...', '');
                let added = 0;
                for (const name of names) {
                    const slug = name.toLowerCase()
                        .replace(/[čć]/g, 'c').replace(/[šś]/g, 's').replace(/[žź]/g, 'z').replace(/đ/g, 'd')
                        .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
                    if (!slug) continue;
                    try {
                        const r = await API.post('/api/blog.php?action=save_tag', { id: 0, slug, names: { [activeLang]: name } });
                        const tagId = r && r.id;
                        if (!tagId) continue;
                        const picker = document.getElementById('bk-tags-picker');
                        if (picker.querySelector('input[value="' + tagId + '"]')) {
                            picker.querySelector('input[value="' + tagId + '"]').checked = true;
                        } else {
                            const lbl = document.createElement('label');
                            lbl.className = 'bk-tag-chip';
                            lbl.innerHTML = '<input type="checkbox" value="' + tagId + '" checked> ' + name.replace(/</g, '&lt;');
                            picker.appendChild(lbl);
                        }
                        added++;
                    } catch (_) {}
                }
                aiStatus(`Dodanih ${added} tagov. Klikni "Shrani meta" za zapis.`, 'var(--color-success, #2F7D52)');
            } catch (e) {
                aiStatus('Napaka: ' + (e.message || ''), 'var(--color-danger, #B34822)');
            } finally {
                aiTagsBtn.disabled = false;
            }
        });
    }

    // ── AI: Image picker modal (DALL-E + Unsplash) ─────────────────────────
    const aiImgBtn = document.getElementById('bk-ai-generate-image');
    const aipModal = document.getElementById('bk-ai-image-modal');
    if (aiImgBtn && aipModal) {
        const aipStatus = document.getElementById('bk-aip-status');
        const setAipStatus = (text, color) => {
            if (!aipStatus) return;
            aipStatus.textContent = text || '';
            aipStatus.style.color = color || '';
        };

        aiImgBtn.addEventListener('click', () => {
            setAipStatus('');
            aipModal.hidden = false;
        });

        // Tab switching
        aipModal.querySelectorAll('.bk-aip-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                aipModal.querySelectorAll('.bk-aip-tab').forEach(t => {
                    t.classList.remove('active');
                    t.style.borderBottomColor = 'transparent';
                    t.style.color = 'var(--color-muted)';
                    t.style.fontWeight = '400';
                });
                tab.classList.add('active');
                tab.style.borderBottomColor = 'var(--color-primary, #c8542b)';
                tab.style.color = '';
                tab.style.fontWeight = '600';
                const target = tab.dataset.aipTab;
                aipModal.querySelectorAll('.bk-aip-pane').forEach(p => {
                    p.hidden = p.dataset.aipPane !== target;
                });
            });
        });

        // DALL-E generate
        const dalleGo = document.getElementById('bk-aip-dalle-go');
        dalleGo.addEventListener('click', async () => {
            const promptText = document.getElementById('bk-aip-dalle-prompt').value.trim();
            const altText    = document.getElementById('bk-aip-dalle-alt').value.trim();
            const captText   = document.getElementById('bk-aip-dalle-caption').value.trim();
            if (!promptText) { setAipStatus('Vpiši DALL-E prompt.', 'var(--color-danger, #B34822)'); return; }
            dalleGo.disabled = true;
            setAipStatus('Generiram sliko z DALL-E (10-20s)...', '');
            try {
                const res = await API.post('/api/blog.php?action=ai_generate_image_single', {
                    provider: 'dalle',
                    prompt: promptText,
                    alt:     altText,
                    caption: captText,
                    lang:    activeLang,
                });
                setAipStatus('✓ Slika dodana v knjižnico (ID #' + res.id + '). Izbereš jo lahko v media pickerju.', 'var(--color-success, #2F7D52)');
                aiStatus('Slika #' + res.id + ' shranjena (DALL-E).', 'var(--color-success, #2F7D52)');
            } catch (e) {
                setAipStatus('Napaka: ' + (e.message || ''), 'var(--color-danger, #B34822)');
            } finally {
                dalleGo.disabled = false;
            }
        });

        // Unsplash search
        let _unsplashSelected = null;
        const unsplashSearchBtn = document.getElementById('bk-aip-unsplash-search');
        const unsplashQ         = document.getElementById('bk-aip-unsplash-q');
        const unsplashResults   = document.getElementById('bk-aip-unsplash-results');
        const unsplashFinalize  = document.getElementById('bk-aip-unsplash-finalize');

        const runUnsplashSearch = async () => {
            const q = unsplashQ.value.trim();
            if (!q) return;
            unsplashSearchBtn.disabled = true;
            unsplashResults.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:24px;color:var(--color-muted)">Iščem...</div>';
            unsplashFinalize.hidden = true;
            _unsplashSelected = null;
            try {
                const res = await API.post('/api/blog.php?action=unsplash_search', {
                    query: q, per_page: 9, orientation: 'landscape',
                });
                if (!res.photos || !res.photos.length) {
                    unsplashResults.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:24px;color:var(--color-muted)">Brez zadetkov za "' + escapeAttr(q) + '".</div>';
                    return;
                }
                unsplashResults.innerHTML = res.photos.map(p => `
                    <div class="bk-media-item" data-photo-id="${escapeAttr(p.id)}" data-photographer="${escapeAttr(p.photographer.name)}" data-description="${escapeAttr(p.description || '')}" style="background:${escapeAttr(p.color || '#eee')};cursor:pointer;border-radius:8px;overflow:hidden;aspect-ratio:16/10">
                        <img src="${escapeAttr(p.small)}" alt="${escapeAttr(p.description || '')}" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block">
                        <div style="padding:6px 8px;background:rgba(0,0,0,.5);color:#fff;font-size:11px;position:relative;margin-top:-28px;width:100%">${escapeAttr(p.photographer.name)}</div>
                    </div>`).join('');
                unsplashResults.querySelectorAll('.bk-media-item').forEach(el => {
                    el.addEventListener('click', () => {
                        _unsplashSelected = {
                            id:           el.dataset.photoId,
                            photographer: el.dataset.photographer,
                            description:  el.dataset.description,
                        };
                        document.getElementById('bk-aip-unsplash-photographer').textContent = el.dataset.photographer;
                        document.getElementById('bk-aip-unsplash-alt').value     = el.dataset.description || '';
                        document.getElementById('bk-aip-unsplash-caption').value = '';
                        unsplashFinalize.hidden = false;
                        unsplashFinalize.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                });
            } catch (e) {
                unsplashResults.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:24px;color:var(--color-danger, #B34822)">Napaka: ' + escapeAttr(e.message || '') + '</div>';
            } finally {
                unsplashSearchBtn.disabled = false;
            }
        };
        unsplashSearchBtn.addEventListener('click', runUnsplashSearch);
        unsplashQ.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); runUnsplashSearch(); } });

        // Unsplash apply
        const unsplashGo = document.getElementById('bk-aip-unsplash-go');
        unsplashGo.addEventListener('click', async () => {
            if (!_unsplashSelected) return;
            const altText  = document.getElementById('bk-aip-unsplash-alt').value.trim();
            const captText = document.getElementById('bk-aip-unsplash-caption').value.trim();
            unsplashGo.disabled = true;
            setAipStatus('Prenašam fotografijo z Unsplash (~5s)...', '');
            try {
                const res = await API.post('/api/blog.php?action=ai_generate_image_single', {
                    provider:    'unsplash',
                    unsplash_id: _unsplashSelected.id,
                    alt:         altText,
                    caption:     captText,
                    lang:        activeLang,
                });
                setAipStatus('✓ Slika #' + res.id + ' (' + _unsplashSelected.photographer + ') dodana v knjižnico.', 'var(--color-success, #2F7D52)');
                aiStatus('Slika #' + res.id + ' shranjena (Unsplash).', 'var(--color-success, #2F7D52)');
            } catch (e) {
                setAipStatus('Napaka: ' + (e.message || ''), 'var(--color-danger, #B34822)');
            } finally {
                unsplashGo.disabled = false;
            }
        });
    }
})();
