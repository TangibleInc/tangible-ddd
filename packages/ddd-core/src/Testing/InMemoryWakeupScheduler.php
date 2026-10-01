<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Ops\IOperatorItemSource;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;
use TangibleDDD\Runtime\Scheduling\WakeRetryPolicy;
use TangibleDDD\Runtime\Scheduling\ClaimedWakeup;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Runtime\Scheduling\WakeupOutsideTransaction;

/**
 * In-memory IWakeupScheduler driven by the caller's clock (pass FrozenClock
 * time to claim_due). schedule()/cancel() enforce the "inside the process
 * transaction" rule against the required boundary (WakeupOutsideTransaction
 * otherwise); enlist it in that boundary so an intent rolls back with the
 * process save. Tests that deliberately skip the rule must say so with
 * lenient().
 *
 * It keeps the WakeupIntent objects themselves, so WakeupIntent::$fact
 * survives the round trip, but it does NOT declare ICarriesFacts: the
 * ProcessRunner keeps the wave-3 rule on it (a contended fact resume fails
 * the subscriber and the delivery retries it). A host or test that wants
 * the wave-5 parked-resume path (AW2) opts in with InMemoryParkingScheduler.
 *
 * Subclasses keep this constructor's signature, so lenient() can build them
 * with `new static`.
 *
 * @phpstan-consistent-constructor
 */
class InMemoryWakeupScheduler implements IWakeupScheduler, InMemoryTransactional, IOperatorItemSource {

  /** @var array<string, array{intent: WakeupIntent, seq: int, attempts: int, next_at: ?\DateTimeImmutable, token: ?string, lease_until: ?\DateTimeImmutable, error: ?string}> */
  private array $intents = [];

  private int $seq = 0;

  private bool $checkTransaction = true;

  public function __construct(private readonly ITransactionBoundary $boundary) {}

  /**
   * Explicit lenient mode: schedule()/cancel() accept calls outside any
   * transaction. Never use it in conformance or runner tests, where it
   * would hide an intent written outside the process transaction (C8/C9).
   */
  public static function lenient(): static {
    $s = new static(new InMemoryTransactionBoundary());
    $s->checkTransaction = false;
    return $s;
  }

  public function schedule(WakeupIntent $i): void {
    $this->assertInTransaction('schedule');
    if (isset($this->intents[$i->key])) {
      return;
    }
    $this->intents[$i->key] = [
      'intent' => $i, 'seq' => ++$this->seq, 'attempts' => 0,
      'next_at' => null, 'token' => null, 'lease_until' => null, 'error' => null,
    ];
  }

  public function cancel(string $idempotencyKey): void {
    $this->assertInTransaction('cancel');
    unset($this->intents[$idempotencyKey]);
  }

  public function claim_due(\DateTimeImmutable $now, int $limit, int $leaseSeconds): array {
    $due = array_filter($this->intents, static fn (array $r) =>
      $r['intent']->due_at <= $now
      && ($r['next_at'] === null || $r['next_at'] <= $now)
      && ($r['token'] === null || $r['lease_until'] <= $now));
    uasort($due, static fn ($a, $b) => [$a['intent']->due_at, $a['seq']] <=> [$b['intent']->due_at, $b['seq']]);

    $leaseUntil = $now->modify("+{$leaseSeconds} seconds");
    $claimed = [];
    foreach (array_slice($due, 0, max(0, $limit), true) as $key => $r) {
      $token = bin2hex(random_bytes(8));
      $this->intents[$key]['token'] = $token;
      $this->intents[$key]['lease_until'] = $leaseUntil;
      $claimed[] = new ClaimedWakeup($r['intent'], $token, $leaseUntil, $r['attempts']);
    }
    return $claimed;
  }

  public function complete(ClaimedWakeup $w): bool {
    if (!$this->holds($w)) {
      return false;
    }
    unset($this->intents[$w->intent->key]);
    return true;
  }

  public function retry_later(ClaimedWakeup $w, string $error, \DateTimeImmutable $nextAt): bool {
    if (!$this->holds($w)) {
      return false;
    }
    $key = $w->intent->key;
    $this->intents[$key]['attempts']++;
    $this->intents[$key]['next_at'] = $nextAt;
    $this->intents[$key]['error'] = $error;
    $this->intents[$key]['token'] = null;
    $this->intents[$key]['lease_until'] = null;
    return true;
  }

  /** IOperatorItemSource: intents that failed at least once (layer `wakeup`). */
  public function items(?Layer $layer, int $limit): array {
    if ($layer !== null && $layer !== Layer::Wakeup) {
      return [];
    }
    $items = [];
    foreach ($this->intents as $key => $r) {
      if ($r['attempts'] === 0) {
        continue;
      }
      $items[] = new OperatorItem(
        Layer::Wakeup, $r['intent']->consumer, $key, $r['attempts'], WakeRetryPolicy::BUDGET,
        $r['error'], null, ['retry_wake'],
      );
    }
    return array_slice($items, 0, max(0, $limit));
  }

  /** @return list<WakeupIntent> every intent not yet completed or cancelled */
  public function pending(): array {
    return array_values(array_map(static fn (array $r) => $r['intent'], $this->intents));
  }

  public function snapshot(): mixed {
    return [$this->intents, $this->seq];
  }

  public function restore(mixed $state): void {
    [$this->intents, $this->seq] = $state;
  }

  private function holds(ClaimedWakeup $w): bool {
    return ($this->intents[$w->intent->key]['token'] ?? null) === $w->token;
  }

  private function assertInTransaction(string $op): void {
    if ($this->checkTransaction && !$this->boundary->is_active()) {
      throw new WakeupOutsideTransaction("IWakeupScheduler::$op() must run inside the process store's transaction.");
    }
  }
}
