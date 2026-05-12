<?php
/**
 * Pošlje anketo gostu po emailu.
 */
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/survey_helper.php';

function send_survey_email(PDO $pdo, array $sr): bool {
    // Naloži anketo
    $stmt = $pdo->prepare("
        SELECT sf.*, r.name AS restaurant_name
        FROM survey_forms sf
        JOIN restaurants r ON sf.restaurant_id = r.id
        WHERE sf.id = ?
    ");
    $stmt->execute([$sr['survey_id']]);
    $form = $stmt->fetch();
    if (!$form) return false;

    // Določi jezik (iz survey_response.survey_language; fallback 'sl')
    $allowed = SURVEY_ALLOWED_LANGS;
    $lang = !empty($sr['survey_language']) && in_array($sr['survey_language'], $allowed, true)
        ? $sr['survey_language']
        : 'sl';

    // Naloži prevod thank_you_message za ta jezik (če obstaja)
    $thanksMsg = $form['thank_you_message'];
    try {
        $tStmt = $pdo->prepare("SELECT thank_you_message FROM survey_form_translations WHERE form_id=? AND lang_code=?");
        $tStmt->execute([$form['id'], $lang]);
        $tr = $tStmt->fetchColumn();
        if ($tr) $thanksMsg = $tr;
    } catch (PDOException $e) { /* ignore */ }

    $appName   = APP_NAME;
    $restName  = htmlspecialchars($form['restaurant_name'], ENT_QUOTES);
    $surveyUrl = APP_URL . BASE_PATH . '/pages/survey.php?t=' . urlencode($sr['token']);

    $bodyParts = [];

    // Zahvala
    if ($form['include_thankyou'] && $thanksMsg) {
        $msg = nl2br(htmlspecialchars($thanksMsg, ENT_QUOTES));
        $bodyParts[] = "
            <p style='margin:0 0 20px;font-size:14px;color:#374151;line-height:1.7'>{$msg}</p>";
    } else {
        $bodyParts[] = "
            <p style='margin:0 0 20px;font-size:14px;color:#374151;line-height:1.7'>" .
            htmlspecialchars(_email_t('email.survey.default_thanks', $lang), ENT_QUOTES) .
            "</p>";
    }

    // Gumb za anketo
    if ($form['include_survey']) {
        $invite = htmlspecialchars(_email_t('email.survey.invite', $lang), ENT_QUOTES);
        $cta    = htmlspecialchars(_email_t('email.survey.cta', $lang), ENT_QUOTES);
        $fb     = htmlspecialchars(_email_t('email.survey.fallback_link', $lang), ENT_QUOTES);
        $bodyParts[] = "
            <div style='margin:24px 0'>
                <p style='margin:0 0 12px;font-size:14px;color:#374151'>{$invite}</p>
                <a href='{$surveyUrl}' target='_blank'
                   style='display:inline-block;background:#F59E0B;color:#fff;text-decoration:none;padding:13px 28px;border-radius:9px;font-weight:600;font-size:14px;font-family:Inter,-apple-system,sans-serif;line-height:1.4'>
                   {$cta}
                </a>
                <p style='margin:14px 0 0;font-size:12px;color:#9CA3AF'>{$fb}<br>
                <a href='{$surveyUrl}' style='color:#F59E0B;word-break:break-all'>{$surveyUrl}</a></p>
            </div>";
    }

    $heading = htmlspecialchars(_email_t('email.survey.heading', $lang, ['restaurant' => $form['restaurant_name']]), ENT_QUOTES);
    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#111827'>{$heading}</p>
        " . implode('', $bodyParts);

    $tagline = $appName . ' · ' . _email_t('email.survey.tagline', $lang);
    $html    = email_wrap($appName, $body, $tagline);

    $textParts = [_email_t('email.survey.heading', $lang, ['restaurant' => $form['restaurant_name']]) . "\n\n"];
    if ($form['include_thankyou'] && $thanksMsg) {
        $textParts[] = $thanksMsg . "\n\n";
    }
    if ($form['include_survey']) {
        $textParts[] = _email_t('email.survey.text_fill', $lang) . $surveyUrl . "\n";
    }
    $text = implode('', $textParts);

    $subject = _email_t('email.survey.subject', $lang, ['restaurant' => $form['restaurant_name']]);

    $ok = send_email($sr['email'], $subject, $html, $text);
    if ($ok) {
        $pdo->prepare("UPDATE survey_responses SET email_sent_at = NOW() WHERE id = ?")
            ->execute([$sr['id']]);
    }
    return $ok;
}
