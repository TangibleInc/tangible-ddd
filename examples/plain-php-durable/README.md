# Using tangible-ddd 0.7 in plain PHP (ddd-core on PDO)

`tangible/ddd-core` runs in a raw-PHP application on MySQL 8 with nothing else: no framework, no WordPress, no daemon and no migrator. You bring the database connection. One call, `DurableRuntime::compose()`, turns it into a command bus, a transactional outbox, durable delivery, processes and an operator view. A drain pass that you schedule does the asynchronous work. The first half of this file is the guide. The second half is this directory's example, which is the acceptance fixture of `compose()` (register 3.3, section 8 wave 3).

The adapter set is described in detail in `packages/ddd-core/src/Defaults/Pdo/README.md`. MySQL 8 is tested. MariaDB is not a claimed target.

## The guide

### Install and compose

```bash
composer require tangible/ddd-core league/tactician:^2.0-rc1
```

```php
$pdo = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
$db = new PdoConnection($pdo);       // the same PDO your repositories use: one transaction covers domain + outbox

foreach (SchemaSql::statements('shop_') as $sql) { $db->execute($sql); }   // or SchemaSql::dump('shop_') into your migration tool
(new SchemaCheck($db, 'shop_'))->assert();

$runtime = DurableRuntime::compose(
  $db,
  new ShopConsumer(),                // IConsumerIdentity: prefix 'shop' → tables shop_ddd_*; its namespace routes ->send()
  $handlers,                         // [Command::class => handler] and services, or a PSR-11 container
  [ReserveStockOnOrderPlaced::class],    // integration listeners
  [FulfilOrderProcess::class],           // processes: #[StartsOn] ignitions and #[Awaits] resumes
);
```

- **Schema.** The files in `packages/ddd-core/schema/mysql8/` are plain `CREATE TABLE IF NOT EXISTS` statements, and you apply them yourself. The schema only grows: an upgrade adds numbered files, and `SchemaSql::dump('shop_', 8)` prints only the files after `008`.
- **Your own connection type.** A host on MySQLi implements `IHostConnection` over the handle it already has. See "A CodeIgniter-style host on MySQLi" below.

### Commands

The bus runs the core middleware order: correlation (act bracket and audit), effect, transaction, domain events, self-executing, handler. A command opens a transaction only if it implements `ITransactionalCommand`.

```php
final class PlaceOrder extends SelfHandlingCommand implements ITransactionalCommand {
  public function __construct(public readonly int $customer_id, public readonly int $total_cents) {}

  protected function handle(OrderRepository $orders): array {   // services come from $handlers
    $order = Order::place($this->customer_id, $this->total_cents);   // records OrderPlaced
    $orders->save($order);
    return ['order_id' => $order->id()];
  }
}

$receipt = $runtime->bus()->handle(new PlaceOrder(7, 4200));   // or (new PlaceOrder(7, 4200))->send()
```

- A plain command maps to its handler in `$handlers` (`PlaceOrder::class => $handler`). The handler implements `ICommandHandler`, or `IReturningCommandHandler` to return a receipt.
- Aggregates extend `AggregateRoot`, and their repositories extend `AggregateRootRepository`, whose final `save()` persists and then collects the recorded events.
- `NotPermittedException` is the 403 family.
- `#[Audit(false)]` and `#[Audit(parameters: false)]` on a command class, plus `#[Sensitive]` and `#[NotAudited]` on its properties, control the audit row.
- Queries go through `$runtime->query_bus()`.

### Facts

A domain event that implements `IAnnouncesIntegration` announces a fact (an `IntegrationEvent`). The fact is appended to `{prefix}_ddd_outbox` in the command's transaction. A fact's `delay()` is applied once, as an absolute UTC due time. A binary or large field is typed `LargeString`. The outbox refuses a payload that is over 8 MiB or not JSON-encodable, and the command then fails.

```php
final class OrderPlacedFact extends IntegrationEvent {
  public function __construct(public readonly int $order_id = 0) {}
}
```

### Listeners

A listener extends `IntegrationTranslator`: one fact in, one optional command out. `compose()` subscribes each class in `$listeners` at `Subscriber::LISTENER`, or at the priority its `#[SubscriberPriority]` names. A listener can subscribe to a marker interface (D2) instead of a fact class.

```php
final class ReserveStockOnOrderPlaced extends IntegrationTranslator {
  protected function get_event_class(): string { return OrderPlacedFact::class; }

  protected function get_command(IIntegrationEvent $event): ?ICommand {
    return new ReserveStock($event->order_id, Correlation::current_fact()?->event_id);
  }
}
```

- **Delivery.** The drain delivers each fact through the per-subscriber ledger (`{prefix}_ddd_delivery_ledger`). Subscribers run in priority order, and each runs at most once per fact.
- **Retries.** A failing subscriber is retried up to 5 times, with backoff 30 s × 2ⁿ capped at 3600 s, and the other subscribers are not affected.
- **Command ids.** The translated command is sent under `DeterministicCommandId::for_fact($event_id, $subscriber_id)`, so a redelivery has the same command id.

### Processes

```php
#[StartsOn(OrderPlacedFact::class)]                  // one process per fact, however often it is delivered
#[Awaits(PaymentReceivedFact::class)]
final class FulfilOrderProcess extends LongProcess {
  public function __construct(public readonly int $order_id = 0) { parent::__construct(null); }

  public static function from_event(OrderPlacedFact $e): ?static { return new static($e->order_id); }

  protected function request_payment(): Result {
    $ref = $this->step_ref('payment');               // the same on a re-run of this step
    return new Result(
      commands: [new RequestPayment($this->order_id, $ref)],
      await: AwaitEvent::keyed(PaymentReceivedFact::class, $ref, timeout_seconds: 3600),
    );
  }

  #[RetryStep(attempts: 2, backoff_seconds: 30)]
  protected function ship(mixed $payload, PaymentReceivedFact $paid): Result {
    return new Result(commands: [new ShipOrder($this->order_id)]);
  }

  #[Compensates('request_payment')]
  protected function cancel(\Throwable $cause, mixed $checkpoint): Result {
    return new Result(commands: [new CancelOrder($this->order_id)]);
  }
}
```

- **Start mode.** The runtime starts processes in `StartMode::Deferred`. `ProcessRunner::start()` (resolve `ProcessRunner` in `handle()`, or use `$runtime->runner()`) may run inside a command. The process row and its Continue intent then commit with the command, and the first step runs in the next drain.
- **Locking.** The process lock is MySQL `GET_LOCK` (`MySqlNamedLock`) and fails closed. Saves are version-fenced.
- **Ignition.** It is gated by `UNIQUE (process_class, ignition_key)`.
- **Strays.** A process stranded mid-step, or one that no longer decodes, appears in the operator view.

### Awaits and alarms

All of the D3 and D7 mechanisms run on pdo:

- `AwaitEvent::keyed()`: the answering fact implements `IAwaitKeyed::await_key()`;
- `AwaitAny::of(...)->cancelled_by(...)->within(3600)`;
- `AwaitAll::keyed($fact_class, $keys, $timeout_seconds)` over a key set computed at step time;
- `IPrecheckAwait::already_satisfied()` with `PrecheckSatisfied::with()`, which registers first and then checks for an answer that already committed;
- alarms: `timeout_seconds` on any await, `AwaitAlarm::at($instant)` and `AwaitAlarm::after($seconds)`.

An alarm is one row in `{prefix}_ddd_jobs` with an absolute UTC due time. Nothing has to stay running until it is due: the next drain after the due time fires it. `on_timeout` is `fail` (compensate) or `proceed` (the next step receives `null`). The 0.6 forms, an unkeyed `AwaitEvent` and an extractor-keyed `AwaitAll`, keep their first-wins behaviour.

### Workflows

`compose()` builds the D10 stores on the same connection: `$runtime->workflows()`, `$runtime->work_items()` and `$runtime->ignitions()`. Give them to your `WorkflowHandler` subclass. For a workflow that a fact or a cron tick starts, use a core `WorkflowIgniter` over `$runtime->ignitions()` and `$runtime->boundary()`. `WorkflowIgniter::ignite($workflow, $fact, $event_id)` creates exactly one workflow per dedup key (`WorkflowIgnitionKey::per_minute()`, `WorkflowIgnitionKey::for_fact()`). `compose()` does not subscribe `IStartsFromFact` handlers itself: `workflow.fact-ignition-once` is `-` on pdo. So call `ignite()` from your own cron entry point.

### External effects (D1)

A command that calls an external API implements `IExternalEffectCommand` (`idempotency_key()`, `perform()`, `record()`, `failure_command()`):

- `EffectMiddleware` runs `perform()` outside any transaction and journals its `EffectResult` in `{prefix}_ddd_effect_journal` (`$runtime->journal()`). Then `record()` runs inside the command's transaction.
- A retry under the same key reuses the journaled result, and `perform()` is not called again.
- Dispatching an effect inside an open transaction throws `EffectInsideTransaction`.
- When a listener's handler budget is spent, `failure_command()` is sent once.
- A repair calls `IEffectJournal::invalidate($key, $reason)` in its own transaction, and only then performs again.
- Entry states (wave 5, `{prefix}_ddd_effect_recorded`, schema `010`): an entry is Performed until `record()` commits, then Recorded. A Recorded entry is returned as it is, and `record()` does not run again. A Performed entry runs `record()` again with the journaled result. `$runtime->journal()->find_entry($key)` shows the state.
- A handler-class effect (wave 5) is an `IEffectCommand` (`idempotency_key()`, `failure_command()`) whose `IExternalEffectHandler` performs and records it. Pass the handler like any other: `[RefundChargeCommand::class => new RefundChargeHandler($gateway)]` in `$handlers`, or a convention-named `CommandHandlers\RefundChargeHandler` in your container. With no handler, the bus throws `NoEffectHandler` before anything is performed.

The Symfony guide has a full effect example ([examples/symfony/README.md](../symfony/README.md), section 6), and it reads the same on pdo.

### The drain and the operator view

```php
$report = $runtime->drain(maxItems: 200, maxSeconds: 50);   // a core Drain::run_once(): relay, deliveries, wakeups, stranded scan
foreach ($runtime->operator_view()->to_arrays() as $item) { /* render */ }
$runtime->operator_view()->repair(Layer::Process, '42', 'resume_stranded');
```

`drain()` never loops or sleeps. Run it from cron, from a shutdown function, or from your own `while (true) { $runtime->drain(); sleep(1); }` worker. Overlapping runs are safe, because claims and leases keep them apart. The operator view lists the `relay`, `delivery`, `wakeup`, `process` and `effect` layers, with attempts against budget, the last error and the repairs that apply:

| Layer | Repairs |
|---|---|
| relay | `retry`, `replay`, `discard` |
| delivery | `redeliver` |
| wakeup | `retry_wake` |
| process | `resume_stranded`, `fail_stranded` |
| effect | `invalidate` (an effect performed more than 5 minutes ago and never recorded; option `reason`) |

An answer that arrives while another worker holds its process lock is not failed (wave 5): the runtime's jobs table is a `PdoParkingJobStore`, so the resume is parked as a `resume_retry` job carrying the fact (`{prefix}_ddd_job_facts`, schema `011`), the delivery completes, and a later drain resumes the process once the lock is free. The parked job shows in the `wakeup` layer while it waits.

Behaviour config types: `compose()` provides one `IBehaviourTypes` to `HostDefaults` (or uses the one already there), and the `register_type()` calls made before it are handed over (`$runtime->behaviour_types()`).

A refused repair throws `PdoRepairRefused`, `OutboxAdministrationRefused`, `OutboxRowNotFound` or `ProcessNotStranded`.

## The example in this directory

A trial must be activated within a minute. A process asks for the activation, then waits for the fact or the timeout.

| File | Role |
|---|---|
| `bootstrap.php` | Host code: opens its own connection from env vars, applies `schema/mysql8`, declares the domain, calls `DurableRuntime::compose()` once |
| `produce.php` | The web request: sends `StartTrial`, then runs one drain pass, as a shutdown function would |
| `drain.php` | The cron line: one bounded `drain()` pass in a fresh process, with assertions |
| `MysqliConnection.php` | `IHostConnection` over `\mysqli`, for CodeIgniter-style hosts (below) |

### Run it

From the repository root, with MySQL 8 reachable (defaults: `127.0.0.1:33306`, `root` / `ddd`):

```sh
php examples/plain-php-durable/produce.php --reset \
  && DDD_CLOCK_OFFSET=120 php examples/plain-php-durable/drain.php \
  && DDD_CLOCK_OFFSET=120 php examples/plain-php-durable/drain.php
```

Each script prints its checks and exits 0, or prints `FAIL` lines and exits 1.

1. `produce.php` sends `StartTrial`. The trial row, the `TrialProcess` row (`scheduled`) and its Continue intent commit in one transaction on the host's connection. The runtime's start mode is deferred, so starting a process inside a command is legal and atomic with the command's own writes. The post-response drain pass then runs the first step on the real clock. The step persists its await and a timeout intent due in 60 s, and only then dispatches `ActivateTrial`. Its `TrialActivated` fact waits in the outbox.
2. The first `drain.php` runs with its clock 120 s ahead (`DDD_CLOCK_OFFSET`, read by `EnvOffsetClock`), so the timeout is due as well. The relay hands the fact to its `deliver` job, and the delivery resumes the process: deliveries run before wakeups, and the fact arrived before the deadline. The trial finishes `activated`, and the satisfied await cancels the timeout intent in the same transaction.
3. The second `drain.php` finds the process completed. It plants a surviving copy of the old timeout intent, the kind a restored backup or a duplicated queue leaves behind. The pass runs it as a stale-safe no-op: the runner re-reads the row under the process lock, sees it is not `suspended`, and changes nothing.

`produce.php --no-activation` sends no activation. A drain without the offset then reports that the trial is still waiting. A drain with the offset fires the timeout, and the trial finishes `timed_out` (`AwaitAll::TIMEOUT_PROCEED`).

Environment variables:

- `DDD_EXAMPLE_DB_HOST`, `DDD_EXAMPLE_DB_PORT`, `DDD_EXAMPLE_DB_USER`, `DDD_EXAMPLE_DB_PASSWORD`;
- `DDD_EXAMPLE_DB_NAME` (default `ddd_example_durable`, created when missing; `--reset` drops it first);
- `DDD_EXAMPLE_DRIVER` (`pdo` or `mysqli`);
- `DDD_CLOCK_OFFSET` (seconds, or an ISO 8601 duration);
- `DDD_AUTOLOAD`.

The pdo suite runs this sequence on both drivers (`packages/ddd-core/tests/Pdo/Native/PlainPhpDurableExampleTest.php`).

### The composition

```php
$db = new PdoConnection($pdo);   // the PDO your repositories already use, ERRMODE_EXCEPTION
foreach (SchemaSql::statements('trialdemo_') as $sql) { $db->execute($sql); }   // your tooling

$runtime = DurableRuntime::compose(
  $db,                        // IHostConnection: one connection, so one transaction covers domain + outbox
  new TrialConsumer(),        // IConsumerIdentity: prefix 'trialdemo' → tables trialdemo_ddd_*; its namespace routes ->send()
  [],                         // handlers: [Command::class => handler] and services, or a PSR-11 container
  [],                         // integration listeners (IntegrationTranslator classes)
  [TrialProcess::class],      // processes: #[StartsOn] ignitions and #[Awaits] resumes
);

$runtime->bus()->handle(new StartTrial(1));                   // request
$report = $runtime->drain(maxItems: 200, maxSeconds: 50);     // cron, or a shutdown function
$items = $runtime->operator_view()->to_arrays();              // one failure view across relay, delivery, wakeup, process
```

`EnvOffsetClock` is a test-only clock from `TangibleDDD\Testing`. A real host passes no clock and gets the system clock.

### A CodeIgniter-style host on MySQLi

Suppose a host's models write through MySQLi rather than PDO. The host implements `IHostConnection` over the handle it already has (CodeIgniter 4: `$db->connID`), so its domain writes and the outbox share one transaction. This is the whole adapter (`MysqliConnection.php`; `DDD_EXAMPLE_DRIVER=mysqli` runs the example on it):

```php
final class MysqliConnection implements IHostConnection {

  private bool $inTransaction = false;

  public function __construct(private readonly \mysqli $db) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // every failure throws mysqli_sql_exception
  }

  public function execute(string $sql, array $params = []): int {
    return (int) $this->run($sql, $params)->affected_rows;
  }

  public function fetch_all(string $sql, array $params = []): array {
    $result = $this->run($sql, $params)->get_result();
    return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
  }

  public function fetch_one(string $sql, array $params = []): ?array {
    return $this->fetch_all($sql, $params)[0] ?? null;
  }

  public function last_insert_id(): string { return (string) $this->db->insert_id; }
  public function begin(): void { $this->db->begin_transaction(); $this->inTransaction = true; }
  public function commit(): void { $this->inTransaction = false; $this->db->commit(); }
  public function rollback(): void { $this->inTransaction = false; $this->db->rollback(); }
  public function in_transaction(): bool { return $this->inTransaction; }

  public function is_duplicate_key(\Throwable $e): bool {
    return $e instanceof \mysqli_sql_exception && $e->getCode() === 1062;
  }

  private function run(string $sql, array $params): \mysqli_stmt {
    $statement = $this->db->prepare($sql);
    if ($params !== []) {
      $values = array_map(static fn ($v) => is_bool($v) ? (int) $v : $v, array_values($params));
      $types = implode('', array_map(static fn ($v) => is_int($v) ? 'i' : 's', $values));
      $statement->bind_param($types, ...$values);
    }
    $statement->execute();
    return $statement;
  }
}
```

Rules the adapter must keep (register 3.3):

- Every method throws on failure, and nothing returns `false`.
- `is_duplicate_key()` matches error 1062 only, never SQLSTATE 23000. That SQLSTATE class also covers foreign-key and NOT NULL violations, and matching it would make an ignition silently disappear.
- Integers bind as integers, so `LIMIT ?` works.
- `in_transaction()` must reflect transactions opened through this adapter. If the host opens one with `$db->transBegin()` outside the adapter, the library cannot see it. A host that mixes the two must route its own transactions through the adapter, or through the runtime's `boundary()`.

If the host's domain writes already go through a PDO, it wraps that PDO in `PdoConnection` instead and needs no adapter.
