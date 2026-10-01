<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Conformance\ScenarioCatalogue;
use TangibleDDD\Conformance\ScenarioId;

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

  public function test_wp_wave_4_matches_register_section_8(): void {
    self::assertEqualsCanonicalizing(self::WP_WAVE_4, ScenarioCatalogue::firstDueAt('wp', 4));
    self::assertEqualsCanonicalizing([...self::WP_WAVE_2, ...self::WP_WAVE_3, ...self::WP_WAVE_4], ScenarioCatalogue::dueBy('wp', 4));
    foreach (['effect.journal-reuse', 'workflow.fact-ignition-once', 'wakeup.post-commit', 'process.await-keyed-precheck', 'process.await-any-cancellation', 'process.await-all-dynamic'] as $outOfScope) {
      self::assertNotContains($outOfScope, ScenarioCatalogue::dueBy('wp', 4), "$outOfScope is - on wp");
    }
  }

  public function test_every_id_due_on_wp_by_wave_4_has_a_wp_scenario_and_a_wp_class(): void {
    $implemented = ScenarioId::implementedBy(self::wpHostClasses());
    self::assertSame([], array_values(array_diff(ScenarioCatalogue::dueBy('wp', 4), array_keys($implemented))), 'due on wp by wave 4 but no wp scenario method carries the id');

    $extended = array_values(array_filter(array_map(static fn (string $c) => get_parent_class($c), self::wpHostClasses())));
    self::assertSame([], array_values(array_diff(ScenarioCatalogue::casesFor('wp', 4), $extended)));
  }

  public function test_wp_wave_2_matches_register_section_8(): void {
    self::assertEqualsCanonicalizing(self::WP_WAVE_2, ScenarioCatalogue::firstDueAt('wp', 2));
    self::assertEqualsCanonicalizing(self::WP_WAVE_2, ScenarioCatalogue::dueBy('wp', 2));
  }

  public function test_wp_wave_3_matches_register_section_8(): void {
    self::assertCount(24, self::WP_WAVE_3);
    self::assertEqualsCanonicalizing(self::WP_WAVE_3, ScenarioCatalogue::firstDueAt('wp', 3));
    self::assertEqualsCanonicalizing([...self::WP_WAVE_2, ...self::WP_WAVE_3], ScenarioCatalogue::dueBy('wp', 3));
    self::assertNotContains('lock.namespace', ScenarioCatalogue::dueBy('wp', 3), 'lock.namespace is - on wp (register 3.7)');
  }

  public function test_every_id_due_on_wp_by_wave_3_has_a_wp_scenario(): void {
    $implemented = ScenarioId::implementedBy(self::wpHostClasses());

    self::assertSame([], array_values(array_diff(ScenarioCatalogue::dueBy('wp', 3), array_keys($implemented))), 'due on wp by wave 3 but no wp scenario method carries the id');
    foreach (array_keys($implemented) as $id) {
      self::assertTrue(ScenarioCatalogue::isKnown($id), "scenario group '$id' is not a register id");
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
    self::assertSame([], array_values(array_diff(ScenarioCatalogue::casesFor('wp', 3), $extended)));
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
