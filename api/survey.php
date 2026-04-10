<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/survey_helper.php';

header('Content-Type: application/json; charset=utf-8');

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = trim($_GET['action'] ?? '');

// ─── Javni endpoint: oddaj anketo ─────────────────────────────────────────────
if ($method === 'POST' && $action === 'submit_response') {
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = trim($body['token'] ?? '');
    if (!$token) json_response(false, null, 'Token manjka.', 400);

    $stmt = $pdo->prepare("SELECT sr.*, sf.id AS form_id FROM survey_responses sr JOIN survey_forms sf ON sr.survey_id = sf.id WHERE sr.token = ?");
    $stmt->execute([$token]);
    $response = $stmt->fetch();
    if (!$response) json_response(false, null, 'Neveljavna povezava.', 404);
    if ($response['submitted_at']) json_response(false, null, 'Anketa je bila že izpolnjena.', 409);

    $consent = $body['consent'] ?? '';
    if (!in_array($consent, ['public', 'anonymous', 'private'], true)) {
        json_response(false, null, 'Soglasje je obvezno.', 400);
    }

    // Naloži vprašanja (za validacijo obveznih)
    $stmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order");
    $stmt->execute([$response['survey_id']]);
    $questions = $stmt->fetchAll();

    $answers = $body['answers'] ?? [];

    // Preveri obvezna vprašanja
    foreach ($questions as $q) {
        if (!$q['is_required']) continue;
        $ans = $answers[$q['id']] ?? null;
        if ($ans === null || $ans === '' || $ans === []) {
            json_response(false, null, 'Prosimo, odgovorite na vsa obvezna vprašanja.', 400);
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE survey_responses SET consent = ?, submitted_at = NOW() WHERE id = ?")
            ->execute([$consent, $response['id']]);

        $aStmt = $pdo->prepare("INSERT INTO survey_answers (response_id, question_id, answer_text, option_ids) VALUES (?,?,?,?)");
        foreach ($questions as $q) {
            $ans = $answers[$q['id']] ?? null;
            if ($ans === null || $ans === '') continue;

            $answerText = null;
            $optionIds  = null;

            if (in_array($q['type'], ['text', 'textarea', 'rating'])) {
                $answerText = (string) $ans;
            } elseif (in_array($q['type'], ['radio', 'checkbox'])) {
                $optionIds = is_array($ans) ? json_encode($ans) : json_encode([$ans]);
            }
            $aStmt->execute([$response['id'], $q['id'], $answerText, $optionIds]);
        }
        $pdo->commit();
        json_response(true, null);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Survey submit error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju.', 500);
    }
}

// ─── Javni endpoint: pridobi anketo po tokenu ─────────────────────────────────
if ($method === 'GET' && $action === 'get_by_token') {
    $token = trim($_GET['token'] ?? '');
    if (!$token) json_response(false, null, 'Token manjka.', 400);

    $stmt = $pdo->prepare("
        SELECT sr.*, sf.title, sf.description, r.name AS restaurant_name
        FROM survey_responses sr
        JOIN survey_forms sf ON sr.survey_id = sf.id
        JOIN restaurants r  ON sf.restaurant_id = r.id
        WHERE sr.token = ?
    ");
    $stmt->execute([$token]);
    $response = $stmt->fetch();
    if (!$response) json_response(false, null, 'Neveljavna povezava.', 404);
    if ($response['submitted_at']) json_response(false, ['already_submitted' => true], '');

    $stmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order, id");
    $stmt->execute([$response['survey_id']]);
    $questions = $stmt->fetchAll();

    if ($questions) {
        $qids = array_column($questions, 'id');
        $placeholders = implode(',', array_fill(0, count($qids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM survey_question_options WHERE question_id IN ($placeholders) ORDER BY sort_order");
        $stmt->execute($qids);
        $optMap = [];
        foreach ($stmt->fetchAll() as $o) $optMap[$o['question_id']][] = $o;
        foreach ($questions as &$q) $q['options'] = $optMap[$q['id']] ?? [];
    }

    json_response(true, [
        'restaurant_name' => $response['restaurant_name'],
        'title'           => $response['title'],
        'description'     => $response['description'],
        'questions'       => $questions,
    ]);
}

// ─── Admin-only endpoints ──────────────────────────────────────────────────────
require_once '../includes/auth_check.php';
require_once '../includes/plans.php';

$session = require_admin();

// ─── GET get_form ──────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'get_form') {
    require_feature($pdo, $session, 'survey');
    $restaurant_id = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
    if (!$restaurant_id) json_response(false, null, 'restaurant_id manjka.', 400);
    if (!admin_owns_restaurant($pdo, $session, $restaurant_id))
        json_response(false, null, 'Dostop zavrnjen.', 403);

    // Samodejno posej privzeto anketo če še ne obstaja
    $form = load_survey($pdo, $restaurant_id);
    if (!$form) {
        seed_default_survey($pdo, $restaurant_id);
        $form = load_survey($pdo, $restaurant_id);
    }
    json_response(true, $form);
}

// ─── POST save_form ────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'save_form') {
    require_feature($pdo, $session, 'survey_edit');
    $body          = json_decode(file_get_contents('php://input'), true) ?? [];
    $restaurant_id = (int)($body['restaurant_id'] ?? 0);
    if (!$restaurant_id) json_response(false, null, 'restaurant_id manjka.', 400);
    if (!admin_owns_restaurant($pdo, $session, $restaurant_id))
        json_response(false, null, 'Dostop zavrnjen.', 403);

    $title          = trim($body['title'] ?? 'Anketa o zadovoljstvu');
    $description    = trim($body['description'] ?? '');
    $thank_you      = trim($body['thank_you_message'] ?? '');
    $send_enabled   = (int)(bool)($body['send_enabled']   ?? 0);
    $send_delay     = max(0, (int)($body['send_delay_hours'] ?? 2));
    $incl_thankyou  = (int)(bool)($body['include_thankyou'] ?? 1);
    $incl_survey    = (int)(bool)($body['include_survey']   ?? 1);
    $questions      = $body['questions'] ?? [];

    $pdo->beginTransaction();
    try {
        // Ustvari ali posodobi anketo
        $existing = $pdo->prepare("SELECT id FROM survey_forms WHERE restaurant_id = ? LIMIT 1");
        $existing->execute([$restaurant_id]);
        $survey_id = $existing->fetchColumn();

        if ($survey_id) {
            $pdo->prepare("
                UPDATE survey_forms SET title=?, description=?, thank_you_message=?,
                send_enabled=?, send_delay_hours=?, include_thankyou=?, include_survey=?
                WHERE id=?
            ")->execute([$title, $description, $thank_you, $send_enabled, $send_delay, $incl_thankyou, $incl_survey, $survey_id]);
        } else {
            $pdo->prepare("
                INSERT INTO survey_forms (restaurant_id, title, description, thank_you_message,
                send_enabled, send_delay_hours, include_thankyou, include_survey)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([$restaurant_id, $title, $description, $thank_you, $send_enabled, $send_delay, $incl_thankyou, $incl_survey]);
            $survey_id = (int)$pdo->lastInsertId();
        }

        // Shrani vprašanja (briši vse in vstavi znova)
        $pdo->prepare("DELETE FROM survey_questions WHERE survey_id = ?")->execute([$survey_id]);

        $qStmt = $pdo->prepare("
            INSERT INTO survey_questions (survey_id, sort_order, question_text, type, is_required)
            VALUES (?,?,?,?,?)
        ");
        $oStmt = $pdo->prepare("
            INSERT INTO survey_question_options (question_id, sort_order, label) VALUES (?,?,?)
        ");

        foreach ($questions as $i => $q) {
            $type = in_array($q['type'] ?? '', ['checkbox','rating','radio','text','textarea'])
                ? $q['type'] : 'text';
            $qStmt->execute([
                $survey_id,
                $i + 1,
                trim($q['question_text'] ?? ''),
                $type,
                (int)(bool)($q['is_required'] ?? 0),
            ]);
            $question_id = (int)$pdo->lastInsertId();

            if (in_array($type, ['radio', 'checkbox'])) {
                foreach ($q['options'] ?? [] as $j => $opt) {
                    $label = trim(is_array($opt) ? ($opt['label'] ?? '') : $opt);
                    if ($label !== '') $oStmt->execute([$question_id, $j + 1, $label]);
                }
            }
        }

        $pdo->commit();
        json_response(true, ['survey_id' => $survey_id]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Survey save_form error: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju.', 500);
    }
}

// ─── GET get_results ──────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'get_results') {
    require_feature($pdo, $session, 'survey');
    $restaurant_id = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
    if (!$restaurant_id) json_response(false, null, 'restaurant_id manjka.', 400);
    if (!admin_owns_restaurant($pdo, $session, $restaurant_id))
        json_response(false, null, 'Dostop zavrnjen.', 403);

    $date_from = $_GET['date_from'] ?? null;
    $date_to   = $_GET['date_to']   ?? null;
    $consent_f = $_GET['consent']   ?? null;

    $where  = ["sf.restaurant_id = ?"];
    $params = [$restaurant_id];

    if ($date_from) { $where[] = "DATE(sr.submitted_at) >= ?"; $params[] = $date_from; }
    if ($date_to)   { $where[] = "DATE(sr.submitted_at) <= ?"; $params[] = $date_to;   }
    if ($consent_f && in_array($consent_f, ['public','anonymous','private']))
        { $where[] = "sr.consent = ?"; $params[] = $consent_f; }

    $sql = "
        SELECT sr.id, sr.token, sr.email, sr.consent, sr.submitted_at,
               sr.email_sent_at, sr.scheduled_send_at,
               res.guest_name, res.reservation_date, res.reservation_time
        FROM survey_responses sr
        JOIN survey_forms sf  ON sr.survey_id = sf.id
        JOIN reservations res ON sr.reservation_id = res.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY sr.created_at DESC
        LIMIT 500
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    json_response(true, $rows);
}

// ─── GET get_response_detail ───────────────────────────────────────────────────
if ($method === 'GET' && $action === 'get_response_detail') {
    require_feature($pdo, $session, 'survey');
    $response_id = (int)($_GET['id'] ?? 0);
    if (!$response_id) json_response(false, null, 'ID manjka.', 400);

    // Preveri dostop
    $stmt = $pdo->prepare("
        SELECT sr.*, sf.restaurant_id FROM survey_responses sr
        JOIN survey_forms sf ON sr.survey_id = sf.id
        WHERE sr.id = ?
    ");
    $stmt->execute([$response_id]);
    $sr = $stmt->fetch();
    if (!$sr) json_response(false, null, 'Ni najdeno.', 404);
    if (!admin_owns_restaurant($pdo, $session, $sr['restaurant_id']))
        json_response(false, null, 'Dostop zavrnjen.', 403);

    $stmt = $pdo->prepare("
        SELECT sa.*, sq.question_text, sq.type
        FROM survey_answers sa
        JOIN survey_questions sq ON sa.question_id = sq.id
        WHERE sa.response_id = ?
        ORDER BY sq.sort_order
    ");
    $stmt->execute([$response_id]);
    $answers = $stmt->fetchAll();

    // Za radio/checkbox nadomesti option_ids z labels
    foreach ($answers as &$a) {
        if ($a['option_ids'] && in_array($a['type'], ['radio','checkbox'])) {
            $ids = json_decode($a['option_ids'], true) ?: [];
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt2 = $pdo->prepare("SELECT label FROM survey_question_options WHERE id IN ($placeholders) ORDER BY sort_order");
                $stmt2->execute($ids);
                $a['option_labels'] = $stmt2->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $a['option_labels'] = [];
            }
        }
    }

    json_response(true, ['response' => $sr, 'answers' => $answers]);
}

// ─── GET export_csv (samo Premium) ────────────────────────────────────────────
if ($method === 'GET' && $action === 'export_csv') {
    require_feature($pdo, $session, 'survey_export');
    $restaurant_id = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
    if (!$restaurant_id) json_response(false, null, 'restaurant_id manjka.', 400);
    if (!admin_owns_restaurant($pdo, $session, $restaurant_id))
        json_response(false, null, 'Dostop zavrnjen.', 403);

    $date_from = $_GET['date_from'] ?? null;
    $date_to   = $_GET['date_to']   ?? null;

    // Pridobi survey_id
    $stmt = $pdo->prepare("SELECT id FROM survey_forms WHERE restaurant_id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$restaurant_id]);
    $survey_id = $stmt->fetchColumn();
    if (!$survey_id) json_response(false, null, 'Anketa ne obstaja.', 404);

    // Vprašanja za glave stolpcev
    $stmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order");
    $stmt->execute([$survey_id]);
    $questions = $stmt->fetchAll();

    // Odgovori
    $where  = ["sr.survey_id = ?", "sr.submitted_at IS NOT NULL"];
    $params = [$survey_id];
    if ($date_from) { $where[] = "DATE(sr.submitted_at) >= ?"; $params[] = $date_from; }
    if ($date_to)   { $where[] = "DATE(sr.submitted_at) <= ?"; $params[] = $date_to;   }

    $stmt = $pdo->prepare("
        SELECT sr.id, sr.email, sr.consent, sr.submitted_at, res.guest_name
        FROM survey_responses sr
        JOIN reservations res ON sr.reservation_id = res.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY sr.submitted_at DESC
    ");
    $stmt->execute($params);
    $responses = $stmt->fetchAll();

    if (!$responses) json_response(true, ['message' => 'Ni odgovorov za izvoz.']);

    // Pridobi vse odgovore naenkrat
    $resp_ids = array_column($responses, 'id');
    $placeholders = implode(',', array_fill(0, count($resp_ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM survey_answers WHERE response_id IN ($placeholders)");
    $stmt->execute($resp_ids);
    $allAnswers = $stmt->fetchAll();

    // Zapolni option labels
    $allOptIds = [];
    foreach ($allAnswers as $a) {
        if ($a['option_ids']) {
            $ids = json_decode($a['option_ids'], true) ?: [];
            foreach ($ids as $oid) $allOptIds[] = (int)$oid;
        }
    }
    $optLabels = [];
    if ($allOptIds) {
        $placeholders2 = implode(',', array_fill(0, count($allOptIds), '?'));
        $stmt = $pdo->prepare("SELECT id, label FROM survey_question_options WHERE id IN ($placeholders2)");
        $stmt->execute($allOptIds);
        foreach ($stmt->fetchAll() as $o) $optLabels[$o['id']] = $o['label'];
    }

    // Grupiranje odgovorov po response_id > question_id
    $ansMap = [];
    foreach ($allAnswers as $a) {
        $ansMap[$a['response_id']][$a['question_id']] = $a;
    }

    // CSV izhod
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="anketa_' . $restaurant_id . '_' . date('Ymd') . '.csv"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM za Excel

    // Glava
    $header = ['Datum oddaje', 'Ime gosta', 'Email', 'Soglasje'];
    foreach ($questions as $q) $header[] = $q['question_text'];
    fputcsv($out, $header, ';');

    foreach ($responses as $resp) {
        $email = $resp['consent'] === 'anonymous' ? '(anonimno)' : $resp['email'];
        $row = [
            $resp['submitted_at'] ? date('d.m.Y H:i', strtotime($resp['submitted_at'])) : '',
            $resp['guest_name'],
            $email,
            ['public' => 'Javno', 'anonymous' => 'Anonimno', 'private' => 'Zasebno'][$resp['consent']] ?? '',
        ];
        foreach ($questions as $q) {
            $a = $ansMap[$resp['id']][$q['id']] ?? null;
            if (!$a) { $row[] = ''; continue; }
            if (in_array($q['type'], ['radio','checkbox']) && $a['option_ids']) {
                $ids = json_decode($a['option_ids'], true) ?: [];
                $labels = array_map(fn($id) => $optLabels[$id] ?? '', $ids);
                $row[] = implode(', ', $labels);
            } else {
                $row[] = $a['answer_text'] ?? '';
            }
        }
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

// ─── POST send_now ─────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'send_now') {
    require_feature($pdo, $session, 'survey');
    require_once '../includes/survey_mailer.php';
    $body          = json_decode(file_get_contents('php://input'), true) ?? [];
    $reservation_id = (int)($body['reservation_id'] ?? 0);
    $force          = (bool)($body['force'] ?? false);
    if (!$reservation_id) json_response(false, null, 'reservation_id manjka.', 400);

    // Preveri dostop
    $stmt = $pdo->prepare("SELECT r.*, res.name AS restaurant_name FROM reservations r JOIN restaurants res ON r.restaurant_id = res.id WHERE r.id = ?");
    $stmt->execute([$reservation_id]);
    $res = $stmt->fetch();
    if (!$res) json_response(false, null, 'Rezervacija ne obstaja.', 404);
    if (!admin_owns_restaurant($pdo, $session, $res['restaurant_id']))
        json_response(false, null, 'Dostop zavrnjen.', 403);

    // Preveri da je gost označen kot prišel
    if (empty($res['arrived_at']))
        json_response(false, null, 'Gost še ni označen kot prišel.', 400);
    if (empty($res['email']))
        json_response(false, null, 'Gost nima emaila.', 400);

    $stmt = $pdo->prepare("SELECT * FROM survey_responses WHERE reservation_id = ? LIMIT 1");
    $stmt->execute([$reservation_id]);
    $sr = $stmt->fetch();

    // Če zapisa še ni (send_enabled = 0), ga ustvari zdaj
    if (!$sr) {
        $stmt = $pdo->prepare("SELECT * FROM survey_forms WHERE restaurant_id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$res['restaurant_id']]);
        $form = $stmt->fetch();
        if (!$form) json_response(false, null, 'Anketa za to restavracijo ne obstaja.', 404);

        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO survey_responses (survey_id, reservation_id, email, token, scheduled_send_at) VALUES (?,?,?,?,NOW())")
            ->execute([$form['id'], $reservation_id, $res['email'], $token]);
        $srId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT * FROM survey_responses WHERE id = ?");
        $stmt->execute([$srId]);
        $sr = $stmt->fetch();
    }

    if ($sr['email_sent_at'] && !$force) {
        json_response(false, ['already_sent' => true, 'sent_at' => $sr['email_sent_at']], 'Email je bil že poslan.');
    }

    $ok = send_survey_email($pdo, $sr);
    if ($ok) {
        json_response(true, null);
    } else {
        json_response(false, null, 'Pošiljanje emaila ni uspelo.', 500);
    }
}

json_response(false, null, 'Neznana akcija.', 400);
