<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Abi;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Infrastructure\AuditSinkFailed;
use TangibleDDD\Application\Infrastructure\FactDeliveredUnheard;
use TangibleDDD\Application\Infrastructure\OutboxAttemptFailed;
use TangibleDDD\Application\Infrastructure\OutboxDeadLettered;
use TangibleDDD\Application\Infrastructure\ProcessFailed;
use TangibleDDD\Application\Infrastructure\WorkflowFailed;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Domain\Events\IntegrationEvent;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Infra\Persistence\OutboxRepository;
use TangibleDDD\Infra\Services\ActionSchedulerOutboxPublisher;
use TangibleDDD\Infra\Services\RoutingOutboxPublisher;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Tests\Unit\Abi\Support\RecordingConfig;
use TangibleDDD\WordPress\Adapter\ActionSchedulerWakeupScheduler;
use TangibleDDD\WordPress\Adapter\HostDefaultsWiring;
use TangibleDDD\WordPress\Adapter\WpHookSignalDispatcher;
use TangibleDDD\WordPress\Adapter\WpOptionsOutboxConfigReader;

use function TangibleDDD\WordPress\consumers;
use function TangibleDDD\WordPress\ddd_schema_version_key;
use function TangibleDDD\WordPress\install_tables;
use function TangibleDDD\WordPress\register_outbox_hooks;
use function TangibleDDD\WordPress\register_process_hooks;
use function TangibleDDD\WordPress\with_lock;

/**
 * ABI freeze, register B15 (section 8, wave 2 wp): every name N derives
 * for a consumer and persists or exposes (hooks, filters, options, Action
 * Scheduler hooks / groups / args, table names) is the 0.6.6 name. Pending
 * AS actions and outbox rows store these names, and other plugins subscribe
 * to them, so a rename strands queued work or silences a listener.
 *
 * Fixed consumer: DDDConfig('tgbl_cred', 'Tangible\\Cred', '1.2.3') (cred's
 * prefix). Each name is observed on N's REAL code path (the wp-stubs record
 * add_action / do_action / AS calls; RecordingConfig records what the
 * framework asks the config for), then compared with a literal golden value
 * taken from v0.6.6 (report B15 cites each source line). Every test runs in
 * its own process: the stubs' registries and the static caches are global.
 */
#[RunTestsInSeparateProcesses]
final class GoldenDerivedNamesTest extends TestCase {

  private DDDConfig $config;

  protected function setUp(): void {
    $GLOBALS['wpdb'] = new \wpdb();
    HostDefaultsWiring::register();
    $this->config = new DDDConfig('tgbl_cred', 'Tangible\\Cred', '1.2.3');
  }

  public function test_the_eight_DDDConfig_derivations(): void {
    $c = $this->config;
    self::assertSame('tgbl_cred', $c->prefix());
    self::assertSame('wp_tgbl_cred_integration_outbox', $c->table('integration_outbox'));
    self::assertSame('tgbl_cred_process_continue', $c->hook('process_continue'));
    self::assertSame('tgbl_cred-outbox', $c->as_group('outbox'));
    self::assertSame('tgbl_cred_outbox_pauses', $c->option('outbox_pauses'));
    self::assertSame('tgbl_cred_domain_user_earned', $c->domain_action('user_earned'));
    self::assertSame('tgbl_cred_integration_user_earned', $c->integration_action('user_earned'));
    self::assertSame('1.2.3', $c->version());
  }

  public function test_N_registers_callbacks_on_every_legacy_runtime_hook(): void {
    global $_test_action_registrations;
    $_test_action_registrations = [];
    $config = new RecordingConfig($this->config);

    register_process_hooks($config, static fn () => null);
    register_outbox_hooks($config, static fn () => null);

    // Action Scheduler fires these with the args queued by any 0.6 copy.
    self::assertSame(1, $_test_action_registrations['tgbl_cred_process_continue'][0]['accepted_args'] ?? null, 'process_continue(int $process_id)');
    self::assertSame(2, $_test_action_registrations['tgbl_cred_await_timeout'][0]['accepted_args'] ?? null, 'await_timeout(int $process_id, int $step_index)');
    self::assertArrayHasKey('tgbl_cred_outbox_process', $_test_action_registrations, 'the recurring relay tick');
    self::assertArrayHasKey('init', $_test_action_registrations, 'the relay tick is scheduled at init');

    $continue = new \ReflectionFunction($_test_action_registrations['tgbl_cred_process_continue'][0]['callback']);
    $timeout = new \ReflectionFunction($_test_action_registrations['tgbl_cred_await_timeout'][0]['callback']);
    self::assertSame(['process_id'], array_map(static fn ($p) => $p->getName(), $continue->getParameters()), 'AS passes the associative args as NAMED arguments (R4)');
    self::assertSame(['process_id', 'step_index'], array_map(static fn ($p) => $p->getName(), $timeout->getParameters()));
  }

  public function test_wakeups_use_the_legacy_hooks_args_and_group(): void {
    global $_test_scheduled_actions;
    $_test_scheduled_actions = [];
    $scheduler = new ActionSchedulerWakeupScheduler($this->config);

    $scheduler->schedule(new WakeupIntent(WakeKind::Timeout, 'tgbl_cred', 7, 2, null, new \DateTimeImmutable('+1 hour'), 'timeout:7:2'));
    $scheduler->schedule(new WakeupIntent(WakeKind::Continue, 'tgbl_cred', 7, null, null, new \DateTimeImmutable('+1 hour'), 'continue:7:0'));

    self::assertSame('tgbl_cred_await_timeout', $_test_scheduled_actions[0]['hook']);
    self::assertSame(['process_id' => 7, 'step_index' => 2], $_test_scheduled_actions[0]['args'], 'R4: associative args, keys frozen');
    self::assertSame('tgbl_cred-processes', $_test_scheduled_actions[0]['group']);
    self::assertSame('tgbl_cred_process_continue', $_test_scheduled_actions[1]['hook']);
    self::assertSame(['process_id' => 7], $_test_scheduled_actions[1]['args']);
    self::assertSame('tgbl_cred-processes', $_test_scheduled_actions[1]['group']);
  }

  public function test_the_outbox_options_keys_and_group(): void {
    $config = new RecordingConfig($this->config);

    $outbox = (new WpOptionsOutboxConfigReader())->read($config);

    self::assertSame([
      'tgbl_cred_outbox_batch_size',
      'tgbl_cred_outbox_max_attempts',
      'tgbl_cred_outbox_retry_delay',
      'tgbl_cred_outbox_retry_multiplier',
      'tgbl_cred_outbox_max_retry_delay',
      'tgbl_cred_outbox_processor_interval',
      'tgbl_cred_outbox_lock_timeout',
      'tgbl_cred_outbox_max_as_payload_bytes',
      'tgbl_cred_outbox_route_large_payloads_external',
    ], $config->take('option'), 'OutboxConfig::from_options option keys (0.6.6 OutboxConfig.php:30-39)');
    self::assertSame('tgbl_cred-outbox', $outbox->action_scheduler_group);
    self::assertSame(['tgbl_cred-outbox'], $config->take('as_group'));

    (new OutboxRepository($config, $outbox))->is_paused('any');
    self::assertSame(['tgbl_cred_outbox_pauses'], $config->take('option'), 'the relay pause option');

    self::assertSame('tgbl_cred_ddd_schema_version', ddd_schema_version_key($this->config));
  }

  public function test_an_outbox_row_carries_the_legacy_hook_and_queue(): void {
    $rows = [];
    $GLOBALS['wpdb'] = new class($rows) extends \wpdb {
      public function __construct(private array &$rows) {}
      public function insert(string $table, array $data, $format = null): bool {
        $this->rows[] = ['table' => $table] + $data;
        return true;
      }
    };
    $fact = new class('u-1') extends IntegrationEvent {
      public function __construct(public readonly string $user_id) {}
      protected static function prefix(): string { return 'tgbl_cred'; }
      public static function name(): string { return 'user_earned'; }
    };

    (new OutboxRepository($this->config, new OutboxConfig()))->write($fact, 'corr-1');

    self::assertSame('wp_tgbl_cred_integration_outbox', $rows[0]['table']);
    self::assertSame('tgbl_cred_integration_user_earned', $rows[0]['integration_action'], 'the AS hook the relay schedules (stored per row)');
    self::assertSame('tgbl_cred-outbox', $rows[0]['queue'], 'the AS group the relay publishes into');
  }

  public function test_the_relay_publishes_on_the_row_hook_and_group_and_exposes_the_routing_filters(): void {
    global $_test_scheduled_actions;
    $_test_scheduled_actions = [];
    $seen = [];
    add_filter('tgbl_cred_outbox_transport_for_entry', static function ($transport) use (&$seen) {
      $seen[] = 'tgbl_cred_outbox_transport_for_entry';
      return $transport;
    });
    add_filter('tgbl_cred_outbox_publish_external', static function ($handled) use (&$seen) {
      $seen[] = 'tgbl_cred_outbox_publish_external';
      return true;
    });
    $outboxConfig = new OutboxConfig(action_scheduler_group: 'tgbl_cred-outbox');
    $publisher = new RoutingOutboxPublisher($this->config, $outboxConfig, new ActionSchedulerOutboxPublisher($outboxConfig));

    $publisher->publish(self::entry('action_scheduler'), ['__event_id' => 'e-1']);
    $publisher->publish(self::entry('external'), ['__event_id' => 'e-2']);

    self::assertSame('tgbl_cred_integration_user_earned', $_test_scheduled_actions[0]['hook']);
    self::assertSame('tgbl_cred-outbox', $_test_scheduled_actions[0]['group']);
    self::assertSame([['__event_id' => 'e-1']], $_test_scheduled_actions[0]['args'], 'R4: AS args = [wrapped envelope]');
    self::assertCount(1, $_test_scheduled_actions, 'the external entry went to the filter, not AS');
    self::assertSame(['tgbl_cred_outbox_transport_for_entry', 'tgbl_cred_outbox_transport_for_entry', 'tgbl_cred_outbox_publish_external'], $seen);
  }

  public function test_infrastructure_signals_keep_their_action_names_and_both_hooks(): void {
    self::assertSame([
      FactDeliveredUnheard::class => 'fact_delivered_unheard',
      OutboxAttemptFailed::class => 'outbox_attempt_failed',
      OutboxDeadLettered::class => 'outbox_dlq',
      ProcessFailed::class => 'process_failed',
      WorkflowFailed::class => 'workflow_failed',
    ], array_combine(
      [FactDeliveredUnheard::class, OutboxAttemptFailed::class, OutboxDeadLettered::class, ProcessFailed::class, WorkflowFailed::class],
      [FactDeliveredUnheard::action(), OutboxAttemptFailed::action(), OutboxDeadLettered::action(), ProcessFailed::action(), WorkflowFailed::action()],
    ), 'the five 0.6.6 signals');

    global $_test_did_actions;
    $_test_did_actions = [];
    (new WpHookSignalDispatcher())->emit(new AuditSinkFailed(str_repeat('a', 32), null, 'close', 'x'), $this->config);
    self::assertSame(['tgbl_cred_audit_sink_failed' => 1, 'tangible_ddd_audit_sink_failed' => 1], $_test_did_actions, '{prefix}_{action} and tangible_ddd_{action}');
  }

  public function test_the_consumers_filter(): void {
    $seen = false;
    add_filter('tangible_ddd_consumers', static function (array $handles) use (&$seen): array {
      $seen = true;
      return $handles;
    });
    consumers();
    self::assertTrue($seen, 'tangible_ddd_consumers');
  }

  public function test_install_tables_creates_the_legacy_table_set(): void {
    $GLOBALS['__abi_dbdelta'] = [];
    if (!function_exists('dbDelta')) {
      eval('function dbDelta($queries = "", $execute = true) { $GLOBALS["__abi_dbdelta"][] = $queries; return []; }');
    }
    $GLOBALS['wpdb'] = new class extends \wpdb {
      public function get_charset_collate(): string { return ''; }
    };

    install_tables($this->config);

    $tables = [];
    foreach ($GLOBALS['__abi_dbdelta'] as $sql) {
      foreach ((array) $sql as $one) {
        if (preg_match_all('/CREATE TABLE\s+`?([A-Za-z0-9_]+)`?/i', (string) $one, $m)) {
          array_push($tables, ...$m[1]);
        }
      }
    }
    sort($tables);
    self::assertSame([
      'wp_tgbl_cred_behaviour_workflow_items',
      'wp_tgbl_cred_behaviour_workflows',
      'wp_tgbl_cred_behaviour_workflows_meta',
      'wp_tgbl_cred_command_audit',
      'wp_tgbl_cred_ddd_delivery_ledger',
      'wp_tgbl_cred_ddd_relay_pauses',
      'wp_tgbl_cred_ddd_wakeups',
      'wp_tgbl_cred_integration_dlq',
      'wp_tgbl_cred_integration_outbox',
      'wp_tgbl_cred_long_processes',
      'wp_tgbl_cred_touches',
    ], $tables, 'install_tables(IDDDConfig): the 0.6.6 table set plus the three schema v8 tables (wave 3), every 0.6.6 name unchanged');
  }

  public function test_the_lock_option_key(): void {
    $GLOBALS['__abi_options'] = [];
    if (!function_exists('TangibleDDD\\WordPress\\add_option')) {
      eval('namespace TangibleDDD\\WordPress; function add_option($k, $v = "", $d = "", $a = "yes") { $GLOBALS["__abi_options"][] = "add:$k"; return true; } function delete_option($k) { $GLOBALS["__abi_options"][] = "delete:$k"; return true; }');
    }

    self::assertSame('ran', with_lock('tgbl_cred', 'user_7', static fn () => 'ran'));
    self::assertSame(['add:_tgbl_cred_lock_user_7', 'delete:_tgbl_cred_lock_user_7'], $GLOBALS['__abi_options'], 'with_lock option key `_{prefix}_lock_{name}`');
  }

  private static function entry(string $transport): OutboxEntry {
    return new OutboxEntry(
      1, 'e-1', 'user_earned', 'tgbl_cred_integration_user_earned', 'event', $transport, 'tgbl_cred-outbox', 10,
      'corr-1', 1, null, ['user_id' => 'u-1'], 0, gmdate('Y-m-d H:i:s', time() - 5), false, 'pending', 0, 5,
      null, null, null, null, null, gmdate('Y-m-d H:i:s'), null, 1,
    );
  }
}
