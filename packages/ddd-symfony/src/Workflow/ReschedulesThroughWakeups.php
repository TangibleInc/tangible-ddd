<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Workflow;

use Symfony\Contracts\Service\Attribute\Required;
use TangibleDDD\Domain\BehaviourWorkflow;

/**
 * W1: the ddd-symfony WorkflowHandler::reschedule(). Use it in a
 * WorkflowHandler subclass that implements IContinuesWorkflows:
 *
 *   final class Digest extends WorkflowHandler implements IContinuesWorkflows {
 *     use ReschedulesThroughWakeups;
 *     ...
 *   }
 *
 * Every continuation core asks for (a run that hit its resource limits, a
 * step that failed with retries left, a fork of failed items, the extra
 * workflows of handle()) becomes a durable `ddd_wakeups` intent, scheduled
 * in its own boundary transaction (joined when one is open) and due at the
 * absolute time now + delay. `ddd:relay` projects it to `ddd_wakeups` like a
 * process wakeup, under the same wake budget and in the same operator
 * layer, and the wake runs continue_workflow() under a per-workflow lock.
 *
 * The continuations service is injected by autowiring (#[Required]); a
 * handler built by hand calls use_continuations() itself.
 */
trait ReschedulesThroughWakeups {

  private ?WorkflowContinuations $continuations = null;

  #[Required]
  public function use_continuations(WorkflowContinuations $continuations): void {
    $this->continuations = $continuations;
  }

  protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void {
    $continuations = $this->continuations ?? throw new \LogicException(sprintf(
      '%s reschedules through the wakeup scheduler but has no WorkflowContinuations (autowire it, or call use_continuations())', static::class
    ));
    $continuations->schedule(static::class, $workflow, $delay_seconds);
  }

  public function continue_workflow(BehaviourWorkflow $workflow): void {
    $this->started_at = time(); // RescheduleAware: this run's resource budget starts now
    $this->handle_workflow($workflow);
  }
}
