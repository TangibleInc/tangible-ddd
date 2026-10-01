<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Defaults\Pdo\Internal\ProcessCodec;
use TangibleDDD\Defaults\Pdo\Internal\Utc;
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
 * IProcessStore on `{prefix}ddd_processes` (register 3.8, X7, 5.2, 5.3,
 * CR-5).
 *
 * - insertIgnited(): the #[StartsOn] path only. INSERT with
 *   ignition_key = uuid5(event_id, process_class) under
 *   UNIQUE (process_class, ignition_key); a duplicate-key error (MySQL 1062
 *   only, IHostConnection::isDuplicateKey) answers AlreadyIgnited and the
 *   loser is not persisted (bug 2). A duplicate-key error rolls back only
 *   the statement, so the caller's transaction stays usable. Any other
 *   error is ProcessStoreFailed, never AlreadyIgnited.
 * - insert(): manual start; ignition_key NULL, never deduped (even with an
 *   ignited_by_event_id stamped inside a fact drain).
 * - Both set the id and start at version 1.
 * - save() / touch(): `WHERE id = ? AND version = ?`, version + 1; 0 rows
 *   is ConcurrentProcessModification for a known id and ProcessStoreFailed
 *   for an unknown one.
 * - find(): null for an unknown id. A row that cannot be decoded (class
 *   gone, corrupt JSON) is quarantined, status `failed` + quarantine_reason
 *   (and a version bump, so a stale holder cannot overwrite it), and
 *   QuarantinedProcess is thrown; the worker continues (R5).
 * - findWaitingFor(): ids of `suspended` rows whose waiting_for is the
 *   class or one of its parents/interfaces (is_a, D2). $awaitKey is reserved
 *   for keyed awaits (D3, wave 4) and ignored.
 * - findStranded(): `running`/`scheduled` rows not updated for the
 *   threshold (default 15 min) with no row in `{prefix}ddd_jobs`.
 *
 * Every write runs on the host connection (inside the runner's transaction
 * when one is open). Times are UTC from IClock.
 */
final class PdoProcessStore implements IProcessStore {

  private readonly string $table;
  private readonly string $jobs;
  private readonly IClock $clock;
  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly IHostConnection $db,
    string $tablePrefix = '',
    ?IClock $clock = null,
    private readonly int $strandedAfterSeconds = 900,
    ?LoggerInterface $logger = null,
  ) {
    $tables = new PrefixedTableNames($tablePrefix);
    $this->table = $tables->table('ddd_processes');
    $this->jobs = $tables->table('ddd_jobs');
    $this->clock = $clock ?? new SystemClock();
    $this->logger = $logger ?? new NullLogger();
  }

  public function insertIgnited(LongProcess $p, string $processClass, string $eventId): IgnitionResult {
    try {
      $this->persistNew($p, IgnitionKey::for($eventId, $processClass), $processClass);
      return IgnitionResult::Inserted;
    } catch (ProcessStoreFailed $e) {
      if ($e->getPrevious() !== null && $this->db->isDuplicateKey($e->getPrevious())) {
        $p->set_id(null);
        return IgnitionResult::AlreadyIgnited;
      }
      throw $e;
    }
  }

  public function insert(LongProcess $p): int {
    return $this->persistNew($p, null, get_class($p));
  }

  public function find(int $id): ?LongProcess {
    $row = $this->db->fetchOne("SELECT * FROM `{$this->table}` WHERE id = ?", [$id]);
    if ($row === null) {
      return null;
    }
    try {
      return ProcessCodec::decode($row);
    } catch (\UnexpectedValueException $e) {
      $reason = $e->getMessage();
      $this->db->execute(
        "UPDATE `{$this->table}` SET status = 'failed', quarantine_reason = ?, version = version + 1, updated_at = ? WHERE id = ?",
        [$reason, Utc::toDb($this->clock->now()), $id]
      );
      $this->logger->error("[ddd process] #$id quarantined: $reason");
      throw new QuarantinedProcess("Process #$id quarantined: $reason", 0, $e);
    }
  }

  public function save(LongProcess $p, int $expectedVersion): int {
    $id = $p->get_id();
    if ($id === null) {
      throw new ProcessStoreFailed('Cannot save a process that was never inserted');
    }
    try {
      $columns = ProcessCodec::encode($p) + ['updated_at' => Utc::toDb($this->clock->now())];
      unset($columns['process_class']);
      $set = implode(', ', array_map(static fn (string $c) => "`$c` = ?", array_keys($columns)));
      $n = $this->db->execute(
        "UPDATE `{$this->table}` SET $set, version = version + 1 WHERE id = ? AND version = ?",
        [...array_values($columns), $id, $expectedVersion]
      );
    } catch (\Throwable $e) {
      throw new ProcessStoreFailed("Saving process #$id failed: " . $e->getMessage(), 0, $e);
    }
    return $this->fenced($n, $id, $expectedVersion, 'save');
  }

  public function touch(int $id, int $expectedVersion): int {
    try {
      $n = $this->db->execute(
        "UPDATE `{$this->table}` SET version = version + 1, updated_at = ? WHERE id = ? AND version = ?",
        [Utc::toDb($this->clock->now()), $id, $expectedVersion]
      );
    } catch (\Throwable $e) {
      throw new ProcessStoreFailed("Touching process #$id failed: " . $e->getMessage(), 0, $e);
    }
    return $this->fenced($n, $id, $expectedVersion, 'touch');
  }

  public function versionOf(int $id): ?int {
    $version = $this->db->fetchOne("SELECT version FROM `{$this->table}` WHERE id = ?", [$id])['version'] ?? null;
    return $version === null ? null : (int) $version;
  }

  public function findWaitingFor(string $eventClass, ?string $awaitKey = null): array {
    $names = [$eventClass];
    if (class_exists($eventClass) || interface_exists($eventClass)) {
      $names = array_values(array_unique([$eventClass, ...array_values(class_parents($eventClass) ?: []), ...array_values(class_implements($eventClass) ?: [])]));
    }
    $in = implode(', ', array_fill(0, count($names), '?'));
    $rows = $this->db->fetchAll(
      "SELECT id FROM `{$this->table}` WHERE status = 'suspended' AND waiting_for IN ($in) ORDER BY id",
      $names
    );
    return array_map(static fn (array $r) => (int) $r['id'], $rows);
  }

  public function findStranded(\DateTimeImmutable $now): array {
    $cutoff = $now->setTimezone(new \DateTimeZone('UTC'))->modify("-{$this->strandedAfterSeconds} seconds");
    $rows = $this->db->fetchAll(
      "SELECT p.id, p.process_class, p.status, p.step_index, p.updated_at FROM `{$this->table}` p
       WHERE p.status IN ('running', 'scheduled') AND p.updated_at <= ?
         AND NOT EXISTS (SELECT 1 FROM `{$this->jobs}` j WHERE j.process_id = p.id)
       ORDER BY p.id",
      [Utc::toDb($cutoff)]
    );
    return array_map(static fn (array $r) => new StrandedProcess(
      (int) $r['id'],
      (string) $r['process_class'],
      (string) $r['status'],
      (int) $r['step_index'],
      Utc::fromDb((string) $r['updated_at']),
    ), $rows);
  }

  private function persistNew(LongProcess $p, ?string $ignitionKey, string $class): int {
    if ($p->get_id() !== null) {
      throw new ProcessStoreFailed('Process #' . $p->get_id() . ' is already persisted; use save()');
    }
    $now = Utc::toDb($this->clock->now());
    try {
      $columns = ['process_class' => $class] + ProcessCodec::encode($p) + [
        'ignition_key' => $ignitionKey,
        'version' => 1,
        'created_at' => $now,
        'updated_at' => $now,
      ];
      $names = implode(', ', array_map(static fn (string $c) => "`$c`", array_keys($columns)));
      $marks = implode(', ', array_fill(0, count($columns), '?'));
      $this->db->execute("INSERT INTO `{$this->table}` ($names) VALUES ($marks)", array_values($columns));
      $id = (int) $this->db->lastInsertId();
    } catch (\Throwable $e) {
      throw new ProcessStoreFailed("Inserting $class failed: " . $e->getMessage(), 0, $e);
    }
    if ($id <= 0) {
      throw new ProcessStoreFailed("Inserting $class returned no id"); // never set_id(0) (C27)
    }
    $p->set_id($id);
    return $id;
  }

  private function fenced(int $affected, int $id, int $expectedVersion, string $what): int {
    if ($affected === 1) {
      return $expectedVersion + 1;
    }
    $current = $this->versionOf($id);
    if ($current === null) {
      throw new ProcessStoreFailed("Cannot $what process #$id: no such row");
    }
    throw new ConcurrentProcessModification(sprintf('Process #%d is at version %d, expected %d (%s refused)', $id, $current, $expectedVersion, $what));
  }
}
