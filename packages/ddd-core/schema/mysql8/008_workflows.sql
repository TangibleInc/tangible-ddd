-- tangible/ddd-core Defaults/Pdo: behaviour workflows (D10, ruling #78, O8;
-- wave 4). Same shape as ddd-symfony's 008_workflows (and the WordPress
-- behaviour_workflows / _meta / _items tables, without blog_id on the
-- workflow row). behaviour_configs and behaviour_results are JSON text.
--
-- ddd_workflow_ignitions is the workflow ignition ledger
-- (IWorkflowIgnitionLedger): one row per caller-supplied dedup key, whose
-- primary key is the gate. The first claim inserts and wins; a later claim
-- of the same key gets MySQL 1062 and loses (workflow.fact-ignition-once).
-- WorkflowIgniter's start markers are ordinary rows of the same table.
--
-- Schema evolution is append-only (L5): new in wave 4.

CREATE TABLE IF NOT EXISTS `{{prefix}}ddd_behaviour_workflows` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref_id`            BIGINT          NOT NULL,
  `ref_type`          VARCHAR(64)     NOT NULL,
  `root_workflow_id`  BIGINT UNSIGNED NULL,
  `behaviour_configs` LONGTEXT        NOT NULL,
  `behaviour_results` LONGTEXT        NOT NULL,
  `current_idx`       INT             NOT NULL DEFAULT 0,
  `current_phase`     INT             NOT NULL DEFAULT 1,
  `is_complete`       TINYINT(1)      NOT NULL DEFAULT 0,
  `is_failed`         TINYINT(1)      NOT NULL DEFAULT 0,
  `correlation_id`    VARCHAR(64)     NULL,
  `created_at`        DATETIME(6)     NOT NULL,
  `updated_at`        DATETIME(6)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ref` (`ref_type`, `ref_id`),
  KEY `idx_root` (`root_workflow_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `{{prefix}}ddd_behaviour_workflow_meta` (
  `meta_id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `workflow_id` BIGINT UNSIGNED NOT NULL,
  `meta_key`    VARCHAR(191)    NOT NULL,
  `meta_value`  LONGTEXT        NULL,
  PRIMARY KEY (`meta_id`),
  KEY `idx_workflow_key` (`workflow_id`, `meta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `{{prefix}}ddd_behaviour_workflow_items` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `workflow_id`   BIGINT UNSIGNED NOT NULL,
  `behaviour_idx` INT             NOT NULL,
  `phase`         INT             NOT NULL DEFAULT 1,
  `item_key`      VARCHAR(191)    NOT NULL,
  `status`        VARCHAR(16)     NOT NULL DEFAULT 'pending',
  `attempts`      INT             NOT NULL DEFAULT 0,
  `last_error`    TEXT            NULL,
  `payload`       LONGTEXT        NULL,
  `blog_id`       INT             NOT NULL DEFAULT 1,
  `created_at`    DATETIME(6)     NOT NULL,
  `updated_at`    DATETIME(6)     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_item` (`workflow_id`, `behaviour_idx`, `phase`, `item_key`),
  KEY `idx_step` (`workflow_id`, `behaviour_idx`, `phase`, `status`),
  CONSTRAINT `{{prefix}}ddd_behaviour_workflow_items_status_chk` CHECK (`status` IN ('pending', 'waiting', 'failed', 'done', 'skipped', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `{{prefix}}ddd_workflow_ignitions` (
  `dedup_key`   VARCHAR(191)    NOT NULL,
  `kind`        VARCHAR(191)    NOT NULL,
  `workflow_id` BIGINT UNSIGNED NULL,
  `event_id`    VARCHAR(64)     NULL,
  `created_at`  DATETIME(6)     NOT NULL,
  PRIMARY KEY (`dedup_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;
