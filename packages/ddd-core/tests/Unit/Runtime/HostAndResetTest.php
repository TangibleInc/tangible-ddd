<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITableNames;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\RuntimeLeakDetected;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Testing\InMemoryProcessLock;
use TangibleDDD\Testing\StaticConsumerIdentity;

final class HostAndResetTest extends TestCase {

  protected function tearDown(): void {
    HostDefaults::resetForTests();
    RuntimeReset::forgetRegistrationsForTests();
    Correlation::reset();
    Reactions::reset();
  }

  public function test_static_identity_is_a_consumer_identity(): void {
    $id = new StaticConsumerIdentity('acme_shop', '1.2.3');

    self::assertInstanceOf(IConsumerIdentity::class, $id);
    self::assertSame('acme_shop', $id->prefix());
    self::assertSame('1.2.3', $id->version());
  }

  public function test_identity_rejects_a_prefix_outside_the_frozen_alphabet(): void {
    $this->expectException(\InvalidArgumentException::class);
    new StaticConsumerIdentity('Acme-Shop');
  }

  public function test_prefixed_table_names(): void {
    $names = new PrefixedTableNames('acme_');

    self::assertInstanceOf(ITableNames::class, $names);
    self::assertSame('acme_integration_outbox', $names->table('integration_outbox'));
  }

  public function test_prefixed_table_names_rejects_unsafe_logical_names(): void {
    $this->expectException(\InvalidArgumentException::class);
    (new PrefixedTableNames('acme_'))->table('outbox; DROP TABLE x');
  }

  public function test_host_defaults_start_empty_and_resolve_what_was_provided(): void {
    self::assertNull(HostDefaults::get(IClock::class));
    self::assertFalse(HostDefaults::has(IClock::class));

    $clock = new FrozenClock();
    HostDefaults::provide(IClock::class, $clock);

    self::assertTrue(HostDefaults::has(IClock::class));
    self::assertSame($clock, HostDefaults::get(IClock::class));
  }

  public function test_host_defaults_refuse_an_implementation_of_the_wrong_port(): void {
    $this->expectException(\InvalidArgumentException::class);
    HostDefaults::provide(ITableNames::class, new FrozenClock());
  }

  public function test_runtime_reset_never_clears_host_defaults(): void {
    $clock = new FrozenClock();
    HostDefaults::provide(IClock::class, $clock);

    RuntimeReset::betweenMessages();

    self::assertSame($clock, HostDefaults::get(IClock::class));
  }

  public function test_runtime_reset_clears_reactions_and_runs_registered_resetters(): void {
    $calls = 0;
    RuntimeReset::register('uow', function () use (&$calls) { $calls++; });

    RuntimeReset::betweenMessages();
    RuntimeReset::betweenMessages();

    self::assertSame(2, $calls);
  }

  public function test_runtime_reset_fails_loudly_on_a_leaked_correlation_scope_and_still_cleans(): void {
    // A leak: the ambient was minted into the worker and never scoped out.
    Correlation::current();
    self::assertNotNull(Correlation::peek());

    try {
      RuntimeReset::betweenMessages();
      self::fail('expected RuntimeLeakDetected');
    } catch (RuntimeLeakDetected $e) {
      self::assertStringContainsString('Correlation', $e->getMessage());
    }

    self::assertNull(Correlation::peek(), 'the next message still starts clean');
  }

  public function test_runtime_reset_is_quiet_inside_a_balanced_bracket_run(): void {
    Correlation::within(TraceContext::root(), static fn () => null);

    RuntimeReset::betweenMessages();
    self::assertNull(Correlation::peek());
  }

  public function test_runtime_reset_reports_a_held_process_lock(): void {
    $lock = new InMemoryProcessLock();
    RuntimeReset::guardLock($lock);
    $lock->acquire(new LockKey('acme', '', 7), 0.0);

    $this->expectException(RuntimeLeakDetected::class);
    $this->expectExceptionMessage('lock');
    RuntimeReset::betweenMessages();
  }

  public function test_a_lock_leak_is_force_released_so_the_next_reset_is_clean(): void {
    $backend = new InMemoryProcessLock();
    $lock = new ReentrantProcessLock($backend, static fn () => null);
    RuntimeReset::guardLock($lock);
    $key = new LockKey('acme', '', 7);
    $lock->acquire($key, 0.0);
    $lock->acquire($key, 0.0);

    try {
      RuntimeReset::betweenMessages();
      self::fail('expected RuntimeLeakDetected');
    } catch (RuntimeLeakDetected $e) {
      self::assertStringContainsString('held 2 time(s)', $e->getMessage());
    }

    self::assertSame(0, $lock->heldCount());
    self::assertSame(0, $backend->heldCount(), 'the backend lock was released too');

    RuntimeReset::betweenMessages(); // no throw: the leak is not sticky

    $lock->acquire($key, 0.0);
    self::assertSame(2, $backend->acquireCount(), 'a later acquire goes to the backend again');
  }

  public function test_runtime_reset_runs_every_resetter_even_when_one_throws(): void {
    $ran = false;
    RuntimeReset::register('bad', static function () { throw new \RuntimeException('boom'); });
    RuntimeReset::register('good', function () use (&$ran) { $ran = true; });

    try {
      RuntimeReset::betweenMessages();
      self::fail('expected RuntimeLeakDetected');
    } catch (RuntimeLeakDetected $e) {
      self::assertStringContainsString('bad', $e->getMessage());
    }
    self::assertTrue($ran);
  }
}
