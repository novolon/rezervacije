<?php
require_once '../config.php';
require_once '../includes/lang.php';
$_appName = htmlspecialchars(APP_NAME, ENT_QUOTES);

$pageTitle = t('aff.terms.page_title');
$extraCss  = ['design.css'];
require_once '../includes/html_head.php';
?>
<body>
<div style="min-height:100vh;background:var(--bg);padding:48px 16px">
<div style="max-width:700px;margin:0 auto">

    <a href="<?= BASE_PATH ?>/affiliate/register.php" style="display:inline-flex;align-items:center;gap:8px;color:var(--ink-mute);font-size:13px;margin-bottom:28px;text-decoration:none;font-weight:600">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        <?= t('aff.terms.back_btn') ?>
    </a>

    <div class="rz-card">
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:28px;padding-bottom:20px;border-bottom:1px solid var(--line)">
            <img src="<?= BASE_PATH ?>/assets/images/Rezble.svg" alt="Rezble" style="height:26px;width:auto;display:block;flex:none">
            <div>
                <div style="font-size:18px;font-weight:700;color:var(--ink)"><?= t('aff.terms.title') ?></div>
                <div style="font-size:12px;color:var(--ink-mute)"><?= $_appName ?></div>
            </div>
        </div>

        <div style="font-size:14px;line-height:1.75;color:var(--ink-soft)">
            <?php
            $h = 'style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-mute);margin:0 0 8px"';
            $p = 'style="margin:0 0 20px"';
            $ul = 'style="margin:0 0 20px 20px"';
            $appParam = ['app' => $_appName];
            ?>

            <h2 <?= $h ?>><?= t('aff.terms.h_general') ?></h2>
            <p <?= $p ?>><?= t('aff.terms.p_general', $appParam) ?></p>

            <h2 <?= $h ?>><?= t('aff.terms.h_eligibility') ?></h2>
            <p style="margin:0 0 8px"><?= t('aff.terms.p_eligibility') ?></p>
            <ul <?= $ul ?>>
                <li><?= t('aff.terms.li_eligibility_1') ?></li>
                <li><?= t('aff.terms.li_eligibility_2') ?></li>
                <li><?= t('aff.terms.li_eligibility_3') ?></li>
            </ul>

            <h2 <?= $h ?>><?= t('aff.terms.h_attribution') ?></h2>
            <p <?= $p ?>><?= t_raw('aff.terms.p_attribution') ?></p>

            <h2 <?= $h ?>><?= t('aff.terms.h_commission') ?></h2>
            <p style="margin:0 0 8px"><?= t('aff.terms.p_commission_1') ?></p>
            <p style="margin:0 0 8px"><?= t('aff.terms.p_commission_2') ?></p>
            <ul <?= $ul ?>>
                <li><?= t('aff.terms.li_no_commission_1') ?></li>
                <li><?= t('aff.terms.li_no_commission_2') ?></li>
                <li><?= t('aff.terms.li_no_commission_3') ?></li>
            </ul>

            <h2 <?= $h ?>><?= t('aff.terms.h_payouts') ?></h2>
            <p <?= $p ?>><?= t('aff.terms.p_payouts') ?></p>

            <h2 <?= $h ?>><?= t('aff.terms.h_discount') ?></h2>
            <p <?= $p ?>><?= t('aff.terms.p_discount') ?></p>

            <h2 <?= $h ?>><?= t('aff.terms.h_prohibited') ?></h2>
            <ul <?= $ul ?>>
                <li><?= t('aff.terms.li_prohibited_1', $appParam) ?></li>
                <li><?= t('aff.terms.li_prohibited_2') ?></li>
                <li><?= t('aff.terms.li_prohibited_3') ?></li>
                <li><?= t('aff.terms.li_prohibited_4') ?></li>
            </ul>

            <h2 <?= $h ?>><?= t('aff.terms.h_termination') ?></h2>
            <p <?= $p ?>><?= t('aff.terms.p_termination', $appParam) ?></p>

            <h2 <?= $h ?>><?= t('aff.terms.h_privacy') ?></h2>
            <p <?= $p ?>><?= t('aff.terms.p_privacy') ?></p>

            <h2 <?= $h ?>><?= t('aff.terms.h_changes') ?></h2>
            <p <?= $p ?>><?= t('aff.terms.p_changes') ?></p>

            <div style="border-top:1px solid var(--line);margin-top:12px;padding-top:16px;font-size:11px;color:var(--ink-mute)">
                <?= t('aff.terms.effective_date', $appParam) ?>
            </div>
        </div>
    </div>

    <div style="text-align:center;margin-top:20px">
        <a href="<?= BASE_PATH ?>/affiliate/register.php" class="rz-btn rz-btn-primary" style="display:inline-flex">
            ← <?= t('aff.terms.back_btn') ?>
        </a>
    </div>
</div>
</div>
</body>
</html>
