<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Workflow;

use TangibleDDD\Application\BehaviourWorkflows\ILoadsIgnitedWorkflow;
use TangibleDDD\Application\BehaviourWorkflows\IStartsFromFact;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgnitionKey;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;

/**
 * D10 (workflow.fact-ignition-once; TXP's cron-ignited workflows): a
 * workflow ignited by CronEntryDue with the (workflow, minute) dedup key
 * WorkflowIgnitionKey::per_minute(kind:entry, due_at). Entry `skip` is
 * declined.
 *
 * The workflow row is ref_type `cron:{entry}` in the host's workflow
 * repository. start_ignited() is the workflow run: it is recorded in
 * $started (this php process); $failStarts makes the next starts throw.
 */
#[StartsOn(CronEntryDue::class)]
final class CronExportWorkflow implements IStartsFromFact, ILoadsIgnitedWorkflow {

  public const KIND = 'conformance.cron-export';

  /** @var list<int> workflow ids whose start completed, in order */
  public static array $started = [];

  public static int $fail_starts = 0;

  public function __construct(private readonly IBehaviourWorkflowRepository $workflows) {}

  public static function reset(): void {
    self::$started = [];
    self::$fail_starts = 0;
  }

  public static function ref_type(string $entry): string {
    return "cron:$entry";
  }

  public function workflow_kind(): string {
    return self::KIND;
  }

  public function workflow_from_fact(IIntegrationEvent $fact): ?BehaviourWorkflow {
    if (!$fact instanceof CronEntryDue || $fact->entry === 'skip') {
      return null;
    }
    return new BehaviourWorkflow(null, 1, self::ref_type($fact->entry), []);
  }

  public function ignition_key(IIntegrationEvent $fact, string $eventId): string {
    /** @var CronEntryDue $fact */
    return WorkflowIgnitionKey::per_minute(self::KIND . ':' . $fact->entry, new \DateTimeImmutable($fact->due_at));
  }

  public function save_ignited(BehaviourWorkflow $workflow): void {
    $this->workflows->save($workflow);
  }

  public function start_ignited(BehaviourWorkflow $workflow): void {
    if (self::$fail_starts > 0) {
      self::$fail_starts--;
      throw new \RuntimeException('workflow start failed');
    }
    self::$started[] = (int) $workflow->get_id();
  }

  public function load_ignited(int $workflowId): ?BehaviourWorkflow {
    try {
      return $this->workflows->get_by_id($workflowId);
    } catch (\Throwable) {
      return null;
    }
  }
}
