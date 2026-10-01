# Wave 3 change requests: pdo-compose (rounds 2-3)

Author: pdo-compose, branch `wave3/pdo-compose`. Owned paths: `packages/ddd-core/src/Defaults/Pdo/**`, `packages/ddd-core/tests/Pdo/**`, `examples/plain-php-durable/**`. Binding inputs: register 3.3 (frozen `compose()` signature), 3.5, 3.6, 3.10, 5.1, 5.3, ruling #80, [wave3-notes.md](wave3-notes.md) (W3C-R5), [wave3-core-change-requests.md](wave3-core-change-requests.md) (CR-W3C-1, -4, -5), [wave3-pdo-adapters-change-requests.md](wave3-pdo-adapters-change-requests.md) (CR-PDO-3, -4).

No ratified core interface changed. Everything below is additive (new classes, new methods or constants on pdo-only classes), with one exception. `PdoOperatorView::list()` changes shape on a pdo-only class, as CR-PDO-4 (ratified) announced.

## What this round did

| Task | Where |
|---|---|
| W3C-R5: `IDeliveryWorker` over the deliver jobs | `PdoDeliveryWorker` |
| W3C-R5: `PdoOperatorView` = `PortOperatorView` + item sources | `PdoOperatorView`, `PdoJobsOperatorSource`, `PdoLedgerOperatorSource`, `Internal\OutboxAndQuarantineItems` |
| W3C-R5: drain on a core `Drain` | `DurableRuntime::drain()` / `drainer()` |
| Register 3.3 / ruling #80: `DurableRuntime::compose()` | `DurableRuntime`, `Internal\{RuntimeContainer, HandlerMiddleware, IdentityConfig, RecordingSubscriptionRegistry}` |
| Acceptance fixture | `examples/plain-php-durable/{bootstrap,produce,drain,MysqliConnection}.php`, `README.md` |

Tests (MySQL 8, both prepare modes): `tests/Pdo/Cases/{DurableRuntimeCases, PdoDeliveryWorkerCases}.php`, additions to `PdoJobStoreCases`, `PdoOutboxStoreCases`, the rewritten `PdoOperatorViewCases`, and `tests/Pdo/Native/PlainPhpDurableExampleTest.php`. That last test runs the example as separate `php` processes on PDO and on mysqli.

## CR-PC-1: `PdoJobStore::withClaimKinds(WakeKind ...$kinds): self`

- **What.** A clone of the store whose `claimDue()` leases only rows of the given kinds (`AND kind IN (...)`). Everything else is shared and unchanged: same connection, same table, `schedule` / `cancel` / `submit` / `complete` / `retryLater`. An empty kind list throws `\InvalidArgumentException`. The unfiltered `claimDue()` (CR-PDO-3: both kinds) is unchanged.
- **Why.** Core's `Drain` sends a claimed `Deliver` wake to `$deliverWakes` and retries it with the wake budget (10, 2 s backoff). Register 5.1 counts deliveries against the handler budget (5, 30 s backoff, per subscriber in the ledger). `DurableRuntime` therefore gives the drain's wakeup stage the Continue/Timeout/ResumeRetry view and gives `PdoDeliveryWorker` the Deliver view, so neither stage ever sees the other's rows.
- **Compatibility.** A new method on a pdo-only class.

## CR-PC-2: the fact class on pdo outbox rows

- **What.** `PdoOutboxStore::withFactClass(string $eventClass, callable $work): mixed` scopes a class onto plain `append()` calls made inside `$work`, and restores the previous scope on return or throw. New class `FactClassRecordingEventBus implements IIntegrationEventBus`: core `OutboxIntegrationEventBus` plus that scope. It is the pdo twin of ddd-symfony's decorator for CR sf-1.
- **Why.** The deliver job needs the PHP class to hydrate the fact and to match marker subscriptions (D2), and core's `OutboxRecord` carries no class. `PdoDeliveryWorker` falls back to an event-type map built from the registered subscriptions when a row has no class.
- **Request to core (wave 4, optional).** Add an optional `?string $event_class = null` to `OutboxRecord`, filled by `OutboxIntegrationEventBus`. Both decorators then become pass-throughs.

## CR-PC-3: `PdoDeliveryWorker` (the pdo `IDeliveryWorker`)

`PdoDeliveryWorker(PdoJobStore $jobs, IntegrationDelivery $delivery, array $eventClasses = [], int $leaseSeconds = 300, ?LoggerInterface $logger = null)`. `runDue($now, $limit)`:

1. Claims up to `$limit` due `deliver` jobs.
2. For each job, calls `IntegrationDelivery::deliver(class, envelope)`.
3. Completes (deletes) the job when the outcome needs no retry. Otherwise it calls `retryLater()` at `now + IntegrationDelivery::backoffSeconds(job attempts + 1)` and stores `subscribers to retry: …` in `last_error`.

The ledger holds the budget, so once every failed subscriber is exhausted the job completes. A job that cannot be delivered at all (unknown class, decode failure with budget left, a ledger storage error) is retried with the same backoff and stays visible in the operator view (layer `delivery`, key `deliver:{event_id}`). It is never dropped. `RuntimeReset::betweenMessages()` runs after each job, and leaks are logged.

**Open point for the coordinator.** A job whose fact can never be delivered (its class was deleted, its ledger is unwritable) is retried forever at the 3600 s cap. It is listed in the operator view but has no budget of its own. Register 5.1 says the host runner's own limit bounds that case ("Messenger retry_strategy, a failed Action Scheduler action"). pdo has none yet. Proposal: in wave 4, dead-letter a deliver job after N job attempts where `deliver()` threw, with a `redeliver` repair.

## CR-PC-4: `PdoOperatorView` implements `IOperatorView` (CR-PDO-4 carried out)

- **What.** `list(?Layer $layer = null, int $limit = 100): list<OperatorItem>`, built as core `PortOperatorView` over `PdoOutboxAdministration` and `PdoProcessStore` plus three sources:
  - `PdoLedgerOperatorSource` (public): delivery layer. Key `subscriber@event_id`, the same as the mem ledger. Repair `redeliver` while the subscriber is retrying, none once exhausted.
  - `PdoJobsOperatorSource` (public): wakeup layer, repair `retry_wake`, budget 10. Also deliver jobs in the delivery layer, budget 5.
  - `Internal\OutboxAndQuarantineItems`: `pending` outbox rows with attempts > 0 (relay layer, repair `retry`), and quarantined processes (process layer).

  `toArrays(?Layer, int)` returns `OperatorItem::toArray()` rows, the form a raw-PHP host renders (register 5.1 "OperatorView::list() returning arrays").
- **Shape change (pdo-only, announced by CR-PDO-4).** `list()` used to take a layer string and return arrays with a `detail` key. It now takes `?Layer` and returns `OperatorItem`. The public `LAYERS` constant is gone (use `Layer::cases()`). The delivery-layer key went from the subscriber id to `subscriber@event_id`. Constructor arguments are unchanged. No code outside `Defaults/Pdo` and `tests/Pdo` used the old shape. The `detail` extras are not on `OperatorItem`; the relay repairs (`replay`, `discard`) need the DLQ id, which `PdoOutboxAdministration::deadLetters()` returns. If the coordinator wants `detail` back, the additive core fix is an optional `array $detail = []` on `OperatorItem`.

## CR-PC-5: `DurableRuntime` (register 3.3 frozen signature; accessors)

- **Signature.** `compose(IHostConnection, IConsumerIdentity, ContainerInterface|array $handlers, array $listeners, array $processes, ?IClock $clock = null): DurableRuntime`, exactly as frozen.
- **Accessors (the register calls them "sketch, not frozen").** `bus()`, `queryBus()`, `drain(int $maxItems = 200, int $maxSeconds = 50): DrainReport`, `drainer(): Drain`, `operatorView(): PdoOperatorView`, `runner()`, `jobs()`, `processes()`, `outbox()`, `boundary()`, `localListeners()`, `container()`, `consumer()`, and the constant `QUERY_BUS_ID = 'tactician.query_bus'`. **Deviation from the register sketch:** the register's `drain(): Drain` is `drainer()` here. `drain()` runs a pass, which is what the task brief and every caller want. W3C-R5 ("drain() returns a core Drain") is met by `drainer()`.
- **Choices compose() makes, for the coordinator to note.**
  - **Table prefix** is `$consumer->prefix() . '_'`, so tables are `{prefix}_ddd_*`.
  - **Start mode** is `HostDefaults::get(StartMode::class)` when one is provided, else `StartMode::Deferred`. sf uses the same default. It lets a command start a process atomically with its own writes (CR-W3C-1), and the first step runs in the drain. A host that wants in-band starts provides `StartMode::InBand`.
  - **Identity.** A plain `IConsumerIdentity` is wrapped in `Internal\IdentityConfig` (pure string derivations) for the core constructors that still type `IDDDConfig`. The consumer is registered in `ConsumerRegistry` with the composed container, and its namespace root comes from the identity as usual.
  - **Handlers array.** An `ICommand` or `IQuery` class key is the message's handler: a callable, or an object with `handle()`. Any other key is a service: a `\Closure` is a lazy factory receiving the container, an object is the instance. A plain message with no array entry goes to the naming-convention handler (`HandlerClassNameInflector`) when the container has it, else `\LogicException`. The runtime's own services resolve before the host's.
  - **Budgets.** Outbox config defaults (`OutboxConfig`), delivery budget 5, wake budget 10, stranded threshold 900 s. The signature has no options argument, so changing them means building the parts by hand.
  - **Logging.** `HostDefaults::get(LoggerInterface::class)` when provided, else each component's default.
  - **Not provided to HostDefaults.** compose() registers nothing in `HostDefaults`. The core repair commands, which resolve ports through `HostDefaults::for()`, are wave-4 work (WP8-10) and will need either that or a pdo repair path.

## Requests to other owners

- **packaging (CR-PDO-8 follow-up).** `tests/harness/run.sh core-pdo` can now run `vendor/bin/phpunit -c packages/ddd-core/phpunit.pdo.xml`, which already includes the two-process script through `PlainPhpDurableExampleTest`. The script can also run directly: `php examples/plain-php-durable/produce.php --reset && DDD_CLOCK_OFFSET=120 php examples/plain-php-durable/drain.php && DDD_CLOCK_OFFSET=120 php examples/plain-php-durable/drain.php` (env `DDD_EXAMPLE_DB_*`). `examples/plain-php-durable/` should be excluded from the release artifact like `examples/plain-php/`.
- **conformance.** A pdo `HostFixture` can be built on `DurableRuntime` (bus, drain, jobs, boundary, runner accessors) for the 37 pdo ids.
- **core (optional, wave 4).** The `OutboxRecord::$event_class` request above (CR-PC-2). Optionally, `OperatorItem::$detail` (CR-PC-4).

## Not done here

- CR-PDO-6 (lease-expired re-claims count as relay attempts, dead-letter at claim) is ruled a core rule for wave 4, and pdo has not changed its claim.
- CR-PDO-7 (`MySqlNamedLock implements INamedLock`) is not needed by compose(), because `LegacyProcessStore` is not used on pdo. It is left for the owner of the legacy bridges.
- Operator repairs (`redeliver`, `retry_wake`, `resume_stranded`, `fail_stranded`) are named in `repairActions` only. Register section 8 puts pdo repairs in wave 4.
