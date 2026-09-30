<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Conformance\ScenarioCatalogue;
use TangibleDDD\Conformance\ScenarioId;

/**
 * Pins the catalogue to the exact per-wave id lists of register section 8,
 * and proves every id due on mem by wave 1 has a scenario method on a mem
 * host class. Runs under `--group mem` too, so the acceptance command
 * fails when a due id has no scenario.
 */
#[Group('mem')]
#[Group('catalogue')]
final class CatalogueTest extends TestCase {

  private const MEM_WAVE_1 = [
    'cmd.commit-atomic', 'cmd.commit-failure', 'cmd.reaction-throws', 'cmd.no-boundary',
    'cmd.nested-rejected', 'cmd.guards-without-audit', 'cmd.return-value',
    'relay.crash-after-submit', 'relay.lease-fencing', 'relay.invalid-acceptance',
    'relay.pause-holders', 'relay.replay-keeps-identity',
    'delivery.double-delivery', 'delivery.subscriber-isolation', 'delivery.phase-order',
    'delivery.delayed-once', 'worker.no-leak',
  ];

  public function test_the_catalogue_has_the_44_register_ids(): void {
    self::assertCount(44, ScenarioCatalogue::WAVES);
  }

  public function test_mem_wave_1_matches_register_section_8(): void {
    self::assertEqualsCanonicalizing(self::MEM_WAVE_1, ScenarioCatalogue::firstDueAt('mem', 1));
    self::assertNotContains('lock.acquire-error', ScenarioCatalogue::dueBy('mem', 1), 'moved to wave 3');
  }

  public function test_later_wave_lists_match_register_section_8(): void {
    self::assertSame(['audit.sink-fails'], ScenarioCatalogue::firstDueAt('mem', 2));
    self::assertCount(13, ScenarioCatalogue::firstDueAt('mem', 3));
    self::assertCount(12, ScenarioCatalogue::firstDueAt('wp', 2));
    self::assertCount(15, ScenarioCatalogue::firstDueAt('sf', 2));
    self::assertSame([], ScenarioCatalogue::firstDueAt('pdo', 2));
    self::assertCount(37, ScenarioCatalogue::firstDueAt('pdo', 3));
    self::assertCount(24, ScenarioCatalogue::firstDueAt('wp', 3));
    self::assertCount(23, ScenarioCatalogue::firstDueAt('sf', 3));
  }

  public function test_every_id_due_on_mem_by_wave_1_has_a_mem_scenario(): void {
    $implemented = ScenarioId::implementedBy(self::memHostClasses());

    $missing = array_values(array_diff(ScenarioCatalogue::dueBy('mem', 1), array_keys($implemented)));
    self::assertSame([], $missing, 'Due on mem by wave 1 but no scenario method carries the id');

    foreach (array_keys($implemented) as $id) {
      self::assertTrue(ScenarioCatalogue::isKnown($id), "Scenario group '$id' is not a register id");
    }
  }

  /** @return list<class-string> */
  private static function memHostClasses(): array {
    $classes = [];
    foreach (glob(__DIR__ . '/Mem/*Test.php') ?: [] as $file) {
      $classes[] = 'TangibleDDD\\Conformance\\Tests\\Mem\\' . basename($file, '.php');
    }
    return $classes;
  }
}
