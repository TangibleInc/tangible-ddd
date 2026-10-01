# plain-php-durable

A raw-PHP consumer of `tangible/ddd-core` with durable delivery and a long-running process, on MySQL 8 and nothing else: no framework, no WordPress, no daemon, no migrator. It is the acceptance fixture of `DurableRuntime::compose()` (register 3.3, section 8 wave 3).

| File | Role |
|---|---|
| `bootstrap.php` | Host code: opens its own connection from env vars, applies `schema/mysql8`, declares the domain, calls `DurableRuntime::compose()` once |
| `produce.php` | The web request: sends `StartTrial`, then runs one drain pass, as a shutdown function would |
| `drain.php` | The cron line: one bounded `drain()` pass in a fresh process, with assertions |
| `MysqliConnection.php` | `IHostConnection` over `\mysqli`, for CodeIgniter-style hosts (below) |

## Run it

From the repository root, with MySQL 8 reachable (defaults: `127.0.0.1:33306`, `root` / `ddd`):

```sh
php examples/plain-php-durable/produce.php --reset \
  && DDD_CLOCK_OFFSET=120 php examples/plain-php-durable/drain.php \
  && DDD_CLOCK_OFFSET=120 php examples/plain-php-durable/drain.php
```

Each script prints its checks and exits 0, or prints `FAIL` lines and exits 1.

1. `produce.php` sends `StartTrial`. The trial row, the `TrialProcess` row (`scheduled`) and its Continue intent commit in one transaction on the host's connection: the runtime's start mode is deferred, so starting a process inside a command is legal and atomic with the command's own writes. The post-response drain pass then runs the first step on the real clock. The step persists its await and a timeout intent due in 60 s, and only then dispatches `ActivateTrial`, whose `TrialActivated` fact waits in the outbox.
2. The first `drain.php` runs with its clock 120 s ahead (`DDD_CLOCK_OFFSET`, read by `EnvOffsetClock`), so the timeout is due too. The relay hands the fact to its `deliver` job, the delivery resumes the process (deliveries run before wakeups, and the fact arrived before the deadline), the trial finishes `activated`, and the satisfied await cancels the timeout intent in the same transaction.
3. The second `drain.php` finds the process completed. It plants a surviving copy of the old timeout intent, the kind a restored backup or a duplicated queue leaves behind, and the pass runs it as a stale-safe no-op: the runner re-reads the row under the process lock, sees it is not `suspended`, and changes nothing.

`produce.php --no-activation` sends no activation. A drain without the offset then reports that the trial is still waiting. A drain with the offset fires the timeout, and the trial finishes `timed_out` (`AwaitAll::TIMEOUT_PROCEED`).

Environment: `DDD_EXAMPLE_DB_HOST`, `DDD_EXAMPLE_DB_PORT`, `DDD_EXAMPLE_DB_USER`, `DDD_EXAMPLE_DB_PASSWORD`, `DDD_EXAMPLE_DB_NAME` (default `ddd_example_durable`, created when missing; `--reset` drops it first), `DDD_EXAMPLE_DRIVER` (`pdo` or `mysqli`), `DDD_CLOCK_OFFSET` (seconds or an ISO 8601 duration), `DDD_AUTOLOAD`.

The pdo suite runs this sequence on both drivers (`packages/ddd-core/tests/Pdo/Native/PlainPhpDurableExampleTest.php`).

## The composition

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

$runtime->bus()->handle(new StartTrial(1));          // request
$report = $runtime->drain(maxItems: 200, maxSeconds: 50);   // cron, or a shutdown function
$items = $runtime->operatorView()->toArrays();       // one failure view across relay, delivery, wakeup, process
```

`drain()` never loops or sleeps. A host that wants a worker writes `while (true) { $runtime->drain(); sleep(1); }` itself. Overlapping cron invocations are safe, because claims and leases keep them apart.

`EnvOffsetClock` is a test-only clock from `TangibleDDD\Testing`. A real host passes no clock and gets the system clock.

## A CodeIgniter-style host on MySQLi

A host whose models write through MySQLi rather than PDO implements `IHostConnection` over the handle it already has (CodeIgniter 4: `$db->connID`), so its domain writes and the outbox share one transaction. This is the whole adapter (`MysqliConnection.php`; `DDD_EXAMPLE_DRIVER=mysqli` runs the example on it):

```php
final class MysqliConnection implements IHostConnection {

  private bool $inTransaction = false;

  public function __construct(private readonly \mysqli $db) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // every failure throws mysqli_sql_exception
  }

  public function execute(string $sql, array $params = []): int {
    return (int) $this->run($sql, $params)->affected_rows;
  }

  public function fetchAll(string $sql, array $params = []): array {
    $result = $this->run($sql, $params)->get_result();
    return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
  }

  public function fetchOne(string $sql, array $params = []): ?array {
    return $this->fetchAll($sql, $params)[0] ?? null;
  }

  public function lastInsertId(): string { return (string) $this->db->insert_id; }
  public function begin(): void { $this->db->begin_transaction(); $this->inTransaction = true; }
  public function commit(): void { $this->inTransaction = false; $this->db->commit(); }
  public function rollBack(): void { $this->inTransaction = false; $this->db->rollback(); }
  public function inTransaction(): bool { return $this->inTransaction; }

  public function isDuplicateKey(\Throwable $e): bool {
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
- `isDuplicateKey()` matches error 1062 only, never SQLSTATE 23000. That class also covers foreign-key and NOT NULL violations, and matching it would make an ignition silently disappear.
- Integers bind as integers, so `LIMIT ?` works.
- `inTransaction()` must reflect transactions opened through this adapter. If the host opens one with `$db->transBegin()` outside the adapter, the library cannot see it, so a host that mixes the two must route its own transactions through the adapter or through the runtime's `boundary()`.

If the host's domain writes already go through a PDO, it wraps that PDO in `PdoConnection` instead and needs no adapter.
