<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Core\Tests\Unit\Fixtures\FulfilmentProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\OnboardingProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Process\ConcurrentProcessModification;
use TangibleDDD\Runtime\Process\IgnitionKey;
use TangibleDDD\Runtime\Process\IgnitionResult;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Process\ProcessStoreFailed;
use TangibleDDD\Runtime\Process\QuarantinedProcess;
use TangibleDDD\Testing\InMemoryProcessStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;

final class InMemoryProcessStoreTest extends TestCase {

  private const EVENT = '0b6c4c5e-1f53-4a8e-9f2b-6b8d5f0a9d11';

  private FrozenClock $clock;
  private InMemoryProcessStore $store;

  protected function setUp(): void {
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
    $this->store = new InMemoryProcessStore($this->clock);
  }

  private function process(string $status = 'running', ?string $waiting_for = null): FulfilmentProcess {
    $p = new FulfilmentProcess(3);
    $p->hydrate(0, $status, 'corr', waiting_for: $waiting_for);
    $p->set_id(null);
    return $p;
  }

  public function test_insert_assigns_an_id_and_version_one(): void {
    self::assertInstanceOf(IProcessStore::class, $this->store);
    $p = $this->process();

    $id = $this->store->insert($p);

    self::assertSame($id, $p->get_id());
    self::assertSame(1, $this->store->versionOf($id));
    self::assertNull($this->store->ignitionKeyOf($id));
    self::assertInstanceOf(FulfilmentProcess::class, $this->store->find($id));
  }

  public function test_insert_of_an_already_persisted_process_fails(): void {
    $p = $this->process();
    $this->store->insert($p);

    $this->expectException(ProcessStoreFailed::class);
    $this->store->insert($p);
  }

  public function test_find_returns_a_copy_not_the_live_instance(): void {
    $p = $this->process();
    $id = $this->store->insert($p);
    $p->fail('mutated after save');

    self::assertSame('running', $this->store->find($id)->status());
    self::assertNotSame($p, $this->store->find($id));
    self::assertNull($this->store->find(999));
  }

  public function test_insert_ignited_dedups_on_process_class_and_event_id(): void {
    $first = $this->process();
    self::assertSame(IgnitionResult::Inserted, $this->store->insertIgnited($first, FulfilmentProcess::class, self::EVENT));
    self::assertSame(IgnitionKey::for(self::EVENT, FulfilmentProcess::class), $this->store->ignitionKeyOf($first->get_id()));

    $loser = $this->process();
    self::assertSame(IgnitionResult::AlreadyIgnited, $this->store->insertIgnited($loser, FulfilmentProcess::class, self::EVENT));
    self::assertNull($loser->get_id(), 'the loser is not persisted');
    self::assertSame(1, $this->store->count());
  }

  public function test_the_same_fact_may_ignite_different_process_classes(): void {
    $a = $this->process();
    $b = new OnboardingProcess();

    self::assertSame(IgnitionResult::Inserted, $this->store->insertIgnited($a, FulfilmentProcess::class, self::EVENT));
    self::assertSame(IgnitionResult::Inserted, $this->store->insertIgnited($b, OnboardingProcess::class, self::EVENT));
  }

  public function test_manual_starts_are_never_deduped(): void {
    // process.manual-start-in-drain: two manual starts stamped with the same fact id
    $a = $this->process();
    $a->mark_ignited_by(self::EVENT);
    $b = $this->process();
    $b->mark_ignited_by(self::EVENT);

    $this->store->insert($a);
    $this->store->insert($b);

    self::assertSame(2, $this->store->count());
    self::assertNull($this->store->ignitionKeyOf($a->get_id()));
    self::assertSame(self::EVENT, $this->store->find($b->get_id())->ignited_by_event_id());

    // a later #[StartsOn] ignition of that class by the same fact still ignites once
    self::assertSame(IgnitionResult::Inserted, $this->store->insertIgnited($this->process(), FulfilmentProcess::class, self::EVENT));
    self::assertSame(IgnitionResult::AlreadyIgnited, $this->store->insertIgnited($this->process(), FulfilmentProcess::class, self::EVENT));
  }

  public function test_save_is_version_fenced(): void {
    $p = $this->process();
    $id = $this->store->insert($p);

    $p->complete();
    self::assertSame(2, $this->store->save($p, 1));
    self::assertSame('completed', $this->store->find($id)->status());

    $this->expectException(ConcurrentProcessModification::class);
    $this->store->save($p, 1);
  }

  public function test_save_of_an_unknown_process_fails(): void {
    $p = $this->process();
    $p->set_id(42);

    $this->expectException(ProcessStoreFailed::class);
    $this->store->save($p, 1);
  }

  public function test_touch_bumps_the_version_under_the_fence(): void {
    $id = $this->store->insert($this->process());

    self::assertSame(2, $this->store->touch($id, 1));
    $this->expectException(ConcurrentProcessModification::class);
    $this->store->touch($id, 1);
  }

  public function test_find_waiting_for_matches_suspended_processes_by_class(): void {
    $waiting = $this->store->insert($this->process('suspended', UserJoined::class));
    $this->store->insert($this->process('suspended', OrderPlaced::class));
    $this->store->insert($this->process('running', UserJoined::class));

    self::assertSame([$waiting], $this->store->findWaitingFor(UserJoined::class));
  }

  public function test_find_stranded_reports_old_running_and_scheduled_rows(): void {
    $old_running = $this->store->insert($this->process('running'));
    $old_scheduled = $this->store->insert($this->process('scheduled'));
    $this->store->insert($this->process('suspended'));
    $this->clock->advance('PT16M');
    $fresh = $this->store->insert($this->process('running'));

    $stranded = $this->store->findStranded($this->clock->now());

    self::assertSame([$old_running, $old_scheduled], array_map(static fn ($s) => $s->processId, $stranded));
    self::assertSame('running', $stranded[0]->status);
    self::assertSame(FulfilmentProcess::class, $stranded[0]->processClass);
    self::assertNotContains($fresh, array_map(static fn ($s) => $s->processId, $stranded));
  }

  public function test_find_stranded_skips_a_row_with_a_live_intent(): void {
    // wave1-notes core minor 4: "running/scheduled with NO LIVE INTENT past threshold".
    $boundary = new InMemoryTransactionBoundary();
    $intents = new \TangibleDDD\Testing\InMemoryWakeupScheduler($boundary);
    $store = new InMemoryProcessStore($this->clock, 900, $intents);

    $covered = $store->insert($this->process('scheduled'));
    $bare = $store->insert($this->process('scheduled'));
    $boundary->run(fn () => $intents->schedule(
      \TangibleDDD\Runtime\Scheduling\WakeupIntent::continuation('acme', $covered, 0, $this->clock->now())
    ));
    $this->clock->advance('PT16M');

    self::assertSame([$bare], array_map(static fn ($s) => $s->processId, $store->findStranded($this->clock->now())));

    // Completing the intent makes the row stranded again.
    $claimed = $intents->claimDue($this->clock->now(), 10, 60);
    $intents->complete($claimed[0]);
    self::assertSame([$covered, $bare], array_map(static fn ($s) => $s->processId, $store->findStranded($this->clock->now())));
  }

  public function test_intents_can_be_attached_after_construction(): void {
    $boundary = new InMemoryTransactionBoundary();
    $intents = new \TangibleDDD\Testing\InMemoryWakeupScheduler($boundary);
    $id = $this->store->insert($this->process('running'));
    $this->store->attachIntents($intents);
    $boundary->run(fn () => $intents->schedule(
      \TangibleDDD\Runtime\Scheduling\WakeupIntent::timeout('acme', $id, 0, $this->clock->now())
    ));
    $this->clock->advance('PT16M');

    self::assertSame([], $this->store->findStranded($this->clock->now()));
  }

  public function test_an_undecodable_row_is_quarantined_as_failed_and_the_worker_continues(): void {
    $bad = $this->store->insert($this->process());
    $good = $this->store->insert($this->process());
    $this->store->corruptClassForTests($bad, 'Gone\\RemovedProcess');

    try {
      $this->store->find($bad);
      self::fail('expected QuarantinedProcess');
    } catch (QuarantinedProcess $e) {
      self::assertStringContainsString('Gone\\RemovedProcess', $e->getMessage());
    }
    self::assertSame('failed', $this->store->statusOf($bad));
    self::assertNotNull($this->store->quarantineReasonOf($bad));
    self::assertNotNull($this->store->find($good));
  }

  public function test_the_store_rolls_back_with_the_boundary(): void {
    $tx = new InMemoryTransactionBoundary();
    $tx->enlist($this->store);

    try {
      $tx->run(function () {
        $this->store->insertIgnited($this->process(), FulfilmentProcess::class, self::EVENT);
        throw new \RuntimeException('first step failed to persist');
      });
    } catch (\RuntimeException) {
    }

    self::assertSame(0, $this->store->count());
    self::assertSame(IgnitionResult::Inserted, $this->store->insertIgnited($this->process(), FulfilmentProcess::class, self::EVENT));
  }
}
