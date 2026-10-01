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
use TangibleDDD\WordPress\Adapter\WpRepositoryProcessStore;
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

  /** wpdb answering every GET_LOCK statement with $answer; records [kind, bound args] per lock statement. */
  private function lockDb(?string $answer): \wpdb {
    return new class($answer) extends \wpdb {
      public array $calls = [];
      private array $args = [];
      public function __construct(private ?string $answer) {}
      public function prepare(string $query, ...$args): string {
        $this->args = $args;
        return $query;
      }
      public function get_var(?string $query = null, int $x = 0, int $y = 0) {
        if (str_contains((string) $query, 'GET_LOCK')) {
          $this->calls[] = ['GET', $this->args];
          return $this->answer;
        }
        $this->calls[] = ['RELEASE', $this->args];
        return '2';
      }
    };
  }

  public function test_the_lock_takes_the_new_then_the_legacy_name_in_one_statement_and_releases_both(): void {
    $GLOBALS['wpdb'] = $db = $this->lockDb('1');
    $lock = new GetLockProcessLock();
    $key = new LockKey('acme', '3', 42);

    $handle = $lock->acquire($key, 5.0);
    self::assertSame(1, $lock->held_count());
    $lock->release($handle);

    $new = substr('ddd:' . sha1('acme|3|42'), 0, 64);
    self::assertSame($new, $key->mysql_name());
    self::assertSame([
      // legacy bound first; GET_LOCK(new) is the inner (first evaluated) acquisition
      ['GET', ['ddd_process_42', $new, 5, $new, 5]],
      ['RELEASE', ['ddd_process_42', $new]],
    ], $db->calls);
    self::assertSame(0, $lock->held_count());
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
    self::assertSame(0, $lock->held_count());
    self::assertCount(1, $db->calls, 'no RELEASE_LOCK for a lock never held');
  }

  public function test_force_release_drops_every_held_acquisition(): void {
    $GLOBALS['wpdb'] = $this->lockDb('1');
    $lock = new GetLockProcessLock();
    $lock->acquire(new LockKey('acme', '', 1), 1);
    $lock->acquire(new LockKey('acme', '', 2), 1);

    self::assertSame(2, $lock->release_all());
    self::assertSame(0, $lock->held_count());
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
    self::assertSame([], $scheduler->claim_due(new \DateTimeImmutable(), 10, 60), 'Action Scheduler runs the actions itself');
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

    (new SubscriptionRegistrar($registry, $runner))->register_process(FakeStartsOnProcess::class);

    self::assertArrayHasKey(FakeResolvedEvent::integration_action(), $GLOBALS['_test_actions']);
    self::assertSame(Subscriber::IGNITION, $GLOBALS['_test_action_registrations'][FakeResolvedEvent::integration_action()][0]['priority']);
  }

  public function test_the_store_ignites_once_under_the_named_lock(): void {
    $repo = new FakeProcessRepository();
    $config = new FakeDDDConfig();
    $store = new WpRepositoryProcessStore($repo, $config);

    $first = new FakeStartsOnProcess(1);
    $first->mark_ignited_by('evt-1');
    self::assertSame(IgnitionResult::Inserted, $store->insert_ignited($first, FakeStartsOnProcess::class, 'evt-1'));

    $second = new FakeStartsOnProcess(1);
    $second->mark_ignited_by('evt-1');
    self::assertSame(IgnitionResult::AlreadyIgnited, $store->insert_ignited($second, FakeStartsOnProcess::class, 'evt-1'));
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
    self::assertInstanceOf(WpRepositoryProcessStore::class, HostDefaults::for(IProcessStore::class, $config, new FakeProcessRepository()));
    self::assertInstanceOf(ActionSchedulerWakeupScheduler::class, HostDefaults::for(IWakeupScheduler::class, $config));
    self::assertInstanceOf(\TangibleDDD\WordPress\Adapter\TouchesFactObserver::class, HostDefaults::for(\TangibleDDD\Runtime\IFactObserver::class, $config));
    self::assertInstanceOf(\TangibleDDD\WordPress\Adapter\WpdbOutboxAdministration::class, HostDefaults::for(\TangibleDDD\Runtime\Outbox\IOutboxAdministration::class, new \TangibleDDD\Runtime\ConsumerPrefix('ghost')));
  }
}
