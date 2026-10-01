<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance\Support;

use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockHandle;
use TangibleDDD\Runtime\Lock\LockKey;

/**
 * Pass-through raw IProcessLock (under the core ReentrantProcessLock, over
 * PostgresAdvisoryProcessLock) that counts SUCCESSFUL backend acquisitions
 * into a counter shared by every worker of the fixture
 * (ProcessHost::processLockAcquisitions()).
 *
 * While the counter says a web request is running (WebRequests), every
 * acquire goes to $web instead: a PostgresAdvisoryProcessLock over a pooled
 * DSN with PoolerPolicy::Refuse, so a lock attempt in a web request throws
 * PooledConnectionRefused, as it would on the pooled web connection
 * (register 5.2, `process.start-from-web`).
 */
final class CountingProcessLock implements IProcessLock {

  public function __construct(
    private readonly IProcessLock $inner,
    private readonly LockCounter $counter,
    private readonly IProcessLock $web,
  ) {}

  public function acquire(LockKey $k, float $timeoutSeconds): LockHandle {
    if ($this->counter->inWebRequest) {
      $this->counter->webAttempts++;
      return $this->web->acquire($k, $timeoutSeconds);
    }
    $handle = $this->inner->acquire($k, $timeoutSeconds);
    $this->counter->acquisitions++;
    return $handle;
  }

  public function release(LockHandle $h): void {
    $this->inner->release($h);
  }

  public function heldCount(): int {
    return $this->inner->heldCount();
  }

  public function forceReleaseAll(): int {
    return $this->inner->forceReleaseAll();
  }
}
