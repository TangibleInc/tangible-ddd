<?php

/**
 * tangible/ddd-core durable in plain PHP: the shared part of produce.php and
 * drain.php. Everything here is HOST code a raw-PHP application writes
 * itself: it opens its own database connection from environment variables,
 * applies schema/mysql8 with its own tooling, declares its domain, and calls
 * DurableRuntime::compose() once. The library never opens a connection,
 * creates a database or runs a migration.
 *
 * Environment (defaults match the extraction harness's MySQL 8 container):
 *   DDD_EXAMPLE_DB_HOST      127.0.0.1
 *   DDD_EXAMPLE_DB_PORT      33306
 *   DDD_EXAMPLE_DB_USER      root
 *   DDD_EXAMPLE_DB_PASSWORD  ddd
 *   DDD_EXAMPLE_DB_NAME      ddd_example_durable   (created when missing)
 *   DDD_EXAMPLE_DRIVER       pdo | mysqli          (default pdo)
 *   DDD_CLOCK_OFFSET         seconds or ISO 8601 duration added to "now"
 *                            (EnvOffsetClock), so a later drain sees a
 *                            timeout as due without waiting for it
 *   DDD_AUTOLOAD             path to a Composer autoload.php
 */

declare(strict_types=1);

namespace Example\PlainPhpDurable;

$autoload = getenv('DDD_AUTOLOAD') ?: null;
foreach ([$autoload, __DIR__ . '/vendor/autoload.php', dirname(__DIR__, 2) . '/vendor/autoload.php'] as $candidate) {
  if ($candidate !== null && is_file($candidate)) {
    require_once $candidate;
    break;
  }
}
if (!class_exists(\TangibleDDD\Defaults\Pdo\DurableRuntime::class)) {
  fwrite(STDERR, "No Composer autoloader with tangible/ddd-core found (set DDD_AUTOLOAD).\n");
  exit(1);
}
require_once __DIR__ . '/MysqliConnection.php';

use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Defaults\Pdo\DurableRuntime;
use TangibleDDD\Defaults\Pdo\IHostConnection;
use TangibleDDD\Defaults\Pdo\PdoConnection;
use TangibleDDD\Defaults\Pdo\SchemaCheck;
use TangibleDDD\Defaults\Pdo\SchemaSql;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IntegrationEvent;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Testing\EnvOffsetClock;

// ── The consumer ───────────────────────────────────────────────────────────

/** Every DDD table, lock and fact name derives from this prefix; the namespace routes ->send(). */
final class TrialConsumer implements IConsumerIdentity {
  public const PREFIX = 'trialdemo';
  public function prefix(): string { return self::PREFIX; }
  public function version(): string { return '1.0.0'; }
}

// ── The domain: a trial that must be activated within a minute ─────────────

/** The integration fact the process awaits. */
final class TrialActivated extends IntegrationEvent {
  public function __construct(public readonly int $trial_id = 0) {}
}

final class TrialWasActivated extends DomainEvent implements IAnnouncesIntegration {
  public function __construct(public readonly int $trial_id) {}
  public function payload(): array { return ['trial_id' => $this->trial_id]; }
  public function to_integration(): TrialActivated { return new TrialActivated($this->trial_id); }
}

/**
 * The web request's command: records the trial and starts its process in
 * the SAME transaction (the runtime's deferred start: process row +
 * Continue intent; the first step runs in a drain).
 */
final class StartTrial extends SelfHandlingCommand implements ITransactionalCommand {
  public function __construct(public readonly int $trial_id, public readonly bool $activate = true) {}

  protected function handle(IHostConnection $db, ProcessRunner $runner): int {
    $db->execute('INSERT INTO trialdemo_trials (id, status) VALUES (?, ?)', [$this->trial_id, 'pending']);
    $process = new TrialProcess($this->trial_id, $this->activate);
    $runner->start($process);
    $db->execute('UPDATE trialdemo_trials SET process_id = ? WHERE id = ?', [(int) $process->get_id(), $this->trial_id]);
    return (int) $process->get_id();
  }
}

/** Sent by the process; announces TrialActivated through the outbox. */
final class ActivateTrial extends SelfHandlingCommand implements ITransactionalCommand {
  public function __construct(public readonly int $trial_id) {}

  protected function handle(IHostConnection $db): void {
    $db->execute('UPDATE trialdemo_trials SET status = ? WHERE id = ?', ['active', $this->trial_id]);
    $this->event(new TrialWasActivated($this->trial_id));
  }
}

final class FinishTrial extends SelfHandlingCommand implements ITransactionalCommand {
  public function __construct(public readonly int $trial_id, public readonly string $outcome) {}

  protected function handle(IHostConnection $db): void {
    $db->execute('UPDATE trialdemo_trials SET outcome = ?, finished_count = finished_count + 1 WHERE id = ?', [$this->outcome, $this->trial_id]);
  }
}

/**
 * Step 0 asks for activation and waits up to TIMEOUT_SECONDS for
 * TrialActivated (the await and its timeout intent commit BEFORE the
 * command is dispatched). Step 1 finishes the trial as `activated`, or as
 * `timed_out` when the timeout fired first (TIMEOUT_PROCEED).
 */
#[Awaits(TrialActivated::class)]
final class TrialProcess extends LongProcess {
  public const TIMEOUT_SECONDS = 60;

  public function __construct(public readonly int $trial_id = 0, public readonly bool $activate = true) {
    parent::__construct(null);
  }

  protected function request_activation(): Result {
    return new Result(
      commands: $this->activate ? [new ActivateTrial($this->trial_id)] : [],
      await: new AwaitAll(TrialActivated::class, [$this->trial_id], [self::class, 'key'], self::TIMEOUT_SECONDS, AwaitAll::TIMEOUT_PROCEED),
    );
  }

  protected function finish(mixed $payload, AwaitAll $activation): Result {
    return new Result(commands: [new FinishTrial($this->trial_id, $activation->missing() === [] ? 'activated' : 'timed_out')]);
  }

  public static function key(TrialActivated $e): int {
    return $e->trial_id;
  }
}

// ── Host plumbing: connection, schema, composition ─────────────────────────

/** The host's own connection, from env vars; the database is created when missing ($reset drops it first). */
function connect(bool $reset = false): IHostConnection {
  $env = static fn (string $name, string $default): string => (string) (getenv($name) ?: $default);
  $host = $env('DDD_EXAMPLE_DB_HOST', '127.0.0.1');
  $port = (int) $env('DDD_EXAMPLE_DB_PORT', '33306');
  $user = $env('DDD_EXAMPLE_DB_USER', 'root');
  $password = $env('DDD_EXAMPLE_DB_PASSWORD', 'ddd');
  $name = $env('DDD_EXAMPLE_DB_NAME', 'ddd_example_durable');
  if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    throw new \InvalidArgumentException("DDD_EXAMPLE_DB_NAME '$name' must match [A-Za-z0-9_]+");
  }
  $create = ($reset ? "DROP DATABASE IF EXISTS `$name`; " : '') . "CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin";

  if ($env('DDD_EXAMPLE_DRIVER', 'pdo') === 'mysqli') {
    $mysqli = new \mysqli($host, $user, $password, null, $port);
    $mysqli->multi_query($create);
    while ($mysqli->more_results() && $mysqli->next_result()) {
    }
    $mysqli->select_db($name);
    $mysqli->set_charset('utf8mb4');
    return new MysqliConnection($mysqli);
  }

  $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC];
  (new \PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $password, $options))->exec($create);
  return new PdoConnection(new \PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $password, $options));
}

/** schema/mysql8 with the consumer's table prefix, plus the host's own table. Idempotent. */
function applySchema(IHostConnection $db): void {
  foreach (SchemaSql::statements(TrialConsumer::PREFIX . '_') as $statement) {
    $db->execute($statement);
  }
  $db->execute(
    'CREATE TABLE IF NOT EXISTS trialdemo_trials (
       id INT NOT NULL PRIMARY KEY, status VARCHAR(16) NOT NULL, outcome VARCHAR(16) NULL,
       process_id BIGINT UNSIGNED NULL, finished_count INT NOT NULL DEFAULT 0,
       timeout_key VARCHAR(191) NULL, timeout_due_at DATETIME(6) NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'
  );
  (new SchemaCheck($db, TrialConsumer::PREFIX . '_'))->assert();
}

/** The one composition call. */
function runtime(IHostConnection $db): DurableRuntime {
  return DurableRuntime::compose(
    $db,
    new TrialConsumer(),
    [],                       // handlers: every command here is self-handling
    [],                       // integration listeners: none
    [TrialProcess::class],    // #[Awaits(TrialActivated)] → resume subscription
    new EnvOffsetClock(),     // DDD_CLOCK_OFFSET; a real host passes nothing (system clock)
  );
}

// ── Script support ─────────────────────────────────────────────────────────

/** @return array<string, mixed>|null the newest trial joined with its process row */
function latestTrial(IHostConnection $db): ?array {
  return $db->fetchOne(
    'SELECT t.*, p.status AS process_status, p.version AS process_version, p.updated_at AS process_updated_at, p.step_index
       FROM trialdemo_trials t LEFT JOIN trialdemo_ddd_processes p ON p.id = t.process_id
      ORDER BY t.id DESC LIMIT 1'
  );
}

final class Checks {
  /** @var list<string> */
  private array $failures = [];

  public function check(bool $ok, string $what): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$ok) {
      $this->failures[] = $what;
    }
  }

  public function finish(): never {
    if ($this->failures !== []) {
      fwrite(STDERR, count($this->failures) . " check(s) failed\n");
      exit(1);
    }
    echo "ok\n";
    exit(0);
  }
}
