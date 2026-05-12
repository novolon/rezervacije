<?php
/**
 * Rezble topbar partial.
 *
 * Parametri:
 *   $topbarTitle       (string, obvezno)   — glavni naslov strani / ime restavracije
 *   $topbarSubtitle    (string|null)       — podnaslov (datum, statistika …)
 *   $topbarActions     (string|null)       — HTML za gumbe na desni strani
 *   $topbarShowCmd     (bool = true)       — prikaži gumb za command palette (⌘K)
 *
 * Potrebuje, da je naložen sidebar.php (za funkcijo _rz_icon()).
 */

$topbarTitle    = $topbarTitle    ?? t('topbar.default_title');
$topbarSubtitle = $topbarSubtitle ?? null;
$topbarActions  = $topbarActions  ?? null;
$topbarShowCmd  = $topbarShowCmd  ?? true;
?>
<div class="rz-topbar">
    <div class="rz-topbar-main">
        <div class="flex flex--center flex--gap20">
        <h1 class="rz-h1 display" id="rz-topbar-title"><?= htmlspecialchars($topbarTitle) ?></h1>
        <button id="btn-today" type="button" class="rz-btn" style="display:none">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        <span><?= t('topbar.today_btn') ?></span>
    </button>
    </div>
        <?php if ($topbarSubtitle !== null): ?>
            <p class="rz-top-sub"><?= $topbarSubtitle /* lahko vsebuje HTML */ ?></p>
        <?php endif; ?>
    </div>
    <div class="rz-top-actions">
        <?php if ($topbarShowCmd): ?>
            <button type="button" class="rz-kbd-hint" id="rz-cmd-open" title="<?= t('topbar.search') ?>">
                <?= _rz_icon('search', 14) ?>
                <span><?= t('topbar.search') ?></span>
                <span class="rz-kbd" data-rz-shortcut="cmd-k">⌘K</span>
            </button>
        <?php endif; ?>
        <?= $topbarActions ?>
    </div>
</div>
<?php
// AI Help Chat — floating widget (loaded enkrat na stran, na vseh authenticated straneh).
// superadmin nima help chat-a, ker ima drugo orodje (chat history pregled).
$_hcSession = $_SESSION ?? [];
if (!empty($_hcSession['user_id']) && ($_hcSession['role'] ?? '') !== 'superadmin'):
?>
<script>window.APP_BASE = window.APP_BASE || <?= json_encode(BASE_PATH) ?>;</script>
<script src="<?= BASE_PATH ?>/assets/js/help_chat.js?v=2" defer></script>
<?php endif; ?>
