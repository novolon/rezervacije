/**
 * Rezervacije Embed Widget
 * Uporaba:
 *   <div id="rez-widget"></div>
 *   <script src=".../widget.js" data-token="TOKEN" data-container="#rez-widget"></script>
 *
 * Brez data-container: widget se vstavi tik pred <script> tag.
 */
(function () {
    'use strict';

    // ── Config ────────────────────────────────────────────────────
    const scriptEl = document.currentScript
        || document.querySelector('script[data-token][src*="widget.js"]');
    if (!scriptEl) return;

    const token = scriptEl.getAttribute('data-token');
    if (!token) { console.warn('[RezWidget] Manjka atribut data-token.'); return; }

    const apiUrl     = scriptEl.src.replace(/\/widget\.js(\?.*)?$/, '') + '/api/book.php';
    const privacyUrl = scriptEl.src.replace(/\/widget\.js(\?.*)?$/, '') + '/pages/privacy.php';

    const sel = scriptEl.getAttribute('data-container');
    let host;
    if (sel) {
        host = document.querySelector(sel);
        if (!host) { console.warn('[RezWidget] Container "' + sel + '" ni najden.'); return; }
    } else {
        host = document.createElement('div');
        scriptEl.parentNode.insertBefore(host, scriptEl);
    }

    // ── Shadow DOM ────────────────────────────────────────────────
    const shadow = host.attachShadow({ mode: 'open' });

    // ── CSS ───────────────────────────────────────────────────────
    const styleEl = document.createElement('style');
    styleEl.textContent = `
:host {
    display: block;
    --f:  #1B4332;
    --f2: rgba(27,67,50,.55);
    --f3: rgba(27,67,50,.35);
    --cr: #FAFAF5;
    --tr: #C4704B;
    --th: #A85D3B;
    --sg: #A3B18A;
    --sl: #DAD7CD;
    --wh: #fff;
    --br: 14px;
    font-family: system-ui,-apple-system,'Segoe UI',sans-serif;
    font-size: 14px;
    line-height: 1.5;
    color: var(--f);
    -webkit-font-smoothing: antialiased;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
button{font-family:inherit;cursor:pointer}
input,textarea{font-family:inherit}

.root{background:var(--cr);border-radius:var(--br);overflow:hidden;border:1px solid var(--sl);max-width:520px}

/* Header */
.hdr{background:var(--wh);border-bottom:1px solid var(--sl);padding:13px 18px;display:flex;align-items:center;gap:11px}
.logo{width:34px;height:34px;background:var(--f);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0}
.rname{font-weight:700;font-size:13px;color:var(--f)}
.rsub{font-size:11px;color:var(--f3);margin-top:1px}

/* Progress */
.prog{background:var(--wh);border-bottom:1px solid var(--sl);padding:11px 18px;display:flex;align-items:center}
.pi{display:flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:var(--f3)}
.pi .n{width:20px;height:20px;border-radius:50%;background:var(--sl);color:var(--f3);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;transition:all .2s;flex-shrink:0}
.pi.active{color:var(--f)}.pi.active .n{background:var(--f);color:#fff}
.pi.done .n{background:var(--sg);color:#fff}
.ps{flex:1;height:1px;background:var(--sl);margin:0 5px}

/* Body */
.body{padding:22px 18px}
.step{display:none}.step.active{display:block}

/* States */
.loading{padding:40px 18px;text-align:center;color:var(--f2);font-size:13px}
.errpanel{padding:40px 18px;text-align:center}
.errpanel .eico{font-size:38px;margin-bottom:12px}
.errpanel h3{font-size:16px;font-weight:700;margin-bottom:6px}
.errpanel p{font-size:13px;color:var(--f2)}

/* Titles */
.ttl{font-size:19px;font-weight:700;color:var(--f);margin-bottom:5px;display:flex;align-items:center;gap:8px}
.sub{font-size:13px;color:var(--f2);margin-bottom:18px}

/* Back */
.back{background:none;border:none;color:var(--f3);padding:0;line-height:0;transition:color .15s;flex-shrink:0}
.back:hover{color:var(--f)}

/* Guest buttons */
.gbtns{display:flex;flex-wrap:wrap;gap:9px;margin-bottom:18px}
.gbtn{width:50px;height:50px;border-radius:13px;border:2px solid var(--sl);background:var(--wh);color:var(--f);font-weight:700;font-size:16px;transition:all .15s}
.gbtn:hover{border-color:var(--f)}
.gbtn.sel{background:var(--f);color:#fff;border-color:var(--f)}
.gmore{padding:0 13px;height:50px;border-radius:13px;border:2px solid var(--sl);background:var(--wh);color:var(--f);font-weight:600;font-size:13px;transition:all .15s;white-space:nowrap}
.gmore:hover,.gmore.sel{border-color:var(--f)}
.gmore-wrap{margin-bottom:14px}
.gmore-wrap label{display:block;font-size:11px;font-weight:600;margin-bottom:6px;color:var(--f)}
.gmore-inp{width:110px;border:2px solid var(--sl);border-radius:11px;padding:9px 13px;font-size:16px;font-weight:600;color:var(--f);outline:none;transition:border-color .15s;background:var(--wh)}
.gmore-inp:focus{border-color:var(--f)}

/* Buttons */
.btn-p{width:100%;padding:13px;background:var(--tr);color:#fff;border:none;border-radius:13px;font-size:15px;font-weight:700;transition:background .15s;display:flex;align-items:center;justify-content:center;gap:8px}
.btn-p:hover:not(:disabled){background:var(--th)}
.btn-p:disabled{opacity:.4;cursor:not-allowed}
.btn-o{width:100%;padding:12px;border:2px solid var(--f);border-radius:13px;background:none;color:var(--f);font-size:14px;font-weight:700;transition:all .15s}
.btn-o:hover{background:var(--f);color:#fff}

/* Calendar */
.cal{background:var(--wh);border-radius:13px;border:1px solid var(--sl);overflow:hidden;margin-bottom:14px}
.cal-nav{display:flex;align-items:center;justify-content:space-between;padding:13px 15px;border-bottom:1px solid var(--sl)}
.cal-nb{background:none;border:none;color:var(--f3);padding:3px;line-height:0;transition:color .15s}
.cal-nb:hover:not(:disabled){color:var(--f)}
.cal-nb:disabled{opacity:.2;cursor:default}
.cal-ttl{font-weight:700;font-size:14px;color:var(--f)}
.cal-grid{padding:7px 11px 11px}
.cal-hdrs{display:grid;grid-template-columns:repeat(7,1fr);margin-bottom:3px}
.cal-dlbl{text-align:center;font-size:11px;font-weight:600;color:var(--f3);padding:4px 0}
.cal-days{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
.cal-day{aspect-ratio:1;display:flex;align-items:center;justify-content:center;border-radius:50%;font-size:13px;font-weight:500;border:none;background:none;color:var(--f);transition:all .15s}
.cal-day.av:hover{background:rgba(163,177,138,.2);cursor:pointer}
.cal-day.td{font-weight:700;color:var(--tr)}
.cal-day.td.sel{color:#fff}
.cal-day.sel{background:var(--f);color:#fff}
.cal-day.dis{color:var(--sl);cursor:default}
.cal-note{padding:7px 15px;font-size:11px;color:var(--f3);border-top:1px solid var(--sl)}

/* Slots */
.slots-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin-bottom:14px}
.slot{border:2px solid var(--sl);border-radius:11px;padding:11px 6px;background:var(--wh);color:var(--f);font-weight:600;font-size:14px;transition:all .15s}
.slot:hover{border-color:var(--f)}
.slot.sel{background:var(--f);color:#fff;border-color:var(--f)}
.slot:disabled{opacity:.35;cursor:not-allowed}
.slot.wl{border-color:#F59E0B;color:#92400E;background:#FFFBEB}
.slot.wl:hover{background:#FEF3C7;border-color:#D97706}
.slot.wl.sel{background:#F59E0B;color:#fff;border-color:#F59E0B}
.empty{text-align:center;padding:28px 0;color:var(--f2);font-size:13px}
.empty .ico{font-size:30px;margin-bottom:8px}

/* Form */
.field{margin-bottom:13px}
.field label{display:block;font-size:12px;font-weight:600;color:var(--f);margin-bottom:5px}
.req{color:var(--tr)}
.opt{color:var(--f3);font-weight:400}
.inp,.ta{width:100%;border:2px solid var(--sl);border-radius:11px;padding:10px 13px;font-size:14px;color:var(--f);outline:none;transition:border-color .15s;background:var(--wh)}
.inp:focus,.ta:focus{border-color:var(--f)}
.ta{resize:vertical;min-height:76px}
.ferr{background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;border-radius:11px;padding:10px 13px;font-size:13px;margin-bottom:13px;display:none}
.ferr.show{display:block}

/* GDPR */
.gdpr-row{display:flex;align-items:flex-start;gap:9px;margin-bottom:9px}
.gdpr-row label{display:flex;align-items:flex-start;gap:9px;cursor:pointer;font-size:12px;color:var(--f2);line-height:1.5;font-weight:400}
.gdpr-cb{width:15px;height:15px;accent-color:var(--f);cursor:pointer;flex-shrink:0;margin-top:2px}
.gdpr-lnk{color:var(--f);text-decoration:underline}
.gdpr-req{color:var(--tr)}

/* Confirm */
.conf{text-align:center;padding:12px 0}
.cico{font-size:50px;margin-bottom:14px}
.cttl{font-size:21px;font-weight:700;margin-bottom:7px}
.cmsg{font-size:13px;color:var(--f2);margin-bottom:22px;line-height:1.6}
.sum{background:var(--wh);border:1px solid var(--sl);border-radius:13px;padding:14px;margin-bottom:18px;text-align:left}
.sum-ttl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--f3);margin-bottom:9px}
.sum-row{display:flex;justify-content:space-between;font-size:13px;padding:5px 0;border-bottom:1px solid rgba(218,215,205,.4)}
.sum-row:last-child{border-bottom:none}
.sum-row span:first-child{color:var(--f2)}
.sum-row span:last-child{font-weight:600}

/* Spinner */
.spin{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:sp .7s linear infinite;flex-shrink:0}
@keyframes sp{to{transform:rotate(360deg)}}

/* Waitlist */
.cal-day.wl{opacity:.5;cursor:pointer}
.cal-day.wl:hover{opacity:.8;background:rgba(245,158,11,.15)}
.wl-panel{background:#FFFBEB;border:1px solid #FDE68A;border-radius:13px;padding:16px;margin-top:14px}
.wl-panel-ttl{font-size:13px;font-weight:700;color:#92400E;margin-bottom:4px}
.wl-panel-sub{font-size:12px;color:#B45309;margin-bottom:12px}
.wl-row2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.wl-inp{width:100%;border:1.5px solid #FDE68A;border-radius:10px;padding:9px 11px;font-size:13px;color:var(--f);outline:none;background:#fff;font-family:inherit;transition:border-color .15s}
.wl-inp:focus{border-color:#F59E0B}
.wl-lbl{display:block;font-size:11px;font-weight:600;color:#92400E;margin-bottom:4px}
.wl-gdpr{display:flex;align-items:flex-start;gap:8px;margin-bottom:10px}
.wl-gdpr label{font-size:11px;color:#78350F;line-height:1.5;cursor:pointer}
.wl-gdpr input{margin-top:2px;accent-color:#F59E0B;flex-shrink:0}
.wl-err{font-size:12px;color:#DC2626;background:#FEE2E2;border-radius:8px;padding:7px 10px;margin-bottom:8px;display:none}
.wl-err.show{display:block}
.wl-btn{width:100%;padding:11px;background:#F59E0B;color:#fff;border:none;border-radius:11px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s}
.wl-btn:hover:not(:disabled){background:#D97706}
.wl-btn:disabled{opacity:.5;cursor:not-allowed}
.wl-done{background:#F0FDF4;border:1px solid #BBF7D0;border-radius:13px;padding:14px;margin-top:14px;text-align:center}
.wl-done-ttl{font-size:14px;font-weight:700;color:#166534;margin-bottom:4px}
.wl-done-sub{font-size:12px;color:#15803D}
`;

    // ── HTML ──────────────────────────────────────────────────────
    const wrap = document.createElement('div');
    wrap.innerHTML = `
<div class="root">
  <div id="wloading" class="loading">Nalagam...</div>
  <div id="werr" class="errpanel" style="display:none">
    <div class="eico">😔</div>
    <h3>Rezervacije niso na voljo</h3>
    <p id="werrmsg">Spletna rezervacija za to restavracijo ni omogočena.</p>
  </div>
  <div id="wmain" style="display:none">
    <div class="hdr">
      <div class="logo" id="wlogo">R</div>
      <div>
        <div class="rname" id="wname">...</div>
        <div class="rsub">Spletna rezervacija</div>
      </div>
    </div>
    <div class="prog">
      <div class="pi active" id="wp1"><div class="n">1</div></div>
      <div class="ps"></div>
      <div class="pi" id="wp2"><div class="n">2</div></div>
      <div class="ps"></div>
      <div class="pi" id="wp3"><div class="n">3</div></div>
      <div class="ps"></div>
      <div class="pi" id="wp4"><div class="n">4</div></div>
    </div>
    <div class="body">

      <!-- Korak 1: Gostje -->
      <div class="step active" id="ws1">
        <div class="ttl">Koliko gostov?</div>
        <div class="sub">Izberite število gostov za rezervacijo.</div>
        <div class="gbtns" id="wgbtns"></div>
        <div class="gmore-wrap" id="wgmorewrap" style="display:none">
          <label id="wgmorelbl">Vnesite število gostov</label>
          <input class="gmore-inp" id="wgmoreinp" type="number" min="11" step="1">
        </div>
        <button class="btn-p" id="wbtn1" style="display:none" disabled>Naprej →</button>
      </div>

      <!-- Korak 2: Datum -->
      <div class="step" id="ws2">
        <div class="ttl">
          <button class="back" id="wb2"><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m13 18-6-6 6-6"/></svg></button>
          Izberite datum
        </div>
        <div class="cal">
          <div class="cal-nav">
            <button class="cal-nb" id="wcalprev"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="m13 18-6-6 6-6"/></svg></button>
            <div class="cal-ttl" id="wcalttl"></div>
            <button class="cal-nb" id="wcalnext"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></button>
          </div>
          <div class="cal-grid">
            <div class="cal-hdrs">
              <div class="cal-dlbl">Po</div><div class="cal-dlbl">To</div><div class="cal-dlbl">Sr</div>
              <div class="cal-dlbl">Če</div><div class="cal-dlbl">Pe</div><div class="cal-dlbl">So</div><div class="cal-dlbl">Ne</div>
            </div>
            <div class="cal-days" id="wcaldays"></div>
          </div>
          <div class="cal-note">Sivi dnevi niso na voljo za rezervacije.</div>
        </div>
      </div>

      <!-- Korak 3: Termin -->
      <div class="step" id="ws3">
        <div class="ttl">
          <button class="back" id="wb3"><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m13 18-6-6 6-6"/></svg></button>
          Izberite termin
        </div>
        <div class="sub" id="ws3sub"></div>
        <div class="empty" id="wsloading">Nalagam termine...</div>
        <div id="wsempty" style="display:none">
          <div class="empty"><div class="ico">😕</div><div>Za ta dan ni prostih terminov.</div></div>
          <div class="wl-panel" id="wwl-panel" style="display:none">
            <div class="wl-panel-ttl">Vpišite se na čakalno listo</div>
            <div class="wl-panel-sub">Ko se sprosti termin, vas bomo takoj obvestili po emailu.</div>
            <div class="wl-row2" style="margin-bottom:8px">
              <div><label class="wl-lbl">Ime <span style="color:#EF4444">*</span></label><input class="wl-inp" id="wwl-first" type="text" placeholder="Janez"></div>
              <div><label class="wl-lbl">Priimek <span style="color:#EF4444">*</span></label><input class="wl-inp" id="wwl-last" type="text" placeholder="Novak"></div>
            </div>
            <div style="margin-bottom:8px"><label class="wl-lbl">Email <span style="color:#EF4444">*</span></label><input class="wl-inp" id="wwl-email" type="email" placeholder="janez@email.com"></div>
            <div style="margin-bottom:8px"><label class="wl-lbl">Telefon <span style="color:#92400E;font-weight:400">(neobvezno)</span></label><input class="wl-inp" id="wwl-phone" type="tel" placeholder="041 123 456"></div>
            <div style="margin-bottom:10px"><label class="wl-lbl">Prednostni čas <span style="color:#92400E;font-weight:400">(neobvezno)</span></label><input class="wl-inp" id="wwl-time" type="time"></div>
            <div class="wl-gdpr"><input type="checkbox" id="wwl-gdpr"><label for="wwl-gdpr">Strinjam se z obdelavo osebnih podatkov za namen obveščanja o prostih terminih.</label></div>
            <div class="wl-err" id="wwl-err"></div>
            <button class="wl-btn" id="wwl-submit">Vpišem se na čakalno listo</button>
          </div>
          <div class="wl-done" id="wwl-done" style="display:none">
            <div class="wl-done-ttl">✓ Vpisani ste na čakalno listo!</div>
            <div class="wl-done-sub">Ko se sprosti termin, vas bomo obvestili po emailu.</div>
          </div>
        </div>
        <div class="slots-grid" id="wsslots" style="display:none"></div>
      </div>

      <!-- Korak 4: Podatki -->
      <div class="step" id="ws4">
        <div class="ttl">
          <button class="back" id="wb4"><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="m13 18-6-6 6-6"/></svg></button>
          Vaši podatki
        </div>
        <div class="sub" id="ws4sub"></div>
        <div class="ferr" id="wferr"></div>
        <div class="field"><label>Ime in priimek <span class="req">*</span></label><input class="inp" id="wfname" type="text" autocomplete="name" placeholder="npr. Janez Novak"></div>
        <div class="field"><label>Email <span class="req">*</span></label><input class="inp" id="wfemail" type="email" autocomplete="email" placeholder="janez@email.com"></div>
        <div class="field"><label>Telefon <span class="opt">(neobvezno)</span></label><input class="inp" id="wfphone" type="tel" autocomplete="tel" placeholder="041 123 456"></div>
        <div class="field"><label>Opombe <span class="opt">(neobvezno)</span></label><textarea class="ta" id="wfnotes" placeholder="Alergije, posebne želje..."></textarea></div>
        <div id="wcf-wrap"></div>
        <div class="gdpr-row">
          <label><input class="gdpr-cb" type="checkbox" id="wgdpr">
          <span>Strinjam se z obdelavo mojih osebnih podatkov za namen rezervacije. Prebral/a sem <a class="gdpr-lnk" id="wgdpr-link" href="#" target="_blank">Politiko zasebnosti</a>. <span class="gdpr-req">*</span></span></label>
        </div>
        <div class="gdpr-row" style="margin-bottom:14px">
          <label><input class="gdpr-cb" type="checkbox" id="wmktg">
          <span>Strinjam se s prejemanjem obvestil in posebnih ponudb po e-pošti. <span style="color:var(--f3)">(neobvezno)</span></span></label>
        </div>
        <button class="btn-p" id="wbtnsubmit"><span id="wbtnlbl">Pošlji rezervacijo</span></button>
      </div>

      <!-- Korak 5: Potrditev -->
      <div class="step" id="ws5">
        <div class="conf">
          <div class="cico" id="wcico"></div>
          <div class="cttl" id="wcttl"></div>
          <div class="cmsg" id="wcmsg"></div>
          <div class="sum">
            <div class="sum-ttl">Podrobnosti rezervacije</div>
            <div class="sum-row"><span>Restavracija</span><span id="wcsrest"></span></div>
            <div class="sum-row"><span>Datum</span><span id="wcsdate"></span></div>
            <div class="sum-row"><span>Ura</span><span id="wcstime"></span></div>
            <div class="sum-row"><span>Gostje</span><span id="wcsgst"></span></div>
          </div>
          <button class="btn-o" id="wbtnreset">Naredi novo rezervacijo</button>
        </div>
      </div>

    </div>
  </div>
</div>`;

    shadow.appendChild(styleEl);
    shadow.appendChild(wrap);

    // ── Pomožne ───────────────────────────────────────────────────
    const $ = (id) => shadow.getElementById(id);
    const MONTHS  = ['Januar','Februar','Marec','April','Maj','Junij','Julij','Avgust','September','Oktober','November','December'];
    const DAYS_SL = ['Ponedeljek','Torek','Sreda','Četrtek','Petek','Sobota','Nedelja'];

    function guestLbl(n) {
        return n === 1 ? 'gost' : n < 5 ? 'gostje' : 'gostov';
    }

    function fmtDate(ds) {
        const dt  = new Date(ds + 'T12:00:00');
        const dow = DAYS_SL[(dt.getDay() + 6) % 7];
        return `${dow}, ${dt.getDate()}. ${MONTHS[dt.getMonth()]} ${dt.getFullYear()}`;
    }

    // ── State ─────────────────────────────────────────────────────
    const state = {
        rest: null, guests: null, date: null, time: null,
        calYear: new Date().getFullYear(), calMonth: new Date().getMonth(),
    };

    // ── Progress ──────────────────────────────────────────────────
    function setStep(n) {
        for (let i = 1; i <= 5; i++) {
            const s = $('ws' + i);
            if (s) s.className = 'step' + (i === n ? ' active' : '');
        }
        for (let i = 1; i <= 4; i++) {
            const pi = $('wp' + i);
            if (!pi) continue;
            pi.className = 'pi' + (i === n ? ' active' : i < n ? ' done' : '');
            pi.querySelector('.n').textContent = i < n ? '✓' : i;
        }
        wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (n === 4) updateStep4Sub();
    }

    // ── Init: naloži restavracijo ─────────────────────────────────
    (async () => {
        try {
            const res  = await fetch(`${apiUrl}?t=${encodeURIComponent(token)}`);
            const json = await res.json();
            if (!json.success) throw new Error(json.error || 'Napaka');
            state.rest = json.data;

            $('wloading').style.display = 'none';
            $('wmain').style.display    = '';

            $('wname').textContent = json.data.name;
            // Logo črka
            $('wlogo').textContent = (json.data.name || 'R')[0].toUpperCase();
            // Privacy link
            $('wgdpr-link').href = privacyUrl;

            buildGuestBtns();
            renderCal();
            renderCustomFields(json.data.custom_fields || []);
        } catch (e) {
            $('wloading').style.display = 'none';
            $('werrmsg').textContent    = e.message;
            $('werr').style.display     = '';
        }
    })();

    // ── Korak 1: Gostje ───────────────────────────────────────────
    function buildGuestBtns() {
        const { min_guests, max_guests } = state.rest;
        const wrap = $('wgbtns');
        const showTo = Math.min(10, max_guests);
        const hasMore = max_guests > 10;

        for (let n = min_guests; n <= showTo; n++) {
            const b = document.createElement('button');
            b.className = 'gbtn';
            b.textContent = n;
            b.addEventListener('click', () => { selectGuests(n, b); setStep(2); });
            wrap.appendChild(b);
        }

        if (hasMore) {
            const more = document.createElement('button');
            more.id = 'wgmore';
            more.className = 'gmore';
            more.textContent = 'Več →';
            more.addEventListener('click', toggleMore);
            wrap.appendChild(more);

            const inp = $('wgmoreinp');
            inp.min = 11;
            inp.max = max_guests;
            $('wgmorelbl').textContent = `Vnesite število gostov (11–${max_guests})`;
            inp.placeholder = '11';
            inp.addEventListener('input', () => {
                const v = parseInt(inp.value);
                if (v >= 11 && v <= max_guests) selectGuests(v, shadow.getElementById('wgmore'));
                else { clearGuests(); if (v > max_guests) inp.value = max_guests; }
            });
            $('wbtn1').style.display = '';
        } else {
            $('wgmorewrap').style.display = 'none';
            $('wbtn1').style.display = 'none';
        }
    }

    let moreOpen = false;
    function toggleMore() {
        moreOpen = !moreOpen;
        $('wgmorewrap').style.display = moreOpen ? '' : 'none';
        const btn = shadow.getElementById('wgmore');
        if (moreOpen) { clearGuests(); btn.classList.add('sel'); $('wgmoreinp').focus(); }
        else btn.classList.remove('sel');
    }

    function clearGuests() {
        state.guests = null;
        shadow.querySelectorAll('.gbtn,.gmore').forEach(b => b.classList.remove('sel'));
        $('wbtn1').disabled = true;
    }

    function selectGuests(n, btn) {
        state.guests = n;
        shadow.querySelectorAll('.gbtn,.gmore').forEach(b => b.classList.remove('sel'));
        btn.classList.add('sel');
        if (btn.id !== 'wgmore') { moreOpen = false; $('wgmorewrap').style.display = 'none'; }
        $('wbtn1').disabled = false;
    }

    $('wbtn1').addEventListener('click', () => { if (state.guests) setStep(2); });

    // ── Korak 2: Kalendar ─────────────────────────────────────────
    function renderCal() {
        const year  = state.calYear;
        const month = state.calMonth;
        const today = new Date(); today.setHours(0, 0, 0, 0);

        $('wcalttl').textContent = `${MONTHS[month]} ${year}`;

        const isCurMon = year === today.getFullYear() && month === today.getMonth();
        const prev = $('wcalprev');
        prev.disabled = isCurMon;

        const first = new Date(year, month, 1);
        const last  = new Date(year, month + 1, 0);
        let dow = first.getDay() - 1;
        if (dow < 0) dow = 6;

        const grid  = $('wcaldays');
        grid.innerHTML = '';

        // Prazne celice
        for (let i = 0; i < dow; i++) {
            grid.appendChild(document.createElement('div'));
        }

        const openDays   = state.rest.open_days;
        const blackouts  = state.rest.blackout_dates || [];

        for (let d = 1; d <= last.getDate(); d++) {
            const dt     = new Date(year, month, d);
            const dayIdx = (dt.getDay() + 6) % 7; // 0=Pon
            const ds     = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const isPast = dt < today;
            const isOpen = (openDays >> dayIdx) & 1;
            const isBlk  = blackouts.includes(ds);
            const isTd   = dt.toDateString() === today.toDateString();
            const isSel  = ds === state.date;

            const cell = document.createElement('div');
            cell.textContent = d;

            const futureUnavail = !isPast && (!isOpen || isBlk);
            if (isPast) {
                cell.className = 'cal-day dis' + (isTd ? ' td' : '');
            } else if (futureUnavail && state.rest.waitlist_enabled) {
                cell.className = 'cal-day wl' + (isTd ? ' td' : '');
                cell.title = 'Ni terminov – kliknite za čakalno listo';
                cell.addEventListener('click', () => selectDateWaitlist(ds));
            } else if (futureUnavail) {
                cell.className = 'cal-day dis' + (isTd ? ' td' : '');
            } else {
                cell.className = 'cal-day av' + (isTd ? ' td' : '') + (isSel ? ' sel' : '');
                cell.addEventListener('click', () => selectDate(ds));
            }
            grid.appendChild(cell);
        }
    }

    $('wcalprev').addEventListener('click', () => calMove(-1));
    $('wcalnext').addEventListener('click', () => calMove(1));

    function calMove(dir) {
        state.calMonth += dir;
        if (state.calMonth > 11) { state.calMonth = 0; state.calYear++; }
        if (state.calMonth < 0)  { state.calMonth = 11; state.calYear--; }
        renderCal();
    }

    function selectDate(ds) {
        state.date = ds;
        renderCal();
        setStep(3);
        loadSlots(ds);
    }

    function selectDateWaitlist(ds) {
        state.date = ds;
        renderCal();
        $('wsloading').style.display = 'none';
        $('wsslots').style.display   = 'none';
        $('wsempty').style.display   = '';
        showWaitlistPanel();
        const dt = new Date(ds + 'T12:00:00');
        $('ws3sub').textContent = `${DAYS_SL[(dt.getDay() + 6) % 7]}, ${dt.getDate()}. ${MONTHS[dt.getMonth()]} ${dt.getFullYear()} · ni terminov`;
        setStep(3);
    }

    function showWaitlistPanel() {
        $('wwl-panel').style.display = '';
        $('wwl-done').style.display  = 'none';
        // Ponastavi
        ['wwl-first','wwl-last','wwl-email','wwl-phone','wwl-time'].forEach(id => {
            const el = $(id); if (el) el.value = '';
        });
        const g = $('wwl-gdpr'); if (g) g.checked = false;
        const e = $('wwl-err');  if (e) { e.textContent = ''; e.classList.remove('show'); }
    }

    // ── Korak 3: Termini ──────────────────────────────────────────
    async function loadSlots(date) {
        $('wsloading').style.display = '';
        $('wsempty').style.display   = 'none';
        $('wsslots').style.display   = 'none';
        $('wsslots').innerHTML = '';
        if ($('wwl-panel')) $('wwl-panel').style.display = 'none';
        if ($('wwl-done'))  $('wwl-done').style.display  = 'none';

        try {
            const res  = await fetch(`${apiUrl}?t=${encodeURIComponent(token)}&date=${date}`);
            const json = await res.json();
            if (!json.success) throw new Error(json.error);
            const slots = json.data.slots || [];
            $('wsloading').style.display = 'none';

            if (!slots.length) {
                $('wsempty').style.display = '';
                if (state.rest.waitlist_enabled) showWaitlistPanel();
                return;
            }

            const grid = $('wsslots');
            slots.forEach(slotObj => {
                const time   = typeof slotObj === 'string' ? slotObj : slotObj.time;
                const status = typeof slotObj === 'string' ? 'available' : (slotObj.status || 'available');
                if (status === 'full') return; // preskoči popolnoma zasedene termine
                const b = document.createElement('button');
                b.className = 'slot' + (status === 'waitlist' ? ' wl' : '');
                b.title = status === 'waitlist' ? 'Zasedeno – vpis na čakalno listo' : '';
                b.textContent = time;
                b.addEventListener('click', () => selectSlot(time, b));
                grid.appendChild(b);
            });
            grid.style.display = '';

            const dt  = new Date(date + 'T12:00:00');
            $('ws3sub').textContent = `${DAYS_SL[(dt.getDay() + 6) % 7]}, ${dt.getDate()}. ${MONTHS[dt.getMonth()]} · ${state.guests} ${guestLbl(state.guests)}`;

        } catch (e) {
            $('wsloading').style.display = 'none';
            $('wsempty').style.display   = '';
            if (state.rest.waitlist_enabled) showWaitlistPanel();
        }
    }

    function selectSlot(time, btn) {
        state.time = time;
        shadow.querySelectorAll('.slot').forEach(b => b.classList.remove('sel'));
        btn.classList.add('sel');
        setTimeout(() => setStep(4), 180);
    }

    // ── Čakalna lista submit ───────────────────────────────────────
    const waitlistApiUrl = scriptEl.src.replace(/\/widget\.js(\?.*)?$/, '') + '/api/waitlist.php';

    $('wwl-submit').addEventListener('click', async () => {
        const firstName = $('wwl-first')?.value.trim();
        const lastName  = $('wwl-last')?.value.trim();
        const email     = $('wwl-email')?.value.trim();
        const phone     = $('wwl-phone')?.value.trim();
        const timePref  = $('wwl-time')?.value.trim();
        const gdpr      = $('wwl-gdpr')?.checked;
        const errEl     = $('wwl-err');
        const btn       = $('wwl-submit');

        const showErr = (msg) => { errEl.textContent = msg; errEl.classList.add('show'); };
        errEl.classList.remove('show');

        if (!firstName) return showErr('Ime je obvezno.');
        if (!lastName)  return showErr('Priimek je obvezen.');
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return showErr('Vnesite veljaven email.');
        if (!gdpr)      return showErr('Soglasje je obvezno.');

        btn.disabled = true;
        btn.textContent = 'Pošiljam...';

        try {
            const res  = await fetch(`${waitlistApiUrl}?t=${encodeURIComponent(token)}`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({
                    date:        state.date,
                    time_pref:   timePref || '',
                    guests:      state.guests || 1,
                    first_name:  firstName,
                    last_name:   lastName,
                    email,
                    phone:       phone || '',
                    gdpr_consent: true,
                }),
            });
            const json = await res.json();
            if (!json.success) throw new Error(json.error || 'Napaka strežnika.');
            $('wwl-panel').style.display = 'none';
            $('wwl-done').style.display  = '';
        } catch (e) {
            showErr(e.message);
            btn.disabled = false;
            btn.textContent = 'Vpišem se na čakalno listo';
        }
    });

    // ── Custom fields ─────────────────────────────────────────────
    function renderCustomFields(fields) {
        const cfWrap = $('wcf-wrap');
        if (!cfWrap) return;
        cfWrap.innerHTML = '';
        if (!fields || !fields.length) return;
        fields.forEach(f => {
            const fid = String(f.id);
            const div = document.createElement('div');
            div.className = 'field';

            if (f.field_type === 'checkbox') {
                const lbl2 = document.createElement('label');
                lbl2.style.cssText = 'display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;font-size:14px;margin-top:4px';
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.id = 'wcf-' + fid;
                cb.dataset.cfid = fid;
                cb.style.cssText = 'width:16px;height:16px;accent-color:var(--f);cursor:pointer;flex-shrink:0';
                const sp = document.createElement('span');
                sp.textContent = f.label;
                lbl2.appendChild(cb);
                lbl2.appendChild(sp);
                div.appendChild(lbl2);
            } else {
                const lbl = document.createElement('label');
                lbl.innerHTML = escH(f.label) + (f.is_required
                    ? ' <span class="req">*</span>'
                    : ' <span class="opt">(neobvezno)</span>');
                div.appendChild(lbl);

                if (f.field_type === 'select' && f.options && f.options.length) {
                    const sel = document.createElement('select');
                    sel.className = 'inp';
                    sel.id = 'wcf-' + fid;
                    sel.dataset.cfid = fid;
                    const empty = document.createElement('option');
                    empty.value = ''; empty.textContent = '— Izberite —';
                    sel.appendChild(empty);
                    f.options.forEach(o => {
                        const opt = document.createElement('option');
                        opt.value = o; opt.textContent = o;
                        sel.appendChild(opt);
                    });
                    div.appendChild(sel);
                } else {
                    const inp = document.createElement('input');
                    inp.type = 'text';
                    inp.className = 'inp';
                    inp.id = 'wcf-' + fid;
                    inp.dataset.cfid = fid;
                    div.appendChild(inp);
                }
            }
            cfWrap.appendChild(div);
        });
    }

    function collectCustomFields() {
        const cf = {};
        const fields = (state.rest && state.rest.custom_fields) || [];
        fields.forEach(f => {
            const el = $('wcf-' + f.id);
            if (!el) return;
            cf[String(f.id)] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value.trim();
        });
        return cf;
    }

    function validateCustomFields() {
        const fields = (state.rest && state.rest.custom_fields) || [];
        for (const f of fields) {
            if (!f.is_required) continue;
            if (f.field_type === 'checkbox') continue;
            const el = $('wcf-' + f.id);
            if (!el || !el.value.trim()) {
                showErr(`Polje "${f.label}" je obvezno.`);
                if (el) el.focus();
                return false;
            }
        }
        return true;
    }

    function escH(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Korak 4: Obrazec ──────────────────────────────────────────
    function updateStep4Sub() {
        if (!state.date || !state.time) return;
        const dt = new Date(state.date + 'T12:00:00');
        $('ws4sub').textContent = `${DAYS_SL[(dt.getDay() + 6) % 7]}, ${dt.getDate()}. ${MONTHS[dt.getMonth()]} · ${state.time} · ${state.guests} ${guestLbl(state.guests)}`;
    }

    $('wbtnsubmit').addEventListener('click', async () => {
        const name  = $('wfname').value.trim();
        const email = $('wfemail').value.trim();
        const phone = $('wfphone').value.trim();
        const notes = $('wfnotes').value.trim();
        const err   = $('wferr');

        err.className = 'ferr';

        const gdprOk = $('wgdpr')?.checked;
        const mktg   = $('wmktg')?.checked;

        if (!name)  { showErr('Ime in priimek sta obvezna.'); return; }
        if (!email) { showErr('Email naslov je obvezen.'); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showErr('Vnesite veljaven email naslov.'); return; }
        if (!gdprOk) { showErr('Strinjanje z obdelavo podatkov je obvezno.'); return; }
        if (!validateCustomFields()) return;

        const customFields = collectCustomFields();

        setLoading(true);
        try {
            const res  = await fetch(`${apiUrl}?t=${encodeURIComponent(token)}`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ date: state.date, time: state.time, guest_name: name, email, phone, notes, guest_count: state.guests, custom_fields: customFields, gdpr_consent: true, marketing_consent: mktg ? true : false }),
            });
            const json = await res.json();
            if (!json.success) throw new Error(json.error || 'Napaka strežnika.');
            showConfirm(json.data.auto_confirm);
        } catch (e) {
            showErr(e.message);
            setLoading(false);
        }
    });

    function showErr(msg) {
        const el = $('wferr');
        el.textContent = msg;
        el.className = 'ferr show';
    }

    function setLoading(on) {
        const btn = $('wbtnsubmit');
        btn.disabled = on;
        $('wbtnlbl').textContent = on ? 'Pošiljam...' : 'Pošlji rezervacijo';
        if (on) {
            const sp = document.createElement('span');
            sp.className = 'spin';
            sp.id = 'wspin';
            btn.appendChild(sp);
        } else {
            const sp = shadow.getElementById('wspin');
            if (sp) sp.remove();
        }
    }

    // ── Korak 5: Potrditev ────────────────────────────────────────
    function showConfirm(auto) {
        const dt  = new Date(state.date + 'T12:00:00');
        const ds  = `${DAYS_SL[(dt.getDay() + 6) % 7]}, ${dt.getDate()}. ${MONTHS[dt.getMonth()]} ${dt.getFullYear()}`;
        $('wcico').textContent = auto ? '✅' : '📩';
        $('wcttl').textContent = auto ? 'Rezervacija potrjena!' : 'Prošnja sprejeta!';
        $('wcmsg').textContent = auto
            ? 'Vaša rezervacija je potrjena. Poslali smo vam potrditveni e-mail.'
            : 'Vaša prošnja je bila sprejeta. Ko jo potrdimo, vas obvestimo po e-pošti.';
        $('wcsrest').textContent  = state.rest.name;
        $('wcsdate').textContent  = ds;
        $('wcstime').textContent  = state.time;
        $('wcsgst').textContent   = `${state.guests} ${guestLbl(state.guests)}`;
        setStep(5);
    }

    // ── Reset ─────────────────────────────────────────────────────
    $('wbtnreset').addEventListener('click', () => {
        state.guests = null; state.date = null; state.time = null;
        shadow.querySelectorAll('.gbtn,.gmore').forEach(b => b.classList.remove('sel'));
        $('wbtn1').disabled = true;
        $('wgmorewrap').style.display = 'none';
        $('wgmoreinp').value = '';
        $('wfname').value = ''; $('wfemail').value = '';
        $('wfphone').value = ''; $('wfnotes').value = '';
        const gdprCb = $('wgdpr'); if (gdprCb) gdprCb.checked = false;
        const mktgCb = $('wmktg'); if (mktgCb) mktgCb.checked = false;
        ((state.rest && state.rest.custom_fields) || []).forEach(f => {
            const el = $('wcf-' + f.id);
            if (!el) return;
            if (el.type === 'checkbox') el.checked = false;
            else el.value = '';
        });
        moreOpen = false;
        setStep(1);
    });

    // ── Back gumbi ────────────────────────────────────────────────
    $('wb2').addEventListener('click', () => setStep(1));
    $('wb3').addEventListener('click', () => setStep(2));
    $('wb4').addEventListener('click', () => setStep(3));

})();
