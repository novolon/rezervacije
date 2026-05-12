/**
 * Rezble Help Chat – floating AI asistent.
 *
 * Predpogoji:
 *   window.APP_BASE  — BASE_PATH iz PHP (npr. "/rezervacije-saas").
 *   window.t()       — i18n helper (assets/js/i18n.js).
 *
 * Mount:
 *   <script src="{BASE}/assets/js/help_chat.js"></script>
 *   v body se samodejno pojavi floating "?" gumb spodaj desno.
 */
(function () {
  'use strict';

  const T = window.t || function (k) { return k; };
  const BASE = window.APP_BASE || '';
  const STORAGE_KEY = 'rz_help_chat_v1';

  // ── State ─────────────────────────────────────────────────────────────
  // Vztrajno (sessionStorage): open / conversationId / messages.
  // Tako chat preživi navigacijo med stranmi v istem tab-u.
  const state = {
    open:           false,
    conversationId: null,
    busy:           false,
    messages:       [], // [{role:'user'|'assistant', text:''}, ...]
  };

  function loadState() {
    try {
      const raw = sessionStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      const s = JSON.parse(raw);
      if (s && typeof s === 'object') {
        state.open           = !!s.open;
        state.conversationId = s.conversationId || null;
        state.messages       = Array.isArray(s.messages) ? s.messages : [];
      }
    } catch (_) { /* ignore */ }
  }
  function saveState() {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
        open:           state.open,
        conversationId: state.conversationId,
        messages:       state.messages,
      }));
    } catch (_) { /* ignore quota / private mode */ }
  }

  // ── Inject CSS (samostojno, da ne odvisi od rezble.css cache) ─────────
  const css = `
.rz-hc-panel{position:fixed;right:18px;bottom:18px;width:380px;max-width:calc(100vw - 36px);height:580px;max-height:calc(100vh - 36px);background:#fff;border:1px solid var(--line,#e5e7eb);border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden;z-index:9991}
.rz-hc-head{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid var(--line,#e5e7eb);background:linear-gradient(135deg,rgba(37,99,235,.06),transparent)}
.rz-hc-head-l{display:flex;align-items:center;gap:8px}
.rz-hc-head-icon{width:30px;height:30px;border-radius:8px;background:var(--accent,#2563eb);color:#fff;display:flex;align-items:center;justify-content:center}
.rz-hc-head-title{font:600 14px/1.2 var(--font),system-ui;color:var(--ink,#111827)}
.rz-hc-head-sub{font:11px/1.2 var(--font),system-ui;color:var(--ink-mute,#6b7280)}
.rz-hc-head-r{display:flex;gap:4px}
.rz-hc-iconbtn{background:none;border:none;cursor:pointer;color:var(--ink-mute,#6b7280);padding:4px;border-radius:6px;display:flex}
.rz-hc-iconbtn:hover{background:var(--bg-sunken,#f9fafb);color:var(--ink,#111827)}
.rz-hc-msgs{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:var(--bg-soft,#fafaf7)}
.rz-hc-msg{max-width:88%;padding:9px 12px;border-radius:11px;font:13.5px/1.5 var(--font),system-ui;word-wrap:break-word;white-space:pre-wrap}
.rz-hc-msg.user{align-self:flex-end;background:var(--accent,#2563eb);color:#fff;border-bottom-right-radius:4px}
.rz-hc-msg.assistant{align-self:flex-start;background:#fff;border:1px solid var(--line,#e5e7eb);color:var(--ink,#111827);border-bottom-left-radius:4px}
.rz-hc-msg.assistant a{color:var(--accent,#2563eb);font-weight:500}
.rz-hc-msg.assistant ul,.rz-hc-msg.assistant ol{margin:6px 0 6px 18px;padding:0}
.rz-hc-msg.assistant li{margin:2px 0}
.rz-hc-msg.assistant code{background:var(--bg-sunken,#f3f4f6);border-radius:4px;padding:1px 4px;font:12px/1.5 ui-monospace,monospace}
.rz-hc-msg.assistant strong{font-weight:600}
.rz-hc-msg.welcome{align-self:stretch;background:transparent;border:1px dashed var(--line,#e5e7eb);color:var(--ink-mute,#6b7280);font-size:12.5px;padding:10px}
.rz-hc-typing{align-self:flex-start;display:flex;gap:4px;padding:9px 12px}
.rz-hc-typing span{width:6px;height:6px;border-radius:999px;background:var(--ink-mute,#9ca3af);animation:rz-hc-bounce 1.2s infinite}
.rz-hc-typing span:nth-child(2){animation-delay:.15s}
.rz-hc-typing span:nth-child(3){animation-delay:.3s}
@keyframes rz-hc-bounce{0%,80%,100%{transform:translateY(0);opacity:.4}40%{transform:translateY(-4px);opacity:1}}
.rz-hc-form{display:flex;gap:6px;padding:10px 12px;border-top:1px solid var(--line,#e5e7eb);background:#fff}
.rz-hc-input{flex:1;border:1px solid var(--line,#e5e7eb);border-radius:8px;padding:8px 11px;font:13.5px var(--font),system-ui;color:var(--ink,#111827);outline:none;resize:none;max-height:120px}
.rz-hc-input:focus{border-color:var(--accent,#2563eb);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.rz-hc-send{background:var(--accent,#2563eb);color:#fff;border:none;border-radius:8px;width:36px;height:36px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;align-self:flex-end}
.rz-hc-send:hover{background:#1d4ed8}
.rz-hc-send:disabled{opacity:.5;cursor:default}
.rz-hc-foot{font:10.5px/1.4 var(--font),system-ui;color:var(--ink-mute,#9ca3af);text-align:center;padding:6px 8px}
@media(max-width:480px){.rz-hc-panel{right:8px;left:8px;width:auto;bottom:8px;height:calc(100vh - 16px)}}
`;
  const styleEl = document.createElement('style');
  styleEl.textContent = css;
  document.head.appendChild(styleEl);

  // ── DOM elementi ──────────────────────────────────────────────────────
  function renderPanel() {
    if (document.getElementById('rz-hc-panel')) return;
    const panel = document.createElement('div');
    panel.className = 'rz-hc-panel';
    panel.id = 'rz-hc-panel';
    panel.innerHTML = `
      <div class="rz-hc-head">
        <div class="rz-hc-head-l">
          <div class="rz-hc-head-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 2 L4 7 L4 17 L12 22 L20 17 L20 7 Z"/><path d="M12 22 V12"/><path d="M4 7 L12 12 L20 7"/>
            </svg>
          </div>
          <div>
            <div class="rz-hc-head-title">${escHtml(T('help_chat.title'))}</div>
            <div class="rz-hc-head-sub">${escHtml(T('help_chat.subtitle'))}</div>
          </div>
        </div>
        <div class="rz-hc-head-r">
          <button class="rz-hc-iconbtn" id="rz-hc-new" title="${escHtml(T('help_chat.new_chat'))}" aria-label="${escHtml(T('help_chat.new_chat'))}">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
          </button>
          <button class="rz-hc-iconbtn" id="rz-hc-close" title="${escHtml(T('help_chat.close'))}" aria-label="${escHtml(T('help_chat.close'))}">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M18 6 6 18M6 6l12 12"/></svg>
          </button>
        </div>
      </div>
      <div class="rz-hc-msgs" id="rz-hc-msgs">
        <div class="rz-hc-msg welcome">${escHtml(T('help_chat.welcome'))}</div>
      </div>
      <form class="rz-hc-form" id="rz-hc-form">
        <textarea class="rz-hc-input" id="rz-hc-input" rows="1" placeholder="${escHtml(T('help_chat.input_placeholder'))}" maxlength="400"></textarea>
        <button type="submit" class="rz-hc-send" id="rz-hc-send" aria-label="${escHtml(T('help_chat.send'))}">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        </button>
      </form>
      <div class="rz-hc-foot">${escHtml(T('help_chat.footer'))}</div>
    `;
    document.body.appendChild(panel);

    document.getElementById('rz-hc-close').addEventListener('click', togglePanel);
    document.getElementById('rz-hc-new').addEventListener('click', newChat);
    const input = document.getElementById('rz-hc-input');
    input.addEventListener('input', autoResize);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
    document.getElementById('rz-hc-form').addEventListener('submit', function (e) {
      e.preventDefault(); send();
    });
  }

  function openPanel() {
    state.open = true;
    let panel = document.getElementById('rz-hc-panel');
    if (!panel) { renderPanel(); panel = document.getElementById('rz-hc-panel'); }
    panel.style.display = 'flex';
    saveState();
    setTimeout(() => document.getElementById('rz-hc-input')?.focus(), 50);
  }
  function closePanel() {
    state.open = false;
    const panel = document.getElementById('rz-hc-panel');
    if (panel) panel.style.display = 'none';
    saveState();
  }
  function togglePanel() {
    state.open ? closePanel() : openPanel();
  }

  function newChat() {
    state.conversationId = null;
    state.messages = [];
    const msgs = document.getElementById('rz-hc-msgs');
    if (msgs) msgs.innerHTML = `<div class="rz-hc-msg welcome">${escHtml(T('help_chat.welcome'))}</div>`;
    saveState();
    document.getElementById('rz-hc-input')?.focus();
  }

  // Pobriše vse renderirane msg/typing nodes (welcome ostane prazen seznam).
  function renderMessages() {
    const msgs = document.getElementById('rz-hc-msgs');
    if (!msgs) return;
    if (!state.messages.length) {
      msgs.innerHTML = `<div class="rz-hc-msg welcome">${escHtml(T('help_chat.welcome'))}</div>`;
      return;
    }
    msgs.innerHTML = '';
    state.messages.forEach(function (m) {
      const el = document.createElement('div');
      el.className = 'rz-hc-msg ' + m.role;
      if (m.role === 'assistant') el.innerHTML = miniMarkdown(m.text);
      else                        el.textContent = m.text;
      msgs.appendChild(el);
    });
    msgs.scrollTop = msgs.scrollHeight;
  }

  function autoResize() {
    const ta = document.getElementById('rz-hc-input');
    if (!ta) return;
    ta.style.height = 'auto';
    ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
  }

  // Zaklene input/send za N sekund (rate limit). Po koncu samodejno odklene.
  let _lockTimer = null;
  let _lockUntil = 0;
  function lockInputFor(seconds) {
    const ta  = document.getElementById('rz-hc-input');
    const btn = document.getElementById('rz-hc-send');
    if (!ta || !btn) return;
    _lockUntil = Date.now() + seconds * 1000;
    const orig = ta.placeholder;
    function tick() {
      const left = Math.max(0, Math.ceil((_lockUntil - Date.now()) / 1000));
      if (left <= 0) {
        ta.disabled = false; btn.disabled = false;
        ta.placeholder = orig;
        clearInterval(_lockTimer); _lockTimer = null;
        return;
      }
      let fmt;
      if (left > 3600)   fmt = Math.ceil(left / 3600) + 'h';
      else if (left > 60) fmt = Math.ceil(left / 60) + 'min';
      else                fmt = left + 's';
      ta.placeholder = (T('help_chat.locked_until') || 'Limit dosežen, počakajte') + ' ' + fmt;
    }
    ta.disabled = true; btn.disabled = true;
    tick();
    if (_lockTimer) clearInterval(_lockTimer);
    _lockTimer = setInterval(tick, 1000);
  }

  // ── Sending message ───────────────────────────────────────────────────
  async function send() {
    if (state.busy) return;
    const ta = document.getElementById('rz-hc-input');
    const text = (ta.value || '').trim();
    if (!text) return;

    appendMessage('user', text);
    ta.value = '';
    autoResize();
    showTyping();
    state.busy = true;
    document.getElementById('rz-hc-send').disabled = true;

    try {
      const r = await fetch(`${BASE}/api/help_chat.php?action=send`, {
        method:  'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ message: text, conversation_id: state.conversationId || 0 }),
      });
      const j = await r.json();
      hideTyping();
      if (!j.success) {
        if (j.data && j.data.conversation_id) state.conversationId = j.data.conversation_id;
        // Rate limit (429): pokaži samo direct sporočilo + disable inputa za retry_in_s
        if (r.status === 429) {
          appendMessage('assistant', j.error || T('help_chat.error'));
          const retryIn = (j.data && j.data.retry_in_s) || 0;
          if (retryIn > 0 && retryIn < 7200) lockInputFor(retryIn);
          return;
        }
        appendMessage('assistant', T('help_chat.error') + (j.error ? ': ' + j.error : ''));
        return;
      }
      state.conversationId = j.data.conversation_id;
      appendMessage('assistant', j.data.reply);
    } catch (e) {
      hideTyping();
      appendMessage('assistant', T('help_chat.error') + ': ' + (e.message || ''));
    } finally {
      state.busy = false;
      document.getElementById('rz-hc-send').disabled = false;
      document.getElementById('rz-hc-input')?.focus();
    }
  }

  function showTyping() {
    const msgs = document.getElementById('rz-hc-msgs');
    if (!msgs) return;
    const t = document.createElement('div');
    t.className = 'rz-hc-typing';
    t.id = 'rz-hc-typing';
    t.innerHTML = '<span></span><span></span><span></span>';
    msgs.appendChild(t);
    msgs.scrollTop = msgs.scrollHeight;
  }
  function hideTyping() {
    document.getElementById('rz-hc-typing')?.remove();
  }

  function appendMessage(role, text) {
    state.messages.push({ role: role, text: text });
    saveState();

    const msgs = document.getElementById('rz-hc-msgs');
    if (!msgs) return;
    // Pobriši welcome placeholder ob prvem realnem sporočilu.
    const welcome = msgs.querySelector('.rz-hc-msg.welcome');
    if (welcome && state.messages.length === 1) welcome.remove();

    const m = document.createElement('div');
    m.className = 'rz-hc-msg ' + role;
    if (role === 'assistant') {
      m.innerHTML = miniMarkdown(text);
    } else {
      m.textContent = text;
    }
    msgs.appendChild(m);
    msgs.scrollTop = msgs.scrollHeight;
  }

  // ── Mini markdown renderer (varno: escape-amo HTML, podpiramo basic syntax) ─
  function miniMarkdown(s) {
    s = String(s);
    // Escape HTML first
    s = s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    // Code fences ```...```
    s = s.replace(/```([\s\S]*?)```/g, function (_m, c) {
      return '<pre style="background:#0f172a;color:#f1f5f9;padding:10px;border-radius:8px;overflow-x:auto;font:12px ui-monospace,monospace"><code>' + c + '</code></pre>';
    });
    // Inline code
    s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
    // Bold
    s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    // Italic
    s = s.replace(/(?<!\*)\*([^*\n]+)\*(?!\*)/g, '<em>$1</em>');
    // Links: [text](url) — only allow http(s) and relative paths starting with / or #
    s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function (_m, txt, url) {
      const safe = /^(https?:|\/|#)/i.test(url) ? url : '#';
      const target = /^https?:/i.test(url) ? ' target="_blank" rel="noopener"' : '';
      return '<a href="' + safe + '"' + target + '>' + txt + '</a>';
    });
    // Lists: convert blocks of "- " or "* "
    s = s.replace(/(^|\n)((?:[ \t]*[-*][ \t]+.+(?:\n|$))+)/g, function (_m, prefix, block) {
      const items = block.trim().split(/\n/).map(function (line) {
        return '<li>' + line.replace(/^[ \t]*[-*][ \t]+/, '') + '</li>';
      }).join('');
      return prefix + '<ul>' + items + '</ul>';
    });
    // Numbered lists: "1. xxx"
    s = s.replace(/(^|\n)((?:[ \t]*\d+\.[ \t]+.+(?:\n|$))+)/g, function (_m, prefix, block) {
      const items = block.trim().split(/\n/).map(function (line) {
        return '<li>' + line.replace(/^[ \t]*\d+\.[ \t]+/, '') + '</li>';
      }).join('');
      return prefix + '<ol>' + items + '</ol>';
    });
    // Newlines → <br> (le tam, kjer ni v HTML bloku)
    s = s.replace(/\n{2,}/g, '<br><br>');
    s = s.replace(/(?<!>)\n/g, '<br>');
    return s;
  }

  function escHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ── Init: obnovi state iz sessionStorage in po potrebi prikaži panel ──
  loadState();
  if (state.open) {
    renderPanel();
    const panel = document.getElementById('rz-hc-panel');
    if (panel) panel.style.display = 'flex';
    renderMessages();
  }

  // ── Public API (kliče sidebar gumb "Pomoč") ───────────────────────────
  window.RezbleHelpChat = {
    open:   openPanel,
    close:  closePanel,
    toggle: togglePanel,
  };
})();
