<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Conformance\AuditSinkFaults;
use TangibleDDD\Conformance\Mem\MemHostFixture;
use TangibleDDD\Conformance\ProcessHost;
use TangibleDDD\Conformance\RecordsSignals;
use TangibleDDD\Conformance\RelayRace;
use TangibleDDD\Conformance\ScenarioCatalogue;
use TangibleDDD\Conformance\ScenarioId;
use TangibleDDD\Conformance\StatementErrors;

/**
 * Pins the catalogue to the exact per-wave id lists of register section 8,
 * proves every id due on mem by wave 3 has a scenario method on a mem host
 * class, and every id due on any host by wave 3 has an abstract scenario
 * method a host can extend. Runs under `--group mem` too, so the
 * acceptance command fails when a due id has no scenario.
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

  /** Register section 8, wave 3, conformance: the ids each host ADDS (exact lists). */
  private const WAVE_3 = [
    'mem' => [
      'relay.replay-keeps-identity.process', 'delivery.double-delivery.process', 'delivery.subscriber-isolation.process',
      'delivery.phase-order.process', 'lock.contention', 'lock.acquire-error', 'lock.reentrant-balance',
      'process.ignition-race', 'process.manual-start-in-drain', 'process.timeout-vs-event',
      'process.await-before-dispatch', 'process.intent-survives-queue-failure', 'process.stale-wakeup',
    ],
    'pdo' => [
      'cmd.commit-atomic', 'cmd.commit-failure', 'cmd.reaction-throws', 'cmd.no-boundary', 'cmd.nested-rejected',
      'cmd.guards-without-audit', 'cmd.return-value', 'relay.fresh-process-pickup', 'relay.crash-after-commit',
      'relay.crash-after-submit', 'relay.lease-fencing', 'relay.invalid-acceptance', 'relay.pause-holders',
      'relay.replay-keeps-identity', 'relay.replay-keeps-identity.process', 'delivery.double-delivery',
      'delivery.double-delivery.process', 'delivery.subscriber-isolation', 'delivery.subscriber-isolation.process',
      'delivery.phase-order', 'delivery.phase-order.process', 'delivery.delayed-once', 'lock.contention',
      'lock.acquire-error', 'lock.reentrant-balance', 'lock.namespace', 'process.ignition-race',
      'process.manual-start-in-drain', 'process.await-all-concurrent', 'process.timeout-vs-event',
      'process.await-before-dispatch', 'process.intent-survives-queue-failure', 'process.stale-wakeup',
      'process.crash-mid-step', 'process.fresh-process-resume', 'worker.no-leak', 'audit.sink-fails',
    ],
    'wp' => [
      'relay.fresh-process-pickup', 'relay.crash-after-commit', 'relay.crash-after-submit', 'relay.lease-fencing',
      'relay.pause-holders', 'relay.replay-keeps-identity.process', 'delivery.double-delivery',
      'delivery.double-delivery.process', 'delivery.subscriber-isolation', 'delivery.subscriber-isolation.process',
      'delivery.phase-order.process', 'lock.contention', 'lock.acquire-error', 'lock.reentrant-balance',
      'process.ignition-race', 'process.manual-start-in-drain', 'process.await-all-concurrent',
      'process.timeout-vs-event', 'process.await-before-dispatch', 'process.intent-survives-queue-failure',
      'process.stale-wakeup', 'process.crash-mid-step', 'process.fresh-process-resume', 'worker.no-leak',
    ],
    'sf' => [
      'relay.fresh-process-pickup', 'relay.crash-after-commit', 'relay.replay-keeps-identity',
      'relay.replay-keeps-identity.process', 'delivery.double-delivery.process', 'delivery.subscriber-isolation.process',
      'delivery.phase-order.process', 'delivery.delayed-once', 'lock.contention', 'lock.acquire-error',
      'lock.reentrant-balance', 'lock.namespace', 'process.ignition-race', 'process.manual-start-in-drain',
      'process.await-all-concurrent', 'process.timeout-vs-event', 'process.await-before-dispatch',
      'process.intent-survives-queue-failure', 'process.stale-wakeup', 'process.crash-mid-step',
      'process.fresh-process-resume', 'process.start-from-web', 'audit.sink-fails',
    ],
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
    self::assertCount(12, ScenarioCatalogue::firstDueAt('wp', 2));
    self::assertCount(15, ScenarioCatalogue::firstDueAt('sf', 2));
    self::assertSame([], ScenarioCatalogue::firstDueAt('pdo', 2));
  }

  public function test_wave_3_lists_match_register_section_8_exactly(): void {
    $counts = ['mem' => 13, 'pdo' => 37, 'wp' => 24, 'sf' => 23];
    foreach (self::WAVE_3 as $host => $ids) {
      self::assertCount($counts[$host], $ids, "register section 8 lists {$counts[$host]} wave-3 $host ids");
      self::assertEqualsCanonicalizing($ids, ScenarioCatalogue::firstDueAt($host, 3), "$host wave 3");
    }
  }

  public function test_every_id_due_on_mem_by_wave_3_has_a_mem_scenario(): void {
    $implemented = ScenarioId::implementedBy(self::memHostClasses());

    self::assertEqualsCanonicalizing([...self::MEM_WAVE_1, 'audit.sink-fails', ...self::WAVE_3['mem']], ScenarioCatalogue::dueBy('mem', 3), 'the 31 mem ids of waves 1-3');
    $missing = array_values(array_diff(ScenarioCatalogue::dueBy('mem', 3), array_keys($implemented)));
    self::assertSame([], $missing, 'Due on mem by wave 3 but no scenario method carries the id');

    foreach (array_keys($implemented) as $id) {
      self::assertTrue(ScenarioCatalogue::isKnown($id), "Scenario group '$id' is not a register id");
      self::assertNotNull(ScenarioCatalogue::WAVES[$id][0], "'$id' is '-' on mem and must not run on a mem host class");
    }
  }

  public function test_every_id_due_on_any_host_by_wave_3_has_an_abstract_scenario(): void {
    $byCase = self::scenarioMethodsOfAbstractCases();

    foreach (ScenarioCatalogue::HOSTS as $host) {
      $missing = array_values(array_diff(ScenarioCatalogue::dueBy($host, 3), array_keys($byCase)));
      self::assertSame([], $missing, "Due on $host by wave 3 but no abstract scenario case carries the id");
    }
  }

  public function test_the_catalogue_names_the_scenario_case_of_every_implemented_id(): void {
    $byCase = self::scenarioMethodsOfAbstractCases();

    foreach ($byCase as $id => $class) {
      self::assertSame($class, ScenarioCatalogue::scenarioCase($id), "ScenarioCatalogue::CASES['$id']");
    }
    foreach (ScenarioCatalogue::CASES as $id => $class) {
      self::assertTrue(ScenarioCatalogue::isKnown($id), "CASES key '$id' is a register id");
      self::assertArrayHasKey($id, $byCase, "CASES['$id'] names a class that implements it");
    }
  }

  public function test_the_mem_host_provides_the_optional_seams_its_ids_need(): void {
    // Without them the scenarios are skipped (CR-CC-1, CR-W3CP-1); mem must not skip.
    self::assertTrue(is_a(MemHostFixture::class, AuditSinkFaults::class, true), 'MemHostFixture implements AuditSinkFaults');
    self::assertTrue(is_a(MemHostFixture::class, RecordsSignals::class, true), 'MemHostFixture implements RecordsSignals');
    self::assertTrue(is_a(MemHostFixture::class, ProcessHost::class, true), 'MemHostFixture implements ProcessHost');
    self::assertTrue(is_a(MemHostFixture::class, RelayRace::class, true), 'MemHostFixture implements RelayRace (CR sf-3 assertion)');
    self::assertTrue(is_a(MemHostFixture::class, StatementErrors::class, true), 'MemHostFixture implements StatementErrors (CR sf-7 case)');
  }

  /** @return list<class-string> */
  private static function memHostClasses(): array {
    $classes = [];
    foreach (glob(__DIR__ . '/Mem/*Test.php') ?: [] as $file) {
      $classes[] = 'TangibleDDD\\Conformance\\Tests\\Mem\\' . basename($file, '.php');
    }
    return $classes;
  }

  /** @return array<string, class-string> scenario id => the abstract case in src/Scenarios declaring it */
  private static function scenarioMethodsOfAbstractCases(): array {
    $out = [];
    foreach (glob(dirname(__DIR__) . '/src/Scenarios/*.php') ?: [] as $file) {
      $ref = new \ReflectionClass('TangibleDDD\\Conformance\\Scenarios\\' . basename($file, '.php'));
      self::assertTrue($ref->isAbstract(), $ref->getShortName() . ' is an abstract scenario case');
      foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
        $id = ScenarioId::of($m);
        if ($id !== null && $m->getDeclaringClass()->getName() === $ref->getName()) {
          self::assertArrayNotHasKey($id, $out, "'$id' is declared by one scenario case only");
          self::assertSame(ScenarioId::methodName($id), $m->name, "'$id' method name");
          $out[$id] = $ref->getName();
        }
      }
    }
    ksort($out);
    return $out;
  }
}
