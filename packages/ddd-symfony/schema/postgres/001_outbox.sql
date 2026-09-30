-- tangible/ddd-symfony: transactional outbox (register 3.4). Postgres 16.
-- {{prefix}} is the configured table prefix (tangible_ddd.table_prefix, default '');
-- `bin/console ddd:schema:dump` prints these files with it substituted.
--
-- Status vocabulary: pending | accepted | dlq | cancelled.
-- due_at is ABSOLUTE UTC, set once at append (bug 3); retries are gated by
-- next_attempt_at only. A claim writes claim_token + lease_until; accept,
-- retryLater and deadLetter are fenced on (event_id, claim_token).
-- payload is JSON text, not jsonb: jsonb rejects \u0000 (report E F11).

CREATE TABLE IF NOT EXISTS {{prefix}}ddd_outbox (
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
    status             VARCHAR(16)  NOT NULL DEFAULT 'pending',
    attempts           INTEGER      NOT NULL DEFAULT 0,
    max_attempts       INTEGER      NOT NULL DEFAULT 5,
    due_at             TIMESTAMPTZ  NOT NULL,
    next_attempt_at    TIMESTAMPTZ  NOT NULL,
    claim_token        VARCHAR(64)  NULL,
    lease_until        TIMESTAMPTZ  NULL,
    transport_ref      VARCHAR(255) NULL,
    last_error         TEXT         NULL,
    blog_id            INTEGER      NULL,
    created_at         TIMESTAMPTZ  NOT NULL DEFAULT now(),
    accepted_at        TIMESTAMPTZ  NULL,
    CONSTRAINT {{prefix}}ddd_outbox_event_id_key UNIQUE (event_id),
    CONSTRAINT {{prefix}}ddd_outbox_status_check CHECK (status IN ('pending', 'accepted', 'dlq', 'cancelled'))
);

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_outbox_claim_idx
    ON {{prefix}}ddd_outbox (due_at, id)
    WHERE status = 'pending';

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_outbox_unique_idx
    ON {{prefix}}ddd_outbox (event_type, payload_signature)
    WHERE status = 'pending' AND payload_signature IS NOT NULL;

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_outbox_purge_idx
    ON {{prefix}}ddd_outbox (accepted_at)
    WHERE status = 'accepted';
