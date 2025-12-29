<?php

namespace TangibleDDD\Application\Process;

/**
 * The instruction set for LongProcess steps.
 *
 * Each step returns a Result that tells the runner what to do:
 * - payload: pass data to the next step
 * - queries: execute these queries, pass results to next step
 * - commands: execute these commands
 * - await: suspend until this event fires
 */
final class Result {
  public function __construct(
    public readonly mixed $payload = null,
    public readonly array $queries = [],
    public readonly array $commands = [],
    public readonly ?AwaitEvent $await = null,
  ) {}

  public function has_queries(): bool {
    return !empty($this->queries);
  }

  public function has_commands(): bool {
    return !empty($this->commands);
  }

  public function should_suspend(): bool {
    return $this->await !== null;
  }
}
