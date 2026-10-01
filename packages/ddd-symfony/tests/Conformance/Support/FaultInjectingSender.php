<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use TangibleDDD\Runtime\Delivery\TransportRejected;
use TangibleDDD\Runtime\IClock;

/**
 * A Messenger Doctrine sender with one-shot faults for the scenarios: the
 * next send() throws, or the next send() is "taken" but yields no transport
 * message id (and so inserts nothing). Every other send() goes to the real
 * Doctrine transport.
 *
 * - $shared: a fault queue several senders consult (the wake hand-off of
 *   any worker, ProcessHost::failNextWakeHandoff()).
 * - $hostClock: records, per transport message id, the host clock minus the
 *   wall clock at the send. The Doctrine transport stores
 *   `available_at = wall now + DelayStamp`, so `available_at + offset` is
 *   the due time on the HOST clock (CR sfc-2 option (b), W3CP-R1).
 */
final class FaultInjectingSender implements SenderInterface {

  private ?\Throwable $rejectNext = null;

  private bool $noIdNext = false;

  /** @var array<string, float> transport message id => host minus wall seconds at send */
  private array $offsets = [];

  public function __construct(
    private readonly SenderInterface $inner,
    private readonly ?SendFaults $shared = null,
    private readonly ?IClock $hostClock = null,
  ) {}

  public function rejectNext(?\Throwable $e = null): void {
    $this->rejectNext = $e ?? new TransportRejected('injected rejection');
  }

  public function noIdNext(): void {
    $this->noIdNext = true;
  }

  public function send(Envelope $envelope): Envelope {
    if ($this->rejectNext !== null) {
      $e = $this->rejectNext;
      $this->rejectNext = null;
      throw $e;
    }
    $reason = $this->shared?->take();
    if ($reason !== null) {
      throw new TransportRejected("transport unavailable: $reason");
    }
    if ($this->noIdNext) {
      $this->noIdNext = false;
      return $envelope;
    }

    $host = $this->hostClock === null ? null : (float) $this->hostClock->now()->format('U.u');
    $wall = microtime(true);
    $sent = $this->inner->send($envelope);
    $id = $sent->last(TransportMessageIdStamp::class)?->getId();
    if ($host !== null && $id !== null) {
      $this->offsets[(string) $id] = $host - $wall;
    }
    return $sent;
  }

  /** Host minus wall seconds at the send of message $id; null when it was not sent here. */
  public function hostOffsetOf(string $id): ?float {
    return $this->offsets[$id] ?? null;
  }
}
