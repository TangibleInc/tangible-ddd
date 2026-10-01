-- tangible/ddd-symfony: per-(subscriber, event_id) delivery ledger (register 3.5, 5.1; CR-1).
-- attempts is the handler-execution budget counter. exhausted_at is the TERMINAL
-- marker, written only after the subscriber's onExhausted compensation returned.
-- last_error is the error of the latest failed attempt.

CREATE TABLE IF NOT EXISTS {{prefix}}ddd_delivery_ledger (
    subscriber_id VARCHAR(255) NOT NULL,
    event_id      VARCHAR(64)  NOT NULL,
    attempts      INTEGER      NOT NULL DEFAULT 0,
    delivered_at  TIMESTAMPTZ  NULL,
    last_error    TEXT         NULL,
    exhausted_at  TIMESTAMPTZ  NULL,
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY (subscriber_id, event_id)
);

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_delivery_ledger_open_idx
    ON {{prefix}}ddd_delivery_ledger (event_id)
    WHERE delivered_at IS NULL AND exhausted_at IS NULL;
