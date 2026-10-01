-- tangible/ddd-core Defaults/Pdo: long-running processes (register 3.8, X7, 5.2, 5.3).
-- Same column layout as the wp `long_processes` table (business_data, steps,
-- payload and await_mechanism as JSON), plus:
--   ignition_key       uuid5(event_id, process_class), set ONLY by the
--                      #[StartsOn] ignition path; NULL for manual starts.
--                      UNIQUE (process_class, ignition_key): NULLs never collide.
--   quarantine_reason  non-NULL when a row could not be decoded (status `failed`).
--   version            fencing counter; every save/touch is
--                      `WHERE id = ? AND version = ?`.

CREATE TABLE IF NOT EXISTS `{{prefix}}ddd_processes` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `process_class`       VARCHAR(191)    NOT NULL,
  `business_data`       LONGTEXT        NULL,
  `steps`               LONGTEXT        NULL,
  `step_index`          INT             NOT NULL DEFAULT 0,
  `step_name`           VARCHAR(191)    NULL,
  `status`              VARCHAR(32)     NOT NULL,
  `waiting_for`         VARCHAR(191)    NULL,
  `match_criteria`      LONGTEXT        NULL,
  `await_mechanism`     LONGTEXT        NULL,
  `payload`             LONGTEXT        NULL,
  `correlation_id`      VARCHAR(64)     NOT NULL,
  `ignited_by_event_id` VARCHAR(64)     NULL,
  `ignition_key`        VARCHAR(64)     NULL,
  `source`              VARCHAR(16)     NULL,
  `last_error`          TEXT            NULL,
  `quarantine_reason`   TEXT            NULL,
  `version`             INT UNSIGNED    NOT NULL DEFAULT 1,
  `blog_id`             INT             NULL,
  `created_at`          DATETIME(6)     NOT NULL,
  `updated_at`          DATETIME(6)     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ignition` (`process_class`, `ignition_key`),
  KEY `idx_waiting` (`status`, `waiting_for`),
  KEY `idx_stranded` (`status`, `updated_at`),
  KEY `idx_ignited_by` (`process_class`, `ignited_by_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;
