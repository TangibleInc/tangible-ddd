-- tangible/ddd-symfony: D1 external-effect journal (register 3.8, IEffectJournal).
-- One row per declared idempotencyKey(). find() returns result_json / external_ref
-- while invalidated_at IS NULL; store() upserts and clears the invalidation;
-- invalidate() (the explicit repair path, inside the repair command's transaction)
-- sets invalidated_at and keeps the reason and a counter for the operator.
-- result_json is TEXT, not JSONB: EffectResult::$data round-trips byte for byte
-- (JSONB would reorder keys).

CREATE TABLE IF NOT EXISTS {{prefix}}ddd_effect_journal (
    idempotency_key     TEXT         NOT NULL PRIMARY KEY,
    result_json         TEXT         NOT NULL,
    external_ref        TEXT         NULL,
    performed_at        TIMESTAMPTZ  NOT NULL,
    invalidated_at      TIMESTAMPTZ  NULL,
    invalidation_reason TEXT         NULL,
    invalidations       INTEGER      NOT NULL DEFAULT 0
);
