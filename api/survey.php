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
        SELECT sr.*, sf.restaurant_id, r.name AS restaurant_name
        FROM survey_responses sr
        JOIN survey_forms sf ON sr.survey_id = sf.id
        JOIN restaurants r  ON sf.restaurant_id = r.id
        WHERE sr.token = ?
    ");
    $stmt->execute([$token]);
    $response = $stmt->fetch();
    if (!$response) json_response(false, null, 'Neveljavna povezava.', 404);
    if ($response['submitted_at']) json_response(false, ['already_submitted' => true], '');

    // Naloži anketo v jeziku, v katerem je bila poslana gostu
    $surveyLang = $response['survey_language'] ?? 'sl';
    $form = load_survey($pdo, (int)$response['restaurant_id'], $surveyLang);
    if (!$form) json_response(false, null, 'Anketa ne obstaja.', 404);

    json_response(true, [
        'restaurant_name' => $response['restaurant_name'],
        'title'           => $form['title'],
        'description'     => $form['description'],
        'questions'       => array_map(function($q) {
            // Stripe out admin-only fields
            unset($q['translations'], $q['_master_text']);
            $q['options'] = array_map(function($o) {
                unset($o['translations'], $o['_master_label']);
                return $o;
            }, $q['options'] ?? []);
            return $q;
        }, $form['questions'] ?? []),
        'lang' => $surveyLang,
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
    }
    // Backfill prevodov za default vsebino (idempotent, ne dotika se admin edit-a)
    try { backfill_default_survey_translations($pdo, $restaurant_id); } catch (Throwable $e) {}

    // Naloži v primary jeziku restavracije, da admin vidi vsebino v "svojem" jeziku.
    // Master (SL) ostane v _master_* poljih za fallback.
    $primary = 'sl';
    try {
        $pStmt = $pdo->prepare("SELECT booking_primary_language FROM restaurants WHERE id=?");
        $pStmt->execute([$restaurant_id]);
        $p = $pStmt->fetchColumn();
        if ($p && in_array($p, SURVEY_ALLOWED_LANGS, true)) $primary = $p;
    } catch (PDOException $e) {}

    $form = load_survey($pdo, $restaurant_id, $primary);
    if (is_array($form)) $form['_primary_lang'] = $primary;
    json_response(true, $form);
}

// ─── POST save_form ────────────────────────────────────────────────────────────
// Ohrani question/option ID-je (potreben za _translations cascade), izbriši samo tiste,
// ki niso več prisotni. To prepreči izgubo prevodov pri vsaki shranitvi.
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

    // Določi primary jezik restavracije — admin tipka v primary, zato shranimo
    // tudi v <kind>_translations[primary], da se prevod ne zgubi pri SL fallbacku.
    $primaryLang = 'sl';
    try {
        $pStmt = $pdo->prepare("SELECT booking_primary_language FROM restaurants WHERE id=?");
        $pStmt->execute([$restaurant_id]);
        $p = $pStmt->fetchColumn();
        if ($p && in_array($p, SURVEY_ALLOWED_LANGS, true)) $primaryLang = $p;
    } catch (PDOException $e) {}

    $pdo->beginTransaction();
    try {
        // Ustvari ali posodobi anketo
        $existing = $pdo->prepare("SELECT id FROM survey_forms WHERE restaurant_id = ? LIMIT 1");
        $existing->execute([$restaurant_id]);
        $survey_id = (int)$existing->fetchColumn();

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

        $qInsert = $pdo->prepare("
            INSERT INTO survey_questions (survey_id, sort_order, question_text, type, is_required)
            VALUES (?,?,?,?,?)
        ");
        $qUpdate = $pdo->prepare("
            UPDATE survey_questions SET sort_order=?, question_text=?, type=?, is_required=? WHERE id=? AND survey_id=?
        ");
        $oInsert = $pdo->prepare("
            INSERT INTO survey_question_options (question_id, sort_order, label) VALUES (?,?,?)
        ");
        $oUpdate = $pdo->prepare("
            UPDATE survey_question_options SET sort_order=?, label=? WHERE id=? AND question_id=?
        ");

        // ID-ji vprašanj/opcij, ki ostanejo po shranitvi (drugi se izbrišejo).
        $keepQids = [];
        $keepOidsByQ = [];

        foreach ($questions as $i => $q) {
            $type = in_array($q['type'] ?? '', ['checkbox','rating','radio','text','textarea'])
                ? $q['type'] : 'text';
            $qid = isset($q['id']) ? (int)$q['id'] : 0;
            $text = trim($q['question_text'] ?? '');

            if ($qid > 0) {
                // Posodobi obstoječe vprašanje (preveri pripadnost)
                $chk = $pdo->prepare("SELECT 1 FROM survey_questions WHERE id=? AND survey_id=?");
                $chk->execute([$qid, $survey_id]);
                if ($chk->fetchColumn()) {
                    $qUpdate->execute([$i + 1, $text, $type, (int)(bool)($q['is_required'] ?? 0), $qid, $survey_id]);
                } else {
                    $qid = 0;
                }
            }
            if ($qid === 0) {
                $qInsert->execute([$survey_id, $i + 1, $text, $type, (int)(bool)($q['is_required'] ?? 0)]);
                $qid = (int)$pdo->lastInsertId();
            }
            $keepQids[] = $qid;
            $keepOidsByQ[$qid] = [];

            if (in_array($type, ['radio', 'checkbox'])) {
                foreach ($q['options'] ?? [] as $j => $opt) {
                    $oid   = is_array($opt) && isset($opt['id']) ? (int)$opt['id'] : 0;
                    $label = trim(is_array($opt) ? ($opt['label'] ?? '') : (string)$opt);
                    if ($label === '') continue;
                    if ($oid > 0) {
                        $chk = $pdo->prepare("SELECT 1 FROM survey_question_options WHERE id=? AND question_id=?");
                        $chk->execute([$oid, $qid]);
                        if ($chk->fetchColumn()) {
                            $oUpdate->execute([$j + 1, $label, $oid, $qid]);
                        } else {
                            $oid = 0;
                        }
                    }
                    if ($oid === 0) {
                        $oInsert->execute([$qid, $j + 1, $label]);
                        $oid = (int)$pdo->lastInsertId();
                    }
                    $keepOidsByQ[$qid][] = $oid;
                }
            }
        }

        // Pobriši odstranjena vprašanja
        if ($keepQids) {
            $ph = implode(',', array_fill(0, count($keepQids), '?'));
            $delQ = $pdo->prepare("DELETE FROM survey_questions WHERE survey_id=? AND id NOT IN ($ph)");
            $delQ->execute(array_merge([$survey_id], $keepQids));
        } else {
            $pdo->prepare("DELETE FROM survey_questions WHERE survey_id=?")->execute([$survey_id]);
        }

        // Pobriši odstranjene opcije znotraj vsakega vprašanja
        foreach ($keepOidsByQ as $qid => $oids) {
            if ($oids) {
                $ph = implode(',', array_fill(0, count($oids), '?'));
                $pdo->prepare("DELETE FROM survey_question_options WHERE question_id=? AND id NOT IN ($ph)")
                    ->execute(array_merge([$qid], $oids));
            } else {
                $pdo->prepare("DELETE FROM survey_question_options WHERE question_id=?")->execute([$qid]);
            }
        }

        // Če je primary != 'sl', shrani uvodne (admin-tipkane) vrednosti tudi
        // kot prevode v primary jeziku — admin je tipkal v primary, naj se to
        // odraža v survey_*_translations[primary]. SL master ostane kot fallback.
        if ($primaryLang !== 'sl') {
            // Form
            $pdo->prepare("
                INSERT INTO survey_form_translations (form_id, lang_code, title, description, thank_you_message)
                VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), thank_you_message=VALUES(thank_you_message)
            ")->execute([$survey_id, $primaryLang, $title, $description, $thank_you]);

            // Vprašanja in opcije po istem zaporedju kot zgoraj (uporabimo trenutni body)
            $qtIns = $pdo->prepare("
                INSERT INTO survey_question_translations (question_id, lang_code, question_text)
                VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE question_text=VALUES(question_text)
            ");
            $otIns = $pdo->prepare("
                INSERT INTO survey_question_option_translations (option_id, lang_code, label)
                VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE label=VALUES(label)
            ");
            // Re-iteriraj vprašanja in poveži z novimi ID-ji preko keepQids/keepOidsByQ
            $idx = 0;
            foreach ($questions as $q) {
                $qid = $keepQids[$idx] ?? null;
                $idx++;
                if (!$qid) continue;
                $text = trim($q['question_text'] ?? '');
                if ($text !== '') $qtIns->execute([$qid, $primaryLang, $text]);

                if (in_array($q['type'] ?? '', ['radio','checkbox'])) {
                    $oidIdx = 0;
                    foreach ($q['options'] ?? [] as $opt) {
                        $label = trim(is_array($opt) ? ($opt['label'] ?? '') : (string)$opt);
                        if ($label === '') continue;
                        $oid = $keepOidsByQ[$qid][$oidIdx] ?? null;
                        $oidIdx++;
                        if ($oid && $label !== '') $otIns->execute([$oid, $primaryLang, $label]);
                    }
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

// ─── POST save_translation ─────────────────────────────────────────────────────
// Body: { kind: 'form'|'question'|'option', target_id: N, lang_code: 'de', fields: {...} }
//   form     fields: { title?, description?, thank_you_message? }
//   question fields: { question_text }
//   option   fields: { label }
if ($method === 'POST' && $action === 'save_translation') {
    require_feature($pdo, $session, 'survey_edit');
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $kind      = $body['kind']      ?? '';
    $targetId  = (int)($body['target_id'] ?? 0);
    $langCode  = $body['lang_code'] ?? '';
    $fields    = $body['fields']    ?? [];
    if (!in_array($langCode, SURVEY_ALLOWED_LANGS, true)) json_response(false, null, 'Neveljaven jezik.', 400);
    if (!$targetId) json_response(false, null, 'target_id manjka.', 400);

    // Authz: preveri, da resource pripada admin-owned restavraciji
    if ($kind === 'form') {
        $stmt = $pdo->prepare("SELECT restaurant_id FROM survey_forms WHERE id=?");
    } elseif ($kind === 'question') {
        $stmt = $pdo->prepare("SELECT sf.restaurant_id FROM survey_questions sq JOIN survey_forms sf ON sq.survey_id=sf.id WHERE sq.id=?");
    } elseif ($kind === 'option') {
        $stmt = $pdo->prepare("SELECT sf.restaurant_id FROM survey_question_options sqo JOIN survey_questions sq ON sqo.question_id=sq.id JOIN survey_forms sf ON sq.survey_id=sf.id WHERE sqo.id=?");
    } else {
        json_response(false, null, 'Neznana vrsta.', 400);
    }
    $stmt->execute([$targetId]);
    $restId = (int)$stmt->fetchColumn();
    if (!$restId || !admin_owns_restaurant($pdo, $session, $restId)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    try {
        if ($kind === 'form') {
            $title  = trim($fields['title'] ?? '');
            $desc   = trim($fields['description'] ?? '');
            $thanks = trim($fields['thank_you_message'] ?? '');
            // Vse polja prazna → izbriši vrstico
            if ($title === '' && $desc === '' && $thanks === '') {
                $pdo->prepare("DELETE FROM survey_form_translations WHERE form_id=? AND lang_code=?")
                    ->execute([$targetId, $langCode]);
            } else {
                $pdo->prepare("
                    INSERT INTO survey_form_translations (form_id, lang_code, title, description, thank_you_message)
                    VALUES (?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), thank_you_message=VALUES(thank_you_message)
                ")->execute([$targetId, $langCode, $title, $desc, $thanks]);
            }
        } elseif ($kind === 'question') {
            $text = trim($fields['question_text'] ?? '');
            if ($text === '') {
                $pdo->prepare("DELETE FROM survey_question_translations WHERE question_id=? AND lang_code=?")
                    ->execute([$targetId, $langCode]);
            } else {
                $pdo->prepare("
                    INSERT INTO survey_question_translations (question_id, lang_code, question_text)
                    VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE question_text=VALUES(question_text)
                ")->execute([$targetId, $langCode, $text]);
            }
        } elseif ($kind === 'option') {
            $label = trim($fields['label'] ?? '');
            if ($label === '') {
                $pdo->prepare("DELETE FROM survey_question_option_translations WHERE option_id=? AND lang_code=?")
                    ->execute([$targetId, $langCode]);
            } else {
                $pdo->prepare("
                    INSERT INTO survey_question_option_translations (option_id, lang_code, label)
                    VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE label=VALUES(label)
                ")->execute([$targetId, $langCode, $label]);
            }
        }
        json_response(true, null);
    } catch (PDOException $e) {
        error_log('Survey save_translation error: ' . $e->getMessage());
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
        // survey_language: guest_language iz rezervacije > restaurant primary > 'sl'
        $sLang = !empty($res['guest_language']) ? (string)$res['guest_language'] : '';
        if (!in_array($sLang, SURVEY_ALLOWED_LANGS, true)) {
            $rq = $pdo->prepare("SELECT booking_primary_language FROM restaurants WHERE id=?");
            try { $rq->execute([(int)$res['restaurant_id']]); $sLang = (string)$rq->fetchColumn(); } catch (PDOException $e) { $sLang = 'sl'; }
            if (!in_array($sLang, SURVEY_ALLOWED_LANGS, true)) $sLang = 'sl';
        }
        $hasLangCol = false;
        try { $hasLangCol = (bool)$pdo->query("SHOW COLUMNS FROM survey_responses LIKE 'survey_language'")->fetch(); } catch (PDOException $e) {}
        if ($hasLangCol) {
            $pdo->prepare("INSERT INTO survey_responses (survey_id, reservation_id, email, token, scheduled_send_at, survey_language) VALUES (?,?,?,?,NOW(),?)")
                ->execute([$form['id'], $reservation_id, $res['email'], $token, $sLang]);
        } else {
            $pdo->prepare("INSERT INTO survey_responses (survey_id, reservation_id, email, token, scheduled_send_at) VALUES (?,?,?,?,NOW())")
                ->execute([$form['id'], $reservation_id, $res['email'], $token]);
        }
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
