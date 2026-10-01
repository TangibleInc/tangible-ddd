<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8;

use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Outbox\IOutboxPublisher;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Infra\IOutboxRepository;
use TangibleDDD\Infra\IProcessRepository;
use TangibleDDD\Infra\Persistence\OutboxRepository;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Infra\Services\ActionSchedulerOutboxPublisher;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Tests\Integration\V8\Fakes\V8Fact;
use TangibleDDD\Tests\Integration\V8\Fakes\V8ManualProcess;
use TangibleDDD\WordPress\Adapter\WpdbOutboxStore;
use TangibleDDD\WordPress\Adapter\WpLedgeredDelivery;
use TangibleDDD\WordPress\Adapter\WpOperatorView;
use TangibleDDD\WordPress\Adapter\WpRelayTick;
use TangibleDDD\WordPress\Adapter\WpRollbackDrain;

use function TangibleDDD\WordPress\integration_action;
use function TangibleDDD\WordPress\register_delivery_hooks;

/**
 * The wp relay tick (`{prefix}_outbox_process`, `wp ddd relay --once`),
 * the operator view (`wp ddd ops`) and the pre-rollback drain
 * (`wp ddd drain --before-rollback`).
 */
final class WpRelayTickV8Test extends V8TestCase {

  protected function setUp(): void {
    parent::setUp();
    $this->installV8();
    WpLedgeredDelivery::resetForTests();
  }

  protected function tearDown(): void {
    WpLedgeredDelivery::resetForTests();
    parent::tearDown();
  }

  /** @param array<string, object> $services */
  private function container(array $services): object {
    return new class($services) {
      public function __construct(private array $services) {}
      public function has(string $id): bool { return isset($this->services[$id]); }
      public function get(string $id): object {
        return $this->services[$id] ?? throw new \RuntimeException("no $id");
      }
    };
  }

  /** @return array<string, object> */
  private function frameworkServices(): array {
    $outboxConfig = new OutboxConfig(action_scheduler_group: $this->config->as_group('outbox'));
    $repo = new OutboxRepository($this->config, $outboxConfig);
    $publisher = new ActionSchedulerOutboxPublisher($outboxConfig);
    return [
      OutboxConfig::class => $outboxConfig,
      IOutboxRepository::class => $repo,
      IOutboxPublisher::class => $publisher,
      IProcessRepository::class => new ProcessRepository($this->config),
      OutboxProcessor::class => new OutboxProcessor($this->config, $repo, $outboxConfig, $publisher),
    ];
  }

  private function append(string $eventId): void {
    $store = new WpdbOutboxStore(new OutboxRepository($this->config, new OutboxConfig()), $this->config);
    $store->append(new OutboxRecord($eventId, 'v8.fact', V8Fact::integration_action(), '22222222-2222-4222-8222-222222222222', 1, null, ['n' => 1], new \DateTimeImmutable('-1 second')));
  }

  public function test_a_migrated_framework_consumer_relays_in_port_form_and_runs_the_recovery_steps(): void {
    $this->append('e1000000-0000-4000-8000-000000000001');
    $old = gmdate('Y-m-d H:i:s', time() - 3600);
    $stranded = SchemaV7::process($this->config, V8ManualProcess::class, 'scheduled', 1, null);
    $this->wpdb->query("UPDATE `{$this->table('long_processes')}` SET updated_at = '$old'");

    $tick = WpRelayTick::for($this->config, $this->container($this->frameworkServices()));
    self::assertTrue($tick->isPortForm());
    $report = $tick->run();

    self::assertTrue($report->ok(), $report->summary());
    self::assertSame(1, $report->relay->completed);
    self::assertSame(0, $report->reprojected);
    self::assertSame([$stranded], $report->stranded->minted);
    self::assertSame('completed', $this->wpdb->get_var("SELECT status FROM `{$this->table('integration_outbox')}`"));
    $actions = $this->pendingActions(V8Fact::integration_action());
    self::assertSame('e1000000-0000-4000-8000-000000000001', IntegrationEnvelope::unwrap($actions[0]->args[0])->event_id);
    self::assertStringContainsString('relay (port form): 1 processed, 1 completed', $report->summary());
  }

  public function test_a_consumer_publisher_or_an_unmigrated_consumer_keeps_the_0_6_relay(): void {
    $services = $this->frameworkServices();
    $services[IOutboxPublisher::class] = new class implements IOutboxPublisher {
      public function publish(OutboxEntry $entry, array $wrapped_payload): void {}
    };
    self::assertFalse(WpRelayTick::for($this->config, $this->container($services))->isPortForm(), 'a routing/external publisher keeps its 0.6 path');

    update_option($this->config->option('ddd_schema_version'), 7, false);
    $tick = WpRelayTick::for($this->config, $this->container($this->frameworkServices()));
    self::assertFalse($tick->isPortForm());
    $report = $tick->run();
    self::assertNull($report->reprojected);
    self::assertNull($report->stranded);
  }

  public function test_the_operator_view_lists_every_layer_against_its_budget(): void {
    $this->append('e1000000-0000-4000-8000-000000000002');
    $this->wpdb->query("UPDATE `{$this->table('integration_outbox')}` SET attempts = 2, last_error = 'transport down'");
    (new \TangibleDDD\WordPress\Adapter\WpDeliveryLedger($this->config->prefix()))->markFailed('ddd8it/listener:x', 'e1000000-0000-4000-8000-000000000002', 'boom', 3);
    $this->wpdb->insert($this->table('ddd_wakeups'), [
      'idempotency_key' => 'timeout:9:1', 'kind' => 'timeout', 'process_id' => 9, 'step_index' => 1, 'due_at' => gmdate('Y-m-d H:i:s'),
      'status' => 'pending', 'attempts' => 2, 'last_error' => 'lock', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $quarantined = SchemaV7::process($this->config, 'Gone\\Class', 'failed', 0, null);
    $this->wpdb->update($this->table('long_processes'), ['quarantine_reason' => 'class missing'], ['id' => $quarantined]);

    $rows = (new WpOperatorView($this->config))->list();

    $by = [];
    foreach ($rows as $r) {
      $by[$r['layer']][] = [$r['key'], $r['attempts'], $r['budget'], $r['last_error']];
    }
    self::assertSame([['e1000000-0000-4000-8000-000000000002', 2, 5, 'transport down']], $by['relay']);
    self::assertSame([['ddd8it/listener:x @ e1000000-0000-4000-8000-000000000002', 3, 5, 'boom']], $by['delivery']);
    self::assertSame([['timeout:9:1', 2, 10, 'lock']], $by['wakeup']);
    self::assertSame([["#$quarantined Gone\\Class (quarantined)", 0, 0, 'class missing']], $by['process']);
    self::assertCount(1, (new WpOperatorView($this->config))->list('delivery'));
  }

  public function test_the_tick_restores_a_lost_redelivery(): void {
    $down = 1;
    integration_action(V8Fact::class, static function () use (&$down): void {
      if ($down-- > 0) {
        throw new \RuntimeException('down');
      }
    });
    register_delivery_hooks($this->config);
    do_action(V8Fact::integration_action(), IntegrationEnvelope::wrap(['n' => 1], '44444444-4444-4444-8444-444444444444', 1, 'f1000000-0000-4000-8000-000000000002'));
    as_unschedule_all_actions('ddd8it_ddd_redeliver'); // Action Scheduler failed it

    $report = WpRelayTick::for($this->config, $this->container($this->frameworkServices()))->run();

    self::assertTrue($report->ok(), $report->summary());
    self::assertSame(1, $report->redeliveriesRestored);
    self::assertCount(1, $this->pendingActions('ddd8it_ddd_redeliver'));
    self::assertStringContainsString('1 lost redeliveries re-scheduled', $report->summary());
  }

  public function test_the_pre_rollback_drain_runs_every_pending_redelivery_to_an_end(): void {
    $down = 2;
    $runs = 0;
    integration_action(V8Fact::class, static function () use (&$down, &$runs): void {
      $runs++;
      if ($down-- > 0) {
        throw new \RuntimeException('down');
      }
    });
    register_delivery_hooks($this->config);
    do_action(V8Fact::integration_action(), IntegrationEnvelope::wrap(['n' => 1], '44444444-4444-4444-8444-444444444444', 1, 'f1000000-0000-4000-8000-000000000001'));
    self::assertCount(1, $this->pendingActions('ddd8it_ddd_redeliver'), 'a future-dated redelivery a 0.6 winner would lose');

    $result = (new WpRollbackDrain($this->config))->run();

    self::assertSame(['ran' => 2, 'remaining' => 0, 'rounds' => 2], $result);
    self::assertSame(3, $runs, 'failed, failed on the first redelivery, delivered on the second');
  }
}
