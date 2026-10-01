<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance\Support;

/** Shared by the CountingProcessLock of every worker of one sf fixture. */
final class LockCounter {

  /** Successful backend acquisitions, all workers (re-entrant ones never reach the backend). */
  public int $acquisitions = 0;

  /** Inside WebRequests::in_web_request(). */
  public bool $inWebRequest = false;

  /** Lock attempts made inside a web request (each was refused). */
  public int $webAttempts = 0;
}
