# Wave 2 round 1: symfony-adapters change requests

Author: symfony-adapters (wave 2, round 1). Branch `wave2/symfony-adapters`. Every item is additive or a request to another owner; no ratified interface was changed. Binding inputs: [contract-register.md](contract-register.md), [coordinator-rulings-wave0.md](coordinator-rulings-wave0.md), [wave1-notes.md](wave1-notes.md), report [E](wave0/E-symfony-host.md).

## CR sf-1: the fact class travels with the outbox record (core, additive)

**Need.** Delivery calls `IntegrationDelivery::deliver(string $eventClass, array $wrapped)`: it hydrates the fact with `$eventClass::from_payload()` and matches subscribers by `is_a`, so marker-interface subscriptions work (D2). On WordPress the hook name selects the callbacks, so 0.6 never stored the class. `OutboxRecord` (register 3.4) carries `event_type` (the short name) and `integration_action`, but not the PHP class, and E section 7's `IntegrationFactMessage{consumer, event_id, event_type, integration_action, wrapped_payload}` has no class either. From `integration_action` alone the relay cannot find the class of a fact that only a marker interface subscribes to.

**Request (core).** Add an optional trailing constructor parameter and property `?string $event_class = null` to `TangibleDDD\Runtime\Outbox\OutboxRecord`. The core `OutboxIntegrationEventBus` (CONF-2) fills it with `get_class($event)`. Existing callers are unaffected (R2 style: an optional trailing parameter).

**Round-1 implementation (sf only, no core edit).**
- The sf outbox table has a nullable `event_class` column. `DbalPostgresOutboxStore::appendFact(OutboxRecord, ?string $class)` is a public sf-only method; `append()` reads `$r->event_class` when that property exists, so CR sf-1 works as soon as core adds it.
- `PortOutboxEventBus` (the transitional bus) calls `appendFact()`.
- `IFactClassResolver` / `OutboxFactClassResolver`: the relay's transport reads the class from the row, falling back to an `integration_action` map over the compile-time known fact classes (every concrete class a listener or process names, plus `tangible_ddd.facts`). If no class resolves, the transport rejects the submission, so the row retries and ends in the DLQ instead of disappearing.

## CR sf-2: `IntegrationFactMessage` carries `eventClass` (sf-local)

The E section 7 message gains `eventClass`, for the reason in CR sf-1. The message is owned by ddd-symfony and has no other consumer. `wrappedPayload` stays exactly `IntegrationEnvelope::wrap()` output (R4 envelope keys).

## CR sf-3: a lost lease on accept rolls back a shared-connection submission (core relay behaviour)

**Finding.** Suppose the transport shares the store's connection, so submit and accept run in one transaction. If `accept()` then matches 0 rows (the lease was lost to another relay), the conformance stand-in `PortRelay` and my first round-1 relay returned `false` inside the transaction, and the transaction committed. The Messenger insert therefore committed, and the new holder of the lease submitted the same fact again: two messages for one fact. The ledger keeps subscriber effects single, but this is the duplicate window the shared connection is supposed to close (E section 7, `relay.crash-after-submit` / `relay.lease-fencing`).

**Fix in sf.** `Relay` throws an internal `LeaseLostOnAccept` inside the shared transaction, so the submission rolls back, and reports the row as `lost`. `tests/Integration/Runtime/RelayPostgresTest::test_a_lost_lease_on_accept_rolls_back_the_messenger_insert` demonstrates it on Postgres: a competing re-claim lands between submit and accept, and no message remains.

**Request (core, CONF-3).** The core relay step `OutboxProcessor::process_batch` must do the same. Suggested conformance addition (conformance owner): in `relay.lease-fencing` on a shared-connection transport, assert that the late holder's submission is not in `transported()`.

## CR sf-4: packaging manifest pin (packaging)

`tests/Unit/Loader/PackageManifestTest::test_ddd_symfony_requires_core_and_the_symfony_host_closure` (packaging-owned) pins `packages/ddd-symfony/composer.json` `require` to five entries. The task for this round adds `symfony/doctrine-messenger ^7.4` (the `ddd_facts` Doctrine transport is required for the one-transaction hand-off). **Request:** add `'symfony/doctrine-messenger' => '^7.4'` after `symfony/messenger` in that assertion. It is the one root-suite failure on this branch (698/699 otherwise green).

Other manifest choices, within the existing pins:
- `league/tactician ^2.0-rc1` stays in `require-dev` (O20 test). It is required transitively through ddd-core.
- `symfony/console` goes in `require-dev` and `suggest`. The bundle wires `ddd:relay`, `ddd:schema:dump` and the console-operator actor only when `symfony/console` is installed (every app with `bin/console` has it).
- `psr/log` comes through ddd-core.
- `require-dev` also carries `doctrine/doctrine-bundle` (the kernel test app) and `symfony/security-core` (session-user actor test).

## CR sf-5: `ConsumerRegistry::add()` still types `IDDDConfig` (core, already planned)

Register 3.1 widens `ConsumerRegistry::add()` to `IConsumerIdentity`; that ships with the core move. Until then `SymfonyConsumerConfig` implements `IDDDConfig` and `IConsumerIdentity` both. Its WordPress-vocabulary methods (`hook`, `as_group`, `option`, ...) are pure string derivations with no WordPress call. No change is requested beyond the planned widening.

## CR sf-6: stable service ids for the round-3 switch (sf-local)

The bundle wires three transitional, port-based stand-ins under ids that round 3 re-points at the core classes without changing any consumer wiring:

| Service id | Round 1 (sf stand-in) | Round 3 (core) |
|---|---|---|
| `tangible_ddd.middleware.act_bracket` | `Symfony\Runtime\Transitional\ActBracketMiddleware` | core `CorrelationMiddleware` with the optional audit ports (CONF-1) |
| `tangible_ddd.integration_bus` | `Symfony\Runtime\Transitional\PortOutboxEventBus` | core `OutboxIntegrationEventBus` over `IOutboxStore` + `IClock` (CONF-2), then CR sf-1 |
| `tangible_ddd.relay` | `Symfony\Runtime\Relay` | a wrapper over the core relay step (CONF-3); `ddd:relay` keeps calling `runOnce()` |

These stand-ins are not copies of core FQCNs (R1). The act bracket mints a random command id. The deterministic `uuid5(event_id, subscriber_id)` inside a fact cause belongs to the core bracket (register 3.8; wave-1 open minor 2).

## Additive sf API (no request, listed for review)

- Attributes `TangibleDDD\Symfony\DependencyInjection\Attribute\AsIntegrationListener(?string $event)` and `AsDomainEventListener(string $event, int $priority = 10, string $method = '__invoke')`. Tags are in `DddTags` (`tangible_ddd.command_handler`, `.query_handler`, `.integration_listener`, `.domain_listener`, plus the existing `ddd.long_process`).
- `CompiledSubscriptionRegistry` builds every `Subscriber` through the core `SubscriptionRegistrar`, so subscriber ids, priorities and D1 failure-command handling are the core's. The compile-time spec ids must equal the registrar's (`listener:<class>`, `ignition:<process>@<fact>`, `resume:<fact>`); a mismatch throws a `LogicException` at the first delivery. If core changes those id formats, `CompiledSubscriptionRegistry::listenerSpec()` / `processSpec()` must follow.
- `RuntimeLog::argument()` passes either a closure or a PSR-3 logger, according to the constructor parameter type. This keeps the wiring valid across the wave-2 logger switch (wave-1 notes) for `IntegrationDelivery`.
- Schema `schema/postgres/001-004`: outbox (with `event_class`, `signature_json`), DLQ, relay pauses, delivery ledger (`last_error`, `exhausted_at`, CR-1). No process tables.
- `MessengerFactTransport::sharesConnectionWith()` gets the Doctrine transport's DBAL connection by reflection (`DoctrineTransport::$connection` → `Connection::$driverConnection`). The kernel test asserts the positive case, so a Messenger upgrade that breaks it fails a test; the runtime fallback is the at-least-once hand-off.

## Fix round 1 (review findings)

### CR sf-7: an aborted Postgres transaction is a commit failure (behaviour; conformance request)

**Finding (review, major).** If `$work` catches a statement error, Postgres marks the transaction aborted (25P02). The following COMMIT answers with the `ROLLBACK` tag and no error, so DBAL's `commit()` does not throw, and the command reported success with its rows and outbox row gone. That violates register 3.2 / C13.

**Fix in sf.** `DbalTransactionBoundary::run()` runs `SELECT 1` just before COMMIT. In an aborted transaction that statement raises 25P02, so the boundary rolls back and throws `TransactionFailed` with the driver error as `previous`. This costs one round trip per command and is a no-op SELECT on engines that do not abort transactions. Tests: `tests/Integration/Persistence/DbalTransactionBoundaryTest::test_work_that_swallows_a_statement_error_is_a_transaction_failure_and_persists_nothing` and `test_savepoint_mode_rolls_back_to_its_savepoint_when_the_inner_work_swallowed_an_error`.

**Savepoint mode (review, minor).** The boundary records the caller's nesting level before BEGIN and never rolls back below it. After a failed RELEASE (DBAL 4 has already decremented the level) it rolls back to its own savepoint by name (`DOCTRINE_<outer+1>`) and does not call `rollBack()`. Test: `tests/Unit/Persistence/DbalTransactionBoundaryTest::test_a_failed_release_in_savepoint_mode_never_rolls_back_the_outer_transaction`.

**Request (conformance owner, round 3).** Add a "work swallows a statement error" case to the sf `cmd.commit-failure` scenario. The core `ITransactionBoundary` contract text should say that a boundary must not report success for a transaction the database has already aborted.

### CR sf-8: re-claiming an expired lease counts an attempt (behaviour; core relay / in-memory store request)

**Finding (review, minor).** A submission that kills the process (fatal error, OOM, SIGKILL) never writes an outcome. The row was re-claimed every lease period forever and stranded the rest of its batch each time.

**Fix in sf.** `DbalPostgresOutboxStore::claim()` still takes the fenced SKIP LOCKED claim. The locked id set now comes from a CTE (`WITH picked AS (SELECT id, claim_token IS NOT NULL AS reclaimed ... FOR UPDATE SKIP LOCKED) UPDATE ... FROM picked ... RETURNING o.*`) instead of `WHERE id IN (...)`. That lets the statement see which rows still held an expired lease: such a re-claim does `attempts = attempts + 1` and sets `last_error` to `DbalPostgresOutboxStore::LEASE_EXPIRED_ERROR`. A re-claimed row whose attempts reach `max_attempts` is moved to `ddd_dlq` at claim time and not handed out. `Claim::$attempts` therefore includes expired leases, so the relay's retry and DLQ arithmetic needs no change. Tests: `test_reclaiming_an_expired_lease_counts_an_attempt`, `test_a_row_whose_lease_expires_max_attempts_times_is_dead_lettered_at_claim`.

**Request (core, CONF-3 / conformance).** `InMemoryOutboxStore` and the core relay step should adopt the same rule, so that all hosts agree, and the scenario should go into `relay.crash-after-submit`. Until then an in-memory host counts only explicit failures.

### ddd:relay recovers from storage errors (sf-local)

`RelayCommand` catches a DBAL exception from a relay step, logs it at ERROR, backs off 1, 2, 4 ... 30 s and continues. After 10 consecutive failed steps it exits `FAILURE` and the supervisor takes over. With `--once` it exits `FAILURE` straight away. Non-DBAL throwables still propagate. The constructor gains optional trailing `?LoggerInterface $logger`, `?callable $sleeper` and `int $maxConsecutiveFailures = 10`. Tests: `tests/Unit/Console/RelayCommandTest`. The round-3 core relay step keeps this loop.

### Handle() locator from handle() signatures (sf-local, additive config)

`tangible_ddd.handle_dependencies` referenced every class-named service, so the container never removed any unused private service. `HandlerLocatorPass` now builds the locator the way Symfony's controller argument locator does: from the `handle()` parameter types of known self-handling classes, read by reflection at compile time, plus `EventsUnitOfWork`. "Known" means one of two things:

- The class carries the new tag `DddTags::SELF_HANDLING` (`tangible_ddd.self_handling`). The tag is autoconfigured on `SelfHandlingCommand` and `SelfHandlingQuery`, so it applies to classes that resource loading registers, such as TXP's `App\: resource: ../src/`.
- The class is listed in the new config `tangible_ddd.self_handling.classes`.

The new config `tangible_ddd.self_handling.locate_all: true` restores the round-1 behaviour. What changes for an app: a self-handling class that is neither resource-loaded nor listed can no longer inject services into `handle()`; it fails at dispatch with the locator's "service not found" error. The kernel test app now resource-loads its `Commands/` directory, as an app would. Tests: `tests/Unit/DependencyInjection/HandlerLocatorPassTest`, `tests/Kernel/BundleWiringTest::test_the_handle_locator_does_not_keep_unused_private_services_alive`.

### Still open for the coordinator

CR sf-4, the root `PackageManifestTest` pin for `symfony/doctrine-messenger`, is unchanged. The root suite stays at 698/699 on this branch until that pin is updated.
