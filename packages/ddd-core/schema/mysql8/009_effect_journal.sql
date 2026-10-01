-- tangible/ddd-core Defaults/Pdo: D1 external-effect journal (register 3.8,
-- IEffectJournal; wave 4). One row per declared idempotencyKey(), the same
-- shape as ddd-symfony's 009_effect_journal. find() returns result_json /
-- external_ref while invalidated_at IS NULL; store() upserts and clears the
-- invalidation; invalidate() (the explicit repair path, inside the repair
-- command's transaction) sets invalidated_at and keeps the reason and a
-- counter for the operator.
--
-- The key is VARCHAR(191) (an index limit of utf8mb4); a longer
-- idempotency key is refused with a \RuntimeException at store().
-- result_json is LONGTEXT, not JSON: EffectResult::$data round-trips byte
-- for byte (MySQL's JSON type normalises key order).
--
-- Schema evolution is append-only (L5): new in wave 4.

CREATE TABLE IF NOT EXISTS `{{prefix}}ddd_effect_journal` (
  `idempotency_key`     VARCHAR(191) NOT NULL,
  `result_json`         LONGTEXT     NOT NULL,
  `external_ref`        TEXT         NULL,
  `performed_at`        DATETIME(6)  NOT NULL,
  `invalidated_at`      DATETIME(6)  NULL,
  `invalidation_reason` TEXT         NULL,
  `invalidations`       INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;
