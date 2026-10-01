# Wave 4: pdo-conf4 change requests

Author: pdo-conf4 (wave 4, round 3). Branch `wave4/pdo-conf4`. Owned paths: `packages/ddd-core/tests/Pdo/Conformance/**`, `packages/ddd-core/src/Defaults/Pdo/**` (only for the wave-4 requests addressed to pdo), the `core-pdo` block of `tests/harness/run.sh`, and this file. Binding inputs: [contract-register.md](contract-register.md) (section 4, section 8 wave 4), [wave3-notes.md](wave3-notes.md) (CR-PDO-6 ruling), [wave4-conformance-4-change-requests.md](wave4-conformance-4-change-requests.md) (W4C4-R1, W4C4-R4, CR-W4C4-1..3), [wave4-core-effects-change-requests.md](wave4-core-effects-change-requests.md) (CR-W4CE-1, -3, -5, -9), [wave4-pdo-4-change-requests.md](wave4-pdo-4-change-requests.md) (CR-PDO4-R1).

No ratified interface changed. No core file outside `Defaults/Pdo` was touched. No schema change.

## What this round did

| Request | Where | Result (MySQL 8.0, Native and Emulated) |
|---|---|---|
| W4C4-R1 / CR-PDO-6: the relay sees claim-time dead letters | `tests/Pdo/Conformance/Support/RoutedOutboxStore` implements `IReportsClaimDeadLetters` and forwards to the store that made the claim | `relay.lease-fencing` green (was red: "OutboxDeadLettered is emitted once": 0) |
| W4C4-R4 / CR-PDO4-R1: the 7 wave-4 pdo ids | host classes `Pdo{Native,Emulated}{Alarm,Await,Codec,Decode,Effect}ScenariosTest`; `PdoHostFixture` implements `EffectHost` and `ProcessDecodeFaults` | all 7 green |
| CR-W4CE-1 / CR-PDO4-4: EffectMiddleware + PdoEffectJournal | already in `DurableRuntime::compose()` (pdo-4). The fixture's `effectBus()` puts `EffectMiddleware(PdoEffectJournal, PdoTransactionBoundary)` in the same place (Correlation, then Effect, then Transaction). `RecordEffect` goes to `apply()` through the handler map | `effect.journal-reuse` green |
| CR-W4CE-9: `IReportsClaimDeadLetters` | already on `PdoOutboxStore` (pdo-4) | see the first row |
| CR-W4CE-5: LargeString in process state, `quarantine_reason` | already in `Internal\ProcessCodec` (pdo-4). `ProcessDecodeFaults` reads `status` and `quarantine_reason` without decoding the row | `codec.large-payload`, `decode.unknown-class` green |
| CR-W4CE-3: AttributeAuditPolicy as the default | `compose()` passes no policy, so `CorrelationMiddleware` falls back to `AttributeAuditPolicy`. This test pins that: `PdoComposeAuditPolicyTest` (an `#[Audit(false)]` command writes no row; `#[Audit(parameters: false)]` writes a row without parameters). The fixture bus now uses `AttributeAuditPolicy` in place of `AuditEverything` | green, and the `cmd.*` / `audit.sink-fails` ids are unchanged |
| D3 checkpoints on pdo (found by `process.await-*`) | `Internal\ProcessCodec::decodeSteps()` (CR-PDOC4-1 below) | `process.await-keyed-precheck`, `process.await-all-dynamic` green (were TypeError) |
| Gate | `PdoCatalogueTest` pins the 7 wave-4 ids and the two new seams. `bin/check-due.php` and `run.sh core-pdo` default to wave 4, so 44 ids must pass in both modes | see the acceptance run |

## CR-PDOC4-1: ProcessCodec turns stored checkpoint envelopes back into arrays (pdo internal, additive)

- **What.** `ProcessCodec::decode()` decodes the `steps` column through a new private `decodeSteps()`. Each `checkpoints[step]` envelope (`{_class, _data}`) becomes an array again. Its `_data` stays as JSON decoded it, and `JsonLifecycleValue::from_json()` accepts either form. Nothing else changes: the encoding is the same, the column is the same, and every other key is decoded as before.
- **Why.** `ProcessSteps::from_json_instance()` casts `checkpoints` to an array but leaves each entry a `stdClass`. `ProcessSteps::checkpoint_for()` then calls `JsonLifecycleValue::deserialize_polymorphic(?array)` and throws a `TypeError` for any checkpoint read back from a SQL row. Before wave 4 this was mostly hidden, because a suspending step's checkpoint was dropped. Since CR-W4P behaviour change 2 it is persisted with the await, and D3 (`process.await-keyed-precheck`, `process.await-all-dynamic`) reads it back. mem never sees the bug, because its store keeps the envelope as an array.
- **Compatibility.** Rows written before this change decode the same. The only change is that a checkpoint that used to throw can now be read.

## Requests to other owners

- **CR-PDOC4-R1 (core-process, `Application/Process/ProcessSteps.php`).** Make `from_json_instance()` turn each checkpoint envelope into an array, as `decodeSteps()` does here. That is the real fix. Every SQL host decodes `steps` as objects: sf `ProcessRowCodec::decode()` (line 67), wp `ProcessRepository` (line 184), and pdo before this change. So the sf and wp `process.await-*` / compensation-with-checkpoint paths hit the same `TypeError` once they read a stored checkpoint. After the core fix, pdo's `decodeSteps()` becomes a harmless no-op and can be removed.
- **CR-PDOC4-R2 (sf, until CR-PDOC4-R1 lands).** Expect the `TypeError` above in `SfHostFixture`'s `AwaitScenarios` (W4C4-R3). The local fix is the same normalisation in `ProcessRowCodec`.
- **CR-PDOC4-R3 (harness owner, outside the core-pdo block).** The usage comment at the top of `tests/harness/run.sh` (line 6) still says `core-pdo ... (wave 3)`. The block itself now gates wave 4.

## Compatibility impact

- **pdo runtime:** one internal decode change (CR-PDOC4-1). No public pdo API changed.
- **pdo conformance:** the per-id gate defaults to wave 4. A caller that wants the wave-3 gate passes `DDD_CONFORMANCE_WAVE=3` (`run.sh core-pdo`) or the second argument of `check-due.php`. The fixture's audited bus now uses `AttributeAuditPolicy`. No scenario command carries `#[Audit]`, so the audit trails are unchanged.
- **`RoutedOutboxStore`** (test support) now reports claim-time dead letters. A relay step over it emits `OutboxDeadLettered` for them, as compose()'s relay does.

## Not done here

- W4C4-R6 (a due wake for a quarantined process is retried by every drain pass) is a core-process finding. `decode.unknown-class` does not assert it, and nothing here changes it.
- `process.crash-mid-step` through `ResumeStrandedProcess` (optional in CR-PDO4-R1): the scenario keeps writing its ResumeRetry intent by hand. The bus path is covered by `DurableRuntimeWave4Cases`.
