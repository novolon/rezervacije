/* ============================================================================
 * Booked admin – CRUD UI logika za superadmin_blog.php
 * ============================================================================ */
(function () {
    'use strict';

    const BASE = (window.APP_STATE && APP_STATE.base) || '';

    // ── Tab switching (URL-aware) ───────────────────────────────────────────
    const tabs   = document.querySelectorAll('.admin-tab');
    const panels = document.querySelectorAll('.admin-panel');
    const showTab = (name) => {
        tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === name));
        panels.forEach(p => p.classList.toggle('active', p.id === 'panel-' + name));
        // Posodobi URL brez reload-a
        const url = new URL(location.href);
        url.searchParams.set('tab', name);
        history.replaceState(null, '', url);
        // Naloži panel-vsebino
        loaders[name] && loaders[name]();
    };
    tabs.forEach(t => t.addEventListener('click', () => showTab(t.dataset.tab)));

    // Stats
    const loadStats = async () => {
        try {
            const data = await API.get('/api/blog.php?action=stats');
            document.querySelectorAll('[data-stat]').forEach(el => {
                const key = el.getAttribute('data-stat');
                if (data && key in data) el.textContent = data[key];
            });
        } catch (e) { console.warn(e); }
    };
    loadStats();

    // ── POSTS panel ─────────────────────────────────────────────────────────
    const postsBody = document.getElementById('bk-posts-tbody');
    const filterStatus = document.getElementById('bk-filter-status');
    const filterSearch = document.getElementById('bk-filter-search');

    const STATUS_LABELS = {
        draft:          { label: 'Osnutek',     bg: '#F4F1EA', fg: '#5A655E' },
        pending_review: { label: 'V pregledu',  bg: '#FAE8DF', fg: '#B34822' },
        scheduled:      { label: 'Zakazano',    bg: '#E6ECE7', fg: '#2C3A30' },
        published:      { label: 'Objavljeno',  bg: '#D1FADF', fg: '#2F7D52' },
        archived:       { label: 'Arhivirano',  bg: '#F4F1EA', fg: '#8A948E' },
    };
    const TR_STATUS = {
        draft:          { label: '—',  cls: 'tr-draft'    },
        pending_review: { label: '⏳', cls: 'tr-pending' },
        approved:       { label: '✓',  cls: 'tr-approved' },
        rejected:       { label: '✗',  cls: 'tr-rejected' },
    };

    const renderTranslationMatrix = (matrix, postId) => {
        return BLOG_LANGS.map(lc => {
            const st = matrix[lc] || null;
            const cell = st ? TR_STATUS[st] : { label: '—', cls: 'tr-none' };
            const url = BASE + '/pages/blog_editor.php?id=' + postId + '&lang=' + lc;
            return '<a href="' + url + '" class="bk-tr-pill ' + cell.cls + '" title="Uredi ' + lc.toUpperCase() + ': ' + (st || 'ni') + '" style="text-decoration:none">'
                + lc.toUpperCase() + ' ' + cell.label + '</a>';
        }).join('');
    };

    const loadPosts = async () => {
        postsBody.innerHTML = '<tr><td colspan="6" class="table-empty">Nalagam...</td></tr>';
        try {
            const params = new URLSearchParams();
            params.set('action', 'list_posts');
            if (filterStatus.value) params.set('status', filterStatus.value);
            if (filterSearch.value.trim()) params.set('search', filterSearch.value.trim());
            const posts = await API.get('/api/blog.php?' + params.toString());
            if (!posts || posts.length === 0) {
                postsBody.innerHTML = '<tr><td colspan="6" class="table-empty">Ni člankov.</td></tr>';
                return;
            }
            postsBody.innerHTML = posts.map(p => {
                const status = STATUS_LABELS[p.status] || { label: p.status, bg: '#eee', fg: '#000' };
                const editUrl = BASE + '/pages/blog_editor.php?id=' + p.id;
                const date = p.published_at ? new Date(p.published_at).toLocaleDateString('sl-SI') : '—';
                const matrix = p.translation_matrix || {};
                return ''
                    + '<tr>'
                    + '<td><a href="' + editUrl + '" style="color:var(--color-text);font-weight:600">' + escapeHtml(p.master_title || '(brez naslova)') + '</a><div style="font-size:12px;color:var(--color-muted);margin-top:2px">' + (p.author_name || '') + (p.ai_generated ? ' · ✨ AI' : '') + '</div></td>'
                    + '<td>' + escapeHtml(p.category_name || '—') + '</td>'
                    + '<td><span class="bk-status-pill" style="background:' + status.bg + ';color:' + status.fg + '">' + status.label + '</span></td>'
                    + '<td><div class="bk-tr-matrix">' + renderTranslationMatrix(matrix, p.id) + '</div></td>'
                    + '<td>' + date + '</td>'
                    + '<td><a href="' + editUrl + '" class="btn-icon" title="Uredi">✎</a> '
                        + (p.status === 'published' && p.master_slug ? '<a href="' + BASE + (p.master_lang === 'sl' ? '/booked/' : '/' + p.master_lang + '/booked/') + escapeAttr(p.master_slug) + '" class="btn-icon" title="Poglej objavo" target="_blank" rel="noopener">↗</a> ' : '')
                        + '<button type="button" class="btn-icon danger" data-action="delete-post" data-id="' + p.id + '" title="Briši">🗑</button></td>'
                    + '</tr>';
            }).join('');
        } catch (e) {
            postsBody.innerHTML = '<tr><td colspan="6" class="table-empty">Napaka: ' + escapeHtml(e.message || '') + '</td></tr>';
        }
    };
    if (filterStatus) filterStatus.addEventListener('change', loadPosts);
    if (filterSearch) {
        let to = null;
        filterSearch.addEventListener('input', () => { clearTimeout(to); to = setTimeout(loadPosts, 300); });
    }

    document.getElementById('bk-new-post').addEventListener('click', () => {
        location.href = BASE + '/pages/blog_editor.php';
    });
    const aiBtn = document.getElementById('bk-ai-generate');
    if (aiBtn) aiBtn.addEventListener('click', () => alert('AI generacija je na voljo v Fazi 3 (po dodatku ANTHROPIC_API_KEY).'));

    postsBody.addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-action="delete-post"]');
        if (!btn) return;
        if (!confirm('Izbrisati članek? Tega ni mogoče razveljaviti.')) return;
        try {
            await API.post('/api/blog.php?action=delete_post', { post_id: parseInt(btn.dataset.id, 10) });
            loadPosts();
            loadStats();
        } catch (err) { alert('Napaka: ' + err.message); }
    });

    // ── TOPICS panel ────────────────────────────────────────────────────────
    const topicsBody = document.getElementById('bk-topics-tbody');
    const loadTopics = async () => {
        topicsBody.innerHTML = '<tr><td colspan="6" class="table-empty">Nalagam...</td></tr>';
        try {
            const rows = await API.get('/api/blog.php?action=list_topics');
            if (!rows || rows.length === 0) {
                topicsBody.innerHTML = '<tr><td colspan="6" class="table-empty">Vrsta je prazna.</td></tr>';
                return;
            }
            topicsBody.innerHTML = rows.map(r => {
                const isGenerated = r.status === 'generated' && r.gen_post_id;
                const generateBtn = isGenerated
                    ? '<a href="' + BASE + '/pages/blog_editor.php?id=' + r.gen_post_id + '" class="btn btn-outline btn-sm" style="font-size:12px;padding:3px 8px">Odpri →</a>'
                    : '<button type="button" class="btn btn-primary btn-sm" data-action="generate-topic" data-id="' + r.id + '" style="font-size:12px;padding:3px 8px">✨ Generiraj</button>';
                return ''
                + '<tr>'
                + '<td><div style="font-weight:600">' + escapeHtml(r.topic) + '</div>' + (r.brief ? '<div style="font-size:12px;color:var(--color-muted);margin-top:2px">' + escapeHtml(r.brief.substring(0, 100)) + '</div>' : '') + '</td>'
                + '<td>' + escapeHtml(r.target_keyword || '—') + '</td>'
                + '<td>' + (r.desired_lang || 'sl').toUpperCase() + '</td>'
                + '<td>' + (r.scheduled_for || '—') + '</td>'
                + '<td><span class="bk-status-pill">' + r.status + '</span>' + (r.gen_post_id ? ' <a href="' + BASE + '/pages/blog_editor.php?id=' + r.gen_post_id + '" style="font-size:12px">→ ' + escapeHtml(r.gen_title || ('#' + r.gen_post_id)) + '</a>' : '') + '</td>'
                + '<td style="white-space:nowrap">'
                + generateBtn + ' '
                + '<button type="button" class="btn-icon" data-action="edit-topic" data-id="' + r.id + '">✎</button> '
                + '<button type="button" class="btn-icon danger" data-action="delete-topic" data-id="' + r.id + '">🗑</button>'
                + '</td>'
                + '</tr>';
            }).join('');
        } catch (e) {
            topicsBody.innerHTML = '<tr><td colspan="6" class="table-empty">Napaka: ' + escapeHtml(e.message || '') + '</td></tr>';
        }
    };

    document.getElementById('bk-new-topic').addEventListener('click', () => openTopicModal({}));
    topicsBody.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('button[data-action="edit-topic"]');
        const delBtn  = e.target.closest('button[data-action="delete-topic"]');
        const genBtn  = e.target.closest('button[data-action="generate-topic"]');
        if (editBtn) {
            try {
                const all = await API.get('/api/blog.php?action=list_topics');
                const topic = (all || []).find(x => x.id == editBtn.dataset.id);
                if (topic) openTopicModal(topic);
            } catch (e) { alert(e.message); }
        }
        if (delBtn) {
            if (!confirm('Izbrisati temo?')) return;
            await API.post('/api/blog.php?action=delete_topic', { id: parseInt(delBtn.dataset.id, 10) });
            loadTopics();
        }
        if (genBtn) {
            await runGenerateFromTopic(parseInt(genBtn.dataset.id, 10), genBtn);
        }
    });

    async function runGenerateFromTopic(topicId, btn) {
        if (!confirm('Generiraj članek + slike za to temo?\n\nKoraka: 1) Claude napiše članek (~15s), 2) DALL-E ustvari hero + inline slike (vsaka ~15s).\nStrošek: ~$0.30 (DALL-E standard quality).')) return;
        const status = document.getElementById('bk-ai-topics-status');
        const original = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = 'Generiram...'; }

        const setStatus = (text, color) => {
            if (!status) return;
            status.textContent = text;
            status.style.color = color || '';
        };

        try {
            // 1) Tekst
            setStatus('1/N · Generiram članek (Claude)...');
            const res = await API.post('/api/blog.php?action=ai_generate', { topic_id: topicId });
            const postId = res.post_id;
            const editorUrl = res.editor_url;
            const lang = res.master_lang;
            const prompts = res.image_prompts || { hero: null, inline: [] };

            // 2) Hero
            const totalImgs = (prompts.hero ? 1 : 0) + (prompts.inline ? prompts.inline.length : 0);
            let imgN = 0;
            if (prompts.hero) {
                imgN++;
                setStatus(`2/${1 + totalImgs} · Generiram hero sliko (DALL-E)...`);
                try {
                    await API.post('/api/blog.php?action=ai_apply_post_image', {
                        post_id: postId, role: 'hero',
                        prompt: prompts.hero.prompt,
                        alt:    prompts.hero.alt,
                        caption: prompts.hero.caption || '',
                        lang,
                    });
                } catch (e) {
                    setStatus('Hero slika ni uspela (' + (e.message || '') + '). Lahko jo dodaš ročno v editorju.', 'var(--color-danger, #B34822)');
                }
            }

            // 3) Inline slike (zaporedno)
            for (const img of (prompts.inline || [])) {
                imgN++;
                setStatus(`${1 + imgN}/${1 + totalImgs} · Generiram inline sliko #${img.index} (DALL-E)...`);
                try {
                    await API.post('/api/blog.php?action=ai_apply_post_image', {
                        post_id: postId, role: 'inline', index: img.index,
                        prompt: img.prompt,
                        alt:    img.alt,
                        caption: img.caption || '',
                        lang,
                    });
                } catch (e) {
                    setStatus(`Inline #${img.index} ni uspela (` + (e.message || '') + '). Druge slike nadaljujem...', 'var(--color-danger, #B34822)');
                }
            }

            // 4) Done
            if (status) {
                status.innerHTML = '✓ Generirano: <strong>' + escapeHtml(res.title) + '</strong> — <a href="' + editorUrl + '">Odpri v editorju →</a>';
                status.style.color = 'var(--color-success, #2F7D52)';
            }
            loadTopics();
            loadStats();
        } catch (e) {
            setStatus('Napaka: ' + (e.message || ''), 'var(--color-danger, #B34822)');
        } finally {
            if (btn) { btn.disabled = false; btn.innerHTML = original; }
        }
    }

    // ── AI: Suggest topics (20 idej) ────────────────────────────────────────
    const aiSuggestBtn = document.getElementById('bk-ai-suggest-topics-btn');
    if (aiSuggestBtn) {
        aiSuggestBtn.addEventListener('click', async () => {
            const status = document.getElementById('bk-ai-topics-status');
            if (!confirm('Claude predlaga 20 idej za blog članke (na podlagi Rezble funkcionalnosti). Trajanje: ~15-30s. Strošek: ~$0.05.\n\nIdeje se vstavijo direktno v vrsto.')) return;
            aiSuggestBtn.disabled = true;
            aiSuggestBtn.textContent = 'Generiram ideje...';
            if (status) { status.textContent = 'Claude razmišlja...'; status.style.color = ''; }
            try {
                const res = await API.post('/api/blog.php?action=ai_suggest_topics', { count: 20, persist: true });
                if (status) {
                    status.textContent = '✓ Dodanih ' + (res.inserted_ids ? res.inserted_ids.length : res.count) + ' idej v vrsto.';
                    status.style.color = 'var(--color-success, #2F7D52)';
                }
                loadTopics();
                loadStats();
            } catch (e) {
                if (status) {
                    status.textContent = 'Napaka: ' + (e.message || '');
                    status.style.color = 'var(--color-danger, #B34822)';
                }
            } finally {
                aiSuggestBtn.disabled = false;
                aiSuggestBtn.innerHTML = '✨ Predlagaj 20 idej z AI';
            }
        });
    }

    function openTopicModal(t) {
        const html = `
            <h3 style="margin:0 0 16px">${t.id ? 'Uredi temo' : 'Nova tema'}</h3>
            <div class="admin-form">
                <label class="admin-field"><span>Tema *</span><input type="text" id="m-topic" value="${escapeAttr(t.topic || '')}" required></label>
                <label class="admin-field"><span>Brief</span><textarea id="m-brief" rows="3">${escapeHtml(t.brief || '')}</textarea></label>
                <div class="admin-field-row">
                    <label class="admin-field"><span>Ključna beseda</span><input type="text" id="m-keyword" value="${escapeAttr(t.target_keyword || '')}"></label>
                    <label class="admin-field"><span>Jezik</span><select id="m-lang">${BLOG_LANGS.map(lc => `<option value="${lc}" ${(t.desired_lang || 'sl') === lc ? 'selected' : ''}>${lc.toUpperCase()}</option>`).join('')}</select></label>
                </div>
                <div class="admin-field-row">
                    <label class="admin-field"><span>Št. besed</span><input type="number" id="m-words" value="${t.desired_word_count || 1200}" min="300" max="5000"></label>
                    <label class="admin-field"><span>Datum</span><input type="date" id="m-date" value="${t.scheduled_for || ''}"></label>
                </div>
            </div>
            <div style="margin-top:18px;display:flex;gap:8px;justify-content:flex-end">
                <button type="button" class="btn btn-outline" data-modal-close>Prekliči</button>
                <button type="button" class="btn btn-primary" id="m-save">Shrani</button>
            </div>`;
        const m = openModal(html);
        m.querySelector('#m-save').addEventListener('click', async () => {
            const body = {
                id: t.id || 0,
                topic: m.querySelector('#m-topic').value.trim(),
                brief: m.querySelector('#m-brief').value.trim(),
                target_keyword: m.querySelector('#m-keyword').value.trim(),
                desired_lang: m.querySelector('#m-lang').value,
                desired_word_count: parseInt(m.querySelector('#m-words').value, 10),
                scheduled_for: m.querySelector('#m-date').value || null,
            };
            if (!body.topic) { alert('Tema je obvezna.'); return; }
            try {
                await API.post('/api/blog.php?action=save_topic', body);
                closeModal();
                loadTopics();
                loadStats();
            } catch (e) { alert(e.message); }
        });
    }

    // ── CATEGORIES panel ────────────────────────────────────────────────────
    const catsBody = document.getElementById('bk-categories-tbody');
    const loadCategories = async () => {
        catsBody.innerHTML = '<tr><td colspan="5" class="table-empty">Nalagam...</td></tr>';
        try {
            const rows = await API.get('/api/blog.php?action=list_categories');
            if (!rows || rows.length === 0) {
                catsBody.innerHTML = '<tr><td colspan="5" class="table-empty">Brez kategorij.</td></tr>';
                return;
            }
            catsBody.innerHTML = rows.map(r => {
                const names = r.names || {};
                return '<tr>'
                    + '<td><code>' + escapeHtml(r.slug) + '</code></td>'
                    + '<td>' + escapeHtml(names.sl || '—') + '</td>'
                    + '<td>' + escapeHtml(names.en || '—') + '</td>'
                    + '<td>' + (parseInt(r.is_active, 10) ? 'Da' : 'Ne') + '</td>'
                    + '<td><button type="button" class="btn-icon" data-action="edit-cat" data-id="' + r.id + '">✎</button> '
                        + '<button type="button" class="btn-icon danger" data-action="delete-cat" data-id="' + r.id + '">🗑</button></td>'
                    + '</tr>';
            }).join('');
        } catch (e) {
            catsBody.innerHTML = '<tr><td colspan="5" class="table-empty">Napaka.</td></tr>';
        }
    };
    document.getElementById('bk-new-category').addEventListener('click', () => openCatModal({}));
    catsBody.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('button[data-action="edit-cat"]');
        const delBtn  = e.target.closest('button[data-action="delete-cat"]');
        if (editBtn) {
            const all = await API.get('/api/blog.php?action=list_categories');
            const cat = (all || []).find(x => x.id == editBtn.dataset.id);
            if (cat) openCatModal(cat);
        }
        if (delBtn) {
            if (!confirm('Izbrisati kategorijo?')) return;
            await API.post('/api/blog.php?action=delete_category', { id: parseInt(delBtn.dataset.id, 10) });
            loadCategories();
        }
    });

    function openCatModal(c) {
        const names = c.names || {};
        const descs = c.descriptions || {};
        const langInputs = BLOG_LANGS.map(lc => `
            <div class="admin-field-row">
                <label class="admin-field"><span>Ime (${lc.toUpperCase()})</span><input type="text" data-lang-name="${lc}" value="${escapeAttr(names[lc] || '')}"></label>
                <label class="admin-field"><span>Opis (${lc.toUpperCase()})</span><input type="text" data-lang-desc="${lc}" value="${escapeAttr(descs[lc] || '')}"></label>
            </div>`).join('');
        const html = `
            <h3 style="margin:0 0 16px">${c.id ? 'Uredi kategorijo' : 'Nova kategorija'}</h3>
            <div class="admin-form">
                <div class="admin-field-row">
                    <label class="admin-field"><span>Slug *</span><input type="text" id="c-slug" value="${escapeAttr(c.slug || '')}" required></label>
                    <label class="admin-field"><span>Vrstni red</span><input type="number" id="c-order" value="${c.display_order || 0}"></label>
                    <label class="admin-field"><span>Aktivna</span><select id="c-active"><option value="1" ${c.is_active != 0 ? 'selected' : ''}>Da</option><option value="0" ${c.is_active == 0 ? 'selected' : ''}>Ne</option></select></label>
                </div>
                ${langInputs}
            </div>
            <div style="margin-top:18px;display:flex;gap:8px;justify-content:flex-end">
                <button type="button" class="btn btn-outline" data-modal-close>Prekliči</button>
                <button type="button" class="btn btn-primary" id="c-save">Shrani</button>
            </div>`;
        const m = openModal(html);
        m.querySelector('#c-save').addEventListener('click', async () => {
            const namesObj = {}, descsObj = {};
            BLOG_LANGS.forEach(lc => {
                const n = m.querySelector(`[data-lang-name="${lc}"]`).value.trim();
                const d = m.querySelector(`[data-lang-desc="${lc}"]`).value.trim();
                if (n) namesObj[lc] = n;
                if (d) descsObj[lc] = d;
            });
            try {
                await API.post('/api/blog.php?action=save_category', {
                    id: c.id || 0,
                    slug: m.querySelector('#c-slug').value.trim(),
                    display_order: parseInt(m.querySelector('#c-order').value, 10) || 0,
                    is_active: m.querySelector('#c-active').value == 1 ? 1 : 0,
                    names: namesObj,
                    descriptions: descsObj,
                });
                closeModal();
                loadCategories();
            } catch (e) { alert(e.message); }
        });
    }

    // ── TAGS panel ──────────────────────────────────────────────────────────
    const tagsBody = document.getElementById('bk-tags-tbody');
    const loadTags = async () => {
        tagsBody.innerHTML = '<tr><td colspan="5" class="table-empty">Nalagam...</td></tr>';
        try {
            const rows = await API.get('/api/blog.php?action=list_tags');
            if (!rows || rows.length === 0) {
                tagsBody.innerHTML = '<tr><td colspan="5" class="table-empty">Brez tagov.</td></tr>';
                return;
            }
            tagsBody.innerHTML = rows.map(r => {
                const names = r.names || {};
                return '<tr>'
                    + '<td><code>' + escapeHtml(r.slug) + '</code></td>'
                    + '<td>' + escapeHtml(names.sl || '—') + '</td>'
                    + '<td>' + escapeHtml(names.en || '—') + '</td>'
                    + '<td>' + (r.post_count || 0) + '</td>'
                    + '<td><button type="button" class="btn-icon" data-action="edit-tag" data-id="' + r.id + '">✎</button> '
                        + '<button type="button" class="btn-icon danger" data-action="delete-tag" data-id="' + r.id + '">🗑</button></td>'
                    + '</tr>';
            }).join('');
        } catch (e) {
            tagsBody.innerHTML = '<tr><td colspan="5" class="table-empty">Napaka.</td></tr>';
        }
    };
    document.getElementById('bk-new-tag').addEventListener('click', () => openTagModal({}));
    tagsBody.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('button[data-action="edit-tag"]');
        const delBtn  = e.target.closest('button[data-action="delete-tag"]');
        if (editBtn) {
            const all = await API.get('/api/blog.php?action=list_tags');
            const t = (all || []).find(x => x.id == editBtn.dataset.id);
            if (t) openTagModal(t);
        }
        if (delBtn) {
            if (!confirm('Izbrisati tag?')) return;
            await API.post('/api/blog.php?action=delete_tag', { id: parseInt(delBtn.dataset.id, 10) });
            loadTags();
        }
    });

    function openTagModal(t) {
        const names = t.names || {};
        const html = `
            <h3 style="margin:0 0 16px">${t.id ? 'Uredi tag' : 'Nov tag'}</h3>
            <div class="admin-form">
                <label class="admin-field"><span>Slug *</span><input type="text" id="tg-slug" value="${escapeAttr(t.slug || '')}" required></label>
                ${BLOG_LANGS.map(lc => `<label class="admin-field"><span>Ime (${lc.toUpperCase()})</span><input type="text" data-lang-name="${lc}" value="${escapeAttr(names[lc] || '')}"></label>`).join('')}
            </div>
            <div style="margin-top:18px;display:flex;gap:8px;justify-content:flex-end">
                <button type="button" class="btn btn-outline" data-modal-close>Prekliči</button>
                <button type="button" class="btn btn-primary" id="tg-save">Shrani</button>
            </div>`;
        const m = openModal(html);
        m.querySelector('#tg-save').addEventListener('click', async () => {
            const namesObj = {};
            BLOG_LANGS.forEach(lc => {
                const v = m.querySelector(`[data-lang-name="${lc}"]`).value.trim();
                if (v) namesObj[lc] = v;
            });
            try {
                await API.post('/api/blog.php?action=save_tag', {
                    id: t.id || 0,
                    slug: m.querySelector('#tg-slug').value.trim(),
                    names: namesObj,
                });
                closeModal();
                loadTags();
            } catch (e) { alert(e.message); }
        });
    }

    // ── AUTHORS panel ───────────────────────────────────────────────────────
    const authBody = document.getElementById('bk-authors-tbody');
    const loadAuthors = async () => {
        authBody.innerHTML = '<tr><td colspan="5" class="table-empty">Nalagam...</td></tr>';
        try {
            const rows = await API.get('/api/blog.php?action=list_authors');
            if (!rows || rows.length === 0) {
                authBody.innerHTML = '<tr><td colspan="5" class="table-empty">Brez avtorjev.</td></tr>';
                return;
            }
            authBody.innerHTML = rows.map(r => {
                const initial = (r.name || '?').charAt(0).toUpperCase();
                const avatar = r.avatar_url
                    ? '<img src="' + escapeAttr(r.avatar_url) + '" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover">'
                    : '<div style="width:32px;height:32px;border-radius:50%;background:#2c3a30;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700">' + escapeHtml(initial) + '</div>';
                return '<tr>'
                    + '<td>' + avatar + '</td>'
                    + '<td><code>' + escapeHtml(r.slug) + '</code></td>'
                    + '<td>' + escapeHtml(r.name) + '</td>'
                    + '<td>' + (parseInt(r.is_active, 10) ? 'Da' : 'Ne') + '</td>'
                    + '<td><button type="button" class="btn-icon" data-action="edit-auth" data-id="' + r.id + '">✎</button> '
                        + '<button type="button" class="btn-icon danger" data-action="delete-auth" data-id="' + r.id + '">🗑</button></td>'
                    + '</tr>';
            }).join('');
        } catch (e) {
            authBody.innerHTML = '<tr><td colspan="5" class="table-empty">Napaka.</td></tr>';
        }
    };
    document.getElementById('bk-new-author').addEventListener('click', () => openAuthorModal({}));
    authBody.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('button[data-action="edit-auth"]');
        const delBtn  = e.target.closest('button[data-action="delete-auth"]');
        if (editBtn) {
            const all = await API.get('/api/blog.php?action=list_authors');
            const a = (all || []).find(x => x.id == editBtn.dataset.id);
            if (a) openAuthorModal(a);
        }
        if (delBtn) {
            if (!confirm('Izbrisati avtorja?')) return;
            await API.post('/api/blog.php?action=delete_author', { id: parseInt(delBtn.dataset.id, 10) });
            loadAuthors();
        }
    });

    function openAuthorModal(a) {
        const bio = a.bio_translations || {};
        const social = a.social_links || {};
        const html = `
            <h3 style="margin:0 0 16px">${a.id ? 'Uredi avtorja' : 'Nov avtor'}</h3>
            <div class="admin-form">
                <div class="admin-field-row">
                    <label class="admin-field"><span>Slug *</span><input type="text" id="au-slug" value="${escapeAttr(a.slug || '')}" required></label>
                    <label class="admin-field"><span>Ime *</span><input type="text" id="au-name" value="${escapeAttr(a.name || '')}" required></label>
                </div>
                <div class="admin-field-row">
                    <label class="admin-field"><span>Avatar URL</span><input type="text" id="au-avatar" value="${escapeAttr(a.avatar_url || '')}"></label>
                    <label class="admin-field"><span>Vrstni red</span><input type="number" id="au-order" value="${a.display_order || 0}"></label>
                    <label class="admin-field"><span>Aktiven</span><select id="au-active"><option value="1" ${a.is_active != 0 ? 'selected' : ''}>Da</option><option value="0" ${a.is_active == 0 ? 'selected' : ''}>Ne</option></select></label>
                </div>
                ${BLOG_LANGS.map(lc => `<label class="admin-field"><span>Bio (${lc.toUpperCase()})</span><textarea data-bio-lang="${lc}" rows="2">${escapeHtml(bio[lc] || '')}</textarea></label>`).join('')}
                <div class="admin-field-row">
                    <label class="admin-field"><span>Twitter/X</span><input type="text" data-social="twitter" value="${escapeAttr(social.twitter || '')}"></label>
                    <label class="admin-field"><span>LinkedIn</span><input type="text" data-social="linkedin" value="${escapeAttr(social.linkedin || '')}"></label>
                    <label class="admin-field"><span>Web</span><input type="text" data-social="website" value="${escapeAttr(social.website || '')}"></label>
                </div>
            </div>
            <div style="margin-top:18px;display:flex;gap:8px;justify-content:flex-end">
                <button type="button" class="btn btn-outline" data-modal-close>Prekliči</button>
                <button type="button" class="btn btn-primary" id="au-save">Shrani</button>
            </div>`;
        const m = openModal(html);
        m.querySelector('#au-save').addEventListener('click', async () => {
            const bioObj = {}, socialObj = {};
            BLOG_LANGS.forEach(lc => {
                const v = m.querySelector(`[data-bio-lang="${lc}"]`).value.trim();
                if (v) bioObj[lc] = v;
            });
            ['twitter','linkedin','website'].forEach(k => {
                const v = m.querySelector(`[data-social="${k}"]`).value.trim();
                if (v) socialObj[k] = v;
            });
            try {
                await API.post('/api/blog.php?action=save_author', {
                    id: a.id || 0,
                    slug: m.querySelector('#au-slug').value.trim(),
                    name: m.querySelector('#au-name').value.trim(),
                    avatar_url: m.querySelector('#au-avatar').value.trim() || null,
                    display_order: parseInt(m.querySelector('#au-order').value, 10) || 0,
                    is_active: m.querySelector('#au-active').value == 1 ? 1 : 0,
                    bio_translations: bioObj,
                    social_links: socialObj,
                });
                closeModal();
                loadAuthors();
            } catch (e) { alert(e.message); }
        });
    }

    // ── MEDIA panel ─────────────────────────────────────────────────────────
    const mediaGrid  = document.getElementById('bk-media-grid');
    const uploadInput = document.getElementById('bk-upload-input');
    const uploadBtn   = document.getElementById('bk-upload-btn');

    const loadMedia = async () => {
        mediaGrid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--color-muted)">Nalagam...</div>';
        try {
            const rows = await API.get('/api/blog_media.php?action=list');
            if (!rows || rows.length === 0) {
                mediaGrid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--color-muted)">Knjižnica je prazna. Naloži prvo sliko.</div>';
                return;
            }
            mediaGrid.innerHTML = rows.map(m => `
                <div class="bk-media-item" data-id="${m.id}" style="background:${m.dominant_color || '#eee'}">
                    <img src="${escapeAttr(m.preview_url)}" alt="" loading="lazy">
                    <div class="bk-media-item__meta">${m.width}×${m.height}</div>
                    <div class="bk-media-item__actions">
                        <button type="button" class="btn btn-sm" data-action="alt-media" data-id="${m.id}">Alt</button>
                        <button type="button" class="btn-icon danger" data-action="delete-media" data-id="${m.id}">🗑</button>
                    </div>
                </div>`).join('');
        } catch (e) {
            mediaGrid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--color-danger)">Napaka.</div>';
        }
    };

    uploadBtn.addEventListener('click', () => uploadInput.click());
    uploadInput.addEventListener('change', async () => {
        const file = uploadInput.files && uploadInput.files[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('file', file);
        const orig = uploadBtn.textContent;
        uploadBtn.textContent = 'Nalagam...';
        uploadBtn.disabled = true;
        try {
            const res = await fetch(BASE + '/api/blog_media.php?action=upload', { method: 'POST', body: fd, credentials: 'same-origin' });
            const json = await res.json();
            if (!json.success) throw new Error(json.error || 'Napaka.');
            loadMedia();
        } catch (e) { alert('Napaka: ' + e.message); }
        finally {
            uploadBtn.textContent = orig;
            uploadBtn.disabled = false;
            uploadInput.value = '';
        }
    });

    mediaGrid.addEventListener('click', async (e) => {
        const altBtn = e.target.closest('button[data-action="alt-media"]');
        const delBtn = e.target.closest('button[data-action="delete-media"]');
        if (altBtn) {
            const id = parseInt(altBtn.dataset.id, 10);
            const all = await API.get('/api/blog_media.php?action=list');
            const item = (all || []).find(x => x.id == id);
            if (item) openAltModal(item);
        }
        if (delBtn) {
            if (!confirm('Izbrisati sliko?')) return;
            await API.post('/api/blog_media.php?action=delete', { id: parseInt(delBtn.dataset.id, 10) });
            loadMedia();
        }
    });

    function openAltModal(m) {
        const alts = m.alt_translations || {};
        const caps = m.caption_translations || {};
        const html = `
            <h3 style="margin:0 0 16px">Alt + caption (${m.width}×${m.height})</h3>
            <img src="${escapeAttr(m.preview_url)}" alt="" style="max-width:100%;border-radius:10px;margin-bottom:16px">
            <div class="admin-form">
                ${BLOG_LANGS.map(lc => `
                    <div class="admin-field-row">
                        <label class="admin-field"><span>Alt (${lc.toUpperCase()})</span><input type="text" data-alt="${lc}" value="${escapeAttr(alts[lc] || '')}"></label>
                        <label class="admin-field"><span>Caption (${lc.toUpperCase()})</span><input type="text" data-cap="${lc}" value="${escapeAttr(caps[lc] || '')}"></label>
                    </div>`).join('')}
            </div>
            <div style="margin-top:18px;display:flex;gap:8px;justify-content:flex-end">
                <button type="button" class="btn btn-outline" data-modal-close>Prekliči</button>
                <button type="button" class="btn btn-primary" id="alt-save">Shrani</button>
            </div>`;
        const md = openModal(html);
        md.querySelector('#alt-save').addEventListener('click', async () => {
            const altsObj = {}, capsObj = {};
            BLOG_LANGS.forEach(lc => {
                const a = md.querySelector(`[data-alt="${lc}"]`).value.trim();
                const c = md.querySelector(`[data-cap="${lc}"]`).value.trim();
                if (a) altsObj[lc] = a;
                if (c) capsObj[lc] = c;
            });
            try {
                await API.post('/api/blog_media.php?action=save_alt', {
                    id: m.id, alt_translations: altsObj, caption_translations: capsObj,
                });
                closeModal();
                loadMedia();
            } catch (e) { alert(e.message); }
        });
    }

    // ── SUBSCRIBERS panel ───────────────────────────────────────────────────
    const subBody = document.getElementById('bk-subscribers-tbody');
    const loadSubs = async () => {
        subBody.innerHTML = '<tr><td colspan="6" class="table-empty">Nalagam...</td></tr>';
        try {
            const rows = await API.get('/api/blog.php?action=list_subscribers');
            if (!rows || rows.length === 0) {
                subBody.innerHTML = '<tr><td colspan="6" class="table-empty">Brez naročnikov.</td></tr>';
                return;
            }
            subBody.innerHTML = rows.map(r => '<tr>'
                + '<td>' + escapeHtml(r.email) + '</td>'
                + '<td>' + (r.lang_code || '—').toUpperCase() + '</td>'
                + '<td>' + escapeHtml(r.source || '—') + '</td>'
                + '<td>' + (r.confirmed_at ? '✓ ' + new Date(r.confirmed_at).toLocaleDateString('sl-SI') : '—') + '</td>'
                + '<td>' + (r.unsubscribed_at ? '✗ ' + new Date(r.unsubscribed_at).toLocaleDateString('sl-SI') : '—') + '</td>'
                + '<td>' + new Date(r.created_at).toLocaleDateString('sl-SI') + '</td>'
                + '</tr>').join('');
        } catch (e) {
            subBody.innerHTML = '<tr><td colspan="6" class="table-empty">Napaka.</td></tr>';
        }
    };
    document.getElementById('bk-export-subscribers').addEventListener('click', async () => {
        const rows = await API.get('/api/blog.php?action=list_subscribers');
        if (!rows) return;
        const csv = [
            'email,lang,source,confirmed_at,unsubscribed_at,created_at',
            ...rows.map(r => [r.email, r.lang_code, r.source || '', r.confirmed_at || '', r.unsubscribed_at || '', r.created_at]
                .map(v => '"' + String(v).replace(/"/g, '""') + '"').join(','))
        ].join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'booked-subscribers-' + new Date().toISOString().slice(0,10) + '.csv';
        a.click();
        URL.revokeObjectURL(a.href);
    });

    // ── Modal helpers (lokalni — modal.js v projektu obstaja, ampak ga ne kličemo direktno tu) ──
    function openModal(html) {
        let overlay = document.getElementById('bk-modal-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'bk-modal-overlay';
            overlay.className = 'bk-modal-overlay';
            document.body.appendChild(overlay);
        }
        overlay.innerHTML = '<div class="bk-modal-content">' + html + '</div>';
        overlay.style.display = 'flex';
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay || e.target.closest('[data-modal-close]')) closeModal();
        });
        return overlay.querySelector('.bk-modal-content');
    }
    function closeModal() {
        const o = document.getElementById('bk-modal-overlay');
        if (o) { o.style.display = 'none'; o.innerHTML = ''; }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
    function escapeAttr(s) { return escapeHtml(s); }

    // ── Loaders ────────────────────────────────────────────────────────────
    const loaders = {
        posts: loadPosts,
        topics: loadTopics,
        categories: loadCategories,
        tags: loadTags,
        authors: loadAuthors,
        media: loadMedia,
        subscribers: loadSubs,
    };

    // Initial load — current tab
    const active = document.querySelector('.admin-tab.active');
    if (active) loaders[active.dataset.tab] && loaders[active.dataset.tab]();
})();
