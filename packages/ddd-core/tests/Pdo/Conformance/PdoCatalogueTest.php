<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Conformance\EffectHost;
use TangibleDDD\Conformance\FreshProcesses;
use TangibleDDD\Conformance\ProcessDecodeFaults;
use TangibleDDD\Conformance\ProcessHost;
use TangibleDDD\Conformance\ScenarioCatalogue;
use TangibleDDD\Conformance\ScenarioId;

/**
 * Pins the pdo ids of register section 8 (37 in wave 3, 7 more in wave 4:
 * the four wave-4 cells plus the three D3 ids of CR-W4C4-1) and proves each
 * runs the shared scenario unchanged in BOTH prepare modes: for every
 * abstract case that declares a due id there is a Native and an Emulated
 * host class, and no host class redeclares a due id (a redeclaration is how
 * a host skips one).
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

  /** Register section 8, wave 4, pdo: the ids pdo ADDS (D7, D6, R5, D1, and the D3 ids of CR-W4C4-1). */
  private const PDO_WAVE_4 = [
    'process.alarm-long', 'codec.large-payload', 'decode.unknown-class', 'effect.journal-reuse',
    'process.await-keyed-precheck', 'process.await-any-cancellation', 'process.await-all-dynamic',
  ];

  /** The latest wave whose pdo ids this suite runs; run.sh core-pdo gates the same wave. */
  public const WAVE = 4;

  public const MODES = ['Native', 'Emulated'];

  public function test_pdo_wave_3_matches_register_section_8(): void {
    self::assertCount(37, self::PDO_WAVE_3);
    self::assertSame([], ScenarioCatalogue::dueBy('pdo', 2), 'nothing is due on pdo before wave 3');
    self::assertEqualsCanonicalizing(self::PDO_WAVE_3, ScenarioCatalogue::dueBy('pdo', 3));
  }

  public function test_pdo_wave_4_matches_register_section_8(): void {
    self::assertCount(7, self::PDO_WAVE_4);
    self::assertEqualsCanonicalizing(self::PDO_WAVE_4, ScenarioCatalogue::firstDueAt('pdo', 4));
    self::assertEqualsCanonicalizing([...self::PDO_WAVE_3, ...self::PDO_WAVE_4], ScenarioCatalogue::dueBy('pdo', self::WAVE));
    self::assertNotContains('workflow.fact-ignition-once', ScenarioCatalogue::dueBy('pdo', self::WAVE), 'pdo: -');
    self::assertNotContains('wakeup.post-commit', ScenarioCatalogue::dueBy('pdo', self::WAVE), 'sf only');
  }

  /** @return array<string, array{string}> */
  public static function modes(): array {
    return ['native' => ['Native'], 'emulated' => ['Emulated']];
  }

  #[DataProvider('modes')]
  public function test_every_id_due_on_pdo_runs_the_shared_scenario_in_this_prepare_mode(string $mode): void {
    $due = ScenarioCatalogue::dueBy('pdo', self::WAVE);
    $classes = self::hostClasses($mode);
    $implemented = ScenarioId::implementedBy($classes);

    self::assertSame([], array_values(array_diff($due, array_keys($implemented))), "due on pdo but no $mode scenario method");

    foreach ($classes as $class) {
      $ref = new \ReflectionClass($class);
      $groups = array_map(static fn ($a) => $a->newInstance()->name(), $ref->getAttributes(Group::class));
      self::assertContains('pdo', $groups, "$class carries the host group");
      self::assertContains('pdo-' . strtolower($mode), $groups, "$class carries its prepare-mode group");

      foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
        $id = ScenarioId::of($m);
        if ($id !== null && in_array($id, $due, true)) {
          self::assertNotSame($class, $m->getDeclaringClass()->getName(), "$id is due on pdo by wave " . self::WAVE . " and must not be overridden by $class");
        }
      }
    }
  }

  public function test_the_fixture_implements_every_seam_the_pdo_ids_need(): void {
    $interfaces = class_implements(PdoHostFixture::class);
    self::assertContains(ProcessHost::class, $interfaces);
    self::assertContains(FreshProcesses::class, $interfaces);
    self::assertContains(EffectHost::class, $interfaces, 'effect.journal-reuse (CR-W4C4-2)');
    self::assertContains(ProcessDecodeFaults::class, $interfaces, 'decode.unknown-class (CR-W4C4-3)');
  }

  /**
   * The host classes of one prepare mode, one per abstract case due on pdo
   * by self::WAVE: `{Mode}\Pdo{Mode}{Case}Test`.
   *
   * @return list<class-string>
   */
  public static function hostClasses(string $mode): array {
    $classes = [];
    foreach (ScenarioCatalogue::casesFor('pdo', self::WAVE) as $case) {
      $short = (new \ReflectionClass($case))->getShortName();
      $class = __NAMESPACE__ . "\\$mode\\Pdo{$mode}{$short}Test";
      self::assertTrue(class_exists($class), "missing $class");
      self::assertTrue(is_subclass_of($class, $case), "$class extends $case");
      $classes[] = $class;
    }
    return $classes;
  }
}
