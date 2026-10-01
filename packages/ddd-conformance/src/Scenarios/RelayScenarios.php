<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;
use TangibleDDD\Conformance\RelayRace;
use TangibleDDD\Conformance\SimulatedCrash;
use TangibleDDD\Conformance\TransportedFact;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Delivery\TransportRejected;
use TangibleDDD\Runtime\Outbox\Claim;

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
  #[TestDox('relay.lease-fencing: A claims, the lease expires, B claims and accepts, A\'s late accept/retryLater/deadLetter change nothing')]
  public function test_relay_lease_fencing(): void {
    $id = $this->publishFact(new WidgetRegistered('w-1'));
    $outbox = $this->host->outbox();

    $a = $outbox->claim(10, $this->host->clock()->now(), 30);
    self::assertCount(1, $a);
    self::assertSame($id, $a[0]->event_id);
    self::assertSame([], $outbox->claim(10, $this->host->clock()->now(), 30), 'a live lease excludes the row');
    $this->whileLeased($a[0]);

    $this->host->advanceClock(31);
    $b = $outbox->claim(10, $this->host->clock()->now(), 30);
    self::assertCount(1, $b, 'an expired lease can be re-claimed');
    self::assertSame($id, $b[0]->event_id);
    self::assertNotSame($a[0]->claimToken, $b[0]->claimToken);

    self::assertTrue($outbox->accept($b[0], 'ref-b'));

    self::assertFalse($outbox->accept($a[0], 'ref-a'), 'late accept matches 0 rows');
    self::assertFalse($outbox->retryLater($a[0], 'late', $this->host->clock()->now()->modify('+60 seconds')), 'late retryLater matches 0 rows');
    self::assertFalse($outbox->deadLetter($a[0], 'late'), 'late deadLetter matches 0 rows');

    $stats = $this->host->outboxAdministration()->stats();
    self::assertSame(1, $stats['accepted']);
    self::assertSame(0, $stats['pending']);
    self::assertSame(0, $stats['dlq']);
    self::assertSame(0, $stats['dead_letters']);
    self::assertSame([], $outbox->claim(10, $this->host->clock()->now()->modify('+1 day'), 30), 'accepted stays accepted');

    if ($this->host instanceof RelayRace) {
      $this->lateHolderOfARelayStep($this->host);
    }
  }

  /**
   * CR sf-3 (wave-2 notes, core sfc-1): the relay step submits, its lease
   * expires, another relay re-claims and accepts the row, and only then
   * does the first step's accept() run. It matches 0 rows (lease lost). On
   * a transport sharing the store's connection the late holder's submission
   * rolls back with it, so the fact is not transported twice.
   */
  private function lateHolderOfARelayStep(RelayRace $race): void {
    $id = $this->publishFact(new WidgetRegistered('w-2'));
    $outbox = $this->host->outbox();
    $shared = $this->host->transport()->sharesConnectionWith($outbox);
    $before = $this->transportedIds();

    $race->raceNextRelayAfterSubmit(function () use ($outbox, $id): void {
      $this->host->advanceClock(self::PAST_ANY_LEASE);
      $b = $outbox->claim(10, $this->host->clock()->now(), 30);
      self::assertSame([$id], array_map(static fn (Claim $c) => $c->event_id, $b), 'the expired lease is re-claimed by B');
      self::assertTrue($outbox->accept($b[0], 'ref-b'), 'B accepts first');
    });
    $report = $this->host->relayOnce();

    self::assertSame([$id], $report->claimed);
    self::assertSame([], $report->accepted, 'the late holder never counts as accepted');
    self::assertSame([$id], $report->leaseLost, 'its accept matched 0 rows');
    $stats = $this->host->outboxAdministration()->stats();
    self::assertSame(2, $stats['accepted'], 'B\'s acceptance stands');
    self::assertSame(0, $stats['pending']);
    $added = array_values(array_diff_key($this->transportedIds(), $before));
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
    $id = $this->publishFact(new WidgetRegistered('w-1'));
    $shared = $this->host->transport()->sharesConnectionWith($this->host->outbox());

    $this->host->crashNextRelayAfterSubmit();
    self::assertInstanceOf(SimulatedCrash::class, self::catchThrowable(fn () => $this->host->relayOnce()));

    $stats = $this->host->outboxAdministration()->stats();
    self::assertSame(1, $stats['pending'], 'never accepted');
    self::assertSame(0, $stats['accepted']);
    if ($shared) {
      self::assertSame([], $this->transportedIds(), 'shared connection: the submission rolled back with the accept');
    }

    $this->host->advanceClock(self::PAST_ANY_LEASE);
    $report = $this->host->relayOnce();
    self::assertSame([$id], $report->accepted, 'the next run relays it');
    self::assertSame(1, $this->host->outboxAdministration()->stats()['accepted']);

    $ids = $this->transportedIds();
    if ($shared) {
      self::assertSame([$id], $ids, 'shared connection: exactly one delivery');
    } else {
      self::assertSame([$id, $id], $ids, 'separate connection: the same event_id recurs');
    }

    $outcomes = $this->host->deliverTransported(WidgetRegistered::class);
    self::assertCount(count($ids), $outcomes);
    self::assertSame(1, $effects, 'subscriber effect applied once');
    if (!$shared) {
      self::assertSame(['conformance.effect'], $outcomes[1]->skipped, 'the recurrence is a ledger hit');
    }
  }

  #[Group('relay.invalid-acceptance')]
  #[TestDox('relay.invalid-acceptance: a throwing transport or a missing reference is retried per the relay budget, then dead-lettered, never accepted')]
  public function test_relay_invalid_acceptance(): void {
    $id = $this->publishFact(new WidgetRegistered('w-1'));
    $failures = ['throw', 'no-ref', 'throw', 'no-ref', 'throw'];

    foreach ($failures as $n => $kind) {
      $kind === 'throw'
        ? $this->host->rejectNextSubmission(new TransportRejected("rejected #$n"))
        : $this->host->acceptNextSubmissionWithoutRef();

      $report = $this->host->relayOnce();

      self::assertSame([$id], $report->claimed, "attempt $n claimed");
      self::assertSame([], $report->accepted, "attempt $n ($kind) is never accepted");
      if ($n < count($failures) - 1) {
        self::assertSame([$id], $report->retried, "attempt $n retried");
        self::assertSame([], $this->host->relayOnce()->claimed, 'backoff: not due again immediately');
      } else {
        self::assertSame([$id], $report->deadLettered, 'the last budgeted attempt dead-letters');
      }
      $this->host->advanceClock(self::PAST_ANY_LEASE);
    }

    $stats = $this->host->outboxAdministration()->stats();
    self::assertSame(0, $stats['accepted']);
    self::assertSame(0, $stats['pending']);
    self::assertSame(1, $stats['dlq']);
    self::assertSame(1, $stats['dead_letters']);

    $letters = $this->host->outboxAdministration()->deadLetters(10);
    self::assertCount(1, $letters);
    self::assertSame($id, $letters[0]->event_id);
    self::assertSame(count($failures), $letters[0]->attempts);

    self::assertSame([], $this->host->relayOnce()->claimed, 'a dead letter is not relayed again');
    self::assertSame([], $this->transportedIds(), 'the transport holds nothing deliverable');
  }

  #[Group('relay.pause-holders')]
  #[TestDox('relay.pause-holders: two overlapping holds; releasing one keeps the pause, the other one\'s expiry is honoured')]
  public function test_relay_pause_holders(): void {
    $id = $this->publishFact(new WidgetRegistered('w-1'));
    $type = WidgetRegistered::name();
    $pauses = $this->host->relayPauses();
    $now = $this->host->clock()->now();

    $pauses->hold('holder-a', '*', null);
    $pauses->hold('holder-b', 'widget_*', $now->modify('+600 seconds'));
    self::assertTrue($pauses->isPaused($type, $now));
    self::assertSame([], $this->host->relayOnce()->claimed, 'paused by both holders');

    $pauses->release('holder-a');
    self::assertTrue($pauses->isPaused($type, $this->host->clock()->now()), 'holder-b still holds');
    self::assertSame([], $this->host->relayOnce()->claimed, 'the remaining hold still pauses');

    $this->host->advanceClock(601);
    self::assertFalse($pauses->isPaused($type, $this->host->clock()->now()), 'holder-b expired');
    self::assertSame([$id], $this->host->relayOnce()->accepted, 'expiry honoured: relayed');
  }

  #[Group('relay.replay-keeps-identity')]
  #[TestDox('relay.replay-keeps-identity: replaying a dead letter keeps the event_id, deletes the DLQ row, and skips subscribers already in the ledger')]
  public function test_relay_replay_keeps_identity(): void {
    $ran = ['conformance.a' => 0, 'conformance.b' => 0];
    foreach (array_keys($ran) as $sid) {
      $this->host->subscriptions()->add(new Subscriber($sid, Subscriber::LISTENER, WidgetRegistered::class,
        static function () use (&$ran, $sid): void { $ran[$sid]++; }));
    }
    $id = $this->publishFact(new WidgetRegistered('w-1'));
    $this->host->ledger()->markDelivered('conformance.a', $id); // an earlier delivery reached A

    for ($n = 0; $n < 5; $n++) {
      $this->host->rejectNextSubmission();
      $this->host->relayOnce();
      $this->host->advanceClock(self::PAST_ANY_LEASE);
    }
    $admin = $this->host->outboxAdministration();
    $letters = $admin->deadLetters(10);
    self::assertCount(1, $letters, 'dead-lettered after the relay budget');
    self::assertSame($id, $letters[0]->event_id);

    $admin->replay($letters[0]->dlqId);

    self::assertSame([], $admin->deadLetters(10), 'the DLQ row is deleted');
    $stats = $admin->stats();
    self::assertSame(1, $stats['pending'], 'the original row is reset, not duplicated');
    self::assertSame(0, $stats['dlq']);

    self::assertSame([$id], $this->host->relayOnce()->accepted, 'relayed under the SAME event_id');
    self::assertSame([$id], $this->transportedIds());

    $outcomes = $this->host->deliverTransported(WidgetRegistered::class);
    self::assertCount(1, $outcomes);
    self::assertSame(['conformance.a'], $outcomes[0]->skipped, 'A was already in the ledger');
    self::assertSame(['conformance.b'], $outcomes[0]->delivered);
    self::assertSame(['conformance.a' => 0, 'conformance.b' => 1], $ran);
  }

  /**
   * Host hook, called while claim $c's lease is live. wp overrides it to
   * assert that a 0.6 fetch_pending() also skips the row (`locked_until` set).
   */
  protected function whileLeased(Claim $c): void {}

  /** @return list<string> */
  protected function transportedIds(): array {
    return array_map(static fn (TransportedFact $t) => $t->eventId, $this->host->transported());
  }
}
