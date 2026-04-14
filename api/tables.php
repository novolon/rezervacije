<?php
/**
 * Admin CRUD za cone, mize in merge grupe (table management).
 *
 * GET    ?restaurant_id=X                      → { areas, tables, merge_groups }
 * POST   action=create_area                    → ustvari cono
 * POST   action=create_table                   → ustvari mizo
 * POST   action=create_merge_group             → ustvari merge grupo
 * PUT    ?area_id=X                            → posodobi cono
 * PUT    ?table_id=X                           → posodobi mizo
 * PUT    ?merge_group_id=X                     → posodobi merge grupo
 * DELETE ?area_id=X                            → briši cono
 * DELETE ?table_id=X                           → briši mizo (blokira, če ima prihodnje rezervacije)
 * DELETE ?merge_group_id=X                     → briši merge grupo
 */

require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';

header('Content-Type: application/json; charset=utf-8');

$session = require_auth();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

// Samo admin in superadmin imata dostop
if ($session['role'] === 'user') {
    json_response(false, null, 'Dostop zavrnjen.', 403);
}

function tables_can_access(PDO $pdo, array $session, int $restId): bool {
    if ($session['role'] === 'superadmin') return true;
    $stmt = $pdo->prepare("SELECT 1 FROM restaurant_admins WHERE restaurant_id = ? AND user_id = ?");
    $stmt->execute([$restId, $session['user_id']]);
    return (bool)$stmt->fetchColumn();
}

function tables_require_feature(PDO $pdo, array $session): void {
    if ($session['role'] === 'superadmin') return;
    require_feature($pdo, $session, 'table_management');
}

// ─── GET ───────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $restId = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
    if (!$restId) json_response(false, null, 'restaurant_id je obvezen.', 400);
    if (!tables_can_access($pdo, $session, $restId)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }
    tables_require_feature($pdo, $session);

    // ?action=available – vrne razpoložljive mize za dani termin
    if (isset($_GET['action']) && $_GET['action'] === 'available') {
        require_once '../includes/table_helper.php';
        $date     = trim($_GET['date']     ?? '');
        $time     = trim($_GET['time']     ?? '');
        $guests   = max(1, (int)($_GET['guests']   ?? 1));
        $duration = max(15, (int)($_GET['duration'] ?? 60));
        $excludeId= isset($_GET['exclude_reservation_id']) ? (int)$_GET['exclude_reservation_id'] : null;

        if (!$date || !$time) {
            json_response(false, null, 'date in time sta obvezna.', 400);
        }

        $result = get_available_tables_for_slot($pdo, $restId, $date, substr($time, 0, 5), $duration, $guests, $excludeId);
        json_response(true, $result);
    }

    // Cone
    $stmtA = $pdo->prepare("
        SELECT id, name, sort_order, is_active
        FROM restaurant_areas
        WHERE restaurant_id = ?
        ORDER BY sort_order, name
    ");
    $stmtA->execute([$restId]);
    $areas = $stmtA->fetchAll(PDO::FETCH_ASSOC);

    // Mize (z imenom cone)
    $stmtT = $pdo->prepare("
        SELECT t.id, t.area_id, t.name, t.capacity, t.sort_order, t.is_active,
               a.name AS area_name
        FROM restaurant_tables t
        LEFT JOIN restaurant_areas a ON t.area_id = a.id
        WHERE t.restaurant_id = ?
        ORDER BY t.sort_order, t.name
    ");
    $stmtT->execute([$restId]);
    $tables = $stmtT->fetchAll(PDO::FETCH_ASSOC);

    // Merge grupe z člani
    $stmtMG = $pdo->prepare("
        SELECT mg.id, mg.name,
               GROUP_CONCAT(mm.table_id ORDER BY mm.table_id SEPARATOR ',') AS member_ids,
               GROUP_CONCAT(rt.name     ORDER BY mm.table_id SEPARATOR ',') AS member_names,
               SUM(rt.capacity) AS total_capacity
        FROM restaurant_table_merge_groups mg
        LEFT JOIN restaurant_table_merge_members mm ON mg.id = mm.merge_group_id
        LEFT JOIN restaurant_tables rt ON mm.table_id = rt.id AND rt.is_active = 1
        WHERE mg.restaurant_id = ?
        GROUP BY mg.id, mg.name
        ORDER BY mg.id
    ");
    $stmtMG->execute([$restId]);
    $rawGroups = $stmtMG->fetchAll(PDO::FETCH_ASSOC);

    $mergeGroups = array_map(function($g) {
        return [
            'id'             => (int)$g['id'],
            'name'           => $g['name'],
            'member_ids'     => $g['member_ids'] ? array_map('intval', explode(',', $g['member_ids'])) : [],
            'member_names'   => $g['member_names'] ? explode(',', $g['member_names']) : [],
            'total_capacity' => $g['total_capacity'] ? (int)$g['total_capacity'] : 0,
        ];
    }, $rawGroups);

    json_response(true, [
        'areas'        => $areas,
        'tables'       => $tables,
        'merge_groups' => $mergeGroups,
    ]);
}

// ─── POST ──────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = get_body();
    $action = $body['action'] ?? '';
    $restId = isset($body['restaurant_id']) ? (int)$body['restaurant_id'] : 0;

    if (!$restId) json_response(false, null, 'restaurant_id je obvezen.', 400);
    if (!tables_can_access($pdo, $session, $restId)) {
        json_response(false, null, 'Dostop zavrnjen.', 403);
    }
    tables_require_feature($pdo, $session);

    // ── Ustvari cono ────────────────────────────────────────────
    if ($action === 'create_area') {
        $name      = trim($body['name'] ?? '');
        $sortOrder = isset($body['sort_order']) ? (int)$body['sort_order'] : 0;
        if (!$name) json_response(false, null, 'Ime cone je obvezno.', 400);

        $stmt = $pdo->prepare("
            INSERT INTO restaurant_areas (restaurant_id, name, sort_order)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$restId, $name, $sortOrder]);
        $id = (int)$pdo->lastInsertId();
        json_response(true, ['id' => $id, 'name' => $name, 'sort_order' => $sortOrder, 'is_active' => 1], '', 201);
    }

    // ── Ustvari mizo ────────────────────────────────────────────
    if ($action === 'create_table') {
        $name      = trim($body['name'] ?? '');
        $capacity  = isset($body['capacity']) ? max(1, (int)$body['capacity']) : 2;
        $areaId    = isset($body['area_id']) && $body['area_id'] ? (int)$body['area_id'] : null;
        $sortOrder = isset($body['sort_order']) ? (int)$body['sort_order'] : 0;

        if (!$name) json_response(false, null, 'Ime mize je obvezno.', 400);

        // Preveri, da area_id pripada isti restavraciji
        if ($areaId) {
            $stmtCheck = $pdo->prepare("SELECT 1 FROM restaurant_areas WHERE id = ? AND restaurant_id = ?");
            $stmtCheck->execute([$areaId, $restId]);
            if (!$stmtCheck->fetchColumn()) {
                json_response(false, null, 'Cona ne pripada tej restavraciji.', 400);
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO restaurant_tables (restaurant_id, area_id, name, capacity, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$restId, $areaId, $name, $capacity, $sortOrder]);
        $id = (int)$pdo->lastInsertId();
        json_response(true, [
            'id'        => $id,
            'name'      => $name,
            'capacity'  => $capacity,
            'area_id'   => $areaId,
            'sort_order'=> $sortOrder,
            'is_active' => 1,
        ], '', 201);
    }

    // ── Ustvari merge grupo ──────────────────────────────────────
    if ($action === 'create_merge_group') {
        $name      = trim($body['name'] ?? '') ?: null;
        $memberIds = isset($body['member_table_ids']) ? array_map('intval', (array)$body['member_table_ids']) : [];

        if (count($memberIds) < 2) {
            json_response(false, null, 'Merge grupa mora imeti vsaj 2 mizi.', 400);
        }

        // Preveri, da vse mize pripadajo tej restavraciji
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmtCheck = $pdo->prepare("
            SELECT COUNT(*) FROM restaurant_tables
            WHERE id IN ($placeholders) AND restaurant_id = ?
        ");
        $stmtCheck->execute([...$memberIds, $restId]);
        if ((int)$stmtCheck->fetchColumn() !== count($memberIds)) {
            json_response(false, null, 'Ena ali več miz ne pripada tej restavraciji.', 400);
        }

        try {
            $pdo->beginTransaction();

            $stmtMG = $pdo->prepare("INSERT INTO restaurant_table_merge_groups (restaurant_id, name) VALUES (?, ?)");
            $stmtMG->execute([$restId, $name]);
            $groupId = (int)$pdo->lastInsertId();

            $stmtMM = $pdo->prepare("INSERT INTO restaurant_table_merge_members (merge_group_id, table_id) VALUES (?, ?)");
            foreach ($memberIds as $tid) {
                $stmtMM->execute([$groupId, $tid]);
            }

            $pdo->commit();
            json_response(true, ['id' => $groupId, 'name' => $name, 'member_ids' => $memberIds], '', 201);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Merge group create: ' . $e->getMessage());
            json_response(false, null, 'Napaka pri shranjevanju.', 500);
        }
    }

    json_response(false, null, 'Neznana akcija.', 400);
}

// ─── PUT ───────────────────────────────────────────────────────────────────
if ($method === 'PUT') {
    $body = get_body();

    // ── Posodobi cono ────────────────────────────────────────────
    if (isset($_GET['area_id'])) {
        $areaId = (int)$_GET['area_id'];
        $stmt = $pdo->prepare("SELECT restaurant_id FROM restaurant_areas WHERE id = ?");
        $stmt->execute([$areaId]);
        $row = $stmt->fetch();
        if (!$row) json_response(false, null, 'Cona ne obstaja.', 404);
        if (!tables_can_access($pdo, $session, (int)$row['restaurant_id'])) {
            json_response(false, null, 'Dostop zavrnjen.', 403);
        }
        tables_require_feature($pdo, $session);

        $sets = []; $params = [];
        if (isset($body['name']))       { $sets[] = 'name = ?';       $params[] = trim($body['name']); }
        if (isset($body['sort_order'])) { $sets[] = 'sort_order = ?'; $params[] = (int)$body['sort_order']; }
        if (isset($body['is_active']))  { $sets[] = 'is_active = ?';  $params[] = $body['is_active'] ? 1 : 0; }

        if ($sets) {
            $params[] = $areaId;
            $pdo->prepare("UPDATE restaurant_areas SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        }

        $stmt = $pdo->prepare("SELECT id, name, sort_order, is_active FROM restaurant_areas WHERE id = ?");
        $stmt->execute([$areaId]);
        json_response(true, $stmt->fetch());
    }

    // ── Posodobi mizo ────────────────────────────────────────────
    if (isset($_GET['table_id'])) {
        $tableId = (int)$_GET['table_id'];
        $stmt = $pdo->prepare("SELECT restaurant_id FROM restaurant_tables WHERE id = ?");
        $stmt->execute([$tableId]);
        $row = $stmt->fetch();
        if (!$row) json_response(false, null, 'Miza ne obstaja.', 404);
        if (!tables_can_access($pdo, $session, (int)$row['restaurant_id'])) {
            json_response(false, null, 'Dostop zavrnjen.', 403);
        }
        tables_require_feature($pdo, $session);

        $restId = (int)$row['restaurant_id'];
        $sets = []; $params = [];

        if (isset($body['name']))       { $sets[] = 'name = ?';       $params[] = trim($body['name']); }
        if (isset($body['capacity']))   { $sets[] = 'capacity = ?';   $params[] = max(1, (int)$body['capacity']); }
        if (isset($body['sort_order'])) { $sets[] = 'sort_order = ?'; $params[] = (int)$body['sort_order']; }
        if (isset($body['is_active']))  { $sets[] = 'is_active = ?';  $params[] = $body['is_active'] ? 1 : 0; }
        if (array_key_exists('area_id', $body)) {
            $areaId = $body['area_id'] ? (int)$body['area_id'] : null;
            if ($areaId) {
                $stmtCheck = $pdo->prepare("SELECT 1 FROM restaurant_areas WHERE id = ? AND restaurant_id = ?");
                $stmtCheck->execute([$areaId, $restId]);
                if (!$stmtCheck->fetchColumn()) {
                    json_response(false, null, 'Cona ne pripada tej restavraciji.', 400);
                }
            }
            $sets[] = 'area_id = ?';
            $params[] = $areaId;
        }

        if ($sets) {
            $params[] = $tableId;
            $pdo->prepare("UPDATE restaurant_tables SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        }

        $stmt = $pdo->prepare("
            SELECT t.id, t.area_id, t.name, t.capacity, t.sort_order, t.is_active, a.name AS area_name
            FROM restaurant_tables t
            LEFT JOIN restaurant_areas a ON t.area_id = a.id
            WHERE t.id = ?
        ");
        $stmt->execute([$tableId]);
        json_response(true, $stmt->fetch());
    }

    // ── Posodobi merge grupo ─────────────────────────────────────
    if (isset($_GET['merge_group_id'])) {
        $groupId = (int)$_GET['merge_group_id'];
        $stmt = $pdo->prepare("SELECT restaurant_id FROM restaurant_table_merge_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $row = $stmt->fetch();
        if (!$row) json_response(false, null, 'Merge grupa ne obstaja.', 404);
        if (!tables_can_access($pdo, $session, (int)$row['restaurant_id'])) {
            json_response(false, null, 'Dostop zavrnjen.', 403);
        }
        tables_require_feature($pdo, $session);

        $restId = (int)$row['restaurant_id'];

        try {
            $pdo->beginTransaction();

            if (isset($body['name'])) {
                $pdo->prepare("UPDATE restaurant_table_merge_groups SET name = ? WHERE id = ?")
                    ->execute([trim($body['name']) ?: null, $groupId]);
            }

            if (isset($body['member_table_ids'])) {
                $memberIds = array_map('intval', (array)$body['member_table_ids']);
                if (count($memberIds) < 2) {
                    $pdo->rollBack();
                    json_response(false, null, 'Merge grupa mora imeti vsaj 2 mizi.', 400);
                }
                // Preveri, da vse mize pripadajo tej restavraciji
                $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
                $stmtCheck = $pdo->prepare("
                    SELECT COUNT(*) FROM restaurant_tables
                    WHERE id IN ($placeholders) AND restaurant_id = ?
                ");
                $stmtCheck->execute([...$memberIds, $restId]);
                if ((int)$stmtCheck->fetchColumn() !== count($memberIds)) {
                    $pdo->rollBack();
                    json_response(false, null, 'Ena ali več miz ne pripada tej restavraciji.', 400);
                }

                $pdo->prepare("DELETE FROM restaurant_table_merge_members WHERE merge_group_id = ?")
                    ->execute([$groupId]);
                $stmtMM = $pdo->prepare("INSERT INTO restaurant_table_merge_members (merge_group_id, table_id) VALUES (?, ?)");
                foreach ($memberIds as $tid) {
                    $stmtMM->execute([$groupId, $tid]);
                }
            }

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Merge group update: ' . $e->getMessage());
            json_response(false, null, 'Napaka pri shranjevanju.', 500);
        }

        // Vrni posodobljeno grupo
        $stmtR = $pdo->prepare("
            SELECT mg.id, mg.name,
                   GROUP_CONCAT(mm.table_id ORDER BY mm.table_id SEPARATOR ',') AS member_ids
            FROM restaurant_table_merge_groups mg
            LEFT JOIN restaurant_table_merge_members mm ON mg.id = mm.merge_group_id
            WHERE mg.id = ?
            GROUP BY mg.id, mg.name
        ");
        $stmtR->execute([$groupId]);
        $g = $stmtR->fetch();
        json_response(true, [
            'id'         => (int)$g['id'],
            'name'       => $g['name'],
            'member_ids' => $g['member_ids'] ? array_map('intval', explode(',', $g['member_ids'])) : [],
        ]);
    }

    json_response(false, null, 'Manjka area_id, table_id ali merge_group_id.', 400);
}

// ─── DELETE ────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {

    // ── Briši cono ───────────────────────────────────────────────
    if (isset($_GET['area_id'])) {
        $areaId = (int)$_GET['area_id'];
        $stmt = $pdo->prepare("SELECT restaurant_id FROM restaurant_areas WHERE id = ?");
        $stmt->execute([$areaId]);
        $row = $stmt->fetch();
        if (!$row) json_response(false, null, 'Cona ne obstaja.', 404);
        if (!tables_can_access($pdo, $session, (int)$row['restaurant_id'])) {
            json_response(false, null, 'Dostop zavrnjen.', 403);
        }
        tables_require_feature($pdo, $session);

        // Mize v tej coni dobijo area_id = NULL (FK ON DELETE SET NULL)
        $pdo->prepare("DELETE FROM restaurant_areas WHERE id = ?")->execute([$areaId]);
        json_response(true);
    }

    // ── Briši mizo ───────────────────────────────────────────────
    if (isset($_GET['table_id'])) {
        $tableId = (int)$_GET['table_id'];
        $stmt = $pdo->prepare("SELECT restaurant_id FROM restaurant_tables WHERE id = ?");
        $stmt->execute([$tableId]);
        $row = $stmt->fetch();
        if (!$row) json_response(false, null, 'Miza ne obstaja.', 404);
        if (!tables_can_access($pdo, $session, (int)$row['restaurant_id'])) {
            json_response(false, null, 'Dostop zavrnjen.', 403);
        }
        tables_require_feature($pdo, $session);

        // Blokira brisanje, če ima miza prihodnje aktivne rezervacije
        $stmtCheck = $pdo->prepare("
            SELECT COUNT(*) FROM reservation_table_assignments rta
            JOIN reservations r ON rta.reservation_id = r.id
            WHERE rta.table_id = ?
              AND r.reservation_date >= CURDATE()
              AND r.status IN ('confirmed', 'pending')
        ");
        $stmtCheck->execute([$tableId]);
        if ((int)$stmtCheck->fetchColumn() > 0) {
            json_response(false, null,
                'Miza ima prihodnje rezervacije. Najprej prestavite rezervacije na drugo mizo.', 409);
        }

        $pdo->prepare("DELETE FROM restaurant_tables WHERE id = ?")->execute([$tableId]);
        json_response(true);
    }

    // ── Briši merge grupo ────────────────────────────────────────
    if (isset($_GET['merge_group_id'])) {
        $groupId = (int)$_GET['merge_group_id'];
        $stmt = $pdo->prepare("SELECT restaurant_id FROM restaurant_table_merge_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $row = $stmt->fetch();
        if (!$row) json_response(false, null, 'Merge grupa ne obstaja.', 404);
        if (!tables_can_access($pdo, $session, (int)$row['restaurant_id'])) {
            json_response(false, null, 'Dostop zavrnjen.', 403);
        }
        tables_require_feature($pdo, $session);

        // Člani se izbrišejo kaskadno (FK ON DELETE CASCADE)
        $pdo->prepare("DELETE FROM restaurant_table_merge_groups WHERE id = ?")->execute([$groupId]);
        json_response(true);
    }

    json_response(false, null, 'Manjka area_id, table_id ali merge_group_id.', 400);
}

json_response(false, null, 'Metoda ni podprta.', 405);
