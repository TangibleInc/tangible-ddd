<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8;

use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Tests\Integration\V8\Fakes\V8AwaitingProcess;
use TangibleDDD\Tests\Integration\V8\Fakes\V8Fact;
use TangibleDDD\WordPress\Adapter\WpdbProcessStore;
use TangibleDDD\WordPress\Adapter\WpdbWakeupScheduler;

use function TangibleDDD\WordPress\register_process_hooks;

/**
 * The 0.6 ProcessRunner construction (config, repository) on a migrated
 * consumer resolves the v8 ports through HostDefaults; a suspended
 * process's timeout is a durable intent projected to the legacy AS hook,
 * and the AS callback brackets the intent (firing → done; failed → back to
 * pending and re-projected by a relay tick).
 */
final class WpProcessWakeE2ETest extends V8TestCase {

  private ProcessRunner $runner;

  protected function setUp(): void {
    parent::setUp();
    $this->installV8();
    V8AwaitingProcess::$finished = 0;
    $this->runner = new ProcessRunner($this->config, new ProcessRepository($this->config));
    $this->runner->register_event(V8Fact::class);
    $runner = $this->runner;
    register_process_hooks($this->config, static fn () => new class($runner) {
      public function __construct(private ProcessRunner $runner) {}
      public function get(string $id): object { return $this->runner; }
    });
  }

  private function start(int $n): int {
    $p = new V8AwaitingProcess($n);
    $this->runner->start($p);
    return (int) $p->get_id();
  }

  /** @return array<string, mixed> */
  private function intent(string $key): array {
    return $this->rows($this->wpdb->prepare("SELECT status, attempts, last_error, as_action_id FROM `{$this->table('ddd_wakeups')}` WHERE idempotency_key = %s", $key))[0];
  }

  private function processStatus(int $id): string {
    return (string) $this->wpdb->get_var("SELECT status FROM `{$this->table('long_processes')}` WHERE id = $id");
  }

  private function runAction(int $actionId): void {
    \ActionScheduler::runner()->process_action($actionId, 'ddd-v8-test');
  }

  public function test_the_runner_resolves_the_v8_ports(): void {
    self::assertInstanceOf(WpdbProcessStore::class, HostDefaults::for(IProcessStore::class, $this->config, new ProcessRepository($this->config)));
    self::assertInstanceOf(WpdbWakeupScheduler::class, HostDefaults::for(IWakeupScheduler::class, $this->config));
  }

  public function test_a_timeout_fires_through_its_intent_and_completes_the_process(): void {
    $id = $this->start(1);
    self::assertSame('suspended', $this->processStatus($id));
    $intent = $this->intent("timeout:$id:0");
    self::assertSame('pending', $intent['status']);
    $actions = $this->pendingActions('ddd8it_await_timeout');
    self::assertSame([['process_id' => $id, 'step_index' => 0]], array_column($actions, 'args'));
    self::assertGreaterThan(time() + 3500, $actions[0]->due, 'future-dated: a rolled-back 0.6 winner fires it when due');

    $this->runAction($actions[0]->id);

    self::assertSame('completed', $this->processStatus($id));
    self::assertSame(1, V8AwaitingProcess::$finished);
    self::assertSame('done', $this->intent("timeout:$id:0")['status']);
  }

  public function test_a_stale_timeout_after_the_awaited_fact_is_a_no_op(): void {
    $id = $this->start(2);
    do_action(V8Fact::integration_action(), IntegrationEnvelope::wrap((new V8Fact(2))->integration_payload(), '44444444-4444-4444-8444-444444444444', 1, 'f0000000-0000-4000-8000-000000000002'));
    self::assertSame('completed', $this->processStatus($id));

    $this->runAction($this->pendingActions('ddd8it_await_timeout')[0]->id);

    self::assertSame(1, V8AwaitingProcess::$finished, 'no resurrection');
    self::assertSame('done', $this->intent("timeout:$id:0")['status']);
  }

  public function test_a_contended_wake_is_re_queued_by_the_relay_tick_and_later_succeeds(): void {
    $id = $this->start(3);
    $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    self::assertSame('1', (string) $other->get_var("SELECT GET_LOCK('ddd_process_$id', 0)"), 'a 0.6 copy holds the legacy lock');

    $this->runAction($this->pendingActions('ddd8it_await_timeout')[0]->id);

    self::assertSame('suspended', $this->processStatus($id), 'nothing ran unlocked');
    $intent = $this->intent("timeout:$id:0");
    self::assertSame(['pending', '1'], [$intent['status'], $intent['attempts']]);
    self::assertStringContainsString('lock', strtolower((string) $intent['last_error']));
    self::assertSame([], $this->pendingActions('ddd8it_await_timeout'), 'Action Scheduler recorded the failed action');

    $other->query("SELECT RELEASE_LOCK('ddd_process_$id')");
    $scheduler = HostDefaults::for(IWakeupScheduler::class, $this->config);
    self::assertSame(1, $scheduler->reproject(new \DateTimeImmutable('+2 hours')));
    $this->runAction($this->pendingActions('ddd8it_await_timeout')[0]->id);

    self::assertSame('completed', $this->processStatus($id));
    self::assertSame(1, V8AwaitingProcess::$finished);
    self::assertSame('done', $this->intent("timeout:$id:0")['status']);
  }
}
