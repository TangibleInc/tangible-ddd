<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Conformance\ScenarioCatalogue;
use TangibleDDD\Conformance\ScenarioId;

/**
 * Pins the sf wave-2 id list of register section 8 and proves each id has
 * a scenario method on an sf host class that runs the shared scenario
 * unchanged: an sf class that redeclares a due id (to skip it) fails here.
 */
#[Group('sf')]
#[Group('conformance')]
final class SfCatalogueTest extends TestCase {

  private const SF_WAVE_2 = [
    'cmd.commit-atomic', 'cmd.commit-failure', 'cmd.reaction-throws', 'cmd.no-boundary',
    'cmd.nested-rejected', 'cmd.guards-without-audit', 'cmd.return-value',
    'relay.crash-after-submit', 'relay.lease-fencing', 'relay.invalid-acceptance', 'relay.pause-holders',
    'delivery.double-delivery', 'delivery.subscriber-isolation', 'delivery.phase-order',
    'worker.no-leak',
  ];

  private const HOST_CLASSES = [
    SfCommandScenariosTest::class,
    SfRelayScenariosTest::class,
    SfDeliveryScenariosTest::class,
    SfWorkerScenariosTest::class,
  ];

  public function test_sf_wave_2_matches_register_section_8(): void {
    self::assertSame([], ScenarioCatalogue::dueBy('sf', 1));
    self::assertEqualsCanonicalizing(self::SF_WAVE_2, ScenarioCatalogue::dueBy('sf', 2));
  }

  public function test_every_id_due_on_sf_by_wave_2_runs_the_shared_scenario(): void {
    $implemented = ScenarioId::implementedBy(self::HOST_CLASSES);

    self::assertSame([], array_values(array_diff(self::SF_WAVE_2, array_keys($implemented))), 'due on sf but no scenario method');

    foreach (self::HOST_CLASSES as $class) {
      foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
        $id = ScenarioId::of($m);
        if ($id !== null && in_array($id, self::SF_WAVE_2, true)) {
          self::assertNotSame($class, $m->getDeclaringClass()->getName(), "$id is due on sf in wave 2 and must not be overridden by $class");
        }
      }
    }
  }
}
