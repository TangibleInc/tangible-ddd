<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

require_once dirname(__DIR__) . '/Compose/Wave5Fixtures.php';

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Core\Tests\Pdo\Compose\ComposeConsumer;
use TangibleDDD\Core\Tests\Pdo\Compose\CreateCustomer;
use TangibleDDD\Core\Tests\Pdo\Compose\KeyedJobSaga;
use TangibleDDD\Core\Tests\Pdo\Compose\PauseConfig;
use TangibleDDD\Core\Tests\Pdo\Compose\RefundCharge;
use TangibleDDD\Core\Tests\Pdo\Compose\RefundChargeHandler;
use TangibleDDD\Core\Tests\Pdo\Compose\ReportJob;
use TangibleDDD\Core\Tests\Pdo\Compose\StartJob;
use TangibleDDD\Core\Tests\Pdo\Compose\Trace;
use TangibleDDD\Core\Tests\Pdo\PdoTestCase;
use TangibleDDD\Defaults\Pdo\DurableRuntime;
use TangibleDDD\Defaults\Pdo\MySqlNamedLock;
use TangibleDDD\Defaults\Pdo\PdoParkingJobStore;
use TangibleDDD\Defaults\Pdo\SchemaSql;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourTypes;
use TangibleDDD\Domain\ValueObjects\Behaviours\IBehaviourTypes;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Runtime\DrainReport;
use TangibleDDD\Runtime\Effects\EffectState;
use TangibleDDD\Runtime\Effects\NoEffectHandler;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Runtime\Scheduling\ICarriesFacts;

/**
 * Wave 5 through DurableRuntime::compose() on MySQL 8:
 *
 * - AW2: the jobs table carries facts (PdoParkingJobStore), so an answer
 *   whose process lock is taken is parked as a fact-carrying ResumeRetry
 *   job, its delivery completes, and the job resumes the process later;
 * - E1: a handler-class effect finds its IExternalEffectHandler in the
 *   runtime's handlers (array form keyed by the command class, or the
 *   naming convention in the container);
 * - E2: entry states on the journal and the operator layer `effect`;
 * - W2: one behaviour type registry, provided to HostDefaults, with the
 *   include-time registrations handed over.
 */
abstract class DurableRuntimeWave5Cases extends PdoTestCase {

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
      self::$schemaReady[$db] = true;
    }
    foreach (SchemaSql::TABLES as $logical) {
      $this->pdo()->exec('DELETE FROM `' . self::TP . $logical . '`');
    }

    $this->clock = new FrozenClock(self::utc('2026-10-01 12:00:00'));
    ConsumerRegistry::reset();
    HostDefaults::reset_for_tests();
    RuntimeReset::forget_for_tests();
    BaseBehaviourConfig::reset_types_for_tests();
    HostDefaults::provide(LoggerInterface::class, new NullLogger());
    Trace::reset();
    CreateCustomer::reset();
    KeyedJobSaga::$refs = [];
  }

  protected function tearDown(): void {
    ConsumerRegistry::reset();
    HostDefaults::reset_for_tests();
    RuntimeReset::forget_for_tests();
    BaseBehaviourConfig::reset_types_for_tests();
    parent::tearDown();
  }

  /** @param array<class-string, callable|object> $handlers */
  private function runtime(array $handlers = []): DurableRuntime {
    return DurableRuntime::compose($this->db, new ComposeConsumer(), $handlers, [], [KeyedJobSaga::class], $this->clock);
  }

  private static function assertClean(DrainReport $report): void {
    self::assertSame([], $report->errors, 'no stage failed');
  }

  // ── AW2 ─────────────────────────────────────────────────────────────────

  public function test_the_runtime_jobs_carry_facts(): void {
    $jobs = $this->runtime()->jobs();

    self::assertInstanceOf(PdoParkingJobStore::class, $jobs);
    self::assertInstanceOf(ICarriesFacts::class, $jobs);
  }

  public function test_an_answer_held_off_by_the_lock_is_parked_and_resumes_the_process_later(): void {
    $rt = $this->runtime();
    $rt->bus()->handle(new StartJob('a'));
    self::assertClean($rt->drain());
    $pid = (int) $this->db->fetch_one('SELECT id FROM pdocompose_ddd_processes WHERE process_class = ?', [KeyedJobSaga::class])['id'];

    $holder = new MySqlNamedLock($this->otherConnection());
    $held = $holder->acquire(new LockKey('pdocompose', '', $pid), 0.0);
    try {
      $rt->bus()->handle(new ReportJob(KeyedJobSaga::$refs['a']));
      self::assertClean($rt->drain());

      self::assertSame(['ordered:a'], Trace::$log, 'nothing ran unlocked');
      $parked = $this->db->fetch_one("SELECT j.idempotency_key, j.expected_status, f.fact FROM pdocompose_ddd_jobs j JOIN pdocompose_ddd_job_facts f ON f.idempotency_key = j.idempotency_key WHERE j.kind = 'resume_retry' AND j.process_id = ?", [$pid]);
      self::assertNotNull($parked, 'a ResumeRetry job carries the answer');
      self::assertSame('suspended', $parked['expected_status']);
      self::assertSame(KeyedJobSaga::$refs['a'], json_decode((string) $parked['fact'], true)['payload']['job_id'] ?? null);
      self::assertSame(0, (int) $this->db->fetch_one("SELECT COUNT(*) AS n FROM pdocompose_ddd_jobs WHERE kind = 'deliver'")['n'], 'the delivery completed');
      self::assertSame(0, (int) $this->db->fetch_one('SELECT COUNT(*) AS n FROM pdocompose_ddd_delivery_ledger WHERE delivered_at IS NULL')['n'], 'no attempt was spent');

      // Due after the wake backoff; still locked, so the job is retried, not dropped.
      $this->clock->advance('PT3S');
      $report = $rt->drain();
      self::assertSame([$parked['idempotency_key']], $report->wakes_retried);
      self::assertSame(['ordered:a'], Trace::$log);
    } finally {
      $holder->release($held);
    }

    $this->clock->advance('PT10S');
    self::assertClean($rt->drain());

    self::assertSame(['ordered:a', 'done:a'], Trace::$log, 'the parked answer resumed the process');
    self::assertSame('completed', $this->db->fetch_one('SELECT status FROM pdocompose_ddd_processes WHERE id = ?', [$pid])['status']);
    self::assertSame(0, (int) $this->db->fetch_one('SELECT COUNT(*) AS n FROM pdocompose_ddd_job_facts')['n'], 'the fact left with its job');
  }

  // ── E1, E2 ──────────────────────────────────────────────────────────────

  public function test_a_handler_class_effect_is_performed_and_recorded_by_its_handler(): void {
    $handler = new RefundChargeHandler();
    $rt = $this->runtime([RefundCharge::class => $handler]);

    $result = $rt->bus()->handle(new RefundCharge('ch_1'));
    $again = $rt->bus()->handle(new RefundCharge('ch_1'));

    self::assertSame('re_ch_1', $result->external_ref);
    self::assertSame('re_ch_1', $again->external_ref, 'the journaled result');
    self::assertSame(['ch_1'], $handler->performed);
    self::assertSame(['re_ch_1'], $handler->recorded, 'a Recorded entry is not recorded again');
    self::assertSame(EffectState::Recorded, $rt->journal()->find_entry('provider:refund:ch_1')?->state);
  }

  public function test_a_handler_class_effect_without_a_handler_is_refused_before_it_performs(): void {
    $rt = $this->runtime();

    $this->expectException(NoEffectHandler::class);
    try {
      $rt->bus()->handle(new RefundCharge('ch_2'));
    } finally {
      self::assertNull($rt->journal()->find_entry('provider:refund:ch_2'), 'nothing performed or journaled');
    }
  }

  public function test_an_unrecorded_effect_is_in_the_operator_layer_effect(): void {
    $rt = $this->runtime();
    CreateCustomer::$failRecord = 1;
    try {
      $rt->bus()->handle(new CreateCustomer(9));
    } catch (\RuntimeException) {
    }
    $this->clock->advance('PT6M');

    $items = $rt->operator_view()->list(Layer::Effect);

    self::assertSame(['provider:customer:9'], array_map(static fn ($i) => $i->key, $items));
    $rt->operator_view()->repair_item($items[0], 'invalidate');
    self::assertNull($rt->journal()->find('provider:customer:9'));
  }

  // ── W2 ──────────────────────────────────────────────────────────────────

  public function test_one_behaviour_type_registry_takes_the_include_time_registrations(): void {
    BaseBehaviourConfig::register_type('pdocompose_pause', PauseConfig::class); // before compose, at include time

    $rt = $this->runtime();

    $types = $rt->behaviour_types();
    self::assertInstanceOf(BehaviourTypes::class, $types);
    self::assertSame($types, HostDefaults::get(IBehaviourTypes::class));
    self::assertSame($types, $rt->container()->get(IBehaviourTypes::class));
    self::assertSame(PauseConfig::class, $types->find('pdocompose_pause'), 'handed over');
    self::assertSame(PauseConfig::class, BaseBehaviourConfig::class_for_type('pdocompose_pause'));
  }

  public function test_a_second_runtime_shares_the_registry_core_already_has(): void {
    $first = $this->runtime();
    BaseBehaviourConfig::register_type('pdocompose_pause', PauseConfig::class); // after compose: into the host registry

    $second = DurableRuntime::compose($this->otherConnection(), new ComposeConsumer(), [], [], [], $this->clock);

    self::assertSame($first->behaviour_types(), $second->behaviour_types());
    self::assertSame(PauseConfig::class, $second->behaviour_types()->find('pdocompose_pause'));
  }
}
