<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process;

/**
 * What one fact did to the suspended processes (ProcessRunner::
 * resume_with_outcome(), D3). Process ids per outcome:
 *
 * - resumed: the await was satisfied and the next step ran;
 * - accumulated: a partial arrival (AwaitAll) was recorded;
 * - cancelled: a cancellation branch (ICancellingAwait) compensated it.
 *
 * is_unheard(): no await took the fact: no process waits for it, or it is a
 * duplicate, or a register-then-check precheck already stood in for it. A
 * host's "fact delivered unheard" alarm can read this instead of guessing.
 */
final class ResumeReport {

  /**
   * @param list<int> $resumed
   * @param list<int> $accumulated
   * @param list<int> $cancelled
   */
  public function __construct(
    public readonly array $resumed = [],
    public readonly array $accumulated = [],
    public readonly array $cancelled = [],
  ) {}

  public function is_unheard(): bool {
    return $this->resumed === [] && $this->accumulated === [] && $this->cancelled === [];
  }
}
