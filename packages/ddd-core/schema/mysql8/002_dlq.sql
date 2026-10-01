-- tangible/ddd-core Defaults/Pdo: relay dead-letter queue (register 3.4, 5.1 layer `relay`).
-- deadLetter() inserts here and sets the outbox row to `dlq` in one
-- transaction; the outbox row stays, so replay keeps event_id (C22) and
-- deletes this row. retry() of a dead-lettered row deletes its entries (sfc-5).

CREATE TABLE IF NOT EXISTS `{{prefix}}ddd_dlq` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id`           VARCHAR(64)     NOT NULL,
  `event_type`         VARCHAR(191)    NOT NULL,
  `event_class`        VARCHAR(255)    NULL,
  `integration_action` VARCHAR(255)    NOT NULL,
  `correlation_id`     VARCHAR(64)     NULL,
  `sequence`           INT             NULL,
  `command_id`         VARCHAR(64)     NULL,
  `payload`            LONGTEXT        NOT NULL,
  `payload_signature`  CHAR(64)        NULL,
  `signature_json`     LONGTEXT        NULL,
  `is_unique`          TINYINT(1)      NOT NULL DEFAULT 0,
  `max_attempts`       INT             NOT NULL DEFAULT 5,
  `due_at`             DATETIME(6)     NOT NULL,
  `blog_id`            INT             NULL,
  `error`              TEXT            NOT NULL,
  `attempts`           INT             NOT NULL,
  `dead_lettered_at`   DATETIME(6)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_event_id` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;
