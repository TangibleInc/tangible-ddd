<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use TangibleDDD\Application\BehaviourWorkflows\IWorkflowIgnitionLedger;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgniter;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Symfony\Messenger\IntegrationFactMessage;
use TangibleDDD\Symfony\Persistence\DbalWorkflowIgnitionLedger;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\AnnounceCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\CronTicked;
use TangibleDDD\Symfony\Tests\Kernel\App\Workflows\NightlyReportWorkflow;

/**
 * D10 on ddd-symfony (register 3.11, ruling #78; scenario
 * workflow.fact-ignition-once): a behaviour workflow service implementing
 * IStartsFromFact with #[StartsOn] is compiled into the subscription map as
 * a core WorkflowIgniter subscriber (priority IGNITION), deduped through
 * DbalWorkflowIgnitionLedger (`ddd_workflow_ignitions`). The fact goes the
 * real way: command → outbox → ddd:relay → messenger:consume ddd_facts.
 */
final class WorkflowIgnitionTest extends KernelTestBase {

  public function test_the_ledger_and_igniter_are_the_core_d10_contract(): void {
    $c = self::getContainer();

    self::assertInstanceOf(DbalWorkflowIgnitionLedger::class, $c->get('test.workflow_ledger'));
    self::assertInstanceOf(IWorkflowIgnitionLedger::class, $c->get('test.workflow_ledger'));
    self::assertInstanceOf(WorkflowIgniter::class, $c->get('test.workflow_igniter'));

    $subscribers = $c->get('test.subscriptions')->for(CronTicked::class);
    $ids = array_map(static fn (Subscriber $s) => $s->id, $subscribers);
    self::assertContains('sfk/workflow-ignition:' . NightlyReportWorkflow::class . '@' . CronTicked::class, $ids);
    self::assertSame(Subscriber::IGNITION, $subscribers[array_search('sfk/workflow-ignition:' . NightlyReportWorkflow::class . '@' . CronTicked::class, $ids, true)]->priority);
  }

  public function test_the_same_fact_twice_and_two_ticks_in_one_minute_run_one_workflow(): void {
    // 1. A tick at 03:00:10 ignites and starts the workflow.
    $first = $this->tick('nightly', '2026-10-01T03:00:10Z');
    self::assertSame(1, $this->countRows('SELECT count(*) FROM ddd_behaviour_workflows'));
    self::assertSame(1, $this->countRows("SELECT count(*) FROM app_listener_runs WHERE listener = 'workflow-start'"));
    $workflowId = (int) $this->db->fetchOne('SELECT id FROM ddd_behaviour_workflows');
    self::assertTrue((bool) $this->db->fetchOne('SELECT is_complete FROM ddd_behaviour_workflows WHERE id = ?', [$workflowId]), 'the start ran the workflow');

    $key = NightlyReportWorkflow::class . ':nightly:2026-10-01T03:00Z';
    $entry = self::getContainer()->get('test.workflow_ledger')->find($key);
    self::assertNotNull($entry, 'the (workflow, minute) key is in the ledger');
    self::assertSame($workflowId, $entry->workflowId);
    self::assertSame($first['event_id'], $entry->eventId);

    // 2. The same fact delivered again (a duplicate message).
    $this->redeliver($first);
    self::assertSame(1, $this->countRows('SELECT count(*) FROM ddd_behaviour_workflows'));
    self::assertSame(1, $this->countRows("SELECT count(*) FROM app_listener_runs WHERE listener = 'workflow-start'"));

    // 3. A second, different tick in the same minute.
    $this->tick('nightly', '2026-10-01T03:00:50Z');
    self::assertSame(1, $this->countRows('SELECT count(*) FROM ddd_behaviour_workflows'), 'exactly one workflow run per minute');
    self::assertSame(1, $this->countRows("SELECT count(*) FROM app_listener_runs WHERE listener = 'workflow-start'"));

    // 4. The next minute runs again; a declined entry runs nothing.
    $this->tick('nightly', '2026-10-01T03:01:00Z');
    $this->tick('skip', '2026-10-01T03:01:00Z');
    self::assertSame(2, $this->countRows('SELECT count(*) FROM ddd_behaviour_workflows'));
    self::assertSame(2, $this->countRows("SELECT count(*) FROM app_listener_runs WHERE listener = 'workflow-start'"));
    self::assertSame(0, $this->countRows('SELECT count(*) FROM messenger_messages'), 'every delivery acked');
  }

  /**
   * Announce a CronTicked through the outbox, relay it and consume it.
   *
   * @return array<string, mixed> the outbox row
   */
  private function tick(string $entry, string $dueAt): array {
    (new AnnounceCommand(new CronTicked($entry, $dueAt)))->send();
    $row = $this->db->fetchAssociative("SELECT * FROM ddd_outbox WHERE status = 'pending' ORDER BY id DESC LIMIT 1");
    self::assertIsArray($row);
    $relay = $this->console('ddd:relay', ['--once' => true]);
    self::assertStringContainsString('accepted 1', $relay->getDisplay());
    $this->consumeFacts();
    return $row;
  }

  /** @param array<string, mixed> $row */
  private function redeliver(array $row): void {
    $payload = IntegrationEnvelope::unwrap((array) json_decode((string) $row['payload'], true))->payload;
    $wrapped = IntegrationEnvelope::wrap($payload, (string) $row['correlation_id'], (int) $row['sequence'], (string) $row['event_id']);
    self::getContainer()->get('messenger.transport.ddd_facts')->send(new Envelope(
      new IntegrationFactMessage('sfk', (string) $row['event_id'], (string) $row['event_type'], CronTicked::class, (string) $row['integration_action'], $wrapped),
      [new BusNameStamp('messenger.bus.default')],
    ));
    $this->consumeFacts();
  }

  private function consumeFacts(): void {
    $consume = $this->console('messenger:consume', ['receivers' => ['ddd_facts'], '--limit' => 1, '--time-limit' => 10]);
    self::assertSame(0, $consume->getStatusCode(), $consume->getDisplay() . $consume->getErrorOutput());
  }
}
