<?php
/**
 * Splošni endpoint za posodabljanje prevodov za:
 *  - restaurant_areas         (kind=area)
 *  - restaurant_custom_fields (kind=custom_field)
 *
 * Survey prevode upravlja api/survey.php (action=save_translation).
 *
 * Endpoints:
 *   GET  ?action=get&kind=area&id=N
 *   GET  ?action=get&kind=custom_field&id=N
 *   POST action=save  body: { kind, id, lang_code, fields: { name? / label?, options? } }
 *
 * Avtentikacija: admin ali superadmin nad pripadajočo restavracijo.
 */

require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

const TRANS_ALLOWED_LANGS = ['sl','en','de','it','fr','hr','es','pt'];

$session = require_admin();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];
$action  = $_GET['action'] ?? ($_POST['action'] ?? '');

function _trans_get_restaurant_id(PDO $pdo, string $kind, int $id): int {
    if ($kind === 'area') {
        $stmt = $pdo->prepare("SELECT restaurant_id FROM restaurant_areas WHERE id=?");
    } elseif ($kind === 'custom_field') {
        $stmt = $pdo->prepare("SELECT restaurant_id FROM restaurant_custom_fields WHERE id=?");
    } else {
        return 0;
    }
    $stmt->execute([$id]);
    return (int)$stmt->fetchColumn();
}

if ($method === 'GET' && $action === 'get') {
    $kind = $_GET['kind'] ?? '';
    $id   = (int)($_GET['id'] ?? 0);
    if (!$id) json_response(false, null, 'id manjka.', 400);

    $restId = _trans_get_restaurant_id($pdo, $kind, $id);
    if (!$restId || !admin_owns_restaurant($pdo, $session, $restId)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    if ($kind === 'area') {
        $stmt = $pdo->prepare("SELECT lang_code, name FROM restaurant_area_translations WHERE area_id=?");
        $stmt->execute([$id]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) $out[$r['lang_code']] = ['name' => $r['name']];
        json_response(true, $out);
    }
    if ($kind === 'custom_field') {
        $stmt = $pdo->prepare("SELECT lang_code, label, options_json FROM restaurant_custom_field_translations WHERE field_id=?");
        $stmt->execute([$id]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['lang_code']] = [
                'label'   => $r['label'],
                'options' => $r['options_json'] ? (json_decode($r['options_json'], true) ?: []) : [],
            ];
        }
        json_response(true, $out);
    }
    json_response(false, null, 'Neznana vrsta.', 400);
}

if ($method === 'POST' && $action === 'save') {
    $body     = get_body();
    $kind     = $body['kind']      ?? '';
    $id       = (int)($body['id']  ?? 0);
    $lang     = $body['lang_code'] ?? '';
    $fields   = $body['fields']    ?? [];
    if (!$id)                                         json_response(false, null, 'id manjka.', 400);
    if (!in_array($lang, TRANS_ALLOWED_LANGS, true))  json_response(false, null, 'Neveljaven jezik.', 400);

    $restId = _trans_get_restaurant_id($pdo, $kind, $id);
    if (!$restId || !admin_owns_restaurant($pdo, $session, $restId)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    try {
        if ($kind === 'area') {
            $name = trim((string)($fields['name'] ?? ''));
            if ($name === '') {
                $pdo->prepare("DELETE FROM restaurant_area_translations WHERE area_id=? AND lang_code=?")
                    ->execute([$id, $lang]);
            } else {
                $pdo->prepare("
                    INSERT INTO restaurant_area_translations (area_id, lang_code, name)
                    VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE name=VALUES(name)
                ")->execute([$id, $lang, $name]);
            }
            json_response(true, null);
        }
        if ($kind === 'custom_field') {
            $label   = trim((string)($fields['label'] ?? ''));
            $options = $fields['options'] ?? null;
            $optsJson = null;
            if (is_array($options)) {
                $clean = array_values(array_filter(array_map('trim', $options), 'strlen'));
                $optsJson = $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
            }
            // Vse prazno → izbriši
            if ($label === '' && $optsJson === null) {
                $pdo->prepare("DELETE FROM restaurant_custom_field_translations WHERE field_id=? AND lang_code=?")
                    ->execute([$id, $lang]);
            } else {
                $pdo->prepare("
                    INSERT INTO restaurant_custom_field_translations (field_id, lang_code, label, options_json)
                    VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE label=VALUES(label), options_json=VALUES(options_json)
                ")->execute([$id, $lang, $label ?: '', $optsJson]);
            }
            json_response(true, null);
        }
        json_response(false, null, 'Neznana vrsta.', 400);
    } catch (PDOException $e) {
        error_log('translations.php save: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju.', 500);
    }
}

json_response(false, null, 'Metoda ali akcija nista podprti.', 405);
