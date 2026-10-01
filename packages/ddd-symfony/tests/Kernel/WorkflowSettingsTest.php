<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgniter;
use TangibleDDD\Runtime\Ops\Layer;

/**
 * W3 (TXP process-kernel demands): the WorkflowIgniter clocks are bundle
 * config (`tangible_ddd.workflow.stale_start_seconds`, `stale_claim_seconds`),
 * the `ddd_facts` transport gets an explicit redeliver_timeout
 * (`tangible_ddd.messenger.redeliver_timeout_seconds`), and the operator
 * view's workflow layer (W5) uses the same stale_start_seconds.
 */
final class WorkflowSettingsTest extends KernelTestBase {

  protected static string $variant = 'workflow_settings';

  private static function property(object $o, string $name): mixed {
    return (new \ReflectionProperty($o, $name))->getValue($o);
  }

  public function test_the_igniter_takes_the_configured_clocks(): void {
    $igniter = self::getContainer()->get('test.workflow_igniter');

    self::assertInstanceOf(WorkflowIgniter::class, $igniter);
    self::assertSame(120, self::property($igniter, 'stale_start_seconds'));
    self::assertSame(300, self::property($igniter, 'stale_claim_seconds'));
  }

  public function test_the_facts_transport_redelivers_after_the_configured_timeout(): void {
    $config = self::getContainer()->getParameter('tangible_ddd.config');
    self::assertSame(240, $config['messenger']['redeliver_timeout_seconds']);

    $transport = self::getContainer()->get('messenger.transport.ddd_facts');
    $connection = self::property($transport, 'connection');
    self::assertSame(240, self::property($connection, 'configuration')['redeliver_timeout']);
  }

  public function test_a_start_marker_older_than_stale_start_seconds_is_in_the_workflow_layer(): void {
    $ledger = self::getContainer()->get('test.workflow_ledger');
    $ledger->claim('marker-old', 'digest', 'evt-1');
    $ledger->claim('marker-new', 'digest', 'evt-2');
    $this->db->executeStatement("UPDATE ddd_workflow_ignitions SET created_at = now() - interval '3 minutes' WHERE dedup_key = 'marker-old'");

    $items = self::getContainer()->get('test.operator_view')->list(Layer::Workflow);

    self::assertSame(['ignition:marker-old'], array_map(static fn ($i) => $i->key, $items));
    self::assertStringContainsString('older than 120 s', (string) $items[0]->last_error);
  }
}
