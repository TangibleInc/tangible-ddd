<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Lock\LockHandle;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\LoggingSignalDispatcher;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\Support\Log;
use TangibleDDD\Testing\InMemoryDeliveryLedger;
use TangibleDDD\Testing\InMemoryProcessLock;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Infrastructure\InfrastructureEvent;
use TangibleDDD\Testing\StaticConsumerIdentity;

/**
 * Wave 2 (wave1-notes "Logging"): the runtime classes take a PSR-3
 * LoggerInterface. The wave-1 closure form was accepted for one round
 * (CR-SP-1) and is removed in wave 3.
 */
final class PsrLoggingTest extends TestCase {

  protected function tearDown(): void {
    HostDefaults::reset_for_tests();
  }

  public function test_log_write_routes_to_a_psr3_logger_at_warning_by_default(): void {
    $logger = new RecordingLogger();
    Log::write($logger, 'hello');
    Log::write($logger, 'loud', 'error');

    self::assertSame([
      ['level' => 'warning', 'message' => 'hello'],
      ['level' => 'error', 'message' => 'loud'],
    ], $logger->records);
  }

  public function test_log_write_without_a_sink_uses_the_host_logger(): void {
    $logger = new RecordingLogger();
    HostDefaults::provide(LoggerInterface::class, $logger);

    Log::write(null, 'via host');

    self::assertSame(['via host'], $logger->messages());
  }

  /** Wave 3 (wave2-notes, CR-SP-1): the transitional closure arm is removed. */
  public function test_the_closure_form_is_gone_from_every_runtime_logger(): void {
    $parameters = [
      [Log::class, 'write', 'sink'],
      [ReentrantProcessLock::class, '__construct', 'log'],
      [IntegrationDelivery::class, '__construct', 'log'],
      [InMemoryTransactionBoundary::class, '__construct', 'log'],
      [LoggingSignalDispatcher::class, '__construct', 'log'],
    ];
    foreach ($parameters as [$class, $method, $name]) {
      $param = null;
      foreach ((new \ReflectionMethod($class, $method))->getParameters() as $p) {
        if ($p->getName() === $name) {
          $param = $p;
        }
      }
      self::assertNotNull($param, "$class::$method has \$$name");
      self::assertSame('?' . LoggerInterface::class, (string) $param->getType(), "$class::$method(\$$name)");
    }
  }

  public function test_reentrant_lock_logs_a_bad_release_through_psr3(): void {
    $logger = new RecordingLogger();
    $lock = new ReentrantProcessLock(new InMemoryProcessLock(), $logger);

    $lock->release(new LockHandle(new LockKey('acme', '', 1), 'reentrant:999'));

    self::assertCount(1, $logger->records);
    self::assertStringContainsString('unknown or already-released handle', $logger->records[0]['message']);
  }

  public function test_delivery_logs_a_failing_subscriber_through_psr3(): void {
    $logger = new RecordingLogger();
    $registry = new SubscriptionRegistry();
    $registry->add(new Subscriber('boom', Subscriber::LISTENER, OrderPlaced::class, static function (): void {
      throw new \RuntimeException('kaput');
    }));

    $delivery = new IntegrationDelivery($registry, new InMemoryDeliveryLedger(), 5, $logger);
    $delivery->deliver(OrderPlaced::class, IntegrationEnvelope::wrap(['order_id' => 7], 'corr', 1, 'evt-1'));

    self::assertStringContainsString('subscriber boom failed', $logger->messages()[0]);
  }

  public function test_mem_boundary_logs_a_failed_rollback_through_psr3(): void {
    $logger = new RecordingLogger();
    $boundary = new InMemoryTransactionBoundary(NestedPolicy::Reject, $logger);
    $boundary->fail_next_rollback('disk gone');

    try {
      $boundary->run(static function (): void { throw new \DomainException('original'); });
    } catch (\DomainException) {
    }

    self::assertStringContainsString('ROLLBACK failed: disk gone', $logger->messages()[0]);
  }

  public function test_signal_dispatcher_logs_through_psr3(): void {
    $logger = new RecordingLogger();
    $signal = new class('subject', 'corr-1') extends InfrastructureEvent {
      public static function action(): string { return 'thing_happened'; }
    };

    (new LoggingSignalDispatcher($logger))->emit($signal, new StaticConsumerIdentity('acme'));

    self::assertStringContainsString('acme_thing_happened', $logger->messages()[0]);
  }
}
