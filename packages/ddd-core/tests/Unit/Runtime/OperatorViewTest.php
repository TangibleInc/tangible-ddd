<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\TwoStepProcess;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Ops\IOperatorItemSource;
use TangibleDDD\Runtime\Ops\IOperatorView;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;
use TangibleDDD\Runtime\Ops\PortOperatorView;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Scheduling\WakeRetryPolicy;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Testing\InMemoryDeliveryLedger;
use TangibleDDD\Testing\InMemoryOutboxStore;
use TangibleDDD\Testing\InMemoryProcessStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryWakeupScheduler;
use TangibleDDD\Testing\StaticConsumerIdentity;

require_once dirname(__DIR__) . '/Fixtures/Process/CoreProcesses.php';

/**
 * Register 3.10 / D9: one read-only operator view over every layer, each
 * item with attempts against budget, last error and the repairs that apply.
 */
final class OperatorViewTest extends TestCase {

  private FrozenClock $clock;

  protected function setUp(): void {
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
  }

  public function test_the_layer_vocabulary_and_labels_are_fixed(): void {
    self::assertSame(
      ['relay', 'delivery', 'wakeup', 'process', 'workflow', 'transport'],
      array_map(static fn (Layer $l) => $l->value, Layer::cases())
    );
    foreach (Layer::cases() as $layer) {
      self::assertNotSame('', $layer->label());
    }
    self::assertSame('Relay', Layer::Relay->label());
  }

  public function test_an_item_renders_as_the_d9_array_shape(): void {
    $item = new OperatorItem(Layer::Relay, 'acme', 'e1', 5, 5, 'broker down', $this->clock->now(), ['retry', 'replay', 'discard']);

    self::assertSame([
      'layer' => 'relay',
      'layer_label' => 'Relay',
      'consumer' => 'acme',
      'key' => 'e1',
      'attempts' => 5,
      'budget' => 5,
      'last_error' => 'broker down',
      'first_seen' => '2026-10-01T12:00:00+00:00',
      'repair_actions' => ['retry', 'replay', 'discard'],
    ], $item->to_array());
  }

  public function test_the_port_view_merges_relay_process_delivery_and_wakeup_layers(): void {
    $boundary = new InMemoryTransactionBoundary();
    $outbox = new InMemoryOutboxStore($this->clock, null, $boundary);
    $outbox->append(new OutboxRecord('dead', 'order_placed', 'a', 'c', 1, null, [], $this->clock->now(), max_attempts: 1));
    [$claim] = $outbox->claim(1, $this->clock->now(), 60);
    $outbox->dead_letter($claim, 'poison');

    $processes = new InMemoryProcessStore($this->clock);
    $running = new TwoStepProcess();
    $processes->insert($running);
    $copy = $processes->find(1);
    $copy->advance(status: 'running');
    $processes->save($copy, 1);

    $ledger = new InMemoryDeliveryLedger('acme');
    $ledger->mark_failed('listener:Ship', 'evt-1', 'smtp down', 2);
    $ledger->mark_delivered('listener:Ok', 'evt-1');

    $wakeups = new InMemoryWakeupScheduler($boundary);
    $boundary->enlist($wakeups);
    $boundary->run(fn () => $wakeups->schedule(WakeupIntent::continuation('acme', 7, 0, $this->clock->now())));
    [$w] = $wakeups->claim_due($this->clock->now(), 1, 60);
    $wakeups->retry_later($w, 'lock busy', $this->clock->now()->modify('+2 seconds'));

    $this->clock->advance('+16 minutes');
    $view = new PortOperatorView(new StaticConsumerIdentity('acme'), $outbox, $processes, $this->clock, [$ledger, $wakeups]);
    self::assertInstanceOf(IOperatorView::class, $view);

    $items = $view->list();
    $byLayer = [];
    foreach ($items as $item) {
      $byLayer[$item->layer->value] = $item;
    }

    self::assertSame(['relay', 'delivery', 'wakeup', 'process'], array_keys($byLayer));
    self::assertSame(['dead', 1, 1, 'poison'], [$byLayer['relay']->key, $byLayer['relay']->attempts, $byLayer['relay']->budget, $byLayer['relay']->last_error]);
    self::assertSame(['retry', 'replay', 'discard'], $byLayer['relay']->repairs);
    self::assertSame('listener:Ship@evt-1', $byLayer['delivery']->key);
    self::assertSame([2, 'smtp down'], [$byLayer['delivery']->attempts, $byLayer['delivery']->last_error]);
    self::assertSame('continue:7:0', $byLayer['wakeup']->key);
    self::assertSame([1, WakeRetryPolicy::BUDGET, 'lock busy'], [$byLayer['wakeup']->attempts, $byLayer['wakeup']->budget, $byLayer['wakeup']->last_error]);
    self::assertSame('1', $byLayer['process']->key);
    self::assertSame(['resume_stranded', 'fail_stranded'], $byLayer['process']->repairs);

    self::assertCount(1, $view->list(Layer::Delivery));
    self::assertCount(2, $view->list(null, 2), 'the limit applies to the merged list');
  }

  public function test_a_host_adds_its_own_layers_through_item_sources(): void {
    $transport = new class implements IOperatorItemSource {
      public function items(?Layer $layer, int $limit): array {
        return $layer === null || $layer === Layer::Transport
          ? [new OperatorItem(Layer::Transport, 'txp', 'msg-9', 3, 3, 'handler failed', null, ['retry_failed_message'])]
          : [];
      }
    };

    $items = (new PortOperatorView(new StaticConsumerIdentity('txp'), sources: [$transport]))->list(Layer::Transport);

    self::assertSame('msg-9', $items[0]->key);
    self::assertSame([], (new PortOperatorView(new StaticConsumerIdentity('txp'), sources: [$transport]))->list(Layer::Relay));
  }
}
