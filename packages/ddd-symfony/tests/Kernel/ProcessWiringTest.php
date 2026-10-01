<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use League\Tactician\CommandBus;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Symfony\Lock\PooledConnectionRefused;
use TangibleDDD\Symfony\Lock\PostgresAdvisoryProcessLock;
use TangibleDDD\Symfony\Messenger\ProcessWakeupMessage;
use TangibleDDD\Symfony\Persistence\DbalProcessStore;
use TangibleDDD\Symfony\Persistence\DbalWakeupScheduler;
use TangibleDDD\Symfony\Runtime\FactClassRecordingEventBus;
use TangibleDDD\Symfony\Runtime\SymfonySignalDispatcher;
use TangibleDDD\Symfony\Tests\Kernel\App\TestKernel;

/**
 * The wave-3 bundle wiring: process ports on the shared DBAL connection,
 * the core act bracket and integration bus, HostDefaults at boot, the
 * ddd_wakeups transport and handler, and the inband_start boot refusal.
 */
final class ProcessWiringTest extends KernelTestBase {

  public function test_the_process_ports_are_the_dbal_adapters(): void {
    $c = self::getContainer();

    self::assertInstanceOf(DbalProcessStore::class, $c->get(IProcessStore::class));
    self::assertInstanceOf(DbalWakeupScheduler::class, $c->get(IWakeupScheduler::class));
    $lock = $c->get(IProcessLock::class);
    self::assertInstanceOf(ReentrantProcessLock::class, $lock);
    self::assertInstanceOf(PostgresAdvisoryProcessLock::class, $lock->inner());
    self::assertInstanceOf(ProcessRunner::class, $c->get(ProcessRunner::class));
  }

  public function test_the_act_bracket_and_integration_bus_are_the_core_classes(): void {
    $c = self::getContainer();

    self::assertInstanceOf(CorrelationMiddleware::class, $c->get('tangible_ddd.middleware.act_bracket'));
    self::assertInstanceOf(FactClassRecordingEventBus::class, $c->get('tangible_ddd.integration_bus'));
  }

  public function test_boot_provides_the_host_defaults(): void {
    self::assertInstanceOf(SymfonySignalDispatcher::class, HostDefaults::get(IInfrastructureSignalDispatcher::class));
    self::assertSame(self::getContainer()->get('tangible_ddd.clock'), HostDefaults::get(IClock::class));
    self::assertNotNull(HostDefaults::get(\Psr\Log\LoggerInterface::class));
  }

  public function test_a_due_intent_goes_through_the_wakeup_transport_to_the_runner(): void {
    $c = self::getContainer();
    $scheduler = $c->get(IWakeupScheduler::class);
    $c->get('tangible_ddd.transaction_boundary')->run(fn () => $scheduler->schedule(WakeupIntent::continuation('sfk', 999, 0, new \DateTimeImmutable())));

    $relay = $this->console('ddd:relay', ['--once' => true]);
    self::assertStringContainsString('wakeups projected 1', $relay->getDisplay());
    self::assertSame(1, $this->countRows("SELECT count(*) FROM messenger_messages WHERE queue_name = 'ddd_wakeups'"));

    // The runner's continue_scheduled() on an unknown process is a stale no-op; the intent completes.
    $this->console('messenger:consume', ['receivers' => ['ddd_wakeups'], '--limit' => 1, '--time-limit' => 5]);
    self::assertSame(0, $this->countRows('SELECT count(*) FROM ddd_wakeups'));
    self::assertSame(0, $this->countRows("SELECT count(*) FROM messenger_messages WHERE queue_name = 'ddd_wakeups'"));
  }

  public function test_the_wakeup_handler_is_registered_for_the_message(): void {
    $handlers = self::getContainer()->get('tangible_ddd.wakeup_handler');
    self::assertIsCallable($handlers);
    self::assertTrue(class_exists(ProcessWakeupMessage::class));
  }

  public function test_inband_start_on_a_pooled_dsn_is_refused_at_boot(): void {
    $kernel = new TestKernel('test', true, 'inband_pooled');
    try {
      $kernel->boot();
      self::fail('expected the bundle to refuse an in-band start on a pooled connection');
    } catch (PooledConnectionRefused $e) {
      self::assertStringContainsString('inband_start', $e->getMessage());
      self::assertStringContainsString('6432', $e->getMessage());
    } finally {
      $kernel->shutdown();
    }
  }

  public function test_inband_start_on_a_direct_dsn_boots(): void {
    $kernel = new TestKernel('test', true, 'inband');
    try {
      $kernel->boot();
      self::assertInstanceOf(CommandBus::class, $kernel->getContainer()->get('tangible_ddd.command_bus'));
    } finally {
      $kernel->shutdown();
    }
  }
}
