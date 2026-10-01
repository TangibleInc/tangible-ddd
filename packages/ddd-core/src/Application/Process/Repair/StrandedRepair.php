<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process\Repair;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Runtime\ConsumerPrefix;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Process\StrandedProcess;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\SystemClock;

/**
 * The shared half of the two stranded-process repair handlers: port
 * resolution and the guards.
 *
 * Ports: the constructor's, else the host's for the command's consumer
 * prefix (HostDefaults::for with a ConsumerPrefix: IProcessStore,
 * IWakeupScheduler), else HostDefaults::get (IProcessLock, IClock,
 * ITransactionBoundary). A missing store, scheduler or lock is a
 * \LogicException naming the port.
 *
 * Guards, in order, all before any write: the process lock is taken with a
 * zero wait (a worker holding it means the process is not stranded); the row
 * is re-read under it; it must be in IProcessStore::find_stranded(now); with
 * an expected version, version_of() must equal it. The writes run in the
 * command's transaction (TransactionalCommandMiddleware), or in the boundary
 * when the handler is called outside one.
 *
 * Lock and commit. Outside a transaction the boundary's run() commits while
 * the lock is still held. Inside the command's transaction the lock is
 * released when the handler returns, BEFORE the outer transaction commits
 * (ITransactionBoundary has no after-commit hook). Both repairs therefore
 * write the row version-fenced first: Fail through its fenced save, Resume
 * through a fenced touch() at the guarded version. A worker that takes the
 * lock in that window and acts on the pre-repair row then loses its own
 * fenced save or touch (on SQL hosts it waits for the repair's row lock and
 * finds the version moved: ConcurrentProcessModification, its wake aborts).
 * Residual: a host whose store does not fence (LegacyProcessStore) keeps
 * the window; the repair's intent is stale-safe (status, step and version
 * checked under the lock), so a duplicate wake is a no-op there too.
 *
 * @internal
 */
abstract class StrandedRepair {

  public function __construct(
    private readonly ?IProcessStore $store = null,
    private readonly ?IWakeupScheduler $wakeups = null,
    private readonly ?IProcessLock $lock = null,
    private readonly ?IClock $clock = null,
    private readonly ?ITransactionBoundary $boundary = null,
  ) {}

  /**
   * Run $repair on the stranded process, under its lock, inside a transaction.
   *
   * @param \Closure(LongProcess, StrandedProcess, int $version, IProcessStore, IWakeupScheduler, \DateTimeImmutable $now): void $repair
   */
  protected function guarded(string $prefix, int $processId, ?int $expectedVersion, \Closure $repair): void {
    $consumer = new ConsumerPrefix($prefix);
    $store = $this->store ?? HostDefaults::for(IProcessStore::class, $consumer) ?? throw new \LogicException(
      "No IProcessStore for consumer \"$prefix\": construct the repair handler with one, or run on a host that provides one."
    );
    $wakeups = $this->wakeups ?? HostDefaults::for(IWakeupScheduler::class, $consumer) ?? throw new \LogicException(
      "No IWakeupScheduler for consumer \"$prefix\": construct the repair handler with one, or run on a host that provides one."
    );
    $lock = $this->lock ?? HostDefaults::get(IProcessLock::class) ?? throw new \LogicException(
      'No ' . IProcessLock::class . ': construct the repair handler with one, or run on a host that provides one.'
    );
    $clock = $this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock();
    $boundary = $this->boundary ?? HostDefaults::get(ITransactionBoundary::class);

    try {
      $handle = $lock->acquire(new LockKey($prefix, '', $processId), 0.0);
    } catch (LockNotAcquired $e) {
      throw new ProcessNotStranded("Process #$processId is not stranded: its lock is held (a worker is running it): " . $e->getMessage(), 0, $e);
    }

    try {
      $now = $clock->now();
      $process = $store->find($processId);
      if ($process === null) {
        throw new ProcessNotStranded("Process #$processId does not exist");
      }
      $stranded = null;
      foreach ($store->find_stranded($now) as $row) {
        if ($row->process_id === $processId) {
          $stranded = $row;
          break;
        }
      }
      if ($stranded === null) {
        throw new ProcessNotStranded(sprintf(
          'Process #%d is not stranded (status %s): it has a live intent, moved recently, or is not running',
          $processId, $process->status()
        ));
      }
      $version = (int) $store->version_of($processId);
      if ($expectedVersion !== null && $version !== $expectedVersion) {
        throw new ProcessNotStranded("Process #$processId moved on: version $version, expected $expectedVersion");
      }

      $work = static fn () => $repair($process, $stranded, $version, $store, $wakeups, $now);
      if ($boundary === null || $boundary->is_active()) {
        $work();
      } else {
        $boundary->run($work);
      }
    } finally {
      $lock->release($handle);
    }
  }
}
