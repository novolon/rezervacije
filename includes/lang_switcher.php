<?php
/**
 * Language switcher partial — uporabljen na landing, login, register strani.
 *
 * Parametri (opcijski, nastavi pred include):
 *   $langSwitcherTheme   — 'light' (privzeto, za svetlo ozadje) ali 'dark' (za temno).
 *   $langSwitcherClass   — dodaten CSS class na zunanji <details>.
 *
 * Po kliku na jezik se stran znova naloži z `?lang=XX`. lang.php potem cookie nastavi.
 */
$LANGS = ['sl', 'en', 'de', 'it', 'fr', 'hr', 'es', 'pt'];
$cur   = get_lang();
$theme = $langSwitcherTheme ?? 'light';
$extra = $langSwitcherClass ?? '';

// Sestavi current URL z `?lang=XX`
$_lsUri = $_SERVER['REQUEST_URI'] ?? '/';
$_lsParts = parse_url($_lsUri);
$_lsQuery = [];
if (!empty($_lsParts['query'])) parse_str($_lsParts['query'], $_lsQuery);
?>
<details class="lang-switcher lang-switcher--<?= htmlspecialchars($theme, ENT_QUOTES) ?> <?= htmlspecialchars($extra, ENT_QUOTES) ?>" style="position:relative;display:inline-block">
    <summary class="lang-switcher-btn" style="list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:7px;font-size:13px;font-weight:600;<?php
        if ($theme === 'dark') {
            echo 'color:rgba(255,255,255,.85);background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);';
        } else {
            echo 'color:var(--ink-mute,#5a655e);background:transparent;border:1px solid var(--line,#e8dcc9);';
        }
    ?>">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
        <span><?= strtoupper($cur) ?></span>
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="opacity:.6"><path d="m6 9 6 6 6-6"/></svg>
    </summary>
    <div class="lang-switcher-menu" style="position:absolute;right:0;top:calc(100% + 6px);min-width:140px;padding:6px;border-radius:10px;z-index:60;<?php
        if ($theme === 'dark') {
            echo 'background:#fff;border:1px solid rgba(0,0,0,.06);box-shadow:0 8px 24px rgba(0,0,0,.18);';
        } else {
            echo 'background:#fff;border:1px solid var(--line,#e8dcc9);box-shadow:0 8px 24px rgba(28,38,32,.10);';
        }
    ?>">
        <?php foreach ($LANGS as $code):
            $_lsQuery['lang'] = $code;
            $_lsHref = ($_lsParts['path'] ?? '/') . '?' . http_build_query($_lsQuery);
            $isActive = $code === $cur;
        ?>
        <a href="<?= htmlspecialchars($_lsHref, ENT_QUOTES) ?>" style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-radius:6px;font-size:13.5px;color:<?= $isActive ? '#1c2620' : '#5a655e' ?>;font-weight:<?= $isActive ? 700 : 500 ?>;text-decoration:none;<?= $isActive ? 'background:rgba(200,84,43,.08)' : '' ?>">
            <span><?= strtoupper($code) ?></span>
            <?php if ($isActive): ?>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#c8542b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</details>
<style>
.lang-switcher summary::-webkit-details-marker { display: none; }
.lang-switcher[open] > .lang-switcher-btn { border-color: rgba(200,84,43,.5); }
</style>
<script>
/* Click-outside zapre <details class="lang-switcher">. Idempotent: registrira samo enkrat. */
(function(){
    if (window.__rzLangSwitcherInit) return;
    window.__rzLangSwitcherInit = true;
    document.addEventListener('click', function(e) {
        document.querySelectorAll('details.lang-switcher[open]').forEach(function(d) {
            if (!d.contains(e.target)) d.removeAttribute('open');
        });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('details.lang-switcher[open]').forEach(function(d) {
                d.removeAttribute('open');
            });
        }
    });
})();
</script>
