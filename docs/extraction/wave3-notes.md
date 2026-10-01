# Wave 3 notes (coordinator)

Date: 2026-10-01. Binding alongside the register, the wave-0 rulings and the wave-1/2 notes; this file wins where they differ.

## Round 1 (merged)

`wave3/core`, `wave3/pdo-adapters`, `wave3/sf-process` merged by the merge agent; `wave3/wp-v8` merged by the coordinator at `9f8f995` after updating `WpProcessWakeE2ETest`: core now cancels the timeout intent when its await is satisfied (CR-W3C behaviour change 4), so the projected Action Scheduler action is unscheduled, and the test asserts that plus "a surviving copy of the action still fires as a no-op".

## Ratified change requests

All requests in `wave3-core-change-requests.md` (CR-W3C-1..6), `wave3-pdo-adapters-change-requests.md` (CR-PDO-1..8), `wave3-sf-process-change-requests.md` (CR sfp-1..3) and `wave3-wp-v8-change-requests.md` (WP8-1..11) are **accepted as written**, with these rulings:

- **CR-W3C-1** Deferred start mode is legal inside a command; in-band starts inside a command still throw. sf wires `StartMode::Deferred` as the bundle default; `ddd.process.inband_start: true` maps to `InBand`.
- **CR-W3C-6** D13 deterministic step command ids land in wave 3 (early) and are frozen.
- **WP8-1** `long_processes.start_path` (nullable) joins the v8 column list.
- **CR-PDO-6 ruling:** CR sf-8 becomes a **core rule**. A re-claim of an expired lease counts as a relay attempt, and a row that reaches `max_attempts` through re-claims is dead-lettered at claim and shows in the operator view. Implemented on mem (core double), pdo and wp in wave 4; `relay.lease-fencing` gains the assertion.
- **WP8-10:** core ships `ResumeStrandedProcess` / `FailStrandedProcess` repair commands in wave 4; wp then adds the `resume-stranded` / `fail-stranded` labels and `wp ddd ops` repairs; sf adds `ddd:ops:stranded --resume|--fail`.
- **W3C-R1** (wp `ResumeRetry` on the final `WpdbWakeupScheduler`) must be closed in round 3's wp conformance work; the transitional `ActionSchedulerWakeupScheduler` may keep refusing it.
- **W3C-R4** (sf: drop `LeaseLostOnAccept`, use `process_batch($limit)` outcomes, drop the closure logger arm, `StartMode::Deferred` default) and **W3C-R5** (pdo: `IDeliveryWorker`, `DurableRuntime::drain()` returns a core `Drain`, `PdoOperatorView` over `PortOperatorView`) are done in rounds 2-3 by their owners.

## TXP demands from tenancy-reference (wave 4a)

The TXP `tenancy-reference` slice passed its gate on 2026-10-01 against this library. It recorded eight demands, all assigned to wave 4a:

- **L1** (core) `IReturningCommandHandler` with `handle(): mixed`, autoconfigured with the command-handler tag (D11 for plain handlers).
- **L2** (core) an identity-agnostic aggregate root; `PersistsAggregatesRepository::save()` accepts `IRecordsDomainEvents`.
- **L3** (core) `IntegrationTranslator::get_event_class()` typed `class-string` (fact class or D2 marker interface).
- **L4** (sf) `consumer.version` null normalised to `'0.0.0'`; README fixed.
- **L5** (sf, contract) schema evolution is append-only: never edit a shipped statement, ship numbered ALTER files.
- **L6** (sf, **correctness**) `DbalTransactionBoundary` rollback must clear/reset the configured EntityManager, plus a conformance case. Highest priority of the eight.
- **L7** (core) `NotPermittedException` (403 family).
- **L8** (sf) a unique violation at the ORM flush in `beforeCommit` is rethrown as the library's conflict exception.
- Doc clarification: a D11 receipt cannot carry what an in-transaction reaction creates.
