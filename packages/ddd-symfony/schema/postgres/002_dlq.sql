-- tangible/ddd-symfony: relay dead-letter queue (register 3.4, 5.1 layer `relay`).
-- deadLetter() inserts here and sets the outbox row to `dlq` in one transaction;
-- the outbox row stays, so replay keeps event_id (C22) and deletes this row.

CREATE TABLE IF NOT EXISTS {{prefix}}ddd_dlq (
    id                 BIGSERIAL    PRIMARY KEY,
    event_id           VARCHAR(64)  NOT NULL,
    event_type         VARCHAR(255) NOT NULL,
    event_class        VARCHAR(255) NULL,
    integration_action VARCHAR(255) NOT NULL,
    correlation_id     VARCHAR(64)  NULL,
    sequence           INTEGER      NULL,
    command_id         VARCHAR(64)  NULL,
    payload            TEXT         NOT NULL,
    payload_signature  VARCHAR(64)  NULL,
    is_unique          BOOLEAN      NOT NULL DEFAULT FALSE,
    max_attempts       INTEGER      NOT NULL DEFAULT 5,
    due_at             TIMESTAMPTZ  NOT NULL,
    blog_id            INTEGER      NULL,
    error              TEXT         NOT NULL,
    attempts           INTEGER      NOT NULL,
    dead_lettered_at   TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_dlq_event_idx ON {{prefix}}ddd_dlq (event_id);
