-- tangible/ddd-symfony: durable wakeup intents (register 3.6, 5.3, D7).
-- The intent row is the source of truth; the ddd_wakeups Messenger transport is a
-- projection. schedule() inserts inside the process transaction (ON CONFLICT on the
-- idempotency key = no-op); the wakeup relay leases due rows (claim_token,
-- lease_until) and sends one message per lease; the handler completes (deletes) the
-- row fenced on the claim token, or retries it later (attempts, next_attempt_at).
-- A lost message or a crash between save and send leaves the row due, and the next
-- relay tick re-projects it once the lease expires.
-- exhausted_at marks an intent whose wake budget (5.1: 10 attempts) ran out or whose
-- wake failed for a non-retryable reason; it stays for the operator (ddd:ops:stranded)
-- until it completes, or is repaired or cancelled. With next_attempt_at set (a
-- retryable failure past the budget) it is still claimed at the cap and stays live;
-- with next_attempt_at NULL (not retryable) it is never claimed again and no longer
-- counts as a live intent for the stranded scan.

CREATE TABLE IF NOT EXISTS {{prefix}}ddd_wakeups (
    id               BIGSERIAL    PRIMARY KEY,
    idempotency_key  VARCHAR(255) NOT NULL,
    kind             VARCHAR(16)  NOT NULL,
    consumer         VARCHAR(64)  NOT NULL,
    process_id       BIGINT       NULL,
    step_index       INTEGER      NULL,
    expected_status  VARCHAR(16)  NULL,
    due_at           TIMESTAMPTZ  NOT NULL,
    next_attempt_at  TIMESTAMPTZ  NULL,
    attempts         INTEGER      NOT NULL DEFAULT 0,
    claim_token      VARCHAR(64)  NULL,
    lease_until      TIMESTAMPTZ  NULL,
    last_error       TEXT         NULL,
    exhausted_at     TIMESTAMPTZ  NULL,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT {{prefix}}ddd_wakeups_key UNIQUE (idempotency_key),
    CONSTRAINT {{prefix}}ddd_wakeups_kind_check CHECK (kind IN ('continue', 'timeout', 'resume_retry', 'deliver'))
);

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_wakeups_due_idx
    ON {{prefix}}ddd_wakeups (due_at, id);

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_wakeups_process_idx
    ON {{prefix}}ddd_wakeups (process_id)
    WHERE process_id IS NOT NULL;
