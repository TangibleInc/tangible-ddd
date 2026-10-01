<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Conformance\ScenarioCatalogue;
use TangibleDDD\Conformance\ScenarioId;

/**
 * Pins the wp host classes to register section 8: every scenario id due on
 * wp by wave 2 has a scenario method on a Wp*Conformance class, and every
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

  public function test_wp_wave_2_matches_register_section_8(): void {
    self::assertEqualsCanonicalizing(self::WP_WAVE_2, ScenarioCatalogue::firstDueAt('wp', 2));
    self::assertEqualsCanonicalizing(self::WP_WAVE_2, ScenarioCatalogue::dueBy('wp', 2));
  }

  public function test_every_id_due_on_wp_by_wave_2_has_a_wp_scenario(): void {
    $implemented = ScenarioId::implementedBy(self::wpHostClasses());

    self::assertSame([], array_values(array_diff(ScenarioCatalogue::dueBy('wp', 2), array_keys($implemented))), 'due on wp by wave 2 but no wp scenario method carries the id');
    foreach (array_keys($implemented) as $id) {
      self::assertTrue(ScenarioCatalogue::isKnown($id), "scenario group '$id' is not a register id");
    }
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
