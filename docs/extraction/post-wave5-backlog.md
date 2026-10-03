# Post-wave-5 backlog

Library items raised after the wave-5 gate (`3f22973`). Each is a gap a consumer worked around, not a correctness bug, unless marked.

## Fixed

- **Undo-end resume (correctness, 2026-10-02).** A process that hit its resource budget right after its last compensation step scheduled an `undo-end` continuation that woke into `execute_forward()`, re-ran the failed step and could end `completed`. Fixed on `fix/compensation-resource-budget` (`b0c72ee`): yield only while undo steps remain, and route a process that has a failure message but is not compensating to `execute_compensation()`. Reported by the TXP pipeline (`txp-program`), tests in `packages/ddd-core/tests/Unit/Process/CompensationBudgetTest.php`.

- **E3 note for E1 effects (2026-10-02).** ddd-symfony's `CompiledSubscriptionRegistry::failure_command_of()` checked `instanceof IExternalEffectCommand`, so a handler-class (E1) effect command, an `IEffectCommand` handled by an `IExternalEffectHandler`, never had its failure command recorded in the delivery ledger note: `ddd:ops:list --layer=delivery` showed the exhausted pair without `failure_command`/`failure_command_at`. Core already fired the command (it checks `IEffectCommand`). Fixed on `fix/effect-failure-command`; test `FailureCommandNoteTest::test_an_exhausted_handler_class_effect_pair_records_its_failure_command`. Reported by TXP slice billing-accounts.

## Open (from TXP PR #4, slice process-kernel)

| Id | Kind | Gap | TXP workaround |
|---|---|---|---|
| PK-S1 | soft | `ddd:ops:stranded --fail` has no `--compensate` option, although `FailStrandedProcess` takes `compensate: true` | each saga context dispatches `FailStrandedProcess(compensate: true)` from its own console command |
| PK-Q1 | gap | the ddd-symfony `process` operator layer does not list quarantined processes (`failed` with a `quarantine_reason`); the plain-PHP host does. On Symfony they show only through an exhausted wakeup intent | the wakeup-layer item plus a runbook read of `ddd_processes.quarantine_reason` |
| PK-W1 | soft | no library repair command for failed workflow items or failed workflows; a failed item's "1 of budget 3" attempts reads as retries left when none will run | each workflow slice ships its own re-run console command |

## For Titus to decide (from TXP billing-ledger, `ApplySubscriptionChange`)

Both make process step lists read as the business story (see the "Step names" section of the `LongProcess` docblock). Neither is covered today; neither is approved.

| Id | Observation | What exists today | Possible shape |
|---|---|---|---|
| LP-F1 | A process whose first real step fails compensates nothing: `begin_compensation()` starts at `undo_index = -1`, so the process just ends `failed` (plus the `ProcessFailed` signal). TXP added a no-op `open()` step only so `#[Compensates('open')] close()` runs on any failure | no on-failure hook on `LongProcess` | an optional `protected function on_failed(\Throwable $cause, mixed $payload): Result` (or `#[OnFailure]` method) the runner calls once after compensation finishes, before `ProcessFailed`; its commands dispatch like a compensation's |
| LP-T1 | Await timeouts can only `TIMEOUT_FAIL` or `TIMEOUT_PROCEED` (null to the next step). An external call with a probe on timeout becomes a push / probe / settle triple of steps | `timeout_seconds` + `on_timeout` on `AwaitEvent`/`AwaitAll`/`AwaitAny`, `within()`/`until()` on `AwaitAny` | a third policy declared on the await, e.g. `->on_timeout_probe(new ProbeCommand(...), retry_within: 600)`: on timeout the runner dispatches the probe and re-arms the same await with the new deadline (once, or N times), then fails or proceeds |

PK items (table above the LP section), suggested shape when picked up: PK-S1 is a `--compensate` flag on the existing command; PK-Q1 is an `IOperatorItemSource` for quarantined rows in ddd-symfony (mirror `PdoOperatorView`'s source) with a `quarantined` label; PK-W1 is a core `RetryWorkflowItem` / `RetryWorkflow` repair pair plus `ddd:ops:workflows --retry`, and the operator item showing "exhausted" instead of an attempts ratio once an item is terminal. Each needs a conformance scenario or host test.
