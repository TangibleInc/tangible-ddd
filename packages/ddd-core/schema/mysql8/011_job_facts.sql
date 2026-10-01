-- tangible/ddd-core Defaults/Pdo: the fact a parked resume carries (wave 5;
-- AW2, ICarriesFacts). One row per wakeup job of ddd_jobs whose intent has
-- a fact (WakeupIntent::resume_fact(): a resume that could not take the
-- process lock): {"class", "payload", "event_id"} as JSON text, written by
-- schedule() in the same transaction as the job row, returned by
-- claim_due(), deleted with the job by complete() and cancel().
--
-- A side table keyed like ddd_jobs, not a column: MySQL 8 has no ADD COLUMN
-- IF NOT EXISTS, so new state goes in a new table (L5). This is the pdo form
-- of the nullable fact column on ddd-symfony's ddd_wakeups (schema 011).
-- fact is LONGTEXT, not JSON: the payload's key order round-trips.

CREATE TABLE IF NOT EXISTS `{{prefix}}ddd_job_facts` (
  `idempotency_key` VARCHAR(191) NOT NULL,
  `fact`            LONGTEXT     NOT NULL,
  PRIMARY KEY (`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC;
