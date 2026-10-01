<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance\Support;

/** One worker.no-leak message: a closure run by WorkerTaskHandler inside a real Messenger Worker. */
final class WorkerTask {

  /** @param \Closure(): void $work */
  public function __construct(
    public readonly int $slot,
    public readonly \Closure $work,
  ) {}
}
