-- tangible/ddd-symfony: effect entry states and parked facts (wave 5; E2, AW2).
-- ddd_effect_journal.recorded_at: record() committed with the entry's result
--   (ITracksEffectState::mark_recorded(), inside record()'s transaction). NULL
--   = performed, not recorded yet; store() resets it. The partial index serves
--   find_unrecorded() and the operator layer `effect`. Entries stored before
--   this file have recorded_at NULL and list as unrecorded once older than
--   the operator threshold; invalidate them or mark them by hand.
-- ddd_wakeups.fact: the fact a parked resume carries (WakeupIntent::$fact,
--   ICarriesFacts): {"class", "payload", "event_id"}. JSON (not JSONB), so the
--   payload's key order round-trips. NULL for every other intent.

ALTER TABLE {{prefix}}ddd_effect_journal ADD COLUMN IF NOT EXISTS recorded_at TIMESTAMPTZ NULL;

CREATE INDEX IF NOT EXISTS {{prefix}}ddd_effect_journal_unrecorded_idx
    ON {{prefix}}ddd_effect_journal (performed_at)
    WHERE recorded_at IS NULL AND invalidated_at IS NULL;

ALTER TABLE {{prefix}}ddd_wakeups ADD COLUMN IF NOT EXISTS fact JSON NULL;
