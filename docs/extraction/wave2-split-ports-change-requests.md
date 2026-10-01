# Wave 2 round 2: split-ports change requests

Author: split-ports (branch `wave2/split-ports`, based on `a35b29b`). Every entry is additive: a new class or interface, an optional trailing parameter, a widened parameter type on a method that is not autowired, or a nullable parameter that used to be required. No ratified interface gained a method and no signature was narrowed, except CR-3's ratified narrowing of `SubscriptionRegistrar`. The coordinator ratifies after the round.

## CR-SP-1: runtime loggers accept `LoggerInterface|\Closure|null` for one round

- **What.** `ReentrantProcessLock`, `IntegrationDelivery`, `InMemoryTransactionBoundary` and `LoggingSignalDispatcher` take `LoggerInterface|\Closure|null $log` (same parameter name and position). `Runtime\Support\Log::write()` resolves the given logger, then `HostDefaults::get(LoggerInterface::class)`, then `error_log()` for notice and above (debug and info are dropped when there is no logger at all).
- **Why.** wave1-notes rules the switch to `?Psr\Log\LoggerInterface`. Conformance (`MemHostFixture`) and ddd-symfony (`RuntimeLog::argument()`) still pass the wave-1 closure, and neither is mine to edit. A pure `?LoggerInterface` would break both on merge.
- **Follow-up.** Conformance switches `MemHostFixture` to a PSR-3 logger; then the coordinator can drop `\Closure` from the four signatures. ddd-symfony's `RuntimeLog` already prefers `LoggerInterface` when a signature accepts it.
- **Compatibility.** Widening only.

## CR-SP-2: `Runtime\IHostPortFactory`, `HostDefaults::for()`, `Runtime\ConsumerPrefix`

- **What.** New interface `IHostPortFactory::create(string $port, IConsumerIdentity $consumer, ?object $legacy = null): ?object` and new static `HostDefaults::for($port, $consumer, $legacy)`: the factory's answer, else `HostDefaults::get($port)`. New value `ConsumerPrefix implements IConsumerIdentity` (prefix only, version `unknown`, validated `[a-z0-9_]+`).
- **Why.** HostDefaults holds one implementation per port, but several ports are per consumer, because their tables are: the audit sink (`{prefix}_command_audit`), the touches observer, the process store (it adapts the IProcessRepository the 0.6 constructor was given, which may be cred's own), the wakeup scheduler (the consumer's hook names), the outbox store and administration. R2 requires the 0.6 constructors to resolve a `null` port from HostDefaults; without a per-consumer form that cannot be done. The repair commands carry only a prefix, possibly of an unregistered ("ghost") consumer, hence `ConsumerPrefix`.
- **Compatibility.** New types; one new static method on a final class.

## CR-SP-3: `Runtime\Outbox\IOutboxRowIds`

- **What.** `eventIdOf(int $outboxId): ?string`, implemented by `WpdbOutboxAdministration`.
- **Why.** Register 3.4 keeps `RetryDeliveryCommand`'s integer `outbox_id` and says the handler maps it to the event id before `retry()`. `IOutboxAdministration` has no such lookup and may not gain methods (R3 spirit; it is ratified), so the lookup is a separate port that only integer-keyed stores implement. `RetryDeliveryHandler` throws `\LogicException` for an administration without it.

## CR-SP-4: `Runtime\Outbox\IOutboxOptionsReader`

- **What.** `read(IDDDConfig): OutboxConfig`. `OutboxConfig::from_options()` delegates to the one in HostDefaults and throws `IncorrectUsageException` without it (X11); `OutboxConfig::from_array()` is new. ddd-wp provides `WpOptionsOutboxConfigReader` (the 0.6 body).
- **Why.** X11 names "an options reader that ddd-wp registers at init" without a type.

## CR-SP-5: `Runtime\Ids\DeterministicCommandId`

- **What.** `forFact(eventId, subscriberId): ?string` (32-hex spelling of `uuid5(event_id, subscriber_id)`, null for a non-UUID event id), `within(?string $id, callable)`, `take()`, `peek()`. `SubscriptionRegistrar` sends a listener's command inside `within()`; `CorrelationMiddleware` uses `take()` when it mints the command id.
- **Why.** Core minor 2 (register 3.8). The command id is minted by the act bracket, not by the listener, so the deterministic id needs a hand-off. The 32-hex spelling matches the ids the bracket already mints and `command_audit.command_id CHAR(32)` on wp; it is the same value as the hyphenated uuid5.

## CR-SP-6: `Application\Infrastructure\AuditSinkFailed` and `Application\Process\ProcessLockUnavailable`

- **What.** `AuditSinkFailed` (action `audit_sink_failed`, subject the command id, phase `open`/`close`) is the signal for `audit.sink-fails`. `ProcessLockUnavailable extends Infra\Exceptions\LockingException` is what `ProcessRunner` throws when the port throws `LockNotAcquired`; the port exception is its `previous`.
- **Why.** Register 3.9 says a sink failure is "logged and emitted as a signal" without naming one. The runner must keep throwing the 0.6 `LockingException` type that callers and the wave-1 tests catch, while the port contract (`LockNotAcquired extends \RuntimeException`) cannot change.

## CR-SP-7: constructor shapes of the moved 0.6 classes (R2)

All keep their 0.6.5 call valid. Additions are optional trailing parameters.

| Class | 0.6.5 call | Added (all optional) | Other change |
|---|---|---|---|
| `ProcessRunner` | `(IDDDConfig, IProcessRepository)` | `?IProcessLock, ?IProcessStore, ?IWakeupScheduler, ?ISubscriptionRegistry, ?ITransactionBoundary, ?IClock` (register order) | repository nullable with default null |
| `CorrelationMiddleware` | `(IDDDConfig, EventsUnitOfWork, Redactor)` | `?IAuditSink, ?IActorProvider, ?IAuditPolicy, ?IEnvironmentProvider` (register order) | none |
| `OutboxIntegrationEventBus` | `(IOutboxRepository, IDDDConfig)` | `?IFactObserver, ?IClock, ?IOutboxStore, ?OutboxConfig` | repository and config nullable (port form: `null, $config, …, $store`) |
| `OutboxProcessor` | `(IDDDConfig, IOutboxRepository, OutboxConfig, IOutboxPublisher)` | `?ISubscriberProbe, ?LoggerInterface, ?IClock` (register order), then `?IOutboxStore, ?ITransport, ?ITransactionBoundary` | repository and publisher nullable (port form); new `between_submit_and_accept()` test seam (CONF-3), static `backoff_seconds()` |
| 4 repair handlers | `()` | `?IOutboxAdministration` (`PurgeOutboxHandler` also `?IClock`) | none |
| `InfrastructureEvent::dispatch()` | `(IDDDConfig)` | none | parameter widened to `IConsumerIdentity` (a method, not autowired) |

- **Why the config parameters were NOT widened to `IConsumerIdentity`.** Consumers' runtime-compiled containers (cred, the integration suite's datastream, every scaffolded `services.yaml` with `~`) AUTOWIRE these classes and alias only `IDDDConfig`. The first attempt widened `ProcessRunner`'s config and the wp-integration harness fatalled: "Cannot autowire service ProcessRunner: argument $config references interface IConsumerIdentity but no such service exists". Union types are not autowirable either, so no autowired position uses one. Consequence for ddd-symfony (identity-only `txp` consumer): it needs an `IDDDConfig` to construct these four classes. See "Needs another owner".
- **Also additive on wave-1 types:** `InMemoryTransport(sharesConnection, ?InMemoryTransactionBoundary)` (CONF-5), `InMemoryProcessStore(clock, threshold, ?InMemoryWakeupScheduler)` + `attachIntents()` (minor 4), `InMemoryOutboxStore::recordOf()`/`eventIds()`, `ReentrantProcessLock::inner()`, `OutboxEntry::from_claim()`.

## CR-SP-8: the transitional wp adapters live in `packages/ddd-wp/wordpress/Adapter/`

- **What.** The task text says `packages/ddd-wp/src/Adapter`, but `src/` is PSR-4 `TangibleDDD\` (that path would be `TangibleDDD\Adapter\`). The register namespace `TangibleDDD\WordPress\Adapter\` maps to `packages/ddd-wp/wordpress/`, where round 1 already put `UncheckedWpdbTransactionBoundary`, so the adapters went there.

## Behaviour notes reviewers should know (not interface changes)

- **ProcessRunner ids.** `register_event()` / `register_start()` subscribe as `{prefix}/resume:{event}` and `{prefix}/ignition:{process}@{event}`; `SubscriptionRegistrar` keeps `resume:{event}` / `ignition:{process}@{event}`. The prefix keeps two consumers' runners from deduplicating each other in the one process-wide wp registry. The runner accepts a registrar `resume:{event}` subscriber as "registered" for its await check. Use one path per runner.
- **wp transaction boundary per process save.** On wp every process save (and its Action Scheduler wakeup) now runs in a checked `START TRANSACTION`/`COMMIT`, joining an open DDD transaction instead of nesting (the per-request depth is shared with the 0.6 `TransactionMiddleware`). Ignition (`insertIgnited`) deliberately runs outside a transaction so its named lock is released only after the insert is visible.
- **Signals never throw.** `InfrastructureEvent::dispatch()` catches and logs a throwing listener; under 0.6 a throwing `{prefix}_outbox_dlq` listener escaped into the relay.
- **Repair commands.** Replay keeps the event id (C22; 0.6 minted a new one). Retry refuses leased rows always and non-`pending`/`dlq` rows unless forced (O5); 0.6 reset any row by integer id.
- **Self-consumer.** `tangible_ddd` is now in `ConsumerRegistry` (root `TangibleDDD\Application\Commands`) from `plugins_loaded:21`, so `consumers()` and the `tangible_ddd_consumers` filter list it; the dashboard catalog skips it in its "registered consumers" pass so the legacy-discovery fallback is unchanged.
- **WP adapter gaps closed in wave 3:** no outbox claim token (unfenced accept/retry/DLQ; fixed 300 s lease), no process version column (unfenced save/touch), no intent table (claimDue() returns []; no idempotency dedup), `cancel_duplicates` matches by event type (0.6) rather than payload signature.

## Needs another owner

- **packaging:** root `composer.json` requires `psr/log: ^1|^2|^3` (your branch already does, CR-PK-3). This branch was tested with psr/log 3.0.2 installed into `vendor/` by hand (the committed lock does not have it); the core suite's PSR-3 tests need it.
- **conformance:** delete the stand-ins and run the mem host on the real classes (CONF-1..3): `new CorrelationMiddleware($config, $uow, new Redactor(), $sink, $actor, $policy, $env)` (needs an `IDDDConfig`), `new OutboxIntegrationEventBus(null, $config, $observer, $clock, $store, $outboxConfig)`, `new OutboxProcessor($config, null, $outboxConfig, null, null, $logger, $clock, $store, $transport, $boundary)` with `between_submit_and_accept()` replacing `crashNextAfterSubmit()`; un-skip `relay.crash-after-submit` (shared) with `new InMemoryTransport(true, $boundary)` (CONF-5); remove the `packages/ddd-wp/src/` fallback in `tests/bootstrap.php` (OutboxConfig is in core now); switch `MemHostFixture`'s closure logger to PSR-3 (CR-SP-1). The mem suite still passes unchanged on this branch (26 tests, the 1 CONF-5 skip).
- **symfony / coordinator:** the four autowired constructors keep `IDDDConfig` (CR-SP-7). Either ddd-symfony provides an `IDDDConfig` for `txp` (a host-neutral one is about 30 lines; core tests use one), or the register rules a core identity-backed `IDDDConfig` value. I did not add one, to avoid freezing names (`table()`, `hook()`, `as_group()`) that only WordPress uses.
