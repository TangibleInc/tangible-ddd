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
 *
 * Shared connection (CONF-5): with `sharesConnection: true` the transport
 * models a queue on the store's connection (AS on the WordPress connection, the pdo jobs table,
 * the Doctrine transport). It takes part in InMemoryTransactionBoundary, so a
 * submission rolls back with the relay's transaction. Pass the boundary to
 * enlist at construction, or call InMemoryTransactionBoundary::enlist()
 * yourself. A non-shared transport never enlists: its submissions survive a
 * rollback, as a separate connection's would.
 */
final class InMemoryTransport implements ITransport, InMemoryTransactional {

  /** @var list<array{event_id: string, envelope: array, due_at: \DateTimeImmutable, ref: ?string}> */
  public array $submissions = [];

  private ?\Throwable $reject = null;

  private bool $noRef = false;

  private int $seq = 0;

  public function __construct(
    private readonly bool $sharesConnection = false,
    ?InMemoryTransactionBoundary $boundary = null,
  ) {
    if ($sharesConnection && $boundary !== null) {
      $boundary->enlist($this);
    }
  }

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

  public function snapshotState(): mixed {
    return [$this->submissions, $this->seq];
  }

  public function restoreState(mixed $state): void {
    [$this->submissions, $this->seq] = $state;
  }
}
