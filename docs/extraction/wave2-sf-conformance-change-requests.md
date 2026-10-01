# Wave 2 round 3: sf-conformance change requests

Author: sf-conformance (wave 2, round 3). Branch `wave2/sf-conformance`. Owned paths: `packages/ddd-symfony/**`. Every item below is additive or a request to another owner; no ratified interface was changed. Binding inputs: [contract-register.md](contract-register.md), [coordinator-rulings-wave0.md](coordinator-rulings-wave0.md), [wave1-notes.md](wave1-notes.md), [wave2-symfony-adapters-change-requests.md](wave2-symfony-adapters-change-requests.md), report [E](wave0/E-symfony-host.md).

## What this round did

1. `ddd:relay` runs the core relay step. `TangibleDDD\Symfony\Runtime\Relay::runOnce()` builds an `OutboxProcessor` in its port form (CONF-3) per step and calls `process_batch()`. The round-1 private loop is gone. The service id `tangible_ddd.relay` and `RelayCommand` are unchanged (CR sf-6).
2. The conformance `HostFixture` for sf: `packages/ddd-symfony/tests/Conformance/SfHostFixture.php`, on Postgres 16. Each test gets a fresh Postgres schema, and no test is wrapped in a transaction. The four scenario classes plus `SfCatalogueTest` and `SfHostFixtureTest` are in the `conformance` testsuite and in the groups `sf` and `conformance`. All 15 wave-2 sf ids are green. `relay.replay-keeps-identity` (sf wave 3) also passes already. `delivery.delayed-once` (sf wave 3) is skipped under CR sfc-2.
3. The round-1 review minors are fixed (section "Review minors").
4. O13 is answered by a spike test (section "O13").

## How the sf fixture maps the HostFixture

| HostFixture member | sf implementation |
|---|---|
| fresh schema | `CREATE SCHEMA ddd_conf_sf_<hash>` in `DDD_SF_PG_URL`'s database. `Support/ScenarioSchemaMiddleware` is a DBAL driver middleware that runs `SET search_path` on every physical connect, so reconnects stay in the schema. `schema/postgres`, the Doctrine transport table (`DoctrineTransport::setup()`) and the scenario table are created in it. `tearDown()` drops the schema with `CASCADE`. |
| one connection | Boundary, outbox, administration, pauses, ledger, scenario rows and the Messenger Doctrine transport all use one DBAL `Connection`. `MessengerFactTransport::sharesConnectionWith()` is therefore true, and the relay uses the one-transaction hand-off. |
| `commandBus()` | The bundle's order and classes: `Transitional\ActBracketMiddleware` (service `tangible_ddd.middleware.act_bracket`), `TransactionalCommandMiddleware` over `DbalTransactionBoundary` (Reject), `DomainEventsPublishMiddleware` over `OrderedListenerDispatcher` and `Transitional\PortOutboxEventBus` (service `tangible_ddd.integration_bus`), then the scenario's handler map. Actor: `SymfonyActorProvider`. Audit sink: core `InMemoryAuditSink`, because sf has no audit table in wave 2 (`audit.sink-fails` is sf wave 3). |
| `failNextCommit()` | Before the driver COMMIT, the middleware inserts a row that violates a `DEFERRABLE INITIALLY DEFERRED` foreign key. Postgres then rejects the COMMIT itself (SQLSTATE 23503). Nothing in DBAL or the boundary is stubbed; `SfHostFixtureTest` pins this. |
| `relayOnce()` | `Relay::runOnce()`, the same object `ddd:relay` uses. `crashNextRelayAfterSubmit()` arms the core seam `between_submit_and_accept` through `Relay::betweenSubmitAndAccept()`. |
| transport faults | `Support/FaultInjectingSender` wraps the Doctrine sender: the next `send()` throws, or the next `send()` returns no `TransportMessageIdStamp` and inserts nothing. `MessengerFactTransport` gets the DBAL connection explicitly (its `senderConnection` parameter), so the wrapper does not hide the shared connection. |
| `transported()` | The rows in `messenger_messages` (decoded with `DoctrineTransport::all()`), plus the messages already consumed by `deliverTransported()`. An ack deletes the row, so the fixture keeps those itself. |
| `deliver()` | Dispatches an `IntegrationFactMessage` through a Messenger bus to `IntegrationFactHandler`. An incomplete delivery surfaces as `FactDeliveryIncomplete` inside `HandlerFailedException`, and its outcome is returned. |
| `deliverTransported()` | A real Messenger `Worker` on the Doctrine receiver, with `DddRuntimeReset` subscribed. |
| `runWorker()` | A real Messenger `Worker` on an in-memory transport, with `DddRuntimeReset` subscribed. Leaks are read from the CRITICAL log entries that `DddRuntimeReset` writes. |
| `processLock()` | Core `ReentrantProcessLock` over `InMemoryProcessLock`, guarded by `RuntimeReset`. The Postgres advisory lock is a wave-3 adapter (register 5.2). |
| signals | `HostDefaults` gets a `RecordingSignalDispatcher` per test, because the relay now emits the core signals. `tearDown()` resets `HostDefaults`, `RuntimeReset`, `ConsumerRegistry`, `Correlation` and `Reactions`. |

## CR sfc-1: the core relay step should roll back a shared submission when the lease is lost on accept (core, behaviour)

This is CR sf-3, restated now that sf runs the core step. `OutboxProcessor::relay_batch()` returns `false` from inside `boundary()->run()` when `accept()` matches 0 rows, and the transaction then commits. On a shared connection that commits the transport's insert, so the new lease holder submits the same fact a second time.

**sf today (no core edit).** `Runtime\RelayOutcomes` is the store view the core step is handed. When the transport shares the connection, its `accept()` throws `LeaseLostOnAccept` on a 0-row accept. The boundary rolls back, the submission goes with it, and the core step's follow-up `retryLater()`/`deadLetter()` is fenced as well and matches 0 rows. The row is reported as `lost`, and the core step logs "lease lost on retry". Tests: `RelayPostgresTest::test_a_lost_lease_on_accept_rolls_back_the_messenger_insert` (Postgres) and `RelayTest::test_a_lost_lease_on_a_shared_connection_rolls_the_submission_back`.

**Request (core).** In the shared branch, `relay_batch()` should throw a private exception inside `run()` when `accept()` returns false, then catch it outside and call `lost_lease($claim, 'accept')`. Once core does this, `RelayOutcomes::accept()` no longer needs to throw. The change is behaviour-only; no signature changes.

## CR sfc-2: `delivery.delayed-once` and transports that schedule on their own clock (conformance, wave 3)

`MessengerFactTransport` converts the absolute due time into a `DelayStamp` relative to the host `IClock`. The Doctrine transport then stores `available_at` = wall-clock now + delay. The scenario advances a frozen host clock by 3600 s and asserts that the transport's due time equals `t0 + D` exactly. The transport cannot know that value, because it never stores the requested absolute time. The id is sf wave 3. Until then `SfDeliveryScenariosTest` overrides it with `skipForChangeRequest('sfc-2', ...)`.

**Request (conformance owner, wave 3).** Choose one of these:

- (a) Define `TransportedFact::$dueAt` as the requested due time, which a host reads from the outbox record it submitted.
- (b) Make the assertion "due no earlier than requested and no later than `max(requested, submit time)`".

(b) is closer to what Messenger guarantees. A third route needs no scenario change: an sf fixture whose clock is the wall clock plus an offset that the Doctrine transport also reads. That route requires the transport to take a PSR-20 clock.

## CR sfc-3: `OutboxProcessor::process_batch(?int $limit = null)` (core, additive, optional)

`ddd:relay --limit=N` sets the batch size of one step. The core step reads it only from `OutboxConfig::batch_size`, so `Relay` builds a copy of the `OutboxConfig` with `batch_size = N` for that step (the named-argument spread of `get_object_vars()`). That works, but it breaks silently if `OutboxConfig` ever gains a constructor parameter that is not a public property. Requested: an optional `?int $limit = null` on `process_batch()`. Existing callers are unaffected.

## CR sfc-4: per-event outcomes from the core relay step (core, additive, optional)

`ProcessingResult` carries counts only. `ddd:relay` reports, and conformance asserts, by event id, so `RelayOutcomes` records the outcome of every fenced write. Requested: optional trailing list parameters on `ProcessingResult` (`claimed`, `accepted`, `retried`, `deadLettered`, `leaseLost`: list of event ids), filled by `relay_batch()`. `RelayOutcomes` could then shrink to the CR sfc-1 workaround, and the mem host could report ids from the core class as well.

## CR sfc-5: `retry()` of a dead-lettered row should remove its DLQ entry on every host (core)

Fixed in sf (review minor 2). `DbalOutboxAdministration::retry()` deletes the row's `ddd_dlq` entries in the same transaction as the reset. Before the fix, `stats()['dead_letters']` kept counting the row, and a later `replay()` of that DLQ id reset an already re-queued row a second time. Core's `InMemoryOutboxStore::retry()` still has the old behaviour, and the `IOutboxAdministration` docblock does not say either way. Requested: the docblock says "a retried `dlq` row leaves the DLQ (its dead-letter entries are deleted)", and the in-memory double follows. Test: `DbalOutboxAdministrationTest::test_retry_of_a_dead_lettered_row_removes_its_dlq_row`.

## Review minors (round 1), status

| Minor | Fix |
|---|---|
| Expired-lease dead letters made inside `claim()` were invisible: the row moved to `ddd_dlq` with only an ERROR log line, it did not appear in the relay report, and no signal fired | `DbalPostgresOutboxStore::takeDeadLetteredAtClaim()` (sf-only, additive) returns the claim and error of each such row since the last call. `Relay` lists them in `RelayReport::$deadLettered` (so `ddd:relay` counts them) and emits `OutboxDeadLettered` for each, as for a relay-side dead letter. Tests: `DbalPostgresOutboxStoreTest::test_a_row_whose_lease_expires_max_attempts_times_is_dead_lettered_at_claim` (extended) and `test_the_relay_reports_and_signals_a_claim_time_dead_letter`. Core adoption of the claim-time rule itself is still CR sf-8. |
| `retry()` on dlq rows left the DLQ row | Fixed; see CR sfc-5. |
| Staleness of the `symlink: false` path repositories | Kept `symlink: false` (report F section 3: a copy catches monorepo-only files). Added the Composer script `composer refresh-siblings` (= `composer update tangible/ddd-core tangible/ddd-conformance`) and documented it in `packages/ddd-symfony/README.md`. |

## O13: does Messenger skip handlers that already carry a `HandledStamp` on a retried envelope?

**Yes, on Symfony 7.4 with the Doctrine transport.** Test: `packages/ddd-symfony/tests/Integration/Messenger/HandledStampRetrySpikeTest.php`.

- A message has two invokable handlers. A succeeds and B throws once. The `Worker` with `SendFailedMessageForRetryListener` re-sends the envelope that `HandlerFailedException` carries, and that envelope has A's `HandledStamp`. The stamp survives `PhpSerializer` and the `messenger_messages` row. On the retry, `HandleMessageMiddleware::messageHasAlreadyBeenHandled()` skips A and runs only B. Result: A ran once, B ran twice, and the message was acked.
- **Caveat 1: matching is by handler name.** Every closure handler is named `Closure`. With two closure handlers, the second is treated as handled as soon as the first succeeds, so it never runs, not even on the first dispatch, and the message is acked. The spike pins this.
- **Caveat 2: skipping depends on the envelope.** It holds only for the envelope Messenger itself re-sends. A fresh dispatch of the same fact runs every handler again. Examples: a relay re-submission after a crash (`relay.crash-after-submit` on a non-shared transport), a replay from the DLQ, or a manual re-send.

**Consequence.** No design change; X8 stands. ddd-symfony sends one `IntegrationFactMessage` per fact to one handler (`IntegrationFactHandler`). Subscriber isolation and once-only effects come from the core invoker and the per-(subscriber, event_id) ledger, and those also cover caveat 2. The stamp skip could only be an optimisation for apps that attach their own extra handlers to `IntegrationFactMessage`, and those apps should use named invokable services, not closures (caveat 1).

## Additive sf API and behaviour changes (no request, listed for review)

- `Relay::__construct()` gains an optional trailing `?IDDDConfig $consumer` (the core step needs it for signals and the log prefix). The bundle passes `tangible_ddd.consumer_config`; the default is `SymfonyConsumerConfig('ddd', 'App')`.
- `Relay::betweenSubmitAndAccept(?\Closure)` exposes the core test seam.
- `Relay::backoffSeconds()` now delegates to `OutboxProcessor::backoff_seconds()` (same values; the round-1 exponent cap of 32 is gone).
- New `@internal` classes: `Runtime\RelayOutcomes` (the recording store view, plus the CR sfc-1 workaround) and `Runtime\RelayTransportView` (answers `sharesConnectionWith()` for the real store).
- `DbalPostgresOutboxStore::takeDeadLetteredAtClaim()`.
- **Behaviour:** the relay now emits the core `OutboxAttemptFailed` / `OutboxDeadLettered` signals and the core `[{prefix}-outbox] STATUS: {json}` log lines. The signals go to `HostDefaults`' `IInfrastructureSignalDispatcher`. The bundle registers none, so they reach core's `LoggingSignalDispatcher`, which writes to `error_log` when no host logger is registered. See Unresolved.
- `composer.json` (owned this round): a path repository `../ddd-conformance`, `require-dev` `tangible/ddd-conformance: self.version`, and the `refresh-siblings` script. `require` is unchanged, so `PackageManifestTest`'s pin still holds.
- `phpunit.xml`: a `conformance` testsuite.

## Unresolved, for the coordinator

- The transitional act bracket and integration bus (CR sf-6 rows 1 and 2) are not switched to core `CorrelationMiddleware` / `OutboxIntegrationEventBus` this round; only the relay was in scope. The fixture builds the bus from the same classes the bundle wires, so the conformance suite will re-verify the switch when it lands.
- Signals in a Symfony app should reach the PSR logger or the event dispatcher, not `error_log`. A small sf `IInfrastructureSignalDispatcher` provided to `HostDefaults` at `Bundle::boot()` would do it. It is not done here, because a boot-time `HostDefaults` write needs care in the kernel tests.
- CR sf-7's conformance request (add a "work swallows a statement error" case to `cmd.commit-failure`) and CR sf-3's suggested `relay.lease-fencing` assertion are for the conformance owner. sf already covers both in its own integration tests.
- The register's wave-2 acceptance line reads `vendor/bin/phpunit -c phpunit.integration.xml`. ddd-symfony has a single `phpunit.xml` that now includes the conformance suite, so the acceptance command is `vendor/bin/phpunit` (or `--testsuite conformance`).
