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
