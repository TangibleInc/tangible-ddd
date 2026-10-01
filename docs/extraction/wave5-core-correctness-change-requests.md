# Wave 5: core-correctness change requests

Author: core-correctness (wave 5). Branch `wave5/core-correctness`, based on `extraction/ddd-packages` at `1b5ffe3` (house-style rename merged). Owned paths: `packages/ddd-core/src/**` except `Defaults/Pdo/**`, `packages/ddd-core/tests/Unit/**`, `tests/Unit/Process/**`. Inputs: the TXP process-kernel rollups (awaits AW1/AW2, effects E1/E2, workflows W2/W4, wiring L9/L10) and the coordinator's wave-5 task.

Every item is additive: a new interface, class, enum case, optional trailing parameter, nullable property or protected default method. No ratified interface lost or changed a method signature. The 0.6 frozen API (R1-R5) is untouched: nothing here changes a 0.6 class's public signature, hook, table or stored shape that 0.6 reads. The section "Behaviour changes" lists what an existing caller can observe.

## What this round did

| Item | Where | Tests |
|---|---|---|
| L10 core 409 family | `Domain/Exceptions/ConflictException` | `tests/Unit/Domain/RemoveAndConflictTest` |
| L9 `remove()` | `Infra/Persistence/Shared/AggregateRootRepository` | `RemoveAndConflictTest` |
| W4 item command ids | `Runtime/Ids/DeterministicCommandId::for_item`, `Application/BehaviourWorkflows/WorkflowHandler` | `tests/Unit/BehaviourWorkflows/WorkflowItemsAndTypesTest` |
| W2 behaviour type registry | `Domain/ValueObjects/Behaviours/{IBehaviourTypes,BehaviourTypes,BaseBehaviourConfig}` | `WorkflowItemsAndTypesTest` |
| E1 handler-class effects | `Runtime/Effects/{IEffectCommand,IExternalEffectHandler,NoEffectHandler,IExternalEffectCommand,EffectMiddleware,RecordEffect}`, `Runtime/Delivery/SubscriptionRegistrar` | `tests/Unit/Runtime/EffectHandlerAndStateTest` |
| E2 performed / recorded | `Runtime/Effects/{EffectState,EffectEntry,ITracksEffectState,UnrecordedEffects}`, `Runtime/Ops/Layer::Effect`, `Testing/InMemoryEffectJournal` | `EffectHandlerAndStateTest`, `EffectMiddlewareTest`, `OperatorViewTest` |
| AW2 parked fact resume | `Application/Process/{ProcessRunner,ResumeReport,ProcessLockUnavailable}`, `Runtime/Scheduling/{ICarriesFacts,WakeupIntent}`, `Testing/{InMemoryParkingScheduler,InMemoryWakeupScheduler}` | `tests/Unit/Process/ProcessRunnerWave5Test` |
| AW1 resuming event id | `Application/Process/{LongProcess,ProcessSteps,ResumeSource,ProcessRunner}` | `ProcessRunnerWave5Test` |

## CR-W5CC-1: `ConflictException` (L10)

- **What.** `class TangibleDDD\Domain\Exceptions\ConflictException extends BusinessConstraintException` (not final). An edge that maps `BusinessConstraintException` to 409 needs no change; `NotPermittedException` (L7, 403) is a sibling.
- **Request (sf).** Re-parent `TangibleDDD\Symfony\Persistence\PersistenceConflict` to `ConflictException`. Note that it extends `\RuntimeException` today and `BusinessConstraintException` extends `\Exception`, so a `catch (\RuntimeException)` around a save would stop catching it; sf should check its own catch sites. This closes sf-a's CR sf-a-2 and TXP's `DddConflict` deptrac layer.

## CR-W5CC-2: `AggregateRootRepository::remove()` (L9)

- **What.** `final public function remove(IRecordsDomainEvents $aggregate): void`: the class check of `save()` (now a private `check()` shared by both), then `delete()`, then `$this->events->collect_from()`. `protected function delete(IRecordsDomainEvents $aggregate): void` is not abstract, so existing subclasses compile; the default throws `\LogicException` before anything is harvested.
- **Compatibility.** `AggregateRootRepository` is wave-4 (L2), not 0.6. A subclass that already declares its own public `remove()` (TXP's `DoctrineTeamInviteRepository`, `DoctrineMembershipRepository`) must rename it to `delete()` when it takes this version, because `remove()` is final. That is the change TXP asked for.

## CR-W5CC-3: `DeterministicCommandId::for_item()` (W4)

- **What.** `for_item(string $consumer, int $workflow_id, int $behaviour_idx, int $phase, string $item_key, int $ordinal = 0): string`, the 32-hex `uuid5(uuid5(WORKFLOW_NAMESPACE, "{consumer}:{workflow_id}"), "item:{behaviour_idx}:{phase}:{ordinal}:{item_key}")`. New constant `WORKFLOW_NAMESPACE`. The item key is last so a key containing `:` cannot collide with another coordinate.
- **Shape choice.** The task sketched `for_item(workflow_id, behaviour_idx, phase, item_key)`. I added `$consumer` first, as `for_step()` has, because workflow ids are per-consumer table ids and two consumers' workflow 7 must not share item ids; and a trailing `$ordinal` for items that dispatch more than one command.
- **`WorkflowHandler`.** `process_chunk()` runs each `execute_one()` inside `DeterministicCommandId::within($this->item_command_id($item))`, so the item's first command takes the id; a crash re-run (command committed, ledger save lost) dispatches the same id. New `protected function item_command_id(WorkItem $item, int $ordinal = 0): string` for further commands. Consumer = `infra_config?->prefix()`, `''` without a config. A forked child workflow re-parents items (new workflow id), so it is a new attempt with new ids.

## CR-W5CC-4: `IBehaviourTypes` (W2)

- **What.** `interface IBehaviourTypes { register(string $type, string $class): void; find(string $type): ?string; }` and `final class BehaviourTypes implements IBehaviourTypes` (in-memory). `BaseBehaviourConfig::register_type()` writes to `HostDefaults::get(IBehaviourTypes::class)` when a host provided one, else to a process-wide fallback; `class_for_type()` asks the host registry, then the fallback, and still throws `\InvalidArgumentException("Invalid behaviour type: …")`. The private static `$type_map` is gone.
- **Fix round 1.** Include-time registrations are handed over to the host registry: `BaseBehaviourConfig::hand_over_types(IBehaviourTypes $to)` copies them (the host entry wins on a clash) and drops the fallback; the facade also does it on its first call that sees a host registry. `BehaviourTypes::all()` (concrete class only) lists the map. `BaseBehaviourConfig::reset_types_for_tests()` clears the fallback (`HostDefaults::reset_for_tests()` does not). Hosts resolve stored types through `class_for_type()` / `from_json()`; a host that reads its `IBehaviourTypes` service directly calls `hand_over_types()` once at boot, after providing it. The `HostDefaults` lookup is the one Domain-to-Runtime reach in this class, kept for the 0.6 static facade.
- **Requests.** sf: build one `BehaviourTypes` service, populate it at compile/boot time from autoconfigured `BaseBehaviourConfig` subclasses (an attribute such as `#[BehaviourType('txp_…')]`, or `get_behaviour_type()` on a no-argument instance), and provide it in `HostDefaultsInstaller`, then call `BaseBehaviourConfig::hand_over_types()` with it. TXP can then drop `ToyDigestConfig::register()` in the handler constructor. wp, pdo: optional; their consumers' `register_type()` calls keep working through the fallback.

## CR-W5CC-5: handler-class effects (E1)

- **What.**
  - `interface IEffectCommand extends ICommand { idempotency_key(): string; failure_command(\Throwable $last): ?ICommand; }`: the effect as data.
  - `IExternalEffectCommand` now `extends IEffectCommand` and declares only `perform()` and `record()`. The two moved declarations are unchanged, so every implementation and caller keeps working.
  - `interface IExternalEffectHandler { perform(IEffectCommand $command): EffectResult; record(IEffectCommand $command, EffectResult $result): void; }` (`@template T of IEffectCommand`). Parameters are `IEffectCommand` because PHP does not allow an implementation to narrow them; an implementation asserts its own class.
  - `final class NoEffectHandler extends \LogicException`.
  - `EffectMiddleware::__construct(?IEffectJournal $journal = null, ?ITransactionBoundary $boundary = null, ?ContainerInterface $handlers = null, ?CommandToHandlerMapping $mapping = null)`. For an `IEffectCommand` that is not an `IExternalEffectCommand` it locates the handler before `perform()`: `$handlers->get($mapping->getClassName($class))`, or with no mapping the `HandlerClassNameInflector` convention (`Commands\XCommand` → `CommandHandlers\XHandler`). No locator, no service, or a service that is not an `IExternalEffectHandler` → `NoEffectHandler`, nothing performed or journaled. A self-contained `IExternalEffectCommand` never consults the locator.
  - `RecordEffect::__construct(IEffectCommand $effect, EffectResult $result, ?IExternalEffectHandler $handler = null, ?ITracksEffectState $journal = null)`. `$effect` is widened from `IExternalEffectCommand`; a handler-less non-self-contained effect throws `\InvalidArgumentException`. `apply()` calls the handler's `record()` when there is one.
  - `SubscriptionRegistrar` fires `failure_command()` for any `IEffectCommand` (was `IExternalEffectCommand`).
- **Requests (sf).** Pass the command handler locator and `tangible_ddd.handler_mapping` to `tangible_ddd.middleware.effect` (arguments 3 and 4), and autoconfigure `IExternalEffectHandler` implementations into the `tangible_ddd.command_handler` locator, as `ICommandHandler` is. `RecordEffect` keeps running through `SelfExecutingCommandMiddleware`. wp: the same wiring if wp consumers want handler-class effects; nothing breaks without it.

## CR-W5CC-6: journal states, retry rule and the `effect` operator layer (E2)

- **What.**
  - `enum EffectState: string { Performed = 'performed'; Recorded = 'recorded'; }`, `final class EffectEntry(key, result, state, performed_at, ?recorded_at)` with `is_recorded()`.
  - `interface ITracksEffectState extends IEffectJournal { mark_recorded(string $key): void; find_entry(string $key): ?EffectEntry; find_unrecorded(\DateTimeImmutable $performed_before, int $limit): array; }`. A separate interface, so `IEffectJournal` (ratified, wave 4) is unchanged and wave-4 journals keep working. The task's "find() exposes the state" is `find_entry()`: changing `find()`'s return type would break every journal.
  - `store()` writes Performed (performed_at now); `RecordEffect::apply()` calls `mark_recorded()` after `record()` returned, inside the Transaction middleware's unit of work, so the mark commits or rolls back with `record()`.
  - **Retry rule** (with an `ITracksEffectState` journal): no entry → perform, store, record. Performed → reuse the result and run `record()` again (the domain write is still owed). Recorded → return the journaled result; `record()` does not run again and no transaction is opened (effectively once). Only `invalidate()` performs again. A plain `IEffectJournal` keeps the wave-4 rule (a found entry is recorded again).
  - `Layer::Effect = 'effect'` (label `Effect`), between `workflow` and `transport` in the enum order.
  - `final class UnrecordedEffects implements IOperatorItemSource` (`__construct(ITracksEffectState $journal, string $consumer, ?IClock $clock = null, int $after_seconds = 300)`): one item per entry performed more than `$after_seconds` ago and not recorded; key = idempotency key, first_seen = performed_at, no budget, `last_error` "performed at …, not recorded", repairs `['invalidate']`.
  - `InMemoryEffectJournal` implements `ITracksEffectState` and takes an optional `IClock`.
- **Requests.** pdo (`packages/ddd-core/schema/*/009_effect_journal.sql`, `PdoEffectJournal`), wp, sf (`DbalEffectJournal`): an append-only nullable `recorded_at` column on the effect journal (`performed_at` exists), `ITracksEffectState` on the journal (`store()`'s upsert also resets `recorded_at` to NULL; `find_entry()` and `find_unrecorded()` skip invalidated rows; `find_unrecorded` = `recorded_at IS NULL AND invalidated_at IS NULL AND performed_at < ?` ordered by `performed_at`), and `UnrecordedEffects` added to the operator view's sources. sf: `ddd:ops:list --layer=effect` then works through `Layer::tryFrom`; an `invalidate` repair command (`ddd:ops:effects:invalidate <key>`) as TXP proposed. wp: `WpOperatorView::LAYERS` lists its layers explicitly and needs `effect` added when it wires the source.

## CR-W5CC-7: a contended fact resume is parked, not failed (AW2)

- **What.**
  - `interface Runtime\Scheduling\ICarriesFacts extends IWakeupScheduler` (marker): stored intents keep `WakeupIntent::$fact`.
  - `WakeupIntent` gains a trailing `public readonly ?array $fact = null` (`['class', 'payload', 'event_id']`, plain scalars) and `WakeupIntent::resume_fact(string $consumer, int $process_id, int $step_index, array $fact, \DateTimeImmutable $due_at)`: kind ResumeRetry, expected status `suspended`, key `resume_retry:{process_id}:{step_index}:suspended:0:fact-{event_id}` (the existing ResumeRetry format with a deterministic nonce, so `retry_version()` and key parsers keep working and parking the same fact twice is one intent).
  - `ProcessRunner::resume_with_outcome(IIntegrationEvent $event, string $event_id = '')` and `resume_on_event(…, string $event_id = '')` (optional trailing parameter; `''` = the ambient fact's id from `Correlation::current_fact()` when it is the same class). The `resume:` subscriber passes the delivery's event id.
  - When one candidate's lock acquisition fails (`ProcessLockUnavailable`), the runner writes `resume_fact(…)` due after the wake backoff (2 s) in its own transaction, records the candidate as `ResumeReport::$deferred` (new trailing property; `is_unheard()` counts it), and continues with the next candidate. A parked first-wins (0.6-shaped) candidate counts as having taken the fact. The `resume:` subscriber returns normally, so the ledger marks it delivered: the answer never spends its per-subscriber budget and can never be dead-lettered while its process waits.
  - The wake (`wake()` → ResumeRetry with a fact) re-reads under the lock and resumes only if the process is still `suspended` at the parked step and its await still accepts the fact (stale = no-op, completed). Contention there propagates and `Drain` re-queues the claimed intent on the wake budget (2 s × 2ⁿ, cap 300 s, reported exhausted at 10 but never dropped), visible in the `wakeup` operator layer.
  - Parked only when the scheduler implements `ICarriesFacts`, the event id is known and the fact encodes (no NonReversibleValue). Otherwise, and for a lock failure raised inside the resumed step, `ProcessLockUnavailable` propagates as in wave 3.
  - **Opt-in on mem (fix round 1).** `InMemoryWakeupScheduler` does not implement `ICarriesFacts` (it is no longer `final`; `lenient()` returns `static`). The new `final class Testing\InMemoryParkingScheduler extends InMemoryWakeupScheduler implements ICarriesFacts` opts in. The mem conformance host keeps the wave-3 rule, so `lock.acquire-error` passes unchanged, and `ProcessRunnerWave3Test` is back to its base version.
  - **R1 on the wake path (fix round 1).** `resume_parked()` recomputes the candidate's exactness from the store lookup (`candidates()`) under the lock and passes it to the shared resume, so a subclass fact parked on an exact-match store reaches only an `AwaitAny`, as on delivery; a process no longer waiting for the fact is a no-op.
- **Why a capability marker.** pdo, wp and sf do not persist a fact on their wakeup rows yet. Without the marker the runner would park facts that come back without their payload. With it, every host keeps the wave-3 behaviour until it adds the column.
- **Requests.**
  - sf (TXP's host, the one this item is for): a nullable JSON `fact` column on `ddd_wakeups`, written by `schedule()` and returned on `claim_due()`, `DbalWakeupScheduler implements ICarriesFacts`, and `ProcessRunnerWakeTarget` already routes ResumeRetry through `wake()`. TXP then inverts `ProcessLockContentionTest::testAnAnswerHeldOffLongerThanTheDeliveryBudgetIsDeadLetteredAndTheProcessKeepsWaiting`.
  - pdo (`{prefix}_ddd_jobs`) and wp (`{prefix}_ddd_wakeups`; the AS projection keeps `['key' => …]`): the same column and marker. Optional.
  - conformance (no longer blocking since fix round 1; needed before any host declares `ICarriesFacts`): `lock.acquire-error`'s resume path (`LockScenarios.php:90-100`) pins the wave-3 behaviour ("the resume subscriber failed; its delivery is retried"). Proposed: when `$processes->worker()`'s scheduler is an `ICarriesFacts`, assert the outcome is complete, one ResumeRetry intent carrying the fact exists, and after `advance_clock(PAST_WAKE_BACKOFF)` + `drain_once()` the partial gather is saved (`version + 1`); otherwise keep the current assertions. A new `lock.parked-answer` scenario (with `MemHostFixture` switching to `InMemoryParkingScheduler` for it, or for all cases once `lock.acquire-error` branches) from `ProcessRunnerWave5Test::test_an_answer_held_off_longer_than_the_delivery_budget_still_resumes_the_process` would cover the host column. `process.await-all-concurrent` ("whichever lost the lock is retried by its delivery runner") should also drain once after the deliveries, for a host that parks.

## CR-W5CC-8: the resuming fact's event id (AW1)

- **What.** `LongProcess::resumed_by_event_id(): ?string`: the event id of the fact that resumed the current step; null in a step no fact resumed (first step, alarm PROCEED, precheck, an id-less fact) and in later steps. Written by the runner through `ProcessSteps::mark_resumed_by(?string)` on the persistence-only `LongProcess::steps()`; since fix round 1 the aggregate has no setter, so an application subclass cannot overwrite the cause. Stored as `ProcessSteps::$resumed_by` (`['step_index' => int, 'event_id' => string]`, new nullable trailing constructor parameter), written with the resuming save. A `#[RetryStep]` re-run, an `#[Async]` continuation and a parked resume (AW2) read the same id from the row. `ResumeSource::of_mechanism()` gains an optional `$event_id` and keeps it in the encoded event; `ResumeSource::fact()` / `event()` are that encoding for parked facts.
- **Store contract.** None for AW1: `resumed_by` lives in the `steps` JSON every host already persists whole (mem, pdo `ProcessCodec`, wp, sf `ProcessRowCodec`), and older readers ignore the extra key. The new per-host column is AW2's `fact` on the wakeup rows (CR-W5CC-7).
- **Not done.** For an AwaitAll the id is the fact that completed the gather; one event id per gathered key (the rollup's "AwaitAll would need one event id per gathered key") would change `AwaitAll`'s persisted tally and is left for a later round.

## Behaviour changes

1. **Fact resume under contention (AW2), mem only until a host opts in.** On a scheduler with `ICarriesFacts` (on mem: `InMemoryParkingScheduler` only), a resume that cannot lock no longer fails the `resume:` subscriber; it parks the fact as a ResumeRetry. The default mem scheduler, `ProcessRunnerWave3Test` and conformance `lock.acquire-error` keep the wave-3 behaviour.
2. **Recorded effects are not recorded again (E2), mem only until a host opts in.** With an `ITracksEffectState` journal, re-dispatching an effect whose entry is Recorded returns the journaled result without calling `record()`. `EffectMiddlewareTest::test_re_dispatching_under_a_new_command_id_does_not_bypass_the_journal` now expects one record instead of two. Conformance `effect.journal-reuse` passes unchanged on mem.
3. **Operator layer vocabulary.** `Layer::cases()` has one more value, `effect`, before `transport`. A host that lists layers explicitly (wp `WpOperatorView::LAYERS`) is unaffected until it adds it.
4. **Item command ids (W4).** The first command an item's `execute_one()` dispatches gets `for_item()` as its command id (it was random).
5. **Message texts.** `NoEffectJournal` says "is an effect command" (was "is an IExternalEffectCommand"); `RecordEffect::send()` says "send the effect command itself".

## Verification on this branch

See the fix-round-1 section below for the current numbers; the list here is the first round's.


- `vendor/bin/phpunit -c packages/ddd-core/phpunit.xml`: OK (512 tests).
- `vendor/bin/phpunit` (root): OK (929 tests, 9 PHPUnit deprecations as on the base).
- `DDD_PDO_DATABASE=ddd_w5_core_correctness vendor/bin/phpunit -c packages/ddd-core/phpunit.pdo.xml --testsuite pdo` on MySQL 8: OK (459 tests).
- ddd-symfony full suite on Postgres 16 (own database, vendor refreshed from this branch): OK (427 tests).
- ddd-conformance (vendor refreshed from this branch): 93/95; the two failures are `lock.acquire-error` on mem and simulated, as described in CR-W5CC-7.
- `vendor/bin/deptrac analyse`: 0 violations, 0 errors. `vendor/bin/phpstan analyse -c phpstan-core.neon`: no errors. WP-symbol grep over `packages/ddd-core/src`: empty. `composer cs`: no drift.

## Fix round 1

Review findings addressed:

- **major, conformance red:** fact carrying on mem is opt-in (`InMemoryParkingScheduler`); see CR-W5CC-7. ddd-conformance passes on this branch unchanged.
- **minor, W2 fallback:** hand-over to the host registry and a reset hook; see CR-W5CC-4.
- **minor, R1 on the wake path:** exactness recomputed; see CR-W5CC-7; test `ProcessRunnerWave5Test::test_a_parked_wake_keeps_the_r1_reachability_guard` (exact-match legacy store).
- **minor, `mark_resumed_by()` public on the aggregate:** moved to `ProcessSteps`; see CR-W5CC-8.
- **minor, ratification:** no code change; CR-W5CC-1..8 and the sf/pdo/wp requests stand as recorded for the coordinator.

Verification after fix round 1:

- `vendor/bin/phpunit -c packages/ddd-core/phpunit.xml`: OK (517 tests).
- `vendor/bin/phpunit` (root): OK (929 tests, 9 PHPUnit deprecations as on the base).
- ddd-conformance on a scratch copy of this branch (`COMPOSER_ROOT_VERSION=dev-main composer install`, `vendor/bin/phpunit`): OK (95 tests).
- `vendor/bin/deptrac analyse`: 0 violations, 0 errors. `vendor/bin/phpstan analyse -c phpstan-core.neon`: no errors. WP-symbol grep over `packages/ddd-core/src` (calls, `$wpdb`, `\WP_*` classes, `ABSPATH`): empty. `composer cs`: no drift.
