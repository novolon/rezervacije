<?php
/**
 * Čakalna lista – obveščanje naslednjih gostov ko se sprosti termin.
 *
 * Uporaba:
 *   require_once 'waitlist_notifier.php';
 *   notify_waitlist($pdo, $restaurantId, $date);
 */

/**
 * Obvesti prvega čakajočega gosta za dani datum (status = 'waiting').
 * Nastavi status = 'notified', expires_at = +2h in pošlje email.
 */
function notify_waitlist(PDO $pdo, int $restaurantId, string $date): void {
    $stmt = $pdo->prepare("
        SELECT w.*, r.name AS rest_name, r.contact_email, r.contact_phone
        FROM waitlist w
        JOIN restaurants r ON r.id = w.restaurant_id
        WHERE w.restaurant_id = ?
          AND w.date = ?
          AND w.status = 'waiting'
        ORDER BY w.created_at ASC
        LIMIT 1
    ");
    $stmt->execute([$restaurantId, $date]);
    $entry = $stmt->fetch();

    if (!$entry) return;

    $expiresAt = date('Y-m-d H:i:s', time() + 2 * 3600);
    $pdo->prepare("
        UPDATE waitlist
        SET status = 'notified', notified_at = NOW(), expires_at = ?
        WHERE id = ?
    ")->execute([$expiresAt, $entry['id']]);

    _send_waitlist_notify_email($entry);
}

/**
 * Pošlje email gostu na čakalni listi (sprosto se je mesto).
 */
function _send_waitlist_notify_email(array $entry): void {
    require_once __DIR__ . '/mailer.php';

    $appName   = defined('APP_NAME') ? APP_NAME : 'Rezervacije';
    $baseUrl   = (defined('APP_URL') ? APP_URL : '') . (defined('BASE_PATH') ? BASE_PATH : '');
    $token     = urlencode($entry['token']);
    $confirmUrl = $baseUrl . '/api/waitlist.php?token=' . $token . '&action=confirm';
    $removeUrl  = $baseUrl . '/api/waitlist.php?token=' . $token . '&action=remove';

    $restName  = htmlspecialchars($entry['rest_name'], ENT_QUOTES);
    $firstName = htmlspecialchars($entry['first_name'], ENT_QUOTES);
    $date      = date('j. n. Y', strtotime($entry['date']));
    $timePref  = $entry['time_preference']
        ? ' (prednostni čas: ' . htmlspecialchars($entry['time_preference'], ENT_QUOTES) . ')'
        : '';

    $body = "
        <h2 style='margin:0 0 16px;font-size:22px;font-weight:700;color:#111827'>Sprosto se je mesto! 🎉</h2>
        <p style='margin:0 0 8px;color:#374151;font-size:15px'>Pozdravljeni, <strong>{$firstName}</strong>!</p>
        <p style='margin:0 0 20px;color:#374151;font-size:15px'>
            Sprosto se je mesto pri <strong>{$restName}</strong> za datum <strong>{$date}</strong>{$timePref}.
        </p>
        <p style='margin:0 0 20px;color:#6B7280;font-size:14px'>
            Za potrditev rezervacije imate <strong>2 uri</strong>. Po tem času bo mesto ponujeno naslednjemu gostu v vrsti.
        </p>
        <div style='text-align:center;margin:28px 0 8px'>
            <a href='" . htmlspecialchars($confirmUrl, ENT_QUOTES) . "'
               style='display:inline-block;background:#1B4332;color:#fff;text-decoration:none;padding:13px 32px;border-radius:10px;font-size:15px;font-weight:700'>
                Potrdi rezervacijo
            </a>
        </div>
        <p style='text-align:center;margin:16px 0 0'>
            <a href='" . htmlspecialchars($removeUrl, ENT_QUOTES) . "'
               style='color:#9CA3AF;font-size:13px;text-decoration:none'>
                Ne zanima me – odjavim se s čakalne liste
            </a>
        </p>
    ";

    $html = email_wrap($appName, $body, "To sporočilo ste prejeli, ker ste se vpisali na čakalno listo restavracije {$restName}.");
    send_email($entry['email'], "Sprosto se je mesto – {$restName}", $html);
}

/**
 * Pošlje potrditveni email gostu po uspešnem vpisu na čakalno listo.
 */
function send_waitlist_signup_email(array $entry, string $restName): void {
    require_once __DIR__ . '/mailer.php';

    $appName   = defined('APP_NAME') ? APP_NAME : 'Rezervacije';
    $baseUrl   = (defined('APP_URL') ? APP_URL : '') . (defined('BASE_PATH') ? BASE_PATH : '');
    $removeUrl = $baseUrl . '/api/waitlist.php?token=' . urlencode($entry['token']) . '&action=remove';

    $firstName = htmlspecialchars($entry['first_name'], ENT_QUOTES);
    $date      = date('j. n. Y', strtotime($entry['date']));
    $restNameE = htmlspecialchars($restName, ENT_QUOTES);

    $body = "
        <h2 style='margin:0 0 16px;font-size:22px;font-weight:700;color:#111827'>Vpisani ste na čakalno listo</h2>
        <p style='margin:0 0 8px;color:#374151;font-size:15px'>Pozdravljeni, <strong>{$firstName}</strong>!</p>
        <p style='margin:0 0 20px;color:#374151;font-size:15px'>
            Uspešno ste se vpisali na čakalno listo restavracije <strong>{$restNameE}</strong> za datum <strong>{$date}</strong>.
        </p>
        <p style='margin:0 0 20px;color:#6B7280;font-size:14px'>
            Ko se sprosti termin, vas bomo obvestili po emailu. Imel/a boste 2 uri časa za potrditev rezervacije.
        </p>
        <p style='text-align:center;margin:20px 0 0'>
            <a href='" . htmlspecialchars($removeUrl, ENT_QUOTES) . "'
               style='color:#9CA3AF;font-size:13px;text-decoration:none'>
                Odjavim se s čakalne liste
            </a>
        </p>
    ";

    $html = email_wrap($appName, $body, "To sporočilo ste prejeli, ker ste se vpisali na čakalno listo restavracije {$restNameE}.");
    send_email($entry['email'], "Vpisani ste na čakalno listo – {$restNameE}", $html);
}
