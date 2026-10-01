# Wave 2 round 3: conformance-cleanup change requests

Author: conformance-cleanup (branch `wave2/conformance-cleanup`, based on `6258c0d`). Owned path: `packages/ddd-conformance/**` (this file is the one exception the task grants). Every entry is additive. `HostFixture` is unchanged: no method was added, removed or re-typed, so the wp and sf fixtures written in parallel against it keep compiling.

## CR-CC-1: optional fixture seams `AuditSinkFaults` and `RecordsSignals` for `audit.sink-fails`

- **What.** Two new interfaces in `TangibleDDD\Conformance`:
  - `AuditSinkFaults::failNextAuditClose(string $reason): void`: the next `IAuditSink::close()` throws. The act bracket closes after the transaction, so the failure lands after commit.
  - `RecordsSignals::signals(): array`: the `IInfrastructureEvent`s the host emitted since `setUp()`, oldest first.
  - New scenario `CommandScenarios::test_audit_sink_fails` (`#[Group('audit.sink-fails')]`). It asserts that the command result passes through, that the domain row and the outbox row commit, that no audit row is closed, and that exactly one `AuditSinkFailed` is emitted (phase `close`, subject = the fact's `command_id`, correlation = the fact's story, error carries the reason). It then asserts that a failing command still surfaces its own exception, not the sink's.
- **Why separate interfaces.** Register 3.9 and the scenario table need a way to make the sink fail and to read the signal; `HostFixture` has neither. Adding methods to `HostFixture` would break the wp and sf fixtures being written against it this round. A fixture that implements neither seam gets the scenario **skipped** with `blocked on CR-CC-1`, never failed. Because `test_audit_sink_fails` lives on `CommandScenarios`, wp's and sf's existing `*CommandScenariosTest` classes inherit it without new classes.
- **For the wp and sf authors.** `audit.sink-fails` is due on wp in wave 2 and on sf in wave 3. To run it, implement both interfaces on the fixture. wp: make the next `WpdbAuditSink` close fail, and record signals by wrapping the `IInfrastructureSignalDispatcher` in HostDefaults or by listening on `tangible_ddd_audit_sink_failed`. sf: the same on the DBAL sink and its dispatcher.
- **Mem.** `MemHostFixture` implements both, with `Support\FaultInjectingAuditSink` around `InMemoryAuditSink` and `Testing\RecordingSignalDispatcher` in HostDefaults. `CatalogueTest::test_the_mem_host_provides_the_optional_seams_its_wave_2_ids_need` makes sure mem never skips the scenario.
- **Compatibility.** New types only.

## CR-CC-2: reusable `Support` helpers for host fixtures

- **What.** `Support\ConformanceConfig` is a host-neutral `IDDDConfig` (CR-SP-7: `CorrelationMiddleware` and `OutboxProcessor` still type their consumer as `IDDDConfig`). `Support\RecordingLogger` is a PSR-3 logger that keeps its records. `Support\RecordingOutboxStore` is a pass-through `IOutboxStore` decorator: it builds a `RelayReport` by event id from a real relay step, because the core `ProcessingResult` only carries counts. `Support\FaultInjectingAuditSink` is described under CR-CC-1.
- **Caveat (documented on the class).** `RecordingOutboxStore` is not the inner store. A transport whose `sharesConnectionWith()` checks store identity or class must be asked about `inner()`. The mem transport ignores its argument, and the core processor passes the store it was given. A host whose transport checks identity can either wrap differently or derive the report another way.
- **Compatibility.** New classes. `packages/ddd-conformance/composer.json` now requires `psr/log: ^2|^3` directly, because conformance code uses `Psr\Log` (it was already in the closure through ddd-core's `^1|^2|^3`).

## Done from other authors' requests (no change request needed)

- **CONF-1..3 (split-ports "Needs another owner").** `ActBracketMiddleware`, `PortOutboxBus` and `PortRelay` are deleted. The mem host composes `CorrelationMiddleware(config, uow, Redactor, sink, actor, policy, env)`, `OutboxIntegrationEventBus(null, config, observer, clock, store, outboxConfig)` and `OutboxProcessor(config, null, outboxConfig, null, null, logger, clock, store, transport, boundary)`. `between_submit_and_accept()` replaces the stand-in's crash flag. `tests/Mem/MemHostCompositionTest.php` pins this: the only conformance-owned middleware is `HandlerMapMiddleware`, no conformance class implements `IIntegrationEventBus` or calls `ITransport::submit()`, and each core-only behaviour is checked (the `plugin` environment key, the fact observer, the `[conformance-outbox] COMPLETED` log line).
- **CONF-5.** The shared-connection `relay.crash-after-submit` is no longer skipped: `new InMemoryTransport(true, $boundary)`.
- **Bootstrap bridge.** `tests/bootstrap.php` loads only Composer autoload. The `packages/ddd-wp/src/` fallback is gone, and a test asserts that `TangibleDDD\Infra\DDDConfig` (a ddd-wp class) is not autoloadable.
- **CR-SP-1 follow-up.** `MemHostFixture` passes a PSR-3 logger to `InMemoryTransactionBoundary`, `ReentrantProcessLock`, `IntegrationDelivery` and `OutboxProcessor`. No conformance code passes a closure logger any more. Once ddd-symfony's `RuntimeLog` also stops passing a closure, the coordinator can drop `\Closure` from those signatures.
- **HostDefaults on mem.** The fixture registers `LoggerInterface`, `IInfrastructureSignalDispatcher` and `IClock`, never `ITransactionBoundary` (so `cmd.no-boundary` still sees no boundary). `tearDown()` resets HostDefaults as before.

## Notes for reviewers

- The relay report now comes from the real processor. `retried` and `deadLettered` are recorded only when the fenced write matched, and a fenced write that matched nothing is reported as `leaseLost` (the stand-in did the same).
- `relayOnce($limit)` builds a fresh `OutboxProcessor` for each call, with `batch_size = $limit` and every other `OutboxConfig` field taken from the fixture's config.
