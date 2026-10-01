<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Support;

use TangibleDDD\Defaults\Pdo\PdoOutboxStore;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;

/**
 * HostFixture::outbox() on pdo: the fixture's PdoOutboxStore, except while
 * asOtherConnection() runs, when every call goes to the same table through
 * a second connection. That is RelayRace's competitor: it runs in the middle
 * of the relay's open transaction on connection 1, and stands for another
 * relay process (PdoOutboxStore::claim() refuses to run inside an open
 * transaction, as it must).
 */
final class RoutedOutboxStore implements IOutboxStore {

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

  private function store(): IOutboxStore {
    return $this->override ?? $this->primary;
  }
}
