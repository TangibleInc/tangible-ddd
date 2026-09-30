<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Process\IProcessEntry;
use TangibleDDD\Symfony\Runtime\CompiledSubscriptionRegistry;
use TangibleDDD\Symfony\Tests\Support\Fixtures\MarkerListener;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingDomainEvent;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingFact;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingListener;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingMarker;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingProcess;
use TangibleDDD\Symfony\Tests\Support\Fixtures\RecordingCommand;

final class CompiledSubscriptionRegistryTest extends TestCase {

  protected function setUp(): void {
    PingListener::$constructed = 0;
    RecordingCommand::$sent = [];
  }

  /** @return list<array<string, mixed>> */
  private static function specs(): array {
    return [
      CompiledSubscriptionRegistry::listenerSpec('app.marker_listener', MarkerListener::class, PingMarker::class, 20),
      CompiledSubscriptionRegistry::processSpec(PingProcess::class, 'resume', PingFact::class),
      CompiledSubscriptionRegistry::processSpec(PingProcess::class, 'ignition', PingFact::class),
      CompiledSubscriptionRegistry::listenerSpec('app.ping_listener', PingListener::class, PingFact::class, Subscriber::LISTENER),
    ];
  }

  private function listeners(): ServiceLocator {
    return new ServiceLocator([
      'app.ping_listener' => static fn () => new PingListener(),
      'app.marker_listener' => static fn () => new MarkerListener(),
    ]);
  }

  private function entry(array &$calls): IProcessEntry {
    return new class ($calls) implements IProcessEntry {
      public function __construct(private array &$calls) {}
      public function ignite(string $processClass, IIntegrationEvent $event, string $eventId): void {
        $this->calls[] = "ignite:$processClass:$eventId";
      }
      public function resume(IIntegrationEvent $event): void {
        $this->calls[] = 'resume';
      }
    };
  }

  public function test_subscribers_come_in_priority_order_with_marker_matches(): void {
    $calls = [];
    $registry = new CompiledSubscriptionRegistry(self::specs(), $this->listeners(), $this->entry($calls));

    $subs = $registry->for(PingFact::class);

    self::assertSame([
      'listener:' . PingListener::class,
      'listener:' . MarkerListener::class,
      'ignition:' . PingProcess::class . '@' . PingFact::class,
      'resume:' . PingFact::class,
    ], array_map(fn (Subscriber $s) => $s->id, $subs));
    self::assertSame([10, 20, 50, 99], array_map(fn (Subscriber $s) => $s->priority, $subs));

    foreach ($subs as $s) {
      ($s->handle)(new PingFact(4), 'evt-4');
    }
    self::assertSame(['ping:4', 'marker:' . PingFact::class], RecordingCommand::$sent);
    self::assertSame(['ignite:' . PingProcess::class . ':evt-4', 'resume'], $calls);
  }

  public function test_listeners_are_not_constructed_until_a_matching_fact_is_delivered(): void {
    $calls = [];
    $registry = new CompiledSubscriptionRegistry(self::specs(), $this->listeners(), $this->entry($calls));
    self::assertSame(0, PingListener::$constructed, 'nothing built at boot');

    self::assertCount(1, $registry->for(PingDomainEvent::class), 'only the marker listener matches');
    self::assertSame(0, PingListener::$constructed);

    $registry->for(PingFact::class);
    $registry->for(PingFact::class);
    self::assertSame(1, PingListener::$constructed, 'built once, then cached');
  }

  public function test_a_process_subscription_without_a_process_entry_fails_loudly(): void {
    $registry = new CompiledSubscriptionRegistry(self::specs(), $this->listeners(), null);

    $this->expectException(\LogicException::class);
    $registry->for(PingFact::class);
  }

  public function test_runtime_additions_are_kept_after_compiled_subscribers_and_ids_dedupe(): void {
    $registry = new CompiledSubscriptionRegistry([], $this->listeners(), null);
    $registry->add(new Subscriber('late', 10, PingFact::class, static function (): void {}));
    $registry->add(new Subscriber('late', 10, PingFact::class, static function (): void {}));

    self::assertSame(['late'], array_map(fn (Subscriber $s) => $s->id, $registry->for(PingFact::class)));
  }

  public function test_known_fact_classes_are_the_concrete_subscribed_classes(): void {
    $registry = new CompiledSubscriptionRegistry(self::specs(), $this->listeners(), null);
    self::assertSame([PingFact::class], $registry->knownFactClasses());
  }
}
