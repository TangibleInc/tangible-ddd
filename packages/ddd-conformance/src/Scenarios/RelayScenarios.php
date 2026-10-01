<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Application\Infrastructure\OutboxDeadLettered;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;
use TangibleDDD\Conformance\ProcessHost;
use TangibleDDD\Conformance\RecordsSignals;
use TangibleDDD\Conformance\RelayRace;
use TangibleDDD\Conformance\SimulatedCrash;
use TangibleDDD\Conformance\TransportedFact;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Delivery\TransportRejected;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IReportsClaimDeadLetters;

/**
 * The process-free relay scenarios (register section 4): lease fencing,
 * crash between submit and accept, invalid acceptance, pause holders and
 * replay identity, against the host's outbox, pause store, transport and
 * relay step.
 */
abstract class RelayScenarios extends ConformanceTestCase {

  /** Longer than any host's relay lease (OutboxConfig::lock_timeout_seconds, 300 by default) and relay backoff cap (3600). */
  protected const PAST_ANY_LEASE = 3601;

  #[Group('relay.lease-fencing')]
  #[TestDox('relay.lease-fencing: A claims, the lease expires, B claims and accepts, A\'s late accept/retry_later/dead_letter change nothing; expired-lease re-claims count as attempts and dead-letter at claim (CR-PDO-6)')]
  public function test_relay_lease_fencing(): void {
    $id = $this->publish(new WidgetRegistered('w-1'));
    $outbox = $this->host->outbox();

    $a = $outbox->claim(10, $this->host->clock()->now(), 30);
    self::assertCount(1, $a);
    self::assertSame($id, $a[0]->event_id);
    self::assertSame([], $outbox->claim(10, $this->host->clock()->now(), 30), 'a live lease excludes the row');
    $this->while_leased($a[0]);

    $this->host->advance_clock(31);
    $b = $outbox->claim(10, $this->host->clock()->now(), 30);
    self::assertCount(1, $b, 'an expired lease can be re-claimed');
    self::assertSame($id, $b[0]->event_id);
    self::assertNotSame($a[0]->token, $b[0]->token);

    self::assertTrue($outbox->accept($b[0], 'ref-b'));

    self::assertFalse($outbox->accept($a[0], 'ref-a'), 'late accept matches 0 rows');
    self::assertFalse($outbox->retry_later($a[0], 'late', $this->host->clock()->now()->modify('+60 seconds')), 'late retryLater matches 0 rows');
    self::assertFalse($outbox->dead_letter($a[0], 'late'), 'late deadLetter matches 0 rows');

    $stats = $this->host->outbox_admin()->stats();
    self::assertSame(1, $stats['accepted']);
    self::assertSame(0, $stats['pending']);
    self::assertSame(0, $stats['dlq']);
    self::assertSame(0, $stats['dead_letters']);
    self::assertSame([], $outbox->claim(10, $this->host->clock()->now()->modify('+1 day'), 30), 'accepted stays accepted');

    if ($this->host instanceof RelayRace) {
      $this->lateHolderOfARelayStep($this->host);
    }

    $this->expiredLeaseReclaimsAreCounted();
  }

  /**
   * CR-PDO-6 ruling (wave3-notes; core rule since wave 4, CR-W4CE-9): a
   * re-claim of an expired lease counts as a relay attempt, and a row that
   * reaches max_attempts through re-claims is dead-lettered AT CLAIM, not
   * handed out, and is visible like any other dead letter (DLQ, operator
   * view, OutboxDeadLettered).
   */
  private function expiredLeaseReclaimsAreCounted(): void {
    $id = $this->publish(new WidgetRegistered('w-3'));
    $outbox = $this->host->outbox();
    $signalsBefore = $this->host instanceof RecordsSignals ? count($this->host->signals()) : 0;

    $claim = $this->claimOf($id);
    self::assertNotNull($claim);
    self::assertSame(0, $claim->attempts, 'a first claim counts nothing');
    $budget = $claim->record->max_attempts;
    self::assertGreaterThan(1, $budget);

    // The submitter dies after every claim (fatal, OOM, SIGKILL): no outcome is written.
    for ($n = 1; $n < $budget; $n++) {
      $this->host->advance_clock(31);
      $claim = $this->claimOf($id);
      self::assertNotNull($claim, "re-claim $n is handed out");
      self::assertSame($n, $claim->attempts, "re-claim $n of an expired lease counts as attempt $n");
    }

    // The next re-claim reaches the budget: dead-lettered at claim, inside the relay step.
    $this->host->advance_clock(self::PAST_ANY_LEASE);
    $report = $this->host->relay_once();
    self::assertNotContains($id, $report->claimed, 'not handed out again');
    self::assertNotContains($id, $report->accepted);
    self::assertNotContains($id, $this->transported_ids(), 'never transported');
    self::assertSame([], $outbox->claim(10, $this->host->clock()->now()->modify('+1 day'), 30), 'nothing left to claim');

    $letters = array_values(array_filter(
      $this->host->outbox_admin()->dead_letters(10),
      static fn ($l) => $l->event_id === $id,
    ));
    self::assertCount(1, $letters, 'in the DLQ');
    self::assertSame($budget, $letters[0]->attempts, 'with attempts equal to the budget');
    self::assertStringContainsString(IReportsClaimDeadLetters::LEASE_EXPIRED_ERROR, (string) $letters[0]->error);
    $stats = $this->host->outbox_admin()->stats();
    self::assertSame(0, $stats['pending']);
    self::assertSame(1, $stats['dlq']);

    if ($this->host instanceof ProcessHost) {
      $items = array_values(array_filter(
        $this->host->operator_view()->list(Layer::Relay),
        static fn ($i) => $i->key === $id,
      ));
      self::assertCount(1, $items, 'the operator view lists the claim-time dead letter');
      self::assertSame($budget, $items[0]->attempts);
    }
    if ($this->host instanceof RecordsSignals) {
      $dead = array_values(array_filter(
        array_slice($this->host->signals(), $signalsBefore),
        static fn ($s) => $s instanceof OutboxDeadLettered && $s->entry()->event_id === $id,
      ));
      self::assertCount(1, $dead, 'OutboxDeadLettered is emitted once, as for a relay-side dead letter');
    }
  }

  /** Claim directly, as a submitter would, and return the claim of $eventId (null if not handed out). */
  private function claimOf(string $eventId): ?Claim {
    foreach ($this->host->outbox()->claim(10, $this->host->clock()->now(), 30) as $c) {
      if ($c->event_id === $eventId) {
        return $c;
      }
    }
    return null;
  }

  /**
   * CR sf-3 (wave-2 notes, core sfc-1): the relay step submits, its lease
   * expires, another relay re-claims and accepts the row, and only then
   * does the first step's accept() run. It matches 0 rows (lease lost). On
   * a transport sharing the store's connection the late holder's submission
   * rolls back with it, so the fact is not transported twice.
   */
  private function lateHolderOfARelayStep(RelayRace $race): void {
    $id = $this->publish(new WidgetRegistered('w-2'));
    $outbox = $this->host->outbox();
    $shared = $this->host->transport()->shares_connection($outbox);
    $before = $this->transported_ids();

    $race->race_next_relay(function () use ($outbox, $id): void {
      $this->host->advance_clock(self::PAST_ANY_LEASE);
      $b = $outbox->claim(10, $this->host->clock()->now(), 30);
      self::assertSame([$id], array_map(static fn (Claim $c) => $c->event_id, $b), 'the expired lease is re-claimed by B');
      self::assertTrue($outbox->accept($b[0], 'ref-b'), 'B accepts first');
    });
    $report = $this->host->relay_once();

    self::assertSame([$id], $report->claimed);
    self::assertSame([], $report->accepted, 'the late holder never counts as accepted');
    self::assertSame([$id], $report->lease_lost, 'its accept matched 0 rows');
    $stats = $this->host->outbox_admin()->stats();
    self::assertSame(2, $stats['accepted'], 'B\'s acceptance stands');
    self::assertSame(0, $stats['pending']);
    $added = array_values(array_diff_key($this->transported_ids(), $before));
    if ($shared) {
      self::assertSame([], $added, 'shared connection: the late holder\'s submission rolled back with its 0-row accept');
    } else {
      self::assertSame([$id], $added, 'separate connection: the submission stays; the ledger keeps the effect single');
    }
  }

  #[Group('relay.crash-after-submit')]
  #[TestDox('relay.crash-after-submit: the transport took it, the relay died before accept; the fact is delivered and its effect applied once')]
  public function test_relay_crash_after_submit(): void {
    $effects = 0;
    $this->host->subscriptions()->add(new Subscriber('conformance.effect', Subscriber::LISTENER, WidgetRegistered::class,
      static function () use (&$effects): void { $effects++; }));
    $id = $this->publish(new WidgetRegistered('w-1'));
    $shared = $this->host->transport()->shares_connection($this->host->outbox());

    $this->host->crash_next_relay();
    self::assertInstanceOf(SimulatedCrash::class, self::thrown(fn () => $this->host->relay_once()));

    $stats = $this->host->outbox_admin()->stats();
    self::assertSame(1, $stats['pending'], 'never accepted');
    self::assertSame(0, $stats['accepted']);
    if ($shared) {
      self::assertSame([], $this->transported_ids(), 'shared connection: the submission rolled back with the accept');
    }

    $this->host->advance_clock(self::PAST_ANY_LEASE);
    $report = $this->host->relay_once();
    self::assertSame([$id], $report->accepted, 'the next run relays it');
    self::assertSame(1, $this->host->outbox_admin()->stats()['accepted']);

    $ids = $this->transported_ids();
    if ($shared) {
      self::assertSame([$id], $ids, 'shared connection: exactly one delivery');
    } else {
      self::assertSame([$id, $id], $ids, 'separate connection: the same event_id recurs');
    }

    $outcomes = $this->host->deliver_transported(WidgetRegistered::class);
    self::assertCount(count($ids), $outcomes);
    self::assertSame(1, $effects, 'subscriber effect applied once');
    if (!$shared) {
      self::assertSame(['conformance.effect'], $outcomes[1]->skipped, 'the recurrence is a ledger hit');
    }
  }

  #[Group('relay.invalid-acceptance')]
  #[TestDox('relay.invalid-acceptance: a throwing transport or a missing reference is retried per the relay budget, then dead-lettered, never accepted')]
  public function test_relay_invalid_acceptance(): void {
    $id = $this->publish(new WidgetRegistered('w-1'));
    $failures = ['throw', 'no-ref', 'throw', 'no-ref', 'throw'];

    foreach ($failures as $n => $kind) {
      $kind === 'throw'
        ? $this->host->reject_next_submission(new TransportRejected("rejected #$n"))
        : $this->host->accept_next_without_ref();

      $report = $this->host->relay_once();

      self::assertSame([$id], $report->claimed, "attempt $n claimed");
      self::assertSame([], $report->accepted, "attempt $n ($kind) is never accepted");
      if ($n < count($failures) - 1) {
        self::assertSame([$id], $report->retried, "attempt $n retried");
        self::assertSame([], $this->host->relay_once()->claimed, 'backoff: not due again immediately');
      } else {
        self::assertSame([$id], $report->dead_lettered, 'the last budgeted attempt dead-letters');
      }
      $this->host->advance_clock(self::PAST_ANY_LEASE);
    }

    $stats = $this->host->outbox_admin()->stats();
    self::assertSame(0, $stats['accepted']);
    self::assertSame(0, $stats['pending']);
    self::assertSame(1, $stats['dlq']);
    self::assertSame(1, $stats['dead_letters']);

    $letters = $this->host->outbox_admin()->dead_letters(10);
    self::assertCount(1, $letters);
    self::assertSame($id, $letters[0]->event_id);
    self::assertSame(count($failures), $letters[0]->attempts);

    self::assertSame([], $this->host->relay_once()->claimed, 'a dead letter is not relayed again');
    self::assertSame([], $this->transported_ids(), 'the transport holds nothing deliverable');
  }

  #[Group('relay.pause-holders')]
  #[TestDox('relay.pause-holders: two overlapping holds; releasing one keeps the pause, the other one\'s expiry is honoured')]
  public function test_relay_pause_holders(): void {
    $id = $this->publish(new WidgetRegistered('w-1'));
    $type = WidgetRegistered::name();
    $pauses = $this->host->pauses();
    $now = $this->host->clock()->now();

    $pauses->hold('holder-a', '*', null);
    $pauses->hold('holder-b', 'widget_*', $now->modify('+600 seconds'));
    self::assertTrue($pauses->is_paused($type, $now));
    self::assertSame([], $this->host->relay_once()->claimed, 'paused by both holders');

    $pauses->release('holder-a');
    self::assertTrue($pauses->is_paused($type, $this->host->clock()->now()), 'holder-b still holds');
    self::assertSame([], $this->host->relay_once()->claimed, 'the remaining hold still pauses');

    $this->host->advance_clock(601);
    self::assertFalse($pauses->is_paused($type, $this->host->clock()->now()), 'holder-b expired');
    self::assertSame([$id], $this->host->relay_once()->accepted, 'expiry honoured: relayed');
  }

  #[Group('relay.replay-keeps-identity')]
  #[TestDox('relay.replay-keeps-identity: replaying a dead letter keeps the event_id, deletes the DLQ row, and skips subscribers already in the ledger')]
  public function test_relay_replay_keeps_identity(): void {
    $ran = ['conformance.a' => 0, 'conformance.b' => 0];
    foreach (array_keys($ran) as $sid) {
      $this->host->subscriptions()->add(new Subscriber($sid, Subscriber::LISTENER, WidgetRegistered::class,
        static function () use (&$ran, $sid): void { $ran[$sid]++; }));
    }
    $id = $this->publish(new WidgetRegistered('w-1'));
    $this->host->ledger()->mark_delivered('conformance.a', $id); // an earlier delivery reached A

    for ($n = 0; $n < 5; $n++) {
      $this->host->reject_next_submission();
      $this->host->relay_once();
      $this->host->advance_clock(self::PAST_ANY_LEASE);
    }
    $admin = $this->host->outbox_admin();
    $letters = $admin->dead_letters(10);
    self::assertCount(1, $letters, 'dead-lettered after the relay budget');
    self::assertSame($id, $letters[0]->event_id);

    $admin->replay($letters[0]->dlq_id);

    self::assertSame([], $admin->dead_letters(10), 'the DLQ row is deleted');
    $stats = $admin->stats();
    self::assertSame(1, $stats['pending'], 'the original row is reset, not duplicated');
    self::assertSame(0, $stats['dlq']);

    self::assertSame([$id], $this->host->relay_once()->accepted, 'relayed under the SAME event_id');
    self::assertSame([$id], $this->transported_ids());

    $outcomes = $this->host->deliver_transported(WidgetRegistered::class);
    self::assertCount(1, $outcomes);
    self::assertSame(['conformance.a'], $outcomes[0]->skipped, 'A was already in the ledger');
    self::assertSame(['conformance.b'], $outcomes[0]->delivered);
    self::assertSame(['conformance.a' => 0, 'conformance.b' => 1], $ran);
  }

  /**
   * Host hook, called while claim $c's lease is live. wp overrides it to
   * assert that a 0.6 fetch_pending() also skips the row (`locked_until` set).
   */
  protected function while_leased(Claim $c): void {}

  /** @return list<string> */
  protected function transported_ids(): array {
    return array_map(static fn (TransportedFact $t) => $t->event_id, $this->host->transported());
  }
}
