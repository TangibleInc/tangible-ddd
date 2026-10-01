-- tangible/ddd-core Defaults/Pdo: what each suspended process waits for
-- (register 3.8, D3, E F14; wave 4). One row per route of the await
-- (LongProcess::await_routes(): awaited fact class, await key), key '' =
-- unkeyed. PdoProcessStore rewrites a process's rows in the same transaction
-- as every insert and save, so the index never disagrees with the process
-- row; a process that is not suspended has no rows.
--
-- findWaitingFor($class, $key) matches event_class against the fact class,
-- its parents and its interfaces, and narrows by key (null = any key, '' =
-- unkeyed rows, otherwise that key). A suspended process with no rows here
-- (written before this file was applied) is still found through its
-- `waiting_for` column by the unkeyed lookups.
--
-- Schema evolution is append-only (L5): this file is new in wave 4; the
-- shipped 001-006 are never edited.

CREATE TABLE IF NOT EXISTS `{{prefix}}ddd_process_waits` (
  `process_id`  BIGINT UNSIGNED NOT NULL,
  `event_class` VARCHAR(191)    NOT NULL,
  `await_key`   VARCHAR(191)    NOT NULL DEFAULT '',
  `step_index`  INT             NOT NULL,
  `created_at`  DATETIME(6)     NOT NULL,
  PRIMARY KEY (`process_id`, `event_class`, `await_key`),
  KEY `idx_lookup` (`event_class`, `await_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;
