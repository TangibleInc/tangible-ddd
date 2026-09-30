<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Scheduling\ClaimedWakeup;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Runtime\Scheduling\WakeupOutsideTransaction;

/**
 * In-memory IWakeupScheduler driven by the caller's clock (pass FrozenClock
 * time to claimDue). With a boundary, schedule()/cancel() enforce the
 * "inside the process transaction" rule; enlist it in that boundary so an
 * intent rolls back with the process save.
 */
final class InMemoryWakeupScheduler implements IWakeupScheduler, InMemoryTransactional {

  /** @var array<string, array{intent: WakeupIntent, seq: int, attempts: int, next_at: ?\DateTimeImmutable, token: ?string, lease_until: ?\DateTimeImmutable, error: ?string}> */
  private array $intents = [];

  private int $seq = 0;

  public function __construct(private readonly ?ITransactionBoundary $boundary = null) {}

  public function schedule(WakeupIntent $i): void {
    $this->assertInTransaction('schedule');
    if (isset($this->intents[$i->idempotencyKey])) {
      return;
    }
    $this->intents[$i->idempotencyKey] = [
      'intent' => $i, 'seq' => ++$this->seq, 'attempts' => 0,
      'next_at' => null, 'token' => null, 'lease_until' => null, 'error' => null,
    ];
  }

  public function cancel(string $idempotencyKey): void {
    $this->assertInTransaction('cancel');
    unset($this->intents[$idempotencyKey]);
  }

  public function claimDue(\DateTimeImmutable $now, int $limit, int $leaseSeconds): array {
    $due = array_filter($this->intents, static fn (array $r) =>
      $r['intent']->dueAt <= $now
      && ($r['next_at'] === null || $r['next_at'] <= $now)
      && ($r['token'] === null || $r['lease_until'] <= $now));
    uasort($due, static fn ($a, $b) => [$a['intent']->dueAt, $a['seq']] <=> [$b['intent']->dueAt, $b['seq']]);

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
    unset($this->intents[$w->intent->idempotencyKey]);
    return true;
  }

  public function retryLater(ClaimedWakeup $w, string $error, \DateTimeImmutable $nextAt): bool {
    if (!$this->holds($w)) {
      return false;
    }
    $key = $w->intent->idempotencyKey;
    $this->intents[$key]['attempts']++;
    $this->intents[$key]['next_at'] = $nextAt;
    $this->intents[$key]['error'] = $error;
    $this->intents[$key]['token'] = null;
    $this->intents[$key]['lease_until'] = null;
    return true;
  }

  /** @return list<WakeupIntent> every intent not yet completed or cancelled */
  public function pending(): array {
    return array_values(array_map(static fn (array $r) => $r['intent'], $this->intents));
  }

  public function snapshotState(): mixed {
    return [$this->intents, $this->seq];
  }

  public function restoreState(mixed $state): void {
    [$this->intents, $this->seq] = $state;
  }

  private function holds(ClaimedWakeup $w): bool {
    return ($this->intents[$w->intent->idempotencyKey]['token'] ?? null) === $w->claimToken;
  }

  private function assertInTransaction(string $op): void {
    if ($this->boundary !== null && !$this->boundary->isActive()) {
      throw new WakeupOutsideTransaction("IWakeupScheduler::$op() must run inside the process store's transaction.");
    }
  }
}
