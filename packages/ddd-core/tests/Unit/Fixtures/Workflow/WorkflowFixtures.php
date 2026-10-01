<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures\Workflow;

use TangibleDDD\Application\BehaviourWorkflows\IStartsFromFact;
use TangibleDDD\Application\BehaviourWorkflows\IWorkflowIgnitionLedger;
use TangibleDDD\Application\BehaviourWorkflows\StartsFromFacts;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowHandler;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgnition;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgnitionKey;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Core\Tests\Unit\Fixtures\CronEntryDue;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Testing\InMemoryTransactional;

/** In-memory ignition ledger (the D10 port), transactional with the boundary. */
final class MemIgnitionLedger implements IWorkflowIgnitionLedger, InMemoryTransactional {

  /** @var array<string, WorkflowIgnition> */
  public array $rows = [];

  public function __construct(private readonly IClock $clock) {}

  public function claim(string $dedupKey, string $kind, ?string $eventId = null): bool {
    if (isset($this->rows[$dedupKey])) {
      return false;
    }
    $this->rows[$dedupKey] = new WorkflowIgnition($dedupKey, $kind, null, $eventId, $this->clock->now());
    return true;
  }

  public function attach(string $dedupKey, int $workflowId): void {
    $row = $this->rows[$dedupKey] ?? null;
    if ($row !== null) {
      $this->rows[$dedupKey] = new WorkflowIgnition($row->dedupKey, $row->kind, $workflowId, $row->eventId, $row->createdAt);
    }
  }

  public function find(string $dedupKey): ?WorkflowIgnition {
    return $this->rows[$dedupKey] ?? null;
  }

  public function release(string $dedupKey): void {
    unset($this->rows[$dedupKey]);
  }

  public function snapshotState(): mixed {
    return $this->rows;
  }

  public function restoreState(mixed $state): void {
    $this->rows = $state;
  }
}

/** In-memory workflow repository, transactional with the boundary. */
final class MemWorkflowRepository implements IBehaviourWorkflowRepository, InMemoryTransactional {

  /** @var array<int, BehaviourWorkflow> */
  public array $rows = [];
  private int $next = 1;
  public ?\Throwable $failNextSave = null;

  public function get_by_id(int $id): BehaviourWorkflow {
    return $this->rows[$id] ?? throw new \RuntimeException("no workflow $id");
  }

  public function get_by_ref_id(int $ref_id, string $ref_type): array {
    return array_values(array_filter($this->rows, static fn (BehaviourWorkflow $w) => $w->get_ref_id() === $ref_id && $w->get_ref_type() === $ref_type));
  }

  public function save(BehaviourWorkflow $workflow): void {
    if ($this->failNextSave !== null) {
      $e = $this->failNextSave;
      $this->failNextSave = null;
      throw $e;
    }
    if ($workflow->get_id() === null) {
      $workflow->set_id($this->next++);
    }
    $this->rows[$workflow->get_id()] = $workflow;
  }

  public function snapshotState(): mixed {
    return [$this->rows, $this->next];
  }

  public function restoreState(mixed $state): void {
    [$this->rows, $this->next] = $state;
  }
}

final class NoItems implements IWorkItemRepository {
  public function get_by_id(int $id): WorkItem { throw new \LogicException('unused'); }
  public function find_by_unique(int $workflow_id, int $behaviour_idx, int $phase, string $item_key): ?WorkItem { return null; }
  public function get_for_step(int $workflow_id, int $behaviour_idx, int $phase): WorkItemList { return new WorkItemList([]); }
  public function save(WorkItem $item): void {}
}

/**
 * D10: a workflow ignited by CronEntryDue with a (workflow, minute) dedup
 * key; entry "skip" is declined. start_ignited() records instead of running
 * behaviours (the run itself is WorkflowHandler's, tested elsewhere).
 */
#[StartsOn(CronEntryDue::class)]
final class NightlyExportWorkflow extends WorkflowHandler implements IStartsFromFact {
  use StartsFromFacts;

  /** @var list<int> workflow ids started after commit */
  public array $started = [];
  public ?\Throwable $failStart = null;

  public function workflow_from_fact(IIntegrationEvent $fact): ?BehaviourWorkflow {
    if (!$fact instanceof CronEntryDue || $fact->entry === 'skip') {
      return null;
    }
    return new BehaviourWorkflow(null, 1, 'cron:' . $fact->entry, []);
  }

  public function ignition_key(IIntegrationEvent $fact, string $eventId): string {
    /** @var CronEntryDue $fact */
    return WorkflowIgnitionKey::perMinute($this->workflow_kind() . ':' . $fact->entry, new \DateTimeImmutable($fact->due_at));
  }

  public function start_ignited(BehaviourWorkflow $workflow): void {
    if ($this->failStart !== null) {
      throw $this->failStart;
    }
    $this->started[] = (int) $workflow->get_id();
  }

  protected function get_workflows(ICommand $command): array { return []; }
  protected function execute_one(BaseBehaviourConfig $config, WorkItem $item, ?BehaviourExecutionResult $previous): BehaviourExecutionResult { throw new \LogicException('unused'); }
  protected function generate_work_items(BehaviourWorkflow $workflow, BaseBehaviourConfig $config): WorkItemList { return new WorkItemList([]); }
  protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void {}
}

/** D10 with the trait's defaults: the dedup key is uuid5(event_id, kind). */
#[StartsOn(CronEntryDue::class)]
final class PerFactWorkflow extends WorkflowHandler implements IStartsFromFact {
  use StartsFromFacts;

  /** @var list<int> */
  public array $started = [];

  public function workflow_from_fact(IIntegrationEvent $fact): ?BehaviourWorkflow {
    return new BehaviourWorkflow(null, 2, 'per-fact', []);
  }

  public function start_ignited(BehaviourWorkflow $workflow): void {
    $this->started[] = (int) $workflow->get_id();
  }

  protected function get_workflows(ICommand $command): array { return []; }
  protected function execute_one(BaseBehaviourConfig $config, WorkItem $item, ?BehaviourExecutionResult $previous): BehaviourExecutionResult { throw new \LogicException('unused'); }
  protected function generate_work_items(BehaviourWorkflow $workflow, BaseBehaviourConfig $config): WorkItemList { return new WorkItemList([]); }
  protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void {}
}

/** Declares no #[StartsOn]. */
final class UndeclaredWorkflow extends WorkflowHandler implements IStartsFromFact {
  use StartsFromFacts;

  public function workflow_from_fact(IIntegrationEvent $fact): ?BehaviourWorkflow { return null; }
  protected function get_workflows(ICommand $command): array { return []; }
  protected function execute_one(BaseBehaviourConfig $config, WorkItem $item, ?BehaviourExecutionResult $previous): BehaviourExecutionResult { throw new \LogicException('unused'); }
  protected function generate_work_items(BehaviourWorkflow $workflow, BaseBehaviourConfig $config): WorkItemList { return new WorkItemList([]); }
  protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void {}
}
