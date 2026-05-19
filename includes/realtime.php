<?php
/**
 * Modul 7: Real-time sync helper.
 *
 * Vstavlja event-e v `realtime_events` ob spremembah rezervacij.
 * SSE endpoint (`api/events.php`) ta tabela polluje in stream-a klientom.
 *
 * Tabela: sql/migrate_realtime.sql
 */

/**
 * Vstavi realtime event. Tihi fail – če tabela ne obstaja ali payload ni serializable,
 * ne razbije glavnega flow-a (rezervacija mora biti shranjena tudi če event fail-a).
 *
 * @param PDO    $pdo
 * @param int    $restaurant_id  Tenant id (zahtevan, vse klijente filtriramo po tem).
 * @param string $event_type     Eden od ENUM vrednosti v migrate_realtime.sql.
 * @param array  $payload        Asociativni array – serializira se v JSON.
 */
function insert_realtime_event(PDO $pdo, int $restaurant_id, string $event_type, array $payload): void {
    if ($restaurant_id <= 0) return;
    $allowed = [
        'reservation_added',
        'reservation_updated',
        'reservation_deleted',
        'reservation_arrived',
        'reservation_noshow',
        'reservation_status_changed',
    ];
    if (!in_array($event_type, $allowed, true)) return;

    try {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return;
        $stmt = $pdo->prepare(
            "INSERT INTO realtime_events (restaurant_id, event_type, payload) VALUES (?, ?, ?)"
        );
        $stmt->execute([$restaurant_id, $event_type, $json]);
    } catch (Throwable $e) {
        error_log('insert_realtime_event failed: ' . $e->getMessage());
    }
}

/**
 * Pobriši event-e starejše od $minutes minut. Kliče se inline iz SSE loop-a
 * (~1x na 30s na klijenta) ali iz crona. Idempotenten.
 */
function cleanup_realtime_events(PDO $pdo, int $minutes = 10): void {
    try {
        $pdo->prepare("DELETE FROM realtime_events WHERE created_at < (NOW() - INTERVAL ? MINUTE)")
            ->execute([$minutes]);
    } catch (Throwable $e) {
        error_log('cleanup_realtime_events failed: ' . $e->getMessage());
    }
}
