<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8;

use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Scheduling\ICarriesFacts;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Tests\Integration\V8\Fakes\V8AwaitingProcess;
use TangibleDDD\Tests\Integration\V8\Fakes\V8Fact;
use TangibleDDD\WordPress\Adapter\WpdbParkingScheduler;
use TangibleDDD\WordPress\Adapter\WpdbTransactionBoundary;
use TangibleDDD\WordPress\Adapter\WpdbWakeupScheduler;

use function TangibleDDD\WordPress\ddd_maybe_migrate;
use function TangibleDDD\WordPress\ddd_schema_version_key;
use function TangibleDDD\WordPress\register_process_hooks;

/**
 * Schema v9 and AW2 on WordPress (wave 5, CR-W5CC-7): `{prefix}_ddd_wakeups`
 * gains a nullable `fact` column, a v9 consumer's runner gets the
 * WpdbParkingScheduler (ICarriesFacts), and an answer whose process lock is
 * taken is parked as a fact-carrying ResumeRetry intent on the
 * `{prefix}_ddd_wakeup` hook instead of failing its resume subscriber; the
 * intent resumes the process once the lock is free.
 */
final class WpParkedFactV9Test extends V8TestCase {

  private const EVENT_ID = 'f0000000-0000-4000-8000-000000000009';

  /** @return array{class: string, payload: array<string, mixed>, event_id: string} */
  private static function fact(): array {
    return ['class' => V8Fact::class, 'payload' => ['z' => 1, 'n' => 9, 'a' => ['f' => 1.5, 'u' => "na\u{00EF}ve"]], 'event_id' => self::EVENT_ID];
  }

  public function test_a_fresh_install_migrates_to_v9_with_the_fact_column(): void {
    ddd_maybe_migrate($this->config);

    self::assertSame(9, (int) get_option(ddd_schema_version_key($this->config)));
    self::assertTrue($this->columnExists($this->table('ddd_wakeups'), 'fact'));
  }

  public function test_a_v8_consumer_gains_the_fact_column_at_v9(): void {
    $this->installV8();
    $this->wpdb->query("ALTER TABLE `{$this->table('ddd_wakeups')}` DROP COLUMN `fact`");
    self::assertFalse($this->columnExists($this->table('ddd_wakeups'), 'fact'), 'a table as v8 shipped it');

    ddd_maybe_migrate($this->config);

    self::assertTrue($this->columnExists($this->table('ddd_wakeups'), 'fact'));
    self::assertSame(9, (int) get_option(ddd_schema_version_key($this->config)));
  }

  public function test_the_factory_serves_the_parking_scheduler_only_at_v9(): void {
    $this->installCurrent();
    $scheduler = HostDefaults::for(IWakeupScheduler::class, $this->config);
    self::assertInstanceOf(WpdbParkingScheduler::class, $scheduler);
    self::assertInstanceOf(ICarriesFacts::class, $scheduler);

    update_option(ddd_schema_version_key($this->config), 8, false);
    $v8 = HostDefaults::for(IWakeupScheduler::class, $this->config);
    self::assertInstanceOf(WpdbWakeupScheduler::class, $v8);
    self::assertNotInstanceOf(ICarriesFacts::class, $v8, 'v8 keeps the wave-3 delivery retry');
  }

  public function test_a_parked_fact_round_trips_through_the_intent_row(): void {
    $this->installCurrent();
    $scheduler = new WpdbParkingScheduler($this->config);
    $intent = WakeupIntent::resume_fact('ddd8it', 7, 0, self::fact(), new \DateTimeImmutable('-1 second'));

    (new WpdbTransactionBoundary())->run(static fn () => $scheduler->schedule($intent));

    [$claimed] = $scheduler->claim_due(new \DateTimeImmutable(), 10, 60);
    self::assertSame($intent->key, $claimed->intent->key);
    self::assertSame(WakeKind::ResumeRetry, $claimed->intent->kind);
    self::assertSame(self::fact(), $claimed->intent->fact, 'class, payload (key order included) and event id');
    self::assertSame([['key' => $intent->key]], array_column($this->pendingActions('ddd8it_ddd_wakeup'), 'args'), 'the projection stays the key');
  }

  public function test_an_intent_without_a_fact_stores_null_and_works_on_a_v8_table(): void {
    $this->installV8();
    $this->wpdb->query("ALTER TABLE `{$this->table('ddd_wakeups')}` DROP COLUMN `fact`");
    $scheduler = new WpdbWakeupScheduler($this->config);

    (new WpdbTransactionBoundary())->run(static fn () => $scheduler->schedule(WakeupIntent::timeout('ddd8it', 5, 0, new \DateTimeImmutable('-1 second'))));

    [$claimed] = $scheduler->claim_due(new \DateTimeImmutable(), 10, 60);
    self::assertNull($claimed->intent->fact);
  }

  public function test_an_answer_held_off_by_the_lock_is_parked_and_resumes_the_process_later(): void {
    $this->installCurrent();
    V8AwaitingProcess::$finished = 0;
    $runner = new ProcessRunner($this->config, new ProcessRepository($this->config));
    $runner->register_event(V8Fact::class);
    register_process_hooks($this->config, static fn () => new class($runner) {
      public function __construct(private ProcessRunner $runner) {}
      public function get(string $id): object { return $this->runner; }
    });
    $p = new V8AwaitingProcess(9);
    $runner->start($p);
    $id = (int) $p->get_id();

    $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    self::assertSame('1', (string) $other->get_var("SELECT GET_LOCK('ddd_process_$id', 0)"), 'another worker holds the process lock');
    try {
      do_action(V8Fact::integration_action(), IntegrationEnvelope::wrap((new V8Fact(9))->integration_payload(), '44444444-4444-4444-8444-444444444449', 1, self::EVENT_ID));

      self::assertSame('suspended', $this->processStatus($id), 'nothing ran unlocked');
      self::assertSame(0, V8AwaitingProcess::$finished);
      $parked = $this->rows($this->wpdb->prepare("SELECT idempotency_key, status, expected_status, fact FROM `{$this->table('ddd_wakeups')}` WHERE kind = 'resume_retry' AND process_id = %d", $id));
      self::assertCount(1, $parked, 'one fact-carrying ResumeRetry intent');
      self::assertSame(['pending', 'suspended'], [$parked[0]['status'], $parked[0]['expected_status']]);
      self::assertSame(self::EVENT_ID, json_decode((string) $parked[0]['fact'], true)['event_id'] ?? null);
      self::assertSame([], $this->rows("SELECT * FROM `{$this->table('ddd_delivery_ledger')}` WHERE status <> 'delivered'"), 'the resume subscriber spent no attempt');
      $actions = $this->pendingActions('ddd8it_ddd_wakeup');
      self::assertCount(1, $actions);
    } finally {
      $other->query("SELECT RELEASE_LOCK('ddd_process_$id')");
    }

    \ActionScheduler::runner()->process_action($actions[0]->id, 'ddd-v9-test');

    self::assertSame('completed', $this->processStatus($id), 'the parked answer resumed the process');
    self::assertSame(1, V8AwaitingProcess::$finished);
    self::assertSame('done', (string) $this->wpdb->get_var($this->wpdb->prepare("SELECT status FROM `{$this->table('ddd_wakeups')}` WHERE idempotency_key = %s", $parked[0]['idempotency_key'])));
  }

  private function processStatus(int $id): string {
    return (string) $this->wpdb->get_var("SELECT status FROM `{$this->table('long_processes')}` WHERE id = $id");
  }
}
