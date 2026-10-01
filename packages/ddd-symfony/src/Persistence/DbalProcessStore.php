<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\Process\ConcurrentProcessModification;
use TangibleDDD\Runtime\Process\IgnitionKey;
use TangibleDDD\Runtime\Process\IgnitionResult;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Process\ProcessStoreFailed;
use TangibleDDD\Runtime\Process\QuarantinedProcess;
use TangibleDDD\Runtime\Process\StrandedProcess;
use TangibleDDD\Runtime\SystemClock;

/**
 * IProcessStore on Postgres 16 through DBAL 4 (register 3.8, X7, 5.2, 5.3;
 * CR-5 touch/versionOf).
 *
 * - insertIgnited(): the #[StartsOn] path only. One statement,
 *   `INSERT ... ON CONFLICT (process_class, ignition_key) DO NOTHING
 *   RETURNING id`, with ignition_key = uuid5(event_id, process_class).
 *   No row back = AlreadyIgnited: the loser is not persisted and its id stays
 *   null (bug 2). A concurrent inserter of the same key waits for the first
 *   one's transaction and then sees the conflict, so the gate holds across
 *   workers; it does not abort an open transaction.
 * - insert(): manual start, ignition_key NULL, never deduped.
 * - find(): rebuilds the process with ProcessRowCodec (the WordPress JSON
 *   shapes). A row that cannot be rebuilt is quarantined: status `failed`,
 *   quarantine_reason set, version + 1, then QuarantinedProcess (R5). An
 *   already-quarantined row throws again without another write.
 * - save() / touch(): `UPDATE ... SET version = version + 1 WHERE id = ? AND
 *   version = ?`; 0 rows on an existing id = ConcurrentProcessModification,
 *   on a missing id = ProcessStoreFailed.
 * - Every insert and save rewrites the process's ddd_process_waits rows in
 *   the same transaction (the caller's when one is open, else its own):
 *   one row (waiting_for or the mechanism's event_class, await key '') while
 *   the status is `suspended`, none otherwise.
 * - findWaitingFor(): ids of `suspended` processes whose wait row names the
 *   class, one of its parents or one of its interfaces (marker awaits, D2);
 *   a non-null $awaitKey narrows to that key (D3, wave 4).
 * - findStranded(): `running`/`scheduled` rows whose updated_at is at or
 *   before now - threshold (default 900 s) and that have no row in
 *   ddd_wakeups (no live intent).
 *
 * updated_at comes from the IClock on every write, so the stranded
 * threshold follows the same clock as the wakeups. Every storage failure
 * throws ProcessStoreFailed with the driver error as previous (C27).
 */
final class DbalProcessStore implements IProcessStore {

  private readonly string $processes;
  private readonly string $waits;
  private readonly string $wakeups;
  private readonly IClock $clock;

  public function __construct(
    private readonly Connection $connection,
    ?IClock $clock = null,
    string $tablePrefix = '',
    private readonly int $strandedAfterSeconds = 900,
  ) {
    $tables = new PrefixedTableNames($tablePrefix);
    $this->processes = $tables->table('ddd_processes');
    $this->waits = $tables->table('ddd_process_waits');
    $this->wakeups = $tables->table('ddd_wakeups');
    $this->clock = $clock ?? new SystemClock();
  }

  public function connection(): Connection {
    return $this->connection;
  }

  public function insertIgnited(LongProcess $p, string $processClass, string $eventId): IgnitionResult {
    $key = IgnitionKey::for($eventId, $processClass);
    $id = $this->persistNew($p, $processClass, $key);
    return $id === null ? IgnitionResult::AlreadyIgnited : IgnitionResult::Inserted;
  }

  public function insert(LongProcess $p): int {
    return (int) $this->persistNew($p, get_class($p), null);
  }

  public function find(int $id): ?LongProcess {
    $row = $this->guard(fn () => $this->connection->fetchAssociative("SELECT * FROM {$this->processes} WHERE id = ?", [$id], [ParameterType::INTEGER]), "find process #$id");
    if ($row === false) {
      return null;
    }
    if ($row['quarantine_reason'] !== null) {
      throw new QuarantinedProcess("Process #$id is quarantined: {$row['quarantine_reason']}");
    }

    try {
      return ProcessRowCodec::decode($row);
    } catch (\UnexpectedValueException $e) {
      $reason = $e->getMessage();
      $this->guard(fn () => $this->connection->executeStatement(
        "UPDATE {$this->processes} SET status = 'failed', quarantine_reason = ?, version = version + 1, updated_at = ?
          WHERE id = ? AND quarantine_reason IS NULL",
        [$reason, Time::toDb($this->clock->now()), $id],
        [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]
      ), "quarantine process #$id");
      throw new QuarantinedProcess("Process #$id quarantined: $reason", 0, $e);
    }
  }

  public function save(LongProcess $p, int $expectedVersion): int {
    $id = $p->get_id();
    if ($id === null) {
      throw new ProcessStoreFailed('Cannot save a process that was never inserted (no id)');
    }
    $columns = $this->encode($p);

    return $this->atomically(function () use ($p, $id, $expectedVersion, $columns): int {
      $sets = implode(', ', array_map(static fn (string $c) => "$c = :$c", array_keys($columns)));
      $version = $this->guard(fn () => $this->connection->fetchOne(
        "UPDATE {$this->processes} SET $sets, version = version + 1, updated_at = :updated_at
          WHERE id = :id AND version = :expected RETURNING version",
        $columns + ['updated_at' => Time::toDb($this->clock->now()), 'id' => $id, 'expected' => $expectedVersion],
        ['id' => ParameterType::INTEGER, 'expected' => ParameterType::INTEGER, 'step_index' => ParameterType::INTEGER]
      ), "save process #$id");
      if ($version === false) {
        $this->failFence($id, $expectedVersion, 'save');
      }
      $this->writeWaits($p, $id);
      return (int) $version;
    });
  }

  public function touch(int $id, int $expectedVersion): int {
    $version = $this->guard(fn () => $this->connection->fetchOne(
      "UPDATE {$this->processes} SET version = version + 1, updated_at = ? WHERE id = ? AND version = ? RETURNING version",
      [Time::toDb($this->clock->now()), $id, $expectedVersion],
      [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER]
    ), "touch process #$id");
    if ($version === false) {
      $this->failFence($id, $expectedVersion, 'touch');
    }
    return (int) $version;
  }

  public function versionOf(int $id): ?int {
    $v = $this->guard(fn () => $this->connection->fetchOne("SELECT version FROM {$this->processes} WHERE id = ?", [$id], [ParameterType::INTEGER]), "read version of process #$id");
    return $v === false ? null : (int) $v;
  }

  public function findWaitingFor(string $eventClass, ?string $awaitKey = null): array {
    $classes = [$eventClass];
    if (class_exists($eventClass) || interface_exists($eventClass)) {
      $classes = array_values(array_unique(array_merge($classes, array_values(class_parents($eventClass) ?: []), array_values(class_implements($eventClass) ?: []))));
    }

    $keySql = $awaitKey === null ? '' : 'AND w.await_key = :key';
    $params = ['classes' => $classes];
    $types = ['classes' => ArrayParameterType::STRING];
    if ($awaitKey !== null) {
      $params['key'] = $awaitKey;
    }
    $ids = $this->guard(fn () => $this->connection->fetchFirstColumn(
      "SELECT DISTINCT w.process_id FROM {$this->waits} w
         JOIN {$this->processes} p ON p.id = w.process_id
        WHERE w.event_class IN (:classes) $keySql AND p.status = 'suspended'
        ORDER BY w.process_id",
      $params,
      $types
    ), "find processes waiting for $eventClass");
    return array_map('intval', $ids);
  }

  public function findStranded(\DateTimeImmutable $now): array {
    $cutoff = $now->modify("-{$this->strandedAfterSeconds} seconds");
    $rows = $this->guard(fn () => $this->connection->fetchAllAssociative(
      "SELECT p.id, p.process_class, p.status, p.step_index, p.updated_at FROM {$this->processes} p
        WHERE p.status IN ('running', 'scheduled')
          AND p.updated_at <= ?
          AND NOT EXISTS (SELECT 1 FROM {$this->wakeups} w WHERE w.process_id = p.id)
        ORDER BY p.updated_at, p.id",
      [Time::toDb($cutoff)]
    ), 'scan for stranded processes');

    return array_map(static fn (array $r) => new StrandedProcess(
      (int) $r['id'], (string) $r['process_class'], (string) $r['status'], (int) $r['step_index'], Time::fromDb((string) $r['updated_at'])
    ), $rows);
  }

  // ── internals ──────────────────────────────────────────────────────────

  /** @return ?int the new id, null on an ignition conflict */
  private function persistNew(LongProcess $p, string $processClass, ?string $ignitionKey): ?int {
    if ($p->get_id() !== null) {
      throw new ProcessStoreFailed('Process #' . $p->get_id() . ' is already persisted; use save()');
    }
    $columns = ['process_class' => $processClass] + $this->encode($p);
    $now = Time::toDb($this->clock->now());
    $columns += ['ignition_key' => $ignitionKey, 'created_at' => $now, 'updated_at' => $now];

    return $this->atomically(function () use ($p, $columns, $ignitionKey): ?int {
      $names = implode(', ', array_keys($columns));
      $values = implode(', ', array_map(static fn (string $c) => ":$c", array_keys($columns)));
      $conflict = $ignitionKey === null ? '' : 'ON CONFLICT (process_class, ignition_key) DO NOTHING';

      $id = $this->guard(fn () => $this->connection->fetchOne(
        "INSERT INTO {$this->processes} ($names, version) VALUES ($values, 1) $conflict RETURNING id",
        $columns,
        ['step_index' => ParameterType::INTEGER]
      ), 'insert process ' . $columns['process_class']);
      if ($id === false) {
        return null;
      }
      $p->set_id((int) $id);
      $this->writeWaits($p, (int) $id);
      return (int) $id;
    });
  }

  /** @return array<string, mixed> */
  private function encode(LongProcess $p): array {
    try {
      $columns = ProcessRowCodec::encode($p);
    } catch (\Throwable $e) {
      throw new ProcessStoreFailed('Cannot encode process ' . get_class($p) . ': ' . $e->getMessage(), 0, $e);
    }
    unset($columns['process_class']);
    return $columns;
  }

  private function writeWaits(LongProcess $p, int $id): void {
    $this->guard(fn () => $this->connection->executeStatement("DELETE FROM {$this->waits} WHERE process_id = ?", [$id], [ParameterType::INTEGER]), "clear waits of process #$id");
    if ($p->status() !== 'suspended') {
      return;
    }
    $class = $p->waiting_for() ?? $p->await_mechanism()?->event_class();
    if ($class === null) {
      return;
    }
    $this->guard(fn () => $this->connection->executeStatement(
      "INSERT INTO {$this->waits} (process_id, event_class, await_key, step_index) VALUES (?, ?, '', ?)",
      [$id, $class, $p->current_step_index()],
      [ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER]
    ), "write waits of process #$id");
  }

  private function failFence(int $id, int $expectedVersion, string $op): never {
    $current = $this->versionOf($id);
    if ($current === null) {
      throw new ProcessStoreFailed("Cannot $op process #$id: no such row");
    }
    throw new ConcurrentProcessModification(sprintf('Process #%d is at version %d, expected %d (%s)', $id, $current, $expectedVersion, $op));
  }

  /**
   * @template T
   * @param callable():T $work
   * @return T
   */
  private function atomically(callable $work): mixed {
    if ($this->connection->isTransactionActive()) {
      return $work();
    }
    try {
      return $this->connection->transactional(static fn () => $work());
    } catch (ProcessStoreFailed|ConcurrentProcessModification $e) {
      throw $e;
    } catch (\Throwable $e) {
      throw new ProcessStoreFailed('Process store transaction failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * @template T
   * @param callable():T $fn
   * @return T
   */
  private function guard(callable $fn, string $what): mixed {
    try {
      return $fn();
    } catch (\Doctrine\DBAL\Exception $e) {
      throw new ProcessStoreFailed("Cannot $what: " . $e->getMessage(), 0, $e);
    }
  }
}
