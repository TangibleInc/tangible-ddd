<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Core\Tests\Unit\Fixtures\BrokenStartsOnProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\ChargeCard;
use TangibleDDD\Core\Tests\Unit\Fixtures\ChargeOnOrderListener;
use TangibleDDD\Core\Tests\Unit\Fixtures\FulfilmentProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\LateAuditListener;
use TangibleDDD\Core\Tests\Unit\Fixtures\LegacyWelcomeListener;
use TangibleDDD\Core\Tests\Unit\Fixtures\OnboardingProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingCommand;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingProcessEntry;
use TangibleDDD\Core\Tests\Unit\Fixtures\ShipOrderListener;
use TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IProcessRepository;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistrar;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Testing\InMemoryDeliveryLedger;

final class SubscriptionRegistrarTest extends TestCase {

  private const EVENT_ID = '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d11';

  private SubscriptionRegistry $registry;
  private RecordingProcessEntry $entry;

  protected function setUp(): void {
    $this->registry = new SubscriptionRegistry();
    $this->entry = new RecordingProcessEntry();
    RecordingCommand::$sent = [];
    RecordingCommand::$hints = [];
    RecordingProcessEntry::$journal = [];
    ChargeCard::$sends = 0;
  }

  private function registrar(?ContainerInterface $services = null): SubscriptionRegistrar {
    return new SubscriptionRegistrar($this->registry, $this->entry, $services);
  }

  /** @return list<array{string, int}> */
  private function subscribers(string $event_class): array {
    return array_map(static fn (Subscriber $s) => [$s->id, $s->priority], $this->registry->for($event_class));
  }

  private function deliver(string $event_class, array $payload, int $budget = 5): \TangibleDDD\Runtime\Delivery\DeliveryOutcome {
    return (new IntegrationDelivery($this->registry, new InMemoryDeliveryLedger(), $budget, new \Psr\Log\NullLogger()))
      ->deliver($event_class, IntegrationEnvelope::wrap($payload, 'corr', 1, self::EVENT_ID));
  }

  public function test_register_listener_instance_subscribes_at_listener_priority_and_sends_its_command(): void {
    $this->registrar()->registerListener(new ShipOrderListener());

    self::assertSame([['listener:' . ShipOrderListener::class, Subscriber::LISTENER]], $this->subscribers(OrderPlaced::class));

    $this->deliver(OrderPlaced::class, ['order_id' => 42, 'sku' => 's']);
    self::assertSame(['ship'], RecordingCommand::labels());
    self::assertSame(42, RecordingCommand::$sent[0]->data);
  }

  public function test_register_listener_class_string_is_resolved_through_the_container(): void {
    $instance = new ShipOrderListener();
    $container = new class($instance) implements ContainerInterface {
      public array $asked = [];
      public function __construct(private object $instance) {}
      public function get(string $id): mixed { $this->asked[] = $id; return $this->instance; }
      public function has(string $id): bool { return $id === ShipOrderListener::class; }
    };

    $this->registrar($container)->registerListener(ShipOrderListener::class);

    self::assertSame([ShipOrderListener::class], $container->asked);
    self::assertCount(1, $this->registry->for(OrderPlaced::class));
  }

  public function test_register_listener_class_string_without_container_is_constructed(): void {
    $this->registrar()->registerListener(ShipOrderListener::class);
    self::assertCount(1, $this->registry->for(OrderPlaced::class));
  }

  public function test_a_declared_subscriber_priority_wins_over_the_listener_default(): void {
    $this->registrar()->registerListener(new LateAuditListener());

    self::assertSame([['listener:' . LateAuditListener::class, 100]], $this->subscribers(OrderPlaced::class));
  }

  public function test_legacy_integration_listener_is_read_through_its_protected_hooks(): void {
    $legacy = (new \ReflectionClass(LegacyWelcomeListener::class))->newInstanceWithoutConstructor();

    $this->registrar()->registerListener($legacy);
    $this->deliver(UserJoined::class, ['user_id' => 5]);

    self::assertSame(['welcome'], RecordingCommand::labels());
    self::assertSame(5, RecordingCommand::$sent[0]->data);
  }

  public function test_registering_the_same_listener_twice_subscribes_once(): void {
    $r = $this->registrar();
    $r->registerListener(new ShipOrderListener());
    $r->registerListener(ShipOrderListener::class);

    $this->deliver(OrderPlaced::class, ['order_id' => 1, 'sku' => 's']);
    self::assertSame(['ship'], RecordingCommand::labels());
  }

  public function test_an_object_that_is_not_a_listener_is_rejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->registrar()->registerListener(new \stdClass());
  }

  public function test_a_listener_whose_event_class_is_not_integration_or_interface_is_rejected(): void {
    $bad = new class {
      public function event_class(): string { return \stdClass::class; }
      public function translate(\TangibleDDD\Domain\Events\IIntegrationEvent $e): ?\TangibleDDD\Application\Commands\ICommand { return null; }
    };
    $this->expectException(\InvalidArgumentException::class);
    $this->registrar()->registerListener($bad);
  }

  public function test_register_process_reads_starts_on_and_awaits_by_reflection(): void {
    $this->registrar()->registerProcess(FulfilmentProcess::class);

    self::assertSame(
      [['ignition:' . FulfilmentProcess::class . '@' . OrderPlaced::class, Subscriber::IGNITION]],
      $this->subscribers(OrderPlaced::class)
    );
    self::assertSame(
      [['resume:' . UserJoined::class, Subscriber::RESUME]],
      $this->subscribers(UserJoined::class)
    );

    $this->deliver(OrderPlaced::class, ['order_id' => 3, 'sku' => 's']);
    $this->deliver(UserJoined::class, ['user_id' => 9]);

    self::assertSame(
      ['ignite:' . FulfilmentProcess::class . ':' . self::EVENT_ID, 'resume:' . UserJoined::class],
      $this->entry->calls
    );
  }

  public function test_two_processes_awaiting_one_fact_share_one_resume_subscriber(): void {
    $r = $this->registrar();
    $r->registerProcess(FulfilmentProcess::class);
    $r->registerProcess(OnboardingProcess::class);

    self::assertSame([['resume:' . UserJoined::class, Subscriber::RESUME]], $this->subscribers(UserJoined::class));
  }

  public function test_listener_ignition_resume_run_in_that_order_for_one_fact(): void {
    $journaling = new class {
      public function event_class(): string { return UserJoined::class; }
      public function translate(\TangibleDDD\Domain\Events\IIntegrationEvent $e): ?\TangibleDDD\Application\Commands\ICommand {
        RecordingProcessEntry::$journal[] = 'listener';
        return null;
      }
    };
    $r = $this->registrar();
    $r->registerProcess(FulfilmentProcess::class);  // resume on UserJoined (99)
    $r->registerListener($journaling);               // listener on UserJoined (10)

    $this->deliver(UserJoined::class, ['user_id' => 1]);

    self::assertSame(['listener', 'resume'], RecordingProcessEntry::$journal);
  }

  public function test_starts_on_without_from_event_is_rejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->registrar()->registerProcess(BrokenStartsOnProcess::class);
  }

  public function test_register_process_rejects_a_non_process_class(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->registrar()->registerProcess(\stdClass::class);
  }

  public function test_register_process_without_a_runner_is_a_logic_error(): void {
    $this->expectException(\LogicException::class);
    (new SubscriptionRegistrar($this->registry))->registerProcess(FulfilmentProcess::class);
  }

  public function test_the_process_runner_is_accepted_as_the_process_entry(): void {
    // CR-3, wave 2: ProcessRunner implements IProcessEntry, so the register
    // 3.5 call `new SubscriptionRegistrar($registry, $runner)` works.
    $config = $this->createStub(IDDDConfig::class);
    $repo = $this->createStub(IProcessRepository::class);
    $runner = new ProcessRunner($config, $repo);

    $registrar = new SubscriptionRegistrar($this->registry, $runner);
    $registrar->registerProcess(FulfilmentProcess::class);

    self::assertCount(1, $this->registry->for(OrderPlaced::class));
  }

  public function test_exhausted_external_effect_listener_dispatches_its_failure_command_once(): void {
    $this->registrar()->registerListener(new ChargeOnOrderListener());
    $ledger = new InMemoryDeliveryLedger();
    $delivery = new IntegrationDelivery($this->registry, $ledger, 2, new \Psr\Log\NullLogger());
    $wrapped = IntegrationEnvelope::wrap(['order_id' => 8, 'sku' => 's'], 'corr', 1, self::EVENT_ID);

    $delivery->deliver(OrderPlaced::class, $wrapped);
    self::assertSame([], RecordingCommand::labels());

    $outcome = $delivery->deliver(OrderPlaced::class, $wrapped);
    self::assertSame(['listener:' . ChargeOnOrderListener::class], $outcome->exhausted);
    self::assertSame(['charge-failed'], RecordingCommand::labels());
    self::assertSame(['order_id' => 8, 'error' => 'gateway down for order 8'], RecordingCommand::$sent[0]->data);

    $delivery->deliver(OrderPlaced::class, $wrapped);
    self::assertSame(['charge-failed'], RecordingCommand::labels(), 'fired once');
    self::assertSame(2, ChargeCard::$sends);
  }

  public function test_a_listener_command_gets_the_deterministic_id_uuid5_of_event_and_subscriber(): void {
    // wave1-notes core minor 2 / register 3.8.
    $this->registrar()->registerListener(new ShipOrderListener());

    $this->deliver(OrderPlaced::class, ['order_id' => 42, 'sku' => 's']);

    $expected = str_replace('-', '', \TangibleDDD\Runtime\Ids\NameBasedUuid::v5(self::EVENT_ID, 'listener:' . ShipOrderListener::class));
    self::assertSame([$expected], RecordingCommand::$hints);
    self::assertNull(\TangibleDDD\Runtime\Ids\DeterministicCommandId::peek(), 'the hint does not outlive the send');
  }

  public function test_a_listener_command_for_a_non_uuid_event_id_gets_no_hint(): void {
    $this->registrar()->registerListener(new ShipOrderListener());

    (new IntegrationDelivery($this->registry, new InMemoryDeliveryLedger(), 5, new \Psr\Log\NullLogger()))
      ->deliver(OrderPlaced::class, IntegrationEnvelope::wrap(['order_id' => 1, 'sku' => 's'], 'corr', 1, 'evt-not-a-uuid'));

    self::assertSame([null], RecordingCommand::$hints);
  }

  public function test_a_legacy_listener_class_string_is_never_constructed(): void {
    // wave1-notes core minor 3: the 0.6 IntegrationListener constructor
    // self-registers on a WordPress hook; the registrar must not run it.
    // This suite has no WordPress, so running it would fatal.
    $this->registrar()->registerListener(LegacyWelcomeListener::class);

    $this->deliver(UserJoined::class, ['user_id' => 5]);
    self::assertSame(['welcome'], RecordingCommand::labels());
  }

  public function test_a_legacy_listener_class_string_from_the_container_is_used_as_is(): void {
    $instance = (new \ReflectionClass(LegacyWelcomeListener::class))->newInstanceWithoutConstructor();
    $container = new class($instance) implements ContainerInterface {
      public function __construct(private object $instance) {}
      public function get(string $id): mixed { return $this->instance; }
      public function has(string $id): bool { return true; }
    };

    $this->registrar($container)->registerListener(LegacyWelcomeListener::class);
    $this->deliver(UserJoined::class, ['user_id' => 6]);

    self::assertSame(6, RecordingCommand::$sent[0]->data);
  }

  public function test_ignition_subscriber_is_registered_per_process_and_event_class(): void {
    self::assertTrue(is_subclass_of(FulfilmentProcess::class, LongProcess::class));
    $r = $this->registrar();
    $r->registerProcess(FulfilmentProcess::class);
    $r->registerProcess(FulfilmentProcess::class);

    self::assertCount(1, $this->registry->for(OrderPlaced::class));
  }
}
