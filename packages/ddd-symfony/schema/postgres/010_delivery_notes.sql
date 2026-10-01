-- tangible/ddd-symfony: operator notes on delivered facts (wave 5; AW3, E3).
-- ddd_outbox.unheard_at: the relay found no subscriber for the fact in any
--   consumer (FactDeliveredUnheard); the row stays accepted, the note is for
--   the operator view.
-- ddd_delivery_ledger.unheard_at: a resume subscriber's fact reached no
--   waiting process (ResumeReport::is_unheard(); a misrouted key, a duplicate
--   or a precheck-absorbed answer). Kept for forensics, not listed as a failure.
-- ddd_delivery_ledger.failure_command / failure_command_at: the D1 failure
--   command an exhausted pair sent, and when (written after it returned).

ALTER TABLE {{prefix}}ddd_outbox ADD COLUMN IF NOT EXISTS unheard_at TIMESTAMPTZ NULL;

ALTER TABLE {{prefix}}ddd_delivery_ledger ADD COLUMN IF NOT EXISTS unheard_at TIMESTAMPTZ NULL;

ALTER TABLE {{prefix}}ddd_delivery_ledger ADD COLUMN IF NOT EXISTS failure_command VARCHAR(255) NULL;

ALTER TABLE {{prefix}}ddd_delivery_ledger ADD COLUMN IF NOT EXISTS failure_command_at TIMESTAMPTZ NULL;
