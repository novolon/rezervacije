<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDB();

// Javni GET za spletne rezervacije (?token=X) – brez avtentikacije
$pubToken = trim($_GET['token'] ?? '');
if ($method === 'GET' && $pubToken) {
    $stmt = $pdo->prepare("SELECT id FROM restaurants WHERE booking_token = ? AND is_active = 1 AND booking_enabled = 1");
    $stmt->execute([$pubToken]);
    $rest = $stmt->fetch();
    if (!$rest) json_response(false, null, 'Restavracija ni najdena.', 404);

    $reqLang = trim($_GET['lang'] ?? '');

    $stmt = $pdo->prepare("
        SELECT id, label, field_type, options, is_required
        FROM restaurant_custom_fields
        WHERE restaurant_id = ? AND applies_to IN ('public','both') AND is_active = 1
        ORDER BY sort_order, id
    ");
    $stmt->execute([$rest['id']]);
    $fields = $stmt->fetchAll();

    // Naloži prevode za zahtevani jezik (eno query za vse polja)
    $trMap = [];
    if ($reqLang && $fields) {
        try {
            $ids = array_column($fields, 'id');
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $tStmt = $pdo->prepare("SELECT field_id, label, options_json FROM restaurant_custom_field_translations WHERE field_id IN ($ph) AND lang_code = ?");
            $tStmt->execute(array_merge($ids, [$reqLang]));
            foreach ($tStmt->fetchAll() as $r) {
                $trMap[(int)$r['field_id']] = [
                    'label'   => $r['label'],
                    'options' => $r['options_json'] ? (json_decode($r['options_json'], true) ?: null) : null,
                ];
            }
        } catch (PDOException $e) { /* tabela morda manjka */ }
    }

    foreach ($fields as &$f) {
        $f['options'] = $f['options'] ? json_decode($f['options'], true) : [];
        $tr = $trMap[(int)$f['id']] ?? null;
        if ($tr) {
            if (!empty($tr['label']))   $f['label']   = $tr['label'];
            if (!empty($tr['options'])) $f['options'] = $tr['options'];
        }
    }
    json_response(true, $fields);
}

// Ostalo zahteva avtentikacijo
require_once '../includes/auth_check.php';
$session = require_auth();

function can_access_cf_restaurant(PDO $pdo, array $session, int $restId): bool {
    if ($session['role'] === 'superadmin') return true;
    if ($session['role'] === 'admin') {
        $stmt = $pdo->prepare("SELECT 1 FROM restaurant_admins WHERE restaurant_id = ? AND user_id = ?");
        $stmt->execute([$restId, $session['user_id']]);
        return (bool)$stmt->fetchColumn();
    }
    return (int)$session['restaurant_id'] === $restId;
}

// ─── GET (seznam polj) ─────────────────────────────────────────
if ($method === 'GET') {
    $restId = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
    if (!$restId) json_response(false, null, 'restaurant_id je obvezen.', 400);
    if (!can_access_cf_restaurant($pdo, $session, $restId)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    $stmt = $pdo->prepare("
        SELECT id, label, field_type, options, applies_to, is_required, sort_order, is_active
        FROM restaurant_custom_fields
        WHERE restaurant_id = ? AND is_active = 1
        ORDER BY sort_order, id
    ");
    $stmt->execute([$restId]);
    $fields = $stmt->fetchAll();
    foreach ($fields as &$f) {
        $f['options']     = $f['options']     ? json_decode($f['options'], true) : [];
        $f['is_required'] = (bool)$f['is_required'];
        $f['is_active']   = (bool)$f['is_active'];
    }
    json_response(true, $fields);
}

// ─── POST (ustvari) ────────────────────────────────────────────
if ($method === 'POST') {
    if ($session['role'] === 'user') json_response(false, null, 'Dostop zavrnjen.', 403);

    $body   = get_body();
    $restId = isset($body['restaurant_id']) ? (int)$body['restaurant_id'] : 0;
    $label  = trim($body['label'] ?? '');

    if (!$restId) json_response(false, null, 'restaurant_id je obvezen.', 400);
    if (!$label)  json_response(false, null, 'Oznaka polja je obvezna.', 400);
    if (!can_access_cf_restaurant($pdo, $session, $restId)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    $validTypes     = ['text', 'select', 'checkbox'];
    $validApplies   = ['internal', 'public', 'both'];
    $fieldType      = in_array($body['field_type'] ?? '', $validTypes)   ? $body['field_type']  : 'text';
    $appliesTo      = in_array($body['applies_to'] ?? '', $validApplies) ? $body['applies_to']  : 'both';
    $isRequired     = !empty($body['is_required']) ? 1 : 0;
    $sortOrder      = isset($body['sort_order']) ? (int)$body['sort_order'] : 0;
    $options        = null;

    if ($fieldType === 'select' && !empty($body['options']) && is_array($body['options'])) {
        $opts = array_values(array_filter(array_map('trim', $body['options']), 'strlen'));
        if ($opts) $options = json_encode($opts, JSON_UNESCAPED_UNICODE);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO restaurant_custom_fields
                (restaurant_id, label, field_type, options, applies_to, is_required, sort_order)
            VALUES (?,?,?,?,?,?,?)
        ");
        $stmt->execute([$restId, $label, $fieldType, $options, $appliesTo, $isRequired, $sortOrder]);
        $id = (int)$pdo->lastInsertId();
        json_response(true, [
            'id'          => $id,
            'label'       => $label,
            'field_type'  => $fieldType,
            'options'     => $options ? json_decode($options, true) : [],
            'applies_to'  => $appliesTo,
            'is_required' => (bool)$isRequired,
            'sort_order'  => $sortOrder,
            'is_active'   => true,
        ], '', 201);
    } catch (PDOException $e) {
        error_log('CustomField create: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju.', 500);
    }
}

// ─── PUT (posodobi) ────────────────────────────────────────────
if ($method === 'PUT') {
    if ($session['role'] === 'user') json_response(false, null, 'Dostop zavrnjen.', 403);

    $id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    $body = get_body();

    $stmt = $pdo->prepare("SELECT restaurant_id FROM restaurant_custom_fields WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_response(false, null, 'Polje ne obstaja.', 404);
    if (!can_access_cf_restaurant($pdo, $session, (int)$row['restaurant_id'])) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    $validTypes   = ['text', 'select', 'checkbox'];
    $validApplies = ['internal', 'public', 'both'];
    $sets = []; $params = [];

    if (isset($body['label']) && trim($body['label']))            { $sets[] = 'label = ?';       $params[] = trim($body['label']); }
    if (isset($body['field_type']) && in_array($body['field_type'], $validTypes))  { $sets[] = 'field_type = ?'; $params[] = $body['field_type']; }
    if (isset($body['applies_to']) && in_array($body['applies_to'], $validApplies)){ $sets[] = 'applies_to = ?'; $params[] = $body['applies_to']; }
    if (isset($body['is_required']))                              { $sets[] = 'is_required = ?'; $params[] = $body['is_required'] ? 1 : 0; }
    if (isset($body['sort_order']))                               { $sets[] = 'sort_order = ?';  $params[] = (int)$body['sort_order']; }

    // Options (samo za select)
    $currentType = $body['field_type'] ?? null;
    if ($currentType === 'select' && isset($body['options']) && is_array($body['options'])) {
        $opts = array_values(array_filter(array_map('trim', $body['options']), 'strlen'));
        $sets[] = 'options = ?';
        $params[] = $opts ? json_encode($opts, JSON_UNESCAPED_UNICODE) : null;
    }

    if ($sets) {
        $params[] = $id;
        $pdo->prepare("UPDATE restaurant_custom_fields SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    }

    $stmt = $pdo->prepare("SELECT id, label, field_type, options, applies_to, is_required, sort_order, is_active FROM restaurant_custom_fields WHERE id = ?");
    $stmt->execute([$id]);
    $f = $stmt->fetch();
    $f['options']     = $f['options'] ? json_decode($f['options'], true) : [];
    $f['is_required'] = (bool)$f['is_required'];
    json_response(true, $f);
}

// ─── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if ($session['role'] === 'user') json_response(false, null, 'Dostop zavrnjen.', 403);

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) json_response(false, null, 'ID ni določen.', 400);

    $stmt = $pdo->prepare("SELECT restaurant_id FROM restaurant_custom_fields WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_response(false, null, 'Polje ne obstaja.', 404);
    if (!can_access_cf_restaurant($pdo, $session, (int)$row['restaurant_id'])) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }

    // Soft delete (ohranjamo vrednosti v zgodovini)
    $pdo->prepare("UPDATE restaurant_custom_fields SET is_active = 0 WHERE id = ?")->execute([$id]);
    json_response(true);
}

json_response(false, null, 'Metoda ni podprta.', 405);
