# tangible/ddd-symfony

Symfony 7.4 host for `tangible/ddd-core` on Postgres 16 (register sections 1.2, 3.2-3.5, 5.1).
Install and configure: [examples/symfony/README.md](../../examples/symfony/README.md).

| Namespace | Contents |
|---|---|
| `Bundle` | `TangibleDddBundle` (configuration, service wiring, consumer registration at boot) |
| `DependencyInjection` | handler and `handle()` locators, compile-time subscription map, domain-listener map, Messenger health check, `#[AsIntegrationListener]`, `#[AsDomainEventListener]` |
| `Persistence` | `DbalTransactionBoundary`, `DbalPostgresOutboxStore`, `DbalRelayPauseStore`, `DbalDeliveryLedger`, `DbalOutboxAdministration`, `PostgresSchema` |
| `Messenger` | `IntegrationFactMessage`, `MessengerFactTransport` (ITransport), `IntegrationFactHandler` |
| `Runtime` | `CompiledSubscriptionRegistry`, `DddRuntimeReset`, `Relay` (the core relay step, `OutboxProcessor` port form), D5 actor providers, the transitional act bracket and outbox bus |
| `Console` | `ddd:relay`, `ddd:schema:dump` |

Schema: `schema/postgres/*.sql` (plain, idempotent; `{{prefix}}` = `tangible_ddd.table_prefix`).

## Tests

```bash
composer install
vendor/bin/phpunit                          # unit + integration + kernel + conformance
vendor/bin/phpunit --testsuite unit         # no database
vendor/bin/phpunit --testsuite conformance  # the shared ddd-conformance scenarios on sf
vendor/bin/phpunit --group relay.lease-fencing   # one scenario id
```

Integration, kernel and conformance suites need Postgres 16. `DDD_SF_PG_URL`
defaults to `pgsql://postgres:ddd@127.0.0.1:55432/ddd_w2_symfony_adapters`; point
it at a database of your own when other runs share the server. The bootstrap
creates that database if it is missing. Integration and kernel tests drop and
re-create their tables; each conformance test gets a fresh Postgres schema
(`ddd_conf_sf_<hash>`) that is dropped afterwards. Nothing runs inside a
per-test transaction.

The conformance host is `tests/Conformance/SfHostFixture.php`; the scenarios
come from `tangible/ddd-conformance` (require-dev).

### Sibling packages are copied, not linked

`tangible/ddd-core` and `tangible/ddd-conformance` come from path repositories
with `"symlink": false` (report F section 3: a copy catches files that exist
only through the monorepo). `vendor/` therefore keeps the copy made at the last
install. After editing `packages/ddd-core` or `packages/ddd-conformance`,
refresh it before running the tests here:

```bash
composer refresh-siblings   # = composer update tangible/ddd-core tangible/ddd-conformance
```

Without it the suite silently runs against the old copy.
