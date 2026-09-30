<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\TransportRejected;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;

/**
 * In-memory ITransport recording each accepted submission with its absolute
 * due time. Controls: rejectNext() (throws), returnNoRefNext() (accepted
 * without a reference, for `relay.invalid-acceptance`).
 */
final class InMemoryTransport implements ITransport {

  /** @var list<array{event_id: string, envelope: array, due_at: \DateTimeImmutable, ref: ?string}> */
  public array $submissions = [];

  private ?\Throwable $reject = null;

  private bool $noRef = false;

  private int $seq = 0;

  public function __construct(private readonly bool $sharesConnection = false) {}

  public function submit(Claim $c, array $wrappedEnvelope, \DateTimeImmutable $dueAt): ?string {
    if ($this->reject !== null) {
      $e = $this->reject;
      $this->reject = null;
      throw $e;
    }

    $ref = $this->noRef ? null : 'mem-' . (++$this->seq);
    $this->noRef = false;
    $this->submissions[] = ['event_id' => $c->event_id, 'envelope' => $wrappedEnvelope, 'due_at' => $dueAt, 'ref' => $ref];

    return $ref;
  }

  public function sharesConnectionWith(IOutboxStore $store): bool {
    return $this->sharesConnection;
  }

  public function rejectNext(?\Throwable $e = null): void {
    $this->reject = $e ?? new TransportRejected('rejected by InMemoryTransport');
  }

  public function returnNoRefNext(): void {
    $this->noRef = true;
  }
}
