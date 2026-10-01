# Wave 5: hosts-conf5 change requests

Author: hosts-conf5 (wave 5, round 3). Branch `wave5/hosts-conf5`, based on `extraction/ddd-packages` at `f1882c5` (everything else of wave 5 merged). Owned paths: `packages/ddd-core/tests/Pdo/Conformance/**`, `tests/Integration/Conformance/**`, `packages/ddd-symfony/tests/Conformance/**`, `packages/ddd-symfony/tests/Support/**`, the `conformance-wp` and `core-pdo` blocks of `tests/harness/run.sh`, and this file.

The task: wire every catalogued wave-5 id into the pdo (both prepare modes), wp and sf host fixtures as [wave5-conformance-5-change-requests.md](wave5-conformance-5-change-requests.md) and [wave5-hosts-follow-change-requests.md](wave5-hosts-follow-change-requests.md) request, switch the fixtures to the parking schedulers, and raise the harness gates to wave 5.

No contract change was needed. Nothing in this round touches a ratified interface or a library class: every edit is a test fixture, a host test class, a gate default or this file.

## What this round did

| Host | Ids wired | Fixture changes |
|---|---|---|
| pdo (Native + Emulated) | `lock.parked-answer`, `process.resume-contention-keeps-answer`, `process.resume-cause`, `effect.performed-not-recorded`, `workflow.item-deterministic-id` | `PdoCatalogueTest::WAVE = 5`, `PDO_WAVE_5`; eight host classes; `PdoHostFixture implements EffectStateHost, WorkItemHost`; jobs on `PdoParkingJobStore` (worker 1 and n > 1); `live_intents()` joins `ddd_job_facts`; an `IBehaviourTypes` provided as `compose()` does |
| wp | `process.resume-cause`, `workflow.item-deterministic-id` | `WpCatalogueConformance` wave-5 list; `WpResumeCauseConformance`, `WpWorkItemConformance`; `WpHostFixture implements WorkItemHost` (wp `BehaviourWorkflowRepository`, `WorkItemRepository`); `WpConformanceRuntime` on `WpdbParkingScheduler` at schema v9 |
| sf | the six wave-5 ids incl. `delivery.cross-consumer-once` | `SfCatalogueTest` wave-5 list; five host classes; `SfHostFixture implements EffectStateHost, WorkItemHost, CrossConsumerHost`; `DbalParkingScheduler` with `ParkedFacts` around the wake target; operator view with the ledger, wakeup and `UnrecordedEffects` sources; a second consumer `sfb` (see below) |
| harness | | `check-due.php` (pdo, wp) and `run.sh` `core-pdo` / `conformance-wp` default to wave 5 |

### sf: the second consumer (CR-W5C5-4)

`SfHostFixture::OTHER = 'sfb'`, composed as the bundle composes another consumer: an empty `CompiledSubscriptionRegistry` (subscribers added at run time count for `has_subscribers()`), a `FactAudience` of every worker's `MessengerFactTransport` (so `relay_once()` sends it an addressed copy on queue `ddd_facts_sfb`), its own `DbalDeliveryLedger` on tables `sfb_` (created on first use of the seam), and its own `IntegrationFactHandler(…, 'sfb')`. `deliver_routed()` consumes `ddd_facts_sfb` with a real Messenger Worker; `deliver_other()` dispatches one addressed copy. A scenario that never uses the seam adds no subscriber, so the relay routes nothing and no `sfb_` table is created.

## Blockers outside the owned paths

Two wave-5 ids are wired and run unchanged but fail on one host each. Neither can be fixed in a fixture without faking the host's answer, so both go to their owners. Every other id due by wave 5 passes on every host.

### HC5-1 (sf adapter owner): an exhausted wake is never claimed again — `process.resume-contention-keeps-answer` red on sf

- **Observed.** `SfParkedAnswerScenariosTest::test_process_resume_contention_keeps_answer` fails at `retry 10`: the 11th drain at the cap does not report the parked intent in `wakes_retried`.
- **Cause.** `ProcessWakeupHandler::failed()` calls `DbalWakeupScheduler::exhaust()` on the 10th failed attempt, even for a retryable `LockNotAcquired`; `exhaust()` sets `exhausted_at`, and `claim_due()` skips rows with `exhausted_at IS NOT NULL`. The parked answer then stays in the operator view but is never woken again until an operator repair. Register 5.1 (`WakeRetryPolicy`: "Exhaustion goes to the operator view; the intent keeps being retried at the cap, so a wake is never dropped") and the AW2 scenario both need the wake retried at the cap. The handler's own non-Dbal branch already does this (`retry_later()` at `MAX_DELAY_SECONDS`). pdo and mem pass.
- **Request.** In `packages/ddd-symfony/src/Messenger/ProcessWakeupHandler.php` / `src/Persistence/DbalWakeupScheduler.php`: for a *retryable* failure at the budget, keep the row visible as exhausted in the `wakeup` layer but claimable again at the cap (for example `retry_later()` at `MAX_DELAY_SECONDS`, plus the exhaustion marker the operator source reads, or `claim_due()` also taking exhausted rows whose `next_attempt_at` is due). Keep exhausting at once for a non-retryable failure. Additive at the API level, but it changes sf runtime behaviour, so it is the owner's call and needs a kernel test of its own.

### HC5-2 (conformance owner): `workflow.item-deterministic-id` reads one audit row per dispatch — red on wp

- **Observed.** `WpWorkItemConformance::test_workflow_item_deterministic_id` fails at "the re-run dispatched the same command id": the audit trail holds `[user:1, user:2]`, not `[user:1, user:2, user:2]`. Everything else in the scenario passes on wp (the re-run uses the same `for_item()` id, the grant is absorbed, the ledger completes).
- **Cause.** The wp audit store is the 0.6 `{prefix}_command_audit` table with `UNIQUE KEY uniq_command_id (command_id)`. The re-run's `open` under the same command id inserts nothing (wpdb errors are not checked, as in 0.6), and its `close` updates the existing row. `HostFixture::audit_trail()` is documented as "audit rows closed so far", so the wp fixture reports the host truthfully. mem, pdo and sf keep one row per dispatch. Making the table non-unique is a schema change to a frozen 0.6 table, so that is not an option.
- **Request.** In `packages/ddd-conformance/src/Scenarios/WorkItemScenarios.php`, record the command ids in the `GrantAccess` handler closure the scenario already installs (it computes `Correlation::current()->cause?->id` there) and assert `[$first, $second, $second]` on that list instead of `granted_ids()` from the audit trail. If the scenario must keep an audit check, assert that the audit trail names no `GrantAccess` id other than `$first` and `$second`. Either is portable to a host whose audit store is keyed by command id.

### Fix round 1 status

The review confirmed both diagnoses and assigned them as above: HC5-1 to the sf adapter owner, HC5-2 to the conformance owner. The conformance owner could also ratify the id as `-` or deferred on wp in the catalogue. When this round ran, `extraction/ddd-packages` had not moved past `f1882c5`, so neither fix is available to this branch. Nothing in the owned paths changes in this round. As soon as either fix merges, rebase this branch and re-run the suites. No fixture change is expected:

- HC5-1 merged: `cd packages/ddd-symfony && composer install && rm -rf var/cache && vendor/bin/phpunit` (re-install because the conformance package is copied into vendor; see below).
- HC5-2 merged: `tests/harness/run.sh conformance-wp` (wp reads the conformance package through the root autoloader).

### Fix round 2 status

`origin/extraction/ddd-packages` is still at `f1882c5`, so neither the HC5-1 fix nor the HC5-2 fix (nor a coordinator deferral) is available. Both blockers stay red for the same reasons. This round only fixed the `SfHostFixture` minor: the import block is now sorted, and `deliver_routed()` documents that "due" is Messenger's wall-clock `available_at` and that `$eventClass` is unused. Re-run results: core-pdo green (49/49 due, both modes); conformance-wp red only on `workflow.item-deterministic-id` (40/41); sf 503 tests with 1 failure (`process.resume-contention-keeps-answer`); root suite green. ~~**Do not merge this branch before HC5-1 and HC5-2 are fixed or ruled deferred.**~~ If the branch has to merge first, the coordinator must rule a deferral that takes the two ids out of the wave-5 due set (sf catalogue for HC5-1, wp catalogue cell for HC5-2), so the gates stay green.

**Resolved.** Both fixes merged before this branch, so no deferral was needed: HC5-2 in `f492527` (`wave5/hc5-2`: `WorkItemScenarios` reads the command ids from the `GrantAccess` handler, `a695b3e`) and HC5-1 in `0682b31` (`wave5/hc5-1`: `DbalWakeupScheduler::exhaust()` takes a retry time, and `claim_due()` claims an exhausted row that has one, `0a7be23`). This branch merged at `dfa514a`, where both ids pass on their hosts.

## Other notes

- The usage text and the header comment at the top of `tests/harness/run.sh` still say "wave 3" / "wave 2" for `core-pdo` / `conformance-wp`. Both are outside the two owned blocks, so they are not edited here; whoever owns the harness can bring them up to date.
- `vendor/tangible/ddd-conformance` in `packages/ddd-symfony` is a copy, not a symlink, made at `composer install` time. After any change to `packages/ddd-conformance`, run `composer install` there again before an sf run, or the sf suite runs the old scenarios.
