# House-style rename table (naming review)

The final names for the house-style rename of the extraction packages
(ddd-core incl. Runtime and Defaults/Pdo, ddd-wp, ddd-symfony public API,
ddd-conformance). The machine-readable form is
[`table.json`](table.json) (`[{fqcn, kind, from, to}]`). It is built from
[`inventory.json`](inventory.json) (library `761aef5`, TXP `6450f11`) and the
seven `proposals-*.json` files, with the reviewer's overrides applied.

- **402 renames** (methods and properties). Each one changes the name.
- Out of scope, as the brief says: the 0.6.6 frozen API (R1-R5 of
  `contract-register.md`), framework-required names, PHPUnit test methods,
  and class/interface names. The inventory excludes 91 such members; none of
  them appears here.
- A rename on an interface, abstract method or trait applies to every
  implementation and override (the inventory's `implementations`). This
  includes TXP's `IExternalEffectCommand` implementations and the conformance
  fixtures.

## How the names were checked

- **Clashes, structural.** A script parsed every class in `packages/`,
  `tests/`, `ddd-wordpress/`, `compat/`, `loader/` and TXP `src/`+`tests/`,
  and reflected their vendor ancestors (PHPUnit `TestCase`, Symfony, Doctrine,
  PSR). It then applied the table to each class's full lineage. No two
  members of one class or hierarchy end up with the same name. No new name
  collides with an existing member of the same lineage (inherited 0.6 names
  included), with a framework method on the same class, or with a PHP
  reserved word. The raw proposals already passed this
  check, so the clashes fixed below are semantic or cross-area ones.
- **Cross-area consistency.** Every concept that appears in more than one
  area has one spelling. The proposal authors had to *assume* what other
  areas would pick, and they disagreed in several places. Those disagreements
  are settled in the next section.
- **Call sites.** For each judgment call I read two or three call sites in
  the library, conformance or TXP.

## Clashes and inconsistencies fixed (overrides of the proposals)

| Concept | Proposals said | Final | Why |
|---|---|---|---|
| fact class of an outbox row | pdo `class_of`, sf `event_class` (same method on both stores) | `event_class_of` on both | One name across the two stores. It uses the `_of($key)` lookup family (`status_of`, `version_of`, `name_of`). A bare `event_class()` would read as an accessor of the store itself. |
| event id of an outbox row (`IOutboxRowIds`) | `event_id` | `event_id_of` | Parallel to `event_class_of`. The argument is an outbox row id, not the store's own id. |
| class scope for appends | `with_class` (pdo, sf) | `with_event_class` | `event_class` is the one spelling of the concept everywhere (columns, `FactRef`, `DeliveryJob`, `AwaitRoute`, 0.6 `event_class()`). |
| live pause selectors as regexes | pdo `active_patterns`, sf `patterns`, wp `active_selectors` | pdo+sf `patterns($now)`, wp `selectors($now)` | pdo and sf return the same thing (regexes), so they now have one name. wp returns the raw selectors, which is a different thing and gets its own noun. The `$now` argument already says "active". |
| claim-time dead letters | outbox `take_dead_lettered`, effects `claim_dead_letters` (they had assumed each other's names) | `take_claim_dead_letters()` / `ProcessingResult::$claim_dead_letters` | One noun, taken from the interface name `IReportsClaimDeadLetters`. The verb `take_` marks that it drains the list. It cannot be confused with `dead_letters()` on the same store. |
| wakeup idempotency key | core `WakeupIntent::$key`, sf `ProcessWakeupMessage::$idempotency_key` | `key` on both | The message mirrors the intent field for field (`from_claim`/`to_claim`). `IExternalEffectCommand::idempotency_key()` stays long (see the judgment calls). |
| claim token | core `token`, sf assumed `claim_token` everywhere | `Claim::$token`, `ClaimedWakeup::$token`, `ProcessWakeupMessage::$claim_token` | Deliberate. On a claim the word "claim" stutters. On the message there is no claim context, and `key` sits beside it. |
| subscribed fact classes | pdo `RecordingSubscriptionRegistry::classes`, sf `CompiledSubscriptionRegistry::fact_classes` | `fact_classes` on both | The same concept on two registries. `FreshProcessBoot::fact_classes` already matches. |
| `IExternalEffectCommand::failureCommand` | `compensation` (low confidence) | `failure_command` | `compensation` collides in meaning with the frozen 0.6 `compensation_for()` (process-step undo), which is a different mechanism. |
| `WakeupRelayReport::didWork` | `is_idle` | `did_work` | `is_idle` inverts the meaning, so it is a logic change at the two `RelayCommand` call sites, not a rename. `did_work` joins the verb-predicate exceptions (`needs_retry`, `shares_connection`, `in_transaction`). |
| `PersistenceConflict::fromUniqueViolationIn` | `from` | `find_in` | `from()` reads like `BackedEnum::from()`, which never returns null. This method returns `?self`. `find_` is the house lookup verb, and `find_in($e) ?? $e` reads correctly. |
| `InMemoryTransport::returnNoRefNext` | `drop_ref_next` | `drop_next_ref` | The one-shot family is `verb_next[_object]`: `fail_next_commit`, `fail_next_acquire`, `drop_next_wakeup`, `race_next_relay`. |
| `WebRequests::bootInBandStartOnPooledDsn` | `boot_pooled_inband` | `boot_inband_pooled` | The subject comes first, as in the config key `inband_start`. `inband` is the existing token (`StartMode::InBand = 'inband'`). |

## Conventions this table settles

- **`_of($key)` for keyed lookups** on stores, ledgers and test doubles:
  `status_of`, `attempts_of`, `record_of`, `version_of`, `event_id_of`,
  `event_class_of`, `name_of`, `prefix_of`, `delivery_of`, `key_of`.
- **`verb_next[_object]` for one-shot test faults**: `fail_next_commit`,
  `fail_next_acquire`, `reject_next`, `drop_next_ref`, `fail_next_lock`,
  `crash_next_relay`, `before_next_lock`. The object is dropped when the
  receiver implies it (`InMemoryTransport::reject_next`) and kept on the flat
  fixture namespace (`HostFixture::reject_next_submission`).
- **`_for_tests` stays on production classes and goes on test doubles.** It
  stays on `HostDefaults::reset_for_tests`,
  `RuntimeReset::forget_for_tests`, `WpLedgeredDelivery::reset_for_tests` and
  `WpdbTransactionDepth::reset_for_tests`, because there it guards a seam.
  It is dropped in `TangibleDDD\Testing\*`, where the namespace already says
  it (`forget`, `corrupt_class`).
- **Units stay in the name**: `backoff_seconds` (matches the 0.6
  `OutboxProcessor::backoff_seconds`), `duration_ms`, `peak_memory_bytes`,
  `max_bytes`, `poll_seconds`.
- **`_once` marks a single pass**: `run_once`, `relay_once`, `drain_once`.
- **Flat fixture namespaces keep a qualifier** that a focused class drops:
  `EffectHost::effect_journal` vs `DurableRuntime::journal`, and
  `HostFixture::reject_next_submission` vs `InMemoryTransport::reject_next`.
- **Accessors prefer a bare noun** (`status()`, `event_id()`, `pooler()`).
  Dropping `get_` is a preference, not a rule: keep it where the bare noun
  would be ambiguous, would read like a verb, or would break a family of
  existing names (the 0.6 `get_id()`, `get_by_*()`).
- **Verb predicates are allowed only where `is_`/`has_` misreads**:
  `needs_retry`, `shares_connection`, `captures_parameters`,
  `in_transaction`, `did_work`. Every other predicate is `is_`/`has_`.

## Follow-ups the rename commit must carry (not names, but required)

- **String references:** `getSubscribedEvents()` strings (`on_command`,
  `on_terminate`, `on_handled`, `on_failed`); the `services.php` factory
  strings for `Factory::*`; `[DbalWakeupScheduler::class, 'intentOf']` in
  `tests/Conformance/SfHostFixture.php`; the `beginKey`/`finishKey`
  `write()` labels in `WpdbWakeupScheduler`.
- **Promoted constructor properties are also named arguments.** Update every
  named-argument call along with the property, for example
  `new MemHostFixture(shared_connection: true)`,
  `new BusOptions(boundary: false)` and `maxBytes:`.
- **Messenger messages:** renaming properties of `IntegrationFactMessage` and
  `ProcessWakeupMessage` changes their serialized form. Drain `ddd_facts` and
  `ddd_wakeups` before deploying.
- **Private twins should follow** so the class reads in one vocabulary:
  `DbalRelayPauseStore::activeSelectors()` → `selectors()`,
  `PdoPauseStore::activeSelectors()` → `selectors()`,
  `OutboxFactClassResolver::$knownFactClasses` → `$fact_classes`,
  `PostgresListenWaiter::$lastPayloads` → `$payloads`,
  `DbalPostgresOutboxStore::$deadLetteredAtClaim` → `$claim_dead_letters`.
  The private `DbalPostgresOutboxStore::recordOf()` only delegates to
  `record_from_row()`, so it can be deleted.
- **TXP:** the inventory's TXP hits for `fetchOne`, `rollBack`,
  `effectJournal` and `workItems` are Doctrine DBAL or TXP's own test
  helpers. Do not rename them. TXP's real renames are on the
  `IExternalEffectCommand` implementations (`idempotency_key`,
  `failure_command`) and on library calls.
- **Zero-call-site members** (`WpNamedLock::is_free`,
  `ConnectionTopology::is_pooled`, `DurableRuntime::listeners`): they are
  renamed here, but they could be removed instead. That is the operator's
  call.

## Judgment calls

The names where taste matters most. Each one gives the name chosen, the
alternative rejected, and the reason.

| # | Member | Chosen | Rejected | Why |
|---|---|---|---|---|
| 1 | `Claim::claimToken`, `ClaimedWakeup::claimToken` | `token` | `claim_token` | `$claim->token` does not stutter. The message keeps `claim_token` because it has no claim context. |
| 2 | `WakeupIntent::idempotencyKey` (+ message) | `key` | `idempotency_key` | The class doc and `cancel()` already say "the key". `$intent->key` sits next to `$intent->kind`. |
| 3 | `IExternalEffectCommand::idempotencyKey` | `idempotency_key` | `key` | This is a user-implemented command interface: a bare `key()` is too generic and could collide with a field on the user's command. |
| 4 | `WorkflowIgnition*::dedupKey` | `key` | `ignition_key` | On a ledger entry the key is the identity. "dedup" says how it is used, not what it is. |
| 5 | `IReportsClaimDeadLetters::takeDeadLetteredAtClaim` | `take_claim_dead_letters` | `take_dead_lettered` | It keeps the interface's noun and pairs with `ProcessingResult::$claim_dead_letters`. A bare participle reads oddly as the object of `take`. |
| 6 | `IExternalEffectCommand::failureCommand` | `failure_command` | `compensation` | 0.6 `compensation_for()` already means step undo. A second meaning would mislead. |
| 7 | `LargeString::toPayload` / `fromPayload` | `encode` / `decode` | `to_payload` / `from_payload` | It pairs with `is_encoded()` and the `UndecodableLargeString` exception, and the class doc already uses these verbs. |
| 8 | `PdoOutboxStore`/`DbalPostgresOutboxStore::eventClassOf` | `event_class_of` | `class_of`, `event_class` | One name on both stores, in the `_of` lookup family. `class_of` drops the concept's name. |
| 9 | `*OutboxStore::withFactClass` | `with_event_class` | `with_class` | `event_class` is the concept everywhere. `with_` follows 0.6 `with_lock`. |
| 10 | `PdoPauseStore`/`DbalRelayPauseStore::activePatterns` | `patterns` | `active_patterns` | `$now` already implies "active". `WpRelayPauseStore` gets `selectors` for its different return value. |
| 11 | `WakeupRelayReport::didWork` | `did_work` | `is_idle` | `is_idle` is the negation, so it would need a logic change at the call sites. A rename must not invert. |
| 12 | `PersistenceConflict::fromUniqueViolationIn` | `find_in` | `from` | The method returns null when there is no violation. `from()` promises non-null (`BackedEnum`). |
| 13 | `ITransport::sharesConnectionWith` | `shares_connection` | `shares_connection_with` | The `IOutboxStore` argument carries the "with". `$transport->shares_connection($store)` still reads well. |
| 14 | `IHostConnection::inTransaction` | `in_transaction` | `is_in_transaction` | It is the PDO/DBAL idiom. `is_active` belongs to `ITransactionBoundary` and would mean something else on a connection. |
| 15 | `DeliveryOutcome::needsRetry` | `needs_retry` | `has_failed` | The method answers the retry decision. Failure also covers compensation-pending. |
| 16 | `HostDefaults::resetForTests` (and wp twins) | `reset_for_tests` | `reset` | The suffix is the guard. Production must never clear host defaults, and a bare `reset()` invites misuse. |
| 17 | `InMemoryOutboxStore::forgetRowForTests` | `forget` | `forget_row_for_tests` | The class is already a test double in `Testing\`. `$store->forget($event_id)` is enough. |
| 18 | `IProcessLock::forceReleaseAll` | `release_all` | `force_release_all` | It is never the normal path, and "all" already carries the force. |
| 19 | `InMemoryWakeupScheduler::withoutTransactionCheck` | `lenient` | `unchecked` | The class doc already names it "lenient mode". `InMemoryWakeupScheduler::lenient()` reads as a named constructor. |
| 20 | `DurableRuntime::effectJournal` / `workflowIgnitions` / `localListeners` | `journal` / `ignitions` / `listeners` | `effect_journal` / `workflow_ignitions` / `local_listeners` | The runtime holds one of each, and its siblings are store nouns (`jobs`, `outbox`, `processes`, `workflows`). The flat `EffectHost` keeps `effect_journal`. |
| 21 | `OperatorItem::repairActions` | `repairs` | `repair_actions` | It is a list of repair command names, so the short noun is enough. The `to_array()` wire key stays `repair_actions` (out of scope). |
| 22 | `UndecodableLargeString::quarantineReason` | `reason` | `quarantine_reason` | It holds exactly the constructor's `$reason`, and `$e->reason` reads well. The host column stays `quarantine_reason`. |
| 23 | `PdoJobStore::withClaimKinds` | `claiming` | `with_claim_kinds` | `$jobs->claiming(WakeKind::Deliver)` says what the view narrows: only what `claim_due` leases. |
| 24 | `Subscriber::eventClassOrMarker` | `event_class` | `event_class_or_marker` | A class-string already admits marker interfaces (`is_a`). The docblock keeps that note. |
| 25 | `HostFixture`/`ProcessWorker::processLock`, `processRunner` | `lock`, `runner` | `process_lock`, `process_runner` | The fixture has one lock port. The family is `lock_key`, `hold_lock_elsewhere`, `fail_next_lock` and `lock_acquisitions`. `Factory::process_lock` keeps the qualifier because a factory builds many locks. |
| 26 | `FreshProcesses::*InFreshProcess` | `publish_fresh` / `drain_fresh` / `deliver_fresh` / `start_fresh` | `publish_in_fresh_process`, ... | They are one family that returns `FreshRun`. The suffix says the rest. |
| 27 | `WpNamedLock::isFreeOrHeldHere` | `is_free_or_mine` | `is_held_elsewhere` | Inverting it would flip the fail-closed default on a query error. "mine" is the shortest word that keeps the "held here" half. |
| 28 | `AggregateRootRepository::get_aggregate_class` | `aggregate_class` | keep `get_aggregate_class` | Dropping `get_` is a preference, applied here because the bare noun reads fine. The frozen sibling `PersistsAggregatesRepository::get_aggregate_class()` is grandfathered, so a subclass that moves between the two bases renames one protected override. |
| 29 | `RuntimeReset::forgetRegistrationsForTests` | `forget_for_tests` | `reset_for_tests` | On a class called `RuntimeReset`, "reset" already means `between_messages()`. |
| 30 | `ConnectionTopology::describePooler` | `pooler` | `describe_pooler` | A bare noun accessor: the detected pooler as a reason string, or null. Call sites bind it to `$why`. |

## The table, by area

### Core: outbox and delivery (40)

| Class | Kind | From | To |
|---|---|---|---|
| `Runtime\Delivery\DeliveryBudgetExhausted` | prop | `eventId` | `event_id` |
| `Runtime\Delivery\DeliveryBudgetExhausted` | prop | `subscriberId` | `subscriber_id` |
| `Runtime\Delivery\DeliveryOutcome` | method | `isComplete` | `is_complete` |
| `Runtime\Delivery\DeliveryOutcome` | method | `needsRetry` | `needs_retry` |
| `Runtime\Delivery\IDeliveryLedger` | method | `lastError` | `last_error` |
| `Runtime\Delivery\IDeliveryLedger` | method | `markDelivered` | `mark_delivered` |
| `Runtime\Delivery\IDeliveryLedger` | method | `markExhausted` | `mark_exhausted` |
| `Runtime\Delivery\IDeliveryLedger` | method | `markFailed` | `mark_failed` |
| `Runtime\Delivery\IDeliveryWorker` | method | `runDue` | `run_due` |
| `Runtime\Delivery\ISubscriberProbe` | method | `hasSubscribers` | `has_subscribers` |
| `Runtime\Delivery\ITransport` | method | `sharesConnectionWith` | `shares_connection` |
| `Runtime\Delivery\IntegrationDelivery` | method | `backoffSeconds` | `backoff_seconds` |
| `Runtime\Delivery\Subscriber` | prop | `eventClassOrMarker` | `event_class` |
| `Runtime\Delivery\Subscriber` | prop | `onExhausted` | `on_exhausted` |
| `Runtime\Delivery\SubscriptionRegistrar` | method | `registerListener` | `register_listener` |
| `Runtime\Delivery\SubscriptionRegistrar` | method | `registerProcess` | `register_process` |
| `Runtime\Drain` | method | `runOnce` | `run_once` |
| `Runtime\DrainReport` | prop | `stoppedBy` | `stopped_by` |
| `Runtime\DrainReport` | prop | `wakesCompleted` | `wakes_completed` |
| `Runtime\DrainReport` | prop | `wakesExhausted` | `wakes_exhausted` |
| `Runtime\DrainReport` | prop | `wakesLeaseLost` | `wakes_lease_lost` |
| `Runtime\DrainReport` | prop | `wakesRetried` | `wakes_retried` |
| `Runtime\Outbox\Claim` | prop | `claimToken` | `token` |
| `Runtime\Outbox\Claim` | prop | `leaseUntil` | `lease_until` |
| `Runtime\Outbox\DeadLetter` | prop | `deadLetteredAt` | `dead_lettered_at` |
| `Runtime\Outbox\DeadLetter` | prop | `dlqId` | `dlq_id` |
| `Runtime\Outbox\IOutboxAdministration` | method | `deadLetters` | `dead_letters` |
| `Runtime\Outbox\IOutboxRowIds` | method | `eventIdOf` | `event_id_of` * |
| `Runtime\Outbox\IOutboxStore` | method | `deadLetter` | `dead_letter` |
| `Runtime\Outbox\IOutboxStore` | method | `retryLater` | `retry_later` |
| `Runtime\Outbox\IRelayPauseStore` | method | `isPaused` | `is_paused` |
| `Runtime\Outbox\IReportsClaimDeadLetters` | method | `takeDeadLetteredAtClaim` | `take_claim_dead_letters` * |
| `Testing\InMemoryOutboxStore` | method | `attemptsOf` | `attempts_of` |
| `Testing\InMemoryOutboxStore` | method | `eventIds` | `event_ids` |
| `Testing\InMemoryOutboxStore` | method | `forgetRowForTests` | `forget` |
| `Testing\InMemoryOutboxStore` | method | `recordOf` | `record_of` |
| `Testing\InMemoryOutboxStore` | method | `statusOf` | `status_of` |
| `Testing\InMemoryOutboxStore` | method | `transportRefOf` | `transport_ref_of` |
| `Testing\InMemoryTransport` | method | `rejectNext` | `reject_next` |
| `Testing\InMemoryTransport` | method | `returnNoRefNext` | `drop_next_ref` * |

### Core: process, lock and wakeup (61)

| Class | Kind | From | To |
|---|---|---|---|
| `Application\BehaviourWorkflows\WorkflowIgnition` | prop | `createdAt` | `created_at` |
| `Application\BehaviourWorkflows\WorkflowIgnition` | prop | `dedupKey` | `key` |
| `Application\BehaviourWorkflows\WorkflowIgnition` | prop | `eventId` | `event_id` |
| `Application\BehaviourWorkflows\WorkflowIgnition` | prop | `workflowId` | `workflow_id` |
| `Application\BehaviourWorkflows\WorkflowIgnitionKey` | method | `forFact` | `for_fact` |
| `Application\BehaviourWorkflows\WorkflowIgnitionKey` | method | `perMinute` | `per_minute` |
| `Application\BehaviourWorkflows\WorkflowIgnitionKey` | method | `startMarker` | `start_marker` |
| `Application\BehaviourWorkflows\WorkflowIgnitionResult` | prop | `dedupKey` | `key` |
| `Application\BehaviourWorkflows\WorkflowIgnitionResult` | prop | `startError` | `start_error` |
| `Application\BehaviourWorkflows\WorkflowIgnitionResult` | prop | `startPending` | `start_pending` |
| `Application\BehaviourWorkflows\WorkflowIgnitionResult` | prop | `workflowId` | `workflow_id` |
| `Application\BehaviourWorkflows\WorkflowStartPending` | prop | `dedupKey` | `key` |
| `Application\BehaviourWorkflows\WorkflowStartPending` | prop | `workflowId` | `workflow_id` |
| `Application\Process\AwaitAny` | method | `cancelledBy` | `cancelled_by` |
| `Application\Process\ResumeReport` | method | `isUnheard` | `is_unheard` |
| `Application\Process\ResumeSource` | method | `ofMechanism` | `of_mechanism` |
| `Application\Process\ResumeSource` | method | `ofValue` | `of_value` |
| `Runtime\Lock\IProcessLock` | method | `forceReleaseAll` | `release_all` |
| `Runtime\Lock\IProcessLock` | method | `heldCount` | `held_count` |
| `Runtime\Lock\LockKey` | method | `mysqlName` | `mysql_name` |
| `Runtime\Lock\LockKey` | method | `postgresKey` | `postgres_key` |
| `Runtime\Lock\LockKey` | prop | `processId` | `process_id` |
| `Runtime\Process\AwaitRoute` | method | `keyOf` | `key_of` |
| `Runtime\Process\AwaitRoute` | prop | `awaitKey` | `await_key` |
| `Runtime\Process\AwaitRoute` | prop | `eventClass` | `event_class` |
| `Runtime\Process\IProcessStore` | method | `findStranded` | `find_stranded` |
| `Runtime\Process\IProcessStore` | method | `findWaitingFor` | `find_waiting_for` |
| `Runtime\Process\IProcessStore` | method | `insertIgnited` | `insert_ignited` |
| `Runtime\Process\IProcessStore` | method | `versionOf` | `version_of` |
| `Runtime\Process\IStrandedScanner` | method | `scanStranded` | `scan_stranded` |
| `Runtime\Process\StrandedProcess` | prop | `processClass` | `process_class` |
| `Runtime\Process\StrandedProcess` | prop | `processId` | `process_id` |
| `Runtime\Process\StrandedProcess` | prop | `stepIndex` | `step_index` |
| `Runtime\Process\StrandedProcess` | prop | `updatedAt` | `updated_at` |
| `Runtime\Scheduling\ClaimedWakeup` | prop | `claimToken` | `token` |
| `Runtime\Scheduling\ClaimedWakeup` | prop | `leaseUntil` | `lease_until` |
| `Runtime\Scheduling\IWakeupScheduler` | method | `claimDue` | `claim_due` |
| `Runtime\Scheduling\IWakeupScheduler` | method | `retryLater` | `retry_later` |
| `Runtime\Scheduling\WakeRetryPolicy` | method | `backoffSeconds` | `backoff_seconds` |
| `Runtime\Scheduling\WakeupIntent` | method | `resumeRetry` | `resume_retry` |
| `Runtime\Scheduling\WakeupIntent` | method | `retryVersion` | `retry_version` |
| `Runtime\Scheduling\WakeupIntent` | method | `timeoutKey` | `timeout_key` |
| `Runtime\Scheduling\WakeupIntent` | prop | `dueAt` | `due_at` |
| `Runtime\Scheduling\WakeupIntent` | prop | `expectedStatus` | `expected_status` |
| `Runtime\Scheduling\WakeupIntent` | prop | `idempotencyKey` | `key` |
| `Runtime\Scheduling\WakeupIntent` | prop | `processId` | `process_id` |
| `Runtime\Scheduling\WakeupIntent` | prop | `stepIndex` | `step_index` |
| `Testing\InMemoryNamedLock` | method | `failNextAcquire` | `fail_next_acquire` |
| `Testing\InMemoryNamedLock` | method | `heldCount` | `held_count` |
| `Testing\InMemoryNamedLock` | method | `holdElsewhere` | `hold_elsewhere` |
| `Testing\InMemoryProcessLock` | method | `acquireCount` | `acquisitions` |
| `Testing\InMemoryProcessLock` | method | `failNextAcquire` | `fail_next_acquire` |
| `Testing\InMemoryProcessLock` | method | `holdElsewhere` | `hold_elsewhere` |
| `Testing\InMemoryProcessLock` | method | `releaseBugs` | `release_bugs` |
| `Testing\InMemoryProcessLock` | method | `releaseElsewhere` | `release_elsewhere` |
| `Testing\InMemoryProcessStore` | method | `attachIntents` | `attach_intents` |
| `Testing\InMemoryProcessStore` | method | `corruptClassForTests` | `corrupt_class` |
| `Testing\InMemoryProcessStore` | method | `ignitionKeyOf` | `ignition_key_of` |
| `Testing\InMemoryProcessStore` | method | `quarantineReasonOf` | `quarantine_reason_of` |
| `Testing\InMemoryProcessStore` | method | `statusOf` | `status_of` |
| `Testing\InMemoryWakeupScheduler` | method | `withoutTransactionCheck` | `lenient` |

### Core: effects, audit, ops, codec, misc (43)

| Class | Kind | From | To |
|---|---|---|---|
| `Application\Correlation\FactRef` | prop | `correlationId` | `correlation_id` |
| `Application\Correlation\FactRef` | prop | `eventClass` | `event_class` |
| `Application\Correlation\FactRef` | prop | `eventId` | `event_id` |
| `Infra\Persistence\Shared\AggregateRootRepository` | method | `get_aggregate_class` | `aggregate_class` |
| `Infra\Services\LeaseLostOnAccept` | prop | `eventId` | `event_id` |
| `Infra\Services\ProcessingResult` | prop | `deadLettered` | `dead_lettered` |
| `Infra\Services\ProcessingResult` | prop | `deadLetteredAtClaim` | `claim_dead_letters` |
| `Infra\Services\ProcessingResult` | prop | `leaseLost` | `lease_lost` |
| `Runtime\Audit\AuditClose` | prop | `commandId` | `command_id` |
| `Runtime\Audit\AuditClose` | prop | `durationMs` | `duration_ms` |
| `Runtime\Audit\AuditClose` | prop | `peakMemoryBytes` | `peak_memory_bytes` |
| `Runtime\Audit\AuditOpen` | prop | `causationId` | `causation_id` |
| `Runtime\Audit\AuditOpen` | prop | `causationType` | `causation_type` |
| `Runtime\Audit\AuditOpen` | prop | `commandId` | `command_id` |
| `Runtime\Audit\AuditOpen` | prop | `commandName` | `command_name` |
| `Runtime\Audit\AuditOpen` | prop | `correlationId` | `correlation_id` |
| `Runtime\Audit\AuditOpen` | prop | `startedAt` | `started_at` |
| `Runtime\Audit\IAuditPolicy` | method | `captureParameters` | `captures_parameters` |
| `Runtime\Codec\LargeString` | method | `fromPayload` | `decode` |
| `Runtime\Codec\LargeString` | method | `isEncoded` | `is_encoded` |
| `Runtime\Codec\LargeString` | method | `toPayload` | `encode` |
| `Runtime\Codec\LargeString` | prop | `maxBytes` | `max_bytes` |
| `Runtime\Codec\PayloadTooLarge` | prop | `maxBytes` | `max_bytes` |
| `Runtime\Codec\UndecodableLargeString` | prop | `quarantineReason` | `reason` |
| `Runtime\Effects\EffectResult` | prop | `externalRef` | `external_ref` |
| `Runtime\Effects\IExternalEffectCommand` | method | `failureCommand` | `failure_command` * |
| `Runtime\Effects\IExternalEffectCommand` | method | `idempotencyKey` | `idempotency_key` |
| `Runtime\HostDefaults` | method | `onMiss` | `on_miss` |
| `Runtime\HostDefaults` | method | `resetForTests` | `reset_for_tests` |
| `Runtime\ITransactionBoundary` | method | `isActive` | `is_active` |
| `Runtime\Ids\DeterministicCommandId` | method | `forFact` | `for_fact` |
| `Runtime\Ids\DeterministicCommandId` | method | `forStep` | `for_step` |
| `Runtime\Ops\OperatorItem` | method | `toArray` | `to_array` |
| `Runtime\Ops\OperatorItem` | prop | `firstSeen` | `first_seen` |
| `Runtime\Ops\OperatorItem` | prop | `lastError` | `last_error` |
| `Runtime\Ops\OperatorItem` | prop | `repairActions` | `repairs` |
| `Runtime\RuntimeReset` | method | `betweenMessages` | `between_messages` |
| `Runtime\RuntimeReset` | method | `forgetRegistrationsForTests` | `forget_for_tests` |
| `Runtime\RuntimeReset` | method | `guardLock` | `guard` |
| `Testing\InMemoryTransactionBoundary` | method | `failNextCommit` | `fail_next_commit` |
| `Testing\InMemoryTransactionBoundary` | method | `failNextRollback` | `fail_next_rollback` |
| `Testing\InMemoryTransactional` | method | `restoreState` | `restore` |
| `Testing\InMemoryTransactional` | method | `snapshotState` | `snapshot` |

### Core: Defaults/Pdo (36)

| Class | Kind | From | To |
|---|---|---|---|
| `Defaults\Pdo\DeliveryJob` | prop | `eventClass` | `event_class` |
| `Defaults\Pdo\DeliveryJob` | prop | `eventId` | `event_id` |
| `Defaults\Pdo\DeliveryJob` | prop | `eventType` | `event_type` |
| `Defaults\Pdo\DeliveryJob` | prop | `integrationAction` | `integration_action` |
| `Defaults\Pdo\DurableRuntime` | method | `effectJournal` | `journal` |
| `Defaults\Pdo\DurableRuntime` | method | `localListeners` | `listeners` |
| `Defaults\Pdo\DurableRuntime` | method | `operatorView` | `operator_view` |
| `Defaults\Pdo\DurableRuntime` | method | `queryBus` | `query_bus` |
| `Defaults\Pdo\DurableRuntime` | method | `workItems` | `work_items` |
| `Defaults\Pdo\DurableRuntime` | method | `workflowIgnitions` | `ignitions` |
| `Defaults\Pdo\IHostConnection` | method | `fetchAll` | `fetch_all` |
| `Defaults\Pdo\IHostConnection` | method | `fetchOne` | `fetch_one` |
| `Defaults\Pdo\IHostConnection` | method | `inTransaction` | `in_transaction` |
| `Defaults\Pdo\IHostConnection` | method | `isDuplicateKey` | `is_duplicate_key` |
| `Defaults\Pdo\IHostConnection` | method | `lastInsertId` | `last_insert_id` |
| `Defaults\Pdo\IHostConnection` | method | `rollBack` | `rollback` |
| `Defaults\Pdo\Internal\Glob` | method | `toRegex` | `to_regex` |
| `Defaults\Pdo\Internal\OutboxRows` | method | `insertSql` | `insert_sql` |
| `Defaults\Pdo\Internal\OutboxRows` | method | `sharedValues` | `shared_values` |
| `Defaults\Pdo\Internal\RecordingSubscriptionRegistry` | method | `subscribedClasses` | `fact_classes` * |
| `Defaults\Pdo\Internal\RuntimeContainer` | method | `handlerFor` | `handler_for` |
| `Defaults\Pdo\Internal\RuntimeContainer` | method | `setDefaultHandler` | `set_default_handler` |
| `Defaults\Pdo\Internal\Utc` | method | `fromDb` | `from_db` |
| `Defaults\Pdo\Internal\Utc` | method | `fromDbOrNull` | `from_db_or_null` |
| `Defaults\Pdo\Internal\Utc` | method | `toDb` | `to_db` |
| `Defaults\Pdo\MySqlNamedLock` | method | `nameOf` | `name_of` |
| `Defaults\Pdo\PdoConnection` | method | `assertErrmode` | `assert_errmode` |
| `Defaults\Pdo\PdoJobStore` | method | `deliveryOf` | `delivery_of` |
| `Defaults\Pdo\PdoJobStore` | method | `hasLiveIntent` | `has_live_intent` |
| `Defaults\Pdo\PdoJobStore` | method | `withClaimKinds` | `claiming` |
| `Defaults\Pdo\PdoOperatorView` | method | `repairItem` | `repair_item` |
| `Defaults\Pdo\PdoOperatorView` | method | `toArrays` | `to_arrays` |
| `Defaults\Pdo\PdoOutboxStore` | method | `appendFact` | `append_fact` |
| `Defaults\Pdo\PdoOutboxStore` | method | `eventClassOf` | `event_class_of` * |
| `Defaults\Pdo\PdoOutboxStore` | method | `withFactClass` | `with_event_class` * |
| `Defaults\Pdo\PdoPauseStore` | method | `activePatterns` | `patterns` * |

### WordPress adapters (ddd-wp) (32)

| Class | Kind | From | To |
|---|---|---|---|
| `WordPress\Adapter\GetLockProcessLock` | method | `legacyName` | `legacy_name` |
| `WordPress\Adapter\WpDeliveryLedger` | method | `failedSubscribers` | `failures` |
| `WordPress\Adapter\WpDeliveryLedger` | method | `markExhaustedBecause` | `mark_exhausted_because` |
| `WordPress\Adapter\WpDeliveryLedger` | method | `markFailedFor` | `mark_failed_with` |
| `WordPress\Adapter\WpLargeEnvelope` | method | `forTransport` | `for_transport` |
| `WordPress\Adapter\WpLargeEnvelope` | method | `isReference` | `is_reference` |
| `WordPress\Adapter\WpLedgeredDelivery` | method | `orphanedRedeliveries` | `orphan_count` |
| `WordPress\Adapter\WpLedgeredDelivery` | method | `prefixOf` | `prefix_of` |
| `WordPress\Adapter\WpLedgeredDelivery` | method | `registerConsumer` | `register_consumer` |
| `WordPress\Adapter\WpLedgeredDelivery` | method | `resetForTests` | `reset_for_tests` |
| `WordPress\Adapter\WpLedgeredDelivery` | method | `restoreRedeliveries` | `restore_redeliveries` |
| `WordPress\Adapter\WpLedgeredDelivery` | method | `subscriberId` | `subscriber_id` |
| `WordPress\Adapter\WpNamedLock` | method | `acquireBoth` | `acquire_both` |
| `WordPress\Adapter\WpNamedLock` | method | `isFree` | `is_free` |
| `WordPress\Adapter\WpNamedLock` | method | `isFreeOrHeldHere` | `is_free_or_mine` |
| `WordPress\Adapter\WpNamedLock` | method | `releaseBoth` | `release_both` |
| `WordPress\Adapter\WpRelayPauseStore` | method | `activeSelectors` | `selectors` * |
| `WordPress\Adapter\WpRelayTick` | method | `isPortForm` | `is_port_form` |
| `WordPress\Adapter\WpRelayTickReport` | prop | `portForm` | `port_form` |
| `WordPress\Adapter\WpRelayTickReport` | prop | `redeliveriesRestored` | `restored` |
| `WordPress\Adapter\WpRollbackDrain` | method | `futureByReference` | `future_references` |
| `WordPress\Adapter\WpSchema` | method | `atLeast` | `at_least` |
| `WordPress\Adapter\WpSchema` | method | `isV8` | `is_v8` |
| `WordPress\Adapter\WpSchema` | method | `lastErrorIsDuplicateKey` | `is_duplicate_key` |
| `WordPress\Adapter\WpStrandedReport` | prop | `alreadyQueued` | `queued` |
| `WordPress\Adapter\WpWakeBracket` | method | `resumeRetry` | `resume_retry` |
| `WordPress\Adapter\WpdbTransactionDepth` | method | `resetForTests` | `reset_for_tests` |
| `WordPress\Adapter\WpdbWakeupScheduler` | method | `backoffSeconds` | `backoff_seconds` |
| `WordPress\Adapter\WpdbWakeupScheduler` | method | `beginKey` | `begin_key` |
| `WordPress\Adapter\WpdbWakeupScheduler` | method | `finishKey` | `finish_key` |
| `WordPress\Adapter\WpdbWakeupScheduler` | method | `hasExhaustedIntent` | `has_exhausted_intent` |
| `WordPress\Adapter\WpdbWakeupScheduler` | method | `hasLiveIntent` | `has_live_intent` |

### Symfony (ddd-symfony) (56)

| Class | Kind | From | To |
|---|---|---|---|
| `Symfony\Messenger\IFactClassResolver` | method | `classFor` | `resolve` |
| `Symfony\Messenger\IntegrationFactMessage` | prop | `eventClass` | `event_class` |
| `Symfony\Messenger\IntegrationFactMessage` | prop | `eventId` | `event_id` |
| `Symfony\Messenger\IntegrationFactMessage` | prop | `eventType` | `event_type` |
| `Symfony\Messenger\IntegrationFactMessage` | prop | `integrationAction` | `integration_action` |
| `Symfony\Messenger\IntegrationFactMessage` | prop | `wrappedPayload` | `envelope` |
| `Symfony\Messenger\ProcessWakeupHandler` | method | `backoffSeconds` | `backoff_seconds` |
| `Symfony\Messenger\ProcessWakeupMessage` | method | `fromClaim` | `from_claim` |
| `Symfony\Messenger\ProcessWakeupMessage` | method | `toClaim` | `to_claim` |
| `Symfony\Messenger\ProcessWakeupMessage` | prop | `claimToken` | `claim_token` |
| `Symfony\Messenger\ProcessWakeupMessage` | prop | `dueAt` | `due_at` |
| `Symfony\Messenger\ProcessWakeupMessage` | prop | `expectedStatus` | `expected_status` |
| `Symfony\Messenger\ProcessWakeupMessage` | prop | `idempotencyKey` | `key` * |
| `Symfony\Messenger\ProcessWakeupMessage` | prop | `leaseUntil` | `lease_until` |
| `Symfony\Messenger\ProcessWakeupMessage` | prop | `processId` | `process_id` |
| `Symfony\Messenger\ProcessWakeupMessage` | prop | `stepIndex` | `step_index` |
| `Symfony\Persistence\ConnectionTopology` | method | `describePooler` | `pooler` |
| `Symfony\Persistence\ConnectionTopology` | method | `isPooled` | `is_pooled` |
| `Symfony\Persistence\DbalPostgresOutboxStore` | method | `appendFact` | `append_fact` |
| `Symfony\Persistence\DbalPostgresOutboxStore` | method | `eventClassOf` | `event_class_of` * |
| `Symfony\Persistence\DbalPostgresOutboxStore` | method | `recordFromRow` | `record_from_row` |
| `Symfony\Persistence\DbalPostgresOutboxStore` | method | `withFactClass` | `with_event_class` * |
| `Symfony\Persistence\DbalRelayPauseStore` | method | `activePatterns` | `patterns` |
| `Symfony\Persistence\DbalWakeupScheduler` | method | `intentOf` | `intent_from_row` |
| `Symfony\Persistence\DbalWorkflowIgnitionLedger` | method | `keyForFact` | `fact_key` |
| `Symfony\Persistence\GlobPattern` | method | `toRegex` | `to_regex` |
| `Symfony\Persistence\PersistenceConflict` | method | `fromUniqueViolationIn` | `find_in` * |
| `Symfony\Persistence\Time` | method | `fromDb` | `from_db` |
| `Symfony\Persistence\Time` | method | `fromDbOrNull` | `from_db_or_null` |
| `Symfony\Persistence\Time` | method | `toDb` | `to_db` |
| `Symfony\Runtime\Actor\ActorContext` | method | `runAs` | `run_as` |
| `Symfony\Runtime\Actor\ConsoleOperatorActorProvider` | method | `onCommand` | `on_command` |
| `Symfony\Runtime\Actor\ConsoleOperatorActorProvider` | method | `onTerminate` | `on_terminate` |
| `Symfony\Runtime\CompiledSubscriptionRegistry` | method | `knownFactClasses` | `fact_classes` |
| `Symfony\Runtime\CompiledSubscriptionRegistry` | method | `listenerSpec` | `listener_spec` |
| `Symfony\Runtime\CompiledSubscriptionRegistry` | method | `processSpec` | `process_spec` |
| `Symfony\Runtime\CompiledSubscriptionRegistry` | method | `workflowSpec` | `workflow_spec` |
| `Symfony\Runtime\DddRuntimeReset` | method | `onMessageFailed` | `on_failed` |
| `Symfony\Runtime\DddRuntimeReset` | method | `onMessageHandled` | `on_handled` |
| `Symfony\Runtime\Factory` | method | `auditEnvironment` | `environment` |
| `Symfony\Runtime\Factory` | method | `domainDispatcher` | `dispatcher` |
| `Symfony\Runtime\Factory` | method | `integrationBus` | `integration_bus` |
| `Symfony\Runtime\Factory` | method | `outboxConfig` | `outbox_config` |
| `Symfony\Runtime\Factory` | method | `processLock` | `process_lock` |
| `Symfony\Runtime\Factory` | method | `processRunner` | `process_runner` |
| `Symfony\Runtime\Factory` | method | `startMode` | `start_mode` |
| `Symfony\Runtime\Relay` | method | `backoffSeconds` | `backoff_seconds` |
| `Symfony\Runtime\Relay` | method | `betweenSubmitAndAccept` | `between_submit_and_accept` |
| `Symfony\Runtime\Relay` | method | `runOnce` | `run_once` |
| `Symfony\Runtime\RelayReport` | prop | `deadLettered` | `dead_lettered` |
| `Symfony\Runtime\SymfonyConsumerConfig` | method | `normaliseVersion` | `normalise_version` |
| `Symfony\Runtime\Wakeup\IWakeupRelayStep` | method | `runOnce` | `run_once` |
| `Symfony\Runtime\Wakeup\PostgresListenWaiter` | method | `lastPayloads` | `payloads` |
| `Symfony\Runtime\Wakeup\WakeupRelayReport` | method | `didWork` | `did_work` * |
| `Symfony\Runtime\Wakeup\WakeupRelayReport` | prop | `strandedReported` | `reported` |
| `Symfony\Runtime\Wakeup\WakeupRelayReport` | prop | `strandedRequeued` | `requeued` |

### Conformance (ddd-conformance) (134)

| Class | Kind | From | To |
|---|---|---|---|
| `Conformance\AuditEntry` | prop | `commandId` | `command_id` |
| `Conformance\AuditEntry` | prop | `commandName` | `command_name` |
| `Conformance\AuditEntry` | prop | `errorType` | `error_type` |
| `Conformance\AuditSinkFaults` | method | `failNextAuditClose` | `fail_next_audit_close` |
| `Conformance\BusOptions` | prop | `withBoundary` | `boundary` |
| `Conformance\ConformanceTestCase` | method | `catchThrowable` | `thrown` |
| `Conformance\ConformanceTestCase` | method | `createFixture` | `create_fixture` |
| `Conformance\ConformanceTestCase` | method | `publishFact` | `publish` |
| `Conformance\ConformanceTestCase` | method | `skipForChangeRequest` | `skip_for` |
| `Conformance\EffectHost` | method | `effectBus` | `effect_bus` |
| `Conformance\EffectHost` | method | `effectJournal` | `effect_journal` |
| `Conformance\Fixtures\Effects\ChargeWidget` | method | `keyFor` | `key_for` |
| `Conformance\Fixtures\Effects\EffectLedger` | method | `failRecord` | `fail_record` |
| `Conformance\Fixtures\Effects\EffectLedger` | prop | `failureSends` | `failure_sends` |
| `Conformance\Fixtures\Effects\EffectLedger` | prop | `recordFailures` | `record_failures` |
| `Conformance\Fixtures\Process\KeyedJobProcess` | method | `doneRow` | `done_row` |
| `Conformance\Fixtures\Process\ProcessJournal` | method | `commandIds` | `command_ids` |
| `Conformance\Fixtures\Process\ProcessJournal` | method | `hasRow` | `has_row` |
| `Conformance\Fixtures\Process\ProcessJournal` | method | `markRow` | `mark_row` |
| `Conformance\Fixtures\Process\ProcessJournal` | method | `rowId` | `row_id` |
| `Conformance\Fixtures\Process\ProcessJournal` | prop | `onSend` | `on_send` |
| `Conformance\Fixtures\Workflow\CronExportWorkflow` | method | `refType` | `ref_type` |
| `Conformance\Fixtures\Workflow\CronExportWorkflow` | prop | `failStarts` | `fail_starts` |
| `Conformance\FreshProcesses` | method | `deliverInFreshProcess` | `deliver_fresh` |
| `Conformance\FreshProcesses` | method | `drainInFreshProcess` | `drain_fresh` |
| `Conformance\FreshProcesses` | method | `publishInFreshProcess` | `publish_fresh` |
| `Conformance\FreshProcesses` | method | `startInFreshProcess` | `start_fresh` |
| `Conformance\FreshRun` | prop | `processId` | `process_id` |
| `Conformance\HostFixture` | method | `acceptNextSubmissionWithoutRef` | `accept_next_without_ref` |
| `Conformance\HostFixture` | method | `advanceClock` | `advance_clock` |
| `Conformance\HostFixture` | method | `auditTrail` | `audit_trail` |
| `Conformance\HostFixture` | method | `commandBus` | `command_bus` |
| `Conformance\HostFixture` | method | `crashNextRelayAfterSubmit` | `crash_next_relay` |
| `Conformance\HostFixture` | method | `deliverTransported` | `deliver_transported` |
| `Conformance\HostFixture` | method | `failNextCommit` | `fail_next_commit` |
| `Conformance\HostFixture` | method | `hostName` | `name` |
| `Conformance\HostFixture` | method | `outboxAdministration` | `outbox_admin` |
| `Conformance\HostFixture` | method | `processLock` | `lock` |
| `Conformance\HostFixture` | method | `rejectNextSubmission` | `reject_next_submission` |
| `Conformance\HostFixture` | method | `relayOnce` | `relay_once` |
| `Conformance\HostFixture` | method | `relayPauses` | `pauses` |
| `Conformance\HostFixture` | method | `runWorker` | `run_worker` |
| `Conformance\HostFixture` | method | `runnerTransients` | `runner_transients` |
| `Conformance\HostFixture` | method | `scenarioRows` | `rows` |
| `Conformance\HostFixture` | method | `seedLegacyDelayedFact` | `seed_legacy_fact` |
| `Conformance\HostFixture` | method | `setUp` | `set_up` |
| `Conformance\HostFixture` | method | `tearDown` | `tear_down` |
| `Conformance\Mem\MemHostFixture` | method | `auditSink` | `audit_sink` |
| `Conformance\Mem\MemHostFixture` | method | `buildWorker` | `build_worker` |
| `Conformance\Mem\MemHostFixture` | method | `competitorParticipants` | `competitor_participants` |
| `Conformance\Mem\MemHostFixture` | method | `deliverWith` | `deliver_with` |
| `Conformance\Mem\MemHostFixture` | method | `factObserver` | `fact_observer` |
| `Conformance\Mem\MemHostFixture` | method | `relayProcessor` | `relay_processor` |
| `Conformance\Mem\MemHostFixture` | prop | `auditPort` | `audit_port` |
| `Conformance\Mem\MemHostFixture` | prop | `effectJournal` | `effect_journal` |
| `Conformance\Mem\MemHostFixture` | prop | `outboxConfig` | `outbox_config` |
| `Conformance\Mem\MemHostFixture` | prop | `outboxConnection` | `outbox_connection` |
| `Conformance\Mem\MemHostFixture` | prop | `processStore` | `process_store` |
| `Conformance\Mem\MemHostFixture` | prop | `rawLock` | `raw_lock` |
| `Conformance\Mem\MemHostFixture` | prop | `relayStore` | `relay_store` |
| `Conformance\Mem\MemHostFixture` | prop | `startMode` | `start_mode` |
| `Conformance\Mem\MemHostFixture` | prop | `transportSharesConnection` | `shared_connection` |
| `Conformance\Mem\MemHostFixture` | prop | `wakeFaults` | `wake_faults` |
| `Conformance\Mem\MemHostFixture` | prop | `workflowRows` | `workflows` |
| `Conformance\PostCommitWakeups` | method | `relayPollIntervalSeconds` | `poll_seconds` |
| `Conformance\PostCommitWakeups` | method | `relayUntilTransported` | `relay_until` |
| `Conformance\PostCommitWakeups` | method | `startRelayWorker` | `start_relay` |
| `Conformance\PostCommitWakeups` | method | `stopRelayWorker` | `stop_relay` |
| `Conformance\PostCommitWakeups` | method | `suppressNextWakeup` | `drop_next_wakeup` |
| `Conformance\PostCommitWakeups` | method | `wakeupArrives` | `await_wakeup` |
| `Conformance\ProcessDecodeFaults` | method | `forgetProcessClass` | `forget_class` |
| `Conformance\ProcessDecodeFaults` | method | `quarantineReason` | `quarantine_reason` |
| `Conformance\ProcessDecodeFaults` | method | `storedProcessStatus` | `stored_status` |
| `Conformance\ProcessHost` | method | `beforeNextProcessLockAcquire` | `before_next_lock` |
| `Conformance\ProcessHost` | method | `failNextProcessLockAcquire` | `fail_next_lock` |
| `Conformance\ProcessHost` | method | `failNextWakeHandoff` | `fail_next_handoff` |
| `Conformance\ProcessHost` | method | `holdProcessLockElsewhere` | `hold_lock_elsewhere` |
| `Conformance\ProcessHost` | method | `operatorView` | `operator_view` |
| `Conformance\ProcessHost` | method | `pendingWakeups` | `live_intents` |
| `Conformance\ProcessHost` | method | `processConsumer` | `consumer_prefix` |
| `Conformance\ProcessHost` | method | `processIds` | `process_ids` |
| `Conformance\ProcessHost` | method | `processLockAcquisitions` | `lock_acquisitions` |
| `Conformance\ProcessHost` | method | `processLockKey` | `lock_key` |
| `Conformance\ProcessHost` | method | `processRow` | `process_row` |
| `Conformance\ProcessHost` | method | `processStore` | `process_store` |
| `Conformance\ProcessHost` | method | `releaseProcessLockElsewhere` | `release_lock_elsewhere` |
| `Conformance\ProcessHost` | method | `wireProcesses` | `wire_processes` |
| `Conformance\ProcessRow` | prop | `ignitedByEventId` | `ignited_by` |
| `Conformance\ProcessRow` | prop | `ignitionKey` | `ignition_key` |
| `Conformance\ProcessRow` | prop | `processClass` | `process_class` |
| `Conformance\ProcessRow` | prop | `stepIndex` | `step_index` |
| `Conformance\ProcessScenarioCase` | method | `intentKeys` | `intent_keys` |
| `Conformance\ProcessWorker` | method | `deliverFact` | `deliver` |
| `Conformance\ProcessWorker` | method | `drainOnce` | `drain_once` |
| `Conformance\ProcessWorker` | method | `processLock` | `lock` |
| `Conformance\ProcessWorker` | method | `processRunner` | `runner` |
| `Conformance\RelayRace` | method | `raceNextRelayAfterSubmit` | `race_next_relay` |
| `Conformance\RelayReport` | prop | `deadLettered` | `dead_lettered` |
| `Conformance\RelayReport` | prop | `leaseLost` | `lease_lost` |
| `Conformance\ScenarioCatalogue` | method | `casesFor` | `cases_for` |
| `Conformance\ScenarioCatalogue` | method | `dueBy` | `due_by` |
| `Conformance\ScenarioCatalogue` | method | `firstDueAt` | `first_due_at` |
| `Conformance\ScenarioCatalogue` | method | `isKnown` | `is_known` |
| `Conformance\ScenarioCatalogue` | method | `scenarioCase` | `case_of` |
| `Conformance\ScenarioContext` | method | `uniqueName` | `unique_name` |
| `Conformance\ScenarioContext` | prop | `scenarioId` | `scenario_id` |
| `Conformance\ScenarioContext` | prop | `testClass` | `test_class` |
| `Conformance\ScenarioContext` | prop | `testMethod` | `test_method` |
| `Conformance\ScenarioId` | method | `implementedBy` | `implemented_by` |
| `Conformance\ScenarioId` | method | `methodName` | `method_name` |
| `Conformance\Scenarios\AwaitScenarios` | method | `deliverFact` | `deliver` |
| `Conformance\Scenarios\CodecScenarios` | method | `outboxPayloadCap` | `payload_cap` |
| `Conformance\Scenarios\CommandScenarios` | method | `createWidget` | `create_widget` |
| `Conformance\Scenarios\CommandScenarios` | method | `outboxRowCount` | `outbox_count` |
| `Conformance\Scenarios\CommandScenarios` | method | `reactWithFact` | `react` |
| `Conformance\Scenarios\DecodeScenarios` | method | `decodeFaults` | `decode_faults` |
| `Conformance\Scenarios\DeliveryScenarios` | method | `assertDueWithin` | `assert_due_within` |
| `Conformance\Scenarios\RelayScenarios` | method | `transportedIds` | `transported_ids` |
| `Conformance\Scenarios\RelayScenarios` | method | `whileLeased` | `while_leased` |
| `Conformance\StatementErrors` | method | `runFailingStatement` | `fail_statement` |
| `Conformance\Support\ConnectionView` | method | `asAnotherConnection` | `run_elsewhere` |
| `Conformance\Support\FaultInjectingAuditSink` | method | `failNextClose` | `fail_next_close` |
| `Conformance\Support\FreshProcessBoot` | method | `effectRowId` | `effect_row` |
| `Conformance\Support\FreshProcessBoot` | method | `factClasses` | `fact_classes` |
| `Conformance\Support\InterleavingProcessLock` | method | `beforeNextAcquire` | `before_next_acquire` |
| `Conformance\Support\WakeHandoffFaults` | method | `failNext` | `fail_next` |
| `Conformance\Support\WakeHandoffFaults` | method | `throwIfArmed` | `throw_if_armed` |
| `Conformance\TransportedFact` | prop | `dueAt` | `due_at` |
| `Conformance\TransportedFact` | prop | `eventId` | `event_id` |
| `Conformance\WebRequests` | method | `bootInBandStartOnPooledDsn` | `boot_inband_pooled` * |
| `Conformance\WebRequests` | method | `inWebRequest` | `in_web_request` |
| `Conformance\WorkflowHost` | method | `workflowIgniter` | `igniter` |
| `Conformance\WorkflowHost` | method | `workflowIgnitionLedger` | `ignition_ledger` |
| `Conformance\WorkflowHost` | method | `workflowRepository` | `workflows` |

`*` = the reviewer changed the area proposal (see "Clashes and inconsistencies fixed").
