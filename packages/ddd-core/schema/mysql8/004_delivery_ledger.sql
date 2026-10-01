-- tangible/ddd-core Defaults/Pdo: per-(subscriber, event_id) delivery ledger
-- (register 3.5, 5.1; CR-1). attempts is the handler-execution budget counter.
-- exhausted_at is the TERMINAL marker, written only after the subscriber's
-- onExhausted compensation returned. last_error is the latest failed attempt's error.

CREATE TABLE IF NOT EXISTS `{{prefix}}ddd_delivery_ledger` (
  `subscriber_id` VARCHAR(255) NOT NULL,
  `event_id`      VARCHAR(64)  NOT NULL,
  `attempts`      INT          NOT NULL DEFAULT 0,
  `delivered_at`  DATETIME(6)  NULL,
  `last_error`    TEXT         NULL,
  `exhausted_at`  DATETIME(6)  NULL,
  `updated_at`    DATETIME(6)  NOT NULL,
  PRIMARY KEY (`subscriber_id`, `event_id`),
  KEY `idx_event_id` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;
