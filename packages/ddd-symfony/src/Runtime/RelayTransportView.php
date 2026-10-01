<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;

/**
 * The transport as one core relay step sees it: submit() goes straight
 * through; sharesConnectionWith() answers for the REAL store, because the
 * step is handed RelayOutcomes (a recording view of it), which no transport
 * recognises.
 *
 * @internal
 */
final class RelayTransportView implements ITransport {

  public function __construct(
    private readonly ITransport $inner,
    private readonly IOutboxStore $store,
  ) {}

  public function submit(Claim $c, array $wrappedEnvelope, \DateTimeImmutable $dueAt): ?string {
    return $this->inner->submit($c, $wrappedEnvelope, $dueAt);
  }

  public function sharesConnectionWith(IOutboxStore $store): bool {
    return $this->inner->sharesConnectionWith($this->store);
  }
}
