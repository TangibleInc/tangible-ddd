<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\RaisesEvents;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemList;
use TangibleDDD\Tests\Fakes\FakeBehaviourWorkflowRepository;
use TangibleDDD\Tests\Fakes\FakeDomainEvent;
use TangibleDDD\Tests\Fakes\FakeWorkItemRepository;

/**
 * The blessed act-level raise: $this->event() on a coordinator delegates to
 * the live EventsUnitOfWork. This lane is for ACT-level coordination facts
 * only — anything that happened to an aggregate belongs on the aggregate.
 */
class RaisesEventsTest extends TestCase {

  public function test_event_records_into_the_unit_of_work(): void {
    $uow = new EventsUnitOfWork();
    $raiser = $this->make_raiser($uow);

    $moment = new FakeDomainEvent(1, 'rescheduled');
    $raiser->raise($moment);

    $this->assertSame([$moment], $uow->drain());
  }

  public function test_event_without_a_unit_of_work_throws_naming_the_dependency(): void {
    $raiser = $this->make_raiser(null);

    try {
      $raiser->raise(new FakeDomainEvent(1));
      $this->fail('a null unit of work must be a loud LogicException, not a silent drop');
    } catch (\LogicException $e) {
      $this->assertStringContainsString('EventsUnitOfWork', $e->getMessage());
    }
  }

  // ── WorkflowHandler adoption (additive) ──

  public function test_old_two_arg_workflow_handler_subclass_still_constructs(): void {
    // The existing consumer shape — parent::__construct($workflow_repo,
    // $item_repo) — must keep compiling and running untouched.
    $handler = new class(new FakeBehaviourWorkflowRepository(), new FakeWorkItemRepository()) extends WorkflowHandler {
      protected function get_workflows(ICommand $command): array { return []; }
      protected function execute_one(BaseBehaviourConfig $config, WorkItem $item, ?BehaviourExecutionResult $previous): BehaviourExecutionResult {
        throw new \LogicException('unreached');
      }
      protected function generate_work_items(BehaviourWorkflow $workflow, BaseBehaviourConfig $config): WorkItemList {
        return new WorkItemList([]);
      }
      protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void {}
      public function raise(\TangibleDDD\Domain\Events\IDomainEvent $e): void { $this->event($e); }
    };

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessageMatches('/EventsUnitOfWork/');
    $handler->raise(new FakeDomainEvent(1));
  }

  public function test_workflow_handler_with_injected_uow_raises_through_it(): void {
    $uow = new EventsUnitOfWork();
    $handler = new class(new FakeBehaviourWorkflowRepository(), new FakeWorkItemRepository(), null, $uow) extends WorkflowHandler {
      protected function get_workflows(ICommand $command): array { return []; }
      protected function execute_one(BaseBehaviourConfig $config, WorkItem $item, ?BehaviourExecutionResult $previous): BehaviourExecutionResult {
        throw new \LogicException('unreached');
      }
      protected function generate_work_items(BehaviourWorkflow $workflow, BaseBehaviourConfig $config): WorkItemList {
        return new WorkItemList([]);
      }
      protected function reschedule(BehaviourWorkflow $workflow, int $delay_seconds): void {}
      public function raise(\TangibleDDD\Domain\Events\IDomainEvent $e): void { $this->event($e); }
    };

    $moment = new FakeDomainEvent(3, 'rescheduled');
    $handler->raise($moment);

    $this->assertSame([$moment], $uow->drain());
  }

  private function make_raiser(?EventsUnitOfWork $uow): object {
    return new class($uow) {
      use RaisesEvents;

      public function __construct(private readonly ?EventsUnitOfWork $uow) {}

      protected function events_uow(): ?EventsUnitOfWork { return $this->uow; }

      public function raise(\TangibleDDD\Domain\Events\IDomainEvent $e): void { $this->event($e); }
    };
  }
}
