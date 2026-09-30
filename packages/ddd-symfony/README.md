# tangible/ddd-symfony

Symfony 7.4 host for `tangible/ddd-core` on Postgres 16 (register sections 1.2, 3.2-3.5, 5.1).
Install and configure: [examples/symfony/README.md](../../examples/symfony/README.md).

| Namespace | Contents |
|---|---|
| `Bundle` | `TangibleDddBundle` (configuration, service wiring, consumer registration at boot) |
| `DependencyInjection` | handler and `handle()` locators, compile-time subscription map, domain-listener map, Messenger health check, `#[AsIntegrationListener]`, `#[AsDomainEventListener]` |
| `Persistence` | `DbalTransactionBoundary`, `DbalPostgresOutboxStore`, `DbalRelayPauseStore`, `DbalDeliveryLedger`, `DbalOutboxAdministration`, `PostgresSchema` |
| `Messenger` | `IntegrationFactMessage`, `MessengerFactTransport` (ITransport), `IntegrationFactHandler` |
| `Runtime` | `CompiledSubscriptionRegistry`, `DddRuntimeReset`, `Relay` (round-1 relay step), D5 actor providers, the transitional act bracket and outbox bus |
| `Console` | `ddd:relay`, `ddd:schema:dump` |

Schema: `schema/postgres/*.sql` (plain, idempotent; `{{prefix}}` = `tangible_ddd.table_prefix`).

## Tests

```bash
composer install
vendor/bin/phpunit                       # unit + integration + kernel
vendor/bin/phpunit --testsuite unit      # no database
```

Integration and kernel suites need Postgres 16; `DDD_SF_PG_URL` defaults to
`pgsql://postgres:ddd@127.0.0.1:55432/ddd_w2_symfony_adapters` and the bootstrap
creates that database if it is missing. Each test drops and re-creates its tables;
nothing runs inside a per-test transaction.

Until the wave-2 move, `tests/bootstrap.php` maps `TangibleDDD\` to the monorepo's
`ddd-src/` as a fallback after ddd-core's own map (see the transition note in the
example README).
