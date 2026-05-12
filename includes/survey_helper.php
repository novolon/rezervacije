<?php
/**
 * Pomožne funkcije za ankete o zadovoljstvu.
 *
 * Anketa ima master vsebino (survey_forms, survey_questions, survey_question_options)
 * in prevodi v survey_form_translations, survey_question_translations,
 * survey_question_option_translations.
 */

const SURVEY_ALLOWED_LANGS = ['sl','en','de','it','fr','hr','es','pt'];

/** Default survey vsebina v vseh 8 jezikih. */
function _default_survey_content(): array {
    return [
        'forms' => [
            'sl' => [
                'title'             => 'Anketa o zadovoljstvu',
                'description'       => 'Prosimo, ocenite vaš obisk. Vaše mnenje nam pomaga izboljšati storitve.',
                'thank_you_message' => 'Hvala za vaš obisk in za čas, ki ste ga namenili izpolnitvi ankete. Veseli smo, da ste nas obiskali, in upamo, da se kmalu vidimo znova!',
            ],
            'en' => [
                'title'             => 'Satisfaction survey',
                'description'       => 'Please rate your visit. Your feedback helps us improve.',
                'thank_you_message' => 'Thank you for your visit and for taking the time to fill out our survey. We\'re glad you came and hope to see you again soon!',
            ],
            'de' => [
                'title'             => 'Zufriedenheitsumfrage',
                'description'       => 'Bitte bewerten Sie Ihren Besuch. Ihre Meinung hilft uns, unseren Service zu verbessern.',
                'thank_you_message' => 'Vielen Dank für Ihren Besuch und dass Sie sich die Zeit für unsere Umfrage genommen haben. Wir freuen uns, dass Sie da waren, und hoffen, Sie bald wiederzusehen!',
            ],
            'it' => [
                'title'             => 'Sondaggio di soddisfazione',
                'description'       => 'Per favore valuti la sua visita. Il suo feedback ci aiuta a migliorare.',
                'thank_you_message' => 'Grazie per la sua visita e per il tempo dedicato a compilare il sondaggio. Siamo lieti che sia venuto e speriamo di rivederla presto!',
            ],
            'fr' => [
                'title'             => 'Enquête de satisfaction',
                'description'       => 'Merci d\'évaluer votre visite. Vos commentaires nous aident à améliorer nos services.',
                'thank_you_message' => 'Merci de votre visite et d\'avoir pris le temps de répondre à notre enquête. Nous sommes ravis de vous avoir accueilli et espérons vous revoir bientôt !',
            ],
            'hr' => [
                'title'             => 'Anketa o zadovoljstvu',
                'description'       => 'Molimo ocijenite svoj posjet. Vaše mišljenje nam pomaže poboljšati uslugu.',
                'thank_you_message' => 'Hvala vam na posjetu i na vremenu koje ste posvetili ispunjavanju ankete. Drago nam je da ste nas posjetili i nadamo se ponovnom susretu!',
            ],
            'es' => [
                'title'             => 'Encuesta de satisfacción',
                'description'       => 'Por favor, valore su visita. Su opinión nos ayuda a mejorar.',
                'thank_you_message' => 'Gracias por su visita y por dedicar tiempo a completar la encuesta. Nos alegra que nos haya visitado y esperamos verle pronto de nuevo.',
            ],
            'pt' => [
                'title'             => 'Inquérito de satisfação',
                'description'       => 'Por favor, avalie a sua visita. A sua opinião ajuda-nos a melhorar.',
                'thank_you_message' => 'Obrigado pela sua visita e pelo tempo dedicado ao preenchimento do inquérito. Estamos contentes pela sua visita e esperamos vê-lo em breve novamente!',
            ],
        ],
        'questions' => [
            // [type, required, sl, en, de, it, fr, hr, es, pt]
            ['rating', 1, [
                'sl'=>'Kako bi ocenili vaš celoten obisk?',
                'en'=>'How would you rate your overall visit?',
                'de'=>'Wie würden Sie Ihren Gesamtbesuch bewerten?',
                'it'=>'Come valuterebbe la sua visita complessiva?',
                'fr'=>'Comment évaluez-vous votre visite globale ?',
                'hr'=>'Kako biste ocijenili svoj cjelokupan posjet?',
                'es'=>'¿Cómo valoraría su visita en general?',
                'pt'=>'Como avalia a sua visita no geral?',
            ]],
            ['rating', 0, [
                'sl'=>'Kako bi ocenili kakovost hrane in pijače?',
                'en'=>'How would you rate the quality of food and drink?',
                'de'=>'Wie würden Sie die Qualität von Speisen und Getränken bewerten?',
                'it'=>'Come valuterebbe la qualità di cibo e bevande?',
                'fr'=>'Comment évaluez-vous la qualité de la nourriture et des boissons ?',
                'hr'=>'Kako biste ocijenili kvalitetu hrane i pića?',
                'es'=>'¿Cómo valoraría la calidad de la comida y la bebida?',
                'pt'=>'Como avalia a qualidade da comida e da bebida?',
            ]],
            ['rating', 0, [
                'sl'=>'Kako bi ocenili prijaznost osebja?',
                'en'=>'How would you rate the friendliness of the staff?',
                'de'=>'Wie würden Sie die Freundlichkeit des Personals bewerten?',
                'it'=>'Come valuterebbe la cordialità del personale?',
                'fr'=>'Comment évaluez-vous l\'amabilité du personnel ?',
                'hr'=>'Kako biste ocijenili ljubaznost osoblja?',
                'es'=>'¿Cómo valoraría la amabilidad del personal?',
                'pt'=>'Como avalia a simpatia da equipa?',
            ]],
            ['radio', 0, [
                'sl'=>'Ali bi nas priporočili prijateljem ali družini?',
                'en'=>'Would you recommend us to friends or family?',
                'de'=>'Würden Sie uns Freunden oder Familie weiterempfehlen?',
                'it'=>'Ci raccomanderebbe ad amici o familiari?',
                'fr'=>'Nous recommanderiez-vous à vos amis ou à votre famille ?',
                'hr'=>'Biste li nas preporučili prijateljima ili obitelji?',
                'es'=>'¿Nos recomendaría a sus amigos o familiares?',
                'pt'=>'Recomendaria-nos a amigos ou familiares?',
            ]],
            ['textarea', 0, [
                'sl'=>'Kaj vam je bilo med obiskom najbolj všeč?',
                'en'=>'What did you like most about your visit?',
                'de'=>'Was hat Ihnen bei Ihrem Besuch am besten gefallen?',
                'it'=>'Cosa le è piaciuto di più durante la sua visita?',
                'fr'=>'Qu\'est-ce qui vous a le plus plu lors de votre visite ?',
                'hr'=>'Što vam se najviše svidjelo tijekom posjeta?',
                'es'=>'¿Qué fue lo que más le gustó de su visita?',
                'pt'=>'O que mais gostou da sua visita?',
            ]],
            ['textarea', 0, [
                'sl'=>'Kaj bi radi izboljšali?',
                'en'=>'What would you like us to improve?',
                'de'=>'Was sollten wir verbessern?',
                'it'=>'Cosa vorrebbe che migliorassimo?',
                'fr'=>'Qu\'aimeriez-vous que nous améliorions ?',
                'hr'=>'Što biste željeli da poboljšamo?',
                'es'=>'¿Qué le gustaría que mejorásemos?',
                'pt'=>'O que gostaria que melhorássemos?',
            ]],
        ],
        'radio_options' => [
            // Same vrstni red v vseh jezikih: ['Da','Verjetno da','Verjetno ne','Ne']
            'sl' => ['Da','Verjetno da','Verjetno ne','Ne'],
            'en' => ['Yes','Probably yes','Probably not','No'],
            'de' => ['Ja','Wahrscheinlich ja','Wahrscheinlich nicht','Nein'],
            'it' => ['Sì','Probabilmente sì','Probabilmente no','No'],
            'fr' => ['Oui','Probablement oui','Probablement non','Non'],
            'hr' => ['Da','Vjerojatno da','Vjerojatno ne','Ne'],
            'es' => ['Sí','Probablemente sí','Probablemente no','No'],
            'pt' => ['Sim','Provavelmente sim','Provavelmente não','Não'],
        ],
    ];
}

/**
 * Ustvari prednastavljeno anketo za restavracijo (samo Advanced/Premium).
 * Master vsebina je vedno SL; vsi 8 prevodi so seedani v _translations tabele.
 */
function seed_default_survey(PDO $pdo, int $restaurant_id): int {
    $stmt = $pdo->prepare("SELECT id FROM survey_forms WHERE restaurant_id = ? LIMIT 1");
    $stmt->execute([$restaurant_id]);
    if ($existing = $stmt->fetchColumn()) return (int)$existing;

    $content = _default_survey_content();
    $sl = $content['forms']['sl'];

    $pdo->prepare("
        INSERT INTO survey_forms (restaurant_id, title, description, thank_you_message, send_enabled, send_delay_hours, include_thankyou, include_survey)
        VALUES (?, ?, ?, ?, 0, 2, 1, 1)
    ")->execute([$restaurant_id, $sl['title'], $sl['description'], $sl['thank_you_message']]);
    $survey_id = (int)$pdo->lastInsertId();

    // Seed prevode forme
    _seed_form_translations($pdo, $survey_id, $content['forms']);

    $qStmt = $pdo->prepare("
        INSERT INTO survey_questions (survey_id, sort_order, question_text, type, is_required)
        VALUES (?, ?, ?, ?, ?)
    ");
    $oStmt = $pdo->prepare("
        INSERT INTO survey_question_options (question_id, sort_order, label)
        VALUES (?, ?, ?)
    ");

    foreach ($content['questions'] as $i => $q) {
        [$type, $required, $byLang] = $q;
        $qStmt->execute([$survey_id, $i + 1, $byLang['sl'], $type, $required]);
        $question_id = (int)$pdo->lastInsertId();

        // Vse jezikovne prevode vprašanja
        _seed_question_translations($pdo, $question_id, $byLang);

        if ($type === 'radio') {
            foreach ($content['radio_options']['sl'] as $j => $label) {
                $oStmt->execute([$question_id, $j + 1, $label]);
                $option_id = (int)$pdo->lastInsertId();
                // Prevode možnosti v vseh jezikih
                $optByLang = [];
                foreach ($content['radio_options'] as $lc => $arr) {
                    $optByLang[$lc] = $arr[$j] ?? $label;
                }
                _seed_option_translations($pdo, $option_id, $optByLang);
            }
        }
    }

    return $survey_id;
}

function _seed_form_translations(PDO $pdo, int $form_id, array $forms): void {
    $stmt = $pdo->prepare("
        INSERT INTO survey_form_translations (form_id, lang_code, title, description, thank_you_message)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), thank_you_message=VALUES(thank_you_message)
    ");
    foreach ($forms as $lc => $f) {
        if (!in_array($lc, SURVEY_ALLOWED_LANGS, true)) continue;
        $stmt->execute([$form_id, $lc, $f['title'], $f['description'] ?? null, $f['thank_you_message'] ?? null]);
    }
}

function _seed_question_translations(PDO $pdo, int $question_id, array $byLang): void {
    $stmt = $pdo->prepare("
        INSERT INTO survey_question_translations (question_id, lang_code, question_text)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE question_text=VALUES(question_text)
    ");
    foreach ($byLang as $lc => $text) {
        if (!in_array($lc, SURVEY_ALLOWED_LANGS, true)) continue;
        $stmt->execute([$question_id, $lc, $text]);
    }
}

function _seed_option_translations(PDO $pdo, int $option_id, array $byLang): void {
    $stmt = $pdo->prepare("
        INSERT INTO survey_question_option_translations (option_id, lang_code, label)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE label=VALUES(label)
    ");
    foreach ($byLang as $lc => $text) {
        if (!in_array($lc, SURVEY_ALLOWED_LANGS, true)) continue;
        $stmt->execute([$option_id, $lc, $text]);
    }
}

/**
 * Naloži anketo z vprašanji in možnostmi za dano restavracijo.
 *
 * @param string|null $lang Če podan in obstaja prevod, vrne lokalizirano besedilo
 *                          (title, description, thank_you_message, question_text, option label).
 *                          Master (SL) ostane v ['_master_*'] ključih za admin UI.
 */
function load_survey(PDO $pdo, int $restaurant_id, ?string $lang = null): ?array {
    $stmt = $pdo->prepare("SELECT * FROM survey_forms WHERE restaurant_id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$restaurant_id]);
    $form = $stmt->fetch();
    if (!$form) return null;

    // Naloži vse prevode forme (za admin UI)
    $form['translations'] = _load_form_translations($pdo, (int)$form['id']);

    // Apliciraj jezik na master polja
    if ($lang && isset($form['translations'][$lang])) {
        $tr = $form['translations'][$lang];
        $form['_master_title']             = $form['title'];
        $form['_master_description']       = $form['description'];
        $form['_master_thank_you_message'] = $form['thank_you_message'];
        if (!empty($tr['title']))             $form['title']             = $tr['title'];
        if (isset($tr['description']))        $form['description']       = $tr['description'];
        if (isset($tr['thank_you_message']))  $form['thank_you_message'] = $tr['thank_you_message'];
    }

    $stmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order, id");
    $stmt->execute([$form['id']]);
    $questions = $stmt->fetchAll();

    if ($questions) {
        $qids = array_column($questions, 'id');
        $placeholders = implode(',', array_fill(0, count($qids), '?'));

        // Naloži vse prevode vprašanj (po qid → lang → text)
        $qtStmt = $pdo->prepare("SELECT question_id, lang_code, question_text FROM survey_question_translations WHERE question_id IN ($placeholders)");
        $qtStmt->execute($qids);
        $qtMap = [];
        foreach ($qtStmt->fetchAll() as $r) {
            $qtMap[(int)$r['question_id']][$r['lang_code']] = $r['question_text'];
        }

        // Možnosti
        $stmt = $pdo->prepare("SELECT * FROM survey_question_options WHERE question_id IN ($placeholders) ORDER BY question_id, sort_order");
        $stmt->execute($qids);
        $options = $stmt->fetchAll();

        $optMap = [];
        $optIds = [];
        foreach ($options as $o) {
            $optMap[$o['question_id']][] = $o;
            $optIds[] = (int)$o['id'];
        }

        // Možnostni prevodi
        $optTrMap = [];
        if (!empty($optIds)) {
            $optPlaceholders = implode(',', array_fill(0, count($optIds), '?'));
            $otStmt = $pdo->prepare("SELECT option_id, lang_code, label FROM survey_question_option_translations WHERE option_id IN ($optPlaceholders)");
            $otStmt->execute($optIds);
            foreach ($otStmt->fetchAll() as $r) {
                $optTrMap[(int)$r['option_id']][$r['lang_code']] = $r['label'];
            }
        }

        foreach ($questions as &$q) {
            $qid = (int)$q['id'];
            $q['translations'] = $qtMap[$qid] ?? [];
            $q['_master_text'] = $q['question_text'];
            if ($lang && !empty($q['translations'][$lang])) {
                $q['question_text'] = $q['translations'][$lang];
            }

            $q['options'] = $optMap[$qid] ?? [];
            foreach ($q['options'] as &$o) {
                $oid = (int)$o['id'];
                $o['translations'] = $optTrMap[$oid] ?? [];
                $o['_master_label'] = $o['label'];
                if ($lang && !empty($o['translations'][$lang])) {
                    $o['label'] = $o['translations'][$lang];
                }
            }
            unset($o);
        }
        unset($q);
    }

    $form['questions'] = $questions;
    return $form;
}

/**
 * Doseti prevode za obstoječe ankete: če master besedilo (SL) ujema enega od
 * default vprašanj/opcij/form polj, vstavi manjkajoče prevode v 8 jezikih.
 * Ne dotika se admin-ovega edit-a (samo manjkajoči prevodi).
 *
 * Vrne število insertanih prevodov.
 */
function backfill_default_survey_translations(PDO $pdo, ?int $restaurant_id = null): int {
    $content = _default_survey_content();

    // Map SL → byLang za vprašanja
    $qSlToByLang = [];
    foreach ($content['questions'] as $q) {
        [$type, $req, $byLang] = $q;
        $qSlToByLang[$byLang['sl']] = $byLang;
    }
    // Map SL → byLang za radio možnosti (po indeksu, ker so vse 4 fiksne)
    $optSlToByLang = [];
    foreach ($content['radio_options']['sl'] as $i => $sl) {
        $perLang = [];
        foreach ($content['radio_options'] as $lc => $arr) {
            $perLang[$lc] = $arr[$i] ?? $sl;
        }
        $optSlToByLang[$sl] = $perLang;
    }
    // Form polja: SL → byLang per polje
    $formSlMap = [
        'title'             => [],
        'description'       => [],
        'thank_you_message' => [],
    ];
    foreach (['title','description','thank_you_message'] as $fk) {
        $sl = $content['forms']['sl'][$fk] ?? '';
        if ($sl === '') continue;
        foreach ($content['forms'] as $lc => $f) {
            $formSlMap[$fk][$sl][$lc] = $f[$fk] ?? '';
        }
    }

    $where = $restaurant_id ? "WHERE sf.restaurant_id = ?" : "";
    $params = $restaurant_id ? [$restaurant_id] : [];
    $forms = $pdo->prepare("SELECT sf.id, sf.title, sf.description, sf.thank_you_message FROM survey_forms sf $where");
    $forms->execute($params);

    $inserted = 0;
    foreach ($forms->fetchAll() as $form) {
        $formId = (int)$form['id'];

        // Form-level prevode
        foreach (['title','description','thank_you_message'] as $fk) {
            $masterVal = trim((string)($form[$fk] ?? ''));
            if ($masterVal === '') continue;
            if (!isset($formSlMap[$fk][$masterVal])) continue; // ne ujema default
            foreach ($formSlMap[$fk][$masterVal] as $lc => $val) {
                if ($lc === 'sl') continue; // master je SL
                $chk = $pdo->prepare("SELECT 1 FROM survey_form_translations WHERE form_id=? AND lang_code=?");
                $chk->execute([$formId, $lc]);
                if ($chk->fetchColumn()) {
                    // Posodobi samo če je polje prazno
                    $cur = $pdo->prepare("SELECT title, description, thank_you_message FROM survey_form_translations WHERE form_id=? AND lang_code=?");
                    $cur->execute([$formId, $lc]);
                    $row = $cur->fetch();
                    if ($row && empty(trim((string)($row[$fk] ?? '')))) {
                        $pdo->prepare("UPDATE survey_form_translations SET $fk=? WHERE form_id=? AND lang_code=?")
                            ->execute([$val, $formId, $lc]);
                        $inserted++;
                    }
                } else {
                    // Ustvari nov zapis z samo tem poljem
                    $title  = $fk === 'title'             ? $val : '';
                    $desc   = $fk === 'description'       ? $val : '';
                    $thanks = $fk === 'thank_you_message' ? $val : '';
                    $pdo->prepare("INSERT INTO survey_form_translations (form_id, lang_code, title, description, thank_you_message) VALUES (?,?,?,?,?)")
                        ->execute([$formId, $lc, $title, $desc, $thanks]);
                    $inserted++;
                }
            }
        }

        // Vprašanja
        $qs = $pdo->prepare("SELECT id, question_text, type FROM survey_questions WHERE survey_id=?");
        $qs->execute([$formId]);
        foreach ($qs->fetchAll() as $q) {
            $qid = (int)$q['id'];
            $sl  = trim((string)$q['question_text']);
            if (!isset($qSlToByLang[$sl])) continue;
            foreach ($qSlToByLang[$sl] as $lc => $text) {
                if ($lc === 'sl') continue;
                $chk = $pdo->prepare("SELECT 1 FROM survey_question_translations WHERE question_id=? AND lang_code=?");
                $chk->execute([$qid, $lc]);
                if ($chk->fetchColumn()) continue;
                $pdo->prepare("INSERT INTO survey_question_translations (question_id, lang_code, question_text) VALUES (?,?,?)")
                    ->execute([$qid, $lc, $text]);
                $inserted++;
            }

            // Opcije za radio
            if ($q['type'] === 'radio') {
                $os = $pdo->prepare("SELECT id, label FROM survey_question_options WHERE question_id=?");
                $os->execute([$qid]);
                foreach ($os->fetchAll() as $o) {
                    $oid = (int)$o['id'];
                    $oSl = trim((string)$o['label']);
                    if (!isset($optSlToByLang[$oSl])) continue;
                    foreach ($optSlToByLang[$oSl] as $lc => $lab) {
                        if ($lc === 'sl') continue;
                        $chk = $pdo->prepare("SELECT 1 FROM survey_question_option_translations WHERE option_id=? AND lang_code=?");
                        $chk->execute([$oid, $lc]);
                        if ($chk->fetchColumn()) continue;
                        $pdo->prepare("INSERT INTO survey_question_option_translations (option_id, lang_code, label) VALUES (?,?,?)")
                            ->execute([$oid, $lc, $lab]);
                        $inserted++;
                    }
                }
            }
        }
    }

    return $inserted;
}

function _load_form_translations(PDO $pdo, int $form_id): array {
    $stmt = $pdo->prepare("SELECT lang_code, title, description, thank_you_message FROM survey_form_translations WHERE form_id = ?");
    $stmt->execute([$form_id]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['lang_code']] = [
            'title'             => $r['title'],
            'description'       => $r['description'],
            'thank_you_message' => $r['thank_you_message'],
        ];
    }
    return $out;
}
