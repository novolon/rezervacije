<?php
/**
 * Pomožne funkcije za ankete o zadovoljstvu.
 */

/**
 * Ustvari prednastavjeno anketo za restavracijo (samo Advanced/Premium).
 * Kliče se ob ustvaritvi nove restavracije ali ročno, če anketa še ne obstaja.
 */
function seed_default_survey(PDO $pdo, int $restaurant_id): int {
    // Preveri, da anketa še ne obstaja
    $stmt = $pdo->prepare("SELECT id FROM survey_forms WHERE restaurant_id = ? LIMIT 1");
    $stmt->execute([$restaurant_id]);
    if ($existing = $stmt->fetchColumn()) {
        return (int) $existing;
    }

    $pdo->prepare("
        INSERT INTO survey_forms (restaurant_id, title, description, thank_you_message, send_enabled, send_delay_hours, include_thankyou, include_survey)
        VALUES (?, 'Anketa o zadovoljstvu', 'Prosimo, ocenite vaš obisk. Vaše mnenje nam pomaga izboljšati storitve.',
                'Hvala za vaš obisk in za čas, ki ste ga namenili izpolnitvi ankete. Veseli smo, da ste nas obiskali, in upamo, da se kmalu vidimo znova!',
                0, 2, 1, 1)
    ")->execute([$restaurant_id]);
    $survey_id = (int) $pdo->lastInsertId();

    $questions = [
        ['rating',   'Kako bi ocenili vaš celoten obisk?',                  1],
        ['rating',   'Kako bi ocenili kakovost hrane in pijače?',           0],
        ['rating',   'Kako bi ocenili prijaznost osebja?',                  0],
        ['radio',    'Ali bi nas priporočili prijateljem ali družini?',      0],
        ['textarea', 'Kaj vam je bilo med obiskom najbolj všeč?',           0],
        ['textarea', 'Kaj bi radi izboljšali?',                             0],
    ];

    $qStmt = $pdo->prepare("
        INSERT INTO survey_questions (survey_id, sort_order, question_text, type, is_required)
        VALUES (?, ?, ?, ?, ?)
    ");
    $oStmt = $pdo->prepare("
        INSERT INTO survey_question_options (question_id, sort_order, label)
        VALUES (?, ?, ?)
    ");

    foreach ($questions as $i => [$type, $text, $required]) {
        $qStmt->execute([$survey_id, $i + 1, $text, $type, $required]);
        $question_id = (int) $pdo->lastInsertId();

        if ($type === 'radio') {
            foreach (['Da', 'Verjetno da', 'Verjetno ne', 'Ne'] as $j => $label) {
                $oStmt->execute([$question_id, $j + 1, $label]);
            }
        }
    }

    return $survey_id;
}

/**
 * Naloži anketo z vprašanji in možnostmi za dano restavracijo.
 * Vrne null če anketa ne obstaja.
 */
function load_survey(PDO $pdo, int $restaurant_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM survey_forms WHERE restaurant_id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$restaurant_id]);
    $form = $stmt->fetch();
    if (!$form) return null;

    $stmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order, id");
    $stmt->execute([$form['id']]);
    $questions = $stmt->fetchAll();

    if ($questions) {
        $qids = array_column($questions, 'id');
        $placeholders = implode(',', array_fill(0, count($qids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM survey_question_options WHERE question_id IN ($placeholders) ORDER BY question_id, sort_order");
        $stmt->execute($qids);
        $options = $stmt->fetchAll();

        $optMap = [];
        foreach ($options as $o) {
            $optMap[$o['question_id']][] = $o;
        }
        foreach ($questions as &$q) {
            $q['options'] = $optMap[$q['id']] ?? [];
        }
    }

    $form['questions'] = $questions;
    return $form;
}
