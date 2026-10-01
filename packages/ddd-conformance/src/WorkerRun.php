<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/** What HostFixture::run_worker() observed, one slot per message. */
final class WorkerRun {

  /**
   * @param list<?\Throwable> $errors what each message threw (null = succeeded)
   * @param list<?\Throwable> $leaks  what the message-boundary reset reported after it
   *                                  (a RuntimeLeakDetected, or null when clean)
   */
  public function __construct(
    public readonly array $errors,
    public readonly array $leaks,
  ) {}
}
