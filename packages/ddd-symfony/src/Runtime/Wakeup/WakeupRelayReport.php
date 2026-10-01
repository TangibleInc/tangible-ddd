<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Wakeup;

/** What one wakeup relay step did. */
final class WakeupRelayReport {

  /**
   * @param list<string> $projected idempotency keys sent to the transport
   * @param list<string> $failed idempotency keys whose send failed (retried later)
   * @param list<int> $requeued `scheduled` process ids given a fresh Continue intent
   * @param list<int> $reported `running` process ids reported for the operator only
   */
  public function __construct(
    public readonly array $projected = [],
    public readonly array $failed = [],
    public readonly array $requeued = [],
    public readonly array $reported = [],
  ) {}

  public function did_work(): bool {
    return $this->projected !== [] || $this->failed !== [] || $this->requeued !== [];
  }
}
