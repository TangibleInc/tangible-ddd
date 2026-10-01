<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Infra\IProcessRepository;
use TangibleDDD\Runtime\Process\IgnitionResult;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Process\ProcessStoreFailed;

/**
 * IProcessStore over a 0.6 IProcessRepository, on the 0.6 schema (the
 * wave-2 transitional store, kept in wave 3 as the wp bridge for what the
 * schema v8 WpdbProcessStore cannot serve): a consumer-authored repository
 * (e.g. cred's, whose table the framework does not own) and the framework
 * ProcessRepository of a consumer whose v8 migration has not run yet.
 * WpHostPortFactory picks between the two. (Core's LegacyProcessStore is
 * the portable equivalent; this one keeps the wave-1 ignition lock name a
 * 0.6.7 copy also takes.)
 *
 * - insertIgnited(): the wave-1 schema-free ignition fix. Under the named
 *   lock `ddd_ign_` + md5(prefix|class|event_id) it re-checks
 *   has_ignition(class, event_id) and inserts; a second delivery returns
 *   AlreadyIgnited. No ignition_key column yet (schema v8, wave 3).
 *   A contended or failed ignition lock throws LockNotAcquired and nothing is
 *   persisted.
 * - NO version fencing: there is no version column before schema v8.
 *   save()/touch() return expectedVersion + 1 without checking, versionOf()
 *   is tracked per instance (1 for a row it has not saved).
 * - findWaitingFor() returns ids (E F14); the runner then find()s each
 *   candidate, so a resume reads a waiting row twice where 0.6 read it once.
 *   Deliberately not cached: a cached row could be served stale to a later
 *   wake in a long-lived Action Scheduler worker.
 * - findStranded() returns []: without an intent table there is nothing to
 *   compare against (wave 3).
 *
 * Errors from the repository propagate unchanged; a repository save that
 * yields no id throws ProcessStoreFailed (never set_id(0), C27).
 */
final class WpRepositoryProcessStore implements IProcessStore {

  /** @var array<int, int> */
  private array $versions = [];

  public function __construct(
    private readonly IProcessRepository $repository,
    private readonly IConsumerIdentity $consumer,
  ) {}

  public function repository(): IProcessRepository {
    return $this->repository;
  }

  public function insertIgnited(LongProcess $p, string $processClass, string $eventId): IgnitionResult {
    $name = 'ddd_ign_' . md5($this->consumer->prefix() . '|' . $processClass . '|' . $eventId);
    WpNamedLock::acquire($name, 5);

    try {
      if ($this->repository->has_ignition($processClass, $eventId)) {
        return IgnitionResult::AlreadyIgnited;
      }
      $this->insert($p);
      return IgnitionResult::Inserted;
    } finally {
      WpNamedLock::release($name);
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
    $this->repository->save($p);
    return $this->versions[$p->get_id()] = $expectedVersion + 1;
  }

  public function touch(int $id, int $expectedVersion): int {
    return $this->versions[$id] = $expectedVersion + 1;
  }

  public function versionOf(int $id): ?int {
    return $this->versions[$id] ?? 1;
  }

  public function findWaitingFor(string $eventClass, ?string $awaitKey = null): array {
    $ids = [];
    foreach ($this->repository->find_waiting_for($eventClass) as $p) {
      $id = $p->get_id();
      if ($id !== null) {
        $ids[] = $id;
      }
    }
    return $ids;
  }

  public function findStranded(\DateTimeImmutable $now): array {
    return [];
  }
}
