<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

require_once dirname(__DIR__, 2) . '/Unit/Fixtures/Workflow/WorkflowFixtures.php';

use TangibleDDD\Application\BehaviourWorkflows\IWorkflowIgnitionLedger;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgniter;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgnitionKey;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgnitionOutcome;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Core\Tests\Pdo\PdoTestCase;
use TangibleDDD\Core\Tests\Unit\Fixtures\CronEntryDue;
use TangibleDDD\Core\Tests\Unit\Fixtures\Workflow\NightlyExportWorkflow;
use TangibleDDD\Core\Tests\Unit\Fixtures\Workflow\NoItems;
use TangibleDDD\Defaults\Pdo\IHostConnection;
use TangibleDDD\Defaults\Pdo\PdoBehaviourWorkflowRepository;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Defaults\Pdo\PdoWorkflowIgnitionLedger;
use TangibleDDD\Defaults\Pdo\PdoWorkItemRepository;
use TangibleDDD\Domain\BehaviourWorkflow;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionResult;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourExecutionStatus;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItem;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemStatus;
use TangibleDDD\Runtime\FrozenClock;

/** A behaviour config for the D10 store tests (type `pdo_stop`). */
final class PdoStopBehaviourConfig extends BaseBehaviourConfig {

  public function __construct(public readonly string $reason = '') {
    parent::__construct();
  }

  public static function register(): void {
    BaseBehaviourConfig::register_type('pdo_stop', self::class);
  }

  public function get_behaviour_type(): string {
    return 'pdo_stop';
  }

  protected static function from_json_instance(\stdClass|array $rendered_data, ...$params): static {
    return new static((string) (((array) $rendered_data)['reason'] ?? ''));
  }
}

/**
 * D10 on MySQL 8 (O8, ruling #78): the behaviour workflow and work-item
 * stores and the workflow ignition ledger, plus the core WorkflowIgniter on
 * them (workflow.fact-ignition-once: the same fact twice and two cron ticks
 * in one minute start one workflow).
 */
abstract class PdoWorkflowStoresCases extends PdoTestCase {

  private FrozenClock $clock;
  private EventsUnitOfWork $events;

  protected function setUp(): void {
    parent::setUp();
    PdoStopBehaviourConfig::register();
    $this->clock = new FrozenClock(self::utc('2026-10-01 12:00:00'));
    $this->events = new EventsUnitOfWork();
  }

  protected function tearDown(): void {
    Correlation::reset();
    parent::tearDown();
  }

  private function workflows(?IHostConnection $db = null): PdoBehaviourWorkflowRepository {
    return new PdoBehaviourWorkflowRepository($this->events, $db ?? $this->db, self::PREFIX, $this->clock);
  }

  private function items(?IHostConnection $db = null): PdoWorkItemRepository {
    return new PdoWorkItemRepository($db ?? $this->db, self::PREFIX, $this->clock);
  }

  private function ledger(?IHostConnection $db = null): PdoWorkflowIgnitionLedger {
    return new PdoWorkflowIgnitionLedger($db ?? $this->db, self::PREFIX, $this->clock);
  }

  private function workflow(int $refId = 10, array $meta = ['attempt_id' => 3, 'tags' => ['a', 'b']]): BehaviourWorkflow {
    return new BehaviourWorkflow(
      null, $refId, 'request',
      [new PdoStopBehaviourConfig('first'), new PdoStopBehaviourConfig('second')],
      [new BehaviourExecutionResult('pdo_stop', true, ['k' => 'v'], BehaviourExecutionStatus::cases()[0], '2026-10-01T12:00:00Z')],
      1, 2, false, false, $meta,
    );
  }

  // ── workflows ───────────────────────────────────────────────────────────

  public function test_a_new_workflow_is_inserted_and_round_trips(): void {
    self::assertInstanceOf(IBehaviourWorkflowRepository::class, $this->workflows());
    $wf = $this->workflow();
    Correlation::within(TraceContext::root()->for_act('cmd', 'Cmd'), fn () => $this->workflows()->save($wf));

    self::assertNotNull($wf->get_id());
    $found = $this->workflows($this->otherConnection())->get_by_id((int) $wf->get_id());

    self::assertSame(10, $found->get_ref_id());
    self::assertSame('request', $found->get_ref_type());
    self::assertCount(2, $found->get_behaviour_configs());
    self::assertInstanceOf(PdoStopBehaviourConfig::class, $found->get_behaviour_configs()[1]);
    self::assertSame('second', $found->get_behaviour_configs()[1]->reason);
    self::assertCount(1, $found->get_behaviour_results());
    self::assertSame(['k' => 'v'], $found->get_behaviour_results()[0]->context);
    self::assertSame(1, $found->get_current_idx());
    self::assertSame(2, $found->get_current_phase());
    self::assertFalse($found->is_complete());
    self::assertFalse($found->is_failed());
    self::assertSame('3', (string) $found->get_meta('attempt_id'));
    self::assertSame(['a', 'b'], $found->get_meta('tags'));
    $row = $this->row('ddd_behaviour_workflows', 'id = ?', [$wf->get_id()]);
    self::assertNotNull($row['correlation_id'], 'stamped from the ambient correlation scope');
    self::assertSame('2026-10-01 12:00:00.000000', $row['created_at']);
  }

  public function test_saving_again_updates_the_row_and_rewrites_meta(): void {
    $wf = $this->workflow();
    $this->workflows()->save($wf);

    $changed = new BehaviourWorkflow((int) $wf->get_id(), 10, 'request', [new PdoStopBehaviourConfig('only')], [], 0, 1, true, false, ['attempt_id' => 4]);
    $this->workflows()->save($changed);

    $found = $this->workflows()->get_by_id((int) $wf->get_id());
    self::assertTrue($found->is_complete());
    self::assertCount(1, $found->get_behaviour_configs());
    self::assertSame(['attempt_id' => '4'], $found->get_all_meta());
    self::assertSame(1, $this->countRows('ddd_behaviour_workflows'));
    self::assertSame(1, $this->countRows('ddd_behaviour_workflow_meta'));
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
    self::assertSame(['a', 'b'], $found[1]->get_meta('tags'));
    self::assertSame([], $this->workflows()->get_by_ref_id(10, 'order'));
  }

  public function test_an_unknown_workflow_id_throws(): void {
    $this->expectException(\RuntimeException::class);
    $this->workflows()->get_by_id(404);
  }

  public function test_a_forked_workflow_keeps_its_root(): void {
    $root = $this->workflow();
    $this->workflows()->save($root);
    $fork = new BehaviourWorkflow(null, 10, 'request', [new PdoStopBehaviourConfig('fork')], [], 0, 1, false, false, [], (int) $root->get_id());
    $this->workflows()->save($fork);

    self::assertSame($root->get_id(), $this->workflows()->get_by_id((int) $fork->get_id())->get_root_workflow_id());
  }

  public function test_a_workflow_save_joins_the_callers_transaction(): void {
    $boundary = new PdoTransactionBoundary($this->db);
    try {
      $boundary->run(function (): void {
        $this->workflows()->save($this->workflow());
        throw new \DomainException('rolled back');
      });
    } catch (\DomainException) {
    }

    self::assertSame(0, $this->countRows('ddd_behaviour_workflows'));
    self::assertSame(0, $this->countRows('ddd_behaviour_workflow_meta'));
  }

  // ── work items ──────────────────────────────────────────────────────────

  public function test_work_items_insert_find_by_unique_and_list_a_step(): void {
    self::assertInstanceOf(IWorkItemRepository::class, $this->items());
    $item = new WorkItem(null, 7, 0, 1, 'user:1', WorkItemStatus::pending, 0, null, ['x' => 1]);
    $this->items()->save($item);
    self::assertNotNull($item->get_id());

    $second = new WorkItem(null, 7, 0, 1, 'user:2');
    $this->items()->save($second);
    $this->items()->save(new WorkItem(null, 7, 1, 1, 'user:1'));

    $found = $this->items()->find_by_unique(7, 0, 1, 'user:1');
    self::assertSame($item->get_id(), $found?->get_id());
    self::assertSame(['x' => 1], $found->payload);
    self::assertSame(WorkItemStatus::pending, $found->status);
    self::assertEquals(self::utc('2026-10-01 12:00:00'), $found->created_at);
    self::assertNull($this->items()->find_by_unique(7, 0, 1, 'user:9'));

    $step = $this->items()->get_for_step(7, 0, 1);
    self::assertSame([$item->get_id(), $second->get_id()], array_map(static fn ($i) => $i->get_id(), iterator_to_array($step)));
  }

  public function test_saving_a_new_item_with_an_existing_natural_key_updates_that_row(): void {
    $first = new WorkItem(null, 7, 0, 1, 'user:1');
    $this->items()->save($first);

    $again = new WorkItem(null, 7, 0, 1, 'user:1', WorkItemStatus::done, 2, 'boom', 'plain');
    $this->items($this->otherConnection())->save($again);

    self::assertSame($first->get_id(), $again->get_id());
    $row = $this->items()->get_by_id((int) $first->get_id());
    self::assertSame(WorkItemStatus::done, $row->status);
    self::assertSame(2, $row->attempts);
    self::assertSame('boom', $row->last_error);
    self::assertSame('plain', $row->payload);
    self::assertSame(1, $this->countRows('ddd_behaviour_workflow_items'));
  }

  public function test_an_item_with_an_id_is_updated_by_id(): void {
    $item = new WorkItem(null, 7, 0, 1, 'user:1');
    $this->items()->save($item);
    $item->status = WorkItemStatus::failed;
    $item->attempts = 3;
    $this->clock->advance('PT1M');

    $this->items()->save($item);

    $row = $this->items()->get_by_id((int) $item->get_id());
    self::assertSame(WorkItemStatus::failed, $row->status);
    self::assertSame(3, $row->attempts);
    self::assertEquals(self::utc('2026-10-01 12:01:00'), $row->updated_at);
    self::assertEquals(self::utc('2026-10-01 12:00:00'), $row->created_at);
  }

  public function test_an_unknown_work_item_id_throws(): void {
    $this->expectException(\RuntimeException::class);
    $this->items()->get_by_id(404);
  }

  // ── ignition ledger ─────────────────────────────────────────────────────

  public function test_the_ignition_ledger_lets_exactly_one_claim_of_a_dedup_key_win(): void {
    $ledger = $this->ledger();
    self::assertInstanceOf(IWorkflowIgnitionLedger::class, $ledger);
    $other = $this->ledger($this->otherConnection());
    $key = 'CronEntryDue:nightly-report:2026-10-01T03:00Z';

    self::assertTrue($ledger->claim($key, 'nightly-report', 'evt-1'), 'the first tick runs the workflow');
    self::assertFalse($other->claim($key, 'nightly-report', 'evt-2'), 'the second tick in the same minute does not');

    $ledger->attach($key, 42);
    $entry = $other->find($key);
    self::assertNotNull($entry);
    self::assertSame($key, $entry->dedupKey);
    self::assertSame('nightly-report', $entry->kind);
    self::assertSame(42, $entry->workflowId);
    self::assertSame('evt-1', $entry->eventId);
    self::assertEquals(self::utc('2026-10-01 12:00:00'), $entry->createdAt);
    self::assertNull($other->find('CronEntryDue:nightly-report:2026-10-01T03:01Z'));
  }

  public function test_an_unattached_claim_has_no_workflow_id(): void {
    $this->ledger()->claim('k1', 'kind');

    $entry = $this->ledger()->find('k1');
    self::assertNull($entry?->workflowId);
    self::assertNull($entry?->eventId);
  }

  public function test_a_losing_claim_inside_a_transaction_leaves_it_usable(): void {
    $this->ledger()->claim('k1', 'kind');
    $boundary = new PdoTransactionBoundary($this->db);

    $boundary->run(function (): void {
      self::assertFalse($this->ledger()->claim('k1', 'kind'));
      self::assertTrue($this->ledger()->claim('k2', 'kind'));
    });

    self::assertNotNull($this->ledger()->find('k2'));
  }

  public function test_a_concurrent_uncommitted_claim_blocks_and_then_wins_or_loses_with_its_transaction(): void {
    $other = $this->otherConnection();
    $other->begin();
    self::assertTrue($this->ledger($other)->claim('k1', 'kind'));
    $other->rollBack();

    self::assertTrue($this->ledger()->claim('k1', 'kind'), 'a rolled-back claim does not hold the key');
  }

  public function test_release_is_the_explicit_repair_path(): void {
    $ledger = $this->ledger();
    $ledger->claim('k1', 'kind');

    $ledger->release('k1');

    self::assertNull($ledger->find('k1'));
    self::assertTrue($ledger->claim('k1', 'kind'));
  }

  public function test_other_storage_errors_throw_and_never_answer_false(): void {
    $this->expectException(\RuntimeException::class);
    $this->ledger()->claim(str_repeat('k', 300), 'kind'); // longer than the key column
  }

  // ── WorkflowIgniter on the pdo stores (workflow.fact-ignition-once) ─────

  public function test_the_igniter_starts_one_workflow_for_a_fact_delivered_twice_and_two_ticks_in_a_minute(): void {
    $workflow = new NightlyExportWorkflow($this->workflows(), new NoItems());
    $igniter = new WorkflowIgniter($this->ledger(), new PdoTransactionBoundary($this->db), clock: $this->clock);

    $first = $igniter->ignite($workflow, new CronEntryDue('nightly', '2026-10-01T03:00:05+00:00'), 'evt-1');
    $redelivery = $igniter->ignite($workflow, new CronEntryDue('nightly', '2026-10-01T03:00:05+00:00'), 'evt-1');
    $secondTick = $igniter->ignite($workflow, new CronEntryDue('nightly', '2026-10-01T03:00:40+00:00'), 'evt-2');
    $nextMinute = $igniter->ignite($workflow, new CronEntryDue('nightly', '2026-10-01T03:01:00+00:00'), 'evt-3');

    self::assertSame(WorkflowIgnitionOutcome::Ignited, $first->outcome);
    self::assertSame(WorkflowIgnitionOutcome::AlreadyIgnited, $redelivery->outcome);
    self::assertSame(WorkflowIgnitionOutcome::AlreadyIgnited, $secondTick->outcome);
    self::assertSame($first->workflowId, $secondTick->workflowId, 'the loser is told the winner');
    self::assertSame(WorkflowIgnitionOutcome::Ignited, $nextMinute->outcome);
    self::assertSame([$first->workflowId, $nextMinute->workflowId], $workflow->started);
    self::assertSame(2, $this->countRows('ddd_behaviour_workflows'));

    $key = WorkflowIgnitionKey::perMinute($workflow->workflow_kind() . ':nightly', new \DateTimeImmutable('2026-10-01T03:00:05+00:00'));
    self::assertSame($first->workflowId, $this->ledger()->find($key)?->workflowId);
  }

  public function test_a_failed_save_releases_the_key_with_the_rolled_back_transaction(): void {
    $workflow = new NightlyExportWorkflow($this->workflows(), new NoItems());
    $igniter = new WorkflowIgniter($this->ledger(), new PdoTransactionBoundary($this->db), clock: $this->clock);
    $this->db->execute('ALTER TABLE `' . $this->table('ddd_behaviour_workflows') . '` RENAME TO `' . $this->table('ddd_behaviour_workflows_gone') . '`');
    try {
      try {
        $igniter->ignite($workflow, new CronEntryDue('nightly'), 'evt-1');
        self::fail('the save should fail');
      } catch (\Throwable) {
      }
    } finally {
      $this->db->execute('ALTER TABLE `' . $this->table('ddd_behaviour_workflows_gone') . '` RENAME TO `' . $this->table('ddd_behaviour_workflows') . '`');
    }

    self::assertSame(0, $this->countRows('ddd_workflow_ignitions'));
    self::assertSame(WorkflowIgnitionOutcome::Ignited, $igniter->ignite($workflow, new CronEntryDue('nightly'), 'evt-1')->outcome);
  }
}
