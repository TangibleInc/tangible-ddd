<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

require_once dirname(__DIR__) . '/Compose/Wave4Fixtures.php';

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Application\Process\Repair\FailStrandedProcess;
use TangibleDDD\Application\Process\Repair\ProcessNotStranded;
use TangibleDDD\Application\Process\Repair\ResumeStrandedProcess;
use TangibleDDD\Core\Tests\Pdo\Compose\AlarmSaga;
use TangibleDDD\Core\Tests\Pdo\Compose\ComposeConsumer;
use TangibleDDD\Core\Tests\Pdo\Compose\CreateCustomer;
use TangibleDDD\Core\Tests\Pdo\Compose\FragileSaga;
use TangibleDDD\Core\Tests\Pdo\Compose\KeyedJobSaga;
use TangibleDDD\Core\Tests\Pdo\Compose\RepairCustomer;
use TangibleDDD\Core\Tests\Pdo\Compose\ReportJob;
use TangibleDDD\Core\Tests\Pdo\Compose\StartAlarm;
use TangibleDDD\Core\Tests\Pdo\Compose\StartFragile;
use TangibleDDD\Core\Tests\Pdo\Compose\StartJob;
use TangibleDDD\Core\Tests\Pdo\Compose\Trace;
use TangibleDDD\Core\Tests\Pdo\PdoTestCase;
use TangibleDDD\Defaults\Pdo\DurableRuntime;
use TangibleDDD\Defaults\Pdo\PdoBehaviourWorkflowRepository;
use TangibleDDD\Defaults\Pdo\PdoEffectJournal;
use TangibleDDD\Defaults\Pdo\PdoWorkflowIgnitionLedger;
use TangibleDDD\Defaults\Pdo\PdoWorkItemRepository;
use TangibleDDD\Defaults\Pdo\SchemaSql;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Runtime\DrainReport;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\RuntimeReset;

/**
 * Wave 4 through DurableRuntime::compose() on MySQL 8: D1 effects on the
 * bus (EffectMiddleware + PdoEffectJournal), D7 long alarms, D3 keyed
 * awaits on the route index, the D10 stores on the runtime's connection, and
 * the stranded-process repairs (WP8-10) dispatched on the runtime's bus.
 */
abstract class DurableRuntimeWave4Cases extends PdoTestCase {

  private const TP = 'pdocompose_';

  /** @var array<string, bool> per database */
  private static array $schemaReady = [];

  private FrozenClock $clock;

  protected function setUp(): void {
    parent::setUp();
    $db = self::databaseName();
    if (!isset(self::$schemaReady[$db])) {
      foreach (SchemaSql::statements(self::TP) as $statement) {
        $this->pdo()->exec($statement);
      }
      $this->pdo()->exec('CREATE TABLE IF NOT EXISTS pdocompose_orders (id INT NOT NULL PRIMARY KEY, status VARCHAR(32) NOT NULL, outcome VARCHAR(32) NULL) ENGINE=InnoDB');
      self::$schemaReady[$db] = true;
    }
    foreach (SchemaSql::TABLES as $logical) {
      $this->pdo()->exec('DELETE FROM `' . self::TP . $logical . '`');
    }

    $this->clock = new FrozenClock(self::utc('2026-10-01 12:00:00'));
    ConsumerRegistry::reset();
    HostDefaults::reset_for_tests();
    RuntimeReset::forget_for_tests();
    HostDefaults::provide(LoggerInterface::class, new NullLogger());
    Trace::reset();
    CreateCustomer::reset();
    KeyedJobSaga::$refs = [];
  }

  protected function tearDown(): void {
    ConsumerRegistry::reset();
    HostDefaults::reset_for_tests();
    RuntimeReset::forget_for_tests();
    parent::tearDown();
  }

  private function runtime(): DurableRuntime {
    return DurableRuntime::compose($this->db, new ComposeConsumer(), [], [], [AlarmSaga::class, KeyedJobSaga::class, FragileSaga::class], $this->clock);
  }

  private static function assertClean(DrainReport $report): void {
    self::assertSame([], $report->errors, 'no stage failed');
    self::assertSame([], $report->leaks, 'nothing leaked across a message boundary');
  }

  /** @return array<string, mixed> */
  private function processOf(string $class): array {
    $row = $this->db->fetch_one('SELECT * FROM pdocompose_ddd_processes WHERE process_class = ? ORDER BY id DESC LIMIT 1', [$class]);
    self::assertNotNull($row, "a $class row");
    return $row;
  }

  // ── D1 ──────────────────────────────────────────────────────────────────

  public function test_an_effect_performs_once_and_a_failed_record_reuses_the_journaled_result(): void {
    $rt = $this->runtime();
    self::assertInstanceOf(PdoEffectJournal::class, $rt->journal());

    CreateCustomer::$failRecord = 1;
    try {
      $rt->bus()->handle(new CreateCustomer(7));
      self::fail('record should fail once');
    } catch (\RuntimeException $e) {
      self::assertSame('record failed on purpose', $e->getMessage());
    }
    self::assertSame(1, CreateCustomer::$performed);
    self::assertSame(['customer' => 'cus_1'], $rt->journal()->find('provider:customer:7')?->data, 'the journal entry survives the rolled-back record()');

    $result = $rt->bus()->handle(new CreateCustomer(7));

    self::assertInstanceOf(EffectResult::class, $result, 'the bus returns the EffectResult (D11)');
    self::assertSame('cus_1', $result->external_ref);
    self::assertSame(1, CreateCustomer::$performed, 'perform is not called again');
    self::assertSame(['cus_1'], CreateCustomer::$recorded);
  }

  public function test_a_repair_that_invalidates_in_its_transaction_makes_the_effect_perform_again(): void {
    $rt = $this->runtime();
    $rt->bus()->handle(new CreateCustomer(7));

    try {
      $rt->bus()->handle(new RepairCustomer(7, fail: true));
    } catch (\RuntimeException) {
    }
    $rt->bus()->handle(new CreateCustomer(7));
    self::assertSame(1, CreateCustomer::$performed, 'a rolled-back repair keeps the entry');

    $rt->bus()->handle(new RepairCustomer(7));
    $rt->bus()->handle(new CreateCustomer(7));

    self::assertSame(2, CreateCustomer::$performed);
    self::assertSame(['cus_1', 'cus_1', 'cus_2'], CreateCustomer::$recorded);
  }

  public function test_the_journal_is_a_runtime_service_for_repair_commands(): void {
    $rt = $this->runtime();
    self::assertSame($rt->journal(), $rt->container()->get(IEffectJournal::class));
  }

  // ── D7 ──────────────────────────────────────────────────────────────────

  public function test_a_25_hour_alarm_fires_once_at_its_absolute_instant(): void {
    $rt = $this->runtime();
    $rt->bus()->handle(new StartAlarm(25 * 3600));
    self::assertClean($rt->drain());
    self::assertSame(['alarm-set'], Trace::$log);

    $saga = $this->processOf(AlarmSaga::class);
    self::assertSame('suspended', $saga['status']);
    $job = $this->db->fetch_one("SELECT due_at FROM pdocompose_ddd_jobs WHERE process_id = ? AND kind = 'timeout'", [$saga['id']]);
    self::assertSame('2026-10-02 13:00:00.000000', $job['due_at'], 'one absolute intent row, due 25 h later');

    $this->clock->advance('PT24H59M59S');
    self::assertClean($rt->drain());
    self::assertSame(['alarm-set'], Trace::$log, 'not a second early');

    $this->clock->advance('PT1S');
    $restarted = $this->runtime(); // a worker restart: nothing but the database survives
    self::assertClean($restarted->drain());
    self::assertSame(['alarm-set', 'alarm-fired'], Trace::$log);
    self::assertSame('completed', $this->processOf(AlarmSaga::class)['status']);

    $this->clock->advance('PT48H');
    self::assertClean($restarted->drain());
    self::assertSame(['alarm-set', 'alarm-fired'], Trace::$log, 'fires once');
  }

  // ── D3 ──────────────────────────────────────────────────────────────────

  public function test_a_keyed_fact_resumes_only_the_process_that_minted_its_key(): void {
    $rt = $this->runtime();
    $rt->bus()->handle(new StartJob('a'));
    $rt->bus()->handle(new StartJob('b'));
    self::assertClean($rt->drain());
    self::assertSame(['ordered:a', 'ordered:b'], Trace::$log);
    self::assertSame(2, (int) $this->db->fetch_one('SELECT COUNT(*) AS n FROM pdocompose_ddd_process_waits WHERE event_class = ?', [\TangibleDDD\Core\Tests\Pdo\Compose\JobDone::class])['n']);

    $rt->bus()->handle(new ReportJob(KeyedJobSaga::$refs['b']));
    self::assertClean($rt->drain());

    self::assertSame(['ordered:a', 'ordered:b', 'done:b'], Trace::$log);
    $rows = $this->db->fetch_all('SELECT status FROM pdocompose_ddd_processes WHERE process_class = ? ORDER BY id', [KeyedJobSaga::class]);
    self::assertSame(['suspended', 'completed'], array_column($rows, 'status'));

    $rt->bus()->handle(new ReportJob('unknown-ref'));
    self::assertClean($rt->drain());
    self::assertSame(['ordered:a', 'ordered:b', 'done:b'], Trace::$log, 'an unheard keyed fact resumes nobody');
  }

  // ── D10 ─────────────────────────────────────────────────────────────────

  public function test_the_workflow_stores_share_the_runtime_connection_and_tables(): void {
    $rt = $this->runtime();

    self::assertInstanceOf(PdoBehaviourWorkflowRepository::class, $rt->workflows());
    self::assertInstanceOf(PdoWorkItemRepository::class, $rt->work_items());
    self::assertInstanceOf(PdoWorkflowIgnitionLedger::class, $rt->ignitions());
    self::assertTrue($rt->ignitions()->claim('k1', 'kind'));
    self::assertSame(1, (int) $this->db->fetch_one('SELECT COUNT(*) AS n FROM pdocompose_ddd_workflow_ignitions')['n']);
  }

  // ── WP8-10 stranded repairs on the runtime's bus ────────────────────────

  /** A FragileSaga whose worker died in its step: `running`, no live intent, untouched for 20 minutes. */
  private function strandedFragile(DurableRuntime $rt): int {
    $rt->bus()->handle(new StartFragile());
    $row = $this->processOf(FragileSaga::class);
    $this->db->execute('DELETE FROM pdocompose_ddd_jobs WHERE process_id = ?', [$row['id']]);
    $this->db->execute("UPDATE pdocompose_ddd_processes SET status = 'running', updated_at = ? WHERE id = ?", ['2026-10-01 11:40:00', $row['id']]);
    return (int) $row['id'];
  }

  public function test_resume_stranded_on_the_bus_re_runs_the_step_in_the_next_drain(): void {
    $rt = $this->runtime();
    $id = $this->strandedFragile($rt);

    $rt->bus()->handle(new ResumeStrandedProcess('pdocompose', $id));
    self::assertSame([], Trace::$log, 'the repair never runs a step itself');
    self::assertClean($rt->drain());

    self::assertSame(['fragile-work'], Trace::$log);
    self::assertSame('completed', $this->processOf(FragileSaga::class)['status']);
  }

  public function test_fail_stranded_on_the_bus_fails_the_process(): void {
    $rt = $this->runtime();
    $id = $this->strandedFragile($rt);

    $rt->bus()->handle(new FailStrandedProcess('pdocompose', $id, 'worker lost'));

    $row = $this->processOf(FragileSaga::class);
    self::assertSame('failed', $row['status']);
    self::assertStringContainsString('worker lost', (string) $row['last_error']);
  }

  public function test_a_process_that_is_not_stranded_is_refused(): void {
    $rt = $this->runtime();
    $rt->bus()->handle(new StartFragile()); // scheduled, with a live Continue intent
    $id = (int) $this->processOf(FragileSaga::class)['id'];

    $this->expectException(ProcessNotStranded::class);
    $rt->bus()->handle(new ResumeStrandedProcess('pdocompose', $id));
  }
}
