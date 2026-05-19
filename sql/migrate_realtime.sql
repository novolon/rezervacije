-- Modul 7: Real-time sync (SSE)
-- Zaženi enkrat na produkcijski bazi

CREATE TABLE IF NOT EXISTS realtime_events (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_id INT UNSIGNED NOT NULL,
  event_type    ENUM(
                  'reservation_added',
                  'reservation_updated',
                  'reservation_deleted',
                  'reservation_arrived',
                  'reservation_noshow',
                  'reservation_status_changed'
                ) NOT NULL,
  payload       JSON NOT NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_restaurant_created (restaurant_id, created_at),
  INDEX idx_restaurant_id      (restaurant_id, id),
  FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
);
