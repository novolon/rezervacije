/* ============================================================================
 * Booked – javni blog: enhancement-only JS.
 * Server-side render je primarna pot, JS samo doda interakcije.
 * Stran deluje brez JS.
 * ============================================================================ */
(function () {
    'use strict';

    // ── Reading progress bar ────────────────────────────────────────────────
    const progressBar = document.getElementById('progressBar');
    if (progressBar) {
        const target = document.querySelector('.prose') || document.body;
        const update = () => {
            const top = window.scrollY;
            const total = Math.max(target.offsetHeight + target.offsetTop - window.innerHeight, 1);
            const pct = Math.min(100, Math.max(0, (top / total) * 100));
            progressBar.style.width = pct + '%';
        };
        window.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        update();
    }

    // ── TOC scroll-spy ───────────────────────────────────────────────────────
    // Stran ima lahko 2 TOC-a (desktop sidebar + mobile <details>). Active class
    // mora dobiti VSAK link z istim hash-om — sicer se aktivira samo en (po DOM
    // vrstnem redu zadnji), ki je morda skrit zaradi responsive CSS-a.
    const allTocLinks = document.querySelectorAll('.toc a[href^="#"]');
    if (allTocLinks.length > 0 && 'IntersectionObserver' in window) {
        const ids = new Set();
        allTocLinks.forEach(a => {
            const id = (a.getAttribute('href') || '').replace(/^#/, '');
            if (id) ids.add(id);
        });
        const headings = Array.from(ids)
            .map(id => document.getElementById(id))
            .filter(Boolean);

        const setActive = (id) => {
            document.querySelectorAll('.toc a.active').forEach(a => a.classList.remove('active'));
            if (!id) return;
            try {
                document.querySelectorAll('.toc a[href="#' + CSS.escape(id) + '"]')
                    .forEach(a => a.classList.add('active'));
            } catch (_) {
                // Fallback: brez CSS.escape (starejši browserji)
                document.querySelectorAll('.toc a').forEach(a => {
                    if ((a.getAttribute('href') || '') === '#' + id) a.classList.add('active');
                });
            }
        };

        if (headings.length > 0) {
            // Sledimo, kateri heading je trenutno "v fokusu" — vzamemo zadnjega,
            // ki je intersected po vrstnem redu DOM-a.
            const visible = new Set();
            const obs = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) visible.add(entry.target.id);
                    else                       visible.delete(entry.target.id);
                });
                if (visible.size === 0) return;
                // Najdi prvi heading v DOM, ki je trenutno viden
                const orderedIds = headings.map(h => h.id);
                for (const id of orderedIds) {
                    if (visible.has(id)) { setActive(id); return; }
                }
            }, { rootMargin: '-15% 0px -70% 0px', threshold: 0 });
            headings.forEach(h => obs.observe(h));
        }
    }

    // ── Share buttons + copy link ────────────────────────────────────────────
    document.querySelectorAll('[data-bk-copy]').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const wrap = btn.closest('[data-bk-share]');
            const url = wrap ? (wrap.getAttribute('data-url') || location.href) : location.href;
            try {
                await navigator.clipboard.writeText(url);
                btn.classList.add('copied');
                const orig = btn.getAttribute('aria-label');
                btn.setAttribute('aria-label', btn.getAttribute('data-copied') || (window.t ? window.t('booked.share.copied') : 'Copied!'));
                setTimeout(() => {
                    btn.classList.remove('copied');
                    if (orig) btn.setAttribute('aria-label', orig);
                }, 1800);
            } catch (err) {
                window.prompt('Copy link:', url);
            }
        });
    });

    // ── Scroll-to-top ────────────────────────────────────────────────────────
    const totop = document.querySelector('.scrollTop');
    if (totop) {
        const sw = () => {
            if (window.scrollY > 600) totop.classList.add('active');
            else totop.classList.remove('active');
        };
        window.addEventListener('scroll', sw, { passive: true });
        totop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    }

    // ── Search (debounced AJAX) ──────────────────────────────────────────────
    const searchInput = document.querySelector('[data-bk-search-input]');
    const searchResults = document.querySelector('[data-bk-search-results]');
    if (searchInput && searchResults) {
        const apiBase = searchInput.getAttribute('data-api-base') || '';
        const lang = searchInput.getAttribute('data-lang') || 'sl';
        let timer = null;
        let activeReq = null;

        const closeResults = () => { searchResults.style.display = 'none'; };
        const openResults  = () => { searchResults.style.display = 'block'; };

        const renderResults = (items, query) => {
            if (!items || items.length === 0) {
                const noRes = window.t ? window.t('booked.search.no_results', { q: query }) : ('No results for "' + query + '"');
                searchResults.innerHTML = '<div class="bk-search-result"><div class="bk-search-result__excerpt" style="color:var(--text-2);font-size:13px">' + escapeHtml(noRes) + '</div></div>';
            } else {
                const rows = items.map(it => {
                    const meta = (it.category || '') + (it.reading_time_minutes ? ' · ' + it.reading_time_minutes + ' min' : '');
                    return ''
                        + '<a class="bk-search-result" href="' + escapeAttr(it.url) + '">'
                        + '<span style="width:36px;height:36px;border-radius:8px;background:var(--cream);flex-shrink:0"></span>'
                        + '<div style="flex:1;min-width:0">'
                        + '<div class="bk-search-result__title">' + escapeHtml(it.title) + '</div>'
                        + (meta ? '<div class="bk-search-result__excerpt">' + escapeHtml(meta) + '</div>' : '')
                        + '</div>'
                        + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--muted);flex-shrink:0"><path d="M5 12h14M13 5l7 7-7 7"/></svg>'
                        + '</a>';
                }).join('');
                searchResults.innerHTML = '<div style="padding:10px 16px;font-size:11px;text-transform:uppercase;letter-spacing:0.12em;color:var(--muted);font-weight:600;border-bottom:1px solid var(--border);background:var(--surface-2)">Predlogi</div>' + rows;
            }
            openResults();
        };

        searchInput.addEventListener('input', () => {
            const q = searchInput.value.trim();
            if (timer) clearTimeout(timer);
            if (q.length < 2) { closeResults(); return; }
            timer = setTimeout(async () => {
                try {
                    if (activeReq) activeReq.abort();
                    activeReq = new AbortController();
                    const res = await fetch(apiBase + '/api/blog.php?action=search&lang=' + encodeURIComponent(lang) + '&q=' + encodeURIComponent(q), { signal: activeReq.signal });
                    const json = await res.json();
                    if (json && json.success) {
                        renderResults(json.data || [], q);
                    } else {
                        renderResults([], q);
                    }
                } catch (e) {
                    if (e.name !== 'AbortError') console.warn(e);
                }
            }, 250);
        });

        document.addEventListener('click', (e) => {
            if (!searchResults.contains(e.target) && e.target !== searchInput) closeResults();
        });
        searchInput.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeResults(); });
    }

    // ── Newsletter subscribe (AJAX) ──────────────────────────────────────────
    document.querySelectorAll('[data-bk-subscribe]').forEach(form => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const okEl = form.querySelector('.bk-success');
            const errEl = form.querySelector('.bk-error');
            const btn = form.querySelector('button[type="submit"]');
            const input = form.querySelector('input[type="email"]');
            if (okEl) okEl.hidden = true;
            if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
            if (btn) btn.disabled = true;
            try {
                const fd = new FormData(form);
                const res = await fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' });
                const json = await res.json();
                if (json && json.success) {
                    if (okEl) okEl.hidden = false;
                    if (input) input.value = '';
                } else {
                    if (errEl) {
                        errEl.hidden = false;
                        errEl.textContent = (json && json.error) || (window.t ? window.t('booked.subscribe.error') : 'Submit failed.');
                    }
                }
            } catch (err) {
                if (errEl) { errEl.hidden = false; errEl.textContent = (window.t ? window.t('booked.subscribe.error') : 'Submit failed.'); }
            } finally {
                if (btn) btn.disabled = false;
            }
        });
    });

    // ── Helpers ──────────────────────────────────────────────────────────────
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
    function escapeAttr(s) { return escapeHtml(s); }
})();
