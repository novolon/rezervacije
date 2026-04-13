<?php
/**
 * Pomožne funkcije za bazo gostov (Modul 5).
 * Zahteva: tabela `guests` (sql/migrate_guests.sql)
 */

/**
 * Ustvari ali posodobi profil gosta glede na email in restaurant_id.
 * Kliče se ob vsaki rezervaciji (admin ali javni booking).
 *
 * @param PDO    $pdo
 * @param int    $restaurant_id
 * @param string $email
 * @param array  $data  Ključi: first_name, last_name, phone, reservation_date, guest_count, no_show
 */
function upsert_guest(PDO $pdo, int $restaurant_id, string $email, array $data): void {
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    $firstName   = trim($data['first_name']      ?? '');
    $lastName    = trim($data['last_name']        ?? '');
    $phone       = trim($data['phone']            ?? '');
    $visitDate   = $data['reservation_date']      ?? null;
    $guestCount  = max(0, (int)($data['guest_count'] ?? 0));
    $isNoShow    = !empty($data['no_show']) ? 1 : 0;

    // Razdeli ime na first/last če je samo guest_name
    if (!$firstName && !$lastName && !empty($data['guest_name'])) {
        $parts = explode(' ', trim($data['guest_name']), 2);
        $firstName = $parts[0] ?? '';
        $lastName  = $parts[1] ?? '';
    }

    try {
        // Najprej poskusi INSERT, nato UPDATE z ON DUPLICATE KEY
        $sql = "
            INSERT INTO guests
                (restaurant_id, email, first_name, last_name, phone,
                 first_visit, last_visit, total_visits, total_covers, no_shows)
            VALUES (?, ?, ?, ?, ?,
                    ?, ?, 1, ?, ?)
            ON DUPLICATE KEY UPDATE
                first_name   = IF(first_name = '' OR first_name IS NULL, VALUES(first_name), first_name),
                last_name    = IF(last_name  = '' OR last_name  IS NULL, VALUES(last_name),  last_name),
                phone        = IF(phone      = '' OR phone      IS NULL, VALUES(phone),      phone),
                first_visit  = IF(first_visit IS NULL OR VALUES(first_visit) < first_visit, VALUES(first_visit), first_visit),
                last_visit   = IF(last_visit IS NULL OR VALUES(last_visit) > last_visit, VALUES(last_visit), last_visit),
                total_visits = total_visits + 1,
                total_covers = total_covers + VALUES(total_covers),
                no_shows     = no_shows + VALUES(no_shows)
        ";
        $pdo->prepare($sql)->execute([
            $restaurant_id,
            strtolower(trim($email)),
            $firstName,
            $lastName,
            $phone ?: null,
            $visitDate,
            $visitDate,
            $guestCount,
            $isNoShow,
        ]);
    } catch (PDOException $e) {
        // Tabela morda še ne obstaja – tiho nadaljuj
        error_log('guest_helper upsert_guest: ' . $e->getMessage());
    }
}

/**
 * Vrne profil gosta (ali null) za dani restaurant_id in email.
 */
function get_guest_profile(PDO $pdo, int $restaurant_id, string $email): ?array {
    if (!$email) return null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM guests WHERE restaurant_id = ? AND email = ?");
        $stmt->execute([$restaurant_id, strtolower(trim($email))]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Vrne zadnjih N rezervacij gosta za restavracijo.
 */
function get_guest_history(PDO $pdo, int $restaurant_id, string $email, int $limit = 10): array {
    if (!$email) return [];
    try {
        $stmt = $pdo->prepare("
            SELECT id, reservation_date, reservation_time, guest_count, status, notes
            FROM reservations
            WHERE restaurant_id = ? AND LOWER(email) = ?
            ORDER BY reservation_date DESC, reservation_time DESC
            LIMIT ?
        ");
        $stmt->execute([$restaurant_id, strtolower(trim($email)), $limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}
