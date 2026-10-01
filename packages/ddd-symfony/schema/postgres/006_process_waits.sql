-- tangible/ddd-symfony: what each suspended process waits for (register 3.8, D3, E F14).
-- One row per (process, awaited fact class, await key). DbalProcessStore rewrites a
-- process's rows in the same transaction as every save, so the index never disagrees
-- with the process row. findWaitingFor() reads ids from here only.
-- await_key is '' for unkeyed awaits; keyed awaits (D3) arrive in wave 4.

CREATE TABLE IF NOT EXISTS {{prefix}}ddd_process_waits (
    process_id  BIGINT       NOT NULL REFERENCES {{prefix}}ddd_processes (id) ON DELETE CASCADE,
    event_class VARCHAR(255) NOT NULL,
    await_key   VARCHAR(255) NOT NULL DEFAULT '',
    step_index  INTEGER      NOT NULL,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY (process_id, event_class, await_key)
);

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_process_waits_lookup_idx
    ON {{prefix}}ddd_process_waits (event_class, await_key);
