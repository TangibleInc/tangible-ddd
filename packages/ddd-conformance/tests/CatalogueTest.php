<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Conformance\AuditSinkFaults;
use TangibleDDD\Conformance\EffectHost;
use TangibleDDD\Conformance\EffectStateHost;
use TangibleDDD\Conformance\Mem\MemHostFixture;
use TangibleDDD\Conformance\ProcessDecodeFaults;
use TangibleDDD\Conformance\ProcessHost;
use TangibleDDD\Conformance\WorkflowHost;
use TangibleDDD\Conformance\WorkItemHost;
use TangibleDDD\Conformance\RecordsSignals;
use TangibleDDD\Conformance\RelayRace;
use TangibleDDD\Conformance\ScenarioCatalogue;
use TangibleDDD\Conformance\ScenarioId;
use TangibleDDD\Conformance\StatementErrors;

/**
 * Pins the catalogue to the exact per-wave id lists of register section 8
 * (plus the three D3 ids of CR-W4C4-1 and the wave-5 ids of CR-W5C5-1),
 * proves every id due on mem by wave 5 has a scenario method on a mem host
 * class, and every id due on any host by wave 5 has an abstract scenario
 * method a host can extend. Runs under
 * `--group mem` too, so the acceptance command fails when a due id has no
 * scenario.
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

  /**
   * Register section 8, wave 4, conformance: the ids each host ADDS (exact
   * lists), plus the three D3 ids of CR-W4C4-1 (TXP process-kernel), which
   * are mem 4, pdo 4, wp -, sf 4.
   */
  private const WAVE_4 = [
    'mem' => [
      'process.alarm-long', 'workflow.fact-ignition-once', 'codec.large-payload', 'decode.unknown-class',
      'effect.journal-reuse', ...self::D3_IDS,
    ],
    'pdo' => ['process.alarm-long', 'codec.large-payload', 'decode.unknown-class', 'effect.journal-reuse', ...self::D3_IDS],
    'wp' => ['process.alarm-long', 'codec.large-payload', 'decode.unknown-class'],
    'sf' => [
      'process.alarm-long', 'workflow.fact-ignition-once', 'codec.large-payload', 'decode.unknown-class',
      'effect.journal-reuse', 'wakeup.post-commit', ...self::D3_IDS,
    ],
  ];

  /** CR-W4C4-1: D3 scenarios TXP's process-kernel needs, added to the catalogue in wave 4. */
  private const D3_IDS = ['process.await-keyed-precheck', 'process.await-any-cancellation', 'process.await-all-dynamic'];

  /**
   * Wave 5 (CR-W5C5-1, docs/extraction/wave5-conformance-5-change-requests.md):
   * id => [mem, pdo, wp, sf]. wp is `-` where the wp adapters do not
   * support the behaviour (no fact column, no effect journal).
   */
  private const WAVE_5 = [
    'lock.parked-answer'                     => [5, 5, null, 5],
    'process.resume-contention-keeps-answer' => [5, 5, null, 5],
    'process.resume-cause'                   => [5, 5, 5, 5],
    'effect.performed-not-recorded'          => [5, 5, null, 5],
    'workflow.item-deterministic-id'         => [5, 5, 5, 5],
    'delivery.cross-consumer-once'           => [null, null, null, 5],
  ];

  public function test_the_catalogue_has_the_44_register_ids_the_3_d3_ids_and_the_wave_5_ids(): void {
    self::assertCount(44 + count(self::D3_IDS) + count(self::WAVE_5), ScenarioCatalogue::WAVES);
    foreach (self::D3_IDS as $id) {
      self::assertSame([4, 4, null, 4], ScenarioCatalogue::WAVES[$id], "$id: mem 4, pdo 4, wp -, sf 4 (CR-W4C4-1)");
    }
    foreach (self::WAVE_5 as $id => $waves) {
      self::assertSame($waves, ScenarioCatalogue::WAVES[$id] ?? null, "$id (CR-W5C5-1)");
    }
  }

  public function test_wave_4_lists_match_register_section_8_exactly(): void {
    $counts = ['mem' => 8, 'pdo' => 7, 'wp' => 3, 'sf' => 9];
    foreach (self::WAVE_4 as $host => $ids) {
      self::assertCount($counts[$host], $ids);
      self::assertEqualsCanonicalizing($ids, ScenarioCatalogue::first_due_at($host, 4), "$host wave 4");
    }
  }

  public function test_wave_5_lists_match_the_wave_5_cells_exactly(): void {
    foreach (ScenarioCatalogue::HOSTS as $col => $host) {
      $ids = array_keys(array_filter(self::WAVE_5, static fn (array $w) => $w[$col] === 5));
      self::assertEqualsCanonicalizing($ids, ScenarioCatalogue::first_due_at($host, 5), "$host wave 5");
    }
    foreach (ScenarioCatalogue::HOSTS as $host) {
      self::assertSame([], ScenarioCatalogue::first_due_at($host, 6), 'nothing is due after wave 5');
    }
  }

  public function test_every_id_has_a_scenario_case_by_wave_4(): void {
    foreach (array_keys(ScenarioCatalogue::WAVES) as $id) {
      self::assertNotNull(ScenarioCatalogue::case_of($id), "'$id' names its abstract scenario case");
    }
  }

  public function test_mem_wave_1_matches_register_section_8(): void {
    self::assertEqualsCanonicalizing(self::MEM_WAVE_1, ScenarioCatalogue::first_due_at('mem', 1));
    self::assertNotContains('lock.acquire-error', ScenarioCatalogue::due_by('mem', 1), 'moved to wave 3');
  }

  public function test_later_wave_lists_match_register_section_8(): void {
    self::assertSame(['audit.sink-fails'], ScenarioCatalogue::first_due_at('mem', 2));
    self::assertCount(12, ScenarioCatalogue::first_due_at('wp', 2));
    self::assertCount(15, ScenarioCatalogue::first_due_at('sf', 2));
    self::assertSame([], ScenarioCatalogue::first_due_at('pdo', 2));
  }

  public function test_wave_3_lists_match_register_section_8_exactly(): void {
    $counts = ['mem' => 13, 'pdo' => 37, 'wp' => 24, 'sf' => 23];
    foreach (self::WAVE_3 as $host => $ids) {
      self::assertCount($counts[$host], $ids, "register section 8 lists {$counts[$host]} wave-3 $host ids");
      self::assertEqualsCanonicalizing($ids, ScenarioCatalogue::first_due_at($host, 3), "$host wave 3");
    }
  }

  public function test_every_id_due_on_mem_by_wave_5_has_a_mem_scenario(): void {
    $implemented = ScenarioId::implemented_by(self::memHostClasses());

    self::assertEqualsCanonicalizing([...self::MEM_WAVE_1, 'audit.sink-fails', ...self::WAVE_3['mem']], ScenarioCatalogue::due_by('mem', 3), 'the 31 mem ids of waves 1-3');
    self::assertEqualsCanonicalizing([...ScenarioCatalogue::due_by('mem', 3), ...self::WAVE_4['mem']], ScenarioCatalogue::due_by('mem', 4), 'the 39 mem ids of waves 1-4');
    $missing = array_values(array_diff(ScenarioCatalogue::due_by('mem', 5), array_keys($implemented)));
    self::assertSame([], $missing, 'Due on mem by wave 5 but no scenario method carries the id');

    foreach (array_keys($implemented) as $id) {
      self::assertTrue(ScenarioCatalogue::is_known($id), "Scenario group '$id' is not a register id");
      self::assertNotNull(ScenarioCatalogue::WAVES[$id][0], "'$id' is '-' on mem and must not run on a mem host class");
    }
  }

  public function test_every_id_due_on_any_host_by_wave_5_has_an_abstract_scenario(): void {
    $byCase = self::scenarioMethodsOfAbstractCases();

    foreach (ScenarioCatalogue::HOSTS as $host) {
      $missing = array_values(array_diff(ScenarioCatalogue::due_by($host, 5), array_keys($byCase)));
      self::assertSame([], $missing, "Due on $host by wave 5 but no abstract scenario case carries the id");
    }
  }

  /** @return array<string, array{int}> */
  public static function laterWaves(): array {
    return ['wave 4' => [4], 'wave 5' => [5]];
  }

  #[DataProvider('laterWaves')]
  public function test_later_wave_ids_live_in_new_cases_so_earlier_host_classes_run_unchanged(int $wave): void {
    $earlierCases = [];
    foreach (ScenarioCatalogue::HOSTS as $host) {
      $earlierCases = [...$earlierCases, ...ScenarioCatalogue::cases_for($host, $wave - 1)];
    }
    self::assertNotEmpty($earlierCases);
    foreach (ScenarioCatalogue::HOSTS as $host) {
      foreach (ScenarioCatalogue::first_due_at($host, $wave) as $id) {
        self::assertNotContains(ScenarioCatalogue::case_of($id), $earlierCases, "'$id' is not added to a case a wave-" . ($wave - 1) . ' host already extends');
      }
    }
  }

  public function test_the_catalogue_names_the_scenario_case_of_every_implemented_id(): void {
    $byCase = self::scenarioMethodsOfAbstractCases();

    foreach ($byCase as $id => $class) {
      self::assertSame($class, ScenarioCatalogue::case_of($id), "ScenarioCatalogue::CASES['$id']");
    }
    foreach (ScenarioCatalogue::CASES as $id => $class) {
      self::assertTrue(ScenarioCatalogue::is_known($id), "CASES key '$id' is a register id");
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
    // wave 4 (CR-W4C4-2..4)
    self::assertTrue(is_a(MemHostFixture::class, EffectHost::class, true), 'MemHostFixture implements EffectHost (effect.journal-reuse)');
    self::assertTrue(is_a(MemHostFixture::class, WorkflowHost::class, true), 'MemHostFixture implements WorkflowHost (workflow.fact-ignition-once)');
    self::assertTrue(is_a(MemHostFixture::class, ProcessDecodeFaults::class, true), 'MemHostFixture implements ProcessDecodeFaults (decode.unknown-class)');
    // wave 5 (CR-W5C5-2..)
    self::assertTrue(is_a(MemHostFixture::class, EffectStateHost::class, true), 'MemHostFixture implements EffectStateHost (effect.performed-not-recorded)');
    self::assertTrue(is_a(MemHostFixture::class, WorkItemHost::class, true), 'MemHostFixture implements WorkItemHost (workflow.item-deterministic-id)');
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
          self::assertSame(ScenarioId::method_name($id), $m->name, "'$id' method name");
          $out[$id] = $ref->getName();
        }
      }
    }
    ksort($out);
    return $out;
  }
}
