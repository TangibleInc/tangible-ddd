<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Process;

use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Infra\IProcessRepository;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Lock\INamedLock;
use TangibleDDD\Runtime\Support\Log;

/**
 * IProcessStore over a consumer's own 0.6 IProcessRepository (register 3.8,
 * R3: cred's repository gains no methods). DEGRADED:
 *
 * - insert_ignited(): the hotfix approach. Under the named lock
 *   `ddd_ign_` + md5(prefix|class|event_id) it re-checks
 *   has_ignition(class, event_id) and inserts; a second delivery returns
 *   AlreadyIgnited and persists nothing. There is no ignition_key column, so
 *   the gate is the lock, not a unique constraint. A contended or failed
 *   lock throws LockNotAcquired and nothing is persisted. The lock is the
 *   given INamedLock, else HostDefaults' INamedLock; with neither, ignition
 *   throws \LogicException (an unguarded check-then-insert would reopen
 *   bug 2).
 * - save()/touch() are NOT version-fenced (no version column): they return
 *   expectedVersion + 1 without checking, and version_of() is tracked per
 *   instance (1 for a row it has not written). The missing fence is logged
 *   once per instance as a warning.
 * - find_waiting_for() returns the ids of find_waiting_for(); find_stranded()
 *   returns [] (the 0.6 interface cannot enumerate rows by status and age).
 *
 * Errors from the repository propagate unchanged; an insert that yields no
 * id throws ProcessStoreFailed (never set_id(0), C27).
 */
final class LegacyProcessStore implements IProcessStore {

  private const IGNITION_LOCK_SECONDS = 5.0;

  /** @var array<int, int> */
  private array $versions = [];

  private bool $warned = false;

  public function __construct(
    private readonly IProcessRepository $repository,
    private readonly IConsumerIdentity $consumer,
    private readonly ?INamedLock $lock = null,
    private readonly ?LoggerInterface $logger = null,
  ) {}

  public function repository(): IProcessRepository {
    return $this->repository;
  }

  public function insert_ignited(LongProcess $p, string $processClass, string $eventId): IgnitionResult {
    $lock = $this->lock ?? HostDefaults::get(INamedLock::class) ?? throw new \LogicException(
      'LegacyProcessStore needs an ' . INamedLock::class . ' to gate #[StartsOn] ignition: pass one, or provide one in HostDefaults.'
    );
    $name = 'ddd_ign_' . md5($this->consumer->prefix() . '|' . $processClass . '|' . $eventId);

    $lock->acquire($name, self::IGNITION_LOCK_SECONDS);
    try {
      if ($this->repository->has_ignition($processClass, $eventId)) {
        return IgnitionResult::AlreadyIgnited;
      }
      $this->insert($p);
      return IgnitionResult::Inserted;
    } finally {
      $lock->release($name);
    }
  }

  public function insert(LongProcess $p): int {
    if ($p->get_id() !== null) {
      throw new ProcessStoreFailed('Process #' . $p->get_id() . ' is already persisted; use save()');
    }
    $id = $this->repository->save($p);
    if ($id <= 0 || $p->get_id() === null) {
      throw new ProcessStoreFailed('Process insert returned no id for ' . get_class($p));
    }
    $this->versions[$id] = 1;
    return $id;
  }

  public function find(int $id): ?LongProcess {
    return $this->repository->find($id);
  }

  public function save(LongProcess $p, int $expectedVersion): int {
    if ($p->get_id() === null) {
      throw new ProcessStoreFailed('Cannot save an unpersisted process; use insert()');
    }
    $this->warnOnce();
    $this->repository->save($p);
    return $this->versions[$p->get_id()] = max($expectedVersion, $this->versions[$p->get_id()] ?? 1) + 1;
  }

  public function touch(int $id, int $expectedVersion): int {
    $this->warnOnce();
    return $this->versions[$id] = max($expectedVersion, $this->versions[$id] ?? 1) + 1;
  }

  public function version_of(int $id): ?int {
    return $this->versions[$id] ?? 1;
  }

  public function find_waiting_for(string $eventClass, ?string $awaitKey = null): array {
    $ids = [];
    foreach ($this->repository->find_waiting_for($eventClass) as $p) {
      if ($p->get_id() !== null) {
        $ids[] = $p->get_id();
      }
    }
    return $ids;
  }

  public function find_stranded(\DateTimeImmutable $now): array {
    return [];
  }

  private function warnOnce(): void {
    if ($this->warned) {
      return;
    }
    $this->warned = true;
    Log::write($this->logger, sprintf(
      '[%s process] LegacyProcessStore over %s saves without version fencing: a holder that lost its lock can overwrite newer state (register 3.8, R3)',
      $this->consumer->prefix(), get_class($this->repository)
    ));
  }
}
