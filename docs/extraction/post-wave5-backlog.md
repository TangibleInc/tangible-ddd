# Post-wave-5 backlog

Library items raised after the wave-5 gate (`3f22973`). Each is a gap a consumer worked around, not a correctness bug, unless marked.

## Fixed

- **Undo-end resume (correctness, 2026-10-02).** A process that hit its resource budget right after its last compensation step scheduled an `undo-end` continuation that woke into `execute_forward()`, re-ran the failed step and could end `completed`. Fixed on `fix/compensation-resource-budget` (`b0c72ee`): yield only while undo steps remain, and route a process that has a failure message but is not compensating to `execute_compensation()`. Reported by the TXP pipeline (`txp-program`), tests in `packages/ddd-core/tests/Unit/Process/CompensationBudgetTest.php`.

## Open (from TXP PR #4, slice process-kernel)

| Id | Kind | Gap | TXP workaround |
|---|---|---|---|
| PK-S1 | soft | `ddd:ops:stranded --fail` has no `--compensate` option, although `FailStrandedProcess` takes `compensate: true` | each saga context dispatches `FailStrandedProcess(compensate: true)` from its own console command |
| PK-Q1 | gap | the ddd-symfony `process` operator layer does not list quarantined processes (`failed` with a `quarantine_reason`); the plain-PHP host does. On Symfony they show only through an exhausted wakeup intent | the wakeup-layer item plus a runbook read of `ddd_processes.quarantine_reason` |
| PK-W1 | soft | no library repair command for failed workflow items or failed workflows; a failed item's "1 of budget 3" attempts reads as retries left when none will run | each workflow slice ships its own re-run console command |

Suggested shape when picked up: PK-S1 is a `--compensate` flag on the existing command; PK-Q1 is an `IOperatorItemSource` for quarantined rows in ddd-symfony (mirror `PdoOperatorView`'s source) with a `quarantined` label; PK-W1 is a core `RetryWorkflowItem` / `RetryWorkflow` repair pair plus `ddd:ops:workflows --retry`, and the operator item showing "exhausted" instead of an attempts ratio once an item is terminal. Each needs a conformance scenario or host test.
