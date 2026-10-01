<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Wakeup;

/** What one wakeup relay step did. */
final class WakeupRelayReport {

  /**
   * @param list<string> $projected idempotency keys sent to the transport
   * @param list<string> $failed idempotency keys whose send failed (retried later)
   * @param list<int> $strandedRequeued `scheduled` process ids given a fresh Continue intent
   * @param list<int> $strandedReported `running` process ids reported for the operator only
   */
  public function __construct(
    public readonly array $projected = [],
    public readonly array $failed = [],
    public readonly array $strandedRequeued = [],
    public readonly array $strandedReported = [],
  ) {}

  public function didWork(): bool {
    return $this->projected !== [] || $this->failed !== [] || $this->strandedRequeued !== [];
  }
}
