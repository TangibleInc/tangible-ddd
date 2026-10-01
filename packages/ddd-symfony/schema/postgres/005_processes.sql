-- tangible/ddd-symfony: long-running processes (register 3.8, X7, 5.2, 5.3). Postgres 16.
--
-- Same columns as the WordPress long_processes table (business_data, steps,
-- payload and await_mechanism are JSON text), plus:
-- - ignition_key: uuid5(event_id, process_class), set ONLY by the #[StartsOn]
--   ignition path. UNIQUE (process_class, ignition_key) is the bug-2 gate;
--   manual starts leave it NULL and NULLs never collide (X7).
-- - quarantine_reason: set with status `failed` when a row cannot be decoded
--   (unknown class, broken JSON). No new status value is written (R5).
-- - version: every save and touch is `WHERE id = ? AND version = ?` (5.2 fencing).
-- Status vocabulary: pending | running | scheduled | suspended | completed | failed.

CREATE TABLE IF NOT EXISTS {{prefix}}ddd_processes (
    id                  BIGSERIAL    PRIMARY KEY,
    process_class       VARCHAR(255) NOT NULL,
    business_data       TEXT         NOT NULL,
    steps               TEXT         NULL,
    step_index          INTEGER      NOT NULL DEFAULT 0,
    step_name           VARCHAR(128) NULL,
    status              VARCHAR(16)  NOT NULL DEFAULT 'pending',
    waiting_for         VARCHAR(255) NULL,
    match_criteria      TEXT         NULL,
    await_mechanism     TEXT         NULL,
    payload             TEXT         NULL,
    correlation_id      VARCHAR(64)  NOT NULL,
    ignited_by_event_id VARCHAR(64)  NULL,
    ignition_key        VARCHAR(64)  NULL,
    source              VARCHAR(16)  NULL,
    last_error          TEXT         NULL,
    quarantine_reason   TEXT         NULL,
    version             INTEGER      NOT NULL DEFAULT 1,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT {{prefix}}ddd_processes_ignition_key UNIQUE (process_class, ignition_key),
    CONSTRAINT {{prefix}}ddd_processes_status_check CHECK (status IN ('pending', 'running', 'scheduled', 'suspended', 'completed', 'failed'))
);

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_processes_open_idx
    ON {{prefix}}ddd_processes (status, updated_at)
    WHERE status IN ('running', 'scheduled', 'suspended');

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_processes_ignited_by_idx
    ON {{prefix}}ddd_processes (ignited_by_event_id)
    WHERE ignited_by_event_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_processes_correlation_idx
    ON {{prefix}}ddd_processes (correlation_id);
