<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
use TangibleDDD\Application\Infrastructure\InfrastructureEvent;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Testing\StaticConsumerIdentity;

/**
 * Register 1.4 core form of InfrastructureEvent: dispatch() routes to the
 * host's IInfrastructureSignalDispatcher (HostDefaults); with none it logs
 * through PSR-3. Never silent, never a WordPress call, never throws.
 */
final class InfrastructureEventDispatchTest extends TestCase {

  protected function tearDown(): void {
    HostDefaults::reset_for_tests();
  }

  private function signal(): InfrastructureEvent {
    return new class('subject', 'corr-1', 'evt-1', 'integration_event') extends InfrastructureEvent {
      public static function action(): string { return 'outbox_dlq'; }
    };
  }

  public function test_dispatch_routes_to_the_host_signal_dispatcher(): void {
    $host = new class implements IInfrastructureSignalDispatcher {
      public array $emitted = [];
      public function emit(IInfrastructureEvent $e, IConsumerIdentity $c): void {
        $this->emitted[] = [$e::action(), $c->prefix()];
      }
    };
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, $host);

    $this->signal()->dispatch(new StaticConsumerIdentity('acme'));

    self::assertSame([['outbox_dlq', 'acme']], $host->emitted);
  }

  public function test_without_a_host_dispatcher_the_signal_is_logged(): void {
    $logger = new RecordingLogger();
    HostDefaults::provide(LoggerInterface::class, $logger);

    $this->signal()->dispatch(new StaticConsumerIdentity('acme'));

    self::assertCount(1, $logger->records);
    self::assertStringContainsString('acme_outbox_dlq', $logger->records[0]['message']);
    self::assertStringContainsString('corr-1', $logger->records[0]['message']);
  }

  public function test_a_throwing_dispatcher_never_breaks_the_machinery(): void {
    $logger = new RecordingLogger();
    HostDefaults::provide(LoggerInterface::class, $logger);
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, new class implements IInfrastructureSignalDispatcher {
      public function emit(IInfrastructureEvent $e, IConsumerIdentity $c): void {
        throw new \RuntimeException('listener blew up');
      }
    });

    $this->signal()->dispatch(new StaticConsumerIdentity('acme'));

    self::assertStringContainsString('listener blew up', $logger->messages()[0]);
  }
}
