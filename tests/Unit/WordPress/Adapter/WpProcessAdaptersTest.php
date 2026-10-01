<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\WordPress\Adapter;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistrar;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IHostPortFactory;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\Process\IgnitionResult;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;
use TangibleDDD\Tests\Fakes\FakeStartsOnProcess;
use TangibleDDD\WordPress\Adapter\ActionSchedulerWakeupScheduler;
use TangibleDDD\WordPress\Adapter\GetLockProcessLock;
use TangibleDDD\WordPress\Adapter\WpdbProcessStore;
use TangibleDDD\WordPress\Adapter\WpHookSubscriptionRegistry;

/**
 * The transitional wp process ports (register section 8 wave 2): GET_LOCK
 * on the legacy name, Action Scheduler wakeups on the legacy hooks and
 * associative args, the add_action registry, the store over a 0.6
 * repository, and their HostDefaults wiring.
 */
final class WpProcessAdaptersTest extends TestCase {

  protected function setUp(): void {
    $GLOBALS['_test_actions'] = [];
    $GLOBALS['_test_action_registrations'] = [];
    $GLOBALS['_test_scheduled_actions'] = [];
    $GLOBALS['wpdb'] = new \wpdb();
    Correlation::reset();
  }

  protected function tearDown(): void {
    Correlation::reset();
  }

  private function lockDb(?string $answer): \wpdb {
    return new class($answer) extends \wpdb {
      public array $calls = [];
      public function __construct(private ?string $answer) {}
      public function prepare(string $query, ...$args): string {
        return str_replace(['%s', '%d'], array_map(static fn ($a) => (string) $a, $args), $query);
      }
      public function get_var(?string $query = null, int $x = 0, int $y = 0) {
        $this->calls[] = $query;
        return str_contains((string) $query, 'RELEASE_LOCK') ? '1' : $this->answer;
      }
    };
  }

  public function test_the_lock_takes_the_legacy_name_and_releases_it(): void {
    $GLOBALS['wpdb'] = $db = $this->lockDb('1');
    $lock = new GetLockProcessLock();

    $handle = $lock->acquire(new LockKey('acme', '3', 42), 5.0);
    self::assertSame(1, $lock->heldCount());
    $lock->release($handle);

    self::assertSame(['SELECT GET_LOCK(ddd_process_42, 5)', 'SELECT RELEASE_LOCK(ddd_process_42)'], $db->calls);
    self::assertSame(0, $lock->heldCount());
  }

  /** @return array<string, array{?string}> */
  public static function not_acquired(): array {
    return ['NULL' => [null], 'timeout' => ['0']];
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('not_acquired')]
  public function test_the_lock_fails_closed(?string $answer): void {
    $GLOBALS['wpdb'] = $db = $this->lockDb($answer);
    $lock = new GetLockProcessLock();

    try {
      $lock->acquire(new LockKey('acme', '', 1), 5.0);
      self::fail('only a definite 1 is an acquisition');
    } catch (LockNotAcquired) {
    }
    self::assertSame(0, $lock->heldCount());
    self::assertCount(1, $db->calls, 'no RELEASE_LOCK for a lock never held');
  }

  public function test_force_release_drops_every_held_acquisition(): void {
    $GLOBALS['wpdb'] = $this->lockDb('1');
    $lock = new GetLockProcessLock();
    $lock->acquire(new LockKey('acme', '', 1), 1);
    $lock->acquire(new LockKey('acme', '', 2), 1);

    self::assertSame(2, $lock->forceReleaseAll());
    self::assertSame(0, $lock->heldCount());
  }

  public function test_wakeups_project_to_the_legacy_hooks_with_associative_args(): void {
    $scheduler = new ActionSchedulerWakeupScheduler(new FakeDDDConfig());
    $due = new \DateTimeImmutable('+1 hour');

    $scheduler->schedule(WakeupIntent::timeout('test', 5, 2, $due));
    $scheduler->schedule(WakeupIntent::continuation('test', 5, 3, new \DateTimeImmutable('-1 second')));
    $scheduler->schedule(WakeupIntent::continuation('test', 6, 1, $due));

    self::assertSame([
      ['timestamp' => $due->getTimestamp(), 'hook' => 'test_await_timeout', 'args' => ['process_id' => 5, 'step_index' => 2], 'group' => 'test-processes'],
      ['hook' => 'test_process_continue', 'args' => ['process_id' => 5], 'group' => 'test-processes'],
      ['timestamp' => $due->getTimestamp(), 'hook' => 'test_process_continue', 'args' => ['process_id' => 6], 'group' => 'test-processes'],
    ], $GLOBALS['_test_scheduled_actions']);
    self::assertSame([], $scheduler->claimDue(new \DateTimeImmutable(), 10, 60), 'Action Scheduler runs the actions itself');
  }

  public function test_the_registry_binds_one_callback_per_subscriber_at_its_priority(): void {
    $registry = new WpHookSubscriptionRegistry();
    $seen = [];
    $registry->add(new Subscriber('a', Subscriber::RESUME, FakeResolvedEvent::class, function (IIntegrationEvent $e, string $id) use (&$seen) {
      $seen[] = ['a', $id, Correlation::peek()?->cause?->kind];
    }));
    $registry->add(new Subscriber('b', Subscriber::LISTENER, FakeResolvedEvent::class, function (IIntegrationEvent $e, string $id) use (&$seen) {
      $seen[] = ['b', $id, Correlation::peek()?->cause?->kind];
    }));
    $registry->add(new Subscriber('a', Subscriber::RESUME, FakeResolvedEvent::class, static fn () => throw new \LogicException('duplicate bound')));

    $hook = FakeResolvedEvent::integration_action();
    $priorities = array_column($GLOBALS['_test_action_registrations'][$hook], 'priority');
    self::assertSame([99, 10], $priorities, 'one add_action per subscriber, at its numeric priority');
    self::assertSame(['b', 'a'], array_map(static fn (Subscriber $s) => $s->id, $registry->for(FakeResolvedEvent::class)));

    do_action($hook, IntegrationEnvelope::wrap(['request_id' => 1, 'outcome' => 'accepted', 'resolved_at' => '2026-07-16T10:00:00+00:00'], 'corr', 1, 'evt-1'));
    self::assertSame([['a', 'evt-1', Kind::Fact], ['b', 'evt-1', Kind::Fact]], $seen, 'the stub runs in registration order; each inside the fact scope');
  }

  public function test_an_id_less_payload_is_delivered_with_an_empty_event_id(): void {
    $registry = new WpHookSubscriptionRegistry();
    $ids = [];
    $registry->add(new Subscriber('x', Subscriber::LISTENER, FakeResolvedEvent::class, function (IIntegrationEvent $e, string $id) use (&$ids) {
      $ids[] = $id;
    }));

    do_action(FakeResolvedEvent::integration_action(), ['request_id' => 1, 'outcome' => 'accepted', 'resolved_at' => '2026-07-16T10:00:00+00:00']);

    self::assertSame([''], $ids);
  }

  public function test_the_registrar_feeds_the_wordpress_registry(): void {
    $registry = new WpHookSubscriptionRegistry();
    $runner = new ProcessRunner(new FakeDDDConfig(), new FakeProcessRepository(), subscriptions: $registry);

    (new SubscriptionRegistrar($registry, $runner))->registerProcess(FakeStartsOnProcess::class);

    self::assertArrayHasKey(FakeResolvedEvent::integration_action(), $GLOBALS['_test_actions']);
    self::assertSame(Subscriber::IGNITION, $GLOBALS['_test_action_registrations'][FakeResolvedEvent::integration_action()][0]['priority']);
  }

  public function test_the_store_ignites_once_under_the_named_lock(): void {
    $repo = new FakeProcessRepository();
    $config = new FakeDDDConfig();
    $store = new WpdbProcessStore($repo, $config);

    $first = new FakeStartsOnProcess(1);
    $first->mark_ignited_by('evt-1');
    self::assertSame(IgnitionResult::Inserted, $store->insertIgnited($first, FakeStartsOnProcess::class, 'evt-1'));

    $second = new FakeStartsOnProcess(1);
    $second->mark_ignited_by('evt-1');
    self::assertSame(IgnitionResult::AlreadyIgnited, $store->insertIgnited($second, FakeStartsOnProcess::class, 'evt-1'));
    self::assertCount(1, $repo->processes);
    self::assertSame([$first->get_id()], array_keys($repo->processes));
  }

  public function test_ddd_wp_init_provides_every_wave_1_port(): void {
    self::assertInstanceOf(GetLockProcessLock::class, HostDefaults::get(IProcessLock::class));
    self::assertInstanceOf(WpHookSubscriptionRegistry::class, HostDefaults::get(\TangibleDDD\Runtime\Delivery\ISubscriptionRegistry::class));
    self::assertInstanceOf(\TangibleDDD\WordPress\Adapter\WpdbTransactionBoundary::class, HostDefaults::get(\TangibleDDD\Runtime\ITransactionBoundary::class));
    self::assertInstanceOf(\TangibleDDD\Runtime\SystemClock::class, HostDefaults::get(\TangibleDDD\Runtime\IClock::class));
    self::assertInstanceOf(\TangibleDDD\WordPress\Adapter\WpActorProvider::class, HostDefaults::get(\TangibleDDD\Runtime\Audit\IActorProvider::class));
    self::assertInstanceOf(\TangibleDDD\WordPress\Adapter\WpEnvironmentProvider::class, HostDefaults::get(\TangibleDDD\Runtime\Audit\IEnvironmentProvider::class));
    self::assertInstanceOf(\TangibleDDD\WordPress\Adapter\WpHookSignalDispatcher::class, HostDefaults::get(\TangibleDDD\Runtime\IInfrastructureSignalDispatcher::class));
    self::assertInstanceOf(IHostPortFactory::class, HostDefaults::get(IHostPortFactory::class));

    $config = new FakeDDDConfig();
    self::assertInstanceOf(WpdbProcessStore::class, HostDefaults::for(IProcessStore::class, $config, new FakeProcessRepository()));
    self::assertInstanceOf(ActionSchedulerWakeupScheduler::class, HostDefaults::for(IWakeupScheduler::class, $config));
    self::assertInstanceOf(\TangibleDDD\WordPress\Adapter\TouchesFactObserver::class, HostDefaults::for(\TangibleDDD\Runtime\IFactObserver::class, $config));
    self::assertInstanceOf(\TangibleDDD\WordPress\Adapter\WpdbOutboxAdministration::class, HostDefaults::for(\TangibleDDD\Runtime\Outbox\IOutboxAdministration::class, new \TangibleDDD\Runtime\ConsumerPrefix('ghost')));
  }
}
