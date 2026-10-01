<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Conformance\ScenarioCatalogue;
use TangibleDDD\Conformance\ScenarioId;
use TangibleDDD\Conformance\WorkItemHost;
use TangibleDDD\Runtime\Scheduling\ICarriesFacts;
use TangibleDDD\Tests\Integration\Conformance\Support\WpConformanceRuntime;
use TangibleDDD\WordPress\Adapter\WpdbParkingScheduler;

/**
 * Pins the wp host classes to register section 8: every scenario id due on
 * wp by wave 3 has a scenario method on a Wp*Conformance class, and every
 * scenario group they carry is a register id. (bin/check-due.php then
 * checks, from the JUnit log, that each due id actually PASSED.)
 */
#[Group('wp')]
#[Group('catalogue')]
final class WpCatalogueConformance extends TestCase {

  private const WP_WAVE_2 = [
    'cmd.commit-atomic', 'cmd.commit-failure', 'cmd.reaction-throws', 'cmd.no-boundary',
    'cmd.nested-rejected', 'cmd.guards-without-audit', 'cmd.return-value',
    'relay.invalid-acceptance', 'relay.replay-keeps-identity',
    'delivery.phase-order', 'delivery.delayed-once', 'audit.sink-fails',
  ];

  /** Register section 8, wave 3, wp (24 ids; `lock.namespace` is `-` on wp). */
  private const WP_WAVE_3 = [
    'relay.fresh-process-pickup', 'relay.crash-after-commit', 'relay.crash-after-submit',
    'relay.lease-fencing', 'relay.pause-holders', 'relay.replay-keeps-identity.process',
    'delivery.double-delivery', 'delivery.double-delivery.process',
    'delivery.subscriber-isolation', 'delivery.subscriber-isolation.process',
    'delivery.phase-order.process',
    'lock.contention', 'lock.acquire-error', 'lock.reentrant-balance',
    'process.ignition-race', 'process.manual-start-in-drain', 'process.await-all-concurrent',
    'process.timeout-vs-event', 'process.await-before-dispatch',
    'process.intent-survives-queue-failure', 'process.stale-wakeup',
    'process.crash-mid-step', 'process.fresh-process-resume',
    'worker.no-leak',
  ];

  /** Register section 8, wave 4, wp (3 ids; D1, D10 and the D3 ids are `-` on wp, O9 and CR-W4C4-1). */
  private const WP_WAVE_4 = ['process.alarm-long', 'codec.large-payload', 'decode.unknown-class'];

  /** Register section 8, wave 5, wp (2 ids, CR-W5C5-1; AW2 and E2 are `-` on wp, cross-consumer is sf only). */
  private const WP_WAVE_5 = ['process.resume-cause', 'workflow.item-deterministic-id'];

  public function test_wp_wave_5_matches_register_section_8(): void {
    self::assertEqualsCanonicalizing(self::WP_WAVE_5, ScenarioCatalogue::first_due_at('wp', 5));
    $due = ScenarioCatalogue::due_by('wp', 5);
    self::assertEqualsCanonicalizing([...self::WP_WAVE_2, ...self::WP_WAVE_3, ...self::WP_WAVE_4, ...self::WP_WAVE_5], $due);
    foreach (['lock.parked-answer', 'process.resume-contention-keeps-answer', 'effect.performed-not-recorded', 'delivery.cross-consumer-once'] as $outOfScope) {
      self::assertNotContains($outOfScope, $due, "$outOfScope is - on wp");
    }
  }

  public function test_every_id_due_on_wp_by_wave_5_has_a_wp_scenario_and_a_wp_class(): void {
    $implemented = ScenarioId::implemented_by(self::wpHostClasses());
    self::assertSame([], array_values(array_diff(ScenarioCatalogue::due_by('wp', 5), array_keys($implemented))), 'due on wp by wave 5 but no wp scenario method carries the id');

    $extended = array_values(array_filter(array_map(static fn (string $c) => get_parent_class($c), self::wpHostClasses())));
    self::assertSame([], array_values(array_diff(ScenarioCatalogue::cases_for('wp', 5), $extended)));
  }

  /** lock.acquire-error takes its parked branch: the wp fixture schedules on the v9 WpdbParkingScheduler. */
  public function test_the_fixture_wakeups_carry_facts(): void {
    self::assertTrue(is_subclass_of(WpdbParkingScheduler::class, ICarriesFacts::class));
    $type = (new \ReflectionProperty(WpConformanceRuntime::class, 'wakeups'))->getType();
    self::assertSame(WpdbParkingScheduler::class, $type instanceof \ReflectionNamedType ? $type->getName() : null);
    self::assertContains(WorkItemHost::class, class_implements(WpHostFixture::class), 'workflow.item-deterministic-id (CR-W5C5-3)');
  }

  public function test_wp_wave_4_matches_register_section_8(): void {
    self::assertEqualsCanonicalizing(self::WP_WAVE_4, ScenarioCatalogue::first_due_at('wp', 4));
    self::assertEqualsCanonicalizing([...self::WP_WAVE_2, ...self::WP_WAVE_3, ...self::WP_WAVE_4], ScenarioCatalogue::due_by('wp', 4));
    foreach (['effect.journal-reuse', 'workflow.fact-ignition-once', 'wakeup.post-commit', 'process.await-keyed-precheck', 'process.await-any-cancellation', 'process.await-all-dynamic'] as $outOfScope) {
      self::assertNotContains($outOfScope, ScenarioCatalogue::due_by('wp', 4), "$outOfScope is - on wp");
    }
  }

  public function test_every_id_due_on_wp_by_wave_4_has_a_wp_scenario_and_a_wp_class(): void {
    $implemented = ScenarioId::implemented_by(self::wpHostClasses());
    self::assertSame([], array_values(array_diff(ScenarioCatalogue::due_by('wp', 4), array_keys($implemented))), 'due on wp by wave 4 but no wp scenario method carries the id');

    $extended = array_values(array_filter(array_map(static fn (string $c) => get_parent_class($c), self::wpHostClasses())));
    self::assertSame([], array_values(array_diff(ScenarioCatalogue::cases_for('wp', 4), $extended)));
  }

  public function test_wp_wave_2_matches_register_section_8(): void {
    self::assertEqualsCanonicalizing(self::WP_WAVE_2, ScenarioCatalogue::first_due_at('wp', 2));
    self::assertEqualsCanonicalizing(self::WP_WAVE_2, ScenarioCatalogue::due_by('wp', 2));
  }

  public function test_wp_wave_3_matches_register_section_8(): void {
    self::assertCount(24, self::WP_WAVE_3);
    self::assertEqualsCanonicalizing(self::WP_WAVE_3, ScenarioCatalogue::first_due_at('wp', 3));
    self::assertEqualsCanonicalizing([...self::WP_WAVE_2, ...self::WP_WAVE_3], ScenarioCatalogue::due_by('wp', 3));
    self::assertNotContains('lock.namespace', ScenarioCatalogue::due_by('wp', 3), 'lock.namespace is - on wp (register 3.7)');
  }

  public function test_every_id_due_on_wp_by_wave_3_has_a_wp_scenario(): void {
    $implemented = ScenarioId::implemented_by(self::wpHostClasses());

    self::assertSame([], array_values(array_diff(ScenarioCatalogue::due_by('wp', 3), array_keys($implemented))), 'due on wp by wave 3 but no wp scenario method carries the id');
    foreach (array_keys($implemented) as $id) {
      self::assertTrue(ScenarioCatalogue::is_known($id), "scenario group '$id' is not a register id");
    }
  }

  /** Every abstract case that declares a wave-3 wp id is extended by a wp class. */
  public function test_every_case_listed_for_wp_wave_3_has_a_wp_class(): void {
    $extended = [];
    foreach (self::wpHostClasses() as $class) {
      $parent = get_parent_class($class);
      if ($parent !== false) {
        $extended[] = $parent;
      }
    }
    self::assertSame([], array_values(array_diff(ScenarioCatalogue::cases_for('wp', 3), $extended)));
  }

  /** @return list<class-string> */
  private static function wpHostClasses(): array {
    $classes = [];
    foreach (glob(__DIR__ . '/Wp*Conformance.php') ?: [] as $file) {
      $class = __NAMESPACE__ . '\\' . basename($file, '.php');
      if ($class !== self::class) {
        $classes[] = $class;
      }
    }
    return $classes;
  }
}
