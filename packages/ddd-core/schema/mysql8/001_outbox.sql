-- tangible/ddd-core Defaults/Pdo: transactional outbox (register 3.4). MySQL 8.0.
-- The HOST applies these files with its own tooling; ddd-core never creates
-- tables (SchemaCheck only verifies them). {{prefix}} is the table prefix the
-- adapters are constructed with (SchemaSql::dump() substitutes it).
--
-- Status vocabulary: pending | accepted | dlq | cancelled.
-- Every time column is DATETIME(6) holding UTC, written by the adapters from
-- IClock (never NOW(), so the session time zone does not matter). due_at is
-- ABSOLUTE, set once at append (bug 3); retries are gated by next_attempt_at.
-- A claim writes claim_token + lease_until; accept, retryLater and deadLetter
-- are fenced on (event_id, claim_token, status = 'pending').
-- payload_signature is sha256 of the canonical is_unique signature (the
-- match key for cancelling duplicates); signature_json keeps the signature.
-- event_class is the fact's PHP class when the writer knows it (NULL otherwise).
-- Every table uses utf8mb4_bin: event ids, types and class names compare
-- case-sensitively, as PHP and fnmatch() do.

CREATE TABLE IF NOT EXISTS `{{prefix}}ddd_outbox` (
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
  `status`             VARCHAR(16)     NOT NULL DEFAULT 'pending',
  `attempts`           INT             NOT NULL DEFAULT 0,
  `max_attempts`       INT             NOT NULL DEFAULT 5,
  `due_at`             DATETIME(6)     NOT NULL,
  `next_attempt_at`    DATETIME(6)     NOT NULL,
  `claim_token`        VARCHAR(64)     NULL,
  `lease_until`        DATETIME(6)     NULL,
  `transport_ref`      VARCHAR(255)    NULL,
  `last_error`         TEXT            NULL,
  `blog_id`            INT             NULL,
  `created_at`         DATETIME(6)     NOT NULL,
  `accepted_at`        DATETIME(6)     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_event_id` (`event_id`),
  KEY `idx_claim` (`status`, `due_at`, `id`),
  KEY `idx_unique` (`event_type`, `payload_signature`, `status`),
  KEY `idx_purge` (`status`, `accepted_at`),
  CONSTRAINT `{{prefix}}ddd_outbox_status_chk` CHECK (`status` IN ('pending', 'accepted', 'dlq', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;
