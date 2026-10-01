-- tangible/ddd-symfony: behaviour workflows (D10, ruling #78). Same shape as the
-- WordPress behaviour_workflows / behaviour_workflows_meta / behaviour_workflow_items
-- tables, without blog_id on the workflow row (one tenant per Symfony app).
-- behaviour_configs and behaviour_results are JSON text.
--
-- ddd_workflow_ignitions is the workflow ignition ledger: one row per caller-supplied
-- dedup key (for #[StartsOn] workflows, uuid5(event_id, workflow kind); for a cron
-- tick, e.g. "CronEntryDue:<entry>:<minute>"). The primary key is the gate: the
-- first claim inserts and wins, every later claim of the same key is told who won
-- (workflow.fact-ignition-once). Wired to the core D10 contract in wave 4.

CREATE TABLE IF NOT EXISTS {{prefix}}ddd_behaviour_workflows (
    id                BIGSERIAL    PRIMARY KEY,
    ref_id            BIGINT       NOT NULL,
    ref_type          VARCHAR(64)  NOT NULL,
    root_workflow_id  BIGINT       NULL,
    behaviour_configs TEXT         NOT NULL,
    behaviour_results TEXT         NOT NULL,
    current_idx       INTEGER      NOT NULL DEFAULT 0,
    current_phase     INTEGER      NOT NULL DEFAULT 1,
    is_complete       BOOLEAN      NOT NULL DEFAULT FALSE,
    is_failed         BOOLEAN      NOT NULL DEFAULT FALSE,
    correlation_id    VARCHAR(64)  NULL,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_behaviour_workflows_ref_idx
    ON {{prefix}}ddd_behaviour_workflows (ref_type, ref_id);

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_behaviour_workflows_root_idx
    ON {{prefix}}ddd_behaviour_workflows (root_workflow_id);

CREATE TABLE IF NOT EXISTS {{prefix}}ddd_behaviour_workflow_meta (
    meta_id     BIGSERIAL    PRIMARY KEY,
    workflow_id BIGINT       NOT NULL REFERENCES {{prefix}}ddd_behaviour_workflows (id) ON DELETE CASCADE,
    meta_key    VARCHAR(191) NOT NULL,
    meta_value  TEXT         NULL
);

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_behaviour_workflow_meta_idx
    ON {{prefix}}ddd_behaviour_workflow_meta (workflow_id, meta_key);

CREATE TABLE IF NOT EXISTS {{prefix}}ddd_behaviour_workflow_items (
    id            BIGSERIAL    PRIMARY KEY,
    workflow_id   BIGINT       NOT NULL,
    behaviour_idx INTEGER      NOT NULL,
    phase         INTEGER      NOT NULL DEFAULT 1,
    item_key      VARCHAR(191) NOT NULL,
    status        VARCHAR(16)  NOT NULL DEFAULT 'pending',
    attempts      INTEGER      NOT NULL DEFAULT 0,
    last_error    TEXT         NULL,
    payload       TEXT         NULL,
    blog_id       INTEGER      NOT NULL DEFAULT 1,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT {{prefix}}ddd_behaviour_workflow_items_uniq UNIQUE (workflow_id, behaviour_idx, phase, item_key),
    CONSTRAINT {{prefix}}ddd_behaviour_workflow_items_status_check CHECK (status IN ('pending', 'waiting', 'failed', 'done', 'skipped', 'cancelled'))
);

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_behaviour_workflow_items_step_idx
    ON {{prefix}}ddd_behaviour_workflow_items (workflow_id, behaviour_idx, phase, status);

CREATE TABLE IF NOT EXISTS {{prefix}}ddd_workflow_ignitions (
    dedup_key   VARCHAR(255) NOT NULL PRIMARY KEY,
    kind        VARCHAR(255) NOT NULL,
    workflow_id BIGINT       NULL,
    event_id    VARCHAR(64)  NULL,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
