<?php

namespace TangibleDDD\Application\Process;

use TangibleDDD\Domain\Shared\JsonLifecycleValue;

/**
 * The instruction set for LongProcess steps.
 *
 * Each step returns a Result that tells the runner what to do:
 * - payload: pass data to the next step (must be JsonLifecycleValue)
 * - queries: execute these queries, pass results to next step
 * - commands: execute these commands
 * - await: suspend until this event fires
 * - checkpoint: data for compensation (must be JsonLifecycleValue)
 */
final class Result {
  public function __construct(
    /**
     * Data to pass to the next step.
     * Must be a JsonLifecycleValue for proper serialization.
     */
    public readonly ?JsonLifecycleValue $payload = null,

    /** @var array Queries to execute (results passed to next step) */
    public readonly array $queries = [],

    /** @var array Commands to dispatch */
    public readonly array $commands = [],

    /** Suspend and wait for this event */
    public readonly ?AwaitEvent $await = null,

    /**
     * Checkpoint data for compensation.
     * Persisted with the step; passed to compensation method if step needs to be undone.
     * Must be a JsonLifecycleValue for proper serialization.
     */
    public readonly ?JsonLifecycleValue $checkpoint = null,
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
