<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Conformance\Fixtures\Process\AwaitOrderProcess;
use TangibleDDD\Conformance\Fixtures\Process\OrderedWidgetProcess;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\Fixtures\Process\WidgetOrdered;
use TangibleDDD\Conformance\ProcessHost;
use TangibleDDD\Conformance\ProcessScenarioCase;
use TangibleDDD\Conformance\TransportedFact;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Runtime\Delivery\Subscriber;

/**
 * The `.process` variants (register section 4, ruling on #71): the same
 * delivery and replay mechanics as the process-free ids, with the REAL
 * runner as the ignition and resume subscribers. One fact, WidgetOrdered,
 * ignites OrderedWidgetProcess and resumes a suspended AwaitOrderProcess.
 */
abstract class ProcessDeliveryScenarios extends ProcessScenarioCase {

  /** @var array<string, int> stub subscriber id => runs */
  protected array $runs = [];

  #[Group('relay.replay-keeps-identity.process')]
  #[TestDox('relay.replay-keeps-identity.process: replaying a dead letter whose fact ignites a process keeps the event_id and ignites no second process')]
  public function test_relay_replay_keeps_identity_process(): void {
    $processes = $this->processes();
    $processes->wire_processes([[OrderedWidgetProcess::class, WidgetOrdered::class]], []);
    $fact = new WidgetOrdered('w-1');
    $id = $this->publish($fact);

    // An earlier delivery reached the ignition but its ledger write was
    // lost: only the ignition gate (ignition_key) remembers it.
    $processes->worker()->runner()->ignite(OrderedWidgetProcess::class, $fact, $id);
    $ignited = $processes->process_ids(OrderedWidgetProcess::class);
    self::assertCount(1, $ignited);

    for ($n = 0; $n < 5; $n++) {
      $this->host->reject_next_submission();
      $this->host->relay_once();
      $this->host->advance_clock(self::PAST_ANY_LEASE);
    }
    $admin = $this->host->outbox_admin();
    $letters = $admin->dead_letters(10);
    self::assertCount(1, $letters, 'dead-lettered after the relay budget');
    self::assertSame($id, $letters[0]->event_id);

    $admin->replay($letters[0]->dlq_id);
    self::assertSame([], $admin->dead_letters(10), 'the DLQ row is deleted');

    self::assertSame([$id], $this->host->relay_once()->accepted, 'relayed under the SAME event_id');
    self::assertSame([$id], array_map(static fn (TransportedFact $t) => $t->event_id, $this->host->transported()));

    $outcomes = $this->host->deliver_transported(WidgetOrdered::class);
    self::assertCount(1, $outcomes);
    self::assertTrue($outcomes[0]->is_complete(), 'the ignition subscriber ran and returned quietly');
    self::assertSame($ignited, $processes->process_ids(OrderedWidgetProcess::class), 'no second process');
    self::assertSame($id, $this->row($ignited[0])->ignited_by);
    self::assertNotNull($this->row($ignited[0])->ignition_key);
    self::assertSame(1, ProcessJournal::runs('open:w-1'), 'the process ran its step once');
  }

  #[Group('delivery.double-delivery.process')]
  #[TestDox('delivery.double-delivery.process: the same igniting and awaited fact delivered twice ignites once and resumes once')]
  public function test_delivery_double_delivery_process(): void {
    $processes = $this->wire();
    $waiting = $this->start(new AwaitOrderProcess('w-1'));
    self::assertSame('suspended', $this->row($waiting)->status);
    $eventId = Uuid::v4();
    $fact = new WidgetOrdered('w-1');
    $wrapped = self::wrap($fact, $eventId);

    $first = $this->host->deliver(WidgetOrdered::class, $wrapped);
    $second = $this->host->deliver(WidgetOrdered::class, $wrapped);

    self::assertTrue($first->is_complete());
    self::assertCount(2, $first->delivered, 'the ignition and the resume subscriber');
    self::assertSame([], $second->delivered, 'nothing runs twice');
    self::assertEqualsCanonicalizing($first->delivered, $second->skipped, 'both are ledger hits');

    // Without the ledger (a lost ledger write): the gates themselves hold.
    $runner = $processes->worker()->runner();
    $runner->ignite(OrderedWidgetProcess::class, $fact, $eventId);
    $runner->resume($fact);

    self::assertCount(1, $processes->process_ids(OrderedWidgetProcess::class), 'ignition once');
    self::assertSame(1, ProcessJournal::runs('open:w-1'));
    self::assertSame(1, ProcessJournal::runs('after_order:w-1'), 'resume once');
    self::assertSame('completed', $this->row($waiting)->status);
  }

  #[Group('delivery.subscriber-isolation.process')]
  #[TestDox('delivery.subscriber-isolation.process: listener B throws once; the real ignition and resume still run once; the retry runs only B')]
  public function test_delivery_subscriber_isolation_process(): void {
    $processes = $this->wire();
    $waiting = $this->start(new AwaitOrderProcess('w-1'));
    $this->subscribe('conformance.a');
    $this->subscribe('conformance.b', failTimes: 1);
    $wrapped = self::wrap(new WidgetOrdered('w-1'), Uuid::v4());

    $first = $this->host->deliver(WidgetOrdered::class, $wrapped);

    self::assertSame(['conformance.b'], $first->failed, 'B\'s throw stops nobody');
    self::assertContains('conformance.a', $first->delivered);
    self::assertCount(3, $first->delivered, 'A, the ignition and the resume');
    self::assertCount(1, $processes->process_ids(OrderedWidgetProcess::class), 'ignited');
    self::assertSame('completed', $this->row($waiting)->status, 'resumed');

    $retry = $this->host->deliver(WidgetOrdered::class, $wrapped);

    self::assertSame(['conformance.b'], $retry->delivered, 'the retry runs only B');
    self::assertEqualsCanonicalizing($first->delivered, $retry->skipped);
    self::assertTrue($retry->is_complete());
    self::assertSame(['conformance.a' => 1, 'conformance.b' => 2], $this->runs);
    self::assertCount(1, $processes->process_ids(OrderedWidgetProcess::class), 'still one ignition');
    self::assertSame(1, ProcessJournal::runs('open:w-1'));
    self::assertSame(1, ProcessJournal::runs('after_order:w-1'), 'still one resume');
  }

  #[Group('delivery.phase-order.process')]
  #[TestDox('delivery.phase-order.process: one fact ignites B and resumes suspended A; listener, then ignition, then resume')]
  public function test_delivery_phase_order_process(): void {
    $this->wire();
    $waiting = $this->start(new AwaitOrderProcess('w-1'));
    // Registered AFTER the runner's subscribers: priority, not registration order, decides.
    $this->host->subscriptions()->add(new Subscriber('conformance.listener', Subscriber::LISTENER, WidgetOrdered::class,
      static function (): void { ProcessJournal::step('listener'); }));
    ProcessJournal::$steps = [];

    $outcome = $this->host->deliver(WidgetOrdered::class, self::wrap(new WidgetOrdered('w-1'), Uuid::v4()));

    self::assertTrue($outcome->is_complete());
    self::assertSame(['listener', 'open:w-1', 'after_order:w-1'], ProcessJournal::$steps, 'listener → ignition (B first) → resume (A)');
    self::assertSame('completed', $this->row($waiting)->status);
  }

  // ── helpers ──────────────────────────────────────────────────────────────

  /** WidgetOrdered ignites OrderedWidgetProcess and resumes AwaitOrderProcess. */
  protected function wire(): ProcessHost {
    $processes = $this->processes();
    $processes->wire_processes([[OrderedWidgetProcess::class, WidgetOrdered::class]], [WidgetOrdered::class]);
    return $processes;
  }

  protected function subscribe(string $id, int $failTimes = 0): void {
    $this->runs[$id] = 0;
    $this->host->subscriptions()->add(new Subscriber($id, Subscriber::LISTENER, WidgetOrdered::class, function () use ($id, $failTimes): void {
      $this->runs[$id]++;
      if ($this->runs[$id] <= $failTimes) {
        throw new \RuntimeException("$id fails on run {$this->runs[$id]}");
      }
    }));
  }
}
