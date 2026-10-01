<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Workflow;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Symfony\Persistence\DbalBehaviourWorkflowRepository;
use TangibleDDD\Symfony\Runtime\Wakeup\IProcessWakeTarget;
use TangibleDDD\Symfony\Runtime\Wakeup\WakeKindUnsupported;

/**
 * W1: the wake target of the bundle's wakeup handler. A workflow
 * continuation (WorkflowContinuations' `workflow:` key, no process id) is
 * run here; every other intent goes to the process target unchanged.
 *
 * A continuation: the handler is looked up by the class in the key (an
 * IContinuesWorkflows service; built first, so a handler that registers its
 * behaviour config types in its constructor has done so before the load),
 * then, under a per-workflow lock (LockKey consumer `{prefix}/workflow`, so
 * it never shares a key space with process ids), the workflow is loaded and,
 * if it is still active, continued. A workflow that is complete, failed or
 * gone makes the wake a no-op (a stale continuation).
 *
 * Errors: an unknown handler class is WakeKindUnsupported (not retryable: the
 * intent is exhausted for the operator, never dropped); a lock that is not
 * free throws LockNotAcquired (retryable: the handler re-queues the intent);
 * the handler's own errors propagate to the wakeup handler's retry policy.
 */
final class WorkflowWakeTarget implements IProcessWakeTarget {

  private const LOCK_TIMEOUT_SECONDS = 5.0;

  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly ContainerInterface $handlers,
    private readonly IBehaviourWorkflowRepository $workflows,
    private readonly WorkflowContinuations $continuations,
    private readonly IProcessWakeTarget $processes,
    private readonly ?IProcessLock $lock = null,
    private readonly string $consumer = '',
    ?LoggerInterface $logger = null,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  public function wake(WakeupIntent $intent): void {
    if (!WorkflowContinuations::is_continuation($intent)) {
      $this->processes->wake($intent);
      return;
    }
    $key = WorkflowContinuations::parse($intent->key)
      ?? throw new WakeKindUnsupported("Workflow continuation {$intent->key} has an unreadable key");
    if (!$this->handlers->has($key['handler'])) {
      throw new WakeKindUnsupported("Workflow continuation {$intent->key}: no IContinuesWorkflows service of class {$key['handler']}");
    }
    /** @var IContinuesWorkflows $handler */
    $handler = $this->handlers->get($key['handler']);

    $lock = $this->lock?->acquire(new LockKey($this->consumer . '/workflow', '', $key['workflow_id']), self::LOCK_TIMEOUT_SECONDS);
    try {
      $workflow = $this->workflows instanceof DbalBehaviourWorkflowRepository
        ? $this->workflows->find($key['workflow_id'])
        : $this->workflows->get_by_id($key['workflow_id']);
      if ($workflow === null) {
        $this->logger->info("[ddd workflow] continuation {$intent->key}: workflow #{$key['workflow_id']} is gone; nothing to do");
        return;
      }
      if (!$workflow->is_active()) {
        $this->logger->info("[ddd workflow] continuation {$intent->key}: workflow #{$key['workflow_id']} already ended; nothing to do");
        return;
      }
      $this->continuations->within($intent->key, fn () => $handler->continue_workflow($workflow));
    } finally {
      if ($lock !== null) {
        $this->lock?->release($lock);
      }
    }
  }
}
