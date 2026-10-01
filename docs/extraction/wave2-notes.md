# Wave 2 notes (coordinator)

Date: 2026-10-01. Binding for wave 3 onward, alongside [contract-register.md](contract-register.md), [coordinator-rulings-wave0.md](coordinator-rulings-wave0.md) and [wave1-notes.md](wave1-notes.md). Where this file and the register differ, this file wins.

## Result

Merged on `extraction/ddd-packages` at `66e294d`: split-move, split-ports, packaging, symfony-adapters, conformance-cleanup, sf-conformance, wp-conformance (the coordinator applied WPC-4, WPC-5, WPC-6 and WPC-8 so the verified patches could land).

| Check | Result |
|---|---|
| root `vendor/bin/phpunit` | 874 tests green |
| `packages/ddd-core` (Composer autoload only) | 291 tests green, ProcessRunner on mem doubles |
| `tests/Compat/core-clean-install.sh` | closure `{tangible/ddd-core, league/tactician, psr/container, psr/log}`, no WP after autoload, plain-php example exits 0 |
| deptrac | 0 violations, 0 errors |
| `run.sh loader` | 23 passed, 2 skipped (`jetpack-mixed`, `compiled-containers`: wave 4) |
| `run.sh conformance-wp` | 12 of 12 wave-2 wp ids |
| ddd-conformance `--group mem` | 33 tests, 0 skips |
| ddd-symfony on Postgres 16 | 161 tests, 15 wave-2 sf ids green; 2 skips due in wave 3 |
| `run.sh wp-integration` | 27 tests green on MySQL 8.0 |

## Ratified change requests

Every request in the `wave2-*-change-requests.md` files is **accepted as written** (CR-SM-1..4, CR-SP-1..8, CR-PK-1..6, CR-CC-1..2, CR sf-1..6, CR sfc-1..5, WPC-1..8), with these rulings:

- **sfc-1 / sf-3** (lost lease on accept rolls back a shared submission): core behaviour change in wave 3. After it lands, sf's `RelayOutcomes::accept()` stops throwing `LeaseLostOnAccept`.
- **sfc-2** (`delivery.delayed-once` on transports with their own clock): option (b). The assertion becomes "due no earlier than requested and no later than `max(requested, submit time)`". The conformance owner changes the scenario in wave 3.
- **sfc-3, sfc-4** (`process_batch(?int $limit)`, per-event outcomes): accepted, core wave 3.
- **sfc-5** (`retry()` of a dead-lettered row removes its DLQ entry on every host): accepted, core contract + each host in wave 3.
- **CR-SP-1** (loggers accept `LoggerInterface|\Closure|null` for one round): the closure arm is removed in wave 3.
- **CR-PK-5** (self-expiring transitional allowances): each must expire by the wave it names; the wave-4 gate fails on any that remain.
- **O13** answered: Messenger 7.4 does skip handlers carrying a `HandledStamp` on its own retried envelope, matched by handler name, and not on a fresh dispatch. X8 stands: one `IntegrationFactMessage` per fact, one handler, isolation and once-only effects from the core invoker and the per-subscriber ledger.

## Carried into wave 3

- **wp:** WPC-1 (Action Scheduler `ITransport`), WPC-2 (per-subscriber ledger), WPC-3 (clock-aware outbox adapters honouring `claim($now, $leaseSeconds)`), then delete the conformance fixture stand-ins and `Support/clock-functions.php`. The relay and delivery ids are provisional on wp until then.
- **sf:** switch the transitional act bracket and integration bus (CR sf-6 rows 1 and 2) to core `CorrelationMiddleware` and `OutboxIntegrationEventBus`; provide an sf `IInfrastructureSignalDispatcher` to `HostDefaults` at bundle boot (signals reach the PSR logger and the event dispatcher, never `error_log`); `audit.sink-fails` and `delivery.delayed-once` on sf.
- **conformance:** add the sf-7 "work swallows a statement error" case to `cmd.commit-failure` and the sf-3 assertion to `relay.lease-fencing`.
- The register's `phpunit.integration.xml` for ddd-symfony is read as `vendor/bin/phpunit` (one config with the integration and conformance suites).

## TXP

`tenancy-reference` started at the end of wave 2, consuming `ddd-core` and `ddd-symfony` through path repositories.
