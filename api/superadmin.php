<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_superadmin();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

// ─── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = get_body();
    $action = $body['action'] ?? '';

    // Ročno dodeljevanje paketa
    if ($action === 'assign_plan') {
        $userId   = isset($body['user_id'])   ? (int)$body['user_id']   : 0;
        $planSlug = $body['plan_slug'] ?? '';
        $endsAt   = $body['ends_at']   ?? null; // null = trajno

        if (!$userId) json_response(false, null, 'user_id je obvezen.', 400);
        if (!array_key_exists($planSlug, PLANS)) json_response(false, null, 'Neveljaven paket.', 400);
        if ($endsAt && !preg_match('/^\d{4}-\d{2}-\d{2}/', $endsAt)) {
            json_response(false, null, 'Neveljaven datum poteka.', 400);
        }

        // Preveri da user obstaja in je admin
        $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin'");
        $chk->execute([$userId]);
        if (!$chk->fetchColumn()) json_response(false, null, 'Admin ne obstaja.', 404);

        try {
            // Deaktiviraj obstoječe aktivne naročnine
            $pdo->prepare("UPDATE subscriptions SET status = 'canceled' WHERE user_id = ? AND status IN ('trial','active')")
                ->execute([$userId]);

            // Vstavi novo
            $status = $planSlug === 'trial' ? 'trial' : 'active';
            $pdo->prepare("
                INSERT INTO subscriptions (user_id, plan_slug, status, ends_at, assigned_by)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$userId, $planSlug, $status, $endsAt ?: null, $session['user_id']]);

            // Posodobi users.subscription_status za kompatibilnost
            $pdo->prepare("UPDATE users SET subscription_status = ? WHERE id = ?")
                ->execute([$status, $userId]);

            json_response(true, null, 'Paket dodeljen.');
        } catch (PDOException $e) {
            error_log('assign_plan error: ' . $e->getMessage());
            json_response(false, null, 'Napaka pri dodeljevanju.', 500);
        }
    }

    // Ustvari popust
    if ($action === 'create_discount') {
        $planSlug    = $body['plan_slug'] ?? '';
        $label       = trim($body['label'] ?? '');
        $discMonthly = isset($body['discounted_monthly']) && $body['discounted_monthly'] !== '' ? (float)$body['discounted_monthly'] : null;
        $discYearly  = isset($body['discounted_yearly'])  && $body['discounted_yearly']  !== '' ? (float)$body['discounted_yearly']  : null;
        $validFrom   = $body['valid_from']  ?? null;
        $validUntil  = $body['valid_until'] ?? null;

        if (!array_key_exists($planSlug, PLANS) || $planSlug === 'trial') {
            json_response(false, null, 'Neveljaven paket.', 400);
        }
        if (!$label) json_response(false, null, 'Opis je obvezen.', 400);
        if (!$validFrom || !preg_match('/^\d{4}-\d{2}-\d{2}/', $validFrom)) {
            json_response(false, null, 'Datum začetka je obvezen.', 400);
        }
        if (!$validUntil || !preg_match('/^\d{4}-\d{2}-\d{2}/', $validUntil)) {
            json_response(false, null, 'Datum konca je obvezen.', 400);
        }
        if ($discMonthly === null && $discYearly === null) {
            json_response(false, null, 'Vsaj ena znižana cena je obvezna.', 400);
        }

        try {
            $pdo->prepare("
                INSERT INTO plan_discounts (plan_slug, label, discounted_monthly, discounted_yearly, valid_from, valid_until, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ")->execute([$planSlug, $label, $discMonthly, $discYearly, $validFrom, $validUntil]);
            json_response(true, null, 'Popust ustvarjen.');
        } catch (PDOException $e) {
            error_log('create_discount error: ' . $e->getMessage());
            json_response(false, null, 'Napaka pri shranjevanju.', 500);
        }
    }

    // Izbriši popust
    if ($action === 'delete_discount') {
        $id = isset($body['id']) ? (int)$body['id'] : 0;
        if (!$id) json_response(false, null, 'id je obvezen.', 400);
        $pdo->prepare("DELETE FROM plan_discounts WHERE id = ?")->execute([$id]);
        json_response(true, null, 'Popust izbrisan.');
    }

    // Testni email — VSI tipi (pred objavo končni pregled designa)
    require_once '../includes/mailer.php';
    $body  = get_body();
    $to    = trim($body['email'] ?? '');
    $type  = $body['type'] ?? 'verification';
    $lang  = $body['lang'] ?? 'sl';
    if (!in_array($lang, ['sl','en','de','it','fr','hr','es','pt'], true)) $lang = 'sl';

    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        json_response(false, null, 'Vnesite veljaven email.', 400);
    }

    $name      = $session['full_name'] ?? 'Superadmin';
    $tomorrow  = time() + 86400;
    $in3Days   = time() + 86400 * 3;
    $in7Days   = time() + 86400 * 7;
    $testToken = 'TEST_TOKEN_' . bin2hex(random_bytes(8));
    $resName   = 'Gostilna Pri Lipi';
    $guestName = 'Janez Novak';
    $date      = date('Y-m-d', $tomorrow);
    $time      = '19:30';
    $duration  = 90;
    $guests    = 4;
    $contactE  = 'info@lipa.si';
    $contactP  = '+386 1 234 5678';
    $resAddress = 'Tržaška cesta 25, 1000 Ljubljana';

    // Mapiranje: tip → callable
    $senders = [
        'verification' => function() use ($to, $name, $testToken, $lang) {
            return send_verification_email($to, $name, $testToken, $lang);
        },
        'reset' => function() use ($to, $name, $testToken, $lang) {
            return send_password_reset_email($to, $name, $testToken, $lang);
        },
        'email_change' => function() use ($to, $name, $testToken, $lang) {
            return send_email_change_email($to, $name, $testToken, $lang);
        },
        'payment_failed' => function() use ($to, $name, $in3Days, $lang) {
            return send_payment_failed_email($to, $name, 'Advanced', PLAN_PRICES['advanced']['monthly'], 2, $in3Days, $lang);
        },
        'upcoming_invoice' => function() use ($to, $name, $in7Days, $lang) {
            return send_upcoming_invoice_email($to, $name, 'Advanced', PLAN_PRICES['advanced']['yearly'], $in7Days, $lang);
        },
        'plan_changed' => function() use ($to, $name, $lang) {
            return send_plan_changed_email($to, $name, 'Premium', 'monthly', PLAN_PRICES['premium']['monthly'], $lang);
        },
        'invoice_request' => function() use ($to, $lang) {
            // Ta email gre superadminu kot obvestilo, da je admin zahteval predračun
            return send_invoice_request_email($to, 'Janez Novak', 'admin@example.com', 'advanced', PLAN_PRICES['advanced']['yearly'], $lang);
        },
        'booking_pending_guest' => function() use ($to, $guestName, $resName, $date, $time, $guests, $contactE, $contactP, $lang, $resAddress) {
            return send_booking_pending_guest($to, $guestName, $resName, $date, $time, $guests, 'EDIT_TEST_TOKEN', $contactE, $contactP, $lang, $resAddress);
        },
        'booking_confirmed_guest' => function() use ($to, $guestName, $resName, $date, $time, $guests, $duration, $contactE, $contactP, $lang, $resAddress) {
            return send_booking_confirmed_guest($to, $guestName, $resName, $date, $time, $guests, $duration, 'EDIT_TEST_TOKEN', $contactE, $contactP, $lang, $resAddress);
        },
        'booking_rejected_guest' => function() use ($to, $guestName, $resName, $date, $time, $guests, $contactE, $contactP, $lang, $resAddress) {
            return send_booking_rejected_guest($to, $guestName, $resName, $date, $time, $guests, $contactE, $contactP, $lang, $resAddress);
        },
        'booking_reminder_guest' => function() use ($to, $guestName, $resName, $date, $time, $guests, $duration, $contactE, $contactP, $lang, $resAddress) {
            return send_booking_reminder_guest($to, $guestName, $resName, $date, $time, $guests, $duration, $contactE, $contactP, $lang, $resAddress);
        },
        'booking_notify_admin' => function() use ($to, $name, $resName, $guestName, $date, $time, $guests, $lang) {
            return send_booking_notify_admin($to, $name, $resName, $guestName, 'gost@example.com', $date, $time, $guests, 'pending', 12345, $lang);
        },
        'gdpr' => function() use ($to, $lang) {
            return send_gdpr_confirmation($to, 'data_export', $lang);
        },
        'affiliate_verify' => function() use ($to, $name, $testToken, $lang) {
            return send_affiliate_verify_email($to, $name, $testToken, $lang);
        },
        'affiliate_approved' => function() use ($to, $name, $lang) {
            return send_affiliate_approved_email($to, $name, 'TESTREZBLE10', $lang);
        },
        'affiliate_rejected' => function() use ($to, $name, $lang) {
            return send_affiliate_rejected_email($to, $name, 'Aplikacija ni izpolnjevala minimalnih pogojev za partnerski program.', $lang);
        },
        'affiliate_payout' => function() use ($to, $name, $lang) {
            return send_affiliate_payout_email($to, $name, 142.50, 'PAY-2026-0042', $lang);
        },
        'affiliate_discount_granted' => function() use ($to, $name, $lang) {
            return send_affiliate_discount_granted_email($to, $name, 'TESTREZBLE10', 10.0, $lang);
        },
        'blog_subscribe' => function() use ($to, $lang) {
            $appName = APP_NAME;
            $confirmUrl = APP_URL . BASE_PATH . '/api/blog_subscribe.php?action=confirm&t=' . urlencode('TEST_TOKEN_BLOG_' . bin2hex(random_bytes(8)));
            $body = email_h(_email_t('email.blog_subscribe.heading', $lang))
                . email_p(_email_t('email.blog_subscribe.intro', $lang))
                . email_button(_email_t('email.blog_subscribe.button', $lang), $confirmUrl)
                . email_p(_email_t('email.blog_subscribe.note', $lang), true);
            $html = email_wrap($appName, $body, '', ['type' => 'booked']);
            return send_email($to, _email_t('email.blog_subscribe.subject', $lang), $html);
        },
    ];

    // BATCH "all" → zaporedno pošlji vse
    if ($type === 'all') {
        $sent = 0; $failed = [];
        foreach ($senders as $key => $fn) {
            try {
                if ($fn()) $sent++;
                else $failed[] = $key;
            } catch (Throwable $e) {
                $failed[] = $key . ' (' . $e->getMessage() . ')';
            }
            usleep(150000); // 150ms throttle, da Mailgun ni reroute
        }
        if (empty($failed)) {
            json_response(true, null, 'Vseh ' . $sent . ' email tipov poslanih na ' . $to);
        }
        json_response(false, null, 'Poslanih: ' . $sent . '. Spodletelo: ' . implode(', ', $failed), 500);
    }

    // Posamezen tip
    if (!isset($senders[$type])) {
        json_response(false, null, 'Neznan tip: ' . $type, 400);
    }
    try {
        $ok = $senders[$type]();
    } catch (Throwable $e) {
        error_log('test mail [' . $type . ']: ' . $e->getMessage());
        json_response(false, null, 'Napaka: ' . $e->getMessage(), 500);
    }

    if ($ok) {
        json_response(true, null, 'Email "' . $type . '" poslan na ' . $to);
    } else {
        json_response(false, null, 'Pošiljanje ni uspelo. Preverite Mailgun nastavitve.', 500);
    }
}

if ($method !== 'GET') {
    json_response(false, null, 'Metoda ni podprta.', 405);
}

$action = $_GET['action'] ?? '';

// ─── Vsi admini z restavracijami ───────────────────────────────
if ($action === 'admins') {
    $stmt = $pdo->query("
        SELECT u.id, u.email, u.full_name, u.is_active,
               u.trial_ends_at, u.created_at,
               COUNT(ra.restaurant_id) AS restaurant_count,
               COALESCE(s.plan_slug, 'trial') AS plan_slug,
               COALESCE(s.status, u.subscription_status) AS subscription_status
        FROM users u
        LEFT JOIN restaurant_admins ra ON u.id = ra.user_id
        LEFT JOIN subscriptions s ON s.user_id = u.id
                                  AND s.status IN ('trial','active','pending_invoice','payment_failed')
                                  AND (s.ends_at IS NULL OR s.ends_at > NOW())
        WHERE u.role = 'admin'
        GROUP BY u.id
        ORDER BY u.created_at DESC
    ");
    json_response(true, $stmt->fetchAll());
}

// ─── Vse restavracije z lastnikom ──────────────────────────────
if ($action === 'restaurants') {
    $stmt = $pdo->query("
        SELECT r.id, r.name, r.is_active, r.created_at,
               u.full_name AS owner_name, u.email AS owner_email,
               COUNT(res.id) AS reservation_count
        FROM restaurants r
        JOIN users u ON r.owner_id = u.id
        LEFT JOIN reservations res ON r.id = res.restaurant_id
        GROUP BY r.id
        ORDER BY r.created_at DESC
    ");
    json_response(true, $stmt->fetchAll());
}

// ─── Rezervacije z filtrom ─────────────────────────────────────
if ($action === 'reservations') {
    $date  = $_GET['date']  ?? null;
    $month = $_GET['month'] ?? null;
    $restId = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : null;

    if ($date) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            json_response(false, null, 'Neveljaven datum.', 400);
        }
        $sql = "
            SELECT r.*, res.name AS restaurant_name, res.color AS restaurant_color,
                   COALESCE(r.duration, res.reservation_duration) AS reservation_duration,
                   u.full_name AS owner_name
            FROM reservations r
            JOIN restaurants res ON r.restaurant_id = res.id
            JOIN users u ON res.owner_id = u.id
            WHERE r.reservation_date = ?
        ";
        $params = [$date];
        if ($restId) { $sql .= " AND r.restaurant_id = ?"; $params[] = $restId; }
        $sql .= " ORDER BY r.reservation_time, res.name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_response(true, $stmt->fetchAll());
    }

    if ($month) {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            json_response(false, null, 'Neveljaven mesec.', 400);
        }
        $from = $month . '-01';
        $to   = date('Y-m-t', strtotime($from));
        $sql = "
            SELECT r.reservation_date,
                   COUNT(*) AS reservation_count,
                   SUM(r.guest_count) AS total_guests
            FROM reservations r
            WHERE r.reservation_date BETWEEN ? AND ?
        ";
        $params = [$from, $to];
        if ($restId) { $sql .= " AND r.restaurant_id = ?"; $params[] = $restId; }
        $sql .= " GROUP BY r.reservation_date";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = [];
        foreach ($stmt->fetchAll() as $row) {
            $data[$row['reservation_date']] = [
                'count'  => (int)$row['reservation_count'],
                'guests' => (int)$row['total_guests'],
            ];
        }
        json_response(true, $data);
    }

    json_response(false, null, 'Potreben parameter date ali month.', 400);
}

// ─── Statistika (dashboard overview) ──────────────────────────
if ($action === 'stats') {
    $admins      = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    $restaurants = $pdo->query("SELECT COUNT(*) FROM restaurants WHERE is_active = 1")->fetchColumn();
    $users       = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user' AND is_active = 1")->fetchColumn();
    $resTodayStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE reservation_date = ?");
    $resTodayStmt->execute([date('Y-m-d')]);
    $today_reservations = $resTodayStmt->fetchColumn();
    $trials = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND subscription_status='trial'")->fetchColumn();

    json_response(true, [
        'admins'             => (int)$admins,
        'restaurants'        => (int)$restaurants,
        'users'              => (int)$users,
        'today_reservations' => (int)$today_reservations,
        'trials'             => (int)$trials,
    ]);
}

// ─── Vsi popusti ──────────────────────────────────────────────
if ($action === 'discounts') {
    $stmt = $pdo->query("SELECT * FROM plan_discounts ORDER BY valid_until DESC");
    json_response(true, $stmt->fetchAll());
}

// ─── Naročnina admina ─────────────────────────────────────────
if ($action === 'subscription') {
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    if (!$userId) json_response(false, null, 'user_id je obvezen.', 400);
    $sub = get_active_subscription($pdo, $userId);
    json_response(true, $sub ?: ['plan_slug' => 'brez', 'status' => 'expired']);
}

json_response(false, null, 'Neznan action parameter.', 400);
