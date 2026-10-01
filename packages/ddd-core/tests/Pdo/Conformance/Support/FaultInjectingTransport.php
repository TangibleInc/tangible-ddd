<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Support;

use TangibleDDD\Conformance\Support\RecordingOutboxStore;
use TangibleDDD\Defaults\Pdo\PdoJobStore;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\TransportRejected;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;

/**
 * The pdo relay transport (PdoJobStore: one `deliver` job per fact) with the
 * two one-shot faults of HostFixture:
 *
 * - rejectNext(): submit() throws before any job is written;
 * - noRefNext(): the job is written, then no reference is returned. The
 *   relay runs submit + accept in one transaction on this shared connection,
 *   so its TransportRejected rolls the job back with it: nothing is held.
 *
 * sharesConnectionWith() looks through the fixture's own store decorators
 * (RecordingOutboxStore, RoutedOutboxStore) to the PdoOutboxStore, so the
 * relay sees the same answer the production composition gives.
 */
final class FaultInjectingTransport implements ITransport {

  /** @var list<?\Throwable> */
  private array $rejections = [];

  private int $noRef = 0;

  public function __construct(private readonly PdoJobStore $inner) {}

  public function inner(): PdoJobStore {
    return $this->inner;
  }

  public function rejectNext(?\Throwable $e = null): void {
    $this->rejections[] = $e;
  }

  public function noRefNext(): void {
    $this->noRef++;
  }

  public function submit(Claim $c, array $wrappedEnvelope, \DateTimeImmutable $dueAt): ?string {
    if ($this->rejections !== []) {
      throw array_shift($this->rejections) ?? new TransportRejected("transport rejected {$c->event_id} (conformance fault)");
    }
    $ref = $this->inner->submit($c, $wrappedEnvelope, $dueAt);
    if ($this->noRef > 0) {
      $this->noRef--;
      return null;
    }
    return $ref;
  }

  public function sharesConnectionWith(IOutboxStore $store): bool {
    while (true) {
      if ($store instanceof RecordingOutboxStore) {
        $store = $store->inner();
      } elseif ($store instanceof RoutedOutboxStore) {
        $store = $store->primary();
      } else {
        break;
      }
    }
    return $this->inner->sharesConnectionWith($store);
  }
}
