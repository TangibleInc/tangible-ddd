<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Conformance\FreshProcesses;
use TangibleDDD\Conformance\ProcessHost;
use TangibleDDD\Conformance\ScenarioCatalogue;
use TangibleDDD\Conformance\ScenarioId;

/**
 * Pins the 37 pdo ids of register section 8 (wave 3) and proves each runs
 * the shared scenario unchanged in BOTH prepare modes: for every abstract
 * case that declares a due id there is a Native and an Emulated host class,
 * and no host class redeclares a due id (a redeclaration is how a host
 * skips one).
 */
#[Group('pdo')]
final class PdoCatalogueTest extends TestCase {

  private const PDO_WAVE_3 = [
    'cmd.commit-atomic', 'cmd.commit-failure', 'cmd.reaction-throws', 'cmd.no-boundary',
    'cmd.nested-rejected', 'cmd.guards-without-audit', 'cmd.return-value',
    'relay.fresh-process-pickup', 'relay.crash-after-commit', 'relay.crash-after-submit',
    'relay.lease-fencing', 'relay.invalid-acceptance', 'relay.pause-holders',
    'relay.replay-keeps-identity', 'relay.replay-keeps-identity.process',
    'delivery.double-delivery', 'delivery.double-delivery.process',
    'delivery.subscriber-isolation', 'delivery.subscriber-isolation.process',
    'delivery.phase-order', 'delivery.phase-order.process', 'delivery.delayed-once',
    'lock.contention', 'lock.acquire-error', 'lock.reentrant-balance', 'lock.namespace',
    'process.ignition-race', 'process.manual-start-in-drain', 'process.await-all-concurrent',
    'process.timeout-vs-event', 'process.await-before-dispatch', 'process.intent-survives-queue-failure',
    'process.stale-wakeup', 'process.crash-mid-step', 'process.fresh-process-resume',
    'worker.no-leak', 'audit.sink-fails',
  ];

  public const MODES = ['Native', 'Emulated'];

  public function test_pdo_wave_3_matches_register_section_8(): void {
    self::assertCount(37, self::PDO_WAVE_3);
    self::assertSame([], ScenarioCatalogue::dueBy('pdo', 2), 'nothing is due on pdo before wave 3');
    self::assertEqualsCanonicalizing(self::PDO_WAVE_3, ScenarioCatalogue::dueBy('pdo', 3));
  }

  /** @return array<string, array{string}> */
  public static function modes(): array {
    return ['native' => ['Native'], 'emulated' => ['Emulated']];
  }

  #[DataProvider('modes')]
  public function test_every_id_due_on_pdo_runs_the_shared_scenario_in_this_prepare_mode(string $mode): void {
    $classes = self::hostClasses($mode);
    $implemented = ScenarioId::implementedBy($classes);

    self::assertSame([], array_values(array_diff(self::PDO_WAVE_3, array_keys($implemented))), "due on pdo but no $mode scenario method");

    foreach ($classes as $class) {
      $ref = new \ReflectionClass($class);
      $groups = array_map(static fn ($a) => $a->newInstance()->name(), $ref->getAttributes(Group::class));
      self::assertContains('pdo', $groups, "$class carries the host group");
      self::assertContains('pdo-' . strtolower($mode), $groups, "$class carries its prepare-mode group");

      foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
        $id = ScenarioId::of($m);
        if ($id !== null && in_array($id, self::PDO_WAVE_3, true)) {
          self::assertNotSame($class, $m->getDeclaringClass()->getName(), "$id is due on pdo in wave 3 and must not be overridden by $class");
        }
      }
    }
  }

  public function test_the_fixture_implements_every_seam_the_pdo_ids_need(): void {
    $interfaces = class_implements(PdoHostFixture::class);
    self::assertContains(ProcessHost::class, $interfaces);
    self::assertContains(FreshProcesses::class, $interfaces);
  }

  /**
   * The host classes of one prepare mode, one per abstract case due on pdo
   * by wave 3: `{Mode}\Pdo{Mode}{Case}Test`.
   *
   * @return list<class-string>
   */
  public static function hostClasses(string $mode): array {
    $classes = [];
    foreach (ScenarioCatalogue::casesFor('pdo', 3) as $case) {
      $short = (new \ReflectionClass($case))->getShortName();
      $class = __NAMESPACE__ . "\\$mode\\Pdo{$mode}{$short}Test";
      self::assertTrue(class_exists($class), "missing $class");
      self::assertTrue(is_subclass_of($class, $case), "$class extends $case");
      $classes[] = $class;
    }
    return $classes;
  }
}
