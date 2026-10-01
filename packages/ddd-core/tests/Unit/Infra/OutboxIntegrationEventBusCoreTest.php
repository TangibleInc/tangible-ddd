<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Infra;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Events\PublishedFacts;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Core\Tests\Unit\Fixtures\ReminderDue;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Infra\IOutboxRepository;
use TangibleDDD\Infra\Services\FactPublishedInsideProcess;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IFactObserver;
use TangibleDDD\Runtime\IHostPortFactory;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Testing\InMemoryOutboxStore;

/**
 * CONF-2: the core bus over IOutboxStore + IClock (absolute UTC due_at set
 * once), the 0.6 stamps, and an optional IFactObserver whose errors are
 * caught. The 0.6 constructor over an IOutboxRepository keeps writing
 * through it.
 */
final class OutboxIntegrationEventBusCoreTest extends TestCase {

  private FrozenClock $clock;
  private InMemoryOutboxStore $store;

  protected function setUp(): void {
    HostDefaults::resetForTests();
    Correlation::reset();
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
    $this->store = new InMemoryOutboxStore($this->clock);
  }

  protected function tearDown(): void {
    HostDefaults::resetForTests();
    Correlation::reset();
  }

  private function observer(?\Throwable $fail = null): IFactObserver {
    return new class($fail) implements IFactObserver {
      /** @var list<array{IIntegrationEvent, OutboxRecord}> */
      public array $seen = [];
      public function __construct(private ?\Throwable $fail) {}
      public function observe(IIntegrationEvent $e, OutboxRecord $r): void {
        $this->seen[] = [$e, $r];
        if ($this->fail !== null) {
          throw $this->fail;
        }
      }
    };
  }

  private function portBus(?IFactObserver $observer = null, ?OutboxConfig $config = null): OutboxIntegrationEventBus {
    return new OutboxIntegrationEventBus(null, new AcmeConfig(), $observer, $this->clock, $this->store, $config);
  }

  public function test_a_flat_publish_appends_a_record_in_a_fresh_story(): void {
    $event = new OrderPlaced(3, 'sku-3');
    $this->portBus()->publish($event);

    [$id] = $this->store->eventIds();
    $r = $this->store->recordOf($id);
    self::assertSame(OrderPlaced::name(), $r->event_type);
    self::assertSame(OrderPlaced::integration_action(), $r->integration_action);
    self::assertSame(['order_id' => 3, 'sku' => 'sku-3'], $r->payload);
    self::assertSame(1, $r->sequence, 'a flat announce is position 1 of its own story');
    self::assertNull($r->command_id);
    self::assertNotNull($r->correlation_id);
    self::assertEquals($this->clock->now(), $r->due_at);
    self::assertSame($id, PublishedFacts::id_of($event));
  }

  public function test_the_record_carries_the_fact_class_cr_pc_2(): void {
    $this->portBus()->publish(new OrderPlaced(3, 'sku-3'));

    self::assertSame(OrderPlaced::class, $this->store->recordOf($this->store->eventIds()[0])->event_class);
  }

  public function test_a_hand_built_record_has_no_fact_class(): void {
    $r = new OutboxRecord('e', 't', 'a', null, null, null, [], new \DateTimeImmutable());
    self::assertNull($r->event_class);
  }

  public function test_inside_an_act_the_story_sequence_and_raiser_are_stamped(): void {
    Correlation::within(new TraceContext('story-1', null, 4), function (): void {
      Correlation::within(Correlation::current()->for_act('cmd-1', 'X'), fn () => $this->portBus()->publish(new OrderPlaced()));
    });

    $r = $this->store->recordOf($this->store->eventIds()[0]);
    self::assertSame('story-1', $r->correlation_id);
    self::assertSame(5, $r->sequence);
    self::assertSame('cmd-1', $r->command_id);
  }

  public function test_a_delayed_fact_gets_an_absolute_utc_due_time_once(): void {
    $this->portBus(config: new OutboxConfig(max_attempts: 9))->publish(new ReminderDue(2));

    $r = $this->store->recordOf($this->store->eventIds()[0]);
    self::assertEquals(new \DateTimeImmutable('2026-10-01 12:01:30', new \DateTimeZone('UTC')), $r->due_at);
    self::assertSame('UTC', $r->due_at->getTimezone()->getName());
    self::assertTrue($r->is_unique);
    self::assertSame(['user_id' => 2], $r->payload_signature);
    self::assertSame(9, $r->max_attempts);
  }

  public function test_a_saga_step_cannot_announce_directly(): void {
    $this->expectException(FactPublishedInsideProcess::class);
    Correlation::within((new TraceContext('s'))->for_trajectory('7', 'P'), fn () => $this->portBus()->publish(new OrderPlaced()));
  }

  public function test_the_observer_sees_the_fact_and_its_record(): void {
    $observer = $this->observer();
    $this->portBus($observer)->publish(new OrderPlaced(8));

    self::assertCount(1, $observer->seen);
    self::assertSame($this->store->eventIds()[0], $observer->seen[0][1]->event_id);
  }

  public function test_an_observer_error_never_breaks_publication(): void {
    $logger = new RecordingLogger();
    HostDefaults::provide(LoggerInterface::class, $logger);

    $this->portBus($this->observer(new \RuntimeException('index down')))->publish(new OrderPlaced());

    self::assertCount(1, $this->store->eventIds(), 'the fact is still appended');
    self::assertStringContainsString('index down', implode("\n", $logger->messages()));
  }

  public function test_the_0_6_constructor_writes_through_the_repository_and_feeds_the_host_observer(): void {
    $repo = $this->createMock(IOutboxRepository::class);
    $repo->expects(self::once())->method('write')
      ->with(self::isInstanceOf(OrderPlaced::class), self::isType('string'), null)
      ->willReturn('evt-legacy-1');
    $observer = $this->observer();
    HostDefaults::provide(IHostPortFactory::class, new class($observer) implements IHostPortFactory {
      public function __construct(private IFactObserver $o) {}
      public function create(string $port, IConsumerIdentity $consumer, ?object $legacy = null): ?object {
        return $port === IFactObserver::class ? $this->o : null;
      }
    });

    $event = new OrderPlaced();
    (new OutboxIntegrationEventBus($repo, new AcmeConfig()))->publish($event);

    self::assertSame('evt-legacy-1', PublishedFacts::id_of($event));
    self::assertSame('evt-legacy-1', $observer->seen[0][1]->event_id);
  }

  public function test_the_0_6_constructor_cancels_unique_duplicates_first(): void {
    $repo = $this->createMock(IOutboxRepository::class);
    $repo->expects(self::once())->method('cancel_duplicates')->with(ReminderDue::name(), ['user_id' => 4]);
    $repo->method('write')->willReturn('evt-2');

    (new OutboxIntegrationEventBus($repo, new AcmeConfig()))->publish(new ReminderDue(4));
  }
}
