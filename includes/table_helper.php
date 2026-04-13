<?php
/**
 * Pomožne funkcije za upravljanje miz (Table Management).
 *
 * Uvozi to datoteko v: api/book.php, api/reservations.php, api/table_assignment.php
 */

/**
 * Preveri, ali ima restavracija sploh definirane aktivne mize.
 * Uporablja se kot zgodnji izhod pred dražjimi poizvedbami.
 */
function restaurant_has_tables(PDO $pdo, int $restId): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM restaurant_tables
        WHERE restaurant_id = ? AND is_active = 1
    ");
    $stmt->execute([$restId]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Poišče razpoložljivo mizo (ali merge grupo) za dano rezervacijo.
 *
 * @param PDO    $pdo
 * @param int    $restId          ID restavracije
 * @param string $date            Datum (YYYY-MM-DD)
 * @param string $time            Čas (HH:MM ali HH:MM:SS)
 * @param int    $durationMins    Trajanje v minutah
 * @param int    $guestCount      Število gostov
 * @param int|null $excludeResId  Izključi to rezervacijo iz overlap preverbe (za re-assign)
 *
 * @return array|false
 *   ['mode' => 'no_tables']                                    – restavracija nima miz (backward-compat)
 *   ['mode' => 'single', 'table_id' => X]                      – enotna miza
 *   ['mode' => 'merge', 'table_ids' => [...], 'merge_group_id' => Y] – združene mize
 *   false                                                        – ni prostega mesta
 */
function find_available_table(
    PDO    $pdo,
    int    $restId,
    string $date,
    string $time,
    int    $durationMins,
    int    $guestCount,
    ?int   $excludeResId
): array|false {

    // 1. Naloži vse aktivne mize restavracije (ORDER BY capacity ASC – najmanjša najprej)
    $stmtT = $pdo->prepare("
        SELECT id, capacity
        FROM restaurant_tables
        WHERE restaurant_id = ? AND is_active = 1
        ORDER BY capacity ASC
    ");
    $stmtT->execute([$restId]);
    $allTables = $stmtT->fetchAll(PDO::FETCH_ASSOC);

    if (empty($allTables)) {
        return ['mode' => 'no_tables'];
    }

    // 2. Izračunaj časovno okno
    // TIME_TO_SEC vrne sekunde od polnoči; delimo z 60 za minute
    [$h, $m] = explode(':', $time);
    $newStart = (int)$h * 60 + (int)$m;
    $newEnd   = $newStart + $durationMins;

    // 3. Poišči zasedene table_id-je (overlap preverba)
    $excludeSql = ($excludeResId !== null) ? 'AND r.id != :excludeId' : '';
    $sql = "
        SELECT DISTINCT rta.table_id
        FROM reservation_table_assignments rta
        JOIN reservations  r   ON rta.reservation_id = r.id
        JOIN restaurants   res ON r.restaurant_id    = res.id
        WHERE r.restaurant_id = :restId
          AND r.reservation_date = :date
          AND r.status IN ('confirmed', 'pending')
          $excludeSql
          AND (TIME_TO_SEC(r.reservation_time) / 60) < :newEnd
          AND (TIME_TO_SEC(r.reservation_time) / 60
               + COALESCE(r.duration, res.reservation_duration)) > :newStart
    ";
    $stmtO = $pdo->prepare($sql);
    $params = [
        ':restId'   => $restId,
        ':date'     => $date,
        ':newEnd'   => $newEnd,
        ':newStart' => $newStart,
    ];
    if ($excludeResId !== null) {
        $params[':excludeId'] = $excludeResId;
    }
    $stmtO->execute($params);
    $occupiedIds = array_column($stmtO->fetchAll(PDO::FETCH_ASSOC), 'table_id');
    $occupiedSet = array_flip($occupiedIds);

    // 4. Proste mize
    $freeTables = array_filter($allTables, fn($t) => !isset($occupiedSet[$t['id']]));

    // 5. Enotna miza: najmanjša prosta z zadostno kapaciteto
    foreach ($freeTables as $t) {
        if ((int)$t['capacity'] >= $guestCount) {
            return ['mode' => 'single', 'table_id' => (int)$t['id']];
        }
    }

    // 6. Merge grupe: poišči grupo, kjer so VSE članice proste in skupna kapaciteta zadošča
    $stmtMG = $pdo->prepare("
        SELECT mg.id AS group_id,
               GROUP_CONCAT(mm.table_id ORDER BY mm.table_id) AS table_ids,
               SUM(rt.capacity) AS total_capacity
        FROM restaurant_table_merge_groups mg
        JOIN restaurant_table_merge_members mm ON mg.id = mm.merge_group_id
        JOIN restaurant_tables rt ON mm.table_id = rt.id
        WHERE mg.restaurant_id = ? AND rt.is_active = 1
        GROUP BY mg.id
        ORDER BY total_capacity ASC
    ");
    $stmtMG->execute([$restId]);
    $mergeGroups = $stmtMG->fetchAll(PDO::FETCH_ASSOC);

    $freeIdSet = array_flip(array_column(array_values($freeTables), 'id'));

    foreach ($mergeGroups as $group) {
        if ((int)$group['total_capacity'] < $guestCount) continue;

        $memberIds = array_map('intval', explode(',', $group['table_ids']));
        $allFree = true;
        foreach ($memberIds as $mid) {
            if (!isset($freeIdSet[$mid])) {
                $allFree = false;
                break;
            }
        }
        if ($allFree) {
            return [
                'mode'           => 'merge',
                'table_ids'      => $memberIds,
                'merge_group_id' => (int)$group['group_id'],
            ];
        }
    }

    // 7. Ni prostega mesta
    return false;
}

/**
 * Shrani dodelitev mize(miz) k rezervaciji.
 * Kliči znotraj odprte transakcije.
 */
function assign_tables_to_reservation(
    PDO    $pdo,
    int    $reservationId,
    array  $assignment,
    ?int   $assignedBy
): void {
    if ($assignment['mode'] === 'no_tables') {
        return; // nič za shraniti
    }

    $stmt = $pdo->prepare("
        INSERT INTO reservation_table_assignments
            (reservation_id, table_id, merge_group_id, assigned_by)
        VALUES (:resId, :tableId, :mergeGroupId, :assignedBy)
        ON DUPLICATE KEY UPDATE
            merge_group_id = VALUES(merge_group_id),
            assigned_by    = VALUES(assigned_by),
            assigned_at    = NOW()
    ");

    $mergeGroupId = $assignment['merge_group_id'] ?? null;

    if ($assignment['mode'] === 'single') {
        $stmt->execute([
            ':resId'       => $reservationId,
            ':tableId'     => $assignment['table_id'],
            ':mergeGroupId'=> null,
            ':assignedBy'  => $assignedBy,
        ]);
    } elseif ($assignment['mode'] === 'merge') {
        foreach ($assignment['table_ids'] as $tableId) {
            $stmt->execute([
                ':resId'       => $reservationId,
                ':tableId'     => $tableId,
                ':mergeGroupId'=> $mergeGroupId,
                ':assignedBy'  => $assignedBy,
            ]);
        }
    }
}

/**
 * Vrne dodeljene mize za rezervacijo (za prikaz v API odgovorih).
 */
function get_table_assignments(PDO $pdo, int $reservationId): array {
    $stmt = $pdo->prepare("
        SELECT rta.table_id,
               rt.name AS table_name,
               rt.capacity,
               ra.name AS area_name,
               rta.merge_group_id,
               rta.assigned_by,
               rta.assigned_at
        FROM reservation_table_assignments rta
        JOIN restaurant_tables rt ON rta.table_id = rt.id
        LEFT JOIN restaurant_areas ra ON rt.area_id = ra.id
        WHERE rta.reservation_id = ?
        ORDER BY rt.sort_order, rt.name
    ");
    $stmt->execute([$reservationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Izbriše vse dodelitve miz za rezervacijo (pred re-assignom).
 */
function clear_table_assignments(PDO $pdo, int $reservationId): void {
    $stmt = $pdo->prepare("
        DELETE FROM reservation_table_assignments WHERE reservation_id = ?
    ");
    $stmt->execute([$reservationId]);
}

/**
 * Vrne kratko ime za prikaz dodeljenih miz (npr. "Miza 3" ali "Miza 3 + Miza 4").
 */
function format_table_assignment_label(array $assignments): string {
    if (empty($assignments)) return '';
    return implode(' + ', array_column($assignments, 'table_name'));
}
