# Wave 1 notes (coordinator)

Date: 2026-10-01. Binding for waves 2 onward, alongside [contract-register.md](contract-register.md) and [coordinator-rulings-wave0.md](coordinator-rulings-wave0.md). Where this file and the register differ, this file wins until the register is revised.

## Ratified contract changes

All six change requests in [wave1-core-change-requests.md](wave1-core-change-requests.md) are **accepted as written**:

- **CR-1** `IDeliveryLedger` gains `lastError()`, `markExhausted()`, `exhausted()`. Every ledger table (wp, pdo, sf) carries `last_error text NULL` and `exhausted_at` (UTC timestamp, NULL).
- **CR-2** `Subscriber::$onExhausted` and the four-list `DeliveryOutcome` (`delivered`, `skipped`, `failed`, `exhausted`). D1's `failureCommand()` is wired by the registrar through `onExhausted`; the invoker never sees commands.
- **CR-3** `IProcessEntry` port. `SubscriptionRegistrar` accepts `ProcessRunner|IProcessEntry|null` in wave 1; wave 2 makes `ProcessRunner implements IProcessEntry` and narrows the parameter to `?IProcessEntry`.
- **CR-4** `IProcessLock::forceReleaseAll(): int`, used by `RuntimeReset::betweenMessages()` after it records a leak.
- **CR-5** `IProcessStore::touch(int $id, int $expectedVersion): int` and `versionOf(int $id): ?int`; the runner's pre-dispatch fence uses `touch()`.
- **CR-6** `Runtime\Ids\NameBasedUuid::v5()` now; wave 2 adds `Domain\Shared\Uuid::v5()` delegating to it. Adapters call `Uuid::v5()` from wave 2 on.

Also accepted from the same file:

- Logging: wave 2 switches the runtime classes' `?\Closure(string): void` loggers to `?Psr\Log\LoggerInterface` and adds `psr/log` to the root and ddd-core manifests (packaging).
- D13: `FactRef` shipped in wave 1; `Correlation::current_fact(): ?FactRef` lands in wave 2 with the move.
- Id-less legacy payloads: the wp adapter routes envelopes without `__event_id` around `IntegrationDelivery`, calling DDD-registered callbacks directly in priority order, unledgered, and logs a notice once per hook per request. The invoker keeps throwing `InvalidArgumentException` for them.
- Placement of `Infra\IConsumerIdentity`, `Application\Persistence\TransactionalCommandMiddleware` and `Application\Correlation\FactRef` in `packages/ddd-core/src/` under their register namespaces is confirmed.

## Open minor findings carried from the core review (wave 2 core owns them)

1. A poison fact (`from_payload()` throws) or a ledger failure must still advance a per-subscriber attempt count, so the 5.1 budget bounds it; otherwise it retries forever. Record the attempt against every subscriber for that event, or against a synthetic `__decode__` subscriber, before rethrowing.
2. `SubscriptionRegistrar::registerListener()` must derive the listener's command id as `uuid5(event_id, subscriber_id)` (register 3.8), not a random id.
3. `registerListener(class-string)` must not `new` a 0.6 `IntegrationListener` on WordPress (its constructor calls `integration_listener()`). Resolve it from the container, or construct it through the core `IntegrationTranslator` path.
4. `findStranded()` in the mem store must also check "no live intent past threshold" as the port docblock says.

## Wave 1 status

- `wave1/wp` merged (three schema-free bug fixes, stubs and fakes no longer hide them). `hotfix/0.6.7` pushed, unmerged, unreleased.
- `wave1/packaging` merged (PHP 8.2 floor, symfony/yaml require, package manifests, CI on `extraction/**`, harness, loader baseline).
- `wave1/core` merged by the coordinator after ratifying CR-1..CR-6.
- `conformance` re-runs on top of the merged core.
