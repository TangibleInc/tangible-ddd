<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process;

/**
 * An await with an absolute UTC deadline (D7). When deadline() is not null,
 * the runner schedules the await's Timeout intent due exactly then, instead
 * of now + timeout_seconds(). A relative alarm returns null here; the runner
 * then fixes its instant once, at suspension (LongProcess::await_deadline()),
 * and never re-delays it.
 */
interface IHasDeadline {

  public function deadline(): ?\DateTimeImmutable;
}
