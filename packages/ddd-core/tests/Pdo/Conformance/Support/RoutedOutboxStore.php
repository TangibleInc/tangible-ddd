<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Support;

use TangibleDDD\Defaults\Pdo\PdoOutboxStore;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IReportsClaimDeadLetters;
use TangibleDDD\Runtime\Outbox\OutboxRecord;

/**
 * HostFixture::outbox() on pdo: the fixture's PdoOutboxStore, except while
 * asOtherConnection() runs, when every call goes to the same table through
 * a second connection. That is RelayRace's competitor: it runs in the middle
 * of the relay's open transaction on connection 1, and stands for another
 * relay process (PdoOutboxStore::claim() refuses to run inside an open
 * transaction, as it must).
 *
 * Claim-time dead letters (CR-PDO-6, IReportsClaimDeadLetters) are reported
 * by the store that made the claim, so the core relay step sees them through
 * this router and the RecordingOutboxStore above it.
 */
final class RoutedOutboxStore implements IOutboxStore, IReportsClaimDeadLetters {

  private ?IOutboxStore $override = null;

  public function __construct(private readonly PdoOutboxStore $primary) {}

  public function primary(): PdoOutboxStore {
    return $this->primary;
  }

  /**
   * @template T
   * @param callable(): T $fn
   * @return T
   */
  public function asOtherConnection(IOutboxStore $other, callable $fn): mixed {
    $previous = $this->override;
    $this->override = $other;
    try {
      return $fn();
    } finally {
      $this->override = $previous;
    }
  }

  public function append(OutboxRecord $r): void {
    $this->store()->append($r);
  }

  public function claim(int $limit, \DateTimeImmutable $now, int $leaseSeconds): array {
    return $this->store()->claim($limit, $now, $leaseSeconds);
  }

  public function accept(Claim $c, ?string $transportRef): bool {
    return $this->store()->accept($c, $transportRef);
  }

  public function retryLater(Claim $c, string $error, \DateTimeImmutable $nextAt): bool {
    return $this->store()->retryLater($c, $error, $nextAt);
  }

  public function deadLetter(Claim $c, string $error): bool {
    return $this->store()->deadLetter($c, $error);
  }

  public function takeDeadLetteredAtClaim(): array {
    $store = $this->store();
    return $store instanceof IReportsClaimDeadLetters ? $store->takeDeadLetteredAtClaim() : [];
  }

  private function store(): IOutboxStore {
    return $this->override ?? $this->primary;
  }
}
