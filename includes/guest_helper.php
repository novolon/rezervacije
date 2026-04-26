<?php
/**
 * Pomožne funkcije za bazo gostov (Modul 5).
 * Zahteva: tabela `guests` (sql/migrate_guests.sql + sql/migrate_phone_guest.sql)
 *
 * Gosta identificiramo po emailu ALI telefonski številki (eno ali drugo).
 */

/**
 * Normalizira telefonsko številko: odstrani presledke, pomišljaje, oklepaje, pike.
 * Ohrani vodilni +. Vrne null če rezultat ni veljavna številka.
 */
function normalize_phone(string $raw): ?string {
    $s = preg_replace('/[\s\-\(\)\.\/]/', '', $raw);
    if ($s === '' || $s === '+') return null;
    if (!preg_match('/^\+?\d{4,}$/', $s)) return null;
    return $s;
}

/**
 * Ustvari ali posodobi profil gosta glede na email ali telefon in restaurant_id.
 * Kliče se ob vsaki rezervaciji (admin ali javni booking).
 *
 * @param PDO    $pdo
 * @param int    $restaurant_id
 * @param string $email         Lahko prazen niz – gost brez emaila
 * @param array  $data          Ključi: first_name, last_name, phone, reservation_date, guest_count, no_show
 */
function upsert_guest(PDO $pdo, int $restaurant_id, string $email, array $data): void {
    $email = strtolower(trim($email));
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

    $phone = normalize_phone(trim($data['phone'] ?? ''));

    if (!$email && !$phone) return;

    $firstName  = trim($data['first_name']  ?? '');
    $lastName   = trim($data['last_name']   ?? '');
    $visitDate  = $data['reservation_date'] ?? null;
    $guestCount = max(0, (int)($data['guest_count'] ?? 0));
    $isNoShow   = !empty($data['no_show']) ? 1 : 0;

    if (!$firstName && !$lastName && !empty($data['guest_name'])) {
        $parts     = explode(' ', trim($data['guest_name']), 2);
        $firstName = $parts[0] ?? '';
        $lastName  = $parts[1] ?? '';
    }

    try {
        // Poišči obstoječega gosta – najprej po emailu, nato po telefonu
        $existing = null;
        if ($email) {
            $stmt = $pdo->prepare("SELECT id FROM guests WHERE restaurant_id = ? AND email = ?");
            $stmt->execute([$restaurant_id, $email]);
            $existing = $stmt->fetch();
        }
        if (!$existing && $phone) {
            $stmt = $pdo->prepare("SELECT id FROM guests WHERE restaurant_id = ? AND phone = ?");
            $stmt->execute([$restaurant_id, $phone]);
            $existing = $stmt->fetch();
        }

        if ($existing) {
            // Posodobi obstoječega gosta
            $sets   = [];
            $params = [];

            // Dopolni manjkajoče podatke (ne prepiši obstoječih)
            if ($email) {
                $sets[]   = 'email      = COALESCE(NULLIF(email, \'\'), ?)';
                $params[] = $email;
            }
            if ($phone) {
                $sets[]   = 'phone      = COALESCE(NULLIF(phone, \'\'), ?)';
                $params[] = $phone;
            }
            if ($firstName) {
                $sets[]   = 'first_name = IF(first_name = \'\' OR first_name IS NULL, ?, first_name)';
                $params[] = $firstName;
            }
            if ($lastName) {
                $sets[]   = 'last_name  = IF(last_name  = \'\' OR last_name  IS NULL, ?, last_name)';
                $params[] = $lastName;
            }
            $sets[]   = 'first_visit  = IF(first_visit  IS NULL OR ? < first_visit,  ?, first_visit)';
            $params[] = $visitDate; $params[] = $visitDate;
            $sets[]   = 'last_visit   = IF(last_visit   IS NULL OR ? > last_visit,   ?, last_visit)';
            $params[] = $visitDate; $params[] = $visitDate;
            $sets[]   = 'total_visits = total_visits + 1';
            $sets[]   = 'total_covers = total_covers + ?';
            $params[] = $guestCount;
            $sets[]   = 'no_shows     = no_shows + ?';
            $params[] = $isNoShow;

            $params[] = $existing['id'];
            $pdo->prepare("UPDATE guests SET " . implode(', ', $sets) . " WHERE id = ?")
                ->execute($params);
        } else {
            // Nov gost
            $pdo->prepare("
                INSERT INTO guests
                    (restaurant_id, email, first_name, last_name, phone,
                     first_visit, last_visit, total_visits, total_covers, no_shows)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
            ")->execute([
                $restaurant_id,
                $email ?: null,
                $firstName,
                $lastName,
                $phone,
                $visitDate,
                $visitDate,
                $guestCount,
                $isNoShow,
            ]);
        }
    } catch (PDOException $e) {
        error_log('guest_helper upsert_guest: ' . $e->getMessage());
    }
}

/**
 * Vrne profil gosta po emailu ali telefonu (ali null).
 */
function get_guest_profile(PDO $pdo, int $restaurant_id, ?string $email, ?string $phone = null): ?array {
    try {
        if ($email) {
            $stmt = $pdo->prepare("SELECT * FROM guests WHERE restaurant_id = ? AND email = ?");
            $stmt->execute([$restaurant_id, strtolower(trim($email))]);
            $row = $stmt->fetch();
            if ($row) return $row;
        }
        if ($phone) {
            $norm = normalize_phone($phone);
            if ($norm) {
                $stmt = $pdo->prepare("SELECT * FROM guests WHERE restaurant_id = ? AND phone = ?");
                $stmt->execute([$restaurant_id, $norm]);
                return $stmt->fetch() ?: null;
            }
        }
    } catch (PDOException $e) {
        error_log('guest_helper get_guest_profile: ' . $e->getMessage());
    }
    return null;
}

/**
 * Vrne zadnjih N rezervacij gosta (iskanje po emailu ALI telefonu).
 */
function get_guest_history(PDO $pdo, int $restaurant_id, ?string $email, int $limit = 10, ?string $phone = null): array {
    try {
        $conditions = [];
        $params     = [$restaurant_id];

        if ($email) {
            $conditions[] = 'LOWER(email) = ?';
            $params[]     = strtolower(trim($email));
        }
        if ($phone) {
            $norm = normalize_phone($phone);
            if ($norm) {
                $conditions[] = 'phone = ?';
                $params[]     = $norm;
            }
        }
        if (!$conditions) return [];

        $where = '(' . implode(' OR ', $conditions) . ')';

        $stmt = $pdo->prepare("
            SELECT id, reservation_date, reservation_time, guest_count, status, notes
            FROM reservations
            WHERE restaurant_id = ? AND {$where}
            ORDER BY reservation_date DESC, reservation_time DESC
            LIMIT ?
        ");
        $params[] = $limit;
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('guest_helper get_guest_history: ' . $e->getMessage());
        return [];
    }
}
