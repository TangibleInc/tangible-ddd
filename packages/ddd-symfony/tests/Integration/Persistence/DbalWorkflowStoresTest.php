<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Persistence;

use TangibleDDD\Application\BehaviourWorkflows\IWorkflowIgnitionLedger;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgnition;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionStatus;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemStatus;
use TangibleDDD\Symfony\Persistence\DbalBehaviourWorkflowRepository;
use TangibleDDD\Symfony\Persistence\DbalWorkflowIgnitionLedger;
use TangibleDDD\Symfony\Persistence\DbalWorkItemRepository;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;
use TangibleDDD\Symfony\Tests\Support\Fixtures\StopBehaviourConfig;

/**
 * D10 on Postgres 16 (ruling #78): the behaviour workflow and work item
 * stores, and the workflow ignition ledger with a unique dedup key
 * (workflow.fact-ignition-once: the same CronEntryDue twice → one run).
 */
final class DbalWorkflowStoresTest extends PostgresTestCase {

  private EventsUnitOfWork $events;

  protected function setUp(): void {
    parent::setUp();
    StopBehaviourConfig::register();
    $this->events = new EventsUnitOfWork();
  }

  protected function tearDown(): void {
    Correlation::reset();
    parent::tearDown();
  }

  private function workflows(): DbalBehaviourWorkflowRepository {
    return new DbalBehaviourWorkflowRepository($this->events, $this->db);
  }

  private function items(?\Doctrine\DBAL\Connection $c = null): DbalWorkItemRepository {
    return new DbalWorkItemRepository($c ?? $this->db);
  }

  private function workflow(int $refId = 10, array $meta = ['attempt_id' => 3, 'tags' => ['a', 'b']]): BehaviourWorkflow {
    return new BehaviourWorkflow(
      null, $refId, 'request',
      [new StopBehaviourConfig('first'), new StopBehaviourConfig('second')],
      [new BehaviourExecutionResult('sf_stop', true, ['k' => 'v'], BehaviourExecutionStatus::cases()[0], '2026-10-01T12:00:00Z')],
      1, 2, false, false, $meta,
    );
  }

  public function test_a_new_workflow_is_inserted_and_round_trips(): void {
    $wf = $this->workflow();
    Correlation::within(TraceContext::root()->for_act('cmd', 'Cmd'), fn () => $this->workflows()->save($wf));

    self::assertNotNull($wf->get_id());
    $found = $this->workflows()->get_by_id((int) $wf->get_id());

    self::assertSame(10, $found->get_ref_id());
    self::assertSame('request', $found->get_ref_type());
    self::assertCount(2, $found->get_behaviour_configs());
    self::assertInstanceOf(StopBehaviourConfig::class, $found->get_behaviour_configs()[1]);
    self::assertSame('second', $found->get_behaviour_configs()[1]->reason);
    self::assertCount(1, $found->get_behaviour_results());
    self::assertSame(['k' => 'v'], $found->get_behaviour_results()[0]->context);
    self::assertSame(1, $found->get_current_idx());
    self::assertSame(2, $found->get_current_phase());
    self::assertFalse($found->is_complete());
    self::assertSame('3', (string) $found->get_meta('attempt_id'));
    self::assertSame(['a', 'b'], $found->get_meta('tags'));
    self::assertNotNull($this->db->fetchOne('SELECT correlation_id FROM ddd_behaviour_workflows WHERE id = ?', [$wf->get_id()]));
  }

  public function test_saving_again_updates_the_row_and_rewrites_meta(): void {
    $wf = $this->workflow();
    $this->workflows()->save($wf);

    $changed = new BehaviourWorkflow((int) $wf->get_id(), 10, 'request', [new StopBehaviourConfig('only')], [], 0, 1, true, false, ['attempt_id' => 4]);
    $this->workflows()->save($changed);

    $found = $this->workflows()->get_by_id((int) $wf->get_id());
    self::assertTrue($found->is_complete());
    self::assertCount(1, $found->get_behaviour_configs());
    self::assertSame(['attempt_id' => '4'], $found->get_all_meta());
    self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_behaviour_workflows'));
  }

  public function test_get_by_ref_id_returns_the_workflows_of_a_ref_in_id_order(): void {
    $a = $this->workflow(10);
    $b = $this->workflow(10);
    $other = $this->workflow(11);
    foreach ([$a, $b, $other] as $wf) {
      $this->workflows()->save($wf);
    }

    $found = $this->workflows()->get_by_ref_id(10, 'request');

    self::assertSame([$a->get_id(), $b->get_id()], array_map(static fn ($w) => $w->get_id(), $found));
    self::assertSame([], $this->workflows()->get_by_ref_id(10, 'order'));
  }

  public function test_an_unknown_workflow_id_throws(): void {
    $this->expectException(\RuntimeException::class);
    $this->workflows()->get_by_id(404);
  }

  public function test_a_forked_workflow_keeps_its_root(): void {
    $root = $this->workflow();
    $this->workflows()->save($root);
    $fork = new BehaviourWorkflow(null, 10, 'request', [new StopBehaviourConfig('fork')], [], 0, 1, false, false, [], (int) $root->get_id());
    $this->workflows()->save($fork);

    self::assertSame($root->get_id(), $this->workflows()->get_by_id((int) $fork->get_id())->get_root_workflow_id());
  }

  public function test_work_items_insert_find_by_unique_and_list_a_step(): void {
    $item = new WorkItem(null, 7, 0, 1, 'user:1', WorkItemStatus::pending, 0, null, ['x' => 1]);
    $this->items()->save($item);
    self::assertNotNull($item->get_id());

    $second = new WorkItem(null, 7, 0, 1, 'user:2');
    $this->items()->save($second);
    $otherStep = new WorkItem(null, 7, 1, 1, 'user:1');
    $this->items()->save($otherStep);

    $found = $this->items()->find_by_unique(7, 0, 1, 'user:1');
    self::assertSame($item->get_id(), $found?->get_id());
    self::assertSame(['x' => 1], $found->payload);
    self::assertSame(WorkItemStatus::pending, $found->status);
    self::assertNotNull($found->created_at);
    self::assertNull($this->items()->find_by_unique(7, 0, 1, 'user:9'));

    $step = $this->items()->get_for_step(7, 0, 1);
    self::assertSame([$item->get_id(), $second->get_id()], array_map(static fn ($i) => $i->get_id(), iterator_to_array($step)));
  }

  public function test_saving_a_new_item_with_an_existing_natural_key_updates_that_row(): void {
    $first = new WorkItem(null, 7, 0, 1, 'user:1');
    $this->items()->save($first);

    $again = new WorkItem(null, 7, 0, 1, 'user:1', WorkItemStatus::done, 2, 'boom');
    $this->items($this->secondConnection())->save($again);

    self::assertSame($first->get_id(), $again->get_id());
    $row = $this->items()->get_by_id((int) $first->get_id());
    self::assertSame(WorkItemStatus::done, $row->status);
    self::assertSame(2, $row->attempts);
    self::assertSame('boom', $row->last_error);
    self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_behaviour_workflow_items'));
  }

  public function test_an_unknown_work_item_id_throws(): void {
    $this->expectException(\RuntimeException::class);
    $this->items()->get_by_id(404);
  }

  public function test_the_ignition_ledger_lets_exactly_one_claim_of_a_dedup_key_win(): void {
    $ledger = new DbalWorkflowIgnitionLedger($this->db);
    $other = new DbalWorkflowIgnitionLedger($this->secondConnection());
    $key = 'CronEntryDue:nightly-report:2026-10-01T03:00';

    self::assertTrue($ledger->claim($key, 'nightly-report', 'evt-1'), 'the first tick runs the workflow');
    self::assertFalse($other->claim($key, 'nightly-report', 'evt-2'), 'the second tick in the same minute does not');

    $ledger->attach($key, 42);
    $entry = $other->find($key);
    self::assertInstanceOf(WorkflowIgnition::class, $entry);
    self::assertSame($key, $entry->dedupKey);
    self::assertSame('nightly-report', $entry->kind);
    self::assertSame(42, $entry->workflowId);
    self::assertSame('evt-1', $entry->eventId);
    self::assertInstanceOf(\DateTimeImmutable::class, $entry->createdAt);
    self::assertNull($other->find('CronEntryDue:nightly-report:2026-10-01T03:01'));
  }

  public function test_the_ledger_is_the_core_d10_port(): void {
    self::assertInstanceOf(IWorkflowIgnitionLedger::class, new DbalWorkflowIgnitionLedger($this->db));
  }

  public function test_an_unattached_claim_reads_back_without_a_workflow(): void {
    $ledger = new DbalWorkflowIgnitionLedger($this->db);
    $ledger->claim('k-marker', 'kind');

    $entry = $ledger->find('k-marker');
    self::assertNull($entry->workflowId);
    self::assertNull($entry->eventId);
  }

  public function test_a_rolled_back_claim_does_not_hold_the_key(): void {
    $ledger = new DbalWorkflowIgnitionLedger($this->db);

    $this->db->beginTransaction();
    self::assertTrue($ledger->claim('k1', 'kind'));
    $this->db->rollBack();

    self::assertTrue((new DbalWorkflowIgnitionLedger($this->secondConnection()))->claim('k1', 'kind'));
  }

  public function test_release_is_the_explicit_repair_path(): void {
    $ledger = new DbalWorkflowIgnitionLedger($this->db);
    $ledger->claim('k1', 'kind');

    $ledger->release('k1');

    self::assertTrue($ledger->claim('k1', 'kind'));
  }

  public function test_the_fact_dedup_key_is_uuid5_of_event_and_kind(): void {
    $event = '6f1c2a7e-3b4d-4e5f-8a9b-0c1d2e3f4a5b';

    self::assertSame(DbalWorkflowIgnitionLedger::keyForFact($event, 'onboarding'), DbalWorkflowIgnitionLedger::keyForFact($event, 'onboarding'));
    self::assertNotSame(DbalWorkflowIgnitionLedger::keyForFact($event, 'onboarding'), DbalWorkflowIgnitionLedger::keyForFact($event, 'offboarding'));
    self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', DbalWorkflowIgnitionLedger::keyForFact($event, 'onboarding'));
  }
}
