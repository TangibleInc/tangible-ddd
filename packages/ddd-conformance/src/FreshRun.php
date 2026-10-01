<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/** What one fresh php process did (FreshProcesses), as the parent test can see it. */
final class FreshRun {

  /**
   * @param bool $died           the process was killed at the requested point (no shutdown handlers ran)
   * @param ?int $process_id     the process a start created
   * @param list<string> $relayed event ids the process's relay step accepted
   * @param int $delivered       facts its delivery stage delivered
   * @param list<string> $errors what failed in it (drain stage errors, subscriber failures, an uncaught throwable)
   */
  public function __construct(
    public readonly bool $died = false,
    public readonly ?int $process_id = null,
    public readonly array $relayed = [],
    public readonly int $delivered = 0,
    public readonly array $errors = [],
  ) {}
}
