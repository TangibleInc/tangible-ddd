<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use League\Tactician\CommandBus;
use League\Tactician\Handler\CommandHandlerMiddleware;
use League\Tactician\Handler\Mapping\MapByStaticList;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;
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
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistrar;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\Effects\EffectInsideTransaction;
use TangibleDDD\Runtime\Effects\EffectMiddleware;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Runtime\Effects\IExternalEffectCommand;
use TangibleDDD\Runtime\Effects\NoEffectJournal;
use TangibleDDD\Runtime\Effects\RecordEffect;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Ids\DeterministicCommandId;
use TangibleDDD\Runtime\OrderedListenerDispatcher;
use TangibleDDD\Testing\InMemoryAuditSink;
use TangibleDDD\Testing\InMemoryDeliveryLedger;
use TangibleDDD\Testing\InMemoryEffectJournal;
use TangibleDDD\Testing\InMemoryTransactionBoundary;

/** D1 fixture: a Cloudflare purge (TXP domains). */
final class PurgeZone implements IExternalEffectCommand {

  public static ?CommandBus $bus = null;
  public static ?InMemoryTransactionBoundary $tx = null;
  public static int $performs = 0;
  public static ?\Throwable $performFails = null;
  public static int $recordFailuresLeft = 0;
  /** @var list<array{result: EffectResult, in_tx: bool}> */
  public static array $recorded = [];
  /** @var list<bool> */
  public static array $performInTx = [];

  public function __construct(public readonly string $zone) {}

  public function send(): mixed {
    return self::$bus->handle($this);
  }

  public function idempotencyKey(): string {
    return "cf-purge:{$this->zone}";
  }

  public function perform(): EffectResult {
    self::$performInTx[] = self::$tx?->isActive() ?? false;
    if (self::$performFails !== null) {
      throw self::$performFails;
    }
    self::$performs++;
    return new EffectResult(['purge_id' => 'p-' . self::$performs], 'cf-' . self::$performs);
  }

  public function record(EffectResult $r): void {
    if (self::$recordFailuresLeft > 0) {
      self::$recordFailuresLeft--;
      throw new \RuntimeException('domain write failed');
    }
    self::$recorded[] = ['result' => $r, 'in_tx' => self::$tx?->isActive() ?? false];
  }

  public function failureCommand(\Throwable $last): ?ICommand {
    return new PurgeFailed($this->zone, $last->getMessage());
  }
}

final class PurgeFailed implements ICommand, ITransactionalCommand {

  /** @var list<self> */
  public static array $handled = [];

  public function __construct(public readonly string $zone, public readonly string $error) {}

  public function send(): mixed {
    return PurgeZone::$bus->handle($this);
  }
}

/** The repair command (TXP RepairStripeCustomer shape): invalidates in its own transaction. */
final class RepairPurge implements ICommand, ITransactionalCommand {

  public function __construct(public readonly string $zone, public readonly bool $failAfterInvalidate = false) {}

  public function send(): mixed {
    return PurgeZone::$bus->handle($this);
  }
}

final class PurgeOnOrder {
  public function event_class(): string {
    return OrderPlaced::class;
  }

  public function translate(IIntegrationEvent $event): ?ICommand {
    /** @var OrderPlaced $event */
    return new PurgeZone($event->sku);
  }
}

/**
 * D1 ExternalEffect (register 3.8, 3.11, 5.1; `effect.journal-reuse` on mem).
 */
final class EffectMiddlewareTest extends TestCase {

  private const EVENT_ID = '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d11';

  private InMemoryTransactionBoundary $tx;
  private InMemoryEffectJournal $journal;
  private InMemoryAuditSink $audit;

  protected function setUp(): void {
    HostDefaults::resetForTests();
    Correlation::reset();
    PurgeZone::$performs = 0;
    PurgeZone::$performFails = null;
    PurgeZone::$recordFailuresLeft = 0;
    PurgeZone::$recorded = [];
    PurgeZone::$performInTx = [];
    PurgeFailed::$handled = [];

    $this->tx = new InMemoryTransactionBoundary();
    $this->journal = new InMemoryEffectJournal();
    $this->tx->enlist($this->journal);
    $this->audit = new InMemoryAuditSink();
    PurgeZone::$tx = $this->tx;
    PurgeZone::$bus = $this->bus($this->journal);
  }

  protected function tearDown(): void {
    HostDefaults::resetForTests();
    Correlation::reset();
    PurgeZone::$bus = null;
    PurgeZone::$tx = null;
  }

  private function bus(?IEffectJournal $journal): CommandBus {
    $events = new EventsUnitOfWork();
    $journalRef = $this->journal;
    $handlers = [
      PurgeFailed::class => new class implements ICommandHandler {
        public function handle(ICommand $command): void {
          PurgeFailed::$handled[] = $command;
        }
      },
      RepairPurge::class => new class ($journalRef) implements ICommandHandler {
        public function __construct(private readonly IEffectJournal $journal) {}
        public function handle(ICommand $command): void {
          /** @var RepairPurge $command */
          $this->journal->invalidate("cf-purge:{$command->zone}", 'operator repair: zone recreated');
          if ($command->failAfterInvalidate) {
            throw new \RuntimeException('repair failed');
          }
        }
      },
    ];
    $container = new class ($handlers, $events) implements ContainerInterface {
      public function __construct(private readonly array $handlers, private readonly EventsUnitOfWork $events) {}
      public function get(string $id): mixed {
        return $id === EventsUnitOfWork::class ? $this->events : $this->handlers[$id];
      }
      public function has(string $id): bool {
        return $id === EventsUnitOfWork::class || isset($this->handlers[$id]);
      }
    };

    return new CommandBus(
      new CorrelationMiddleware(new AcmeConfig(), $events, new Redactor(), $this->audit),
      new EffectMiddleware($journal, $this->tx),
      new TransactionalCommandMiddleware($this->tx),
      new DomainEventsPublishMiddleware($events, new EventRouter(new OrderedListenerDispatcher(), new class implements IIntegrationEventBus {
        public function publish(IIntegrationEvent $event): void {}
      })),
      new SelfExecutingCommandMiddleware($container),
      new CommandHandlerMiddleware($container, new MapByStaticList([
        PurgeFailed::class => [PurgeFailed::class, 'handle'],
        RepairPurge::class => [RepairPurge::class, 'handle'],
      ])),
    );
  }

  public function test_perform_runs_outside_the_transaction_record_inside_it_and_the_result_is_journaled_and_returned(): void {
    $result = (new PurgeZone('example.com'))->send();

    self::assertSame([false], PurgeZone::$performInTx);
    self::assertCount(1, PurgeZone::$recorded);
    self::assertTrue(PurgeZone::$recorded[0]['in_tx']);
    self::assertSame($result, PurgeZone::$recorded[0]['result']);
    self::assertSame(['purge_id' => 'p-1'], $result->data);
    self::assertSame('cf-1', $this->journal->find('cf-purge:example.com')?->externalRef);
    self::assertSame(1, $this->tx->commits());
    self::assertSame([PurgeZone::class], array_map(static fn ($o) => $o->commandName, $this->audit->opened), 'one act, the effect command');
  }

  public function test_a_record_failure_keeps_the_journal_and_a_retry_reuses_the_journaled_result(): void {
    PurgeZone::$recordFailuresLeft = 1;
    try {
      (new PurgeZone('example.com'))->send();
      self::fail('record must throw');
    } catch (\RuntimeException $e) {
      self::assertSame('domain write failed', $e->getMessage());
    }
    self::assertSame(1, $this->tx->rollbacks());
    self::assertNotNull($this->journal->find('cf-purge:example.com'), 'journaled outside the rolled-back transaction');

    $result = (new PurgeZone('example.com'))->send();

    self::assertSame(1, PurgeZone::$performs, 'perform not called again');
    self::assertSame(['purge_id' => 'p-1'], $result->data);
    self::assertSame(['purge_id' => 'p-1'], PurgeZone::$recorded[0]['result']->data);
  }

  public function test_re_dispatching_under_a_new_command_id_does_not_bypass_the_journal(): void {
    (new PurgeZone('example.com'))->send();
    (new PurgeZone('example.com'))->send();

    self::assertSame(1, PurgeZone::$performs);
    self::assertCount(2, PurgeZone::$recorded);
    self::assertNotSame($this->audit->opened[0]->commandId, $this->audit->opened[1]->commandId);
  }

  public function test_invalidate_in_the_repair_commands_transaction_makes_the_effect_perform_again(): void {
    (new PurgeZone('example.com'))->send();

    // A failed repair rolls its invalidation back.
    try {
      (new RepairPurge('example.com', failAfterInvalidate: true))->send();
      self::fail('repair must throw');
    } catch (\RuntimeException) {
    }
    self::assertNotNull($this->journal->find('cf-purge:example.com'));

    (new RepairPurge('example.com'))->send();
    self::assertNull($this->journal->find('cf-purge:example.com'));
    self::assertSame('operator repair: zone recreated', $this->journal->invalidations[0]['reason']);

    $result = (new PurgeZone('example.com'))->send();
    self::assertSame(2, PurgeZone::$performs);
    self::assertSame(['purge_id' => 'p-2'], $result->data);
  }

  public function test_a_perform_failure_journals_nothing_and_never_records(): void {
    PurgeZone::$performFails = new \RuntimeException('cloudflare 503');

    try {
      (new PurgeZone('example.com'))->send();
      self::fail('perform must throw');
    } catch (\RuntimeException $e) {
      self::assertSame('cloudflare 503', $e->getMessage());
    }
    self::assertNull($this->journal->find('cf-purge:example.com'));
    self::assertSame([], PurgeZone::$recorded);
    self::assertSame(0, $this->tx->commits() + $this->tx->rollbacks(), 'no transaction opened');
  }

  public function test_perform_inside_an_open_transaction_is_refused(): void {
    $mw = new EffectMiddleware($this->journal, $this->tx);

    $this->expectException(EffectInsideTransaction::class);
    $this->tx->run(static fn () => $mw->execute(new PurgeZone('example.com'), static fn () => null));
  }

  public function test_a_pre_journaled_effect_records_without_performing(): void {
    $this->journal->store('cf-purge:example.com', new EffectResult(['purge_id' => 'old']));

    (new PurgeZone('example.com'))->send();

    self::assertSame([], PurgeZone::$performInTx, 'perform not reached');
    self::assertSame(['purge_id' => 'old'], PurgeZone::$recorded[0]['result']->data);
  }

  public function test_without_a_journal_the_effect_is_refused_before_perform(): void {
    PurgeZone::$bus = $this->bus(null);

    try {
      (new PurgeZone('example.com'))->send();
      self::fail('expected NoEffectJournal');
    } catch (NoEffectJournal) {
    }
    self::assertSame([], PurgeZone::$performInTx);
  }

  public function test_the_journal_comes_from_host_defaults_when_not_injected(): void {
    HostDefaults::provide(IEffectJournal::class, $this->journal);
    PurgeZone::$bus = $this->bus(null);

    (new PurgeZone('example.com'))->send();

    self::assertNotNull($this->journal->find('cf-purge:example.com'));
  }

  public function test_other_commands_pass_through_untouched(): void {
    $mw = new EffectMiddleware($this->journal, $this->tx);
    $command = new PurgeFailed('z', 'e');

    self::assertSame('next', $mw->execute($command, static fn ($c) => $c === $command ? 'next' : 'other'));
  }

  public function test_record_effect_cannot_be_sent_by_hand(): void {
    $this->expectException(\LogicException::class);
    (new RecordEffect(new PurgeZone('z'), new EffectResult()))->send();
  }

  /**
   * `effect.journal-reuse` end to end on mem: perform ok, record throws,
   * redelivery reuses the journal; at the subscriber's ledger budget the
   * core invoker commits the failure command once, with a deterministic id.
   */
  public function test_budget_is_counted_in_the_delivery_ledger_and_the_failure_command_fires_once(): void {
    $registry = new SubscriptionRegistry();
    (new SubscriptionRegistrar($registry))->registerListener(new PurgeOnOrder());
    $ledger = new InMemoryDeliveryLedger();
    $delivery = new IntegrationDelivery($registry, $ledger, 3, new NullLogger());
    $wrapped = IntegrationEnvelope::wrap(['order_id' => 1, 'sku' => 'example.com'], 'corr-1', 1, self::EVENT_ID);
    $subscriber = 'listener:' . PurgeOnOrder::class;

    PurgeZone::$recordFailuresLeft = 99;
    $delivery->deliver(OrderPlaced::class, $wrapped);
    $delivery->deliver(OrderPlaced::class, $wrapped);
    self::assertSame(2, $ledger->attempts($subscriber, self::EVENT_ID));
    self::assertSame([], PurgeFailed::$handled);

    $outcome = $delivery->deliver(OrderPlaced::class, $wrapped);

    self::assertSame([$subscriber], $outcome->exhausted);
    self::assertSame(1, PurgeZone::$performs, 'perform ran once; every retry reused the journal');
    self::assertCount(1, PurgeFailed::$handled);
    self::assertSame('domain write failed', PurgeFailed::$handled[0]->error);

    $failureOpen = array_values(array_filter($this->audit->opened, static fn ($o) => $o->commandName === PurgeFailed::class));
    self::assertSame(DeterministicCommandId::forFact(self::EVENT_ID, $subscriber . '#failure'), $failureOpen[0]->commandId);

    $delivery->deliver(OrderPlaced::class, $wrapped);
    self::assertCount(1, PurgeFailed::$handled, 'fired once');
  }
}
