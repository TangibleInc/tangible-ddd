# tangible/ddd-conformance

Dev-only. The shared scenarios of [contract-register.md section 4](../../docs/extraction/contract-register.md), written once and run on every host (mem, pdo, wp, sf).

```sh
cd packages/ddd-conformance
composer install
vendor/bin/phpunit --group mem                       # one host
vendor/bin/phpunit --group relay.lease-fencing       # one scenario id, every host present
vendor/bin/phpunit --list-groups                     # host groups + scenario ids
```

## Layout

| Path | What |
|---|---|
| `src/HostFixture.php` | What a host provides: its ports (one connection) plus pipeline operations and fault seams |
| `src/Scenarios/*Scenarios.php` | Abstract scenario cases. Each scenario method has `#[Group('<scenario id>')]` and is named `test_<id with . and - as _>` |
| `src/ScenarioCatalogue.php` | The 44 ids with the wave each must pass per host (a copy of the register table, pinned by `tests/CatalogueTest.php`) |
| `src/Mem/` | The mem host: ddd-core in-memory doubles under the real ddd-core pipeline (see below) |
| `tests/Mem/*Test.php` | Concrete mem classes, `#[Group('mem')]` |

## Adding a host

1. Implement `HostFixture`. `setUp(ScenarioContext)` must create a fresh schema for the one test (`$context->uniqueName('pdo')` gives a safe database or schema name), apply the host schema and bind every port to that one connection. Do not wrap the test in a transaction. `tearDown()` drops the schema and clears `RuntimeReset`, `HostDefaults` and `Correlation`.
2. For each scenario case, add a concrete class with the host group:

```php
#[Group('sf')]
final class SfRelayScenariosTest extends RelayScenarios {
  protected function createFixture(): HostFixture { return new SfHostFixture(/* DSN */); }
}
```

3. Add the host's class directory to `CatalogueTest` so the ids due on that host are enforced.
4. If the host cannot express a scenario yet, override that one method (same `#[Group]`) and call `skipForChangeRequest('<request id>', '<why>')`.

Host hooks: `RelayScenarios::whileLeased(Claim)` runs while a lease is live. wp overrides it to check that a 0.6 `fetch_pending()` skips the claimed row.

## The mem pipeline

Since wave 2 round 3 the mem host runs on the real ddd-core classes; the wave-1 stand-ins (CONF-1..3) are deleted and `tests/bootstrap.php` loads only Composer autoload. `tests/Mem/MemHostCompositionTest.php` pins this.

| Piece | Core class (port form) |
|---|---|
| act bracket + audit | `CorrelationMiddleware(config, uow, Redactor, IAuditSink, IActorProvider, IAuditPolicy, IEnvironmentProvider)` |
| transaction | `TransactionalCommandMiddleware(InMemoryTransactionBoundary)` |
| facts to the outbox | `OutboxIntegrationEventBus(null, config, IFactObserver, IClock, IOutboxStore, OutboxConfig)` |
| relay step | `OutboxProcessor(config, null, OutboxConfig, null, null, logger, clock, store, transport, boundary)::process_batch()`; the crash seam is `between_submit_and_accept()` |
| delivery, worker reset | `IntegrationDelivery`, `RuntimeReset` |

Helpers other hosts may reuse (`src/Support/`): `ConformanceConfig` (a host-neutral `IDDDConfig` for the constructors that still type one, CR-SP-7), `RecordingLogger` (PSR-3), `RecordingOutboxStore` (turns a real relay step into a `RelayReport` by event id).
