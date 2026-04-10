<?php
/**
 * Pošlje anketo gostu po emailu.
 */
require_once __DIR__ . '/mailer.php';

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

    $appName   = APP_NAME;
    $restName  = htmlspecialchars($form['restaurant_name'], ENT_QUOTES);
    $surveyUrl = APP_URL . BASE_PATH . '/pages/survey.php?t=' . urlencode($sr['token']);

    $bodyParts = [];

    // Zahvala
    if ($form['include_thankyou'] && $form['thank_you_message']) {
        $msg = nl2br(htmlspecialchars($form['thank_you_message'], ENT_QUOTES));
        $bodyParts[] = "
            <p style='margin:0 0 20px;font-size:14px;color:#374151;line-height:1.7'>{$msg}</p>";
    } else {
        $bodyParts[] = "
            <p style='margin:0 0 20px;font-size:14px;color:#374151;line-height:1.7'>
                Hvala, ker ste nas obiskali! Veselimo se vašega naslednjega obiska.
            </p>";
    }

    // Gumb za anketo
    if ($form['include_survey']) {
        $bodyParts[] = "
            <div style='margin:24px 0'>
                <p style='margin:0 0 12px;font-size:14px;color:#374151'>Prosimo, vzemite si minuto in izpolnite kratko anketo o vašem obisku. Vaše mnenje nam pomaga, da se izboljšamo.</p>
                <a href='{$surveyUrl}' target='_blank'
                   style='display:inline-block;background:#F59E0B;color:#fff;text-decoration:none;padding:13px 28px;border-radius:9px;font-weight:600;font-size:14px;font-family:Inter,-apple-system,sans-serif;line-height:1.4'>
                   Izpolni anketo →
                </a>
                <p style='margin:14px 0 0;font-size:12px;color:#9CA3AF'>Če gumb ne deluje, kopirajte ta naslov v brskalnik:<br>
                <a href='{$surveyUrl}' style='color:#F59E0B;word-break:break-all'>{$surveyUrl}</a></p>
            </div>";
    }

    $body = "
        <p style='margin:0 0 6px;font-size:16px;font-weight:600;color:#111827'>Hvala za obisk v {$restName}!</p>
        " . implode('', $bodyParts);

    $html = email_wrap($appName, $body, $appName . ' · Zahvala za obisk');

    $textParts = ["Hvala za obisk v " . $form['restaurant_name'] . "!\n\n"];
    if ($form['include_thankyou'] && $form['thank_you_message']) {
        $textParts[] = $form['thank_you_message'] . "\n\n";
    }
    if ($form['include_survey']) {
        $textParts[] = "Izpolnite anketo: " . $surveyUrl . "\n";
    }
    $text = implode('', $textParts);

    $subject = 'Hvala za obisk – ' . $form['restaurant_name'];

    $ok = send_email($sr['email'], $subject, $html, $text);
    if ($ok) {
        $pdo->prepare("UPDATE survey_responses SET email_sent_at = NOW() WHERE id = ?")
            ->execute([$sr['id']]);
    }
    return $ok;
}
