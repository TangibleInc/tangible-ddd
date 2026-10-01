# Wave 5 notes

Date: 2026-10-01. Read these with [contract-register.md](contract-register.md), [coordinator-rulings-wave0.md](coordinator-rulings-wave0.md) and the wave 1-3 notes. Wave 4 has no notes file, so its record is in the "Wave 4" section below. If this file and the register disagree, this file wins until the register is revised again.

This file was written by the wave-5 docs-housekeeping author on `wave5/docs-housekeeping`, based on `1b5ffe3`. The coordinator adds the round results and rulings after the wave-5 round.

## Where wave 5 starts

- Waves 1-4 are merged on `extraction/ddd-packages` and gated. The last wave-4 merge is `761aef5` (`wave4/sf-conf4`).
- The house-style rename is merged at `1b5ffe3`. It covers 402 method and property names, listed in [naming/table.json](naming/table.json) and explained in [naming/naming.md](naming/naming.md). Every document written in wave 5 uses the new names. The register and the change-request files of waves 1-4 keep the names that applied when they were written. The table maps them, for example `insertIgnited` → `insert_ignited`, `findStranded` → `find_stranded`, `Drain::runOnce` → `run_once`, `IProcessLock::forceReleaseAll` → `release_all`, `takeDeadLetteredAtClaim` → `take_claim_dead_letters`, `failureCommand` → `failure_command`.
- Wave 5 closes the library demands that the TXP process-kernel slice recorded, plus coordinator decisions. The demand lists are the rollups under `txp-slices/.docs/research/2026-10-01-process-kernel-{awaits,effects,wiring,workflows}-demands/` and `2026-10-01-tangible-ddd-kernel-demands/`: AW1-AW3, E1-E3, L9, L10, W1-W5 and P1-P2. P1 and P2 were already closed in wave 4 by sf-conf4 (CR sf-conf4-1 and -2).
- Branches in flight while this was written: `wave5/core-correctness`, `wave5/sf-features`, `wave5/wp-redelivery-default` and this one. None of them was merged, so the docs below describe the code at `1b5ffe3`. For wave-5 behaviour they describe only the decisions the coordinator gave this author. The one exception is the wp redelivery opt-in names, read from that branch (below). The "Sync after the wave-5 merges" checklist lists what to finish.

## Register edits made in wave 5 (docs-housekeeping)

These edits record what waves 2-5 already ratified or merged. None of them changes a contract.

1. **Section 4: the three D3 ids (CR-W4C4-1).** `process.await-keyed-precheck`, `process.await-any-cancellation` and `process.await-all-dynamic` are now rows, with cells mem 4, pdo 4, wp `-`, sf 4. These are the cells that `ScenarioCatalogue::WAVES` and the three host catalogue pins (`PdoCatalogueTest`, `SfCatalogueTest`, `WpCatalogueConformance`) already enforce. The count is now 47 ids. Section 8 wave 4 lists them: mem 8 ids, pdo 7, wp 3 (unchanged), sf 9.
2. **CR-WPC4-6 recorded (WPC4-R1).** Section 7.3 says where the rollback fixtures live and how they are driven (`tests/Compat/rollback/**` behind `tests/Integration/Rollback/phpunit.xml`, `DDD_ROLLBACK_REFS`, `DDD_ROLLBACK_LEGACY`). It also says which N-only artifacts the drain covers, and records the 0.6 `#[Async]` defect.
3. **Section 3.6 (WPC4-R1).** By-reference integration actions (CR-WPC4-2, D6 on wp) are N-only artifacts. `wp ddd drain --before-rollback` runs the due ones and counts the future ones as remaining. ResumeRetry intents on `{prefix}_ddd_wakeup` are N-only too, and the drain runs them.
4. **Revision note** at the top of the register. It summarises what waves 2-5 ratified and points to the wave notes and to this file.

## Wave 4 (recorded here, there is no wave4-notes.md)

Every request in these files was merged and passed the wave-4 gate. They are recorded as **ratified as merged**:

| File | Ids | In one line |
|---|---|---|
| [wave4-core-effects-change-requests.md](wave4-core-effects-change-requests.md) | CR-W4CE-1..10 | D1 `EffectMiddleware` / `RecordEffect`; a deterministic failure-command id; D12 `#[Audit]` + `AttributeAuditPolicy`; D8 `Redactor` extension (`#[Sensitive]`, `#[NotAudited]`); D6 `LargeString`; L1 `IReturningCommandHandler`; L2 `AggregateRoot` / `AggregateRootRepository`; L3 and L7 `NotPermittedException`; the CR-PDO-6 core rule (`IReportsClaimDeadLetters`); `OutboxRecord::$event_class` |
| [wave4-core-process-change-requests.md](wave4-core-process-change-requests.md) | CR-W4P-1..7 | D3 keyed awaits, routes, precheck, `AwaitAny`, dynamic `AwaitAll`; D7 absolute alarms (`AwaitAlarm`); `#[RetryStep]`; WP8-10 stranded repairs; the D10 ignition ledger port |
| [wave4-pdo-4-change-requests.md](wave4-pdo-4-change-requests.md), [wave4-pdo-conf4-change-requests.md](wave4-pdo-conf4-change-requests.md) | CR-PDO4-1..6, CR-PDOC4-1 | `007_process_waits`, the CR-PDO-6 claim, the D10 stores (`008`), `PdoEffectJournal` (`009`), `PdoOperatorView::repair()`, the append-only schema gate (L5) |
| [wave4-sf-a-change-requests.md](wave4-sf-a-change-requests.md), [wave4-sf-b-change-requests.md](wave4-sf-b-change-requests.md), [wave4-sf-conf4-change-requests.md](wave4-sf-conf4-change-requests.md) | CR sf-a-1..7, sf-b-1..6, sf-conf4-1..4 | L4/L5/L6/L8; `DbalEffectJournal`; the merged operator view and `ddd:ops:list`; D10 ignition in the compiled map; route-indexed `ddd_process_waits`; `EffectMiddleware` in the bundle bus; `ddd:ops:stranded` on the core repairs; `AttributeAuditPolicy` as the bundle default |
| [wave4-wp-conf4-change-requests.md](wave4-wp-conf4-change-requests.md) | CR-WPC4-1..6 | the CR-PDO-6 claim on wp; by-reference envelopes (D6); `LargeString` in process state; quarantined wakes complete their action; `wp ddd ops --resume-stranded/--fail-stranded`; the 7.3 rollback suite |
| [wave4-conformance-4-change-requests.md](wave4-conformance-4-change-requests.md) | CR-W4C4-1..6 | the wave-4 scenarios, the three D3 ids, and the `EffectHost` / `ProcessDecodeFaults` / `WorkflowHost` / `PostCommitWakeups` seams |
| [wave4-packaging-4-change-requests.md](wave4-packaging-4-change-requests.md) | PK4-1..3 | the 7.3 hook of `run.sh compat`, the loader judge fields, the provenance of the compiled-container fixtures |

## Wave 5 decisions this author was given

- **WordPress listener redelivery: one attempt by default, with an opt-in.** On wp, a DDD-registered listener (`integration_listener()`, `IntegrationListener`, `integration_action()`) that throws is not retried by default. That is 0.6 behaviour: one attempt, the error logged, the rest of the hook's callbacks still run. A listener that opts in gets the ledger budget of register 5.1 (30 s × 2ⁿ, capped at 3600 s) through `{prefix}_ddd_redeliver`. Ignition and resume subscribers are unaffected. `wave5/wp-redelivery-default` implements this ([its change requests](wave5-wp-redelivery-default-change-requests.md), CR-RD-1 and CR-RD-2, read on that branch at `875bac5`):
  - `#[TangibleDDD\WordPress\Retries(n)]` gives n + 1 attempts;
  - else the option `{prefix}_ddd_delivery_attempts`;
  - else `WpLedgeredDelivery::LISTENER_ATTEMPTS` (1);
  - then the filter `tangible_ddd_delivery_attempts` has the last word.

  The CHANGELOG, the README and the runbook use these names. They are the only names in the wave-5 docs that do not exist at `1b5ffe3`, and they resolve once that branch merges. That branch's packaging request 2 (a CHANGELOG bullet and a migration step) is covered by the CHANGELOG entry here, because this author owns `CHANGELOG.md` in wave 5.
- The three 0.6.x bug fixes stay as frozen in register 6, and are mirrored onto `hotfix/0.6.7`, which is prepared and not released.

## Docs written in wave 5

| What | Where |
|---|---|
| The 0.7.0 changelog entry, rewritten to the state at `1b5ffe3` plus the wave-5 decisions | [CHANGELOG.md](../../CHANGELOG.md) |
| The rollback runbook | [docs/runbooks/rollback.md](../runbooks/rollback.md) |
| "Using tangible-ddd 0.7" on WordPress | [README.md](../../README.md) |
| "Using tangible-ddd 0.7" on Symfony | [examples/symfony/README.md](../../examples/symfony/README.md) |
| "Using tangible-ddd 0.7" on plain PHP with PDO | [examples/plain-php-durable/README.md](../../examples/plain-php-durable/README.md) |

Every class, method and command these documents name was checked against the source at the base commit. The command that did it is in the docs-housekeeping report.

## Sync after the wave-5 merges

When `wave5/core-correctness`, `wave5/sf-features` and `wave5/wp-redelivery-default` merge, the owner of the next docs round updates these places:

1. The CHANGELOG "Wave 5" list: one line per demand closed (AW1-AW3, E1-E3, L9, L10, W1-W5), with its API.
2. The CHANGELOG, README and runbook lines about the wp redelivery default: check `Retries`, the option and the filter against the merged code, and drop "lands with wave5/wp-redelivery-default".
3. The Symfony guide sections on effects (E1 handler shape, E2 `recorded_at`), workflows (W1 reschedule, W3 `stale_start_seconds`, W5 operator source) and awaits (AW1 resuming event id, AW2 contention, AW3 unheard log), where those land.
4. The register's section 4 and 8 cells for any new scenario id the wave-5 authors add.
5. `docs/README.md` (not owned by this author): link the runbook and the three guides, and mark the 0.6.x status line as superseded.

## Wave 5 closed

Wave 5 is merged on `extraction/ddd-packages` at `dfa514a` (2026-10-01). The merges, in order:

| Merge | Branch | What |
|---|---|---|
| `3fe61fd` | `wave5/core-correctness` | L9, L10, W2, W4, E1, E2, AW1, AW2 in core (CR-W5CC-1..8) |
| `e23b9cd` | `wave5/docs-housekeeping` | the 0.7.0 CHANGELOG, the host guides, the rollback runbook, the three D3 register rows |
| `c9e9a3d` | `wave5/sf-features` | W1, W3, W5, E3, AW3, multi-consumer configuration, sf schema 010 (CR-W5SF-1..9) |
| `aae89ab` | `wave5/wp-redelivery-default` | one attempt by default for wp listeners, `#[Retries]`, `wire_unbooted()` (CR-RD-1..3) |
| `9138f2c` | `wave5/test-hygiene` | per-test globals in `ProcessRunnerTest`, the scoped exclusion in `LoadDiagnosticsTest` (CR-W5TH-1 recorded) |
| `0a017d6` | `wave5/conformance-5` | the six wave-5 ids and the seams `EffectStateHost`, `WorkItemHost`, `CrossConsumerHost` (CR-W5C5-1..5) |
| `f1882c5` | `wave5/hosts-follow` | pdo schema 010/011, sf schema 011, wp schema v9, the parking schedulers, `ddd:ops:effects:invalidate`, the wp fresh-database fix (CR-W5HF-1..5) |
| `f492527` | `wave5/hc5-2` | `workflow.item-deterministic-id` reads command ids from the handler (HC5-2) |
| `0682b31` | `wave5/hc5-1` | sf retries a retryable wake at the cap after its budget (HC5-1) |
| `dfa514a` | `wave5/hosts-conf5` | every wave-5 id wired on pdo, wp and sf; harness gates at wave 5 |

### Rulings and outcomes

- **CR-W5CC-1..8 were ratified as written** (the coordinator ruling recorded in the inputs of [wave5-conformance-5-change-requests.md](wave5-conformance-5-change-requests.md)). CR-W5SF-1..9, CR-RD-1..3, CR-W5C5-1..5 and CR-W5HF-1..5 are merged as written. The sf requests CR-W5SF-R1 (`PersistenceConflict` re-parented), CR-W5SF-R2 (the behaviour-type registry provided at boot) and CR-W5SF-R4 (E1, E2 and AW2 on sf) were done by hosts-follow. `EffectsInvalidateCommand` (CR-W5HF R2) merged at `packages/ddd-symfony/src/Console/Ops/`.
- **HC5-1 and HC5-2 were fixed, not deferred.** Both merged before `wave5/hosts-conf5`, so the wave-5 due set is the catalogue's as written: mem 5 ids, pdo 5, wp 2, sf 6 (register section 8, "Wave 5").
- **`wire_unbooted()` is an explicit test-bootstrap API.** CR-RD-3's loader line in `tangible-ddd.php` (packaging request 1) was not applied. A bootstrap that stubs WordPress requires `packages/ddd-wp/wordpress/unbooted.php` and calls `TangibleDDD\WordPress\wire_unbooted()` itself, as `tests/Unit/WordPress/fixtures/late-wordpress-boot.php` does.
- **CR-RD request 4 is applied.** `NRowsRolledBackRollback` opts its consumer in through `WpLedgeredDelivery::ATTEMPTS_OPTION`, so the 7.3 rollback fixtures still leave a pending redelivery to drain.
- **The two wp AW2 cells stay `-`.** They were set before wp schema v9 added the `fact` column. `WpdbParkingScheduler` parks answers in production at v9, and `WpParkedFactV9Test` covers it as a host test. Raising the cells is left to a later round.

### Docs synced at the close (this section's round)

The "Sync after the wave-5 merges" list above is done:

1. CHANGELOG: the "in progress" framing and the "Still landing" list are gone. Every wave-5 item is under Changed or Added, marked "(wave 5)", and the migration notes cover v9, `TABLES_INSTALLED_ACTION`, `wire_unbooted()`, sf schema 010/011, pdo schema 010/011, `PersistenceConflict` and the final `remove()`. The scenario count is 53.
2. The redelivery opt-in names (`Retries`, `ATTEMPTS_OPTION`, `ATTEMPTS_FILTER`, `LISTENER_ATTEMPTS`) are checked against the merged code.
3. The Symfony guide covers E1 (handler shape), E2 (`recorded_at`, the `effect` layer), `ddd:ops:effects:invalidate`, AW1, AW2 (parked answers, retry at the cap), AW3 and `PersistenceConflict` as a 409.
4. Register section 4 has the six wave-5 rows (53 ids, header wave range 1-5), and section 8 has a "Wave 5" entry. `ScenarioCatalogue`'s comments and the conformance README count match.
5. `docs/README.md` links the CHANGELOG, the runbook, the guides and the register, and its status line describes 0.7.0.

The rollback runbook also covers schema v9 and the parked answers a ResumeRetry intent carries, and its drain text no longer contradicts the one-attempt listener default.

### Open after wave 5

- **Root suite order dependence** (final gate defect 1): `ConsumerRegistry` is reset only in `setUp()` by some tests and only in `tearDown()` by `SelfConsumerRegistrationTest`, so some random seeds fail. CR-W5TH-1's fix and the switch to `executionOrder="depends,random"` follow.
- **`phpstan.neon`** (final gate defect 2): `new static(...)` in `InMemoryWakeupScheduler::lenient()` since the class stopped being final.
- `php tools/naming/inventory.php` overwrites the tracked historical `docs/extraction/naming/inventory.json` snapshot.
- Optional requests still open: CR-W5SF-R3 (an `IReportsResume` port), CR-W5SF-R5 (a core `WakeKind::Workflow`), CR-W5HF R1 (the fact on `ProcessWakeupMessage`), R3 (fold `EffectHandlersPass` into `HandlerLocatorPass`), R6 (an `effect` layer on wp once it has a journal), CR-RD request 3 (move the conformance opt-in into `WpConformanceRuntime`), and per-gathered-key event ids for `AwaitAll` (CR-W5CC-8, "Not done").
- The usage text at the top of `tests/harness/run.sh` still names wave 3 and wave 2 for `core-pdo` and `conformance-wp`.
