<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use League\Tactician\CommandBus;
use League\Tactician\Handler\CommandHandlerMiddleware;
use League\Tactician\Handler\Mapping\CommandToHandlerMapping;
use League\Tactician\Handler\Mapping\MapByStaticList;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware;
use TangibleDDD\Application\Events\DomainEventsPublishMiddleware;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Application\Persistence\TransactionalCommandMiddleware;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\Effects\CommandHandlers\EnsureCustomerHandler;
use TangibleDDD\Core\Tests\Unit\Fixtures\Effects\Commands\CustomerCreationFailed;
use TangibleDDD\Core\Tests\Unit\Fixtures\Effects\Commands\EnsureCustomerCommand;
use TangibleDDD\Core\Tests\Unit\Fixtures\Effects\FakeStripe;
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistrar;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\Effects\EffectMiddleware;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\EffectState;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Runtime\Effects\ITracksEffectState;
use TangibleDDD\Runtime\Effects\NoEffectHandler;
use TangibleDDD\Runtime\Effects\UnrecordedEffects;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Ids\DeterministicCommandId;
use TangibleDDD\Runtime\OrderedListenerDispatcher;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\PortOperatorView;
use TangibleDDD\Testing\InMemoryAuditSink;
use TangibleDDD\Testing\InMemoryDeliveryLedger;
use TangibleDDD\Testing\InMemoryEffectJournal;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\StaticConsumerIdentity;

/** A journal with no state tracking: the wave-4 contract only. */
final class PlainJournal implements IEffectJournal {

  /** @var array<string, EffectResult> */
  public array $entries = [];

  public function find(string $key): ?EffectResult {
    return $this->entries[$key] ?? null;
  }

  public function store(string $key, EffectResult $r): void {
    $this->entries[$key] = $r;
  }

  public function invalidate(string $key, string $reason): void {
    unset($this->entries[$key]);
  }
}

final class EnsureCustomerOnOrder {
  public function event_class(): string {
    return OrderPlaced::class;
  }

  public function translate(IIntegrationEvent $event): ?ICommand {
    /** @var OrderPlaced $event */
    return new EnsureCustomerCommand($event->sku);
  }
}

/**
 * Wave 5, TXP effect demands E1 (a handler-class effect: the command
 * carries data, an injected IExternalEffectHandler performs and records)
 * and E2 (journal entries are performed, then recorded; the operator view
 * shows "charged but not written down").
 */
final class EffectHandlerAndStateTest extends TestCase {

  private const EVENT_ID = '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d12';
  private const KEY = 'stripe-customer:acc-1';

  private FrozenClock $clock;
  private InMemoryTransactionBoundary $tx;
  private InMemoryEffectJournal $journal;
  private InMemoryAuditSink $audit;
  private FakeStripe $stripe;
  private EnsureCustomerHandler $handler;

  /** @var array<string, object> */
  private array $services = [];

  protected function setUp(): void {
    HostDefaults::reset_for_tests();
    Correlation::reset();
    CustomerCreationFailed::$handled = [];

    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
    $this->tx = new InMemoryTransactionBoundary();
    $this->journal = new InMemoryEffectJournal($this->clock);
    $this->tx->enlist($this->journal);
    $this->audit = new InMemoryAuditSink();
    $this->stripe = new FakeStripe($this->tx);
    $this->handler = new EnsureCustomerHandler($this->stripe, $this->tx);
    $this->services = [EnsureCustomerHandler::class => $this->handler];
    EnsureCustomerCommand::$bus = $this->bus($this->journal);
  }

  protected function tearDown(): void {
    HostDefaults::reset_for_tests();
    Correlation::reset();
    EnsureCustomerCommand::$bus = null;
  }

  private function container(array $entries): ContainerInterface {
    return new class ($entries) implements ContainerInterface {
      public function __construct(private readonly array $entries) {}
      public function get(string $id): mixed {
        return $this->entries[$id] ?? throw new class ("no service $id") extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
      }
      public function has(string $id): bool {
        return isset($this->entries[$id]);
      }
    };
  }

  private function bus(IEffectJournal $journal, ?ContainerInterface $handlers = null, ?CommandToHandlerMapping $mapping = null, bool $with_handlers = true): CommandBus {
    $events = new EventsUnitOfWork();
    $failed = new class implements ICommandHandler {
      public function handle(ICommand $command): void {
        CustomerCreationFailed::$handled[] = $command;
      }
    };
    $terminal = $this->container([CustomerCreationFailed::class => $failed, EventsUnitOfWork::class => $events]);

    return new CommandBus(
      new CorrelationMiddleware(new AcmeConfig(), $events, new Redactor(), $this->audit),
      new EffectMiddleware($journal, $this->tx, $with_handlers ? ($handlers ?? $this->container($this->services)) : null, $mapping),
      new TransactionalCommandMiddleware($this->tx),
      new DomainEventsPublishMiddleware($events, new EventRouter(new OrderedListenerDispatcher(), new class implements IIntegrationEventBus {
        public function publish(IIntegrationEvent $event): void {}
      })),
      new SelfExecutingCommandMiddleware($terminal),
      new CommandHandlerMiddleware($terminal, new MapByStaticList([
        CustomerCreationFailed::class => [CustomerCreationFailed::class, 'handle'],
      ])),
    );
  }

  // ── E1: the handler-class effect ─────────────────────────────────────────

  public function test_the_located_handler_performs_outside_the_transaction_and_records_inside_it(): void {
    $result = (new EnsureCustomerCommand('acc-1'))->send();

    self::assertSame([['account' => 'acc-1', 'key' => self::KEY, 'in_tx' => false]], $this->stripe->calls);
    self::assertSame([['account' => 'acc-1', 'ref' => 'cus_1', 'in_tx' => true]], $this->handler->recorded);
    self::assertInstanceOf(EffectResult::class, $result, 'the bus returns the EffectResult (D11)');
    self::assertSame('cus_1', $result->external_ref);
    self::assertSame('cus_1', $this->journal->find(self::KEY)?->external_ref);
    self::assertSame([EnsureCustomerCommand::class], array_map(static fn ($o) => $o->command_name, $this->audit->opened), 'one act, the effect command');
  }

  public function test_a_handler_record_failure_keeps_the_journal_and_the_retry_records_without_performing(): void {
    $this->handler->record_failures_left = 1;
    try {
      (new EnsureCustomerCommand('acc-1'))->send();
      self::fail('record must throw');
    } catch (\RuntimeException $e) {
      self::assertSame('account save failed', $e->getMessage());
    }
    self::assertSame(1, $this->tx->rollbacks());

    (new EnsureCustomerCommand('acc-1'))->send();

    self::assertCount(1, $this->stripe->calls, 'perform not called again');
    self::assertSame('cus_1', $this->handler->recorded[0]['ref']);
  }

  public function test_an_effect_command_without_a_locatable_handler_is_refused_before_perform(): void {
    EnsureCustomerCommand::$bus = $this->bus($this->journal, $this->container([]));

    try {
      (new EnsureCustomerCommand('acc-1'))->send();
      self::fail('expected NoEffectHandler');
    } catch (NoEffectHandler $e) {
      self::assertStringContainsString(EnsureCustomerHandler::class, $e->getMessage());
    }
    self::assertSame([], $this->stripe->calls);
    self::assertNull($this->journal->find(self::KEY));
  }

  public function test_without_a_handler_locator_the_effect_command_is_refused(): void {
    EnsureCustomerCommand::$bus = $this->bus($this->journal, with_handlers: false);

    $this->expectException(NoEffectHandler::class);
    (new EnsureCustomerCommand('acc-1'))->send();
  }

  public function test_a_located_service_that_is_not_an_effect_handler_is_refused(): void {
    EnsureCustomerCommand::$bus = $this->bus($this->journal, $this->container([EnsureCustomerHandler::class => new \stdClass()]));

    $this->expectException(NoEffectHandler::class);
    (new EnsureCustomerCommand('acc-1'))->send();
  }

  public function test_the_host_mapping_names_the_handler(): void {
    $mapping = new MapByStaticList([EnsureCustomerCommand::class => ['effect.ensure_customer', 'perform']]);
    EnsureCustomerCommand::$bus = $this->bus($this->journal, $this->container(['effect.ensure_customer' => $this->handler]), $mapping);

    (new EnsureCustomerCommand('acc-1'))->send();

    self::assertCount(1, $this->handler->recorded);
  }

  public function test_the_failure_command_of_a_handler_effect_fires_once_at_the_ledger_budget(): void {
    $registry = new SubscriptionRegistry();
    (new SubscriptionRegistrar($registry))->register_listener(new EnsureCustomerOnOrder());
    $ledger = new InMemoryDeliveryLedger();
    $delivery = new IntegrationDelivery($registry, $ledger, 2, new NullLogger());
    $wrapped = IntegrationEnvelope::wrap(['order_id' => 1, 'sku' => 'acc-1'], 'corr-1', 1, self::EVENT_ID);
    $this->stripe->fails = new \RuntimeException('stripe 503');

    $delivery->deliver(OrderPlaced::class, $wrapped);
    $outcome = $delivery->deliver(OrderPlaced::class, $wrapped);

    $subscriber = 'listener:' . EnsureCustomerOnOrder::class;
    self::assertSame([$subscriber], $outcome->exhausted);
    self::assertCount(1, CustomerCreationFailed::$handled);
    self::assertSame('stripe 503', CustomerCreationFailed::$handled[0]->error);
    $failure = array_values(array_filter($this->audit->opened, static fn ($o) => $o->command_name === CustomerCreationFailed::class));
    self::assertSame(DeterministicCommandId::for_fact(self::EVENT_ID, $subscriber . '#failure'), $failure[0]->command_id);
  }

  // ── E2: performed, then recorded ─────────────────────────────────────────

  public function test_the_in_memory_journal_tracks_state(): void {
    self::assertInstanceOf(ITracksEffectState::class, $this->journal);
  }

  public function test_store_writes_performed_and_the_record_transaction_marks_recorded(): void {
    $this->stripe->fails = null;
    $this->handler->record_failures_left = 1;
    try {
      (new EnsureCustomerCommand('acc-1'))->send();
    } catch (\RuntimeException) {
    }

    $entry = $this->journal->find_entry(self::KEY);
    self::assertNotNull($entry);
    self::assertSame(EffectState::Performed, $entry->state, 'performed right after perform(); record() rolled back');
    self::assertEquals($this->clock->now(), $entry->performed_at);
    self::assertNull($entry->recorded_at);
    self::assertFalse($entry->is_recorded());

    $this->clock->advance('5 seconds');
    (new EnsureCustomerCommand('acc-1'))->send();

    $entry = $this->journal->find_entry(self::KEY);
    self::assertSame(EffectState::Recorded, $entry?->state, 'record() committed the mark');
    self::assertEquals($this->clock->now(), $entry->recorded_at);
    self::assertTrue($entry->is_recorded());
    self::assertSame('cus_1', $entry->result->external_ref);
  }

  public function test_a_recorded_entry_neither_performs_nor_records_again(): void {
    (new EnsureCustomerCommand('acc-1'))->send();
    $commits = $this->tx->commits();

    $again = (new EnsureCustomerCommand('acc-1'))->send();

    self::assertCount(1, $this->stripe->calls);
    self::assertCount(1, $this->handler->recorded, 'effectively once: record() is not run again');
    self::assertSame('cus_1', $again->external_ref, 'the journaled result is returned');
    self::assertSame($commits, $this->tx->commits(), 'no transaction opened');
  }

  public function test_a_journal_without_state_tracking_keeps_re_recording(): void {
    $plain = new PlainJournal();
    EnsureCustomerCommand::$bus = $this->bus($plain);

    (new EnsureCustomerCommand('acc-1'))->send();
    (new EnsureCustomerCommand('acc-1'))->send();

    self::assertCount(1, $this->stripe->calls);
    self::assertCount(2, $this->handler->recorded, 'the wave-4 rule: a found entry goes to record()');
  }

  public function test_invalidating_a_recorded_entry_performs_again(): void {
    (new EnsureCustomerCommand('acc-1'))->send();
    $this->tx->run(fn () => $this->journal->invalidate(self::KEY, 'customer deleted upstream'));

    self::assertNull($this->journal->find_entry(self::KEY));
    (new EnsureCustomerCommand('acc-1'))->send();

    self::assertCount(2, $this->stripe->calls);
    self::assertSame(EffectState::Recorded, $this->journal->find_entry(self::KEY)?->state);
  }

  public function test_find_unrecorded_lists_performed_entries_older_than_the_cutoff_oldest_first(): void {
    $this->journal->store('a', new EffectResult());
    $this->clock->advance('10 seconds');
    $this->journal->store('b', new EffectResult());
    $this->tx->run(fn () => $this->journal->mark_recorded('b'));
    $this->journal->store('c', new EffectResult());
    $this->clock->advance('10 seconds');
    $this->journal->store('d', new EffectResult());

    $keys = array_map(static fn ($e) => $e->key, $this->journal->find_unrecorded($this->clock->now()->modify('-5 seconds'), 10));

    self::assertSame(['a', 'c'], $keys);
    self::assertCount(1, $this->journal->find_unrecorded($this->clock->now(), 1), 'capped at the limit');
  }

  public function test_the_operator_view_lists_charged_but_not_written_down_past_the_threshold(): void {
    $this->handler->record_failures_left = PHP_INT_MAX;
    try {
      (new EnsureCustomerCommand('acc-1'))->send();
    } catch (\RuntimeException) {
    }
    $this->journal->store('fresh', new EffectResult());
    $this->journal->store('done', new EffectResult());
    $this->tx->run(fn () => $this->journal->mark_recorded('done'));
    $source = new UnrecordedEffects($this->journal, 'acme', $this->clock, 300);
    $view = new PortOperatorView($this->consumer(), null, null, $this->clock, [$source]);

    self::assertSame([], $view->list(Layer::Effect), 'younger than the threshold');

    $performed_at = $this->clock->now();
    $this->clock->advance('301 seconds');
    $this->journal->store('fresh', new EffectResult());
    $items = $view->list(Layer::Effect);

    self::assertCount(1, $items);
    self::assertSame(Layer::Effect, $items[0]->layer);
    self::assertSame('acme', $items[0]->consumer);
    self::assertSame(self::KEY, $items[0]->key);
    self::assertEquals($performed_at, $items[0]->first_seen);
    self::assertSame(['invalidate'], $items[0]->repairs);
    self::assertStringContainsString('not recorded', (string) $items[0]->last_error);
    self::assertSame([], $source->items(Layer::Delivery, 10), 'only its own layer');
    self::assertCount(1, $view->list(), 'present in the unfiltered view');
  }

  public function test_the_effect_layer_is_part_of_the_vocabulary(): void {
    self::assertSame('effect', Layer::Effect->value);
    self::assertSame('Effect', Layer::Effect->label());
    self::assertNotSame('', Layer::Effect->description());
  }

  private function consumer(): IConsumerIdentity {
    return new StaticConsumerIdentity('acme');
  }
}
