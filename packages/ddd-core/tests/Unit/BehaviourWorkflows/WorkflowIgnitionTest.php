<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\BehaviourWorkflows;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgniter;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgnitionKey;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgnitionOutcome;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Core\Tests\Unit\Fixtures\CronEntryDue;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Core\Tests\Unit\Fixtures\Workflow\MemIgnitionLedger;
use TangibleDDD\Core\Tests\Unit\Fixtures\Workflow\MemWorkflowRepository;
use TangibleDDD\Core\Tests\Unit\Fixtures\Workflow\NightlyExportWorkflow;
use TangibleDDD\Core\Tests\Unit\Fixtures\Workflow\NoItems;
use TangibleDDD\Core\Tests\Unit\Fixtures\Workflow\PerFactWorkflow;
use TangibleDDD\Core\Tests\Unit\Fixtures\Workflow\UndeclaredWorkflow;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Ids\NameBasedUuid;
use TangibleDDD\Testing\InMemoryDeliveryLedger;
use TangibleDDD\Testing\InMemoryTransactionBoundary;

require_once dirname(__DIR__) . '/Fixtures/Workflow/WorkflowFixtures.php';

/**
 * D10 (register 3.11, ruling #78): BehaviourWorkflow ignition from a fact
 * (#[StartsOn] on the workflow handler) through the workflow ignition
 * ledger port with a caller-supplied dedup key; scenario
 * workflow.fact-ignition-once on the mem doubles.
 */
final class WorkflowIgnitionTest extends TestCase {

  private const E1 = '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e01';
  private const E2 = '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e02';
  private const E3 = '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e03';

  private FrozenClock $clock;
  private InMemoryTransactionBoundary $boundary;
  private MemIgnitionLedger $ledger;
  private MemWorkflowRepository $repo;
  private SubscriptionRegistry $registry;
  private WorkflowIgniter $igniter;
  private RecordingLogger $log;

  protected function setUp(): void {
    HostDefaults::resetForTests();
    $this->log = new RecordingLogger();
    HostDefaults::provide(LoggerInterface::class, $this->log);
    Correlation::reset();
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
    $this->boundary = new InMemoryTransactionBoundary();
    $this->ledger = new MemIgnitionLedger($this->clock);
    $this->repo = new MemWorkflowRepository();
    $this->boundary->enlist($this->ledger);
    $this->boundary->enlist($this->repo);
    $this->registry = new SubscriptionRegistry();
    $this->igniter = new WorkflowIgniter($this->ledger, $this->boundary, $this->log);
  }

  protected function tearDown(): void {
    Correlation::reset();
    HostDefaults::resetForTests();
  }

  private function nightly(): NightlyExportWorkflow {
    return new NightlyExportWorkflow($this->repo, new NoItems());
  }

  private function deliver(array $payload, string $eventId): void {
    (new IntegrationDelivery($this->registry, new InMemoryDeliveryLedger(), 5, new \Psr\Log\NullLogger()))
      ->deliver(CronEntryDue::class, IntegrationEnvelope::wrap($payload, 'corr-1', 1, $eventId));
  }

  public function test_register_subscribes_one_ignition_per_starts_on_fact(): void {
    $this->igniter->register($this->nightly(), $this->registry, 'acme');

    $subs = $this->registry->for(CronEntryDue::class);
    self::assertCount(1, $subs);
    self::assertSame(Subscriber::IGNITION, $subs[0]->priority);
    self::assertSame('acme/workflow-ignition:' . NightlyExportWorkflow::class . '@' . CronEntryDue::class, $subs[0]->id);
  }

  public function test_a_workflow_without_starts_on_is_refused_at_registration(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->igniter->register(new UndeclaredWorkflow($this->repo, new NoItems()), $this->registry, 'acme');
  }

  public function test_the_same_fact_delivered_twice_and_two_ticks_in_one_minute_run_once(): void {
    // workflow.fact-ignition-once (mem)
    $wf = $this->nightly();
    $this->igniter->register($wf, $this->registry, 'acme');

    $this->deliver(['entry' => 'nightly', 'due_at' => '2026-10-01T12:00:05+00:00'], self::E1);
    $this->deliver(['entry' => 'nightly', 'due_at' => '2026-10-01T12:00:05+00:00'], self::E1); // redelivery
    $this->deliver(['entry' => 'nightly', 'due_at' => '2026-10-01T12:00:41+00:00'], self::E2); // second tick, same minute

    self::assertCount(1, $this->repo->rows, 'exactly one workflow');
    self::assertSame([1], $wf->started);

    $this->deliver(['entry' => 'nightly', 'due_at' => '2026-10-01T12:01:02+00:00'], self::E3); // next minute
    self::assertCount(2, $this->repo->rows);
    self::assertSame([1, 2], $wf->started);
  }

  public function test_the_ledger_records_the_winner(): void {
    $wf = $this->nightly();
    $fact = new CronEntryDue('nightly', '2026-10-01T12:00:05+00:00');

    $first = $this->igniter->ignite($wf, $fact, self::E1);
    $second = $this->igniter->ignite($wf, new CronEntryDue('nightly', '2026-10-01T12:00:59+00:00'), self::E2);

    self::assertSame(WorkflowIgnitionOutcome::Ignited, $first->outcome);
    self::assertSame(WorkflowIgnitionOutcome::AlreadyIgnited, $second->outcome);
    self::assertSame($first->dedupKey, $second->dedupKey);
    self::assertSame(1, $second->workflowId, 'the loser is told who won');
    $entry = $this->ledger->find($first->dedupKey);
    self::assertSame(1, $entry->workflowId);
    self::assertSame(self::E1, $entry->eventId);
    self::assertSame(NightlyExportWorkflow::class, $entry->kind);
  }

  public function test_a_declined_fact_claims_nothing(): void {
    $result = $this->igniter->ignite($this->nightly(), new CronEntryDue('skip'), self::E1);

    self::assertSame(WorkflowIgnitionOutcome::Declined, $result->outcome);
    self::assertSame([], $this->ledger->rows);
  }

  public function test_a_failed_save_rolls_the_claim_back_so_a_redelivery_ignites(): void {
    $wf = $this->nightly();
    $fact = new CronEntryDue('nightly', '2026-10-01T12:00:05+00:00');
    $this->repo->failNextSave = new \RuntimeException('db down');

    try {
      $this->igniter->ignite($wf, $fact, self::E1);
      self::fail('expected the save failure');
    } catch (\RuntimeException) {
    }
    self::assertSame([], $this->ledger->rows, 'the claim committed with the workflow, or not at all');

    self::assertSame(WorkflowIgnitionOutcome::Ignited, $this->igniter->ignite($wf, $fact, self::E1)->outcome);
    self::assertSame([1], $wf->started);
  }

  public function test_a_failing_start_after_commit_keeps_the_single_ignition_and_is_logged(): void {
    $wf = $this->nightly();
    $wf->failStart = new \RuntimeException('behaviour exploded');

    $result = $this->igniter->ignite($wf, new CronEntryDue('nightly'), self::E1);

    self::assertSame(WorkflowIgnitionOutcome::Ignited, $result->outcome);
    self::assertNotNull($result->startError);
    self::assertCount(1, $this->repo->rows);
    self::assertNotSame([], array_filter($this->log->records, static fn (array $r) => $r['level'] === 'error'));
  }

  public function test_a_failed_start_is_redelivered_and_the_workflow_runs_exactly_once(): void {
    $wf = $this->nightly();
    $this->igniter->register($wf, $this->registry, 'acme');
    $ledger = new InMemoryDeliveryLedger();
    $delivery = new IntegrationDelivery($this->registry, $ledger, 5, new \Psr\Log\NullLogger());
    $fact = ['entry' => 'nightly', 'due_at' => '2026-10-01T12:00:05+00:00'];

    $wf->failStart = new \RuntimeException('transient: db gone away');
    $first = $delivery->deliver(CronEntryDue::class, IntegrationEnvelope::wrap($fact, 'corr-1', 1, self::E1));
    self::assertNotSame([], $first->failed, 'the start failure reaches the delivery ledger, so it retries');
    self::assertCount(1, $this->repo->rows, 'the ignition itself committed');
    self::assertSame([], $wf->started);

    $wf->failStart = null;
    $delivery->deliver(CronEntryDue::class, IntegrationEnvelope::wrap($fact, 'corr-1', 1, self::E1)); // the retry
    $delivery->deliver(CronEntryDue::class, IntegrationEnvelope::wrap($fact, 'corr-1', 1, self::E1)); // succeeded: skipped
    $this->deliver(['entry' => 'nightly', 'due_at' => '2026-10-01T12:00:41+00:00'], self::E2); // second tick, same minute

    self::assertCount(1, $this->repo->rows);
    self::assertSame([1], $wf->started, 'the saved workflow is started once, by the retry');
  }

  public function test_a_later_tick_restarts_a_workflow_whose_start_failed(): void {
    $wf = $this->nightly();
    $wf->failStart = new \RuntimeException('behaviour exploded');
    $this->igniter->ignite($wf, new CronEntryDue('nightly', '2026-10-01T12:00:05+00:00'), self::E1);
    $wf->failStart = null;

    $second = $this->igniter->ignite($wf, new CronEntryDue('nightly', '2026-10-01T12:00:41+00:00'), self::E2);
    $third = $this->igniter->ignite($wf, new CronEntryDue('nightly', '2026-10-01T12:00:05+00:00'), self::E1);

    self::assertSame(WorkflowIgnitionOutcome::Restarted, $second->outcome);
    self::assertSame(1, $second->workflowId);
    self::assertSame(WorkflowIgnitionOutcome::AlreadyIgnited, $third->outcome, 'started: nothing left to do');
    self::assertSame([1], $wf->started);
    self::assertCount(1, $this->repo->rows);
  }

  public function test_a_stale_claim_without_a_workflow_is_reclaimed_when_there_is_no_boundary(): void {
    // No boundary: a crash between claim() and attach() leaves the key claimed with no workflow.
    $igniter = new WorkflowIgniter($this->ledger, null, $this->log, $this->clock);
    $wf = $this->nightly();
    $fact = new CronEntryDue('nightly', '2026-10-01T12:00:05+00:00');
    $key = $wf->ignition_key($fact, self::E1);
    $this->ledger->claim($key, $wf->workflow_kind(), self::E1);

    $fresh = $igniter->ignite($wf, $fact, self::E2);
    self::assertSame(WorkflowIgnitionOutcome::AlreadyIgnited, $fresh->outcome, 'a fresh claim may still be in flight');
    self::assertNull($fresh->workflowId);

    $this->clock->advance('PT16M');
    $stale = $igniter->ignite($wf, $fact, self::E2);
    self::assertSame(WorkflowIgnitionOutcome::Ignited, $stale->outcome);
    self::assertSame([1], $wf->started);
    self::assertSame(1, $this->ledger->find($key)->workflowId);
  }

  public function test_a_completed_start_attaches_the_workflow_to_its_start_marker(): void {
    $result = $this->igniter->ignite($this->nightly(), new CronEntryDue('nightly'), self::E1);

    $marker = $this->ledger->find(WorkflowIgnitionKey::startMarker($result->dedupKey));
    self::assertNotNull($marker);
    self::assertSame(1, $marker->workflowId, 'an attached marker = the start completed');
  }

  public function test_a_worker_that_died_inside_the_start_keeps_redelivering_and_a_stale_marker_is_reclaimed(): void {
    $wf = $this->nightly();
    $igniter = new WorkflowIgniter($this->ledger, $this->boundary, $this->log, $this->clock);
    $igniter->register($wf, $this->registry, 'acme');
    $fact = ['entry' => 'nightly', 'due_at' => '2026-10-01T12:00:05+00:00'];
    $key = $wf->ignition_key(new CronEntryDue('nightly', $fact['due_at']), self::E1);

    // The dead worker's state: ignition committed, start marker claimed, no start completed.
    $wf->failStart = new \RuntimeException('x');
    $igniter->ignite($wf, new CronEntryDue('nightly', $fact['due_at']), self::E1);
    $wf->failStart = null;
    $this->ledger->claim(WorkflowIgnitionKey::startMarker($key), $wf->workflow_kind(), self::E1);

    $delivery = new IntegrationDelivery($this->registry, new InMemoryDeliveryLedger(), 5, new \Psr\Log\NullLogger());
    $pending = $delivery->deliver(CronEntryDue::class, IntegrationEnvelope::wrap($fact, 'corr-1', 1, self::E1));
    self::assertNotSame([], $pending->failed, 'a start in flight is not reported as done: the delivery retries');
    self::assertSame([], $wf->started);
    $direct = $igniter->ignite($wf, new CronEntryDue('nightly', '2026-10-01T12:00:41+00:00'), self::E2);
    self::assertSame(WorkflowIgnitionOutcome::AlreadyIgnited, $direct->outcome);
    self::assertTrue($direct->startPending);

    $this->clock->advance('PT16M');
    $retry = $delivery->deliver(CronEntryDue::class, IntegrationEnvelope::wrap($fact, 'corr-1', 1, self::E1));
    self::assertSame([], $retry->failed);
    self::assertSame([1], $wf->started, 'the stale marker was reclaimed and the workflow started');
    self::assertSame(1, $this->ledger->find(WorkflowIgnitionKey::startMarker($key))->workflowId);
    self::assertNotSame([], array_filter($this->log->records, static fn (array $r) => $r['level'] === 'warning'));

    $after = $igniter->ignite($wf, new CronEntryDue('nightly', '2026-10-01T12:00:41+00:00'), self::E2);
    self::assertSame(WorkflowIgnitionOutcome::AlreadyIgnited, $after->outcome);
    self::assertFalse($after->startPending);
    self::assertSame([1], $wf->started);
  }

  public function test_a_stale_marker_of_a_finished_workflow_is_not_restarted(): void {
    $wf = $this->nightly();
    $igniter = new WorkflowIgniter($this->ledger, $this->boundary, $this->log, $this->clock);
    $wf->failStart = new \RuntimeException('x');
    $first = $igniter->ignite($wf, new CronEntryDue('nightly', '2026-10-01T12:00:05+00:00'), self::E1);
    $wf->failStart = null;
    $this->ledger->claim(WorkflowIgnitionKey::startMarker($first->dedupKey), $wf->workflow_kind(), self::E1);
    $this->repo->rows[1]->fail();
    $this->clock->advance('PT16M');

    $again = $igniter->ignite($wf, new CronEntryDue('nightly', '2026-10-01T12:00:41+00:00'), self::E2);

    self::assertSame(WorkflowIgnitionOutcome::AlreadyIgnited, $again->outcome);
    self::assertFalse($again->startPending);
    self::assertSame([], $wf->started);
  }

  public function test_inside_an_open_transaction_the_ignition_and_its_start_marker_roll_back_with_it(): void {
    // Documented: ignite() joins an open transaction, and start_ignited() then runs inside it.
    $wf = $this->nightly();
    try {
      $this->boundary->run(function () use ($wf): void {
        $this->igniter->ignite($wf, new CronEntryDue('nightly'), self::E1);
        throw new \RuntimeException('outer rollback');
      });
    } catch (\RuntimeException) {
    }
    self::assertSame([], $this->ledger->rows, 'claim, attach and start marker rolled back together');
    self::assertSame([], $this->repo->rows);

    self::assertSame(WorkflowIgnitionOutcome::Ignited, $this->igniter->ignite($wf, new CronEntryDue('nightly'), self::E1)->outcome);
  }

  public function test_the_default_key_is_uuid5_of_the_event_id_and_kind(): void {
    $wf = new PerFactWorkflow($this->repo, new NoItems());
    $this->igniter->register($wf, $this->registry, 'acme');

    $this->deliver(['entry' => 'a'], self::E1);
    $this->deliver(['entry' => 'a'], self::E1);
    $this->deliver(['entry' => 'b'], self::E2);

    self::assertSame([1, 2], $wf->started);
    self::assertNotNull($this->ledger->find(NameBasedUuid::v5(self::E1, PerFactWorkflow::class)));
    self::assertSame(WorkflowIgnitionKey::forFact(self::E2, PerFactWorkflow::class), NameBasedUuid::v5(self::E2, PerFactWorkflow::class));
  }

  public function test_an_id_less_fact_with_the_default_key_ignites_without_dedup_and_warns(): void {
    $wf = new PerFactWorkflow($this->repo, new NoItems());

    $result = $this->igniter->ignite($wf, new CronEntryDue('a'), '');

    self::assertSame(WorkflowIgnitionOutcome::Ignited, $result->outcome);
    self::assertNull($result->dedupKey);
    self::assertSame([], $this->ledger->rows);
    self::assertNotSame([], array_filter($this->log->records, static fn (array $r) => $r['level'] === 'warning'));
  }

  public function test_per_minute_keys(): void {
    $berlin = new \DateTimeImmutable('2026-10-01 14:00:59', new \DateTimeZone('Europe/Berlin'));
    self::assertSame('wf:2026-10-01T12:00Z', WorkflowIgnitionKey::perMinute('wf', $berlin));
  }
}
