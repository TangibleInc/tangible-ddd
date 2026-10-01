-- tangible/ddd-core Defaults/Pdo: durable jobs (register 3.5, 3.6, 5.3).
-- One table for both kinds of work Drain::runOnce() executes:
--   * wakeup intents (kind continue | timeout | resume_retry), written by
--     IWakeupScheduler::schedule() in the process transaction;
--   * fact deliveries (kind deliver), one row per fact, written by the relay
--     through ITransport::submit() on the same connection as the outbox.
-- idempotency_key is unique: scheduling an existing key is a no-op. due_at is
-- ABSOLUTE UTC; retries are gated by next_attempt_at. A claim writes
-- claim_token + lease_until; complete() deletes the row, retryLater() bumps
-- attempts, both fenced on (idempotency_key, claim_token).
-- envelope is the wrapped integration payload (JSON) of a deliver job.

CREATE TABLE IF NOT EXISTS `{{prefix}}ddd_jobs` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `idempotency_key`    VARCHAR(191)    NOT NULL,
  `kind`               VARCHAR(16)     NOT NULL,
  `consumer`           VARCHAR(64)     NOT NULL,
  `process_id`         BIGINT UNSIGNED NULL,
  `step_index`         INT             NULL,
  `expected_status`    VARCHAR(32)     NULL,
  `event_id`           VARCHAR(64)     NULL,
  `event_type`         VARCHAR(191)    NULL,
  `event_class`        VARCHAR(255)    NULL,
  `integration_action` VARCHAR(255)    NULL,
  `envelope`           LONGTEXT        NULL,
  `due_at`             DATETIME(6)     NOT NULL,
  `next_attempt_at`    DATETIME(6)     NOT NULL,
  `attempts`           INT             NOT NULL DEFAULT 0,
  `claim_token`        VARCHAR(64)     NULL,
  `lease_until`        DATETIME(6)     NULL,
  `last_error`         TEXT            NULL,
  `created_at`         DATETIME(6)     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_idempotency_key` (`idempotency_key`),
  KEY `idx_due` (`next_attempt_at`, `due_at`, `id`),
  KEY `idx_process` (`process_id`),
  CONSTRAINT `{{prefix}}ddd_jobs_kind_chk` CHECK (`kind` IN ('continue', 'timeout', 'resume_retry', 'deliver'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;
