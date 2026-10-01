<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Conformance\ScenarioCatalogue;
use TangibleDDD\Conformance\ScenarioId;

/**
 * Pins the sf id lists of register section 8 (waves 2, 3 and 4) and proves
 * each id due by wave 4 has a scenario method on an sf host class that runs
 * the shared scenario unchanged: an sf class that redeclares a due id (to
 * skip it) fails here.
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

  /** Register section 8, wave 3, sf (adds): 23 ids. */
  private const SF_WAVE_3 = [
    'relay.fresh-process-pickup', 'relay.crash-after-commit', 'relay.replay-keeps-identity',
    'relay.replay-keeps-identity.process', 'delivery.double-delivery.process',
    'delivery.subscriber-isolation.process', 'delivery.phase-order.process', 'delivery.delayed-once',
    'lock.contention', 'lock.acquire-error', 'lock.reentrant-balance', 'lock.namespace',
    'process.ignition-race', 'process.manual-start-in-drain', 'process.await-all-concurrent',
    'process.timeout-vs-event', 'process.await-before-dispatch', 'process.intent-survives-queue-failure',
    'process.stale-wakeup', 'process.crash-mid-step', 'process.fresh-process-resume',
    'process.start-from-web', 'audit.sink-fails',
  ];

  /**
   * Register section 8, wave 4, sf (adds): 6 ids, plus the three D3 ids of
   * CR-W4C4-1 (register edit requested by conformance-4): 9.
   */
  private const SF_WAVE_4 = [
    'process.alarm-long', 'process.await-keyed-precheck', 'process.await-any-cancellation',
    'process.await-all-dynamic', 'workflow.fact-ignition-once', 'codec.large-payload',
    'decode.unknown-class', 'effect.journal-reuse', 'wakeup.post-commit',
  ];

  public const HOST_CLASSES = [
    SfCommandScenariosTest::class,
    SfRelayScenariosTest::class,
    SfDeliveryScenariosTest::class,
    SfWorkerScenariosTest::class,
    SfProcessDeliveryScenariosTest::class,
    SfProcessScenariosTest::class,
    SfLockScenariosTest::class,
    SfConcurrencyScenariosTest::class,
    SfFreshProcessScenariosTest::class,
    SfWebStartScenariosTest::class,
    SfAlarmScenariosTest::class,
    SfAwaitScenariosTest::class,
    SfCodecScenariosTest::class,
    SfDecodeScenariosTest::class,
    SfEffectScenariosTest::class,
    SfWorkflowScenariosTest::class,
    SfPostCommitWakeupScenariosTest::class,
  ];

  /** The wave-3 host classes (the first ten): they must still cover exactly casesFor('sf', 3). */
  private const WAVE_3_HOST_CLASSES = 10;

  public function test_sf_waves_2_and_3_match_register_section_8(): void {
    self::assertSame([], ScenarioCatalogue::dueBy('sf', 1));
    self::assertEqualsCanonicalizing(self::SF_WAVE_2, ScenarioCatalogue::dueBy('sf', 2));
    self::assertCount(23, self::SF_WAVE_3);
    self::assertEqualsCanonicalizing([...self::SF_WAVE_2, ...self::SF_WAVE_3], ScenarioCatalogue::dueBy('sf', 3));
  }

  public function test_sf_wave_4_matches_register_section_8(): void {
    self::assertCount(9, self::SF_WAVE_4);
    self::assertEqualsCanonicalizing(self::SF_WAVE_4, ScenarioCatalogue::firstDueAt('sf', 4));
    self::assertEqualsCanonicalizing([...self::SF_WAVE_2, ...self::SF_WAVE_3, ...self::SF_WAVE_4], ScenarioCatalogue::dueBy('sf', 4));
  }

  public function test_every_case_due_on_sf_by_wave_3_has_an_sf_host_class(): void {
    $extended = [];
    foreach (array_slice(self::HOST_CLASSES, 0, self::WAVE_3_HOST_CLASSES) as $class) {
      $extended[] = (string) get_parent_class($class);
    }
    self::assertEqualsCanonicalizing(ScenarioCatalogue::casesFor('sf', 3), $extended);
  }

  public function test_every_case_due_on_sf_by_wave_4_has_an_sf_host_class(): void {
    $extended = [];
    foreach (self::HOST_CLASSES as $class) {
      $extended[] = (string) get_parent_class($class);
    }
    self::assertEqualsCanonicalizing(ScenarioCatalogue::casesFor('sf', 4), $extended);
  }

  public function test_every_id_due_on_sf_by_wave_3_runs_the_shared_scenario(): void {
    $this->assertRunsUnchanged([...self::SF_WAVE_2, ...self::SF_WAVE_3], 3);
  }

  public function test_every_id_due_on_sf_by_wave_4_runs_the_shared_scenario(): void {
    $this->assertRunsUnchanged([...self::SF_WAVE_2, ...self::SF_WAVE_3, ...self::SF_WAVE_4], 4);
  }

  /** @param list<string> $due */
  private function assertRunsUnchanged(array $due, int $wave): void {
    $implemented = ScenarioId::implementedBy(self::HOST_CLASSES);

    self::assertSame([], array_values(array_diff($due, array_keys($implemented))), 'due on sf but no scenario method');

    foreach (self::HOST_CLASSES as $class) {
      foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
        $id = ScenarioId::of($m);
        if ($id !== null && in_array($id, $due, true)) {
          self::assertNotSame($class, $m->getDeclaringClass()->getName(), "$id is due on sf by wave $wave and must not be overridden by $class");
        }
      }
    }
  }
}
