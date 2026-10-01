<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\ArrayProcessRepository;
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\IgnitedProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\Journal;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\TwoStepProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingCommand;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Lock\INamedLock;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\Process\IgnitionResult;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Process\LegacyProcessStore;
use TangibleDDD\Runtime\Process\ProcessStoreFailed;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Testing\InMemoryNamedLock;
use TangibleDDD\Testing\InMemoryProcessLock;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryWakeupScheduler;
use TangibleDDD\Testing\StaticConsumerIdentity;

require_once dirname(__DIR__) . '/Fixtures/Process/CoreProcesses.php';

/**
 * Register 3.8 / R3: LegacyProcessStore bridges a consumer's 0.6
 * IProcessRepository (cred). Ignition runs under a named lock (the hotfix
 * approach), saves are not version-fenced, and that is logged.
 */
final class LegacyProcessStoreTest extends TestCase {

  private const EVENT_ID = '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d11';

  private ArrayProcessRepository $repo;
  private InMemoryNamedLock $named;
  private RecordingLogger $logger;
  private LegacyProcessStore $store;

  protected function setUp(): void {
    HostDefaults::reset_for_tests();
    Correlation::reset();
    Journal::reset();
    RecordingCommand::$sent = [];
    $this->repo = new ArrayProcessRepository();
    $this->named = new InMemoryNamedLock();
    $this->logger = new RecordingLogger();
    $this->store = new LegacyProcessStore($this->repo, new StaticConsumerIdentity('acme'), $this->named, $this->logger);
  }

  protected function tearDown(): void {
    HostDefaults::reset_for_tests();
    Correlation::reset();
  }

  private function ignitable(int $order = 4): IgnitedProcess {
    $p = new IgnitedProcess($order);
    $p->mark_ignited_by(self::EVENT_ID);
    return $p;
  }

  public function test_it_is_a_process_store(): void {
    self::assertInstanceOf(IProcessStore::class, $this->store);
  }

  public function test_ignition_is_gated_by_has_ignition_under_the_named_lock(): void {
    self::assertSame(IgnitionResult::Inserted, $this->store->insert_ignited($this->ignitable(), IgnitedProcess::class, self::EVENT_ID));
    self::assertSame(IgnitionResult::AlreadyIgnited, $this->store->insert_ignited($this->ignitable(), IgnitedProcess::class, self::EVENT_ID));

    self::assertCount(1, $this->repo->rows);
    $name = 'ddd_ign_' . md5('acme|' . IgnitedProcess::class . '|' . self::EVENT_ID);
    self::assertSame([$name, $name], $this->named->acquired);
    self::assertSame(0, $this->named->held_count(), 'released in finally');
  }

  public function test_a_contended_ignition_lock_persists_nothing(): void {
    $this->named->hold_elsewhere('ddd_ign_' . md5('acme|' . IgnitedProcess::class . '|' . self::EVENT_ID));

    try {
      $this->store->insert_ignited($this->ignitable(), IgnitedProcess::class, self::EVENT_ID);
      self::fail('expected LockNotAcquired');
    } catch (LockNotAcquired) {
    }
    self::assertSame([], $this->repo->rows);
  }

  public function test_without_a_named_lock_ignition_fails_loudly(): void {
    $store = new LegacyProcessStore($this->repo, new StaticConsumerIdentity('acme'));

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(INamedLock::class);
    $store->insert_ignited($this->ignitable(), IgnitedProcess::class, self::EVENT_ID);
  }

  public function test_the_named_lock_resolves_from_host_defaults(): void {
    HostDefaults::provide(INamedLock::class, $this->named);
    $store = new LegacyProcessStore($this->repo, new StaticConsumerIdentity('acme'));

    self::assertSame(IgnitionResult::Inserted, $store->insert_ignited($this->ignitable(), IgnitedProcess::class, self::EVENT_ID));
  }

  public function test_insert_assigns_an_id_at_version_one(): void {
    $p = new TwoStepProcess();
    $id = $this->store->insert($p);

    self::assertSame($id, $p->get_id());
    self::assertSame(1, $this->store->version_of($id));
    self::assertInstanceOf(TwoStepProcess::class, $this->store->find($id));
  }

  public function test_an_insert_that_yields_no_id_throws_and_never_sets_id_zero(): void {
    $this->repo->breakNextSave = true;
    $p = new TwoStepProcess();

    try {
      $this->store->insert($p);
      self::fail('expected ProcessStoreFailed');
    } catch (ProcessStoreFailed) {
    }
    self::assertNull($p->get_id());
  }

  public function test_saves_are_unfenced_and_that_is_logged_once(): void {
    $p = new TwoStepProcess();
    $id = $this->store->insert($p);

    self::assertSame(2, $this->store->save($p, 1));
    self::assertSame(3, $this->store->save($p, 1), 'a stale expected version is NOT detected (no version column)');
    self::assertSame(4, $this->store->touch($id, 1));

    $warnings = array_values(array_filter($this->logger->records, static fn ($r) => $r['level'] === 'warning'));
    self::assertCount(1, $warnings);
    self::assertStringContainsString('without version fencing', $warnings[0]['message']);
    self::assertStringContainsString(ArrayProcessRepository::class, $warnings[0]['message']);
  }

  public function test_find_waiting_for_returns_ids_and_stranded_is_empty(): void {
    self::assertSame([], $this->store->find_waiting_for(OrderPlaced::class));
    self::assertSame([], $this->store->find_stranded(new \DateTimeImmutable()));
  }

  public function test_the_core_runner_runs_on_the_bridge(): void {
    $clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
    $boundary = new InMemoryTransactionBoundary();
    $wakeups = new InMemoryWakeupScheduler($boundary);
    $boundary->enlist($wakeups);
    $runner = new ProcessRunner(
      new AcmeConfig(), null, new InMemoryProcessLock(), $this->store, $wakeups, new SubscriptionRegistry(), $boundary, $clock,
    );

    $runner->ignite(IgnitedProcess::class, new OrderPlaced(4), self::EVENT_ID);
    $runner->ignite(IgnitedProcess::class, new OrderPlaced(4), self::EVENT_ID);
    $runner->start(new TwoStepProcess(1));

    self::assertSame(['open:4', 'reserve', 'ship'], Journal::$steps);
    self::assertCount(2, $this->repo->rows);
    self::assertSame('completed', $this->repo->find(1)->status());
  }
}
