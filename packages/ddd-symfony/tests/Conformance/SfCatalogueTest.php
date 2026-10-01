<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Conformance\CrossConsumerHost;
use TangibleDDD\Conformance\EffectStateHost;
use TangibleDDD\Conformance\ScenarioCatalogue;
use TangibleDDD\Conformance\ScenarioId;
use TangibleDDD\Conformance\WorkItemHost;
use TangibleDDD\Runtime\Scheduling\ICarriesFacts;
use TangibleDDD\Symfony\Persistence\DbalParkingScheduler;
use TangibleDDD\Symfony\Tests\Conformance\Support\SfWorkerPorts;

/**
 * Pins the sf id lists of register section 8 (waves 2 to 5) and proves
 * each id due by wave 5 has a scenario method on an sf host class that runs
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

  /** Register section 8, wave 5, sf (adds, CR-W5C5-1): AW2, AW1, E2, W4 and the cross-consumer id. */
  private const SF_WAVE_5 = [
    'lock.parked-answer', 'process.resume-contention-keeps-answer', 'process.resume-cause',
    'effect.performed-not-recorded', 'workflow.item-deterministic-id', 'delivery.cross-consumer-once',
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
    SfParkedAnswerScenariosTest::class,
    SfResumeCauseScenariosTest::class,
    SfEffectStateScenariosTest::class,
    SfWorkItemScenariosTest::class,
    SfCrossConsumerScenariosTest::class,
  ];

  /** The wave-3 host classes (the first ten): they must still cover exactly cases_for('sf', 3). */
  private const WAVE_3_HOST_CLASSES = 10;

  /** The wave-3 and wave-4 host classes (the first seventeen): they must still cover exactly cases_for('sf', 4). */
  private const WAVE_4_HOST_CLASSES = 17;

  public function test_sf_waves_2_and_3_match_register_section_8(): void {
    self::assertSame([], ScenarioCatalogue::due_by('sf', 1));
    self::assertEqualsCanonicalizing(self::SF_WAVE_2, ScenarioCatalogue::due_by('sf', 2));
    self::assertCount(23, self::SF_WAVE_3);
    self::assertEqualsCanonicalizing([...self::SF_WAVE_2, ...self::SF_WAVE_3], ScenarioCatalogue::due_by('sf', 3));
  }

  public function test_sf_wave_4_matches_register_section_8(): void {
    self::assertCount(9, self::SF_WAVE_4);
    self::assertEqualsCanonicalizing(self::SF_WAVE_4, ScenarioCatalogue::first_due_at('sf', 4));
    self::assertEqualsCanonicalizing([...self::SF_WAVE_2, ...self::SF_WAVE_3, ...self::SF_WAVE_4], ScenarioCatalogue::due_by('sf', 4));
  }

  public function test_every_case_due_on_sf_by_wave_3_has_an_sf_host_class(): void {
    $extended = [];
    foreach (array_slice(self::HOST_CLASSES, 0, self::WAVE_3_HOST_CLASSES) as $class) {
      $extended[] = (string) get_parent_class($class);
    }
    self::assertEqualsCanonicalizing(ScenarioCatalogue::cases_for('sf', 3), $extended);
  }

  public function test_every_case_due_on_sf_by_wave_4_has_an_sf_host_class(): void {
    $extended = [];
    foreach (array_slice(self::HOST_CLASSES, 0, self::WAVE_4_HOST_CLASSES) as $class) {
      $extended[] = (string) get_parent_class($class);
    }
    self::assertEqualsCanonicalizing(ScenarioCatalogue::cases_for('sf', 4), $extended);
  }

  public function test_sf_wave_5_matches_register_section_8(): void {
    self::assertCount(6, self::SF_WAVE_5);
    self::assertEqualsCanonicalizing(self::SF_WAVE_5, ScenarioCatalogue::first_due_at('sf', 5));
    self::assertEqualsCanonicalizing([...self::SF_WAVE_2, ...self::SF_WAVE_3, ...self::SF_WAVE_4, ...self::SF_WAVE_5], ScenarioCatalogue::due_by('sf', 5));
  }

  public function test_every_case_due_on_sf_by_wave_5_has_an_sf_host_class(): void {
    $extended = [];
    foreach (self::HOST_CLASSES as $class) {
      $extended[] = (string) get_parent_class($class);
    }
    self::assertEqualsCanonicalizing(ScenarioCatalogue::cases_for('sf', 5), $extended);
  }

  public function test_every_id_due_on_sf_by_wave_5_runs_the_shared_scenario(): void {
    $this->assertRunsUnchanged([...self::SF_WAVE_2, ...self::SF_WAVE_3, ...self::SF_WAVE_4, ...self::SF_WAVE_5], 5);
  }

  /** The seams of the wave-5 ids, and the parking scheduler lock.acquire-error branches on (CR-W5CC-7). */
  public function test_the_fixture_implements_the_wave_5_seams(): void {
    $interfaces = class_implements(SfHostFixture::class);
    self::assertContains(EffectStateHost::class, $interfaces, 'effect.performed-not-recorded (CR-W5C5-2)');
    self::assertContains(WorkItemHost::class, $interfaces, 'workflow.item-deterministic-id (CR-W5C5-3)');
    self::assertContains(CrossConsumerHost::class, $interfaces, 'delivery.cross-consumer-once (CR-W5C5-4)');
    $type = (new \ReflectionProperty(SfWorkerPorts::class, 'wakeups'))->getType();
    self::assertSame(DbalParkingScheduler::class, $type instanceof \ReflectionNamedType ? $type->getName() : null, 'the bundle\'s wakeup_scheduler');
    self::assertTrue(is_subclass_of(DbalParkingScheduler::class, ICarriesFacts::class));
  }

  public function test_every_id_due_on_sf_by_wave_3_runs_the_shared_scenario(): void {
    $this->assertRunsUnchanged([...self::SF_WAVE_2, ...self::SF_WAVE_3], 3);
  }

  public function test_every_id_due_on_sf_by_wave_4_runs_the_shared_scenario(): void {
    $this->assertRunsUnchanged([...self::SF_WAVE_2, ...self::SF_WAVE_3, ...self::SF_WAVE_4], 4);
  }

  /** @param list<string> $due */
  private function assertRunsUnchanged(array $due, int $wave): void {
    $implemented = ScenarioId::implemented_by(self::HOST_CLASSES);

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
