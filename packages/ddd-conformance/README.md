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
| `src/Mem/` | The mem host: ddd-core in-memory doubles, plus wave-1 stand-ins (see below) |
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

## Wave-1 stand-ins on mem

Three pipeline pieces reach WordPress in 0.6 and only get their core form with the wave-2 move. Until then the mem host uses conformance-owned versions built only on the ports. Replace each one with the core class when it lands:

| Stand-in | Core form it replaces | Request |
|---|---|---|
| `Mem\ActBracketMiddleware` | `CorrelationMiddleware` with `IAuditSink`, `IAuditPolicy`, `IActorProvider`, `IEnvironmentProvider` | CONF-1 |
| `Mem\PortOutboxBus` | `OutboxIntegrationEventBus` over `IOutboxStore` + `IClock` | CONF-2 |
| `Mem\PortRelay` | `OutboxProcessor::process_batch()` over `IOutboxStore` + `ITransport` | CONF-3 |

Wave-1 bridge: `tests/bootstrap.php` maps `TangibleDDD\` to the monorepo `ddd-src/`, after ddd-core's own map, for the 0.6 classes the ports build on. Remove it at the wave-2 move.
