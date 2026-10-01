<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Process;

use Doctrine\DBAL\Connection;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Symfony\Lock\PostgresAdvisoryProcessLock;
use TangibleDDD\Symfony\Messenger\ProcessWakeupHandler;
use TangibleDDD\Symfony\Messenger\ProcessWakeupMessage;
use TangibleDDD\Symfony\Persistence\DbalProcessStore;
use TangibleDDD\Symfony\Persistence\DbalTransactionBoundary;
use TangibleDDD\Symfony\Persistence\DbalWakeupScheduler;
use TangibleDDD\Symfony\Runtime\SymfonyConsumerConfig;
use TangibleDDD\Symfony\Runtime\Wakeup\ProcessRunnerWakeTarget;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;
use TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4\AppDestroyed;
use TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4\CancellableProcess;
use TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4\ChildGone;
use TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4\ChildrenFirstProcess;
use TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4\JobDone;
use TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4\KeyedJobProcess;
use TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4\LongAlarmProcess;
use TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4\ReadyCheckProcess;
use TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4\Trail;

/**
 * The wave-4 process demands on Postgres 16 through the sf adapters
 * (register section 8 wave 4 symfony; D3, D7): the core ProcessRunner over
 * DbalProcessStore (route-indexed `ddd_process_waits`), DbalWakeupScheduler
 * (`ddd_wakeups`, absolute UTC due_at), the session advisory lock and
 * DbalTransactionBoundary. Wakes go through the sf ProcessWakeupHandler, as
 * `messenger:consume ddd_wakeups` runs them. A "restarted worker" is a new
 * runner on a new connection. Nothing is wrapped in a per-test transaction.
 */
final class ProcessRunnerWave4PostgresTest extends PostgresTestCase {

  private FrozenClock $clock;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01T12:00:00Z'));
    Trail::reset();
    ReadyCheckProcess::$ready = false;
  }

  protected function tearDown(): void {
    HostDefaults::resetForTests();
    parent::tearDown();
  }

  // ── D3 keyed awaits ─────────────────────────────────────────────────────

  public function test_a_keyed_answer_reaches_only_the_process_that_minted_the_key(): void {
    $runner = $this->runner();
    $a = new KeyedJobProcess(1);
    $b = new KeyedJobProcess(2);
    $runner->start($a);
    $runner->start($b);
    $keyA = $this->awaitKeyOf((int) $a->get_id());

    self::assertSame([[JobDone::class, $keyA]], $this->waitRows((int) $a->get_id()));

    $report = $this->runner()->resume_with_outcome(new JobDone($keyA));

    self::assertSame([(int) $a->get_id()], $report->resumed);
    self::assertSame('completed', $this->statusOf((int) $a->get_id()));
    self::assertSame('suspended', $this->statusOf((int) $b->get_id()));
    self::assertSame(['order:1', 'order:2', 'record:1:ok'], Trail::$notes);
    self::assertSame(0, $this->countWakeups((int) $a->get_id()), 'the resuming save cancelled the timeout intent');
    self::assertSame(1, $this->countWakeups((int) $b->get_id()));
  }

  public function test_an_answer_for_an_unknown_key_is_unheard(): void {
    $runner = $this->runner();
    $runner->start($p = new KeyedJobProcess(1));

    $report = $this->runner()->resume_with_outcome(new JobDone('someone-else'));

    self::assertTrue($report->isUnheard());
    self::assertSame('suspended', $this->statusOf((int) $p->get_id()));
  }

  public function test_the_keyed_timeout_compensates_and_a_late_answer_is_then_a_no_op(): void {
    $this->runner()->start($p = new KeyedJobProcess(1, 1800));
    $id = (int) $p->get_id();
    $key = $this->awaitKeyOf($id);

    $this->clock->advance('+1801 seconds');
    self::assertSame([ProcessWakeupHandler::COMPLETED], $this->drainWakeups($this->runner(), $this->secondConnection()));

    // The suspended step itself never completed, so nothing is undone (core: completed steps only).
    self::assertSame('failed', $this->statusOf($id));
    self::assertSame(['order:1'], Trail::$notes);
    self::assertStringContainsString('Await timed out', (string) $this->db->fetchOne('SELECT last_error FROM ddd_processes WHERE id = ?', [$id]));
    self::assertTrue($this->runner()->resume_with_outcome(new JobDone($key))->isUnheard(), 'no resurrection after compensation');
  }

  // ── D3 any-of over two classes ─────────────────────────────────────────

  public function test_an_any_of_await_is_indexed_per_branch_and_its_answer_resumes(): void {
    $this->runner()->start($p = new CancellableProcess(4));
    $id = (int) $p->get_id();
    $key = $this->awaitKeyOf($id, JobDone::class);

    self::assertSame([[AppDestroyed::class, ''], [JobDone::class, $key]], $this->waitRows($id));

    $report = $this->runner()->resume_with_outcome(new JobDone($key));

    self::assertSame([$id], $report->resumed);
    self::assertSame('completed', $this->statusOf($id));
    self::assertSame(['prepare:4', 'sync:4', 'synced:4'], Trail::$notes);
  }

  public function test_a_cancellation_fact_compensates_every_process_it_names_and_skips_the_others(): void {
    $runner = $this->runner();
    $runner->start($four = new CancellableProcess(4));
    $runner->start($fourAgain = new CancellableProcess(4));
    $runner->start($five = new CancellableProcess(5));

    $report = $this->runner()->resume_with_outcome(new AppDestroyed(4));

    self::assertSame([(int) $four->get_id(), (int) $fourAgain->get_id()], $report->cancelled);
    self::assertSame('failed', $this->statusOf((int) $four->get_id()));
    self::assertSame('failed', $this->statusOf((int) $fourAgain->get_id()));
    self::assertSame('suspended', $this->statusOf((int) $five->get_id()));
    self::assertContains('unprepare:4:Process failed at sync: Cancelled by AppDestroyed', Trail::$notes);
    self::assertNotContains('unprepare:5', array_map(static fn (string $n) => substr($n, 0, 11), Trail::$notes));
    self::assertSame([], $this->waitRows((int) $four->get_id()));
  }

  // ── D3 precheck ─────────────────────────────────────────────────────────

  public function test_a_precheck_hit_resumes_in_place_after_the_await_committed(): void {
    ReadyCheckProcess::$ready = true;

    $this->runner()->start($p = new ReadyCheckProcess());

    self::assertSame('completed', $this->statusOf((int) $p->get_id()));
    self::assertSame(['await_ready', 'provision:precheck'], Trail::$notes);
    self::assertSame(0, $this->countWakeups((int) $p->get_id()), 'the precheck cancelled the alarm');
    self::assertSame([], $this->waitRows((int) $p->get_id()));
  }

  public function test_a_precheck_miss_stays_suspended_on_its_keyed_route(): void {
    $this->runner()->start($p = new ReadyCheckProcess());
    $id = (int) $p->get_id();

    self::assertSame('suspended', $this->statusOf($id));
    $key = $this->awaitKeyOf($id);
    $this->runner()->resume_with_outcome(new JobDone($key));
    self::assertSame('completed', $this->statusOf($id));
    self::assertSame(['await_ready', 'provision:' . JobDone::class], Trail::$notes);
  }

  // ── D3 AwaitAll over a dynamic key set ──────────────────────────────────

  public function test_a_dynamic_await_all_shrinks_its_routes_and_resumes_once_with_every_key(): void {
    $this->runner()->start($p = new ChildrenFirstProcess(['c1', 'c2', 'c3']));
    $id = (int) $p->get_id();
    self::assertSame([[ChildGone::class, 'c1'], [ChildGone::class, 'c2'], [ChildGone::class, 'c3']], $this->waitRows($id));

    self::assertSame([$id], $this->runner()->resume_with_outcome(new ChildGone('c2'))->accumulated);
    self::assertSame([[ChildGone::class, 'c1'], [ChildGone::class, 'c3']], $this->waitRows($id));
    self::assertTrue($this->runner()->resume_with_outcome(new ChildGone('c2'))->isUnheard(), 'a repeated key finds no route');
    $this->runner()->resume_with_outcome(new ChildGone('c3'));
    $this->runner()->resume_with_outcome(new ChildGone('c1'));

    self::assertSame('completed', $this->statusOf($id));
    self::assertSame(['children_first:3', 'purge_self:c1,c2,c3'], Trail::$notes);
  }

  public function test_an_empty_dynamic_key_set_does_not_suspend(): void {
    $this->runner()->start($p = new ChildrenFirstProcess([]));

    self::assertSame('completed', $this->statusOf((int) $p->get_id()));
    self::assertSame(['children_first:0', 'purge_self:'], Trail::$notes);
    self::assertSame(0, $this->countWakeups((int) $p->get_id()));
  }

  public function test_the_mechanism_and_its_tally_survive_a_worker_restart(): void {
    $this->runner()->start($p = new ChildrenFirstProcess(['c1', 'c2']));
    $id = (int) $p->get_id();
    $this->runner()->resume_with_outcome(new ChildGone('c1'));

    $fresh = $this->runner($this->secondConnection());
    $fresh->resume_with_outcome(new ChildGone('c2'));

    self::assertSame('completed', $this->statusOf($id));
    self::assertSame(['children_first:2', 'purge_self:c1,c2'], Trail::$notes);
  }

  // ── D7 long alarms ──────────────────────────────────────────────────────

  public function test_a_25_hour_alarm_is_one_intent_due_at_an_absolute_utc_instant_and_fires_once_after_a_restart(): void {
    $this->runner()->start($p = new LongAlarmProcess(after: 25 * 3600));
    $id = (int) $p->get_id();

    $intents = $this->db->fetchAllAssociative('SELECT kind, step_index, expected_status, due_at AT TIME ZONE \'UTC\' AS due FROM ddd_wakeups WHERE process_id = ?', [$id]);
    self::assertCount(1, $intents, 'a single delay: one intent, no chain of short timers');
    self::assertSame('timeout', $intents[0]['kind']);
    self::assertSame('2026-10-02 13:00:00', $intents[0]['due']);
    self::assertSame([], $this->waitRows($id), 'an alarm waits for no fact');
    self::assertSame('suspended', $this->statusOf($id));

    // One second early: nothing is due.
    $this->clock->advance('+24 hours 59 minutes 59 seconds');
    self::assertSame([], $this->drainWakeups($this->runner(), $this->secondConnection()));

    // Worker restarted (a new connection and runner), clock past the instant.
    $this->clock->advance('+1 second');
    $restarted = $this->secondConnection();
    self::assertSame([ProcessWakeupHandler::COMPLETED], $this->drainWakeups($this->runner($restarted), $restarted));
    self::assertSame('completed', $this->statusOf($id));

    // Way past it, and a duplicate timeout wake: nothing fires again.
    $this->clock->advance('+48 hours');
    self::assertSame([], $this->drainWakeups($this->runner(), $this->secondConnection()));
    $this->runner()->wake(WakeupIntent::timeout('sft', $id, 0, $this->clock->now()));
    self::assertSame(['wait', 'fire'], Trail::$notes);
  }

  public function test_an_absolute_alarm_given_in_another_zone_is_stored_as_the_same_utc_instant(): void {
    $this->runner()->start($p = new LongAlarmProcess(at: '2026-10-03T09:30:00+02:00'));

    self::assertSame(
      '2026-10-03 07:30:00',
      $this->db->fetchOne("SELECT due_at AT TIME ZONE 'UTC' FROM ddd_wakeups WHERE process_id = ?", [$p->get_id()])
    );
    self::assertEquals(new \DateTimeImmutable('2026-10-03T07:30:00Z'), $this->store()->find((int) $p->get_id())->await_deadline());
  }

  public function test_a_partial_await_all_arrival_does_not_re_delay_the_alarm(): void {
    $this->runner()->start($p = new ChildrenFirstProcess(['c1', 'c2']));
    $id = (int) $p->get_id();
    $due = $this->db->fetchOne('SELECT due_at FROM ddd_wakeups WHERE process_id = ?', [$id]);

    $this->clock->advance('+30 minutes');
    $this->runner()->resume_with_outcome(new ChildGone('c1'));

    self::assertSame($due, $this->db->fetchOne('SELECT due_at FROM ddd_wakeups WHERE process_id = ?', [$id]));
    $this->clock->advance('+31 minutes');
    $this->drainWakeups($this->runner(), $this->secondConnection());
    self::assertSame('failed', $this->statusOf($id), 'the 1 h alarm fired at its original instant: missing c2');
  }

  // ── helpers ─────────────────────────────────────────────────────────────

  private function store(?Connection $c = null): DbalProcessStore {
    return new DbalProcessStore($c ?? $this->db, $this->clock);
  }

  private function runner(?Connection $c = null): ProcessRunner {
    $c ??= $this->db;
    $subscriptions = new SubscriptionRegistry();
    $runner = new ProcessRunner(
      new SymfonyConsumerConfig('sft', 'TangibleDDD\\Symfony\\Tests'),
      null,
      new ReentrantProcessLock(new PostgresAdvisoryProcessLock($c)),
      $this->store($c),
      new DbalWakeupScheduler($c),
      $subscriptions,
      new DbalTransactionBoundary($c, NestedPolicy::Reject),
      $this->clock,
      StartMode::InBand,
    );
    foreach ([JobDone::class, AppDestroyed::class, ChildGone::class] as $fact) {
      $runner->register_event($fact);
    }
    return $runner;
  }

  /**
   * One drain of due intents through the sf wakeup handler (what
   * `ddd:relay` + `messenger:consume ddd_wakeups` do, without Messenger).
   *
   * @return list<string> the handler outcomes
   */
  private function drainWakeups(ProcessRunner $runner, Connection $c): array {
    $scheduler = new DbalWakeupScheduler($c);
    $handler = new ProcessWakeupHandler(new ProcessRunnerWakeTarget($runner), $scheduler, $this->clock);
    $outcomes = [];
    foreach ($scheduler->claimDue($this->clock->now(), 50, 300) as $claim) {
      $outcomes[] = $handler(ProcessWakeupMessage::fromClaim($claim));
    }
    return $outcomes;
  }

  private function statusOf(int $id): string {
    return (string) $this->db->fetchOne('SELECT status FROM ddd_processes WHERE id = ?', [$id]);
  }

  private function countWakeups(int $id): int {
    return (int) $this->db->fetchOne('SELECT count(*) FROM ddd_wakeups WHERE process_id = ?', [$id]);
  }

  private function awaitKeyOf(int $id, string $class = JobDone::class): string {
    $key = $this->db->fetchOne("SELECT await_key FROM ddd_process_waits WHERE process_id = ? AND event_class = ? AND await_key <> ''", [$id, $class]);
    self::assertIsString($key, "process #$id has a keyed route on $class");
    return $key;
  }

  /** @return list<array{0: string, 1: string}> */
  private function waitRows(int $id): array {
    return array_map(
      static fn (array $r) => [(string) $r['event_class'], (string) $r['await_key']],
      $this->db->fetchAllAssociative('SELECT event_class, await_key FROM ddd_process_waits WHERE process_id = ? ORDER BY event_class, await_key', [$id])
    );
  }
}
