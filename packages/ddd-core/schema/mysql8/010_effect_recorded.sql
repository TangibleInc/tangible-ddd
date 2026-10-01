-- tangible/ddd-core Defaults/Pdo: effect entry states (wave 5; E2,
-- ITracksEffectState). One row per journal entry whose record() committed:
-- mark_recorded() inserts it inside record()'s transaction, so the mark
-- commits or rolls back with record(); store() deletes it (the entry is
-- Performed again). An entry of ddd_effect_journal without a row here is
-- Performed, not recorded: find_unrecorded() and the operator layer
-- `effect` list it once it is older than the threshold.
--
-- A side table, not a column: MySQL 8 has no ADD COLUMN IF NOT EXISTS, so
-- new state goes in a new table (L5). This is the pdo form of ddd-symfony's
-- ddd_effect_journal.recorded_at (schema 011).
--
-- Entries stored before this file have no row and list as unrecorded once
-- older than the threshold; invalidate them or insert their row by hand.

CREATE TABLE IF NOT EXISTS `{{prefix}}ddd_effect_recorded` (
  `idempotency_key` VARCHAR(191) NOT NULL,
  `recorded_at`     DATETIME(6)  NOT NULL,
  PRIMARY KEY (`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;
