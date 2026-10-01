<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Workflow;

use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;

/**
 * W1: workflow continuations as durable wakeup intents on the consumer's
 * IWakeupScheduler (`ddd_wakeups`), the mechanism processes use (D7).
 *
 * An intent is a `continue` wake with no process id and the key
 *
 *   workflow:{handler class}:{workflow id}:{behaviour idx}:{phase}#{n}
 *
 * due at now + delay (absolute). Scheduling a key that exists is a no-op,
 * so a repeated schedule (a redelivered start) does not double the
 * continuation. `n` counts continuations of one step: inside a wake
 * (within()) a reschedule of the same step takes the next number, because
 * the waking intent's own row is deleted only after the wake returns.
 *
 * schedule() runs in an ITransactionBoundary::run (the scheduler refuses to
 * write outside one), joining the caller's transaction when one is open.
 * Errors: the scheduler's and the boundary's, unchanged; a workflow without
 * an id is an \InvalidArgumentException.
 */
final class WorkflowContinuations {

  public const PREFIX = 'workflow:';

  private ?string $waking = null;

  public function __construct(
    private readonly IWakeupScheduler $scheduler,
    private readonly ITransactionBoundary $boundary,
    private readonly IClock $clock,
    private readonly string $consumer,
  ) {}

  /** @param class-string $handler the IContinuesWorkflows that continues it */
  public function schedule(string $handler, BehaviourWorkflow $workflow, int $delaySeconds): WakeupIntent {
    $id = $workflow->get_id() ?? throw new \InvalidArgumentException('A workflow is saved before it is rescheduled; this one has no id');
    $step = sprintf('%s%s:%d:%d:%d', self::PREFIX, $handler, $id, $workflow->get_current_idx(), $workflow->get_current_phase());
    $n = 1;
    if ($this->waking !== null && ($parsed = self::parse($this->waking)) !== null && $parsed['step'] === $step) {
      $n = $parsed['n'] + 1;
    }
    $intent = new WakeupIntent(
      WakeKind::Continue, $this->consumer, null, $workflow->get_current_idx(), null,
      $this->clock->now()->modify('+' . max(0, $delaySeconds) . ' seconds'),
      "$step#$n",
    );
    $this->boundary->run(fn () => $this->scheduler->schedule($intent));
    return $intent;
  }

  /**
   * Run $wake as the wake of intent $key (the wake target does this).
   *
   * @template T
   * @param \Closure(): T $wake
   * @return T
   */
  public function within(string $key, \Closure $wake): mixed {
    $previous = $this->waking;
    $this->waking = $key;
    try {
      return $wake();
    } finally {
      $this->waking = $previous;
    }
  }

  public static function is_continuation(WakeupIntent $intent): bool {
    return $intent->process_id === null && str_starts_with($intent->key, self::PREFIX);
  }

  /**
   * @return array{handler: class-string, workflow_id: int, idx: int, phase: int, n: int, step: string}|null
   */
  public static function parse(string $key): ?array {
    if (!preg_match('/^workflow:([^:#]+):(\d+):(\d+):(\d+)#(\d+)$/', $key, $m)) {
      return null;
    }
    return [
      'handler' => $m[1], 'workflow_id' => (int) $m[2], 'idx' => (int) $m[3], 'phase' => (int) $m[4], 'n' => (int) $m[5],
      'step' => "workflow:{$m[1]}:{$m[2]}:{$m[3]}:{$m[4]}",
    ];
  }
}
