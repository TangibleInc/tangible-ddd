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

  public function test_host_defaults_for_asks_the_per_consumer_factory_first(): void {
    $global = new FrozenClock(new \DateTimeImmutable('2026-01-01'));
    $acme = new FrozenClock(new \DateTimeImmutable('2030-01-01'));
    HostDefaults::provide(IClock::class, $global);

    self::assertSame($global, HostDefaults::for(IClock::class, new StaticConsumerIdentity('acme')), 'no factory: the global default');

    HostDefaults::provide(\TangibleDDD\Runtime\IHostPortFactory::class, new class($acme) implements \TangibleDDD\Runtime\IHostPortFactory {
      public array $asked = [];
      public function __construct(private object $acme) {}
      public function create(string $port, IConsumerIdentity $consumer, ?object $legacy = null): ?object {
        $this->asked[] = [$port, $consumer->prefix(), $legacy];
        return $consumer->prefix() === 'acme' ? $this->acme : null;
      }
    });

    $legacy = new \stdClass();
    self::assertSame($acme, HostDefaults::for(IClock::class, new StaticConsumerIdentity('acme'), $legacy));
    self::assertSame($global, HostDefaults::for(IClock::class, new StaticConsumerIdentity('other')), 'factory declines: the global default');
    self::assertNull(HostDefaults::for(ITableNames::class, new StaticConsumerIdentity('other')));
  }

  public function test_host_defaults_for_rejects_a_factory_answer_of_the_wrong_type(): void {
    HostDefaults::provide(\TangibleDDD\Runtime\IHostPortFactory::class, new class implements \TangibleDDD\Runtime\IHostPortFactory {
      public function create(string $port, IConsumerIdentity $consumer, ?object $legacy = null): ?object {
        return new \stdClass();
      }
    });

    $this->expectException(\UnexpectedValueException::class);
    HostDefaults::for(IClock::class, new StaticConsumerIdentity('acme'));
  }

  public function test_host_defaults_start_empty_and_resolve_what_was_provided(): void {
    self::assertNull(HostDefaults::get(IClock::class));
    self::assertFalse(HostDefaults::has(IClock::class));

    $clock = new FrozenClock();
    HostDefaults::provide(IClock::class, $clock);

    self::assertTrue(HostDefaults::has(IClock::class));
    self::assertSame($clock, HostDefaults::get(IClock::class));
  }

  public function test_a_miss_asks_the_resolver_and_reads_the_port_again(): void {
    $clock = new FrozenClock();
    $asked = [];
    HostDefaults::onMiss(static function (string $port) use ($clock, &$asked): void {
      $asked[] = $port;
      HostDefaults::provide(IClock::class, $clock);
    });

    self::assertFalse(HostDefaults::has(IClock::class), 'has() reports only what was provided; it never resolves');
    self::assertSame([], $asked);
    self::assertSame($clock, HostDefaults::get(IClock::class));
    self::assertSame([IClock::class], $asked);
    self::assertSame($clock, HostDefaults::get(IClock::class), 'a hit never asks');
    self::assertSame([IClock::class], $asked);
  }

  public function test_a_resolver_that_provides_nothing_keeps_the_miss_and_is_asked_again(): void {
    $calls = 0;
    HostDefaults::onMiss(static function () use (&$calls): void { $calls++; });

    self::assertNull(HostDefaults::get(IClock::class));
    self::assertNull(HostDefaults::get(IClock::class));
    self::assertSame(2, $calls, 'a resolver that is not ready yet is asked on the next miss');
  }

  public function test_a_miss_inside_the_resolver_returns_null_instead_of_recursing(): void {
    $inner = 'unset';
    HostDefaults::onMiss(static function () use (&$inner): void {
      $inner = HostDefaults::get(ITableNames::class);
    });

    self::assertNull(HostDefaults::get(IClock::class));
    self::assertNull($inner);
  }

  public function test_for_asks_the_resolver_for_the_host_port_factory(): void {
    $acme = new FrozenClock(new \DateTimeImmutable('2030-01-01'));
    HostDefaults::onMiss(static function () use ($acme): void {
      HostDefaults::provide(\TangibleDDD\Runtime\IHostPortFactory::class, new class($acme) implements \TangibleDDD\Runtime\IHostPortFactory {
        public function __construct(private readonly IClock $acme) {}
        public function create(string $port, IConsumerIdentity $consumer, ?object $legacy = null): ?object {
          return $port === IClock::class ? $this->acme : null;
        }
      });
      HostDefaults::onMiss(null);
    });

    self::assertSame($acme, HostDefaults::for(IClock::class, new StaticConsumerIdentity('acme')));
  }

  public function test_reset_for_tests_removes_the_resolver(): void {
    HostDefaults::onMiss(static function (): void { HostDefaults::provide(IClock::class, new FrozenClock()); });

    HostDefaults::resetForTests();

    self::assertNull(HostDefaults::get(IClock::class));
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
    $lock = new ReentrantProcessLock($backend, new \Psr\Log\NullLogger());
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
