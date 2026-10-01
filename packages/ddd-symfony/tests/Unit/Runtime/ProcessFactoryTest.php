<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Runtime;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Symfony\Lock\PostgresAdvisoryProcessLock;
use TangibleDDD\Symfony\Persistence\PoolerPolicy;
use TangibleDDD\Symfony\Runtime\Factory;
use TangibleDDD\Symfony\Runtime\SymfonyConsumerConfig;
use TangibleDDD\Symfony\Tests\Support\RecordingLogger;
use TangibleDDD\Testing\InMemoryProcessLock;
use TangibleDDD\Testing\InMemoryProcessStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryWakeupScheduler;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;

/**
 * The process runner wiring (register X3, 5.2, scenario process.start-from-web):
 * on sf start() persists the process plus a Continue intent and the first
 * step runs in a worker, unless ddd.process.inband_start is true. The start
 * mode is a core ProcessRunner constructor option requested in
 * wave3-sf-process-change-requests.md (CR sfp-1); the factory passes it when
 * the core constructor has it.
 */
final class ProcessFactoryTest extends TestCase {

  protected function tearDown(): void {
    RuntimeReset::forgetRegistrationsForTests();
  }

  public function test_the_start_mode_parameter_is_found_by_name_on_a_constructor(): void {
    $withCamel = new class (true) { public function __construct(public bool $inbandStart = true) {} };
    $withSnake = new class (true) { public function __construct(public bool $inband_start = true) {} };
    $without = new class () { public function __construct() {} };

    self::assertSame('inbandStart', Factory::startModeParameter($withCamel::class));
    self::assertSame('inband_start', Factory::startModeParameter($withSnake::class));
    self::assertNull(Factory::startModeParameter($without::class));
  }

  public function test_the_runner_is_built_on_the_given_ports(): void {
    $clock = new FrozenClock();
    $boundary = new InMemoryTransactionBoundary();
    $log = new RecordingLogger();

    $runner = Factory::processRunner(
      new SymfonyConsumerConfig('acme', 'App'),
      new ReentrantProcessLock(new InMemoryProcessLock()),
      new InMemoryProcessStore($clock),
      new InMemoryWakeupScheduler($boundary),
      new SubscriptionRegistry(),
      $boundary,
      $clock,
      false,
      $log,
    );

    self::assertInstanceOf(ProcessRunner::class, $runner);
    if (Factory::startModeParameter(ProcessRunner::class) === null) {
      self::assertStringContainsString('in-band', $log->text(), 'an unsupported persist-only start is announced, never silent');
    } else {
      self::assertSame('', $log->text());
    }
  }

  public function test_an_in_band_start_needs_no_core_support_and_logs_nothing(): void {
    $clock = new FrozenClock();
    $boundary = new InMemoryTransactionBoundary();
    $log = new RecordingLogger();

    Factory::processRunner(
      new SymfonyConsumerConfig('acme', 'App'), new ReentrantProcessLock(new InMemoryProcessLock()), new InMemoryProcessStore($clock),
      new InMemoryWakeupScheduler($boundary), new SubscriptionRegistry(), $boundary, $clock, true, $log,
    );

    self::assertSame('', $log->text());
  }

  public function test_the_process_lock_is_the_reentrant_wrapper_over_the_advisory_lock(): void {
    $conn = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'dbname' => 'x']);

    $lock = Factory::processLock($conn, 'warn');

    self::assertInstanceOf(ReentrantProcessLock::class, $lock);
    self::assertInstanceOf(PostgresAdvisoryProcessLock::class, $lock->inner());
    self::assertSame(PoolerPolicy::Warn, PoolerPolicy::from('warn'));
  }
}
